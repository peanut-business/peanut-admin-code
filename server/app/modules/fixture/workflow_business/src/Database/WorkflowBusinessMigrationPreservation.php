<?php

declare(strict_types=1);

namespace PeanutAdmin\Fixtures\WorkflowBusiness\Database;

use app\common\services\upgrade\MySqlMetadataObservation;
use Closure;
use PDO;
use RuntimeException;

/** Immutable native CREATE stages; no DDL execution, history adoption or parent recovery. */
final class WorkflowBusinessMigrationPreservation
{
    private const MODULE = 'fixture.workflow-business';
    private const SOURCES = [
        'fixture.workflow-business:20261012050100_workflow_business' => '34c60bca6ac1dd7bdb31a77be9a137ae99f7de8b863e9d309f4f7075d094a99c',
        'fixture.workflow-business:20261012050200_workflow_collaboration' => 'fd9e10722653314d2fdfd28bd9bbafd969a4e00eaec64a6c970053a259d04399',
    ];
    /** Owned table => creating stage, in native creation order. */
    private const TABLES = [
        'pa_fixture_workflow_object' => 'fixture.workflow-business:20261012050100_workflow_business',
        'pa_fixture_workflow_payload' => 'fixture.workflow-business:20261012050100_workflow_business',
        'pa_fixture_workflow_effect' => 'fixture.workflow-business:20261012050100_workflow_business',
        'pa_fixture_workflow_stock_ledger' => 'fixture.workflow-business:20261012050100_workflow_business',
        'pa_fixture_workflow_stock_balance' => 'fixture.workflow-business:20261012050100_workflow_business',
        'pa_fixture_workflow_task_actor' => 'fixture.workflow-business:20261012050100_workflow_business',
        'pa_fixture_workflow_collaboration' => 'fixture.workflow-business:20261012050200_workflow_collaboration',
    ];
    private const PARENTS = [];

    public static function before(PDO $pdo, array $stage): array
    {
        return self::snapshot($pdo, static function () use ($pdo, $stage): array {
            self::identity($stage); self::sources();
            $ledger = self::ledger($pdo, $stage); $tables = self::tables($pdo); $rows = [];
            foreach (self::TABLES as $table => $creator) {
                $exists = strcmp($creator, $stage['migration_key']) < 0;
                if (($tables[$table] !== null) !== $exists) { self::fail('PRESTATE_TABLES_INVALID'); }
                if ($exists) {
                    self::schema($pdo, $table, $tables[$table], $creator);
                    $rows[$table] = self::rowDigest($pdo, $table, $tables[$table]);
                }
            }
            $parents = self::parents($pdo);
            if ($ledger !== self::ledger($pdo, $stage)) { self::fail('LEDGER_CHANGED'); }
            return ['module_key' => self::MODULE, 'migration_key' => $stage['migration_key'], 'checksum' => $stage['checksum'],
                'pdo_object_id' => spl_object_id($pdo), 'connection' => self::connection($pdo),
                'stageLedger' => $stage['stageLedger'], 'ledger' => $ledger,
                'tables' => $tables, 'parents' => $parents, 'row_digests' => $rows, 'readonly' => true,
                'safe_summary' => ['migration_key' => $stage['migration_key'], 'metadata_sha256' => self::digest($tables),
                    'ledger_sha256' => self::digest($ledger),
                    'data_proof' => 'fixed CREATE; prior owned original-column primary-key row digests retained']];
        });
    }

    public static function after(PDO $pdo, array $before): array
    {
        return self::snapshot($pdo, static function () use ($pdo, $before): array {
            self::identity($before);
            if (($before['pdo_object_id'] ?? null) !== spl_object_id($pdo)
                || ($before['connection'] ?? null) !== self::connection($pdo)
                || !is_array($before['tables'] ?? null) || !is_array($before['parents'] ?? null)
                || !is_array($before['row_digests'] ?? null) || !is_array($before['ledger'] ?? null)
                || array_keys($before['tables']) !== array_keys(self::TABLES)) { self::fail('INPUT_INVALID'); }
            self::sources();
            if ($before['ledger'] !== self::ledger($pdo, $before)) { self::fail('LEDGER_CHANGED'); }
            $tables = self::tables($pdo); $created = 0;
            foreach (self::TABLES as $table => $creator) {
                $order = strcmp($creator, $before['migration_key']);
                if ($order > 0) {
                    if ($before['tables'][$table] !== null || $tables[$table] !== null) { self::fail('FUTURE_TABLE_PRESENT'); }
                    continue;
                }
                if ($tables[$table] === null) { self::fail('TABLE_MISSING'); }
                self::schema($pdo, $table, $tables[$table], $creator);
                if ($order === 0) {
                    if ($before['tables'][$table] !== null) { self::fail('CREATE_PRESTATE_INVALID'); }
                    $auto = array_filter($tables[$table]['columns'], static fn(array $c): bool => $c['EXTRA'] === 'auto_increment');
                    if ($tables[$table]['auto_increment'] !== ($auto === [] ? null : '1')
                        || self::rows($pdo, 'SELECT EXISTS(SELECT 1 FROM ' . self::quote($table) . ') AS present')[0]['present'] !== '0') {
                        self::fail('CREATE_POSTSTATE_INVALID');
                    }
                    ++$created;
                } elseif ($before['tables'][$table] !== $tables[$table]
                    || ($before['row_digests'][$table] ?? null) !== self::rowDigest($pdo, $table, $before['tables'][$table])) {
                    self::fail('ORIGINAL_TABLE_CHANGED');
                }
            }
            $parents = self::parents($pdo);
            if ($before['parents'] !== $parents) { self::fail('PARENT_METADATA_CHANGED'); }
            if ($before['ledger'] !== self::ledger($pdo, $before)) { self::fail('LEDGER_CHANGED'); }
            return ['module_key' => self::MODULE, 'migration_key' => $before['migration_key'], 'checksum' => $before['checksum'],
                'readonly' => true, 'preservation_verified' => true,
                'before_sha256' => self::digest([$before['tables'], $before['parents'], $before['row_digests']]),
                'after_sha256' => self::digest([$tables, $parents, $before['row_digests']]),
                'metadata_sha256' => self::digest([$tables, $parents]), 'ledger_sha256' => self::digest($before['ledger']),
                'created_empty_tables' => $created,
                'data_proof' => 'exact fixed-source constraints; new empty tables and real allocators; prior schemas, original-column rows and parent metadata retained'];
        });
    }

