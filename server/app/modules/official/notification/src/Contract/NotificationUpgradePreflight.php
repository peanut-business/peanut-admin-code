<?php

declare(strict_types=1);

namespace PeanutAdmin\Modules\Notification\Contract;

use app\common\execution\CurrentExecutionContext;
use app\common\execution\InstanceExecutionContext;
use app\common\execution\InstallationExecutionContext;
use PDO;
use think\facade\Db;

/** Old-schema, read-only admission for the trusted instance maintenance host. */
final readonly class NotificationUpgradePreflight
{
    public const MIGRATION_KEY = 'official.notification:20261012-notification-producer-identity';

    public function __construct(private CurrentExecutionContext $contexts) {}

    public function report(): array
    {
        $unknown = [
            'schema_version' => 1, 'module_key' => 'official.notification',
            'status' => 'unknown', 'pending_count' => null, 'may_apply' => false,
            'code' => 'NOTIFICATION_UPGRADE_STATE_UNKNOWN',
        ];
        $pdo = null;
        $started = false;
        $phase = 'execution_context';
        try {
            $context = $this->contexts->current();
            if (!$context instanceof InstanceExecutionContext && !$context instanceof InstallationExecutionContext) {
                return $unknown + ['reason' => 'EXECUTION_CONTEXT_UNAVAILABLE', 'phase' => $phase];
            }
            $phase = 'connection';
            $connection = Db::connect();
            // getPdo() observes the current link; a native query initializes a lazy connection.
            if ($connection->getPdo() === false) {
                $connection->query('SELECT 1', [], true);
            }
            $pdo = $connection->getPdo();
            if (!$pdo instanceof PDO) {
                return $unknown + ['reason' => 'CONNECTION_UNAVAILABLE', 'phase' => $phase];
            }
            if ($pdo->inTransaction()) {
                return $unknown + ['reason' => 'TRANSACTION_ALREADY_ACTIVE', 'phase' => $phase];
            }
            $phase = 'transaction_read_only';
            $pdo->exec('SET TRANSACTION READ ONLY');
            $phase = 'transaction_begin';
            $pdo->beginTransaction();
            $started = true;
            $phase = 'outbox_schema';
            $table = Db::name('notification_outbox')->getTable();
            $exists = Db::query('SELECT COUNT(*) AS n FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?', [$table]);
            if ((int) ($exists[0]['n'] ?? 0) !== 1) {
                return $unknown + ['reason' => 'OUTBOX_SCHEMA_UNAVAILABLE', 'phase' => $phase];
            }
            $phase = 'pending_count';
            $pending = (int) Db::name('notification_outbox')->whereIn('status', ['pending', 'queued', 'processing', 'retryable'])->count();
            $phase = 'status_validation';
            $invalid = (int) Db::name('notification_outbox')->whereNotIn('status', ['pending', 'queued', 'processing', 'retryable', 'delivered', 'permanent_failed'])->count();
            if ($invalid !== 0) {
                return $unknown + ['reason' => 'OUTBOX_STATUS_UNRECOGNIZED', 'phase' => $phase];
            }
            return [
                'schema_version' => 1, 'module_key' => 'official.notification',
                'status' => $pending === 0 ? 'ready' : 'blocked',
                'pending_count' => $pending, 'may_apply' => $pending === 0,
                'code' => $pending === 0 ? null : 'NOTIFICATION_LEGACY_DELIVERY_PENDING',
                'runtime_safe_window_required' => true,
            ];
        } catch (\Throwable) {
            return $unknown + ['reason' => 'PREFLIGHT_OPERATION_FAILED', 'phase' => $phase];
        } finally {
            if ($started && $pdo instanceof PDO && $pdo->inTransaction()) { $pdo->rollBack(); }
        }
    }

    public const ADOPTION_KEY = 'official.notification:20260921-adopt-notification-delivery-schema';
    private const SQL_SHA256 = [
        'official.notification:20260826-namespace-permission-keys' => '8be087e7adacb8d34f2fa61111e683a7b15af4b62d93910d3a9655f1a605f606',
        self::ADOPTION_KEY => 'eaef582cd762c803f3f7d213687f61d43fd3634e9f0b984471f11648e10979fb',
        self::MIGRATION_KEY => '7c01bc1bf1f309e879ff3014f89418157abbce45374a58064d1a6dc94fca07ce',
    ];

    /**
     * Structural admission only; no maintenance window, backup or empty-queue attestation.
     * @param \Closure(PDO, 'ledger'|'parent_keys'|'catalog_schema'):array $platformPreflight Fixed platform prerequisites inside this snapshot.
     */
    public function forwardSchemaReport(\Closure $platformPreflight): array
    {
        $pdo = null; $started = false; $phase = 'context';
        try {
            $context = $this->contexts->current();
            if (!$context instanceof InstanceExecutionContext && !$context instanceof InstallationExecutionContext) {
                throw new \RuntimeException('NOTIFICATION_FORWARD_CONTEXT_UNKNOWN');
            }
            $connection = Db::connect();
            $config = $connection->getConfig();
            if (($config['type'] ?? null) !== 'mysql' || ($config['prefix'] ?? null) !== 'pa_'
                || !empty($config['deploy']) || !empty($config['rw_separate']) || !empty($config['break_reconnect'])) {
                throw new \RuntimeException('NOTIFICATION_FORWARD_CONNECTION_UNSUPPORTED');
            }
            if ($connection->getPdo() === false) { $connection->query('SELECT 1', [], true); }
            $pdo = $connection->getPdo();
            if (!$pdo instanceof PDO || $pdo->inTransaction() || $pdo->getAttribute(PDO::ATTR_CASE) !== PDO::CASE_NATURAL) {
                throw new \RuntimeException('NOTIFICATION_FORWARD_SNAPSHOT_UNAVAILABLE');
            }
            $pdo->exec('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ, READ ONLY');
            $pdo->beginTransaction(); $started = true;
            $phase = 'source_and_ledger';
            foreach (self::SQL_SHA256 as $key => $sha) {
                $file = dirname(__DIR__, 2) . '/database/migrations/' . substr($key, strlen('official.notification:')) . '.sql';
                if (!is_file($file) || is_link($file) || !hash_equals($sha, (string) hash_file('sha256', $file))) {
                    throw new \RuntimeException('NOTIFICATION_FORWARD_SOURCE_CHANGED');
                }
            }
            $ledger = $platformPreflight($pdo, 'ledger');
            $rows = array_column($ledger, null, 'migration_key');
            foreach ($rows as $key => $row) {
                if (!isset(self::SQL_SHA256[$key]) || $row['checksum'] !== self::SQL_SHA256[$key] || $row['status'] !== 'applied') {
                    throw new \RuntimeException('NOTIFICATION_FORWARD_LEDGER_UNRESOLVED');
                }
            }
            if (!isset($rows['official.notification:20260826-namespace-permission-keys'])) {
                throw new \RuntimeException('NOTIFICATION_FORWARD_NAMESPACE_UNVERIFIED');
            }
            $adopted = isset($rows[self::ADOPTION_KEY]); $produced = isset($rows[self::MIGRATION_KEY]);
            if ($produced && !$adopted) { throw new \RuntimeException('NOTIFICATION_FORWARD_LEDGER_ORDER_INVALID'); }
            $phase = 'complete_schema';
            $expected = self::forwardSchemas($pdo, $produced);
            $observed = [];
            foreach ($expected as $table => $schema) {
                $observed[$table] = self::tableSchema($pdo, $table);
                if (!$adopted && $observed[$table] !== null) {
                    throw new \RuntimeException('NOTIFICATION_FORWARD_PARTIAL_SCHEMA');
                }
                if ($adopted && self::canonical($observed[$table]) !== self::canonical($schema)) {
                    throw new \RuntimeException('NOTIFICATION_FORWARD_SCHEMA_MISMATCH');
                }
            }
            if (!$produced && self::tableSchema($pdo, 'pa_notification_task_actor') !== null) {
                throw new \RuntimeException('NOTIFICATION_FORWARD_PRODUCER_COLLISION');
            }
            $platformPreflight($pdo, 'parent_keys');
            if (!$adopted) {
                // FK and CHECK names are schema-scoped, including constraints on unrelated tables.
                foreach (self::forwardSchemas($pdo, true) as $schema) {
                    foreach (array_keys($schema['foreign_keys'] + $schema['checks']) as $name) {
                        if (self::readRows($pdo, 'SELECT CONSTRAINT_NAME FROM information_schema.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA=DATABASE() AND CONSTRAINT_NAME=?', [$name]) !== []) {
                            throw new \RuntimeException('NOTIFICATION_FORWARD_CONSTRAINT_COLLISION');
                        }
                    }
                }
            }
            $phase = 'legacy_preservation';
            $legacy = [];
            foreach (['pa_notice_log', 'pa_notice_scene', 'pa_notice_template'] as $table) {
                $schema = self::tableSchema($pdo, $table, false);
                if ($schema === null || $schema['engine'] !== 'InnoDB' || !isset($schema['columns']['id'])) {
                    throw new \RuntimeException('NOTIFICATION_FORWARD_LEGACY_SCHEMA_UNKNOWN');
                }
                $statement = $pdo->query('SELECT * FROM `' . $table . '` ORDER BY `id`');
                $hash = hash_init('sha256'); $count = 0;
                while ($row = $statement->fetch(PDO::FETCH_ASSOC)) { hash_update($hash, self::canonical($row) . "\n"); ++$count; }
                $legacy[$table] = ['schema_sha256' => hash('sha256', self::canonical($schema)), 'rows' => $count, 'rows_sha256' => hash_final($hash)];
            }
            if ($connection->getPdo() !== $pdo || !$pdo->inTransaction()) {
                throw new \RuntimeException('NOTIFICATION_FORWARD_SNAPSHOT_CHANGED');
            }
            return ['module_key' => 'official.notification', 'status' => $produced ? 'producer_complete' : ($adopted ? 'producer_pending' : 'adoption_required'),
                'may_apply' => true, 'structural_admission_only' => true, 'ledger' => $ledger,
                'schema_sha256' => hash('sha256', self::canonical($observed)), 'legacy' => $legacy,
                'producer_preflight' => ['status' => 'not_evaluated', 'may_apply' => false, 'pending_count' => null],
                'external_writer_exclusion' => 'not_attested', 'backup_admission' => 'not_attested', 'catalog_schema' => $platformPreflight($pdo, 'catalog_schema')];
        } catch (\Throwable) {
            return ['module_key' => 'official.notification', 'status' => 'unknown', 'may_apply' => false,
                'code' => 'NOTIFICATION_FORWARD_STATE_UNKNOWN', 'phase' => $phase,
                'structural_admission_only' => true, 'producer_preflight' => ['status' => 'not_evaluated', 'may_apply' => false, 'pending_count' => null]];
        } finally {
            if ($started && $pdo instanceof PDO && $pdo->inTransaction()) { $pdo->rollBack(); }
        }
    }

    /** Exact owned schema contract derived only from the two immutable, checksum-checked SQL files. */
    private static function forwardSchemas(PDO $pdo, bool $producer): array
    {
        $database = self::readRows($pdo, 'SELECT DEFAULT_CHARACTER_SET_NAME,DEFAULT_COLLATION_NAME FROM information_schema.SCHEMATA WHERE SCHEMA_NAME=DATABASE()');
        if (count($database) !== 1) { throw new \RuntimeException('NOTIFICATION_FORWARD_DATABASE_UNKNOWN'); }
        $base = dirname(__DIR__, 2) . '/database/migrations/';
        $sql = (string) file_get_contents($base . '20260921-adopt-notification-delivery-schema.sql');
        if ($producer) { $sql .= "\n" . file_get_contents($base . '20261012-notification-producer-identity.sql'); }
        preg_match_all('/CREATE TABLE IF NOT EXISTS `(pa_[a-z_]+)` \((.*?)\) ENGINE=InnoDB(?: DEFAULT CHARSET=([a-z0-9]+) COLLATE=([a-z0-9_]+))?;/s', $sql, $tables, PREG_SET_ORDER);
        $schemas = [];
        foreach ($tables as $definition) {
            $charset = $definition[3] ?? $database[0]['DEFAULT_CHARACTER_SET_NAME'];
            $collation = $definition[4] ?? $database[0]['DEFAULT_COLLATION_NAME'];
            $schema = ['engine' => 'InnoDB', 'collation' => $collation, 'columns' => [], 'indexes' => [], 'foreign_keys' => [], 'checks' => []];
            foreach (explode("\n", trim($definition[2])) as $line) { self::schemaLine($schema, rtrim(trim($line), ','), $charset, $collation); }
            $schemas[$definition[1]] = $schema;
        }
        if (count($schemas) !== ($producer ? 7 : 6)) { throw new \RuntimeException('NOTIFICATION_FORWARD_SQL_CONTRACT_UNKNOWN'); }
        if ($producer) {
            if (!preg_match('/ALTER TABLE `pa_notification_message`\s+(.*?);/s', $sql, $alter)) { throw new \RuntimeException('NOTIFICATION_FORWARD_SQL_CONTRACT_UNKNOWN'); }
            $schema = &$schemas['pa_notification_message'];
            foreach (explode("\n", trim($alter[1])) as $line) {
                $line = preg_replace('/^ADD COLUMN /', '', trim($line));
                $line = preg_replace('/^ADD UNIQUE KEY /', 'UNIQUE KEY ', $line);
                self::schemaLine($schema, rtrim($line, ','), $database[0]['DEFAULT_CHARACTER_SET_NAME'], $database[0]['DEFAULT_COLLATION_NAME']);
            }
            unset($schema);
        }
        foreach ($schemas as &$schema) {
            foreach ($schema['foreign_keys'] as $name => $foreign) {
                $supported = false;
                foreach ($schema['indexes'] as $index) {
                    if (array_slice($index['columns'], 0, count($foreign['columns'])) === $foreign['columns']) { $supported = true; }
                }
                if (!$supported) { $schema['indexes'][$name] = ['unique' => false, 'columns' => $foreign['columns']]; }
            }
        }
        unset($schema);
        return $schemas;
    }

    private static function schemaLine(array &$schema, string $line, string $charset, string $collation): void
    {
        if (preg_match('/^`([a-z_]+)` ([A-Z]+(?:\([0-9,]+\))?(?: UNSIGNED)?)(.*)$/', $line, $m)) {
            $text = preg_match('/^(?:VARCHAR|CHAR|TEXT)/', $m[2]) === 1;
            preg_match('/CHARACTER SET ([a-z0-9]+)/', $m[3], $cs); preg_match('/COLLATE ([a-z0-9_]+)/', $m[3], $co);
            preg_match('/DEFAULT (?:\x27([^\x27]*)\x27|([0-9]+))/', $m[3], $default);
            $schema['columns'][$m[1]] = ['ordinal' => count($schema['columns']) + 1, 'type' => strtolower($m[2]), 'nullable' => !str_contains($m[3], 'NOT NULL'),
                'default' => isset($default[1]) && $default[1] !== '' ? $default[1] : ($default[2] ?? null),
                'extra' => str_contains($m[3], 'AUTO_INCREMENT') ? 'auto_increment' : '',
                'charset' => $text ? ($cs[1] ?? $charset) : null, 'collation' => $text ? ($co[1] ?? $collation) : null];
            return;
        }
        if (preg_match('/^(PRIMARY KEY|UNIQUE KEY `([a-z_]+)`|KEY `([a-z_]+)`) \(([^)]+)\)$/', $line, $m)) {
            $name = $m[1] === 'PRIMARY KEY' ? 'PRIMARY' : (($m[2] ?? '') !== '' ? $m[2] : $m[3]);
            $schema['indexes'][$name] = ['unique' => !str_starts_with($m[1], 'KEY '), 'columns' => self::columnNames($m[4])]; return;
        }
        if (preg_match('/^CONSTRAINT `([a-z_]+)` FOREIGN KEY \(([^)]+)\) REFERENCES `(pa_[a-z_]+)` \(([^)]+)\) ON DELETE RESTRICT$/', $line, $m)) {
            $schema['foreign_keys'][$m[1]] = ['columns' => self::columnNames($m[2]), 'table' => $m[3], 'references' => self::columnNames($m[4]), 'delete' => 'RESTRICT', 'update' => 'NO ACTION']; return;
        }
        if (preg_match('/^CONSTRAINT `([a-z_]+)` CHECK \((.*)\)$/', $line, $m)) {
            $schema['checks'][$m[1]] = self::expression($m[2]); return;
        }
        throw new \RuntimeException('NOTIFICATION_FORWARD_SQL_CONTRACT_UNKNOWN');
    }

    private static function columnNames(string $list): array
    {
        $result = [];
        foreach (explode(',', $list) as $column) {
            if (!preg_match('/^`([a-z_]+)`$/D', trim($column), $m)) { throw new \RuntimeException('NOTIFICATION_FORWARD_INDEX_UNKNOWN'); }
            $result[] = $m[1];
        }
        return $result;
    }

    private static function tableSchema(PDO $pdo, string $table, bool $owned = true): ?array
    {
        $tables = self::readRows($pdo, 'SELECT TABLE_TYPE,ENGINE,TABLE_COLLATION FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?', [$table]);
        if ($tables === []) { return null; }
        if (count($tables) !== 1 || $tables[0]['TABLE_TYPE'] !== 'BASE TABLE') { throw new \RuntimeException('NOTIFICATION_FORWARD_TABLE_UNKNOWN'); }
        $schema = ['engine' => $tables[0]['ENGINE'], 'collation' => $tables[0]['TABLE_COLLATION'], 'columns' => [], 'indexes' => [], 'foreign_keys' => [], 'checks' => []];
        foreach (self::readRows($pdo, 'SELECT COLUMN_NAME,ORDINAL_POSITION,COLUMN_TYPE,IS_NULLABLE,COLUMN_DEFAULT,EXTRA,CHARACTER_SET_NAME,COLLATION_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? ORDER BY ORDINAL_POSITION', [$table]) as $row) {
            $schema['columns'][$row['COLUMN_NAME']] = ['ordinal' => (int) $row['ORDINAL_POSITION'], 'type' => strtolower($row['COLUMN_TYPE']), 'nullable' => $row['IS_NULLABLE'] === 'YES', 'default' => $row['COLUMN_DEFAULT'], 'extra' => $row['EXTRA'], 'charset' => $row['CHARACTER_SET_NAME'], 'collation' => $row['COLLATION_NAME']];
        }
        foreach (self::readRows($pdo, 'SELECT INDEX_NAME,NON_UNIQUE,SEQ_IN_INDEX,COLUMN_NAME,SUB_PART,INDEX_TYPE,IS_VISIBLE,EXPRESSION FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? ORDER BY INDEX_NAME,SEQ_IN_INDEX', [$table]) as $row) {
            if ($row['SUB_PART'] !== null || $row['INDEX_TYPE'] !== 'BTREE' || $row['IS_VISIBLE'] !== 'YES' || $row['EXPRESSION'] !== null) { throw new \RuntimeException('NOTIFICATION_FORWARD_INDEX_UNKNOWN'); }
            $schema['indexes'][$row['INDEX_NAME']]['unique'] = (int) $row['NON_UNIQUE'] === 0;
            $schema['indexes'][$row['INDEX_NAME']]['columns'][] = $row['COLUMN_NAME'];
        }
        foreach (self::readRows($pdo, 'SELECT k.CONSTRAINT_NAME,k.COLUMN_NAME,k.REFERENCED_TABLE_SCHEMA,k.REFERENCED_TABLE_NAME,k.REFERENCED_COLUMN_NAME,r.UPDATE_RULE,r.DELETE_RULE FROM information_schema.KEY_COLUMN_USAGE k JOIN information_schema.REFERENTIAL_CONSTRAINTS r ON r.CONSTRAINT_SCHEMA=k.CONSTRAINT_SCHEMA AND r.CONSTRAINT_NAME=k.CONSTRAINT_NAME AND r.TABLE_NAME=k.TABLE_NAME WHERE k.TABLE_SCHEMA=DATABASE() AND k.TABLE_NAME=? AND k.REFERENCED_TABLE_NAME IS NOT NULL ORDER BY k.CONSTRAINT_NAME,k.ORDINAL_POSITION', [$table]) as $row) {
            $database = self::readRows($pdo, 'SELECT DATABASE() AS name')[0]['name'];
            if ($row['REFERENCED_TABLE_SCHEMA'] !== $database) { throw new \RuntimeException('NOTIFICATION_FORWARD_FOREIGN_SCHEMA'); }
            $foreign = &$schema['foreign_keys'][$row['CONSTRAINT_NAME']];
            $foreign['columns'][] = $row['COLUMN_NAME']; $foreign['table'] = $row['REFERENCED_TABLE_NAME']; $foreign['references'][] = $row['REFERENCED_COLUMN_NAME'];
            $foreign['delete'] = $row['DELETE_RULE']; $foreign['update'] = $row['UPDATE_RULE']; unset($foreign);
        }
        foreach (self::readRows($pdo, "SELECT t.CONSTRAINT_NAME,c.CHECK_CLAUSE,t.ENFORCED FROM information_schema.TABLE_CONSTRAINTS t JOIN information_schema.CHECK_CONSTRAINTS c ON c.CONSTRAINT_SCHEMA=t.CONSTRAINT_SCHEMA AND c.CONSTRAINT_NAME=t.CONSTRAINT_NAME WHERE t.TABLE_SCHEMA=DATABASE() AND t.TABLE_NAME=? AND t.CONSTRAINT_TYPE='CHECK'", [$table]) as $row) {
            if ($row['ENFORCED'] !== 'YES') { throw new \RuntimeException('NOTIFICATION_FORWARD_CHECK_UNENFORCED'); }
            $schema['checks'][$row['CONSTRAINT_NAME']] = $owned ? self::expression($row['CHECK_CLAUSE']) : $row['CHECK_CLAUSE'];
        }
        if (self::readRows($pdo, 'SELECT TRIGGER_NAME FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA=DATABASE() AND EVENT_OBJECT_TABLE=?', [$table]) !== []) { throw new \RuntimeException('NOTIFICATION_FORWARD_TRIGGER_UNKNOWN'); }
        return $schema;
    }

    private static function readRows(PDO $pdo, string $sql, array $parameters = []): array
    {
        $statement = $pdo->prepare($sql); $statement->execute($parameters); return $statement->fetchAll(PDO::FETCH_ASSOC);
    }

    private static function canonical(mixed $value): string
    {
        $sort = static function (mixed $item) use (&$sort): mixed {
            if (!is_array($item)) { return $item; }
            if (!array_is_list($item)) { ksort($item, SORT_STRING); }
            foreach ($item as &$child) { $child = $sort($child); } unset($child); return $item;
        };
        return json_encode($sort($value), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    private static function expression(string $sql): array
    {
        $tokens = []; $offset = 0;
        while ($offset < strlen($sql)) {
            if (preg_match('/\G\s+/A', $sql, $m, 0, $offset)) { $offset += strlen($m[0]); continue; }
            if (!preg_match('/\G(?:_[a-zA-Z0-9]+(?=\x27)|`[^`]+`|\x27(?:\x27\x27|[^\x27])*\x27|[a-zA-Z_][a-zA-Z0-9_]*|[0-9]+|<>|!=|>=|<=|[(),*=<>])/A', $sql, $m, 0, $offset)) { throw new \RuntimeException('CHECK_SYNTAX_UNKNOWN'); }
            $offset += strlen($m[0]); $token = $m[0];
            if ($token[0] === '_' && $offset < strlen($sql) && $sql[$offset] === "'") { continue; }
            $tokens[] = $token[0] === "'" ? $token : strtolower(trim($token, '`'));
        }
        $at = 0;
        $parse = static function (int $minimum = 0) use (&$parse, &$at, $tokens): array {
            $token = $tokens[$at++] ?? null;
            if ($token === '(') {
                $left = $parse(); if (($tokens[$at++] ?? null) !== ')') { throw new \RuntimeException('CHECK_GROUP_UNKNOWN'); }
            } elseif ($token === 'regexp_like') {
                if (($tokens[$at++] ?? null) !== '(') { throw new \RuntimeException('CHECK_FUNCTION_UNKNOWN'); }
                $one = $parse(); if (($tokens[$at++] ?? null) !== ',') { throw new \RuntimeException('CHECK_FUNCTION_UNKNOWN'); }
                $two = $parse(); if (($tokens[$at++] ?? null) !== ')') { throw new \RuntimeException('CHECK_FUNCTION_UNKNOWN'); }
                $left = ['regexp', $one, $two];
            } elseif ($token !== null && preg_match('/^(?:[a-z_][a-z0-9_]*|[0-9]+|\x27.*\x27)$/sD', $token)) {
                $left = ['value', $token];
            } else { throw new \RuntimeException('CHECK_VALUE_UNKNOWN'); }
            $precedence = ['or' => 1, 'and' => 2, '=' => 3, '<>' => 3, '!=' => 3, '<' => 3, '>' => 3, '>=' => 3, '<=' => 3, 'is' => 3, 'in' => 3, 'between' => 3, 'regexp' => 3, '*' => 4];
            while (isset($tokens[$at], $precedence[$tokens[$at]]) && $precedence[$tokens[$at]] >= $minimum) {
                $operator = $tokens[$at++]; $level = $precedence[$operator];
                if ($operator === 'between') {
                    $low = $parse(4); if (($tokens[$at++] ?? null) !== 'and') { throw new \RuntimeException('CHECK_BETWEEN_UNKNOWN'); }
                    $left = ['between', $left, $low, $parse(4)];
                } elseif ($operator === 'in') {
                    if (($tokens[$at++] ?? null) !== '(') { throw new \RuntimeException('CHECK_LIST_UNKNOWN'); }
                    $list = []; do { $list[] = $parse(4); $end = $tokens[$at++] ?? null; } while ($end === ',');
                    if ($end !== ')') { throw new \RuntimeException('CHECK_LIST_UNKNOWN'); }
                    $left = ['in', $left, $list];
                } elseif ($operator === 'is') {
                    $not = ($tokens[$at] ?? null) === 'not'; if ($not) { ++$at; }
                    if (($tokens[$at++] ?? null) !== 'null') { throw new \RuntimeException('CHECK_NULL_UNKNOWN'); }
                    $left = [$not ? 'is_not_null' : 'is_null', $left];
                } else { $left = [$operator === '!=' ? '<>' : $operator, $left, $parse($level + 1)]; }
            }
            return $left;
        };
        $tree = $parse(); if ($at !== count($tokens)) { throw new \RuntimeException('CHECK_TRAILING_UNKNOWN'); } return $tree;
    }

    public function assertMayApply(): void
    {
        $report = $this->report();
        if (!$report['may_apply']) { throw new \RuntimeException($report['code']); }
    }
}
