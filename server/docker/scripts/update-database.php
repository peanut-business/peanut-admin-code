#!/usr/bin/env php
<?php

declare(strict_types=1);

require_once __DIR__ . '/update-plan.php';

/** Requiring the host never runs a phase; application-only callers share its admission. */
function peanutServerDatabaseStage(string $operation, string $server, string $workspace, string $scope = 'server-release'): array
{
    if (!in_array($operation, ['preflight', 'migrate', 'verify'], true)
        || !in_array($scope, ['server-release', 'application-schema'], true)) { throw new RuntimeException('invalid database stage'); }
    if (!str_starts_with($server, '/') || !str_starts_with($workspace, '/')
        || realpath($server) !== $server || realpath($workspace) !== $workspace) {
        throw new RuntimeException('server and workspace must be canonical directories');
    }
    $planPath = $workspace . '/plan.json';
    require_once __DIR__ . '/update-plan.php';
    [$product, $productState] = PeanutServerUpdatePlan::productBinding($workspace);
    $plan = json_decode((string) file_get_contents($planPath), true, 512, JSON_THROW_ON_ERROR);
    $planSha = hash_file('sha256', $planPath);
    if (($product['scope'] ?? null) !== 'server' || $product['instance_root'] . '/server' !== $server
        || $product['inputs_sha256'] !== $planSha || $product['candidate'] !== $plan['update_id']) {
        throw new RuntimeException('database phase inputs differ from the product plan');
    }
    $targetServer = $operation === 'preflight' ? $workspace . '/prepared/server' : $server;
    $runtimeServer = $scope === 'application-schema' && class_exists(\think\App::class, false) ? $server : $targetServer;
    [$app, $config, $pdo] = peanutServerDatabaseMaster($runtimeServer);
    require_once $runtimeServer . '/database/install.php';
    if ($operation === 'preflight') {
        if ($productState['phase_in_progress'] !== 'migration-preflight') { throw new RuntimeException('product preflight phase is not active'); }
        $prepared = $workspace . '/prepared/server';
        $identity = json_decode((string) file_get_contents($prepared . '/.peanut/release-identity.json'), true, 512, JSON_THROW_ON_ERROR);
        if (hash_file('sha256', $prepared . '/.peanut/release-identity.json') !== $plan['target']['identity_sha256']) { throw new RuntimeException('prepared migration identity differs'); }
        $files = [];
        foreach ($identity['files'] as $row) {
            if (preg_match('#^server/database/migrations/[0-9]{8}-[a-z0-9][a-z0-9_-]*\.sql$#D', $row['path']) !== 1) { continue; }
            $file = $workspace . '/prepared/' . $row['path'];
            if (!is_file($file) || is_link($file) || hash_file('sha256', $file) !== $row['sha256']) { throw new RuntimeException('prepared migration changed'); }
            $files[] = $file;
        }
        sort($files, SORT_STRING);
        $config = guardedDatabaseConfig();
        $runner = new \app\common\services\upgrade\ApplicationMigrationRunner($config, $pdo);
        $target = applicationMigrationTargetVersion($prepared, applicationReleaseVersions($prepared));
        $result = $runner->run($files, $target, $identity['application']['version'], true);
        $proofs = peanutServerDatabasePrestates($pdo, $files, $result);
        $modules = PeanutServerUpdatePlan::withServerApplicationPlanning($server, $workspace, $pdo, $files, $result,
            static fn(): array => $scope === 'server-release'
                ? \app\command\PluginReconcile::planInProcess($app, $pdo, $server) : []);
        if ($scope === 'application-schema') {
            return ['status' => 'completed', 'scope' => $scope, 'operation' => 'preflight', 'application' => $result,
                'prestates' => $proofs, 'plugins' => 'not_run', 'product_database' => 'not_verified', 'execution_admitted' => false];
        }
        $reportSha = is_array($modules) ? ($modules['report_sha256'] ?? null) : null;
        $unsigned = is_array($modules) ? $modules : [];
        unset($unsigned['report_sha256']);
        $deferred = ($modules['may_apply'] ?? null) === false && ($modules['status'] ?? null) === 'planned'
            && ($modules['planning_complete'] ?? null) === true && ($modules['execution_admitted'] ?? null) === false;
        if (!is_array($modules) || ($modules['schema_version'] ?? null) !== 1
            || ($modules['protocol'] ?? null) !== 'peanut.plugin-reconcile-plan.v1'
            || ($modules['scope'] ?? null) !== 'release-locked' || ($modules['readonly'] ?? null) !== true
            || ($modules['complete'] ?? null) !== true
            || (!$deferred && (($modules['status'] ?? null) !== 'ready' || ($modules['may_apply'] ?? null) !== true))
            || ($modules['target_server_root'] ?? null) !== $prepared || ($modules['current_server_root'] ?? null) !== $server
            || ($modules['target_lock_sha256'] ?? null) !== hash_file('sha256', $prepared . '/plugins.lock')
            || !is_string($reportSha) || !hash_equals($reportSha, hash('sha256', peanutServerDatabaseCanonical($unsigned)))
            || !is_array($modules['packages'] ?? null) || !array_is_list($modules['packages'])) {
            throw new RuntimeException('module plan is blocked, unknown or mismatched');
        }
        foreach ($modules['packages'] as $item) {
            if ((!$deferred && ($item['may_apply'] ?? null) !== true) || !in_array($item['action'] ?? null,
                ['planned_install', 'planned_upgrade', 'planned_unchanged', 'preserve_disabled'], true)) {
                throw new RuntimeException('module plan contains an unapproved action');
            }
        }
        peanutServerDatabaseDeferredPlan($modules, $files, $deferred);
        $selected = array_column($modules['packages'], 'key');
        $ordered = $selected;
        sort($ordered, SORT_STRING);
        if (($modules['selected_package_keys'] ?? null) !== $selected || $selected !== $ordered
            || count(array_unique($selected)) !== count($selected)
            || ($modules['action'] ?? null) !== ($selected === [] ? 'planned_noop' : 'planned_reconcile')) {
            throw new RuntimeException('module plan selection is incomplete');
        }
        $moduleEvidence = ['schema_version' => 1, 'protocol' => 'peanut.upgrade-module-preflight.v1',
            'candidate' => $product['candidate'], 'product_plan_sha256' => $product['plan_sha256'],
            'native_inputs_sha256' => $planSha, 'target_identity_sha256' => $plan['target']['identity_sha256'],
            'target_composer_lock_sha256' => hash_file('sha256', $prepared . '/composer.lock'),
            'database_identity' => ['resource_id' => requiredEnvironment('PEANUT_DATABASE_RESOURCE_ID'),
                'endpoint_id' => requiredEnvironment('PEANUT_DATABASE_ENDPOINT_ID'), 'database' => (string) $config['database']],
            'may_apply' => !$deferred, 'planning_complete' => true, 'execution_admitted' => false,
            'application_prerequisites' => $modules['application_prerequisites'] ?? [], 'plan' => $modules];
        $moduleEvidence['report_sha256'] = hash('sha256', peanutServerDatabaseCanonical($moduleEvidence));
        return ['status' => 'completed', 'operation' => 'preflight', 'scope' => $scope, 'candidate' => $product['candidate'],
            'application' => $result, 'prestates' => $proofs, 'modules' => $moduleEvidence];
    }
    return PeanutServerUpdatePlan::withDatabaseMigration($server, $workspace, $operation, $scope, $pdo,
        static function (array $facts) use ($app, $config, $pdo, $scope, $server, $workspace, $operation, $plan, $planSha, $productState): array {
    $verificationKey = PeanutServerUpdatePlan::databaseVerificationKey($server);
    $assertSigned = static function (string $path) use ($verificationKey): void {
        $signaturePath = $path . '.hmac';
        foreach ([$path, $signaturePath] as $file) {
            $stat = lstat($file);
            if (!is_file($file) || is_link($file) || !is_array($stat)
                || ($stat['nlink'] ?? 0) !== 1 || ($stat['mode'] & 0777) !== 0600) {
                throw new RuntimeException('signed update result is unsafe');
            }
        }
        $actual = trim((string) file_get_contents($signaturePath));
        if (preg_match('/^[a-f0-9]{64}$/D', $actual) !== 1
            || !hash_equals(hash_hmac('sha256', (string) file_get_contents($path), $verificationKey), $actual)) {
            throw new RuntimeException('signed update result differs from instance');
        }
    };
    if (($plan['protocol'] ?? null) !== 'peanut.server-release-inputs.v1'
        || $productState['database_recovery_required'] !== true
        || !isset($productState['evidence']['managed_files'])
        || ($operation === 'migrate' && ($productState['phase_in_progress'] !== 'migrate' || $productState['activation_started'] !== false))
        || ($operation === 'verify' && $productState['phase_in_progress'] !== 'migration-verify')) {
        throw new RuntimeException('migration is not bound to the product phase');
    }
    if (!$productState['activation_started']) {
        $maintenance = json_decode((string) file_get_contents($server . '/runtime/upgrade/maintenance.json'), true, 128, JSON_THROW_ON_ERROR);
        if (($maintenance['update_id'] ?? null) !== $plan['update_id'] || ($maintenance['status'] ?? null) !== 'active') {
            throw new RuntimeException('migration requires the matching maintenance marker');
        }
    }
    $identityPath = $server . '/.peanut/release-identity.json';
    if (hash_file('sha256', $identityPath) !== ($plan['target']['identity_sha256'] ?? null)) {
        throw new RuntimeException('migration target identity differs from plan');
    }
    $identity = json_decode((string) file_get_contents($identityPath), true, 512, JSON_THROW_ON_ERROR);
    $files = [];
    foreach ($identity['files'] as $row) {
        $path = $row['path'] ?? '';
        if (preg_match('#^server/database/migrations/[0-9]{8}-[a-z0-9][a-z0-9_-]*\.sql$#D', $path) !== 1) {
            continue;
        }
        $file = $server . '/' . substr($path, 7);
        if (!is_file($file) || is_link($file) || hash_file('sha256', $file) !== $row['sha256']) {
            throw new RuntimeException('release migration file differs from manifest: ' . $path);
        }
        $files[] = $file;
    }
    sort($files, SORT_STRING);
    putenv('PEANUT_SERVER_ENV_FILE=' . $server . '/.env');
    require_once $server . '/database/environment-guard.php';
    require_once $server . '/app/common/services/upgrade/ApplicationMigrationRunner.php';
    $config = guardedDatabaseConfig();
    $databaseIdentity = [
        'resource_id' => requiredEnvironment('PEANUT_DATABASE_RESOURCE_ID'),
        'endpoint_id' => requiredEnvironment('PEANUT_DATABASE_ENDPOINT_ID'),
        'database' => (string) $config['database'],
    ];
    $backup = json_decode((string) file_get_contents($workspace . '/backup.json'), true, 128, JSON_THROW_ON_ERROR);
    $assertSigned($workspace . '/backup.json');
    $backupDatabaseIdentity = $backup['database_identity'] ?? null;
    if (!is_array($backupDatabaseIdentity)) {
        throw new RuntimeException('migration recovery database identity is invalid');
    }
    ksort($backupDatabaseIdentity, SORT_STRING);
    ksort($databaseIdentity, SORT_STRING);
    if (($backup['plan_sha256'] ?? null) !== $planSha
        || ($backup['update_id'] ?? null) !== $plan['update_id']
        || $backupDatabaseIdentity !== $databaseIdentity
        || ($backup['snapshots']['private/installation/installed.json'] ?? null) !== $plan['source']['installed_receipt_sha256']
        || ($backup['snapshots']['private/installation/baseline.json'] ?? null) !== $plan['source']['baseline_sha256']
        || ($backup['database_dump_sha256'] ?? null) !== hash_file('sha256', $workspace . '/recovery/database.sql.gz')) {
        throw new RuntimeException('migration database differs from the bound recovery point');
    }
    // The verified native default master remains the only server-update PDO.
    $uuid = strtolower((string) $pdo->query('SELECT @@server_uuid')->fetchColumn());
    if ($uuid === '' || $uuid !== ($backup['mysql_identity']['server_uuid'] ?? null)) {
        throw new RuntimeException('migration is connected to another MySQL server');
    }
    $runner = new \app\common\services\upgrade\ApplicationMigrationRunner([
        'host' => (string) $config['host'], 'port' => (string) $config['port'],
        'database' => (string) $config['database'], 'user' => (string) $config['user'],
        'password' => (string) $config['password'],
    ], $pdo);
    $version = (string) $identity['application']['version'];
    require_once $server . '/database/install.php';
    $migrationTargetVersion = applicationMigrationTargetVersion($server, applicationReleaseVersions($server));
    $migrationPath = $workspace . ($scope === 'server-release' ? '/migration.json' : '/application-schema.json');
    $migrationProtocol = $scope === 'server-release' ? 'peanut.server-update-migration.v1' : 'peanut.application-schema-result.v1';
    $replayed = false;
    $previous = null;
    if ($operation === 'migrate') {
        if (file_exists($migrationPath) || is_link($migrationPath)) {
            $assertSigned($migrationPath);
            $previous = json_decode((string) file_get_contents($migrationPath), true, 128, JSON_THROW_ON_ERROR);
            if (($previous['protocol'] ?? null) !== $migrationProtocol
                || ($previous['scope'] ?? null) !== $scope
                || ($scope === 'server-release' && (($previous['plugins'] ?? null) !== 'reconciled' || ($previous['product_database'] ?? null) !== 'migrated'))
                || ($previous['status'] ?? null) !== 'completed' || ($previous['update_id'] ?? null) !== $plan['update_id']
                || ($previous['plan_sha256'] ?? null) !== $planSha
                || ($previous['backup_sha256'] ?? null) !== hash_file('sha256', $workspace . '/backup.json')) {
                throw new RuntimeException('migration has an unknown result; inspect ledger or recover this update');
            }
            $replayed = true;
        }
        if (!$replayed) {
            $selection = $runner->run($files, $migrationTargetVersion, $version, true);
            peanutServerDatabasePrestates($pdo, $files, $selection);
            $intent = ['protocol' => $migrationProtocol, 'scope' => $scope, 'status' => 'started',
                'update_id' => $plan['update_id'], 'plan_sha256' => $planSha,
                'database_identity' => $databaseIdentity, 'backup_sha256' => hash_file('sha256', $workspace . '/backup.json')];
            $bytes = json_encode($intent, JSON_THROW_ON_ERROR);
            $stream = fopen($migrationPath, 'x');
            if ($stream === false || !chmod($migrationPath, 0600)
                || fwrite($stream, $bytes) !== strlen($bytes) || !fflush($stream) || !fsync($stream)) {
                throw new RuntimeException('migration intent could not be persisted');
            }
            fclose($stream);
        }
    } else {
        $assertSigned($migrationPath);
        $previous = json_decode((string) file_get_contents($migrationPath), true, 128, JSON_THROW_ON_ERROR);
        if (($previous['protocol'] ?? null) !== $migrationProtocol
            || ($previous['scope'] ?? null) !== $scope
                || ($scope === 'server-release' && (($previous['plugins'] ?? null) !== 'reconciled' || ($previous['product_database'] ?? null) !== 'migrated'))
                || ($previous['status'] ?? null) !== 'completed'
            || ($previous['update_id'] ?? null) !== $plan['update_id']
            || ($previous['plan_sha256'] ?? null) !== $planSha
            || ($previous['backup_sha256'] ?? null) !== hash_file('sha256', $workspace . '/backup.json')) {
            throw new RuntimeException('native migration result is missing or bound to another recovery point');
        }
    }
    $modules = null;
    $readOnly = $operation === 'verify' || $replayed;
    $businessBefore = []; $businessPoststates = [];
    if ($readOnly) {
        \app\common\services\upgrade\ApplicationSchemaHost::assertMenuPoststates(
            $previous['application']['applied'] ?? [], [], true);
        \app\common\services\upgrade\ApplicationSchemaHost::assertPermissionPoststates(
            $previous['application']['applied'] ?? [], [], true);
    }
    $migration = $runner->run($files, $migrationTargetVersion, $version, $readOnly,
        $readOnly ? null : static function (PDO $actual, array $migration) use ($pdo, $scope, &$businessBefore): bool {
            if ($actual !== $pdo) { throw new RuntimeException('native migration master changed'); }
            PeanutServerUpdatePlan::assertDatabaseMigrationStage($pdo);
            if ($scope === 'server-release') { PeanutServerUpdatePlan::databaseMigrationAdmission($pdo); }
            $before = \app\common\services\upgrade\ApplicationSchemaHost::menuMigrationBefore($pdo, $migration);
            if ($before !== null) {
                $businessBefore[$migration['id']] = $before;
                return false;
            }
            $before = \app\common\services\upgrade\ApplicationSchemaHost::permissionMigrationBefore($pdo, $migration);
            if ($before !== null) {
                $businessBefore[$migration['id']] = $before;
                return $migration['id'] !== '20261012-align-platform-ops-permission-ownership';
            }
            \app\common\services\upgrade\ApplicationSchemaHost::migrationPrestate($pdo, $migration, [], false);
            return false;
        },
        static function (PDO $actual, array $migration) use ($pdo, $app, $scope, $readOnly, $previous, &$modules, &$businessPoststates): void {
            if ($actual !== $pdo || $migration['pending'] !== []) { throw new RuntimeException('application migration ledger is incomplete'); }
            PeanutServerUpdatePlan::assertDatabaseMigrationStage($pdo);
            \app\common\services\upgrade\ApplicationSchemaHost::assertMenuPoststates($migration['applied'], $businessPoststates, false);
            \app\common\services\upgrade\ApplicationSchemaHost::assertPermissionPoststates($migration['applied'], $businessPoststates, false);
            if ($scope !== 'server-release') { return; }
            if (!$readOnly) { $modules = \app\command\PluginReconcile::reconcileInProcess($app, $pdo); }
            if ((int) $pdo->query("SELECT COUNT(*) FROM pa_module_migration WHERE status <> 'applied'")->fetchColumn() !== 0) {
                throw new RuntimeException('module migration ledger is incomplete');
            }
            if ($readOnly && ($previous['module_state_sha256'] ?? null) !== peanutServerDatabaseModuleState($pdo)) {
                throw new RuntimeException('module migration or installation state changed');
            }
        }, $readOnly ? null : static function (PDO $actual, array $migration, ?array $executionBounds) use ($pdo, $scope, &$businessBefore, &$businessPoststates): void {
            if ($actual !== $pdo) { throw new RuntimeException('native migration master changed'); }
            PeanutServerUpdatePlan::assertDatabaseMigrationStage($pdo);
            if ($scope === 'server-release') { PeanutServerUpdatePlan::databaseMigrationAdmission($pdo); }
            if (isset($businessBefore[$migration['id']])) {
                if (in_array($migration['id'], ['20261007-readiness-permission', '20261012-align-department-canonical-permissions', '20261012-align-platform-ops-permission-ownership'], true)) {
                    $businessPoststates[$migration['id']] = \app\common\services\upgrade\ApplicationSchemaHost::permissionMigrationAfter($pdo, $migration, $businessBefore[$migration['id']], $executionBounds);
                } else {
                    $businessPoststates[$migration['id']] = \app\common\services\upgrade\ApplicationSchemaHost::menuMigrationAfter($pdo, $migration, $businessBefore[$migration['id']]);
                }
                unset($businessBefore[$migration['id']]);
            } elseif (in_array($migration['id'], ['20261007-readiness-permission', '20261012-align-department-canonical-permissions', '20261012-align-platform-ops-permission-ownership'], true)) {
                throw new RuntimeException('APPLICATION_SCHEMA_BUSINESS_PROOF_REQUIRED');
            }
            PeanutServerUpdatePlan::assertDatabaseMigrationStage($pdo);
        });
    $ids = array_map(static fn(string $file): string => basename($file, '.sql'), $files);
    $statuses = $runner->statuses($ids);
    $evidence = ['status' => 'completed', 'operation' => $operation, 'update_id' => $plan['update_id'],
        'plan_sha256' => $planSha, 'database_identity' => $databaseIdentity,
        'application' => $migration, 'application_statuses' => $statuses, 'business_poststates' => $businessPoststates];
    $evidence['scope'] = $scope;
    $evidence['plugins'] = $scope === 'server-release' ? 'reconciled' : 'not_run';
    $evidence['product_database'] = $scope === 'server-release' ? ($operation === 'verify' ? 'verified' : 'migrated') : 'not_verified';
    if ($scope === 'server-release') {
        $evidence['module_state_sha256'] = peanutServerDatabaseModuleState($pdo);
        $evidence['modules'] = $modules ?? $previous['modules'] ?? null;
    }
    if ($operation === 'migrate' && !$replayed) {
        $evidence['protocol'] = $migrationProtocol;
        $evidence['backup_sha256'] = hash_file('sha256', $workspace . '/backup.json');
        $temporary = $migrationPath . '.complete';
        $stream = fopen($temporary, 'x');
        $bytes = json_encode($evidence, JSON_THROW_ON_ERROR);
        if ($stream === false || !chmod($temporary, 0600)
            || fwrite($stream, $bytes) !== strlen($bytes) || !fflush($stream) || !fsync($stream)) {
            throw new RuntimeException('migration result could not be persisted');
        }
        fclose($stream);
        if (!rename($temporary, $migrationPath)) {
            throw new RuntimeException('migration result could not be published');
        }
        $signaturePath = $migrationPath . '.hmac';
        $signature = hash_hmac('sha256', (string) file_get_contents($migrationPath), $verificationKey) . "\n";
        $stream = fopen($signaturePath, 'x');
        if ($stream === false || !chmod($signaturePath, 0600) || fwrite($stream, $signature) !== strlen($signature)
            || !fflush($stream) || !fsync($stream)) {
            throw new RuntimeException('migration signature could not be persisted');
        }
        fclose($stream);
    }
    return $evidence + ['result_path' => $migrationPath];
    });
}

