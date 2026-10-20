<?php

declare(strict_types=1);

namespace PeanutAdmin\Modules\Notification\Contract;

use Closure;
use PDO;
use RuntimeException;
use Throwable;

/** Business postimage of the immutable verification-SMS migration; the host owns admission and recovery. */
final class SmsReservationMigrationProof
{
    private const ID = '20260909-notification-sms-reservation';
    private const SHA256 = '4446c1feb1d3226de810682f79e246c327f1d631b22f507ad725ae960f151753';
    private const COLUMNS = [
        'reservation_key' => ['char(37)', 'ascii', 'ascii_bin', 'opaque verification SMS reservation capability'],
        'idempotency_key_hash' => ['char(64)', 'ascii', 'ascii_bin', 'request-scoped idempotency identity digest'],
        'request_digest' => ['char(64)', 'ascii', 'ascii_bin', 'immutable scene and receiver request digest'],
        'receiver_hash' => ['char(64)', 'ascii', 'ascii_bin', 'receiver digest used by the active reservation key'],
        'reservation_until' => ['int unsigned', null, null, 'exclusive resend reservation deadline'],
        'reservation_active' => ['tinyint unsigned', null, null, '1 while reserved, successful, or unknown inside the resend window'],
    ];
    private const INDEXES = [
        'uk_notice_sms_reservation_key' => [true, ['reservation_key']],
        'uk_notice_sms_idempotency' => [true, ['tenant_id', 'idempotency_key_hash']],
        'uk_notice_sms_active_receiver' => [true, ['tenant_id', 'channel', 'receiver_hash', 'reservation_active']],
        'idx_notice_sms_reservation_expiry' => [false, ['tenant_id', 'reservation_active', 'reservation_until', 'id']],
    ];
    private const CHECK = 'chk_notice_sms_reservation_active';
    private const PREDICATE = 'reservation_active IS NULL OR (reservation_active = 1 AND receiver_hash IS NOT NULL AND reservation_until IS NOT NULL AND status IN (0,1,3))';

    /** Private in-memory input for after(), never a public plan, backup, or authority to execute SQL. */
    public static function before(PDO $pdo): array
    {
        return self::snapshot($pdo, static function () use ($pdo): array {
            $connection = self::connection($pdo);
            $schema = self::schema($pdo);
            foreach (array_keys(self::COLUMNS) as $name) {
                if (isset($schema['columns'][$name])) { self::fail('PRESTATE_COLLISION'); }
            }
            foreach (array_keys(self::INDEXES) as $name) {
                if (isset($schema['indexes'][$name])) { self::fail('PRESTATE_COLLISION'); }
            }
            if (isset($schema['checks'][self::CHECK]) || !isset($schema['columns']['verify_code_hash'])
                || array_column($schema['indexes']['PRIMARY'] ?? [], 'COLUMN_NAME') !== ['id']) {
                self::fail('PRESTATE_UNSUPPORTED');
            }
            $rows = self::rowImage($pdo, array_keys($schema['columns']));
            // The server supplies GROUP BY-equivalent collation groups and SHA2 bytes, not PHP string equality.
            $candidates = self::query($pdo, 'SELECT id,send_time,SHA2(receiver,256) AS receiver_hash,'
                . 'DENSE_RANK() OVER (ORDER BY tenant_id,channel,receiver) AS receiver_group'
                . ' FROM pa_notice_log WHERE channel=1 AND scene_id>0 AND status=1 AND send_time>? - 60 ORDER BY id',
                [$connection['unix_second']]);
            return ['migration_id' => self::ID, 'source_sha256' => self::SHA256,
                'pdo_object_id' => spl_object_id($pdo), 'connection' => $connection,
                'schema' => $schema, 'rows' => $rows, 'candidates' => $candidates, 'readonly' => true,
                'safe_summary' => ['migration_id' => self::ID, 'source_sha256' => self::SHA256,
                    'schema_sha256' => hash('sha256', serialize($schema)),
                    'historical_rows_sha256' => $rows['sha256'], 'rows' => $rows['count'],
                    'candidate_rows' => count($candidates)]];
        });
    }