    private static function identity(array $stage): void
    {
        $key = $stage['migration_key'] ?? null;
        if (($stage['module_key'] ?? null) !== self::MODULE || !is_string($key) || !isset(self::SOURCES[$key])
            || ($stage['checksum'] ?? null) !== self::SOURCES[$key]
            || !($stage['stageLedger'] ?? null) instanceof Closure) { self::fail('IDENTITY_INVALID'); }
    }

    private static function tables(PDO $pdo): array
    {
        $tables = [];
        foreach (self::TABLES as $table => $_) { $tables[$table] = MySqlMetadataObservation::tableSchema($pdo, $table); }
        return $tables;
    }

    private static function parents(PDO $pdo): array
    {
        $parents = [];
        foreach (self::PARENTS as $table) {
            $parents[$table] = MySqlMetadataObservation::tableSchema($pdo, $table);
            if ($parents[$table] === null || ($parents[$table]['metadata']['TABLE_TYPE'] ?? null) !== 'BASE TABLE'
                || ($parents[$table]['metadata']['ENGINE'] ?? null) !== 'InnoDB') { self::fail('PARENT_UNKNOWN'); }
        }
        return $parents;
    }

    private static function schema(PDO $pdo, string $table, array $image, string $creator): void
    {
        $expected = MySqlMetadataObservation::createTableSchema(self::source($creator), $table, self::connection($pdo)['db']);
        $fields = ['metadata', 'columns', 'indexes', 'foreign_keys', 'checks'];
        if (array_diff($fields, array_keys($expected)) !== [] || array_diff(array_keys($expected), $fields) !== []) { self::fail('EXPECTED_SCHEMA_UNKNOWN'); }
        foreach ($expected as $field => $value) {
            if (self::digest($image[$field] ?? null) !== self::digest($value)) { self::fail('SCHEMA_INVALID'); }
        }
        foreach ($image['columns'] as $column) {
            if (($column['GENERATION_EXPRESSION'] ?? null) !== '') { self::fail('GENERATED_COLUMN_UNKNOWN'); }
        }
        foreach ($image['checks'] as $check) {
            if (($check['ENFORCED'] ?? null) !== 'YES') { self::fail('CHECK_NOT_ENFORCED'); }
        }
        if (($image['triggers'] ?? null) !== []) { self::fail('TRIGGER_UNKNOWN'); }
        $auto = array_filter($image['columns'], static fn(array $c): bool => $c['EXTRA'] === 'auto_increment');
        if (!array_key_exists('auto_increment', $image)) { self::fail('ALLOCATOR_UNKNOWN'); }
        $allocator = $image['auto_increment'];
        if (($auto === [] && $allocator !== null)
            || ($auto !== [] && (!is_string($allocator) || preg_match('/\A[1-9][0-9]*\z/D', $allocator) !== 1))) { self::fail('ALLOCATOR_UNKNOWN'); }
    }

