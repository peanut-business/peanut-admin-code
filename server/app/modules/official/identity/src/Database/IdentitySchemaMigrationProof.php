<?php

declare(strict_types=1);

namespace PeanutAdmin\Modules\Identity\Database;

use Closure;
use PDO;
use RuntimeException;
use Throwable;

/** Identity's fixed DDL contract on the lifecycle's held master; never executes migration SQL or repairs history. */
final class IdentitySchemaMigrationProof
{
    private const STAGES = [
        'official.identity:20260716020101_create_pa_data_permission_policy' => ['9ccbb19f70c230b27f97d1eeac818dfd08197406664cdf85ddd63a25cb51750e', 'pa_data_permission_policy', 'create'],
        'official.identity:20260716020102_create_pa_data_permission_group' => ['bac2408f49ffcfa3470e854b7550b5197d9cc9cf4c96ac74dd732572b9f91cbd', 'pa_data_permission_group', 'create'],
        'official.identity:20260716020103_create_pa_data_permission_target_set' => ['d34eecc5886ee842b78e44556a8029487668a22b32190c1d275137dd9e3c2708', 'pa_data_permission_target_set', 'create'],
        'official.identity:20260716020104_create_pa_data_permission_condition' => ['52b4836e3be7e87a6ccc5f5eab410778f947fa892f0708b2fd3f332989f54530', 'pa_data_permission_condition', 'create'],
        'official.identity:20260716020105_create_pa_data_permission_target' => ['baa95b025a3bdae3ee9e56f40018443f1927959f41c71828fd40bca96bd9078e', 'pa_data_permission_target', 'create'],
        'official.identity:20260921020000_create_pa_source_read_grant' => ['e59e69ed167d4f565dd7e9b60284f69b021e93a9826fdf1f9b9a35a7bf6e7c0c', 'pa_source_read_grant', 'create'],
        'official.identity:20261007010000_department_contact_fields' => ['47626b595811ced52d25b9eb7438fc4ba9cb921a76272e47c073c2faae11b9ee', 'pa_department', 'contacts'],
        'official.identity:20261012010000_department_leader_member' => ['909c7bd6885a93cf2975ecbbb108cbbe993bc1e0b47f2590292968a886051532', 'pa_department', 'leader_member'],
        'official.identity:20261015010000_index_revocation_audit_facts' => ['eacd6eddd89a9f064688ff45ba67210d896a65bbeaea1448394e7b6df3c271aa', 'pa_tenant_audit_event', 'audit'],
    ];

    public static function before(PDO $pdo, array $exactMigration): array
    {
        return self::snapshot($pdo, static function () use ($pdo, $exactMigration): array {
            $key = $exactMigration['migration_key'] ?? null;
            if (($exactMigration['module_key'] ?? null) !== 'official.identity' || !isset(self::STAGES[$key])
                || ($exactMigration['checksum'] ?? null) !== self::STAGES[$key][0]
                || !($exactMigration['stageLedger'] ?? null) instanceof Closure) { self::fail('IDENTITY_INVALID'); }
            self::source($key);
            $ledger = self::ledger($pdo, $exactMigration);
            [, $table, $kind] = self::STAGES[$key];
            $before = self::table($pdo, $table);
            if ($kind === 'create') {
                // These six immutable statements are bare CREATE, not IF NOT EXISTS or adoption.
                if ($before !== null) { self::fail('CREATE_TARGET_EXISTS'); }
            } else {
                if ($before === null) { self::fail('ALTER_TARGET_MISSING'); }
                self::expectedAlter($before, $kind);
            }
            $rows = $before === null ? null : self::rowImage($pdo, $table, $before);
            if ($ledger !== self::ledger($pdo, $exactMigration)) { self::fail('LEDGER_CHANGED'); }
            return ['module_key' => 'official.identity', 'migration_key' => $key, 'checksum' => self::STAGES[$key][0],
                'pdo_object_id' => spl_object_id($pdo), 'connection' => self::connection($pdo),
                'stageLedger' => $exactMigration['stageLedger'], 'ledger' => $ledger, 'table' => $before, 'original_rows' => $rows,
                'safe_summary' => ['migration_key' => $key, 'checksum' => self::STAGES[$key][0],
                    'metadata_sha256' => hash('sha256', serialize($before)), 'ledger_sha256' => hash('sha256', serialize($ledger)),
                    'data_proof' => 'fixed native CREATE or additive columns/indexes; original-column streaming digest for existing parents'], 'readonly' => true];
        });
    }

