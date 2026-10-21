<?php

declare(strict_types=1);

namespace PeanutAdmin\Modules\Collaboration\Contract;

use app\common\services\upgrade\MySqlMetadataObservation;
use Closure;
use PDO;
use RuntimeException;
use PeanutAdmin\Modules\Document\Contract\DocumentMigrationPreservation;

/** Four immutable native stages. Never executes SQL, adopts history or restores shared parents. */
final class CollaborationMigrationPreservation
{
    private const BASE = 'official.collaboration:20261012120000_create_collaboration_runtime';
    private const BARRIERS = 'official.collaboration:20261013120100_add_collaboration_business_barriers';
    private const RUNTIME = 'official.collaboration:20261013130000_add_instance_lifecycle';
    private const GRANTS = 'official.collaboration:20261014120000_add_native_collaboration_grants';
    private const SOURCES = [
        self::BASE => '0befec8d041ce121f624417fef9df343907b2686e57c8c0a8dde509d556813ac',
        self::BARRIERS => '6cd0d6dab2b08f26371ba97d6887f0e3ae7f7e0b1fcdb3fa3f8a59c54e2f850b',
        self::RUNTIME => 'e876c39e990491ba422ecb9067c8050ed98573b3ee457108de7d8955a084d6bd',
        self::GRANTS => '15a44a7aaa282b9addcc8c7b72cae027197e5f68058244e881ffc48695ac2f18',
    ];
    private const TABLES = ['pa_collaboration_service_nonce', 'pa_collaboration_ticket', 'pa_collaboration_room',
        'pa_collaboration_connection', 'pa_collaboration_admission', 'pa_collaboration_barrier',
        'pa_collaboration_runtime', 'pa_collaboration_grant'];

    public static function before(PDO $pdo, array $stage): array
    {
        return self::snapshot($pdo, static function () use ($pdo, $stage): array {
            $key = $stage['migration_key'] ?? null;
            if (($stage['module_key'] ?? null) !== 'official.collaboration' || !isset(self::SOURCES[$key])
                || ($stage['checksum'] ?? null) !== self::SOURCES[$key] || !($stage['stageLedger'] ?? null) instanceof Closure) { self::fail('IDENTITY_INVALID'); }
            self::sources(); $ledger = self::ledger($pdo, $stage); $tables = self::tables($pdo);
            $present = match ($key) { self::BASE => 0, self::BARRIERS, self::RUNTIME => 6, self::GRANTS => 7 };
            foreach (self::TABLES as $i => $table) {
                if (($tables[$table] !== null) !== ($i < $present)) { self::fail('PRESTATE_TABLES_INVALID'); }
            }
            if ($key === self::BARRIERS) {
                self::originalColumn($tables['pa_collaboration_barrier'], 'connection_id', 'bigint unsigned');
                self::absentColumns($tables['pa_collaboration_barrier'], ['business_plan_json', 'business_sha256', 'business_result_json', 'released_at']);
            }
            if ($key === self::GRANTS) {
                foreach (['candidate_binary_sha256', 'chain_sha256'] as $column) {
                    self::originalColumn($tables['pa_collaboration_room'], $column, 'char(64)', 'ascii', 'ascii_bin');
                }
                self::originalColumn($tables['pa_collaboration_barrier'], 'target_accepted_seq', 'bigint unsigned');
                self::absentColumns($tables['pa_collaboration_room'], ['protocol_version', 'holder_key', 'last_target_progress']);
                self::absentColumns($tables['pa_collaboration_barrier'], ['drained_receipt_json']);
            }
            if ($ledger !== self::ledger($pdo, $stage)) { self::fail('LEDGER_CHANGED'); }
            return ['module_key' => 'official.collaboration', 'migration_key' => $key, 'checksum' => self::SOURCES[$key],
                'pdo_object_id' => spl_object_id($pdo), 'connection' => self::connection($pdo), 'stageLedger' => $stage['stageLedger'],
                'ledger' => $ledger, 'tables' => $tables, 'safe_summary' => ['migration_key' => $key,
                    'before_sha256' => self::digest($tables), 'ledger_sha256' => self::digest($ledger),
                    'data_proof' => 'native fixed CREATE or nullable/default-only ALTER; no history row scan'], 'readonly' => true];
        });
    }

