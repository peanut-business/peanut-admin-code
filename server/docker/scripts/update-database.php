#!/usr/bin/env php
<?php

declare(strict_types=1);

// This adapter calls the same native migration runner and module reconciler
// used by the product upgrader. It only selects a server release's SQL chain.
try {
    if (count($argv) !== 4 || !in_array($argv[1], ['preflight', 'migrate', 'verify'], true)) {
        throw new RuntimeException('Usage: update-database.php preflight|migrate|verify INSTANCE_SERVER WORKSPACE');
    }
    [$unused, $operation, $server, $workspace] = $argv;
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
    if ($operation === 'preflight') {
        if ($productState['phase_in_progress'] !== 'migration-preflight') { throw new RuntimeException('product preflight phase is not active'); }
        $prepared = $workspace . '/prepared/server';
        putenv('PEANUT_SERVER_ENV_FILE=' . $prepared . '/.env');
        require_once $prepared . '/vendor/autoload.php';
        require_once $prepared . '/database/environment-guard.php';
        require_once $prepared . '/app/common/services/upgrade/ApplicationMigrationRunner.php';
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
        $runner = new \app\common\services\upgrade\ApplicationMigrationRunner($config);
        require_once $prepared . '/database/install.php';
        $target = applicationMigrationTargetVersion($prepared, applicationReleaseVersions($prepared));
        $result = $runner->run($files, $target, $identity['application']['version'], true);
        echo json_encode(['status' => 'completed', 'operation' => 'preflight', 'candidate' => $product['candidate'], 'application' => $result], JSON_THROW_ON_ERROR), PHP_EOL;
        exit(0);
    }
    $keyPath = $server . '/private/installation/update-verification.key';
    $keyStat = lstat($keyPath);
    if (!is_file($keyPath) || is_link($keyPath) || !is_array($keyStat)
        || ($keyStat['nlink'] ?? 0) !== 1 || ($keyStat['mode'] & 0777) !== 0600) {
        throw new RuntimeException('private update verification key is unsafe');
    }
    $verificationKey = trim((string) file_get_contents($keyPath));
    if (preg_match('/^[a-f0-9]{64}$/D', $verificationKey) !== 1) {
        throw new RuntimeException('private update verification key is invalid');
    }
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
    $pdo = guardedConnection($config);
    $uuid = strtolower((string) $pdo->query('SELECT @@server_uuid')->fetchColumn());
    if ($uuid === '' || $uuid !== ($backup['mysql_identity']['server_uuid'] ?? null)) {
        throw new RuntimeException('migration is connected to another MySQL server');
    }
    $runner = new \app\common\services\upgrade\ApplicationMigrationRunner([
        'host' => (string) $config['host'], 'port' => (string) $config['port'],
        'database' => (string) $config['database'], 'user' => (string) $config['user'],
        'password' => (string) $config['password'],
    ]);
    $version = (string) $identity['application']['version'];
    require_once $server . '/database/install.php';
    $migrationTargetVersion = applicationMigrationTargetVersion($server, applicationReleaseVersions($server));
    $migrationPath = $workspace . '/migration.json';
    $replayed = false;
    if ($operation === 'migrate') {
        if (file_exists($migrationPath) || is_link($migrationPath)) {
            $assertSigned($migrationPath);
            $previous = json_decode((string) file_get_contents($migrationPath), true, 128, JSON_THROW_ON_ERROR);
            if (($previous['protocol'] ?? null) !== 'peanut.server-update-migration.v1'
                || ($previous['status'] ?? null) !== 'completed' || ($previous['update_id'] ?? null) !== $plan['update_id']
                || ($previous['plan_sha256'] ?? null) !== $planSha
                || ($previous['backup_sha256'] ?? null) !== hash_file('sha256', $workspace . '/backup.json')) {
                throw new RuntimeException('migration has an unknown result; inspect ledger or recover this update');
            }
            $replayed = true;
        }
        if (!$replayed) {
            $intent = ['protocol' => 'peanut.server-update-migration.v1', 'status' => 'started',
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
        if (($previous['protocol'] ?? null) !== 'peanut.server-update-migration.v1'
            || ($previous['status'] ?? null) !== 'completed'
            || ($previous['update_id'] ?? null) !== $plan['update_id']
            || ($previous['plan_sha256'] ?? null) !== $planSha
            || ($previous['backup_sha256'] ?? null) !== hash_file('sha256', $workspace . '/backup.json')) {
            throw new RuntimeException('native migration result is missing or bound to another recovery point');
        }
    }
    $migration = $runner->run($files, $migrationTargetVersion, $version, $operation === 'verify' || $replayed);
    if (($operation === 'verify' || $replayed) && $migration['pending'] !== []) {
        // The native runner selects applicable migrations and checks immutable
        // checksums. Missing ledger rows must not disappear from verification.
        throw new RuntimeException('application migration ledger is incomplete');
    }
    $ids = array_map(static fn(string $file): string => basename($file, '.sql'), $files);
    $statuses = $runner->statuses($ids);
    if ($operation === 'migrate' && !$replayed) {
        // The child bootstrap must load its own controlled file, rather than
        // inheriting the managed values already loaded by this adapter.
        $commandEnvironment = getenv();
        if (!is_array($commandEnvironment)) {
            throw new RuntimeException('module reconciliation environment is unavailable');
        }
        foreach (array_merge(peanutBackendEnvironmentKeys(), peanutTransientInstallationKeys()) as $name) {
            unset($commandEnvironment[$name]);
        }
        $process = proc_open(
            [PHP_BINARY, $server . '/think', 'plugin:reconcile', '--release-locked'],
            [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            $server,
            $commandEnvironment,
        );
        if (!is_resource($process)) {
            throw new RuntimeException('module reconciliation could not start');
        }
        $output = stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        $diagnostic = stream_get_contents($pipes[2]);
        fclose($pipes[2]);
        $exitCode = proc_close($process);
        $result = is_string($output) ? json_decode(trim($output), true) : null;
        if ($exitCode !== 0 || !is_array($result) || isset($result['error'])) {
            throw new RuntimeException('module reconciliation failed with exit ' . $exitCode
                . '; stderr_sha256=' . hash('sha256', (string) $diagnostic), $exitCode > 0 ? $exitCode : 1);
        }
    } else {
        foreach ($statuses as $status) {
            if ($status !== 'applied') {
                throw new RuntimeException('application migration ledger is incomplete');
            }
        }
        $incomplete = (int) $pdo->query("SELECT COUNT(*) FROM pa_module_migration WHERE status <> 'applied'")->fetchColumn();
        if ($incomplete !== 0) {
            throw new RuntimeException('module migration ledger is incomplete');
        }
    }
    $evidence = ['status' => 'completed', 'operation' => $operation, 'update_id' => $plan['update_id'],
        'plan_sha256' => $planSha, 'database_identity' => $databaseIdentity,
        'application' => $migration, 'application_statuses' => $statuses];
    if ($operation === 'migrate' && !$replayed) {
        $evidence['protocol'] = 'peanut.server-update-migration.v1';
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
    echo json_encode($evidence, JSON_THROW_ON_ERROR), PHP_EOL;
} catch (Throwable $exception) {
    fwrite(STDERR, 'server-update-migration failed: code=' . $exception->getCode()
        . '; diagnostic_sha256=' . hash('sha256', $exception->getMessage()) . PHP_EOL);
    exit($exception->getCode() > 0 && $exception->getCode() <= 125 ? $exception->getCode() : 1);
}
