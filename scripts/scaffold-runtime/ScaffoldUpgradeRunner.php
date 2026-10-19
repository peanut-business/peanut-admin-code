<?php

declare(strict_types=1);

namespace app\common\infrastructure\scaffold;

require_once __DIR__ . '/ReleaseDependencyIdentity.php';

use app\common\validation\scaffold\ScaffoldPathGuard;
use app\common\value\scaffold\ScaffoldManifest;
use app\platform\value\plugin\PluginDescriptor;
use app\platform\exception\plugin\PluginLifecycleException;
use app\platform\infrastructure\plugin\PluginLockResolver;
use app\platform\infrastructure\plugin\PluginArtifactWriter;
use app\platform\validation\module\StrictVersionConstraintMatcher;
use app\platform\validation\module\OpisManifestSchemaValidator;
use app\platform\validation\plugin\ModulePackagePreflight;
use RuntimeException;
use Throwable;

/** Plan, apply, verify, and recover scaffold-owned changes with frozen application identities. */
final class ScaffoldUpgradeRunner
{
    /** Real process-local open file descriptions, never a caller-supplied "locked" flag. */
    private static array $sourceLocks = [];
    private static ?string $recoveringRuntime = null;
    private static array $runtimeEventBinding = [];
    /** Present only after native internal-preinstall facts have been checked under the source/instance locks. */
    private ?array $internalPreinstall = null;

    public static function sourceProductLock(string $root, callable $operation): mixed
    {
        return self::sourceLock(ScaffoldPathGuard::projectRoot($root) . '/.peanut/upgrades/product.lock', $operation);
    }

    private static function sourceLock(string $path, callable $operation): mixed
    {
        ScaffoldPathGuard::ensureDirectory(dirname($path));
        if (isset(self::$sourceLocks[$path])) {
            $held = self::$sourceLocks[$path];
            $stat = @lstat($path);
            $open = fstat($held['handle']);
            if ($held['pid'] !== getmypid() || !is_array($stat) || is_link($path)
                || $open['dev'] !== $stat['dev'] || $open['ino'] !== $stat['ino']) {
                throw new RuntimeException('SCAFFOLD_SOURCE_LOCK_CHANGED');
            }
            return $operation();
        }
        if (is_link($path) || (file_exists($path) && (!is_file($path) || lstat($path)['nlink'] !== 1))) {
            throw new RuntimeException('SCAFFOLD_SOURCE_LOCK_UNSAFE');
        }
        $handle = fopen($path, 'c+b');
        if ($handle === false || !chmod($path, 0600) || !flock($handle, LOCK_EX | LOCK_NB)) {
            throw new RuntimeException('SCAFFOLD_SOURCE_LOCK_BUSY');
        }
        self::$sourceLocks[$path] = ['handle' => $handle, 'pid' => getmypid()];
        try { return $operation(); }
        finally { unset(self::$sourceLocks[$path]); flock($handle, LOCK_UN); fclose($handle); }
    }
    private function changesNotificationExecution(array $plan): bool
    {
        $prefix = 'server/app/modules/official/notification/';
        foreach ($plan['actions'] ?? [] as $action) {
            if (!in_array($action['action'] ?? '', ['create', 'replace', 'regenerate', 'delete', 'rename', 'rename-preserve'], true)) {
                continue;
            }
            $path = $action['path'] ?? '';
            if (is_string($path) && str_starts_with($path, $prefix)
                && ($path === $prefix . 'module.json'
                    || str_starts_with($path, $prefix . 'src/')
                    || str_starts_with($path, $prefix . 'database/'))) {
                return true;
            }
        }
        return false;
    }