    public static function after(PDO $pdo, array $before): array
    {
        return self::snapshot($pdo, static function () use ($pdo, $before): array {
            $key = $before['migration_key'] ?? null;
            if (($before['module_key'] ?? null) !== 'official.collaboration' || !isset(self::SOURCES[$key])
                || ($before['checksum'] ?? null) !== self::SOURCES[$key] || ($before['pdo_object_id'] ?? null) !== spl_object_id($pdo)
                || ($before['connection'] ?? null) !== self::connection($pdo) || !is_array($before['tables'] ?? null)
                || !is_array($before['ledger'] ?? null)) { self::fail('INPUT_INVALID'); }
            self::sources();
            if ($before['ledger'] !== self::ledger($pdo, $before)) { self::fail('LEDGER_CHANGED'); }
            $tables = self::tables($pdo); $expected = $before['tables']; $created = [];
            if ($key === self::BASE) { $created = array_slice(self::TABLES, 0, 6); }
            if ($key === self::RUNTIME) { $created = ['pa_collaboration_runtime']; }
            if ($key === self::GRANTS) { $created = ['pa_collaboration_grant']; }
            if ($key === self::BARRIERS) {
                self::relax($expected['pa_collaboration_barrier'], 'connection_id');
                self::append($expected['pa_collaboration_barrier'], 'business_plan_json', 'json');
                self::append($expected['pa_collaboration_barrier'], 'business_sha256', 'char(64)', 'YES', null, 'ascii', 'ascii_bin');
                self::append($expected['pa_collaboration_barrier'], 'business_result_json', 'json');
                self::append($expected['pa_collaboration_barrier'], 'released_at', 'datetime(3)');
            }
            if ($key === self::GRANTS) {
                self::append($expected['pa_collaboration_room'], 'protocol_version', 'int unsigned', 'NO', '1');
                self::append($expected['pa_collaboration_room'], 'holder_key', 'char(36)', 'YES', null, 'ascii', 'ascii_bin');
                self::append($expected['pa_collaboration_room'], 'last_target_progress', 'bigint unsigned', 'NO', '0');
                foreach (['candidate_binary_sha256', 'chain_sha256'] as $column) { self::relax($expected['pa_collaboration_room'], $column); }
                self::append($expected['pa_collaboration_barrier'], 'drained_receipt_json', 'json');
                self::relax($expected['pa_collaboration_barrier'], 'target_accepted_seq');
            }
            foreach ($created as $table) {
                if ($before['tables'][$table] !== null || $tables[$table] === null) { self::fail('CREATE_POSTSTATE_INVALID'); }
                self::created($pdo, $table, $tables[$table], $key);
                $expected[$table] = $tables[$table];
            }
            foreach ($expected as $table => $image) {
                $actual = $tables[$table];
                if ($image === null || $actual === null) {
                    if ($image !== $actual) { self::fail('TABLES_CHANGED'); }
                    continue;
                }
                // Typed metadata allows only the exact additions/nullable delta above.
                $oldDdl = $before['tables'][$table]['ddl'] ?? $image['ddl'];
                $actualDdl = $actual['ddl'];
                foreach (array_diff(array_keys($image['columns']), array_keys($before['tables'][$table]['columns'] ?? $image['columns'])) as $name) {
                    $actualDdl = self::removeColumnLine($actualDdl, $name);
                }
                foreach ($before['tables'][$table]['columns'] ?? [] as $name => $column) {
                    if ($column !== $image['columns'][$name]) {
                        $actualDdl = self::restoreColumnLine($actualDdl, $oldDdl, $name);
                    }
                }
                unset($image['ddl'], $actual['ddl']);
                if ($image !== $actual || self::ddlChecks($actualDdl) !== self::ddlChecks($oldDdl)) { self::fail('SCHEMA_OR_ALLOCATOR_CHANGED'); }
            }
            if ($before['ledger'] !== self::ledger($pdo, $before)) { self::fail('LEDGER_CHANGED'); }
            return ['module_key' => 'official.collaboration', 'migration_key' => $key, 'checksum' => self::SOURCES[$key],
                'readonly' => true, 'preservation_verified' => true, 'before_sha256' => self::digest($before['tables']),
                'after_sha256' => self::digest($tables), 'metadata_sha256' => self::digest($tables), 'ledger_sha256' => self::digest($before['ledger']),
                'created_empty_tables' => count($created),
                'data_proof' => 'immutable native DDL under writer exclusion; old values/allocator/Document parent retained; protocol default1 is not a business upgrade'];
        });
    }