    public static function after(PDO $pdo, array $before): array
    {
        return self::snapshot($pdo, static function () use ($pdo, $before): array {
            $key = $before['migration_key'] ?? null;
            if (($before['module_key'] ?? null) !== 'official.identity' || !isset(self::STAGES[$key])
                || ($before['checksum'] ?? null) !== self::STAGES[$key][0]
                || ($before['pdo_object_id'] ?? null) !== spl_object_id($pdo)
                || ($before['connection'] ?? null) !== self::connection($pdo)
                || !array_key_exists('table', $before) || !array_key_exists('original_rows', $before)
                || !is_array($before['ledger'] ?? null)) { self::fail('INPUT_INVALID'); }
            self::source($key);
            if ($before['ledger'] !== self::ledger($pdo, $before)) { self::fail('LEDGER_CHANGED'); }
            [, $table, $kind] = self::STAGES[$key];
            $after = self::table($pdo, $table);
            if ($after === null) { self::fail('TARGET_MISSING'); }
            if ($kind === 'create') {
                if ($before['table'] !== null || (int) self::query($pdo, 'SELECT EXISTS(SELECT 1 FROM `' . $table . '`) AS present')[0]['present'] !== 0) {
                    self::fail('CREATE_POSTSTATE_INVALID');
                }
                // Fixed source, before-absence and actual native execution establish the new shape.
                // Do not simulate CREATE or infer that pre-existing tables executed this history.
            } else {
                self::assertAlter($before['table'], $after, $kind);
                if (($before['original_rows'] ?? null) !== self::rowImage($pdo, $table, $before['table'])) { self::fail('ORIGINAL_ROWS_CHANGED'); }
                foreach (array_diff(array_keys($after['columns']), array_keys($before['table']['columns'])) as $name) {
                    // Only newly introduced fields have a default/NULL postcondition; existing fields are preserved above.
                    $field = '`' . str_replace('`', '``', $name) . '`';
                    $invalid = $after['columns'][$name]['IS_NULLABLE'] === 'YES' ? $field . ' IS NOT NULL' : $field . " IS NULL OR " . $field . "<>''";
                    if ((int) self::query($pdo, 'SELECT EXISTS(SELECT 1 FROM `' . $table . '` WHERE ' . $invalid . ') AS invalid')[0]['invalid'] !== 0) {
                        self::fail('NEW_FIELD_VALUES_INVALID');
                    }
                }
            }
            if ($before['ledger'] !== self::ledger($pdo, $before)) { self::fail('LEDGER_CHANGED'); }
            return ['module_key' => 'official.identity', 'migration_key' => $key, 'checksum' => self::STAGES[$key][0],
                'preservation_verified' => true, 'before_sha256' => hash('sha256', serialize([$before['table'], $before['original_rows']])),
                'after_sha256' => hash('sha256', serialize([$after, $before['original_rows']])), 'metadata_sha256' => hash('sha256', serialize($after)),
                'ledger_sha256' => hash('sha256', serialize($before['ledger'])), 'created_empty_tables' => $kind === 'create' ? 1 : 0,
                'data_proof' => 'fixed native DDL; original-column streaming digest/count retained, new fields checked NULL/default, allocator retained', 'readonly' => true];
        });
    }

    private static function assertAlter(array $before, array $after, string $kind): void
    {
        $expected = self::expectedAlter($before, $kind);
        unset($after['ddl']);
        // INDEX_NAME is a map: MySQL's metadata collation differs from PHP byte ordering.
        // Keep each index's ordered parts intact.
        ksort($after['indexes'], SORT_STRING);
        if ($expected !== $after) { self::fail('ALTER_POSTSTATE_INVALID'); }
    }