    /** Verify before the host marks the migration applied; no SQL writes, retries or provider calls. */
    public static function after(PDO $pdo, array $before): array
    {
        return self::snapshot($pdo, static function () use ($pdo, $before): array {
            if (($before['migration_id'] ?? null) !== self::ID || ($before['source_sha256'] ?? null) !== self::SHA256
                || ($before['pdo_object_id'] ?? null) !== spl_object_id($pdo)
                || !is_array($before['schema'] ?? null) || !is_array($before['rows'] ?? null)
                || !is_array($before['candidates'] ?? null)) { self::fail('INPUT_INVALID'); }
            $connection = self::connection($pdo);
            foreach (['database', 'server_uuid', 'connection_id'] as $key) {
                if (($before['connection'][$key] ?? null) !== $connection[$key]) { self::fail('CONNECTION_CHANGED'); }
            }
            [$low, $high] = self::bounds($before, $connection);
            $schema = self::schema($pdo);
            self::assertSchema($before['schema'], $schema);
            if (self::rowImage($pdo, array_keys($before['schema']['columns'])) !== $before['rows']) {
                self::fail('HISTORICAL_ROWS_CHANGED');
            }
            $candidates = array_column($before['candidates'], null, 'id');
            if (count($candidates) !== count($before['candidates'])) { self::fail('INPUT_INVALID'); }
            $selected = [];
            $statement = $pdo->query('SELECT id,reservation_key,idempotency_key_hash,request_digest,'
                . 'receiver_hash,reservation_until,reservation_active FROM pa_notice_log ORDER BY id');
            try {
                while ($row = $statement->fetch(PDO::FETCH_ASSOC)) {
                    foreach (['reservation_key', 'idempotency_key_hash', 'request_digest'] as $name) {
                        if ($row[$name] !== null) { self::fail('POSTIMAGE_INVALID'); }
                    }
                    if ($row['reservation_active'] === null) {
                        if ($row['receiver_hash'] !== null || $row['reservation_until'] !== null) { self::fail('POSTIMAGE_INVALID'); }
                        continue;
                    }
                    $candidate = $candidates[$row['id']] ?? null;
                    if ((string) $row['reservation_active'] !== '1' || $candidate === null
                        || $row['receiver_hash'] !== $candidate['receiver_hash']
                        || (string) $row['reservation_until'] !== (string) (self::integer($candidate['send_time']) + 60)
                        || isset($selected[$candidate['receiver_group']])) { self::fail('POSTIMAGE_INVALID'); }
                    $selected[$candidate['receiver_group']] = self::integer($candidate['id']);
                }
            } finally { $statement->closeCursor(); }
            // Eligibility is t < send_time+60. For one selected id, all greater ids in its collation group
            // must be expired; an unselected group must have no eligible id. Intersect ALL groups with ONE t.
            foreach ($candidates as $candidate) {
                $id = self::integer($candidate['id']);
                $expiry = self::integer($candidate['send_time']) + 60;
                $winner = $selected[$candidate['receiver_group']] ?? null;
                if ($winner === $id) { $high = min($high, $expiry - 1); }
                elseif ($winner === null || $id > $winner) { $low = max($low, $expiry); }
            }
            if ($low > $high) { self::fail('COMMON_EXECUTION_SECOND_UNPROVEN'); }
            return ['migration_id' => self::ID, 'source_sha256' => self::SHA256, 'sms_reservation_verified' => true,
                'schema_sha256' => hash('sha256', serialize($schema)), 'historical_rows_sha256' => $before['rows']['sha256'],
                'rows' => $before['rows']['count'], 'activated_rows' => count($selected),
                'possible_execution_seconds' => ['from' => (string) $low, 'through' => (string) $high],
                'execution_bounds_scope' => 'immutable-sql-batch', 'readonly' => true];
        });
    }

