<?php

declare(strict_types=1);

namespace PeanutAdmin\Modules\Identity\Auth;

use PDO;
use RuntimeException;
use Throwable;

/** Target metadata for this DDL-only migration; admission, its sole journal and recovery remain with the lifecycle. */
final class AuthClientSchemaMigrationProof
{
    private const ID = '20261007-auth-client-schema';
    private const SHA256 = '8411ed958b0d5d70c6a30771f5d1b8fd85f1cd6ae047a94f8404fa7737baf38b';

    public static function before(PDO $pdo): array
    {
        return self::observe($pdo, true);
    }

    /** Same held master PDO, after the immutable SQL and before its applied journal entry. */
    public static function after(PDO $pdo, array $before): array
    {
        if (($before['migration_id'] ?? null) !== self::ID
            || ($before['source_sha256'] ?? null) !== self::SHA256
            || ($before['pdo_object_id'] ?? null) !== spl_object_id($pdo)
            || ($before['client_prestate_valid'] ?? null) !== true
            || !is_bool($before['ip_index_retained'] ?? null)) {
            throw new RuntimeException('IDENTITY_AUTH_CLIENT_PROOF_INPUT_INVALID');
        }
        $after = self::observe($pdo, false);
        if ($before['connection'] !== $after['connection']) {
            throw new RuntimeException('IDENTITY_AUTH_CLIENT_PROOF_CONNECTION_CHANGED');
        }
        if ($before['tables'] !== $after['tables']) {
            throw new RuntimeException('IDENTITY_AUTH_CLIENT_PROOF_TARGET_CHANGED');
        }
        $check = $after['client_check'];
        if (($check['ENFORCED'] ?? null) !== 'YES'
            || !self::clientCheck((string) ($check['CHECK_CLAUSE'] ?? ''))) {
            throw new RuntimeException('IDENTITY_AUTH_CLIENT_PROOF_CHECK_INVALID');
        }
        // The SQL preserves an existing index meeting exactly these predicates, including comments/direction.
        if ($before['ip_index_retained']) {
            if ($before['ip_index'] !== $after['ip_index']) {
                throw new RuntimeException('IDENTITY_AUTH_CLIENT_PROOF_INDEX_INVALID');
            }
        } else {
            if (!self::retainedIpIndex($after['ip_index'])) {
                throw new RuntimeException('IDENTITY_AUTH_CLIENT_PROOF_INDEX_INVALID');
            }
            foreach ($after['ip_index'] as $part) {
                if ($part['INDEX_NAME'] !== 'idx_auth_event_ip' || $part['COLLATION'] !== 'A'
                    || $part['EXPRESSION'] !== null || $part['PACKED'] !== null
                    || $part['INDEX_COMMENT'] !== '' || $part['COMMENT'] !== '') {
                    throw new RuntimeException('IDENTITY_AUTH_CLIENT_PROOF_INDEX_INVALID');
                }
            }
        }
        return [...$after, 'target_metadata_verified' => true];
    }