    /** Model only the fixed additive metadata delta; never generate or execute SQL. */
    private static function expectedAlter(array $before, string $kind): array
    {
        unset($before['ddl']);
        $columns = &$before['columns']; $indexes = &$before['indexes'];
        if ($kind === 'contacts') {
            foreach (['leader' => [50, 'name'], 'mobile' => [20, 'leader']] as $name => [$length, $after]) {
                $column = self::column($name, 'varchar(' . $length . ')', false, '', $before['charset'], $before['table']['TABLE_COLLATION']);
                if (isset($columns[$name])) { self::assertColumn($columns[$name], $column); }
                else { self::insertColumn($columns, $column, $after); }
            }
        } elseif ($kind === 'leader_member') {
            $column = self::column('leader_member_id', 'bigint unsigned', true, null, null, null);
            $index = self::index('idx_department_leader_member', ['tenant_id', 'leader_member_id'], false);
            if (isset($columns['leader_member_id'])) {
                self::assertColumn($columns['leader_member_id'], $column);
                if (($indexes['idx_department_leader_member'] ?? null) !== $index) { self::fail('LEADER_INDEX_INVALID'); }
            } else {
                if (isset($indexes['idx_department_leader_member'])) { self::fail('LEADER_INDEX_INVALID'); }
                self::insertColumn($columns, $column, 'leader');
                $indexes['idx_department_leader_member'] = $index;
            }
        } elseif ($kind === 'audit') {
            foreach (['revocation_fact_key', 'revocation_fact_sha256'] as $name) {
                if (isset($columns[$name])) { self::fail('AUDIT_TARGET_EXISTS'); }
                self::insertColumn($columns, self::column($name, $name === 'revocation_fact_key' ? 'varchar(64)' : 'char(64)', true, null, 'ascii', 'ascii_bin'), null);
            }
            foreach (['uk_identity_revocation_fact' => [['revocation_fact_key'], true],
                'idx_identity_revocation_scan' => [['tenant_id', 'event_type', 'id'], false]] as $name => [$fields, $unique]) {
                if (isset($indexes[$name])) { self::fail('AUDIT_INDEX_EXISTS'); }
                foreach ($fields as $field) { if (!isset($columns[$field])) { self::fail('AUDIT_COLUMN_MISSING'); } }
                $indexes[$name] = self::index($name, $fields, $unique);
            }
        } else { self::fail('STAGE_UNKNOWN'); }
        ksort($indexes, SORT_STRING);
        unset($columns, $indexes);
        return $before;
    }

    private static function column(string $name, string $type, bool $nullable, ?string $default, ?string $charset, ?string $collation): array
    {
        return ['COLUMN_NAME' => $name, 'ORDINAL_POSITION' => '0', 'COLUMN_TYPE' => $type, 'IS_NULLABLE' => $nullable ? 'YES' : 'NO',
            'COLUMN_DEFAULT' => $default, 'EXTRA' => '', 'CHARACTER_SET_NAME' => $charset, 'COLLATION_NAME' => $collation,
            'COLUMN_COMMENT' => '', 'GENERATION_EXPRESSION' => ''];
    }

    private static function assertColumn(array $actual, array $expected): void
    {
        unset($actual['ORDINAL_POSITION'], $expected['ORDINAL_POSITION']);
        if ($actual !== $expected) { self::fail('COLUMN_PRESTATE_INVALID'); }
    }

    private static function insertColumn(array &$columns, array $column, ?string $after): void
    {
        if ($after !== null && !isset($columns[$after])) { self::fail('COLUMN_PREDECESSOR_MISSING'); }
        $ordered = [];
        foreach ($columns as $key => $original) {
            $ordered[$key] = $original;
            if ($key === $after) { $ordered[$column['COLUMN_NAME']] = $column; }
        }
        if ($after === null) { $ordered[$column['COLUMN_NAME']] = $column; }
        $position = 0;
        foreach ($ordered as &$value) { $value['ORDINAL_POSITION'] = (string) ++$position; }
        unset($value);
        $columns = $ordered;
    }

    private static function index(string $name, array $fields, bool $unique): array
    {
        $parts = [];
        foreach ($fields as $position => $field) {
            $parts[] = ['INDEX_NAME' => $name, 'NON_UNIQUE' => $unique ? '0' : '1', 'SEQ_IN_INDEX' => (string) ($position + 1),
                'COLUMN_NAME' => $field, 'COLLATION' => 'A', 'SUB_PART' => null, 'PACKED' => null, 'INDEX_TYPE' => 'BTREE',
                'COMMENT' => '', 'INDEX_COMMENT' => '', 'IS_VISIBLE' => 'YES', 'EXPRESSION' => null];
        }
        return $parts;
    }

