<?php

declare(strict_types=1);

namespace app\common\services\upgrade;

use app\common\services\installation\InstallationExecutionHost;
use app\common\value\installation\ApplicationSourceIdentity;
use app\common\value\installation\ServerReleaseIdentity;
use app\platform\infrastructure\plugin\PluginLockResolver;
use app\platform\services\plugin\PluginLifecycleService;
use PeanutAdmin\Modules\Identity\Auth\AuthClientSchemaMigrationProof;
use PeanutAdmin\Modules\Identity\Menu\MenuSchemaMigrationProof;
use PeanutAdmin\Modules\Identity\Permission\PermissionSchemaMigrationProof;
use PeanutAdmin\Modules\Notification\Contract\SmsReservationMigrationProof;
use PDO;
use RuntimeException;

/** Owns application-schema admission, not SQL execution, backup or deployment. */
final class ApplicationSchemaHost
{
    private const PROTOCOL = 'peanut.application-schema-plan.v1';
    private const RESULT_PROTOCOL = 'peanut.application-schema-result.v1';
    private ?string $sourceConnectionId = null;
    private string $sourceMetadataTool = '';
    private ?array $fileRelocationInput = null;
    private const MENU_MIGRATIONS = ['20261004-group-default-navigation', '20261004-menu-stable-identities', '20261012-repair-seeded-permission-parents'];
    private const PERMISSION_MIGRATIONS = ['20261007-readiness-permission', '20261012-align-department-canonical-permissions', '20261012-align-platform-ops-permission-ownership'];
    // Business reads made by the complete fixed chain, including protected FK bindings.
    // Backup/write scope is a separate, smaller contract.
    private const SOURCE_READ_TABLES = ['pa_auth_security_event', 'pa_menu_definition', 'pa_module_migration', 'pa_notice_log',
        'pa_permission', 'pa_platform_role_permission', 'pa_resource_operation_permission', 'pa_resource_operation_target_type',
        'pa_role', 'pa_role_permission', 'pa_schema_migration', 'pa_system_menu', 'pa_tenant', 'pa_tenant_session'];

    /** Immutable SQL understood by the source-specific read-only proofs below. */
    private const PRESTATE_SQL = [
        '20260909-notification-sms-reservation' => '4446c1feb1d3226de810682f79e246c327f1d631b22f507ad725ae960f151753',
        '20261004-group-default-navigation' => '76124c9bb3672beef79622004e9e8f4f18d9f14ee07000c05f7251544d207ec2',
        '20261004-menu-stable-identities' => 'fbe16c60e6e580b78058a6f1c11cf78c0f5b43483d1490730b64ab134bd4cdb3',
        '20261007-auth-client-schema' => '8411ed958b0d5d70c6a30771f5d1b8fd85f1cd6ae047a94f8404fa7737baf38b',
        '20261007-readiness-permission' => '7a275b6475893fd0076ef8c3f6386d53343ef30cf3d0434dbef317fedcc28034',
        '20261012-align-department-canonical-permissions' => '1267c4b33baa4567ae45f12eac6edc7d0538cd9fac4f9c4ccc61e2b37eb793bf',
        '20261012-align-platform-ops-permission-ownership' => '038766e7e9ab5356cacf11cc6bb2dd675af02cd9bd3180efd356a8234d6aa1d9',
        '20261012-repair-seeded-permission-parents' => '06c5e8cad88139ba64cbd498765082a52df43e947370ddf2add1de956788ba5f',
        '20261019-module-migration-provenance-metadata' => '5e8abee207d363cdbb48f01c3980a611b01f4457150666a67e67f690dd547ad9',
        FileAdoptionRelocation::ID => FileAdoptionRelocation::SQL_SHA,
    ];

    /**
     * Read-only admission for the fixed application SQL chain. A deferred plan
     * is never execution admission: the runner must call again after predecessors.
     * @param list<array<string,mixed>> $precedingPending canonical earlier pending SQL rows
     */
    public static function migrationPrestate(PDO $pdo, array $migration, array $precedingPending = [], bool $planning = false): array
    {
        [$id, $sql, $sha] = self::prestateIdentity($migration);
        $before = [];
        foreach ($precedingPending as $row) {
            if (!is_array($row)) { throw new RuntimeException('APPLICATION_SCHEMA_PREDECESSOR_IDENTITY_REQUIRED'); }
            [$previousId, , $previousSha] = self::prestateIdentity($row);
            if (strcmp($previousId, $id) >= 0 || isset($before[$previousId])) {
                throw new RuntimeException('APPLICATION_SCHEMA_PREDECESSOR_ORDER_INVALID');
            }
            $before[$previousId] = $previousSha;
        }
        if (in_array($id, self::MENU_MIGRATIONS, true)) {
            $dependencies = match ($id) {
                '20261004-group-default-navigation' => [],
                '20261004-menu-stable-identities' => ['20261004-group-default-navigation'],
                default => ['20261004-group-default-navigation', '20261004-menu-stable-identities'],
            };
            $deferred = [];
            foreach (array_intersect($dependencies, array_keys($before)) as $previousId) {
                $deferred[] = ['migration_id' => $previousId, 'sha256' => $before[$previousId], 'reason' => 'menu_business_predecessor'];
            }
            if ($deferred !== []) {
                if (!$planning) { throw new RuntimeException('APPLICATION_SCHEMA_PRESTATE_DEFERRED'); }
                return ['migration_id' => $id, 'sha256' => $sha, 'status' => 'deferred',
                    'complete_preconditions_proven' => false, 'application_prerequisites' => $deferred,
                    'proof' => ['contract' => MenuSchemaMigrationProof::class], 'readonly' => true];
            }
            $snapshot = self::menuMigrationBefore($pdo, $migration);
            // The public observation cannot reconstruct or expose the private
            // finite facts. Execution takes a new snapshot in its held callback.
            return ['migration_id' => $id, 'sha256' => $sha, 'status' => 'ready',
                'complete_preconditions_proven' => true, 'application_prerequisites' => [],
                'proof' => ['contract' => MenuSchemaMigrationProof::class, 'safe_summary' => $snapshot['safe_summary']], 'readonly' => true];
        }
        if (in_array($id, self::PERMISSION_MIGRATIONS, true)) {
            $dependencies = $id === '20261012-align-department-canonical-permissions'
                ? ['20261004-group-default-navigation', '20261004-menu-stable-identities'] : [];
            $deferred = [];
            foreach (array_intersect($dependencies, array_keys($before)) as $previousId) {
                $deferred[] = ['migration_id' => $previousId, 'sha256' => $before[$previousId], 'reason' => 'permission_business_predecessor'];
            }
            if ($deferred !== []) {
                if (!$planning) { throw new RuntimeException('APPLICATION_SCHEMA_PRESTATE_DEFERRED'); }
                return ['migration_id' => $id, 'sha256' => $sha, 'status' => 'deferred',
                    'complete_preconditions_proven' => false, 'application_prerequisites' => $deferred,
                    'proof' => ['contract' => PermissionSchemaMigrationProof::class], 'readonly' => true];
            }
            $snapshot = self::permissionMigrationBefore($pdo, $migration);
            return ['migration_id' => $id, 'sha256' => $sha, 'status' => 'ready',
                'complete_preconditions_proven' => true, 'application_prerequisites' => [],
                'proof' => ['contract' => PermissionSchemaMigrationProof::class, 'safe_summary' => $snapshot['safe_summary']], 'readonly' => true];
        }
        if ($id === '20260909-notification-sms-reservation') {
            $snapshot = self::smsMigrationBefore($pdo, $migration);
            return ['migration_id' => $id, 'sha256' => $sha, 'status' => 'ready',
                'complete_preconditions_proven' => true, 'application_prerequisites' => [],
                'proof' => ['contract' => SmsReservationMigrationProof::class, 'safe_summary' => $snapshot['safe_summary'],
                    'private_snapshot_sha256' => hash('sha256', serialize($snapshot))], 'readonly' => true];
        }
        if ($id === '20261007-auth-client-schema') {
            $before = self::authMigrationBefore($pdo, $migration);
            return ['migration_id' => $id, 'sha256' => $sha, 'status' => 'ready',
                'complete_preconditions_proven' => true, 'application_prerequisites' => [],
                'proof' => ['contract' => AuthClientSchemaMigrationProof::class, 'safe_summary' => $before['safe_summary']],
                'constraint_names' => $before['constraint_names'], 'tables' => [], 'readonly' => true];
        }
        // Other fixed stages returned through their owning business contract above.
        $constraintNames = self::prestateConstraintNames($pdo, $id);
        $table = self::prestateTable($pdo, 'pa_module_migration');
        self::prestateColumnShape('pa_module_migration', $table['columns']);
        self::prestateConstraintShape('pa_module_migration', $table);
        foreach (['id','module_key','migration_key','module_version','checksum','batch_no','status','started_at','finished_at','error_code'] as $column) {
            if (!isset($table['columns'][$column])) { throw new RuntimeException('APPLICATION_SCHEMA_PRESTATE_COLUMN_MISSING:pa_module_migration.' . $column); }
        }
        self::prestateUnique($table, ['id']);
        return ['migration_id' => $id, 'sha256' => $sha, 'status' => 'ready',
            'complete_preconditions_proven' => true, 'application_prerequisites' => [],
            'proof' => self::prestateProvenance($pdo, $table), 'constraint_names' => $constraintNames,
            'tables' => ['pa_module_migration' => $table], 'readonly' => true];
    }

    /** Both native runners retain this finite DTO only until their same-PDO completion callback. */
    public static function menuMigrationBefore(PDO $pdo, array $migration): ?array
    {
        if (!in_array($migration['id'] ?? null, self::MENU_MIGRATIONS, true)) { return null; }
        [$id, , $sha] = self::prestateIdentity($migration);
        if (!class_exists(MenuSchemaMigrationProof::class)) { throw new RuntimeException('APPLICATION_SCHEMA_MENU_CONTRACT_UNAVAILABLE'); }
        $before = MenuSchemaMigrationProof::before($pdo, $migration);
        if ($pdo->inTransaction() || ($before['migration_id'] ?? null) !== $id || ($before['source_sha256'] ?? null) !== $sha
            || ($before['readonly'] ?? null) !== true || !is_array($before['safe_summary'] ?? null) || !is_array($before['private_state'] ?? null)) {
            throw new RuntimeException('APPLICATION_SCHEMA_MENU_INPUT_INVALID');
        }
        return $before;
    }

    /** Called after the original SQL is drained, before the sole official applied write. */
    public static function menuMigrationAfter(PDO $pdo, array $migration, array $before): array
    {
        [$id, , $sha] = self::prestateIdentity($migration);
        if (!in_array($id, self::MENU_MIGRATIONS, true) || ($before['migration_id'] ?? null) !== $id
            || ($before['source_sha256'] ?? null) !== $sha) { throw new RuntimeException('APPLICATION_SCHEMA_MENU_INPUT_INVALID'); }
        $observed = MenuSchemaMigrationProof::after($pdo, $before);
        if ($pdo->inTransaction() || ($observed['migration_id'] ?? null) !== $id || ($observed['source_sha256'] ?? null) !== $sha
            || ($observed['readonly'] ?? null) !== true || ($observed['business_postconditions_verified'] ?? null) !== true
            || !is_int($observed['protected_checks'] ?? null) || $observed['protected_checks'] < 0) {
            throw new RuntimeException('APPLICATION_SCHEMA_BUSINESS_POSTSTATE_REQUIRED');
        }
        return ['contract' => MenuSchemaMigrationProof::class, 'scope' => 'menu-business-postconditions', 'sha256' => $sha,
            'proof' => array_intersect_key($observed, array_flip(['migration_id', 'source_sha256', 'readonly', 'business_postconditions_verified', 'protected_checks']))];
    }

    /** Safe summaries/counts are stage evidence, not a new live comparison with the original facts. */
    public static function assertMenuPoststates(array $applied, array $poststates, bool $liveVerify, ?PDO $pdo = null, ?array $terminal = null): void
    {
        if ($liveVerify && array_intersect(self::MENU_MIGRATIONS, $applied) !== []) { self::assertBusinessTerminalState($pdo, $applied, $poststates, $terminal); }
        foreach (array_intersect(self::MENU_MIGRATIONS, $applied) as $id) {
            $record = $poststates[$id] ?? null;
            if (!is_array($record) || ($record['contract'] ?? null) !== MenuSchemaMigrationProof::class
                || ($record['sha256'] ?? null) !== self::PRESTATE_SQL[$id]
                || ($record['proof']['business_postconditions_verified'] ?? null) !== true) {
                throw new RuntimeException('APPLICATION_SCHEMA_BUSINESS_PROOF_REQUIRED');
            }
        }
    }

    /** Private finite facts are retained only by the current native stage callback. */
    public static function permissionMigrationBefore(PDO $pdo, array $migration): ?array
    {
        if (!in_array($migration['id'] ?? null, self::PERMISSION_MIGRATIONS, true)) { return null; }
        [$id, , $sha] = self::prestateIdentity($migration);
        if (!class_exists(PermissionSchemaMigrationProof::class)) { throw new RuntimeException('APPLICATION_SCHEMA_PERMISSION_CONTRACT_UNAVAILABLE'); }
        $before = PermissionSchemaMigrationProof::before($pdo, $migration);
        if ($pdo->inTransaction() || ($before['migration_id'] ?? null) !== $id || ($before['source_sha256'] ?? null) !== $sha
            || ($before['readonly'] ?? null) !== true || !is_array($before['safe_summary'] ?? null) || !is_array($before['private_state'] ?? null)) {
            throw new RuntimeException('APPLICATION_SCHEMA_PERMISSION_INPUT_INVALID');
        }
        return $before;
    }

    /** Original batch clocks reach Identity directly; the host never approximates timestamps. */
    public static function permissionMigrationAfter(PDO $pdo, array $migration, array $before, ?array $executionBounds): array
    {
        [$id, , $sha] = self::prestateIdentity($migration);
        if (!in_array($id, self::PERMISSION_MIGRATIONS, true) || ($before['migration_id'] ?? null) !== $id
            || ($before['source_sha256'] ?? null) !== $sha) { throw new RuntimeException('APPLICATION_SCHEMA_PERMISSION_INPUT_INVALID'); }
        $clockRequired = $id !== '20261012-align-platform-ops-permission-ownership';
        if (($clockRequired && !is_array($executionBounds)) || (!$clockRequired && $executionBounds !== null)) {
            throw new RuntimeException('APPLICATION_SCHEMA_PERMISSION_CLOCK_REQUIRED');
        }
        $observed = PermissionSchemaMigrationProof::after($pdo, $before, $executionBounds);
        if ($pdo->inTransaction() || ($observed['migration_id'] ?? null) !== $id || ($observed['source_sha256'] ?? null) !== $sha
            || ($observed['readonly'] ?? null) !== true || ($observed['business_postconditions_verified'] ?? null) !== true
            || !is_int($observed['protected_checks'] ?? null) || $observed['protected_checks'] < 0
            || !is_int($observed['candidate_count'] ?? null) || $observed['candidate_count'] < 0
            || !array_key_exists('execution_bounds_scope', $observed)
            || $observed['execution_bounds_scope'] !== ($clockRequired ? 'immutable-sql-batch' : null)) {
            throw new RuntimeException('APPLICATION_SCHEMA_BUSINESS_POSTSTATE_REQUIRED');
        }
        return ['contract' => PermissionSchemaMigrationProof::class, 'scope' => 'permission-business-postconditions', 'sha256' => $sha,
            'proof' => array_intersect_key($observed, array_flip(['migration_id', 'source_sha256', 'readonly',
                'business_postconditions_verified', 'protected_checks', 'candidate_count', 'execution_bounds_scope']))];
    }

    /** A prior safe result cannot recreate the original private facts for a live comparison. */
    public static function assertPermissionPoststates(array $applied, array $poststates, bool $liveVerify, ?PDO $pdo = null, ?array $terminal = null): void
    {
        if ($liveVerify && array_intersect(self::PERMISSION_MIGRATIONS, $applied) !== []) { self::assertBusinessTerminalState($pdo, $applied, $poststates, $terminal); }
        foreach (array_intersect(self::PERMISSION_MIGRATIONS, $applied) as $id) {
            $record = $poststates[$id] ?? null;
            if (!is_array($record) || ($record['contract'] ?? null) !== PermissionSchemaMigrationProof::class
                || ($record['sha256'] ?? null) !== self::PRESTATE_SQL[$id]
                || ($record['proof']['business_postconditions_verified'] ?? null) !== true) {
                throw new RuntimeException('APPLICATION_SCHEMA_BUSINESS_PROOF_REQUIRED');
            }
        }
    }

    /** The full SMS input stays in the executing callback; public plans receive its safe_summary only. */
    public static function smsMigrationBefore(PDO $pdo, array $migration): ?array
    {
        if (($migration['id'] ?? null) !== '20260909-notification-sms-reservation') { return null; }
        self::prestateIdentity($migration);
        if (!class_exists(SmsReservationMigrationProof::class)) { throw new RuntimeException('APPLICATION_SCHEMA_SMS_CONTRACT_UNAVAILABLE'); }
        self::prestateConstraintNames($pdo, '20260909-notification-sms-reservation');
        // Preserve the existing SMS DML preconditions; Auth's DDL-only relaxation does not apply here.
        $table = self::prestateTable($pdo, 'pa_notice_log');
        self::prestateColumnShape('pa_notice_log', $table['columns']);
        self::prestateConstraintShape('pa_notice_log', $table);
        foreach (['id','tenant_id','channel','receiver','scene_id','status','send_time','verify_code_hash'] as $column) {
            if (!isset($table['columns'][$column])) { throw new RuntimeException('APPLICATION_SCHEMA_PRESTATE_COLUMN_MISSING:pa_notice_log.' . $column); }
        }
        self::prestateUnique($table, ['id']);
        $latest = "SELECT tenant_id,channel,receiver,MAX(id) AS id FROM pa_notice_log WHERE channel=1 AND scene_id>0 AND status=1 AND send_time>UNIX_TIMESTAMP()-60 GROUP BY tenant_id,channel,receiver";
        self::prestateZero($pdo, 'WITH latest AS (' . $latest . ') SELECT COUNT(*) FROM (SELECT l.tenant_id,l.channel,SHA2(l.receiver,256) AS receiver_hash FROM pa_notice_log l JOIN latest ON latest.id=l.id GROUP BY l.tenant_id,l.channel,SHA2(l.receiver,256) HAVING COUNT(*)>1) collisions');
        self::prestateZero($pdo, 'WITH latest AS (' . $latest . ') SELECT COUNT(*) FROM pa_notice_log l JOIN latest ON latest.id=l.id WHERE l.send_time>4294967235 OR l.receiver IS NULL OR l.tenant_id IS NULL');
        return SmsReservationMigrationProof::before($pdo);
    }

    /** The original business proof consumes the live same-PDO batch clock before applied. */
    public static function smsMigrationAfter(PDO $pdo, array $migration, array $before, ?array $executionBounds): array
    {
        [$id, , $sha] = self::prestateIdentity($migration);
        if ($id !== '20260909-notification-sms-reservation' || ($before['migration_id'] ?? null) !== $id
            || ($before['source_sha256'] ?? null) !== $sha || !is_array($executionBounds)) {
            throw new RuntimeException('APPLICATION_SCHEMA_BUSINESS_PROOF_REQUIRED');
        }
        $before['execution_bounds'] = $executionBounds;
        $observed = SmsReservationMigrationProof::after($pdo, $before);
        if ($pdo->inTransaction() || ($observed['migration_id'] ?? null) !== $id || ($observed['source_sha256'] ?? null) !== $sha
            || ($observed['readonly'] ?? null) !== true || ($observed['sms_reservation_verified'] ?? null) !== true
            || ($observed['execution_bounds_scope'] ?? null) !== 'immutable-sql-batch') {
            throw new RuntimeException('APPLICATION_SCHEMA_BUSINESS_POSTSTATE_REQUIRED');
        }
        $safe = array_intersect_key($observed, array_flip(['migration_id', 'source_sha256', 'sms_reservation_verified',
            'schema_sha256', 'historical_rows_sha256', 'rows', 'activated_rows', 'possible_execution_seconds', 'execution_bounds_scope', 'readonly']));
        return ['contract' => SmsReservationMigrationProof::class, 'scope' => 'sms-reservation-schema-and-data',
            'sha256' => $sha, 'proof' => $safe, 'proof_sha256' => self::digest($safe)];
    }

    /** Existing SMS summaries cannot recreate their private historical facts for a fresh comparison. */
    public static function assertSmsPoststates(array $applied, array $poststates, bool $liveVerify, ?PDO $pdo = null, ?array $terminal = null): void
    {
        $id = '20260909-notification-sms-reservation';
        if (!in_array($id, $applied, true)) { return; }
        if ($liveVerify) { self::assertBusinessTerminalState($pdo, $applied, $poststates, $terminal); }
        $record = $poststates[$id] ?? null;
        if (!is_array($record) || ($record['contract'] ?? null) !== SmsReservationMigrationProof::class
            || ($record['sha256'] ?? null) !== self::PRESTATE_SQL[$id]
            || ($record['proof']['migration_id'] ?? null) !== $id || ($record['proof']['source_sha256'] ?? null) !== self::PRESTATE_SQL[$id]
            || ($record['proof']['readonly'] ?? null) !== true || ($record['proof']['sms_reservation_verified'] ?? null) !== true
            || ($record['proof_sha256'] ?? null) !== self::digest($record['proof'])) {
            throw new RuntimeException('APPLICATION_SCHEMA_BUSINESS_PROOF_REQUIRED');
        }
    }