    private static function observe(PDO $pdo, bool $checkClients): array
    {
        try {
            $connection = self::query($pdo, 'SELECT DATABASE() AS db,@@server_uuid AS server_uuid,CONNECTION_ID() AS connection_id')[0];
            $tables = [];
            foreach (['pa_tenant_session' => ['client_key'], 'pa_auth_security_event' => ['ip_address','occurred_at']] as $table => $names) {
                $shape = self::query($pdo, 'SELECT TABLE_TYPE,ENGINE FROM information_schema.TABLES'
                    . ' WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?', [$table]);
                if (count($shape) !== 1 || $shape[0]['TABLE_TYPE'] !== 'BASE TABLE' || $shape[0]['ENGINE'] !== 'InnoDB') {
                    throw new RuntimeException('IDENTITY_AUTH_CLIENT_PROOF_TARGET_UNSUPPORTED');
                }
                $columns = [];
                foreach ($names as $name) {
                    $column = self::query($pdo, 'SELECT COLUMN_NAME,COLUMN_TYPE,IS_NULLABLE,CHARACTER_SET_NAME,COLLATION_NAME'
                        . ' FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=?', [$table, $name]);
                    if (count($column) !== 1) { throw new RuntimeException('IDENTITY_AUTH_CLIENT_PROOF_TARGET_UNSUPPORTED'); }
                    $columns[] = $column[0];
                }
                $tables[$table] = ['table' => $shape[0], 'target_columns' => $columns];
            }
            if ($checkClients) {
                $invalid = self::query($pdo, "SELECT EXISTS(SELECT 1 FROM pa_tenant_session WHERE client_key IS NULL OR NOT ("
                    . "CHAR_LENGTH(client_key) BETWEEN 1 AND 64 AND REGEXP_LIKE(client_key,'^[a-z]','c')"
                    . " AND NOT REGEXP_LIKE(client_key,'[^a-z0-9-]','c'))) AS invalid")[0]['invalid'];
                if ($invalid !== '0') { throw new RuntimeException('IDENTITY_AUTH_CLIENT_PROOF_CLIENT_PRESTATE_INVALID'); }
            }
            $checks = self::query($pdo, 'SELECT t.CONSTRAINT_NAME,t.ENFORCED,CAST(c.CHECK_CLAUSE AS BINARY) AS CHECK_CLAUSE'
                . ' FROM information_schema.TABLE_CONSTRAINTS t JOIN information_schema.CHECK_CONSTRAINTS c'
                . ' ON c.CONSTRAINT_SCHEMA=t.CONSTRAINT_SCHEMA AND c.CONSTRAINT_NAME=t.CONSTRAINT_NAME'
                . " WHERE t.CONSTRAINT_SCHEMA=DATABASE() AND t.TABLE_NAME='pa_tenant_session'"
                . " AND t.CONSTRAINT_TYPE='CHECK' AND t.CONSTRAINT_NAME='chk_tenant_session_client'");
            $index = self::query($pdo, 'SELECT INDEX_NAME,NON_UNIQUE,SEQ_IN_INDEX,COLUMN_NAME,COLLATION,SUB_PART,PACKED,'
                . 'NULLABLE,INDEX_TYPE,CAST(COMMENT AS BINARY) AS COMMENT,CAST(INDEX_COMMENT AS BINARY) AS INDEX_COMMENT,'
                . 'IS_VISIBLE,EXPRESSION FROM information_schema.STATISTICS'
                . " WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='pa_auth_security_event' AND INDEX_NAME='idx_auth_event_ip'"
                . ' ORDER BY SEQ_IN_INDEX');
            // Use the source SQL's native comparison, including its collation and GROUP_CONCAT behavior.
            $retained = self::query($pdo, 'SELECT IF(COUNT(*)=2'
                . " AND GROUP_CONCAT(COLUMN_NAME ORDER BY SEQ_IN_INDEX SEPARATOR ',')='ip_address,occurred_at'"
                . ' AND MIN(NON_UNIQUE)=1 AND MAX(NON_UNIQUE)=1 AND SUM(SUB_PART IS NOT NULL)=0'
                . " AND MIN(INDEX_TYPE)='BTREE' AND MAX(INDEX_TYPE)='BTREE'"
                . " AND MIN(IS_VISIBLE)='YES' AND MAX(IS_VISIBLE)='YES',1,0) AS retained"
                . ' FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE()'
                . " AND TABLE_NAME='pa_auth_security_event' AND INDEX_NAME='idx_auth_event_ip'")[0]['retained'];
            return ['migration_id' => self::ID, 'source_sha256' => self::SHA256,
                'pdo_object_id' => spl_object_id($pdo), 'connection' => $connection, 'tables' => $tables,
                'client_prestate_valid' => $checkClients ? true : null, 'client_check' => $checks[0] ?? null,
                'ip_index' => $index, 'ip_index_retained' => $retained === '1', 'readonly' => true];
        } catch (Throwable $exception) {
            if ($exception instanceof RuntimeException && str_starts_with($exception->getMessage(), 'IDENTITY_AUTH_CLIENT_PROOF_')) {
                throw $exception;
            }
            // The lifecycle exposes only safe diagnostic fields, never the cause's SQL/message/arguments.
            throw new RuntimeException('IDENTITY_AUTH_CLIENT_PROOF_OBSERVATION_FAILED', 0, $exception);
        }
    }