    private static function tables(PDO $pdo): array
    {
        $result = [];
        foreach (self::TABLES as $table) {
            $metadata = self::rows($pdo, 'SELECT TABLE_TYPE,ENGINE,TABLE_COLLATION,ROW_FORMAT,CREATE_OPTIONS,TABLE_COMMENT FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?', [$table]);
            if ($metadata === []) { $result[$table] = null; continue; }
            if (count($metadata) !== 1 || $metadata[0]['TABLE_TYPE'] !== 'BASE TABLE' || $metadata[0]['ENGINE'] !== 'InnoDB') { self::fail('TABLE_UNKNOWN'); }
            $ddl = self::rows($pdo, 'SHOW CREATE TABLE `' . $table . '`');
            if (count($ddl) !== 1 || !is_string($ddl[0]['Create Table'] ?? null)) { self::fail('DDL_UNKNOWN'); }
            $counter = self::rows($pdo, 'SELECT AUTO_INCREMENT FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?', [$table]);
            if (count($counter) !== 1 || preg_match('/\A[1-9][0-9]*\z/D', (string) ($counter[0]['AUTO_INCREMENT'] ?? '')) !== 1) { self::fail('ALLOCATOR_UNKNOWN'); }
            $columns = self::rows($pdo, 'SELECT COLUMN_NAME,ORDINAL_POSITION,COLUMN_TYPE,IS_NULLABLE,COLUMN_DEFAULT,EXTRA,CHARACTER_SET_NAME,COLLATION_NAME,COLUMN_COMMENT,GENERATION_EXPRESSION FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? ORDER BY ORDINAL_POSITION', [$table]);
            foreach ($columns as $column) { if ($column['GENERATION_EXPRESSION'] !== '') { self::fail('GENERATED_COLUMN_UNKNOWN'); } }
            $checks = self::rows($pdo, "SELECT t.CONSTRAINT_NAME,c.CHECK_CLAUSE,t.ENFORCED FROM information_schema.TABLE_CONSTRAINTS t JOIN information_schema.CHECK_CONSTRAINTS c ON c.CONSTRAINT_SCHEMA=t.CONSTRAINT_SCHEMA AND c.CONSTRAINT_NAME=t.CONSTRAINT_NAME WHERE t.TABLE_SCHEMA=DATABASE() AND t.TABLE_NAME=? AND t.CONSTRAINT_TYPE='CHECK' ORDER BY t.CONSTRAINT_NAME", [$table]);
            foreach ($checks as &$check) { if ($check['ENFORCED'] !== 'YES') { self::fail('CHECK_NOT_ENFORCED'); } $check['CHECK_CLAUSE'] = self::checkTree($check['CHECK_CLAUSE']); } unset($check);
            $result[$table] = ['metadata' => $metadata[0], 'ddl' => $ddl[0]['Create Table'], 'auto_increment' => (string) $counter[0]['AUTO_INCREMENT'],
                'columns' => array_column($columns, null, 'COLUMN_NAME'), 'checks' => $checks,
                'indexes' => self::rows($pdo, 'SELECT INDEX_NAME,NON_UNIQUE,SEQ_IN_INDEX,COLUMN_NAME,COLLATION,SUB_PART,INDEX_TYPE,IS_VISIBLE,EXPRESSION,INDEX_COMMENT FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? ORDER BY INDEX_NAME,SEQ_IN_INDEX', [$table]),
                'foreign_keys' => self::rows($pdo, 'SELECT k.CONSTRAINT_NAME,k.ORDINAL_POSITION,k.COLUMN_NAME,k.REFERENCED_TABLE_SCHEMA,k.REFERENCED_TABLE_NAME,k.REFERENCED_COLUMN_NAME,r.UPDATE_RULE,r.DELETE_RULE FROM information_schema.KEY_COLUMN_USAGE k JOIN information_schema.REFERENTIAL_CONSTRAINTS r ON r.CONSTRAINT_SCHEMA=k.CONSTRAINT_SCHEMA AND r.CONSTRAINT_NAME=k.CONSTRAINT_NAME WHERE k.TABLE_SCHEMA=DATABASE() AND k.TABLE_NAME=? ORDER BY k.CONSTRAINT_NAME,k.ORDINAL_POSITION', [$table]),
                'triggers' => self::rows($pdo, 'SELECT TRIGGER_NAME,ACTION_TIMING,EVENT_MANIPULATION,ACTION_STATEMENT,SQL_MODE,DEFINER,CHARACTER_SET_CLIENT,COLLATION_CONNECTION,DATABASE_COLLATION FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA=DATABASE() AND EVENT_OBJECT_TABLE=? ORDER BY TRIGGER_NAME', [$table])];
        }
        $result['document_parent'] = app()->make(DocumentMigrationPreservation::class)->collaborationParentMetadata($pdo);
        return $result;
    }

