<?php

declare(strict_types=1);

use app\common\infrastructure\scaffold\DeterministicEditionArchive;
use PeanutAdmin\Modules\Ops\Contract\InstanceSafetyQueries;

require_once dirname(__DIR__, 2) . '/docker/scripts/update-plan.php';
require_once dirname(__DIR__, 2) . '/app/common/infrastructure/scaffold/DeterministicEditionArchive.php';
require_once dirname(__DIR__, 2) . '/app/modules/official/ops/src/Contract/InstanceSafetyQueries.php';

$updatePlanAssertions = 0;

function updatePlanExpect(bool $condition, string $message): void
{
    global $updatePlanAssertions;
    ++$updatePlanAssertions;
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
function updatePlanIdentity(string $slug, string $version, array $files, array $modes = []): array
{
    ksort($files, SORT_STRING);
    $rows = [];
    foreach ($files as $path => $bytes) {
        $rows[] = ['path' => $path, 'sha256' => hash('sha256', $bytes), 'mode' => $modes[$path] ?? 0644];
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
function updatePlanWriteServer(string $root, string $version, array $files, bool $installed = true, array $modes = []): void
{
    foreach ($files as $path => $bytes) {
        $relative = substr($path, strlen('server/'));
        $target = $root . '/server/' . $relative;
        if (!is_dir(dirname($target)) && !mkdir(dirname($target), 0700, true) && !is_dir(dirname($target))) {
            throw new RuntimeException('cannot create fixture directory');
        }
        file_put_contents($target, $bytes);
        chmod($target, $modes[$path] ?? 0644);
    }
    $identity = updatePlanIdentity('fixture-app', $version, $files, $modes);
    $identityPath = $root . '/server/.peanut/release-identity.json';
    if (!is_dir(dirname($identityPath)) && !mkdir(dirname($identityPath), 0700, true) && !is_dir(dirname($identityPath))) {
        throw new RuntimeException('cannot create fixture identity directory');
    }
    file_put_contents($identityPath, json_encode($identity, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n");
    chmod($identityPath, 0644);
    if ($installed) {
        $baseline = [
            'protocol' => 'peanut.installation-baseline.v1',
            'deployment_mode' => 'standalone',
            'source' => [
                'kind' => 'server-release',
                'server_release_identity_sha256' => (string) hash_file('sha256', $identityPath),
            ],
        ];
        $baselinePath = $root . '/server/private/installation/baseline.json';
        upgradePlanFixtureJson($baselinePath, $baseline);
        upgradePlanFixtureJson($root . '/server/private/installation/installed.json', [
            'schema_version' => 1,
            'protocol' => 'peanut.installation-receipt.v1',
            'state' => 'installed',
            'deployment_mode' => 'standalone',
            'baseline_manifest' => [
                'path' => 'server/private/installation/baseline.json',
                'sha256' => (string) hash_file('sha256', $baselinePath),
            ],
        ]);
    }
}

/** @param array<string,string> $files */
function updatePlanArchive(string $root, string $version, array $files, ?callable $mutateIdentity = null, array $modes = []): string
{
    $stage = $root . '/stage-' . bin2hex(random_bytes(3));
    updatePlanWriteServer($stage, $version, $files, false, $modes);
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

/** A file-only fixture for the private receipt format; it does not exercise native database health. */
function updatePlanSealFixture(string $serverRoot, string $workspace, string $phase): void
{
    $plan = json_decode((string) file_get_contents($workspace . '/plan.json'), true, 512, JSON_THROW_ON_ERROR);
    $journal = json_decode((string) file_get_contents($workspace . '/journal.json'), true, 512, JSON_THROW_ON_ERROR);
    $method = new ReflectionMethod(PeanutServerUpdatePlan::class, 'sealVerification');
    $method->invoke(null, $serverRoot, $workspace, $plan, $journal, $phase);
}

/** File-only dependency receipt; it does not run Composer or qualify an installed vendor tree. */
function updatePlanPrepareFixture(string $serverRoot, string $workspace): void
{
    $plan = json_decode((string) file_get_contents($workspace . '/plan.json'), true, 512, JSON_THROW_ON_ERROR);
    $vendor = $workspace . '/prepared/server/vendor';
    upgradePlanFixtureJson($vendor . '/.peanut-complete.json', [
        'protocol' => 'peanut.composer-install.v1',
        'lock_sha256' => hash_file('sha256', $workspace . '/prepared/server/composer.lock'),
    ]);
    upgradePlanFixtureFile($vendor . '/autoload.php', "<?php\n", 0644);
    upgradePlanFixtureJson($workspace . '/preparation.json', [
        'protocol' => 'peanut.server-update-preparation.v1',
        'update_id' => $plan['update_id'],
        'plan_sha256' => hash_file('sha256', $workspace . '/plan.json'),
        'source_identity_sha256' => $plan['source']['identity_sha256'],
        'target_identity_sha256' => $plan['target']['identity_sha256'],
        'vendor_mode' => 'prepared',
        'vendor_receipt_sha256' => hash_file('sha256', $vendor . '/.peanut-complete.json'),
    ]);
}

/** Synthetic signed file receipt; no database operation is performed. */
function updatePlanSignedFixture(string $serverRoot, string $path, array $data): void
{
    upgradePlanFixtureJson($path, $data);
    $key = trim((string) file_get_contents($serverRoot . '/private/installation/update-verification.key'));
    upgradePlanFixtureFile($path . '.hmac', hash_hmac('sha256', (string) file_get_contents($path), $key) . "\n");
}

/** Synthetic recovery point for file/state-machine regression only. */
function updatePlanBackupFixture(string $serverRoot, string $workspace): void
{
    $plan = json_decode((string) file_get_contents($workspace . '/plan.json'), true, 512, JSON_THROW_ON_ERROR);
    upgradePlanFixtureFile($workspace . '/recovery/database.sql.gz', "synthetic database dump; never restore\n");
    updatePlanSignedFixture($serverRoot, $workspace . '/backup.json', [
        'protocol' => 'peanut.server-update-backup.v1',
        'update_id' => $plan['update_id'],
        'plan_sha256' => hash_file('sha256', $workspace . '/plan.json'),
        'snapshots' => [
            'private/installation/installed.json' => $plan['source']['installed_receipt_sha256'],
            'private/installation/baseline.json' => $plan['source']['baseline_sha256'],
            'private/installation/deployment.json' => $plan['source']['deployment_state_sha256'],
        ],
        'database_dump_sha256' => hash_file('sha256', $workspace . '/recovery/database.sql.gz'),
        'fixture_only' => 'synthetic; no database backup',
    ]);
}

/** Synthetic completed migration receipt; no native migration is run. */
function updatePlanMigrationFixture(string $serverRoot, string $workspace): void
{
    $plan = json_decode((string) file_get_contents($workspace . '/plan.json'), true, 512, JSON_THROW_ON_ERROR);
    updatePlanSignedFixture($serverRoot, $workspace . '/migration.json', [
        'protocol' => 'peanut.server-update-migration.v1',
        'status' => 'completed',
        'update_id' => $plan['update_id'],
        'plan_sha256' => hash_file('sha256', $workspace . '/plan.json'),
        'backup_sha256' => hash_file('sha256', $workspace . '/backup.json'),
        'fixture_only' => 'synthetic; no native migration',
    ]);
}

/** Synthetic completed database recovery receipt; no database is restored. */
function updatePlanDatabaseRecoveryFixture(string $serverRoot, string $workspace): void
{
    $plan = json_decode((string) file_get_contents($workspace . '/plan.json'), true, 512, JSON_THROW_ON_ERROR);
    updatePlanSignedFixture($serverRoot, $workspace . '/database-recovery.json', [
        'status' => 'completed',
        'update_id' => $plan['update_id'],
        'backup_sha256' => hash_file('sha256', $workspace . '/backup.json'),
        'fixture_only' => 'synthetic; no database restore',
    ]);
}

$projectRoot = dirname(__DIR__, 3);
$tmpRoot = $projectRoot . '/.local/tmp';
if (!is_dir($tmpRoot) && !mkdir($tmpRoot, 0700, true) && !is_dir($tmpRoot)) {
    throw new RuntimeException('cannot create checkout-local temporary root');
}
$root = $tmpRoot . '/server-update-plan-' . bin2hex(random_bytes(6));
mkdir($root, 0700);

try {
    $durable = new ReflectionMethod(PeanutServerUpdatePlan::class, 'durableFile');
    $durablePath = $root . '/private/installation/secret-test.json';
    $previousUmask = umask(0000);
    try {
        $durable->invoke(null, $durablePath, "secret\n", 0600);
    } finally {
        umask($previousUmask);
    }
    updatePlanExpect(
        (fileperms($durablePath) & 0777) === 0600,
        'durable private file must remain 0600 under permissive caller umask',
    );
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
    upgradePlanFixtureFile($applyInstance . '/server/docker/.env', "MYSQL_ROOT_PASSWORD=secret\n", 0600);
    $applyArchive = updatePlanArchive(
        $root,
        '1.1.0',
        $targetFilesNoDependencyChange + ['server/docker/conf/nginx.conf' => "old-conf\n"],
    );
    $applyWorkspace = updatePlanWorkspace($root);
    PeanutServerUpdatePlan::build(
        $applyInstance . '/server',
        $applyArchive,
        (string) hash_file('sha256', $applyArchive),
        $applyWorkspace,
    );
    updatePlanPrepareFixture($applyInstance . '/server', $applyWorkspace);
    $trafficPermit = $applyInstance . '/server/runtime/upgrade/.traffic-ready';
    $initialTraffic = PeanutServerUpdatePlan::initializeTraffic($applyInstance . '/server');
    updatePlanExpect(
        ($initialTraffic['status'] ?? null) === 'open' && is_file($trafficPermit),
        'fresh known instance may initialize persistent public traffic permission',
    );
    $beginJournal = PeanutServerUpdatePlan::begin($applyInstance . '/server', $applyWorkspace);
    updatePlanBackupFixture($applyInstance . '/server', $applyWorkspace);
    updatePlanExpect(($beginJournal['status'] ?? null) === 'applying', 'begin must publish an applying journal');
    updatePlanExpect(
        !file_exists($trafficPermit) && !is_link($trafficPermit),
        'begin must revoke public traffic permission before program mutation',
    );
    updatePlanExpect(is_file($applyInstance . '/server/runtime/upgrade/maintenance.json'), 'begin must persist maintenance before file apply');
    $activeTraffic = PeanutServerUpdatePlan::initializeTraffic($applyInstance . '/server');
    updatePlanExpect(
        ($activeTraffic['status'] ?? null) === 'closed' && !file_exists($trafficPermit),
        'PHP startup during active update must not restore public permission',
    );
    $markerPath = $applyInstance . '/server/runtime/upgrade/maintenance.json';
    $activeMarkerBytes = (string) file_get_contents($markerPath);
    unlink($markerPath);
    symlink('missing-maintenance-target', $markerPath);
    $danglingTraffic = PeanutServerUpdatePlan::initializeTraffic($applyInstance . '/server');
    updatePlanExpect(
        ($danglingTraffic['status'] ?? null) === 'closed' && !file_exists($trafficPermit),
        'dangling maintenance link must not restore public permission',
    );
    unlink($markerPath);
    upgradePlanFixtureFile($markerPath, $activeMarkerBytes, 0600);
    updatePlanExpect((string) file_get_contents($applyInstance . '/server/app/a.txt') === "old\n", 'begin must not change program files');
    $applyJournal = PeanutServerUpdatePlan::apply($applyInstance . '/server', $applyWorkspace);
    updatePlanMigrationFixture($applyInstance . '/server', $applyWorkspace);
    updatePlanExpect(($applyJournal['status'] ?? null) === 'applied', 'apply must finish program update before activation');
    updatePlanExpect((string) file_get_contents($applyInstance . '/server/app/a.txt') === "new\n", 'replace must publish target bytes');
    updatePlanExpect(is_file($applyInstance . '/server/app/new.txt'), 'add must publish target file');
    updatePlanExpect(!file_exists($applyInstance . '/server/app/remove.txt'), 'delete must remove old program file');
    updatePlanExpect((string) file_get_contents($applyInstance . '/server/docker/conf/nginx.conf') === "old-conf\n", 'unchanged version conf remains intact');
    updatePlanExpect((string) file_get_contents($applyInstance . '/server/.env') === "DB_NAME=keep\n", 'server .env must be preserved');
    updatePlanExpect((string) file_get_contents($applyInstance . '/server/app/local-custom.txt') === "custom\n", 'unknown files must be preserved');
    updatePlanExpect((string) file_get_contents($applyInstance . '/server/public/storage/upload.txt') === "upload\n", 'public storage must be preserved');
    updatePlanExpect((string) file_get_contents($applyInstance . '/server/docker/.env') === "MYSQL_ROOT_PASSWORD=secret\n", 'private Docker environment must be preserved');
    updatePlanExpect(is_file($applyWorkspace . '/backups/server/app/a.txt'), 'replace backup must include affected old file');
    updatePlanExpect(is_file($applyWorkspace . '/backups/server/app/remove.txt'), 'delete backup must include affected old file');
    updatePlanExpect(!is_file($applyWorkspace . '/backups/server/docker/conf/nginx.conf'), 'unchanged version conf needs no backup');
    updatePlanExpect(is_file($applyInstance . '/server/runtime/upgrade/maintenance.json'), 'applied update must keep maintenance until activation');
    file_put_contents($applyInstance . '/server/app/a.txt', "changed-after-apply\n");
    try {
        PeanutServerUpdatePlan::activate($applyInstance . '/server', $applyWorkspace);
        throw new RuntimeException('changed file was activated');
    } catch (RuntimeException $exception) {
        updatePlanExpect(str_contains($exception->getMessage(), 'changed during update'), 'activation must verify final program bytes');
    }
    updatePlanExpect(is_file($applyInstance . '/server/runtime/upgrade/maintenance.json'), 'failed activation must keep maintenance');
    file_put_contents($applyInstance . '/server/app/a.txt', "new\n");
    try {
        PeanutServerUpdatePlan::activate($applyInstance . '/server', $applyWorkspace);
        throw new RuntimeException('missing private verification was accepted');
    } catch (RuntimeException $exception) {
        updatePlanExpect(str_contains($exception->getMessage(), 'private runtime verification'), 'activation requires private verification');
    }
    updatePlanSealFixture($applyInstance . '/server', $applyWorkspace, 'target');
    $verificationPath = $applyWorkspace . '/verification.json';
    $forged = json_decode((string) file_get_contents($verificationPath), true, 512, JSON_THROW_ON_ERROR);
    $forged['hmac_sha256'] = str_repeat('0', 64);
    upgradePlanFixtureJson($verificationPath, $forged);
    try {
        PeanutServerUpdatePlan::activate($applyInstance . '/server', $applyWorkspace);
        throw new RuntimeException('forged private verification was accepted');
    } catch (RuntimeException $exception) {
        updatePlanExpect(str_contains($exception->getMessage(), 'signature is invalid'), 'activation rejects forged verification');
    }
    updatePlanSealFixture($applyInstance . '/server', $applyWorkspace, 'target');
    $stale = json_decode((string) file_get_contents($verificationPath), true, 512, JSON_THROW_ON_ERROR);
    $stale['verified_at'] = time() - 121;
    upgradePlanFixtureJson($verificationPath, $stale);
    try {
        PeanutServerUpdatePlan::activate($applyInstance . '/server', $applyWorkspace);
        throw new RuntimeException('stale private verification was accepted');
    } catch (RuntimeException $exception) {
        updatePlanExpect(str_contains($exception->getMessage(), 'stale or mismatched'), 'activation rejects stale verification');
    }
    updatePlanSealFixture($applyInstance . '/server', $applyWorkspace, 'target');
    $pendingMaintenance = (string) file_get_contents($applyInstance . '/server/runtime/upgrade/maintenance.json');
    $pendingPointer = (string) file_get_contents($applyInstance . '/server/runtime/upgrade/current-update.json');
    $activated = PeanutServerUpdatePlan::activate($applyInstance . '/server', $applyWorkspace);
    updatePlanExpect(($activated['status'] ?? null) === 'completed', 'activation must complete update');
    updatePlanExpect(($activated['activation_started'] ?? null) === true, 'activation must persist its boundary');
    updatePlanExpect(!file_exists($applyInstance . '/server/runtime/upgrade/maintenance.json'), 'activation must clear maintenance marker');
    updatePlanExpect(is_file($trafficPermit), 'verified activation must restore public permission');
    $replayed = PeanutServerUpdatePlan::begin($applyInstance . '/server', $applyWorkspace);
    updatePlanExpect(($replayed['next_action'] ?? null) === 'none', 'completed update replay must be a no-op');
    updatePlanExpect(!file_exists($applyInstance . '/server/runtime/upgrade/maintenance.json'), 'completed replay must not recreate maintenance');
    // Simulate a crash after maintenance removal but before removing the final pointer.
    $terminalPointer = json_decode($pendingPointer, true, 512, JSON_THROW_ON_ERROR);
    $terminalPointer['status'] = 'completed';
    upgradePlanFixtureJson($applyInstance . '/server/runtime/upgrade/current-update.json', $terminalPointer);
    $terminalTraffic = PeanutServerUpdatePlan::initializeTraffic($applyInstance . '/server');
    updatePlanExpect(
        ($terminalTraffic['status'] ?? null) === 'closed' && !file_exists($trafficPermit),
        'restart must keep a terminal pointer closed until its completion is reconciled',
    );
    $terminalResume = PeanutServerUpdatePlan::begin($applyInstance . '/server', $applyWorkspace);
    updatePlanExpect(
        ($terminalResume['next_action'] ?? null) === 'verify_activate',
        'terminal pointer without maintenance must recreate its own verification window',
    );
    updatePlanExpect(
        is_file($applyInstance . '/server/runtime/upgrade/maintenance.json'),
        'terminal pointer resume must restore maintenance before private verification',
    );
    updatePlanSealFixture($applyInstance . '/server', $applyWorkspace, 'target');
    PeanutServerUpdatePlan::activate($applyInstance . '/server', $applyWorkspace);
    updatePlanExpect(
        !file_exists($applyInstance . '/server/runtime/upgrade/current-update.json') && is_file($trafficPermit),
        'terminal pointer resume must release the pointer and preserve verified public permission',
    );
    upgradePlanFixtureFile($applyInstance . '/server/runtime/upgrade/maintenance.json', $pendingMaintenance, 0600);
    $completedPointer = json_decode($pendingPointer, true, 512, JSON_THROW_ON_ERROR);
    $completedPointer['status'] = 'applied';
    upgradePlanFixtureJson($applyInstance . '/server/runtime/upgrade/current-update.json', $completedPointer);
    $wrongMarker = json_decode($pendingMaintenance, true, 512, JSON_THROW_ON_ERROR);
    $wrongMarker['update_id'] = 'server_update_20260930000000_ffffffffffff';
    upgradePlanFixtureJson($applyInstance . '/server/runtime/upgrade/maintenance.json', $wrongMarker);
    try {
        PeanutServerUpdatePlan::begin($applyInstance . '/server', $applyWorkspace);
        throw new RuntimeException('other task maintenance was accepted');
    } catch (RuntimeException $exception) {
        updatePlanExpect(str_contains($exception->getMessage(), 'not bound to plan'), 'resume must reject another task maintenance');
    }
    upgradePlanFixtureFile($applyInstance . '/server/runtime/upgrade/maintenance.json', $pendingMaintenance, 0600);
    $resume = PeanutServerUpdatePlan::begin($applyInstance . '/server', $applyWorkspace);
    updatePlanExpect(($resume['next_action'] ?? null) === 'verify_activate', 'completed update with maintenance must resume verification');
    $expired = json_decode((string) file_get_contents($verificationPath), true, 512, JSON_THROW_ON_ERROR);
    $expired['verified_at'] = time() - 121;
    upgradePlanFixtureJson($verificationPath, $expired);
    try {
        PeanutServerUpdatePlan::activate($applyInstance . '/server', $applyWorkspace);
        throw new RuntimeException('expired completion receipt was accepted');
    } catch (RuntimeException $exception) {
        updatePlanExpect(str_contains($exception->getMessage(), 'stale or mismatched'), 'completed resume must reject expired receipt');
    }
    set_error_handler(static fn(): bool => true);
    try {
        PeanutServerUpdatePlan::verifyRuntime($applyInstance . '/server', $applyWorkspace, 'target');
        throw new RuntimeException('fixture unexpectedly has native runtime health');
    } catch (Throwable $exception) {
        updatePlanExpect(
            str_contains($exception->getMessage(), 'vendor/autoload.php'),
            'completed pointer must reach native verifier before fixture dependency stops it',
        );
    } finally {
        restore_error_handler();
    }
    $completedPointer['status'] = 'completed';
    upgradePlanFixtureJson($applyInstance . '/server/runtime/upgrade/current-update.json', $completedPointer);
    $resumeCompletedPointer = PeanutServerUpdatePlan::begin($applyInstance . '/server', $applyWorkspace);
    updatePlanExpect(
        ($resumeCompletedPointer['next_action'] ?? null) === 'verify_activate',
        'completed pointer with maintenance must also resume',
    );
    set_error_handler(static fn(): bool => true);
    try {
        PeanutServerUpdatePlan::verifyRuntime($applyInstance . '/server', $applyWorkspace, 'target');
        throw new RuntimeException('fixture unexpectedly has native runtime health');
    } catch (Throwable $exception) {
        updatePlanExpect(
            str_contains($exception->getMessage(), 'vendor/autoload.php'),
            'completed pointer must reach native verifier before fixture dependency stops it',
        );
    } finally {
        restore_error_handler();
    }
    updatePlanSealFixture($applyInstance . '/server', $applyWorkspace, 'target');
    PeanutServerUpdatePlan::activate($applyInstance . '/server', $applyWorkspace);
    updatePlanExpect(!file_exists($applyInstance . '/server/runtime/upgrade/maintenance.json'), 'completed resume must clear maintenance after fresh receipt');
    $deploymentPath = $applyInstance . '/server/private/installation/deployment.json';
    $firstDeployment = json_decode((string) file_get_contents($deploymentPath), true, 512, JSON_THROW_ON_ERROR);
    updatePlanExpect(($firstDeployment['generation'] ?? null) === 1, 'first update must publish deployment generation one');
    try {
        PeanutServerUpdatePlan::recover($applyInstance . '/server', $applyWorkspace);
        throw new RuntimeException('activated update was recovered automatically');
    } catch (RuntimeException $exception) {
        updatePlanExpect(str_contains($exception->getMessage(), 'activation already started')
            || str_contains($exception->getMessage(), 'completed server update'), 'activation must block automatic recovery');
    }

    $firstBaselineDigest = hash_file('sha256', $applyInstance . '/server/private/installation/baseline.json');
    $firstReceiptDigest = hash_file('sha256', $applyInstance . '/server/private/installation/installed.json');
    $secondArchive = updatePlanArchive($root, '1.2.0', [
        'server/app/a.txt' => "next\n",
        'server/app/new.txt' => "added\n",
        'server/composer.lock' => "lock-v1\n",
        'server/docker/conf/nginx.conf' => "old-conf\n",
    ]);
    $secondWorkspace = updatePlanWorkspace($root);
    $secondPlan = PeanutServerUpdatePlan::build($applyInstance . '/server', $secondArchive, (string) hash_file('sha256', $secondArchive), $secondWorkspace);
    updatePlanExpect(($secondPlan['source']['deployment_generation'] ?? null) === 1, 'second plan must bind deployed generation one');
    updatePlanPrepareFixture($applyInstance . '/server', $secondWorkspace);
    PeanutServerUpdatePlan::begin($applyInstance . '/server', $secondWorkspace);
    updatePlanBackupFixture($applyInstance . '/server', $secondWorkspace);
    PeanutServerUpdatePlan::apply($applyInstance . '/server', $secondWorkspace);
    updatePlanMigrationFixture($applyInstance . '/server', $secondWorkspace);
    updatePlanSealFixture($applyInstance . '/server', $secondWorkspace, 'target');
    PeanutServerUpdatePlan::activate($applyInstance . '/server', $secondWorkspace);
    $secondDeployment = json_decode((string) file_get_contents($deploymentPath), true, 512, JSON_THROW_ON_ERROR);
    updatePlanExpect(($secondDeployment['generation'] ?? null) === 2, 'second update must publish deployment generation two');
    updatePlanExpect(hash_file('sha256', $applyInstance . '/server/private/installation/baseline.json') === $firstBaselineDigest, 'initial baseline must remain unchanged');
    updatePlanExpect(hash_file('sha256', $applyInstance . '/server/private/installation/installed.json') === $firstReceiptDigest, 'installation receipt must remain unchanged');
    $stateBytes = (string) file_get_contents($deploymentPath);
    $wrongState = json_decode($stateBytes, true, 512, JSON_THROW_ON_ERROR);
    $wrongState['current_identity_sha256'] = str_repeat('0', 64);
    upgradePlanFixtureJson($deploymentPath, $wrongState);
    try {
        PeanutServerUpdatePlan::build($applyInstance . '/server', $secondArchive, (string) hash_file('sha256', $secondArchive), updatePlanWorkspace($root));
        throw new RuntimeException('unknown current deployment identity was accepted');
    } catch (RuntimeException $exception) {
        updatePlanExpect(str_contains($exception->getMessage(), 'current deployment identity is invalid'), 'unknown deployment identity must fail closed');
    }
    file_put_contents($deploymentPath, $stateBytes);
    $thirdArchive = updatePlanArchive($root, '1.3.0', [
        'server/app/a.txt' => "third\n",
        'server/app/new.txt' => "added\n",
        'server/composer.lock' => "lock-v1\n",
        'server/docker/conf/nginx.conf' => "old-conf\n",
    ]);
    $thirdPlan = PeanutServerUpdatePlan::build($applyInstance . '/server', $thirdArchive, (string) hash_file('sha256', $thirdArchive), updatePlanWorkspace($root));
    updatePlanExpect(($thirdPlan['source']['deployment_generation'] ?? null) === 2, 'third plan must bind deployed generation two');

    $modeInstance = $root . '/mode-instance';
    updatePlanWriteServer($modeInstance, '1.0.0', $currentFiles);
    $modeArchive = updatePlanArchive($root, '1.1.0', $currentFiles + [
        'server/app/deep/nested/new.txt' => "nested\n",
    ], null, ['server/app/a.txt' => 0755]);
    $modeWorkspace = updatePlanWorkspace($root);
    $modePlan = PeanutServerUpdatePlan::build($modeInstance . '/server', $modeArchive, (string) hash_file('sha256', $modeArchive), $modeWorkspace);
    $modeOps = array_column($modePlan['operations'], null, 'path');
    updatePlanExpect(($modeOps['server/app/a.txt']['current_mode'] ?? null) === 0644
        && ($modeOps['server/app/a.txt']['target_mode'] ?? null) === 0755, 'mode-only operation must bind both modes');
    updatePlanPrepareFixture($modeInstance . '/server', $modeWorkspace);
    PeanutServerUpdatePlan::begin($modeInstance . '/server', $modeWorkspace);
    updatePlanBackupFixture($modeInstance . '/server', $modeWorkspace);
    PeanutServerUpdatePlan::apply($modeInstance . '/server', $modeWorkspace);
    updatePlanMigrationFixture($modeInstance . '/server', $modeWorkspace);
    updatePlanExpect((fileperms($modeInstance . '/server/app/a.txt') & 0777) === 0755, 'same-byte program update must apply target mode');
    updatePlanExpect((fileperms($modeInstance . '/server/app/deep') & 0777) === 0755
        && (fileperms($modeInstance . '/server/app/deep/nested') & 0777) === 0755, 'nested new program directories must be traversable');
    updatePlanExpect(is_file($modeWorkspace . '/backups/server/app/a.txt'), 'mode-only update must retain old file backup');
    chmod($modeInstance . '/server/app/a.txt', 0644);
    try {
        PeanutServerUpdatePlan::activate($modeInstance . '/server', $modeWorkspace);
        throw new RuntimeException('mode drift was activated');
    } catch (RuntimeException $exception) {
        updatePlanExpect(str_contains($exception->getMessage(), 'changed during update'), 'activation must reject mode drift');
    }
    chmod($modeInstance . '/server/app/a.txt', 0755);
    updatePlanDatabaseRecoveryFixture($modeInstance . '/server', $modeWorkspace);
    PeanutServerUpdatePlan::recover($modeInstance . '/server', $modeWorkspace);
    updatePlanExpect((fileperms($modeInstance . '/server/app/a.txt') & 0777) === 0644, 'recovery must restore original program mode');
    updatePlanExpect(!file_exists($modeInstance . '/server/app/deep/nested/new.txt'), 'recovery must remove nested added file');

    $lockedInstance = $root . '/locked-instance';
    updatePlanWriteServer($lockedInstance, '1.0.0', $currentFiles + ['server/docker/conf/nginx.conf' => "old-conf\n"]);
    $lockedWorkspace = updatePlanWorkspace($root);
    PeanutServerUpdatePlan::build($lockedInstance . '/server', $applyArchive, (string) hash_file('sha256', $applyArchive), $lockedWorkspace);
    updatePlanPrepareFixture($lockedInstance . '/server', $lockedWorkspace);
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
    updatePlanWriteServer($interruptedInstance, '1.0.0', $currentFiles + ['server/docker/conf/nginx.conf' => "old-conf\n"]);
    $interruptedWorkspace = updatePlanWorkspace($root);
    PeanutServerUpdatePlan::build(
        $interruptedInstance . '/server',
        $applyArchive,
        (string) hash_file('sha256', $applyArchive),
        $interruptedWorkspace,
    );
    updatePlanPrepareFixture($interruptedInstance . '/server', $interruptedWorkspace);
    PeanutServerUpdatePlan::begin($interruptedInstance . '/server', $interruptedWorkspace);
    updatePlanBackupFixture($interruptedInstance . '/server', $interruptedWorkspace);
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
    updatePlanDatabaseRecoveryFixture($interruptedInstance . '/server', $interruptedWorkspace);
    $recovery = PeanutServerUpdatePlan::recover($interruptedInstance . '/server', $interruptedWorkspace);
    updatePlanExpect(($recovery['status'] ?? null) === 'recovered', 'recover must complete from journal');
    updatePlanExpect((string) file_get_contents($interruptedInstance . '/server/app/a.txt') === "old\n", 'recover must restore replaced file');
    updatePlanExpect(!file_exists($interruptedInstance . '/server/app/new.txt'), 'recover must clear file added by failed update');
    updatePlanExpect((string) file_get_contents($interruptedInstance . '/server/app/remove.txt') === "remove\n", 'recover must restore deleted file');
    updatePlanExpect(is_file($interruptedInstance . '/server/runtime/upgrade/maintenance.json'), 'recovered update must keep maintenance until runtime verification');
    updatePlanSealFixture($interruptedInstance . '/server', $interruptedWorkspace, 'source');
    PeanutServerUpdatePlan::finishRecovery($interruptedInstance . '/server', $interruptedWorkspace);
    updatePlanExpect(!file_exists($interruptedInstance . '/server/runtime/upgrade/maintenance.json'), 'recovery finish must clear maintenance marker');
    updatePlanExpect(
        is_file($interruptedInstance . '/server/runtime/upgrade/.traffic-ready'),
        'verified recovery finish must restore public permission',
    );

    $unsafeTrafficInstance = $root . '/unsafe-traffic-instance';
    updatePlanWriteServer($unsafeTrafficInstance, '1.0.0', $currentFiles);
    PeanutServerUpdatePlan::initializeTraffic($unsafeTrafficInstance . '/server');
    $unsafeInitialized = $unsafeTrafficInstance . '/server/runtime/upgrade/.traffic-initialized';
    unlink($unsafeInitialized);
    symlink('missing-initialization-state', $unsafeInitialized);
    try {
        PeanutServerUpdatePlan::initializeTraffic($unsafeTrafficInstance . '/server');
        throw new RuntimeException('symbolic traffic state was accepted');
    } catch (RuntimeException $exception) {
        updatePlanExpect(
            str_contains($exception->getMessage(), 'traffic initialization state'),
            'unknown traffic initialization state must fail closed',
        );
    }
    updatePlanExpect(
        !file_exists($unsafeTrafficInstance . '/server/runtime/upgrade/.traffic-ready'),
        'unknown traffic initialization state must revoke old permission',
    );

    $invalidStartupInstance = $root . '/invalid-startup-identity';
    updatePlanWriteServer($invalidStartupInstance, '1.0.0', $currentFiles);
    PeanutServerUpdatePlan::initializeTraffic($invalidStartupInstance . '/server');
    upgradePlanFixtureFile($invalidStartupInstance . '/server/.peanut/release-identity.json', "{invalid-json\n", 0644);
    try {
        PeanutServerUpdatePlan::initializeTraffic($invalidStartupInstance . '/server');
        throw new RuntimeException('invalid startup release identity was accepted');
    } catch (JsonException) {
        updatePlanExpect(
            !file_exists($invalidStartupInstance . '/server/runtime/upgrade/.traffic-ready'),
            'invalid startup identity must revoke an earlier public permission before failing',
        );
    }

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
    updatePlanPrepareFixture($migrationInstance . '/server', $migrationWorkspace);
    try {
        PeanutServerUpdatePlan::apply($migrationInstance . '/server', $migrationWorkspace);
        throw new RuntimeException('migration update bypassed recovery point');
    } catch (RuntimeException $exception) {
        updatePlanExpect(str_contains($exception->getMessage(), 'recovery point'), 'migration plan must require a signed recovery point before file apply');
    }
    updatePlanExpect((string) file_get_contents($migrationInstance . '/server/app/a.txt') === "old\n", 'migration gate must not modify program files');
    updatePlanExpect(is_file($migrationInstance . '/server/runtime/upgrade/maintenance.json'), 'missing recovery point must retain closed maintenance after begin');
    $migrationPlanPath = $migrationWorkspace . '/plan.json';
    $migrationPlan = json_decode((string) file_get_contents($migrationPlanPath), true, 512, JSON_THROW_ON_ERROR);
    $migrationPlan['requirements']['database_migrations_changed'] = false;
    upgradePlanFixtureJson($migrationPlanPath, $migrationPlan);
    try {
        PeanutServerUpdatePlan::begin($migrationInstance . '/server', $migrationWorkspace);
        throw new RuntimeException('false migration requirement was accepted');
    } catch (RuntimeException $exception) {
        updatePlanExpect(str_contains($exception->getMessage(), 'target dependency preparation differs from update plan'), 'changed migration requirement must fail preparation binding');
    }
    updatePlanExpect(is_file($migrationInstance . '/server/runtime/upgrade/maintenance.json'), 'false migration requirement must retain existing maintenance');

    $dependencyInstance = $root . '/dependency-instance';
    updatePlanWriteServer($dependencyInstance, '1.0.0', $currentFiles);
    $dependencyWorkspace = updatePlanWorkspace($root);
    PeanutServerUpdatePlan::build($dependencyInstance . '/server', $archive, (string) hash_file('sha256', $archive), $dependencyWorkspace);
    try {
        PeanutServerUpdatePlan::apply($dependencyInstance . '/server', $dependencyWorkspace);
        throw new RuntimeException('composer dependency update bypassed preparation');
    } catch (RuntimeException $exception) {
        updatePlanExpect(str_contains($exception->getMessage(), 'target dependency preparation'), 'changed Composer lock must require preparation receipt');
    }
    updatePlanExpect((string) file_get_contents($dependencyInstance . '/server/composer.lock') === "lock-v1\n", 'composer gate must not modify lock file');

    $environmentInstance = $root . '/environment-instance';
    updatePlanWriteServer($environmentInstance, '1.0.0', $currentFiles);
    $environmentArchive = updatePlanArchive($root, '1.1.0', $targetFilesNoDependencyChange + [
        'server/docker/conf/nginx.conf' => "new-conf\n",
    ]);
    $environmentWorkspace = updatePlanWorkspace($root);
    $environmentPlan = PeanutServerUpdatePlan::build(
        $environmentInstance . '/server',
        $environmentArchive,
        (string) hash_file('sha256', $environmentArchive),
        $environmentWorkspace,
    );
    updatePlanPrepareFixture($environmentInstance . '/server', $environmentWorkspace);
    updatePlanExpect(
        ($environmentPlan['requirements']['runtime_environment_changed'] ?? null) === true,
        'changed runtime environment must retain its plan flag',
    );
    try {
        PeanutServerUpdatePlan::begin($environmentInstance . '/server', $environmentWorkspace);
        throw new RuntimeException('unprepared runtime environment was accepted');
    } catch (RuntimeException $exception) {
        updatePlanExpect(
            str_contains($exception->getMessage(), 'runtime environment changed without an independently prepared compatible image set'),
            'runtime environment gate must fail before stop',
        );
    }
    updatePlanExpect(
        !file_exists($environmentInstance . '/server/runtime/upgrade/maintenance.json'),
        'runtime environment gate must not start maintenance',
    );
    $environmentModeInstance = $root . '/environment-mode-instance';
    updatePlanWriteServer($environmentModeInstance, '1.0.0', $currentFiles + [
        'server/docker/conf/nginx.conf' => "old-conf\n",
    ]);
    $environmentModeArchive = updatePlanArchive($root, '1.1.0', $targetFilesNoDependencyChange + [
        'server/docker/conf/nginx.conf' => "old-conf\n",
    ], null, ['server/docker/conf/nginx.conf' => 0755]);
    $environmentModeWorkspace = updatePlanWorkspace($root);
    $environmentModePlan = PeanutServerUpdatePlan::build(
        $environmentModeInstance . '/server',
        $environmentModeArchive,
        (string) hash_file('sha256', $environmentModeArchive),
        $environmentModeWorkspace,
    );
    updatePlanPrepareFixture($environmentModeInstance . '/server', $environmentModeWorkspace);
    updatePlanExpect(
        ($environmentModePlan['requirements']['runtime_environment_changed'] ?? null) === true,
        'runtime config mode change must not be classified as unchanged',
    );
    try {
        PeanutServerUpdatePlan::begin($environmentModeInstance . '/server', $environmentModeWorkspace);
        throw new RuntimeException('runtime config mode change was accepted');
    } catch (RuntimeException $exception) {
        updatePlanExpect(
            str_contains($exception->getMessage(), 'runtime environment changed without an independently prepared compatible image set'),
            'mode-only runtime environment change must fail before stop',
        );
    }

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

    $uninstalledInstance = $root . '/uninstalled-instance';
    updatePlanWriteServer($uninstalledInstance, '1.0.0', $currentFiles);
    unlink($uninstalledInstance . '/server/private/installation/installed.json');
    try {
        PeanutServerUpdatePlan::build($uninstalledInstance . '/server', $applyArchive, (string) hash_file('sha256', $applyArchive), updatePlanWorkspace($root));
        throw new RuntimeException('uninstalled instance was accepted for update');
    } catch (RuntimeException $exception) {
        updatePlanExpect(str_contains($exception->getMessage(), 'installed instance receipt'), 'update requires a real installed receipt');
    }

    $driftInstance = $root . '/drift-instance';
    updatePlanWriteServer($driftInstance, '1.0.0', $currentFiles + ['server/docker/conf/nginx.conf' => "old-conf\n"]);
    $driftWorkspace = updatePlanWorkspace($root);
    PeanutServerUpdatePlan::build($driftInstance . '/server', $applyArchive, (string) hash_file('sha256', $applyArchive), $driftWorkspace);
    updatePlanPrepareFixture($driftInstance . '/server', $driftWorkspace);
    file_put_contents($driftInstance . '/server/.peanut/release-identity.json', "{}\n");
    try {
        PeanutServerUpdatePlan::begin($driftInstance . '/server', $driftWorkspace);
        throw new RuntimeException('changed source identity was accepted');
    } catch (RuntimeException $exception) {
        updatePlanExpect(str_contains($exception->getMessage(), 'differs from update plan'), 'begin must bind exact source identity');
    }
    updatePlanExpect(!file_exists($driftInstance . '/server/runtime/upgrade/maintenance.json'), 'source drift must fail before maintenance');

    $planDriftInstance = $root . '/plan-drift-instance';
    updatePlanWriteServer($planDriftInstance, '1.0.0', $currentFiles + ['server/docker/conf/nginx.conf' => "old-conf\n"]);
    $planDriftWorkspace = updatePlanWorkspace($root);
    PeanutServerUpdatePlan::build($planDriftInstance . '/server', $applyArchive, (string) hash_file('sha256', $applyArchive), $planDriftWorkspace);
    updatePlanPrepareFixture($planDriftInstance . '/server', $planDriftWorkspace);
    PeanutServerUpdatePlan::begin($planDriftInstance . '/server', $planDriftWorkspace);
    $changedPlan = json_decode((string) file_get_contents($planDriftWorkspace . '/plan.json'), true, 512, JSON_THROW_ON_ERROR);
    $changedPlan['note'] = 'changed after begin';
    upgradePlanFixtureJson($planDriftWorkspace . '/plan.json', $changedPlan);
    $changedPreparation = json_decode((string) file_get_contents($planDriftWorkspace . '/preparation.json'), true, 512, JSON_THROW_ON_ERROR);
    $changedPreparation['plan_sha256'] = hash_file('sha256', $planDriftWorkspace . '/plan.json');
    upgradePlanFixtureJson($planDriftWorkspace . '/preparation.json', $changedPreparation);
    try {
        PeanutServerUpdatePlan::apply($planDriftInstance . '/server', $planDriftWorkspace);
        throw new RuntimeException('changed plan was accepted after journal creation');
    } catch (RuntimeException $exception) {
        updatePlanExpect(str_contains($exception->getMessage(), 'journal differs from plan'), 'changed plan must fail before file apply');
    }
    updatePlanExpect((string) file_get_contents($planDriftInstance . '/server/app/a.txt') === "old\n", 'changed plan must not modify source files');

    $parentLinkInstance = $root . '/parent-link-instance';
    updatePlanWriteServer($parentLinkInstance, '1.0.0', $currentFiles + ['server/docker/conf/nginx.conf' => "old-conf\n"]);
    $parentLinkWorkspace = updatePlanWorkspace($root);
    PeanutServerUpdatePlan::build($parentLinkInstance . '/server', $applyArchive, (string) hash_file('sha256', $applyArchive), $parentLinkWorkspace);
    updatePlanPrepareFixture($parentLinkInstance . '/server', $parentLinkWorkspace);
    $originalApp = $parentLinkInstance . '/server/app-original';
    rename($parentLinkInstance . '/server/app', $originalApp);
    $outsideApp = $root . '/outside-app';
    mkdir($outsideApp, 0700);
    file_put_contents($outsideApp . '/a.txt', "outside\n");
    symlink($outsideApp, $parentLinkInstance . '/server/app');
    try {
        PeanutServerUpdatePlan::begin($parentLinkInstance . '/server', $parentLinkWorkspace);
        throw new RuntimeException('parent symlink was accepted after planning');
    } catch (RuntimeException $exception) {
        updatePlanExpect(str_contains($exception->getMessage(), 'parent is unsafe'), 'changed parent symlink must fail before maintenance');
    }
    updatePlanExpect((string) file_get_contents($outsideApp . '/a.txt') === "outside\n", 'update must not write through parent symlink');
    updatePlanExpect(!file_exists($parentLinkInstance . '/server/runtime/upgrade/maintenance.json'), 'parent symlink must fail before maintenance');

    echo "SERVER-UPDATE-PLAN passed assertions={$updatePlanAssertions}\n";
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
