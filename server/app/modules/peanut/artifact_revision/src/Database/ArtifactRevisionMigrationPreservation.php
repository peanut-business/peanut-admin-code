<?php

declare(strict_types=1);

namespace PeanutAdmin\Modules\ArtifactRevision\Database;

use app\common\services\upgrade\MySqlMetadataObservation;
use app\platform\services\plugin\PluginLifecycleService;
use Closure;
use PDO;
use RuntimeException;

/** Immutable native CREATE stages; no DDL execution, history adoption or parent recovery. */
final class ArtifactRevisionMigrationPreservation
{
    private const MODULE = 'peanut.artifact-revision';
    private const SOURCES = [
        'peanut.artifact-revision:20260921-register-module-schema' => 'fe70a60a35892fc6352764a4ee8eb065ca8a19e067ab99ccb91c79c21ce95e39',
    ];
    /** Owned table => creating stage, in native creation order. */
    private const TABLES = [
        'pa_artifact' => 'peanut.artifact-revision:20260921-register-module-schema',
        'pa_artifact_revision' => 'peanut.artifact-revision:20260921-register-module-schema',
    ];
    public static function before(PDO $pdo, array $stage, array $nativeParentFacts): array
    {
        self::identity($stage);
        $parents = self::parents(PluginLifecycleService::verifiedMigrationParentFacts($pdo, $stage['migration_key'], 'before', $nativeParentFacts));
        return self::snapshot($pdo, static function () use ($pdo, $stage, $parents): array {
            self::identity($stage); self::sources();
            $ledger = self::ledger($pdo, $stage); $tables = self::tables($pdo);
            foreach ($tables as $image) {
                if ($image !== null) { self::fail('PRESTATE_TABLES_INVALID'); }
            }
            if ($ledger !== self::ledger($pdo, $stage)) { self::fail('LEDGER_CHANGED'); }
            return ['module_key' => self::MODULE, 'migration_key' => $stage['migration_key'], 'checksum' => $stage['checksum'],
                'pdo_object_id' => spl_object_id($pdo), 'connection' => self::connection($pdo),
                'stageLedger' => $stage['stageLedger'], 'ledger' => $ledger,
                'tables' => $tables, 'parents' => $parents, 'readonly' => true,
                'safe_summary' => ['migration_key' => $stage['migration_key'], 'metadata_sha256' => self::digest($tables),
                    'ledger_sha256' => self::digest($ledger),
                    'data_proof' => 'fixed CREATE of two absent owned tables; shared parents observed without row scans']];
        });
    }

    public static function after(PDO $pdo, array $before, array $nativeParentFacts): array
    {
        self::identity($before);
        $parents = self::parents(PluginLifecycleService::verifiedMigrationParentFacts($pdo, $before['migration_key'], 'after', $nativeParentFacts));
        return self::snapshot($pdo, static function () use ($pdo, $before, $parents): array {
            self::identity($before);
            if (($before['pdo_object_id'] ?? null) !== spl_object_id($pdo)
                || ($before['connection'] ?? null) !== self::connection($pdo)
                || !is_array($before['tables'] ?? null) || !is_array($before['parents'] ?? null)
                || !is_array($before['ledger'] ?? null)
                || array_keys($before['tables']) !== array_keys(self::TABLES)) { self::fail('INPUT_INVALID'); }
            self::sources();
            if ($before['ledger'] !== self::ledger($pdo, $before)) { self::fail('LEDGER_CHANGED'); }
            $tables = self::tables($pdo); $created = 0;
            foreach (self::TABLES as $table => $creator) {
                if ($tables[$table] === null) { self::fail('TABLE_MISSING'); }
                self::schema($pdo, $table, $tables[$table], $creator);
                if ($before['tables'][$table] !== null) { self::fail('CREATE_PRESTATE_INVALID'); }
                $auto = array_filter($tables[$table]['columns'], static fn(array $c): bool => $c['EXTRA'] === 'auto_increment');
                if ($tables[$table]['auto_increment'] !== ($auto === [] ? null : '1')
                    || self::rows($pdo, 'SELECT EXISTS(SELECT 1 FROM ' . self::quote($table) . ') AS present')[0]['present'] !== '0') {
                    self::fail('CREATE_POSTSTATE_INVALID');
                }
                ++$created;
            }
            if ($before['parents'] !== $parents) { self::fail('PARENT_METADATA_CHANGED'); }
            if ($before['ledger'] !== self::ledger($pdo, $before)) { self::fail('LEDGER_CHANGED'); }
            return ['module_key' => self::MODULE, 'migration_key' => $before['migration_key'], 'checksum' => $before['checksum'],
                'readonly' => true, 'preservation_verified' => true,
                'before_sha256' => self::digest([$before['tables'], $before['parents']]),
                'after_sha256' => self::digest([$tables, $parents]),
                'metadata_sha256' => self::digest([$tables, $parents]), 'ledger_sha256' => self::digest($before['ledger']),
                'created_empty_tables' => $created,
                'data_proof' => 'exact fixed-source constraints; new empty tables and real allocators; shared parent metadata retained'];
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

    /** Native aliases carry complete observations; this Module never reads another Module's tables. */
    private static function parents(array $facts): array
    {
        $input = $facts['parents'] ?? null;
        $aliases = ['tenant', 'tenant_member'];
        if (!is_array($input) || array_diff($aliases, array_keys($input)) !== []
            || array_diff(array_keys($input), $aliases) !== []) { self::fail('PARENT_UNKNOWN'); }
        $parents = [];
        $fields = ['metadata', 'ddl', 'auto_increment', 'columns', 'indexes', 'foreign_keys', 'checks', 'triggers'];
        foreach ($aliases as $alias) {
            $image = $input[$alias];
            if (!is_array($image) || array_diff($fields, array_keys($image)) !== []
                || array_diff(array_keys($image), $fields) !== []
                || !is_array($image['metadata']) || ($image['metadata']['TABLE_TYPE'] ?? null) !== 'BASE TABLE'
                || ($image['metadata']['ENGINE'] ?? null) !== 'InnoDB'
                || !is_string($image['ddl']) || $image['ddl'] === ''
                || !is_array($image['columns']) || $image['columns'] === []
                || !is_array($image['indexes']) || $image['indexes'] === []
                || !is_array($image['foreign_keys']) || !is_array($image['checks']) || !is_array($image['triggers'])
                || ($image['auto_increment'] !== null && (!is_string($image['auto_increment'])
                    || preg_match('/\A[1-9][0-9]*\z/D', $image['auto_increment']) !== 1))) { self::fail('PARENT_UNKNOWN'); }
            $parents[$alias] = $image;
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
    private static function fail(string $code): never { throw new RuntimeException('ARTIFACT_REVISION_STAGE_' . $code); }
}
