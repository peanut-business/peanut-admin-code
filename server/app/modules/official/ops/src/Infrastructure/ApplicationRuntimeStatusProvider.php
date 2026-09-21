<?php
declare(strict_types=1);

namespace PeanutAdmin\Modules\Ops\Infrastructure;

use PeanutAdmin\Modules\Ops\Service\PlatformUpgradeReadinessService;
use app\common\value\installation\ApplicationReleaseVersions;
use PeanutAdmin\Kernel\Context\PlatformContext;
use PeanutAdmin\Modules\Ops\Domain\Status\OpsStatusSnapshot;
use PeanutAdmin\Modules\Ops\Domain\Status\RuntimeStatusProvider;
use app\platform\infrastructure\module\ThinkPhpModuleGovernanceProvider;
use Throwable;
use think\facade\Cache;
use think\facade\Db;

/** Application-owned runtime evidence provider for the Core Ops status contract. */
final readonly class ApplicationRuntimeStatusProvider implements RuntimeStatusProvider
{
    public function __construct(
        private string $projectRoot,
        private PlatformUpgradeReadinessService $readiness,
        private ThinkPhpModuleGovernanceProvider $moduleGovernance,
    ) {
    }

    public function snapshot(PlatformContext $context): OpsStatusSnapshot
    {
        $runtime = $this->runtimeEvidence();
        $readiness = $this->readiness->snapshot($context, $runtime);
        $backup = is_array($readiness['backup']['latest_verified'] ?? null)
            ? $readiness['backup']['latest_verified']
            : null;

        return new OpsStatusSnapshot(
            $runtime['health'],
            $runtime['checks'],
            $runtime['identity']['commit'],
            $runtime['identity']['tree'],
            $runtime['identity']['release_key'],
            $runtime['identity']['built_at'],
            $runtime['migrations']['applied'],
            $runtime['migrations']['target'],
            $runtime['migrations']['pending'],
            $runtime['migrations']['digest'],
            $runtime['migrations']['drift'],
            $readiness['state'],
            $readiness['code'],
            $runtime['identity']['commit'],
            is_array($readiness['target'] ?? null) ? (string)$readiness['target']['commit'] : null,
            $runtime['identity']['repository_clean'],
            $backup !== null,
            ($backup['source_matches_runtime'] ?? false) === true,
        );
    }

    /** @return array<string,mixed> */
    public function upgradeReadiness(PlatformContext $context): array
    {
        return $this->readiness->snapshot($context, $this->runtimeEvidence());
    }

    public function runtimeCommit(): string
    {
        return $this->runtimeIdentity()['commit'];
    }

    /**
     * @return array{
     *   health:string,
     *   checks:list<array{key:string,status:string,critical:bool,latency_ms:float}>,
     *   identity:array{commit:string,tree:string,release_key:?string,built_at:string,repository_clean:bool},
     *   migrations:array{applied:int,target:int,pending:int,digest:string,drift:bool,files:array<string,string>}
     * }
     */
    private function runtimeEvidence(): array
    {
        $identity = $this->runtimeIdentity();
        $checks = [];

        [$databaseStatus, $databaseLatency] = $this->probe(function (): void {
            $result = Db::query('SELECT 1 AS healthy');
            if ((int)($result[0]['healthy'] ?? 0) !== 1) {
                throw new \RuntimeException('database probe failed');
            }
        });
        $checks[] = $this->check('database.connection', $databaseStatus, true, $databaseLatency);

        $migrationStarted = hrtime(true);
        try {
            $migrations = $this->migrationState();
            $migrationStatus = !$migrations['drift'] && $migrations['pending'] === 0 ? 'up' : 'down';
        } catch (Throwable) {
            $migrations = $this->unavailableMigrationState();
            $migrationStatus = 'down';
        }
        $checks[] = $this->check(
            'database.migrations',
            $migrationStatus,
            true,
            $this->elapsedMilliseconds($migrationStarted)
        );

        [$moduleStatus, $moduleLatency] = $this->probe(function (): void {
            $this->moduleGovernance
                ->qualification()
                ->installedModules();
        });
        $checks[] = $this->check('module.catalog', $moduleStatus, true, $moduleLatency);

        [$cacheStatus, $cacheLatency] = $this->probe(static function (): void {
            Cache::get('application:v1:ops-readonly-health');
        });
        $checks[] = $this->check('cache.read', $cacheStatus, false, $cacheLatency);

        [$storageStatus, $storageLatency] = $this->probe(function (): void {
            foreach (['server/runtime', 'server/public/storage', 'server/private/storage'] as $relative) {
                $path = $this->projectRoot . '/' . $relative;
                if (!is_dir($path) || !is_readable($path) || !is_writable($path)) {
                    throw new \RuntimeException('runtime storage unavailable');
                }
            }
        });
        $checks[] = $this->check('storage.runtime', $storageStatus, true, $storageLatency);

        $criticalDown = false;
        $anyDown = false;
        foreach ($checks as $check) {
            $isDown = $check['status'] === 'down';
            $anyDown = $anyDown || $isDown;
            $criticalDown = $criticalDown || ($isDown && $check['critical']);
        }
        $health = $criticalDown ? 'unhealthy' : ($anyDown ? 'degraded' : 'healthy');

        return [
            'health' => $health,
            'checks' => $checks,
            'identity' => $identity,
            'migrations' => $migrations,
        ];
    }

    /** @return array{commit:string,tree:string,release_key:?string,built_at:string,repository_clean:bool} */
    private function runtimeIdentity(): array
    {
        $metadata = $this->releaseMetadata();
        if (file_exists($this->projectRoot . '/.git')) {
            $commit = $this->git(['rev-parse', 'HEAD']);
            $tree = $this->git(['rev-parse', 'HEAD^{tree}']);
            // The fixed deployment-staged target is operational evidence, not
            // application source. Every other tracked/untracked change remains
            // part of the repository-clean upgrade gate.
            $clean = $this->git([
                'status', '--porcelain', '--untracked-files=all', '--', '.',
                ':(exclude).peanut/upgrade-target',
                ':(exclude).peanut/upgrade-target/**',
            ]) === '';
            $releaseKey = $this->exactReleaseKey($metadata, $commit);

            return [
                'commit' => $this->commit($commit),
                'tree' => $this->commit($tree),
                'release_key' => $releaseKey,
                'built_at' => $this->builtAt($this->projectRoot . '/server/composer.lock'),
                'repository_clean' => $clean,
            ];
        }

        $receipt = $this->deploymentReceipt($metadata);
        $release = $receipt['release'];
        return [
            'commit' => $this->commit((string)$release['commit']),
            'tree' => $this->commit((string)$release['tree']),
            'release_key' => $receipt['overlay'] === null ? (string)$release['tag'] : null,
            'built_at' => $this->builtAt($this->deploymentReceiptPath()),
            'repository_clean' => $receipt['overlay'] === null,
        ];
    }

    /** @param array<string,mixed> $metadata */
    private function exactReleaseKey(array $metadata, string $commit): ?string
    {
        $releaseKey = $this->releaseKey($metadata);
        if ($releaseKey === null) {
            return null;
        }
        try {
            return $this->git(['cat-file', '-t', $releaseKey]) === 'tag'
                && hash_equals($commit, $this->git(['rev-parse', $releaseKey . '^{commit}']))
                ? $releaseKey
                : null;
        } catch (Throwable) {
            return null;
        }
    }

    /** @param array<string,mixed> $metadata @return array<string,mixed> */
    private function deploymentReceipt(array $metadata): array
    {
        $raw = file_get_contents($this->deploymentReceiptPath());
        $receipt = is_string($raw) ? json_decode($raw, true, 512, JSON_THROW_ON_ERROR) : null;
        if (!is_array($receipt)
            || array_keys($receipt) !== [
                'schema_version', 'protocol', 'target', 'edition', 'release',
                'artifact', 'overlay', 'generated_at',
            ]
            || $receipt['schema_version'] !== 1
            || $receipt['protocol'] !== 'peanut.deployment-receipt.v1'
            || !in_array($receipt['target'], ['production', 'production-candidate'], true)
            || !in_array($receipt['edition'], ['standalone', 'multi-tenant'], true)
            || !is_array($receipt['release'])
            || array_keys($receipt['release']) !== ['tag', 'commit', 'tree']
            || !is_array($receipt['artifact'])
            || array_keys($receipt['artifact']) !== ['kind', 'archive_sha256', 'manifest_sha256']
            || !in_array($receipt['artifact']['kind'], ['source', 'edition'], true)
            || preg_match('/^[a-f0-9]{64}$/D', (string)$receipt['artifact']['archive_sha256']) !== 1
            || ($receipt['artifact']['kind'] === 'source' && $receipt['artifact']['manifest_sha256'] !== null)
            || ($receipt['artifact']['kind'] === 'edition'
                && preg_match('/^[a-f0-9]{64}$/D', (string)$receipt['artifact']['manifest_sha256']) !== 1)
            || preg_match('/^v(?:0|[1-9][0-9]*)\.(?:0|[1-9][0-9]*)\.(?:0|[1-9][0-9]*)$/D', (string)$receipt['release']['tag']) !== 1
            || preg_match('/^[a-f0-9]{40}$/D', (string)$receipt['release']['commit']) !== 1
            || preg_match('/^[a-f0-9]{40}$/D', (string)$receipt['release']['tree']) !== 1
            || $receipt['release']['tag'] !== $this->releaseKey($metadata)
            || preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/D', (string)$receipt['generated_at']) !== 1
            || !$this->validOverlayReceipt($receipt['overlay'], (string)$receipt['target'])) {
            throw new \RuntimeException('OPS_RELEASE_IDENTITY_UNAVAILABLE');
        }
        return $receipt;
    }

    private function validOverlayReceipt(mixed $overlay, string $target): bool
    {
        if ($overlay === null) {
            return true;
        }
        return $target === 'production-candidate'
            && is_array($overlay)
            && array_keys($overlay) === ['commit', 'archive_sha256', 'metadata_sha256']
            && preg_match('/^[a-f0-9]{40}$/D', (string)$overlay['commit']) === 1
            && preg_match('/^[a-f0-9]{64}$/D', (string)$overlay['archive_sha256']) === 1
            && preg_match('/^[a-f0-9]{64}$/D', (string)$overlay['metadata_sha256']) === 1;
    }

    private function deploymentReceiptPath(): string
    {
        $path = $this->projectRoot . '/DEPLOYMENT_RECEIPT.json';
        if (!is_file($path) || is_link($path)) {
            throw new \RuntimeException('OPS_RELEASE_IDENTITY_UNAVAILABLE');
        }
        return $path;
    }

    /** @return array<string,mixed> */
    private function releaseMetadata(): array
    {
        $raw = file_get_contents($this->metadataPath());
        $decoded = is_string($raw) ? json_decode($raw, true, 512, JSON_THROW_ON_ERROR) : null;
        if (!is_array($decoded)) {
            throw new \RuntimeException('OPS_RELEASE_IDENTITY_UNAVAILABLE');
        }
        return $decoded;
    }

    private function metadataPath(): string
    {
        foreach ([$this->projectRoot . '/RELEASE_METADATA.json', $this->projectRoot . '/legal/RELEASE_METADATA.json'] as $path) {
            if (is_file($path) && !is_link($path)) {
                return $path;
            }
        }
        throw new \RuntimeException('OPS_RELEASE_IDENTITY_UNAVAILABLE');
    }

    /** @param array<string,mixed> $metadata */
    private function releaseKey(array $metadata): ?string
    {
        $version = ($metadata['schema_version'] ?? null) === 2
            && ($metadata['protocol'] ?? null) === 'peanut.release-metadata.v2'
            ? (string)($metadata['instance_version'] ?? $metadata['source_product_version'] ?? '')
            : (string)($metadata['version'] ?? '');
        return preg_match('/^(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)$/D', $version) === 1
            ? 'v' . $version
            : null;
    }

    /** @param list<string> $arguments */
    private function git(array $arguments): string
    {
        $pipes = [];
        $process = proc_open(
            ['git', '-C', $this->projectRoot, ...$arguments],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes
        );
        if (!is_resource($process)) {
            throw new \RuntimeException('OPS_RELEASE_IDENTITY_UNAVAILABLE');
        }
        $stdout = stream_get_contents($pipes[1]);
        stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        if (proc_close($process) !== 0 || !is_string($stdout)) {
            throw new \RuntimeException('OPS_RELEASE_IDENTITY_UNAVAILABLE');
        }
        return trim($stdout);
    }

    private function commit(string $value): string
    {
        if (preg_match('/^[a-f0-9]{40}$/D', $value) !== 1) {
            throw new \RuntimeException('OPS_RELEASE_IDENTITY_UNAVAILABLE');
        }
        return $value;
    }

    private function builtAt(string $path): string
    {
        $timestamp = filemtime($path);
        if (!is_int($timestamp)) {
            throw new \RuntimeException('OPS_RELEASE_IDENTITY_UNAVAILABLE');
        }
        return gmdate('Y-m-d\TH:i:s', $timestamp) . '.000Z';
    }

    /** @return array{applied:int,target:int,pending:int,digest:string,drift:bool,files:array<string,string>} */
    private function migrationState(): array
    {
        $expected = $this->expectedMigrations();
        $rows = Db::name('schema_migration')->field('migration_id,checksum,status')
            ->order('migration_id')->select()->toArray();
        $actual = [];
        foreach ($rows as $row) {
            $actual[(string)$row['migration_id']] = [
                'checksum' => (string)$row['checksum'],
                'status' => (string)$row['status'],
            ];
        }

        $applied = 0;
        $drift = count(array_diff_key($actual, $expected)) > 0;
        foreach ($expected as $id => $checksum) {
            $row = $actual[$id] ?? null;
            if (is_array($row)
                && $row['status'] === 'applied'
                && hash_equals($checksum, $row['checksum'])) {
                $applied++;
                continue;
            }
            if (is_array($row)) {
                $drift = true;
            }
        }

        $target = count($expected);
        return [
            'applied' => $applied,
            'target' => $target,
            'pending' => $target - $applied,
            'digest' => hash('sha256', json_encode($expected, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)),
            'drift' => $drift,
            'files' => $expected,
        ];
    }

    /** Return migrations eligible for the deployed scaffold or verified demo overlay target. */
    private function expectedMigrations(): array
    {
        $targetVersion = $this->migrationTargetVersion();
        $directory = $this->projectRoot . '/server/database/migrations';
        $files = glob($directory . '/*.sql') ?: [];
        sort($files, SORT_STRING);
        $expected = [];
        foreach ($files as $file) {
            $name = basename($file);
            if (!is_file($file)
                || is_link($file)
                || preg_match('/^[0-9]{8}-[a-z0-9][a-z0-9_-]*\.sql$/D', $name) !== 1) {
                throw new \RuntimeException('OPS_MIGRATION_INVENTORY_INVALID');
            }
            $contents = file_get_contents($file);
            if (!is_string($contents) || trim($contents) === '') {
                throw new \RuntimeException('OPS_MIGRATION_INVENTORY_INVALID');
            }
            $peanutRelease = $this->peanutMigrationRelease($contents);
            if ($peanutRelease !== null && version_compare($peanutRelease, $targetVersion, '>')) {
                continue;
            }
            $expected[basename($name, '.sql')] = hash('sha256', $contents);
        }
        return $expected;
    }

    /** Return one valid Peanut marker, while leaving truly unmarked application SQL unversioned. */
    private function peanutMigrationRelease(string $sql): ?string
    {
        preg_match_all('/^\s*--\s*peanut-release\b[^\r\n]*$/mi', $sql, $markerLines);
        if (count($markerLines[0]) === 0) {
            return null;
        }
        if (count($markerLines[0]) !== 1
            || preg_match(
                '/^\s*--\s*peanut-release:\s*((0|[1-9][0-9]*)\.(0|[1-9][0-9]*)\.(0|[1-9][0-9]*))\s*$/iD',
                $markerLines[0][0],
                $matches,
            ) !== 1
        ) {
            throw new \RuntimeException('OPS_MIGRATION_INVENTORY_INVALID');
        }
        return $matches[1];
    }

    /** Resolve the same scaffold/overlay migration target used by deployment. */
    private function migrationTargetVersion(): string
    {
        $versions = ApplicationReleaseVersions::load($this->projectRoot . '/release-versions.json');
        $base = $versions->scaffoldTemplate();
        $overlayPath = $this->projectRoot . '/DEMO_PATCH_METADATA.json';
        if (!file_exists($overlayPath)) {
            return $base;
        }
        if (!is_file($overlayPath) || is_link($overlayPath)) {
            throw new \RuntimeException('OPS_MIGRATION_TARGET_INVALID');
        }
        try {
            $overlay = json_decode((string)file_get_contents($overlayPath), true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            throw new \RuntimeException('OPS_MIGRATION_TARGET_INVALID', 0, $exception);
        }
        $target = is_array($overlay) ? ($overlay['migration_target_version'] ?? null) : null;
        if (!is_array($overlay)
            || ($overlay['schema_version'] ?? null) !== 1
            || ($overlay['kind'] ?? null) !== 'peanut-admin-demo-site-overlay'
            || ($overlay['base_tag'] ?? null) !== 'v' . $versions->sourceProductVersion()
            || !is_array($overlay['files'] ?? null)
            || !is_string($target)
            || preg_match('/^(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)$/D', $target) !== 1
            || version_compare($target, $base, '<')
        ) {
            throw new \RuntimeException('OPS_MIGRATION_TARGET_INVALID');
        }
        return $target;
    }

    /** @return array{applied:int,target:int,pending:int,digest:string,drift:bool,files:array<string,string>} */
    private function unavailableMigrationState(): array
    {
        $expected = $this->expectedMigrations();
        return [
            'applied' => 0,
            'target' => count($expected),
            'pending' => count($expected),
            'digest' => hash('sha256', json_encode($expected, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)),
            'drift' => true,
            'files' => $expected,
        ];
    }

    /** @return array{string,float} */
    private function probe(callable $probe): array
    {
        $started = hrtime(true);
        try {
            $probe();
            return ['up', $this->elapsedMilliseconds($started)];
        } catch (Throwable) {
            return ['down', $this->elapsedMilliseconds($started)];
        }
    }

    /** @return array{key:string,status:string,critical:bool,latency_ms:float} */
    private function check(string $key, string $status, bool $critical, float $latency): array
    {
        return ['key' => $key, 'status' => $status, 'critical' => $critical, 'latency_ms' => $latency];
    }

    private function elapsedMilliseconds(int $started): float
    {
        return min(60000.0, max(0.0, round((hrtime(true) - $started) / 1_000_000, 3)));
    }
}
