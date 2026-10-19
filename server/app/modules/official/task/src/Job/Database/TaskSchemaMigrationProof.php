<?php

declare(strict_types=1);

namespace PeanutAdmin\Modules\Task\Job\Database;

use Closure;
use PDO;
use RuntimeException;
use Throwable;

/** The two immutable Task DDL stages. No task payload snapshots, SQL execution or recovery writes. */
final class TaskSchemaMigrationProof
{
    private const ADOPT = 'official.task:20260921-adopt-task-job-schema';
    private const PROFILE = 'official.task:20261015020000_add_negative_fact_job_profile';
    private const SOURCES = [
        self::ADOPT => 'f04762aeab007bea0b0c4ce277d8f7781a694b17891f1193f303d0481366f21a',
        self::PROFILE => '2217e8a0cfc44e2c4c6c1a76a0413ed2448c5695e32f2a31a6a2a3072f44dbd4',
    ];
    private const TABLES = ['pa_task_job', 'pa_task_job_attempt', 'pa_task_job_event'];
    private const PROFILE_CHECK = "(authorization_profile='member' AND created_by_member_id IS NOT NULL AND producer_fact_key IS NULL) OR (authorization_profile='identity_negative_fact' AND created_by_member_id IS NULL AND producer_fact_key IS NOT NULL AND task_type='identity.revocation.propagate' AND handler_key='identity.revocation.propagate')";

    public static function before(PDO $pdo, array $exactMigration): array
    {
        return self::snapshot($pdo, static function () use ($pdo, $exactMigration): array {
            $key = $exactMigration['migration_key'] ?? null;
            if (($exactMigration['module_key'] ?? null) !== 'official.task' || !isset(self::SOURCES[$key])
                || ($exactMigration['checksum'] ?? null) !== self::SOURCES[$key]
                || !($exactMigration['stageLedger'] ?? null) instanceof Closure) { self::fail('IDENTITY_INVALID'); }
            self::source($key);
            $ledger = self::ledger($pdo, $exactMigration);
            $tables = self::tables($pdo);
            if ($key === self::PROFILE) { self::profilePrestate($pdo, $tables); }
            if ($ledger !== self::ledger($pdo, $exactMigration)) { self::fail('LEDGER_CHANGED'); }
            return ['module_key' => 'official.task', 'migration_key' => $key, 'checksum' => self::SOURCES[$key],
                'pdo_object_id' => spl_object_id($pdo), 'connection' => self::connection($pdo),
                'stageLedger' => $exactMigration['stageLedger'], 'ledger' => $ledger, 'tables' => $tables,
                'safe_summary' => ['migration_key' => $key, 'checksum' => self::SOURCES[$key],
                    'metadata_sha256' => hash('sha256', serialize($tables)), 'ledger_sha256' => hash('sha256', serialize($ledger)),
                    'data_proof' => 'native DDL; only constraint-dependent EXISTS when metadata is insufficient'], 'readonly' => true];
        });
    }

    public static function after(PDO $pdo, array $before): array
    {
        return self::snapshot($pdo, static function () use ($pdo, $before): array {
            $key = $before['migration_key'] ?? null;
            if (($before['module_key'] ?? null) !== 'official.task' || !isset(self::SOURCES[$key])
                || ($before['checksum'] ?? null) !== self::SOURCES[$key]
                || ($before['pdo_object_id'] ?? null) !== spl_object_id($pdo)
                || ($before['connection'] ?? null) !== self::connection($pdo)
                || !is_array($before['ledger'] ?? null) || !is_array($before['tables'] ?? null)) { self::fail('INPUT_INVALID'); }
            self::source($key);
            if ($before['ledger'] !== self::ledger($pdo, $before)) { self::fail('LEDGER_CHANGED'); }
            $after = self::tables($pdo); $created = 0;
            if ($key === self::ADOPT) {
                foreach (self::TABLES as $table) {
                    if ($before['tables'][$table] !== null) {
                        // IF NOT EXISTS is a native no-op for the existing table, not an adoption of its history.
                        if ($before['tables'][$table] !== $after[$table]) { self::fail('EXISTING_METADATA_CHANGED'); }
                    } else {
                        if ($after[$table] === null || (int) self::query($pdo, 'SELECT EXISTS(SELECT 1 FROM `' . $table . '`) AS present')[0]['present'] !== 0) {
                            self::fail('CREATE_POSTSTATE_INVALID');
                        }
                        // Source checksum, actual before-absence, held exclusion and native CREATE establish its
                        // declared shape. Record metadata; do not build a second CREATE parser or SQL simulator.
                        ++$created;
                    }
                }
            } else {
                self::profilePoststate($before['tables'], $after, $pdo);
            }
            if ($before['ledger'] !== self::ledger($pdo, $before)) { self::fail('LEDGER_CHANGED'); }
            return ['module_key' => 'official.task', 'migration_key' => $key, 'checksum' => self::SOURCES[$key],
                'preservation_verified' => true, 'metadata_sha256' => hash('sha256', serialize($after)),
                'ledger_sha256' => hash('sha256', serialize($before['ledger'])), 'created_empty_tables' => $created,
                'data_proof' => 'exact native DDL and target conversion preconditions; no payload/history row scan', 'readonly' => true];
        });
    }