    private static function table(PDO $pdo, string $table): ?array
    {
        $meta = self::query($pdo, 'SELECT TABLE_TYPE,ENGINE,TABLE_COLLATION,AUTO_INCREMENT,CREATE_OPTIONS,TABLE_COMMENT,ROW_FORMAT'
            . ' FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?', [$table]);
        if ($meta === []) { return null; }
        if (count($meta) !== 1 || $meta[0]['TABLE_TYPE'] !== 'BASE TABLE' || $meta[0]['ENGINE'] !== 'InnoDB') { self::fail('TABLE_UNSUPPORTED'); }
        $charset = self::query($pdo, 'SELECT CHARACTER_SET_NAME FROM information_schema.COLLATIONS WHERE COLLATION_NAME=?', [$meta[0]['TABLE_COLLATION']]);
        if (count($charset) !== 1) { self::fail('TABLE_COLLATION_UNKNOWN'); }
        $columns = self::query($pdo, 'SELECT COLUMN_NAME,ORDINAL_POSITION,COLUMN_TYPE,IS_NULLABLE,COLUMN_DEFAULT,EXTRA,CHARACTER_SET_NAME,'
            . 'COLLATION_NAME,COLUMN_COMMENT,GENERATION_EXPRESSION FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? ORDER BY ORDINAL_POSITION', [$table]);
        $indexes = [];
        foreach (self::query($pdo, 'SELECT INDEX_NAME,NON_UNIQUE,SEQ_IN_INDEX,COLUMN_NAME,COLLATION,SUB_PART,PACKED,INDEX_TYPE,COMMENT,INDEX_COMMENT,IS_VISIBLE,EXPRESSION'
            . ' FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? ORDER BY INDEX_NAME,SEQ_IN_INDEX', [$table]) as $part) { $indexes[$part['INDEX_NAME']][] = $part; }
        $checks = self::query($pdo, 'SELECT t.CONSTRAINT_NAME,t.ENFORCED,CAST(c.CHECK_CLAUSE AS BINARY) AS CHECK_CLAUSE FROM information_schema.TABLE_CONSTRAINTS t'
            . ' JOIN information_schema.CHECK_CONSTRAINTS c ON c.CONSTRAINT_SCHEMA=t.CONSTRAINT_SCHEMA AND c.CONSTRAINT_NAME=t.CONSTRAINT_NAME'
            . " WHERE t.TABLE_SCHEMA=DATABASE() AND t.TABLE_NAME=? AND t.CONSTRAINT_TYPE='CHECK' ORDER BY t.CONSTRAINT_NAME", [$table]);
        $foreign = self::query($pdo, 'SELECT k.CONSTRAINT_NAME,k.ORDINAL_POSITION,k.COLUMN_NAME,k.REFERENCED_TABLE_SCHEMA,k.REFERENCED_TABLE_NAME,k.REFERENCED_COLUMN_NAME,r.UPDATE_RULE,r.DELETE_RULE'
            . ' FROM information_schema.KEY_COLUMN_USAGE k JOIN information_schema.REFERENTIAL_CONSTRAINTS r ON r.CONSTRAINT_SCHEMA=k.CONSTRAINT_SCHEMA AND r.TABLE_NAME=k.TABLE_NAME AND r.CONSTRAINT_NAME=k.CONSTRAINT_NAME'
            . ' WHERE k.TABLE_SCHEMA=DATABASE() AND k.TABLE_NAME=? AND k.REFERENCED_TABLE_NAME IS NOT NULL ORDER BY k.CONSTRAINT_NAME,k.ORDINAL_POSITION', [$table]);
        return ['table' => $meta[0], 'charset' => $charset[0]['CHARACTER_SET_NAME'], 'columns' => array_column($columns, null, 'COLUMN_NAME'),
            'indexes' => $indexes, 'checks' => array_column($checks, null, 'CONSTRAINT_NAME'), 'foreign_keys' => $foreign,
            'ddl' => array_values(self::query($pdo, 'SHOW CREATE TABLE `' . $table . '`')[0])[1]];
    }

    private static function ledger(PDO $pdo, array $stage): array
    {
        if (!($stage['stageLedger'] ?? null) instanceof Closure) { self::fail('LEDGER_READER_REQUIRED'); }
        $rows = ($stage['stageLedger'])($pdo); $current = 0;
        if (!is_array($rows)) { self::fail('LEDGER_INVALID'); }
        foreach ($rows as $row) {
            if (!is_array($row) || ($row['module_key'] ?? null) !== 'official.identity') { self::fail('LEDGER_INVALID'); }
            if (($row['migration_key'] ?? null) === $stage['migration_key']) {
                if (($row['status'] ?? null) !== 'applying' || ($row['checksum'] ?? null) !== $stage['checksum']) { self::fail('LEDGER_INVALID'); }
                ++$current;
            } elseif (($row['status'] ?? null) !== 'applied') { self::fail('LEDGER_INVALID'); }
        }
        if ($current !== 1) { self::fail('LEDGER_INVALID'); }
        usort($rows, static fn(array $a, array $b): int => strcmp($a['migration_key'], $b['migration_key']));
        return $rows;
    }

