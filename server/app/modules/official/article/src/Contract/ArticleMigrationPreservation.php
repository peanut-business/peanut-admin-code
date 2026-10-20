<?php

declare(strict_types=1);

namespace PeanutAdmin\Modules\Article\Contract;

use app\common\execution\CurrentExecutionContext;
use app\common\execution\InstallationExecutionContext;
use app\common\execution\InstanceExecutionContext;
use PDO;
use RuntimeException;
use think\facade\Db;

/** Article67 DDL observations; the native caller owns predecessor admission, execution, exclusion and recovery. */
final readonly class ArticleMigrationPreservation
{
    private const PROTOCOL = 'peanut.article-migration-preservation.v1';
    private const KEY = 'official.article:20261013230000_add_article_document_save_binding';
    private const SQL_SHA256 = '67e504df714ee1a1d0cc6959506eaa584cfaeb0ea26c3e95597586b5ec2576fe';
    private const ADDED = ['body_document_id', 'body_revision_id', 'body_source_state_seq', 'body_snapshot_sha256'];
    private const CONSTRAINTS = ['chk_article_body_binding', 'chk_article_body_save_phase', 'chk_article_body_save_digest',
        'chk_article_body_save_result', 'fk_article_body_save_tenant', 'fk_article_body_save_document'];
    private const OBSERVED = ['article', 'article_body_save', 'tenant', 'document', 'document_state',
        'document_revision', 'document_file_ref', 'document_resource_binding', 'document_operation'];
    private const SAVE_COLUMNS = ['id', 'tenant_id', 'article_id', 'document_id', 'request_id', 'input_sha256',
        'actor_account_id', 'actor_member_id', 'phase', 'result_json', 'created_at', 'completed_at'];

    public function __construct(private CurrentExecutionContext $contexts) {}

    /** Keep private_state only in the held native callback; only safe_summary may be logged. */
    public function before(PDO $pdo, string $canonicalMigrationKey): array
    {
        return $this->guard(function () use ($pdo, $canonicalMigrationKey): array {
            $context = $this->context($pdo);
            $sqlHash = $this->sqlHash($canonicalMigrationKey);
            $snapshot = $this->snapshot($pdo);
            $this->prestate($snapshot);
            if ($this->context($pdo) !== $context) { self::fail('CONTEXT_CHANGED'); }
            $hash = self::hash($snapshot);
            return [
                'safe_summary' => ['module_key' => 'official.article', 'migration_key' => $canonicalMigrationKey,
                    'sql_sha256' => $sqlHash, 'readonly' => true, 'preservation_verified' => false,
                    'before_sha256' => $hash, 'proof_kind' => 'fixed_native_additive_ddl'],
                'private_state' => ['protocol' => self::PROTOCOL, 'pdo' => $pdo, 'context' => $context,
                    'migration_key' => $canonicalMigrationKey, 'sql_sha256' => $sqlHash,
                    'transaction_active' => false, 'snapshot' => $snapshot, 'before_sha256' => $hash],
            ];
        });
    }

    /** Only after full immutable SQL success on the same PDO, before the sole native applied transition. */
    public function after(PDO $pdo, array $privateState): array
    {
        return $this->guard(function () use ($pdo, $privateState): array {
            $keys = ['protocol', 'pdo', 'context', 'migration_key', 'sql_sha256', 'transaction_active', 'snapshot', 'before_sha256'];
            if (count($privateState) !== count($keys) || array_diff(array_keys($privateState), $keys) !== []
                || ($privateState['protocol'] ?? null) !== self::PROTOCOL || ($privateState['pdo'] ?? null) !== $pdo
                || ($privateState['context'] ?? null) !== $this->context($pdo)
                || ($privateState['transaction_active'] ?? null) !== false
                || ($privateState['migration_key'] ?? null) !== self::KEY
                || !is_array($privateState['snapshot'] ?? null) || !is_string($privateState['before_sha256'] ?? null)
                || !hash_equals($privateState['before_sha256'], self::hash($privateState['snapshot']))) {
                self::fail('BEFORE_BINDING_INVALID');
            }
            $sqlHash = $this->sqlHash($privateState['migration_key']);
            if ($privateState['sql_sha256'] !== $sqlHash) { self::fail('SOURCE_CHANGED'); }
            $old = $privateState['snapshot'];
            $this->prestate($old);
            $new = $this->snapshot($pdo);
            if ($old['identity'] !== $new['identity']) { self::fail('DATABASE_CHANGED'); }
            if ($old['article_inbound'] !== $new['article_inbound']) { self::fail('ARTICLE_INBOUND_CHANGED'); }
            foreach ($old['tables'] as $name => $table) {
                if ($name === 'article_body_save') { continue; }
                $current = $new['tables'][$name];
                if ($current === null) { self::fail('TABLE_DISAPPEARED'); }
                if ($name === 'article') { $current = $this->withoutBinding($current); }
                if ($table !== $current) { self::fail('ORIGINAL_SCHEMA_OR_COUNTER_CHANGED'); }
            }
            $save = $new['tables']['article_body_save'];
            if ($save === null || array_column($save['columns'], 'COLUMN_NAME') !== self::SAVE_COLUMNS
                || $save['primary'] !== ['id'] || $save['collation'] !== 'utf8mb4_unicode_ci'
                || $save['triggers'] !== [] || $save['auto_increment'] !== '1'
                || $this->query($pdo, 'SELECT 1 FROM `pa_article_body_save` LIMIT 1') !== []) {
                self::fail('SAVE_TABLE_NOT_EMPTY_OR_UNKNOWN');
            }
            $expected = [];
            foreach (self::CONSTRAINTS as $constraint) {
                $expected[] = ['TABLE_NAME' => $constraint === 'chk_article_body_binding' ? 'pa_article' : 'pa_article_body_save',
                    'CONSTRAINT_NAME' => $constraint];
            }
            usort($expected, static fn(array $a, array $b): int => strcmp($a['CONSTRAINT_NAME'], $b['CONSTRAINT_NAME']));
            if ($new['constraint_names'] !== $expected) { self::fail('CONSTRAINT_DELTA_UNKNOWN'); }
            if ($this->context($pdo) !== $privateState['context']) { self::fail('CONTEXT_CHANGED'); }
            return ['module_key' => 'official.article', 'migration_key' => self::KEY, 'sql_sha256' => $sqlHash,
                'readonly' => true, 'preservation_verified' => true, 'proof_kind' => 'fixed_native_additive_ddl',
                'before_sha256' => $privateState['before_sha256'], 'after_sha256' => self::hash($new),
                'delta_kind' => 'article_document_binding_and_empty_save_receipt', 'table_count' => 2];
        });
    }

    private function prestate(array $snapshot): void
    {
        $article = $snapshot['tables']['article'];
        if ($article === null || $article['primary'] !== ['id']
            || array_intersect(self::ADDED, array_column($article['columns'], 'COLUMN_NAME')) !== []
            || $snapshot['tables']['article_body_save'] !== null || $snapshot['constraint_names'] !== []) {
            self::fail('TARGET_ABSENCE_REQUIRED');
        }
        // Metadata prerequisites only. Canonical Document2 completion/ledger/source admission belongs to the caller.
        foreach (array_slice(self::OBSERVED, 2) as $name) {
            if ($snapshot['tables'][$name] === null) { self::fail('DOCUMENT_PREDECESSOR_REQUIRED'); }
        }
        foreach (['article' => ['id' => 'int unsigned', 'tenant_id' => 'bigint unsigned'],
            'tenant' => ['id' => 'bigint unsigned'], 'document' => ['tenant_id' => 'bigint unsigned', 'id' => 'bigint unsigned']] as $name => $keys) {
            $columns = array_column($snapshot['tables'][$name]['columns'], null, 'COLUMN_NAME');
            foreach ($keys as $key => $type) {
                $column = $columns[$key] ?? null;
                if ($column === null || $column['COLUMN_TYPE'] !== $type || $column['IS_NULLABLE'] !== 'NO') {
                    self::fail('PARENT_KEY_SHAPE_UNKNOWN');
                }
            }
        }
        $this->uniqueKey($snapshot['tables']['tenant'], ['id']);
        $this->uniqueKey($snapshot['tables']['document'], ['tenant_id', 'id']);
        $operation = $snapshot['tables']['document_operation'];
        $names = array_column($operation['columns'], 'COLUMN_NAME');
        if (array_slice($names, -6) !== ['phase', 'initiator_json', 'command_input_json', 'barrier_plan_json', 'business_sha256', 'released_at']
            || !str_contains($operation['ddl'], 'CONSTRAINT `chk_document_operation_phase` CHECK')) {
            self::fail('DOCUMENT_BARRIERS_REQUIRED');
        }
    }

    private function uniqueKey(array $table, array $columns): void
    {
        $keys = []; $invalid = [];
        foreach ($table['indexes'] as $index) {
            $name = $index['INDEX_NAME'];
            $keys[$name][] = $index['COLUMN_NAME'];
            if ((string) $index['NON_UNIQUE'] !== '0' || $index['SUB_PART'] !== null || $index['EXPRESSION'] !== null) { $invalid[$name] = true; }
        }
        $keys = array_diff_key($keys, $invalid);
        if (!in_array($columns, $keys, true)) { self::fail('PARENT_KEY_SHAPE_UNKNOWN'); }
    }

    private function withoutBinding(array $table): array
    {
        $columns = $table['columns'];
        $added = array_splice($columns, -4);
        if (array_column($added, 'COLUMN_NAME') !== self::ADDED) { self::fail('BINDING_COLUMNS_UNKNOWN'); }
        foreach ($added as $column) {
            $hash = $column['COLUMN_NAME'] === 'body_snapshot_sha256';
            if ($column['COLUMN_TYPE'] !== ($hash ? 'char(64)' : 'bigint unsigned') || $column['IS_NULLABLE'] !== 'YES'
                || $column['COLUMN_DEFAULT'] !== null || $column['EXTRA'] !== '' || $column['GENERATION_EXPRESSION'] !== ''
                || $column['CHARACTER_SET_NAME'] !== ($hash ? 'ascii' : null) || $column['COLLATION_NAME'] !== ($hash ? 'ascii_bin' : null)) {
                self::fail('BINDING_COLUMN_SEMANTICS_UNKNOWN');
            }
        }
        $ddl = $table['ddl'];
        foreach (self::ADDED as $name) {
            $ddl = preg_replace('/^  `' . $name . '` [^\r\n]+,\r?\n/m', '', $ddl, -1, $removed);
            if ($removed !== 1 || !is_string($ddl)) { self::fail('BINDING_DDL_UNKNOWN'); }
        }
        if (preg_match('/^  CONSTRAINT `chk_article_body_binding` CHECK \((.+)\)(,?)$/m', $ddl, $match) !== 1) {
            self::fail('BINDING_CHECK_UNKNOWN');
        }
        $expression = preg_replace('/[\s`()]+|_(?:utf8mb4|ascii)/', '', $match[1]);
        $head = 'body_revision_idisnullandbody_source_state_seqisnullandbody_snapshot_sha256isnullorbody_document_idisnotnullandbody_revision_idisnotnullandbody_source_state_seqisnotnullandbody_snapshot_sha256isnotnulland';
        if (!in_array($expression, [$head . "body_snapshot_sha256regexp'^[a-f0-9]{64}$'",
            $head . "regexp_likebody_snapshot_sha256,'^[a-f0-9]{64}$'"], true)) { self::fail('BINDING_CHECK_UNKNOWN'); }
        $ddl = preg_replace('/^  CONSTRAINT `chk_article_body_binding` CHECK [^\r\n]+\r?\n/m', '', $ddl, -1, $removed);
        if ($removed !== 1 || !is_string($ddl)) { self::fail('BINDING_CHECK_UNKNOWN'); }
        $table['ddl'] = preg_replace('/,\r?\n\) ENGINE=/', "\n) ENGINE=", $ddl);
        $table['columns'] = $columns;
        return $table;
    }

    /** No transaction mutation or business-row scan; the caller must hold the native writer/DDL window. */
    private function snapshot(PDO $pdo): array
    {
        $identity = $this->identity($pdo);
        $this->visibility($pdo, $identity['database_name']);
        $tables = [];
        foreach (self::OBSERVED as $name) {
            if (Db::name($name)->getTable() !== 'pa_' . $name) { self::fail('EXECUTE_PREFIX_UNSUPPORTED'); }
            $tables[$name] = $this->metadata($pdo, 'pa_' . $name);
        }
        $constraints = $this->query($pdo,
            'SELECT TABLE_NAME,CONSTRAINT_NAME FROM information_schema.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA=DATABASE()'
            . ' AND CONSTRAINT_NAME IN (' . implode(',', array_fill(0, count(self::CONSTRAINTS), '?')) . ') ORDER BY CONSTRAINT_NAME', self::CONSTRAINTS);
        $inbound = $this->query($pdo,
            'SELECT TABLE_NAME,CONSTRAINT_NAME,COLUMN_NAME,ORDINAL_POSITION,REFERENCED_COLUMN_NAME FROM information_schema.KEY_COLUMN_USAGE'
            . " WHERE REFERENCED_TABLE_SCHEMA=DATABASE() AND REFERENCED_TABLE_NAME='pa_article' ORDER BY TABLE_NAME,CONSTRAINT_NAME,ORDINAL_POSITION");
        if ($identity !== $this->identity($pdo)) { self::fail('DATABASE_CHANGED'); }
        return ['identity' => $identity, 'tables' => $tables, 'constraint_names' => $constraints, 'article_inbound' => $inbound];
    }

    private function metadata(PDO $pdo, string $table): ?array
    {
        $info = $this->query($pdo, 'SELECT ENGINE,TABLE_TYPE,TABLE_COLLATION,AUTO_INCREMENT FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?', [$table]);
        if ($info === []) { return null; }
        if (count($info) !== 1 || $info[0]['ENGINE'] !== 'InnoDB' || $info[0]['TABLE_TYPE'] !== 'BASE TABLE') { self::fail('TABLE_SHAPE_UNKNOWN'); }
        $ddl = $this->query($pdo, 'SHOW CREATE TABLE ' . self::q($table));
        if (count($ddl) !== 1 || !is_string($ddl[0]['Create Table'] ?? null)) { self::fail('DDL_UNAVAILABLE'); }
        $columns = $this->query($pdo,
            'SELECT COLUMN_NAME,COLUMN_TYPE,IS_NULLABLE,COLUMN_DEFAULT,EXTRA,GENERATION_EXPRESSION,CHARACTER_SET_NAME,COLLATION_NAME'
            . ' FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? ORDER BY ORDINAL_POSITION', [$table]);
        $indexes = $this->query($pdo,
            'SELECT INDEX_NAME,NON_UNIQUE,SEQ_IN_INDEX,COLUMN_NAME,SUB_PART,EXPRESSION FROM information_schema.STATISTICS'
            . ' WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? ORDER BY INDEX_NAME,SEQ_IN_INDEX', [$table]);
        $primary = [];
        foreach ($indexes as $index) { if ($index['INDEX_NAME'] === 'PRIMARY') { $primary[] = $index['COLUMN_NAME']; } }
        $triggers = $this->query($pdo,
            'SELECT TRIGGER_NAME,EVENT_MANIPULATION,ACTION_ORDER,ACTION_CONDITION,ACTION_STATEMENT,ACTION_ORIENTATION,ACTION_TIMING,'
            . 'SQL_MODE,DEFINER,CHARACTER_SET_CLIENT,COLLATION_CONNECTION,DATABASE_COLLATION,CREATED'
            . ' FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA=DATABASE() AND EVENT_OBJECT_TABLE=? ORDER BY TRIGGER_NAME', [$table]);
        return ['ddl' => $ddl[0]['Create Table'], 'columns' => $columns, 'indexes' => $indexes, 'primary' => $primary,
            'triggers' => $triggers, 'collation' => $info[0]['TABLE_COLLATION'],
            'auto_increment' => $info[0]['AUTO_INCREMENT'] === null ? null : (string) $info[0]['AUTO_INCREMENT']];
    }

    private function identity(PDO $pdo): array
    {
        $this->context($pdo);
        $rows = $this->query($pdo, 'SELECT DATABASE() AS database_name,@@server_uuid AS server_uuid,CONNECTION_ID() AS connection_id,@@session.sql_mode AS sql_mode,@@session.time_zone AS time_zone');
        if (count($rows) !== 1 || !is_string($rows[0]['database_name']) || $rows[0]['database_name'] === '') { self::fail('DATABASE_UNKNOWN'); }
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
            if (!preg_match('/\AGRANT (.+) ON (.+) TO /D', $grant, $match) || !in_array($match[2], $scopes, true)) { continue; }
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
            || Db::connect()->getPdo() !== $pdo || $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'mysql' || $pdo->inTransaction()) {
            self::fail('NATIVE_DDL_CONTEXT_REQUIRED');
        }
        return $context;
    }

    private function sqlHash(string $key): string
    {
        if ($key !== self::KEY) { self::fail('MIGRATION_UNKNOWN'); }
        $path = dirname(__DIR__, 2) . '/database/migrations/20261013230000_add_article_document_save_binding.sql';
        if (!is_file($path) || hash_file('sha256', $path) !== self::SQL_SHA256) { self::fail('SOURCE_CHANGED'); }
        return self::SQL_SHA256;
    }

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
            $code = $error instanceof RuntimeException && preg_match('/\AARTICLE_PRESERVATION_[A-Z_]+\z/D', $error->getMessage()) === 1
                ? $error->getMessage() : 'ARTICLE_PRESERVATION_OBSERVATION_FAILED';
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

    private static function fail(string $code): never { throw new RuntimeException('ARTICLE_PRESERVATION_' . $code); }
}