/** Use ThinkORM's existing default master and reject configuration drift or reconnect. */
function peanutServerDatabaseMaster(string $server): array
{
    if (!class_exists(\think\App::class, false)) {
        putenv('PEANUT_SERVER_ENV_FILE=' . $server . '/.env');
        require_once $server . '/bootstrap/environment.php';
        require_once $server . '/vendor/autoload.php';
        $app = new \think\App($server . '/');
        $app->console;
    } else {
        $app = app();
        if (realpath($app->getRootPath()) !== $server) { throw new RuntimeException('database host is bound to another App'); }
        $app->console;
    }
    require_once $server . '/database/environment-guard.php';
    $config = guardedDatabaseConfig();
    $connection = \think\facade\Db::connect();
    foreach (['hostname' => 'host', 'hostport' => 'port', 'database' => 'database', 'username' => 'user', 'password' => 'password'] as $native => $guarded) {
        if ((string) $connection->getConfig($native) !== (string) $config[$guarded]) { throw new RuntimeException('default database differs from registered master'); }
    }
    if ((int) $connection->getConfig('deploy') !== 0 || $connection->getConfig('break_reconnect') !== false
        || $connection->getConfig('prefix') !== 'pa_') { throw new RuntimeException('native migration master policy differs'); }
    $connection->query('SELECT CONNECTION_ID() AS connection_id, DATABASE() AS database_name', [], true);
    $pdo = $connection->getPdo();
    if (!$pdo instanceof PDO || $pdo->inTransaction() || \think\facade\Db::connect() !== $connection) { throw new RuntimeException('native migration master unavailable'); }
    $actual = \app\common\services\installation\InstallationExecutionHost::migrationDatabaseIdentity($pdo);
    if ($actual['database'] !== $config['database']) { throw new RuntimeException('native migration database changed'); }
    return [$app, $config, $pdo];
}