    private static function profilePrestate(PDO $pdo, array $tables): void
    {
        foreach (self::TABLES as $table) { if ($tables[$table] === null) { self::fail('PROFILE_PRESTATE_INVALID'); } }
        $job = $tables['pa_task_job']; $event = $tables['pa_task_job_event'];
        $actor = $job['columns']['created_by_member_id'] ?? [];
        // No numeric/domain conversion is admitted. Relaxing NOT NULL on this exact existing type preserves values.
        if (($actor['COLUMN_TYPE'] ?? null) !== 'bigint unsigned' || ($actor['EXTRA'] ?? null) !== ''
            || ($actor['GENERATION_EXPRESSION'] ?? null) !== '') { self::fail('PROFILE_PRESTATE_INVALID'); }
        if (isset($job['columns']['authorization_profile'])) {
            self::assertColumn($job['columns']['authorization_profile'], self::column('authorization_profile', 'varchar(32)', false, 'member', self::asciiCollation($pdo)));
            self::assertColumn($job['columns']['producer_fact_key'] ?? [], self::column('producer_fact_key', 'varchar(64)', true, null, 'ascii_bin'));
            if (!isset($job['checks']['chk_task_job_profile'])) {
                foreach (['task_type', 'handler_key'] as $column) {
                    if (($job['columns'][$column]['IS_NULLABLE'] ?? null) !== 'NO') { self::fail('PROFILE_PRESTATE_INVALID'); }
                }
                if ((int) self::query($pdo, 'SELECT EXISTS(SELECT 1 FROM pa_task_job WHERE NOT (' . self::PROFILE_CHECK . ')) AS invalid')[0]['invalid'] !== 0) {
                    self::fail('PROFILE_VALUES_INVALID');
                }
            }
            if (!isset($job['indexes']['uk_task_negative_fact'])
                && (int) self::query($pdo, 'SELECT EXISTS(SELECT 1 FROM pa_task_job WHERE producer_fact_key IS NOT NULL'
                    . ' GROUP BY authorization_profile,producer_fact_key HAVING COUNT(*)>1) AS invalid')[0]['invalid'] !== 0) {
                self::fail('PROFILE_UNIQUE_CONFLICT');
            }
        } else {
            if (isset($job['columns']['producer_fact_key'])) { self::fail('PROFILE_PRESTATE_INVALID'); }
            if ($actor['IS_NULLABLE'] !== 'NO'
                && (int) self::query($pdo, 'SELECT EXISTS(SELECT 1 FROM pa_task_job WHERE created_by_member_id IS NULL) AS invalid')[0]['invalid'] !== 0) {
                self::fail('PROFILE_VALUES_INVALID');
            }
            // ADD COLUMN defaults member/NULL make the new unique key nullable and its CHECK depend only on actor non-null.
        }
        if (isset($job['indexes']['uk_task_negative_fact'])) {
            self::assertIndex($job['indexes']['uk_task_negative_fact'], 'uk_task_negative_fact', ['authorization_profile', 'producer_fact_key']);
        }
        if (isset($job['checks']['chk_task_job_profile'])) { self::assertCheck($job['checks']['chk_task_job_profile']); }
        $collision = self::query($pdo, "SELECT CONSTRAINT_TYPE FROM information_schema.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA=DATABASE() AND TABLE_NAME='pa_task_job' AND CONSTRAINT_NAME='chk_task_job_profile'");
        if ($collision !== [] && (count($collision) !== 1 || $collision[0]['CONSTRAINT_TYPE'] !== 'CHECK')) { self::fail('PROFILE_PRESTATE_INVALID'); }
        if (isset($event['columns']['event_reference'])) {
            self::assertColumn($event['columns']['event_reference'], self::column('event_reference', 'char(64)', true, null, 'ascii_bin'));
            self::assertIndex($event['indexes']['uk_task_event_reference'] ?? [], 'uk_task_event_reference', ['job_id', 'event_reference']);
        } elseif (isset($event['indexes']['uk_task_event_reference'])) { self::fail('PROFILE_PRESTATE_INVALID'); }
    }