    /**
     * Seal only after every per-SQL business proof and all legal successors have completed.
     * The caller keeps this digest in its protected result, bound to its original recovery point.
     * No private before DTO or row payload survives the executing callback.
     */
    public static function businessTerminalState(PDO $pdo, array $applied, array $poststates): array
    {
        self::assertMenuPoststates($applied, $poststates, false);
        self::assertPermissionPoststates($applied, $poststates, false);
        self::assertSmsPoststates($applied, $poststates, false);
        if ($pdo->inTransaction()) { throw new RuntimeException('APPLICATION_SCHEMA_TERMINAL_TRANSACTION_INVALID'); }
        $sources = [];
        foreach ($applied as $id) {
            if (!isset(self::PRESTATE_SQL[$id])) { throw new RuntimeException('APPLICATION_SCHEMA_TERMINAL_SOURCE_UNKNOWN'); }
            $sources[$id] = self::PRESTATE_SQL[$id];
        }
        ksort($sources, SORT_STRING);
        $tables = [];
        if (array_intersect(self::MENU_MIGRATIONS, $applied) !== []) { $tables = ['pa_system_menu', 'pa_menu_definition']; }
        if (array_intersect(self::PERMISSION_MIGRATIONS, $applied) !== []) {
            $tables = [...$tables, 'pa_permission', 'pa_role_permission', 'pa_role', 'pa_tenant', 'pa_menu_definition', 'pa_system_menu',
                'pa_platform_role_permission', 'pa_resource_operation_permission', 'pa_resource_operation_target_type',
                'pa_auth_security_event', 'pa_tenant_session'];
        }
        if (in_array('20260909-notification-sms-reservation', $applied, true)) { $tables[] = 'pa_notice_log'; }
        $tables = array_values(array_unique($tables)); sort($tables, SORT_STRING);
        $pdo->exec('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
        $pdo->exec('SET TRANSACTION READ ONLY');
        $pdo->beginTransaction();
        try {
            $database = $pdo->query('SELECT DATABASE() AS database_name,@@server_uuid AS server_uuid')->fetch(PDO::FETCH_ASSOC);
            $result = [];
            foreach ($tables as $table) {
                $shape = self::prestateTable($pdo, $table);
                $parts = [];
                foreach (array_keys($shape['columns']) as $column) {
                    $field = '`' . str_replace('`', '``', $column) . '`';
                    // Fixed-width hashes plus a NULL tag preserve binary values and multiplicity.
                    $parts[] = "IF($field IS NULL,CONCAT('N',REPEAT('0',64)),CONCAT('S',SHA2(CAST($field AS BINARY),256)))";
                }
                if ($parts === []) { throw new RuntimeException('APPLICATION_SCHEMA_TERMINAL_COLUMNS_MISSING'); }
                $create = $pdo->query('SHOW CREATE TABLE `' . $table . '`')->fetch(PDO::FETCH_NUM);
                $statement = $pdo->query('SELECT SHA2(CONCAT(' . implode(',', $parts) . '),256) AS row_digest FROM `' . $table . '` ORDER BY row_digest');
                $hash = hash_init('sha256'); $count = 0;
                while (($row = $statement->fetchColumn()) !== false) { hash_update($hash, $row . "\n"); ++$count; }
                $statement->closeCursor();
                $result[$table] = ['schema_sha256' => hash('sha256', (string) $create[1]), 'rows' => $count, 'rows_sha256' => hash_final($hash)];
            }
            return ['protocol' => 'peanut.application-business-terminal.v1', 'sources' => $sources,
                'proofs_sha256' => self::digest($poststates), 'database' => $database, 'tables' => $result];
        } finally { $pdo->rollBack(); }
    }

    private static function assertBusinessTerminalState(?PDO $pdo, array $applied, array $poststates, ?array $terminal): void
    {
        if ($pdo === null || $terminal === null || ($terminal['protocol'] ?? null) !== 'peanut.application-business-terminal.v1'
            || self::digest($terminal) !== self::digest(self::businessTerminalState($pdo, $applied, $poststates))) {
            throw new RuntimeException('APPLICATION_SCHEMA_BUSINESS_LIVE_POSTSTATE_CHANGED');
        }
    }

    /** Both actual native callers hold this metadata-only input privately until completion. */
    public static function authMigrationBefore(PDO $pdo, array $migration): ?array
    {
        if (($migration['id'] ?? null) !== '20261007-auth-client-schema') { return null; }
        [$id, , $sha] = self::prestateIdentity($migration);
        if (!class_exists(AuthClientSchemaMigrationProof::class)) { throw new RuntimeException('APPLICATION_SCHEMA_AUTH_CONTRACT_UNAVAILABLE'); }
        $names = self::prestateConstraintNames($pdo, $id);
        try { $before = AuthClientSchemaMigrationProof::before($pdo); }
        catch (RuntimeException $exception) {
            $code = match ($exception->getMessage()) {
                'IDENTITY_AUTH_CLIENT_PROOF_TARGET_UNSUPPORTED' => 'APPLICATION_SCHEMA_AUTH_TARGET_UNSUPPORTED',
                'IDENTITY_AUTH_CLIENT_PROOF_CLIENT_PRESTATE_INVALID' => 'APPLICATION_SCHEMA_AUTH_CLIENT_PRESTATE_INVALID',
                default => null,
            };
            if ($code === null || $exception->getPrevious() !== null) { throw $exception; }
            throw new RuntimeException($code, 0, $exception);
        }
        if ($pdo->inTransaction() || ($before['migration_id'] ?? null) !== $id || ($before['source_sha256'] ?? null) !== $sha
            || ($before['readonly'] ?? null) !== true || ($before['pdo_object_id'] ?? null) !== spl_object_id($pdo)) {
            throw new RuntimeException('APPLICATION_SCHEMA_BUSINESS_PROOF_REQUIRED');
        }
        return $before + ['constraint_names' => $names, 'safe_summary' => ['migration_id' => $id, 'source_sha256' => $sha,
            'readonly' => true, 'target_metadata_sha256' => self::digest(array_intersect_key($before, array_flip(['tables', 'client_check', 'ip_index'])))]];
    }

    public static function authMigrationAfter(PDO $pdo, array $migration, array $before): array
    {
        [$id, , $sha] = self::prestateIdentity($migration);
        if ($id !== '20261007-auth-client-schema' || ($before['migration_id'] ?? null) !== $id
            || ($before['source_sha256'] ?? null) !== $sha) { throw new RuntimeException('APPLICATION_SCHEMA_BUSINESS_PROOF_REQUIRED'); }
        $observed = AuthClientSchemaMigrationProof::after($pdo, $before);
        if ($pdo->inTransaction() || ($observed['migration_id'] ?? null) !== $id || ($observed['source_sha256'] ?? null) !== $sha
            || ($observed['readonly'] ?? null) !== true || ($observed['target_metadata_verified'] ?? null) !== true
            || !is_array($observed['tables'] ?? null) || !is_array($observed['client_check'] ?? null) || !is_array($observed['ip_index'] ?? null)) {
            throw new RuntimeException('APPLICATION_SCHEMA_BUSINESS_POSTSTATE_REQUIRED');
        }
        return ['contract' => AuthClientSchemaMigrationProof::class, 'scope' => 'target-schema-metadata', 'sha256' => $sha,
            'target_metadata_sha256' => self::digest(array_intersect_key($observed, array_flip(['tables', 'client_check', 'ip_index']))),
            'proof' => array_intersect_key($observed, array_flip(['migration_id', 'source_sha256', 'readonly', 'target_metadata_verified']))];
    }

    /** Reuse the existing Auth target-metadata comparison; it makes no historical-row claim. */
    public static function assertAuthPoststates(PDO $pdo, array $applied, array $poststates, bool $liveVerify): void
    {
        $id = '20261007-auth-client-schema';
        if (!in_array($id, $applied, true)) { return; }
        $record = $poststates[$id] ?? null;
        if (!is_array($record) || ($record['contract'] ?? null) !== AuthClientSchemaMigrationProof::class
            || ($record['sha256'] ?? null) !== self::PRESTATE_SQL[$id]
            || ($record['proof']['migration_id'] ?? null) !== $id || ($record['proof']['source_sha256'] ?? null) !== self::PRESTATE_SQL[$id]
            || ($record['proof']['readonly'] ?? null) !== true || ($record['proof']['target_metadata_verified'] ?? null) !== true) {
            throw new RuntimeException('APPLICATION_SCHEMA_BUSINESS_PROOF_REQUIRED');
        }
        if ($liveVerify) {
            $observed = AuthClientSchemaMigrationProof::before($pdo);
            if ($pdo->inTransaction() || ($observed['migration_id'] ?? null) !== $id || ($observed['source_sha256'] ?? null) !== self::PRESTATE_SQL[$id]
                || ($observed['readonly'] ?? null) !== true || ($record['target_metadata_sha256'] ?? null) !== self::digest(
                    array_intersect_key($observed, array_flip(['tables', 'client_check', 'ip_index'])))) {
                throw new RuntimeException('APPLICATION_SCHEMA_BUSINESS_POSTSTATE_REQUIRED');
            }
        }
    }

    /** Schema-scoped names in the fixed SQL, including dynamic ALTER strings. */
    private static function prestateConstraintNames(PDO $pdo, string $id): array
    {
        // All nine immutable inputs are enumerated. Index/unique names are table
        // scoped and checked separately. These SQL files create no persistent FK.
        $expected = match ($id) {
            '20260909-notification-sms-reservation' => ['chk_notice_sms_reservation_active' => ['table' => 'pa_notice_log', 'existing' => false]],
            '20261007-auth-client-schema' => ['chk_tenant_session_client' => ['table' => 'pa_tenant_session', 'existing' => true]],
            '20261019-module-migration-provenance-metadata' => [
                'chk_module_migration_resolution' => ['table' => 'pa_module_migration', 'existing' => true],
                'chk_module_migration_provenance' => ['table' => 'pa_module_migration', 'existing' => true],
            ],
            '20261004-group-default-navigation', '20261004-menu-stable-identities',
            '20261007-readiness-permission', '20261012-align-department-canonical-permissions',
            '20261012-align-platform-ops-permission-ownership', '20261012-repair-seeded-permission-parents' => [],
            default => throw new RuntimeException('APPLICATION_SCHEMA_PRESTATE_SQL_UNKNOWN'),
        };
        // The assertion CHECKs belong to session-local temporary tables, not the
        // persistent schema namespace; their predicates are proved by the CTEs.
        $temporary = match ($id) {
            '20261012-align-department-canonical-permissions' => ['chk_dept_permission_assertion', 'chk_dept_action_assertion'],
            '20261012-align-platform-ops-permission-ownership' => ['chk_platform_ops_permission_owner_assertion'],
            default => [],
        };
        if ($expected === []) { return ['persistent' => [], 'temporary_checks' => $temporary]; }
        $database = (string) $pdo->query('SELECT DATABASE()')->fetchColumn();
        if (preg_match('/\A[A-Za-z0-9_]+\z/D', $database) !== 1) { throw new RuntimeException('APPLICATION_SCHEMA_PRESTATE_CONSTRAINT_VISIBILITY_UNKNOWN'); }
        // Match the native File proof: INFORMATION_SCHEMA alone can hide owners.
        // Direct whole-schema SELECT/ALL grants establish visibility; role-only,
        // unrecognized or partially revoked grants cannot prove name absence.
        $mode = $pdo->query('SELECT @@GLOBAL.partial_revokes AS partial_revokes')->fetchColumn();
        if (!in_array($mode, [0, 1, '0', '1'], true)) { throw new RuntimeException('APPLICATION_SCHEMA_PRESTATE_CONSTRAINT_VISIBILITY_UNKNOWN'); }
        $scope = '`' . $database . '`.*';
        $scopes = ['*.*', $scope];
        if ((string) $mode === '0') { $scopes[] = str_replace('_', '\\_', $scope); }
        $visible = false; $restricted = false;
        foreach ($pdo->query('SHOW GRANTS')->fetchAll(PDO::FETCH_NUM) as $row) {
            $grant = count($row) === 1 ? $row[0] : null;
            if (is_string($grant) && str_starts_with($grant, 'REVOKE ')) { $restricted = true; }
            if (!is_string($grant) || preg_match('/\AGRANT (.+) ON (.+) TO /D', $grant, $match) !== 1
                || !in_array($match[2], $scopes, true)) { continue; }
            $privileges = array_map('trim', explode(',', $match[1]));
            $visible = $visible || in_array('SELECT', $privileges, true) || in_array('ALL PRIVILEGES', $privileges, true);
        }
        if (!$visible || $restricted) { throw new RuntimeException('APPLICATION_SCHEMA_PRESTATE_CONSTRAINT_VISIBILITY_UNKNOWN'); }
        $query = $pdo->prepare("SELECT TABLE_NAME,CONSTRAINT_NAME,CONSTRAINT_TYPE FROM information_schema.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA=DATABASE() AND CONSTRAINT_TYPE IN ('CHECK','FOREIGN KEY') AND CONSTRAINT_NAME IN ("
            . implode(',', array_fill(0, count($expected), '?')) . ') ORDER BY CONSTRAINT_NAME,TABLE_NAME,CONSTRAINT_TYPE');
        $query->execute(array_keys($expected)); $owners = $query->fetchAll(PDO::FETCH_ASSOC); $seen = [];
        foreach ($owners as $row) {
            $name = $row['CONSTRAINT_NAME']; $owner = $expected[$name] ?? null;
            if (!is_array($owner) || !$owner['existing'] || $row['CONSTRAINT_TYPE'] !== 'CHECK'
                || $row['TABLE_NAME'] !== $owner['table'] || isset($seen[$name])) { throw new RuntimeException('APPLICATION_SCHEMA_PRESTATE_CONSTRAINT_NAME_CONFLICT'); }
            $seen[$name] = true;
        }
        // Existing provenance names still require the exact complete no-op shape;
        // the auth CHECK is replaced only on its expected owner, after row proof.
        return ['whole_schema_visibility' => true, 'persistent' => $expected, 'observed_owners' => $owners, 'temporary_checks' => $temporary];
    }

    private static function prestateIdentity(array $migration): array
    {
        $id = $migration['id'] ?? null; $sql = $migration['sql'] ?? null;
        $sha = $migration['checksum'] ?? $migration['sha256'] ?? null;
        if (!is_string($id) || !isset(self::PRESTATE_SQL[$id]) || !is_string($sql) || !is_string($sha)
            || $sha !== self::PRESTATE_SQL[$id] || !hash_equals($sha, hash('sha256', $sql))
            || (isset($migration['sha256']) && $migration['sha256'] !== $sha)) {
            throw new RuntimeException('APPLICATION_SCHEMA_PRESTATE_SQL_UNKNOWN');
        }
        return [$id, $sql, $sha];
    }

    /** Schema facts include exact named checks/index components, not column counts alone. */
    private static function prestateTable(PDO $pdo, string $table): array
    {
        $query = $pdo->prepare('SELECT TABLE_TYPE,ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?');
        $query->execute([$table]); $shape = $query->fetch(PDO::FETCH_ASSOC);
        if (!is_array($shape) || $shape['TABLE_TYPE'] !== 'BASE TABLE' || $shape['ENGINE'] !== 'InnoDB') { throw new RuntimeException('APPLICATION_SCHEMA_PRESTATE_TABLE_UNKNOWN:' . $table); }
        $query = $pdo->prepare('SELECT COUNT(*) FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA=DATABASE() AND EVENT_OBJECT_TABLE=?');
        $query->execute([$table]);
        if ((int) $query->fetchColumn() !== 0) { throw new RuntimeException('APPLICATION_SCHEMA_PRESTATE_TRIGGER_UNKNOWN:' . $table); }
        $query = $pdo->prepare('SELECT COLUMN_NAME,COLUMN_TYPE,DATA_TYPE,IS_NULLABLE,COLUMN_DEFAULT,CHARACTER_SET_NAME,COLLATION_NAME,EXTRA FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? ORDER BY ORDINAL_POSITION');
        $query->execute([$table]); $columns = [];
        foreach ($query->fetchAll(PDO::FETCH_ASSOC) as $row) { $columns[$row['COLUMN_NAME']] = $row; }
        $query = $pdo->prepare('SELECT INDEX_NAME,COLUMN_NAME,NON_UNIQUE,SEQ_IN_INDEX,SUB_PART,INDEX_TYPE,IS_VISIBLE,EXPRESSION FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? ORDER BY INDEX_NAME,SEQ_IN_INDEX');
        $query->execute([$table]); $indexes = [];
        foreach ($query->fetchAll(PDO::FETCH_ASSOC) as $row) { $indexes[$row['INDEX_NAME']][] = $row; }
        $query = $pdo->prepare("SELECT t.CONSTRAINT_NAME,t.ENFORCED,c.CHECK_CLAUSE FROM information_schema.TABLE_CONSTRAINTS t JOIN information_schema.CHECK_CONSTRAINTS c ON c.CONSTRAINT_SCHEMA=t.CONSTRAINT_SCHEMA AND c.CONSTRAINT_NAME=t.CONSTRAINT_NAME WHERE t.CONSTRAINT_SCHEMA=DATABASE() AND t.TABLE_NAME=? AND t.CONSTRAINT_TYPE='CHECK'");
        $query->execute([$table]); $checks = [];
        foreach ($query->fetchAll(PDO::FETCH_ASSOC) as $row) { $checks[$row['CONSTRAINT_NAME']] = $row; }
        return ['columns' => $columns, 'indexes' => $indexes, 'checks' => $checks];
    }

    private static function prestateUnique(array $table, array $columns): void
    {
        foreach ($table['indexes'] as $rows) {
            if (array_column($rows, 'COLUMN_NAME') === $columns && count(array_filter($rows, static fn(array $row): bool => (int) $row['NON_UNIQUE'] !== 0 || $row['SUB_PART'] !== null || $row['EXPRESSION'] !== null || $row['INDEX_TYPE'] !== 'BTREE' || $row['IS_VISIBLE'] !== 'YES')) === 0) { return; }
        }
        throw new RuntimeException('APPLICATION_SCHEMA_PRESTATE_UNIQUE_UNKNOWN');
    }

    /** Compare existing columns with the native source definitions, without executing them. */
    private static function prestateColumnShape(string $table, array $columns): void
    {
        $shape = self::prestateNativeTable($table);
        preg_match_all('/^\s*`([a-z0-9_]+)`\s+([A-Z]+(?:\([0-9,]+\))?(?:\s+UNSIGNED)?)([^\r\n]*)/m', $shape, $definitions, PREG_SET_ORDER);
        $native = [];
        foreach ($definitions as $definition) { $native[$definition[1]] = [$definition[2], $definition[3]]; }
        $normalize = static fn(string $type): string => (string) preg_replace('/\b(tinyint|smallint|mediumint|int|bigint)\([0-9]+\)/', '$1', strtolower((string) preg_replace('/\s+/', ' ', trim($type))));
        foreach ($columns as $name => $row) {
            if (!isset($native[$name]) || $normalize($row['COLUMN_TYPE']) !== $normalize($native[$name][0])
                || (($row['IS_NULLABLE'] === 'NO') !== str_contains($native[$name][1], 'NOT NULL')
                    && !($table === 'pa_system_menu' && $name === 'menu_key' && $row['IS_NULLABLE'] === 'NO'))
                || str_contains($row['EXTRA'], 'GENERATED')) {
                throw new RuntimeException('APPLICATION_SCHEMA_PRESTATE_COLUMN_SHAPE_UNKNOWN:' . $table . '.' . $name);
            }
        }
    }

    private static function prestateNativeTable(string $table): string
    {
        static $source = null;
        $database = dirname(__DIR__, 4) . '/database';
        $source ??= (string) file_get_contents($database . '/init.sql') . "\n" . (string) file_get_contents($database . '/schema/KernelSchema.php');
        if (preg_match('/CREATE TABLE `' . preg_quote($table, '/') . '` \((.*?)\)\s*ENGINE=/s', $source, $match) !== 1) {
            throw new RuntimeException('APPLICATION_SCHEMA_PRESTATE_NATIVE_SHAPE_MISSING:' . $table);
        }
        return $match[1];
    }

    private static function prestateConstraintShape(string $table, array $fact): void
    {
        $source = self::prestateNativeTable($table);
        preg_match_all('/(?:PRIMARY KEY|UNIQUE KEY `[^`]+`)\s*\(([^\r\n]+)\)/', $source, $matches);
        $unique = [];
        foreach ($matches[1] as $columns) {
            preg_match_all('/`([^`]+)`/', $columns, $names); $unique[] = $names[1];
        }
        if ($table === 'pa_system_menu' && isset($fact['columns']['menu_key'])) { $unique[] = ['menu_key']; }
        foreach ($fact['indexes'] as $rows) {
            if ((int) $rows[0]['NON_UNIQUE'] !== 0) { continue; }
            if (!in_array(array_column($rows, 'COLUMN_NAME'), $unique, true)
                || count(array_filter($rows, static fn(array $row): bool => $row['SUB_PART'] !== null || $row['EXPRESSION'] !== null || $row['INDEX_TYPE'] !== 'BTREE' || $row['IS_VISIBLE'] !== 'YES')) !== 0) {
                throw new RuntimeException('APPLICATION_SCHEMA_PRESTATE_UNIQUE_SHAPE_UNKNOWN:' . $table);
            }
        }
        preg_match_all('/CONSTRAINT `([^`]+)` CHECK/', $source, $matches);
        foreach ($fact['checks'] as $name => $check) {
            if (!in_array($name, $matches[1], true) || $check['ENFORCED'] !== 'YES') { throw new RuntimeException('APPLICATION_SCHEMA_PRESTATE_CHECK_SHAPE_UNKNOWN:' . $table); }
        }
        if ($table === 'pa_permission' && array_diff($matches[1], array_keys($fact['checks'])) !== []) {
            throw new RuntimeException('APPLICATION_SCHEMA_PRESTATE_CHECK_SHAPE_UNKNOWN:' . $table);
        }
    }

    private static function prestateCount(PDO $pdo, string $select): int
    {
        return (int) $pdo->query($select)->fetchColumn();
    }

    private static function prestateZero(PDO $pdo, string $select): void
    {
        if (self::prestateCount($pdo, $select) !== 0) { throw new RuntimeException('APPLICATION_SCHEMA_PRESTATE_PREDICATE_FAILED'); }
    }

    private static function prestateProvenance(PDO $pdo, array $fact): array
    {
        $columns = array_intersect(['resolution_kind','provenance_json'], array_keys($fact['columns']));
        $checks = array_intersect(['chk_module_migration_resolution','chk_module_migration_provenance'], array_keys($fact['checks']));
        if ($columns === [] && $checks === []) { return ['shape' => 'before_additions', 'existing_rows_retain_unknown_origin' => true]; }
        if (count($columns) !== 2 || count($checks) !== 2) { throw new RuntimeException('APPLICATION_SCHEMA_PRESTATE_PROVENANCE_PARTIAL'); }
        foreach (['resolution_kind' => 'varchar(16)', 'provenance_json' => 'json'] as $column => $type) {
            $row = $fact['columns'][$column];
            if ($row['COLUMN_TYPE'] !== $type || $row['IS_NULLABLE'] !== 'YES' || $row['COLUMN_DEFAULT'] !== null) { throw new RuntimeException('APPLICATION_SCHEMA_PRESTATE_PROVENANCE_DIFFERENT'); }
        }
        $expected = ['chk_module_migration_resolution' => "((resolution_kindisnull)or(resolution_kindin('executed','adopted')))",
            'chk_module_migration_provenance' => "((resolution_kind<=>'adopted')=(provenance_jsonisnotnull))"];
        foreach ($expected as $name => $clause) {
            $row = $fact['checks'][$name];
            $normalized = strtolower(str_replace(['`',' ',"\n",'_utf8mb4'], '', $row['CHECK_CLAUSE']));
            // MySQL metadata may escape literal delimiters; admit only the complete
            // expected rendering, never strip arbitrary backslashes from a clause.
            if ($row['ENFORCED'] !== 'YES' || !in_array($normalized, [$clause, str_replace("'", "\\'", $clause)], true)) { throw new RuntimeException('APPLICATION_SCHEMA_PRESTATE_PROVENANCE_DIFFERENT'); }
        }
        self::prestateZero($pdo, "SELECT COUNT(*) FROM pa_module_migration WHERE NOT ((resolution_kind IS NULL OR resolution_kind IN ('executed','adopted')) AND ((resolution_kind<=>'adopted')=(provenance_json IS NOT NULL)))");
        return ['shape' => 'exact_noop'];
    }

    /** Native source maintenance; facts never substitute for the current resource grant. */
    public function sourceDevelopment(string $operation, array $actor, string $planPath, string $resultPath, string $maintenancePath, string $recoveryPath, string $backupTool, string $backupOutput, string $metadataTool = '', string $fileRelocationPath = ''): array
    {
        if ($fileRelocationPath !== '') {
            if (($actor['scope'] ?? '') !== 'source-development' || !in_array($operation, ['preflight', 'backup', 'apply', 'verify', 'snapshot-failure', 'restore-failure'], true)) {
                throw new RuntimeException('APPLICATION_SCHEMA_FILE_RELOCATION_SCOPE_INVALID');
            }
            $this->fileRelocationInput = FileAdoptionRelocation::input($fileRelocationPath, $this->serverRoot, in_array($operation, ['verify', 'snapshot-failure', 'restore-failure'], true));
        }
        $run = fn(): array => $this->sourceDevelopmentHeld($operation, $actor, $planPath, $resultPath, $maintenancePath, $recoveryPath, $backupTool, $backupOutput, $metadataTool);
        if (($actor['scope'] ?? 'source-development') !== 'source-application') { return $run(); }
        if (in_array($operation, ['snapshot-failure', 'restore-failure'], true)) {
            throw new RuntimeException('SOURCE_APPLICATION_USE_WHOLE_RECOVERY_REQUIRED');
        }
        $facts = $this->sourceDevelopmentAdmission($actor, in_array($operation, ['apply', 'restore-failure', 'snapshot-failure'], true));
        return \PeanutServerUpdatePlan::withSourceApplicationMaintenance($this->serverRoot, $facts['source'],
            function () use ($run, $operation, $facts, $maintenancePath): array {
                try {
                    $result = $run();
                    if ($operation === 'apply') { SourceApplicationBackup::sourceApplicationStage($facts, $maintenancePath, 'completed', $result['result_path']); }
                    return $result;
                } catch (\Throwable $failure) {
                    if ($operation === 'apply') {
                        try { SourceApplicationBackup::sourceApplicationStage($facts, $maintenancePath, 'failed'); }
                        catch (\Throwable) { /* Existing journal/in-use UNKNOWN boundary remains protective. */ }
                    }
                    throw $failure;
                }
            });
    }

    private function sourceDevelopmentHeld(string $operation, array $actor, string $planPath, string $resultPath, string $maintenancePath, string $recoveryPath, string $backupTool, string $backupOutput, string $metadataTool = ''): array
    {
        $this->sourceMetadataTool = $metadataTool;
        $backupOperation = in_array($operation, ['backup', 'snapshot-failure'], true);
        $restoreOperation = $operation === 'restore-failure';
        if (!in_array($operation, ['preflight', 'apply', 'verify', 'verify-receipt', 'backup', 'snapshot-failure', 'restore-failure'], true)
            || ($operation === 'verify-receipt' && $metadataTool !== '')
            || ($operation === 'preflight' && ($planPath !== '' || $resultPath !== ''))
            || ($operation === 'apply' && ($planPath === '' || $resultPath !== ''))
            || (!$restoreOperation && (($maintenancePath === '') !== ($recoveryPath === '')))
            || ($backupOperation && ($planPath === '' || $resultPath !== '' || $maintenancePath !== '' || $recoveryPath !== '' || $backupTool === '' || $backupOutput === ''))
            || ($restoreOperation && ($planPath === '' || $resultPath !== '' || $maintenancePath === '' || $recoveryPath !== '' || $backupTool === '' || $backupOutput === '' || $metadataTool === ''))
            || (!$backupOperation && !$restoreOperation && ($backupTool !== '' || $backupOutput !== ''))
            || (in_array($operation, ['verify', 'verify-receipt'], true) && ($resultPath === '' || $planPath !== '' || $maintenancePath !== '' || $recoveryPath !== ''))) {
            throw new RuntimeException('APPLICATION_SCHEMA_ARGUMENTS_INVALID');
        }
        $context = app()->make(\app\common\execution\CurrentExecutionContext::class)->current();
        if (!$context instanceof \app\common\execution\InstanceExecutionContext
            || $context->operation() !== 'console.schema:application' || $context->instance->actorKey !== 'console' || PHP_SAPI !== 'cli'
            || config('peanut.environment') !== 'development' || !app()->isDebug()
            || (($actor['scope'] ?? 'source-development') !== 'source-application' && !\installationSourceDevelopmentMode($this->serverRoot))
            || !in_array(config('deployment.mode'), ['standalone', 'multi-tenant'], true)) {
            throw new RuntimeException('APPLICATION_SCHEMA_SOURCE_CONTEXT_REQUIRED');
        }
        // File/registry/actor/native lease admission must precede master initialization.
        $ddl = in_array($operation, ['apply', 'snapshot-failure', 'restore-failure'], true);
        $admission = $this->sourceDevelopmentAdmission($actor, $ddl);
        $config = \guardedDatabaseConfig();
        $reads = match ($operation) {
            'verify-receipt' => ['pa_schema_migration'],
            'preflight', 'apply', 'verify', 'snapshot-failure', 'restore-failure' => self::SOURCE_READ_TABLES,
            default => SourceApplicationBackup::applicationTables(array_keys(self::PRESTATE_SQL)),
        };
        if ($this->fileRelocationInput !== null) { $reads = array_values(array_unique(array_merge($reads, FileAdoptionRelocation::READ_TABLES, ['pa_tenant_member', 'pa_config', 'pa_plugin_module']))); }
        $this->assertSourceReadScope($config, $reads);
        $connection = \think\facade\Db::connect();
        foreach (['hostname' => 'host', 'hostport' => 'port', 'database' => 'database', 'username' => 'user', 'password' => 'password'] as $native => $guarded) {
            if ((string) $connection->getConfig($native) !== (string) $config[$guarded]) { throw new RuntimeException('APPLICATION_SCHEMA_MASTER_MISMATCH'); }
        }
        if ($connection->getConfig('break_reconnect') !== false || (int) $connection->getConfig('deploy') !== 0
            || $connection->getConfig('prefix') !== 'pa_') { throw new RuntimeException('APPLICATION_SCHEMA_MASTER_MISMATCH'); }
        $connection->query('SELECT CONNECTION_ID()', [], true);
        $pdo = $connection->getPdo();
        if (!$pdo instanceof PDO || $pdo->inTransaction()) { throw new RuntimeException('APPLICATION_SCHEMA_MASTER_MISMATCH'); }
        if ($this->fileRelocationInput !== null && in_array($operation, ['preflight', 'backup', 'apply'], true) && FileAdoptionRelocation::marker($pdo) !== null) {
            throw new RuntimeException('APPLICATION_SCHEMA_FILE_RELOCATION_ALREADY_STARTED');
        }
        $facts = fn(): array => $this->sourceDevelopmentFacts($pdo, $connection, $actor, $context->requestId(), $ddl);
        $initial = $facts();
        if (!$this->sourceSame(array_diff_key($initial, ['database' => true]), $admission)) { throw new RuntimeException('APPLICATION_SCHEMA_SOURCE_DRIFT'); }
        if ($operation === 'verify-receipt') {
            return \app\common\infrastructure\scaffold\ScaffoldUpgradeRunner::sourceProductLock(dirname($this->serverRoot),
                fn(): array => $this->verifySourceReceipt($pdo, $initial, $facts, $resultPath));
        }
        $files = \applicationMigrationFiles($this->serverRoot . '/database');
        if ($this->fileRelocationInput !== null) {
            $path = $this->serverRoot . '/database/maintenance/' . FileAdoptionRelocation::ID . '.sql';
            if (realpath($path) !== $path || !is_file($path) || hash_file('sha256', $path) !== FileAdoptionRelocation::SQL_SHA) {
                throw new RuntimeException('APPLICATION_SCHEMA_FILE_RELOCATION_SQL_CHANGED');
            }
            $files[] = $path;
        }
        $versions = \applicationReleaseVersions($this->serverRoot);
        $target = \applicationMigrationTargetVersion($this->serverRoot, $versions);
        if ($operation === 'snapshot-failure') {
            return $this->sourceFailureSnapshot($pdo, $initial, $facts, $files, $planPath, $backupTool, $backupOutput);
        }
        if ($restoreOperation) {
            return $this->sourceFailureRestore($pdo, $initial, $facts, $files, $planPath, $maintenancePath, $backupTool, $backupOutput);
        }
        $runner = new ApplicationMigrationRunner($config, $pdo);
        if ($operation === 'backup') {
            return $this->sourceDevelopmentBackup($pdo, $runner, $initial, $facts, $files, $target, $versions, $planPath, $backupTool, $backupOutput);
        }
        if ($operation === 'preflight') {
            return \app\common\infrastructure\scaffold\ScaffoldUpgradeRunner::sourceProductLock(dirname($this->serverRoot),
                function () use ($runner, $pdo, $initial, $facts, $files, $target, $versions, $maintenancePath, $recoveryPath): array {
                    $plan = null;
                    $runner->run($files, $target, $versions['release_sequence_version'], true, null,
                        function (PDO $actual, array $selection) use ($pdo, $initial, $facts, $files, $target, $versions, $maintenancePath, $recoveryPath, &$plan): void {
                            if ($actual !== $pdo) { throw new RuntimeException('APPLICATION_SCHEMA_MASTER_MISMATCH'); }
                            $plan = $this->sourceDevelopmentPlan($pdo, $initial, $facts, $files, $target, $versions, $maintenancePath, $recoveryPath);
                            if ($selection['pending'] !== $plan['baseline']['pending']) { throw new RuntimeException('APPLICATION_SCHEMA_LEDGER_CHANGED'); }
                        });
                    return $plan;
                });
        }
        if ($operation === 'verify' && (realpath(dirname($resultPath)) !== $this->serverRoot . '/private/installation/application-schema/source-development'
            || preg_match('/\Aresult-[a-f0-9]{32}\.json\z/D', basename($resultPath)) !== 1)) { throw new RuntimeException('APPLICATION_SCHEMA_RESULT_ORIGIN_INVALID'); }
        $expected = $this->sourceProtectedJson($operation === 'apply' ? $planPath : $resultPath);
        $this->assertSourceDocument($expected, $operation === 'apply' ? 'peanut.source-development-schema-plan.v1' : 'peanut.source-development-schema-result.v1');
        if (!$this->sourceSame($expected['file_relocation_input'] ?? null, $this->fileRelocationInput)) {
            throw new RuntimeException('APPLICATION_SCHEMA_FILE_RELOCATION_INPUT_CHANGED');
        }
        if (($expected['metadata_tool'] ?? '') !== $this->sourceMetadataTool) { throw new RuntimeException('APPLICATION_SCHEMA_PLAN_STALE'); }
        return \app\common\infrastructure\scaffold\ScaffoldUpgradeRunner::sourceProductLock(dirname($this->serverRoot),
            function () use ($operation, $pdo, $runner, $files, $target, $versions, $expected, $facts, $initial, $maintenancePath, $recoveryPath, $planPath): array {
                $current = $facts();
                if ($current !== $initial) { throw new RuntimeException('APPLICATION_SCHEMA_SOURCE_DRIFT'); }
                $binding = $expected['binding'] ?? null;
                if (!is_array($binding) || !$this->sourceSame($binding['source'] ?? null, $current['source'])
                    || !$this->sourceSame($binding['database'] ?? null, $current['database'])
                    || !$this->sourceSame($binding['installation_originals'] ?? null, $current['installation_originals'])
                    || ($binding['registry_sha256'] ?? null) !== $current['registry_sha256']) {
                    throw new RuntimeException('APPLICATION_SCHEMA_PLAN_STALE');
                }
                if ($operation === 'apply' && !$this->sourceSame($binding, $current)) {
                    throw new RuntimeException('APPLICATION_SCHEMA_PLAN_STALE');
                }
                $baseline = $expected['baseline'] ?? null;
                if (!is_array($baseline) || ($operation === 'apply' && !is_array($expected['backup_scope'] ?? null))) {
                    throw new RuntimeException('APPLICATION_SCHEMA_DOCUMENT_INVALID');
                }
                if ($operation === 'apply') {
                    $requirements = $expected['execution_requirements'] ?? null;
                    if (($expected['status'] ?? null) !== 'planned' || !is_array($requirements)
                        || array_filter($requirements, static fn($row): bool => !is_array($row)
                            || !in_array($row['code'] ?? null, ['APPLICATION_SCHEMA_DML_TRIGGER_EFFECT_REVIEW_REQUIRED',
                                'APPLICATION_SCHEMA_CASCADE_EFFECT_REVIEW_REQUIRED'], true)) !== []) {
                        throw new RuntimeException('APPLICATION_SCHEMA_SOURCE_PLAN_BLOCKED');
                    }
                }
                $recheck = function () use ($facts, $initial): void {
                    \app\common\infrastructure\scaffold\ScaffoldUpgradeRunner::sourceProductLock(dirname($this->serverRoot), static fn() => null);
                    if ($facts() !== $initial) { throw new RuntimeException('APPLICATION_SCHEMA_SOURCE_DRIFT'); }
                };
                $proofs = [];
                if ($operation === 'apply') {
                    $proofs = $this->sourceDevelopmentEvidence($current, $maintenancePath, $recoveryPath, $baseline, $expected['backup_scope']);
                    if (!$this->sourceSame($expected['evidence'] ?? null, $proofs)) { throw new RuntimeException('APPLICATION_SCHEMA_PLAN_STALE'); }                    SourceApplicationBackup::sourceApplicationBeforeApply($current, $maintenancePath, $recheck);

                }
                $completed = []; $businessBefore = []; $businessPoststates = []; $stageBounds = [];
                $before = function (PDO $actual, array $migration) use ($pdo, $baseline, $recheck, $current, $maintenancePath, $recoveryPath, $proofs, $expected, &$completed, &$businessBefore): bool {
                    $recheck();
                    if ($actual !== $pdo || $this->sourceDevelopmentEvidence($current, $maintenancePath, $recoveryPath, $baseline, $expected['backup_scope']) !== $proofs) {
                        throw new RuntimeException('APPLICATION_SCHEMA_WINDOW_CHANGED');
                    }
                    $this->assertSourceBaseline($pdo, $baseline, $completed, $migration, 'before');
                    // Compare the original whole capture only before the first
                    // applying marker. Later stages legitimately change that schema.
                    if ($completed === [] && !$this->sourceSame(SourceApplicationBackup::scope($pdo, $baseline['pending'], $current, $this->sourceMetadataTool, $recheck), $expected['backup_scope'] ?? null)) {
                        throw new RuntimeException('APPLICATION_SCHEMA_BACKUP_SNAPSHOT_CHANGED');
                    }
                    $stageScope = SourceApplicationBackup::scope($pdo, [$migration['id']], $current, $this->sourceMetadataTool, $recheck);
                    if ($stageScope['execution_requirements'] !== []) {
                        throw new RuntimeException('APPLICATION_SCHEMA_STAGE_EFFECT_REVIEW_REQUIRED');
                    }
                    if ($migration['id'] === FileAdoptionRelocation::ID) {
                        if ($this->fileRelocationInput === null || $baseline['pending'] !== [FileAdoptionRelocation::ID]) { throw new RuntimeException('APPLICATION_SCHEMA_FILE_RELOCATION_SELECTION_INVALID'); }
                        $businessBefore[$migration['id']] = FileAdoptionRelocation::before($pdo, $migration, $this->fileRelocationInput);
                        if (!$this->sourceSame($businessBefore[$migration['id']], $expected['chain'][0]['prestate'] ?? null)) { throw new RuntimeException('APPLICATION_SCHEMA_FILE_RELOCATION_STATE_CHANGED'); }
                        $recheck(); return false;
                    }
                    if ($migration['id'] === '20260909-notification-sms-reservation') {
                        self::prestateIdentity($migration);
                        $businessBefore[$migration['id']] = self::smsMigrationBefore($pdo, $migration);
                        $recheck();
                        return true;
                    }
                    $menuBefore = self::menuMigrationBefore($pdo, $migration);
                    if ($menuBefore !== null) {
                        $businessBefore[$migration['id']] = $menuBefore;
                        $recheck();
                        return false;
                    }
                    $permissionBefore = self::permissionMigrationBefore($pdo, $migration);
                    if ($permissionBefore !== null) {
                        $businessBefore[$migration['id']] = $permissionBefore;
                        $recheck();
                        return $migration['id'] !== '20261012-align-platform-ops-permission-ownership';
                    }
                    $authBefore = self::authMigrationBefore($pdo, $migration);
                    if ($authBefore !== null) {
                        $businessBefore[$migration['id']] = $authBefore;
                        $recheck();
                        return false;
                    }
                    $proof = self::migrationPrestate($pdo, $migration, [], false);
                    if ($migration['id'] === '20261019-module-migration-provenance-metadata') {
                        if (!in_array($proof['proof']['shape'] ?? null, ['before_additions', 'exact_noop'], true)) { throw new RuntimeException('APPLICATION_SCHEMA_PRESTATE_PROVENANCE_PARTIAL'); }
                    } else { throw new RuntimeException('APPLICATION_SCHEMA_BUSINESS_POSTSTATE_REQUIRED'); }
                    $recheck();
                    return false;
                };
                $after = function (PDO $actual, array $migration, ?array $executionBounds) use ($pdo, $baseline, $recheck, $current, $maintenancePath, $recoveryPath, $proofs, $expected, $planPath, &$completed, &$businessBefore, &$businessPoststates, &$stageBounds): void {
                    $recheck();
                    if ($actual !== $pdo || $this->sourceDevelopmentEvidence($current, $maintenancePath, $recoveryPath, $baseline, $expected['backup_scope']) !== $proofs) {
                        throw new RuntimeException('APPLICATION_SCHEMA_WINDOW_CHANGED');
                    }
                    $this->assertSourceBaseline($pdo, $baseline, $completed, $migration, 'after');
                    if ($migration['id'] === FileAdoptionRelocation::ID) {
                        $businessPoststates[$migration['id']] = FileAdoptionRelocation::after($pdo, $migration, $businessBefore[$migration['id']] ?? [], $expected, $planPath);
                        unset($businessBefore[$migration['id']]);
                    } elseif ($migration['id'] === '20260909-notification-sms-reservation') {
                        $before = $businessBefore[$migration['id']] ?? null;
                        if (!is_array($before)) { throw new RuntimeException('APPLICATION_SCHEMA_BUSINESS_PROOF_REQUIRED'); }
                        $businessPoststates[$migration['id']] = self::smsMigrationAfter($pdo, $migration, $before, $executionBounds);
                        unset($businessBefore[$migration['id']]);
                    } elseif (in_array($migration['id'], self::MENU_MIGRATIONS, true)) {
                        $before = $businessBefore[$migration['id']] ?? null;
                        if (!is_array($before)) { throw new RuntimeException('APPLICATION_SCHEMA_BUSINESS_PROOF_REQUIRED'); }
                        $businessPoststates[$migration['id']] = self::menuMigrationAfter($pdo, $migration, $before);
                        unset($businessBefore[$migration['id']]);
                    } elseif (in_array($migration['id'], self::PERMISSION_MIGRATIONS, true)) {
                        $before = $businessBefore[$migration['id']] ?? null;
                        if (!is_array($before)) { throw new RuntimeException('APPLICATION_SCHEMA_BUSINESS_PROOF_REQUIRED'); }
                        $businessPoststates[$migration['id']] = self::permissionMigrationAfter($pdo, $migration, $before, $executionBounds);
                        unset($businessBefore[$migration['id']]);
                    } elseif ($migration['id'] === '20261007-auth-client-schema') {
                        $before = $businessBefore[$migration['id']] ?? null;
                        if (!is_array($before)) { throw new RuntimeException('APPLICATION_SCHEMA_BUSINESS_PROOF_REQUIRED'); }
                        $businessPoststates[$migration['id']] = self::authMigrationAfter($pdo, $migration, $before);
                        unset($businessBefore[$migration['id']]);
                    }
                    $recheck();
                    $stageBounds[$migration['id']] = $executionBounds;
                    $completed[] = $migration['id'];
                };
                $record = null;
                $runner->run($files, $target, $versions['release_sequence_version'], $operation === 'verify',
                    $operation === 'apply' ? $before : null,
                    function (PDO $actual, array $selection) use ($pdo, $baseline, $recheck, $operation, $current, $maintenancePath, $recoveryPath, $proofs, $expected, $planPath, &$record, &$businessPoststates, &$stageBounds): void {
                        $recheck();
                        if ($actual !== $pdo || $selection['pending'] !== []) { throw new RuntimeException('APPLICATION_SCHEMA_VERIFY_INCOMPLETE'); }
                        if ($operation === 'apply' && $selection['applied'] !== $baseline['pending']) { throw new RuntimeException('APPLICATION_SCHEMA_APPLIED_USE_VERIFY'); }
                        if ($operation === 'apply' && $this->sourceDevelopmentEvidence($current, $maintenancePath, $recoveryPath, $baseline, $expected['backup_scope']) !== $proofs) {
                            throw new RuntimeException('APPLICATION_SCHEMA_WINDOW_CHANGED');
                        }
                        $this->assertSourceBaseline($pdo, $baseline, $baseline['pending'], null, 'completed');
                        if ($operation === 'verify') {
                            $businessPoststates = $expected['business_poststates'] ?? [];
                        }
                        self::assertMenuPoststates($baseline['pending'], $businessPoststates, $operation === 'verify', $pdo, $expected['business_terminal_state'] ?? null);
                        self::assertPermissionPoststates($baseline['pending'], $businessPoststates, $operation === 'verify', $pdo, $expected['business_terminal_state'] ?? null);
                        self::assertSmsPoststates($baseline['pending'], $businessPoststates, $operation === 'verify', $pdo, $expected['business_terminal_state'] ?? null);
                        self::assertAuthPoststates($pdo, $baseline['pending'], $businessPoststates, $operation === 'verify');
                        if (in_array(FileAdoptionRelocation::ID, $baseline['pending'], true)) {
                            PluginLifecycleService::fileAdoptionRelocationHistory($pdo);
                            if ($operation === 'verify') { $businessPoststates[FileAdoptionRelocation::ID] = $expected['business_poststates'][FileAdoptionRelocation::ID] ?? []; }
                        }
                        $terminal = $operation === 'apply' ? self::businessTerminalState($pdo, $baseline['pending'], $businessPoststates)
                            : ($expected['business_terminal_state'] ?? null);
                        $record = ['schema_version' => 1, 'protocol' => 'peanut.source-development-schema-result.v1',
                            'scope' => 'application-schema', 'maintenance_scope' => ($current['source']['kind'] ?? null) === 'generated-application' ? 'source-application' : 'source-development', 'status' => 'completed',
                            'operation' => $operation, 'binding' => $current, 'baseline' => $baseline, 'application' => $selection, 'business_poststates' => $businessPoststates,
                            'business_terminal_state' => $terminal,
                            'validation_only' => $current['validation_only'],
                            'file_relocation_input' => $this->fileRelocationInput,
                            'recovery_references' => $operation === 'apply' ? ($proofs['recovery']['references'] ?? null) : ($expected['recovery_references'] ?? null),
                            'external_gates' => 'not_evaluated',
                            'plugins' => 'not_run', 'product_database' => 'not_verified'];
                        if ($this->sourceMetadataTool !== '') { $record['metadata_tool'] = $this->sourceMetadataTool; }
                        if ($operation === 'apply') {
                            $marks = implode(',', array_fill(0, count($baseline['pending']), '?'));
                            $query = $pdo->prepare('SELECT migration_id,release_version,checksum,status,started_at,finished_at,error_code'
                                . " FROM pa_schema_migration WHERE migration_id IN ($marks) ORDER BY migration_id");
                            $query->execute($baseline['pending']);
                            $ledger = $query->fetchAll(PDO::FETCH_ASSOC); $query->closeCursor();
                            if (array_column($ledger, 'migration_id') !== $baseline['pending']) { throw new RuntimeException('APPLICATION_SCHEMA_EXECUTION_PREFIX_CHANGED'); }
                            foreach ($ledger as $row) {
                                if ($row['status'] !== 'applied' || $row['checksum'] !== $baseline['selected'][$row['migration_id']]
                                    || $row['finished_at'] === null || $row['error_code'] !== null) {
                                    throw new RuntimeException('APPLICATION_SCHEMA_EXECUTION_PREFIX_CHANGED');
                                }
                            }
                            // This protected native result binds a real completed
                            // window. It never reconstructs private business inputs.
                            if (!$this->sourceSame($this->sourceProtectedJson($planPath), $expected)) { throw new RuntimeException('APPLICATION_SCHEMA_PLAN_STALE'); }
                            $contracts = $this->sourceBusinessContracts($businessPoststates);
                            $writerSha = $this->fileDigest(__FILE__);
                            $acceptedAt = time();
                            if ($this->sourceDevelopmentEvidence($current, $maintenancePath, $recoveryPath, $baseline, $expected['backup_scope'], $acceptedAt) !== $proofs) {
                                throw new RuntimeException('APPLICATION_SCHEMA_WINDOW_CHANGED');
                            }
                            $recheck();
                            $record['execution_evidence'] = ['proof_kind' => 'same_process_native_migration_window',
                                'plan_sha256' => $expected['document_sha256'], 'plan' => ['path' => $planPath, 'sha256' => $this->fileDigest($planPath)],
                                'observed_at' => $acceptedAt, 'request_id' => app()->make(\app\common\execution\CurrentExecutionContext::class)->current()->requestId(),
                                'writer_sha256' => $writerSha,
                                'contracts' => $contracts, 'execution_bounds' => $stageBounds,
                                'window' => $proofs['maintenance'],
                                'recovery' => $proofs['recovery'], 'tables' => $expected['backup_scope']['tables'],
                                'ordered_stages' => $ledger, 'ledger_sha256' => $this->digest($ledger),
                                'qualification' => 'not_run', 'restore' => 'not_executed'];
                        }
                        $record['document_sha256'] = $this->digest($record);
                        // Seal only while both the source and same-PDO schema locks remain held.
                        if ($operation === 'apply') { $record['result_path'] = $this->saveSourceResult($record); }
                        $recheck();
                    }, $operation === 'apply' ? $after : null);
                return $record;
            });
    }

    private function sourceDevelopmentPlan(PDO $pdo, array $binding, callable $facts, array $files, string $target, array $versions, string $maintenancePath, string $recoveryPath): array
    {
        $baseline = $this->sourceBaseline($pdo, $files, $target, $versions['release_sequence_version']);
        $chain = []; $preceding = []; $requirements = []; $blocked = false;
        foreach ($files as $file) {
            $id = basename($file, '.sql');
            if (!in_array($id, $baseline['pending'], true)) { continue; }
            $migration = ['id' => $id, 'sql' => (string) file_get_contents($file), 'checksum' => $this->fileDigest($file)];
            $identity = ApplicationMigrationRunner::releaseIdentity($migration['sql'], $versions['release_sequence_version']);
            try {
                $proof = $id === FileAdoptionRelocation::ID && $this->fileRelocationInput !== null
                    ? FileAdoptionRelocation::before($pdo, $migration, $this->fileRelocationInput)
                    : self::migrationPrestate($pdo, $migration, $preceding, true);
            } catch (\Throwable $exception) {
                $code = explode(':', $exception->getMessage(), 2)[0];
                if (!$exception instanceof RuntimeException || preg_match('/\AAPPLICATION_SCHEMA_[A-Z0-9_]+\z/D', $code) !== 1) {
                    // Transport/driver/unclassified failures are not a successful
                    // observation and must not trigger the next database query.
                    throw $exception;
                }
                $proof = ['status' => 'blocked', 'complete_preconditions_proven' => false,
                    'code' => $code,
                    'diagnostic_sha256' => hash('sha256', $exception->getMessage()), 'readonly' => true];
                $blocked = true;
            }
            $poststate = match ($id) {
                FileAdoptionRelocation::ID => FileAdoptionRelocation::class,
                '20261019-module-migration-provenance-metadata' => 'exact_provenance_shape',
                '20261007-auth-client-schema' => AuthClientSchemaMigrationProof::class,
                '20260909-notification-sms-reservation' => SmsReservationMigrationProof::class,
                '20261004-group-default-navigation', '20261004-menu-stable-identities',
                '20261012-repair-seeded-permission-parents' => MenuSchemaMigrationProof::class,
                '20261007-readiness-permission', '20261012-align-department-canonical-permissions',
                '20261012-align-platform-ops-permission-ownership' => PermissionSchemaMigrationProof::class,
                default => 'business_preservation_contract_required',
            };
            if ($poststate === 'business_preservation_contract_required') {
                $requirements[] = ['migration_id' => $id, 'code' => 'APPLICATION_SCHEMA_BUSINESS_POSTSTATE_REQUIRED'];
            }
            $chain[] = ['migration_id' => $id, 'sha256' => $migration['checksum'],
                'release_version' => $identity['release_version'], 'prestate' => $proof, 'poststate_contract' => $poststate];
            $preceding[] = $migration;
        }
        $backupScope = SourceApplicationBackup::scope($pdo, $baseline['pending'], $binding, $this->sourceMetadataTool, static function () use ($facts, $binding): void {
            if ($facts() !== $binding) { throw new RuntimeException('APPLICATION_SCHEMA_SOURCE_DRIFT'); }
        });
        array_push($requirements, ...$backupScope['execution_requirements']);
        foreach ($chain as $stage) {
            if ($stage['migration_id'] === '20261019-module-migration-provenance-metadata'
                && !in_array($stage['prestate']['proof']['shape'] ?? null, ['before_additions', 'exact_noop'], true)) {
                $requirements[] = ['code' => 'APPLICATION_SCHEMA_PRESTATE_PROVENANCE_PARTIAL'];
                $blocked = true;
            }
        }
        \app\common\infrastructure\scaffold\ScaffoldUpgradeRunner::sourceProductLock(dirname($this->serverRoot), static fn() => null);
        if ($facts() !== $binding) { throw new RuntimeException('APPLICATION_SCHEMA_SOURCE_DRIFT'); }
        $evidence = null;
        if ($maintenancePath !== '' && $recoveryPath !== '') {
            $evidence = $this->sourceDevelopmentEvidence($binding, $maintenancePath, $recoveryPath, $baseline, $backupScope);
        } elseif ($baseline['pending'] !== []) {
            $requirements[] = ['code' => 'APPLICATION_SCHEMA_SOURCE_WINDOW_RECOVERY_REQUIRED'];
        }
        $plan = ['schema_version' => 1, 'protocol' => 'peanut.source-development-schema-plan.v1',
            'file_relocation_input' => $this->fileRelocationInput,
            'scope' => 'application-schema', 'maintenance_scope' => ($binding['source']['kind'] ?? null) === 'generated-application' ? 'source-application' : 'source-development', 'status' => $blocked ? 'blocked' : 'planned',
            'binding' => $binding, 'baseline' => $baseline, 'chain' => $chain, 'backup_scope' => $backupScope, 'execution_requirements' => $requirements, 'evidence' => $evidence,
            'protected_permission_bindings' => PermissionSchemaMigrationProof::readonlyBindingDigests($pdo),
            'validation_only' => $binding['validation_only'],
            'execution_admitted' => false, 'external_gates' => 'not_evaluated', 'plugins' => 'not_run', 'product_database' => 'not_verified'];
        if ($this->sourceMetadataTool !== '') { $plan['metadata_tool'] = $this->sourceMetadataTool; }
        $plan['document_sha256'] = $this->digest($plan);
        return $plan;
    }

    /** Historical inputs remain bound to their original source and exact bytes. */
    private function sourceFailureOrigin(array $binding, array $files, string $planPath): array
    {
        $plan = $this->sourceProtectedJson($planPath, $planSha);
        $this->assertSourceDocument($plan, 'peanut.source-development-schema-plan.v1');
        $previous = $plan['binding'] ?? [];
        // A new native lease may rebind the environment's proof path. Both
        // environment digests remain recorded in their respective bindings.
        foreach (['database', 'resource_id', 'endpoint_id', 'registry_sha256', 'installation_originals'] as $key) {
            if (!$this->sourceSame($previous[$key] ?? null, $binding[$key] ?? null)) { throw new RuntimeException('APPLICATION_SCHEMA_FAILURE_ORIGIN_CHANGED'); }
        }
        if (($previous['source']['root'] ?? null) !== $binding['source']['root'] || ($plan['metadata_tool'] ?? '') !== $this->sourceMetadataTool) {
            throw new RuntimeException('APPLICATION_SCHEMA_FAILURE_ORIGIN_CHANGED');
        }
        foreach (['owner', 'thread', 'session', 'gate'] as $key) {
            if (($previous['authority'][$key] ?? null) !== $binding['authority'][$key]) { throw new RuntimeException('APPLICATION_SCHEMA_FAILURE_ORIGIN_CHANGED'); }
        }
        $baseline = $plan['baseline'] ?? []; $pending = $baseline['pending'] ?? [];
        if (!is_array($pending) || $pending === [] || !array_is_list($pending)) { throw new RuntimeException('APPLICATION_SCHEMA_FAILURE_PLAN_REQUIRED'); }
        $actualFiles = [];
        foreach ($files as $file) { $actualFiles[basename($file, '.sql')] = hash_file('sha256', $file); }
        foreach ($pending as $id) {
            if (!is_string($id) || ($actualFiles[$id] ?? null) !== ($baseline['selected'][$id] ?? null)) {
                throw new RuntimeException('APPLICATION_SCHEMA_FAILURE_SQL_CHANGED');
            }
        }
        $origin = $plan['evidence']['recovery']['references'] ?? [];
        foreach (['backup_manifest', 'backup_payload'] as $key) {
            $reference = $origin[$key] ?? [];
            $this->sourceProtectedFile($reference['path'] ?? '', null);
            if (hash_file('sha256', $reference['path']) !== ($reference['sha256'] ?? null)) {
                throw new RuntimeException('APPLICATION_SCHEMA_FAILURE_BACKUP_CHANGED');
            }
        }
        $backup = $this->sourceProtectedJson($origin['backup_manifest']['path'], $backupSha);
        if ($backupSha !== $origin['backup_manifest']['sha256'] || ($backup['protocol'] ?? null) !== 'peanut.source-application-backup.v1' || ($backup['status'] ?? null) !== 'captured'
            || !$this->sourceSame($backup['binding'] ?? null, $previous) || !$this->sourceSame($backup['backup_payload'] ?? null, $origin['backup_payload'])
            || ($backup['baseline_sha256'] ?? null) !== $this->digest($baseline)
            || !$this->sourceSame($backup['scope'] ?? null, $plan['backup_scope'] ?? null)
            || ($backup['pending'] ?? null) !== $pending
            || !$this->sourceSame($backup['selected'] ?? null, array_intersect_key($baseline['selected'], array_flip($pending)))
            || ($backup['backup_payload']['bytes'] ?? null) !== filesize($origin['backup_payload']['path'])
            || array_column($backup['receipts'] ?? [], 'phase') !== ['probe-before', 'dump', 'probe-after']) {
            throw new RuntimeException('APPLICATION_SCHEMA_FAILURE_BACKUP_CHANGED');
        }
        $historical = $this->sourceFailureHistoricalWindow($plan, $backup);
        $previousHead = $previous['source']['head'] ?? '';
        if (!is_string($previousHead) || preg_match('/\A[a-f0-9]{40}\z/D', $previousHead) !== 1
            || \leaseGit(['-C', dirname($this->serverRoot), 'merge-base', $previousHead, $binding['source']['head']]) !== $previousHead) {
            throw new RuntimeException('APPLICATION_SCHEMA_FAILURE_ORIGIN_CHANGED');
        }
        $originFiles = [$planPath => $planSha] + $historical['files'] + $this->sourceFailureCaptureFiles($backup, $origin, $previous,
            'peanut.source-application-restore-instructions.v1');
        return [$plan, $planSha, $baseline, $pending, $origin, $originFiles, $historical];
    }

    /** Consume the original declared maintenance window, not a retrospective declaration or a still-held lease. */
    private function sourceFailureHistoricalWindow(array $plan, array $backup): array
    {
        $reference = $plan['evidence']['maintenance'] ?? [];
        $path = $reference['path'] ?? '';
        $window = $this->sourceProtectedJson($path, $sha);
        $previous = $plan['binding']; $baseline = $plan['baseline']; $pending = $baseline['pending'];
        if ($sha !== ($reference['sha256'] ?? null) || ($window['schema_version'] ?? null) !== 1
            || ($window['protocol'] ?? null) !== 'peanut.source-development-maintenance.v1' || ($window['state'] ?? null) !== 'active'
            || !$this->sourceSame($window['source'] ?? null, $previous['source']) || !$this->sourceSame($window['database'] ?? null, $previous['database'])
            || !$this->sourceSame($window['authority'] ?? null, $previous['authority']) || ($window['registry_sha256'] ?? null) !== $previous['registry_sha256']
            || ($window['pending'] ?? null) !== $pending
            || !$this->sourceSame($window['selected'] ?? null, array_intersect_key($baseline['selected'], array_flip($pending)))
            || ($window['baseline_sha256'] ?? null) !== $this->digest($baseline) || ($window['scope'] ?? null) !== $plan['backup_scope']['tables']
            || ($window['excluded_writers'] ?? null) !== ['http', 'workers', 'other-writers']
            || !is_int($window['observed_at'] ?? null) || !is_int($window['expires_at'] ?? null)
            || $window['observed_at'] < (int) $previous['authority']['created_at'] || $window['observed_at'] > time()
            || $window['expires_at'] <= $window['observed_at'] || $window['expires_at'] > (int) $previous['authority']['expires_at']
            || !is_int($backup['capture_started_at'] ?? null) || !is_int($backup['observed_at'] ?? null)
            || $backup['capture_started_at'] < $window['observed_at'] || $backup['observed_at'] < $backup['capture_started_at']
            || $backup['observed_at'] >= $window['expires_at'] || $backup['observed_at'] > time()
            || !is_array($window['receipts'] ?? null) || $window['receipts'] === []) {
            throw new RuntimeException('APPLICATION_SCHEMA_FAILURE_HISTORICAL_WINDOW_REQUIRED');
        }
        $files = [$path => $sha];
        foreach ($window['receipts'] as $receipt) {
            $receiptPath = $receipt['path'] ?? '';
            $this->sourceProtectedFile($receiptPath);
            if (hash_file('sha256', $receiptPath) !== ($receipt['sha256'] ?? null)) {
                throw new RuntimeException('APPLICATION_SCHEMA_FAILURE_HISTORICAL_WINDOW_REQUIRED');
            }
            $files[$receiptPath] = $receipt['sha256'];
        }
        return ['files' => $files, 'observed_at' => $window['observed_at'], 'expires_at' => $window['expires_at'],
            'backup_observed_at' => $backup['observed_at']];
    }

    private function sourceFailureHistoricalInterval(array $historical, array $capsule, array $pending): void
    {
        $cursor = $historical['backup_observed_at'];
        $start = $capsule['capture_started_at'] ?? null; $end = $capsule['observed_at'] ?? null;
        if (!is_int($start) || !is_int($end) || $start < $cursor || $end < $start || $end >= $historical['expires_at'] || $end > time()) {
            throw new RuntimeException('APPLICATION_SCHEMA_FAILURE_HISTORICAL_WINDOW_REQUIRED');
        }
        $rows = array_values(array_filter($capsule['failure']['ledger'] ?? [], static fn(array $row): bool => in_array($row['migration_id'] ?? null, $pending, true)));
        $expected = [...($capsule['failure']['executed_prefix'] ?? []), $capsule['failure']['failed_migration']['migration_id'] ?? null];
        if (array_column($rows, 'migration_id') !== $expected) { throw new RuntimeException('APPLICATION_SCHEMA_FAILURE_HISTORICAL_WINDOW_REQUIRED'); }
        foreach ($rows as $row) {
            foreach (['started_at', 'finished_at'] as $key) {
                $value = $row[$key] ?? null;
                $date = is_string($value) ? \DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $value, new \DateTimeZone('UTC')) : false;
                if ($date === false || $date->format('Y-m-d H:i:s') !== $value || $date->getTimestamp() < $cursor || $date->getTimestamp() > $start) {
                    throw new RuntimeException('APPLICATION_SCHEMA_FAILURE_HISTORICAL_WINDOW_REQUIRED');
                }
                $cursor = $date->getTimestamp();
            }
        }
    }