function peanutServerDatabasePrestates(PDO $pdo, array $files, array $selection): array
{
    $proofs = []; $preceding = [];
    foreach ($files as $file) {
        $id = basename($file, '.sql');
        if (!in_array($id, $selection['pending'], true)) { continue; }
        $sql = (string) file_get_contents($file);
        $migration = ['id' => $id, 'file' => $file, 'sql' => $sql, 'checksum' => hash('sha256', $sql)];
        $proofs[] = \app\common\services\upgrade\ApplicationSchemaHost::migrationPrestate($pdo, $migration, $preceding, true);
        $preceding[] = $migration;
    }
    return $proofs;
}

/** Deferral is a complete plan of one canonical future schema, never an unknown gate. */
function peanutServerDatabaseDeferredPlan(array $plan, array $files, bool $deferred): void
{
    $allowed = [];
    foreach ($files as $file) { $allowed[basename($file, '.sql')] = hash_file('sha256', $file); }
    $normalize = static function (mixed $rows) use ($allowed): array {
        if (!is_array($rows) || !array_is_list($rows) || $rows === []) { throw new RuntimeException('missing module application prerequisite'); }
        $result = [];
        foreach ($rows as $row) {
            $id = is_array($row) ? ($row['migration_id'] ?? null) : null;
            if (!is_string($id) || $id !== '20261016-module-migration-provenance' || count($row) !== 3 || isset($result[$id])
                || ($row['reason'] ?? null) !== 'module_migration_provenance_schema' || ($row['sha256'] ?? null) !== ($allowed[$id] ?? null)) {
                throw new RuntimeException('unknown module application prerequisite');
            }
            $result[$id] = $row;
        }
        ksort($result, SORT_STRING); return $result;
    };
    $collected = [];
    foreach ($plan['packages'] as $item) {
        if (!is_array($item['modules'] ?? null)) { throw new RuntimeException('incomplete module plan'); }
        $children = [];
        foreach ($item['modules'] as $module) {
            if (($module['may_apply'] ?? null) === true && ($module['application_prerequisites'] ?? []) === []) { continue; }
            if (!$deferred || ($module['may_apply'] ?? null) !== false || ($module['planning_complete'] ?? null) !== true
                || ($module['status'] ?? null) !== 'planned' || ($module['execution_admitted'] ?? null) !== false) { throw new RuntimeException('unknown module precondition'); }
            $children += $normalize($module['application_prerequisites'] ?? null);
        }
        if (($item['may_apply'] ?? null) === true && $children === [] && ($item['application_prerequisites'] ?? []) === []) { continue; }
        if (!$deferred || ($item['may_apply'] ?? null) !== false || ($item['planning_complete'] ?? null) !== true
            || ($item['execution_admitted'] ?? null) !== false || ($item['status'] ?? null) !== 'planned'
            || $children !== $normalize($item['application_prerequisites'] ?? null)) { throw new RuntimeException('incomplete deferred package plan'); }
        $collected += $children;
    }
    if (($deferred && $collected !== $normalize($plan['application_prerequisites'] ?? null))
        || (!$deferred && ($collected !== [] || ($plan['application_prerequisites'] ?? []) !== []))) { throw new RuntimeException('incomplete deferred module plan'); }
}