    private static function profilePoststate(array $before, array $after, PDO $pdo): void
    {
        if ($before['pa_task_job_attempt'] !== $after['pa_task_job_attempt']) { self::fail('EXISTING_METADATA_CHANGED'); }
        $expected = $before;
        foreach (['pa_task_job', 'pa_task_job_event'] as $table) {
            if ($after[$table] === null) { self::fail('PROFILE_POSTSTATE_INVALID'); }
            unset($expected[$table]['ddl'], $after[$table]['ddl']);
        }
        $job = &$expected['pa_task_job'];
        $actor = &$job['columns']['created_by_member_id'];
        $actor = ['COLUMN_NAME' => 'created_by_member_id', 'ORDINAL_POSITION' => $actor['ORDINAL_POSITION'],
            'COLUMN_TYPE' => 'bigint unsigned', 'IS_NULLABLE' => 'YES', 'COLUMN_DEFAULT' => null, 'EXTRA' => '',
            'CHARACTER_SET_NAME' => null, 'COLLATION_NAME' => null, 'COLUMN_COMMENT' => '', 'GENERATION_EXPRESSION' => ''];
        unset($actor);
        if (!isset($job['columns']['authorization_profile'])) {
            self::appendColumn($job['columns'], self::column('authorization_profile', 'varchar(32)', false, 'member', self::asciiCollation($pdo)));
            self::appendColumn($job['columns'], self::column('producer_fact_key', 'varchar(64)', true, null, 'ascii_bin'));
        }
        if (!isset($job['indexes']['uk_task_negative_fact'])) { $job['indexes']['uk_task_negative_fact'] = self::index('uk_task_negative_fact', ['authorization_profile', 'producer_fact_key']); }
        ksort($job['indexes']);
        self::assertCheck($after['pa_task_job']['checks']['chk_task_job_profile'] ?? []);
        if (!isset($job['checks']['chk_task_job_profile'])) { $job['checks']['chk_task_job_profile'] = $after['pa_task_job']['checks']['chk_task_job_profile']; }
        ksort($job['checks']); unset($job);
        $event = &$expected['pa_task_job_event'];
        if (!isset($event['columns']['event_reference'])) {
            self::appendColumn($event['columns'], self::column('event_reference', 'char(64)', true, null, 'ascii_bin'));
            $event['indexes']['uk_task_event_reference'] = self::index('uk_task_event_reference', ['job_id', 'event_reference']);
        }
        ksort($event['indexes']); unset($event);
        if ($expected !== $after) { self::fail('PROFILE_POSTSTATE_INVALID'); }
    }

    private static function column(string $name, string $type, bool $nullable, ?string $default, string $collation): array
    {
        return ['COLUMN_NAME' => $name, 'COLUMN_TYPE' => $type, 'IS_NULLABLE' => $nullable ? 'YES' : 'NO',
            'COLUMN_DEFAULT' => $default, 'EXTRA' => '', 'CHARACTER_SET_NAME' => 'ascii', 'COLLATION_NAME' => $collation,
            'COLUMN_COMMENT' => '', 'GENERATION_EXPRESSION' => ''];
    }

    private static function assertColumn(array $actual, array $expected): void
    {
        unset($actual['ORDINAL_POSITION']);
        if ($actual !== $expected) { self::fail('PROFILE_PRESTATE_INVALID'); }
    }