    private static function originalColumn(array $table, string $name, string $type, ?string $charset = null, ?string $collation = null): void
    {
        $column = $table['columns'][$name] ?? [];
        if ([$column['COLUMN_TYPE'] ?? null, $column['IS_NULLABLE'] ?? null, $column['COLUMN_DEFAULT'] ?? null,
            $column['EXTRA'] ?? null, $column['CHARACTER_SET_NAME'] ?? null, $column['COLLATION_NAME'] ?? null, $column['GENERATION_EXPRESSION'] ?? null]
            !== [$type, 'NO', null, '', $charset, $collation, '']) { self::fail('COLUMN_PRESTATE_INVALID'); }
    }

    private static function absentColumns(array $table, array $names): void
    {
        foreach ($names as $name) { if (isset($table['columns'][$name])) { self::fail('COLUMN_ALREADY_PRESENT'); } }
    }

    private static function relax(array &$table, string $name): void { $table['columns'][$name]['IS_NULLABLE'] = 'YES'; }

    private static function append(array &$table, string $name, string $type, string $nullable = 'YES', ?string $default = null, ?string $charset = null, ?string $collation = null): void
    {
        if (isset($table['columns'][$name])) { self::fail('COLUMN_ALREADY_PRESENT'); }
        $table['columns'][$name] = ['COLUMN_NAME' => $name, 'ORDINAL_POSITION' => (string) (count($table['columns']) + 1),
            'COLUMN_TYPE' => $type, 'IS_NULLABLE' => $nullable, 'COLUMN_DEFAULT' => $default, 'EXTRA' => '',
            'CHARACTER_SET_NAME' => $charset, 'COLLATION_NAME' => $collation, 'COLUMN_COMMENT' => '', 'GENERATION_EXPRESSION' => ''];
    }

    private static function created(PDO $pdo, string $table, array $image, string $key): void
    {
        $sql = self::source($key);
        if (preg_match('/CREATE TABLE `' . $table . '` \((.*?)\) ENGINE=InnoDB[^;]*;/s', $sql, $definition) !== 1) { self::fail('CREATE_SOURCE_UNKNOWN'); }
        preg_match_all('/`([a-z0-9_]+)`\s+(?:BIGINT|INT|CHAR|VARCHAR|DATETIME|JSON|MEDIUMBLOB)\b/i', $definition[1], $columns);
        preg_match_all('/CONSTRAINT `([a-z0-9_]+)` CHECK \((.*)\),?$/m', $definition[1], $checks, PREG_SET_ORDER);
        $expectedChecks = [];
        foreach ($checks as $check) { $expectedChecks[$check[1]] = self::checkTree($check[2]); }
        $actualChecks = [];
        foreach ($image['checks'] as $check) { $actualChecks[$check['CONSTRAINT_NAME']] = $check['CHECK_CLAUSE']; }
        ksort($expectedChecks); ksort($actualChecks);
        if (array_keys($image['columns']) !== $columns[1] || $actualChecks !== $expectedChecks || $image['triggers'] !== []
            || $image['auto_increment'] !== '1'
            || $image['metadata']['TABLE_COLLATION'] !== ($table === 'pa_collaboration_runtime' ? 'utf8mb4_0900_ai_ci' : 'utf8mb4_unicode_ci')
            || self::rows($pdo, 'SELECT EXISTS(SELECT 1 FROM `' . $table . '`) AS present')[0]['present'] !== '0') { self::fail('CREATE_POSTSTATE_INVALID'); }
    }