function peanutServerDatabaseModuleState(PDO $pdo): string
{
    $state = [];
    foreach (['pa_module_migration' => 'module_key,migration_key', 'pa_plugin_installation' => 'plugin_key', 'pa_plugin_module' => 'module_key'] as $table => $order) {
        $state[$table] = $pdo->query('SELECT * FROM ' . $table . ' ORDER BY ' . $order)->fetchAll(PDO::FETCH_ASSOC);
    }
    return hash('sha256', peanutServerDatabaseCanonical($state));
}

function peanutServerDatabaseCanonical(array $value): string
{
    $normalize = static function (array $items) use (&$normalize): array {
        if (!array_is_list($items)) { ksort($items, SORT_STRING); }
        foreach ($items as $key => $item) { if (is_array($item)) { $items[$key] = $normalize($item); } }
        return $items;
    };
    return json_encode($normalize($value), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
}

if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    try {
        if (count($argv) !== 4) { throw new RuntimeException('Usage: update-database.php preflight|migrate|verify INSTANCE_SERVER WORKSPACE'); }
        echo json_encode(peanutServerDatabaseStage($argv[1], $argv[2], $argv[3]), JSON_THROW_ON_ERROR), PHP_EOL;
    } catch (Throwable $exception) {
        fwrite(STDERR, 'module-preflight:' . json_encode(['phase' => 'module-preflight', 'code' => 'MODULE_PREFLIGHT_REPORT_INVALID',
            'diagnostic_sha256' => hash('sha256', $exception->getMessage())], JSON_THROW_ON_ERROR) . PHP_EOL);
        fwrite(STDERR, 'server-update-migration failed: code=' . $exception->getCode()
            . '; diagnostic_sha256=' . hash('sha256', $exception->getMessage()) . PHP_EOL);
        exit($exception->getCode() > 0 && $exception->getCode() <= 125 ? $exception->getCode() : 1);
    }
}