    private static function appendColumn(array &$columns, array $column): void
    {
        $column = ['COLUMN_NAME' => $column['COLUMN_NAME'], 'ORDINAL_POSITION' => (string) (count($columns) + 1), ...array_slice($column, 1)];
        $columns[$column['COLUMN_NAME']] = $column;
    }

    private static function asciiCollation(PDO $pdo): string
    {
        return self::query($pdo, "SELECT DEFAULT_COLLATE_NAME FROM information_schema.CHARACTER_SETS WHERE CHARACTER_SET_NAME='ascii'")[0]['DEFAULT_COLLATE_NAME'];
    }

    private static function index(string $name, array $columns): array
    {
        $parts = [];
        foreach ($columns as $position => $column) {
            $parts[] = ['INDEX_NAME' => $name, 'NON_UNIQUE' => '0', 'SEQ_IN_INDEX' => (string) ($position + 1),
                'COLUMN_NAME' => $column, 'COLLATION' => 'A', 'SUB_PART' => null, 'PACKED' => null, 'INDEX_TYPE' => 'BTREE',
                'COMMENT' => '', 'INDEX_COMMENT' => '', 'IS_VISIBLE' => 'YES', 'EXPRESSION' => null];
        }
        return $parts;
    }

    private static function assertIndex(array $actual, string $name, array $columns): void
    {
        if ($actual !== self::index($name, $columns)) { self::fail('PROFILE_PRESTATE_INVALID'); }
    }

    private static function tables(PDO $pdo): array
    {
        $result = [];
        foreach (self::TABLES as $table) {
            $meta = self::query($pdo, 'SELECT TABLE_TYPE,ENGINE,TABLE_COLLATION,AUTO_INCREMENT,CREATE_OPTIONS,TABLE_COMMENT,ROW_FORMAT'
                . ' FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?', [$table]);
            if ($meta === []) { $result[$table] = null; continue; }
            if (count($meta) !== 1 || $meta[0]['TABLE_TYPE'] !== 'BASE TABLE' || $meta[0]['ENGINE'] !== 'InnoDB') { self::fail('TABLE_UNSUPPORTED'); }
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
            $result[$table] = ['table' => $meta[0], 'columns' => array_column($columns, null, 'COLUMN_NAME'), 'indexes' => $indexes,
                'checks' => array_column($checks, null, 'CONSTRAINT_NAME'), 'foreign_keys' => $foreign,
                'ddl' => array_values(self::query($pdo, 'SHOW CREATE TABLE `' . $table . '`')[0])[1]];
        }
        return $result;
    }

    /** Recognize only the fixed profile CHECK's logical grouping, not SQL statements or row execution. */
    private static function assertCheck(array $check): void
    {
        if (($check['ENFORCED'] ?? null) !== 'YES' || self::profileExpression($check['CHECK_CLAUSE'] ?? '') !== self::profileExpression(self::PROFILE_CHECK)) {
            self::fail('PROFILE_CHECK_INVALID');
        }
    }

    private static function profileExpression(string $sql): array
    {
        $decoded = strtr($sql, ["\\\\" => "\\", "\\'" => "'"]);
        if (strtr($decoded, ["\\" => "\\\\", "'" => "\\'"]) === $sql) { $sql = $decoded; }
        elseif (str_contains($sql, "\\")) { self::fail('PROFILE_CHECK_INVALID'); }
        $tokens = []; $offset = 0;
        while ($offset < strlen($sql)) {
            if (!preg_match('/\G(?:\s+|_[a-z0-9]+(?=\x27)|`([a-z_]+)`|(\x27(?:\x27\x27|[^\x27])*\x27|[a-z_]+|[(),=]))/Ai', $sql, $match, 0, $offset)) { self::fail('PROFILE_CHECK_INVALID'); }
            $offset += strlen($match[0]);
            if (trim($match[0]) === '' || $match[0][0] === '_') { continue; }
            $token = ($match[1] ?? '') !== '' ? $match[1] : $match[2];
            $tokens[] = $token[0] === "'" ? $token : strtolower($token);
        }
        $at = 0;
        $parse = static function (int $level = 0) use (&$parse, &$at, $tokens): array {
            if ($level < 2) {
                $op = $level === 0 ? 'or' : 'and'; $items = [$parse($level + 1)];
                while (($tokens[$at] ?? null) === $op) { ++$at; $items[] = $parse($level + 1); }
                $flat = [];
                foreach ($items as $item) { if (($item[0] ?? null) === $op) { array_push($flat, ...array_slice($item, 1)); } else { $flat[] = $item; } }
                return count($flat) === 1 ? $flat[0] : [$op, ...$flat];
            }
            if (($tokens[$at] ?? null) === '(') {
                ++$at; $node = $parse(); if (($tokens[$at++] ?? null) !== ')') { self::fail('PROFILE_CHECK_INVALID'); } return $node;
            }
            $leaf = [];
            while (isset($tokens[$at]) && !in_array($tokens[$at], ['and', 'or', ')'], true)) { $leaf[] = $tokens[$at++]; }
            if ($leaf === []) { self::fail('PROFILE_CHECK_INVALID'); } return ['predicate', ...$leaf];
        };
        $tree = $parse(); if ($at !== count($tokens)) { self::fail('PROFILE_CHECK_INVALID'); } return $tree;
    }

