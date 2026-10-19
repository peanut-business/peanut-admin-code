<?php

declare(strict_types=1);

namespace PeanutAdmin\Modules\Member\Contract;

use app\common\execution\CurrentExecutionContext;
use app\common\execution\InstallationExecutionContext;
use app\common\execution\InstanceExecutionContext;
use PDO;
use PDOException;
use RuntimeException;
use Throwable;
use think\facade\Db;

/** The fixed ALTER + CREATE is executed by the native caller; this class observes its target metadata only. */
final readonly class MemberSessionMigrationPreservation
{
    private const KEY = 'official.member:20260921-create-member-session';
    private const SQL_SHA256 = '297fc9de7bc227d584cb79fe6e5d50f6cf8512b33696578acbb3f10243b65337';
    private const PROTOCOL = 'peanut.member-session-migration-preservation.v1';
    private const NAMES = [
        'chk_member_security_session_revision', 'fk_member_session_tenant', 'fk_member_session_member',
        'chk_member_session_hash', 'chk_member_session_record_revision', 'chk_member_session_expiry', 'chk_member_session_revocation',
    ];
    /** type, nullable, extra, charset, collation; all new columns have SQL NULL/no explicit default. */
    private const COLUMNS = [
        'id' => ['bigint unsigned','NO','auto_increment',null,null],
        'session_hash' => ['char(64)','NO','','ascii','ascii_bin'],
        'tenant_id' => ['bigint unsigned','NO','',null,null],
        'member_id' => ['int unsigned','NO','',null,null],
        'session_revision' => ['bigint unsigned','NO','',null,null],
        'issued_at' => ['bigint unsigned','NO','',null,null],
        'expires_at' => ['bigint unsigned','NO','',null,null],
        'revoked_at' => ['bigint unsigned','YES','',null,null],
        'revoke_reason' => ['varchar(32)','YES','','ascii','ascii_bin'],
        'created_at' => ['datetime','NO','',null,null],
        'updated_at' => ['datetime','YES','',null,null],
    ];

    public function __construct(private CurrentExecutionContext $contexts) {}

    /** Only absent targets support execute. Existing/partial targets require the caller's real ledger resolution. */
    public function before(PDO $pdo, string $canonicalMigrationKey): array
    {
        return $this->guard(function () use ($pdo, $canonicalMigrationKey): array {
            $context = $this->context($pdo);
            $this->sqlHash($canonicalMigrationKey);
            $identity = $this->identity($pdo);
            $references = $this->references($pdo);
            if (!$this->missingTable($pdo) || !$this->missingRevision($pdo) || $this->namedConstraints($pdo) !== []) {
                self::fail('UNAPPLIED_TARGET_ABSENCE_REQUIRED');
            }
            if ($this->context($pdo) !== $context) { self::fail('CONTEXT_CHANGED'); }
            $hash = self::hash(['identity' => $identity, 'references' => $references, 'targets' => 'absent']);
            return [
                'safe_summary' => ['module_key' => 'official.member', 'migration_key' => self::KEY,
                    'sql_sha256' => self::SQL_SHA256, 'readonly' => true, 'preservation_verified' => false,
                    'proof_kind' => 'fixed_native_additive_ddl', 'before_sha256' => $hash],
                'private_state' => ['protocol' => self::PROTOCOL, 'pdo' => $pdo, 'context' => $context,
                    'migration_key' => self::KEY, 'sql_sha256' => self::SQL_SHA256,
                    'identity' => $identity, 'references' => $references, 'targets_absent' => true, 'before_sha256' => $hash],
            ];
        });
    }

    /** After the complete immutable SQL on this held PDO, before the sole official applied transition. */
    public function after(PDO $pdo, array $privateState): array
    {
        return $this->guard(function () use ($pdo, $privateState): array {
            if (($privateState['protocol'] ?? null) !== self::PROTOCOL || ($privateState['pdo'] ?? null) !== $pdo
                || ($privateState['context'] ?? null) !== $this->context($pdo) || ($privateState['targets_absent'] ?? null) !== true
                || ($privateState['migration_key'] ?? null) !== self::KEY || ($privateState['sql_sha256'] ?? null) !== self::SQL_SHA256
                || !is_array($privateState['identity'] ?? null) || !is_array($privateState['references'] ?? null)
                || !is_string($privateState['before_sha256'] ?? null)
                || !hash_equals($privateState['before_sha256'], self::hash(['identity' => $privateState['identity'], 'references' => $privateState['references'], 'targets' => 'absent']))) {
                self::fail('BEFORE_BINDING_INVALID');
            }
            $this->sqlHash(self::KEY);
            if ($this->identity($pdo) !== $privateState['identity'] || $this->references($pdo) !== $privateState['references']) {
                self::fail('DATABASE_OR_REFERENCES_CHANGED');
            }
            $revision = $this->columns($pdo, 'pa_member', ['session_revision']);
            $column = $revision['session_revision'] ?? null;
            $status = $this->columns($pdo, 'pa_member', ['status'])['status'] ?? null;
            if ($column === null || $status === null || $column['COLUMN_TYPE'] !== 'bigint unsigned'
                || $column['IS_NULLABLE'] !== 'NO' || $column['COLUMN_DEFAULT'] !== '1'
                || $column['EXTRA'] !== '' || $column['GENERATION_EXPRESSION'] !== ''
                || $column['CHARACTER_SET_NAME'] !== null || $column['COLLATION_NAME'] !== null
                || $column['COLUMN_COMMENT'] !== '会员安全域会话修订；改密、重置或停用时递增'
                || (int)$column['ORDINAL_POSITION'] !== (int)$status['ORDINAL_POSITION'] + 1) {
                self::fail('MEMBER_REVISION_TARGET_INVALID');
            }
            $table = $this->table($pdo, 'pa_member_session');
            if ($table === null || $table['TABLE_COLLATION'] !== 'utf8mb4_0900_ai_ci' || $table['TABLE_COMMENT'] !== '会员端可撤销会话') {
                self::fail('SESSION_TABLE_TARGET_INVALID');
            }
            $columns = $this->columns($pdo, 'pa_member_session');
            if (array_keys($columns) !== array_keys(self::COLUMNS)) { self::fail('SESSION_COLUMNS_INVALID'); }
            foreach (self::COLUMNS as $name => $expected) {
                $part = $columns[$name];
                if ([$part['COLUMN_TYPE'],$part['IS_NULLABLE'],$part['EXTRA'],$part['CHARACTER_SET_NAME'],$part['COLLATION_NAME']] !== $expected
                    || $part['COLUMN_DEFAULT'] !== null || $part['GENERATION_EXPRESSION'] !== '' || $part['COLUMN_COMMENT'] !== '') {
                    self::fail('SESSION_COLUMN_SEMANTICS_INVALID');
                }
            }
            $indexes = $this->indexes($pdo, 'pa_member_session');
            $wanted = ['PRIMARY' => ['0',['id']], 'uk_member_session_hash' => ['0',['session_hash']],
                'idx_member_session_subject' => ['1',['tenant_id','member_id','revoked_at','expires_at','id']]];
            if (array_diff(array_keys($indexes), array_keys($wanted)) !== [] || count($indexes) !== count($wanted)) { self::fail('SESSION_INDEXES_INVALID'); }
            foreach ($wanted as $name => [$nonUnique,$fields]) {
                $parts = $indexes[$name] ?? [];
                if (array_column($parts, 'COLUMN_NAME') !== $fields) { self::fail('SESSION_INDEXES_INVALID'); }
                foreach ($parts as $part) {
                    if ($part['NON_UNIQUE'] !== $nonUnique || $part['SUB_PART'] !== null || $part['EXPRESSION'] !== null
                        || $part['INDEX_TYPE'] !== 'BTREE' || $part['IS_VISIBLE'] !== 'YES' || $part['COLLATION'] !== 'A') {
                        self::fail('SESSION_INDEXES_INVALID');
                    }
                }
            }
            $foreign = $this->foreignKeys($pdo);
            $expectedForeign = [
                'fk_member_session_tenant' => [['tenant_id','pa_tenant','id']],
                'fk_member_session_member' => [['tenant_id','pa_member','tenant_id'],['member_id','pa_member','id']],
            ];
            if (count($foreign) !== 2) { self::fail('SESSION_FOREIGN_KEYS_INVALID'); }
            foreach ($expectedForeign as $name => $fields) {
                $parts = $foreign[$name] ?? [];
                $actual = array_map(static fn(array $r): array => [$r['COLUMN_NAME'],$r['REFERENCED_TABLE_NAME'],$r['REFERENCED_COLUMN_NAME']], $parts);
                if ($actual !== $fields) { self::fail('SESSION_FOREIGN_KEYS_INVALID'); }
                foreach ($parts as $part) {
                    if ($part['REFERENCED_TABLE_SCHEMA'] !== $privateState['identity']['database_name'] || $part['DELETE_RULE'] !== 'RESTRICT'
                        || !in_array($part['UPDATE_RULE'], ['NO ACTION','RESTRICT'], true) || $part['MATCH_OPTION'] !== 'NONE') {
                        self::fail('SESSION_FOREIGN_KEYS_INVALID');
                    }
                }
            }
            $checks = $this->checks($pdo);
            if (count($checks) !== 5) { self::fail('TARGET_CHECKS_INVALID'); }
            foreach ($checks as $name => $check) {
                if ($check['ENFORCED'] !== 'YES' || !$this->knownCheck($name, $check['CHECK_CLAUSE'])) { self::fail('TARGET_CHECKS_INVALID'); }
            }
            $names = $this->namedConstraints($pdo);
            if (count($names) !== 7) { self::fail('TARGET_CONSTRAINT_NAMES_INVALID'); }
            foreach ($names as $part) {
                $member = $part['CONSTRAINT_NAME'] === 'chk_member_security_session_revision';
                if ($part['TABLE_NAME'] !== ($member ? 'pa_member' : 'pa_member_session')
                    || $part['CONSTRAINT_TYPE'] !== (str_starts_with($part['CONSTRAINT_NAME'], 'fk_') ? 'FOREIGN KEY' : 'CHECK')) {
                    self::fail('TARGET_CONSTRAINT_NAMES_INVALID');
                }
            }
            if ($this->context($pdo) !== $privateState['context']) { self::fail('CONTEXT_CHANGED'); }
            return ['module_key' => 'official.member', 'migration_key' => self::KEY, 'sql_sha256' => self::SQL_SHA256,
                'readonly' => true, 'preservation_verified' => true, 'proof_kind' => 'fixed_native_additive_ddl',
                'before_sha256' => $privateState['before_sha256'],
                'after_sha256' => self::hash([$revision,$table,$columns,$indexes,$foreign,$checks,$names]),
                'delta_kind' => 'member_session_revision_and_empty_sessions', 'table_count' => 2];
        });
    }

    private function references(PDO $pdo): array
    {
        $result = [];
        foreach (['pa_member' => ['tenant_id'=>'bigint unsigned','id'=>'int unsigned','status'=>null], 'pa_tenant' => ['id'=>'bigint unsigned']] as $table => $required) {
            $shape = $this->table($pdo, $table);
            if ($shape === null) { self::fail('REFERENCE_TABLE_REQUIRED'); }
            $columns = $this->columns($pdo, $table, array_keys($required));
            foreach ($required as $name => $type) {
                if (!isset($columns[$name]) || ($type !== null && ($columns[$name]['COLUMN_TYPE'] !== $type || $columns[$name]['IS_NULLABLE'] !== 'NO'))) {
                    self::fail('REFERENCE_COLUMNS_INVALID');
                }
                unset($columns[$name]['ORDINAL_POSITION']);
            }
            $fields = $table === 'pa_member' ? ['tenant_id','id'] : ['id'];
            $usable = [];
            foreach ($this->indexes($pdo, $table) as $name => $parts) {
                if (array_column($parts, 'COLUMN_NAME') !== $fields) { continue; }
                if (array_filter($parts, static fn(array $r): bool => $r['NON_UNIQUE'] !== '0' || $r['SUB_PART'] !== null || $r['EXPRESSION'] !== null || $r['INDEX_TYPE'] !== 'BTREE' || $r['IS_VISIBLE'] !== 'YES') === []) { $usable[$name] = $parts; }
            }
            if ($usable === []) { self::fail('REFERENCE_UNIQUE_KEY_REQUIRED'); }
            $result[$table] = ['table'=>$shape, 'columns'=>$columns, 'referenced_unique_keys'=>$usable];
        }
        return $result;
    }

    private function table(PDO $pdo, string $name): ?array
    {
        $rows = $this->query($pdo, 'SELECT TABLE_TYPE,ENGINE,TABLE_COLLATION,CAST(TABLE_COMMENT AS BINARY) AS TABLE_COMMENT FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?', [$name]);
        if ($rows === []) { return null; }
        if (count($rows) !== 1 || $rows[0]['TABLE_TYPE'] !== 'BASE TABLE' || $rows[0]['ENGINE'] !== 'InnoDB') { self::fail('BASE_INNODB_TABLE_REQUIRED'); }
        return $rows[0];
    }

    private function columns(PDO $pdo, string $table, ?array $names = null): array
    {
        $rows = $this->query($pdo, 'SELECT COLUMN_NAME,COLUMN_TYPE,IS_NULLABLE,COLUMN_DEFAULT,EXTRA,GENERATION_EXPRESSION,CHARACTER_SET_NAME,COLLATION_NAME,CAST(COLUMN_COMMENT AS BINARY) AS COLUMN_COMMENT,ORDINAL_POSITION FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? ORDER BY ORDINAL_POSITION', [$table]);
        $out = [];
        foreach ($rows as $row) { if ($names === null || in_array($row['COLUMN_NAME'], $names, true)) { $out[$row['COLUMN_NAME']] = $row; } }
        return $out;
    }

    private function indexes(PDO $pdo, string $table): array
    {
        $rows = $this->query($pdo, 'SELECT INDEX_NAME,NON_UNIQUE,COLUMN_NAME,SUB_PART,EXPRESSION,INDEX_TYPE,IS_VISIBLE,COLLATION FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? ORDER BY INDEX_NAME,SEQ_IN_INDEX', [$table]);
        $out = []; foreach ($rows as $row) { $out[$row['INDEX_NAME']][] = $row; } return $out;
    }

    private function foreignKeys(PDO $pdo): array
    {
        $rows = $this->query($pdo, "SELECT k.CONSTRAINT_NAME,k.COLUMN_NAME,k.REFERENCED_TABLE_SCHEMA,k.REFERENCED_TABLE_NAME,k.REFERENCED_COLUMN_NAME,r.DELETE_RULE,r.UPDATE_RULE,r.MATCH_OPTION FROM information_schema.KEY_COLUMN_USAGE k JOIN information_schema.REFERENTIAL_CONSTRAINTS r ON r.CONSTRAINT_SCHEMA=k.CONSTRAINT_SCHEMA AND r.CONSTRAINT_NAME=k.CONSTRAINT_NAME AND r.TABLE_NAME=k.TABLE_NAME WHERE k.TABLE_SCHEMA=DATABASE() AND k.TABLE_NAME='pa_member_session' ORDER BY k.CONSTRAINT_NAME,k.ORDINAL_POSITION");
        $out = []; foreach ($rows as $row) { $out[$row['CONSTRAINT_NAME']][] = $row; } return $out;
    }

    private function checks(PDO $pdo): array
    {
        $rows = $this->query($pdo, "SELECT t.CONSTRAINT_NAME,t.ENFORCED,CAST(c.CHECK_CLAUSE AS BINARY) AS CHECK_CLAUSE FROM information_schema.TABLE_CONSTRAINTS t JOIN information_schema.CHECK_CONSTRAINTS c ON c.CONSTRAINT_SCHEMA=t.CONSTRAINT_SCHEMA AND c.CONSTRAINT_NAME=t.CONSTRAINT_NAME WHERE t.TABLE_SCHEMA=DATABASE() AND t.CONSTRAINT_TYPE='CHECK' AND (t.TABLE_NAME='pa_member_session' OR (t.TABLE_NAME='pa_member' AND t.CONSTRAINT_NAME='chk_member_security_session_revision')) ORDER BY t.CONSTRAINT_NAME");
        return array_column($rows, null, 'CONSTRAINT_NAME');
    }

    private function namedConstraints(PDO $pdo): array
    {
        return $this->query($pdo, 'SELECT TABLE_NAME,CONSTRAINT_NAME,CONSTRAINT_TYPE FROM information_schema.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA=DATABASE() AND CONSTRAINT_NAME IN (' . implode(',', array_fill(0, count(self::NAMES), '?')) . ') ORDER BY CONSTRAINT_NAME', self::NAMES);
    }

    /** Only the fixed predicates' source and native MySQL spellings; logical grouping and regex case remain significant. */
    private function knownCheck(string $name, string $clause): bool
    {
        // Preserve quoted literals byte-for-byte, including SQL doubled quotes and backslash escapes.
        $parts = preg_split(<<<'RX'
~('(?:''|\\.|[^'\\])*')~s
RX, $clause, -1, PREG_SPLIT_DELIM_CAPTURE);
        if ($parts === false) { return false; }
        $clause = '';
        foreach ($parts as $i => $part) {
            if ($i % 2 === 1) { $clause .= $part; continue; }
            $part = preg_replace_callback('/\b(?:regexp_like|regexp|is|not|null|and|or)\b/i', static fn(array $m): string => strtolower($m[0]), $part);
            $part = preg_replace('/[\s`]+/', '', $part);
            if ($i + 1 < count($parts)) { $part = preg_replace('/_(?:utf8mb4|ascii)\z/i', '', $part); }
            $clause .= $part;
        }
        $forms = match ($name) {
            'chk_member_security_session_revision','chk_member_session_record_revision' => ['session_revision>=1'],
            'chk_member_session_hash' => ["session_hashregexp'^[0-9a-f]{64}$'", "regexp_like(session_hash,'^[0-9a-f]{64}$')"],
            'chk_member_session_expiry' => ['expires_at>issued_at'],
            'chk_member_session_revocation' => [
                '(revoked_atisnullandrevoke_reasonisnull)or(revoked_atisnotnullandrevoked_at>=issued_atandrevoke_reasonisnotnull)',
                '((revoked_atisnull)and(revoke_reasonisnull))or((revoked_atisnotnull)and(revoked_at>=issued_at)and(revoke_reasonisnotnull))',
            ],
            default => [],
        };
        foreach ($forms as $form) { for ($i=0; $i<=4; ++$i) { if ($clause === str_repeat('(', $i) . $form . str_repeat(')', $i)) { return true; } } }
        return false;
    }

    /** SHOW/zero-row column resolution distinguishes absence from a metadata visibility or privilege error. */
    private function missingTable(PDO $pdo): bool
    {
        try { $this->query($pdo, 'SHOW CREATE TABLE `pa_member_session`'); return false; }
        catch (PDOException $error) { if ((int)($error->errorInfo[1] ?? 0) === 1146) { return true; } throw $error; }
    }

    private function missingRevision(PDO $pdo): bool
    {
        try { $this->query($pdo, 'SELECT session_revision FROM pa_member LIMIT 0'); return false; }
        catch (PDOException $error) { if ((int)($error->errorInfo[1] ?? 0) === 1054) { return true; } throw $error; }
    }

    private function identity(PDO $pdo): array
    {
        return $this->query($pdo, 'SELECT DATABASE() AS database_name,@@server_uuid AS server_uuid,CONNECTION_ID() AS connection_id')[0];
    }

    private function context(PDO $pdo): InstanceExecutionContext|InstallationExecutionContext
    {
        $context = $this->contexts->current();
        if (!($context instanceof InstanceExecutionContext || $context instanceof InstallationExecutionContext)
            || Db::connect()->getPdo() !== $pdo || $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'mysql'
            || $pdo->getAttribute(PDO::ATTR_CASE) !== PDO::CASE_NATURAL || $pdo->inTransaction()) { self::fail('NATIVE_DDL_CONTEXT_REQUIRED'); }
        if (Db::name('member')->getTable() !== 'pa_member' || Db::name('member_session')->getTable() !== 'pa_member_session') { self::fail('FIXED_TABLE_PREFIX_REQUIRED'); }
        return $context;
    }

    private function sqlHash(string $key): void
    {
        $path = dirname(__DIR__, 2) . '/database/migrations/20260921-create-member-session.sql';
        if ($key !== self::KEY || !is_file($path) || hash_file('sha256', $path) !== self::SQL_SHA256) { self::fail('SOURCE_CHANGED'); }
    }

    private function query(PDO $pdo, string $sql, array $bindings = []): array
    {
        $statement = $pdo->prepare($sql);
        try {
            $statement->execute($bindings);
            return array_map(static fn(array $row): array => array_map(static fn(mixed $v): ?string => $v === null ? null : (string)$v, $row), $statement->fetchAll(PDO::FETCH_ASSOC));
        } finally { $statement->closeCursor(); }
    }

    private function guard(callable $operation): array
    {
        try { return $operation(); }
        catch (Throwable $error) {
            if ($error instanceof RuntimeException && str_starts_with($error->getMessage(), 'MEMBER_SESSION_PRESERVATION_')) { throw $error; }
            throw new RuntimeException('MEMBER_SESSION_PRESERVATION_OBSERVATION_FAILED', 0, $error);
        }
    }
    private static function hash(array $value): string { return hash('sha256', json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)); }
    private static function fail(string $code): never { throw new RuntimeException('MEMBER_SESSION_PRESERVATION_' . $code); }
}
