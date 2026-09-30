<?php

declare(strict_types=1);

use app\common\infrastructure\scaffold\DeterministicEditionArchive;
use PeanutAdmin\Modules\Ops\Contract\InstanceSafetyQueries;

require_once dirname(__DIR__, 2) . '/docker/scripts/update-plan.php';
require_once dirname(__DIR__, 2) . '/app/common/infrastructure/scaffold/DeterministicEditionArchive.php';
require_once dirname(__DIR__, 2) . '/app/modules/official/ops/src/Contract/InstanceSafetyQueries.php';

function updatePlanExpect(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function updatePlanDelete(string $path): void
{
    if (is_dir($path) && !is_link($path)) {
        foreach (array_diff(scandir($path) ?: [], ['.', '..']) as $entry) {
            updatePlanDelete($path . '/' . $entry);
        }
        rmdir($path);
        return;
    }
    if (file_exists($path) || is_link($path)) {
        unlink($path);
    }
}

/** @param array<string,string> $files */
function updatePlanIdentity(string $slug, string $version, array $files): array
{
    ksort($files, SORT_STRING);
    $rows = [];
    foreach ($files as $path => $bytes) {
        $rows[] = ['path' => $path, 'sha256' => hash('sha256', $bytes), 'mode' => 0644];
    }
    return [
        'schema_version' => 1,
        'protocol' => 'peanut.server-release.v1',
        'application' => [
            'kind' => 'application',
            'manifest_sha256' => str_repeat('1', 64),
            'commit' => str_repeat($version === '1.0.0' ? 'a' : 'b', 40),
            'tree' => str_repeat($version === '1.0.0' ? 'c' : 'd', 40),
            'slug' => $slug,
            'edition' => 'standalone',
            'version' => $version,
            'profile' => 'full',
            'package_identity' => $slug . '/application',
            'name' => 'Update Fixture',
            'source_files_sha256' => str_repeat('2', 64),
        ],
        'files' => $rows,
        'files_sha256' => hash('sha256', json_encode($rows, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)),
    ];
}

/** @param array<string,string> $files */
function updatePlanWriteServer(string $root, string $version, array $files): void
{
    foreach ($files as $path => $bytes) {
        $relative = substr($path, strlen('server/'));
        $target = $root . '/server/' . $relative;
        if (!is_dir(dirname($target)) && !mkdir(dirname($target), 0700, true) && !is_dir(dirname($target))) {
            throw new RuntimeException('cannot create fixture directory');
        }
        file_put_contents($target, $bytes);
        chmod($target, 0644);
    }
    $identity = updatePlanIdentity('fixture-app', $version, $files);
    $identityPath = $root . '/server/.peanut/release-identity.json';
    if (!is_dir(dirname($identityPath)) && !mkdir(dirname($identityPath), 0700, true) && !is_dir(dirname($identityPath))) {
        throw new RuntimeException('cannot create fixture identity directory');
    }
    file_put_contents($identityPath, json_encode($identity, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n");
    chmod($identityPath, 0644);
}

/** @param array<string,string> $files */
function updatePlanArchive(string $root, string $version, array $files, ?callable $mutateIdentity = null): string
{
    $stage = $root . '/stage-' . bin2hex(random_bytes(3));
    updatePlanWriteServer($stage, $version, $files);
    $identityPath = $stage . '/server/.peanut/release-identity.json';
    if ($mutateIdentity !== null) {
        $identity = json_decode((string) file_get_contents($identityPath), true, 512, JSON_THROW_ON_ERROR);
        $identity = $mutateIdentity($identity);
        file_put_contents($identityPath, json_encode($identity, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n");
    }
    $archive = $root . '/target-' . bin2hex(random_bytes(4)) . '.tar.gz';
    (new DeterministicEditionArchive())->write($stage, 'fixture-release', $archive);
    updatePlanDelete($stage);
    return $archive;
}

function updatePlanWorkspace(string $root): string
{
    $workspace = $root . '/workspace-' . bin2hex(random_bytes(3));
    mkdir($workspace, 0700);
    return $workspace;
}

$projectRoot = dirname(__DIR__, 3);
$tmpRoot = $projectRoot . '/.local/tmp';
if (!is_dir($tmpRoot) && !mkdir($tmpRoot, 0700, true) && !is_dir($tmpRoot)) {
    throw new RuntimeException('cannot create checkout-local temporary root');
}
$root = $tmpRoot . '/server-update-plan-' . bin2hex(random_bytes(6));
mkdir($root, 0700);

try {
    $currentFiles = [
        'server/app/a.txt' => "old\n",
        'server/app/remove.txt' => "remove\n",
        'server/composer.lock' => "lock-v1\n",
    ];
    $targetFiles = [
        'server/app/a.txt' => "new\n",
        'server/app/new.txt' => "added\n",
        'server/composer.lock' => "lock-v2\n",
    ];
    $targetFilesNoDependencyChange = $targetFiles;
    $targetFilesNoDependencyChange['server/composer.lock'] = "lock-v1\n";
    $instance = $root . '/instance';
    updatePlanWriteServer($instance, '1.0.0', $currentFiles);
    $archive = updatePlanArchive($root, '1.1.0', $targetFiles);
    $workspace = updatePlanWorkspace($root);
    $plan = PeanutServerUpdatePlan::build($instance . '/server', $archive, (string) hash_file('sha256', $archive), $workspace);
    $ops = array_column($plan['operations'], 'operation', 'path');
    updatePlanExpect(($ops['server/app/a.txt'] ?? null) === 'replace', 'changed program file must replace');
    updatePlanExpect(($ops['server/app/new.txt'] ?? null) === 'add', 'new program file must add');
    updatePlanExpect(($ops['server/app/remove.txt'] ?? null) === 'delete', 'removed program file must delete');
    updatePlanExpect(($ops['server/.peanut/release-identity.json'] ?? null) === 'replace', 'release identity must advance with program files');
    updatePlanExpect(($plan['requirements']['composer_dependencies_changed'] ?? false) === true, 'composer lock change must require dependency preparation');
    updatePlanExpect(is_file($workspace . '/plan.json'), 'plan must be durably written before any apply phase');
    updatePlanExpect(is_file($workspace . '/prepared/server/app/a.txt'), 'plan must prepare target server files from archive');

    $applyInstance = $root . '/apply-instance';
    updatePlanWriteServer($applyInstance, '1.0.0', $currentFiles + ['server/docker/conf/nginx.conf' => "old-conf\n"]);
    upgradePlanFixtureFile($applyInstance . '/server/.env', "DB_NAME=keep\n", 0600);
    upgradePlanFixtureFile($applyInstance . '/server/app/local-custom.txt', "custom\n", 0600);
    upgradePlanFixtureFile($applyInstance . '/server/public/storage/upload.txt', "upload\n", 0600);
    upgradePlanFixtureFile($applyInstance . '/server/docker/secrets/mysql-root-password', "secret\n", 0600);
    $applyArchive = updatePlanArchive(
        $root,
        '1.1.0',
        $targetFilesNoDependencyChange + ['server/docker/conf/nginx.conf' => "new-conf\n"],
    );
    $applyWorkspace = updatePlanWorkspace($root);
    PeanutServerUpdatePlan::build(
        $applyInstance . '/server',
        $applyArchive,
        (string) hash_file('sha256', $applyArchive),
        $applyWorkspace,
    );
    $applyJournal = PeanutServerUpdatePlan::apply($applyInstance . '/server', $applyWorkspace);
    updatePlanExpect(($applyJournal['status'] ?? null) === 'completed', 'apply must complete program update');
    updatePlanExpect((string) file_get_contents($applyInstance . '/server/app/a.txt') === "new\n", 'replace must publish target bytes');
    updatePlanExpect(is_file($applyInstance . '/server/app/new.txt'), 'add must publish target file');
    updatePlanExpect(!file_exists($applyInstance . '/server/app/remove.txt'), 'delete must remove old program file');
    updatePlanExpect((string) file_get_contents($applyInstance . '/server/docker/conf/nginx.conf') === "new-conf\n", 'version conf must update');
    updatePlanExpect((string) file_get_contents($applyInstance . '/server/.env') === "DB_NAME=keep\n", 'server .env must be preserved');
    updatePlanExpect((string) file_get_contents($applyInstance . '/server/app/local-custom.txt') === "custom\n", 'unknown files must be preserved');
    updatePlanExpect((string) file_get_contents($applyInstance . '/server/public/storage/upload.txt') === "upload\n", 'public storage must be preserved');
    updatePlanExpect((string) file_get_contents($applyInstance . '/server/docker/secrets/mysql-root-password') === "secret\n", 'docker secrets must be preserved');
    updatePlanExpect(is_file($applyWorkspace . '/backups/server/app/a.txt'), 'replace backup must include affected old file');
    updatePlanExpect(is_file($applyWorkspace . '/backups/server/app/remove.txt'), 'delete backup must include affected old file');
    updatePlanExpect(is_file($applyWorkspace . '/backups/server/docker/conf/nginx.conf'), 'version conf backup must include affected old file');
    updatePlanExpect(!file_exists($applyInstance . '/server/runtime/upgrade/maintenance.json'), 'successful apply must clear maintenance marker');

    $lockedInstance = $root . '/locked-instance';
    updatePlanWriteServer($lockedInstance, '1.0.0', $currentFiles);
    $lockedWorkspace = updatePlanWorkspace($root);
    PeanutServerUpdatePlan::build($lockedInstance . '/server', $applyArchive, (string) hash_file('sha256', $applyArchive), $lockedWorkspace);
    upgradePlanFixtureJson($lockedInstance . '/server/runtime/upgrade/current-update.json', [
        'schema_version' => 1,
        'protocol' => 'peanut.server-update-current.v1',
        'update_id' => 'server_update_20260930000000_aaaaaaaaaaaa',
        'workspace' => $lockedWorkspace,
        'status' => 'failed',
    ]);
    try {
        PeanutServerUpdatePlan::apply($lockedInstance . '/server', $lockedWorkspace);
        throw new RuntimeException('second updater was accepted');
    } catch (RuntimeException $exception) {
        updatePlanExpect(str_contains($exception->getMessage(), 'already active'), 'single updater guard must fail closed');
    }

    $interruptedInstance = $root . '/interrupted-instance';
    updatePlanWriteServer($interruptedInstance, '1.0.0', $currentFiles);
    $interruptedWorkspace = updatePlanWorkspace($root);
    PeanutServerUpdatePlan::build(
        $interruptedInstance . '/server',
        $applyArchive,
        (string) hash_file('sha256', $applyArchive),
        $interruptedWorkspace,
    );
    try {
        PeanutServerUpdatePlan::apply(
            $interruptedInstance . '/server',
            $interruptedWorkspace,
            static function (string $event, string $path): void {
                if ($event === 'after_operation' && $path === 'server/app/remove.txt') {
                    throw new RuntimeException('fixture interruption after journaled changes');
                }
            },
        );
        throw new RuntimeException('interruption did not stop apply');
    } catch (RuntimeException $exception) {
        updatePlanExpect(str_contains($exception->getMessage(), 'fixture interruption'), 'fixture interruption must propagate');
    }
    updatePlanExpect(is_file($interruptedInstance . '/server/runtime/upgrade/maintenance.json'), 'failed apply must keep maintenance marker');
    $window = (new InstanceSafetyQueries($interruptedInstance . '/server'))->blockingMaintenanceWindow();
    updatePlanExpect(is_array($window) && $window['reason_key'] === 'planned-upgrade', 'file maintenance guard must work without database');
    $recovery = PeanutServerUpdatePlan::recover($interruptedInstance . '/server', $interruptedWorkspace);
    updatePlanExpect(($recovery['status'] ?? null) === 'recovered', 'recover must complete from journal');
    updatePlanExpect((string) file_get_contents($interruptedInstance . '/server/app/a.txt') === "old\n", 'recover must restore replaced file');
    updatePlanExpect(!file_exists($interruptedInstance . '/server/app/new.txt'), 'recover must clear file added by failed update');
    updatePlanExpect((string) file_get_contents($interruptedInstance . '/server/app/remove.txt') === "remove\n", 'recover must restore deleted file');
    updatePlanExpect(!file_exists($interruptedInstance . '/server/runtime/upgrade/maintenance.json'), 'recovered update must clear maintenance marker');

    $migrationInstance = $root . '/migration-instance';
    updatePlanWriteServer($migrationInstance, '1.0.0', $currentFiles);
    $migrationArchive = updatePlanArchive(
        $root,
        '1.1.0',
        $targetFilesNoDependencyChange + ['server/database/migrations/20260930_fixture.php' => "<?php\n"],
    );
    $migrationWorkspace = updatePlanWorkspace($root);
    PeanutServerUpdatePlan::build(
        $migrationInstance . '/server',
        $migrationArchive,
        (string) hash_file('sha256', $migrationArchive),
        $migrationWorkspace,
    );
    try {
        PeanutServerUpdatePlan::apply($migrationInstance . '/server', $migrationWorkspace);
        throw new RuntimeException('database migration update was applied');
    } catch (RuntimeException $exception) {
        updatePlanExpect(str_contains($exception->getMessage(), 'database migration gate is not implemented'), 'missing database migration gate must fail closed');
    }
    updatePlanExpect((string) file_get_contents($migrationInstance . '/server/app/a.txt') === "old\n", 'migration gate must not modify program files');
    updatePlanExpect(!file_exists($migrationInstance . '/server/runtime/upgrade/maintenance.json'), 'migration gate failure must not activate maintenance');

    $dependencyInstance = $root . '/dependency-instance';
    updatePlanWriteServer($dependencyInstance, '1.0.0', $currentFiles);
    $dependencyWorkspace = updatePlanWorkspace($root);
    PeanutServerUpdatePlan::build($dependencyInstance . '/server', $archive, (string) hash_file('sha256', $archive), $dependencyWorkspace);
    try {
        PeanutServerUpdatePlan::apply($dependencyInstance . '/server', $dependencyWorkspace);
        throw new RuntimeException('composer dependency update was applied');
    } catch (RuntimeException $exception) {
        updatePlanExpect(str_contains($exception->getMessage(), 'composer dependency preparation gate is not implemented'), 'missing composer preparation gate must fail closed');
    }
    updatePlanExpect((string) file_get_contents($dependencyInstance . '/server/composer.lock') === "lock-v1\n", 'composer gate must not modify lock file');

    $collisionInstance = $root . '/collision-instance';
    updatePlanWriteServer($collisionInstance, '1.0.0', $currentFiles);
    mkdir($collisionInstance . '/server/app/new.txt', 0700, true);
    $collisionWorkspace = updatePlanWorkspace($root);
    try {
        PeanutServerUpdatePlan::build($collisionInstance . '/server', $archive, (string) hash_file('sha256', $archive), $collisionWorkspace);
        throw new RuntimeException('unknown target collision was accepted');
    } catch (RuntimeException $exception) {
        updatePlanExpect(str_contains($exception->getMessage(), 'unknown existing path'), 'unknown collision must fail closed');
    }

    $protectedFiles = $targetFiles;
    $protectedFiles['server/docker/secrets/root-password'] = "secret\n";
    $protectedArchive = updatePlanArchive($root, '1.2.0', $protectedFiles);
    try {
        PeanutServerUpdatePlan::build($instance . '/server', $protectedArchive, (string) hash_file('sha256', $protectedArchive), updatePlanWorkspace($root));
        throw new RuntimeException('protected target was accepted');
    } catch (RuntimeException $exception) {
        updatePlanExpect(str_contains($exception->getMessage(), 'protected instance data'), 'protected target must fail closed');
    }

    $malformedArchive = updatePlanArchive(
        $root,
        '1.3.0',
        $targetFiles,
        static function (array $identity): array {
            $identity['files'][0]['sha256'] = str_repeat('0', 64);
            $identity['files_sha256'] = hash(
                'sha256',
                json_encode($identity['files'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            );
            return $identity;
        },
    );
    try {
        PeanutServerUpdatePlan::build($instance . '/server', $malformedArchive, (string) hash_file('sha256', $malformedArchive), updatePlanWorkspace($root));
        throw new RuntimeException('archive whose files differ from identity was accepted');
    } catch (RuntimeException $exception) {
        updatePlanExpect(str_contains($exception->getMessage(), 'differ from release identity'), 'tampered file list must fail closed');
    }

    $wrongDigestWorkspace = updatePlanWorkspace($root);
    try {
        PeanutServerUpdatePlan::build($instance . '/server', $archive, str_repeat('0', 64), $wrongDigestWorkspace);
        throw new RuntimeException('wrong trusted digest was accepted');
    } catch (RuntimeException $exception) {
        updatePlanExpect(str_contains($exception->getMessage(), 'differs from trusted'), 'external trusted digest mismatch must fail closed');
    }

    echo "SERVER-UPDATE-PLAN passed\n";
} finally {
    updatePlanDelete($root);
}

function upgradePlanFixtureFile(string $path, string $contents, int $mode = 0600): void
{
    if (!is_dir(dirname($path)) && !mkdir(dirname($path), 0700, true) && !is_dir(dirname($path))) {
        throw new RuntimeException('cannot create fixture directory');
    }
    file_put_contents($path, $contents);
    chmod($path, $mode);
}

/** @param array<string,mixed> $data */
function upgradePlanFixtureJson(string $path, array $data): void
{
    upgradePlanFixtureFile(
        $path,
        json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n",
        0600,
    );
}