    private static function ledger(PDO $pdo, array $stage): array
    {
        if (!($stage['stageLedger'] ?? null) instanceof Closure) { self::fail('LEDGER_READER_REQUIRED'); }
        $rows = ($stage['stageLedger'])($pdo); $current = 0;
        if (!is_array($rows)) { self::fail('LEDGER_INVALID'); }
        foreach ($rows as $row) {
            if (!is_array($row) || ($row['module_key'] ?? null) !== 'official.task') { self::fail('LEDGER_INVALID'); }
            if (($row['migration_key'] ?? null) === $stage['migration_key']) {
                if (($row['status'] ?? null) !== 'applying' || ($row['checksum'] ?? null) !== $stage['checksum']) { self::fail('LEDGER_INVALID'); } ++$current;
            } elseif (($row['status'] ?? null) !== 'applied') { self::fail('LEDGER_INVALID'); }
        }
        if ($current !== 1) { self::fail('LEDGER_INVALID'); }
        usort($rows, static fn(array $a, array $b): int => strcmp($a['migration_key'], $b['migration_key'])); return $rows;
    }

    private static function source(string $key): void
    {
        $path = dirname(__DIR__, 3) . '/database/migrations/' . substr($key, strlen('official.task:')) . '.sql';
        if (!is_file($path) || is_link($path) || hash_file('sha256', $path) !== self::SOURCES[$key]) { self::fail('SOURCE_CHANGED'); }
    }

    private static function connection(PDO $pdo): array
    {
        return self::query($pdo, 'SELECT DATABASE() AS db,@@server_uuid AS server_uuid,CONNECTION_ID() AS connection_id')[0];
    }

    private static function query(PDO $pdo, string $sql, array $parameters = []): array
    {
        $statement = $pdo->prepare($sql); $statement->execute($parameters);
        try { return array_map(static fn(array $row): array => array_map(static fn($value) => $value === null ? null : (string) $value, $row), $statement->fetchAll(PDO::FETCH_ASSOC)); }
        finally { $statement->closeCursor(); }
    }

    private static function snapshot(PDO $pdo, Closure $read): array
    {
        $started = false;
        try {
            if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'mysql' || $pdo->getAttribute(PDO::ATTR_CASE) !== PDO::CASE_NATURAL || $pdo->inTransaction()) { self::fail('CONNECTION_UNSUPPORTED'); }
            $pdo->exec('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ, READ ONLY'); $pdo->beginTransaction(); $started = true;
            $result = $read(); if (!$pdo->inTransaction()) { self::fail('SNAPSHOT_CHANGED'); } return $result;
        } catch (Throwable $error) {
            if ($error instanceof RuntimeException && str_starts_with($error->getMessage(), 'TASK_SCHEMA_STAGE_')) { throw $error; }
            throw new RuntimeException('TASK_SCHEMA_STAGE_OBSERVATION_FAILED', 0, $error);
        } finally { if ($started && $pdo->inTransaction()) { $pdo->rollBack(); } }
    }

    private static function fail(string $code): never
    {
        throw new RuntimeException('TASK_SCHEMA_STAGE_' . $code);
    }
}