    /** Validate historical native payload/terminal/RESTORE bytes without requiring its old lease or adapter now. */
    private function sourceFailureCaptureFiles(array $backup, array $origin, array $previous, string $restoreProtocol): array
    {
        $originFiles = [];
        foreach (['backup_manifest', 'backup_payload'] as $key) {
            $this->sourceProtectedFile($origin[$key]['path'] ?? '', null);
            if (hash_file('sha256', $origin[$key]['path']) !== ($origin[$key]['sha256'] ?? null)) { throw new RuntimeException('APPLICATION_SCHEMA_FAILURE_BACKUP_CHANGED'); }
            $originFiles[$origin[$key]['path']] = $origin[$key]['sha256'];
        }
        if (($origin['backup_payload']['bytes'] ?? null) !== filesize($origin['backup_payload']['path'])
            || array_column($backup['receipts'] ?? [], 'phase') !== ['probe-before', 'dump', 'probe-after']) { throw new RuntimeException('APPLICATION_SCHEMA_FAILURE_BACKUP_CHANGED'); }
        $restorePath = $backup['restore_instructions']['path'] ?? '';
        $restore = $this->sourceProtectedJson($restorePath, $restoreSha);
        if ($restoreSha !== ($backup['restore_instructions']['sha256'] ?? null)
            || $restoreSha !== ($origin['restore_contract_sha256'] ?? null)
            || ($restore['protocol'] ?? null) !== $restoreProtocol || ($restore['executed'] ?? null) !== false
            || !$this->sourceSame($restore['source'] ?? null, $previous['source']) || !$this->sourceSame($restore['database'] ?? null, $previous['database'])
            || !$this->sourceSame($restore['native_client'] ?? null, $backup['native_client'] ?? null)
            || !$this->sourceSame($restore['payload'] ?? null, $origin['backup_payload']) || ($restore['tables'] ?? null) !== $backup['scope']['tables']
            || ($origin['restore_tool']['path'] ?? null) !== $previous['source']['root'] . '/server/app/common/services/upgrade/SourceApplicationBackup.php'
            || ($origin['restore_tool']['sha256'] ?? null) !== ($backup['adapter_sha256'] ?? null)) {
            throw new RuntimeException('APPLICATION_SCHEMA_FAILURE_BACKUP_CHANGED');
        }
        $originFiles[$restorePath] = $origin['restore_contract_sha256'];
        foreach ($backup['receipts'] as $receipt) {
            $stdout = dirname($origin['backup_manifest']['path']) . '/' . match ($receipt['phase']) {
                'probe-before' => 'probe-before.txt', 'dump' => 'database.sql', 'probe-after' => 'probe-after.txt',
            };
            if (($receipt['path'] ?? null) !== $stdout . '.process.json') { throw new RuntimeException('APPLICATION_SCHEMA_FAILURE_BACKUP_CHANGED'); }
            $terminal = $this->sourceProtectedJson($receipt['path'], $terminalSha);
            $this->sourceProtectedFile($stdout, $receipt['phase'] === 'dump' ? null : 4194304);
            $actual = ['path' => $stdout, 'sha256' => hash_file('sha256', $stdout), 'bytes' => filesize($stdout)];
            if ($terminalSha !== ($receipt['sha256'] ?? null)
                || ($terminal['phase'] ?? null) !== $receipt['phase'] || ($terminal['local_process_terminal'] ?? null) !== true || ($terminal['rc'] ?? null) !== 0
                || ($terminal['local_deadline_exceeded'] ?? null) !== false || ($terminal['remote_success_terminal_confirmed'] ?? null) !== true
                || !$this->sourceSame($terminal['stdout'] ?? null, $actual)
                || ($receipt['phase'] === 'dump' && !$this->sourceSame($actual, $origin['backup_payload']))) {
                throw new RuntimeException('APPLICATION_SCHEMA_FAILURE_BACKUP_CHANGED');
            }
            $originFiles[$stdout] = $actual['sha256']; $originFiles[$receipt['path']] = $receipt['sha256'];
        }
        return $originFiles;
    }

