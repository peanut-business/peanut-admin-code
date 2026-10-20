<?php

declare(strict_types=1);

namespace PeanutAdmin\Modules\ImportExport\Contract;

use app\common\execution\CurrentExecutionContext;
use app\common\execution\InstallationExecutionContext;
use app\common\execution\InstanceExecutionContext;
use app\platform\services\plugin\PluginLifecycleService;
use PDO;
use PDOException;
use RuntimeException;
use Throwable;
use think\facade\Db;

/** Fixed CREATE IF NOT EXISTS observations. The native caller owns execution and writer exclusion. */
final readonly class ImportExportMigrationPreservation
{
    private const KEY = 'official.import-export:20260921-adopt-import-export-schema';
    private const SHA = 'ad77642648ef414871258f1ff2ec61a9586d115651a3c883b7986e58b0d4c8ba';
    private const PROTOCOL = 'peanut.import-export-migration-preservation.v1';
    private const OPERATION = 'pa_import_export_operation';
    private const ERRORS = 'pa_import_export_row_error';
    // type, nullable, default, extra. Every textual source column is explicitly ascii_bin.
    private const COLUMNS = [
        self::OPERATION => [
            'id' => ['bigint unsigned', false, null, 'auto_increment'],
            'operation_key' => ['varchar(36)', false, null, ''],
            'tenant_id' => ['bigint unsigned', false, null, ''],
            'created_by_member_id' => ['bigint unsigned', false, null, ''],
            'provider_key' => ['varchar(96)', false, null, ''],
            'direction' => ['varchar(8)', false, null, ''],
            'format' => ['varchar(8)', false, 'csv', ''],
            'status' => ['varchar(24)', false, 'queued', ''],
            'input_file_key' => ['varchar(64)', true, null, ''],
            'result_file_key' => ['varchar(64)', true, null, ''],
            'error_file_key' => ['varchar(64)', true, null, ''],
            'task_job_key' => ['varchar(64)', true, null, ''],
            'schema_revision' => ['varchar(64)', false, null, ''],
            'mapping_json' => ['json', false, null, ''],
            'processed_rows' => ['int unsigned', false, '0', ''],
            'accepted_rows' => ['int unsigned', false, '0', ''],
            'rejected_rows' => ['int unsigned', false, '0', ''],
            'total_rows' => ['int unsigned', false, '0', ''],
            'attempt_number' => ['smallint unsigned', false, '0', ''],
            'idempotency_key_hash' => ['char(64)', false, null, ''],
            'request_hash' => ['char(64)', false, null, ''],
            'last_error_code' => ['varchar(64)', true, null, ''],
            'revision' => ['bigint unsigned', false, '1', ''],
            'retention_until' => ['datetime(3)', false, null, ''],
            'created_at' => ['datetime(3)', false, null, ''],
            'updated_at' => ['datetime(3)', false, null, ''],
            'completed_at' => ['datetime(3)', true, null, ''],
        ],
        self::ERRORS => [
            'id' => ['bigint unsigned', false, null, 'auto_increment'],
            'tenant_id' => ['bigint unsigned', false, null, ''],
            'operation_id' => ['bigint unsigned', false, null, ''],
            'row_number' => ['int unsigned', false, null, ''],
            'column_key' => ['varchar(64)', true, null, ''],
            'column_key_unique' => ['varchar(64)', true, null, 'STORED GENERATED'],
            'error_code' => ['varchar(64)', false, null, ''],
            'occurred_at' => ['datetime(3)', false, null, ''],
        ],
    ];
    private const INDEXES = [
        self::OPERATION => [
            'PRIMARY' => [true, ['id']], 'uk_import_export_key' => [true, ['operation_key']],
            'uk_import_export_tenant_id' => [true, ['tenant_id', 'id']],
            'uk_import_export_idempotency' => [true, ['tenant_id', 'created_by_member_id', 'direction', 'provider_key', 'idempotency_key_hash']],
            'idx_import_export_status' => [false, ['tenant_id', 'status', 'id']],
            'idx_import_export_task' => [false, ['tenant_id', 'task_job_key']],
            'idx_import_export_retention' => [false, ['status', 'retention_until', 'id']],
        ],
        self::ERRORS => [
            'PRIMARY' => [true, ['id']],
            'uk_import_export_row_error' => [true, ['tenant_id', 'operation_id', 'row_number', 'column_key_unique', 'error_code']],
            'idx_import_export_row_error' => [false, ['tenant_id', 'operation_id', 'row_number', 'id']],
        ],
    ];
    private const FOREIGN = [
        self::OPERATION => [
            'fk_import_export_tenant' => [['tenant_id'], 'tenant', ['id']],
            'fk_import_export_member' => [['tenant_id', 'created_by_member_id'], 'tenant_member', ['tenant_id', 'id']],
        ],
        self::ERRORS => ['fk_import_export_row_operation' => [['tenant_id', 'operation_id'], self::OPERATION, ['tenant_id', 'id']]],
    ];
    private const CHECKS = [
        self::OPERATION => ['key', 'direction', 'format', 'status', 'input', 'files', 'task', 'hashes', 'progress', 'attempt', 'revision', 'completion'],
        self::ERRORS => ['row_number', 'column', 'error_code'],
    ];

    public function __construct(private CurrentExecutionContext $contexts) {}

    public function before(PDO $pdo, string $canonicalMigrationKey, array $nativeParentFacts): array
    {
        return $this->guard(function () use ($pdo, $canonicalMigrationKey, $nativeParentFacts): array {
            $context = $this->context($pdo); $this->source($canonicalMigrationKey);
            $parents = PluginLifecycleService::verifiedMigrationParentFacts($pdo, self::KEY, 'before', $nativeParentFacts);
            $snapshot = $this->snapshot($pdo, $parents);
            foreach ($snapshot['targets'] as $name => $table) { if ($table !== null) { $this->target($snapshot, $name, false); } }
            if ($snapshot['targets'][self::ERRORS] !== null && $snapshot['targets'][self::OPERATION] === null) { self::fail('OPERATION_PARENT_REQUIRED'); }
            if ($this->context($pdo) !== $context) { self::fail('CONTEXT_CHANGED'); }
            $hash = self::hash($snapshot);
            return ['safe_summary' => ['module_key' => 'official.import-export', 'migration_key' => self::KEY, 'sql_sha256' => self::SHA,
                    'readonly' => true, 'preservation_verified' => false, 'before_sha256' => $hash, 'proof_kind' => 'fixed_native_create_if_missing'],
                'private_state' => ['protocol' => self::PROTOCOL, 'pdo' => $pdo, 'context' => $context,
                    'snapshot' => $snapshot, 'before_sha256' => $hash]];
        });
    }

    /** Only after the whole original SQL, before the sole native applied transition. */
    public function after(PDO $pdo, array $privateState, array $nativeParentFacts): array
    {
        return $this->guard(function () use ($pdo, $privateState, $nativeParentFacts): array {
            if (count($privateState) !== 5 || ($privateState['protocol'] ?? null) !== self::PROTOCOL
                || ($privateState['pdo'] ?? null) !== $pdo || ($privateState['context'] ?? null) !== $this->context($pdo)
                || !is_array($privateState['snapshot'] ?? null) || !is_string($privateState['before_sha256'] ?? null)
                || !hash_equals($privateState['before_sha256'], self::hash($privateState['snapshot']))) { self::fail('BEFORE_BINDING_INVALID'); }
            $parents = PluginLifecycleService::verifiedMigrationParentFacts($pdo, self::KEY, 'after', $nativeParentFacts);
            $this->source(self::KEY); $old = $privateState['snapshot']; $new = $this->snapshot($pdo, $parents); $created = 0;
            if ($old['identity'] !== $new['identity'] || $old['parents'] !== $new['parents']
                || $old['parent_names'] !== $new['parent_names']) { self::fail('DATABASE_OR_PARENT_CHANGED'); }
            foreach ($old['targets'] as $name => $table) {
                $this->target($new, $name, $table === null);
                if ($table === null) {
                    ++$created;
                    if ($new['targets'][$name]['table']['AUTO_INCREMENT'] !== '1' || $new['targets'][$name]['triggers'] !== []) { self::fail('CREATED_TABLE_CHANGED'); }
                } elseif ($table !== $new['targets'][$name]) { self::fail('EXISTING_METADATA_OR_COUNTER_CHANGED'); }
            }
            if ($this->context($pdo) !== $privateState['context']) { self::fail('CONTEXT_CHANGED'); }
            return ['module_key' => 'official.import-export', 'migration_key' => self::KEY, 'sql_sha256' => self::SHA,
                'readonly' => true, 'preservation_verified' => true, 'proof_kind' => 'fixed_native_create_if_missing',
                'delta_kind' => 'import_export_tables_created_or_preserved', 'table_count' => 2, 'created_empty_tables' => $created,
                'before_sha256' => $privateState['before_sha256'], 'after_sha256' => self::hash($new)];
        });
    }

    private function snapshot(PDO $pdo, array $nativeParents): array
    {
        $identity = $this->query($pdo, 'SELECT DATABASE() AS database_name,@@server_uuid AS server_uuid,CONNECTION_ID() AS connection_id,@@foreign_key_checks AS foreign_key_checks')[0];
        if ($identity['foreign_key_checks'] !== '1') { self::fail('FOREIGN_KEY_CHECKS_REQUIRED'); }
        if (!is_string($identity['database_name']) || preg_match('/\A[a-zA-Z0-9_]+\z/D', $identity['database_name']) !== 1) {
            self::fail('CONSTRAINT_VISIBILITY_REQUIRED');
        }
        $mode = $this->query($pdo, 'SELECT @@GLOBAL.partial_revokes AS partial_revokes')[0]['partial_revokes'] ?? null;
        if (!in_array($mode, [0, 1, '0', '1'], true)) { self::fail('CONSTRAINT_VISIBILITY_REQUIRED'); }
        $scope = '`' . $identity['database_name'] . '`.*';
        $scopes = ['*.*', $scope];
        if ((string) $mode === '0') { $scopes[] = str_replace('_', '\\_', $scope); }
        $visible = false; $restricted = false;
        foreach ($this->query($pdo, 'SHOW GRANTS') as $row) {
            $grant = count($row) === 1 ? array_values($row)[0] : null;
            if (is_string($grant) && str_starts_with($grant, 'REVOKE ')) { $restricted = true; }
            if (!is_string($grant) || preg_match('/\AGRANT (.+) ON (.+) TO /D', $grant, $match) !== 1
                || !in_array($match[2], $scopes, true)) { continue; }
            $privileges = array_map('trim', explode(',', $match[1]));
            $visible = $visible || in_array('SELECT', $privileges, true) || in_array('ALL PRIVILEGES', $privileges, true);
        }
        if (!$visible || $restricted) { self::fail('CONSTRAINT_VISIBILITY_REQUIRED'); }
        $parents = [];
        foreach (['tenant' => ['id'], 'tenant_member' => ['tenant_id', 'id']] as $role => $keys) {
            $name = $nativeParents['names'][$role];
            $table = $nativeParents['parents'][$name];
            if ($table === null) { self::fail('REFERENCE_REQUIRED'); }
            foreach ($keys as $key) {
                if (($table['columns'][$key]['COLUMN_TYPE'] ?? null) !== 'bigint unsigned'
                    || $table['columns'][$key]['IS_NULLABLE'] !== 'NO') { self::fail('REFERENCE_KEY_INVALID'); }
            }
            $unique = false;
            foreach ($table['indexes'] as $parts) {
                if (array_column($parts, 'COLUMN_NAME') === $keys && $this->usableIndex($parts, true)) { $unique = true; }
            }
            if (!$unique) { self::fail('REFERENCE_UNIQUE_REQUIRED'); }
            $parents[$name] = $table;
        }
        $targets = []; $expectedNames = [];
        foreach (array_keys(self::COLUMNS) as $name) {
            $targets[$name] = $this->table($pdo, $name);
            foreach (self::CHECKS[$name] as $suffix) { $expectedNames['chk_import_export_' . $suffix] = [$name, 'CHECK']; }
            foreach (array_keys(self::FOREIGN[$name]) as $key) { $expectedNames[$key] = [$name, 'FOREIGN KEY']; }
        }
        $rows = $this->query($pdo, 'SELECT TABLE_NAME,CONSTRAINT_NAME,CONSTRAINT_TYPE FROM information_schema.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA=DATABASE() AND CONSTRAINT_NAME IN ('
            . implode(',', array_fill(0, count($expectedNames), '?')) . ') ORDER BY CONSTRAINT_NAME,TABLE_NAME', array_keys($expectedNames));
        foreach ($rows as $row) {
            if (($expectedNames[$row['CONSTRAINT_NAME']] ?? null) !== [$row['TABLE_NAME'], $row['CONSTRAINT_TYPE']]) { self::fail('CONSTRAINT_OWNER_CONFLICT'); }
        }
        return ['identity' => $identity, 'parents' => $parents, 'parent_names' => $nativeParents['names'], 'targets' => $targets];
    }

    private function target(array $snapshot, string $name, bool $created): void
    {
        $table = $snapshot['targets'][$name];
        if ($table === null || ($created && array_keys($table['columns']) !== array_keys(self::COLUMNS[$name]))) { self::fail('TARGET_COLUMNS_REQUIRED'); }
        foreach (self::COLUMNS[$name] as $key => [$type, $nullable, $default, $extra]) {
            $column = $table['columns'][$key] ?? null; $text = str_starts_with($type, 'varchar(') || str_starts_with($type, 'char(');
            if ($column === null || $column['COLUMN_TYPE'] !== $type || $column['IS_NULLABLE'] !== ($nullable ? 'YES' : 'NO')
                || $column['COLUMN_DEFAULT'] !== $default || $column['EXTRA'] !== $extra
                || $column['CHARACTER_SET_NAME'] !== ($text ? 'ascii' : null) || $column['COLLATION_NAME'] !== ($text ? 'ascii_bin' : null)
                || ($key === 'column_key_unique' ? $this->clause($column['GENERATION_EXPRESSION']) !== "coalesce(column_key,'')" : $column['GENERATION_EXPRESSION'] !== '')) { self::fail('TARGET_COLUMN_INVALID'); }
        }
        foreach (self::INDEXES[$name] as $key => [$unique, $columns]) {
            $parts = $table['indexes'][$key] ?? [];
            if (array_column($parts, 'COLUMN_NAME') !== $columns || !$this->usableIndex($parts, $unique)) { self::fail('TARGET_INDEX_INVALID'); }
        }
        if ($created && count($table['indexes']) !== count(self::INDEXES[$name])) { self::fail('TARGET_INDEX_DELTA_INVALID'); }
        foreach (self::FOREIGN[$name] as $key => [$columns, $parent, $parentColumns]) {
            // Foreign owner names are authenticated by the finite native Host stage.
            $parent = $name === self::OPERATION ? $snapshot['parent_names'][$parent] : $parent;
            $parts = $table['foreign'][$key] ?? [];
            if (array_column($parts, 'COLUMN_NAME') !== $columns || array_column($parts, 'REFERENCED_COLUMN_NAME') !== $parentColumns) { self::fail('TARGET_FOREIGN_INVALID'); }
            foreach ($parts as $part) {
                if ($part['REFERENCED_TABLE_SCHEMA'] !== $snapshot['identity']['database_name'] || $part['REFERENCED_TABLE_NAME'] !== $parent
                    || $part['DELETE_RULE'] !== 'RESTRICT' || !in_array($part['UPDATE_RULE'], ['RESTRICT', 'NO ACTION'], true) || $part['MATCH_OPTION'] !== 'NONE') { self::fail('TARGET_FOREIGN_INVALID'); }
            }
        }
        foreach (self::CHECKS[$name] as $suffix) {
            $check = $table['checks']['chk_import_export_' . $suffix] ?? null;
            if ($check === null || $check['ENFORCED'] !== 'YES' || !$this->knownCheck($suffix, $check['CHECK_CLAUSE'])) { self::fail('TARGET_CHECK_INVALID'); }
        }
        if ($created && (count($table['foreign']) !== count(self::FOREIGN[$name]) || count($table['checks']) !== count(self::CHECKS[$name]))) { self::fail('TARGET_CONSTRAINT_DELTA_INVALID'); }
    }

    private function usableIndex(array $parts, bool $unique): bool
    {
        return $parts !== [] && array_filter($parts, static fn(array $p): bool => $p['NON_UNIQUE'] !== ($unique ? '0' : '1')
            || $p['SUB_PART'] !== null || $p['EXPRESSION'] !== null || $p['INDEX_TYPE'] !== 'BTREE' || $p['IS_VISIBLE'] !== 'YES' || $p['COLLATION'] !== 'A') === [];
    }

    private function table(PDO $pdo, string $name): ?array
    {
        try { $ddl = $this->query($pdo, 'SHOW CREATE TABLE `' . $name . '`'); }
        catch (PDOException $error) { if ((int)($error->errorInfo[1] ?? 0) === 1146) { return null; } throw $error; }
        $rows = $this->query($pdo, 'SELECT TABLE_TYPE,ENGINE,TABLE_COLLATION,AUTO_INCREMENT,CAST(TABLE_COMMENT AS BINARY) AS TABLE_COMMENT FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?', [$name]);
        if (count($rows) !== 1 || $rows[0]['TABLE_TYPE'] !== 'BASE TABLE' || $rows[0]['ENGINE'] !== 'InnoDB') { self::fail('BASE_INNODB_REQUIRED'); }
        $columns = $this->query($pdo, 'SELECT COLUMN_NAME,COLUMN_TYPE,IS_NULLABLE,COLUMN_DEFAULT,EXTRA,GENERATION_EXPRESSION,CHARACTER_SET_NAME,COLLATION_NAME,CAST(COLUMN_COMMENT AS BINARY) AS COLUMN_COMMENT,ORDINAL_POSITION FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? ORDER BY ORDINAL_POSITION', [$name]);
        $indexes = $this->query($pdo, 'SELECT INDEX_NAME,NON_UNIQUE,COLUMN_NAME,SUB_PART,EXPRESSION,INDEX_TYPE,IS_VISIBLE,COLLATION FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? ORDER BY INDEX_NAME,SEQ_IN_INDEX', [$name]);
        $foreign = $this->query($pdo, 'SELECT k.CONSTRAINT_NAME,k.COLUMN_NAME,k.REFERENCED_TABLE_SCHEMA,k.REFERENCED_TABLE_NAME,k.REFERENCED_COLUMN_NAME,r.DELETE_RULE,r.UPDATE_RULE,r.MATCH_OPTION FROM information_schema.KEY_COLUMN_USAGE k JOIN information_schema.REFERENTIAL_CONSTRAINTS r ON r.CONSTRAINT_SCHEMA=k.CONSTRAINT_SCHEMA AND r.CONSTRAINT_NAME=k.CONSTRAINT_NAME AND r.TABLE_NAME=k.TABLE_NAME WHERE k.TABLE_SCHEMA=DATABASE() AND k.TABLE_NAME=? ORDER BY k.CONSTRAINT_NAME,k.ORDINAL_POSITION', [$name]);
        $checks = $this->query($pdo, "SELECT t.CONSTRAINT_NAME,t.ENFORCED,CAST(c.CHECK_CLAUSE AS BINARY) AS CHECK_CLAUSE FROM information_schema.TABLE_CONSTRAINTS t JOIN information_schema.CHECK_CONSTRAINTS c ON c.CONSTRAINT_SCHEMA=t.CONSTRAINT_SCHEMA AND c.CONSTRAINT_NAME=t.CONSTRAINT_NAME WHERE t.TABLE_SCHEMA=DATABASE() AND t.TABLE_NAME=? AND t.CONSTRAINT_TYPE='CHECK' ORDER BY t.CONSTRAINT_NAME", [$name]);
        $triggers = $this->query($pdo, 'SELECT TRIGGER_NAME,EVENT_MANIPULATION,ACTION_TIMING,CAST(ACTION_STATEMENT AS BINARY) AS ACTION_STATEMENT,SQL_MODE,DEFINER FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA=DATABASE() AND EVENT_OBJECT_TABLE=? ORDER BY TRIGGER_NAME', [$name]);
        $group = static function (array $rows, string $key): array { $out = []; foreach ($rows as $row) { $out[$row[$key]][] = $row; } return $out; };
        return ['table' => $rows[0], 'ddl' => $ddl, 'columns' => array_column($columns, null, 'COLUMN_NAME'),
            'indexes' => $group($indexes, 'INDEX_NAME'), 'foreign' => $group($foreign, 'CONSTRAINT_NAME'),
            'checks' => array_column($checks, null, 'CONSTRAINT_NAME'), 'triggers' => $triggers];
    }

    /** Fixed source/native forms only; quoted values and logical grouping remain significant. */
    private function knownCheck(string $key, string $clause): bool
    {
        $forms = match ($key) {
            'key' => ["operation_keyregexp'^iox_[0-9a-f]{32}$'", "regexp_like(operation_key,'^iox_[0-9a-f]{32}$')"],
            'direction' => ["directionin('import','export')"], 'format' => ["format='csv'"],
            'status' => ["statusin('queued','running','cancel_requested','succeeded','failed','cancelled','expired')"],
            'input' => ["(direction='import')=(input_file_keyisnotnull)"],
            'files' => ["(result_file_keyisnullorresult_file_keyregexp'^file_[0-9a-f]{32}$')and(error_file_keyisnullorerror_file_keyregexp'^file_[0-9a-f]{32}$')", "((result_file_keyisnull)orregexp_like(result_file_key,'^file_[0-9a-f]{32}$'))and((error_file_keyisnull)orregexp_like(error_file_key,'^file_[0-9a-f]{32}$'))"],
            'task' => ["task_job_keyisnullortask_job_keyregexp'^job_[0-9a-f]{32}$'", "(task_job_keyisnull)orregexp_like(task_job_key,'^job_[0-9a-f]{32}$')"],
            'hashes' => ["idempotency_key_hashregexp'^[0-9a-f]{64}$'andrequest_hashregexp'^[0-9a-f]{64}$'", "regexp_like(idempotency_key_hash,'^[0-9a-f]{64}$')andregexp_like(request_hash,'^[0-9a-f]{64}$')"],
            'progress' => ['accepted_rows+rejected_rows<=processed_rowsandprocessed_rows<=100000andtotal_rows<=100000', '((accepted_rows+rejected_rows)<=processed_rows)and(processed_rows<=100000)and(total_rows<=100000)'],
            'attempt' => ['attempt_number<=10'], 'revision' => ['revision>=1'],
            'completion' => ["(statusin('succeeded','failed','cancelled','expired'))=(completed_atisnotnull)"],
            'row_number' => ['row_number>=2androw_number<=100001', '(row_number>=2)and(row_number<=100001)'],
            'column' => ["column_keyisnullorcolumn_keyregexp'^[a-z][a-z0-9_]{0,63}$'", "(column_keyisnull)orregexp_like(column_key,'^[a-z][a-z0-9_]{0,63}$')"],
            'error_code' => ["error_coderegexp'^[A-Z][A-Z0-9_]{2,63}$'", "regexp_like(error_code,'^[A-Z][A-Z0-9_]{2,63}$')"],
            default => [],
        };
        $value = $this->clause($clause);
        foreach ($forms as $form) { for ($i = 0; $i <= 4; ++$i) { if ($value === str_repeat('(', $i) . $form . str_repeat(')', $i)) { return true; } } }
        return false;
    }

    private function clause(string $clause): string
    {
        $parts = preg_split(<<<'RX'
~('(?:''|\\.|[^'\\])*')~s
RX, $clause, -1, PREG_SPLIT_DELIM_CAPTURE);
        if ($parts === false) { self::fail('CLAUSE_OBSERVATION_FAILED'); }
        $out = '';
        foreach ($parts as $i => $part) {
            if ($i % 2 === 1) { $out .= $part; continue; }
            $part = preg_replace_callback('/\b(?:regexp_like|regexp|is|not|null|and|or|in|coalesce)\b/i', static fn(array $m): string => strtolower($m[0]), $part);
            $part = preg_replace('/[\s`]+/', '', $part);
            if ($i + 1 < count($parts)) { $part = preg_replace('/_(?:utf8mb4|ascii)\z/i', '', $part); }
            $out .= $part;
        }
        return $out;
    }

    private function context(PDO $pdo): InstanceExecutionContext|InstallationExecutionContext
    {
        $context = $this->contexts->current();
        if (!($context instanceof InstanceExecutionContext || $context instanceof InstallationExecutionContext)
            || Db::connect()->getPdo() !== $pdo || $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'mysql'
            || $pdo->getAttribute(PDO::ATTR_CASE) !== PDO::CASE_NATURAL || $pdo->inTransaction()
            || Db::name('import_export_operation')->getTable() !== self::OPERATION || Db::name('import_export_row_error')->getTable() !== self::ERRORS) { self::fail('NATIVE_DDL_CONTEXT_REQUIRED'); }
        return $context;
    }

    private function source(string $key): void
    {
        $path = dirname(__DIR__, 2) . '/database/migrations/20260921-adopt-import-export-schema.sql';
        if ($key !== self::KEY || !is_file($path) || hash_file('sha256', $path) !== self::SHA) { self::fail('SOURCE_CHANGED'); }
    }

    private function query(PDO $pdo, string $sql, array $bindings = []): array
    {
        $statement = $pdo->prepare($sql);
        try { $statement->execute($bindings); return array_map(static fn(array $row): array => array_map(static fn(mixed $v): ?string => $v === null ? null : (string)$v, $row), $statement->fetchAll(PDO::FETCH_ASSOC)); }
        finally { $statement->closeCursor(); }
    }

    private function guard(callable $operation): array
    {
        try { return $operation(); }
        catch (Throwable $error) {
            if ($error instanceof RuntimeException && str_starts_with($error->getMessage(), 'IMPORT_EXPORT_PRESERVATION_')) { throw $error; }
            throw new RuntimeException('IMPORT_EXPORT_PRESERVATION_OBSERVATION_FAILED', 0, $error);
        }
    }
    private static function hash(array $value): string { return hash('sha256', json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)); }
    private static function fail(string $code): never { throw new RuntimeException('IMPORT_EXPORT_PRESERVATION_' . $code); }
}