    /** Stream original values in actual PK order; only the framed byte digest leaves the reader. */
    private static function rowDigest(PDO $pdo, string $table, array $image): string
    {
        $columns = array_keys($image['columns']);
        $primary = array_values(array_filter($image['indexes'], static fn(array $i): bool => $i['INDEX_NAME'] === 'PRIMARY'));
        usort($primary, static fn(array $a, array $b): int => (int) $a['SEQ_IN_INDEX'] <=> (int) $b['SEQ_IN_INDEX']);
        $keys = array_column($primary, 'COLUMN_NAME');
        if ($columns === [] || $keys === []) { self::fail('PRIMARY_KEY_UNKNOWN'); }
        $query = $pdo->prepare('SELECT ' . implode(',', array_map(self::quote(...), $columns))
            . ' FROM ' . self::quote($table) . ' ORDER BY ' . implode(',', array_map(self::quote(...), $keys)));
        $query->execute(); $hash = hash_init('sha256'); hash_update($hash, self::canonical($columns));
        try {
            while (($row = $query->fetch(PDO::FETCH_NUM)) !== false) {
                hash_update($hash, 'R');
                foreach ($row as $value) {
                    if ($value === null) { hash_update($hash, 'N;'); }
                    elseif (is_scalar($value)) { $bytes = (string) $value; hash_update($hash, 'V' . strlen($bytes) . ':' . $bytes . ';'); }
                    else { self::fail('ROW_VALUE_UNKNOWN'); }
                }
            }
        } finally { $query->closeCursor(); }
        return hash_final($hash);
    }

    private static function quote(string $name): string
    {
        if (preg_match('/\A[a-z0-9_]+\z/D', $name) !== 1) { self::fail('IDENTIFIER_UNKNOWN'); }
        return chr(96) . $name . chr(96);
    }

    private static function ledger(PDO $pdo, array $stage): array
    {
        $rows = ($stage['stageLedger'])($pdo);
        $current = array_search($stage['migration_key'], array_keys(self::SOURCES), true);
        if (!is_array($rows) || array_column($rows, 'migration_key') !== array_slice(array_keys(self::SOURCES), 0, $current + 1)) { self::fail('LEDGER_INVALID'); }
        foreach ($rows as $row) {
            $key = $row['migration_key']; $active = $key === $stage['migration_key'];
            if (($row['module_key'] ?? null) !== self::MODULE || ($row['checksum'] ?? null) !== self::SOURCES[$key]
                || ($row['status'] ?? null) !== ($active ? 'applying' : 'applied')
                || ($row['resolution_kind'] ?? null) !== ($active ? null : 'executed')
                || ($row['provenance_json'] ?? null) !== null || ($row['error_code'] ?? null) !== null
                || ($active ? ($row['finished_at'] ?? null) !== null : !is_string($row['finished_at'] ?? null))) { self::fail('LEDGER_INVALID'); }
        }
        return $rows;
    }

    private static function sources(): void { foreach (self::SOURCES as $key => $_) { self::source($key); } }
    private static function source(string $key): string
    {
        if (!isset(self::SOURCES[$key])) { self::fail('SOURCE_UNKNOWN'); }
        $file = dirname(__DIR__, 2) . '/database/migrations/' . substr($key, strlen(self::MODULE) + 1) . '.sql';
        if (!is_file($file) || is_link($file) || hash_file('sha256', $file) !== self::SOURCES[$key]) { self::fail('SOURCE_CHANGED'); }
        return (string) file_get_contents($file);
    }
    private static function connection(PDO $pdo): array
    {
        $rows = self::rows($pdo, 'SELECT DATABASE() AS db,@@server_uuid AS server_uuid,CONNECTION_ID() AS connection_id');
        if (count($rows) !== 1 || !is_string($rows[0]['db']) || $rows[0]['db'] === '') { self::fail('CONNECTION_UNKNOWN'); }
        return $rows[0];
    }
    private static function rows(PDO $pdo, string $sql, array $arguments = []): array
    {
        $query = $pdo->prepare($sql); $query->execute($arguments);
        try { return array_map(static fn(array $row): array => array_map(static fn($v) => $v === null ? null : (string) $v, $row), $query->fetchAll(PDO::FETCH_ASSOC)); }
        finally { $query->closeCursor(); }
    }
    private static function snapshot(PDO $pdo, Closure $read): array
    {
        return MySqlMetadataObservation::uncached($pdo, static function () use ($pdo, $read): array {
            if ($pdo->inTransaction() || $pdo->getAttribute(PDO::ATTR_CASE) !== PDO::CASE_NATURAL) { self::fail('CONNECTION_INVALID'); }
            $started = false;
            try {
                $pdo->exec('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ, READ ONLY'); $pdo->beginTransaction(); $started = true;
                $result = $read();
                if (!$pdo->inTransaction()) { self::fail('SNAPSHOT_CHANGED'); }
                return $result;
            } finally { if ($started && $pdo->inTransaction()) { $pdo->rollBack(); } }
        });
    }
    private static function canonical(mixed $value): string
    {
        $sort = static function (mixed $v) use (&$sort): mixed {
            if (!is_array($v)) { return $v; }
            if (!array_is_list($v)) { ksort($v, SORT_STRING); }
            foreach ($v as &$item) { $item = $sort($item); } return $v;
        };
        return json_encode($sort($value), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
    private static function digest(mixed $value): string { return hash('sha256', self::canonical($value)); }
    private static function fail(string $code): never { throw new RuntimeException('WORKFLOW_BUSINESS_STAGE_' . $code); }
}