    /** Preserve the live failed state; never invokes the runner or supplies execution/recovery admission. */
    private function sourceFailureSnapshot(PDO $pdo, array $binding, callable $facts, array $files, string $planPath, string $toolId, string $output): array
    {
        [$plan, $planSha, $baseline, $pending, $origin, $originFiles] = $this->sourceFailureOrigin($binding, $files, $planPath);
        $registry = \projectResourceRegistry($registrySha);
        $tools = array_values(array_filter($registry['resources']['tooling'] ?? [], static fn(array $row): bool => ($row['stable_resource_id'] ?? null) === $toolId));
        $resources = \activeLeaseResources(\requiredEnvironment('PEANUT_RESOURCE_LEASE_PROOF'));
        if (count($tools) !== 1 || $registrySha !== $binding['registry_sha256']
            || !in_array($toolId, $resources['tooling-resource-id'] ?? [], true) || !in_array($output, $resources['output-dir'] ?? [], true)) {
            throw new RuntimeException('APPLICATION_SCHEMA_BACKUP_NATIVE_TOOL_OUTPUT_REQUIRED');
        }
        return \app\common\infrastructure\scaffold\ScaffoldUpgradeRunner::sourceProductLock(dirname($this->serverRoot),
            function () use ($pdo, $binding, $facts, $planPath, $planSha, $baseline, $pending, $tools, $output, $origin, $originFiles, $plan): array {
                $lockName = 'peanut_migrate_' . substr(hash('sha256', $binding['database']['database']), 0, 48);
                $lock = $pdo->prepare('SELECT GET_LOCK(?, 10)');
                try { $lock->execute([$lockName]); $held = (int) $lock->fetchColumn() === 1; }
                finally { $lock->closeCursor(); }
                if (!$held) { throw new RuntimeException('APPLICATION_SCHEMA_BACKUP_SCHEMA_LOCK_REQUIRED'); }
                try {
                    $ledger = $pdo->query('SELECT * FROM pa_schema_migration ORDER BY migration_id')->fetchAll(PDO::FETCH_ASSOC);
                    $failed = $this->sourceFailureLedger($ledger, $baseline, $this->sourceLedgerShape($pdo));
                    $guard = function () use ($pdo, $binding, $facts, $ledger, $lockName, $planPath, $planSha, $origin, $originFiles): void {
                        if ($facts() !== $binding || hash_file('sha256', $planPath) !== $planSha
                            || $pdo->query('SELECT * FROM pa_schema_migration ORDER BY migration_id')->fetchAll(PDO::FETCH_ASSOC) !== $ledger) {
                            throw new RuntimeException('APPLICATION_SCHEMA_FAILURE_SNAPSHOT_CHANGED');
                        }
                        foreach (['backup_manifest', 'backup_payload'] as $key) {
                            $this->sourceProtectedFile($origin[$key]['path'], null);
                            if (hash_file('sha256', $origin[$key]['path']) !== $origin[$key]['sha256']) { throw new RuntimeException('APPLICATION_SCHEMA_FAILURE_BACKUP_CHANGED'); }
                        }
                        foreach ($originFiles as $path => $sha) {
                            $this->sourceProtectedFile($path, null);
                            if (hash_file('sha256', $path) !== $sha) { throw new RuntimeException('APPLICATION_SCHEMA_FAILURE_BACKUP_CHANGED'); }
                        }
                        $check = $pdo->prepare('SELECT IS_USED_LOCK(?) = CONNECTION_ID()');
                        try { $check->execute([$lockName]); $held = (int) $check->fetchColumn() === 1; }
                        finally { $check->closeCursor(); }
                        if (!$held) { throw new RuntimeException('APPLICATION_SCHEMA_BACKUP_SCHEMA_LOCK_REQUIRED'); }
                    };
                    $scope = SourceApplicationBackup::scope($pdo, $pending, $binding, $this->sourceMetadataTool, $guard);
                    $protectedBindings = PermissionSchemaMigrationProof::readonlyBindingDigests($pdo);
                    if (isset($plan['protected_permission_bindings']) && !$this->sourceSame($protectedBindings, $plan['protected_permission_bindings'])) {
                        throw new RuntimeException('APPLICATION_SCHEMA_FAILURE_SNAPSHOT_CHANGED');
                    }
                    $image = ['pending' => $pending, 'selected' => $baseline['selected'], 'failure_afterimage' => true,
                        'protected_permission_bindings' => $protectedBindings,
                        'original_plan' => ['path' => $planPath, 'sha256' => $planSha], 'original_backup' => $origin,
                        'executed_prefix' => array_slice($pending, 0, array_search($failed['migration_id'], $pending, true)),
                        'failed_migration' => $failed, 'ledger' => $ledger];
                    if ($pending === [FileAdoptionRelocation::ID]) { $image['file_relocation'] = FileAdoptionRelocation::failureFacts($pdo, $plan); }
                    $recheck = function () use ($pdo, $pending, $binding, $guard, $scope, $protectedBindings, $image, $plan): void {
                        $guard();
                        if (isset($image['file_relocation']) && FileAdoptionRelocation::failureFacts($pdo, $plan) !== $image['file_relocation']) {
                            throw new RuntimeException('APPLICATION_SCHEMA_FAILURE_SNAPSHOT_CHANGED');
                        }
                        if (!$this->sourceSame(PermissionSchemaMigrationProof::readonlyBindingDigests($pdo), $protectedBindings)) {
                            throw new RuntimeException('APPLICATION_SCHEMA_FAILURE_SNAPSHOT_CHANGED');
                        }
                        if (!$this->sourceSame(SourceApplicationBackup::scope($pdo, $pending, $binding, $this->sourceMetadataTool, $guard), $scope)) {
                            throw new RuntimeException('APPLICATION_SCHEMA_FAILURE_SNAPSHOT_CHANGED');
                        }
                    };
                    $result = SourceApplicationBackup::capture($binding, $image, $this->digest($image), $scope, $tools[0], $output, $recheck, 'application-failure');
                    return $result + ['scope' => 'application-schema', 'maintenance_scope' => 'source-development',
                        'failure_afterimage' => true, 'migration' => 'not_run', 'plugins' => 'not_run', 'product_database' => 'not_verified'];
                } finally { $pdo->prepare('SELECT RELEASE_LOCK(?)')->execute([$lockName]); }
            });
    }

    /** A separate, explicit recovery action; it never retries the application runner or opens writers. */
    private function sourceFailureRestoreProfile(array $failure): string
    {
        $id = $failure['failed_migration']['migration_id'] ?? '';
        if (($failure['failure_afterimage'] ?? null) !== true
            || ($failure['failed_migration']['status'] ?? null) !== 'failed'
            || !isset(self::PRESTATE_SQL[$id])
            || ($failure['failed_migration']['checksum'] ?? null) !== self::PRESTATE_SQL[$id]) {
            throw new RuntimeException('APPLICATION_SCHEMA_FAILURE_RESTORE_UNSUPPORTED');
        }
        $prefix = $failure['executed_prefix'] ?? null;
        $error = $failure['failed_migration']['error_code'] ?? null;
        if ($id === FileAdoptionRelocation::ID && $prefix === [] && is_array($failure['file_relocation'] ?? null)) { return 'file-adoption-relocation'; }
        if ($id === FileAdoptionRelocation::ID) { throw new RuntimeException('APPLICATION_SCHEMA_FAILURE_RESTORE_UNSUPPORTED'); }
        if ($id === '20260909-notification-sms-reservation' && $prefix === []
            && $error === 'NOTIFICATION_SMS_MIGRATION_SCHEMA_POSTIMAGE_INVALID') { return 'sms-reservation'; }
        $expected = ['20260909-notification-sms-reservation', '20261004-group-default-navigation'];
        if ($id === '20261004-menu-stable-identities' && $prefix === $expected
            && $error === 'IDENTITY_MENU_MIGRATION_CUSTOM_ID_ALLOCATION_RANGE') {
            foreach ($expected as $predecessor) {
                if (($failure['selected'][$predecessor] ?? null) !== self::PRESTATE_SQL[$predecessor]) {
                    throw new RuntimeException('APPLICATION_SCHEMA_FAILURE_RESTORE_UNSUPPORTED');
                }
            }
            return 'menu-stable-identities';
        }
        $ids = array_keys(self::PRESTATE_SQL); $index = array_search($id, $ids, true);
        if ($index !== false && ($index === 1 || $index >= 3) && $prefix === array_slice($ids, 0, $index)
            && is_string($error) && $error !== '') {
            foreach (array_slice($ids, 0, $index) as $predecessor) {
                if (($failure['selected'][$predecessor] ?? null) !== self::PRESTATE_SQL[$predecessor]) {
                    throw new RuntimeException('APPLICATION_SCHEMA_FAILURE_RESTORE_UNSUPPORTED');
                }
            }
            return 'prefix:' . $id;
        }
        throw new RuntimeException('APPLICATION_SCHEMA_FAILURE_RESTORE_UNSUPPORTED');
    }