    private static function bounds(array $before, array $connection): array
    {
        $bounds = $before['execution_bounds'] ?? [];
        if (($bounds['scope'] ?? null) !== 'immutable-sql-batch' || ($bounds['migration_key'] ?? null) !== self::ID
            || ($bounds['sha256'] ?? null) !== self::SHA256 || ($bounds['pdo_object_id'] ?? null) !== $before['pdo_object_id']) {
            self::fail('EXECUTION_BOUNDS_INVALID');
        }
        foreach (['before', 'after'] as $stage) {
            $row = $bounds[$stage] ?? [];
            if (($row['database'] ?? null) !== $connection['database']
                || (string) ($row['connection_id'] ?? '') !== $connection['connection_id']
                || !is_string($row['utc_timestamp'] ?? null)
                || !preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}\.\d{3}$/D', $row['utc_timestamp'])) {
                self::fail('EXECUTION_BOUNDS_INVALID');
            }
        }
        $low = self::integer($bounds['before']['unix_second'] ?? null);
        $high = self::integer($bounds['after']['unix_second'] ?? null);
        if ($low < self::integer($before['connection']['unix_second'] ?? null)
            || $low > $high || $high > self::integer($connection['unix_second'])) { self::fail('EXECUTION_BOUNDS_INVALID'); }
        return [$low, $high];
    }

    private static function connection(PDO $pdo): array
    {
        return self::query($pdo, 'SELECT DATABASE() AS `database`,@@server_uuid AS server_uuid,'
            . 'CONNECTION_ID() AS connection_id,UNIX_TIMESTAMP() AS unix_second')[0];
    }

    private static function schema(PDO $pdo): array
    {
        $table = self::query($pdo, 'SELECT TABLE_TYPE,ENGINE,TABLE_COLLATION,AUTO_INCREMENT,CREATE_OPTIONS,TABLE_COMMENT'
            . " FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='pa_notice_log'");
        if (count($table) !== 1 || $table[0]['TABLE_TYPE'] !== 'BASE TABLE' || $table[0]['ENGINE'] !== 'InnoDB') {
            self::fail('PRESTATE_UNSUPPORTED');
        }
        if (self::query($pdo, "SELECT TRIGGER_NAME FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA=DATABASE() AND EVENT_OBJECT_TABLE='pa_notice_log'") !== []) {
            self::fail('DML_TRIGGER_EFFECT_UNKNOWN');
        }
        $columns = self::query($pdo, 'SELECT COLUMN_NAME,ORDINAL_POSITION,COLUMN_TYPE,IS_NULLABLE,COLUMN_DEFAULT,'
            . 'EXTRA,CHARACTER_SET_NAME,COLLATION_NAME,COLUMN_COMMENT,GENERATION_EXPRESSION'
            . " FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='pa_notice_log' ORDER BY ORDINAL_POSITION");
        $indexes = [];
        foreach (self::query($pdo, 'SELECT INDEX_NAME,NON_UNIQUE,SEQ_IN_INDEX,COLUMN_NAME,COLLATION,SUB_PART,PACKED,INDEX_TYPE,'
            . 'COMMENT,INDEX_COMMENT,IS_VISIBLE,EXPRESSION FROM information_schema.STATISTICS'
            . " WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='pa_notice_log' ORDER BY INDEX_NAME,SEQ_IN_INDEX") as $row) {
            $indexes[$row['INDEX_NAME']][] = $row;
        }
        $checks = self::query($pdo, 'SELECT t.CONSTRAINT_NAME,t.ENFORCED,CAST(c.CHECK_CLAUSE AS BINARY) AS CHECK_CLAUSE'
            . ' FROM information_schema.TABLE_CONSTRAINTS t JOIN information_schema.CHECK_CONSTRAINTS c'
            . ' ON c.CONSTRAINT_SCHEMA=t.CONSTRAINT_SCHEMA AND c.CONSTRAINT_NAME=t.CONSTRAINT_NAME'
            . " WHERE t.TABLE_SCHEMA=DATABASE() AND t.TABLE_NAME='pa_notice_log' AND t.CONSTRAINT_TYPE='CHECK' ORDER BY t.CONSTRAINT_NAME");
        $foreign = self::query($pdo, 'SELECT k.CONSTRAINT_NAME,k.ORDINAL_POSITION,k.COLUMN_NAME,k.REFERENCED_TABLE_SCHEMA,'
            . 'k.REFERENCED_TABLE_NAME,k.REFERENCED_COLUMN_NAME,r.UPDATE_RULE,r.DELETE_RULE'
            . ' FROM information_schema.KEY_COLUMN_USAGE k JOIN information_schema.REFERENTIAL_CONSTRAINTS r'
            . ' ON r.CONSTRAINT_SCHEMA=k.CONSTRAINT_SCHEMA AND r.TABLE_NAME=k.TABLE_NAME AND r.CONSTRAINT_NAME=k.CONSTRAINT_NAME'
            . " WHERE k.TABLE_SCHEMA=DATABASE() AND k.TABLE_NAME='pa_notice_log' AND k.REFERENCED_TABLE_NAME IS NOT NULL ORDER BY k.CONSTRAINT_NAME,k.ORDINAL_POSITION");
        return ['table' => $table[0], 'columns' => array_column($columns, null, 'COLUMN_NAME'),
            'indexes' => $indexes, 'checks' => array_column($checks, null, 'CONSTRAINT_NAME'), 'foreign_keys' => $foreign];
    }

    private static function assertSchema(array $before, array $after): void
    {
        $expected = $before;
        $columns = []; $position = 0;
        foreach ($before['columns'] as $name => $column) {
            $column['ORDINAL_POSITION'] = (string) ++$position; $columns[$name] = $column;
            if ($name !== 'verify_code_hash') { continue; }
            foreach (self::COLUMNS as $added => [$type, $charset, $collation, $comment]) {
                $columns[$added] = ['COLUMN_NAME' => $added, 'ORDINAL_POSITION' => (string) ++$position,
                    'COLUMN_TYPE' => $type, 'IS_NULLABLE' => 'YES', 'COLUMN_DEFAULT' => null, 'EXTRA' => '',
                    'CHARACTER_SET_NAME' => $charset, 'COLLATION_NAME' => $collation,
                    'COLUMN_COMMENT' => $comment, 'GENERATION_EXPRESSION' => ''];
            }
        }
        $expected['columns'] = $columns;
        foreach (self::INDEXES as $name => [$unique, $names]) {
            $parts = [];
            foreach ($names as $position => $column) {
                $parts[] = ['INDEX_NAME' => $name, 'NON_UNIQUE' => $unique ? '0' : '1', 'SEQ_IN_INDEX' => (string) ($position + 1),
                    'COLUMN_NAME' => $column, 'COLLATION' => 'A', 'SUB_PART' => null, 'PACKED' => null, 'INDEX_TYPE' => 'BTREE',
                    'COMMENT' => '', 'INDEX_COMMENT' => '', 'IS_VISIBLE' => 'YES', 'EXPRESSION' => null];
            }
            $expected['indexes'][$name] = $parts;
        }
        // Index names form an unordered map; MySQL's metadata collation may
        // order PRIMARY differently from PHP. Index parts retain their order.
        ksort($expected['indexes'], SORT_STRING);
        ksort($after['indexes'], SORT_STRING);
        $check = $after['checks'][self::CHECK] ?? [];
        if (($check['ENFORCED'] ?? null) !== 'YES'
            || self::predicate((string) ($check['CHECK_CLAUSE'] ?? '')) !== self::predicate(self::PREDICATE)) {
            self::fail('SCHEMA_POSTIMAGE_INVALID');
        }
        unset($after['checks'][self::CHECK]);
        if ($expected !== $after) { self::fail('SCHEMA_POSTIMAGE_INVALID'); }
    }

    private static function rowImage(PDO $pdo, array $columns): array
    {
        $projection = array_map(static fn(string $name): string => 'CAST(`' . str_replace('`', '``', $name)
            . '` AS BINARY) AS `' . str_replace('`', '``', $name) . '`', $columns);
        $statement = $pdo->query('SELECT ' . implode(',', $projection) . ' FROM pa_notice_log ORDER BY id');
        $hash = hash_init('sha256'); $count = 0;
        try {
            while ($row = $statement->fetch(PDO::FETCH_NUM)) {
                foreach ($row as $value) { hash_update($hash, $value === null ? 'N;' : 'S' . strlen($value) . ':' . $value . ';'); }
                hash_update($hash, '|'); ++$count;
            }
        } finally { $statement->closeCursor(); }
        return ['count' => $count, 'sha256' => hash_final($hash)];
    }

    /** Only this fixed predicate's identifiers, numbers, IS/IN and logical grouping are accepted. */
    private static function predicate(string $sql): array
    {
        $tokens = []; $offset = 0;
        while ($offset < strlen($sql)) {
            if (!preg_match('/\G(?:\s+|`([a-z_]+)`|([a-z_]+|[0-9]+|[(),=]))/Ai', $sql, $match, 0, $offset)) {
                self::fail('CHECK_SHAPE_UNKNOWN');
            }
            $offset += strlen($match[0]);
            if (trim($match[0]) !== '') { $tokens[] = strtolower(($match[1] ?? '') !== '' ? $match[1] : $match[2]); }
        }
        $at = 0;
        $parse = static function (int $level = 0) use (&$parse, &$at, $tokens): array {
            if ($level < 2) {
                $operator = $level === 0 ? 'or' : 'and'; $items = [$parse($level + 1)];
                while (($tokens[$at] ?? null) === $operator) { ++$at; $items[] = $parse($level + 1); }
                $flat = [];
                foreach ($items as $item) {
                    if (($item[0] ?? null) === $operator) { array_push($flat, ...array_slice($item, 1)); }
                    else { $flat[] = $item; }
                }
                return count($flat) === 1 ? $flat[0] : [$operator, ...$flat];
            }
            if (($tokens[$at] ?? null) === '(') {
                ++$at; $node = $parse();
                if (($tokens[$at++] ?? null) !== ')') { self::fail('CHECK_SHAPE_UNKNOWN'); }
                return $node;
            }
            $leaf = [];
            while (isset($tokens[$at]) && !in_array($tokens[$at], ['and', 'or', ')'], true)) {
                $token = $tokens[$at++]; $leaf[] = $token;
                if ($token === 'in') {
                    if (($tokens[$at++] ?? null) !== '(') { self::fail('CHECK_SHAPE_UNKNOWN'); }
                    $leaf[] = '(';
                    while (isset($tokens[$at]) && $tokens[$at] !== ')') { $leaf[] = $tokens[$at++]; }
                    if (($tokens[$at++] ?? null) !== ')') { self::fail('CHECK_SHAPE_UNKNOWN'); }
                    $leaf[] = ')';
                }
            }
            if ($leaf === []) { self::fail('CHECK_SHAPE_UNKNOWN'); }
            return ['predicate', ...$leaf];
        };
        $result = $parse();
        if ($at !== count($tokens)) { self::fail('CHECK_SHAPE_UNKNOWN'); }
        return $result;
    }

    private static function integer(mixed $value): int
    {
        $text = is_int($value) ? (string) $value : $value;
        if (!is_string($text) || !preg_match('/^(0|[1-9][0-9]*)$/D', $text)
            || strlen($text) > strlen((string) PHP_INT_MAX)
            || (strlen($text) === strlen((string) PHP_INT_MAX) && strcmp($text, (string) PHP_INT_MAX) > 0)) {
            self::fail('INPUT_INVALID');
        }
        return (int) $text;
    }

    private static function query(PDO $pdo, string $sql, array $parameters = []): array
    {
        $statement = $pdo->prepare($sql); $statement->execute($parameters);
        try {
            return array_map(static fn(array $row): array => array_map(
                static fn(mixed $value): ?string => $value === null ? null : (string) $value, $row), $statement->fetchAll(PDO::FETCH_ASSOC));
        } finally { $statement->closeCursor(); }
    }

    private static function snapshot(PDO $pdo, Closure $read): array
    {
        $started = false;
        try {
            $source = dirname(__DIR__, 6) . '/database/migrations/' . self::ID . '.sql';
            if (!is_file($source) || is_link($source) || hash_file('sha256', $source) !== self::SHA256
                || $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'mysql' || $pdo->getAttribute(PDO::ATTR_CASE) !== PDO::CASE_NATURAL
                || $pdo->inTransaction()) { self::fail('SOURCE_OR_CONNECTION_UNSUPPORTED'); }
            $pdo->exec('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ, READ ONLY');
            $pdo->beginTransaction(); $started = true;
            $result = $read();
            if (!$pdo->inTransaction()) { self::fail('SNAPSHOT_CHANGED'); }
            return $result;
        } catch (Throwable $error) {
            if ($error instanceof RuntimeException && str_starts_with($error->getMessage(), 'NOTIFICATION_SMS_MIGRATION_')) { throw $error; }
            throw new RuntimeException('NOTIFICATION_SMS_MIGRATION_OBSERVATION_FAILED', 0, $error);
        } finally {
            if ($started && $pdo->inTransaction()) { $pdo->rollBack(); }
        }
    }

    private static function fail(string $code): never
    {
        throw new RuntimeException('NOTIFICATION_SMS_MIGRATION_' . $code);
    }
}
