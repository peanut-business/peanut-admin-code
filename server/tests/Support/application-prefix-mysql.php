<?php

declare(strict_types=1);

use app\common\services\upgrade\SourceApplicationBackup;
use app\common\services\upgrade\SourceApplicationDump;

// Explicit, retained-fixture qualification only. Never boots an APP or writes either migration ledger.
try { (static function (): void {
    $options = getopt('', ['mysql', 'baseline:', 'output:', 'tool:', 'owner:']);
    foreach (['baseline', 'output', 'tool', 'owner'] as $key) {
        if (!is_string($options[$key] ?? null) || $options[$key] === '') { throw new RuntimeException('MYSQL_PREFIX_ARGUMENT_REQUIRED:' . $key); }
    }
    $root = dirname(__DIR__, 3);
    require_once $root . '/server/database/environment-guard.php';
    $dumpTables = ['pa_auth_security_event', 'pa_permission', 'pa_role', 'pa_tenant', 'pa_tenant_session'];
    $children = ['pa_platform_role_permission', 'pa_resource_operation_permission', 'pa_resource_operation_target_type', 'pa_role_permission', 'pa_tenant_session_token'];
    $fixtureTables = [...$dumpTables, ...$children]; sort($fixtureTables, SORT_STRING);
    $retained = ['pa_module_migration', 'pa_schema_migration'];
    $expect = static function (bool $value, string $message): void { if (!$value) { throw new RuntimeException('MYSQL_PREFIX_' . $message); } };
    $digest = static fn(mixed $value): string => hash('sha256', json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
    $baselinePath = $options['baseline']; $stat = @lstat($baselinePath);
    $expect(is_array($stat) && !is_link($baselinePath) && ($stat['mode'] & 0777) === 0600 && $stat['nlink'] === 1
        && in_array($stat['uid'], [0, posix_geteuid()], true) && realpath($baselinePath) === $baselinePath && $stat['size'] < 65536, 'BASELINE_FILE_UNSAFE');
    $baselineBytes = file_get_contents($baselinePath); $baselineSha = hash('sha256', $baselineBytes);
    $baseline = json_decode($baselineBytes, true, 32, JSON_THROW_ON_ERROR);
    $expect(($baseline['protocol'] ?? null) === 'peanut.mysql-retained-ledger-baseline.v1', 'BASELINE_PROTOCOL');
    $registry = projectResourceRegistry($registrySha); $config = guardedDatabaseConfig();
    $database = registeredDatabase($registry, $config['resource_id']); $endpoint = registeredDatabaseEndpoint($database, 'host');
    $contract = $database['inverse_fixture_qualification'] ?? [];
    $expect(($database['application_runtime'] ?? null) === false && ($database['lease_gate'] ?? null) === 'migration-validation'
        && !isset($database['lease_policy']['shared_access'])
        && ($contract['protocol'] ?? null) === 'peanut.mysql-prefix-inverse-fixture.v1'
        && ($contract['fixture_tables'] ?? null) === $fixtureTables && ($contract['retained_tables'] ?? null) === $retained
        && ($contract['dump_tables'] ?? null) === $dumpTables && ($contract['block_restore_tables'] ?? null) === ['pa_auth_security_event']
        && ($contract['cleanup'] ?? null) === 'retain', 'REGISTERED_FIXTURE_REQUIRED');
    $output = $options['output'];
    $expect($output === ($contract['output_root'] ?? null) && realpath(dirname($output)) === dirname($output)
        && !file_exists($output) && !is_link($output), 'OUTPUT_UNSAFE');
    $proof = requiredEnvironment('PEANUT_RESOURCE_LEASE_PROOF');
    $metadata = activeLeaseMetadata($proof, time(), 'migration-validation'); $resources = activeLeaseResources($proof);
    // This native policy's undeclared table access is schema-exclusive, never a shared read lease.
    $expect($metadata['owner'] === $options['owner'] && $metadata['thread'] === $options['owner']
        && !isset($resources['mysql-access']), 'EXCLUSIVE_OWNER_REQUIRED');
    $schema = mysqlSchemaLeaseResource($database, $endpoint, $config);
    $tools = array_values(array_filter($registry['resources']['tooling'] ?? [], static fn(array $row): bool => ($row['stable_resource_id'] ?? null) === $options['tool']));
    $expect(count($tools) === 1 && ($tools[0]['source_schema_backup']['tables'] ?? null) === $dumpTables
        && ($tools[0]['source_schema_backup']['database_resource_id'] ?? null) === $config['resource_id']
        && ($tools[0]['source_schema_backup']['database'] ?? null) === $config['database']
        && in_array($options['tool'], $resources['tooling-resource-id'] ?? [], true)
        && in_array($output, $resources['output-dir'] ?? [], true), 'NATIVE_SNAPSHOT_TOOL_REQUIRED');
    $tool = $tools[0];
    $native = static function () use ($root, $proof, $config, $schema, $registrySha, $options, $baselinePath, $baselineSha, $expect): void {
        $current = activeLeaseMetadata($proof, time(), 'migration-validation');
        projectResourceRegistry($currentRegistrySha);
        $expect($current['owner'] === $options['owner'] && $current['thread'] === $options['owner']
            && $currentRegistrySha === $registrySha && hash_file('sha256', $baselinePath) === $baselineSha
            && leaseGit(['-C', $root, 'status', '--porcelain']) === '', 'INPUT_DRIFT');
        assertNativeMysqlSchemaLease($root, $proof, $current, activeLeaseResources($proof), $config['resource_id'], $schema, $config['endpoint_id'], $registrySha);
    };
    $native();
    $connect = static function () use ($native, $config): PDO {
        $native();
        return new PDO('mysql:host=' . $config['host'] . ';port=' . $config['port'] . ';dbname=' . $config['database'] . ';charset=utf8mb4',
            $config['user'], $config['password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES => false,
                PDO::ATTR_STRINGIFY_FETCHES => false, PDO::ATTR_TIMEOUT => 5, PDO::MYSQL_ATTR_MULTI_STATEMENTS => true]);
    };
    $pdo = $connect(); $identity = $pdo->query('SELECT DATABASE() AS database_name,@@server_uuid AS server_uuid,@@hostname AS server_hostname,@@port AS server_port,VERSION() AS version')->fetch(PDO::FETCH_ASSOC);
    $expect(($baseline['resource_id'] ?? null) === $config['resource_id'] && ($baseline['database'] ?? null) === $config['database']
        && ($baseline['server_uuid'] ?? null) === $identity['server_uuid'] && $identity['database_name'] === $config['database']
        && $identity['version'] === ($tool['source_schema_backup']['mysql_version'] ?? null), 'DATABASE_IDENTITY');
    $ledgerFacts = static function () use (&$pdo, $digest): array {
        $result = [];
        foreach (['pa_module_migration' => 'module_key,migration_key', 'pa_schema_migration' => 'migration_id'] as $table => $order) {
            $rows = $pdo->query('SELECT * FROM `' . $table . '` ORDER BY ' . $order)->fetchAll(PDO::FETCH_ASSOC);
            $result[$table] = ['rows' => count($rows), 'sha256' => $digest($rows),
                'create_sha256' => hash('sha256', $pdo->query('SHOW CREATE TABLE `' . $table . '`')->fetch(PDO::FETCH_NUM)[1])];
        }
        return $result;
    };
    $originalLedgers = $ledgerFacts();
    foreach ($retained as $table) {
        $expect($originalLedgers[$table]['rows'] === ($baseline['tables'][$table]['rows'] ?? null)
            && $originalLedgers[$table]['sha256'] === ($baseline['tables'][$table]['sha256'] ?? null), 'RETAINED_LEDGER_DRIFT');
    }
    $objects = $pdo->query('SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() ORDER BY TABLE_NAME')->fetchAll(PDO::FETCH_COLUMN);
    $expect($objects === $retained, 'FIXTURE_ALREADY_PRESENT_OR_UNKNOWN_OBJECTS');
    // Direct schema ALL makes these local metadata rows visible. No role/grant changes,
    // cross-schema completeness or full Host effect-boundary qualification is implied.
    $schemaMetadataVisible = false;
    foreach ($pdo->query('SHOW GRANTS')->fetchAll(PDO::FETCH_COLUMN) as $grant) {
        $expect(is_string($grant) && !str_starts_with($grant, 'REVOKE '), 'METADATA_VISIBILITY_REQUIRED');
        if (preg_match('/\AGRANT ALL PRIVILEGES ON `([^`]+)`\.\* TO /D', $grant, $match) === 1
            && $match[1] === $config['database']) { $schemaMetadataVisible = true; }
    }
    $expect($schemaMetadataVisible && (int) $pdo->query('SELECT @@GLOBAL.partial_revokes')->fetchColumn() === 0, 'METADATA_VISIBILITY_REQUIRED');
    $extraObjects = [];
    foreach (['EVENTS' => 'EVENT_SCHEMA', 'ROUTINES' => 'ROUTINE_SCHEMA', 'TRIGGERS' => 'TRIGGER_SCHEMA'] as $kind => $column) {
        $extraObjects[$kind] = (int) $pdo->query('SELECT COUNT(*) FROM information_schema.' . $kind . ' WHERE ' . $column . '=DATABASE()')->fetchColumn();
        $expect($extraObjects[$kind] === 0, 'UNKNOWN_' . $kind);
    }
    $lockName = 'peanut_migrate_' . substr(hash('sha256', $config['database']), 0, 48);
    $lock = static function () use (&$pdo, $lockName, $expect): void {
        $statement = $pdo->prepare('SELECT GET_LOCK(?,10)'); $statement->execute([$lockName]);
        $expect((int) $statement->fetchColumn() === 1, 'SCHEMA_LOCK_REQUIRED'); $statement->closeCursor();
    };
    $lock(); $oldMask = umask(0077); $expect(mkdir($output, 0700), 'OUTPUT_CREATE_FAILED');
    $record = ['protocol' => 'peanut.mysql-prefix-inverse-qualification.v1', 'status' => 'running', 'source' => $metadata['candidate'],
        'resource_id' => $config['resource_id'], 'database' => $config['database'], 'owner' => $metadata['owner'], 'lease' => $metadata['lease'],
        'baseline' => ['path' => $baselinePath, 'sha256' => $baselineSha], 'retained_originals' => $originalLedgers,
        'initial_metadata' => ['visibility' => 'direct exact-schema ALL; partial_revokes disabled', 'extra_objects' => $extraObjects],
        'created_tables' => [], 'cases' => [], 'qualification_scope' => 'fixed inverse primitives with representative native foreign keys',
        'full_host_new_failure_routes' => 'not_run', 'migration_runner' => 'not_run', 'app_or_production_qualified' => false, 'cleanup' => 'retain'];
    $save = static function () use ($output, &$record): void {
        $temporary = $output . '/fixture.json.tmp'; $stream = fopen($temporary, 'x');
        if ($stream === false) { throw new RuntimeException('MYSQL_PREFIX_CHECKPOINT_UNSAFE'); }
        try { fwrite($stream, json_encode($record, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n"); fflush($stream); }
        finally { fclose($stream); }
        if (!rename($temporary, $output . '/fixture.json')) { throw new RuntimeException('MYSQL_PREFIX_CHECKPOINT_WRITE_FAILED'); }
    };
    $guard = static function () use ($native, &$pdo, $ledgerFacts, $originalLedgers, $lockName, $expect): void {
        $native(); $expect($ledgerFacts() === $originalLedgers, 'RETAINED_LEDGER_CHANGED');
        $statement = $pdo->prepare('SELECT IS_USED_LOCK(?)=CONNECTION_ID()'); $statement->execute([$lockName]);
        $expect((int) $statement->fetchColumn() === 1, 'SCHEMA_LOCK_LOST'); $statement->closeCursor();
        $checks = $pdo->query('SELECT @@foreign_key_checks,@@unique_checks')->fetch(PDO::FETCH_NUM);
        $expect((int) $checks[0] === 1 && (int) $checks[1] === 1, 'CONSTRAINT_CHECKS_DISABLED');
    };
    $run = static function (string $sql) use (&$pdo, $guard): void {
        $guard(); $emulated = (bool) $pdo->getAttribute(PDO::ATTR_EMULATE_PREPARES);
        $pdo->setAttribute(PDO::ATTR_EMULATE_PREPARES, true);
        try {
            $statement = $pdo->query($sql);
            try {
                do { if ($statement->columnCount() > 0) { $statement->fetchAll(PDO::FETCH_ASSOC); } } while ($statement->nextRowset());
            } finally { $statement->closeCursor(); }
        } finally { $pdo->setAttribute(PDO::ATTR_EMULATE_PREPARES, $emulated); }
    };
    $binding = ['resource_id' => $config['resource_id'], 'registry_sha256' => $registrySha,
        'source' => ['root' => $root, 'head' => $metadata['candidate'], 'tree' => leaseGit(['-C', $root, 'rev-parse', 'HEAD^{tree}'])],
        'database' => ['database' => $config['database'], 'server_uuid' => $identity['server_uuid'], 'server_hostname' => $identity['server_hostname'], 'server_port' => (int) $identity['server_port']],
        'authority' => ['kind' => 'native-fixture-owner', 'owner' => $metadata['owner'], 'thread' => $metadata['thread'], 'gate' => $metadata['gate'],
            'lease' => $metadata['lease'], 'created_at' => (int) $metadata['created_at'], 'expires_at' => (int) $metadata['expires_at']]];
    $capture = static function (string $name) use ($binding, $dumpTables, $tool, $output, $guard, $digest): array {
        $facts = ['pending' => [], 'selected' => [], 'fixture_only' => true];
        $result = SourceApplicationBackup::capture($binding, $facts, $digest($facts), ['tables' => $dumpTables, 'fixture_only' => true], $tool, $output . '/' . $name, $guard);
        $manifest = json_decode(file_get_contents($result['path']), true, 32, JSON_THROW_ON_ERROR);
        $bytes = file_get_contents($manifest['backup_payload']['path']);
        if (hash('sha256', $bytes) !== $result['backup_payload_sha256']) { throw new RuntimeException('MYSQL_PREFIX_NATIVE_DUMP_CHANGED'); }
        return ['bytes' => $bytes, 'sha256' => hash('sha256', $bytes), 'dump' => SourceApplicationDump::read($bytes, $dumpTables), 'receipt' => $result];
    };
    $derive = static function (array $before, array $current, string $name) use ($dumpTables, $output, $expect): string {
        $payload = SourceApplicationDump::restorePrefix($before['dump'], $current['dump'], $dumpTables);
        foreach (['pa_permission', 'pa_role', 'pa_tenant', 'pa_tenant_session'] as $table) {
            $expect(!str_contains($payload, 'DROP TABLE IF EXISTS `' . $table . '`'), 'PARENT_DROP');
        }
        $expect(!str_contains($payload, 'pa_schema_migration') && !str_contains($payload, 'pa_module_migration'), 'LEDGER_PAYLOAD');
        $path = $output . '/' . $name . '.sql'; $expect(!file_exists($path), 'PAYLOAD_EXISTS'); file_put_contents($path, $payload, LOCK_EX);
        return $payload;
    };
    $childFacts = static function () use (&$pdo, $children, $digest): array {
        $result = [];
        foreach ($children as $table) { $result[$table] = $digest($pdo->query('SELECT * FROM `' . $table . '` ORDER BY 1,2')->fetchAll(PDO::FETCH_ASSOC)); }
        return $result;
    };
    try {
        $save();
        $definitions = [
            'pa_tenant' => "`id` bigint unsigned NOT NULL AUTO_INCREMENT,`code` varchar(64) NOT NULL,`authorization_revision` bigint unsigned NOT NULL DEFAULT 1,`updated_at` datetime(3) DEFAULT NULL,PRIMARY KEY (`id`),UNIQUE KEY `uq_fixture_tenant_code` (`code`)",
            'pa_role' => "`id` bigint unsigned NOT NULL AUTO_INCREMENT,`tenant_id` bigint unsigned NOT NULL,`key` varchar(96) NOT NULL,`authorization_revision` bigint unsigned NOT NULL DEFAULT 1,`updated_at` datetime(3) DEFAULT NULL,PRIMARY KEY (`id`),UNIQUE KEY `uq_fixture_role_identity` (`tenant_id`,`id`),CONSTRAINT `fk_fixture_role_tenant` FOREIGN KEY (`tenant_id`) REFERENCES `pa_tenant` (`id`) ON DELETE RESTRICT",
            'pa_permission' => "`id` bigint unsigned NOT NULL AUTO_INCREMENT,`key` varchar(160) NOT NULL,`module_key` varchar(96) NOT NULL,`type` varchar(32) NOT NULL,`name` varchar(160) NOT NULL,`description` varchar(500) DEFAULT NULL,`risk_level` varchar(16) NOT NULL,`status` varchar(32) NOT NULL,`manifest_version` varchar(32) NOT NULL,`created_at` datetime(3) NOT NULL,`updated_at` datetime(3) NOT NULL,`retired_at` datetime(3) DEFAULT NULL,PRIMARY KEY (`id`),UNIQUE KEY `uq_fixture_permission_key` (`key`),CONSTRAINT `chk_fixture_permission_retired` CHECK (`status`<>'retired' OR `retired_at` IS NOT NULL)",
            'pa_tenant_session' => "`id` bigint unsigned NOT NULL AUTO_INCREMENT,`tenant_id` bigint unsigned NOT NULL,`client_key` varchar(64) NOT NULL,`updated_at` datetime(3) DEFAULT NULL,PRIMARY KEY (`id`),CONSTRAINT `fk_fixture_session_tenant` FOREIGN KEY (`tenant_id`) REFERENCES `pa_tenant` (`id`) ON DELETE RESTRICT,CONSTRAINT `chk_tenant_session_client` CHECK (`client_key`='admin-web')",
            'pa_auth_security_event' => "`id` bigint unsigned NOT NULL AUTO_INCREMENT,`ip_address` varchar(45) NOT NULL,`occurred_at` datetime(3) NOT NULL,PRIMARY KEY (`id`),KEY `idx_auth_event_ip` (`occurred_at`)",
            'pa_role_permission' => "`tenant_id` bigint unsigned NOT NULL,`role_id` bigint unsigned NOT NULL,`permission_id` bigint unsigned NOT NULL,PRIMARY KEY (`tenant_id`,`role_id`,`permission_id`),CONSTRAINT `fk_fixture_grant_role` FOREIGN KEY (`tenant_id`,`role_id`) REFERENCES `pa_role` (`tenant_id`,`id`) ON DELETE RESTRICT,CONSTRAINT `fk_fixture_grant_permission` FOREIGN KEY (`permission_id`) REFERENCES `pa_permission` (`id`) ON DELETE RESTRICT",
            'pa_platform_role_permission' => "`id` bigint unsigned NOT NULL,`permission_id` bigint unsigned NOT NULL,PRIMARY KEY (`id`),CONSTRAINT `fk_fixture_platform_permission` FOREIGN KEY (`permission_id`) REFERENCES `pa_permission` (`id`) ON DELETE RESTRICT",
            'pa_resource_operation_permission' => "`id` bigint unsigned NOT NULL,`permission_id` bigint unsigned NOT NULL,PRIMARY KEY (`id`),CONSTRAINT `fk_fixture_operation_permission` FOREIGN KEY (`permission_id`) REFERENCES `pa_permission` (`id`) ON DELETE RESTRICT",
            'pa_resource_operation_target_type' => "`id` bigint unsigned NOT NULL,`policy_selection_permission_id` bigint unsigned NOT NULL,PRIMARY KEY (`id`),CONSTRAINT `fk_fixture_target_permission` FOREIGN KEY (`policy_selection_permission_id`) REFERENCES `pa_permission` (`id`) ON DELETE RESTRICT",
            'pa_tenant_session_token' => "`id` bigint unsigned NOT NULL,`session_id` bigint unsigned NOT NULL,PRIMARY KEY (`id`),CONSTRAINT `fk_fixture_token_session` FOREIGN KEY (`session_id`) REFERENCES `pa_tenant_session` (`id`) ON DELETE RESTRICT",
        ];
        foreach ($definitions as $table => $definition) {
            $record['submitted_table'] = $table; $save();
            $run('CREATE TABLE `' . $table . '` (' . $definition . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci');
            $record['created_tables'][] = $table; unset($record['submitted_table']); $save();
        }
        $literalName = $pdo->quote("literal; -- #, quote' value");
        $run("INSERT INTO pa_tenant VALUES(1,'inverse-fixture',7,'2026-01-01 00:00:00.001'); INSERT INTO pa_role VALUES(1,1,'inverse-fixture',9,NULL);"
            . "INSERT INTO pa_permission VALUES(1,'fixture/read','fixture','api'," . $literalName . ",NULL,'normal','active','fixture-v1','2026-01-01','2026-01-01',NULL),(2,'fixture/other','fixture','api','other','retained','normal','active','fixture-v1','2026-01-01','2026-01-01',NULL);"
            . "ALTER TABLE pa_permission AUTO_INCREMENT=100; INSERT INTO pa_tenant_session VALUES(1,1,'admin-web',NULL); INSERT INTO pa_auth_security_event VALUES(1,'203.0.113.1','2026-01-01');"
            . 'INSERT INTO pa_role_permission VALUES(1,1,1); INSERT INTO pa_platform_role_permission VALUES(1,1); INSERT INTO pa_resource_operation_permission VALUES(1,2); INSERT INTO pa_resource_operation_target_type VALUES(1,2); INSERT INTO pa_tenant_session_token VALUES(1,1)');
        $originalChildren = $childFacts(); $before = $capture('original'); $record['original'] = $before['receipt']; $save();
        $sources = (new ReflectionClass(app\common\services\upgrade\ApplicationSchemaHost::class))->getConstant('PRESTATE_SQL');
        $fixedSql = static function (string $id) use ($root, $sources, $expect): string {
            $path = $root . '/server/database/migrations/' . $id . '.sql';
            $expect(hash_file('sha256', $path) === ($sources[$id] ?? null), 'FIXED_SQL_CHANGED'); return file_get_contents($path);
        };
        $authSql = $fixedSql('20261007-auth-client-schema'); $readinessSql = $fixedSql('20261007-readiness-permission');
        $run($authSql); $run($readinessSql);
        $run("UPDATE pa_permission SET module_key='platform',type='action',name='changed',description='changed',risk_level='sensitive',status='retired',manifest_version='fixture-v2',updated_at='2026-02-01',retired_at='2026-02-01' WHERE id=1; UPDATE pa_role SET authorization_revision=10,updated_at='2026-02-01' WHERE id=1; UPDATE pa_tenant SET authorization_revision=8,updated_at='2026-02-01' WHERE id=1");
        $current = $capture('changed'); $payload = $derive($before, $current, 'derived-changed');
        $readinessId = $pdo->query("SELECT CAST(id AS CHAR) FROM pa_permission WHERE `key`='readiness/checklist'")->fetchColumn();
        $expect(is_string($readinessId) && preg_match('/\A[1-9][0-9]*\z/D', $readinessId) === 1, 'READINESS_ID');
        $run('INSERT INTO pa_platform_role_permission VALUES(2,' . $readinessId . ')');
        $rejected = false;
        try { $run($payload); }
        catch (PDOException $error) {
            if ($pdo->inTransaction()) { $pdo->rollBack(); }
            $expect(($error->errorInfo[1] ?? null) === 1451, 'UNEXPECTED_NATIVE_FAILURE'); $rejected = true;
        }
        $expect($rejected, 'NATIVE_FK_NOT_ENFORCED');
        $partial = $capture('foreign-key-refused-partial');
        $record['cases']['foreign_key_refusal'] = ['mysql_error' => 1451, 'partial_payload_sha256' => $partial['sha256'], 'automatic_replay' => false]; $save();
        $run('DELETE FROM pa_platform_role_permission WHERE id=2 AND permission_id=' . $readinessId);
        $expect($childFacts() === $originalChildren, 'CHILD_BINDINGS_CHANGED');
        $fresh = $capture('reconciled-partial'); $run($derive($before, $fresh, 'derived-reconciled'));
        $restored = $capture('restored'); $expect($restored['sha256'] === $before['sha256'] && $childFacts() === $originalChildren, 'FULL_RESTORATION');
        $record['cases']['nonempty_parents_and_check'] = ['before_sha256' => $before['sha256'], 'after_sha256' => $restored['sha256'], 'child_hashes_unchanged' => true]; $save();
        $run($derive($before, $restored, 'derived-noop')); $noop = $capture('noop'); $expect($noop['sha256'] === $before['sha256'], 'NOOP_CHANGED');
        $pdo->beginTransaction(); $run($readinessSql); $pdo->rollBack();
        $allocator = $capture('rolled-back-allocator'); $expect($allocator['sha256'] !== $before['sha256'], 'ALLOCATOR_CASE_NOT_EXERCISED');
        $run($derive($before, $allocator, 'derived-allocator')); $allocated = $capture('allocator-restored'); $expect($allocated['sha256'] === $before['sha256'], 'ALLOCATOR_NOT_RESTORED');
        $record['cases']['noop_and_rolled_back_allocator'] = ['noop_sha256' => $noop['sha256'], 'rolled_back_sha256' => $allocator['sha256'], 'restored_sha256' => $allocated['sha256']]; $save();
        $run('ALTER TABLE pa_tenant_session DROP CHECK chk_tenant_session_client'); $absent = $capture('check-absent-original');
        $run($authSql); $added = $capture('check-added'); $run($derive($absent, $added, 'derived-absent-check'));
        $absentRestored = $capture('check-absent-restored'); $expect($absentRestored['sha256'] === $absent['sha256'], 'ABSENT_CHECK_NOT_RESTORED');
        $record['cases']['absent_check'] = ['before_sha256' => $absent['sha256'], 'after_sha256' => $absentRestored['sha256']]; $save();
        $run("ALTER TABLE pa_tenant_session ADD CONSTRAINT chk_tenant_session_client CHECK (client_key='admin-web'); UPDATE pa_permission SET name='interrupted' WHERE id=1");
        $interrupted = $capture('interruption-original'); $payload = $derive($before, $interrupted, 'derived-interruption');
        $commit = strpos($payload, "COMMIT;\n"); $expect($commit !== false && !str_contains(substr($payload, 0, $commit), 'DROP TABLE'), 'INTERRUPTION_CASE_NOT_DML_ONLY');
        // Submit an unchanged prefix, then close only this test connection before COMMIT.
        $run(substr($payload, 0, $commit)); $expect($pdo->inTransaction(), 'INTERRUPTION_TRANSACTION_REQUIRED'); $pdo = null;
        $pdo = $connect(); $lock(); $afterDisconnect = $capture('interruption-preserved');
        $expect($afterDisconnect['sha256'] === $interrupted['sha256'], 'INTERRUPTED_DML_NOT_PRESERVED');
        $run($payload); $final = $capture('final');
        $expect($final['sha256'] === $before['sha256'] && $childFacts() === $originalChildren, 'FINAL_NATIVE_IMAGE_CHANGED'); $guard();
        $record['cases']['connection_close_before_commit'] = ['scope' => 'unchanged DML prefix only; no DDL atomicity claim', 'before_sha256' => $interrupted['sha256'], 'after_sha256' => $afterDisconnect['sha256']];
        $record['status'] = 'passed'; $record['final'] = $final['receipt']; $record['retained_originals_unchanged'] = true; $save();
        echo "MYSQL-PREFIX-INVERSE passed: native nonempty dumps, parent identities/FKs, named CHECK present/absent, no-op, rolled-back allocator, native FK refusal/partial reconciliation and DML disconnect preservation; original ledgers unchanged; full Host routes NOT_RUN.\n";
    } catch (Throwable $error) {
        if ($pdo instanceof PDO && $pdo->inTransaction()) { $pdo->rollBack(); }
        $record['status'] = 'failed'; $record['error'] = ['class' => get_class($error), 'code' => $error->getCode(), 'message_sha256' => hash('sha256', $error->getMessage())]; $save();
        throw $error;
    } finally {
        if ($pdo instanceof PDO) { $statement = $pdo->prepare('SELECT RELEASE_LOCK(?)'); $statement->execute([$lockName]); $statement->closeCursor(); }
        umask($oldMask);
    }
})(); } catch (Throwable $error) {
    fwrite(STDERR, 'MYSQL-PREFIX-INVERSE FAILED ' . get_class($error) . ' code=' . $error->getCode()
        . ' native_code=' . ($error instanceof PDOException ? ($error->errorInfo[1] ?? 'none') : 'none')
        . ' location=' . basename($error->getFile()) . ':' . $error->getLine()
        . ' reason_sha256=' . hash('sha256', $error->getMessage()) . "\n");
    exit(1);
}