    private static function removeColumnLine(string $ddl, string $name): string
    {
        $ddl = preg_replace('/^  `' . $name . '` [^\n]*\n/m', '', $ddl, 1, $count);
        if ($count !== 1) { self::fail('DDL_COLUMN_UNKNOWN'); } return $ddl;
    }

    private static function restoreColumnLine(string $ddl, string $old, string $name): string
    {
        $pattern = '/^  `' . $name . '` [^\n]*$/m';
        if (preg_match_all($pattern, $old, $lines) !== 1) { self::fail('DDL_COLUMN_UNKNOWN'); }
        $ddl = preg_replace_callback($pattern, static fn(): string => $lines[0][0], $ddl, 1, $count);
        if ($count !== 1) { self::fail('DDL_COLUMN_UNKNOWN'); } return $ddl;
    }

    private static function ddlChecks(string $ddl): string
    {
        return preg_replace_callback('/^  CONSTRAINT `([a-z0-9_]+)` CHECK (\(.*\))(,?)$/m',
            static fn(array $m): string => '  CONSTRAINT `' . $m[1] . '` CHECK ' . self::canonical(self::checkTree($m[2])) . $m[3], $ddl);
    }

    /** Closed grammar of the thirteen owned CHECKs; preserves literals and boolean precedence. */
    private static function checkTree(string $expression): array
    {
        $tokens = MySqlMetadataObservation::checkTokens($expression);
        if ($tokens === null) { self::fail('CHECK_UNKNOWN'); }
        $at = 0; $or = $and = $term = $value = null;
        $value = static function () use (&$at, $tokens, &$or, &$value): array {
            $token = $tokens[$at++] ?? null;
            if ($token === '(') { $node = $or(); if (($tokens[$at++] ?? null) !== ')') { self::fail('CHECK_UNKNOWN'); } return $node; }
            if (is_string($token) && preg_match('/\A[0-9]+\z/D', $token)) { return ['number', $token]; }
            if (is_string($token) && str_starts_with($token, "'")) { return ['literal', $token]; }
            if (!is_string($token) || preg_match('/\A[a-z_][a-z0-9_]*\z/D', $token) !== 1) { self::fail('CHECK_UNKNOWN'); }
            if (($tokens[$at] ?? null) !== '(') { return ['column', $token]; }
            if ($token !== 'octet_length') { self::fail('CHECK_UNKNOWN'); }
            ++$at; $argument = $value(); if (($tokens[$at++] ?? null) !== ')') { self::fail('CHECK_UNKNOWN'); }
            return ['octet_length', $argument];
        };
        $term = static function () use (&$at, $tokens, &$value): array {
            $left = $value(); $operator = $tokens[$at] ?? null;
            if (in_array($operator, ['=', '>', '<='], true)) { ++$at; return [$operator, $left, $value()]; }
            if ($operator === 'in') {
                $at += 2; if (($tokens[$at - 1] ?? null) !== '(') { self::fail('CHECK_UNKNOWN'); }
                $items = [$value()]; while (($tokens[$at] ?? null) === ',') { ++$at; $items[] = $value(); }
                if (($tokens[$at++] ?? null) !== ')') { self::fail('CHECK_UNKNOWN'); } return ['in', $left, $items];
            }
            return $left;
        };
        $combine = static function (string $operator, callable $next) use (&$at, $tokens): array {
            $nodes = [$next()]; while (($tokens[$at] ?? null) === $operator) { ++$at; $nodes[] = $next(); }
            $flat = []; foreach ($nodes as $node) { if (($node[0] ?? null) === $operator) { array_push($flat, ...array_slice($node, 1)); } else { $flat[] = $node; } }
            return count($flat) === 1 ? $flat[0] : [$operator, ...$flat];
        };
        $and = static fn(): array => $combine('and', $term); $or = static fn(): array => $combine('or', $and);
        $tree = $or(); if ($at !== count($tokens)) { self::fail('CHECK_UNKNOWN'); } return $tree;
    }