    /** The operator still owns the exclusive runtime-safe window; this never stops services. */
    private function notificationExecutionPreflight(string $root): array
    {
        $unknown = ['status' => 'unknown', 'may_apply' => false, 'code' => 'NOTIFICATION_UPGRADE_STATE_UNKNOWN'];
        $entry = $root . '/server/think';
        if (!is_file($entry) || is_link($entry)) { return $unknown; }
        $process = proc_open(
            [PHP_BINARY, $entry, 'module:check', 'official.notification', '--runtime-data'],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            $root . '/server',
        );
        if (!is_resource($process)) { return $unknown; }
        fclose($pipes[0]);
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);
        $stdout = '';
        $exit = -1;
        $deadline = microtime(true) + 15;
        do {
            $stdout .= (string) stream_get_contents($pipes[1]);
            stream_get_contents($pipes[2]); // Never expose arbitrary APP errors or environment values.
            $status = proc_get_status($process);
            if (!$status['running']) { $exit = $status['exitcode']; break; }
            if (strlen($stdout) > 8192 || microtime(true) >= $deadline) {
                proc_terminate($process);
                break;
            }
            usleep(50000);
        } while (true);
        $stdout .= (string) stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($process);
        if (strlen($stdout) > 8192) { return $unknown; }
        foreach (array_reverse(explode("\n", trim($stdout))) as $line) {
            $report = json_decode($line, true);
            if (!is_array($report) || ($report['schema_version'] ?? null) !== 1
                || ($report['module_key'] ?? null) !== 'official.notification') { continue; }
            if ($exit === 0 && ($report['may_apply'] ?? false) === true
                && ($report['status'] ?? '') === 'ready' && ($report['pending_count'] ?? null) === 0) {
                return ['status' => 'ready', 'pending_count' => 0, 'may_apply' => true, 'code' => null,
                    'runtime_safe_window_required' => true];
            }
            if (($report['status'] ?? '') === 'blocked' && is_int($report['pending_count'] ?? null)
                && $report['pending_count'] > 0) {
                return ['status' => 'blocked', 'pending_count' => $report['pending_count'], 'may_apply' => false,
                    'code' => 'NOTIFICATION_LEGACY_DELIVERY_PENDING'];
            }
        }
        return $unknown;
    }

    public function __construct(
        private readonly ?int $failAfterReplacements = null,
        private readonly ?int $failAfterAdoptionWrites = null,
    ) {
        if (($failAfterReplacements !== null && $failAfterReplacements < 1)
            || ($failAfterAdoptionWrites !== null && $failAfterAdoptionWrites < 1)) {
            throw new RuntimeException('SCAFFOLD_FAULT_INJECTION_INVALID');
        }
    }

    private const STRICT_SEMVER = '/^(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)(?:-(?:0|[1-9][0-9]*|[0-9]*[A-Za-z-][0-9A-Za-z-]*)(?:\.(?:0|[1-9][0-9]*|[0-9]*[A-Za-z-][0-9A-Za-z-]*))*)?(?:\+[0-9A-Za-z-]+(?:\.[0-9A-Za-z-]+)*)?$/D';
    private const VERSION_CONTRACT_V1_KEYS = [
        'schema_version',
        'protocol',
        'product_release',
        'scaffold_template',
        'generated_application_default',
        'core_php',
        'core_web',
    ];
    private const VERSION_CONTRACT_V2_KEYS = [
        'schema_version',
        'protocol',
        'source_product_version',
        'instance_version',
        'scaffold_template',
        'generated_instance_default',
        'core_php',
        'core_web',
    ];
    private const VERSION_CONTRACT_V3_KEYS = self::VERSION_CONTRACT_V2_KEYS;
    private const CORE_WEB_PACKAGES = [
        '@peanut-admin/client',
        '@peanut-admin/vue',
        '@peanut-admin/ui-vue',
        '@peanut-admin/nuxt',
        '@peanut-admin/uniapp',
        '@peanut-admin/testing',
    ];

    /**
     * Build the immutable scaffold plan without writing a plan file or ledger event.
     *
     * The Platform upgrade-readiness projection uses this method so a read request
     * can evaluate exactly the same ownership/conflict rules as the CLI preflight.
     */
    public function preview(string $projectRoot, string $fromManifestPath, string $toManifestPath, ?array $sourceSelection = null): array
    {
        $root = ScaffoldPathGuard::projectRoot($projectRoot);
        if (PHP_SAPI === 'cli' && is_file($root . '/server/.peanut/internal-candidate.json')
            && !file_exists($root . '/server/.peanut/development-runtime.json')) {
            require_once dirname(__DIR__, 2) . '/server/docker/scripts/update-plan.php';
            return self::sourceProductLock($root, fn(): array => \PeanutServerUpdatePlan::withSourceInstanceLock($root . '/server',
                fn(): array => $this->previewSource($root, $fromManifestPath, $toManifestPath, $sourceSelection)));
        }
        return $this->previewSource($root, $fromManifestPath, $toManifestPath, $sourceSelection);
    }

    private function previewSource(string $projectRoot, string $fromManifestPath, string $toManifestPath, ?array $sourceSelection): array
    {
        $root = ScaffoldPathGuard::projectRoot($projectRoot);
        [$application, $applicationDigest] = $this->applicationManifest($root);
        $from = ScaffoldManifest::load($fromManifestPath);
        $to = ScaffoldManifest::load($toManifestPath);
        $sourceSelection = $this->validateUpgradeSourceSelection($root, $sourceSelection, $to);
        $internal = null;
        if (PHP_SAPI === 'cli' && $from->version() === $to->version() && is_file($root . '/server/.peanut/internal-candidate.json')) {
            require_once dirname(__DIR__, 2) . '/server/docker/scripts/update-plan.php';
            if ($sourceSelection !== null) { throw new RuntimeException('INTERNAL_PREINSTALL_NOT_PUBLISHED_RELEASE_SELECTION'); }
            $internal = \PeanutServerUpdatePlan::internalPreinstallContext($root . '/server', $from->release(), $to->release());
        }
        $this->assertReleaseChain($application, $from, $to, $internal !== null);
        if ($internal !== null) {
            if (($from->data['edition'] ?? null) !== ($to->data['edition'] ?? null)
                || ($to->data['edition']['name'] ?? null) !== 'multi-tenant') { throw new RuntimeException('INTERNAL_PREINSTALL_EDITION_CHANGED'); }
            $this->internalPreinstall = $internal;
        }
        try {
        [$versionContract, $versionContractDigest] = $this->versionContract($root, $application);
        $fromParameters = $this->parameters($application, (string) $application['application']['version']);
        $instanceVersion = $this->instanceVersion($versionContract);
        $targetParameters = $this->parameters($application, $instanceVersion);
        if ($internal !== null) {
            $this->assertInternalCoreInputs($root, $to, $targetParameters, $versionContract);
            $this->assertInternalSourceDelta($from, $to);
        }
        $actions = $this->classify($root, $application, $from, $to, $fromParameters, $targetParameters, $versionContract);
        $pluginProjection = null;
        $pluginTarget = null;
        if (Semver::greaterThanOrEqualTo($from->version(), '3.0.0')) {
            $pluginProjection = $this->pluginProjection($root, sourceApplication: true);
            $targetPlugins = $this->targetPluginIndex($to, $targetParameters, $versionContract);
            $managedUpstreamProofs = $this->managedBundledUpstreamProofs(
                $root,
                $application,
                $pluginProjection,
                $this->targetPluginIndex($from, $fromParameters, $versionContract),
                $from->files(),
                $targetPlugins,
                $to->files(),
            );
            $pluginTarget = $this->upstreamPluginProjection(
                $root,
                $pluginProjection,
                $from->files(),
                $managedUpstreamProofs,
                $targetPlugins,
                $to,
                $targetParameters,
                $versionContract,
            );
            $actions = $this->projectPluginBoundary($actions, $pluginProjection, $targetPlugins, $pluginTarget);
        }
        $summary = $this->summary($actions);
        $impact = $this->withModuleCompatibilityImpact($this->impact($actions), $pluginTarget);
        $appOwnedState = $this->ownershipState($root, $application, 'app-owned');
        $managedState = $this->actionState($root, $actions);
        $identity = [
            'from' => $this->releaseIdentity($from) + ['generation_source' => $application['generation_source'] ?? null],
            'to' => $this->releaseIdentity($to),
            'source_selection' => $sourceSelection,
            'target_generation_source' => $this->targetGenerationSource($application, $to, $sourceSelection),
            'edition' => $to->data['edition'] ?? null,
            'application_version' => $instanceVersion,
            'adoption_application_version' => $application['application']['version'],
            'version_contract' => $versionContract,
            'version_contract_sha256' => $versionContractDigest,
            'from_parameters' => $fromParameters,
            'target_parameters' => $targetParameters,
            'application_manifest_sha256' => $applicationDigest,
            'managed_pre_sha256' => $managedState['digest'],
            'app_owned_pre_sha256' => $appOwnedState['digest'],
            'plugin_projection' => $pluginProjection,
            'plugin_target' => $pluginTarget,
        ];
        if ($internal !== null) {
            $identity['kind'] = 'internal-candidate-preinstall-correction';
            $identity['internal_preinstall'] = $internal;
        }
        $deployment = $root . '/server/private/installation/deployment.json';
        if (is_file($deployment) || is_link($deployment)) {
            $identity['deployment_state_sha256'] = hash_file('sha256', ScaffoldPathGuard::existingFileWithin($root, $deployment, 'SCAFFOLD_DEPLOYMENT_INVALID'));
        }
        if (file_exists($root . '/server/.peanut/development-runtime.json')
            && (file_exists($root . '/server/private/installation/installed.json') || file_exists($root . '/server/private/installation/executing.json'))) {
            $history = $root . '/.peanut/upgrades/ledger.ndjson';
            $identity['source_history_sha256'] = file_exists($history)
                ? hash_file('sha256', ScaffoldPathGuard::existingFileWithin($root, $history, 'SCAFFOLD_HISTORY_INVALID')) : null;
        }
        $planDigest = hash('sha256', self::canonicalJson([$identity, $actions]));
        $candidate = 'scaffold-' . substr($planDigest, 0, 24);
        $status = $summary['conflicts'] === 0 && !$this->moduleCompatibilityBlocked($pluginTarget)
            ? 'ready'
            : 'blocked';
        return [
            'schema_version' => 2,
            'protocol' => 'peanut.scaffold-upgrade-plan.v2',
            'candidate' => $candidate,
            'plan_sha256' => 'sha256:' . $planDigest,
            'status' => $status,
            'identity' => $identity,
            'manifest_paths' => ['from' => $from->path, 'to' => $to->path],
            'summary' => $summary,
            'impact' => $impact,
            'managed_pre_state' => $managedState['files'],
            'app_owned_pre_state' => $appOwnedState['files'],
            'actions' => $actions,
        ];
        } finally { $this->internalPreinstall = null; }
    }

    /** The raw native scaffold may change source; it cannot change any of the seven fixed dependency inputs. */
    private function assertInternalCoreInputs(string $root, ScaffoldManifest $to, array $parameters, array $versions): void
    {
        $files = $to->files(); $version = $files['release-versions.json'] ?? null;
        if (!is_array($version)) { throw new RuntimeException('INTERNAL_PREINSTALL_VERSION_INPUT_REQUIRED'); }
        $rendered = $this->normalizeVersionContractDocument($this->renderCurrentVersionArtifact($to, $version, $parameters, $versions), 'INTERNAL_PREINSTALL_VERSION_INPUT_INVALID');
        foreach (['source_product_version', 'scaffold_template', 'instance_version', 'core_php', 'core_web'] as $key) {
            if (($rendered[$key] ?? null) !== ($versions[$key] ?? null)) { throw new RuntimeException('INTERNAL_PREINSTALL_CORE_OR_VERSION_CHANGED'); }
        }
        foreach ($files as $path => $file) {
            if (!str_starts_with($path, 'packages/core-php/') && !str_starts_with($path, 'packages/core-web/')) { continue; }
            $actual = ScaffoldPathGuard::existingFileWithin($root, $root . '/' . $path, 'INTERNAL_PREINSTALL_CORE_INPUT_UNAVAILABLE');
            if (hash_file('sha256', $actual) !== $file['template_sha256']) { throw new RuntimeException('INTERNAL_PREINSTALL_CORE_ARCHIVE_CHANGED'); }
        }
    }

    /** This one pre-install correction does not become a general same-version package replacement policy. */
    private function assertInternalSourceDelta(ScaffoldManifest $from, ScaffoldManifest $to): void
    {
        foreach (['application', 'renames', 'edition'] as $key) {
            if (($from->data[$key] ?? null) !== ($to->data[$key] ?? null)) { throw new RuntimeException('INTERNAL_PREINSTALL_SELECTION_CHANGED'); }
        }
        foreach (['protocol', 'scope', 'core_inputs_sha256', 'core', 'core_web', 'web_source_reference', 'release_versions'] as $key) {
            $before = $from->data['internal_core_projection'][$key] ?? null;
            if ($before === null || $before !== ($to->data['internal_core_projection'][$key] ?? null)) {
                throw new RuntimeException('INTERNAL_PREINSTALL_CORE_QUALIFICATION_CHANGED');
            }
        }
        if (($from->data['internal_core_projection']['protocol'] ?? null) !== 'peanut.internal-core-scaffold-projection.v1'
            || ($from->data['internal_core_projection']['scope'] ?? null) !== 'internal-upgrade-target-not-formal-release'
            || ($to->data['internal_core_projection']['source'] ?? null) !== ['commit' => $to->release()['source_commit'], 'tree' => $to->release()['source_tree']]) {
            throw new RuntimeException('INTERNAL_PREINSTALL_CORE_SOURCE_CHANGED');
        }
        foreach (['version', 'inventory_template_version', 'workflow', 'tokens'] as $key) {
            if (($from->release()[$key] ?? null) !== ($to->release()[$key] ?? null)) { throw new RuntimeException('INTERNAL_PREINSTALL_TEMPLATE_CONTRACT_CHANGED'); }
        }
        $allowed = array_fill_keys([
            'server/database/baselines/5.0.2-r2/baseline.lock', 'server/database/baselines/5.0.2-r2/manifest.json',
            'server/database/baselines/5.0.2-r2/schema.sql', 'server/database/baselines/5.0.2-r2/data.sql',
            'server/app/common/infrastructure/migration/CleanInstallBaseline.php',
            'server/app/common/infrastructure/migration/CleanInstallMigration.php',
            'server/app/common/infrastructure/migration/PeanutMigrationRun.php',
            'scripts/scaffold-runtime/EditionUpgradePackage.php', 'docs/development/current-code-lifecycle.md',
            'server/app/modules/official/file/src/Contract/FileMigrationAdoptionProof.php',
            'server/app/modules/official/file/src/Contract/FileMigrationPreservation.php',
            'server/app/platform/services/plugin/PluginLifecycleService.php',
            'server/app/common/services/installation/InstallationExecutionHost.php', 'server/database/install.php',
            'server/docker/scripts/update-plan.php', 'server/docker/scripts/update-recovery.py',
            'scripts/product-upgrade-host', 'scripts/scaffold-runtime/ScaffoldUpgradeRunner.php',
            'plugins/official.file/plugin.json', 'plugins.lock', 'scaffold/application-template-inventory.json',
            'release-versions.json', 'RELEASE_METADATA.json',
        ], true);
        $old = $from->files(); $new = $to->files();
        foreach (array_unique([...array_keys($old), ...array_keys($new)]) as $path) {
            if (($old[$path] ?? null) === ($new[$path] ?? null)) { continue; }
            if (!isset($allowed[$path]) || !isset($new[$path])) { throw new RuntimeException('INTERNAL_PREINSTALL_SOURCE_DELTA_UNSUPPORTED: ' . $path); }
            if (isset($old[$path])) {
                $left = $old[$path]; $right = $new[$path]; unset($left['template_sha256'], $right['template_sha256']);
                if ($left !== $right) { throw new RuntimeException('INTERNAL_PREINSTALL_OWNERSHIP_CHANGED: ' . $path); }
            }
        }
        $prefix = 'server/app/modules/official/file/database/migrations/';
        $sql = array_filter($old, static fn(string $path): bool => str_starts_with($path, $prefix) && str_ends_with($path, '.sql'), ARRAY_FILTER_USE_KEY);
        $targetSql = array_filter($new, static fn(string $path): bool => str_starts_with($path, $prefix) && str_ends_with($path, '.sql'), ARRAY_FILTER_USE_KEY);
        if (count($sql) !== 9 || $sql !== $targetSql) { throw new RuntimeException('INTERNAL_PREINSTALL_ORIGINAL_FILE_SQL_CHANGED'); }
    }

    public function preflight(string $projectRoot, string $fromManifestPath, string $toManifestPath, ?array $sourceSelection = null): array
    {
        $root = ScaffoldPathGuard::projectRoot($projectRoot);
        $plan = $this->preview($root, $fromManifestPath, $toManifestPath, $sourceSelection);
        if ($this->changesNotificationExecution($plan)) {
            $plan['notification_preflight'] = $this->notificationExecutionPreflight($root);
            if (($plan['notification_preflight']['may_apply'] ?? false) !== true) {
                $plan['status'] = 'blocked';
            }
        }
        $stateRoot = ScaffoldPathGuard::projectPath($root, '.peanut/upgrades');
        $path = $stateRoot . '/plans/' . $plan['candidate'] . '.json';
        $this->writeJsonAtomic($path, $plan, 0600);
        $ledger = new ScaffoldUpgradeLedger($stateRoot . '/ledger.ndjson');
        if (!$this->hasEvent($ledger, $plan['candidate'], 'preflight', $plan['status'])) {
            $ledger->append($this->event(
                $plan,
                'preflight',
                $plan['status'],
                $plan['identity']['managed_pre_sha256'],
                null,
            ));
        }
        return $plan + ['plan_path' => $this->relative($root, $path)];
    }

    /** Resolve every blocked managed-file conflict through an explicit preserve/replace decision. */
    public function resolve(
        string $projectRoot,
        string $planPath,
        string $confirmedPlanSha256,
        array $preservePaths,
        array $replacePaths,
    ): array {
        return $this->locked($projectRoot, function (string $root) use ($planPath, $confirmedPlanSha256, $preservePaths, $replacePaths): array {
            $plan = $this->loadPlan($root, $planPath);
            if (($plan['status'] ?? null) !== 'blocked') {
                throw new RuntimeException('SCAFFOLD_RESOLUTION_PLAN_NOT_BLOCKED');
            }
            $this->assertPlanFresh($root, $plan);
            $resolved = $this->resolveConflicts($plan, $confirmedPlanSha256, $preservePaths, $replacePaths);
            if (($resolved['status'] ?? null) !== 'ready') {
                throw new RuntimeException('SCAFFOLD_MODULE_COMPATIBILITY_BLOCKED');
            }
            $stateRoot = ScaffoldPathGuard::projectPath($root, '.peanut/upgrades');
            $path = $stateRoot . '/plans/' . $resolved['candidate'] . '.json';
            $this->writeJsonAtomic($path, $resolved, 0600);
            $ledger = new ScaffoldUpgradeLedger($stateRoot . '/ledger.ndjson');
            if (!$this->hasEvent($ledger, $resolved['candidate'], 'resolve', 'ready')) {
                $ledger->append($this->event(
                    $resolved,
                    'resolve',
                    'ready',
                    $resolved['identity']['managed_pre_sha256'],
                    null,
                    ['resolution' => $resolved['resolution']],
                ));
            }
            return $resolved + ['plan_path' => $this->relative($root, $path)];
        });
    }

    /** Build a metadata-only ownership-adoption plan from an authenticated formal package. */
    public function adoptionPlan(string $projectRoot, string $packageRoot): array
    {
        $root = ScaffoldPathGuard::projectRoot($projectRoot);
        $prepared = (new EditionUpgradePackage())->prepareAdoption($root, $packageRoot);
        $plan = $this->buildAdoptionPlan($root, $prepared, $packageRoot);
        $stateRoot = ScaffoldPathGuard::projectPath($root, '.peanut/upgrades');
        $path = $stateRoot . '/plans/' . $plan['candidate'] . '.json';
        $this->writeJsonAtomic($path, $plan, 0600);
        $ledger = new ScaffoldUpgradeLedger($stateRoot . '/ledger.ndjson');
        if (!$this->hasEvent($ledger, $plan['candidate'], 'adoption-plan', 'ready')) {
            $ledger->append($this->event($plan, 'adoption-plan', 'ready', $plan['identity']['managed_pre_sha256'], null));
        }
        return $plan + ['plan_path' => $this->relative($root, $path)];
    }

    /** Apply only authenticated old baselines and ownership metadata; live application bytes are never replaced. */
    public function adoptionApply(
        string $projectRoot,
        string $planPath,
        string $confirmedPlanSha256,
        array $confirmedPaths,
    ): array {
        return $this->locked($projectRoot, function (string $root) use ($planPath, $confirmedPlanSha256, $confirmedPaths): array {
            $plan = $this->loadAdoptionPlan($root, $planPath);
            $this->runtimeSourceGuard($root, $plan, 'adoption-apply');
            if (!hash_equals($plan['plan_sha256'], $confirmedPlanSha256)
                || array_values($confirmedPaths) !== $plan['paths']) {
                throw new RuntimeException('SCAFFOLD_ADOPTION_CONFIRMATION_MISMATCH');
            }
            $ledger = $this->ledger($root);
            if ($this->candidateState($ledger, $plan['candidate']) === 'adopted') {
                return ['status' => 'adopted', 'candidate' => $plan['candidate'], 'idempotent' => true];
            }
            $prepared = (new EditionUpgradePackage())->prepareAdoption(
                $root,
                $plan['formal_package']['root'],
            );
            $expected = $this->buildAdoptionPlan(
                $root,
                $prepared,
                $plan['formal_package']['root'],
            );
            if (!hash_equals($plan['candidate'], $expected['candidate'])) {
                throw new RuntimeException('SCAFFOLD_ADOPTION_PLAN_REBIND_FAILED');
            }
            $recovery = $this->createRecoveryForPaths(
                $root,
                $plan['candidate'],
                $plan['metadata_writes'],
            );
            $ledger->append($this->event($plan, 'adoption-apply', 'started', $plan['identity']['managed_pre_sha256'], null));
            try {
                $writes = 0;
                foreach ($plan['actions'] as $action) {
                    $source = ScaffoldPathGuard::existingFileWithin(
                        $plan['formal_package']['root'],
                        $action['old']['source'],
                        'SCAFFOLD_ADOPTION_SOURCE_INVALID',
                    );
                    $content = file_get_contents($source);
                    if (!is_string($content) || !hash_equals($action['old']['sha256'], hash('sha256', $content))) {
                        throw new RuntimeException('SCAFFOLD_ADOPTION_SOURCE_DRIFT: ' . $action['path']);
                    }
                    $this->writeFileAtomic(
                        ScaffoldPathGuard::projectPath($root, $action['baseline_path']),
                        $content,
                        0644,
                    );
                    $writes++;
                    $this->adoptionFault($writes);
                }
                $manifest = $this->nextAdoptedApplicationManifest($root, $plan);
                $this->writeJsonAtomic(ScaffoldPathGuard::projectPath($root, '.peanut/application-manifest.json'), $manifest, 0644);
                $writes++;
                $this->adoptionFault($writes);
                $post = $this->managedDigestFromManifest($root, $manifest);
                $ledger->append($this->event($plan, 'adoption-apply', 'adopted', $plan['identity']['managed_pre_sha256'], $post, ['recovery' => $recovery]));
                return ['status' => 'adopted', 'candidate' => $plan['candidate'], 'managed_post_sha256' => $post, 'recovery' => $recovery, 'idempotent' => false];
            } catch (Throwable $exception) {
                $ledger->append($this->event($plan, 'adoption-apply', 'failed', $plan['identity']['managed_pre_sha256'], null, ['error' => $exception->getMessage(), 'recovery' => $recovery]));
                throw $exception;
            }
        });
    }

    public function adoptionRecover(string $projectRoot, string $planPath): array
    {
        return $this->locked($projectRoot, function (string $root) use ($planPath): array {
            $plan = $this->loadAdoptionPlan($root, $planPath);
            return $this->recoverCandidate($root, $plan, 'adoption-recover');
        });
    }

    /** Bind an equivalent development source to an already published release without changing application bytes. */
    public function releaseAdoptionPlan(
        string $projectRoot,
        string $releaseManifestPath,
        string $repository,
        string $requestedRef,
        string $channel,
        string $releaseVersion,
        string $sourceCommit,
        string $sourceTree,
        string $releaseLockPath,
        string $releaseLockSha256,
    ): array {
        $root = ScaffoldPathGuard::projectRoot($projectRoot);
        $plan = $this->previewReleaseAdoption(
            $root,
            $releaseManifestPath,
            $repository,
            $requestedRef,
            $channel,
            $releaseVersion,
            $sourceCommit,
            $sourceTree,
            $releaseLockPath,
            $releaseLockSha256,
        );
        $stateRoot = ScaffoldPathGuard::projectPath($root, '.peanut/upgrades');
        $path = $stateRoot . '/plans/' . $plan['candidate'] . '.json';
        $this->writeJsonAtomic($path, $plan, 0600);
        $ledger = new ScaffoldUpgradeLedger($stateRoot . '/ledger.ndjson');
        if (!$this->hasEvent($ledger, $plan['candidate'], 'release-adoption-plan', 'ready')) {
            $ledger->append($this->event($plan, 'release-adoption-plan', 'ready', $plan['identity']['managed_pre_sha256'], null));
        }
        return $plan + ['plan_path' => $this->relative($root, $path)];
    }

    public function releaseAdoptionApply(string $projectRoot, string $planPath): array
    {
        return $this->locked($projectRoot, function (string $root) use ($planPath): array {
            $plan = $this->loadReleaseAdoptionPlan($root, $planPath);
            $this->runtimeSourceGuard($root, $plan, 'release-adoption-apply');
            $ledger = $this->ledger($root);
            if (in_array($this->candidateState($ledger, $plan['candidate']), ['applied', 'verified'], true)) {
                $this->assertReleaseAdoptionApplied($root, $plan);
                return ['status' => 'applied', 'candidate' => $plan['candidate'], 'idempotent' => true];
            }
            $proof = $plan['identity']['source_proof'];
            $expected = $this->previewReleaseAdoption(
                $root,
                $plan['manifest_paths']['to'],
                $proof['repository'],
                $proof['requested_ref'],
                $proof['channel'],
                $proof['release_version'],
                $proof['commit'],
                $proof['tree'],
                $proof['release_lock_path'],
                $proof['release_lock_sha256'],
            );
            if ($expected['candidate'] !== $plan['candidate'] || $expected['plan_sha256'] !== $plan['plan_sha256']) {
                throw new RuntimeException('SCAFFOLD_RELEASE_ADOPTION_PLAN_REBIND_FAILED');
            }
            $recovery = $this->createRecoveryForPaths($root, $plan['candidate'], ['.peanut/application-manifest.json']);
            $ledger->append($this->event($plan, 'release-adoption-apply', 'started', $plan['identity']['managed_pre_sha256'], null));
            try {
                [$application] = $this->applicationManifest($root);
                $seal = $plan['identity']['release_seal'];
                $application['template']['source_commit'] = $seal['source_commit'];
                $application['template']['source_tree'] = $seal['source_tree'];
                $application['template']['inventory_sha256'] = $seal['inventory_sha256'];
                $application['generation_source'] = $plan['identity']['adopted_generation_source'];
                $application['last_release_adoption'] = [
                    'candidate' => $plan['candidate'],
                    'release_version' => $seal['version'],
                    'release_manifest_sha256' => $seal['manifest_sha256'],
                ];
                $this->writeJsonAtomic(ScaffoldPathGuard::projectPath($root, '.peanut/application-manifest.json'), $application, 0644);
                $this->assertReleaseAdoptionApplied($root, $plan);
                $post = $this->managedDigestFromManifest($root, $application);
                $ledger->append($this->event($plan, 'release-adoption-apply', 'applied', $plan['identity']['managed_pre_sha256'], $post, ['recovery' => $recovery]));
                return ['status' => 'applied', 'candidate' => $plan['candidate'], 'managed_post_sha256' => $post, 'recovery' => $recovery, 'idempotent' => false];
            } catch (Throwable $exception) {
                $ledger->append($this->event($plan, 'release-adoption-apply', 'failed', $plan['identity']['managed_pre_sha256'], null, ['error' => $exception->getMessage(), 'recovery' => $recovery]));
                throw $exception;
            }
        });
    }

    public function releaseAdoptionVerify(string $projectRoot, string $planPath): array
    {
        return $this->locked($projectRoot, function (string $root) use ($planPath): array {
            $plan = $this->loadReleaseAdoptionPlan($root, $planPath);
            $ledger = $this->ledger($root);
            if (!in_array($this->candidateState($ledger, $plan['candidate']), ['applied', 'verified'], true)) {
                throw new RuntimeException('SCAFFOLD_RELEASE_ADOPTION_NOT_APPLIED');
            }
            $this->assertReleaseAdoptionApplied($root, $plan);
            if ($this->candidateState($ledger, $plan['candidate']) === 'verified') {
                return ['status' => 'verified', 'candidate' => $plan['candidate'], 'idempotent' => true];
            }
            [$application] = $this->applicationManifest($root);
            $post = $this->managedDigestFromManifest($root, $application);
            $ledger->append($this->event($plan, 'release-adoption-verify', 'verified', $plan['identity']['managed_pre_sha256'], $post));
            return ['status' => 'verified', 'candidate' => $plan['candidate'], 'managed_post_sha256' => $post, 'idempotent' => false];
        });
    }

    public function releaseAdoptionRecover(string $projectRoot, string $planPath): array
    {
        return $this->locked($projectRoot, function (string $root) use ($planPath): array {
            $plan = $this->loadReleaseAdoptionPlan($root, $planPath);
            return $this->recoverCandidate($root, $plan, 'release-adoption-recover');
        });
    }

    /** Apply only the bytes and identities frozen by a fresh, ready plan. */
    public function apply(string $projectRoot, string $planPath): array
    {
        return $this->locked($projectRoot, function (string $root) use ($planPath): array {
            $plan = $this->loadPlan($root, $planPath);
            $this->runtimeSourceGuard($root, $plan, 'apply');
            $ledger = $this->ledger($root);
            if ($plan['status'] !== 'ready') {
                throw new RuntimeException('SCAFFOLD_PLAN_BLOCKED');
            }
            if (in_array($this->candidateState($ledger, $plan['candidate']), ['applied', 'verified'], true)) {
                $this->assertAppliedState($root, $plan);
                return ['status' => 'applied', 'candidate' => $plan['candidate'], 'idempotent' => true];
            }
            $this->assertPlanFresh($root, $plan);
            $this->assertPlanRebound($root, $plan);
            $to = ScaffoldManifest::load($plan['manifest_paths']['to']);
            $this->assertManifestDigest($to, $plan['identity']['to']['manifest_sha256']);
            // Inspect the old APP before replacing source. Missing native proof remains unknown.
            if ($this->changesNotificationExecution($plan)) {
                $notification = $this->notificationExecutionPreflight($root);
                if (($notification['may_apply'] ?? false) !== true) {
                    throw new RuntimeException($notification['code']);
                }
            }
            $recovery = $this->createRecovery($root, $plan);
            $pre = $plan['identity']['managed_pre_sha256'];
            $ledger->append($this->event($plan, 'apply', 'started', $pre, null));
            try {
                $writes = 0;
                foreach ($plan['actions'] as $action) {
                    if (!in_array($action['action'], ['rename', 'rename-preserve'], true)) {
                        continue;
                    }
                    $this->assertActionFresh($root, $action);
                    $artifact = $this->targetContent($to, $action, $plan);
                    $targetPath = ScaffoldPathGuard::projectPath($root, $action['path']);
                    if ($action['action'] === 'rename-preserve') {
                        if (($action['rename_preserve'] ?? null) === 'source') {
                            $sourcePath = ScaffoldPathGuard::projectPath($root, (string) $action['rename_from']);
                            $sourceContent = file_get_contents($sourcePath);
                            if (!is_string($sourceContent)) {
                                throw new RuntimeException('SCAFFOLD_RENAME_SOURCE_READ_FAILED: ' . $action['rename_from']);
                            }
                            $this->writeFileAtomic($targetPath, $sourceContent, (int) $action['rename_from_current']['mode']);
                        } elseif (($action['rename_preserve'] ?? null) !== 'target') {
                            throw new RuntimeException('SCAFFOLD_RENAME_PRESERVE_INVALID: ' . $action['path']);
                        }
                    } else {
                        $this->writeFileAtomic($targetPath, $artifact, (int) $action['mode']);
                    }
                    $baseline = '.peanut/scaffold-baseline/' . $to->version() . '/files/' . $action['path'];
                    $this->writeFileAtomic(ScaffoldPathGuard::projectPath($root, $baseline), $artifact, 0644);
                    $sourcePath = ScaffoldPathGuard::projectPath($root, (string) $action['rename_from']);
                    if (file_exists($sourcePath)) {
                        if (!is_file($sourcePath) || is_link($sourcePath) || !unlink($sourcePath)) {
                            throw new RuntimeException('SCAFFOLD_ATOMIC_RENAME_SOURCE_DELETE_FAILED: ' . $action['rename_from']);
                        }
                        $this->pruneEmptyParents(dirname($sourcePath), $root);
                    }
                    $writes++;
                    if ($this->failAfterReplacements !== null && $writes >= $this->failAfterReplacements) {
                        throw new RuntimeException('SCAFFOLD_FAULT_INJECTED');
                    }
                }
                foreach ($plan['actions'] as $action) {
                    if ($action['action'] !== 'delete') {
                        continue;
                    }
                    $this->assertActionFresh($root, $action);
                    $target = ScaffoldPathGuard::projectPath($root, $action['path']);
                    if (!unlink($target)) {
                        throw new RuntimeException('SCAFFOLD_ATOMIC_DELETE_FAILED: ' . $action['path']);
                    }
                    $this->pruneEmptyParents(dirname($target), $root);
                    $writes++;
                    if ($this->failAfterReplacements !== null && $writes >= $this->failAfterReplacements) {
                        throw new RuntimeException('SCAFFOLD_FAULT_INJECTED');
                    }
                }
                foreach ($plan['actions'] as $action) {
                    if (!in_array($action['action'], ['create', 'replace', 'regenerate'], true)) {
                        continue;
                    }
                    $this->assertActionFresh($root, $action);
                    $artifact = $this->targetContent($to, $action, $plan);
                    $this->writeFileAtomic(ScaffoldPathGuard::projectPath($root, $action['path']), $artifact, (int) $action['mode']);
                    $baseline = '.peanut/scaffold-baseline/' . $to->version() . '/files/' . $action['path'];
                    $this->writeFileAtomic(ScaffoldPathGuard::projectPath($root, $baseline), $artifact, 0644);
                    $writes++;
                    if ($this->failAfterReplacements !== null && $writes >= $this->failAfterReplacements) {
                        throw new RuntimeException('SCAFFOLD_FAULT_INJECTED');
                    }
                }
                foreach ($plan['actions'] as $action) {
                    if ($action['action'] !== 'preserve') {
                        continue;
                    }
                    $this->assertActionFresh($root, $action);
                    $artifact = $this->baselineContent($root, $to, $action, $plan);
                    $baseline = '.peanut/scaffold-baseline/' . $to->version() . '/files/' . $action['path'];
                    $this->writeFileAtomic(ScaffoldPathGuard::projectPath($root, $baseline), $artifact, 0644);
                }
                $this->assertPluginProjection($root, $plan, true);
                $manifest = $this->nextApplicationManifest($root, $plan, $to);
                $this->writeJsonAtomic(ScaffoldPathGuard::projectPath($root, '.peanut/application-manifest.json'), $manifest, 0644);
                $post = $this->managedDigestFromManifest($root, $manifest);
                $ledger->append($this->event($plan, 'apply', 'applied', $pre, $post, ['recovery' => $recovery]));
                return ['status' => 'applied', 'candidate' => $plan['candidate'], 'managed_post_sha256' => $post, 'recovery' => $recovery, 'idempotent' => false];
            } catch (Throwable $exception) {
                $ledger->append($this->event($plan, 'apply', 'failed', $pre, null, ['error' => $exception->getMessage(), 'recovery' => $recovery]));
                throw $exception;
            }
        });
    }

    /** Verify the target scaffold and its newly adopted current-version snapshot. */
    public function verify(string $projectRoot, string $planPath): array
    {
        return $this->locked($projectRoot, function (string $root) use ($planPath): array {
            $plan = $this->loadPlan($root, $planPath);
            $this->runtimeSourceGuard($root, $plan, 'verify');
            $ledger = $this->ledger($root);
            if ($this->candidateState($ledger, $plan['candidate']) === 'verified') {
                $this->assertAppliedState($root, $plan);
                return ['status' => 'verified', 'candidate' => $plan['candidate'], 'idempotent' => true];
            }
            if (!in_array($this->candidateState($ledger, $plan['candidate']), ['applied','verified'], true)) {
                throw new RuntimeException('SCAFFOLD_APPLY_NOT_COMMITTED');
            }
            [$application, $actualAppOwned] = $this->assertAppliedState($root, $plan);
            $post = $this->managedDigestFromManifest($root, $application);
            $ledger->append($this->event($plan, 'verify', 'verified', $plan['identity']['managed_pre_sha256'], $post));
            return ['status' => 'verified', 'candidate' => $plan['candidate'], 'managed_post_sha256' => $post, 'app_owned_sha256' => $actualAppOwned['digest'], 'idempotent' => false];
        });
    }

    public function recover(string $projectRoot, string $planPath): array
    {
        return $this->locked($projectRoot, function (string $root) use ($planPath): array {
            $plan = $this->loadPlan($root, $planPath);
            $this->runtimeSourceGuard($root, $plan, 'recover');
            $ledger = $this->ledger($root);
            $manifestPath = ScaffoldPathGuard::projectPath($root, '.peanut/upgrades/backups/' . $plan['candidate'] . '/recovery.json');
            if (!is_file($manifestPath)) {
                throw new RuntimeException('SCAFFOLD_RECOVERY_NOT_FOUND');
            }
            $recovery = json_decode((string) file_get_contents($manifestPath), true, 512, JSON_THROW_ON_ERROR);
            if (!is_array($recovery) || ($recovery['candidate'] ?? null) !== $plan['candidate'] || !is_array($recovery['files'] ?? null)
                || !hash_equals((string) ($recovery['pre_tree_sha256'] ?? ''), 'sha256:' . hash('sha256', self::canonicalJson($recovery['files'])))) {
                throw new RuntimeException('SCAFFOLD_RECOVERY_INVALID');
            }
            $already = $this->recoveryMatches($root, $recovery);
            if (!$already) {
                foreach ($recovery['files'] as $relative => $state) {
                    $target = ScaffoldPathGuard::projectPath($root, (string) $relative);
                    if ($state['present']) {
                        $backup = ScaffoldPathGuard::existingFileWithin(dirname($manifestPath), dirname($manifestPath) . '/' . $state['backup'], 'SCAFFOLD_RECOVERY_BACKUP_INVALID');
                        $content = file_get_contents($backup);
                        if (!is_string($content) || !hash_equals($state['sha256'], hash('sha256', $content))
                            || (fileperms($backup) & 0777) !== 0600) {
                            throw new RuntimeException('SCAFFOLD_RECOVERY_BACKUP_DRIFT');
                        }
                        $this->writeFileAtomic($target, $content, (int) $state['mode']);
                    } elseif (file_exists($target)) {
                        if (!is_file($target) || is_link($target)) {
                            throw new RuntimeException('SCAFFOLD_RECOVERY_PATH_COLLISION: ' . $relative);
                        }
                        unlink($target);
                        $this->pruneEmptyParents(dirname($target), $root);
                    }
                }
                if (!$this->recoveryMatches($root, $recovery)) {
                    throw new RuntimeException('SCAFFOLD_RECOVERY_VERIFY_FAILED');
                }
            }
            $this->assertPluginProjection($root, $plan);
            if (!$this->hasEvent($ledger, $plan['candidate'], 'recover', 'recovered')) {
                $ledger->append($this->event($plan, 'recover', 'recovered', $plan['identity']['managed_pre_sha256'], $plan['identity']['managed_pre_sha256']));
            }
            return ['status' => 'recovered', 'candidate' => $plan['candidate'], 'tree_sha256' => $recovery['pre_tree_sha256'], 'idempotent' => $already];
        });
    }

    /** Verify an already recovered tree without rewriting any application file. */
    public function verifyRecovery(string $projectRoot, string $planPath): array
    {
        return $this->locked($projectRoot, function (string $root) use ($planPath): array {
            $plan = $this->loadPlan($root, $planPath);
            $manifestPath = ScaffoldPathGuard::projectPath(
                $root,
                '.peanut/upgrades/backups/' . $plan['candidate'] . '/recovery.json',
            );
            if (!is_file($manifestPath) || is_link($manifestPath)) {
                throw new RuntimeException('SCAFFOLD_RECOVERY_NOT_FOUND');
            }
            $recovery = json_decode((string) file_get_contents($manifestPath), true, 512, JSON_THROW_ON_ERROR);
            if (!is_array($recovery) || ($recovery['candidate'] ?? null) !== $plan['candidate']
                || !is_array($recovery['files'] ?? null)
                || !hash_equals(
                    (string) ($recovery['pre_tree_sha256'] ?? ''),
                    'sha256:' . hash('sha256', self::canonicalJson($recovery['files'])),
                )
                || !$this->recoveryMatches($root, $recovery)) {
                throw new RuntimeException('SCAFFOLD_RECOVERY_VERIFY_FAILED');
            }
            $this->assertPluginProjection($root, $plan);
            return [
                'status' => 'recovered',
                'candidate' => $plan['candidate'],
                'tree_sha256' => $recovery['pre_tree_sha256'],
                'read_only' => true,
            ];
        });
    }

    private function previewReleaseAdoption(
        string $root,
        string $releaseManifestPath,
        string $repository,
        string $requestedRef,
        string $channel,
        string $releaseVersion,
        string $sourceCommit,
        string $sourceTree,
        string $releaseLockPath,
        string $releaseLockSha256,
    ): array {
        if ($repository !== 'peanut-business/peanut-admin-code'
            || !is_string($requestedRef) || $requestedRef === '' || strlen($requestedRef) > 255
            || preg_match('/[\x00-\x20\x7f]/', $requestedRef) === 1
            || !in_array($channel, ['prerelease', 'stable'], true)
            || preg_match(self::STRICT_SEMVER, $releaseVersion) !== 1
            || ($channel === 'stable' && str_contains($releaseVersion, '-'))
            || ($channel === 'prerelease' && !str_contains($releaseVersion, '-'))
            || preg_match('/^[a-f0-9]{40}$/D', $sourceCommit) !== 1
            || preg_match('/^[a-f0-9]{40}$/D', $sourceTree) !== 1
            || preg_match('/^sha256:[a-f0-9]{64}$/D', $releaseLockSha256) !== 1) {
            throw new RuntimeException('SCAFFOLD_RELEASE_ADOPTION_PROOF_INVALID');
        }
        $release = ScaffoldManifest::load($releaseManifestPath);
        $sourceRoot = realpath(dirname($release->directory, 3));
        if (!is_string($sourceRoot)
            || $release->path !== $sourceRoot . '/scaffold/releases/v' . $releaseVersion . '/scaffold-manifest.json'
            || $release->version() !== $releaseVersion
            || ($release->release()['inventory_template_version'] ?? null) !== $releaseVersion
            || ($release->data['edition'] ?? null) !== null) {
            throw new RuntimeException('SCAFFOLD_RELEASE_ADOPTION_MANIFEST_INVALID');
        }
        $this->validatePublishedReleaseLock(
            $root,
            $releaseLockPath,
            $releaseLockSha256,
            $releaseVersion,
            $sourceCommit,
            $sourceTree,
            $release->release()['inventory_sha256'],
            $release->digest(),
        );
        $lockPath = ScaffoldPathGuard::existingFileWithin($root, $releaseLockPath, 'SCAFFOLD_RELEASE_ADOPTION_LOCK_INVALID');
        if (!str_starts_with($lockPath, $root . '/.peanut/upgrades/release-proofs/')) {
            throw new RuntimeException('SCAFFOLD_RELEASE_ADOPTION_LOCK_INVALID');
        }
        if ($this->releaseGit($sourceRoot, ['status', '--porcelain=v1', '--untracked-files=all']) !== ''
            || $this->releaseGit($sourceRoot, ['rev-parse', 'HEAD']) !== $sourceCommit
            || $this->releaseGit($sourceRoot, ['rev-parse', 'HEAD^{tree}']) !== $sourceTree
            || $this->releaseGit($sourceRoot, ['rev-parse', $release->release()['source_commit'] . '^{tree}']) !== $release->release()['source_tree']) {
            throw new RuntimeException('SCAFFOLD_RELEASE_ADOPTION_SOURCE_CHANGED');
        }
        $origin = $this->releaseGit($sourceRoot, ['remote', 'get-url', 'origin']);
        if (!in_array($origin, [
            'git@github.com:peanut-business/peanut-admin-code.git',
            'https://github.com/peanut-business/peanut-admin-code.git',
            'https://github.com/peanut-business/peanut-admin-code',
        ], true)) {
            throw new RuntimeException('SCAFFOLD_RELEASE_ADOPTION_REPOSITORY_MISMATCH');
        }
        $this->releaseGit($sourceRoot, ['merge-base', '--is-ancestor', $release->release()['source_commit'], $sourceCommit]);
        $inventoryDigest = hash_file('sha256', $sourceRoot . '/scaffold/application-template-inventory.json');
        if (!is_string($inventoryDigest) || !hash_equals($release->release()['inventory_sha256'], $inventoryDigest)) {
            throw new RuntimeException('SCAFFOLD_RELEASE_ADOPTION_INVENTORY_MISMATCH');
        }
        foreach ($release->files() as $path => $file) {
            $artifact = $release->artifactPath($file);
            $digest = hash_file('sha256', $artifact);
            if (!is_string($digest) || !hash_equals((string) $file['template_sha256'], $digest)
                || (fileperms($artifact) & 0777) !== $file['mode']) {
                throw new RuntimeException('SCAFFOLD_RELEASE_ADOPTION_ARTIFACT_CHANGED: ' . $path);
            }
        }
        [$application, $applicationDigest] = $this->applicationManifest($root);
        $generation = $application['generation_source'] ?? null;
        if (!is_array($generation)
            || ($generation['repository'] ?? null) !== $repository
            || !is_string($generation['requested_ref'] ?? null)
            || $generation['requested_ref'] === ''
            || !array_key_exists('release_version', $generation)
            || $generation['release_version'] !== null
            || ($generation['channel'] ?? null) !== 'development'
            || preg_match('/^[a-f0-9]{40}$/D', (string) ($generation['commit'] ?? '')) !== 1
            || ($generation['tree'] ?? null) !== $sourceTree
            || ($generation['inventory_sha256'] ?? null) !== $inventoryDigest
            || ($application['template']['version'] ?? null) !== $releaseVersion
            || ($application['template']['inventory_sha256'] ?? null) !== $inventoryDigest
            || ($application['edition']['source_sha256'] ?? null) !== ($generation['edition_profile_sha256'] ?? null)) {
            throw new RuntimeException('SCAFFOLD_RELEASE_ADOPTION_CONTENT_DIFFERS');
        }
        [$versionContract, $versionContractDigest] = $this->versionContract($root, $application);
        $releaseFiles = $release->files();
        $appManagedFiles = [];
        foreach ($application['files'] as $file) {
            if (in_array($file['classification'] ?? null, ['managed', 'generated-managed'], true)) {
                $appManagedFiles[$file['path']] = $file;
            }
        }
        if (count($releaseFiles) !== count($appManagedFiles)) {
            throw new RuntimeException('SCAFFOLD_RELEASE_ADOPTION_BASELINE_SET_DIFFERS');
        }
        foreach ($releaseFiles as $path => $releasedFile) {
            $owned = $appManagedFiles[$path] ?? null;
            if (!is_array($owned)
                || ($owned['classification'] ?? null) !== $releasedFile['classification']
                || ($owned['mode'] ?? null) !== $releasedFile['mode']) {
                throw new RuntimeException('SCAFFOLD_RELEASE_ADOPTION_BASELINE_METADATA_DIFFERS: ' . $path);
            }
        }
        $managed = $this->releaseManagedState($root, $application);
        $baseline = $this->releaseBaselineState($root, $application);
        $appOwned = $this->ownershipState($root, $application, 'app-owned');
        $adoptedGeneration = $generation;
        $adoptedGeneration['repository'] = $repository;
        $adoptedGeneration['requested_ref'] = $requestedRef;
        $adoptedGeneration['channel'] = $channel;
        $adoptedGeneration['release_version'] = $releaseVersion;
        $adoptedGeneration['commit'] = $sourceCommit;
        $adoptedGeneration['tree'] = $sourceTree;
        $identity = [
            'kind' => 'release-adoption',
            'metadata_writes' => ['.peanut/application-manifest.json'],
            'from' => ['version' => $application['template']['version'], 'generation_source' => $generation],
            'to' => ['version' => $releaseVersion, 'release_version' => $releaseVersion, 'source_commit' => $sourceCommit,
                'source_tree' => $sourceTree, 'inventory_sha256' => $inventoryDigest],
            'release_seal' => ['version' => $releaseVersion, 'source_commit' => $release->release()['source_commit'],
                'source_tree' => $release->release()['source_tree'], 'inventory_sha256' => $inventoryDigest,
                'manifest_sha256' => $release->digest()],
            'source_proof' => ['repository' => $repository, 'requested_ref' => $requestedRef, 'channel' => $channel,
                'release_version' => $releaseVersion, 'commit' => $sourceCommit, 'tree' => $sourceTree,
                'release_lock_path' => $lockPath, 'release_lock_sha256' => $releaseLockSha256],
            'adopted_generation_source' => $adoptedGeneration,
            'application_manifest_sha256' => $applicationDigest,
            'version_contract_sha256' => $versionContractDigest,
            'version_contract' => $versionContract,
            'application_identity' => $application['application'],
            'edition' => $application['edition'],
            'files_sha256' => 'sha256:' . hash('sha256', self::canonicalJson($application['files'])),
            'digests' => $application['digests'],
            'managed_pre_sha256' => $managed['digest'],
            'baseline_pre_sha256' => $baseline['digest'],
            'app_owned_pre_sha256' => $appOwned['digest'],
        ];
        $digest = hash('sha256', self::canonicalJson([$identity, []]));
        return [
            'schema_version' => 2, 'protocol' => 'peanut.scaffold-upgrade-plan.v2',
            'candidate' => 'scaffold-' . substr($digest, 0, 24), 'plan_sha256' => 'sha256:' . $digest,
            'status' => 'ready', 'identity' => $identity,
            'manifest_paths' => ['to' => $release->path], 'summary' => $this->summary([]),
            'impact' => $this->impact([]), 'managed_pre_state' => $managed['files'],
            'baseline_pre_state' => $baseline['files'], 'app_owned_pre_state' => $appOwned['files'],
            'actions' => [],
        ];
    }

    private function validatePublishedReleaseLock(
        string $root,
        string $releaseLockPath,
        string $releaseLockSha256,
        string $releaseVersion,
        string $sourceCommit,
        string $sourceTree,
        string $inventorySha256,
        ?string $manifestSha256,
    ): string {
        $lockPath = ScaffoldPathGuard::existingFileWithin($root, $releaseLockPath, 'SCAFFOLD_RELEASE_ADOPTION_LOCK_INVALID');
        if (!str_starts_with($lockPath, $root . '/.peanut/upgrades/release-proofs/')) {
            throw new RuntimeException('SCAFFOLD_RELEASE_ADOPTION_LOCK_INVALID');
        }
        $lockRaw = file_get_contents($lockPath);
        if (!is_string($lockRaw) || !hash_equals($releaseLockSha256, 'sha256:' . hash('sha256', $lockRaw))) {
            throw new RuntimeException('SCAFFOLD_RELEASE_ADOPTION_LOCK_CHANGED');
        }
        try {
            $lock = json_decode($lockRaw, true, 128, JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            throw new RuntimeException('SCAFFOLD_RELEASE_ADOPTION_LOCK_INVALID', 0, $exception);
        }
        if (!is_array($lock) || ($lock['schema_version'] ?? null) !== 1
            || ($lock['protocol'] ?? null) !== 'peanut.release-candidate-lock.v1'
            || ($lock['version'] ?? null) !== $releaseVersion
            || ($lock['tag'] ?? null) !== 'v' . $releaseVersion
            || ($lock['candidate']['commit'] ?? null) !== $sourceCommit
            || ($lock['candidate']['tree'] ?? null) !== $sourceTree
            || ($lock['inputs']['application_template_inventory_sha256'] ?? null) !== $inventorySha256
            || ($manifestSha256 !== null
                && ($lock['inputs']['scaffold_manifest_sha256'] ?? null) !== substr($manifestSha256, 7))) {
            throw new RuntimeException('SCAFFOLD_RELEASE_ADOPTION_LOCK_INVALID');
        }
        return $lockPath;
    }

    private function releaseGit(string $root, array $arguments): string
    {
        $pipes = [];
        $process = proc_open(['git', '-C', $root, ...$arguments], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        if (!is_resource($process)) {
            throw new RuntimeException('SCAFFOLD_RELEASE_ADOPTION_GIT_UNAVAILABLE');
        }
        $output = stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        if (proc_close($process) !== 0 || !is_string($output)) {
            throw new RuntimeException('SCAFFOLD_RELEASE_ADOPTION_GIT_IDENTITY_INVALID');
        }
        return trim($output);
    }

    private function releaseManagedState(string $root, array $application): array
    {
        $files = [];
        foreach ($application['files'] as $file) {
            if (in_array($file['classification'] ?? null, ['managed', 'generated-managed'], true)) {
                $path = (string) $file['path'];
                $files[$path] = $this->regularFileState(ScaffoldPathGuard::projectPath($root, $path), $path);
            }
        }
        ksort($files, SORT_STRING);
        return ['files' => $files, 'digest' => 'sha256:' . hash('sha256', self::canonicalJson($files))];
    }

    private function releaseBaselineState(string $root, array $application): array
    {
        $files = [];
        foreach ($application['files'] as $file) {
            if (!in_array($file['classification'] ?? null, ['managed', 'generated-managed'], true)) {
                continue;
            }
            $path = (string) ($file['baseline_path'] ?? '');
            $expected = '.peanut/scaffold-baseline/' . $application['template']['version'] . '/files/' . $file['path'];
            if ($path !== $expected) {
                throw new RuntimeException('SCAFFOLD_RELEASE_ADOPTION_BASELINE_INVALID: ' . $file['path']);
            }
            $state = $this->regularFileState(ScaffoldPathGuard::projectPath($root, $path), $path);
            if (!$state['present'] || $state['mode'] !== 0644
                || !hash_equals((string) ($file['baseline_sha256'] ?? ''), (string) $state['sha256'])) {
                throw new RuntimeException('SCAFFOLD_RELEASE_ADOPTION_BASELINE_CHANGED: ' . $file['path']);
            }
            $files[$path] = $state;
        }
        ksort($files, SORT_STRING);
        return ['files' => $files, 'digest' => 'sha256:' . hash('sha256', self::canonicalJson($files))];
    }

    private function loadReleaseAdoptionPlan(string $root, string $path): array
    {
        $plan = $this->loadPlan($root, $path);
        if (($plan['identity']['kind'] ?? null) !== 'release-adoption' || $plan['actions'] !== []
            || ($plan['status'] ?? null) !== 'ready'
            || !is_array($plan['managed_pre_state'] ?? null)
            || !is_array($plan['baseline_pre_state'] ?? null)
            || !is_array($plan['app_owned_pre_state'] ?? null)
            || !is_array($plan['identity']['source_proof'] ?? null)
            || ($plan['identity']['managed_pre_sha256'] ?? null) !== 'sha256:' . hash('sha256', self::canonicalJson($plan['managed_pre_state']))
            || ($plan['identity']['baseline_pre_sha256'] ?? null) !== 'sha256:' . hash('sha256', self::canonicalJson($plan['baseline_pre_state']))
            || ($plan['identity']['app_owned_pre_sha256'] ?? null) !== 'sha256:' . hash('sha256', self::canonicalJson($plan['app_owned_pre_state']))) {
            throw new RuntimeException('SCAFFOLD_RELEASE_ADOPTION_PLAN_INVALID');
        }
        return $plan;
    }

    private function assertReleaseAdoptionApplied(string $root, array $plan): void
    {
        [$application] = $this->applicationManifest($root);
        $seal = $plan['identity']['release_seal'];
        if (($application['template']['version'] ?? null) !== $seal['version']
            || ($application['template']['source_commit'] ?? null) !== $seal['source_commit']
            || ($application['template']['source_tree'] ?? null) !== $seal['source_tree']
            || ($application['template']['inventory_sha256'] ?? null) !== $seal['inventory_sha256']
            || ($application['generation_source'] ?? null) !== $plan['identity']['adopted_generation_source']
            || ($application['last_release_adoption']['candidate'] ?? null) !== $plan['candidate']
            || ($application['last_release_adoption']['release_manifest_sha256'] ?? null) !== $seal['manifest_sha256']
            || ($application['application'] ?? null) !== $plan['identity']['application_identity']
            || ($application['edition'] ?? null) !== $plan['identity']['edition']
            || ($application['digests'] ?? null) !== $plan['identity']['digests']
            || 'sha256:' . hash('sha256', self::canonicalJson($application['files'])) !== $plan['identity']['files_sha256']
            || $this->versionContract($root, $application) !== [$plan['identity']['version_contract'], $plan['identity']['version_contract_sha256']]
            || $this->releaseManagedState($root, $application)['digest'] !== $plan['identity']['managed_pre_sha256']
            || $this->releaseBaselineState($root, $application)['digest'] !== $plan['identity']['baseline_pre_sha256']
            || $this->ownershipState($root, $application, 'app-owned')['digest'] !== $plan['identity']['app_owned_pre_sha256']) {
            throw new RuntimeException('SCAFFOLD_RELEASE_ADOPTION_VERIFY_FAILED');
        }
    }

    private function validateUpgradeSourceSelection(string $root, ?array $selection, ScaffoldManifest $to): ?array
    {
        if ($selection === null) {
            return null;
        }
        if (array_keys($selection) !== ['repository', 'requested_ref', 'channel', 'commit', 'tree', 'release_lock_path', 'release_lock_sha256']
            || $selection['repository'] !== 'peanut-business/peanut-admin-code'
            || !is_string($selection['requested_ref']) || $selection['requested_ref'] === ''
            || strlen($selection['requested_ref']) > 255
            || preg_match('/[\x00-\x20\x7f]/', $selection['requested_ref']) === 1
            || !in_array($selection['channel'], ['prerelease', 'stable'], true)
            || ($selection['channel'] === 'stable' && str_contains($to->version(), '-'))
            || ($selection['channel'] === 'prerelease' && !str_contains($to->version(), '-'))
            || preg_match('/^[a-f0-9]{40}$/D', (string) $selection['commit']) !== 1
            || preg_match('/^[a-f0-9]{40}$/D', (string) $selection['tree']) !== 1
            || !is_string($selection['release_lock_path'])
            || preg_match('/^sha256:[a-f0-9]{64}$/D', (string) $selection['release_lock_sha256']) !== 1) {
            throw new RuntimeException('SCAFFOLD_UPGRADE_SOURCE_SELECTION_INVALID');
        }
        $selection['release_lock_path'] = $this->validatePublishedReleaseLock(
            $root,
            $selection['release_lock_path'],
            $selection['release_lock_sha256'],
            $to->version(),
            $selection['commit'],
            $selection['tree'],
            $to->release()['inventory_sha256'],
            null,
        );
        return $selection;
    }

    private function targetGenerationSource(array $application, ScaffoldManifest $to, ?array $selection): array
    {
        $old = is_array($application['generation_source'] ?? null) ? $application['generation_source'] : [];
        return [
            'repository' => $selection['repository'] ?? $old['repository'] ?? null,
            'requested_ref' => $selection['requested_ref'] ?? $to->release()['source_commit'],
            'channel' => $selection['channel'] ?? 'development',
            'release_version' => $selection === null ? null : $to->version(),
            'commit' => $selection['commit'] ?? $to->release()['source_commit'],
            'tree' => $selection['tree'] ?? $to->release()['source_tree'],
            'inventory_sha256' => $to->release()['inventory_sha256'],
            'edition_profile_sha256' => $to->data['edition']['source_sha256']
                ?? $old['edition_profile_sha256'] ?? null,
        ];
    }

    private function assertReleaseChain(array $application, ScaffoldManifest $from, ScaffoldManifest $to, bool $internalPreinstall = false): void
    {
        if ((!$internalPreinstall && Semver::greaterThanOrEqualTo($from->version(), $to->version())) || ($application['template']['version'] ?? null) !== $from->version()
            || ($application['template']['source_commit'] ?? null) !== $from->release()['source_commit']
            || ($application['template']['source_tree'] ?? null) !== $from->release()['source_tree']) {
            throw new RuntimeException('SCAFFOLD_RELEASE_CHAIN_INVALID');
        }
        $applicationEdition = $application['edition'] ?? null;
        $fromEdition = $from->data['edition'] ?? null;
        $toEdition = $to->data['edition'] ?? null;
        $name = $application['application']['edition'] ?? null;
        if ($name === null && $applicationEdition === null && $fromEdition === null && $toEdition === null) {
            // Immutable pre-Edition releases have no Edition identity. Their
            // generic upgrade chain remains valid, but it cannot convert an
            // instance into either current Edition implicitly.
            return;
        }
        $expectedBootstrap = [
            'kind' => 'real-default-tenant',
            'code' => 'default',
            'tenant_identity' => 'required',
            'rbac' => 'required',
            'execution_context' => 'PeanutAdmin\\Kernel\\Context\\TenantSystemContext',
            'module_lifecycle' => 'required',
        ];
        if (!in_array($name, ['standalone', 'multi-tenant'], true)
            || !is_array($applicationEdition) || ($applicationEdition['name'] ?? null) !== $name
            || !is_array($fromEdition) || ($fromEdition['name'] ?? null) !== $name
            || !is_array($toEdition) || ($toEdition['name'] ?? null) !== $name
            || ($toEdition['deployment_mode'] ?? null) !== $name
            || ($toEdition['module_profile'] ?? null) !== 'official-default'
            || ($toEdition['tenant_bootstrap'] ?? null) !== $expectedBootstrap
            || (isset($applicationEdition['tenant_bootstrap'])
                && $applicationEdition['tenant_bootstrap'] !== $expectedBootstrap)
            || (isset($fromEdition['tenant_bootstrap'])
                && $fromEdition['tenant_bootstrap'] !== $expectedBootstrap)) {
            throw new RuntimeException('SCAFFOLD_EDITION_CHAIN_INVALID');
        }
    }

    /** @param array<string,mixed> $prepared */
    private function buildAdoptionPlan(
        string $root,
        array $prepared,
        string $packageRoot,
    ): array {
        $adoption = $prepared['adoption'] ?? null;
        if (!is_array($adoption) || !is_array($adoption['files'] ?? null)) {
            throw new RuntimeException('SCAFFOLD_ADOPTION_NOT_AVAILABLE');
        }
        [$application, $applicationDigest] = $this->applicationManifest($root);
        [$versionContract, $versionContractDigest] = $this->versionContract($root, $application);
        $to = ScaffoldManifest::load($prepared['to_manifest']);
        $parameters = $this->parameters($application, $this->instanceVersion($versionContract));
        $applicationFiles = [];
        foreach ($application['files'] as $file) {
            if (is_array($file) && is_string($file['path'] ?? null)) {
                $applicationFiles[$file['path']] = $file;
            }
        }
        $targetFiles = $to->files();
        $actions = [];
        foreach (EditionUpgradePackage::OWNERSHIP_ADOPTION_PATHS as $path) {
            $entry = $adoption['files'][$path] ?? null;
            $target = $targetFiles[$path] ?? null;
            $instance = $applicationFiles[$path] ?? null;
            if (!is_array($entry) || !is_array($target)
                || ($instance !== null && ($instance['classification'] ?? null) !== 'app-owned')) {
                throw new RuntimeException('SCAFFOLD_ADOPTION_NOT_REQUIRED: ' . $path);
            }
            $current = $this->regularFileState(ScaffoldPathGuard::projectPath($root, $path), $path);
            if (!$current['present']) {
                throw new RuntimeException('SCAFFOLD_ADOPTION_FILE_MISSING: ' . $path);
            }
            $oldSource = (string) $entry['absolute_source'];
            $oldBytes = file_get_contents($oldSource);
            if (!is_string($oldBytes) || !hash_equals((string) $entry['sha256'], hash('sha256', $oldBytes))) {
                throw new RuntimeException('SCAFFOLD_ADOPTION_SOURCE_DRIFT: ' . $path);
            }
            $targetBytes = $this->renderCurrentVersionArtifact($to, $target, $parameters, $versionContract);
            $actions[] = [
                'path' => $path,
                'old' => ['sha256' => hash('sha256', $oldBytes), 'mode' => $entry['mode'], 'source' => $oldSource],
                'current' => $current,
                'target' => ['sha256' => hash('sha256', $targetBytes), 'mode' => $target['mode']],
                'baseline_path' => '.peanut/scaffold-baseline/' . $adoption['source']['version'] . '/files/' . $path,
                'metadata_action' => $instance === null ? 'add-managed-record' : 'adopt-app-owned-record',
            ];
        }
        usort($actions, static fn(array $a, array $b): int => strcmp($a['path'], $b['path']));
        $paths = array_column($actions, 'path');
        $managedState = $this->actionState($root, $actions);
        $appOwnedState = $this->ownershipState($root, $application, 'app-owned');
        $identity = [
            'from' => $adoption['source'],
            'to' => $this->releaseIdentity($to),
            'edition' => $to->data['edition'],
            'source_edition' => $application['edition'],
            'application_manifest_sha256' => $applicationDigest,
            'version_contract_sha256' => $versionContractDigest,
            'managed_pre_sha256' => $managedState['digest'],
            'app_owned_pre_sha256' => $appOwnedState['digest'],
            'package_inventory_sha256' => $prepared['package']['inventory_sha256'],
            'package_manifest_sha256' => $prepared['package']['manifest_sha256'],
        ];
        $candidateDigest = hash('sha256', self::canonicalJson([$identity, $actions]));
        $metadataWrites = ['.peanut/application-manifest.json'];
        foreach ($actions as $action) {
            $metadataWrites[] = $action['baseline_path'];
        }
        sort($metadataWrites, SORT_STRING);
        return [
            'schema_version' => 1,
            'protocol' => 'peanut.scaffold-ownership-adoption-plan.v1',
            'candidate' => 'ownership-adoption-' . substr($candidateDigest, 0, 24),
            'status' => 'ready',
            'plan_sha256' => 'sha256:' . $candidateDigest,
            'paths' => $paths,
            'paths_sha256' => 'sha256:' . hash('sha256', self::canonicalJson($paths)),
            'identity' => $identity,
            'formal_package' => [
                'root' => realpath($packageRoot),
            ],
            'metadata_writes' => $metadataWrites,
            'actions' => $actions,
        ];
    }

    private function nextAdoptedApplicationManifest(string $root, array $plan): array
    {
        [$application] = $this->applicationManifest($root);
        $adopted = array_fill_keys($plan['paths'], true);
        $files = [];
        foreach ($application['files'] as $file) {
            if (!isset($adopted[$file['path']])) {
                $files[] = $file;
            }
        }
        foreach ($plan['actions'] as $action) {
            $files[] = [
                'path' => $action['path'],
                'sha256' => $action['current']['sha256'],
                'mode' => $action['old']['mode'],
                'classification' => 'managed',
                'owner' => 'scaffold',
                'source' => $action['path'],
                'baseline_path' => $action['baseline_path'],
                'baseline_sha256' => $action['old']['sha256'],
            ];
        }
        usort($files, static fn(array $a, array $b): int => strcmp($a['path'], $b['path']));
        $managed = array_values(array_filter($files, static fn(array $f): bool => in_array($f['classification'], ['managed', 'generated-managed'], true)));
        $appOwned = array_values(array_filter($files, static fn(array $f): bool => $f['classification'] === 'app-owned'));
        $application['files'] = $files;
        $application['digests']['managed_tree_sha256'] = $this->manifestTreeDigest($managed);
        $application['digests']['app_owned_tree_sha256'] = $this->manifestTreeDigest($appOwned);
        $application['last_ownership_adoption'] = [
            'candidate' => $plan['candidate'],
            'source' => $plan['identity']['from'],
            'paths_sha256' => $plan['paths_sha256'],
        ];
        return $application;
    }

    private function adoptionFault(int $writes): void
    {
        if ($this->failAfterAdoptionWrites !== null && $writes >= $this->failAfterAdoptionWrites) {
            throw new RuntimeException('SCAFFOLD_ADOPTION_FAULT_INJECTED');
        }
    }

    /**
     * Compare the recorded adoption rendering with the live tree and render the target
     * from the current application release frozen by preflight.
     */
    private function classify(
        string $root,
        array $application,
        ScaffoldManifest $from,
        ScaffoldManifest $to,
        array $fromParameters,
        array $targetParameters,
        array $versionContract,
    ): array {
        $old = $from->files();
        $new = $to->files();
        $actions = [];
        $applicationFiles = [];
        foreach ($application['files'] as $file) {
            if (is_array($file) && is_string($file['path'] ?? null)) {
                $applicationFiles[$file['path']] = $file;
            }
        }
        $renamedFrom = [];
        $renamedTo = [];
        foreach ($to->renames() as $rename) {
            $fromPath = (string) $rename['from'];
            $toPath = (string) $rename['to'];
            $before = $old[$fromPath] ?? null;
            $after = $new[$toPath] ?? null;
            if ($before === null || $after === null || isset($old[$toPath]) || isset($new[$fromPath])
                || ($before['owner'] ?? null) !== ($rename['owner'] ?? null)
                || ($after['owner'] ?? null) !== ($rename['owner'] ?? null)) {
                throw new RuntimeException("SCAFFOLD_RENAME_CONTRACT_INVALID: {$fromPath} -> {$toPath}");
            }
            $sourceInstance = $applicationFiles[$fromPath] ?? null;
            if (!is_array($sourceInstance)
                || !in_array($sourceInstance['classification'] ?? null, ['managed', 'generated-managed'], true)) {
                throw new RuntimeException("SCAFFOLD_RENAME_SOURCE_OWNERSHIP_INVALID: {$fromPath}");
            }
            if (isset($applicationFiles[$toPath])) {
                throw new RuntimeException("SCAFFOLD_RENAME_TARGET_OWNERSHIP_CONFLICT: {$toPath}");
            }
            $sourceCurrent = $this->regularFileState(ScaffoldPathGuard::projectPath($root, $fromPath), $fromPath);
            $targetCurrent = $this->regularFileState(ScaffoldPathGuard::projectPath($root, $toPath), $toPath);
            $oldContent = $this->renderArtifact($from, $before, $fromParameters);
            $currentVersionContent = $this->renderCurrentVersionArtifact($from, $before, $targetParameters, $versionContract);
            $targetContent = $this->renderCurrentVersionArtifact($to, $after, $targetParameters, $versionContract);
            $targetDigest = hash('sha256', $targetContent);
            $sourceChanged = !$sourceCurrent['present']
                || (!$this->renderedContentMatches($root, $fromPath, $sourceCurrent, $oldContent)
                    && !$this->renderedContentMatches($root, $fromPath, $sourceCurrent, $currentVersionContent))
                || ($sourceCurrent['mode'] ?? null) !== ($before['mode'] ?? null);
            $action = 'rename';
            $reason = 'upstream_renamed';
            $conflict = false;
            $resolutionOptions = null;
            $preserveKind = null;
            if (!$sourceCurrent['present']) {
                $action = 'conflict';
                $reason = 'rename_source_missing';
                $conflict = true;
                $resolutionOptions = ['replace'];
            } elseif ($sourceChanged && $targetCurrent['present']) {
                $action = 'conflict';
                $reason = 'rename_source_and_target_modified';
                $conflict = true;
                $resolutionOptions = ['replace'];
            } elseif ($sourceChanged) {
                $action = 'conflict';
                $reason = 'project_modified_upstream_renamed';
                $conflict = true;
                $resolutionOptions = ['preserve', 'replace'];
                $preserveKind = 'source';
            } elseif ($targetCurrent['present']) {
                $action = 'conflict';
                $reason = 'rename_target_already_exists';
                $conflict = true;
                $resolutionOptions = ['preserve', 'replace'];
                $preserveKind = 'target';
            }
            $renameAction = $this->action($toPath, $after, $action, $reason, $conflict, $targetCurrent, $targetDigest);
            $renameAction['rename_from'] = $fromPath;
            $renameAction['rename_from_current'] = $sourceCurrent;
            if ($resolutionOptions !== null) {
                $renameAction['resolution_options'] = $resolutionOptions;
            }
            if ($preserveKind !== null) {
                $renameAction['rename_preserve'] = $preserveKind;
            }
            $actions[] = $renameAction;
            $renamedFrom[$fromPath] = true;
            $renamedTo[$toPath] = true;
        }
        $paths = array_unique(array_merge(array_keys($old), array_keys($new)));
        sort($paths, SORT_STRING);
        foreach ($paths as $path) {
            if (isset($renamedFrom[$path]) || isset($renamedTo[$path])) {
                continue;
            }
            $before = $old[$path] ?? null;
            $after = $new[$path] ?? null;
            $projectPath = ScaffoldPathGuard::projectPath($root, $path);
            $current = $this->regularFileState($projectPath, $path);
            $instanceFile = $applicationFiles[$path] ?? null;
            if (($instanceFile['classification'] ?? null) === 'app-owned') {
                $file = $after ?? $before ?? [];
                $target = $after === null ? null : hash('sha256', $this->renderCurrentVersionArtifact($to, $after, $targetParameters, $versionContract));
                $actions[] = $this->action($path, $file, 'conflict', 'app_owned_adoption_required', true, $current, $target);
                continue;
            }
            if ($before !== null && (!$instanceFile
                || !in_array($instanceFile['classification'] ?? null, ['managed', 'generated-managed'], true))) {
                $file = $after ?? $before;
                $target = $after === null ? null : hash('sha256', $this->renderCurrentVersionArtifact($to, $after, $targetParameters, $versionContract));
                $actions[] = $this->action($path, $file, 'conflict', 'managed_adoption_required', true, $current, $target);
                continue;
            }
            if ($after === null) {
                // Platform files from older baselines need an explicit ownership
                // transition; removing them from the neutral template is not deletion consent.
                if (str_starts_with($path, '.github/')) {
                    $actions[] = $this->action($path, $before ?? [], 'conflict', 'recipe_ownership_transition_required', true, $current, null);
                    continue;
                }
                if (!$current['present']) {
                    $actions[] = $this->action($path, $before ?? [], 'conflict', 'managed_file_missing', true, $current, null);
                    continue;
                }
                $oldContent = $this->renderArtifact($from, $before, $fromParameters);
                $currentVersionContent = $this->renderCurrentVersionArtifact($from, $before, $targetParameters, $versionContract);
                $actions[] = (($this->renderedContentMatches($root, $path, $current, $oldContent)
                        || $this->renderedContentMatches($root, $path, $current, $currentVersionContent))
                    && ($current['mode'] ?? null) === ($before['mode'] ?? null))
                    ? $this->action($path, $before, 'delete', 'upstream_removed_only', false, $current, null)
                    : $this->action($path, $before, 'conflict', 'project_modified_upstream_removed', true, $current, null);
                continue;
            }
            $targetContent = $this->renderCurrentVersionArtifact($to, $after, $targetParameters, $versionContract);
            $targetDigest = hash('sha256', $targetContent);
            if ($before === null) {
                $decision = ScaffoldManifest::additionDecision(
                    $current['present'],
                    $this->renderedContentMatches($root, $path, $current, $targetContent)
                        && ($current['mode'] ?? null) === ($after['mode'] ?? null),
                );
                $actions[] = $this->action($path, $after, $decision['action'], $decision['reason'], $decision['conflict'], $current, $targetDigest);
                continue;
            }
            $oldContent = $this->renderArtifact($from, $before, $fromParameters);
            $currentVersionContent = $this->renderCurrentVersionArtifact($from, $before, $targetParameters, $versionContract);
            $currentVersionDigest = hash('sha256', $currentVersionContent);
            if (!$current['present']) {
                $actions[] = $this->action($path, $after, 'conflict', 'managed_file_missing', true, $current, $targetDigest);
                continue;
            }
            if ($this->renderedContentMatches($root, $path, $current, $targetContent)
                && ($current['mode'] ?? null) === ($after['mode'] ?? null)) {
                $actions[] = $this->action($path, $after, 'preserve', 'already_at_target', false, $current, $targetDigest);
                continue;
            }
            $projectChanged = (!$this->renderedContentMatches($root, $path, $current, $oldContent)
                    && !$this->renderedContentMatches($root, $path, $current, $currentVersionContent))
                || ($current['mode'] ?? null) !== ($before['mode'] ?? null);
            $upstreamChanged = !hash_equals($currentVersionDigest, $targetDigest)
                || ($before['mode'] ?? null) !== ($after['mode'] ?? null);
            if ($projectChanged && $upstreamChanged) {
                $actions[] = $this->action($path, $after, 'conflict', 'both_project_and_upstream_modified', true, $current, $targetDigest);
            } elseif ($projectChanged) {
                $actions[] = $this->action($path, $after, 'preserve', 'project_modified_only', false, $current, $targetDigest);
            } else {
                $actions[] = $this->action($path, $after, $after['classification'] === 'generated-managed' ? 'regenerate' : 'replace', $upstreamChanged ? 'upstream_modified_only' : 'application_version_projection', false, $current, $targetDigest);
            }
        }
        return $actions;
    }

    private function action(string $path, array $file, string $action, string $reason, bool $conflict, ?array $current, ?string $target): array
    {
        return ['path' => $path, 'classification' => $file['classification'] ?? 'managed', 'owner' => $file['owner'] ?? 'host',
            'policy' => $file['policy'] ?? 'managed', 'transform' => $file['transform'] ?? 'copy', 'mode' => $file['mode'] ?? 0644,
            'source' => $file['source'] ?? null, 'template_sha256' => $file['template_sha256'] ?? null, 'action' => $action,
            'reason' => $reason, 'conflict' => $conflict, 'current' => $current, 'target_sha256' => $target];
    }

    private function renderArtifact(ScaffoldManifest $manifest, array $file, array $parameters): string
    {
        $path = $manifest->artifactPath($file);
        $raw = file_get_contents($path);
        if (!is_string($raw) || !hash_equals($file['template_sha256'], hash('sha256', $raw))) {
            throw new RuntimeException('SCAFFOLD_ARTIFACT_DIGEST_MISMATCH: ' . $file['path']);
        }
        $tokens = $manifest->release()['tokens'];
        $expectedKeys = $manifest->supportsApplicationVersion()
            ? ['product_name','slug','package_identity','application_version']
            : ['product_name','slug','package_identity'];
        if (array_keys($tokens) !== $expectedKeys) {
            throw new RuntimeException('SCAFFOLD_RELEASE_TOKENS_INVALID');
        }
        $values = [
            'product_name' => $parameters['PRODUCT_NAME'],
            'slug' => $parameters['SLUG'],
            'package_identity' => $parameters['PACKAGE_IDENTITY'],
            'application_version' => $parameters['APPLICATION_VERSION'],
        ];
        $rendered = $raw;
        foreach ($tokens as $key => $token) {
            $rendered = str_replace($token, $values[$key], $rendered);
        }
        if (($file['transform'] ?? null) === 'composer-lock') {
            $composer = $manifest->files()['server/composer.json'] ?? null;
            if (!is_array($composer)) {
                throw new RuntimeException('SCAFFOLD_COMPOSER_COMPANION_MISSING');
            }
            $composerPath = $manifest->artifactPath($composer);
            $composerRaw = file_get_contents($composerPath);
            if (!is_string($composerRaw)
                || !hash_equals((string) ($composer['template_sha256'] ?? ''), hash('sha256', $composerRaw))) {
                throw new RuntimeException('SCAFFOLD_COMPOSER_COMPANION_INVALID');
            }
            foreach ($tokens as $key => $token) {
                $composerRaw = str_replace($token, $values[$key], $composerRaw);
            }
            return ScaffoldManifest::renderComposerLock($rendered, $composerRaw);
        }
        return $rendered;
    }

    /**
     * Keep the application's current release and original generated default while
     * accepting the target scaffold/Core fields from the immutable target artifact.
     */
    private function renderCurrentVersionArtifact(
        ScaffoldManifest $manifest,
        array $file,
        array $parameters,
        array $versionContract,
    ): string {
        $rendered = $this->renderArtifact($manifest, $file, $parameters);
        if (($file['path'] ?? null) !== 'release-versions.json') {
            return $rendered;
        }
        $document = $this->normalizeVersionContractDocument(
            $rendered,
            'SCAFFOLD_VERSION_CONTRACT_TARGET_INVALID',
        );
        if ($document['schema_version'] >= 2) {
            $document['instance_version'] = $this->instanceVersion($versionContract);
        } else {
            $instanceVersion = $this->instanceVersion($versionContract);
            $document['product_release'] = $instanceVersion;
        }
        return json_encode(
            $document,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
        ) . "\n";
    }

    /** Render apply bytes only from the immutable parameters and version contract in the plan. */
    private function targetContent(ScaffoldManifest $manifest, array $action, array $plan): string
    {
        $derived = $plan['identity']['plugin_target']['manifest_contents'][$action['path']] ?? null;
        if (is_string($derived)) {
            return $derived;
        }
        if ($action['path'] === 'plugins.lock' && is_array($plan['identity']['plugin_target'] ?? null)) {
            return $plan['identity']['plugin_target']['lock_contents'];
        }
        return $this->renderCurrentVersionArtifact(
            $manifest,
            $action,
            $plan['identity']['target_parameters'],
            $plan['identity']['version_contract'],
        );
    }

    /** Use the validated live Plugin bytes as the next baseline without rewriting the installed Plugin. */
    private function baselineContent(string $root, ScaffoldManifest $manifest, array $action, array $plan): string
    {
        if (($action['baseline_source'] ?? null) !== 'validated-plugin-projection') {
            return $this->targetContent($manifest, $action, $plan);
        }
        $path = ScaffoldPathGuard::projectPath($root, $action['path']);
        $state = $this->regularFileState($path, $action['path']);
        $content = $state['present'] ? file_get_contents($path) : false;
        if (!is_string($content)
            || $state !== $action['current']
            || !hash_equals((string) $action['target_sha256'], hash('sha256', $content))) {
            throw new RuntimeException('SCAFFOLD_PLUGIN_PROJECTION_CHANGED: ' . $action['path']);
        }
        return $content;
    }

    /**
     * Resolve the installed Plugin graph with the production verifier and freeze only
     * its normalized identities. Root bytes are covered by each canonical source digest.
     *
     * @return array<string,mixed>
     */
    private function pluginProjection(string $root, bool $sourceApplication): array
    {
        $lockPath = ScaffoldPathGuard::projectPath($root, 'plugins.lock');
        $lockState = $this->regularFileState($lockPath, 'plugins.lock');
        if (!$lockState['present']) {
            throw new RuntimeException('SCAFFOLD_PLUGIN_LOCK_MISSING');
        }
        try {
            // Controlled raw/derived graphs retain the ordinary full-lock verifier.
            $resolved = $sourceApplication
                ? PluginLockResolver::forSourceUpgrade($root)
                : (new PluginLockResolver(
                    ScaffoldPathGuard::projectPath($root, 'server'),
                    '../plugins.lock',
                ))->all();
            $lockBytes = file_get_contents($lockPath);
            $lockEndState = $this->regularFileState($lockPath, 'plugins.lock');
            if (!is_string($lockBytes)
                || $lockEndState !== $lockState
                || !hash_equals((string) $lockState['sha256'], hash('sha256', $lockBytes))) {
                throw new RuntimeException('SCAFFOLD_PLUGIN_PROJECTION_CHANGED');
            }
            $lock = json_decode($lockBytes, true, 64, JSON_THROW_ON_ERROR);
        } catch (PluginLifecycleException $exception) {
            throw new RuntimeException(
                'SCAFFOLD_PLUGIN_PROJECTION_INVALID: ' . $exception->errorCode . ': ' . $exception->getMessage(),
                0,
                $exception,
            );
        } catch (\JsonException $exception) {
            throw new RuntimeException('SCAFFOLD_PLUGIN_PROJECTION_INVALID: PLUGIN_LOCK_INVALID', 0, $exception);
        }
        if (!is_array($lock) || !is_array($lock['plugins'] ?? null) || !array_is_list($lock['plugins'])) {
            throw new RuntimeException('SCAFFOLD_PLUGIN_PROJECTION_INVALID: PLUGIN_LOCK_INVALID');
        }

        $plugins = [];
        $artifactPaths = ['plugins.lock'];
        $rootPaths = [];
        foreach ($lock['plugins'] as $entry) {
            $key = is_array($entry) ? ($entry['key'] ?? null) : null;
            $manifest = is_array($entry) ? ($entry['manifest'] ?? null) : null;
            if (!is_string($key) || !isset($resolved[$key]) || !is_string($manifest)) {
                throw new RuntimeException('SCAFFOLD_PLUGIN_PROJECTION_INVALID: PLUGIN_LOCK_INVALID');
            }
            $manifest = ScaffoldManifest::path($manifest);
            $manifestState = $this->regularFileState(
                ScaffoldPathGuard::projectPath($root, $manifest),
                $manifest,
            );
            /** @var PluginDescriptor $descriptor */
            $descriptor = $resolved[$key];
            if (!$manifestState['present']
                || !hash_equals($descriptor->manifestDigest, (string) $manifestState['sha256'])) {
                throw new RuntimeException('SCAFFOLD_PLUGIN_PROJECTION_INVALID: PLUGIN_MANIFEST_INVALID');
            }
            $moduleRoots = [];
            $frontendRoots = [];
            foreach ($descriptor->moduleRoots as $moduleKey => $absoluteRoot) {
                $moduleRoots[] = $this->relativeDirectory($root, $absoluteRoot);
            }
            foreach ($descriptor->frontend as $frontend) {
                $entry = is_array($frontend) ? ($frontend['entry'] ?? null) : null;
                if (!is_string($entry)) {
                    throw new RuntimeException('SCAFFOLD_PLUGIN_PROJECTION_INVALID: PLUGIN_MANIFEST_INVALID');
                }
                $frontendRoot = dirname(ScaffoldManifest::path($entry));
                $frontendPath = ScaffoldPathGuard::projectPath($root, $frontendRoot);
                if (!is_dir($frontendPath)) {
                    throw new RuntimeException('SCAFFOLD_PLUGIN_PROJECTION_INVALID: PLUGIN_PATH_UNAVAILABLE');
                }
                $frontendRoots[] = $frontendRoot;
            }
            sort($moduleRoots, SORT_STRING);
            sort($frontendRoots, SORT_STRING);
            $plugins[$key] = [
                'version' => $descriptor->version,
                'manifest' => $manifest,
                'manifest_sha256' => $descriptor->manifestDigest,
                'source_sha256' => $descriptor->source['sha256'],
                'source_contract' => ['type' => $descriptor->source['type'], 'channel' => $descriptor->trust['channel'], 'origin' => $descriptor->trust['origin']],
                'module_roots' => $moduleRoots,
                'module_keys' => array_keys($descriptor->moduleRoots),
                'frontend_roots' => $frontendRoots,
                'manifest_state' => $manifestState,
            ];
            $artifactPaths[] = $manifest;
            array_push($rootPaths, ...$moduleRoots, ...$frontendRoots);
        }
        if (array_diff(array_keys($resolved), array_keys($plugins)) !== []) {
            throw new RuntimeException('SCAFFOLD_PLUGIN_PROJECTION_INVALID: PLUGIN_LOCK_INVALID');
        }
        ksort($plugins, SORT_STRING);
        $artifactPaths = array_values(array_unique($artifactPaths));
        $rootPaths = array_values(array_unique($rootPaths));
        sort($artifactPaths, SORT_STRING);
        sort($rootPaths, SORT_STRING);
        $projection = [
            'schema_version' => 1,
            'lock_state' => $lockState,
            'plugins' => $plugins,
            'artifact_paths' => $artifactPaths,
            'root_paths' => $rootPaths,
        ];
        $projection['sha256'] = 'sha256:' . hash('sha256', self::canonicalJson($projection));
        return $projection;
    }

    /** @return array<string,array{manifest:string,module_roots:list<string>,frontend_roots:list<string>}> */
    private function targetPluginIndex(
        ScaffoldManifest $manifest,
        array $parameters,
        array $versionContract,
    ): array {
        $files = $manifest->files();
        $lockFile = $files['plugins.lock'] ?? null;
        if (!is_array($lockFile)) {
            throw new RuntimeException('SCAFFOLD_TARGET_PLUGIN_LOCK_MISSING');
        }
        try {
            $lock = json_decode(
                $this->renderCurrentVersionArtifact($manifest, $lockFile, $parameters, $versionContract),
                true,
                64,
                JSON_THROW_ON_ERROR,
            );
        } catch (\JsonException $exception) {
            throw new RuntimeException('SCAFFOLD_TARGET_PLUGIN_LOCK_INVALID', 0, $exception);
        }
        if (!is_array($lock)
            || ($lock['schema_version'] ?? null) !== 1
            || !is_array($lock['plugins'] ?? null)
            || !array_is_list($lock['plugins'])) {
            throw new RuntimeException('SCAFFOLD_TARGET_PLUGIN_LOCK_INVALID');
        }
        $plugins = [];
        foreach ($lock['plugins'] as $entry) {
            if (!is_array($entry)
                || preg_match('/^[a-z][a-z0-9]*(?:[.-][a-z0-9]+)*$/D', (string) ($entry['key'] ?? '')) !== 1
                || !is_string($entry['manifest'] ?? null)
                || !is_array($entry['frontend'] ?? null)
                || !array_is_list($entry['frontend'])
                || !is_array($entry['modules'] ?? null)
                || $entry['modules'] === []
                || !array_is_list($entry['modules'])) {
                throw new RuntimeException('SCAFFOLD_TARGET_PLUGIN_LOCK_INVALID');
            }
            $key = (string) $entry['key'];
            $manifestPath = ScaffoldManifest::path($entry['manifest']);
            if (isset($plugins[$key]) || !isset($files[$manifestPath])) {
                throw new RuntimeException('SCAFFOLD_TARGET_PLUGIN_LOCK_INVALID');
            }
            $moduleRoots = [];
            $moduleKeys = [];
            $frontendRoots = [];
            foreach ($entry['modules'] as $module) {
                if (!is_array($module)
                    || preg_match('/^[a-z][a-z0-9]*(?:[.-][a-z0-9]+)*$/D', (string) ($module['key'] ?? '')) !== 1
                    || !is_string($module['root'] ?? null)) {
                    throw new RuntimeException('SCAFFOLD_TARGET_PLUGIN_LOCK_INVALID');
                }
                $moduleRoot = ScaffoldManifest::path($module['root']);
                if (isset($moduleRoots[$moduleRoot])) {
                    throw new RuntimeException('SCAFFOLD_TARGET_PLUGIN_LOCK_INVALID');
                }
                $moduleRoots[$moduleRoot] = true;
                $moduleKeys[] = $module['key'];
            }
            foreach ($entry['frontend'] ?? [] as $frontend) {
                if (!is_array($frontend) || !is_string($frontend['entry'] ?? null)) {
                    throw new RuntimeException('SCAFFOLD_TARGET_PLUGIN_LOCK_INVALID');
                }
                $frontendRoots[dirname(ScaffoldManifest::path($frontend['entry']))] = true;
            }
            $plugins[$key] = [
                'manifest' => $manifestPath,
                'version' => $entry['version'] ?? null,
                'source_sha256' => $entry['source']['sha256'] ?? null,
                'source_contract' => ['type' => $entry['source']['type'] ?? null, 'channel' => $entry['trust']['channel'] ?? null, 'origin' => $entry['trust']['origin'] ?? null],
                'manifest_sha256' => $entry['manifest_sha256'] ?? null,
                'module_keys' => $moduleKeys,
                'module_roots' => array_keys($moduleRoots),
                'frontend_roots' => array_keys($frontendRoots),
            ];
            sort($plugins[$key]['module_roots'], SORT_STRING);
            sort($plugins[$key]['frontend_roots'], SORT_STRING);
        }
        ksort($plugins, SORT_STRING);
        return $plugins;
    }

    /** A namespace or bundled label alone never grants upstream ownership of a private/customer package. */
    private function managedBundledUpstreamProofs(
        string $root,
        array $application,
        array $current,
        array $from,
        array $fromFiles,
        array $target,
        array $targetFiles,
    ): array {
        $baselineRoot = '.peanut/scaffold-baseline/' . $application['template']['version'] . '/files';
        if (($application['ownership']['baseline_root'] ?? null) !== $baselineRoot) {
            return [];
        }
        $applicationFiles = array_column($application['files'], null, 'path');
        $contract = ['type' => 'canonical-contents', 'channel' => 'bundled',
            'origin' => ['type' => 'repository-contents', 'reference' => 'canonical-plugin-contents-v1']];
        $proofs = [];
        foreach ($target as $key => $plugin) {
            if (str_starts_with($key, 'official.') || !isset($current['plugins'][$key], $from[$key])
                || $plugin['manifest'] !== 'plugins/' . $key . '/plugin.json') {
                continue;
            }
            $installed = $current['plugins'][$key];
            $baseline = $from[$key];
            $sameOwner = true;
            foreach (['manifest', 'module_keys', 'module_roots', 'frontend_roots'] as $field) {
                $sameOwner = $sameOwner && $installed[$field] === $plugin[$field] && $baseline[$field] === $plugin[$field];
            }
            foreach ([$installed, $baseline, $plugin] as $identity) {
                $sameOwner = $sameOwner && ($identity['source_contract'] ?? null) === $contract;
            }
            if (!$sameOwner) {
                continue;
            }
            $paths = $this->pluginSourceFiles($root, [...$installed['module_roots'], ...$installed['frontend_roots']]);
            $paths[$installed['manifest']] = $installed['manifest_sha256'];
            ksort($paths, SORT_STRING);
            $evidence = [];
            foreach ($paths as $path => $digest) {
                $file = $applicationFiles[$path] ?? null;
                $old = $fromFiles[$path] ?? null;
                $next = $targetFiles[$path] ?? null;
                if (!is_array($file) || !is_array($old)
                    || !in_array($file['classification'] ?? null, ['managed', 'generated-managed'], true)
                    || ($file['owner'] ?? null) !== 'scaffold'
                    || ($file['source'] ?? null) !== $path
                    || !in_array($old['classification'] ?? null, ['managed', 'generated-managed'], true)
                    || ($next !== null && !in_array($next['classification'] ?? null, ['managed', 'generated-managed'], true))
                    || ($file['sha256'] ?? null) !== $digest
                    || ($file['baseline_path'] ?? null) !== $baselineRoot . '/' . $path
                    || ($file['baseline_sha256'] ?? null) !== $digest) {
                    $sameOwner = false;
                    break;
                }
                $actual = $this->regularFileState(ScaffoldPathGuard::projectPath($root, $path), $path);
                $saved = $this->regularFileState(ScaffoldPathGuard::projectPath($root, $file['baseline_path']), $file['baseline_path']);
                if (!$saved['present'] || $saved['sha256'] !== $digest || $actual['sha256'] !== $digest
                    || $actual['mode'] !== ($file['mode'] ?? null)) {
                    $sameOwner = false;
                    break;
                }
                $evidence[$path] = ['sha256' => $digest, 'mode' => $actual['mode'], 'baseline_path' => $file['baseline_path']];
            }
            if ($sameOwner) {
                $proofs[$key] = ['baseline_root' => $baselineRoot, 'files' => count($evidence),
                    'ownership_sha256' => 'sha256:' . hash('sha256', self::canonicalJson($evidence))];
            }
        }
        return $proofs;
    }

    /** Materialize the selected upstream graph and validated customer graph through the native canonical writer. */
    private function upstreamPluginProjection(
        string $root,
        array $current,
        array $fromFiles,
        array $managedUpstreamProofs,
        array $target,
        ScaffoldManifest $release,
        array $parameters,
        array $versionContract,
    ): array {
        $keys = [];
        foreach ($target as $key => $plugin) {
            $official = str_starts_with($key, 'official.');
            if (!$official && !isset($managedUpstreamProofs[$key])) {
                continue;
            }
            if ($plugin['manifest'] !== 'plugins/' . $key . '/plugin.json'
                || ($official && array_filter($plugin['module_keys'], static fn(string $module): bool => !str_starts_with($module, 'official.')) !== [])
                || (!$official && array_filter($plugin['module_keys'], static fn(string $module): bool => str_starts_with($module, 'official.')) !== [])) {
                throw new RuntimeException('SCAFFOLD_UPSTREAM_OWNERSHIP_INVALID');
            }
            $installed = $current['plugins'][$key] ?? null;
            if (is_array($installed)) {
                foreach (['manifest', 'module_keys', 'module_roots', 'frontend_roots'] as $field) {
                    if ($installed[$field] !== $plugin[$field]) {
                        throw new RuntimeException('SCAFFOLD_OFFICIAL_MEMBERSHIP_CHANGED: ' . $key);
                    }
                }
                $comparison = version_compare((string) $plugin['version'], (string) $installed['version']);
                if ($comparison < 0) {
                    throw new RuntimeException('SCAFFOLD_OFFICIAL_VERSION_IDENTITY_CONFLICT: ' . $key);
                }
            }
            foreach ($current['plugins'] as $owner => $existing) {
                if ($owner !== $key
                    && (array_intersect($plugin['module_keys'], $existing['module_keys']) !== []
                        || array_intersect($plugin['module_roots'], $existing['module_roots']) !== []
                        || array_intersect($plugin['frontend_roots'], $existing['frontend_roots']) !== [])) {
                    throw new RuntimeException('SCAFFOLD_OFFICIAL_OWNERSHIP_CONFLICT: ' . $key);
                }
            }
            $keys[] = $key;
        }
        if ($keys === []) {
            return ['keys' => [], 'lock_contents' => (string) file_get_contents($root . '/plugins.lock'), 'projection' => $current];
        }

        $stage = ScaffoldPathGuard::projectPath($root, '.peanut/upgrades/plugin-projection-' . bin2hex(random_bytes(16)));
        if (!mkdir($stage, 0700, true)) {
            throw new RuntimeException('SCAFFOLD_PLUGIN_PROJECTION_STAGE_FAILED');
        }
        try {
            $sourceProjection = $this->validateRawPluginProjection($root, $stage, $release, $target);
            // Customer sources are copied, never reinterpreted as upstream files.
            foreach ($current['plugins'] as $key => $plugin) {
                if (in_array($key, $keys, true)) {
                    continue;
                }
                foreach ([$plugin['manifest'], ...$plugin['module_roots'], ...$plugin['frontend_roots']] as $path) {
                    $this->copyPluginProjectionPath($root, $stage, $path);
                }
            }
            $files = $release->files();
            foreach ($keys as $key) {
                $plugin = $target[$key];
                $roots = [...$plugin['module_roots'], ...$plugin['frontend_roots']];
                if (isset($current['plugins'][$key])) {
                    foreach (array_keys($this->pluginSourceFiles($root, $roots)) as $path) {
                        if (!isset($files[$path]) && !isset($fromFiles[$path])) {
                            throw new RuntimeException('SCAFFOLD_OFFICIAL_UNTRACKED_SOURCE: ' . $path);
                        }
                    }
                }
                foreach ($files as $path => $file) {
                    if ($path !== $plugin['manifest'] && !$this->withinAnyRoot($path, $roots)) {
                        continue;
                    }
                    $this->writeFileAtomic(
                        ScaffoldPathGuard::projectPath($stage, $path),
                        $this->renderCurrentVersionArtifact($release, $file, $parameters, $versionContract),
                        (int) $file['mode'],
                    );
                }
            }
            $schema = 'server/resources/schemas/plugin.schema.json';
            if (isset($files[$schema])) {
                $this->writeFileAtomic(
                    ScaffoldPathGuard::projectPath($stage, $schema),
                    $this->renderCurrentVersionArtifact($release, $files[$schema], $parameters, $versionContract),
                    0644,
                );
            } else {
                $this->copyPluginProjectionPath($root, $stage, $schema);
            }
            $writer = new PluginArtifactWriter($stage . '/server');
            $manifestContents = [];
            foreach ($keys as $key) {
                $plugin = $target[$key];
                $manifest = json_decode((string) file_get_contents($stage . '/' . $plugin['manifest']), true, 128, JSON_THROW_ON_ERROR);
                $specs = [];
                foreach ($manifest['modules'] as $module) {
                    $specs[] = $module['key'] . '=' . $module['root'];
                }
                // Rendering changes source bytes; derive the APP manifest exactly as create-app does.
                $writer->make($key, $plugin['version'], $specs);
                $manifestContents[$plugin['manifest']] = (string) file_get_contents($stage . '/' . $plugin['manifest']);
                // Canonical public artifacts retain the release-declared mode regardless
                // of the caller's private-state umask, which is part of the plan identity.
                $this->writeFileAtomic(
                    $stage . '/' . $plugin['manifest'],
                    $manifestContents[$plugin['manifest']],
                    (int) $files[$plugin['manifest']]['mode'],
                );
            }
            $built = $writer->lock();
            $this->writeFileAtomic($stage . '/plugins.lock', $built['contents'], 0644);
            $projection = $this->pluginProjection($stage, sourceApplication: false);
            $preflight = new ModulePackagePreflight($stage, $this->targetModuleSchemaValidator($release, $stage));
            $modules = [];
            foreach ($projection['plugins'] as $key => $plugin) {
                foreach ($plugin['module_keys'] as $moduleKey) {
                    if (isset($modules[$moduleKey])) {
                        throw new RuntimeException('SCAFFOLD_PLUGIN_MODULE_OWNERSHIP_CONFLICT');
                    }
                    $inspection = $preflight->inspect($moduleKey);
                    $modules[$moduleKey] = ['version' => $inspection['version'], 'dependencies' => $inspection['dependencies']];
                    if (in_array($key, $keys, true) && isset($current['plugins'][$key])) {
                        $oldManifest = json_decode((string) file_get_contents($root . '/' . $inspection['backend_relative'] . '/module.json'), true, 64, JSON_THROW_ON_ERROR);
                        $oldVersion = (string) ($oldManifest['version'] ?? '');
                        $oldDigest = hash_file('sha256', $root . '/' . $inspection['backend_relative'] . '/module.json');
                        $moduleRoots = [$inspection['backend_relative'], ...array_column($inspection['frontend_contributions'], 'root')];
                        $changed = $this->pluginSourceFiles($root, $moduleRoots) !== $this->pluginSourceFiles($stage, $moduleRoots);
                        $pendingFileCorrection = $this->internalPreinstall !== null && $key === 'official.file' && $moduleKey === 'official.file'
                            && $oldVersion === '1.0.15' && $inspection['version'] === $oldVersion;
                        if (version_compare($inspection['version'], $oldVersion) < 0
                            || ($changed && version_compare($inspection['version'], $oldVersion) <= 0 && !$pendingFileCorrection)
                            || ($inspection['version'] === $oldVersion
                                && $oldDigest !== hash_file('sha256', $stage . '/' . $inspection['backend_relative'] . '/module.json'))) {
                            throw new RuntimeException('SCAFFOLD_OFFICIAL_MODULE_VERSION_IDENTITY_CONFLICT: ' . $moduleKey);
                        }
                    }
                }
                if (in_array($key, $keys, true)) {
                    foreach (['version', 'manifest', 'module_keys', 'module_roots', 'frontend_roots'] as $field) {
                        if ($plugin[$field] !== $target[$key][$field]) {
                            throw new RuntimeException('SCAFFOLD_OFFICIAL_TARGET_IDENTITY_MISMATCH: ' . $key);
                        }
                    }
                    $installed = $current['plugins'][$key] ?? null;
                    if (is_array($installed) && version_compare($plugin['version'], $installed['version']) === 0
                        && $plugin['manifest_sha256'] !== $installed['manifest_sha256']
                        && !($this->internalPreinstall !== null && $key === 'official.file')) {
                        throw new RuntimeException('SCAFFOLD_OFFICIAL_VERSION_IDENTITY_CONFLICT: ' . $key);
                    }
                } elseif ($plugin !== ($current['plugins'][$key] ?? null)) {
                    throw new RuntimeException('SCAFFOLD_CUSTOMER_PLUGIN_PROJECTION_CHANGED: ' . $key);
                }
            }
            $preflight->dependencyOrder($modules, []);
            $compatibility = $this->customerModuleCompatibility(
                $stage,
                $projection,
                $keys,
                $release,
                $parameters,
                $versionContract,
            );
            return ['keys' => $keys, 'source_projection' => $sourceProjection,
                'managed_upstream_proofs' => $managedUpstreamProofs,
                'manifest_contents' => $manifestContents, 'lock_contents' => $built['contents'],
                'projection' => $projection, 'compatibility' => $compatibility];
        } finally {
            $this->removePluginProjectionStage($stage);
        }
    }

    /**
     * Bootstrap target validation from authenticated data, without loading target PHP.
     * The whole projected graph (including retained customer Modules) uses this schema.
     */
    private function targetModuleSchemaValidator(ScaffoldManifest $release, string $stage): OpisManifestSchemaValidator
    {
        $files = $release->files();
        $read = static function (string $path) use ($release, $files): string {
            $file = $files[$path] ?? null;
            if (!is_array($file) || ($file['policy'] ?? null) !== 'managed') {
                throw new RuntimeException('SCAFFOLD_TARGET_MODULE_SCHEMA_INPUT_MISSING: ' . $path);
            }
            $raw = file_get_contents($release->artifactPath($file));
            if (!is_string($raw) || !hash_equals($file['template_sha256'], hash('sha256', $raw))) {
                throw new RuntimeException('SCAFFOLD_ARTIFACT_DIGEST_MISMATCH: ' . $path);
            }
            return $raw;
        };
        $versions = $this->normalizeVersionContractDocument($read('release-versions.json'), 'SCAFFOLD_TARGET_MODULE_SCHEMA_VERSION_INVALID');
        $lock = json_decode($read('server/composer.lock'), true, 512, JSON_THROW_ON_ERROR);
        $cores = array_values(array_filter($lock['packages'] ?? [], static fn(array $package): bool => ($package['name'] ?? null) === 'peanut-admin/core'));
        $identity = $versions['core_php'];
        $version = is_array($identity) ? $identity['resolved_version'] : $identity;
        $core = count($cores) === 1 ? $cores[0] : null;
        $reference = $core['source']['reference'] ?? null;
        if ($versions['source_product_version'] !== $release->version()
            || !is_array($core) || ($core['version'] ?? null) !== $version
            || !is_string($reference) || preg_match('/^[a-f0-9]{40}$/D', $reference) !== 1
            || ($core['source']['type'] ?? null) !== 'git'
            || (is_array($identity) && (($identity['source_reference'] ?? null) !== $reference
                || ($identity['source_url'] ?? null) !== ($core['source']['url'] ?? null)))) {
            throw new RuntimeException('SCAFFOLD_TARGET_MODULE_SCHEMA_CORE_IDENTITY_INVALID');
        }

        $url = $core['dist']['url'] ?? '';
        if (is_string($url) && str_starts_with($url, '../packages/core-php/')) {
            $archivePath = substr($url, 3);
            if ($archivePath !== 'packages/core-php/peanut-admin-core-' . $version . '.zip'
                || ($core['dist']['type'] ?? null) !== 'zip'
                || ($core['dist']['reference'] ?? null) !== $reference
                || !is_string($core['dist']['shasum'] ?? null)
                || preg_match('/^[a-f0-9]{40}$/D', $core['dist']['shasum']) !== 1) {
                throw new RuntimeException('SCAFFOLD_TARGET_MODULE_SCHEMA_ARCHIVE_IDENTITY_INVALID');
            }
            // Read and hash before opening the archive; never extract or autoload it.
            $archiveBytes = $read($archivePath);
            if (!hash_equals($core['dist']['shasum'], sha1($archiveBytes))) {
                throw new RuntimeException('SCAFFOLD_TARGET_MODULE_SCHEMA_ARCHIVE_DIGEST_MISMATCH');
            }
            $archive = new \PharData($release->artifactPath($files[$archivePath]));
            $entry = static function (string $path) use ($archive): string {
                if (!isset($archive[$path]) || !$archive[$path]->isFile() || $archive[$path]->isLink()
                    || $archive[$path]->getSize() > 1048576) {
                    throw new RuntimeException('SCAFFOLD_TARGET_MODULE_SCHEMA_ARCHIVE_ENTRY_INVALID: ' . $path);
                }
                return $archive[$path]->getContent();
            };
            $composer = json_decode($entry('core/composer.json'), true, 512, JSON_THROW_ON_ERROR);
            if (($composer['name'] ?? null) !== 'peanut-admin/core'
                || ($composer['autoload']['psr-4']['PeanutAdmin\\Kernel\\'] ?? null) !== 'kernel/src/'
                || (isset($composer['version']) && $composer['version'] !== $version)) {
                throw new RuntimeException('SCAFFOLD_TARGET_MODULE_SCHEMA_ARCHIVE_PACKAGE_INVALID');
            }
            $schema = $entry('core/kernel/resources/schemas/module-manifest.schema.json');
            if (!hash_equals(hash('sha256', $archiveBytes), hash_file('sha256', $release->artifactPath($files[$archivePath])))) {
                throw new RuntimeException('SCAFFOLD_TARGET_MODULE_SCHEMA_ARCHIVE_CHANGED');
            }
        } else {
            // Registry releases can use the running validator only for that exact Core.
            if (!\Composer\InstalledVersions::isInstalled('peanut-admin/core')
                || ltrim((string) \Composer\InstalledVersions::getPrettyVersion('peanut-admin/core'), 'v') !== $version
                || \Composer\InstalledVersions::getReference('peanut-admin/core') !== $reference) {
                throw new RuntimeException('SCAFFOLD_TARGET_MODULE_SCHEMA_UNAVAILABLE: target Core archive or exact installed Core required');
            }
            $kernelRoot = dirname((new \ReflectionClass(\PeanutAdmin\Kernel\Module\ModuleProvider::class))->getFileName(), 3);
            $installedRoot = realpath((string) \Composer\InstalledVersions::getInstallPath('peanut-admin/core'));
            if ($installedRoot === false || realpath(dirname($kernelRoot)) !== $installedRoot) {
                throw new RuntimeException('SCAFFOLD_TARGET_MODULE_SCHEMA_INSTALLED_IDENTITY_INVALID');
            }
            $schema = file_get_contents($kernelRoot . '/resources/schemas/module-manifest.schema.json');
        }
        if (!is_string($schema) || !is_object(json_decode($schema, false, 512, JSON_THROW_ON_ERROR))) {
            throw new RuntimeException('SCAFFOLD_TARGET_MODULE_SCHEMA_INVALID');
        }
        $schemaPath = $stage . '/target-module-manifest.schema.json';
        $this->writeFileAtomic($schemaPath, $schema, 0600);
        return new OpisManifestSchemaValidator($schemaPath);
    }

    /**
     * Check only customer Modules preserved into the target graph. This is intentionally
     * narrow: Kernel version constraints, explicit Kernel imports, and direct static calls.
     * It reports upgrade work; it never rewrites downstream source.
     *
     * @param array<string,mixed> $projection
     * @param list<string> $upstreamPluginKeys
     * @param array<string,string> $parameters
     * @param array<string,mixed> $versionContract
     * @return array<string,mixed>
     */
    private function customerModuleCompatibility(
        string $stage,
        array $projection,
        array $upstreamPluginKeys,
        ScaffoldManifest $release,
        array $parameters,
        array $versionContract,
    ): array {
        $kernelVersion = $this->targetModuleKernelVersion($release, $parameters, $versionContract);
        $matcher = new StrictVersionConstraintMatcher();
        $upstream = array_fill_keys($upstreamPluginKeys, true);
        $modules = [];
        $blockers = [];
        $checkedPhpFiles = 0;
        foreach (($projection['plugins'] ?? []) as $pluginKey => $plugin) {
            if (!is_string($pluginKey) || !is_array($plugin)) {
                throw new RuntimeException('SCAFFOLD_PLUGIN_PROJECTION_INVALID');
            }
            if (isset($upstream[$pluginKey])) {
                continue;
            }
            $moduleKeys = $plugin['module_keys'] ?? null;
            $moduleRoots = $plugin['module_roots'] ?? null;
            if (!is_array($moduleKeys) || !array_is_list($moduleKeys)
                || !is_array($moduleRoots) || !array_is_list($moduleRoots)
                || count($moduleKeys) !== count($moduleRoots)) {
                throw new RuntimeException('SCAFFOLD_PLUGIN_PROJECTION_INVALID');
            }
            foreach ($moduleKeys as $index => $moduleKey) {
                $moduleRoot = $moduleRoots[$index] ?? null;
                if (!is_string($moduleKey) || !is_string($moduleRoot)) {
                    throw new RuntimeException('SCAFFOLD_PLUGIN_PROJECTION_INVALID');
                }
                $manifestPath = ScaffoldPathGuard::projectPath($stage, $moduleRoot . '/module.json');
                $manifest = json_decode((string) file_get_contents($manifestPath), true, 128, JSON_THROW_ON_ERROR);
                $constraint = is_array($manifest) ? ($manifest['kernel_constraint'] ?? null) : null;
                if (($manifest['key'] ?? null) !== $moduleKey || !is_string($constraint)) {
                    throw new RuntimeException('SCAFFOLD_PLUGIN_PROJECTION_INVALID');
                }
                $moduleBlockers = [];
                if (!$matcher->matches($kernelVersion, $constraint)) {
                    $moduleBlockers[] = [
                        'code' => 'MODULE_KERNEL_INCOMPATIBLE',
                        'module_key' => $moduleKey,
                        'path' => $moduleRoot . '/module.json',
                        'line' => null,
                        'symbol' => null,
                        'method' => null,
                        'detail' => "Target Module Kernel {$kernelVersion} does not satisfy {$constraint}.",
                    ];
                }
                [$referenceBlockers, $phpFiles] = $this->scanCustomerModuleKernelReferences(
                    $stage,
                    $moduleKey,
                    $moduleRoot,
                );
                array_push($moduleBlockers, ...$referenceBlockers);
                array_push($blockers, ...$moduleBlockers);
                $checkedPhpFiles += $phpFiles;
                $modules[] = [
                    'key' => $moduleKey,
                    'kernel_constraint' => $constraint,
                    'checked_php_files' => $phpFiles,
                    'status' => $moduleBlockers === [] ? 'ready' : 'blocked',
                ];
            }
        }
        usort($modules, static fn(array $left, array $right): int => strcmp($left['key'], $right['key']));
        usort($blockers, static fn(array $left, array $right): int => strcmp(
            implode('|', array_map('strval', [
                $left['module_key'] ?? '', $left['path'] ?? '', $left['line'] ?? 0,
                $left['code'] ?? '', $left['symbol'] ?? '', $left['method'] ?? '',
            ])),
            implode('|', array_map('strval', [
                $right['module_key'] ?? '', $right['path'] ?? '', $right['line'] ?? 0,
                $right['code'] ?? '', $right['symbol'] ?? '', $right['method'] ?? '',
            ])),
        ));
        return [
            'status' => $blockers === [] ? 'ready' : 'blocked',
            'kernel_version' => $kernelVersion,
            'customer_module_count' => count($modules),
            'checked_php_files' => $checkedPhpFiles,
            'modules' => $modules,
            'blockers' => $blockers,
            'scope' => 'kernel_constraint, explicit PeanutAdmin\\Kernel imports, and direct static calls; no automatic rewrite',
        ];
    }

    /** @return array{0:list<array<string,mixed>>,1:int} */
    private function scanCustomerModuleKernelReferences(string $stage, string $moduleKey, string $moduleRoot): array
    {
        $root = ScaffoldPathGuard::projectPath($stage, $moduleRoot);
        if (!is_dir($root) || is_link($root)) {
            throw new RuntimeException('SCAFFOLD_PLUGIN_PROJECTION_INVALID');
        }
        $files = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS),
        );
        foreach ($iterator as $entry) {
            if ($entry->isLink()) {
                throw new RuntimeException('SCAFFOLD_PLUGIN_PROJECTION_PATH_INVALID');
            }
            if ($entry->isFile() && strtolower($entry->getExtension()) === 'php') {
                $files[] = $entry->getPathname();
            }
        }
        sort($files, SORT_STRING);
        $blockers = [];
        foreach ($files as $file) {
            $source = (string) file_get_contents($file);
            $scan = '';
            foreach (token_get_all($source) as $token) {
                if (is_array($token) && in_array($token[0], [
                    T_COMMENT, T_DOC_COMMENT, T_CONSTANT_ENCAPSED_STRING, T_ENCAPSED_AND_WHITESPACE,
                ], true)) {
                    $scan .= preg_replace('/[^\r\n]/', ' ', $token[1]) ?? '';
                } else {
                    $scan .= is_array($token) ? $token[1] : $token;
                }
            }
            $relative = str_replace(DIRECTORY_SEPARATOR, '/', substr($file, strlen($stage) + 1));
            preg_match_all(
                '/^\s*use\s+(PeanutAdmin\\\\Kernel\\\\[A-Za-z_][A-Za-z0-9_\\\\]*)(?:\s+as\s+([A-Za-z_][A-Za-z0-9_]*))?\s*;/mi',
                $scan,
                $imports,
                PREG_SET_ORDER | PREG_OFFSET_CAPTURE,
            );
            foreach ($imports as $import) {
                $symbol = $import[1][0];
                $segments = explode('\\', $symbol);
                $alias = ($import[2][0] ?? '') !== '' ? $import[2][0] : end($segments);
                $importLine = substr_count(substr($scan, 0, $import[1][1]), "\n") + 1;
                if (!$this->kernelSymbolExists($symbol)) {
                    $blockers[] = [
                        'code' => 'MODULE_CORE_CLASS_MISSING', 'module_key' => $moduleKey,
                        'path' => $relative, 'line' => $importLine, 'symbol' => $symbol, 'method' => null,
                        'detail' => 'Imported Peanut Module Kernel symbol is unavailable in the target Core.',
                    ];
                    continue;
                }
                preg_match_all(
                    '/\b' . preg_quote((string) $alias, '/') . '::([A-Za-z_][A-Za-z0-9_]*)\s*\(/',
                    $scan,
                    $calls,
                    PREG_SET_ORDER | PREG_OFFSET_CAPTURE,
                );
                foreach ($calls as $call) {
                    $method = $call[1][0];
                    try {
                        $reflection = new \ReflectionMethod($symbol, $method);
                        $compatible = $reflection->isPublic() && $reflection->isStatic();
                    } catch (\ReflectionException) {
                        $compatible = false;
                    }
                    if (!$compatible) {
                        $blockers[] = [
                            'code' => method_exists($symbol, $method)
                                ? 'MODULE_CORE_METHOD_INCOMPATIBLE'
                                : 'MODULE_CORE_METHOD_MISSING',
                            'module_key' => $moduleKey,
                            'path' => $relative,
                            'line' => substr_count(substr($scan, 0, $call[0][1]), "\n") + 1,
                            'symbol' => $symbol,
                            'method' => $method,
                            'detail' => 'Direct static Kernel call is unavailable or no longer public/static in the target Core.',
                        ];
                    }
                }
            }
        }
        return [$blockers, count($files)];
    }

    private function kernelSymbolExists(string $symbol): bool
    {
        try {
            return class_exists($symbol)
                || interface_exists($symbol)
                || trait_exists($symbol)
                || (function_exists('enum_exists') && enum_exists($symbol));
        } catch (Throwable) {
            return false;
        }
    }

    /** @param array<string,string> $parameters @param array<string,mixed> $versionContract */
    private function targetModuleKernelVersion(
        ScaffoldManifest $release,
        array $parameters,
        array $versionContract,
    ): string {
        $file = $release->files()['server/config/modules.php'] ?? null;
        if (!is_array($file)) {
            throw new RuntimeException('SCAFFOLD_MODULE_KERNEL_VERSION_UNAVAILABLE');
        }
        $contents = $this->renderCurrentVersionArtifact($release, $file, $parameters, $versionContract);
        if (preg_match(
            '/env\(\s*[\'"]PEANUT_MODULE_KERNEL_VERSION[\'"]\s*,\s*[\'"]([^\'"]+)[\'"]\s*\)/',
            $contents,
            $match,
        ) !== 1 || !(new StrictVersionConstraintMatcher())->matches($match[1], $match[1])) {
            throw new RuntimeException('SCAFFOLD_MODULE_KERNEL_VERSION_UNAVAILABLE');
        }
        return $match[1];
    }

    /** @param array<string,mixed>|null $pluginTarget */
    private function moduleCompatibilityBlocked(?array $pluginTarget): bool
    {
        return is_array($pluginTarget)
            && (($pluginTarget['compatibility']['status'] ?? null) === 'blocked');
    }

    /** @param array<string,mixed>|null $pluginTarget */
    private function withModuleCompatibilityImpact(array $impact, ?array $pluginTarget): array
    {
        $compatibility = is_array($pluginTarget) ? ($pluginTarget['compatibility'] ?? null) : null;
        if (!is_array($compatibility)) {
            return $impact;
        }
        $impact['module_compatibility'] = $compatibility;
        if (($compatibility['status'] ?? null) === 'blocked') {
            $impact['message'] = sprintf(
                'No files will be changed until %d managed-file conflicts and %d customer Module compatibility blockers are resolved.',
                count($impact['must_resolve'] ?? []),
                count($compatibility['blockers'] ?? []),
            );
        }
        return $impact;
    }

    /** Verify the fixed raw template graph before deriving any APP-specific canonical identities. */
    private function validateRawPluginProjection(string $root, string $stage, ScaffoldManifest $release, array $target): array
    {
        $raw = $stage . '/raw-source';
        if (!mkdir($raw, 0700)) {
            throw new RuntimeException('SCAFFOLD_PLUGIN_PROJECTION_STAGE_FAILED');
        }
        $artifacts = ['plugins.lock' => true, 'server/resources/schemas/plugin.schema.json' => true];
        $roots = [];
        foreach ($target as $plugin) {
            $artifacts[$plugin['manifest']] = true;
            array_push($roots, ...$plugin['module_roots'], ...$plugin['frontend_roots']);
        }
        foreach ($release->files() as $path => $file) {
            if (!isset($artifacts[$path]) && !$this->withinAnyRoot($path, $roots)) {
                continue;
            }
            $bytes = file_get_contents($release->artifactPath($file));
            if (!is_string($bytes) || !hash_equals($file['template_sha256'], hash('sha256', $bytes))) {
                throw new RuntimeException('SCAFFOLD_ARTIFACT_DIGEST_MISMATCH: ' . $path);
            }
            $this->writeFileAtomic(ScaffoldPathGuard::projectPath($raw, $path), $bytes, (int) $file['mode']);
        }
        if (!is_file($raw . '/server/resources/schemas/plugin.schema.json')) {
            $this->copyPluginProjectionPath($root, $raw, 'server/resources/schemas/plugin.schema.json');
        }
        // checkLock regenerates the expected raw manifests and lock without replacing them.
        (new PluginArtifactWriter($raw . '/server'))->checkLock();
        $projection = $this->pluginProjection($raw, sourceApplication: false);
        if (array_keys($projection['plugins']) !== array_keys($target)) {
            throw new RuntimeException('SCAFFOLD_RAW_PLUGIN_TARGET_IDENTITY_MISMATCH');
        }
        foreach ($target as $key => $plugin) {
            foreach (['version', 'manifest', 'manifest_sha256', 'source_sha256', 'source_contract', 'module_keys', 'module_roots', 'frontend_roots'] as $field) {
                if ($projection['plugins'][$key][$field] !== $plugin[$field]) {
                    throw new RuntimeException('SCAFFOLD_RAW_PLUGIN_TARGET_IDENTITY_MISMATCH: ' . $key);
                }
            }
        }
        return $projection;
    }

    /** Compare complete Module contents without trusting a rewritten per-file digest or following a link. */
    private function pluginSourceFiles(string $root, array $roots): array
    {
        $files = [];
        foreach ($roots as $relative) {
            $source = ScaffoldPathGuard::projectPath($root, $relative);
            if (!is_dir($source) || is_link($source)) {
                throw new RuntimeException('SCAFFOLD_PLUGIN_PROJECTION_PATH_INVALID');
            }
            $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($source, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::SELF_FIRST);
            foreach ($iterator as $entry) {
                if ($entry->isLink() || (!$entry->isDir() && !$entry->isFile())) {
                    throw new RuntimeException('SCAFFOLD_PLUGIN_PROJECTION_PATH_INVALID');
                }
                if ($entry->isFile()) {
                    $path = str_replace(DIRECTORY_SEPARATOR, '/', substr($entry->getPathname(), strlen($root) + 1));
                    $files[$path] = hash_file('sha256', $entry->getPathname());
                }
            }
        }
        ksort($files, SORT_STRING);
        return $files;
    }

    /** Reject every link and copy exact customer bytes/modes into the ephemeral projection. */
    private function copyPluginProjectionPath(string $root, string $stage, string $relative): void
    {
        $source = ScaffoldPathGuard::projectPath($root, $relative);
        if (is_file($source) && !is_link($source)) {
            $state = $this->regularFileState($source, $relative);
            $bytes = file_get_contents($source);
            if (!is_string($bytes) || !hash_equals($state['sha256'], hash('sha256', $bytes))) {
                throw new RuntimeException('SCAFFOLD_PLUGIN_PROJECTION_CHANGED');
            }
            $this->writeFileAtomic(ScaffoldPathGuard::projectPath($stage, $relative), $bytes, (int) $state['mode']);
            return;
        }
        if (!is_dir($source) || is_link($source)) {
            throw new RuntimeException('SCAFFOLD_PLUGIN_PROJECTION_PATH_INVALID');
        }
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($source, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::SELF_FIRST);
        foreach ($iterator as $entry) {
            if ($entry->isLink() || (!$entry->isDir() && !$entry->isFile())) {
                throw new RuntimeException('SCAFFOLD_PLUGIN_PROJECTION_PATH_INVALID');
            }
            if ($entry->isFile()) {
                $this->copyPluginProjectionPath($root, $stage, str_replace(DIRECTORY_SEPARATOR, '/', substr($entry->getPathname(), strlen($root) + 1)));
            }
        }
    }

    /** This directory is created by this preview and never holds recovery evidence. */
    private function removePluginProjectionStage(string $stage): void
    {
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($stage, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($iterator as $entry) {
            if ($entry->isDir() && !$entry->isLink()) {
                rmdir($entry->getPathname());
            } else {
                unlink($entry->getPathname());
            }
        }
        rmdir($stage);
    }

    /** Keep the installed customer Plugin graph whole; scaffold upgrades cannot partially replace its derived metadata or roots. */
    private function projectPluginBoundary(array $actions, array $current, array $target, array $official): array
    {
        $currentArtifacts = array_fill_keys($current['artifact_paths'], true);
        $currentRoots = $current['root_paths'];
        $targetArtifacts = [];
        $adoptionRequired = [];
        $officialArtifacts = [];
        $officialRoots = [];
        $managedArtifacts = [];
        $managedRoots = [];
        foreach ($official['keys'] as $key) {
            $officialArtifacts[$target[$key]['manifest']] = true;
            array_push($officialRoots, ...$target[$key]['module_roots'], ...$target[$key]['frontend_roots']);
            if (isset($official['managed_upstream_proofs'][$key])) {
                $managedArtifacts[$target[$key]['manifest']] = true;
                array_push($managedRoots, ...$target[$key]['module_roots'], ...$target[$key]['frontend_roots']);
            }
        }
        foreach ($target as $key => $plugin) {
            $targetArtifacts[$plugin['manifest']] = $key;
            $installed = $current['plugins'][$key] ?? null;
            if (!in_array($key, $official['keys'], true) && (!is_array($installed)
                || $installed['manifest'] !== $plugin['manifest']
                || array_diff($plugin['module_roots'], $installed['module_roots']) !== []
                || array_diff($plugin['frontend_roots'], $installed['frontend_roots']) !== [])) {
                $adoptionRequired[$key] = true;
            }
        }

        foreach ($actions as &$action) {
            $path = (string) $action['path'];
            if (isset($officialArtifacts[$path]) || $this->withinAnyRoot($path, $officialRoots)) {
                if (isset($official['manifest_contents'][$path])) {
                    $action['target_sha256'] = hash('sha256', $official['manifest_contents'][$path]);
                }
                // The production-verified installed graph is the source authority for a complete
                // official replacement, including earlier scaffold omissions. App-owned overrides
                // remain conflicts; edits outside canonical integrity never reach this projection.
                if ($action['reason'] !== 'app_owned_adoption_required') {
                    $present = ($action['current']['present'] ?? false) === true;
                    $action['action'] = is_string($action['target_sha256'] ?? null)
                        ? ($present ? (($action['current']['sha256'] === $action['target_sha256']
                            && $action['current']['mode'] === $action['mode']) ? 'preserve' : 'replace') : 'create')
                        : ($present ? 'delete' : 'omit');
                    $action['reason'] = isset($managedArtifacts[$path]) || $this->withinAnyRoot($path, $managedRoots)
                        ? 'managed_bundled_module_complete_projection' : 'official_module_complete_projection';
                    $action['conflict'] = false;
                }
                continue;
            }
            if ($path === 'plugins.lock' && $action['reason'] === 'app_owned_adoption_required') {
                continue;
            }
            if ($path === 'plugins.lock' && $adoptionRequired === []) {
                $digest = hash('sha256', $official['lock_contents']);
                $action['action'] = ($action['current']['sha256'] ?? null) === $digest ? 'preserve' : 'replace';
                $action['reason'] = 'official_plugin_lock_projection';
                $action['conflict'] = false;
                $action['target_sha256'] = $digest;
                continue;
            }
            if ($path === 'plugins.lock' && $adoptionRequired !== []) {
                $action['action'] = 'conflict';
                $action['reason'] = 'plugin_adoption_required';
                $action['conflict'] = true;
                continue;
            }
            if (isset($targetArtifacts[$path]) && !isset($currentArtifacts[$path])) {
                $action['action'] = 'conflict';
                $action['reason'] = 'plugin_adoption_required';
                $action['conflict'] = true;
                continue;
            }
            if (in_array($action['reason'], ['app_owned_adoption_required', 'managed_adoption_required'], true)) {
                continue;
            }
            if (isset($currentArtifacts[$path]) || $this->withinAnyRoot($path, $currentRoots)) {
                $present = ($action['current']['present'] ?? false) === true;
                $action['action'] = $present ? 'preserve' : 'omit';
                $action['reason'] = $present ? 'installed_plugin_projection' : 'installed_plugin_target_only_omitted';
                $action['conflict'] = false;
                $action['target_sha256'] = $present ? $action['current']['sha256'] : null;
                $action['baseline_source'] = $present ? 'validated-plugin-projection' : 'absent-plugin-projection';
            }
        }
        unset($action);
        return $actions;
    }

    private function assertPluginProjection(string $root, array $plan, bool $applied = false): void
    {
        $expected = $applied
            ? ($plan['identity']['plugin_target']['projection'] ?? $plan['identity']['plugin_projection'] ?? null)
            : ($plan['identity']['plugin_projection'] ?? null);
        if ($expected === null) {
            return;
        }
        if (!is_array($expected) || $this->pluginProjection($root, sourceApplication: true) !== $expected) {
            throw new RuntimeException('SCAFFOLD_PLUGIN_PROJECTION_CHANGED');
        }
    }

    private function relativeDirectory(string $root, string $directory): string
    {
        $resolved = realpath($directory);
        if ($resolved === false
            || ($resolved !== $root && !str_starts_with($resolved, $root . DIRECTORY_SEPARATOR))) {
            throw new RuntimeException('SCAFFOLD_PLUGIN_PROJECTION_INVALID: PLUGIN_PATH_UNAVAILABLE');
        }
        $relative = str_replace(DIRECTORY_SEPARATOR, '/', substr($resolved, strlen($root) + 1));
        ScaffoldPathGuard::projectPath($root, ScaffoldManifest::path($relative));
        return $relative;
    }

    /** @param list<string> $roots */
    private function withinAnyRoot(string $path, array $roots): bool
    {
        foreach ($roots as $root) {
            if ($path === $root || str_starts_with($path, $root . '/')) {
                return true;
            }
        }
        return false;
    }

    /** @param list<string> $preservePaths @param list<string> $replacePaths */
    private function resolveConflicts(array $plan, string $confirmedPlanSha256, array $preservePaths, array $replacePaths): array
    {
        $actualPlanSha256 = 'sha256:' . hash('sha256', self::canonicalJson([$plan['identity'] ?? null, $plan['actions'] ?? null]));
        if (!hash_equals($actualPlanSha256, $confirmedPlanSha256)
            || (isset($plan['plan_sha256']) && !hash_equals((string) $plan['plan_sha256'], $actualPlanSha256))) {
            throw new RuntimeException('SCAFFOLD_RESOLUTION_CONFIRMATION_MISMATCH');
        }
        foreach ([$preservePaths, $replacePaths] as $paths) {
            if (array_filter($paths, static fn(mixed $path): bool => !is_string($path) || $path === '') !== []
                || count(array_unique($paths)) !== count($paths)) {
                throw new RuntimeException('SCAFFOLD_RESOLUTION_PATHS_INVALID');
            }
        }
        $preservePaths = array_values($preservePaths);
        $replacePaths = array_values($replacePaths);
        sort($preservePaths, SORT_STRING);
        sort($replacePaths, SORT_STRING);
        if (array_intersect($preservePaths, $replacePaths) !== []) {
            throw new RuntimeException('SCAFFOLD_RESOLUTION_PATHS_INVALID');
        }
        $conflictPaths = [];
        foreach ($plan['actions'] as $action) {
            if (($action['conflict'] ?? null) === true) {
                $conflictPaths[] = (string) $action['path'];
            }
        }
        sort($conflictPaths, SORT_STRING);
        $confirmedPaths = [...$preservePaths, ...$replacePaths];
        sort($confirmedPaths, SORT_STRING);
        if ($confirmedPaths !== $conflictPaths) {
            throw new RuntimeException('SCAFFOLD_RESOLUTION_PATHS_MISMATCH');
        }
        $preserve = array_fill_keys($preservePaths, true);
        $replace = array_fill_keys($replacePaths, true);
        $actions = $plan['actions'];
        foreach ($actions as &$action) {
            if (($action['conflict'] ?? null) !== true) {
                continue;
            }
            $path = (string) $action['path'];
            $selectedResolution = isset($preserve[$path]) ? 'preserve' : 'replace';
            if (isset($action['resolution_options'])
                && (!is_array($action['resolution_options'])
                    || !in_array($selectedResolution, $action['resolution_options'], true))) {
                throw new RuntimeException('SCAFFOLD_RESOLUTION_OPTION_UNSUPPORTED: ' . $path);
            }
            if (isset($preserve[$path])) {
                if (isset($action['rename_from'])) {
                    if (!in_array($action['rename_preserve'] ?? null, ['source', 'target'], true)) {
                        throw new RuntimeException('SCAFFOLD_RENAME_PRESERVE_INVALID: ' . $path);
                    }
                    $action['action'] = 'rename-preserve';
                    $action['reason'] = 'conflict_preserve_confirmed';
                    $action['conflict'] = false;
                    continue;
                }
                $ownershipTransition = ($action['reason'] ?? null) === 'recipe_ownership_transition_required'
                    && ($action['target_sha256'] ?? null) === null;
                $action['action'] = $ownershipTransition ? 'omit' : 'preserve';
                $action['reason'] = $ownershipTransition
                    ? 'recipe_ownership_transition_confirmed'
                    : 'conflict_preserve_confirmed';
                $action['conflict'] = false;
                continue;
            }
            if (!isset($replace[$path]) || !is_string($action['target_sha256'] ?? null)) {
                throw new RuntimeException('SCAFFOLD_RESOLUTION_PATHS_MISMATCH');
            }
            $action['action'] = isset($action['rename_from'])
                ? 'rename'
                : (($action['classification'] ?? null) === 'generated-managed' ? 'regenerate' : 'replace');
            $action['reason'] = 'conflict_replace_confirmed';
            $action['conflict'] = false;
        }
        unset($action);
        $resolved = $plan;
        $resolved['actions'] = $actions;
        $resolved['status'] = $this->moduleCompatibilityBlocked($resolved['identity']['plugin_target'] ?? null)
            ? 'blocked'
            : 'ready';
        $resolved['summary'] = $this->summary($actions);
        $resolved['impact'] = $this->withModuleCompatibilityImpact(
            $this->impact($actions),
            $resolved['identity']['plugin_target'] ?? null,
        );
        $resolved['resolution'] = [
            'from_candidate' => $plan['candidate'],
            'from_plan_sha256' => $actualPlanSha256,
            'preserve_paths' => $preservePaths,
            'replace_paths' => $replacePaths,
        ];
        $digest = hash('sha256', self::canonicalJson([$resolved['identity'], $resolved['actions']]));
        $resolved['candidate'] = 'scaffold-' . substr($digest, 0, 24);
        $resolved['plan_sha256'] = 'sha256:' . $digest;
        return $resolved;
    }

    private function summary(array $actions): array
    {
        $summary = ['total' => count($actions), 'automatic' => 0, 'preserved' => 0, 'conflicts' => 0];
        foreach ($actions as $action) {
            $action['conflict'] ? $summary['conflicts']++ : (in_array($action['action'], ['create','delete','replace','regenerate','rename'], true) ? $summary['automatic']++ : $summary['preserved']++);
        }
        return $summary;
    }

    private function impact(array $actions): array
    {
        $changes = [];
        $preserved = [];
        $conflicts = [];
        foreach ($actions as $action) {
            $item = ['path' => $action['path'], 'action' => $action['action'], 'reason' => $action['reason']];
            if (isset($action['rename_from'])) {
                $item['from'] = $action['rename_from'];
            }
            if (isset($action['resolution_options'])) {
                $item['resolution_options'] = $action['resolution_options'];
            }
            if ($action['conflict']) {
                $conflicts[] = $item;
            } elseif (in_array($action['action'], ['create', 'delete', 'replace', 'regenerate', 'rename', 'rename-preserve'], true)) {
                $changes[] = $item;
            } else {
                $preserved[] = $item;
            }
        }
        return [
            'message' => $conflicts === []
                ? sprintf('%d managed files will change; %d files will be preserved. Review the plan before apply.', count($changes), count($preserved))
                : sprintf('No files will be changed until %d conflicts are resolved.', count($conflicts)),
            'will_change' => $changes,
            'will_preserve' => $preserved,
            'must_resolve' => $conflicts,
            'ownership_notice' => 'App-owned files, third-party Modules and secrets are outside the automatic write set.',
        ];
    }

    /** @return array{APPLICATION_VERSION:string,PACKAGE_IDENTITY:string,PRODUCT_NAME:string,SLUG:string} */
    private function parameters(array $application, string $applicationVersion): array
    {
        return [
            'APPLICATION_VERSION' => $applicationVersion,
            'PACKAGE_IDENTITY' => (string) $application['application']['package_identity'],
            'PRODUCT_NAME' => (string) $application['application']['name'],
            'SLUG' => (string) $application['application']['slug'],
        ];
    }

    /**
     * Read the live application release authority without requiring installed
     * Composer dependencies and bind it to the currently adopted scaffold.
     *
     * @return array{0:array<string,int|string|null>,1:string}
     */
    private function versionContract(string $root, array $application): array
    {
        $path = ScaffoldPathGuard::projectPath($root, 'release-versions.json');
        if (!is_file($path) || is_link($path)) {
            throw new RuntimeException('SCAFFOLD_VERSION_CONTRACT_INVALID');
        }
        $raw = file_get_contents($path);
        $document = $this->normalizeVersionContractDocument(
            is_string($raw) ? $raw : '',
            'SCAFFOLD_VERSION_CONTRACT_INVALID',
        );
        if ($document['schema_version'] === 3) {
            $this->assertV3ArchiveDigests($root, $document['core_web']);
        }
        if ($document['scaffold_template'] !== ($application['template']['version'] ?? null)) {
            throw new RuntimeException('SCAFFOLD_VERSION_CONTRACT_IDENTITY_MISMATCH');
        }
        return [$document, 'sha256:' . hash('sha256', (string) $raw)];
    }

    /** Compare the known version contract semantically while all other managed files remain byte-exact. */
    private function renderedContentMatches(
        string $root,
        string $path,
        array $current,
        string $expected,
    ): bool {
        if (($current['present'] ?? false) !== true) {
            return false;
        }
        if ($path !== 'release-versions.json') {
            return hash_equals(hash('sha256', $expected), (string) $current['sha256']);
        }
        $actual = file_get_contents(ScaffoldPathGuard::projectPath($root, $path));
        return is_string($actual)
            && hash_equals(hash('sha256', $actual), (string) $current['sha256'])
            && $this->normalizeVersionContractDocument($actual, 'SCAFFOLD_VERSION_CONTRACT_INVALID')
                === $this->normalizeVersionContractDocument($expected, 'SCAFFOLD_VERSION_CONTRACT_TARGET_INVALID');
    }

    /** Parse the supported historical/current fields and return their stable semantic order. */
    private function normalizeVersionContractDocument(string $raw, string $error): array
    {
        try {
            $document = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            throw new RuntimeException($error, 0, $exception);
        }
        $keys = is_array($document) ? array_keys($document) : [];
        $v3 = is_array($document)
            && ($document['schema_version'] ?? null) === 3
            && ($document['protocol'] ?? null) === 'peanut.release-versions.v3';
        $v2 = is_array($document)
            && ($document['schema_version'] ?? null) === 2
            && ($document['protocol'] ?? null) === 'peanut.release-versions.v2';
        $expectedKeys = $v3
            ? self::VERSION_CONTRACT_V3_KEYS
            : ($v2 ? self::VERSION_CONTRACT_V2_KEYS : self::VERSION_CONTRACT_V1_KEYS);
        if (!is_array($document) || count($keys) !== count($expectedKeys)
            || array_diff($keys, $expectedKeys) !== [] || array_diff($expectedKeys, $keys) !== []
            || (!$v2 && !$v3 && (($document['schema_version'] ?? null) !== 1
                || ($document['protocol'] ?? null) !== 'peanut.release-versions.v1'))) {
            throw new RuntimeException($error);
        }
        $normalized = [];
        foreach ($expectedKeys as $key) {
            $value = $document[$key];
            if ($key === 'instance_version' && $value === null) {
                $normalized[$key] = null;
                continue;
            }
            if ($v3 && in_array($key, ['core_php', 'core_web'], true)) {
                $normalized[$key] = $value;
                continue;
            }
            if (!in_array($key, ['schema_version', 'protocol'], true)
                && (!is_string($value)
                    || !(($v2 || $v3) ? $this->isStrictSemanticVersion($value) : $this->isSemanticVersion($value)))) {
                throw new RuntimeException($error . ': ' . $key);
            }
            $normalized[$key] = $value;
        }
        if ($v2 && ($normalized['source_product_version'] !== $normalized['core_php']
                || $normalized['source_product_version'] !== $normalized['core_web']
                || $normalized['source_product_version'] !== $normalized['scaffold_template'])) {
            throw new RuntimeException($error . ': product-core-version-mismatch');
        }
        if ($v3) {
            if ($normalized['source_product_version'] !== $normalized['scaffold_template']) {
                throw new RuntimeException($error . ': product-template-version-mismatch');
            }
            $this->assertV3DependencyIdentity($normalized['core_php'], $normalized['core_web'], $error);
        }
        return $normalized;
    }

    /** Resolve only the customer instance sequence used by scaffold apply/verify plans. */
    private function instanceVersion(array $versionContract): string
    {
        $version = $versionContract['schema_version'] >= 2
            ? ($versionContract['instance_version'] ?? null)
            : ($versionContract['product_release'] ?? null);
        if (!is_string($version)
            || !($versionContract['schema_version'] >= 2
                ? $this->isStrictSemanticVersion($version)
                : $this->isSemanticVersion($version))) {
            throw new RuntimeException('SCAFFOLD_INSTANCE_VERSION_INVALID');
        }
        return $version;
    }

    /** Accept the SemVer surface already supported by scaffold application identities. */
    private function isSemanticVersion(string $version): bool
    {
        return preg_match('/^(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)(?:[-+][0-9A-Za-z.-]+)?$/D', $version) === 1;
    }

    private function isStrictSemanticVersion(string $version): bool
    {
        return preg_match(self::STRICT_SEMVER, $version) === 1;
    }

    private function assertV3DependencyIdentity(mixed $php, mixed $web, string $error): void
    {
        ReleaseDependencyIdentity::validate($php, $web, $error . ': core_php', $error . ': core_web');
    }

    private function assertV3ArchiveDigests(string $root, array $web): void
    {
        ReleaseDependencyIdentity::verifyArchives($root, $web, 'SCAFFOLD_VERSION_CONTRACT_CORE_WEB_ARCHIVE_INVALID');
    }

    private function releaseIdentity(ScaffoldManifest $manifest): array
    {
        return $manifest->release() + ['manifest_sha256' => $manifest->digest()];
    }
    private function assertManifestDigest(ScaffoldManifest $manifest, string $digest): void
    {
        if (!hash_equals($digest, $manifest->digest())) {
            throw new RuntimeException('SCAFFOLD_MANIFEST_CHECKSUM_DRIFT');
        }
    }

    private function applicationManifest(string $root): array
    {
        $path = ScaffoldPathGuard::projectPath($root, '.peanut/application-manifest.json');
        if (!is_file($path) || is_link($path)) {
            throw new RuntimeException('SCAFFOLD_APPLICATION_MANIFEST_MISSING');
        }
        $raw = file_get_contents($path);
        $data = is_string($raw) ? json_decode($raw, true, 512, JSON_THROW_ON_ERROR) : null;
        if (!is_array($data) || !is_array($data['application'] ?? null) || !is_array($data['files'] ?? null)) {
            throw new RuntimeException('SCAFFOLD_APPLICATION_MANIFEST_INVALID');
        }
        $protocol = $data['protocol'] ?? null;
        if (($data['schema_version'] ?? null) === 2 && $protocol === 'peanut.application-scaffold.v2') {
            $version = $data['application']['version'] ?? null;
        } elseif (($data['schema_version'] ?? null) === 1 && $protocol === 'peanut.application-scaffold.v1') {
            $version = $this->legacyApplicationVersion($root);
            $data['application']['version'] = $version;
        } else {
            throw new RuntimeException('SCAFFOLD_APPLICATION_MANIFEST_INVALID');
        }
        if (preg_match('/^(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)(?:[-+][0-9A-Za-z.-]+)?$/D', (string) $version) !== 1) {
            throw new RuntimeException('SCAFFOLD_APPLICATION_VERSION_INVALID');
        }
        return [$data, 'sha256:' . hash('sha256', (string) $raw)];
    }

    private function legacyApplicationVersion(string $root): string
    {
        $path = ScaffoldPathGuard::projectPath($root, 'RELEASE_METADATA.json');
        if (!is_file($path) || is_link($path)) {
            throw new RuntimeException('SCAFFOLD_LEGACY_APPLICATION_VERSION_UNAVAILABLE');
        }
        try {
            $metadata = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            throw new RuntimeException('SCAFFOLD_LEGACY_APPLICATION_VERSION_UNAVAILABLE', 0, $exception);
        }
        $candidates = [];
        if (is_array($metadata)
            && ($metadata['schema_version'] ?? null) === 2
            && ($metadata['protocol'] ?? null) === 'peanut.release-metadata.v2'
            && is_string($metadata['instance_version'] ?? null)) {
            $candidates[] = $metadata['instance_version'];
        }
        if (is_array($metadata) && is_string($metadata['version'] ?? null)) {
            $candidates[] = $metadata['version'];
        }
        if (is_array($metadata['application'] ?? null) && is_string($metadata['application']['version'] ?? null)) {
            $candidates[] = $metadata['application']['version'];
        }
        $candidates = array_values(array_unique($candidates, SORT_STRING));
        if (count($candidates) !== 1
            || preg_match('/^(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)(?:[-+][0-9A-Za-z.-]+)?$/D', $candidates[0]) !== 1) {
            throw new RuntimeException('SCAFFOLD_LEGACY_APPLICATION_VERSION_AMBIGUOUS');
        }
        return $candidates[0];
    }

    private function regularFileState(string $path, string $relative): array
    {
        if (!file_exists($path) && !is_link($path)) {
            return ['present' => false, 'sha256' => null, 'mode' => null];
        }
        if (!is_file($path) || is_link($path)) {
            throw new RuntimeException('SCAFFOLD_PATH_TYPE_REJECTED: ' . $relative);
        }
        $stat = lstat($path);
        if (!is_array($stat) || ($stat['nlink'] ?? 0) !== 1) {
            throw new RuntimeException('SCAFFOLD_PATH_HARDLINK_REJECTED: ' . $relative);
        }
        return ['present' => true, 'sha256' => hash_file('sha256', $path), 'mode' => fileperms($path) & 0777];
    }

    private function actionState(string $root, array $actions): array
    {
        $files = [];
        foreach ($actions as $action) {
            $files[$action['path']] = $this->regularFileState(ScaffoldPathGuard::projectPath($root, $action['path']), $action['path']);
            if (isset($action['rename_from'])) {
                $source = (string) $action['rename_from'];
                $files[$source] = $this->regularFileState(ScaffoldPathGuard::projectPath($root, $source), $source);
            }
        }
        ksort($files, SORT_STRING);
        return ['files' => $files, 'digest' => 'sha256:' . hash('sha256', self::canonicalJson($files))];
    }

    private function ownershipState(string $root, array $application, string $classification): array
    {
        $files = [];
        foreach ($application['files'] as $file) {
            if (($file['classification'] ?? null) === $classification) {
                $files[$file['path']] = $this->regularFileState(ScaffoldPathGuard::projectPath($root, $file['path']), $file['path']);
            }
        }
        ksort($files, SORT_STRING);
        return ['files' => $files, 'digest' => 'sha256:' . hash('sha256', self::canonicalJson($files))];
    }

    private function loadPlan(string $root, string $path): array
    {
        $resolved = ScaffoldPathGuard::existingFileWithin($root, $path, 'SCAFFOLD_PLAN_PATH_INVALID');
        $data = json_decode((string) file_get_contents($resolved), true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($data) || ($data['protocol'] ?? null) !== 'peanut.scaffold-upgrade-plan.v2' || preg_match('/^scaffold-[a-f0-9]{24}$/D', (string) ($data['candidate'] ?? '')) !== 1) {
            throw new RuntimeException('SCAFFOLD_PLAN_INVALID');
        }
        $digest = hash('sha256', self::canonicalJson([$data['identity'] ?? null,$data['actions'] ?? null]));
        $expected = 'scaffold-' . substr($digest, 0, 24);
        if (!hash_equals($expected, $data['candidate'])
            || (isset($data['plan_sha256']) && !hash_equals('sha256:' . $digest, (string) $data['plan_sha256']))) {
            throw new RuntimeException('SCAFFOLD_PLAN_CHECKSUM_DRIFT');
        }
        $expectedStatus = count(array_filter($data['actions'], static fn(array $action): bool => ($action['conflict'] ?? null) === true)) === 0
            && !$this->moduleCompatibilityBlocked($data['identity']['plugin_target'] ?? null)
            ? 'ready'
            : 'blocked';
        if (($data['status'] ?? null) !== $expectedStatus) {
            throw new RuntimeException('SCAFFOLD_PLAN_STATUS_DRIFT');
        }
        $expectedImpact = $this->withModuleCompatibilityImpact(
            $this->impact($data['actions']),
            $data['identity']['plugin_target'] ?? null,
        );
        if (($data['impact'] ?? null) !== $expectedImpact) {
            throw new RuntimeException('SCAFFOLD_PLAN_IMPACT_DRIFT');
        }
        return $data;
    }

    private function loadAdoptionPlan(string $root, string $path): array
    {
        $resolved = ScaffoldPathGuard::existingFileWithin($root, $path, 'SCAFFOLD_ADOPTION_PLAN_PATH_INVALID');
        $data = json_decode((string) file_get_contents($resolved), true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($data)
            || ($data['schema_version'] ?? null) !== 1
            || ($data['protocol'] ?? null) !== 'peanut.scaffold-ownership-adoption-plan.v1'
            || ($data['status'] ?? null) !== 'ready'
            || preg_match('/^ownership-adoption-[a-f0-9]{24}$/D', (string) ($data['candidate'] ?? '')) !== 1
            || !is_array($data['identity'] ?? null)
            || !is_array($data['actions'] ?? null)
            || !is_array($data['paths'] ?? null)
            || !is_array($data['metadata_writes'] ?? null)) {
            throw new RuntimeException('SCAFFOLD_ADOPTION_PLAN_INVALID');
        }
        $paths = EditionUpgradePackage::OWNERSHIP_ADOPTION_PATHS;
        sort($paths, SORT_STRING);
        $candidateDigest = hash('sha256', self::canonicalJson([$data['identity'], $data['actions']]));
        $metadataWrites = ['.peanut/application-manifest.json'];
        foreach ($data['actions'] as $action) {
            if (!is_array($action) || !is_string($action['path'] ?? null)) {
                throw new RuntimeException('SCAFFOLD_ADOPTION_PLAN_INVALID');
            }
            $metadataWrites[] = (string) ($action['baseline_path'] ?? '');
        }
        sort($metadataWrites, SORT_STRING);
        if ($data['paths'] !== $paths
            || array_column($data['actions'], 'path') !== $paths
            || $data['metadata_writes'] !== $metadataWrites
            || !hash_equals('ownership-adoption-' . substr($candidateDigest, 0, 24), $data['candidate'])
            || !hash_equals('sha256:' . $candidateDigest, (string) ($data['plan_sha256'] ?? ''))
            || !hash_equals('sha256:' . hash('sha256', self::canonicalJson($paths)), (string) ($data['paths_sha256'] ?? ''))) {
            throw new RuntimeException('SCAFFOLD_ADOPTION_PLAN_CHECKSUM_DRIFT');
        }
        return $data;
    }

    private function assertActionFresh(string $root, array $action): void
    {
        $actual = $this->regularFileState(ScaffoldPathGuard::projectPath($root, $action['path']), $action['path']);
        if ($actual !== $action['current']) {
            throw new RuntimeException('SCAFFOLD_PLAN_PROJECT_CHANGED: ' . $action['path']);
        }
        if (isset($action['rename_from'])) {
            $source = (string) $action['rename_from'];
            $actualSource = $this->regularFileState(ScaffoldPathGuard::projectPath($root, $source), $source);
            if ($actualSource !== ($action['rename_from_current'] ?? null)) {
                throw new RuntimeException('SCAFFOLD_PLAN_PROJECT_CHANGED: ' . $source);
            }
        }
    }

    /** Reject any manifest, version authority, managed, app-owned, or release drift since preflight. */
    private function assertPlanFresh(string $root, array $plan): void
    {
        $deployment = $root . '/server/private/installation/deployment.json';
        $actualDeployment = file_exists($deployment) || is_link($deployment)
            ? hash_file('sha256', ScaffoldPathGuard::existingFileWithin($root, $deployment, 'SCAFFOLD_DEPLOYMENT_INVALID')) : null;
        if (($plan['identity']['deployment_state_sha256'] ?? null) !== $actualDeployment) {
            throw new RuntimeException('SCAFFOLD_PLAN_DEPLOYMENT_CHANGED');
        }
        [$application, $manifestDigest] = $this->applicationManifest($root);
        if (!hash_equals($plan['identity']['application_manifest_sha256'], $manifestDigest)) {
            throw new RuntimeException('SCAFFOLD_PLAN_APPLICATION_LOCK_CHANGED');
        }
        $this->assertPluginProjection($root, $plan);
        [$versionContract, $versionContractDigest] = $this->versionContract($root, $application);
        if ($versionContract !== ($plan['identity']['version_contract'] ?? null)
            || $versionContractDigest !== ($plan['identity']['version_contract_sha256'] ?? null)
        ) {
            throw new RuntimeException('SCAFFOLD_PLAN_VERSION_CONTRACT_CHANGED');
        }
        $managed = $this->actionState($root, $plan['actions']);
        if (!hash_equals($plan['identity']['managed_pre_sha256'], $managed['digest'])) {
            throw new RuntimeException('SCAFFOLD_PLAN_PROJECT_CHANGED');
        }
        [$application] = $this->applicationManifest($root);
        $app = $this->ownershipState($root, $application, 'app-owned');
        if (!hash_equals($plan['identity']['app_owned_pre_sha256'], $app['digest'])) {
            throw new RuntimeException('SCAFFOLD_PLAN_PROJECT_CHANGED');
        }
        $this->assertManifestDigest(ScaffoldManifest::load($plan['manifest_paths']['from']), $plan['identity']['from']['manifest_sha256']);
        $this->assertManifestDigest(ScaffoldManifest::load($plan['manifest_paths']['to']), $plan['identity']['to']['manifest_sha256']);
    }

    /**
     * Revalidate the complete committed target before an idempotent apply/verify returns success.
     *
     * @return array{0:array<string,mixed>,1:array{digest:string,files:array<string,mixed>}}
     */
    private function assertAppliedState(string $root, array $plan): array
    {
        [$application] = $this->applicationManifest($root);
        $to = ScaffoldManifest::load($plan['manifest_paths']['to']);
        $this->assertManifestDigest($to, $plan['identity']['to']['manifest_sha256']);
        if (($application['template']['version'] ?? null) !== $to->version()
            || ($application['template']['source_commit'] ?? null) !== $to->release()['source_commit']
            || ($application['template']['source_tree'] ?? null) !== $to->release()['source_tree']
            || ($application['application']['version'] ?? null) !== $plan['identity']['application_version']
            || ($application['generation_source'] ?? null) !== ($plan['identity']['target_generation_source'] ?? null)
            || ($application['edition'] ?? null) !== ($plan['identity']['edition'] ?? null)) {
            throw new RuntimeException('SCAFFOLD_VERIFY_APPLICATION_IDENTITY_MISMATCH');
        }
        $actualAppOwned = $this->ownershipState(
            $root,
            ['files' => array_values(array_filter(
                $application['files'],
                static fn(array $file): bool => $file['classification'] === 'app-owned',
            ))],
            'app-owned',
        );
        if (!hash_equals($plan['identity']['app_owned_pre_sha256'], $actualAppOwned['digest'])) {
            throw new RuntimeException('SCAFFOLD_VERIFY_APP_OWNED_CHANGED');
        }
        $this->assertPluginProjection($root, $plan, true);
        foreach ($application['files'] as $file) {
            if (!in_array($file['classification'], ['managed', 'generated-managed'], true)) {
                continue;
            }
            $path = ScaffoldPathGuard::projectPath($root, $file['path']);
            if (!is_file($path) || is_link($path)
                || !hash_equals((string) $file['sha256'], (string) hash_file('sha256', $path))
                || ((fileperms($path) & 0777) !== ($file['mode'] ?? 0644))) {
                throw new RuntimeException('SCAFFOLD_VERIFY_MANAGED_MISMATCH: ' . $file['path']);
            }
        }
        return [$application, $actualAppOwned];
    }

    /** Rebuild the plan from its immutable manifests so edited actions cannot claim another ownership class or path. */
    private function assertPlanRebound(string $root, array $plan): void
    {
        $expected = $this->preview(
            $root,
            (string) $plan['manifest_paths']['from'],
            (string) $plan['manifest_paths']['to'],
            $plan['identity']['source_selection'] ?? null,
        );
        if (isset($plan['resolution'])) {
            $resolution = $plan['resolution'];
            if (!is_array($resolution)
                || !hash_equals((string) ($resolution['from_candidate'] ?? ''), (string) $expected['candidate'])
                || !hash_equals((string) ($resolution['from_plan_sha256'] ?? ''), (string) $expected['plan_sha256'])
                || !is_array($resolution['preserve_paths'] ?? null)
                || !is_array($resolution['replace_paths'] ?? null)) {
                throw new RuntimeException('SCAFFOLD_PLAN_MANIFEST_REBIND_FAILED');
            }
            $expected = $this->resolveConflicts(
                $expected,
                (string) $expected['plan_sha256'],
                $resolution['preserve_paths'],
                $resolution['replace_paths'],
            );
        }
        if (!hash_equals((string) $expected['candidate'], (string) $plan['candidate'])
            || !hash_equals((string) $expected['plan_sha256'], (string) ($plan['plan_sha256'] ?? ''))) {
            throw new RuntimeException('SCAFFOLD_PLAN_MANIFEST_REBIND_FAILED');
        }
    }

    private function ledger(string $root): ScaffoldUpgradeLedger
    {
        return new ScaffoldUpgradeLedger(ScaffoldPathGuard::projectPath($root, '.peanut/upgrades/ledger.ndjson'));
    }
    private function hasEvent(ScaffoldUpgradeLedger $ledger, string $candidate, string $operation, string $status): bool
    {
        foreach ($ledger->entries($candidate) as $entry) {
            if (($entry['operation'] ?? null) === $operation && ($entry['status'] ?? null) === $status) {
                return true;
            }
        } return false;
    }
    private function candidateState(ScaffoldUpgradeLedger $ledger, string $candidate): ?string
    {
        $entries = $ledger->entries($candidate);
        return $entries === [] ? null : (string) ($entries[array_key_last($entries)]['status'] ?? '');
    }
    private function event(array $plan, string $operation, string $status, ?string $pre, ?string $post, array $extra = []): array
    {
        return ['schema_version' => 2,'candidate' => $plan['candidate'],'operation' => $operation,'status' => $status,'from' => $plan['identity']['from'],'to' => $plan['identity']['to'],'pre_sha256' => $pre,'post_sha256' => $post,
            'plan_sha256' => $plan['plan_sha256'] ?? null, 'identity_sha256' => hash('sha256', self::canonicalJson($plan['identity']))]
            + self::$runtimeEventBinding + $extra;
    }

    private function locked(string $projectRoot, callable $operation): array
    {
        $root = ScaffoldPathGuard::projectRoot($projectRoot);
        return self::sourceProductLock($root, static fn() => self::sourceLock(
            $root . '/.peanut/upgrades/project.lock', static function () use ($root, $operation) {
                if (file_exists($root . '/server/.peanut/development-runtime.json')
                    || is_link($root . '/server/.peanut/development-runtime.json')
                    || file_exists($root . '/server/.peanut/internal-candidate.json')
                    || file_exists($root . '/server/runtime/upgrade/source-transition.json')) {
                    require_once dirname(__DIR__, 2) . '/server/docker/scripts/update-plan.php';
                    return \PeanutServerUpdatePlan::withSourceInstanceLock($root . '/server', static fn() => $operation($root));
                }
                return $operation($root);
            }));
    }

    private function runtimeRead(string $path): array
    {
        if (realpath($path) !== $path) { throw new RuntimeException('SOURCE_RUNTIME_NONCANONICAL_RECORD'); }
        $root = dirname($path);
        ScaffoldPathGuard::existingFileWithin($root, $path, 'SOURCE_RUNTIME_RECORD_UNSAFE');
        $value = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($value)) { throw new RuntimeException('SOURCE_RUNTIME_RECORD_INVALID'); }
        return $value;
    }

    private function runtimeCheckpoint(string $path, string $root): array
    {
        $state = $this->runtimeRead($path);
        $digest = $state['state_sha256'] ?? null;
        unset($state['state_sha256']);
        if (($state['protocol'] ?? null) !== 'peanut.source-runtime-transition.v1'
            || ($state['binding']['instance_root'] ?? null) !== $root
            || ($state['binding']['workspace'] ?? null) !== dirname($path)
            || $digest !== hash('sha256', self::canonicalJson($state))
            || (fileperms($path) & 0777) !== 0600
            || !in_array(fileowner($path), [0, fileowner($root)], true)
            || (fileperms(dirname($path)) & 0077) !== 0) {
            throw new RuntimeException('SOURCE_RUNTIME_CHECKPOINT_INVALID');
        }
        foreach ($state['binding']['tools'] as $relative => $sha) {
            foreach ([dirname($path) . '/trusted/' . $relative] as $tool) {
                ScaffoldPathGuard::existingFileWithin(dirname($tool), $tool, 'SOURCE_RUNTIME_TOOL_UNSAFE');
                if (hash_file('sha256', $tool) !== $sha || (fileperms($tool) & 0777) !== $state['binding']['tool_modes'][$relative]) { throw new RuntimeException('SOURCE_RUNTIME_TOOL_CHANGED'); }
            }
        }
        foreach ($state['binding']['tool_dependencies'] as $file => $proof) {
            if (realpath($file) !== $file || !is_file($file) || lstat($file)['nlink'] !== 1
                || hash_file('sha256', $file) !== $proof['sha256'] || (fileperms($file) & 0777) !== $proof['mode']) {
                throw new RuntimeException('SOURCE_RUNTIME_TOOL_DEPENDENCY_CHANGED');
            }
        }
        $this->runtimeHost($state, 'tools-check');
        $server = $root . '/server';
        if (isset($state['binding']['internal_preinstall'])) {
            if (isset($state['installation_completion'])) {
                \PeanutServerUpdatePlan::assertInternalPreinstallCompletion($server, $state);
            } else {
                \PeanutServerUpdatePlan::recheckInternalPreinstallAnchors($server, $state['binding']['internal_preinstall']);
            }
            $this->runtimeOldProgram($root, $state);
        } else { \PeanutServerUpdatePlan::assertSourceInstallationAnchors($server, $state['binding']['deployment']); }
        return $state;
    }

    private function runtimeDependencyInputs(string $sourceRoot, string $server, string $helperRoot): array
    {
        $source = $sourceRoot . '/server';
        $composer = $this->runtimeRead($source . '/composer.json');
        $lock = $this->runtimeRead($source . '/composer.lock');
        $files = [];
        $add = function (string $relative, string $file) use (&$files, $sourceRoot, $helperRoot): void {
            $base = str_starts_with($file, $helperRoot . '/') ? $helperRoot : $sourceRoot;
            $file = ScaffoldPathGuard::existingFileWithin($base, $file, 'SOURCE_RUNTIME_DEPENDENCY_INPUT_UNSAFE');
            $files[$relative] = ['source' => $file, 'sha256' => hash_file('sha256', $file), 'mode' => fileperms($file) & 0777];
        };
        foreach (['composer.json', 'composer.lock'] as $name) { $add($name, $source . '/' . $name); }
        $add('docker/scripts/prepare-vendor.php', $helperRoot . '/server/docker/scripts/prepare-vendor.php');
        $paths = $composer['autoload']['classmap'] ?? [];
        foreach (['psr-0', 'psr-4'] as $kind) {
            foreach ($composer['autoload'][$kind] ?? [] as $value) { array_push($paths, ...(is_array($value) ? $value : [$value])); }
        }
        array_push($paths, ...($composer['autoload']['files'] ?? []));
        foreach (array_unique($paths) as $relative) {
            $relative = rtrim($relative, '/');
            ScaffoldManifest::path($relative);
            $path = $source . '/' . rtrim($relative, '/');
            if (is_link($path) || realpath($path) !== $path) { throw new RuntimeException('SOURCE_RUNTIME_AUTOLOAD_INPUT_UNSAFE'); }
            if (is_file($path)) { $add($relative, $path); continue; }
            if (!is_dir($path)) { throw new RuntimeException('SOURCE_RUNTIME_AUTOLOAD_INPUT_MISSING'); }
            $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS));
            foreach ($iterator as $file) {
                if ($file->isLink()) { throw new RuntimeException('SOURCE_RUNTIME_AUTOLOAD_INPUT_LINKED'); }
                if ($file->isFile() && str_ends_with($file->getFilename(), '.php')) {
                    $add(substr($file->getPathname(), strlen($source) + 1), $file->getPathname());
                }
            }
        }
        $archives = [];
        foreach ($lock['packages'] ?? [] as $package) {
            $url = $package['dist']['url'] ?? '';
            if ($url === '' || str_starts_with($url, 'https://')) { continue; }
            if ($package['name'] !== 'peanut-admin/core' || ($package['dist']['type'] ?? null) !== 'zip'
                || preg_match('#^\.\./(packages/core-php/peanut-admin-core-([0-9A-Za-z.+-]+)\.zip)$#D', $url, $match) !== 1) {
                throw new RuntimeException('SOURCE_RUNTIME_LOCAL_DEPENDENCY_REJECTED');
            }
            Semver::compare($match[2], $match[2]);
            $manifest = $this->runtimeRead($sourceRoot . '/.peanut/application-manifest.json');
            $versions = $this->runtimeRead($sourceRoot . '/release-versions.json');
            $core = $versions['core_php'] ?? [];
            $entries = array_values(array_filter($manifest['files'] ?? [], static fn(array $row): bool => $row['path'] === $match[1]));
            $archive = ScaffoldPathGuard::existingFileWithin($sourceRoot, $sourceRoot . '/' . $match[1], 'SOURCE_RUNTIME_CORE_ARCHIVE_UNSAFE');
            if (($manifest['protocol'] ?? null) !== 'peanut.application-scaffold.v2'
                || ($versions['protocol'] ?? null) !== 'peanut.release-versions.v3' || count($entries) !== 1
                || ($entries[0]['classification'] ?? null) !== 'managed' || hash_file('sha256', $archive) !== $entries[0]['sha256']
                || ($core['source_reference'] ?? null) !== ($package['source']['reference'] ?? null)
                || ($core['source_url'] ?? null) !== ($package['source']['url'] ?? null)
                || ($core['resolved_version'] ?? null) !== $package['version'] || $match[2] !== $package['version']
                || ($package['dist']['reference'] ?? null) !== $core['source_reference']
                || hash_file('sha1', $archive) !== ($package['dist']['shasum'] ?? null) || (fileperms($archive) & 0777) !== 0644) {
                throw new RuntimeException('SOURCE_RUNTIME_CORE_ARCHIVE_PROOF_INVALID');
            }
            $archives[$match[1]] = ['sha256' => $entries[0]['sha256'], 'sha1' => $package['dist']['shasum'],
                'source_reference' => $core['source_reference'], 'version' => $core['resolved_version']];
        }
        ksort($files); ksort($archives);
        return ['server_root' => $server, 'source_root' => $sourceRoot, 'files' => $files, 'archives' => $archives,
            'composer_sha256' => hash_file('sha256', $source . '/composer.json'), 'lock_sha256' => hash_file('sha256', $source . '/composer.lock')];
    }

    private function runtimeToolBundle(string $root, string $workspace, array $binding): array
    {
        $originalVendor = $binding['tool_root'] . '/server/vendor';
        $server = $workspace . '/tools/prepared/server';
        $inputs = $this->runtimeDependencyInputs($binding['tool_root'], $server, $workspace . '/trusted');
        $loaded = [];
        $metadata = array_merge([$originalVendor . '/autoload.php', $originalVendor . '/composer/installed.json'],
            array_values(array_filter(glob($originalVendor . '/composer/*.php') ?: [],
                static fn(string $file): bool => preg_match('/^(autoload_[A-Za-z0-9_]+|installed|platform_check)\.php$/D', basename($file)) === 1)));
        foreach ($metadata as $file) {
            $relative = substr($file, strlen($originalVendor) + 1);
            $proof = $this->regularFileState($file, $relative);
            if (!$proof['present']) { throw new RuntimeException('SOURCE_RUNTIME_TOOL_METADATA_MISSING'); }
            $inputs['files']['.tool-metadata/' . $relative] = ['source' => $file, 'sha256' => $proof['sha256'], 'mode' => $proof['mode']];
            $inputs['tool_metadata'][] = $relative;
        }
        $schema = $originalVendor . '/peanut-admin/core/resources/schemas/module-manifest.schema.json';
        foreach (array_unique([...get_included_files(), $schema]) as $file) {
            if (str_starts_with($file, $originalVendor . '/')) {
                $relative = substr($file, strlen($originalVendor) + 1);
                $proof = $this->regularFileState($file, $relative);
                if (!$proof['present']) { throw new RuntimeException('SOURCE_RUNTIME_LOADED_DEPENDENCY_MISSING'); }
                $loaded[$relative] = ['sha256' => $proof['sha256'], 'mode' => $proof['mode']];
            } elseif (str_starts_with($file, $binding['tool_root'] . '/')) {
                $relative = substr($file, strlen($binding['tool_root']) + 1);
                $binding['tools'][$relative] = hash_file('sha256', $file);
                $binding['tool_modes'][$relative] = fileperms($file) & 0777;
                $this->writeFileAtomic($workspace . '/trusted/' . $relative, (string) file_get_contents($file), $binding['tool_modes'][$relative]);
            } else { throw new RuntimeException('SOURCE_RUNTIME_UNBOUND_LOADED_DEPENDENCY'); }
        }
        foreach ($inputs['files'] as $relative => $proof) {
            if (!str_ends_with($relative, '.php') || str_starts_with($relative, '.tool-metadata/')) { continue; }
            $relative = 'server/' . $relative;
            if (isset($binding['tools'][$relative]) && $binding['tools'][$relative] !== $proof['sha256']) {
                throw new RuntimeException('SOURCE_RUNTIME_TOOL_SOURCE_COLLISION');
            }
            $binding['tools'][$relative] = $proof['sha256']; $binding['tool_modes'][$relative] = $proof['mode'];
            $this->writeFileAtomic($workspace . '/trusted/' . $relative, (string) file_get_contents($proof['source']), $proof['mode']);
        }
        ksort($binding['tools']); ksort($binding['tool_modes']); ksort($loaded);
        $document = ['protocol' => 'peanut.source-tools-inputs.v1', 'instance_root' => $root, 'workspace' => $workspace,
            'inputs' => $inputs, 'loaded_vendor' => $loaded, 'tools' => $binding['tools'], 'tool_modes' => $binding['tool_modes'],
            'source_binding' => array_intersect_key($binding, array_flip(['candidate', 'plan_sha256', 'package_inventory_sha256', 'deployment', 'old_runtime_sha256', 'old_identity', 'internal_preinstall', 'source_input_kind']))];
        $path = $workspace . '/tool-inputs.json';
        if (file_exists($path) || is_link($path)) {
            if (self::canonicalJson($this->runtimeRead($path)) !== self::canonicalJson($document)) { throw new RuntimeException('SOURCE_RUNTIME_TOOL_INPUT_RETRY_CHANGED'); }
        } else { $this->writeJsonAtomic($path, $document, 0600); }
        $result = $this->runtimeHost(['binding' => $binding, 'transition_id' => hash_file('sha256', $path)], 'tools');
        $binding['tool_autoload'] = $server . '/vendor/autoload.php';
        $binding['tool_vendor_root'] = $server . '/vendor'; $binding['tool_source_root'] = dirname($server);
        $binding['tool_inputs_sha256'] = hash_file('sha256', $path);
        $binding['tool_bundle_sha256'] = $result['tool_bundle_sha256'];
        $binding['tool_image'] = $result['tool_image']; $binding['tool_project'] = $result['tool_project'];
        return $this->runtimeFreezePhp($workspace, $binding);
    }

    private function runtimeFreezePhp(string $workspace, array $binding): array
    {
        $php = []; $dependencies = $binding['tool_dependencies'] ?? [];
        foreach (get_included_files() as $file) {
            $original = $binding['tool_root'] . '/server/vendor/';
            if (str_starts_with($file, $original)) {
                $fixed = $binding['tool_vendor_root'] . '/' . substr($file, strlen($original));
                if (realpath($fixed) !== $fixed || hash_file('sha256', $fixed) !== hash_file('sha256', $file)
                    || (fileperms($fixed) & 0777) !== (fileperms($file) & 0777)) { throw new RuntimeException('SOURCE_RUNTIME_TOOL_DEPENDENCY_IDENTITY_CHANGED'); }
                $file = $fixed;
            }
            $relative = null;
            foreach ([$workspace . '/trusted', $binding['tool_root']] as $base) {
                if (str_starts_with($file, $base . '/') && !str_contains($file, '/vendor/')) { $relative = substr($file, strlen($base) + 1); break; }
            }
            if ($relative !== null) {
                $php[] = $relative;
                $target = $workspace . '/trusted/' . $relative;
                $sha = hash_file('sha256', $file);
                if (isset($binding['tools'][$relative]) && $binding['tools'][$relative] !== $sha) { throw new RuntimeException('SOURCE_RUNTIME_FIXED_PHP_CHANGED'); }
                if (!file_exists($target)) { $this->writeFileAtomic($target, (string) file_get_contents($file), fileperms($file) & 0777); }
                $binding['tools'][$relative] = $sha;
                $binding['tool_modes'][$relative] = fileperms($target) & 0777;
            } else {
                $canonical = realpath($file);
                if (!str_starts_with($canonical, $binding['tool_vendor_root'] . '/')) { throw new RuntimeException('SOURCE_RUNTIME_UNBOUND_TOOL_DEPENDENCY'); }
                $proof = ['sha256' => hash_file('sha256', $canonical), 'mode' => fileperms($canonical) & 0777];
                if (isset($dependencies[$canonical]) && $dependencies[$canonical] !== $proof) { throw new RuntimeException('SOURCE_RUNTIME_TOOL_DEPENDENCY_CHANGED'); }
                $dependencies[$canonical] = $proof;
            }
        }
        $kernelSchema = $binding['tool_vendor_root'] . '/peanut-admin/core/resources/schemas/module-manifest.schema.json';
        if (is_file($kernelSchema)) {
            $canonical = realpath($kernelSchema);
            $proof = ['sha256' => hash_file('sha256', $canonical), 'mode' => fileperms($canonical) & 0777];
            if (isset($dependencies[$canonical]) && $dependencies[$canonical] !== $proof) { throw new RuntimeException('SOURCE_RUNTIME_TOOL_DEPENDENCY_CHANGED'); }
            $dependencies[$canonical] = $proof;
        }
        foreach (glob(dirname($binding['tool_autoload']) . '/composer/*.php') ?: [] as $file) {
            $canonical = realpath($file);
            $proof = ['sha256' => hash_file('sha256', $canonical), 'mode' => fileperms($canonical) & 0777];
            if (isset($dependencies[$canonical]) && $dependencies[$canonical] !== $proof) { throw new RuntimeException('SOURCE_RUNTIME_TOOL_DEPENDENCY_CHANGED'); }
            $dependencies[$canonical] = $proof;
        }
        $binding['php_files'] = array_values(array_unique([...($binding['php_files'] ?? []), ...$php]));
        $binding['tool_dependencies'] = $dependencies;
        return $binding;
    }

    private function runtimeSave(string $path, array $state): array
    {
        unset($state['state_sha256']);
        $state['state_sha256'] = hash('sha256', self::canonicalJson($state));
        $this->writeJsonAtomic($path, $state, 0600);
        return $state;
    }

    private function runtimePointer(array $state, string $phase): void
    {
        \PeanutServerUpdatePlan::publishSourcePending($state['binding']['instance_root'] . '/server', [
            'transition_id' => $state['transition_id'], 'checkpoint' => $state['binding']['workspace'] . '/source-runtime.json',
            'binding_sha256' => hash('sha256', self::canonicalJson($state['binding'])),
            'phase' => $phase, 'activation_started' => $state['activation_started'],
            'deployment_sha256' => $state['published_head_sha256'] ?? $state['binding']['deployment']['head_sha256'],
            'runtime_sha256' => (($state['recovery_admitted'] ?? false) || $state['phase'] === 'recovered')
                ? ($state['binding']['old_runtime_sha256'] ?? null) : ($state['prepared_identity_sha256'] ?? $state['binding']['old_runtime_sha256'] ?? null),
            ...(isset($state['binding']['old_identity']) ? ['original_identity' => array_intersect_key($state['binding']['old_identity'], array_flip(['kind', 'sha256']))] : []),
        ]);
    }

    private function runtimeHost(array $state, string $phase): array
    {
        $workspace = $state['binding']['workspace'];
        $command = ['python3', $workspace . '/trusted/scripts/product-upgrade-host', 'source-' . $phase,
            '--instance-root=' . $state['binding']['instance_root'], '--workspace=' . $workspace];
        $process = proc_open($command, [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        if (!is_resource($process)) { throw new RuntimeException('SOURCE_RUNTIME_HOST_UNAVAILABLE'); }
        $output = stream_get_contents($pipes[1]);
        $error = stream_get_contents($pipes[2]);
        fclose($pipes[1]); fclose($pipes[2]);
        $code = proc_close($process);
        if ($code !== 0 || strlen((string) $output) > 32768) {
            throw new RuntimeException('SOURCE_RUNTIME_HOST_FAILED: ' . $phase . ':' . $code . ':' . hash('sha256', (string) $error));
        }
        $result = json_decode((string) $output, true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($result) || ($result['phase'] ?? null) !== $phase
            || ($result['transition_id'] ?? null) !== $state['transition_id']) {
            throw new RuntimeException('SOURCE_RUNTIME_HOST_RESULT_INVALID');
        }
        return $result;
    }

    private function runtimeOldIdentity(array $state): array
    {
        return $state['binding']['old_identity']['document'] ?? $state['binding']['old_runtime'];
    }

    /** Held native origin plus exact current-or-own-preimage proof; never reverify changed source as an old release. */
    private function runtimeOldProgram(string $root, array $state): void
    {
        $context = $state['binding']['internal_preinstall'];
        $old = $this->runtimeOldIdentity($state);
        if (($state['binding']['source_input_kind'] ?? null) !== 'raw-internal-preinstall-scaffold'
            || ($old['protocol'] ?? null) !== 'peanut.server-release.v1'
            || ($old['application']['kind'] ?? null) !== 'internal-candidate'
            || ($old['application']['formal_release_eligible'] ?? null) !== false
            || self::canonicalJson($context['old_identity'] ?? []) !== self::canonicalJson($state['binding']['old_identity'])
            || !is_array($old['files'] ?? null) || $old['files'] === []
            || hash('sha256', json_encode($old['files'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)) !== ($old['files_sha256'] ?? null)) {
            throw new RuntimeException('SOURCE_RUNTIME_INTERNAL_ORIGIN_INVALID');
        }
        $plan = $this->runtimePackage($root, $state);
        if ($plan === null || self::canonicalJson($plan['identity']['internal_preinstall'] ?? []) !== self::canonicalJson($context)) {
            throw new RuntimeException('SOURCE_RUNTIME_INTERNAL_PLAN_CHANGED');
        }
        $actions = array_column($plan['actions'], null, 'path');
        $sourceFiles = []; $sourceBase = null;
        if (isset($state['source_recovery'])) {
            $recovery = ScaffoldPathGuard::existingFileWithin($root, $root . '/' . $state['source_recovery'], 'SOURCE_RUNTIME_PREIMAGE_MISSING');
            if (hash_file('sha256', $recovery) !== ($state['source_recovery_sha256'] ?? null)) { throw new RuntimeException('SOURCE_RUNTIME_PREIMAGE_CHANGED'); }
            $sourceFiles = $this->runtimeRead($recovery)['files']; $sourceBase = dirname($recovery);
        }
        $proofs = array_column($old['files'], null, 'path');
        foreach ($context['originals'] as $relative => $proof) { $proofs[$relative] = $proof; }
        foreach ($proofs as $relative => $proof) {
            $expected = ['present' => true, 'sha256' => $proof['sha256'], 'mode' => $proof['mode']];
            $actual = $this->regularFileState(ScaffoldPathGuard::projectPath($root, $relative), $relative);
            if ($actual === $expected) { continue; }
            $preimage = $state['backups'][$relative] ?? $sourceFiles[$relative] ?? null;
            $base = isset($state['backups'][$relative]) ? $state['binding']['workspace'] : $sourceBase;
            if ($preimage === null || $base === null || array_intersect_key($preimage, $expected) !== $expected
                || hash_file('sha256', ScaffoldPathGuard::existingFileWithin($base, $base . '/' . $preimage['backup'], 'SOURCE_RUNTIME_PREIMAGE_INVALID')) !== $proof['sha256']) {
                throw new RuntimeException('SOURCE_RUNTIME_OLD_PROGRAM_PREIMAGE_MISSING: ' . $relative);
            }
            $intent = $state['intents'][$relative] ?? null;
            if ($intent !== null) {
                $after = $intent['after'] === null ? ['present' => false, 'sha256' => null, 'mode' => null]
                    : ['present' => true, 'sha256' => $intent['after']['sha256'], 'mode' => $intent['after']['mode']];
                if ($intent['before'] !== $expected || $actual !== $after) { throw new RuntimeException('SOURCE_RUNTIME_INTERNAL_AUTHORITY_DRIFT'); }
            } elseif ($relative === '.peanut/application-manifest.json') {
                $this->assertAppliedState($root, $plan);
            } else {
                $action = $actions[$relative] ?? null;
                if ($action === null || !in_array($action['action'], ['create', 'replace', 'regenerate'], true)
                    || $actual !== ['present' => true, 'sha256' => $action['target_sha256'], 'mode' => $action['mode']]) {
                    throw new RuntimeException('SOURCE_RUNTIME_INTERNAL_SOURCE_AFTERIMAGE_DRIFT: ' . $relative);
                }
            }
        }
        // The original document itself remains recoverable, byte-for-byte, throughout the authority switch.
        $release = 'server/.peanut/release-identity.json';
        $backup = $state['backups'][$release] ?? null;
        $document = $backup === null ? $root . '/' . $release : $state['binding']['workspace'] . '/' . $backup['backup'];
        if (hash_file('sha256', $document) !== $context['old_identity']['sha256']
            || self::canonicalJson($this->runtimeRead($document)) !== self::canonicalJson($old)) {
            throw new RuntimeException('SOURCE_RUNTIME_INTERNAL_ORIGIN_DOCUMENT_CHANGED');
        }
        // New files and baseline copies are not in the old program inventory. They still cannot hide unknown edits.
        foreach ($sourceFiles as $relative => $preimage) {
            $actual = $this->regularFileState(ScaffoldPathGuard::projectPath($root, $relative), $relative);
            if ($actual === array_intersect_key($preimage, $actual)) { continue; }
            if ($relative === '.peanut/application-manifest.json') { $this->assertAppliedState($root, $plan); continue; }
            $sourcePath = $relative;
            $baseline = '.peanut/scaffold-baseline/' . $plan['identity']['to']['version'] . '/files/';
            $baselineCopy = str_starts_with($sourcePath, $baseline);
            if ($baselineCopy) { $sourcePath = substr($sourcePath, strlen($baseline)); }
            $action = $actions[$sourcePath] ?? null;
            if ($action === null || !is_string($action['target_sha256'] ?? null)
                || $actual !== ['present' => true, 'sha256' => $action['target_sha256'], 'mode' => $baselineCopy ? 0644 : $action['mode']]) {
                throw new RuntimeException('SOURCE_RUNTIME_INTERNAL_RECOVERY_AFTERIMAGE_DRIFT: ' . $relative);
            }
        }
    }

    private function runtimeDerived(array $identity): array
    {
        $paths = ['server/.peanut/application-manifest.json', 'server/.peanut/release-versions.json',
            'server/.peanut/RELEASE_METADATA.json', 'server/resources/project-resources.json',
            'server/docker/scripts/scaffold-runtime/ReleaseDependencyIdentity.php', 'server/plugins.lock',
            'server/.peanut/development-runtime.json'];
        if (($identity['protocol'] ?? null) === 'peanut.server-release.v1') {
            $paths = [...$paths, 'server/.peanut/release-identity.json', 'server/.peanut/internal-candidate.json'];
        }
        foreach ($identity['plugin_projection']['plugins'] ?? [] as $plugin) {
            if (preg_match('/^[a-z][a-z0-9.-]*$/D', (string) ($plugin['key'] ?? '')) !== 1) {
                throw new RuntimeException('SOURCE_RUNTIME_PLUGIN_INVALID');
            }
            $paths[] = 'server/plugins/' . $plugin['key'] . '/plugin.json';
        }
        return array_values(array_unique($paths));
    }

    private function runtimeBackup(string $root, string $workspace, string $relative): array
    {
        $path = ScaffoldPathGuard::projectPath($root, $relative);
        $state = $this->regularFileState($path, $relative);
        if ($state['present']) {
            $backup = $workspace . '/recovery/' . $relative;
            $bytes = (string) file_get_contents($path);
            if (file_exists($backup) || is_link($backup)) {
                ScaffoldPathGuard::existingFileWithin($workspace, $backup, 'SOURCE_RUNTIME_BACKUP_UNSAFE');
                if (file_get_contents($backup) !== $bytes) { throw new RuntimeException('SOURCE_RUNTIME_BACKUP_COLLISION'); }
            } else { $this->writeFileAtomic($backup, $bytes, 0600); }
            $state['backup'] = 'recovery/' . $relative;
        }
        return $state;
    }

    private function runtimeSourceGuard(string $root, array $plan, string $operation): void
    {
        self::$runtimeEventBinding = [];
        if (!file_exists($root . '/server/.peanut/development-runtime.json')
            && !is_link($root . '/server/.peanut/development-runtime.json')
            && !file_exists($root . '/server/.peanut/internal-candidate.json')
            && !is_link($root . '/server/.peanut/internal-candidate.json')
            && !file_exists($root . '/server/runtime/upgrade/source-transition.json')
            && !is_link($root . '/server/runtime/upgrade/source-transition.json')) { return; }
        if (($plan['protocol'] ?? null) !== 'peanut.scaffold-upgrade-plan.v2') {
            throw new RuntimeException('SOURCE_RUNTIME_SOURCE_ADOPTION_REQUIRES_SEPARATE_MAINTENANCE');
        }
        require_once dirname(__DIR__, 2) . '/server/docker/scripts/update-plan.php';
        $pending = \PeanutServerUpdatePlan::sourcePending($root . '/server');
        if ($pending === null) { throw new RuntimeException('SOURCE_RUNTIME_PREPARE_REQUIRED'); }
        $state = $this->runtimeCheckpoint($pending['checkpoint'], $root);
        if ($state['transition_id'] !== $pending['transition_id'] || $state['binding']['candidate'] !== $plan['candidate']
            || ($pending['binding_sha256'] ?? null) !== hash('sha256', self::canonicalJson($state['binding']))
            || $state['binding']['plan_sha256'] !== $plan['plan_sha256']
            || $state['activation_started'] || in_array($state['phase'], ['recovered', 'opened'], true)
            || !in_array($state['phase'], ['prepared', 'source-verified', 'runtime-prepared', 'runtime-installed', 'head-published', 'admitted', 'recovering'], true)
            || ($operation === 'recover' && self::$recoveringRuntime !== $state['transition_id'])) {
            throw new RuntimeException('SOURCE_RUNTIME_SOURCE_OPERATION_REJECTED');
        }
        $this->runtimeHost($state, 'check');
        $this->runtimePackage($root, $state);
        if (isset($state['binding']['internal_preinstall'])) {
            if (self::canonicalJson($plan['identity']['internal_preinstall'] ?? []) !== self::canonicalJson($state['binding']['internal_preinstall'])) {
                throw new RuntimeException('SOURCE_RUNTIME_INTERNAL_PLAN_CHANGED');
            }
            $this->internalPreinstall = $state['binding']['internal_preinstall'];
        }
        self::$runtimeEventBinding = ['source_runtime_transition_id' => $state['transition_id'],
            'package_inventory_sha256' => $state['binding']['package_inventory_sha256']];
    }

    private function runtimePackage(string $root, array $state): ?array
    {
        $binding = $state['binding'];
        if ($binding['plan_path'] === null) { return null; }
        if (($binding['source_input_kind'] ?? null) === 'raw-internal-preinstall-scaffold') {
            $plan = $this->loadPlan($root, $binding['plan_path']);
            $input = $this->runtimeRawInput($root, $plan, $binding['package_root']);
            if ($input['inventory_sha256'] !== $binding['package_inventory_sha256'] || $plan['candidate'] !== $binding['candidate']
                || $plan['plan_sha256'] !== $binding['plan_sha256']) { throw new RuntimeException('SOURCE_RUNTIME_RAW_INPUT_REBIND_FAILED'); }
            return $plan;
        }
        $prepared = (new EditionUpgradePackage())->reauthenticate($root, $binding['package_root']);
        $plan = $this->loadPlan($root, $binding['plan_path']);
        if ($prepared['to_manifest'] !== $plan['manifest_paths']['to'] || $plan['candidate'] !== $binding['candidate']
            || $plan['plan_sha256'] !== $binding['plan_sha256']
            || hash_file('sha256', $binding['package_root'] . '/META-INF/files.sha256') !== $binding['package_inventory_sha256']) {
            throw new RuntimeException('SOURCE_RUNTIME_PACKAGE_REBIND_FAILED');
        }
        return $plan;
    }

    /** Existing native raw scaffold data, not a fabricated formal upgrade envelope. */
    private function runtimeRawInput(string $root, array $plan, string $package): array
    {
        if (($plan['identity']['kind'] ?? null) !== 'internal-candidate-preinstall-correction'
            || ($plan['identity']['internal_preinstall']['protocol'] ?? null) !== 'peanut.internal-preinstall-source.v1'
            || ($plan['identity']['internal_preinstall']['server_root'] ?? null) !== $root . '/server'
            || realpath($package) !== $package || !is_dir($package) || is_link($package)) { throw new RuntimeException('SOURCE_RUNTIME_INTERNAL_RAW_INPUT_REQUIRED'); }
        $from = ScaffoldManifest::load($plan['manifest_paths']['from']);
        $to = ScaffoldManifest::load($package . '/scaffold-manifest.json');
        if ($to->path !== $plan['manifest_paths']['to']
            || $this->releaseIdentity($from) !== array_diff_key($plan['identity']['from'], ['generation_source' => true])
            || $this->releaseIdentity($to) !== $plan['identity']['to']) { throw new RuntimeException('SOURCE_RUNTIME_RAW_MANIFEST_CHANGED'); }
        foreach ([$from, $to] as $manifest) {
            foreach ($manifest->files() as $file) {
                $path = $manifest->artifactPath($file);
                if (hash_file('sha256', $path) !== $file['template_sha256'] || (fileperms($path) & 0777) !== $file['mode']) { throw new RuntimeException('SOURCE_RUNTIME_RAW_ARTIFACT_CHANGED'); }
            }
        }
        $this->assertInternalSourceDelta($from, $to);
        $this->assertInternalCoreInputs($root, $to, $plan['identity']['target_parameters'], $plan['identity']['version_contract']);
        return ['to_manifest' => $to->path, 'inventory_sha256' => hash('sha256', self::canonicalJson($to->data)), 'kind' => 'raw-internal-preinstall-scaffold'];
    }

    private function runtimeVerifiedSource(string $root, array $state): array
    {
        $plan = $this->runtimePackage($root, $state);
        if ($plan === null) {
            $manifest = hash_file('sha256', $root . '/.peanut/application-manifest.json');
            if ($manifest !== $state['binding']['deployment']['current_source']['sha256']) {
                throw new RuntimeException('SOURCE_RUNTIME_REFREEZE_MANIFEST_CHANGED');
            }
            return $state['binding']['inherited_source_proof'];
        }
        $this->assertAppliedState($root, $plan);
        $events = $this->ledger($root)->entries($plan['candidate']);
        $applied = null; $verified = null;
        foreach ($events as $event) {
            if (in_array($event['status'] ?? null, ['failed', 'recovered'], true)) { $applied = $verified = null; }
            if (($event['plan_sha256'] ?? null) !== $plan['plan_sha256']
                || ($event['source_runtime_transition_id'] ?? null) !== $state['transition_id']
                || ($event['package_inventory_sha256'] ?? null) !== $state['binding']['package_inventory_sha256']
                || ($event['identity_sha256'] ?? null) !== hash('sha256', self::canonicalJson($plan['identity']))) { continue; }
            if (($event['operation'] ?? null) === 'apply' && ($event['status'] ?? null) === 'applied') { $applied = $event; $verified = null; }
            if ($applied !== null && ($event['operation'] ?? null) === 'verify' && ($event['status'] ?? null) === 'verified') { $verified = $event; }
        }
        if ($applied === null || $verified === null || $this->candidateState($this->ledger($root), $plan['candidate']) !== 'verified'
            || $applied['post_sha256'] !== $verified['post_sha256']) {
            throw new RuntimeException('SOURCE_RUNTIME_VERIFIED_SOURCE_REQUIRED');
        }
        return ['candidate' => $plan['candidate'], 'plan_sha256' => $plan['plan_sha256'],
            'package_inventory_sha256' => $state['binding']['package_inventory_sha256'],
            'apply_event_sha256' => hash('sha256', self::canonicalJson($applied)),
            'verify_event_sha256' => hash('sha256', self::canonicalJson($verified)),
            'managed_sha256' => $verified['post_sha256'], 'app_owned_sha256' => $plan['identity']['app_owned_pre_sha256']];
    }

    /** Explicit installed-APP transition; ordinary source verify never calls this method. */
    public function runtimeCommand(string $command, string $root, array $options, string $toolRoot): array
    {
        require_once dirname(__DIR__, 2) . '/server/docker/scripts/update-plan.php';
        return self::sourceProductLock($root, function () use ($command, $root, $options, $toolRoot): array {
            $result = $this->locked($root, function () use ($command, $root, $options, $toolRoot): array {
                \PeanutServerUpdatePlan::sourceOwner($root . '/server');
                if (in_array($command, ['runtime-prepare', 'runtime-refreeze'], true)) {
                    $state = $this->runtimePrepare($root, $options, $toolRoot, $command === 'runtime-refreeze');
                    if ($state['phase'] === 'tools-prepared') { return $state; }
                    if ($command === 'runtime-refreeze') {
                        if ($state['activation_started']) { throw new RuntimeException('SOURCE_RUNTIME_RESUME_OPEN_REQUIRED'); }
                        $this->runtimeHost($state, 'check');
                        return $this->runtimeInstall($root, $state['binding']['workspace'] . '/source-runtime.json', $state);
                    }
                    return $state;
                }
                $path = $options['transition'];
                $state = $this->runtimeCheckpoint($path, $root);
                if ($state['phase'] === 'tools-prepared') {
                    $this->runtimeAssertHead($root, $state, false);
                    if ($command === 'runtime-recover') { return $state; }
                    throw new RuntimeException('SOURCE_RUNTIME_PREPARE_REQUIRED');
                }
                if (($state['recovery_restoration_intent'] ?? false) && $state['phase'] !== 'recovered'
                    && !($state['recovery_admitted'] ?? false)) {
                    $deployment = $root . '/server/private/installation/deployment.json';
                    $actual = file_exists($deployment) || is_link($deployment)
                        ? hash_file('sha256', ScaffoldPathGuard::existingFileWithin($root, $deployment, 'SOURCE_RUNTIME_DEPLOYMENT_INVALID')) : null;
                    if ($actual === $state['binding']['deployment']['head_sha256']) {
                        if ($actual === null) { unset($state['published_head_sha256']); }
                        else { $state['published_head_sha256'] = $actual; }
                        $state['phase'] = 'recovering';
                        $state = $this->runtimeSave($path, $state);
                    }
                }
                foreach (['head_intent'] as $intent) {
                    if (!isset($state[$intent])) { continue; }
                    $bytes = json_encode($state[$intent], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . "\n";
                    $deployment = $root . '/server/private/installation/deployment.json';
                    if (is_file($deployment) && hash_file('sha256', $deployment) === hash('sha256', $bytes)) {
                        $state['published_head_sha256'] = hash('sha256', $bytes);
                        $state = $this->runtimeSave($path, $state);
                        break;
                    }
                }
                $pending = \PeanutServerUpdatePlan::sourcePending($root . '/server');
                if (($pending['transition_id'] ?? null) !== $state['transition_id']) { throw new RuntimeException('SOURCE_RUNTIME_PENDING_CHANGED'); }
                if ($command === 'runtime-open') {
                    if (isset($state['binding']['deployment']['partial_installation'])) {
                        throw new RuntimeException('SOURCE_RUNTIME_PARTIAL_INSTALLATION_COMPLETION_REQUIRED');
                    }
                    if (!in_array($state['phase'], ['admitted', 'opening', 'opened'], true)
                        && !($state['phase'] === 'recovered' && ($state['recovery_admitted'] ?? false))) { throw new RuntimeException('SOURCE_RUNTIME_NOT_ADMITTED'); }
                    $this->runtimeAssertHead($root, $state, isset($state['published_head_sha256']));
                    if ($state['recovery_admitted'] ?? false) {
                        if ($state['source_recovery'] !== null) { $this->verifyRecovery($root, $state['binding']['plan_path']); }
                    } else { $this->runtimeVerifiedSource($root, $state); }
                    \app\common\value\installation\ApplicationSourceIdentity::loadRuntime($root . '/server');
                    if ($state['phase'] === 'opened') { return $state; }
                    $state['activation_started'] = true;
                    $state['phase'] = 'opening';
                    $state = $this->runtimeSave($path, $state);
                    $this->runtimePointer($state, 'opening');
                    return $state;
                }
                if ($state['activation_started']) { throw new RuntimeException('SOURCE_RUNTIME_ACTIVATION_RECOVERY_FORBIDDEN'); }
                $this->runtimeHost($state, 'check');
                if ($command === 'runtime-recover') { return $this->runtimeRecover($root, $path, $state); }
                if ($command !== 'runtime-transition') { throw new RuntimeException('SOURCE_RUNTIME_COMMAND_INVALID'); }
                return $this->runtimeInstall($root, $path, $state);
            });
            if ($command === 'runtime-open' && $result['phase'] !== 'opened') {
                // Release project/instance locks before the actual root entrypoint takes its native lock.
                try { $this->runtimeHost($result, 'open'); }
                catch (Throwable $error) { $this->runtimeHost($result, 'stop'); throw $error; }
                $result['phase'] = 'opened';
                $result = $this->runtimeSave($options['transition'], $result);
                $this->runtimePointer($result, 'opened');
            }
            return ['status' => $result['phase'], 'transition_id' => $result['transition_id'],
                'checkpoint' => $result['binding']['workspace'] . '/source-runtime.json', 'source_only' => false,
                'write_gate' => $result['phase'] === 'tools-prepared' ? 'unchanged' : ($result['phase'] === 'opened' ? 'open' : 'closed'),
                'requires_refreeze' => $result['phase'] === 'recovered' && !($result['recovery_admitted'] ?? false)];
        });
    }

    private function runtimePrepare(string $root, array $options, string $toolRoot, bool $refreeze): array
    {
        if ($refreeze) { $this->runtimeRequireNativeProtocol($root); }
        $workspace = $options['workspace'];
        if (!str_starts_with($workspace, '/') || str_contains($workspace, '/../')
            || $workspace === $root || str_starts_with($workspace . '/', $root . '/')
            || str_starts_with($root . '/', $workspace . '/')) { throw new RuntimeException('SOURCE_RUNTIME_WORKSPACE_INVALID'); }
        if (realpath(dirname($workspace)) !== dirname($workspace) || is_link($workspace)) {
            throw new RuntimeException('SOURCE_RUNTIME_WORKSPACE_PARENT_UNSAFE');
        }
        ScaffoldPathGuard::ensureDirectory($workspace);
        if (realpath($workspace) !== $workspace || stat($workspace)['dev'] !== stat($root)['dev'] || !chmod($workspace, 0700)) {
            throw new RuntimeException('SOURCE_RUNTIME_WORKSPACE_UNSAFE');
        }
        $path = $workspace . '/source-runtime.json';
        $pending = \PeanutServerUpdatePlan::sourcePending($root . '/server');
        if ($pending !== null && !in_array($pending['phase'] ?? null, ['opened', 'recovered'], true)
            && ($pending['checkpoint'] ?? null) !== $path) { throw new RuntimeException('SOURCE_RUNTIME_OTHER_TRANSITION_PENDING'); }
        if (!file_exists($path) && !is_link($path)) {
            foreach (new \DirectoryIterator($workspace) as $item) {
                if (!$item->isDot() && !in_array($item->getFilename(), ['trusted', 'recovery', 'tools', 'tool-inputs.json'], true)) {
                    throw new RuntimeException('SOURCE_RUNTIME_WORKSPACE_NOT_EMPTY');
                }
            }
        }
        if (file_exists($path) || is_link($path)) {
            $state = $this->runtimeCheckpoint($path, $root);
            if (($options['expect-head'] === 'none' ? null : $options['expect-head']) !== $state['binding']['deployment']['head_sha256']
                || (int) $options['expect-generation'] !== $state['binding']['deployment']['source_generation']
                || ($options['plan'] ?? null) !== $state['binding']['plan_path']
                || ($options['package'] ?? null) !== $state['binding']['package_root']) {
                throw new RuntimeException('SOURCE_RUNTIME_PREPARE_RETRY_CHANGED');
            }
        } else {
            $plan = null; $rawInput = null;
            if (!$refreeze) {
                foreach (['package', 'plan'] as $input) {
                    if (realpath($options[$input]) !== $options[$input]) { throw new RuntimeException('SOURCE_RUNTIME_NONCANONICAL_INPUT'); }
                }
                $plan = $this->loadPlan($root, $options['plan']);
                $prepared = ($plan['identity']['kind'] ?? null) === 'internal-candidate-preinstall-correction'
                    ? ($rawInput = $this->runtimeRawInput($root, $plan, $options['package']))
                    : (new EditionUpgradePackage())->reauthenticate($root, $options['package']);
                if ($plan['status'] !== 'ready' || $prepared['to_manifest'] !== $plan['manifest_paths']['to']) {
                    throw new RuntimeException('SOURCE_RUNTIME_PLAN_BLOCKED');
                }
            }
            $identityPath = $root . '/server/.peanut/development-runtime.json';
            $internal = null;
            if ($rawInput !== null) {
                $from = ScaffoldManifest::load($plan['manifest_paths']['from']); $to = ScaffoldManifest::load($plan['manifest_paths']['to']);
                $internal = \PeanutServerUpdatePlan::internalPreinstallContext($root . '/server', $from->release(), $to->release());
                if ($internal === null || self::canonicalJson($internal) !== self::canonicalJson($plan['identity']['internal_preinstall'])) { throw new RuntimeException('SOURCE_RUNTIME_INTERNAL_FRONTIER_CHANGED'); }
                $old = $internal['old_identity']['document']; $oldSha = $internal['old_identity']['sha256'];
                $deployment = \PeanutServerUpdatePlan::internalPreinstallDeployment($root . '/server', $internal);
            } else {
                $old = $this->runtimeRead($identityPath); $oldSha = hash_file('sha256', $identityPath);
                $deployment = \PeanutServerUpdatePlan::applicationDeployment($root . '/server', $old, $oldSha);
            }
            if (($options['expect-head'] === 'none' ? null : $options['expect-head']) !== $deployment['head_sha256']
                || (string) $deployment['source_generation'] !== $options['expect-generation']) {
                throw new RuntimeException('SOURCE_RUNTIME_EXPECTED_HEAD_CHANGED');
            }
            if ($refreeze && hash_file('sha256', $root . '/.peanut/application-manifest.json') !== $deployment['current_source']['sha256']) {
                throw new RuntimeException('SOURCE_RUNTIME_REFREEZE_REQUIRES_CURRENT_SOURCE');
            }
            $files = ['scripts/product-upgrade-host', 'scripts/package-release-files.py', 'server/resources/schemas/plugin.schema.json',
                'server/app/common/value/installation/ApplicationSourceIdentity.php',
                'server/app/platform/infrastructure/plugin/PluginLockResolver.php'];
            foreach (['update-plan.php', 'update-database.php', 'update-preparation.py', 'update-recovery.py',
                'prepare-vendor.sh', 'prepare-vendor.php', 'vendor-state.py', 'configure-collaboration.py'] as $name) {
                $files[] = 'server/docker/scripts/' . $name;
            }
            foreach (get_included_files() as $file) {
                if (str_starts_with($file, $toolRoot . '/') && !str_contains($file, '/vendor/')) { $files[] = substr($file, strlen($toolRoot) + 1); }
            }
            $tools = []; $toolModes = [];
            foreach (array_unique($files) as $relative) {
                $source = ScaffoldPathGuard::existingFileWithin($toolRoot, $toolRoot . '/' . $relative, 'SOURCE_RUNTIME_TOOL_INVALID');
                $tools[$relative] = hash_file('sha256', $source);
                $toolModes[$relative] = fileperms($source) & 0777;
                $target = $workspace . '/trusted/' . $relative;
                if (file_exists($target) && file_get_contents($target) !== file_get_contents($source)) { throw new RuntimeException('SOURCE_RUNTIME_TOOL_COLLISION'); }
                $this->writeFileAtomic($target, (string) file_get_contents($source), $toolModes[$relative]);
            }
            ksort($tools);
            $binding = ['instance_root' => $root, 'workspace' => $workspace, 'tool_root' => $toolRoot, 'tools' => $tools, 'tool_modes' => $toolModes,
                'tool_autoload' => realpath($toolRoot . '/server/vendor/autoload.php'),
                'candidate' => $plan['candidate'] ?? null, 'plan_path' => $options['plan'] ?? null,
                'plan_sha256' => $plan['plan_sha256'] ?? null, 'package_root' => $options['package'] ?? null,
                'package_inventory_sha256' => $refreeze ? null : ($rawInput['inventory_sha256'] ?? hash_file('sha256', $options['package'] . '/META-INF/files.sha256')),
                'source_input_kind' => $rawInput['kind'] ?? 'edition-upgrade', 'deployment' => $deployment,
                ...($internal !== null ? ['internal_preinstall' => $internal, 'old_identity' => $internal['old_identity']]
                    : ['old_runtime' => $old, 'old_runtime_sha256' => $oldSha]),
                'inherited_source_proof' => $deployment['source_proof'] ?? (isset($deployment['partial_installation'])
                    ? ['partial_installation_sha256' => hash('sha256', self::canonicalJson($deployment['partial_installation']))]
                    : ['installation_baseline_sha256' => $deployment['baseline_sha256']])];
            $binding = $this->runtimeToolBundle($root, $workspace, $binding);
            $state = ['schema_version' => 1, 'protocol' => 'peanut.source-runtime-transition.v1',
                'transition_id' => hash('sha256', self::canonicalJson($binding)), 'binding' => $binding,
                'phase' => 'tools-prepared', 'activation_started' => false, 'backups' => [], 'intents' => []];
            $state = $this->runtimeSave($path, $state);
        }
        if ($state['phase'] === 'tools-prepared') {
            if ($toolRoot !== $workspace . '/trusted') { return $state; }
            $state['phase'] = 'preparing'; $state = $this->runtimeSave($path, $state);
        }
        if ($state['phase'] !== 'preparing') {
            $this->runtimeAssertHead($root, $state, isset($state['published_head_sha256']));
            $this->runtimePointer($state, $state['phase']);
            return $state;
        }
        $state['vendor_preflight_sha256'] = $this->runtimeHost($state, 'vendor-preflight')['sha256'];
        $state = $this->runtimeSave($path, $state);
        $this->runtimePointer($state, 'preparing');
        $observation = $this->runtimeHost($state, 'stop');
        $state['quiescence'] = $observation['quiescence'];
        if ($state['quiescence']['images']['php'] !== $state['binding']['tool_image']
            || $state['quiescence']['project'] !== $state['binding']['tool_project']) { throw new RuntimeException('SOURCE_RUNTIME_TOOL_IMAGE_CHANGED'); }
        $state = $this->runtimeSave($path, $state);
        $this->runtimeHost($state, 'check');
        $plan = $this->runtimePackage($root, $state);
        $recovery = null;
        if ($plan !== null) {
            $candidateState = $this->candidateState($this->ledger($root), $plan['candidate']);
            if (in_array($candidateState, ['applied', 'verified'], true)) {
                $this->assertAppliedState($root, $plan);
                $recovery = '.peanut/upgrades/backups/' . $plan['candidate'] . '/recovery.json';
            } else {
                if ($candidateState !== null) { throw new RuntimeException('SOURCE_RUNTIME_PRIOR_FAILURE_REQUIRES_RECOVERY'); }
                $this->assertPlanFresh($root, $plan);
                $this->assertPlanRebound($root, $plan);
                $recovery = $this->createRecovery($root, $plan);
                if (!isset($state['binding']['internal_preinstall'])) {
                    \app\common\value\installation\ApplicationSourceIdentity::loadRuntime($root . '/server');
                }
            }
        }
        $state['source_recovery'] = $recovery;
        $state['source_recovery_sha256'] = $recovery === null ? null : hash_file('sha256', ScaffoldPathGuard::existingFileWithin($root, $root . '/' . $recovery, 'SOURCE_RUNTIME_PREIMAGE_MISSING'));
        $preimages = $recovery === null ? [] : $this->runtimeRead($root . '/' . $recovery)['files'];
        $old = $this->runtimeOldIdentity($state);
        if (isset($state['binding']['internal_preinstall'])) {
            $state['old_program_matches'] = true;
            $this->runtimeOldProgram($root, $state);
        } else {
        if (($old['protocol'] ?? null) !== 'peanut.development-server-runtime.v1' || ($old['environment'] ?? null) !== 'development'
            || ($old['schema_version'] ?? null) !== 1 || !is_array($old['files'] ?? null) || $old['files'] === []
            || hash('sha256', json_encode($old['files'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)) !== ($old['files_sha256'] ?? null)
            || file_exists($root . '/server/.peanut/release-identity.json') || is_link($root . '/server/.peanut/release-identity.json')) {
            throw new RuntimeException('SOURCE_RUNTIME_OLD_IDENTITY_INVALID');
        }
        $state['old_program_matches'] = true;
        foreach ($old['files'] as $row) {
            $actual = $this->regularFileState(ScaffoldPathGuard::projectPath($root, $row['path']), $row['path']);
            if ($actual['present'] && $actual['sha256'] === $row['sha256'] && $actual['mode'] === $row['mode']) { continue; }
            $preimage = $preimages[$row['path']] ?? null;
            if ($preimage !== null && $preimage['present'] && $preimage['sha256'] === $row['sha256'] && $preimage['mode'] === $row['mode']) {
                $backup = dirname($root . '/' . $recovery) . '/' . $preimage['backup'];
                if (hash_file('sha256', ScaffoldPathGuard::existingFileWithin(dirname($root . '/' . $recovery), $backup, 'SOURCE_RUNTIME_PREIMAGE_INVALID')) !== $row['sha256']) {
                    throw new RuntimeException('SOURCE_RUNTIME_PREIMAGE_CHANGED');
                }
            } elseif ($plan === null) { $state['old_program_matches'] = false; }
            else { throw new RuntimeException('SOURCE_RUNTIME_OLD_PROGRAM_PREIMAGE_MISSING'); }
        }
        }
        if ($plan !== null && $recovery !== null) {
            $before = $preimages['.peanut/application-manifest.json'] ?? null;
            if (!$before || 'sha256:' . $before['sha256'] !== $plan['identity']['application_manifest_sha256']
                || $before['sha256'] !== ($old['application_manifest_sha256'] ?? $old['application']['manifest_sha256'] ?? null)) {
                throw new RuntimeException('SOURCE_RUNTIME_OLD_MANIFEST_PREIMAGE_MISMATCH');
            }
        }
        $paths = [...$this->runtimeDerived($old), 'server/private/installation/deployment.json',
            'server/runtime/upgrade/verified-deployment/current.json', 'server/runtime/upgrade/verified-deployment/receipt.json'];
        foreach ($paths as $relative) { $state['backups'][$relative] = $this->runtimeBackup($root, $workspace, $relative); }
        $state['vendor_original_sha256'] = $this->runtimeHost($state, 'vendor-record')['sha256'];
        $state['binding'] = $this->runtimeFreezePhp($workspace, $state['binding']);
        $state['phase'] = 'prepared';
        $state = $this->runtimeSave($path, $state);
        $this->runtimePointer($state, 'prepared');
        return $state;
    }

    private function runtimeAssertHead(string $root, array $state, bool $published): void
    {
        $path = $root . '/server/private/installation/deployment.json';
        $actual = file_exists($path) || is_link($path)
            ? hash_file('sha256', ScaffoldPathGuard::existingFileWithin($root, $path, 'SOURCE_RUNTIME_DEPLOYMENT_INVALID')) : null;
        $expected = $published ? $state['published_head_sha256'] : $state['binding']['deployment']['head_sha256'];
        if ($actual !== $expected) { throw new RuntimeException('SOURCE_RUNTIME_DEPLOYMENT_CAS_FAILED'); }
    }

    private function runtimeWriteIntent(string $root, string $path, array $state, string $relative, ?array $target): array
    {
        $actualPath = ScaffoldPathGuard::projectPath($root, $relative);
        $before = $this->regularFileState($actualPath, $relative);
        if (!isset($state['intents'][$relative])) {
            $old = $state['backups'][$relative] ?? ['present' => false, 'sha256' => null, 'mode' => null];
            if ($before !== array_intersect_key($old, $before)) { throw new RuntimeException('SOURCE_RUNTIME_PROJECTION_CHANGED: ' . $relative); }
            $state['intents'][$relative] = ['before' => $before, 'after' => $target, 'done' => false];
            $state = $this->runtimeSave($path, $state);
        }
        $intent = $state['intents'][$relative];
        if ($intent['after'] !== $target) { throw new RuntimeException('SOURCE_RUNTIME_INTENT_CHANGED'); }
        $after = $target === null ? ['present' => false, 'sha256' => null, 'mode' => null]
            : ['present' => true, 'sha256' => $target['sha256'], 'mode' => $target['mode']];
        if ($before !== $after) {
            if ($before !== $intent['before']) { throw new RuntimeException('SOURCE_RUNTIME_INTENT_DRIFT'); }
            if ($target === null) {
                if ($before['present'] && !unlink($actualPath)) { throw new RuntimeException('SOURCE_RUNTIME_REMOVE_FAILED'); }
                $this->runtimeSyncDirectory(dirname($actualPath));
            } else {
                $source = ScaffoldPathGuard::existingFileWithin($state['binding']['workspace'], $state['binding']['workspace'] . '/prepared/' . $relative, 'SOURCE_RUNTIME_STAGED_FILE_INVALID');
                if (hash_file('sha256', $source) !== $target['sha256'] || (fileperms($source) & 0777) !== $target['mode']) { throw new RuntimeException('SOURCE_RUNTIME_STAGED_FILE_CHANGED'); }
                $this->writeFileAtomic($actualPath, (string) file_get_contents($source), $target['mode']);
            }
        }
        if ($this->regularFileState($actualPath, $relative) !== $after) { throw new RuntimeException('SOURCE_RUNTIME_INSTALL_VERIFY_FAILED'); }
        $state['intents'][$relative]['done'] = true;
        return $this->runtimeSave($path, $state);
    }

    private function runtimeInstall(string $root, string $path, array $state): array
    {
        if ($state['phase'] === 'recovered') { throw new RuntimeException('SOURCE_RUNTIME_RECOVERED_CANDIDATE'); }
        $this->runtimeAssertHead($root, $state, isset($state['published_head_sha256']));
        $state['source_proof'] = $this->runtimeVerifiedSource($root, $state);
        $this->runtimeRequireNativeProtocol($root);
        if ($state['source_recovery'] !== null && hash_file('sha256', $root . '/' . $state['source_recovery']) !== $state['source_recovery_sha256']) {
            throw new RuntimeException('SOURCE_RUNTIME_SOURCE_RECOVERY_CHANGED');
        }
        if (!isset($state['vendor_target_sha256'])) {
            $state['vendor_target_inputs'] = $this->runtimeDependencyInputs($root,
                dirname($path) . '/dependencies/prepared/server', dirname($path) . '/trusted');
            $state = $this->runtimeSave($path, $state);
            $target = $this->runtimeHost($state, 'vendor-stage');
            $state['vendor_target_sha256'] = $target['sha256'];
            $state['vendor_reuse'] = $target['reuse'];
            $state = $this->runtimeSave($path, $state);
        }
        if (!$state['vendor_reuse']) {
            $state['vendor_intent'] = true;
            $state = $this->runtimeSave($path, $state);
            $this->runtimeHost($state, 'vendor-switch');
        } else { $this->runtimeHost($state, 'vendor-check'); }
        if (!isset($state['prepared_identity_sha256'])) {
            $state['phase'] = 'source-verified';
            $state = $this->runtimeSave($path, $state);
            $this->runtimeHost($state, 'project');
            $identity = $this->runtimeRead(dirname($path) . '/prepared/server/.peanut/development-runtime.json');
            $state['prepared_identity_sha256'] = hash_file('sha256', dirname($path) . '/prepared/server/.peanut/development-runtime.json');
            $state['prepared_identity'] = $identity;
            $state['phase'] = 'runtime-prepared';
            $state = $this->runtimeSave($path, $state);
        }
        $identity = $state['prepared_identity'];
        if (hash_file('sha256', dirname($path) . '/prepared/server/.peanut/development-runtime.json') !== $state['prepared_identity_sha256']
            || $identity['application_manifest_sha256'] !== hash_file('sha256', $root . '/.peanut/application-manifest.json')) {
            throw new RuntimeException('SOURCE_RUNTIME_PROJECTION_REBIND_FAILED');
        }
        $applicationKeys = array_flip(['slug', 'edition', 'package_identity']);
        if (array_intersect_key($identity['application'], $applicationKeys)
            !== array_intersect_key($this->runtimeOldIdentity($state)['application'], $applicationKeys)) {
            throw new RuntimeException('SOURCE_RUNTIME_APPLICATION_IDENTITY_CHANGED');
        }
        foreach ($identity['source_projection'] as $origin => $sha) {
            if (hash_file('sha256', ScaffoldPathGuard::existingFileWithin($root, ScaffoldPathGuard::projectPath($root, $origin), 'SOURCE_RUNTIME_SOURCE_PROJECTION_INVALID')) !== $sha) {
                throw new RuntimeException('SOURCE_RUNTIME_ROOT_SOURCE_CHANGED');
            }
        }
        $derived = array_values(array_unique([...$this->runtimeDerived($this->runtimeOldIdentity($state)), ...$this->runtimeDerived($identity)]));
        $rows = array_column($identity['files'], null, 'path');
        foreach ($rows as $relative => $row) {
            if (in_array($relative, $derived, true)) { continue; }
            if ($this->regularFileState(ScaffoldPathGuard::projectPath($root, $relative), $relative)
                !== ['present' => true, 'sha256' => $row['sha256'], 'mode' => $row['mode']]) {
                throw new RuntimeException('SOURCE_RUNTIME_SOURCE_PROGRAM_CHANGED: ' . $relative);
            }
        }
        $old = $state['binding']['deployment'];
        if ($old['source_generation'] >= PHP_INT_MAX || $old['runtime_revision'] >= PHP_INT_MAX) {
            throw new RuntimeException('SOURCE_RUNTIME_GENERATION_EXHAUSTED');
        }
        $new = ['schema_version' => 1, 'protocol' => \PeanutServerUpdatePlan::APPLICATION_DEPLOYMENT,
            'application' => array_intersect_key($identity['application'], array_flip(['slug', 'edition', 'package_identity'])),
            ...(isset($old['partial_installation']) ? ['partial_installation' => $old['partial_installation']]
                : ['installed_receipt_sha256' => $old['installed_receipt_sha256'], 'baseline_sha256' => $old['baseline_sha256']]),
            'initial_source' => $old['initial_source'],
            'current_source' => ['kind' => 'application-manifest', 'sha256' => $identity['application_manifest_sha256']],
            'current_runtime' => ['kind' => 'development-runtime', 'sha256' => $state['prepared_identity_sha256']],
            'source_generation' => $old['source_generation'] + (int) ($old['current_source']['sha256'] !== $identity['application_manifest_sha256']),
            'runtime_revision' => $old['runtime_revision'] + 1, 'previous_head_sha256' => $old['head_sha256'],
            'transition_id' => $state['transition_id'], 'source_proof' => $state['source_proof']];
        if (isset($old['original_identity'])) {
            $new['original_identity'] = $old['original_identity'];
            $new['original_authority'] = $state['binding']['internal_preinstall']['originals'];
        }
        if (isset($state['head_intent']) && $state['head_intent'] !== $new) { throw new RuntimeException('SOURCE_RUNTIME_HEAD_INTENT_CHANGED'); }
        $state['head_intent'] = $new;
        $state = $this->runtimeSave($path, $state);
        foreach ($derived as $relative) {
            if ($relative === 'server/.peanut/development-runtime.json') { continue; }
            $state = $this->runtimeWriteIntent($root, $path, $state, $relative, $rows[$relative] ?? null);
        }
        $this->runtimeHost($state, 'check');
        // The complete next head and identity are durable before either original authority is retired.
        // Identity is the final program write; strict mixed-authority rejection stays in force.
        $state = $this->runtimeWriteIntent($root, $path, $state, 'server/.peanut/development-runtime.json',
            ['path' => 'server/.peanut/development-runtime.json', 'sha256' => $state['prepared_identity_sha256'], 'mode' => 0644]);
        \app\common\value\installation\ApplicationSourceIdentity::loadRuntime($root . '/server');
        $state['phase'] = 'runtime-installed';
        $state = $this->runtimeSave($path, $state);
        $state['published_head_sha256'] = \PeanutServerUpdatePlan::publishApplicationDeployment($root . '/server', $old['head_sha256'], $new);
        $state['phase'] = 'head-published';
        $state = $this->runtimeSave($path, $state);
        $this->runtimePointer($state, 'head-published');
        $this->runtimeAdmit($state);
        $state['phase'] = 'admitted';
        $state = $this->runtimeSave($path, $state);
        $this->runtimePointer($state, 'admitted');
        return $state;
    }

    private function runtimeRecover(string $root, string $path, array $state): array
    {
        if (isset($state['installation_completion'])) { throw new RuntimeException('SOURCE_RUNTIME_COMPLETED_INSTALLATION_RECOVERY_FORBIDDEN'); }
        $this->runtimeAssertHead($root, $state, isset($state['published_head_sha256']));
        if (isset($state['vendor_original_sha256'])) { $this->runtimeHost($state, 'vendor-check'); }
        if ($state['phase'] === 'preparing') {
            $plan = $this->runtimePackage($root, $state);
            if ($plan !== null && $this->candidateState($this->ledger($root), $plan['candidate']) !== null) {
                throw new RuntimeException('SOURCE_RUNTIME_COMPLETE_PREIMAGE_PREPARATION_REQUIRED');
            }
            // No source writer can enter before prepared. Cancelling this phase restores no file or old grant.
            $state['source_recovery'] = null;
            $state['old_program_matches'] = false;
            try { \app\common\value\installation\ApplicationSourceIdentity::loadRuntime($root . '/server'); $state['old_program_matches'] = true; }
            catch (Throwable) { /* Leave the original failure closed; an explicit refreeze is required. */ }
            $state['phase'] = 'recovered';
            $state = $this->runtimeSave($path, $state);
            $this->runtimePointer($state, 'recovered');
        }
        if ($state['phase'] === 'recovered') {
            if (!isset($state['binding']['internal_preinstall']) && $state['old_program_matches'] && !($state['recovery_admitted'] ?? false)) {
                $this->runtimeAdmit($state);
                $state['recovery_admitted'] = true;
                $state = $this->runtimeSave($path, $state);
                $this->runtimePointer($state, 'recovered');
            }
            return $state;
        }
        $derived = array_values(array_unique([...$this->runtimeDerived($this->runtimeOldIdentity($state)),
            ...(isset($state['prepared_identity']) ? $this->runtimeDerived($state['prepared_identity']) : [])]));
        // Validate every recovery input before any source write or vendor rename; writes still recheck their own input.
        $checkPreimages = function (string $base, array $files, string $prefix) use ($root): void {
            foreach ($files as $relative => $old) {
                if (!is_string($relative) || !is_array($old) || !is_bool($old['present'] ?? null)) {
                    throw new RuntimeException('SOURCE_RUNTIME_RECOVERY_PREIMAGE_INVALID');
                }
                $target = ScaffoldPathGuard::projectPath($root, $relative);
                $this->regularFileState($target, $relative);
                for ($parent = dirname($target); $parent !== $root; $parent = dirname($parent)) {
                    if ((file_exists($parent) || is_link($parent)) && (!is_dir($parent) || realpath($parent) !== $parent)) {
                        throw new RuntimeException('SOURCE_RUNTIME_RECOVERY_PARENT_INVALID');
                    }
                }
                if (!$old['present']) {
                    if (($old['sha256'] ?? null) !== null || ($old['mode'] ?? null) !== null || ($old['backup'] ?? null) !== null) {
                        throw new RuntimeException('SOURCE_RUNTIME_RECOVERY_ABSENCE_INVALID');
                    }
                    $absent = ScaffoldPathGuard::projectPath($base, $prefix . $relative);
                    if (file_exists($absent) || is_link($absent)) { throw new RuntimeException('SOURCE_RUNTIME_RECOVERY_ABSENCE_INVALID'); }
                    continue;
                }
                if (($old['backup'] ?? null) !== $prefix . $relative || !is_int($old['mode'] ?? null)
                    || $old['mode'] < 0 || $old['mode'] > 0777 || preg_match('/^[a-f0-9]{64}$/D', (string) ($old['sha256'] ?? '')) !== 1) {
                    throw new RuntimeException('SOURCE_RUNTIME_RECOVERY_PREIMAGE_INVALID');
                }
                $backup = ScaffoldPathGuard::projectPath($base, $old['backup']);
                ScaffoldPathGuard::existingFileWithin($base, $backup, 'SOURCE_RUNTIME_RECOVERY_BACKUP_INVALID');
                if (realpath($backup) !== $backup || hash_file('sha256', $backup) !== $old['sha256'] || (fileperms($backup) & 0777) !== 0600) {
                    throw new RuntimeException('SOURCE_RUNTIME_RECOVERY_BACKUP_DRIFT');
                }
            }
        };
        $plan = $this->runtimePackage($root, $state);
        if ($state['source_recovery'] !== null) {
            $expected = '.peanut/upgrades/backups/' . ($plan['candidate'] ?? '') . '/recovery.json';
            $manifest = ScaffoldPathGuard::projectPath($root, $state['source_recovery']);
            $recovery = $this->runtimeRead($manifest);
            if ($plan === null || $state['source_recovery'] !== $expected || hash_file('sha256', $manifest) !== $state['source_recovery_sha256']
                || (fileperms($manifest) & 0777) !== 0600 || ($recovery['schema_version'] ?? null) !== 2
                || ($recovery['protocol'] ?? null) !== 'peanut.scaffold-recovery.v2' || ($recovery['candidate'] ?? null) !== $plan['candidate']
                || !is_array($recovery['files'] ?? null)
                || ($recovery['pre_tree_sha256'] ?? null) !== 'sha256:' . hash('sha256', self::canonicalJson($recovery['files']))) {
                throw new RuntimeException('SOURCE_RUNTIME_RECOVERY_DRIFT');
            }
            $checkPreimages(dirname($manifest), $recovery['files'], 'files/');
        } elseif ($plan !== null) { throw new RuntimeException('SOURCE_RUNTIME_RECOVERY_PREIMAGE_MISSING'); }
        if (!is_array($state['backups'] ?? null)
            || (isset($state['published_head_sha256']) && !isset($state['backups']['server/private/installation/deployment.json']))) {
            throw new RuntimeException('SOURCE_RUNTIME_RECOVERY_PREIMAGE_MISSING');
        }
        $checkPreimages(dirname($path), $state['backups'], 'recovery/');
        foreach ($derived as $relative) {
            $actual = $this->regularFileState(ScaffoldPathGuard::projectPath($root, $relative), $relative);
            $backup = $state['backups'][$relative] ?? ['present' => false, 'sha256' => null, 'mode' => null];
            $before = array_intersect_key($backup, $actual);
            $intent = $state['intents'][$relative] ?? null;
            $after = $intent === null || $intent['after'] === null ? ['present' => false, 'sha256' => null, 'mode' => null]
                : ['present' => true, 'sha256' => $intent['after']['sha256'], 'mode' => $intent['after']['mode']];
            if ($actual !== $before && ($intent === null || ($actual !== $after && $actual !== $intent['before']))) {
                throw new RuntimeException('SOURCE_RUNTIME_RECOVERY_PROJECTION_DRIFT: ' . $relative);
            }
        }
        $state['phase'] = 'recovering';
        $state = $this->runtimeSave($path, $state);
        $this->runtimePointer($state, 'recovering');
        $this->runtimeHost($state, 'vendor-recover');
        if ($state['source_recovery'] !== null) {
            if (hash_file('sha256', $root . '/' . $state['source_recovery']) !== $state['source_recovery_sha256']) { throw new RuntimeException('SOURCE_RUNTIME_RECOVERY_DRIFT'); }
            self::$recoveringRuntime = $state['transition_id'];
            try { $this->recover($root, $state['binding']['plan_path']); }
            finally { self::$recoveringRuntime = null; }
        }
        // Remove the new authority before restoring either original release authority.
        if (isset($state['binding']['internal_preinstall'])) {
            $derived = array_values(array_unique(['server/.peanut/development-runtime.json', ...$derived]));
        }
        foreach ($derived as $relative) {
            $old = $state['backups'][$relative] ?? ['present' => false];
            $target = ScaffoldPathGuard::projectPath($root, $relative);
            if ($old['present']) {
                $backup = ScaffoldPathGuard::existingFileWithin(dirname($path), dirname($path) . '/' . $old['backup'], 'SOURCE_RUNTIME_RECOVERY_BACKUP_INVALID');
                if (hash_file('sha256', $backup) !== $old['sha256'] || (fileperms($backup) & 0777) !== 0600) { throw new RuntimeException('SOURCE_RUNTIME_RECOVERY_BACKUP_DRIFT'); }
                $this->writeFileAtomic($target, (string) file_get_contents($backup), $old['mode']);
            } elseif (file_exists($target) || is_link($target)) {
                $this->regularFileState($target, $relative);
                if (!unlink($target)) { throw new RuntimeException('SOURCE_RUNTIME_RECOVERY_REMOVE_FAILED'); }
                $this->runtimeSyncDirectory(dirname($target));
            }
        }
        $this->runtimeHost($state, 'vendor-paired');
        // Old persistent admission pointers and all ephemeral grants are revoked, never replayed.
        foreach (['current.json', 'receipt.json'] as $name) {
            $file = $root . '/server/runtime/upgrade/verified-deployment/' . $name;
            if (file_exists($file) || is_link($file)) {
                ScaffoldPathGuard::existingFileWithin($root, $file, 'SOURCE_RUNTIME_ADMISSION_UNSAFE'); unlink($file);
                $this->runtimeSyncDirectory(dirname($file));
            }
        }
        if (isset($state['published_head_sha256'])) {
            $state['recovery_restoration_intent'] = true;
            $state = $this->runtimeSave($path, $state);
            $old = $state['backups']['server/private/installation/deployment.json'];
            $previous = null;
            if ($old['present']) {
                $backup = ScaffoldPathGuard::existingFileWithin(dirname($path), dirname($path) . '/' . $old['backup'], 'SOURCE_RUNTIME_HEAD_PREIMAGE_INVALID');
                if (hash_file('sha256', $backup) !== $old['sha256'] || (fileperms($backup) & 0777) !== 0600) { throw new RuntimeException('SOURCE_RUNTIME_HEAD_PREIMAGE_CHANGED'); }
                $previous = (string) file_get_contents($backup);
            }
            $restored = \PeanutServerUpdatePlan::recoverApplicationDeployment($root . '/server', $state['published_head_sha256'], $previous);
            if ($restored === null) { unset($state['published_head_sha256']); }
            else { $state['published_head_sha256'] = $restored; }
        }
        $state['phase'] = 'recovered';
        $state = $this->runtimeSave($path, $state);
        $this->runtimePointer($state, 'recovered');
        // A dirty same-manifest refreeze cannot claim its earlier program bytes were recovered.
        if (!isset($state['binding']['internal_preinstall']) && $state['old_program_matches']) {
            $this->runtimeAdmit($state);
            $state['recovery_admitted'] = true;
            $state = $this->runtimeSave($path, $state);
            $this->runtimePointer($state, 'recovered');
        }
        return $state;
    }

    private function runtimeSyncDirectory(string $path): void
    {
        $directory = fopen($path, 'r');
        if ($directory === false || !fsync($directory)) { throw new RuntimeException('SOURCE_RUNTIME_DIRECTORY_SYNC_FAILED'); }
        fclose($directory);
    }

    private function runtimeAdmit(array $state): void
    {
        // The host adapter starts a fresh fixed-image PHP process with the target's own Composer loader.
        // Product/project locks remain held; transfer only the instance lock to the native child.
        \PeanutServerUpdatePlan::sourceAdmissionHandoff($state['binding']['instance_root'] . '/server',
            fn() => $this->runtimeHost($state, 'admit'));
    }

    private function runtimeRequireNativeProtocol(string $root): void
    {
        $path = ScaffoldPathGuard::existingFileWithin($root, $root . '/server/docker/scripts/update-plan.php', 'SOURCE_RUNTIME_NATIVE_ENTRY_MISSING');
        $tokens = array_values(array_filter(token_get_all((string) file_get_contents($path)),
            static fn($token): bool => !is_array($token) || !in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)));
        foreach ($tokens as $index => $token) {
            if (!is_array($token) || $token[0] !== T_CONST) { continue; }
            $name = $tokens[$index + 1] ?? null;
            $value = $tokens[$index + 3] ?? null;
            if (is_array($name) && $name[1] === 'APPLICATION_DEPLOYMENT' && ($tokens[$index + 2] ?? null) === '='
                && is_array($value) && $value[0] === T_CONSTANT_ENCAPSED_STRING
                && trim($value[1], "'\"") === \PeanutServerUpdatePlan::APPLICATION_DEPLOYMENT) { return; }
        }
        throw new RuntimeException('SOURCE_RUNTIME_TARGET_REQUIRES_NATIVE_PROTOCOL_UPGRADE');
    }

    private function createRecovery(string $root, array $plan): string
    {
        $paths = ['.peanut/application-manifest.json'];
        foreach ($plan['actions'] as $action) {
            if (in_array($action['action'], ['create', 'replace', 'regenerate', 'delete', 'rename', 'rename-preserve'], true)) {
                $paths[] = $action['path'];
                if (isset($action['rename_from'])) { $paths[] = $action['rename_from']; }
            }
            if (in_array($action['action'], ['create', 'replace', 'regenerate', 'rename', 'rename-preserve', 'preserve'], true)) {
                $paths[] = '.peanut/scaffold-baseline/' . $plan['identity']['to']['version'] . '/files/' . $action['path'];
            }
        }
        $paths = array_values(array_unique($paths));
        sort($paths, SORT_STRING);
        return $this->createRecoveryForPaths($root, $plan['candidate'], $paths);
    }

    private function createRecoveryForPaths(string $root, string $candidate, array $paths): string
    {
        $directory = ScaffoldPathGuard::projectPath($root, '.peanut/upgrades/backups/' . $candidate);
        ScaffoldPathGuard::ensureDirectory($directory . '/files');
        chmod($directory, 0700);
        chmod($directory . '/files', 0700);
        $files = [];
        foreach ($paths as $relative) {
            $state = $this->regularFileState(ScaffoldPathGuard::projectPath($root, $relative), $relative);
            if ($state['present']) {
                $content = file_get_contents(ScaffoldPathGuard::projectPath($root, $relative));
                if (!is_string($content)) {
                    throw new RuntimeException('SCAFFOLD_BACKUP_READ_FAILED: ' . $relative);
                }
                $backupRelative = 'files/' . $relative;
                $backup = ScaffoldPathGuard::projectPath($directory, $backupRelative);
                if (file_exists($backup) || is_link($backup)) {
                    ScaffoldPathGuard::existingFileWithin($directory, $backup, 'SCAFFOLD_RECOVERY_BACKUP_INVALID');
                    if (file_get_contents($backup) !== $content) { throw new RuntimeException('SCAFFOLD_RECOVERY_PREIMAGE_CHANGED'); }
                } else { $this->writeFileAtomic($backup, $content, 0600); }
                $state['backup'] = $backupRelative;
            } else {
                $state['backup'] = null;
            }
            $files[$relative] = $state;
        }
        $recovery = ['schema_version' => 2,'protocol' => 'peanut.scaffold-recovery.v2','candidate' => $candidate,
            'pre_tree_sha256' => 'sha256:' . hash('sha256', self::canonicalJson($files)),'files' => $files];
        $path = $directory . '/recovery.json';
        if (is_file($path)) {
            $existing = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
            if ($existing !== $recovery) {
                throw new RuntimeException('SCAFFOLD_RECOVERY_COLLISION');
            }
        } else {
            $this->writeJsonAtomic($path, $recovery, 0600);
        }
        return $this->relative($root, $path);
    }

    private function recoverCandidate(string $root, array $plan, string $operation): array
    {
        $this->runtimeSourceGuard($root, $plan, $operation);
        $ledger = $this->ledger($root);
        $manifestPath = ScaffoldPathGuard::projectPath($root, '.peanut/upgrades/backups/' . $plan['candidate'] . '/recovery.json');
        if (!is_file($manifestPath)) {
            throw new RuntimeException('SCAFFOLD_RECOVERY_NOT_FOUND');
        }
        $recovery = json_decode((string) file_get_contents($manifestPath), true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($recovery) || ($recovery['candidate'] ?? null) !== $plan['candidate'] || !is_array($recovery['files'] ?? null)
            || !hash_equals((string) ($recovery['pre_tree_sha256'] ?? ''), 'sha256:' . hash('sha256', self::canonicalJson($recovery['files'])))) {
            throw new RuntimeException('SCAFFOLD_RECOVERY_INVALID');
        }
        $already = $this->recoveryMatches($root, $recovery);
        if (!$already) {
            foreach ($recovery['files'] as $relative => $state) {
                $target = ScaffoldPathGuard::projectPath($root, (string) $relative);
                if ($state['present']) {
                    $backup = ScaffoldPathGuard::existingFileWithin(dirname($manifestPath), dirname($manifestPath) . '/' . $state['backup'], 'SCAFFOLD_RECOVERY_BACKUP_INVALID');
                    $content = file_get_contents($backup);
                    if (!is_string($content) || !hash_equals($state['sha256'], hash('sha256', $content))) {
                        throw new RuntimeException('SCAFFOLD_RECOVERY_BACKUP_DRIFT');
                    }
                    $this->writeFileAtomic($target, $content, (int) $state['mode']);
                } elseif (file_exists($target)) {
                    if (!is_file($target) || is_link($target)) {
                        throw new RuntimeException('SCAFFOLD_RECOVERY_PATH_COLLISION: ' . $relative);
                    }
                    unlink($target);
                    $this->pruneEmptyParents(dirname($target), $root);
                }
            }
            if (!$this->recoveryMatches($root, $recovery)) {
                throw new RuntimeException('SCAFFOLD_RECOVERY_VERIFY_FAILED');
            }
        }
        $this->assertPluginProjection($root, $plan);
        if (!$this->hasEvent($ledger, $plan['candidate'], $operation, 'recovered')) {
            $ledger->append($this->event($plan, $operation, 'recovered', $plan['identity']['managed_pre_sha256'], $plan['identity']['managed_pre_sha256']));
        }
        return ['status' => 'recovered', 'candidate' => $plan['candidate'], 'tree_sha256' => $recovery['pre_tree_sha256'], 'idempotent' => $already];
    }

    /** Advance the scaffold identity and snapshot the current version used for this target rendering. */
    private function nextApplicationManifest(string $root, array $plan, ScaffoldManifest $to): array
    {
        [$application] = $this->applicationManifest($root);
        $oldByPath = [];
        foreach ($application['files'] as $file) {
            $oldByPath[$file['path']] = $file;
        }
        $managedPaths = array_column($plan['actions'], 'path');
        $files = [];
        foreach ($application['files'] as $file) {
            if (!in_array($file['classification'], ['managed','generated-managed'], true)) {
                $state = $this->regularFileState(ScaffoldPathGuard::projectPath($root, $file['path']), $file['path']);
                $file['sha256'] = $state['sha256'];
                $file['mode'] = $state['mode'];
                $files[] = $file;
            }
        }
        foreach ($plan['actions'] as $action) {
            if (in_array($action['action'], ['delete', 'omit'], true)) {
                continue;
            }
            $state = $this->regularFileState(ScaffoldPathGuard::projectPath($root, $action['path']), $action['path']);
            if (!$state['present']) {
                throw new RuntimeException('SCAFFOLD_APPLY_MANAGED_MISSING: ' . $action['path']);
            }
            $files[] = ['path' => $action['path'],'sha256' => $state['sha256'],'mode' => $state['mode'],'classification' => $action['classification'],
                'owner' => 'scaffold','source' => $action['path'],'baseline_path' => '.peanut/scaffold-baseline/' . $to->version() . '/files/' . $action['path'],
                'baseline_sha256' => $action['target_sha256']];
        }
        usort($files, static fn(array $a, array $b): int => strcmp($a['path'], $b['path']));
        $managed = array_values(array_filter($files, static fn(array $f): bool => in_array($f['classification'], ['managed','generated-managed'], true)));
        $appOwned = array_values(array_filter($files, static fn(array $f): bool => $f['classification'] === 'app-owned'));
        $application['template'] = ['version' => $to->version(),'inventory_sha256' => $to->release()['inventory_sha256'],
            'source_commit' => $to->release()['source_commit'],'source_tree' => $to->release()['source_tree']];
        $application['generation_source'] = $plan['identity']['target_generation_source'];
        $edition = $plan['identity']['edition'] ?? null;
        if (is_array($edition)) {
            $application['edition'] = $edition;
        }
        $application['schema_version'] = 2;
        $application['protocol'] = 'peanut.application-scaffold.v2';
        $application['application']['version'] = $plan['identity']['application_version'];
        $application['ownership']['baseline_root'] = '.peanut/scaffold-baseline/' . $to->version() . '/files';
        $application['digests'] = ['managed_tree_sha256' => $this->manifestTreeDigest($managed),'app_owned_tree_sha256' => $this->manifestTreeDigest($appOwned)];
        $application['files'] = $files;
        $application['last_scaffold_upgrade'] = [
            'candidate' => $plan['candidate'],
            'from' => $plan['identity']['from']['version'],
            'to' => $to->version(),
        ];
        if (is_array($edition)) {
            $application['last_scaffold_upgrade']['edition_profile_sha256'] = $edition['source_sha256'];
            $application['last_scaffold_upgrade']['tenant_bootstrap'] = $edition['tenant_bootstrap'];
        }
        return $application;
    }

    private function managedDigestFromManifest(string $root, array $manifest): string
    {
        $files = [];
        foreach ($manifest['files'] as $file) {
            if (in_array($file['classification'], ['managed','generated-managed'], true)) {
                $files[$file['path']] = $this->regularFileState(ScaffoldPathGuard::projectPath($root, $file['path']), $file['path']);
            }
        }
        ksort($files, SORT_STRING);
        return 'sha256:' . hash('sha256', self::canonicalJson($files));
    }
    private function manifestTreeDigest(array $files): string
    {
        $rows = array_map(static fn(array $f): string => $f['path'] . "\0" . $f['sha256'], $files);
        sort($rows, SORT_STRING);
        return hash('sha256', implode("\n", $rows));
    }
    private function recoveryMatches(string $root, array $recovery): bool
    {
        foreach ($recovery['files'] as $relative => $state) {
            $actual = $this->regularFileState(ScaffoldPathGuard::projectPath($root, (string) $relative), (string) $relative);
            if ($actual['present'] !== $state['present'] || $actual['sha256'] !== $state['sha256'] || $actual['mode'] !== $state['mode']) {
                return false;
            }
        } return true;
    }

    private function writeFileAtomic(string $path, string $content, int $mode): void
    {
        ScaffoldPathGuard::ensureDirectory(dirname($path));
        $tmp = dirname($path) . '/.' . basename($path) . '.stage-' . bin2hex(random_bytes(6));
        $stream = fopen($tmp, 'x+b');
        if ($stream === false || !chmod($tmp, $mode) || fwrite($stream, $content) !== strlen($content)
            || !fflush($stream) || !fsync($stream)) {
            if (is_resource($stream)) { fclose($stream); }
            @unlink($tmp);
            throw new RuntimeException('SCAFFOLD_ATOMIC_WRITE_FAILED: ' . $path);
        }
        fclose($stream);
        if (!rename($tmp, $path)) {
            @unlink($tmp);
            throw new RuntimeException('SCAFFOLD_ATOMIC_WRITE_FAILED: ' . $path);
        }
        $directory = fopen(dirname($path), 'r');
        if ($directory === false || !fsync($directory)) { throw new RuntimeException('SCAFFOLD_DIRECTORY_SYNC_FAILED'); }
        fclose($directory);
    }
    private function pruneEmptyParents(string $directory, string $root): void
    {
        while ($directory !== $root && str_starts_with($directory, $root . DIRECTORY_SEPARATOR)) {
            if (!is_dir($directory) || is_link($directory) || (scandir($directory) ?: []) !== ['.','..'] || !rmdir($directory)) {
                return;
            }
            $directory = dirname($directory);
        }
    }
    private function writeJsonAtomic(string $path, array $data, int $mode): void
    {
        $this->writeFileAtomic($path, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . "\n", $mode);
    }
    private function relative(string $root, string $path): string
    {
        return str_replace(DIRECTORY_SEPARATOR, '/', substr($path, strlen($root) + 1));
    }
    private static function canonicalJson(array $value): string
    {
        self::sortRecursive($value);
        return json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }
    private static function sortRecursive(array &$value): void
    {
        if (!array_is_list($value)) {
            ksort($value, SORT_STRING);
        } foreach ($value as &$item) {
            if (is_array($item)) {
                self::sortRecursive($item);
            }
        }
    }
}