    private function sourceFailureRestore(PDO $pdo, array $binding, callable $facts, array $files, string $capsulePath,
        string $maintenancePath, string $toolId, string $output): array
    {
        $capsule = $this->sourceProtectedJson($capsulePath, $capsuleSha);
        $failure = $capsule['failure'] ?? []; $planPath = $failure['original_plan']['path'] ?? '';
        if (($capsule['protocol'] ?? null) !== 'peanut.source-application-failure-snapshot.v1' || ($capsule['status'] ?? null) !== 'captured'
            || ($failure['failure_afterimage'] ?? null) !== true) {
            throw new RuntimeException('APPLICATION_SCHEMA_FAILURE_RESTORE_UNSUPPORTED');
        }
        $profile = $this->sourceFailureRestoreProfile($failure);
        [$plan, $planSha, $baseline, $pending, $origin, $originFiles, $historical] = $this->sourceFailureOrigin($binding, $files, $planPath);
        $this->sourceFailureHistoricalInterval($historical, $capsule, $pending);
        if ($planSha !== ($failure['original_plan']['sha256'] ?? null) || ($failure['pending'] ?? null) !== $pending
            || !$this->sourceSame($failure['selected'] ?? null, $baseline['selected']) || !$this->sourceSame($failure['original_backup'] ?? null, $origin)
            || ($capsule['baseline_sha256'] ?? null) !== $this->digest($failure) || ($capsule['pending'] ?? null) !== $pending
            || !$this->sourceSame($capsule['selected'] ?? null, array_intersect_key($baseline['selected'], array_flip($pending)))
            || !$this->sourceSame($capsule['binding']['database'] ?? null, $binding['database'])
            || !$this->sourceSame($capsule['binding']['installation_originals'] ?? null, $binding['installation_originals'])
            || ($capsule['binding']['source']['root'] ?? null) !== $binding['source']['root']
            || ($capsule['binding']['registry_sha256'] ?? null) !== $binding['registry_sha256']
            || ($capsule['scope']['tables'] ?? null) !== $plan['backup_scope']['tables']) { throw new RuntimeException('APPLICATION_SCHEMA_FAILURE_ORIGIN_CHANGED'); }
        foreach (['owner', 'thread', 'session', 'gate'] as $key) {
            if (($capsule['binding']['authority'][$key] ?? null) !== $binding['authority'][$key]) { throw new RuntimeException('APPLICATION_SCHEMA_FAILURE_ORIGIN_CHANGED'); }
        }
        $sourceHead = $capsule['binding']['source']['head'] ?? '';
        if (!is_string($sourceHead) || preg_match('/\A[a-f0-9]{40}\z/D', $sourceHead) !== 1
            || \leaseGit(['-C', dirname($this->serverRoot), 'merge-base', $plan['binding']['source']['head'], $sourceHead]) !== $plan['binding']['source']['head']
            || \leaseGit(['-C', dirname($this->serverRoot), 'merge-base', $sourceHead, $binding['source']['head']]) !== $sourceHead
            || !is_int($capsule['capture_started_at'] ?? null) || !is_int($capsule['observed_at'] ?? null)
            || $capsule['capture_started_at'] < (int) $capsule['binding']['authority']['created_at']
            || $capsule['observed_at'] < $capsule['capture_started_at'] || $capsule['observed_at'] > time()
            || $capsule['observed_at'] >= (int) $capsule['binding']['authority']['expires_at']) { throw new RuntimeException('APPLICATION_SCHEMA_FAILURE_ORIGIN_CHANGED'); }
        $referencePath = dirname($capsulePath) . '/recovery.json'; $reference = $this->sourceProtectedJson($referencePath, $referenceSha);
        if (($reference['protocol'] ?? null) !== 'peanut.source-application-failure-reference.v1'
            || ($reference['backup_manifest'] ?? null) !== ['path' => $capsulePath, 'sha256' => $capsuleSha]
            || !$this->sourceSame($reference['backup_payload'] ?? null, $capsule['backup_payload'] ?? null)
            || !$this->sourceSame($reference['source'] ?? null, $capsule['binding']['source'])
            || !$this->sourceSame($reference['database'] ?? null, $capsule['binding']['database'])
            || !$this->sourceSame($reference['authority'] ?? null, $capsule['binding']['authority'])
            || ($reference['baseline_sha256'] ?? null) !== $capsule['baseline_sha256']
            || ($reference['capture_started_at'] ?? null) !== $capsule['capture_started_at'] || ($reference['observed_at'] ?? null) !== $capsule['observed_at']
            || !$this->sourceSame($reference['receipts'] ?? null, $capsule['receipts'] ?? null)) { throw new RuntimeException('APPLICATION_SCHEMA_FAILURE_BACKUP_CHANGED'); }
        $originFiles += $this->sourceFailureCaptureFiles($capsule, $reference, $capsule['binding'], 'peanut.source-application-failure-restore-instructions.v1');
        $originFiles[$referencePath] = $referenceSha;
        $original = SourceApplicationDump::read((string) file_get_contents($origin['backup_payload']['path']), $plan['backup_scope']['tables']);
        $afterimage = SourceApplicationDump::read((string) file_get_contents($capsule['backup_payload']['path']), $capsule['scope']['tables']);
        $tables = SourceApplicationBackup::failureRestoreTables($profile); $changed = [];
        foreach ($original['tables'] as $table => $row) {
            if ($row['sha256'] !== $afterimage['tables'][$table]['sha256']) { $changed[] = $table; }
        }
        if ((str_starts_with($profile, 'prefix:') ? array_diff($changed, $tables) !== [] : $changed !== $tables)
            || ($profile !== 'file-adoption-relocation' && ($original['tables']['pa_notice_log']['insert_rows'] !== 0
                || $afterimage['tables']['pa_notice_log']['insert_rows'] !== 0))) { throw new RuntimeException('APPLICATION_SCHEMA_FAILURE_RESTORE_SCOPE_CHANGED'); }
        $window = $this->sourceFailureWindow($maintenancePath, $binding, ['path' => $capsulePath, 'sha256' => $capsuleSha], $tables, self::SOURCE_READ_TABLES);
        $originFiles += $window['files'];
        $registry = \projectResourceRegistry($registrySha);
        $tools = array_values(array_filter($registry['resources']['tooling'] ?? [], static fn(array $row): bool => ($row['stable_resource_id'] ?? null) === $toolId));
        $resources = \activeLeaseResources(\requiredEnvironment('PEANUT_RESOURCE_LEASE_PROOF'));
        if (count($tools) !== 1 || $registrySha !== $binding['registry_sha256'] || $toolId !== ($capsule['tooling_resource_id'] ?? null)
            || !in_array($toolId, $resources['tooling-resource-id'] ?? [], true) || !in_array($output, $resources['output-dir'] ?? [], true)) {
            throw new RuntimeException('APPLICATION_SCHEMA_BACKUP_NATIVE_TOOL_OUTPUT_REQUIRED');
        }
        return \app\common\infrastructure\scaffold\ScaffoldUpgradeRunner::sourceProductLock(dirname($this->serverRoot),
            function () use ($pdo, $binding, $facts, $pending, $baseline, $origin, $originFiles, $capsule, $capsulePath, $capsuleSha, $failure, $plan, $window, $tools, $output, $profile, $tables): array {
                $lockName = 'peanut_migrate_' . substr(hash('sha256', $binding['database']['database']), 0, 48);
                $lock = $pdo->prepare('SELECT GET_LOCK(?, 10)');
                try { $lock->execute([$lockName]); $held = (int) $lock->fetchColumn() === 1; } finally { $lock->closeCursor(); }
                if (!$held) { throw new RuntimeException('APPLICATION_SCHEMA_BACKUP_SCHEMA_LOCK_REQUIRED'); }
                try {
                    $guard = function () use ($pdo, $binding, $facts, $originFiles, $lockName, $window, $tools): void {
                        $requiredWindow = (int) ($tools[0]['source_schema_backup']['timeout_seconds'] ?? 600) + 60;
                        if ($facts() !== $binding || time() + $requiredWindow >= $window['expires_at']) { throw new RuntimeException('APPLICATION_SCHEMA_SOURCE_DRIFT'); }
                        foreach ($originFiles as $path => $sha) {
                            $this->sourceProtectedFile($path, null);
                            if (hash_file('sha256', $path) !== $sha) { throw new RuntimeException('APPLICATION_SCHEMA_FAILURE_BACKUP_CHANGED'); }
                        }
                        $query = $pdo->prepare('SELECT IS_USED_LOCK(?) = CONNECTION_ID()');
                        try { $query->execute([$lockName]); $held = (int) $query->fetchColumn() === 1; } finally { $query->closeCursor(); }
                        if (!$held) { throw new RuntimeException('APPLICATION_SCHEMA_BACKUP_SCHEMA_LOCK_REQUIRED'); }
                    };
                    if ($profile === 'file-adoption-relocation') {
                        $guard();
                        $scope = SourceApplicationBackup::scope($pdo, $pending, $binding, $this->sourceMetadataTool, $guard);
                        if (!$this->sourceSame($scope, $capsule['scope'])) { throw new RuntimeException('APPLICATION_SCHEMA_FAILURE_SNAPSHOT_CHANGED'); }
                        $this->sourceFailureForeignKeys($plan['backup_scope'], $scope, $binding['database']['database'], ['pa_schema_migration']);
                        $parent = @lstat(dirname($output));
                        if (!is_array($parent) || realpath(dirname($output)) !== dirname($output) || ($parent['mode'] & 0022) !== 0
                            || !in_array($parent['uid'], [0, posix_geteuid()], true) || file_exists($output) || is_link($output) || !mkdir($output, 0700)) {
                            throw new RuntimeException('APPLICATION_SCHEMA_BACKUP_OUTPUT_UNSAFE');
                        }
                        $after = function () use ($pdo, $guard, $baseline): void {
                            $guard(); $rows = $pdo->query('SELECT * FROM pa_schema_migration ORDER BY migration_id')->fetchAll(PDO::FETCH_ASSOC);
                            if ($this->digest($rows) !== $baseline['application_rows_sha256'] || count($rows) !== $baseline['application_rows_count']
                                || $this->digest($this->sourceLedgerShape($pdo)) !== $baseline['application_shape_sha256']) {
                                throw new RuntimeException('APPLICATION_SCHEMA_FAILURE_RESTORE_POSTIMAGE_INVALID');
                            }
                        };
                        $restoration = SourceApplicationBackup::restoreApplicationFailure($binding, $tools[0], $origin['backup_manifest']['path'],
                            $output . '/restore', $guard, $after, $profile, $capsulePath, $pdo);
                        $record = ['schema_version' => 1, 'protocol' => 'peanut.source-development-failure-restoration.v1', 'status' => 'restored',
                            'binding' => $binding, 'failure_snapshot' => ['path' => $capsulePath, 'sha256' => $capsuleSha], 'original_backup' => $origin,
                            'restoration' => $restoration, 'restoration_profile' => $profile, 'write_tables' => ['pa_schema_migration'],
                            'migration' => 'not_run', 'writers' => 'not_opened', 'qualification' => 'not_run'];
                        $path = $this->saveSourceResult($record);
                        return $record + ['result' => ['path' => $path, 'sha256' => hash_file('sha256', $path)]];
                    }
                    $bindingGuard = function () use ($pdo, $failure, $plan, $profile): void {
                        if (!str_starts_with($profile, 'prefix:')) { return; }
                        $bindings = PermissionSchemaMigrationProof::readonlyBindingDigests($pdo);
                        if (!$this->sourceSame($bindings, $plan['protected_permission_bindings'] ?? null)
                            || !$this->sourceSame($bindings, $failure['protected_permission_bindings'] ?? null)) {
                            throw new RuntimeException('APPLICATION_SCHEMA_FAILURE_SNAPSHOT_CHANGED');
                        }
                    };
                    $failedGuard = function () use ($pdo, $guard, $failure, $baseline, $bindingGuard): void {
                        $guard(); $bindingGuard(); $ledger = $pdo->query('SELECT * FROM pa_schema_migration ORDER BY migration_id')->fetchAll(PDO::FETCH_ASSOC);
                        if ($ledger !== $failure['ledger'] || (int) $pdo->query('SELECT COUNT(*) FROM pa_notice_log')->fetchColumn() !== 0
                            || $this->sourceFailureLedger($ledger, $baseline, $this->sourceLedgerShape($pdo)) !== $failure['failed_migration']) {
                            throw new RuntimeException('APPLICATION_SCHEMA_FAILURE_SNAPSHOT_CHANGED');
                        }
                    };
                    $restoredGuard = function () use ($pdo, $guard, $baseline, $bindingGuard): void {
                        $guard(); $bindingGuard(); $ledger = $pdo->query('SELECT * FROM pa_schema_migration ORDER BY migration_id')->fetchAll(PDO::FETCH_ASSOC);
                        if ($this->digest($ledger) !== $baseline['application_rows_sha256'] || count($ledger) !== $baseline['application_rows_count']
                            || $this->digest($this->sourceLedgerShape($pdo)) !== $baseline['application_shape_sha256']
                            || (int) $pdo->query('SELECT COUNT(*) FROM pa_notice_log')->fetchColumn() !== 0) {
                            throw new RuntimeException('APPLICATION_SCHEMA_FAILURE_RESTORE_POSTIMAGE_INVALID');
                        }
                    };
                    $failedGuard(); $scope = SourceApplicationBackup::scope($pdo, $pending, $binding, $this->sourceMetadataTool, $guard);
                    if (!$this->sourceSame($scope, $capsule['scope'])) { throw new RuntimeException('APPLICATION_SCHEMA_FAILURE_SNAPSHOT_CHANGED'); }
                    $this->sourceFailureForeignKeys($plan['backup_scope'], $scope, $binding['database']['database'], $tables, str_starts_with($profile, 'prefix:'));
                    $parent = @lstat(dirname($output));
                    if (!is_array($parent) || realpath(dirname($output)) !== dirname($output) || ($parent['mode'] & 0022) !== 0
                        || !in_array($parent['uid'], [0, posix_geteuid()], true) || file_exists($output) || is_link($output) || !mkdir($output, 0700)) {
                        throw new RuntimeException('APPLICATION_SCHEMA_BACKUP_OUTPUT_UNSAFE');
                    }
                    $before = SourceApplicationBackup::capture($binding, $failure, $this->digest($failure), $scope, $tools[0], $output . '/before', $failedGuard, 'application-failure');
                    if ($before['backup_payload_sha256'] !== $capsule['backup_payload']['sha256']) { throw new RuntimeException('APPLICATION_SCHEMA_FAILURE_SNAPSHOT_CHANGED'); }
                    $restoration = SourceApplicationBackup::restoreApplicationFailure($binding, $tools[0], $origin['backup_manifest']['path'], $output . '/restore', $failedGuard, $restoredGuard, $profile, $capsulePath);
                    $restoredGuard(); $restoredScope = SourceApplicationBackup::scope($pdo, $pending, $binding, $this->sourceMetadataTool, $guard);
                    if (!$this->sourceSame($restoredScope, $plan['backup_scope'])) { throw new RuntimeException('APPLICATION_SCHEMA_FAILURE_RESTORE_POSTIMAGE_INVALID'); }
                    $postimage = ['pending' => $pending, 'selected' => $baseline['selected'], 'restoration_postimage' => true,
                        'original_backup' => $origin, 'failure_snapshot' => ['path' => $capsulePath, 'sha256' => $capsuleSha],
                        'ledger' => $pdo->query('SELECT * FROM pa_schema_migration ORDER BY migration_id')->fetchAll(PDO::FETCH_ASSOC)];
                    $after = SourceApplicationBackup::capture($binding, $postimage, $this->digest($postimage), $restoredScope, $tools[0], $output . '/after', $restoredGuard, 'application-restoration');
                    if ($after['backup_payload_sha256'] !== $origin['backup_payload']['sha256']) { throw new RuntimeException('APPLICATION_SCHEMA_FAILURE_RESTORE_POSTIMAGE_INVALID'); }
                    $record = ['schema_version' => 1, 'protocol' => 'peanut.source-development-failure-restoration.v1', 'status' => 'restored',
                        'binding' => $binding, 'failure_snapshot' => ['path' => $capsulePath, 'sha256' => $capsuleSha], 'original_backup' => $origin,
                        'before' => $before, 'restoration' => $restoration, 'after' => $after, 'write_tables' => $restoration['tables'],
                        'restoration_profile' => $profile, 'unchanged_other_tables' => count($scope['tables']) - count($tables), 'full_native_postimage_equals_original' => true,
                        'constraint_checks' => 'enabled', 'migration' => 'not_run', 'writers' => 'not_opened', 'qualification' => 'not_run'];
                    $path = $this->saveSourceResult($record);
                    return ['status' => 'restored', 'result' => ['path' => $path, 'sha256' => hash_file('sha256', $path)],
                        'tables' => $restoration['tables'], 'full_native_postimage_equals_original' => true, 'migration' => 'not_run', 'qualification' => 'not_run'];
                } finally { $pdo->prepare('SELECT RELEASE_LOCK(?)')->execute([$lockName]); }
            });
    }

    private function sourceFailureWindow(string $path, array $binding, array $capsule, array $writeTables, array $readTables): array
    {
        $window = $this->sourceProtectedJson($path, $sha);
        if (($window['protocol'] ?? null) !== 'peanut.source-development-failure-maintenance.v1' || ($window['state'] ?? null) !== 'active'
            || !$this->sourceSame($window['source'] ?? null, $binding['source']) || !$this->sourceSame($window['database'] ?? null, $binding['database'])
            || !$this->sourceSame($window['authority'] ?? null, $binding['authority']) || ($window['registry_sha256'] ?? null) !== $binding['registry_sha256']
            || ($window['failure_snapshot'] ?? null) !== $capsule || ($window['write_tables'] ?? null) !== $writeTables || ($window['read_tables'] ?? null) !== $readTables
            || ($window['excluded_writers'] ?? null) !== ['http', 'workers', 'other-writers']
            || !is_int($window['observed_at'] ?? null) || $window['observed_at'] < (int) $binding['authority']['created_at'] || $window['observed_at'] > time()
            || !is_int($window['expires_at'] ?? null) || $window['expires_at'] <= time() || $window['expires_at'] > (int) $binding['authority']['expires_at']
            || !is_array($window['receipts'] ?? null) || $window['receipts'] === []) { throw new RuntimeException('APPLICATION_SCHEMA_SOURCE_WINDOW_REQUIRED'); }
        $files = [$path => $sha];
        foreach ($window['receipts'] as $receipt) {
            $this->sourceProtectedFile($receipt['path'] ?? '');
            if (hash_file('sha256', $receipt['path']) !== ($receipt['sha256'] ?? null)) { throw new RuntimeException('APPLICATION_SCHEMA_SOURCE_WINDOW_REQUIRED'); }
            $files[$receipt['path']] = $receipt['sha256'];
        }
        return ['files' => $files, 'expires_at' => $window['expires_at']];
    }

    private function sourceFailureForeignKeys(array $before, array $current, string $database, array $tables = ['pa_notice_log', 'pa_schema_migration'], bool $preserveParents = false): void
    {
        $parents = $preserveParents ? array_values(array_intersect($tables, ['pa_permission', 'pa_role', 'pa_tenant', 'pa_tenant_session'])) : [];
        $selected = [];
        foreach ($current['triggers'] as $trigger) { if (in_array($trigger['table'], $tables, true)) { throw new RuntimeException('APPLICATION_SCHEMA_FAILURE_RESTORE_DEPENDENCY_UNKNOWN'); } }
        foreach ([$before, $current] as $scope) {
            $keys = [];
            foreach ($scope['foreign_keys'] as $row) {
                if ($row['REFERENCED_TABLE_SCHEMA'] === $database && in_array($row['REFERENCED_TABLE_NAME'], $tables, true)
                    && $row['TABLE_SCHEMA'] !== $database) { throw new RuntimeException('APPLICATION_SCHEMA_FAILURE_RESTORE_DEPENDENCY_UNKNOWN'); }
                if ($row['REFERENCED_TABLE_SCHEMA'] === $database && in_array($row['REFERENCED_TABLE_NAME'], $tables, true)
                    && !in_array($row['REFERENCED_TABLE_NAME'], $parents, true)) {
                    throw new RuntimeException('APPLICATION_SCHEMA_FAILURE_RESTORE_DEPENDENCY_UNKNOWN');
                }
                if ($row['TABLE_SCHEMA'] !== $database || (!in_array($row['TABLE_NAME'], $tables, true)
                    && !in_array($row['REFERENCED_TABLE_NAME'], $parents, true))) { continue; }
                $stableParent = in_array($row['REFERENCED_TABLE_NAME'], array_diff($parents, ['pa_permission']), true)
                    && in_array($row['REFERENCED_COLUMN_NAME'], $row['REFERENCED_TABLE_NAME'] === 'pa_role' ? ['id', 'tenant_id'] : ['id'], true);
                // These original external parents are never written. Native FK checks stay on.
                $edge = [$row['TABLE_NAME'], $row['COLUMN_NAME'], $row['REFERENCED_TABLE_NAME'], $row['REFERENCED_COLUMN_NAME']];
                $externalParent = $preserveParents && in_array($edge, [
                    ['pa_role_permission', 'tenant_id', 'pa_tenant_member', 'tenant_id'],
                    ['pa_role_permission', 'granted_by_member_id', 'pa_tenant_member', 'id'],
                    ['pa_tenant_session', 'account_id', 'pa_account', 'id'],
                    ['pa_tenant_session', 'tenant_id', 'pa_tenant_member', 'tenant_id'],
                    ['pa_tenant_session', 'tenant_member_id', 'pa_tenant_member', 'id'],
                ], true);
                if ($row['REFERENCED_TABLE_SCHEMA'] !== $database || (!in_array($row['REFERENCED_TABLE_NAME'], $scope['tables'], true) && !$externalParent)
                    || (!$stableParent && (!in_array($row['UPDATE_RULE'], ['NO ACTION', 'RESTRICT'], true)
                        || !in_array($row['DELETE_RULE'], ['NO ACTION', 'RESTRICT'], true)))) {
                    throw new RuntimeException('APPLICATION_SCHEMA_FAILURE_RESTORE_DEPENDENCY_UNKNOWN');
                }
                $keys[] = $row;
            }
            $selected[] = $keys;
        }
        if (!$this->sourceSame($selected[0], $selected[1])) { throw new RuntimeException('APPLICATION_SCHEMA_FAILURE_RESTORE_DEPENDENCY_UNKNOWN'); }
    }

    private function sourceFailureLedger(array $ledger, array $baseline, array $shape): array
    {
        $pending = $baseline['pending'];
        $failed = array_values(array_filter($ledger, static fn(array $row): bool => $row['status'] === 'failed'
            && ($pending !== [FileAdoptionRelocation::ID] || $row['migration_id'] === FileAdoptionRelocation::ID)));
        $executed = array_values(array_filter($ledger, static fn(array $row): bool => in_array($row['migration_id'], $pending, true)));
        $old = array_values(array_filter($ledger, static fn(array $row): bool => !in_array($row['migration_id'], $pending, true)));
        $failedIndex = count($failed) === 1 ? array_search($failed[0]['migration_id'], $pending, true) : false;
        if ($failedIndex === false || array_column($executed, 'migration_id') !== array_slice($pending, 0, $failedIndex + 1)
            || $failed[0]['checksum'] !== $baseline['selected'][$pending[$failedIndex]]
            || $failed[0]['release_version'] !== $baseline['release_versions'][$pending[$failedIndex]]
            || $failed[0]['started_at'] === null || $failed[0]['finished_at'] === null || !is_string($failed[0]['error_code'])
            || $this->digest($old) !== $baseline['application_rows_sha256'] || count($old) !== $baseline['application_rows_count']
            || $this->digest($shape) !== $baseline['application_shape_sha256']) {
            throw new RuntimeException('APPLICATION_SCHEMA_FAILED_LEDGER_REQUIRED');
        }
        foreach (array_slice($executed, 0, $failedIndex) as $row) {
            $id = $row['migration_id'];
            if ($row['status'] !== 'applied' || $row['checksum'] !== $baseline['selected'][$id]
                || $row['release_version'] !== $baseline['release_versions'][$id]
                || $row['started_at'] === null || $row['finished_at'] === null || $row['error_code'] !== null) {
                throw new RuntimeException('APPLICATION_SCHEMA_FAILED_LEDGER_REQUIRED');
            }
        }
        return $failed[0];
    }