    private static function ledger(PDO $pdo, array $stage): array
    {
        if (!($stage['stageLedger'] ?? null) instanceof Closure) { self::fail('LEDGER_READER_REQUIRED'); }
        $rows = ($stage['stageLedger'])($pdo); $current = array_search($stage['migration_key'], array_keys(self::SOURCES), true);
        if (!is_array($rows) || array_column($rows, 'migration_key') !== array_slice(array_keys(self::SOURCES), 0, $current + 1)) { self::fail('LEDGER_INVALID'); }
        foreach ($rows as $row) {
            $key = $row['migration_key']; $active = $key === $stage['migration_key'];
            if (($row['module_key'] ?? null) !== 'official.collaboration' || ($row['checksum'] ?? null) !== self::SOURCES[$key]
                || ($row['status'] ?? null) !== ($active ? 'applying' : 'applied')
                || ($row['resolution_kind'] ?? null) !== ($active ? null : 'executed') || ($row['provenance_json'] ?? null) !== null
                || ($row['error_code'] ?? null) !== null || (!$active && !is_string($row['finished_at'] ?? null))) { self::fail('LEDGER_INVALID'); }
        }
        return $rows;
    }

    private static function sources(): void { foreach (self::SOURCES as $key => $_) { self::source($key); } }
    private static function source(string $key): string
    {
        $file = dirname(__DIR__, 2) . '/database/migrations/' . substr($key, strlen('official.collaboration:')) . '.sql';
        if (!is_file($file) || is_link($file) || hash_file('sha256', $file) !== self::SOURCES[$key]) { self::fail('SOURCE_CHANGED'); }
        return (string) file_get_contents($file);
    }
    private static function connection(PDO $pdo): array { return self::rows($pdo, 'SELECT DATABASE() AS db,@@server_uuid AS server_uuid,CONNECTION_ID() AS connection_id')[0]; }
    private static function rows(PDO $pdo, string $sql, array $arguments = []): array
    {
        $q = $pdo->prepare($sql); $q->execute($arguments);
        try { return array_map(static fn(array $row): array => array_map(static fn($v) => $v === null ? null : (string) $v, $row), $q->fetchAll(PDO::FETCH_ASSOC)); }
        finally { $q->closeCursor(); }
    }
    private static function snapshot(PDO $pdo, Closure $read): array
    {
        return MySqlMetadataObservation::uncached($pdo, static function () use ($pdo, $read): array {
            if ($pdo->inTransaction() || $pdo->getAttribute(PDO::ATTR_CASE) !== PDO::CASE_NATURAL) { self::fail('CONNECTION_INVALID'); }
            $started = false;
            try { $pdo->exec('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ, READ ONLY'); $pdo->beginTransaction(); $started = true;
                $result = $read(); if (!$pdo->inTransaction()) { self::fail('SNAPSHOT_CHANGED'); } return $result;
            } finally { if ($started && $pdo->inTransaction()) { $pdo->rollBack(); } }
        });
    }
    private static function canonical(mixed $value): string
    {
        $sort = static function (mixed $v) use (&$sort): mixed { if (!is_array($v)) { return $v; } if (!array_is_list($v)) { ksort($v, SORT_STRING); } foreach ($v as &$item) { $item = $sort($item); } return $v; };
        return json_encode($sort($value), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
    private static function digest(mixed $value): string { return hash('sha256', self::canonical($value)); }
    private static function fail(string $code): never { throw new RuntimeException('COLLABORATION_STAGE_' . $code); }
}
