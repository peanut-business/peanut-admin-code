<?php

declare(strict_types=1);

namespace PeanutAdmin\Modules\File\Contract;

use app\common\execution\CurrentExecutionContext;
use app\common\execution\InstallationExecutionContext;
use app\common\execution\InstanceExecutionContext;
use app\common\services\installation\InstallationExecutionHost;
use app\common\services\upgrade\MySqlMetadataObservation;
use PDO;
use RuntimeException;
use think\facade\Db;

/** File-only unchanged-row/delta checks. Admission, adoption proof and recovery belong to the caller. */
final readonly class FileMigrationPreservation
{
    public const VISIBILITY_STAGES = [
        'official.file:20261021060100_file_visibility_batches' => '3827e993c38855ce94ceaa2b7c0ae8c7082a9d231476e6c42358cd29f977edb7',
        'official.file:20261021060200_file_visibility_object_state' => '93ab8b34a84124f7d6ed86e7f36a7ea76e6ac122cc64f58810387506bef65b6b',
    ];
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
        ['file_visibility_batch', 'file_visibility_item'],
    ];
    private const IMAGE_INDEX = 'uk_file_object_tenant_key';

    public function __construct(private CurrentExecutionContext $contexts) {}

    /** The native reader is created only after this exact applying row has committed. */
    public function stageBefore(PDO $pdo, array $stage): array
    {
        return $this->readSnapshot($pdo, function () use ($pdo, $stage): array {
            $ledger = $this->visibilityLedger($pdo, $stage);
            $before = $this->before($pdo, $stage['migration_key']);
            if ($ledger !== $this->visibilityLedger($pdo, $stage)) {
                self::fail('LEDGER_CHANGED');
            }
            return ['module_key' => 'official.file', 'migration_key' => $stage['migration_key'], 'checksum' => $stage['checksum'],
                'readonly' => true, 'safe_summary' => $before['safe_summary'], 'stageLedger' => $stage['stageLedger'],
                'ledger' => $ledger, 'private_state' => $before['private_state']];
        });
    }

    public function stageAfter(PDO $pdo, array $before): array
    {
        return $this->readSnapshot($pdo, function () use ($pdo, $before): array {
            if (!is_array($before['private_state'] ?? null) || !is_array($before['ledger'] ?? null)
                || $before['ledger'] !== $this->visibilityLedger($pdo, $before)) {
                self::fail('LEDGER_CHANGED');
            }
            $after = $this->after($pdo, $before['private_state']);
            if ($before['ledger'] !== $this->visibilityLedger($pdo, $before)) {
                self::fail('LEDGER_CHANGED');
            }
            return $after + ['checksum' => $before['checksum'], 'readonly' => true,
                'metadata_sha256' => $after['after_sha256'], 'ledger_sha256' => self::hash($before['ledger']),
                'created_empty_tables' => $before['migration_key'] === array_key_first(self::VISIBILITY_STAGES) ? 2 : 0];
        });
    }

    private function visibilityLedger(PDO $pdo, array $stage): array
    {
        $this->context($pdo);
        $key = $stage['migration_key'] ?? '';
        if (($stage['module_key'] ?? null) !== 'official.file' || !isset(self::VISIBILITY_STAGES[$key])
            || ($stage['checksum'] ?? null) !== self::VISIBILITY_STAGES[$key]
            || !($stage['stageLedger'] ?? null) instanceof \Closure) {
            self::fail('NATIVE_STAGE_REQUIRED');
        }
        $rows = ($stage['stageLedger'])($pdo);
        if (!is_array($rows)) {
            self::fail('LEDGER_CHANGED');
        }
        $new = [];
        foreach ($rows as $row) {
            $active = ($row['migration_key'] ?? null) === $key;
            if (($row['module_key'] ?? null) !== 'official.file' || ($row['status'] ?? null) !== ($active ? 'applying' : 'applied')
                || ($row['error_code'] ?? null) !== null) {
                self::fail('LEDGER_CHANGED');
            }
            if (isset(self::VISIBILITY_STAGES[$row['migration_key']])) {
                if (($row['checksum'] ?? null) !== self::VISIBILITY_STAGES[$row['migration_key']]
                    || ($row['resolution_kind'] ?? null) !== ($active ? null : 'executed')
                    || ($row['provenance_json'] ?? null) !== null
                    || ($active ? ($row['finished_at'] ?? null) !== null : !is_string($row['finished_at'] ?? null))) {
                    self::fail('LEDGER_CHANGED');
                }
                $new[] = $row['migration_key'];
            }
        }
        $position = array_search($key, array_keys(self::VISIBILITY_STAGES), true);
        if ($new !== array_slice(array_keys(self::VISIBILITY_STAGES), 0, $position + 1)) {
            self::fail('LEDGER_CHANGED');
        }
        return $rows;
    }

    /** Return only safe_summary to logs/plans. Keep private_state in the held callback and pass it to after(). */
    public function before(PDO $pdo, string $canonicalMigrationKey): array
    {
        return $this->guard(function () use ($pdo, $canonicalMigrationKey): array {
            $context = $this->context($pdo);
            $sqlHash = $this->sqlHash($canonicalMigrationKey);
            $active = $pdo->inTransaction();
            $snapshot = $this->snapshot($pdo, isset(self::VISIBILITY_STAGES[$canonicalMigrationKey]));
            if (isset(self::VISIBILITY_STAGES[$canonicalMigrationKey])) {
                $schema = $snapshot['tables']['file_object']['metadata']['complete'] ?? null;
                if (!is_array($schema)) {
                    self::fail('VISIBILITY_PRESTATE_UNKNOWN');
                }
                $checks = array_column($schema['checks'], null, 'CONSTRAINT_NAME');
                foreach ($this->visibilityChecks(false) as $name => $expression) {
                    if (($checks[$name]['CHECK_CLAUSE'] ?? null) !== $expression) {
                        self::fail('VISIBILITY_PRESTATE_UNKNOWN');
                    }
                }
                if (isset($checks['chk_file_object_visibility_operation'])) {
                    self::fail('VISIBILITY_PRESTATE_UNKNOWN');
                }
                foreach (['file_visibility_batch','file_visibility_item'] as $name) {
                    if ($snapshot['tables'][$name] !== null) {
                        $this->visibilityTable($pdo, $name, $snapshot['tables'][$name]['metadata']['complete']);
                    }
                }
            }
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
            $new = $this->snapshot($pdo, isset(self::VISIBILITY_STAGES[$key]));
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
                        if ($current !== null && in_array($name, ['file_visibility_batch','file_visibility_item'], true)) {
                            $this->visibilityTable($pdo, $name, $current['metadata']['complete']);
                        }
                        continue;
                    }
                    if ($name === 'file_object' && ($delta['visibility_object'] ?? false)) {
                        if ($current === null) {
                            self::fail('ORIGINAL_SCHEMA_OR_COUNTER_CHANGED');
                        }
                        $this->assertVisibilityObject($pdo, $table, $current);
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
                'delta_kind' => isset(self::VISIBILITY_STAGES[$key]) ? 'file_visibility_append_only'
                    : ($delta['empty_legacy_unify'] ? 'empty_r1_file_unify'
                    : ($delta['new_tables'] === [] ? 'no_business_delta' : 'empty_file_family_increment')),
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
        if (isset(self::VISIBILITY_STAGES[$key])) {
            $first = $key === array_key_first(self::VISIBILITY_STAGES);
            foreach (array_slice(self::FAMILIES, 0, 3) as $family) {
                foreach ($family as $name) {
                    if ($tables[$name] === null) {
                        self::fail('BASE_POSTSTATE_REQUIRED');
                    }
                }
            }
            foreach (['file_visibility_batch','file_visibility_item'] as $name) {
                if ($first ? $tables[$name] !== null : ($tables[$name] === null || $tables[$name]['count'] !== 0)) {
                    self::fail('PARTIAL_FILE_FAMILY');
                }
            }
            $schema = $tables['file_object']['metadata']['complete'];
            if (isset($schema['columns']['visibility_batch_key']) || $schema['columns']['status']['COLUMN_TYPE'] !== 'varchar(16)') {
                self::fail('VISIBILITY_PRESTATE_UNKNOWN');
            }
            return ['new_tables' => $first ? ['file_visibility_batch','file_visibility_item'] : [],
                'image_index' => false, 'empty_legacy_unify' => false, 'visibility_object' => !$first];
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

    private function snapshot(PDO $pdo, bool $visibility = false): array
    {
        return $this->readSnapshot($pdo, function () use ($pdo, $visibility): array {
            $identity = $this->identity($pdo);
            $this->visibility($pdo, $identity['database']);
            $tables = [];
            foreach (array_merge(self::BASE, ...self::FAMILIES) as $name) {
                $physical = Db::name($name)->getTable();
                if (!is_string($physical)) {
                    self::fail('TABLE_IDENTITY_UNKNOWN');
                }
                self::q($physical);
                $complete = $visibility && in_array($name, ['file_object','file_visibility_batch','file_visibility_item'], true);
                $metadata = $this->metadata($pdo, $physical, $complete);
                if ($metadata === null) {
                    $tables[$name] = null;
                    continue;
                }
                $rows = $this->rowsDigest($pdo, $metadata);
                if ($this->metadata($pdo, $physical, $complete) !== $metadata) {
                    self::fail('DDL_CHANGED_DURING_READ');
                }
                $tables[$name] = ['metadata' => $metadata, ...$rows];
            }
            if ($identity !== $this->identity($pdo)) {
                self::fail('DATABASE_CHANGED');
            }
            return ['identity' => $identity, 'tables' => $tables];
        });
    }

    /** Keep native ledger and table observations in the same real read-only snapshot. */
    private function readSnapshot(PDO $pdo, \Closure $read): array
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
            return $read();
        } finally {
            try {
                if ($started && (!$pdo->inTransaction() || !$pdo->rollBack())) { self::fail('SNAPSHOT_CLOSE_FAILED'); }
            } finally {
                if ($statistics !== null) { $pdo->exec('SET SESSION information_schema_stats_expiry=' . $statistics); }
            }
        }
    }

    private function metadata(PDO $pdo, string $table, bool $complete = false): ?array
    {
        $info = $this->query($pdo,
            'SELECT ENGINE,TABLE_TYPE,TABLE_COLLATION FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?', [$table],
        );
        if ($info === []) { return null; }
        if (count($info) !== 1 || $info[0]['ENGINE'] !== 'InnoDB' || $info[0]['TABLE_TYPE'] !== 'BASE TABLE') {
            self::fail('TABLE_SHAPE_UNKNOWN');
        }
        $ddl = $this->query($pdo, 'SHOW CREATE TABLE ' . self::q($table));
        if (count($ddl) !== 1 || !is_string($ddl[0]['Create Table'] ?? null)) { self::fail('DDL_UNAVAILABLE'); }
        $columns = $this->query($pdo,
            'SELECT COLUMN_NAME,COLUMN_TYPE,IS_NULLABLE,COLUMN_DEFAULT,EXTRA,GENERATION_EXPRESSION,CHARACTER_SET_NAME,COLLATION_NAME'
            . ' FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? ORDER BY ORDINAL_POSITION', [$table],
        );
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
            "SELECT COLUMN_NAME FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND INDEX_NAME='PRIMARY' ORDER BY SEQ_IN_INDEX", [$table],
        ), 'COLUMN_NAME');
        if ($primary === [] || array_diff($primary, array_column($columns, 'COLUMN_NAME')) !== []) { self::fail('PRIMARY_KEY_UNKNOWN'); }
        $triggers = $this->query($pdo,
            'SELECT TRIGGER_NAME,EVENT_MANIPULATION,ACTION_ORDER,ACTION_CONDITION,ACTION_STATEMENT,ACTION_ORIENTATION,ACTION_TIMING,'
            . 'SQL_MODE,DEFINER,CHARACTER_SET_CLIENT,COLLATION_CONNECTION,DATABASE_COLLATION,CREATED'
            . ' FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA=DATABASE() AND EVENT_OBJECT_TABLE=? ORDER BY TRIGGER_NAME', [$table],
        );
        return ['physical_table' => $table, 'ddl' => $ddl[0]['Create Table'], 'columns' => $columns,
            'primary' => $primary, 'triggers' => $triggers,
            'auto_increment' => $auto === null ? null : (string) $auto]
            + ($complete ? ['complete' => MySqlMetadataObservation::tableSchema($pdo, $table)] : []);
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

    public function visibilityTable(PDO $pdo, string $name, array $image): void
    {
        $this->context($pdo);
        if (!in_array($name, ['file_visibility_batch','file_visibility_item'], true)) {
            self::fail('VISIBILITY_SCHEMA_UNKNOWN');
        }
        $key = array_key_first(self::VISIBILITY_STAGES);
        $this->sqlHash($key);
        $sql = (string) file_get_contents(dirname(__DIR__, 2) . '/database/migrations/' . substr($key, strlen('official.file:')) . '.sql');
        $expected = MySqlMetadataObservation::createTableSchema($sql, 'pa_' . $name, $this->identity($pdo)['database']);
        foreach ($expected as $field => $value) {
            if (self::hash($image[$field]) !== self::hash($value)) {
                self::fail('VISIBILITY_SCHEMA_UNKNOWN');
            }
        }
        if ($image['auto_increment'] !== null || $image['triggers'] !== []) {
            self::fail('VISIBILITY_SCHEMA_UNKNOWN');
        }
    }

    private function visibilityChecks(bool $after): array
    {
        $expressions = [
            'chk_file_object_status' => "`status` IN ('pending_write','write_failed','ready','archived')",
            'chk_file_object_archive_shape' => "((`status` IN ('pending_write','write_failed','ready')) AND `archived_at` IS NULL) OR (`status`='archived' AND `archived_at` IS NOT NULL)",
        ];
        if ($after) {
            $key = array_key_last(self::VISIBILITY_STAGES);
            $this->sqlHash($key);
            $sql = (string) file_get_contents(dirname(__DIR__, 2) . '/database/migrations/' . substr($key, strlen('official.file:')) . '.sql');
            foreach (['chk_file_object_status','chk_file_object_archive_shape','chk_file_object_visibility_operation'] as $name) {
                if (preg_match('/ADD CONSTRAINT `' . $name . '` CHECK \(([^\r\n]+)\)(?:,|;)/', $sql, $match) !== 1) {
                    self::fail('SOURCE_CHANGED');
                }
                $expressions[$name] = $match[1];
            }
        }
        return array_map(MySqlMetadataObservation::checkExpression(...), $expressions);
    }

    /** Preserve all original columns/rows and complete metadata; accept only the fixed additive state profile. */
    private function assertVisibilityObject(PDO $pdo, array $old, array $new): void
    {
        $metadata = $new['metadata'];
        $complete = $metadata['complete'];
        $original = $old['metadata']['complete'];
        $column = $complete['columns']['visibility_batch_key'] ?? null;
        $expected = ['COLUMN_NAME' => 'visibility_batch_key', 'ORDINAL_POSITION' => (string) (count($original['columns']) + 1),
            'COLUMN_TYPE' => 'varchar(64)', 'IS_NULLABLE' => 'YES', 'COLUMN_DEFAULT' => null, 'EXTRA' => '',
            'CHARACTER_SET_NAME' => 'ascii', 'COLLATION_NAME' => 'ascii_bin', 'COLUMN_COMMENT' => '', 'GENERATION_EXPRESSION' => ''];
        if ($column !== $expected || ($complete['columns']['status']['COLUMN_TYPE'] ?? null) !== 'varchar(32)'
            || count($complete['columns']) !== count($original['columns']) + 1
            || $this->query($pdo, 'SELECT COUNT(*) AS n FROM pa_file_object WHERE visibility_batch_key IS NOT NULL')[0]['n'] != 0) {
            self::fail('VISIBILITY_SCHEMA_UNKNOWN');
        }
        $checks = array_column($complete['checks'], null, 'CONSTRAINT_NAME');
        foreach ($this->visibilityChecks(true) as $name => $expression) {
            if (($checks[$name]['CHECK_CLAUSE'] ?? null) !== $expression || $checks[$name]['ENFORCED'] !== 'YES') {
                self::fail('VISIBILITY_SCHEMA_UNKNOWN');
            }
        }
        unset($checks['chk_file_object_visibility_operation']);
        foreach ($original['checks'] as $check) {
            if (isset($this->visibilityChecks(false)[$check['CONSTRAINT_NAME']])) {
                $checks[$check['CONSTRAINT_NAME']] = $check;
            }
        }
        ksort($checks, SORT_STRING);
        $complete['checks'] = array_values($checks);
        unset($complete['columns']['visibility_batch_key']);
        $complete['columns']['status']['COLUMN_TYPE'] = 'varchar(16)';
        $stripChecks = static function (string $ddl, int $count): string {
            $value = preg_replace('/^  CONSTRAINT `[^`]+` CHECK [^\r\n]+(?:\r?\n|$)/m', '', $ddl, -1, $removed);
            if (!is_string($value) || $removed !== $count) {
                self::fail('VISIBILITY_SCHEMA_UNKNOWN');
            }
            return $value;
        };
        $ddl = preg_replace('/^  `visibility_batch_key` [^\r\n]+\r?\n/m', '', $metadata['ddl'], -1, $removed);
        if (!is_string($ddl) || $removed !== 1
            || preg_match('/^  `status` [^\r\n]+/m', $old['metadata']['ddl'], $status) !== 1) {
            self::fail('VISIBILITY_SCHEMA_UNKNOWN');
        }
        $ddl = preg_replace_callback('/^  `status` [^\r\n]+/m', static fn() => $status[0], $ddl, -1, $removed);
        if ($removed !== 1 || $stripChecks($ddl, count($new['metadata']['complete']['checks']))
            !== $stripChecks($old['metadata']['ddl'], count($original['checks']))) {
            self::fail('VISIBILITY_SCHEMA_UNKNOWN');
        }
        $complete['ddl'] = $original['ddl'];
        if ($complete !== $original) {
            self::fail('ORIGINAL_SCHEMA_OR_COUNTER_CHANGED');
        }
        $projection = $metadata;
        $projection['columns'] = $old['metadata']['columns'];
        $rows = $this->rowsDigest($pdo, $projection);
        if ($rows['count'] !== $old['count'] || $rows['rows_sha256'] !== $old['rows_sha256']) {
            self::fail('ORIGINAL_ROWS_CHANGED');
        }
        $metadata['columns'] = $old['metadata']['columns'];
        $metadata['ddl'] = $old['metadata']['ddl'];
        $metadata['complete'] = $complete;
        if ($metadata !== $old['metadata']) {
            self::fail('ORIGINAL_SCHEMA_OR_COUNTER_CHANGED');
        }
    }

    private function identity(PDO $pdo): array
    {
        $this->context($pdo);
        $row = $this->query($pdo,
            'SELECT DATABASE() AS database_name,@@server_uuid AS server_uuid,CONNECTION_ID() AS connection_id,'
            . '@@session.transaction_isolation AS session_default_isolation,@@session.sql_mode AS sql_mode,@@session.time_zone AS time_zone',
        );
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
        $hash = self::SQL_HASHES[$key] ?? self::VISIBILITY_STAGES[$key] ?? null;
        if ($hash === null || (!isset(self::VISIBILITY_STAGES[$key]) && !in_array($key, FileMigrationAdoptionProof::MIGRATION_KEYS, true))) {
            self::fail('MIGRATION_UNKNOWN');
        }
        $path = dirname(__DIR__, 2) . '/database/migrations/' . substr($key, strlen('official.file:')) . '.sql';
        if (!is_file($path) || is_link($path) || hash_file('sha256', $path) !== $hash) { self::fail('SOURCE_CHANGED'); }
        return $hash;
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