    private function sourceDevelopmentBackup(PDO $pdo, ApplicationMigrationRunner $runner, array $binding, callable $facts, array $files,
        string $target, array $versions, string $planPath, string $toolId, string $output): array
    {
        $plan = $this->sourceProtectedJson($planPath);
        $this->assertSourceDocument($plan, 'peanut.source-development-schema-plan.v1');
        if (($plan['metadata_tool'] ?? '') !== $this->sourceMetadataTool) { throw new RuntimeException('APPLICATION_SCHEMA_PLAN_STALE'); }
        if (!$this->sourceSame($plan['binding'] ?? null, $binding) || !is_array($plan['baseline'] ?? null) || !is_array($plan['backup_scope'] ?? null)) {
            throw new RuntimeException('APPLICATION_SCHEMA_PLAN_STALE');
        }
        $registry = \projectResourceRegistry($registrySha);
        $tools = array_values(array_filter($registry['resources']['tooling'] ?? [], static fn(array $row): bool => ($row['stable_resource_id'] ?? null) === $toolId));
        $resources = \activeLeaseResources(\requiredEnvironment('PEANUT_RESOURCE_LEASE_PROOF'));
        if (count($tools) !== 1 || $registrySha !== $binding['registry_sha256']
            || !in_array($toolId, $resources['tooling-resource-id'] ?? [], true) || !in_array($output, $resources['output-dir'] ?? [], true)) {
            throw new RuntimeException('APPLICATION_SCHEMA_BACKUP_NATIVE_TOOL_OUTPUT_REQUIRED');
        }
        return \app\common\infrastructure\scaffold\ScaffoldUpgradeRunner::sourceProductLock(dirname($this->serverRoot),
            function () use ($pdo, $runner, $binding, $facts, $files, $target, $versions, $plan, $tools, $output): array {
                $result = null;
                $runner->run($files, $target, $versions['release_sequence_version'], true, null,
                    function (PDO $actual, array $selection) use ($pdo, $binding, $facts, $files, $target, $versions, $plan, $tools, $output, &$result): void {
                        $recheck = function () use ($pdo, $actual, $binding, $facts, $files, $target, $versions, $plan): void {
                            if ($actual !== $pdo || $facts() !== $binding
                                || !$this->sourceSame($this->sourceBaseline($pdo, $files, $target, $versions['release_sequence_version']), $plan['baseline'])
                                || !$this->sourceSame(SourceApplicationBackup::scope($pdo, $plan['baseline']['pending'], $binding, $this->sourceMetadataTool,
                                    static function () use ($facts, $binding): void { if ($facts() !== $binding) { throw new RuntimeException('APPLICATION_SCHEMA_SOURCE_DRIFT'); } }), $plan['backup_scope'])) {
                                throw new RuntimeException('APPLICATION_SCHEMA_BACKUP_SNAPSHOT_CHANGED');
                            }
                            $lock = $pdo->prepare('SELECT IS_USED_LOCK(?) = CONNECTION_ID()');
                            try {
                                $lock->execute(['peanut_migrate_' . substr(hash('sha256', $binding['database']['database']), 0, 48)]);
                                if ((int) $lock->fetchColumn() !== 1) { throw new RuntimeException('APPLICATION_SCHEMA_BACKUP_SCHEMA_LOCK_REQUIRED'); }
                            } finally { $lock->closeCursor(); }
                        };
                        if ($selection['pending'] !== $plan['baseline']['pending']) { throw new RuntimeException('APPLICATION_SCHEMA_PLAN_STALE'); }
                        $recheck();
                        $result = SourceApplicationBackup::capture($binding, $plan['baseline'], $this->digest($plan['baseline']), $plan['backup_scope'], $tools[0], $output, $recheck);
                        if (($binding['source']['kind'] ?? null) === 'generated-application') {
                            $result['application_recovery'] = SourceApplicationBackup::sourceApplicationProtection($binding, $plan, $tools[0], $output, $recheck);
                        }
                    });
                if (!is_array($result)) { throw new RuntimeException('APPLICATION_SCHEMA_BACKUP_INCOMPLETE'); }
                return $result + ['scope' => 'application-schema', 'maintenance_scope' => ($binding['source']['kind'] ?? null) === 'generated-application' ? 'source-application' : 'source-development', 'plugins' => 'not_run', 'product_database' => 'not_verified'];
            });
    }

    private function sourceDevelopmentFacts(PDO $pdo, object $connection, array $actor, string $requestId, bool $ddl): array
    {
        $admission = $this->sourceDevelopmentAdmission($actor, $ddl);
        $context = app()->make(\app\common\execution\CurrentExecutionContext::class)->current();
        if (!$context instanceof \app\common\execution\InstanceExecutionContext || $context->requestId() !== $requestId
            || $context->operation() !== 'console.schema:application' || $pdo->inTransaction()
            || \think\facade\Db::connect() !== $connection || $connection->getPdo() !== $pdo) { throw new RuntimeException('APPLICATION_SCHEMA_MASTER_MISMATCH'); }
        $connectionId = (string) $pdo->query('SELECT CONNECTION_ID()')->fetchColumn();
        if ($this->sourceConnectionId !== null && $this->sourceConnectionId !== $connectionId) { throw new RuntimeException('APPLICATION_SCHEMA_MASTER_MISMATCH'); }
        $this->sourceConnectionId = $connectionId;
        return $admission + ['database' => InstallationExecutionHost::migrationDatabaseIdentity($pdo)];
    }

    /** No PDO initialization or SQL: the canonical native lease is checked first. */
    private function sourceDevelopmentAdmission(array $actor, bool $ddl): array
    {
        return (new SourceDevelopmentMaintenanceAdmission($this->serverRoot))->capture($actor, $ddl);
    }

    /** Current read authorization precedes PDO initialization and is independent of historical grants. */
    private function assertSourceReadScope(array $config, array $tables): void
    {
        $registry = \projectResourceRegistry($unused);
        $database = \registeredDatabase($registry, $config['resource_id']);
        $schema = \mysqlSchemaLeaseResource($database, \registeredDatabaseEndpoint($database, $config['consumer']), $config);
        $resources = \activeLeaseResources(\requiredEnvironment('PEANUT_RESOURCE_LEASE_PROOF'));
        if (!isset($database['lease_policy']['shared_access']) && !isset($resources['mysql-access'])) { return; }
        if (!$this->sourceReadScopeCovered($resources, $schema, $tables)) {
            throw new RuntimeException('APPLICATION_SCHEMA_SOURCE_READ_SCOPE_REQUIRED');
        }
    }

    private function sourceReadScopeCovered(array $resources, string $schema, array $tables): bool
    {
        foreach ($resources['mysql-access'] ?? [] as $raw) {
            $access = json_decode($raw, true, 64, JSON_THROW_ON_ERROR);
            if (($access['schema'] ?? null) !== $schema || ($access['complete'] ?? null) !== true) { continue; }
            if (in_array($access['operation'] ?? null, ['ddl', 'exclusive'], true) && ($access['tables'] ?? null) === []) { return true; }
            $reads = array_column(array_filter($access['tables'] ?? [], static fn(array $table): bool => ($table['rows'] ?? null) === '*'
                && in_array($table['access'] ?? null, ['read', 'write'], true)), 'table');
            if (array_diff($tables, $reads) === []) { return true; }
        }
        return false;
    }

    /** Only already accepted, safe after results; never serializes private business inputs. */
    private function sourceBusinessContracts(array $poststates): array
    {
        $contracts = [];
        foreach ($poststates as $id => $row) {
            $contract = match (true) {
                in_array($id, self::MENU_MIGRATIONS, true) => MenuSchemaMigrationProof::class,
                in_array($id, self::PERMISSION_MIGRATIONS, true) => PermissionSchemaMigrationProof::class,
                $id === '20260909-notification-sms-reservation' => SmsReservationMigrationProof::class,
                $id === '20261007-auth-client-schema' => AuthClientSchemaMigrationProof::class,
                $id === FileAdoptionRelocation::ID => FileAdoptionRelocation::class,
                default => throw new RuntimeException('APPLICATION_SCHEMA_RECEIPT_CONTRACT_UNKNOWN'),
            };
            if (($row['contract'] ?? null) !== $contract) { throw new RuntimeException('APPLICATION_SCHEMA_RECEIPT_CONTRACT_UNKNOWN'); }
            $file = (new \ReflectionClass($contract))->getFileName();
            if (!is_string($file) || realpath($file) !== $file
                || ($contract === FileAdoptionRelocation::class ? $file !== __DIR__ . '/FileAdoptionRelocation.php'
                    : !str_starts_with($file, $this->serverRoot . '/app/modules/official/'))) {
                throw new RuntimeException('APPLICATION_SCHEMA_RECEIPT_CONTRACT_UNKNOWN');
            }
            $contracts[$id] = ['contract' => $contract, 'source_sha256' => $this->fileDigest($file)];
        }
        ksort($contracts, SORT_STRING);
        return $contracts;
    }

    private function assertReceiptSourceFile(string $head, string $file, string $sha): void
    {
        $root = dirname($this->serverRoot);
        if (!str_starts_with($file, $root . '/') || $this->fileDigest($file) !== $sha
            || \leaseGit(['-C', $root, 'rev-parse', $head . ':' . substr($file, strlen($root) + 1)])
                !== \leaseGit(['-C', $root, 'hash-object', '--no-filters', '--', $file])) {
            throw new RuntimeException('APPLICATION_SCHEMA_ORIGINAL_CONTRACT_VERSION_UNAVAILABLE');
        }
    }

