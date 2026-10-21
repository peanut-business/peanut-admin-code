<?php

declare(strict_types=1);

namespace PeanutAdmin\Modules\File\Contract;

use app\common\execution\CurrentExecutionContext;
use app\common\execution\InstallationExecutionContext;
use app\common\execution\InstanceExecutionContext;
use app\common\services\installation\InstallationExecutionHost;
use PDO;
use RuntimeException;
use think\facade\Db;

/** File-only unchanged-row/delta checks. Admission, adoption proof and recovery belong to the caller. */
final readonly class FileMigrationPreservation
{
    private const PROTOCOL = 'peanut.file-migration-preservation.v1';
    private const SQL_HASHES = [
        'official.file:20260823-unify-storage-service' => 'f79c35b2fe8414dc172a81a45183d2a52eadb05833a704149780b36e400ca2d4',
        'official.file:20260921020000_add_file_image_assets' => '826fa9d8fd841b6834bc5f23cabced30b7ca111de4bb1f629ccbdb8501200cd4',
        'official.file:20261012030000_add_controlled_file_references' => '005edad54fc0cf06a4eeb6afa7c0e5f374a0bc352eaa088282b2f12932251591',
        'official.file:20261012051000_add_immutable_document_snapshots' => '7b73c0c24f14f4c7f4fc1f75321e009b1a645d7df3f7d653dbb3d018e3b5bdbe',
    ];
    private const BASE = ['file', 'file_object', 'storage_account', 'storage_space', 'storage_route'];
    private const FAMILIES = [
        ['file_image_asset', 'file_derivative', 'file_delivery_nonce'],
        ['file_delivery_grant', 'file_reference'],
        ['file_immutable_snapshot'],
    ];
    private const IMAGE_INDEX = 'uk_file_object_tenant_key';

    public function __construct(private CurrentExecutionContext $contexts) {}

    /** Return only safe_summary to logs/plans. Keep private_state in the held callback and pass it to after(). */
    public function before(PDO $pdo, string $canonicalMigrationKey): array
    {
        return $this->guard(function () use ($pdo, $canonicalMigrationKey): array {
            $context = $this->context($pdo);
            $sqlHash = $this->sqlHash($canonicalMigrationKey);
            $active = $pdo->inTransaction();
            $snapshot = $this->snapshot($pdo);
            $delta = $this->delta($snapshot['tables'], $canonicalMigrationKey);
            $unify = $delta['empty_legacy_unify'] ? [
                'authority' => app()->make(InstallationExecutionHost::class)->fileUnifyContinuationAdmission($pdo, $canonicalMigrationKey),
                'clock' => (string) $pdo->query('SELECT UTC_TIMESTAMP(3)')->fetchColumn(),
            ] : null;
            if ($this->context($pdo) !== $context || $pdo->inTransaction() !== $active) {
                self::fail('CONTEXT_CHANGED');
            }
            $hash = self::hash($snapshot);
            return [
                'safe_summary' => ['module_key' => 'official.file', 'migration_key' => $canonicalMigrationKey,
                    'sql_sha256' => $sqlHash, 'table_counts' => self::counts($snapshot['tables']),
                    'before_sha256' => $hash, 'preservation_verified' => false],
                'private_state' => ['protocol' => self::PROTOCOL, 'pdo' => $pdo, 'context' => $context,
                    'migration_key' => $canonicalMigrationKey, 'sql_sha256' => $sqlHash,
                    'transaction_active' => $active, 'snapshot' => $snapshot, 'before_sha256' => $hash, 'unify' => $unify],
            ];
        });
    }

    /** Consumes before()['private_state']; the result contains no raw DDL, values or private state. */
    public function after(PDO $pdo, array $privateBefore): array
    {
        return $this->guard(function () use ($pdo, $privateBefore): array {
            $expected = ['protocol', 'pdo', 'context', 'migration_key', 'sql_sha256', 'transaction_active', 'snapshot', 'before_sha256', 'unify'];
            if (count($privateBefore) !== count($expected) || array_diff(array_keys($privateBefore), $expected) !== []
                || ($privateBefore['protocol'] ?? null) !== self::PROTOCOL || ($privateBefore['pdo'] ?? null) !== $pdo
                || ($privateBefore['context'] ?? null) !== $this->context($pdo)
                || ($privateBefore['transaction_active'] ?? null) !== $pdo->inTransaction()
                || !is_string($privateBefore['migration_key'] ?? null) || !is_array($privateBefore['snapshot'] ?? null)
                || !is_string($privateBefore['before_sha256'] ?? null)
                || !hash_equals($privateBefore['before_sha256'], self::hash($privateBefore['snapshot']))) {
                self::fail('BEFORE_BINDING_INVALID');
            }
            $key = $privateBefore['migration_key'];
            $sqlHash = $this->sqlHash($key);
            if ($privateBefore['sql_sha256'] !== $sqlHash) { self::fail('SOURCE_CHANGED'); }
            $old = $privateBefore['snapshot'];
            $delta = $this->delta($old['tables'], $key);
            $new = $this->snapshot($pdo);
            if ($old['identity'] !== $new['identity']) { self::fail('DATABASE_CHANGED'); }
            if ($delta['empty_legacy_unify']) {
                $this->assertEmptyLegacyUnify($pdo, $key, $old['tables'], $new['tables'], $privateBefore['unify']);
            } else {
              if ($privateBefore['unify'] !== null) { self::fail('BEFORE_BINDING_INVALID'); }
              foreach ($old['tables'] as $name => $table) {
                $current = $new['tables'][$name];
                if ($table === null) {
                    if (!in_array($name, $delta['new_tables'], true)) {
                        if ($current !== null) { self::fail('UNDECLARED_TABLE_DELTA'); }
                    } elseif ($current === null || $current['count'] !== 0 || $current['metadata']['triggers'] !== []
                        || $current['metadata']['auto_increment'] !== ($name === 'file_reference' ? '1' : null)) {
                        self::fail('NEW_TABLE_NOT_EMPTY_OR_UNKNOWN');
                    }
                    continue;
                }
                if ($current === null || $table['count'] !== $current['count'] || $table['rows_sha256'] !== $current['rows_sha256']) {
                    self::fail('ORIGINAL_ROWS_CHANGED');
                }
                $metadata = $current['metadata'];
                if ($name === 'file_object' && $delta['image_index']) {
                    $metadata['ddl'] = preg_replace(
                        '/^  UNIQUE KEY `uk_file_object_tenant_key` \(`tenant_id`,`file_key`\),\r?\n/m',
                        '', $metadata['ddl'], -1, $removed,
                    );
                    if ($removed !== 1 || !is_string($metadata['ddl'])) { self::fail('IMAGE_INDEX_DELTA_UNKNOWN'); }
                }
                if ($metadata !== $table['metadata']) { self::fail('ORIGINAL_SCHEMA_OR_COUNTER_CHANGED'); }
              }
            }
            if ($this->context($pdo) !== $privateBefore['context'] || $pdo->inTransaction() !== $privateBefore['transaction_active']) {
                self::fail('CONTEXT_CHANGED');
            }
            return ['module_key' => 'official.file', 'migration_key' => $key, 'sql_sha256' => $sqlHash,
                'preservation_verified' => true, 'before_sha256' => $privateBefore['before_sha256'],
                'after_sha256' => self::hash($new), 'table_counts' => self::counts($new['tables']),
                'delta_kind' => $delta['empty_legacy_unify'] ? 'empty_r1_file_unify'
                    : ($delta['new_tables'] === [] ? 'no_business_delta' : 'empty_file_family_increment'),
                'delta_sha256' => self::hash($delta)];
        });
    }

    /** Observe presence only; FileMigrationAdoptionProof remains the sole execute/adopt/reject authority. */
    private function delta(array $tables, string $key): array
    {
        if ($key === FileMigrationAdoptionProof::MIGRATION_KEYS[0]
            && ($tables['file']['count'] ?? null) === 0 && ($tables['file_object']['count'] ?? null) === 0) {
            $legacy = true;
            foreach ($tables as $name => $table) {
                if (!in_array($name, ['file', 'file_object'], true) && $table !== null) { $legacy = false; }
            }
            if ($legacy) {
                // Presence is not execution authority: the host and the sole File proof both remain mandatory.
                return ['new_tables' => ['storage_account', 'storage_space', 'storage_route'], 'image_index' => false, 'empty_legacy_unify' => true];
            }
        }
        foreach (self::BASE as $name) {
            if (($tables[$name] ?? null) === null) { self::fail('BASE_POSTSTATE_REQUIRED'); }
        }
        $position = array_search($key, FileMigrationAdoptionProof::MIGRATION_KEYS, true);
        if ($position === false) { self::fail('MIGRATION_UNKNOWN'); }
        $new = [];
        foreach (self::FAMILIES as $i => $family) {
            $present = 0;
            foreach ($family as $name) { $present += ($tables[$name] ?? null) !== null ? 1 : 0; }
            if ($present !== 0 && $present !== count($family)) { self::fail('PARTIAL_FILE_FAMILY'); }
            if ($position === $i + 1 && $present === 0) { $new = $family; }
            if ($i === 0) {
                $hasIndex = str_contains($tables['file_object']['metadata']['ddl'], '`' . self::IMAGE_INDEX . '`');
                if ($hasIndex !== ($present !== 0)) { self::fail('PARTIAL_IMAGE_INDEX'); }
            }
        }
        if ($new !== []) {
            foreach ($tables as $name => $table) {
                $physical = $table['metadata']['physical_table'] ?? Db::name($name)->getTable();
                if ($physical !== 'pa_' . $name) { self::fail('EXECUTE_PREFIX_UNSUPPORTED'); }
            }
        }
        return ['new_tables' => $new, 'image_index' => $position === 1 && $new !== [], 'empty_legacy_unify' => false];
    }

    /** The native caller has already run File's full canonical postshape proof before this callback. */
    private function assertEmptyLegacyUnify(PDO $pdo, string $key, array $old, array $new, mixed $before): void
    {
        if (!is_array($before) || array_keys($before) !== ['authority', 'clock'] || !is_array($before['authority'])
            || !is_string($before['clock'])) { self::fail('BEFORE_BINDING_INVALID'); }
        $authority = app()->make(InstallationExecutionHost::class)->fileUnifyContinuationAdmission($pdo, $key);
        if ($authority !== $before['authority']) { self::fail('CONTINUATION_AUTHORITY_CHANGED'); }
        foreach (['file', 'file_object'] as $name) {
            if (($old[$name]['count'] ?? null) !== 0 || ($new[$name]['count'] ?? null) !== 0) { self::fail('ORIGINAL_ROWS_CHANGED'); }
            // Only the exact two proven empty schemas may change. Their physical identity,
            // original primary key, triggers and counters cannot be reset by the ALTERs.
            foreach (['physical_table', 'primary', 'triggers', 'auto_increment'] as $field) {
                if ($old[$name]['metadata'][$field] !== $new[$name]['metadata'][$field]) { self::fail('ORIGINAL_SCHEMA_OR_COUNTER_CHANGED'); }
            }
        }
        foreach (array_merge(...self::FAMILIES) as $name) {
            if ($old[$name] !== null || $new[$name] !== null) { self::fail('UNDECLARED_TABLE_DELTA'); }
        }
        foreach (['storage_account' => [1, '2'], 'storage_space' => [2, '3'], 'storage_route' => [2, null]] as $name => [$count, $counter]) {
            if ($old[$name] !== null || ($new[$name]['count'] ?? null) !== $count
                || $new[$name]['metadata']['triggers'] !== [] || $new[$name]['metadata']['auto_increment'] !== $counter) {
                self::fail('CANONICAL_STORAGE_DEFAULTS_REQUIRED');
            }
        }
        $end = (string) $pdo->query('SELECT UTC_TIMESTAMP(3)')->fetchColumn();
        if (strcmp($before['clock'], $end) > 0) { self::fail('SQL_CLOCK_CHANGED'); }
        $account = $this->query($pdo, "SELECT COUNT(*) AS n FROM pa_storage_account WHERE id=1 AND account_key='local' AND driver='local' AND name='本地存储'"
            . " AND credential_ciphertext IS NULL AND credential_key_version IS NULL AND credential_rotated_at IS NULL AND status='active'"
            . ' AND created_at=updated_at AND created_at BETWEEN ? AND ?', [$before['clock'], $end]);
        $spaces = $this->query($pdo, "SELECT COUNT(*) AS n FROM pa_storage_space WHERE account_id=1 AND status='active'"
            . ' AND bucket IS NULL AND region IS NULL AND endpoint IS NULL AND access_domain IS NULL AND created_at=updated_at'
            . " AND ((id=1 AND space_key='local-public' AND name='本地公开文件' AND access_type='public' AND local_path='public/storage')"
            . " OR (id=2 AND space_key='local-private' AND name='本地私有文件' AND access_type='private' AND local_path='private/storage'))"
            . ' AND created_at BETWEEN ? AND ?', [$before['clock'], $end]);
        $routes = $this->query($pdo, "SELECT COUNT(*) AS n FROM pa_storage_route WHERE ((route_key='default.public' AND access_type='public' AND space_id=1)"
            . " OR (route_key='default.private' AND access_type='private' AND space_id=2)) AND updated_at BETWEEN ? AND ?", [$before['clock'], $end]);
        if ((int) ($account[0]['n'] ?? -1) !== 1 || (int) ($spaces[0]['n'] ?? -1) !== 2 || (int) ($routes[0]['n'] ?? -1) !== 2) {
            self::fail('CANONICAL_STORAGE_DEFAULTS_REQUIRED');
        }
        // The host authority includes its independently observed whole-config digest. Equality
        // proves the original DELETE touched no storage row or unrelated configuration value.
    }

    private function snapshot(PDO $pdo): array
    {
        $owned = !$pdo->inTransaction();
        $started = false; $statistics = null;
        // Only our new snapshot gets explicit RR/READ ONLY. A caller-owned transaction
        // retains its characteristics and requires the caller's held writer/DDL exclusion.
        try {
            $value = $this->query($pdo, 'SELECT @@SESSION.information_schema_stats_expiry AS expiry')[0]['expiry'] ?? null;
            if (!is_int($value) && (!is_string($value) || preg_match('/\A[0-9]+\z/D', $value) !== 1)) { self::fail('STATISTICS_UNAVAILABLE'); }
            $statistics = (int) $value;
            $pdo->exec('SET SESSION information_schema_stats_expiry=0');
            if ($owned) {
                $pdo->exec('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
                $pdo->exec('SET TRANSACTION READ ONLY');
                $pdo->beginTransaction(); $started = true;
            }
            $identity = $this->identity($pdo);
            $this->visibility($pdo, $identity['database']);
            $tables = [];
            foreach (array_merge(self::BASE, ...self::FAMILIES) as $name) {
                $physical = Db::name($name)->getTable();
                if (!is_string($physical)) { self::fail('TABLE_IDENTITY_UNKNOWN'); }
                self::q($physical);
                $metadata = $this->metadata($pdo, $physical);
                if ($metadata === null) { $tables[$name] = null; continue; }
                $rows = $this->rowsDigest($pdo, $metadata);
                if ($this->metadata($pdo, $physical) !== $metadata) { self::fail('DDL_CHANGED_DURING_READ'); }
                $tables[$name] = ['metadata' => $metadata, ...$rows];
            }
            if ($identity !== $this->identity($pdo)) { self::fail('DATABASE_CHANGED'); }
            return ['identity' => $identity, 'tables' => $tables];
        } finally {
            try {
                if ($started && (!$pdo->inTransaction() || !$pdo->rollBack())) { self::fail('SNAPSHOT_CLOSE_FAILED'); }
            } finally {
                if ($statistics !== null) { $pdo->exec('SET SESSION information_schema_stats_expiry=' . $statistics); }
            }
        }
    }

    private function metadata(PDO $pdo, string $table): ?array
    {
        $info = $this->query($pdo,
            'SELECT ENGINE,TABLE_TYPE,TABLE_COLLATION FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?', [$table]);
        if ($info === []) { return null; }
        if (count($info) !== 1 || $info[0]['ENGINE'] !== 'InnoDB' || $info[0]['TABLE_TYPE'] !== 'BASE TABLE') {
            self::fail('TABLE_SHAPE_UNKNOWN');
        }
        $ddl = $this->query($pdo, 'SHOW CREATE TABLE ' . self::q($table));
        if (count($ddl) !== 1 || !is_string($ddl[0]['Create Table'] ?? null)) { self::fail('DDL_UNAVAILABLE'); }
        $columns = $this->query($pdo,
            'SELECT COLUMN_NAME,COLUMN_TYPE,IS_NULLABLE,COLUMN_DEFAULT,EXTRA,GENERATION_EXPRESSION,CHARACTER_SET_NAME,COLLATION_NAME'
            . ' FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? ORDER BY ORDINAL_POSITION', [$table]);
        if ($columns === []) { self::fail('COLUMNS_UNKNOWN'); }
        // SHOW CREATE initializes a new empty InnoDB allocator before this uncached read.
        $allocator = $this->query($pdo, 'SELECT AUTO_INCREMENT FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?', [$table]);
        if (count($allocator) !== 1 || !array_key_exists('AUTO_INCREMENT', $allocator[0])) { self::fail('STATISTICS_UNAVAILABLE'); }
        $auto = $allocator[0]['AUTO_INCREMENT'];
        $hasAuto = array_filter($columns, static fn(array $column): bool => str_contains($column['EXTRA'], 'auto_increment')) !== [];
        if ($hasAuto ? preg_match('/\A[1-9][0-9]*\z/D', (string) $auto) !== 1 : $auto !== null) { self::fail('STATISTICS_UNAVAILABLE'); }
        foreach ($columns as $column) {
            self::q($column['COLUMN_NAME']);
            if ($column['GENERATION_EXPRESSION'] !== '') { self::fail('GENERATED_COLUMN_UNKNOWN'); }
        }
        $primary = array_column($this->query($pdo,
            "SELECT COLUMN_NAME FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND INDEX_NAME='PRIMARY' ORDER BY SEQ_IN_INDEX", [$table]), 'COLUMN_NAME');
        if ($primary === [] || array_diff($primary, array_column($columns, 'COLUMN_NAME')) !== []) { self::fail('PRIMARY_KEY_UNKNOWN'); }
        $triggers = $this->query($pdo,
            'SELECT TRIGGER_NAME,EVENT_MANIPULATION,ACTION_ORDER,ACTION_CONDITION,ACTION_STATEMENT,ACTION_ORIENTATION,ACTION_TIMING,'
            . 'SQL_MODE,DEFINER,CHARACTER_SET_CLIENT,COLLATION_CONNECTION,DATABASE_COLLATION,CREATED'
            . ' FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA=DATABASE() AND EVENT_OBJECT_TABLE=? ORDER BY TRIGGER_NAME', [$table]);
        return ['physical_table' => $table, 'ddl' => $ddl[0]['Create Table'], 'columns' => $columns,
            'primary' => $primary, 'triggers' => $triggers,
            'auto_increment' => $auto === null ? null : (string) $auto];
    }

    /** Bounded keyset pages, including composite/string keys; no all-table PDO result buffer or row DTO. */
    private function rowsDigest(PDO $pdo, array $metadata): array
    {
        $columns = array_column($metadata['columns'], 'COLUMN_NAME');
        $primary = $metadata['primary'];
        $projection = [];
        foreach ($columns as $i => $column) { $projection[] = 'CAST(' . self::q($column) . ' AS BINARY) AS ' . self::q('c' . $i); }
        $keyAliases = [];
        foreach ($primary as $column) { $keyAliases[] = 'c' . array_search($column, $columns, true); }
        $order = implode(',', array_map(self::q(...), $primary));
        $hash = hash_init('sha256');
        hash_update($hash, self::json(['columns' => $columns, 'primary' => $primary]) . "\n");
        $count = 0; $cursor = null;
        do {
            $where = ''; $bindings = [];
            if ($cursor !== null) {
                $terms = [];
                foreach ($primary as $i => $column) {
                    $parts = [];
                    for ($j = 0; $j < $i; ++$j) { $parts[] = self::q($primary[$j]) . '=?'; $bindings[] = $cursor[$j]; }
                    $parts[] = self::q($column) . '>?'; $bindings[] = $cursor[$i];
                    $terms[] = '(' . implode(' AND ', $parts) . ')';
                }
                $where = ' WHERE ' . implode(' OR ', $terms);
            }
            $statement = $pdo->prepare('SELECT ' . implode(',', $projection) . ' FROM ' . self::q($metadata['physical_table'])
                . $where . ' ORDER BY ' . $order . ' LIMIT 64');
            $statement->execute($bindings);
            $page = 0;
            try {
                while (($row = $statement->fetch(PDO::FETCH_ASSOC)) !== false) {
                    foreach ($columns as $i => $_column) {
                        $value = $row['c' . $i];
                        if ($value !== null && !is_string($value)) { self::fail('ROW_BYTES_UNKNOWN'); }
                        hash_update($hash, $value === null ? 'N;' : 'S' . strlen($value) . ':' . $value . ';');
                    }
                    hash_update($hash, "\n");
                    $next = [];
                    foreach ($keyAliases as $alias) {
                        if (!is_string($row[$alias])) { self::fail('PRIMARY_KEY_BYTES_UNKNOWN'); }
                        $next[] = $row[$alias];
                    }
                    if ($cursor === $next) { self::fail('PRIMARY_KEY_PROGRESS_UNKNOWN'); }
                    $cursor = $next;
                    ++$page; ++$count;
                    unset($row);
                }
            } finally { $statement->closeCursor(); }
        } while ($page === 64);
        return ['count' => $count, 'rows_sha256' => hash_final($hash)];
    }

    private function identity(PDO $pdo): array
    {
        $this->context($pdo);
        $row = $this->query($pdo,
            'SELECT DATABASE() AS database_name,@@server_uuid AS server_uuid,CONNECTION_ID() AS connection_id,'
            . '@@session.transaction_isolation AS session_default_isolation,@@session.sql_mode AS sql_mode,@@session.time_zone AS time_zone');
        // SET TRANSACTION overrides the next transaction without changing this session default.
        // Record it as a connection fact, never as evidence of the current transaction's isolation.
        if (count($row) !== 1 || !is_string($row[0]['database_name']) || $row[0]['database_name'] === '') {
            self::fail('DATABASE_UNKNOWN');
        }
        return ['database' => $row[0]['database_name'], ...$row[0]];
    }

    /** Schema-wide direct privileges establish absence/trigger visibility; role-only/partial grants stay unknown. */
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
            if (!preg_match('/\AGRANT (.+) ON (.+) TO /D', $grant, $match)
                || !in_array($match[2], $scopes, true)) { continue; }
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
            || Db::connect()->getPdo() !== $pdo || $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'mysql') {
            self::fail('NATIVE_CONTEXT_OR_PDO_REQUIRED');
        }
        return $context;
    }

    private function sqlHash(string $key): string
    {
        if (!isset(self::SQL_HASHES[$key]) || !in_array($key, FileMigrationAdoptionProof::MIGRATION_KEYS, true)) {
            self::fail('MIGRATION_UNKNOWN');
        }
        $path = dirname(__DIR__, 2) . '/database/migrations/' . substr($key, strlen('official.file:')) . '.sql';
        if (!is_file($path) || hash_file('sha256', $path) !== self::SQL_HASHES[$key]) { self::fail('SOURCE_CHANGED'); }
        return self::SQL_HASHES[$key];
    }

    /** Only bounded schema metadata uses this collector; business rows always use rowsDigest(). */
    private function query(PDO $pdo, string $sql, array $bindings = []): array
    {
        $statement = $pdo->prepare($sql);
        $statement->execute($bindings);
        $rows = [];
        try { while (($row = $statement->fetch(PDO::FETCH_ASSOC)) !== false) { $rows[] = $row; } }
        finally { $statement->closeCursor(); }
        return $rows;
    }

    private function guard(callable $operation): array
    {
        try { return $operation(); }
        catch (\Throwable $error) {
            // Never chain driver exceptions containing SQL, grants or private metadata to a public failure.
            $code = $error instanceof RuntimeException && preg_match('/\AFILE_PRESERVATION_[A-Z_]+\z/D', $error->getMessage()) === 1
                ? $error->getMessage() : 'FILE_PRESERVATION_OBSERVATION_FAILED';
            throw new RuntimeException($code);
        }
    }

    private static function counts(array $tables): array
    {
        $counts = [];
        foreach ($tables as $name => $table) { $counts[$name] = $table === null ? null : $table['count']; }
        return $counts;
    }

    private static function q(string $name): string
    {
        if (preg_match('/\A[a-zA-Z0-9_]+\z/D', $name) !== 1) { self::fail('IDENTIFIER_UNKNOWN'); }
        return '`' . $name . '`';
    }

    private static function json(array $value): string
    {
        return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    private static function hash(array $value): string { return hash('sha256', self::json($value)); }
    private static function fail(string $code): never { throw new RuntimeException('FILE_PRESERVATION_' . $code); }
}