    /** Hash original fields only, including custom fields and binary bytes; never retain or publish rows. */
    private static function rowImage(PDO $pdo, string $table, array $metadata): array
    {
        $primary = $metadata['indexes']['PRIMARY'] ?? [];
        if (count($primary) !== 1 || $primary[0]['COLUMN_NAME'] !== 'id' || $primary[0]['NON_UNIQUE'] !== '0') { self::fail('PARENT_KEY_UNSUPPORTED'); }
        $columns = array_map(static fn(string $name): string => '`' . str_replace('`', '``', $name) . '`', array_keys($metadata['columns']));
        $statement = $pdo->prepare('SELECT ' . implode(',', $columns) . ' FROM `' . $table . '` ORDER BY `id`');
        $hash = hash_init('sha256'); $count = 0;
        try {
            $statement->execute();
            while (($row = $statement->fetch(PDO::FETCH_NUM)) !== false) {
                $bytes = serialize(array_map(static fn($value) => $value === null ? null : (string) $value, $row));
                hash_update($hash, strlen($bytes) . ':' . $bytes); ++$count;
            }
        } finally { $statement->closeCursor(); }
        return ['rows' => $count, 'sha256' => hash_final($hash)];
    }

    private static function source(string $key): void
    {
        $path = dirname(__DIR__, 2) . '/database/migrations/' . substr($key, strlen('official.identity:')) . '.sql';
        if (!is_file($path) || is_link($path) || hash_file('sha256', $path) !== self::STAGES[$key][0]) { self::fail('SOURCE_CHANGED'); }
    }

    private static function connection(PDO $pdo): array
    {
        return self::query($pdo, 'SELECT DATABASE() AS db,@@server_uuid AS server_uuid,CONNECTION_ID() AS connection_id')[0];
    }

    private static function query(PDO $pdo, string $sql, array $parameters = []): array
    {
        $statement = $pdo->prepare($sql);
        try {
            $statement->execute($parameters);
            return array_map(static fn(array $row): array => array_map(static fn($value) => $value === null ? null : (string) $value, $row), $statement->fetchAll(PDO::FETCH_ASSOC));
        } finally { $statement->closeCursor(); }
    }

    private static function snapshot(PDO $pdo, Closure $read): array
    {
        $started = false; $statistics = null;
        try {
            if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'mysql' || $pdo->getAttribute(PDO::ATTR_CASE) !== PDO::CASE_NATURAL || $pdo->inTransaction()) { self::fail('CONNECTION_UNSUPPORTED'); }
            $statement = $pdo->query('SELECT @@SESSION.information_schema_stats_expiry');
            try { $value = $statement->fetchColumn(); } finally { $statement->closeCursor(); }
            if (!is_int($value) && (!is_string($value) || preg_match('/\A[0-9]+\z/D', $value) !== 1)) { self::fail('STATISTICS_UNAVAILABLE'); }
            $statistics = (int) $value;
            $pdo->exec('SET SESSION information_schema_stats_expiry=0');
            $pdo->exec('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ, READ ONLY');
            $pdo->beginTransaction(); $started = true;
            $result = $read();
            if (!$pdo->inTransaction()) { self::fail('SNAPSHOT_CHANGED'); }
            return $result;
        } catch (Throwable $error) {
            if ($error instanceof RuntimeException && str_starts_with($error->getMessage(), 'IDENTITY_SCHEMA_STAGE_')) { throw $error; }
            throw new RuntimeException('IDENTITY_SCHEMA_STAGE_OBSERVATION_FAILED', 0, $error);
        } finally {
            if ($started && $pdo->inTransaction()) { $pdo->rollBack(); }
            if ($statistics !== null) { $pdo->exec('SET SESSION information_schema_stats_expiry=' . $statistics); }
        }
    }

    private static function fail(string $code): never
    {
        throw new RuntimeException('IDENTITY_SCHEMA_STAGE_' . $code);
    }
}