    private static function retainedIpIndex(array $index): bool
    {
        if (count($index) !== 2) { return false; }
        foreach (['ip_address','occurred_at'] as $position => $column) {
            $part = $index[$position];
            if (strcasecmp((string) $part['COLUMN_NAME'], $column) !== 0 || $part['SEQ_IN_INDEX'] !== (string) ($position + 1)
                || $part['NON_UNIQUE'] !== '1' || $part['SUB_PART'] !== null
                || $part['INDEX_TYPE'] !== 'BTREE' || $part['IS_VISIBLE'] !== 'YES') { return false; }
        }
        return true;
    }

    /** Recognize the three source CHECK conjuncts without discarding logical grouping. */
    private static function clientCheck(string $clause): bool
    {
        $tokens = []; $offset = 0;
        while ($offset < strlen($clause)) {
            if (preg_match('/\G(?:\s+|(?<identifier>`[a-z_]+`|"[a-z_]+")|(?:_[a-z0-9]+)?(?<literal>\'(?:\'\'|[^\'])*\')|(?<word>[a-z_]+|[0-9]+|[(),]))/Ai',
                $clause, $match, PREG_UNMATCHED_AS_NULL, $offset) !== 1) { return false; }
            $offset += strlen($match[0]);
            if (trim($match[0]) === '') { continue; }
            $tokens[] = $match['literal'] ?? strtolower($match['identifier'] !== null
                ? substr($match['identifier'], 1, -1) : $match['word']);
        }
        $tokens = self::unwrapped($tokens);
        $terms = []; $term = []; $depth = 0; $between = false;
        foreach ($tokens as $token) {
            if ($token === '(') { ++$depth; }
            if ($token === ')') { --$depth; }
            if ($depth < 0) { return false; }
            if ($depth === 0 && $token === 'between') { $between = true; }
            if ($depth === 0 && $token === 'and') {
                if ($between) { $between = false; }
                else { $terms[] = self::unwrapped($term); $term = []; continue; }
            }
            $term[] = $token;
        }
        if ($depth !== 0) { return false; }
        $terms[] = self::unwrapped($term);
        foreach ($terms as &$value) {
            if (($value[0] ?? null) === 'not') { $value = ['not', ...self::unwrapped(array_slice($value, 1))]; }
        }
        unset($value);
        return $terms === [
            ['char_length','(','client_key',')','between','1','and','64'],
            ['regexp_like','(','client_key',',',"'^[a-z]'",',',"'c'",')'],
            ['not','regexp_like','(','client_key',',',"'[^a-z0-9-]'",',',"'c'",')'],
        ];
    }

    private static function unwrapped(array $tokens): array
    {
        while (($tokens[0] ?? null) === '(' && end($tokens) === ')') {
            $depth = 0;
            foreach ($tokens as $position => $token) {
                if ($token === '(') { ++$depth; }
                if ($token === ')') { --$depth; }
                if ($depth === 0 && $position !== count($tokens) - 1) { return $tokens; }
                if ($depth < 0) { return $tokens; }
            }
            if ($depth !== 0) { return $tokens; }
            $tokens = array_slice($tokens, 1, -1);
        }
        return $tokens;
    }

    private static function query(PDO $pdo, string $sql, array $parameters = []): array
    {
        $statement = $pdo->prepare($sql);
        try {
            $statement->execute($parameters);
            return array_map(static fn(array $row): array => array_map(
                static fn(mixed $value): ?string => $value === null ? null : (string) $value, $row,
            ), $statement->fetchAll(PDO::FETCH_ASSOC));
        } finally {
            $statement->closeCursor();
        }
    }
}
