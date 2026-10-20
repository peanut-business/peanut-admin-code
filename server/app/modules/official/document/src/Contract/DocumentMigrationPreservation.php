<?php

declare(strict_types=1);

namespace PeanutAdmin\Modules\Document\Contract;

use app\common\execution\CurrentExecutionContext;
use app\common\execution\InstallationExecutionContext;
use app\common\execution\InstanceExecutionContext;
use PDO;
use RuntimeException;
use think\facade\Db;

/** Document DDL observations. The native caller owns source admission, writer exclusion, execution and recovery. */
final readonly class DocumentMigrationPreservation
{
    private const PROTOCOL = 'peanut.document-migration-preservation.v1';
    private const BASE = 'official.document:20261012050000_create_business_documents';
    private const BARRIERS = 'official.document:20261013120000_add_document_business_barriers';
    private const SQL_HASHES = [
        self::BASE => '103003e0a0cbb8ec11ed2d1f039772500d7c610ad9b66bc19623fb012fa31f00',
        self::BARRIERS => 'e015617440e433d0d443f7c4c13b2bf02e35b4403e25c1009747d5d1d707f579',
    ];
    private const COLUMNS = [
        'document' => 'id,tenant_id,owner_module_key,owner_object_type,owner_object_id,slot,content_kind,body_type,schema_version,status,auth_revision,state_seq,collaboration_enabled,lease_epoch,created_by_member_id,updated_by_member_id,create_time,update_time,archived_at',
        'document_state' => 'id,tenant_id,document_id,yjs_state_binary,state_seq,state_sha256,projected_json,projection_schema_version,persisted_at,last_store_id',
        'document_revision' => 'id,tenant_id,document_id,revision_no,source_state_seq,content_kind,yjs_snapshot,content_snapshot_json,immutable_file_key,immutable_file_sha256,immutable_file_mime_type,created_at,created_by_member_id,reason,snapshot_sha256',
        'document_file_ref' => 'id,tenant_id,document_id,file_key,variant_key,usage,derived_state_seq,updated_at',
        'document_resource_binding' => 'id,tenant_id,document_id,primary_file_key,verified_mime_type,source_sha256,source_file_revision,state_seq,bound_at',
        'document_operation' => 'id,tenant_id,document_id,operation_id,action,input_sha256,result_json,created_at',
    ];
    private const ADDED = ['phase', 'initiator_json', 'command_input_json', 'barrier_plan_json', 'business_sha256', 'released_at'];
    private const PHASE_CHECK = 'chk_document_operation_phase';

    public function __construct(private CurrentExecutionContext $contexts) {}

    /** Only safe_summary may enter plans/logs. Retain private_state in the native completion callback. */
    public function before(PDO $pdo, string $canonicalMigrationKey): array
    {
        return $this->guard(function () use ($pdo, $canonicalMigrationKey): array {
            $context = $this->context($pdo);
            $sqlHash = $this->sqlHash($canonicalMigrationKey);
            $snapshot = $this->snapshot($pdo);
            $this->prestate($snapshot, $canonicalMigrationKey);
            if ($this->context($pdo) !== $context) { self::fail('CONTEXT_CHANGED'); }
            $hash = self::hash($snapshot);
            return [
                'safe_summary' => ['module_key' => 'official.document', 'migration_key' => $canonicalMigrationKey,
                    'sql_sha256' => $sqlHash, 'readonly' => true, 'preservation_verified' => false,
                    'before_sha256' => $hash, 'proof_kind' => 'fixed_native_additive_ddl'],
                'private_state' => ['protocol' => self::PROTOCOL, 'pdo' => $pdo, 'context' => $context,
                    'migration_key' => $canonicalMigrationKey, 'sql_sha256' => $sqlHash,
                    'transaction_active' => false, 'snapshot' => $snapshot, 'before_sha256' => $hash],
            ];
        });
    }

    /** Call only after the entire fixed SQL succeeds on this PDO, before the native ledger becomes applied. */
    public function after(PDO $pdo, array $privateState): array
    {
        return $this->guard(function () use ($pdo, $privateState): array {
            $keys = ['protocol', 'pdo', 'context', 'migration_key', 'sql_sha256', 'transaction_active', 'snapshot', 'before_sha256'];
            if (count($privateState) !== count($keys) || array_diff(array_keys($privateState), $keys) !== []
                || ($privateState['protocol'] ?? null) !== self::PROTOCOL || ($privateState['pdo'] ?? null) !== $pdo
                || ($privateState['context'] ?? null) !== $this->context($pdo)
                || ($privateState['transaction_active'] ?? null) !== false
                || !is_string($privateState['migration_key'] ?? null) || !is_array($privateState['snapshot'] ?? null)
                || !is_string($privateState['before_sha256'] ?? null)
                || !hash_equals($privateState['before_sha256'], self::hash($privateState['snapshot']))) {
                self::fail('BEFORE_BINDING_INVALID');
            }
            $key = $privateState['migration_key'];
            $sqlHash = $this->sqlHash($key);
            if ($privateState['sql_sha256'] !== $sqlHash) { self::fail('SOURCE_CHANGED'); }
            $old = $privateState['snapshot'];
            $this->prestate($old, $key);
            $new = $this->snapshot($pdo);
            if ($old['identity'] !== $new['identity']) { self::fail('DATABASE_CHANGED'); }
            if ($key === self::BASE) {
                foreach (self::COLUMNS as $name => $columns) {
                    $table = $new['tables'][$name];
                    $this->baseline($table, $columns);
                    if ($table['triggers'] !== [] || $table['auto_increment'] !== '1'
                        || $this->query($pdo, 'SELECT 1 FROM ' . self::q('pa_' . $name) . ' LIMIT 1') !== []) {
                        self::fail('NEW_TABLE_NOT_EMPTY_OR_UNKNOWN');
                    }
                }
            } else {
                foreach ($old['tables'] as $name => $table) {
                    $current = $new['tables'][$name];
                    if ($current === null) { self::fail('TABLE_DISAPPEARED'); }
                    if ($name === 'document_operation') { $current = $this->withoutBarriers($current); }
                    if ($current !== $table) { self::fail('ORIGINAL_SCHEMA_OR_COUNTER_CHANGED'); }
                }
            }
            if ($this->context($pdo) !== $privateState['context']) { self::fail('CONTEXT_CHANGED'); }
            return ['module_key' => 'official.document', 'migration_key' => $key, 'sql_sha256' => $sqlHash,
                'readonly' => true, 'preservation_verified' => true, 'before_sha256' => $privateState['before_sha256'],
                'after_sha256' => self::hash($new), 'proof_kind' => 'fixed_native_additive_ddl',
                'delta_kind' => $key === self::BASE ? 'six_empty_document_tables' : 'operation_progress_columns',
                'table_count' => count(self::COLUMNS)];
        });
    }

    private function prestate(array $snapshot, string $key): void
    {
        foreach (self::COLUMNS as $name => $columns) {
            $table = $snapshot['tables'][$name];
            if ($key === self::BASE) {
                if ($table !== null) { self::fail('BASE_REQUIRES_ABSENT_TABLES'); }
            } else {
                $this->baseline($table, $columns);
            }
        }
        if ($snapshot['phase_constraint'] !== []) { self::fail('PHASE_CONSTRAINT_ALREADY_PRESENT'); }
    }

    private function baseline(?array $table, string $columns): void
    {
        if ($table === null || implode(',', array_column($table['columns'], 'COLUMN_NAME')) !== $columns
            || $table['primary'] !== ['id']) { self::fail('BASE_SHAPE_UNKNOWN'); }
    }

    /** Remove exactly the declared ADD delta; compare all remaining DDL/columns/triggers/counter byte for byte. */
    private function withoutBarriers(array $table): array
    {
        $columns = $table['columns'];
        $added = array_splice($columns, -count(self::ADDED));
        if (array_column($added, 'COLUMN_NAME') !== self::ADDED) { self::fail('BARRIER_COLUMNS_UNKNOWN'); }
        foreach ($added as $i => $column) {
            $expected = match ($column['COLUMN_NAME']) {
                'phase' => ['varchar(16)', 'NO', 'completed', 'utf8mb4', 'utf8mb4_unicode_ci'],
                'business_sha256' => ['char(64)', 'YES', null, 'ascii', 'ascii_bin'],
                'released_at' => ['datetime(3)', 'YES', null, null, null],
                default => ['json', 'YES', null, null, null],
            };
            if ([$column['COLUMN_TYPE'], $column['IS_NULLABLE'], $column['COLUMN_DEFAULT'],
                $column['CHARACTER_SET_NAME'], $column['COLLATION_NAME']] !== $expected
                || $column['EXTRA'] !== '' || $column['GENERATION_EXPRESSION'] !== '') {
                self::fail('BARRIER_COLUMN_SEMANTICS_UNKNOWN');
            }
        }
        $ddl = $table['ddl'];
        foreach (self::ADDED as $name) {
            $ddl = preg_replace('/^  `' . $name . '` [^\r\n]+,\r?\n/m', '', $ddl, -1, $removed);
            if ($removed !== 1 || !is_string($ddl)) { self::fail('BARRIER_DDL_UNKNOWN'); }
        }
        if (preg_match('/^  CONSTRAINT `chk_document_operation_phase` CHECK \((.+)\)(,?)$/m', $ddl, $match) !== 1) {
            self::fail('BARRIER_CHECK_UNKNOWN');
        }
        $expression = preg_replace('/[\s`()]+|_utf8mb4/', '', $match[1]);
        if ($expression !== "phasein'prepared','attention','completed'") { self::fail('BARRIER_CHECK_UNKNOWN'); }
        $ddl = preg_replace('/^  CONSTRAINT `chk_document_operation_phase` CHECK [^\r\n]+\r?\n/m', '', $ddl, -1, $removed);
        if ($removed !== 1 || !is_string($ddl)) { self::fail('BARRIER_CHECK_UNKNOWN'); }
        $table['ddl'] = preg_replace('/,\r?\n\) ENGINE=/', "\n) ENGINE=", $ddl);
        $table['columns'] = $columns;
        return $table;
    }

    /** Metadata is protected by the caller's held DDL/writer exclusion, not by an MVCC claim. */
    private function snapshot(PDO $pdo): array
    {
        $identity = $this->identity($pdo);
        $this->visibility($pdo, $identity['database_name']);
        $tables = [];
        foreach (self::COLUMNS as $name => $_) {
            $physical = Db::name($name)->getTable();
            if ($physical !== 'pa_' . $name) { self::fail('EXECUTE_PREFIX_UNSUPPORTED'); }
            $tables[$name] = $this->metadata($pdo, $physical);
        }
        $phase = $this->query($pdo,
            'SELECT TABLE_NAME,CONSTRAINT_NAME FROM information_schema.TABLE_CONSTRAINTS'
            . ' WHERE CONSTRAINT_SCHEMA=DATABASE() AND CONSTRAINT_NAME=? ORDER BY TABLE_NAME', [self::PHASE_CHECK]);
        if ($identity !== $this->identity($pdo)) { self::fail('DATABASE_CHANGED'); }
        return ['identity' => $identity, 'tables' => $tables, 'phase_constraint' => $phase];
    }

    private function metadata(PDO $pdo, string $table): ?array
    {
        $info = $this->query($pdo,
            'SELECT ENGINE,TABLE_TYPE,TABLE_COLLATION,AUTO_INCREMENT FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?', [$table]);
        if ($info === []) { return null; }
        if (count($info) !== 1 || $info[0]['ENGINE'] !== 'InnoDB' || $info[0]['TABLE_TYPE'] !== 'BASE TABLE'
            || $info[0]['TABLE_COLLATION'] !== 'utf8mb4_unicode_ci') { self::fail('TABLE_SHAPE_UNKNOWN'); }
        $ddl = $this->query($pdo, 'SHOW CREATE TABLE ' . self::q($table));
        if (count($ddl) !== 1 || !is_string($ddl[0]['Create Table'] ?? null)) { self::fail('DDL_UNAVAILABLE'); }
        $columns = $this->query($pdo,
            'SELECT COLUMN_NAME,COLUMN_TYPE,IS_NULLABLE,COLUMN_DEFAULT,EXTRA,GENERATION_EXPRESSION,CHARACTER_SET_NAME,COLLATION_NAME'
            . ' FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? ORDER BY ORDINAL_POSITION', [$table]);
        $primary = array_column($this->query($pdo,
            "SELECT COLUMN_NAME FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND INDEX_NAME='PRIMARY' ORDER BY SEQ_IN_INDEX", [$table]), 'COLUMN_NAME');
        $triggers = $this->query($pdo,
            'SELECT TRIGGER_NAME,EVENT_MANIPULATION,ACTION_ORDER,ACTION_CONDITION,ACTION_STATEMENT,ACTION_ORIENTATION,ACTION_TIMING,'
            . 'SQL_MODE,DEFINER,CHARACTER_SET_CLIENT,COLLATION_CONNECTION,DATABASE_COLLATION,CREATED'
            . ' FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA=DATABASE() AND EVENT_OBJECT_TABLE=? ORDER BY TRIGGER_NAME', [$table]);
        return ['ddl' => $ddl[0]['Create Table'], 'columns' => $columns, 'primary' => $primary, 'triggers' => $triggers,
            'auto_increment' => $info[0]['AUTO_INCREMENT'] === null ? null : (string) $info[0]['AUTO_INCREMENT']];
    }

    private function identity(PDO $pdo): array
    {
        $this->context($pdo);
        $rows = $this->query($pdo,
            'SELECT DATABASE() AS database_name,@@server_uuid AS server_uuid,CONNECTION_ID() AS connection_id,'
            . '@@session.sql_mode AS sql_mode,@@session.time_zone AS time_zone');
        if (count($rows) !== 1 || !is_string($rows[0]['database_name']) || $rows[0]['database_name'] === '') {
            self::fail('DATABASE_UNKNOWN');
        }
        return $rows[0];
    }

    private function visibility(PDO $pdo, string $database): void
    {
        $mode = $this->query($pdo, 'SELECT @@GLOBAL.partial_revokes AS partial_revokes')[0]['partial_revokes'] ?? null;
        if (!in_array($mode, [0, 1, '0', '1'], true)) { self::fail('METADATA_VISIBILITY_UNKNOWN'); }
        $scope = self::q($database) . '.*';
        $scopes = ['*.*', $scope];
        // With partial revokes disabled, SHOW GRANTS escapes literal underscores in database patterns.
        if ((string) $mode === '0') { $scopes[] = str_replace('_', '\\_', $scope); }
        $select = false; $trigger = false;
        foreach ($this->query($pdo, 'SHOW GRANTS') as $row) {
            $grant = count($row) === 1 ? reset($row) : null;
            if (!is_string($grant) || str_starts_with($grant, 'REVOKE ')) { self::fail('METADATA_VISIBILITY_UNKNOWN'); }
            if (!preg_match('/\AGRANT (.+) ON (.+) TO /D', $grant, $match)
                || !in_array($match[2], $scopes, true)) { continue; }
            $privileges = array_map('trim', explode(',', $match[1]));
            $select = $select || in_array('SELECT', $privileges, true) || in_array('ALL PRIVILEGES', $privileges, true);
            $trigger = $trigger || in_array('TRIGGER', $privileges, true) || in_array('ALL PRIVILEGES', $privileges, true);
        }
        if (!$select || !$trigger) { self::fail('METADATA_VISIBILITY_UNKNOWN'); }
    }

    private function context(PDO $pdo): InstanceExecutionContext|InstallationExecutionContext
    {
        $context = $this->contexts->current();
        if (!($context instanceof InstanceExecutionContext || $context instanceof InstallationExecutionContext)
            || Db::connect()->getPdo() !== $pdo || $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'mysql'
            || $pdo->inTransaction()) { self::fail('NATIVE_DDL_CONTEXT_REQUIRED'); }
        return $context;
    }

    private function sqlHash(string $key): string
    {
        if (!isset(self::SQL_HASHES[$key])) { self::fail('MIGRATION_UNKNOWN'); }
        $path = dirname(__DIR__, 2) . '/database/migrations/' . substr($key, strlen('official.document:')) . '.sql';
        if (!is_file($path) || hash_file('sha256', $path) !== self::SQL_HASHES[$key]) { self::fail('SOURCE_CHANGED'); }
        return self::SQL_HASHES[$key];
    }

    /** Bounded schema metadata and SELECT 1 LIMIT 1 only; never materialize historical business rows. */
    private function query(PDO $pdo, string $sql, array $bindings = []): array
    {
        $statement = $pdo->prepare($sql);
        $statement->execute($bindings);
        try { return $statement->fetchAll(PDO::FETCH_ASSOC); }
        finally { $statement->closeCursor(); }
    }

    private function guard(callable $operation): array
    {
        try { return $operation(); }
        catch (\Throwable $error) {
            $code = $error instanceof RuntimeException && preg_match('/\ADOCUMENT_PRESERVATION_[A-Z_]+\z/D', $error->getMessage()) === 1
                ? $error->getMessage() : 'DOCUMENT_PRESERVATION_OBSERVATION_FAILED';
            throw new RuntimeException($code);
        }
    }

    private static function q(string $name): string
    {
        if (preg_match('/\A[a-zA-Z0-9_]+\z/D', $name) !== 1) { self::fail('IDENTIFIER_UNKNOWN'); }
        return '`' . $name . '`';
    }

    private static function hash(array $value): string
    {
        return hash('sha256', json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    private static function fail(string $code): never { throw new RuntimeException('DOCUMENT_PRESERVATION_' . $code); }
}