    /** Revalidates the original native writer artifact; it does not rerun business before/after. */
    private function verifySourceReceipt(PDO $pdo, array $current, callable $facts, string $path): array
    {
        $directory = $this->serverRoot . '/private/installation/application-schema/source-development';
        if (realpath(dirname($path)) !== $directory || preg_match('/\Aresult-[a-f0-9]{32}\.json\z/D', basename($path)) !== 1) {
            throw new RuntimeException('APPLICATION_SCHEMA_RESULT_ORIGIN_INVALID');
        }
        $parent = $this->serverRoot;
        foreach (['private', 'installation', 'application-schema', 'source-development'] as $part) {
            $parent .= '/' . $part;
            if (realpath($parent) !== $parent || !is_dir($parent) || is_link($parent)
                || !in_array(fileowner($parent), [0, fileowner($this->serverRoot)], true) || (fileperms($parent) & 0022) !== 0) {
                throw new RuntimeException('APPLICATION_SCHEMA_RESULT_ORIGIN_INVALID');
            }
        }
        if ((fileperms($directory) & 0777) !== 0700) { throw new RuntimeException('APPLICATION_SCHEMA_RESULT_ORIGIN_INVALID'); }
        $record = $this->sourceProtectedJson($path); $receiptSha = $this->fileDigest($path);
        $this->assertSourceDocument($record, 'peanut.source-development-schema-result.v1');
        $original = $record['binding'] ?? null; $evidence = $record['execution_evidence'] ?? null; $baseline = $record['baseline'] ?? null;
        if (($record['operation'] ?? null) !== 'apply' || ($record['status'] ?? null) !== 'completed'
            || !is_array($original) || !is_array($evidence) || !is_array($baseline)
            || ($evidence['proof_kind'] ?? null) !== 'same_process_native_migration_window'
            || !is_int($evidence['observed_at'] ?? null) || !is_string($evidence['request_id'] ?? null) || $evidence['request_id'] === ''
            || !$this->sourceSame($original['database'] ?? null, $current['database'])
            || ($original['resource_id'] ?? null) !== $current['resource_id'] || ($original['endpoint_id'] ?? null) !== $current['endpoint_id']
            || ($original['source']['root'] ?? null) !== dirname($this->serverRoot)
            || preg_match('/\A[a-f0-9]{40}\z/D', (string) ($original['source']['head'] ?? '')) !== 1
            || preg_match('/\A[a-f0-9]{40}\z/D', (string) ($original['source']['tree'] ?? '')) !== 1
            || ($evidence['qualification'] ?? null) !== 'not_run' || ($evidence['restore'] ?? null) !== 'not_executed') {
            throw new RuntimeException('APPLICATION_SCHEMA_ORIGINAL_RECEIPT_REQUIRED');
        }
        // Resolve the original commit in this actual source repository, without changing a checkout.
        if (\leaseGit(['-C', dirname($this->serverRoot), 'rev-parse', $original['source']['head'] . '^{tree}']) !== $original['source']['tree']) {
            throw new RuntimeException('APPLICATION_SCHEMA_ORIGINAL_SOURCE_UNKNOWN');
        }
        $this->assertReceiptSourceFile($original['source']['head'], __FILE__, (string) ($evidence['writer_sha256'] ?? ''));
        $planPath = $evidence['plan']['path'] ?? '';
        $plan = $this->sourceProtectedJson($planPath);
        $this->assertSourceDocument($plan, 'peanut.source-development-schema-plan.v1');
        if (($evidence['plan']['sha256'] ?? null) !== $this->fileDigest($planPath) || ($evidence['plan_sha256'] ?? null) !== $plan['document_sha256']
            || !$this->sourceSame($plan['binding'] ?? null, $original) || !$this->sourceSame($plan['baseline'] ?? null, $baseline)
            || !is_array($plan['backup_scope'] ?? null) || ($plan['status'] ?? null) !== 'planned') {
            throw new RuntimeException('APPLICATION_SCHEMA_ORIGINAL_PLAN_CHANGED');
        }
        $proofs = $this->sourceDevelopmentEvidence($original, $evidence['window']['path'] ?? '', $evidence['recovery']['path'] ?? '',
            $baseline, $plan['backup_scope'], $evidence['observed_at']);
        if (!$this->sourceSame($proofs['maintenance'], $evidence['window']) || !$this->sourceSame($proofs['recovery'], $evidence['recovery'])
            || !$this->sourceSame($plan['evidence'] ?? null, $proofs) || ($evidence['tables'] ?? null) !== $plan['backup_scope']['tables']) {
            throw new RuntimeException('APPLICATION_SCHEMA_ORIGINAL_EVIDENCE_CHANGED');
        }
        $pending = $baseline['pending']; $ledger = $evidence['ordered_stages'] ?? null;
        $poststates = $record['business_poststates'] ?? null; $bounds = $evidence['execution_bounds'] ?? null;
        if (!is_array($ledger) || array_column($ledger, 'migration_id') !== $pending || ($evidence['ledger_sha256'] ?? null) !== $this->digest($ledger)
            || !is_array($poststates) || !is_array($bounds) || array_keys($bounds) !== $pending
            || !$this->sourceSame($evidence['contracts'] ?? null, $this->sourceBusinessContracts($poststates))) {
            throw new RuntimeException('APPLICATION_SCHEMA_ORIGINAL_COMPLETION_CHANGED');
        }
        foreach ($ledger as $row) {
            $id = $row['migration_id'];
            if (($row['status'] ?? null) !== 'applied' || ($row['checksum'] ?? null) !== self::PRESTATE_SQL[$id]
                || ($row['checksum'] ?? null) !== ($baseline['selected'][$id] ?? null)
                || ($row['release_version'] ?? null) !== ($baseline['release_versions'][$id] ?? null)
                || !is_string($row['started_at'] ?? null) || !is_string($row['finished_at'] ?? null) || ($row['error_code'] ?? null) !== null) {
                throw new RuntimeException('APPLICATION_SCHEMA_ORIGINAL_COMPLETION_CHANGED');
            }
            $clockRequired = in_array($id, ['20260909-notification-sms-reservation', '20261007-readiness-permission', '20261012-align-department-canonical-permissions'], true);
            $clock = $bounds[$id];
            if (!$clockRequired) {
                if ($clock !== null) { throw new RuntimeException('APPLICATION_SCHEMA_ORIGINAL_CLOCK_CHANGED'); }
                continue;
            }
            if (!is_array($clock) || ($clock['scope'] ?? null) !== 'immutable-sql-batch' || ($clock['migration_key'] ?? null) !== $id
                || ($clock['sha256'] ?? null) !== self::PRESTATE_SQL[$id] || !is_int($clock['pdo_object_id'] ?? null) || $clock['pdo_object_id'] <= 0) {
                throw new RuntimeException('APPLICATION_SCHEMA_ORIGINAL_CLOCK_CHANGED');
            }
            foreach (['before', 'after'] as $point) {
                $value = $clock[$point] ?? [];
                if (($value['database'] ?? null) !== $original['database']['database']
                    || !is_string($value['connection_id'] ?? null) || !ctype_digit($value['connection_id'])
                    || !is_string($value['unix_second'] ?? null) || !ctype_digit($value['unix_second'])
                    || preg_match('/\A[0-9]{4}-[0-9]{2}-[0-9]{2} [0-9]{2}:[0-9]{2}:[0-9]{2}\.[0-9]{3}\z/D', (string) ($value['utc_timestamp'] ?? '')) !== 1) {
                    throw new RuntimeException('APPLICATION_SCHEMA_ORIGINAL_CLOCK_CHANGED');
                }
            }
            if ($clock['before']['connection_id'] !== $clock['after']['connection_id'] || $clock['before']['utc_timestamp'] > $clock['after']['utc_timestamp']) {
                throw new RuntimeException('APPLICATION_SCHEMA_ORIGINAL_CLOCK_CHANGED');
            }
        }
        // These functions only check the accepted safe result when liveVerify=false.
        self::assertMenuPoststates($pending, $poststates, false);
        self::assertPermissionPoststates($pending, $poststates, false);
        self::assertSmsPoststates($pending, $poststates, false);
        self::assertAuthPoststates($pdo, $pending, $poststates, false);
        if (in_array(FileAdoptionRelocation::ID, $pending, true)) { PluginLifecycleService::fileAdoptionRelocationHistory($pdo); }
        foreach ($evidence['contracts'] as $contract) {
            $this->assertReceiptSourceFile($original['source']['head'], (string) (new \ReflectionClass($contract['contract']))->getFileName(), $contract['source_sha256']);
        }
        if ($facts() !== $current) { throw new RuntimeException('APPLICATION_SCHEMA_SOURCE_DRIFT'); }
        $pdo->exec('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
        $pdo->exec('SET TRANSACTION READ ONLY');
        $pdo->beginTransaction();
        try {
            $this->sourceLedgerShape($pdo);
            $query = $pdo->prepare('SELECT migration_id,checksum,release_version,status,started_at,finished_at,error_code FROM pa_schema_migration'
                . ' WHERE migration_id IN (' . implode(',', array_fill(0, count($pending), '?')) . ') ORDER BY migration_id');
            try { $query->execute($pending); $actual = $query->fetchAll(PDO::FETCH_ASSOC); } finally { $query->closeCursor(); }
            if (!$this->sourceSame($actual, $ledger)) { throw new RuntimeException('APPLICATION_SCHEMA_ORIGINAL_LEDGER_CHANGED'); }
            $pdo->commit();
        } finally { if ($pdo->inTransaction()) { $pdo->rollBack(); } }
        if ($facts() !== $current || $this->fileDigest($path) !== $receiptSha
            || !$this->sourceSame($proofs, $this->sourceDevelopmentEvidence($original, $evidence['window']['path'], $evidence['recovery']['path'],
                $baseline, $plan['backup_scope'], $evidence['observed_at'])) || $this->fileDigest($planPath) !== $evidence['plan']['sha256']) {
            throw new RuntimeException('APPLICATION_SCHEMA_ORIGINAL_EVIDENCE_CHANGED');
        }
        return ['protocol' => 'peanut.source-development-original-receipt-verification.v1', 'status' => 'verified', 'readonly' => true,
            'verification_level' => 'accepted_original_window_receipt', 'original_window_receipt_verified' => true,
            'original_private_algorithm_recomputed' => false, 'current_target_verified' => false,
            'receipt' => ['path' => $path, 'sha256' => $receiptSha], 'original_source' => $original['source'],
            'original_request_id' => $evidence['request_id'], 'original_observed_at' => $evidence['observed_at'],
            'ordered_stages' => $pending, 'ledger_sha256' => $evidence['ledger_sha256'],
            'later_ledger_rows' => 'outside_original_scope_not_compared', 'restore' => 'not_executed', 'qualification' => 'not_run', 'product_database' => 'not_verified'];
    }

    /**
     * Current window/backup references only. They neither authorize SQL nor prove
     * DB2 migration/restoration: actual Jobs and readback require external acceptance.
     */
    private function sourceDevelopmentEvidence(array $binding, string $maintenancePath, string $recoveryPath, array $baseline, array $backupScope, ?int $recordedAt = null): array
    {
        $applicationRecovery = ($binding['source']['kind'] ?? null) === 'generated-application'
            ? SourceApplicationBackup::sourceApplicationQualification($binding, $baseline, $backupScope, $maintenancePath) : null;
        $at = $recordedAt ?? time();
        if ($at <= 0 || $at > time()) { throw new RuntimeException('APPLICATION_SCHEMA_SOURCE_EVIDENCE_REQUIRED'); }
        $pending = $baseline['pending'] ?? null;
        if (!is_array($pending) || !array_is_list($pending) || $pending === []
            || count(array_filter($pending, 'is_string')) !== count($pending)
            || $pending !== array_values(array_intersect(array_keys(self::PRESTATE_SQL), $pending))) {
            throw new RuntimeException('APPLICATION_SCHEMA_SOURCE_PENDING_SCOPE_REQUIRED');
        }
        $selected = [];
        foreach ($pending as $id) {
            if (($baseline['selected'][$id] ?? null) !== self::PRESTATE_SQL[$id]) {
                throw new RuntimeException('APPLICATION_SCHEMA_SOURCE_PENDING_SCOPE_REQUIRED');
            }
            $selected[$id] = self::PRESTATE_SQL[$id];
        }
        $tables = SourceApplicationBackup::applicationTables($pending);
        $baselineSha = $this->digest($baseline);
        $out = []; $windowStarted = null;
        foreach (['maintenance' => $maintenancePath, 'recovery' => $recoveryPath] as $kind => $path) {
            $value = $this->sourceProtectedJson($path);
            if (($value['schema_version'] ?? null) !== 1 || ($value['protocol'] ?? null) !== 'peanut.source-development-' . $kind . '.v1'
                || !$this->sourceSame($value['source'] ?? null, $binding['source']) || !$this->sourceSame($value['database'] ?? null, $binding['database'])
                || !$this->sourceSame($value['authority'] ?? null, $binding['authority']) || ($value['registry_sha256'] ?? null) !== $binding['registry_sha256']
                || ($value['pending'] ?? null) !== $pending || !$this->sourceSame($value['selected'] ?? null, $selected)
                || ($value['baseline_sha256'] ?? null) !== $baselineSha || ($value['scope'] ?? null) !== $tables
                || !is_int($value['observed_at'] ?? null) || !is_int($value['expires_at'] ?? null)
                || $value['observed_at'] < (int) $binding['authority']['created_at']
                || $value['observed_at'] > $at || $value['expires_at'] <= $at || $value['expires_at'] > (int) $binding['authority']['expires_at']
                || !is_array($value['receipts'] ?? null) || $value['receipts'] === []) { throw new RuntimeException('APPLICATION_SCHEMA_SOURCE_EVIDENCE_REQUIRED'); }
            if ($kind === 'maintenance' && (($value['state'] ?? null) !== 'active'
                || ($value['excluded_writers'] ?? null) !== ['http', 'workers', 'other-writers'])) { throw new RuntimeException('APPLICATION_SCHEMA_SOURCE_WINDOW_REQUIRED'); }
            if ($kind === 'maintenance') { $windowStarted = $value['observed_at']; }
            if ($kind === 'recovery') {
                if (!is_array($value['source_database'] ?? null) || !$this->sourceDatabaseIdentity($value['source_database'])
                    || !is_int($value['capture_started_at'] ?? null) || $value['capture_started_at'] < $windowStarted
                    || $value['capture_started_at'] > $value['observed_at']
                    || !$this->sourceSame($value['source_database'], $binding['database'])
                    || preg_match('/\A[a-f0-9]{64}\z/D', (string) ($value['restore_contract_sha256'] ?? '')) !== 1
                    || array_key_exists('qualification_result', $value)) {
                    throw new RuntimeException('APPLICATION_SCHEMA_SOURCE_RECOVERY_REFERENCES_REQUIRED');
                }
                foreach (['backup_payload', 'restore_tool'] as $key) {
                    $file = $value[$key]['path'] ?? '';
                    if (!is_string($file) || realpath($file) !== $file || ($value[$key]['sha256'] ?? null) !== $this->fileDigest($file)) {
                        throw new RuntimeException('APPLICATION_SCHEMA_SOURCE_RECOVERY_CHANGED');
                    }
                    if ($key === 'backup_payload') { $this->sourceProtectedFile($file, null); }
                }
                // A file name/checksum supplied in a wrapper is not a backup.
                // Require the native capture's real payload, terminal receipts and restore instructions.
                $manifestPath = $value['backup_manifest']['path'] ?? '';
                $manifest = $this->sourceProtectedJson($manifestPath);
                $restorePath = $manifest['restore_instructions']['path'] ?? '';
                $restore = $this->sourceProtectedJson($restorePath);
                if (($value['backup_manifest']['sha256'] ?? null) !== $this->fileDigest($manifestPath)
                    || ($manifest['protocol'] ?? null) !== 'peanut.source-application-backup.v1' || ($manifest['status'] ?? null) !== 'captured'
                    || !$this->sourceSame($manifest['binding'] ?? null, $binding) || ($manifest['baseline_sha256'] ?? null) !== $this->digest($baseline)
                    || ($manifest['pending'] ?? null) !== $pending || !$this->sourceSame($manifest['selected'] ?? null, $selected)
                    || ($manifest['capture_started_at'] ?? null) !== $value['capture_started_at']
                    || ($manifest['observed_at'] ?? null) !== $value['observed_at']
                    || ($manifest['scope']['tables'] ?? null) !== $tables || !$this->sourceSame($manifest['scope'] ?? null, $backupScope)
                    || !$this->sourceSame($manifest['receipts'] ?? null, $value['receipts'])
                    || array_column($value['receipts'], 'phase') !== ['probe-before', 'dump', 'probe-after']
                    || !$this->sourceSame($manifest['backup_payload'] ?? null, $value['backup_payload'])
                    || ($manifest['backup_payload']['bytes'] ?? null) !== filesize($value['backup_payload']['path'])
                    || ($value['restore_tool']['path'] ?? null) !== __DIR__ . '/SourceApplicationBackup.php'
                    || ($manifest['adapter_sha256'] ?? null) !== $value['restore_tool']['sha256']
                    || ($manifest['restore_instructions']['sha256'] ?? null) !== $this->fileDigest($restorePath)
                    || ($value['restore_contract_sha256'] ?? null) !== $this->fileDigest($restorePath)
                    || ($restore['protocol'] ?? null) !== 'peanut.source-application-restore-instructions.v1'
                    || !$this->sourceSame($restore['native_client'] ?? null, $manifest['native_client'] ?? null)
                    || !$this->sourceSame($restore['source'] ?? null, $binding['source']) || !$this->sourceSame($restore['database'] ?? null, $binding['database'])
                    || ($restore['tables'] ?? null) !== $value['scope'] || !$this->sourceSame($restore['payload'] ?? null, $value['backup_payload'])
                    || ($restore['executed'] ?? null) !== false) { throw new RuntimeException('APPLICATION_SCHEMA_SOURCE_RECOVERY_CAPTURE_REQUIRED'); }
                foreach ($value['receipts'] as $receipt) {
                    $stdout = dirname($manifestPath) . '/' . match ($receipt['phase']) {
                        'probe-before' => 'probe-before.txt', 'dump' => 'database.sql', 'probe-after' => 'probe-after.txt',
                    };
                    if ($receipt['path'] !== $stdout . '.process.json') { throw new RuntimeException('APPLICATION_SCHEMA_SOURCE_RECOVERY_CAPTURE_REQUIRED'); }
                    $terminal = $this->sourceProtectedJson($receipt['path'] ?? '');
                    $this->sourceProtectedFile($stdout, $receipt['phase'] === 'dump' ? null : 4194304);
                    $actualOutput = ['path' => $stdout, 'sha256' => $this->fileDigest($stdout), 'bytes' => filesize($stdout)];
                    if (($terminal['phase'] ?? null) !== $receipt['phase'] || ($terminal['local_process_terminal'] ?? null) !== true || ($terminal['rc'] ?? null) !== 0
                        || ($terminal['local_deadline_exceeded'] ?? null) !== false || ($terminal['remote_success_terminal_confirmed'] ?? null) !== true
                        || !$this->sourceSame($terminal['stdout'] ?? null, $actualOutput)
                        || ($receipt['phase'] === 'dump' && !$this->sourceSame($actualOutput, $value['backup_payload']))) { throw new RuntimeException('APPLICATION_SCHEMA_SOURCE_RECOVERY_CAPTURE_REQUIRED'); }
                }
            }
            foreach ($value['receipts'] as $receipt) {
                $receiptPath = $receipt['path'] ?? '';
                $this->sourceProtectedFile($receiptPath);
                if (($receipt['sha256'] ?? null) !== $this->fileDigest($receiptPath)) { throw new RuntimeException('APPLICATION_SCHEMA_SOURCE_EVIDENCE_CHANGED'); }
            }
            $out[$kind] = ['path' => $path, 'sha256' => $this->fileDigest($path)];
            if ($kind === 'recovery') {
                $out[$kind]['references'] = array_intersect_key($value,
                    array_flip(['source_database', 'baseline_sha256', 'backup_manifest', 'backup_payload', 'restore_tool', 'restore_contract_sha256']));
            }
        }
        return $out + ($applicationRecovery === null ? [] : ['application_recovery' => $applicationRecovery]);
    }

    private function sourceDatabaseIdentity(array $identity): bool
    {
        $keys = array_keys($identity); sort($keys, SORT_STRING);
        return $keys === ['database', 'server_hostname', 'server_port', 'server_uuid', 'transport']
            && preg_match('/\A[A-Za-z0-9_]+\z/D', (string) $identity['database']) === 1
            && preg_match('/\A[a-f0-9]{8}(?:-[a-f0-9]{4}){3}-[a-f0-9]{12}\z/Di', (string) $identity['server_uuid']) === 1
            && is_int($identity['server_port']) && $identity['server_port'] > 0 && $identity['server_port'] <= 65535
            && is_string($identity['server_hostname']) && $identity['server_hostname'] !== ''
            && is_string($identity['transport']) && $identity['transport'] !== '';
    }

    private function sourceBaseline(PDO $pdo, array $files, string $target, string $default): array
    {
        $ledgerShape = $this->sourceLedgerShape($pdo);
        $ledger = $pdo->query('SELECT * FROM pa_schema_migration ORDER BY migration_id')->fetchAll(PDO::FETCH_ASSOC);
        $byId = array_column($ledger, null, 'migration_id'); $pending = []; $selected = []; $releaseVersions = [];
        $seedSelection = \app\common\infrastructure\migration\CleanInstallBaseline::selection($pdo, $files, $target, $this->serverRoot . '/database');
        $files = $seedSelection['files'];
        if ($seedSelection['baseline'] !== null) {
            $seed = $seedSelection['baseline'];
            $selected[$seed['key']] = $seed['sha256'];
            $releaseVersions[$seed['key']] = $seed['source_product_version'];
        }
        foreach ($files as $file) {
            $sql = (string) file_get_contents($file); $identity = ApplicationMigrationRunner::releaseIdentity($sql, $default);
            if ($identity['peanut_release'] && version_compare($identity['release_version'], $target, '>')) { continue; }
            $id = basename($file, '.sql'); $sha = hash('sha256', $sql); $selected[$id] = $sha;
            $releaseVersions[$id] = $identity['release_version'];
            if (!isset($byId[$id])) { $pending[] = $id; continue; }
            if ($byId[$id]['status'] !== 'applied' || $byId[$id]['checksum'] !== $sha) { throw new RuntimeException('APPLICATION_SCHEMA_LEDGER_CONFLICT'); }
            // Historical release_version is deliberately preserved, as in the runner.
        }
        $historical = [];
        $supersession = ApplicationMigrationRunner::provenanceSupersession($pdo, $files, $target);
        $extra = array_diff(array_keys($byId), array_keys($selected));
        if ($extra !== []) {
            $transfers = PluginLifecycleService::applicationMigrationTransfers(new PluginLockResolver($this->serverRoot, '../plugins.lock'));
            foreach ($extra as $id) {
                $row = $byId[$id]; $source = $transfers[$id] ?? null;
                if ($id === '20261024-development-admin-credentials') {
                    $sha = 'eeb264889bdfaaa2a86d0dc8f314845ded2b5f70ab90650c8997350e7090aa87';
                    $path = $this->serverRoot . '/database/maintenance/' . $id . '.sql';
                    if ($row['status'] !== 'applied' || $row['checksum'] !== $sha || realpath($path) !== $path
                        || !is_file($path) || is_link($path) || hash_file('sha256', $path) !== $sha
                        || !is_string($row['release_version']) || preg_match('/^[0-9]+\.[0-9]+\.[0-9]+(?:[-+][0-9A-Za-z.+-]+)?$/D', $row['release_version']) !== 1) {
                        throw new RuntimeException('APPLICATION_SCHEMA_HISTORY_SOURCE_REQUIRED');
                    }
                    $historical[] = ['history' => $row, 'source_ownership' => ['migration_id' => $id, 'sha256' => $sha,
                        'source_path' => 'server/database/maintenance/' . $id . '.sql', 'selection' => 'historical_only_never_default_execution']];
                    continue;
                }
                if ($id === FileAdoptionRelocation::ID && $row['status'] === 'applied' && $row['checksum'] === FileAdoptionRelocation::SQL_SHA) {
                    $historical[] = ['history' => $row, 'source_ownership' => PluginLifecycleService::fileAdoptionRelocationHistory($pdo)];
                    continue;
                }
                if ($id === ApplicationMigrationRunner::PROVENANCE_ORIGINAL && $supersession !== null
                    && $supersession['original_ledger'] === $row) {
                    $historical[] = ['history' => $row, 'source_ownership' => $supersession];
                    continue;
                }
                if ($source === null || $row['status'] !== 'applied' || !hash_equals($source['sha256'], (string) $row['checksum'])
                    || !is_string($row['release_version']) || preg_match('/^[0-9]+\.[0-9]+\.[0-9]+(?:[-+][0-9A-Za-z.+-]+)?$/D', $row['release_version']) !== 1) {
                    throw new RuntimeException('APPLICATION_SCHEMA_HISTORY_SOURCE_REQUIRED');
                }
                $historical[] = ['history' => $row, 'source_ownership' => $source];
            }
        }
        $moduleRows = $pdo->query('SELECT * FROM pa_module_migration ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
        $moduleResolutions = null;
        if ($this->fileRelocationInput !== null) {
            $moduleResolutions = PluginLifecycleService::fileRelocationModuleHistory($pdo, $moduleRows, $this->fileRelocationInput);
        } else {
            foreach ($moduleRows as $row) { if ($row['status'] !== 'applied') { throw new RuntimeException('APPLICATION_SCHEMA_MODULE_LEDGER_INCOMPLETE'); } }
        }
        $columns = $pdo->query("SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='pa_module_migration' ORDER BY ORDINAL_POSITION")->fetchAll(PDO::FETCH_COLUMN);
        $shape = $this->sourceModuleShape($pdo);
        return ['pending' => $pending, 'selected' => $selected, 'release_versions' => $releaseVersions, 'target' => $target, 'default_release' => $default,
            'installation_baseline' => $seedSelection['baseline'],
            'supersessions' => $supersession === null ? [] : [$supersession],
            'historical_transferred' => $historical,
            'application_rows_sha256' => $this->digest($ledger), 'application_rows_count' => count($ledger),
            'application_shape_sha256' => $this->digest($ledgerShape),
            'module_rows_sha256' => $this->digest($moduleRows), 'module_rows_count' => count($moduleRows), 'module_columns' => $columns,
            'module_shape_sha256' => $this->digest($shape)]
            + ($moduleResolutions === null ? [] : ['module_native_resolutions' => $moduleResolutions]);
    }

    /** Preserve original history and only the exact, ordered execution prefix. */
    private function assertSourceBaseline(PDO $pdo, array $baseline, array $completed, ?array $migration, string $phase): void
    {
        $pending = $baseline['pending'] ?? null;
        if (!is_array($pending) || !array_is_list($pending) || $pending === []
            || !in_array($phase, ['before', 'after', 'completed'], true)
            || $completed !== array_slice($pending, 0, count($completed))) {
            throw new RuntimeException('APPLICATION_SCHEMA_SOURCE_PENDING_SCOPE_REQUIRED');
        }
        foreach ($pending as $id) {
            if (!is_string($id) || !isset(self::PRESTATE_SQL[$id]) || ($baseline['selected'][$id] ?? null) !== self::PRESTATE_SQL[$id]
                || !is_string($baseline['release_versions'][$id] ?? null)) {
                throw new RuntimeException('APPLICATION_SCHEMA_SOURCE_PENDING_SCOPE_REQUIRED');
            }
        }
        if ($phase === 'completed') {
            if ($migration !== null || $completed !== $pending) { throw new RuntimeException('APPLICATION_SCHEMA_EXECUTION_PREFIX_CHANGED'); }
        } elseif ($migration === null || ($pending[count($completed)] ?? null) !== ($migration['id'] ?? null)
            || ($baseline['selected'][$migration['id']] ?? null) !== ($migration['checksum'] ?? null)
            || ($baseline['release_versions'][$migration['id']] ?? null) !== ($migration['release_version'] ?? null)) {
            throw new RuntimeException('APPLICATION_SCHEMA_EXECUTION_PREFIX_CHANGED');
        }
        $ledger = $pdo->query('SELECT * FROM pa_schema_migration ORDER BY migration_id')->fetchAll(PDO::FETCH_ASSOC);
        if ($this->digest($this->sourceLedgerShape($pdo)) !== $baseline['application_shape_sha256']) { throw new RuntimeException('APPLICATION_SCHEMA_LEDGER_SHAPE_CHANGED'); }
        $old = array_values(array_filter($ledger, static fn(array $row): bool => !in_array($row['migration_id'], $pending, true)));
        if ($this->digest($old) !== $baseline['application_rows_sha256'] || count($old) !== $baseline['application_rows_count']) {
            throw new RuntimeException('APPLICATION_SCHEMA_LEDGER_CHANGED');
        }
        $byId = array_column($ledger, null, 'migration_id');
        foreach ($pending as $id) {
            $state = in_array($id, $completed, true) ? 'applied' : ($phase === 'after' && $id === $migration['id'] ? 'applying' : null);
            $row = $byId[$id] ?? null;
            if ($state === null) {
                if ($row !== null) { throw new RuntimeException('APPLICATION_SCHEMA_EXECUTION_PREFIX_CHANGED'); }
                continue;
            }
            if ($row === null || $row['checksum'] !== $baseline['selected'][$id] || $row['status'] !== $state
                || $row['release_version'] !== $baseline['release_versions'][$id] || $row['error_code'] !== null
                || $row['started_at'] === null || ($state === 'applied' ? $row['finished_at'] === null : $row['finished_at'] !== null)) {
                throw new RuntimeException('APPLICATION_SCHEMA_LEDGER_CHANGED');
            }
        }
        $provenance = in_array('20261019-module-migration-provenance-metadata', $completed, true)
            || ($phase === 'after' && $migration['id'] === '20261019-module-migration-provenance-metadata');
        $rows = $pdo->query('SELECT * FROM pa_module_migration ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
        $oldRows = [];
        foreach ($rows as $row) {
            if ($provenance && !in_array('resolution_kind', $baseline['module_columns'], true)
                && (($row['resolution_kind'] ?? null) !== null || ($row['provenance_json'] ?? null) !== null)) { throw new RuntimeException('APPLICATION_SCHEMA_MODULE_ORIGIN_CHANGED'); }
            $oldRows[] = array_intersect_key($row, array_fill_keys($baseline['module_columns'], true));
        }
        if (count($rows) !== $baseline['module_rows_count'] || $this->digest($oldRows) !== $baseline['module_rows_sha256']) { throw new RuntimeException('APPLICATION_SCHEMA_MODULE_LEDGER_CHANGED'); }
        $shape = $this->sourceModuleShape($pdo);
        if ($provenance && !in_array('resolution_kind', $baseline['module_columns'], true)) {
            unset($shape['columns']['resolution_kind'], $shape['columns']['provenance_json'],
                $shape['checks']['chk_module_migration_resolution'], $shape['checks']['chk_module_migration_provenance']);
        }
        if ($this->digest($shape) !== $baseline['module_shape_sha256']) { throw new RuntimeException('APPLICATION_SCHEMA_MODULE_SHAPE_CHANGED'); }
        if ($provenance) {
            $shape = self::prestateProvenance($pdo, self::prestateTable($pdo, 'pa_module_migration'));
            if ($shape['shape'] !== 'exact_noop') { throw new RuntimeException('APPLICATION_SCHEMA_POSTSTATE_INCOMPLETE'); }
        }
    }

    private function sourceModuleShape(PDO $pdo): array
    {
        $shape = self::prestateTable($pdo, 'pa_module_migration');
        $oldColumns = ['id', 'module_key', 'migration_key', 'module_version', 'checksum', 'batch_no', 'status', 'started_at', 'finished_at', 'error_code'];
        if (!in_array(array_keys($shape['columns']), [$oldColumns, [...$oldColumns, 'resolution_kind', 'provenance_json']], true)) {
            throw new RuntimeException('APPLICATION_SCHEMA_MODULE_SHAPE_UNKNOWN');
        }
        self::prestateColumnShape('pa_module_migration', $shape['columns']);
        self::prestateConstraintShape('pa_module_migration', $shape);
        self::prestateUnique($shape, ['id']); self::prestateUnique($shape, ['module_key', 'migration_key']);
        $status = $shape['checks']['chk_module_migration_status'] ?? null;
        $normal = static fn(string $clause): string => trim(strtolower((string) preg_replace('/\s+|`|_utf8mb4/', '', $clause)), '()');
        $expected = $normal("status IN ('applying','applied','rolled_back','failed')");
        // Compare two closed metadata renderings of this exact status predicate.
        if (!is_array($status) || $status['ENFORCED'] !== 'YES'
            || !in_array($normal($status['CHECK_CLAUSE']), [$expected, str_replace("'", "\\'", $expected)], true)) {
            throw new RuntimeException('APPLICATION_SCHEMA_MODULE_SHAPE_UNKNOWN');
        }
        $query = $pdo->query("SELECT COUNT(*) FROM information_schema.REFERENTIAL_CONSTRAINTS WHERE (CONSTRAINT_SCHEMA=DATABASE() AND TABLE_NAME='pa_module_migration') OR (UNIQUE_CONSTRAINT_SCHEMA=DATABASE() AND REFERENCED_TABLE_NAME='pa_module_migration')");
        if ((int) $query->fetchColumn() !== 0) { throw new RuntimeException('APPLICATION_SCHEMA_MODULE_FOREIGN_KEY_UNKNOWN'); }
        $shape['checks']['chk_module_migration_status']['CHECK_CLAUSE'] = $expected;
        return $shape;
    }

    private function sourceLedgerShape(PDO $pdo): array
    {
        $shape = self::prestateTable($pdo, 'pa_schema_migration');
        if (array_keys($shape['columns']) !== ['migration_id', 'release_version', 'checksum', 'status', 'started_at', 'finished_at', 'error_code']) {
            throw new RuntimeException('APPLICATION_SCHEMA_LEDGER_SHAPE_UNKNOWN');
        }
        $columns = $shape['columns']; $status = $columns['status']; unset($columns['status']);
        self::prestateColumnShape('pa_schema_migration', $columns);
        if (strtolower($status['COLUMN_TYPE']) !== "enum('applying','applied','failed')" || $status['IS_NULLABLE'] !== 'NO'
            || $status['COLUMN_DEFAULT'] !== 'applying' || $status['EXTRA'] !== '') { throw new RuntimeException('APPLICATION_SCHEMA_LEDGER_SHAPE_UNKNOWN'); }
        self::prestateConstraintShape('pa_schema_migration', $shape);
        self::prestateUnique($shape, ['migration_id']);
        $count = $pdo->query("SELECT COUNT(*) FROM information_schema.REFERENTIAL_CONSTRAINTS WHERE (CONSTRAINT_SCHEMA=DATABASE() AND TABLE_NAME='pa_schema_migration') OR (UNIQUE_CONSTRAINT_SCHEMA=DATABASE() AND REFERENCED_TABLE_NAME='pa_schema_migration')")->fetchColumn();
        if ((int) $count !== 0) { throw new RuntimeException('APPLICATION_SCHEMA_LEDGER_FOREIGN_KEY_UNKNOWN'); }
        return $shape;
    }

    private function sourceSame(mixed $left, mixed $right): bool
    {
        return is_array($left) && is_array($right) ? $this->canonical($left) === $this->canonical($right) : $left === $right;
    }

    private function assertSourceDocument(array $document, string $protocol): void
    {
        $sha = $document['document_sha256'] ?? null; unset($document['document_sha256']);
        if (($document['protocol'] ?? null) !== $protocol || ($document['schema_version'] ?? null) !== 1
            || ($document['scope'] ?? null) !== 'application-schema'
            || ($document['maintenance_scope'] ?? null) !== ((($document['binding']['source']['kind'] ?? null) === 'generated-application') ? 'source-application' : 'source-development')
            || ($document['external_gates'] ?? null) !== 'not_evaluated'
            || ($document['plugins'] ?? null) !== 'not_run' || ($document['product_database'] ?? null) !== 'not_verified'
            || !is_string($sha) || !hash_equals($sha, $this->digest($document))) { throw new RuntimeException('APPLICATION_SCHEMA_DOCUMENT_INVALID'); }
    }

    private function sourceProtectedFile(string $path, ?int $maximumBytes = 4194304): void
    {
        $stat = @lstat($path); $owner = fileowner($this->serverRoot);
        if (!str_starts_with($path, '/') || realpath($path) !== $path || !is_file($path) || is_link($path)
            || !is_array($stat) || $stat['nlink'] !== 1 || ($stat['mode'] & 0777) !== 0600
            || !in_array($stat['uid'], [0, $owner], true) || ($maximumBytes !== null && $stat['size'] > $maximumBytes)
            || !function_exists('posix_geteuid') || !in_array(posix_geteuid(), [0, $owner], true)) {
            throw new RuntimeException('APPLICATION_SCHEMA_SOURCE_DOCUMENT_UNSAFE');
        }
    }

    private function sourceProtectedJson(string $path, ?string &$sha = null): array
    {
        $this->sourceProtectedFile($path);
        $bytes = file_get_contents($path);
        if (!is_string($bytes)) { throw new RuntimeException('APPLICATION_SCHEMA_DOCUMENT_INVALID'); }
        $value = json_decode($bytes, true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($value)) { throw new RuntimeException('APPLICATION_SCHEMA_DOCUMENT_INVALID'); }
        $sha = hash('sha256', $bytes);
        return $value;
    }

    private function saveSourceResult(array $record): string
    {
        // Use the existing ignored persistent state namespace; sealing must not dirty source.
        $directory = $this->serverRoot . '/private/installation/application-schema/source-development';
        $parent = $this->serverRoot;
        foreach (['private', 'installation', 'application-schema', 'source-development'] as $part) {
            $parent .= '/' . $part;
            if ((!is_dir($parent) && !mkdir($parent, 0700)) || realpath($parent) !== $parent || is_link($parent)
                || !in_array(fileowner($parent), [0, fileowner($this->serverRoot)], true) || (fileperms($parent) & 0022) !== 0) {
                throw new RuntimeException('APPLICATION_SCHEMA_RESULT_DIRECTORY_UNSAFE');
            }
        }
        if ((fileperms($directory) & 0777) !== 0700) { throw new RuntimeException('APPLICATION_SCHEMA_RESULT_DIRECTORY_UNSAFE'); }
        $path = $directory . '/result-' . bin2hex(random_bytes(16)) . '.json'; $tmp = $path . '.tmp';
        $stream = fopen($tmp, 'x'); $bytes = $this->canonical($record) . "\n";
        if ($stream === false || !chmod($tmp, 0600) || fwrite($stream, $bytes) !== strlen($bytes) || !fflush($stream) || !fsync($stream)) { throw new RuntimeException('APPLICATION_SCHEMA_RESULT_WRITE_FAILED'); }
        fclose($stream);
        if (!rename($tmp, $path)) { throw new RuntimeException('APPLICATION_SCHEMA_RESULT_WRITE_FAILED'); }
        $handle = fopen($directory, 'r');
        if ($handle === false || !fsync($handle)) { throw new RuntimeException('APPLICATION_SCHEMA_RESULT_WRITE_FAILED'); }
        fclose($handle);
        $this->sourceProtectedFile($path);
        return $path;
    }

    public function __construct(private readonly string $serverRoot)
    {
        if (realpath($serverRoot) !== $serverRoot) {
            throw new RuntimeException('APPLICATION_SCHEMA_ROOT_INVALID');
        }
        require_once $serverRoot . '/database/install.php';
        require_once $serverRoot . '/database/environment-guard.php';
    }

    /** Real pending server SQL uses the existing native update window and recovery point. */
    public function serverStage(string $operation, string $workspace): array
    {
        if (!in_array($operation, ['preflight', 'apply', 'verify'], true)
            || !is_file($this->serverRoot . '/.peanut/release-identity.json')
            || is_link($this->serverRoot . '/.peanut/release-identity.json')) {
            throw new RuntimeException('APPLICATION_SCHEMA_NATIVE_SERVER_WORKSPACE_REQUIRED');
        }
        ServerReleaseIdentity::load($this->serverRoot);
        require_once $this->serverRoot . '/docker/scripts/update-database.php';
        return \peanutServerDatabaseStage($operation === 'apply' ? 'migrate' : $operation,
            $this->serverRoot, $workspace, 'application-schema');
    }

    /** No operation record, ledger initialization, maintenance or persistent DB writes. */
    public function preflight(): array
    {
        return $this->observe()['plan'];
    }

    public function apply(string $planPath): array
    {
        $expected = $this->protectedJson($planPath);
        $this->assertDocument($expected, self::PROTOCOL, 'plan_sha256');
        $observation = $this->observe();
        $plan = $observation['plan'];
        if (!hash_equals($expected['plan_sha256'], $plan['plan_sha256'])) {
            throw new RuntimeException('APPLICATION_SCHEMA_PLAN_STALE');
        }
        if ($plan['may_apply'] !== true) {
            return $plan + ['operation' => 'apply', 'execution' => 'not_started'];
        }

        // All admission conditions are re-read, not supplied by an arbitrary JSON
        // recovery flag. Today the public Ops contract cannot qualify pending SQL;
        // only a completely applied chain can reach this native no-op execution.
        $fresh = $this->observe();
        if (!hash_equals($plan['plan_sha256'], $fresh['plan']['plan_sha256'])
            || $fresh['plan']['may_apply'] !== true) {
            throw new RuntimeException('APPLICATION_SCHEMA_PLAN_STALE');
        }
        $result = $fresh['runner']->run($fresh['files'], $fresh['target'], $fresh['default'], false);
        $after = $this->observe();
        if ($result['pending'] !== [] || $after['plan']['pending'] !== []
            || $after['plan']['target'] !== $plan['target']
            || $after['plan']['installation'] !== $plan['installation']) {
            throw new RuntimeException('APPLICATION_SCHEMA_RESULT_INCOMPLETE');
        }
        $record = [
            'schema_version' => 1, 'protocol' => self::RESULT_PROTOCOL,
            'scope' => 'application-schema', 'status' => 'completed',
            'plan_sha256' => $plan['plan_sha256'], 'plan' => $plan,
            'application' => $result, 'application_schema' => 'up_to_date',
            'plugins' => 'not_run', 'product_database' => 'not_verified',
        ];
        $record['result_sha256'] = $this->digest($record);
        $path = $this->saveResult($record);
        return $record + ['result_path' => $path];
    }

    public function verify(string $resultPath): array
    {
        $directory = $this->serverRoot . '/private/application-schema';
        if (realpath(dirname($resultPath)) !== realpath($directory)
            || preg_match('/\Aresult-[a-f0-9]{32}\.json\z/D', basename($resultPath)) !== 1) {
            throw new RuntimeException('APPLICATION_SCHEMA_RESULT_ORIGIN_INVALID');
        }
        $record = $this->protectedJson($resultPath);
        if (!is_file($resultPath . '.hmac') || is_link($resultPath . '.hmac')
            || (fileperms($resultPath . '.hmac') & 0777) !== 0600) {
            throw new RuntimeException('APPLICATION_SCHEMA_RESULT_ORIGIN_INVALID');
        }
        $signature = trim((string) file_get_contents($resultPath . '.hmac'));
        if (!hash_equals(hash_hmac('sha256', (string) file_get_contents($resultPath), $this->resultKey()), $signature)) {
            throw new RuntimeException('APPLICATION_SCHEMA_RESULT_ORIGIN_INVALID');
        }
        $this->assertDocument($record, self::RESULT_PROTOCOL, 'result_sha256');
        $plan = $record['plan'] ?? null;
        if (!is_array($plan)) {
            throw new RuntimeException('APPLICATION_SCHEMA_RESULT_INVALID');
        }
        $this->assertDocument($plan, self::PROTOCOL, 'plan_sha256');
        $current = $this->observe()['plan'];
        if (($record['status'] ?? null) !== 'completed'
            || ($record['application']['pending'] ?? null) !== []
            || ($record['application']['applied'] ?? null) !== []
            || ($record['application']['status'] ?? null) !== 'up_to_date'
            || ($record['plan_sha256'] ?? null) !== $plan['plan_sha256']
            || $current['pending'] !== []
            || !hash_equals($plan['plan_sha256'], $current['plan_sha256'])) {
            throw new RuntimeException('APPLICATION_SCHEMA_VERIFY_INCOMPLETE');
        }
        return [
            'scope' => 'application-schema', 'status' => 'completed',
            'application_schema' => 'verified', 'plugins' => 'not_run',
            'product_database' => 'not_verified', 'result_sha256' => $record['result_sha256'],
            'plan_sha256' => $plan['plan_sha256'],
        ];
    }

    private function observe(): array
    {
        $installation = InstallationExecutionHost::verifiedInstallationFacts($this->serverRoot);
        $target = $this->target($installation);
        $config = \guardedDatabaseConfig();
        \projectResourceRegistry($registrySha256);
        if (($target['deployment_mode'] ?? null) !== getenv('DEPLOYMENT_MODE')) {
            throw new RuntimeException('APPLICATION_SCHEMA_EDITION_MISMATCH');
        }
        $files = \applicationMigrationFiles($this->serverRoot . '/database');
        $versions = \applicationReleaseVersions($this->serverRoot);
        $targetVersion = \applicationMigrationTargetVersion($this->serverRoot, $versions);
        $default = $versions['release_sequence_version'];
        $runner = new ApplicationMigrationRunner($config);
        // Runner checks the existing ledger and immutable hashes before returning.
        // Its GET_LOCK/RELEASE_LOCK are transient; it has no ensureLedger path.
        $selection = $runner->run($files, $targetVersion, $default, true);
        $pdo = \guardedConnection($config);
        $pdo->exec('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
        $pdo->exec('START TRANSACTION WITH CONSISTENT SNAPSHOT, READ ONLY');
        try {
            $chain = [];
            $pending = [];
            foreach ($files as $path) {
                $sql = (string) file_get_contents($path);
                $id = basename($path, '.sql');
                $identity = ApplicationMigrationRunner::releaseIdentity($sql, $default);
                $included = !$identity['peanut_release'] || version_compare($identity['release_version'], $targetVersion, '<=');
                $query = $pdo->prepare('SELECT checksum,status,release_version FROM pa_schema_migration WHERE migration_id=?');
                $query->execute([$id]);
                $row = $query->fetch(PDO::FETCH_ASSOC) ?: null;
                $entry = [
                    'id' => $id, 'sha256' => hash('sha256', $sql),
                    'release_version' => $identity['release_version'],
                    'included' => $included, 'ledger' => $row,
                ];
                if ($included && ($row['status'] ?? null) !== 'applied') {
                    $entry['prestate'] = $this->prestate($pdo, $id, $sql, $pending);
                    $pending[] = $id;
                }
                $chain[] = $entry;
            }
            if ($selection['pending'] !== $pending) {
                throw new RuntimeException('APPLICATION_SCHEMA_LEDGER_CHANGED');
            }
            $lease = $this->lease();
        } finally {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
        }
        $prerequisites = [];
        try {
            $this->resultKey();
        } catch (RuntimeException) {
            $prerequisites[] = ['code' => 'APPLICATION_SCHEMA_NATIVE_RESULT_KEY_UNAVAILABLE', 'status' => 'blocked'];
        }
        if ($pending !== []) {
            foreach ($chain as $entry) {
                if (isset($entry['prestate'])) {
                    $prerequisites[] = [
                        'migration_id' => $entry['id'], 'status' => $entry['prestate']['status'],
                        'code' => 'APPLICATION_SCHEMA_FULL_PRESTATE_UNPROVEN',
                        'dependencies' => $entry['prestate']['dependencies'],
                        'next_step' => $entry['prestate']['next_step'],
                    ];
                }
            }
            if (!$lease['ddl']) {
                $prerequisites[] = ['code' => 'APPLICATION_SCHEMA_DDL_LEASE_REQUIRED', 'status' => 'blocked'];
            }
            // InstanceSafetyQueries exposes a maintenance key and a backup time,
            // not source/DB-bound writer exclusion or a restored recovery scope.
            // Do not invent a proof format, query private Ops tables, or accept a
            // caller-provided JSON assertion as a replacement for that capability.
            $prerequisites[] = [
                'code' => 'APPLICATION_SCHEMA_BOUND_MAINTENANCE_UNAVAILABLE', 'status' => 'blocked',
                'requirement' => 'Native target/DB/owner-bound active window and exclusion of all affected writers.',
            ];
            $prerequisites[] = [
                'code' => 'APPLICATION_SCHEMA_BOUND_RECOVERY_UNAVAILABLE', 'status' => 'blocked',
                'requirement' => 'Native protected recovery evidence covering the complete pending application chain and ledger.',
            ];
        }
        $plan = [
            'schema_version' => 1, 'protocol' => self::PROTOCOL,
            'scope' => 'application-schema', 'status' => $prerequisites === [] ? 'ready' : 'blocked',
            'may_apply' => $prerequisites === [], 'server_root' => $this->serverRoot,
            'installation' => $installation, 'target' => $target,
            'database' => array_intersect_key($config, array_flip(['environment', 'deployment_target', 'resource_id', 'endpoint_id', 'consumer', 'host', 'port', 'database'])),
            'registry_sha256' => $registrySha256,
            'lease' => $lease, 'target_version' => $targetVersion, 'default_release_version' => $default,
            'chain' => $chain, 'pending' => $pending, 'prerequisites' => $prerequisites,
            'supersessions' => $selection['supersessions'] ?? [],
            'observation' => 'Read-only interval; schema metadata is not MVCC and no external-writer exclusion is implied.',
            'transient_effects' => ['native_runner_advisory_lock'],
            'plugins' => 'not_run', 'product_database' => 'not_verified',
        ];
        $plan['plan_sha256'] = $this->digest($plan);
        return ['plan' => $plan, 'runner' => $runner, 'files' => $files, 'target' => $targetVersion, 'default' => $default];
    }

    private function target(array $installation): array
    {
        $root = dirname($this->serverRoot);
        $files = [
            'server/database/install.php', 'server/database/environment-guard.php',
            'server/app/command/ApplicationSchema.php',
            'server/app/common/services/upgrade/ApplicationSchemaHost.php',
            'server/app/common/services/upgrade/SourceDevelopmentMaintenanceAdmission.php',
            'server/app/common/services/upgrade/SourceApplicationBackup.php',
            'server/app/common/services/upgrade/ApplicationMigrationRunner.php',
            'server/app/common/infrastructure/migration/PeanutMigrationAdapter.php',
            'server/app/common/infrastructure/migration/ImmutableSqlMigration.php',
            'server/app/common/infrastructure/migration/PeanutMigrationRun.php',
            'server/app/common/infrastructure/migration/PeanutMigrationService.php',
            'server/app/command/NativeMigrationEntry.php', 'server/app/service.php',
            'server/app/platform/services/plugin/PluginLifecycleService.php',
            'server/app/platform/infrastructure/plugin/PluginLockResolver.php',
            'server/app/common/services/installation/InstallationExecutionHost.php',
            'server/app/common/value/installation/ApplicationSourceIdentity.php',
            'server/app/common/value/installation/ServerReleaseIdentity.php',
            'server/plugins.lock', 'server/config/console.php',
            'server/composer.json', 'server/composer.lock',
            'server/vendor/composer/installed.json', 'server/vendor/.peanut-complete.json',
        ];
        if (file_exists($this->serverRoot . '/.peanut/release-identity.json') || is_link($this->serverRoot . '/.peanut/release-identity.json')) {
            $identity = ServerReleaseIdentity::load($this->serverRoot);
            $kind = 'server-release';
            $mode = $identity->applicationIdentity()['edition'] ?? null;
            $files[] = 'server/.peanut/release-identity.json';
        } elseif (ApplicationSourceIdentity::hasRuntime($this->serverRoot)
            || file_exists($root . '/.peanut/application-manifest.json') || is_link($root . '/.peanut/application-manifest.json')) {
            $identity = ApplicationSourceIdentity::load($this->serverRoot);
            $kind = ApplicationSourceIdentity::hasRuntime($this->serverRoot) ? 'development-runtime' : 'generated-application';
            $mode = $identity->applicationIdentity()['edition'];
            $files[] = $kind === 'development-runtime' ? 'server/.peanut/development-runtime.json' : '.peanut/application-manifest.json';
            $files[] = $kind === 'development-runtime' ? 'server/.peanut/release-versions.json' : 'release-versions.json';
        } elseif (\installationSourceDevelopmentMode($this->serverRoot)
            && getenv('APP_ENV') === 'development' && filter_var(getenv('APP_DEBUG'), FILTER_VALIDATE_BOOLEAN)) {
            $kind = 'product-source';
            $mode = getenv('DEPLOYMENT_MODE');
            // Reuse the actual Edition profile parser; never infer an Edition
            // simply from a missing server release identity.
            \installationTenantBootstrapContract($this->serverRoot);
            $files = [...$files, 'release-versions.json', 'scaffold/application-template-inventory.json', 'scaffold/edition-profiles.json'];
        } else {
            throw new RuntimeException('APPLICATION_SCHEMA_TARGET_IDENTITY_UNAVAILABLE');
        }
        if ($mode !== ($installation['receipt']['deployment_mode'] ?? null)) {
            throw new RuntimeException('APPLICATION_SCHEMA_EDITION_MISMATCH');
        }
        $fingerprints = [];
        foreach ($files as $file) {
            $fingerprints[$file] = $this->fileDigest($root . '/' . $file);
        }
        $installed = json_decode((string) file_get_contents($this->serverRoot . '/vendor/composer/installed.json'), true, 512, JSON_THROW_ON_ERROR);
        $core = array_values(array_filter($installed['packages'] ?? [], static fn(array $row): bool => ($row['name'] ?? null) === 'peanut-admin/core'));
        if (count($core) !== 1) {
            throw new RuntimeException('APPLICATION_SCHEMA_CORE_IDENTITY_INVALID');
        }
        $corePath = realpath($this->serverRoot . '/vendor/composer/' . ($core[0]['install-path'] ?? ''));
        if (!is_string($corePath) || !is_dir($corePath) || is_link($this->serverRoot . '/vendor/peanut-admin/core')) {
            throw new RuntimeException('APPLICATION_SCHEMA_CORE_IDENTITY_INVALID');
        }
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($corePath, \FilesystemIterator::SKIP_DOTS));
        $coreFiles = [];
        foreach ($iterator as $file) {
            if ($file->isLink()) {
                throw new RuntimeException('APPLICATION_SCHEMA_CORE_IDENTITY_INVALID');
            }
            if ($file->isFile()) {
                $coreFiles[substr($file->getPathname(), strlen($corePath) + 1)] = hash_file('sha256', $file->getPathname());
            }
        }
        ksort($coreFiles, SORT_STRING);
        ksort($fingerprints, SORT_STRING);
        return [
            'kind' => $kind, 'deployment_mode' => $mode,
            'release' => \applicationReleaseVersions($this->serverRoot),
            'commit' => $kind === 'product-source' ? $this->git($root, ['rev-parse', 'HEAD^{commit}']) : null,
            'tree' => $kind === 'product-source' ? $this->git($root, ['rev-parse', 'HEAD^{tree}']) : null,
            'files' => $fingerprints,
            'core' => ['name' => $core[0]['name'], 'version' => $core[0]['version'], 'source' => $core[0]['source'] ?? null, 'files_sha256' => $this->digest($coreFiles)],
            'qualification' => 'not_claimed',
            'php' => ['version' => PHP_VERSION, 'binary_sha256' => $this->fileDigest(PHP_BINARY)],
        ];
    }

    /** Metadata and counts are observations, never a generic SQL safety proof. */
    private function prestate(PDO $pdo, string $id, string $sql, array $precedingPending): array
    {
        preg_match_all('/\bpa_[a-z0-9_]+\b/', $sql, $matches);
        preg_match_all('/CREATE\s+TEMPORARY\s+TABLE\s+`?(pa_[a-z0-9_]+)`?/i', $sql, $temporary);
        $tables = array_values(array_diff(array_unique($matches[0]), $temporary[1]));
        sort($tables, SORT_STRING);
        $facts = [];
        foreach ($tables as $table) {
            $query = $pdo->prepare('SELECT TABLE_TYPE,ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?');
            $query->execute([$table]);
            $shape = $query->fetch(PDO::FETCH_ASSOC) ?: null;
            if ($shape !== null && $shape['TABLE_TYPE'] === 'BASE TABLE') {
                $ddl = $pdo->query('SHOW CREATE TABLE `' . $table . '`')->fetch(PDO::FETCH_NUM);
                $facts[$table] = ['exists' => true, 'engine' => $shape['ENGINE'], 'ddl_sha256' => hash('sha256', (string) $ddl[1]), 'rows' => (string) $pdo->query('SELECT COUNT(*) FROM `' . $table . '`')->fetchColumn()];
                $columns = $pdo->prepare('SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? ORDER BY ORDINAL_POSITION');
                $columns->execute([$table]);
                $facts[$table]['columns'] = $columns->fetchAll(PDO::FETCH_COLUMN);
            } else {
                $facts[$table] = ['exists' => false, 'observed_type' => $shape['TABLE_TYPE'] ?? null];
            }
        }
        // These are dependencies of the immutable application SQL, not a second
        // migration executor. No successor SQL or DDL runs to fill an observation.
        $dependencies = match ($id) {
            '20261004-menu-stable-identities' => ['20261004-group-default-navigation'],
            '20261012-align-department-canonical-permissions',
            '20261012-repair-seeded-permission-parents' => [
                '20261004-group-default-navigation', '20261004-menu-stable-identities',
            ],
            default => [],
        };
        $unappliedDependencies = array_values(array_intersect($dependencies, $precedingPending));
        $status = $unappliedDependencies === [] ? 'unknown' : 'deferred';
        $next = $unappliedDependencies === []
            ? 'Prove the complete SQL prestate and DML predicates from native source-bound facts; do not replay on missing ledger alone.'
            : 'After the named predecessors are legitimately admitted and applied, re-observe the dependent predicates before admitting this successor; no pre-emptive DDL.';
        if ($id === '20261004-group-default-navigation') {
            $next = 'Prove original menu selectors and preservation rules; regroup predicates use columns created earlier inside this SQL and need a source-derived proof before admission.';
        }
        $additions = match ($id) {
            '20260909-notification-sms-reservation' => ['pa_notice_log' => ['reservation_key', 'idempotency_key_hash', 'request_digest', 'receiver_hash', 'reservation_until', 'reservation_active']],
            '20261004-group-default-navigation' => [
                'pa_menu_definition' => ['upstream_defaults_json', 'menu_conflict_json'],
                'pa_system_menu' => ['upstream_defaults_json', 'menu_conflict_json'],
            ],
            '20261004-menu-stable-identities' => [
                'pa_system_menu' => ['menu_key', 'parent_key', 'module_key'],
                'pa_menu_definition' => ['is_show', 'is_cache', 'is_disable'],
            ],
            default => [],
        };
        $structural = [];
        foreach ($additions as $table => $columns) {
            $present = array_values(array_intersect($columns, $facts[$table]['columns'] ?? []));
            $structural[$table] = [
                'expected_additions' => $columns, 'already_present' => $present,
                'classification' => ($facts[$table]['exists'] ?? false) !== true ? 'missing_base_table'
                    : ($present === [] ? 'before_additions' : (count($present) === count($columns) ? 'existing_post_shape_unproven' : 'partial_shape')),
            ];
            if ($present !== [] || ($facts[$table]['exists'] ?? false) !== true) {
                $status = 'collision';
                $next = 'Resolve the existing or partial shape through its native repair/adoption contract; do not replay or fill a ledger row.';
            }
        }
        return [
            'status' => $status, 'tables' => $facts,
            'structural_observations' => $structural,
            'dependencies' => $dependencies, 'unapplied_dependencies' => $unappliedDependencies,
            'next_step' => $next, 'complete_preconditions_proven' => null,
        ];
    }

    private function lease(): array
    {
        $path = \requiredEnvironment('PEANUT_RESOURCE_LEASE_PROOF');
        $metadata = \activeLeaseMetadata($path, time(), null);
        $resources = \activeLeaseResources($path);
        $ddl = false;
        foreach ($resources['mysql-access'] ?? [] as $value) {
            $access = json_decode($value, true, 64, JSON_THROW_ON_ERROR);
            $ddl = $ddl || (($access['complete'] ?? null) === true
                && in_array($access['operation'] ?? null, ['ddl', 'exclusive'], true)
                && ($access['tables'] ?? null) === []);
        }
        return ['lease' => $metadata['lease'], 'owner' => $metadata['owner'], 'thread' => $metadata['thread'], 'candidate' => $metadata['candidate'], 'ddl' => $ddl];
    }

    private function protectedJson(string $path): array
    {
        if (!str_starts_with($path, '/') || !is_file($path) || is_link($path)
            || (fileperms($path) & 0777) !== 0600 || filesize($path) > 4194304) {
            throw new RuntimeException('APPLICATION_SCHEMA_DOCUMENT_UNSAFE');
        }
        $value = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($value)) {
            throw new RuntimeException('APPLICATION_SCHEMA_DOCUMENT_INVALID');
        }
        return $value;
    }

    private function assertDocument(array $value, string $protocol, string $hashKey): void
    {
        $digest = $value[$hashKey] ?? null;
        unset($value[$hashKey]);
        if (($value['schema_version'] ?? null) !== 1 || ($value['protocol'] ?? null) !== $protocol
            || ($value['scope'] ?? null) !== 'application-schema'
            || ($value['plugins'] ?? null) !== 'not_run' || ($value['product_database'] ?? null) !== 'not_verified'
            || !is_string($digest) || !hash_equals($digest, $this->digest($value))) {
            throw new RuntimeException('APPLICATION_SCHEMA_DOCUMENT_INVALID');
        }
    }

    private function saveResult(array $record): string
    {
        $directory = $this->serverRoot . '/private/application-schema';
        if ((!is_dir($directory) && !mkdir($directory, 0700)) || is_link($directory)
            || (fileperms($directory) & 0777) !== 0700) {
            throw new RuntimeException('APPLICATION_SCHEMA_RESULT_DIRECTORY_UNSAFE');
        }
        $path = $directory . '/result-' . bin2hex(random_bytes(16)) . '.json';
        $stream = fopen($path, 'x');
        $bytes = $this->canonical($record) . "\n";
        if ($stream === false || !chmod($path, 0600) || fwrite($stream, $bytes) !== strlen($bytes)
            || !fflush($stream) || !fsync($stream)) {
            throw new RuntimeException('APPLICATION_SCHEMA_RESULT_WRITE_FAILED');
        }
        fclose($stream);
        $signaturePath = $path . '.hmac';
        $signature = hash_hmac('sha256', $bytes, $this->resultKey()) . "\n";
        $stream = fopen($signaturePath, 'x');
        if ($stream === false || !chmod($signaturePath, 0600)
            || fwrite($stream, $signature) !== strlen($signature) || !fflush($stream) || !fsync($stream)) {
            throw new RuntimeException('APPLICATION_SCHEMA_RESULT_WRITE_FAILED');
        }
        fclose($stream);
        return $path;
    }

    /** Reuse the native update result key; never generate a new signing authority. */
    private function resultKey(): string
    {
        $legacy = $this->serverRoot . '/private/installation/update-verification.key';
        $parentPath = $this->serverRoot . '/private';
        $directory = $parentPath . '/maintenance';
        $owner = fileowner($this->serverRoot);
        $parent = is_dir($parentPath) && !is_link($parentPath) ? lstat($parentPath) : false;
        $stat = is_dir($directory) && !is_link($directory) ? lstat($directory) : false;
        // Match the existing native maintenance reader without its create path.
        // A legacy key requires its original owner; never revive or relocate it.
        if (file_exists($legacy) || is_link($legacy)
            || !is_int($owner) || !function_exists('posix_geteuid')
            || !in_array(posix_geteuid(), [0, $owner], true)
            || !is_array($parent) || !in_array($parent['uid'], [0, $owner], true)
            || ($parent['mode'] & 0022) !== 0
            || !is_array($stat) || !in_array($stat['uid'], [0, $owner], true)
            || ($stat['mode'] & 0777) !== 0700) {
            throw new RuntimeException('APPLICATION_SCHEMA_NATIVE_RESULT_KEY_UNAVAILABLE');
        }
        $path = $directory . '/update-verification.key';
        $keyStat = is_file($path) && !is_link($path) ? lstat($path) : false;
        if (!is_array($keyStat) || $keyStat['nlink'] !== 1 || ($keyStat['mode'] & 0777) !== 0600) {
            throw new RuntimeException('APPLICATION_SCHEMA_NATIVE_RESULT_KEY_UNAVAILABLE');
        }
        $key = trim((string) file_get_contents($path));
        if (preg_match('/\A[a-f0-9]{64}\z/D', $key) !== 1) {
            throw new RuntimeException('APPLICATION_SCHEMA_NATIVE_RESULT_KEY_UNAVAILABLE');
        }
        return $key;
    }

    private function fileDigest(string $path): string
    {
        if (!is_file($path) || is_link($path)) {
            throw new RuntimeException('APPLICATION_SCHEMA_SOURCE_FILE_INVALID');
        }
        return (string) hash_file('sha256', $path);
    }

    private function git(string $root, array $arguments): string
    {
        return \leaseGit(['-C', $root, ...$arguments]);
    }

    private static function digest(array $value): string
    {
        return hash('sha256', self::canonical($value));
    }

    private static function canonical(array $value): string
    {
        $normalize = static function (array $items) use (&$normalize): array {
            if (!array_is_list($items)) {
                ksort($items, SORT_STRING);
            }
            foreach ($items as $key => $item) {
                if (is_array($item)) {
                    $items[$key] = $normalize($item);
                }
            }
            return $items;
        };
        return json_encode($normalize($value), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
}
