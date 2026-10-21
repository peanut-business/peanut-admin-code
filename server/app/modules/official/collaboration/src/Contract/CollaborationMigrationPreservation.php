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
    private const REPAIR = 'official.collaboration:20261012120000_create_collaboration_runtime-repair-20261021';
    private const BARRIERS = 'official.collaboration:20261013120100_add_collaboration_business_barriers';
    private const RUNTIME = 'official.collaboration:20261013130000_add_instance_lifecycle';
    private const GRANTS = 'official.collaboration:20261014120000_add_native_collaboration_grants';
    private const SOURCES = [
        self::BASE => '0befec8d041ce121f624417fef9df343907b2686e57c8c0a8dde509d556813ac',
        self::REPAIR => '38d46aa0ee5874a23d96e97cbdc954aac378a085ed41f27f93a5298fe4dea5a1',
        self::BARRIERS => '6cd0d6dab2b08f26371ba97d6887f0e3ae7f7e0b1fcdb3fa3f8a59c54e2f850b',
        self::RUNTIME => 'e876c39e990491ba422ecb9067c8050ed98573b3ee457108de7d8955a084d6bd',
        self::GRANTS => '15a44a7aaa282b9addcc8c7b72cae027197e5f68058244e881ffc48695ac2f18',
    ];
    private const TABLES = ['pa_collaboration_service_nonce', 'pa_collaboration_ticket', 'pa_collaboration_room',
        'pa_collaboration_connection', 'pa_collaboration_admission', 'pa_collaboration_barrier',
        'pa_collaboration_runtime', 'pa_collaboration_grant'];

    /** A real failed-base plan can reject an invalid graph before catalog/ledger writes. */
    public static function repairReadiness(PDO $pdo): array
    {
        return self::snapshot($pdo, static function () use ($pdo): array {
            self::sources(); $tables = self::tables($pdo);
            foreach (self::TABLES as $i => $table) {
                if ($i < 6) {
                    if ($tables[$table] === null) { self::fail('REPAIR_BASE_MISSING'); }
                    self::created($pdo, $table, $tables[$table], self::BASE);
                } elseif ($tables[$table] !== null) { self::fail('REPAIR_FUTURE_PRESENT'); }
            }
            return ['module_key' => 'official.collaboration', 'migration_key' => self::REPAIR,
                'may_apply' => true, 'readonly' => true, 'metadata_sha256' => self::digest($tables),
                'scope' => 'six complete fixed-source empty base schemas and two absent future tables; not accepted-after or lifecycle admission'];
        });
    }

    public static function before(PDO $pdo, array $stage): array
    {
        return self::snapshot($pdo, static function () use ($pdo, $stage): array {
            $key = $stage['migration_key'] ?? null;
            if (($stage['module_key'] ?? null) !== 'official.collaboration' || !isset(self::SOURCES[$key])
                || ($stage['checksum'] ?? null) !== self::SOURCES[$key] || !($stage['stageLedger'] ?? null) instanceof Closure) { self::fail('IDENTITY_INVALID'); }
            self::sources(); $ledger = self::ledger($pdo, $stage); $tables = self::tables($pdo);
            $present = match ($key) { self::BASE => 0, self::REPAIR, self::BARRIERS, self::RUNTIME => 6, self::GRANTS => 7 };
            foreach (self::TABLES as $i => $table) {
                if (($tables[$table] !== null) !== ($i < $present)) { self::fail('PRESTATE_TABLES_INVALID'); }
            }
            if ($key === self::REPAIR) {
                foreach (array_slice(self::TABLES, 0, 6) as $table) { self::created($pdo, $table, $tables[$table], self::BASE); }
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
        $expected = self::createSchema($key, $table, self::connection($pdo)['db']);
        foreach ($expected as $field => $value) {
            if (self::digest($image[$field] ?? null) !== self::digest($value)) { self::fail('CREATE_SCHEMA_INVALID'); }
        }
        if ($image['auto_increment'] !== '1' || $image['triggers'] !== []
            || self::rows($pdo, 'SELECT EXISTS(SELECT 1 FROM `' . $table . '`) AS present')[0]['present'] !== '0') { self::fail('CREATE_POSTSTATE_INVALID'); }
    }

    /** Closed declarations in the four pinned SQL files, not an arbitrary SQL parser. */
    private static function createSchema(string $key, string $table, string $database): array
    {
        if (!in_array($table, self::TABLES, true)
            || preg_match('/CREATE TABLE `' . $table . '` \((.*?)\) ENGINE=InnoDB DEFAULT (?:CHARSET|CHARACTER SET)=utf8mb4 COLLATE=(utf8mb4_(?:unicode_ci|0900_ai_ci));/s', self::source($key), $definition) !== 1) {
            self::fail('CREATE_SOURCE_UNKNOWN');
        }
        $collation = $definition[2];
        $schema = ['metadata' => ['TABLE_TYPE' => 'BASE TABLE', 'ENGINE' => 'InnoDB', 'TABLE_COLLATION' => $collation,
            'ROW_FORMAT' => 'Dynamic', 'CREATE_OPTIONS' => '', 'TABLE_COMMENT' => ''], 'columns' => [], 'indexes' => [], 'foreign_keys' => [], 'checks' => []];
        $indexes = []; $foreign = [];
        foreach (self::declarations($definition[1]) as $line) {
            if (preg_match('/\A`([a-z0-9_]+)` (BIGINT UNSIGNED|INT UNSIGNED|CHAR\([0-9]+\)|VARCHAR\([0-9]+\)|DATETIME\(3\)|JSON|MEDIUMBLOB)(?: CHARACTER SET (ascii))?(?: COLLATE (ascii_bin))? (NOT NULL|NULL)(?: DEFAULT ([0-9]+))?( AUTO_INCREMENT)?\z/D', $line, $m)) {
                $text = str_starts_with($m[2], 'CHAR(') || str_starts_with($m[2], 'VARCHAR(');
                $charset = $text ? (($m[3] ?? '') !== '' ? $m[3] : 'utf8mb4') : null;
                $columnCollation = $text ? (($m[4] ?? '') !== '' ? $m[4] : ($charset === 'ascii' ? 'ascii_general_ci' : $collation)) : null;
                $default = isset($m[6]) && $m[6] !== '' ? $m[6] : null;
                self::append($schema, $m[1], strtolower($m[2]), $m[5] === 'NOT NULL' ? 'NO' : 'YES', $default, $charset, $columnCollation);
                if (($m[7] ?? '') !== '') { $schema['columns'][$m[1]]['EXTRA'] = 'auto_increment'; }
                continue;
            }
            if (preg_match('/\A(PRIMARY KEY|UNIQUE KEY `([a-z0-9_]+)`|KEY `([a-z0-9_]+)`) \(([^)]+)\)\z/D', $line, $m)) {
                $name = $m[1] === 'PRIMARY KEY' ? 'PRIMARY' : (($m[2] ?? '') !== '' ? $m[2] : $m[3]);
                if (isset($indexes[$name])) { self::fail('CREATE_SOURCE_UNKNOWN'); }
                $indexes[$name] = ['unique' => !str_starts_with($m[1], 'KEY '), 'columns' => self::columnNames($m[4])]; continue;
            }
            if (preg_match('/\ACONSTRAINT `([a-z0-9_]+)` FOREIGN KEY \(([^)]+)\) REFERENCES `(pa_[a-z0-9_]+)` \(([^)]+)\)\z/D', $line, $m)) {
                if (isset($foreign[$m[1]])) { self::fail('CREATE_SOURCE_UNKNOWN'); }
                $foreign[$m[1]] = ['columns' => self::columnNames($m[2]), 'table' => $m[3], 'references' => self::columnNames($m[4])]; continue;
            }
            if (preg_match('/\ACONSTRAINT `([a-z0-9_]+)` CHECK \((.*)\)\z/Ds', $line, $m)) {
                foreach ($schema['checks'] as $check) { if ($check['CONSTRAINT_NAME'] === $m[1]) { self::fail('CREATE_SOURCE_UNKNOWN'); } }
                $schema['checks'][] = ['CONSTRAINT_NAME' => $m[1], 'CHECK_CLAUSE' => self::checkTree($m[2]), 'ENFORCED' => 'YES']; continue;
            }
            self::fail('CREATE_SOURCE_UNKNOWN');
        }
        foreach ($foreign as $name => $relation) {
            $supported = false;
            foreach ($indexes as $index) {
                $supported = $supported || array_slice($index['columns'], 0, count($relation['columns'])) === $relation['columns'];
            }
            if (!$supported) { $indexes[$name] = ['unique' => false, 'columns' => $relation['columns']]; }
            if (count($relation['columns']) !== count($relation['references'])) { self::fail('CREATE_SOURCE_UNKNOWN'); }
            foreach ($relation['columns'] as $ordinal => $column) {
                $schema['foreign_keys'][] = ['CONSTRAINT_NAME' => $name, 'ORDINAL_POSITION' => (string) ($ordinal + 1), 'COLUMN_NAME' => $column,
                    'REFERENCED_TABLE_SCHEMA' => $database, 'REFERENCED_TABLE_NAME' => $relation['table'],
                    'REFERENCED_COLUMN_NAME' => $relation['references'][$ordinal], 'UPDATE_RULE' => 'NO ACTION', 'DELETE_RULE' => 'NO ACTION'];
            }
        }
        foreach ($indexes as $name => $index) {
            foreach ($index['columns'] as $ordinal => $column) {
                $schema['indexes'][] = ['INDEX_NAME' => $name, 'NON_UNIQUE' => $index['unique'] ? '0' : '1', 'SEQ_IN_INDEX' => (string) ($ordinal + 1),
                    'COLUMN_NAME' => $column, 'COLLATION' => 'A', 'SUB_PART' => null, 'INDEX_TYPE' => 'BTREE', 'IS_VISIBLE' => 'YES', 'EXPRESSION' => null, 'INDEX_COMMENT' => ''];
            }
        }
        usort($schema['indexes'], static fn(array $a, array $b): int => strcasecmp($a['INDEX_NAME'], $b['INDEX_NAME']) ?: (int) $a['SEQ_IN_INDEX'] <=> (int) $b['SEQ_IN_INDEX']);
        usort($schema['foreign_keys'], static fn(array $a, array $b): int => strcmp($a['CONSTRAINT_NAME'], $b['CONSTRAINT_NAME']) ?: (int) $a['ORDINAL_POSITION'] <=> (int) $b['ORDINAL_POSITION']);
        usort($schema['checks'], static fn(array $a, array $b): int => strcmp($a['CONSTRAINT_NAME'], $b['CONSTRAINT_NAME']));
        return $schema;
    }

    private static function columnNames(string $list): array
    {
        $columns = [];
        foreach (explode(',', $list) as $column) {
            if (preg_match('/\A`([a-z0-9_]+)`\z/D', trim($column), $m) !== 1) { self::fail('CREATE_SOURCE_UNKNOWN'); }
            $columns[] = $m[1];
        }
        if ($columns === [] || count($columns) !== count(array_unique($columns))) { self::fail('CREATE_SOURCE_UNKNOWN'); }
        return $columns;
    }

    private static function declarations(string $body): array
    {
        $items = []; $start = 0; $depth = 0; $quote = null;
        for ($i = 0, $size = strlen($body); $i < $size; ++$i) {
            $char = $body[$i];
            if ($quote !== null) {
                if ($char === '\\') { ++$i; }
                elseif ($char === $quote) {
                    if (($body[$i + 1] ?? null) === $quote) { ++$i; } else { $quote = null; }
                }
            } elseif ($char === "'" || $char === '`') { $quote = $char; }
            elseif ($char === '(') { ++$depth; }
            elseif ($char === ')') { if (--$depth < 0) { self::fail('CREATE_SOURCE_UNKNOWN'); } }
            elseif ($char === ',' && $depth === 0) { $items[] = trim(substr($body, $start, $i - $start)); $start = $i + 1; }
        }
        if ($quote !== null || $depth !== 0) { self::fail('CREATE_SOURCE_UNKNOWN'); }
        $items[] = trim(substr($body, $start));
        if (in_array('', $items, true)) { self::fail('CREATE_SOURCE_UNKNOWN'); }
        return $items;
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
            if (!in_array($token, ['octet_length', 'length'], true)) { self::fail('CHECK_UNKNOWN'); }
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
            $failedBase = $key === self::BASE && !$active && ($row['status'] ?? null) === 'failed';
            if (($row['module_key'] ?? null) !== 'official.collaboration' || ($row['checksum'] ?? null) !== self::SOURCES[$key]
                || ($row['status'] ?? null) !== ($active ? 'applying' : ($failedBase ? 'failed' : 'applied'))
                || ($row['resolution_kind'] ?? null) !== ($active || $failedBase ? null : 'executed') || ($row['provenance_json'] ?? null) !== null
                || ($row['error_code'] ?? null) !== ($failedBase ? 'MODULE_MIGRATION_FAILED' : null)
                || (!$active && !is_string($row['finished_at'] ?? null))) { self::fail('LEDGER_INVALID'); }
            // The native stageLedger reader separately authenticates the declared repair
            // relation and its real applying/applied row. Never rewrite the old failed row.
            if ($failedBase && !in_array(self::REPAIR, array_column($rows, 'migration_key'), true)) { self::fail('LEDGER_INVALID'); }
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
