<?php

declare(strict_types=1);

namespace app\common\services\upgrade;

use app\common\services\installation\InstallationExecutionHost;
use app\common\value\installation\ApplicationSourceIdentity;
use app\common\value\installation\ServerReleaseIdentity;
use PDO;
use RuntimeException;

/** Owns application-schema admission, not SQL execution, backup or deployment. */
final class ApplicationSchemaHost
{
    private const PROTOCOL = 'peanut.application-schema-plan.v1';
    private const RESULT_PROTOCOL = 'peanut.application-schema-result.v1';

    /** Immutable SQL understood by the source-specific read-only proofs below. */
    private const PRESTATE_SQL = [
        '20260909-notification-sms-reservation' => '4446c1feb1d3226de810682f79e246c327f1d631b22f507ad725ae960f151753',
        '20261004-group-default-navigation' => '76124c9bb3672beef79622004e9e8f4f18d9f14ee07000c05f7251544d207ec2',
        '20261004-menu-stable-identities' => 'fbe16c60e6e580b78058a6f1c11cf78c0f5b43483d1490730b64ab134bd4cdb3',
        '20261007-auth-client-schema' => '8411ed958b0d5d70c6a30771f5d1b8fd85f1cd6ae047a94f8404fa7737baf38b',
        '20261007-readiness-permission' => '7a275b6475893fd0076ef8c3f6386d53343ef30cf3d0434dbef317fedcc28034',
        '20261012-align-department-canonical-permissions' => '1267c4b33baa4567ae45f12eac6edc7d0538cd9fac4f9c4ccc61e2b37eb793bf',
        '20261012-align-platform-ops-permission-ownership' => '038766e7e9ab5356cacf11cc6bb2dd675af02cd9bd3180efd356a8234d6aa1d9',
        '20261012-repair-seeded-permission-parents' => '06c5e8cad88139ba64cbd498765082a52df43e947370ddf2add1de956788ba5f',
        '20261016-module-migration-provenance' => 'd27a6f52dd3e02b9c0c45a804c059f54062a732c9e1a742dfe12e78b80d96cce',
    ];

    /**
     * Read-only admission for the fixed application SQL chain. A deferred plan
     * is never execution admission: the runner must call again after predecessors.
     * @param list<array<string,mixed>> $precedingPending canonical earlier pending SQL rows
     */
    public static function migrationPrestate(PDO $pdo, array $migration, array $precedingPending = [], bool $planning = false): array
    {
        [$id, $sql, $sha] = self::prestateIdentity($migration);
        $before = [];
        foreach ($precedingPending as $row) {
            if (!is_array($row)) { throw new RuntimeException('APPLICATION_SCHEMA_PREDECESSOR_IDENTITY_REQUIRED'); }
            [$previousId, , $previousSha] = self::prestateIdentity($row);
            if (strcmp($previousId, $id) >= 0 || isset($before[$previousId])) {
                throw new RuntimeException('APPLICATION_SCHEMA_PREDECESSOR_ORDER_INVALID');
            }
            $before[$previousId] = $previousSha;
        }
        $menu = ['id','pid','type','name','icon','sort','perms','paths','component','is_cache','is_show','is_disable'];
        $definition = ['id','key','module_key','scope','parent_key','type','name','route_name','route_path','component_key','icon','sort_order','required_permission_id','client_keys_json','status'];
        $required = match ($id) {
            '20260909-notification-sms-reservation' => ['pa_notice_log' => ['id','tenant_id','channel','receiver','scene_id','status','send_time','verify_code_hash']],
            '20261004-group-default-navigation' => ['pa_system_menu' => $menu, 'pa_menu_definition' => $definition, 'pa_permission' => ['id','key']],
            '20261004-menu-stable-identities' => ['pa_system_menu' => [...$menu,'upstream_defaults_json','menu_conflict_json'], 'pa_menu_definition' => [...$definition,'upstream_defaults_json','menu_conflict_json']],
            '20261007-auth-client-schema' => ['pa_tenant_session' => ['client_key'], 'pa_auth_security_event' => ['ip_address','occurred_at']],
            '20261007-readiness-permission', '20261012-align-platform-ops-permission-ownership' => ['pa_permission' => ['id','key','module_key','type','name','description','risk_level','status','manifest_version','created_at','updated_at','retired_at']],
            '20261012-align-department-canonical-permissions' => [
                'pa_system_menu' => [...$menu,'menu_key','parent_key','module_key','upstream_defaults_json','menu_conflict_json'],
                'pa_menu_definition' => ['required_permission_id'], 'pa_permission' => ['id','key','module_key','status','manifest_version','retired_at','updated_at'],
                'pa_platform_role_permission' => ['permission_id'], 'pa_resource_operation_permission' => ['permission_id'],
                'pa_resource_operation_target_type' => ['policy_selection_permission_id'],
                'pa_role_permission' => ['id','tenant_id','role_id','permission_id','granted_by_member_id','granted_at'],
                'pa_role' => ['id','tenant_id','authorization_revision','updated_at'], 'pa_tenant' => ['id','authorization_revision','updated_at'],
            ],
            '20261012-repair-seeded-permission-parents' => ['pa_system_menu' => [...$menu,'menu_key','parent_key','module_key','upstream_defaults_json','menu_conflict_json']],
            '20261016-module-migration-provenance' => ['pa_module_migration' => ['id','module_key','migration_key','module_version','checksum','batch_no','status','started_at','finished_at','error_code']],
        };
        $facts = []; $deferred = [];
        foreach ($required as $table => $columns) {
            $facts[$table] = self::prestateTable($pdo, $table);
            self::prestateColumnShape($table, $facts[$table]['columns']);
            self::prestateConstraintShape($table, $facts[$table]);
            foreach ($columns as $column) {
                if (isset($facts[$table]['columns'][$column])) { continue; }
                $producer = match ($table . '.' . $column) {
                    'pa_system_menu.upstream_defaults_json','pa_system_menu.menu_conflict_json',
                    'pa_menu_definition.upstream_defaults_json','pa_menu_definition.menu_conflict_json' => '20261004-group-default-navigation',
                    'pa_system_menu.menu_key','pa_system_menu.parent_key','pa_system_menu.module_key' => '20261004-menu-stable-identities',
                    default => null,
                };
                if (!$planning || $producer === null || !isset($before[$producer])) {
                    throw new RuntimeException('APPLICATION_SCHEMA_PRESTATE_COLUMN_MISSING:' . $table . '.' . $column);
                }
                $deferred[$producer] = ['migration_id' => $producer, 'sha256' => $before[$producer], 'reason' => 'application_schema_predecessor_columns'];
            }
        }
        foreach (array_keys($deferred) as $producer) {
            $produces = $producer === '20261004-group-default-navigation'
                ? ['pa_system_menu' => ['upstream_defaults_json','menu_conflict_json'], 'pa_menu_definition' => ['upstream_defaults_json','menu_conflict_json']]
                : ['pa_system_menu' => ['menu_key','parent_key','module_key'], 'pa_menu_definition' => ['is_show','is_cache','is_disable']];
            foreach ($produces as $table => $columns) {
                $fact = $facts[$table] ?? self::prestateTable($pdo, $table);
                if (array_intersect($columns, array_keys($fact['columns'])) !== []) {
                    throw new RuntimeException('APPLICATION_SCHEMA_PRESTATE_PREDECESSOR_PARTIAL:' . $producer);
                }
            }
        }
        // Non-DDL facts that can already be proved are not skipped by deferral.
        if (isset($facts['pa_permission'])) { self::prestateUnique($facts['pa_permission'], ['key']); }
        foreach ($facts as $table => $fact) {
            if (isset($fact['columns']['id'])) { self::prestateUnique($fact, ['id']); }
        }
        $additions = match ($id) {
            '20260909-notification-sms-reservation' => ['pa_notice_log' => ['reservation_key','idempotency_key_hash','request_digest','receiver_hash','reservation_until','reservation_active']],
            '20261004-group-default-navigation' => ['pa_menu_definition' => ['upstream_defaults_json','menu_conflict_json'], 'pa_system_menu' => ['upstream_defaults_json','menu_conflict_json']],
            '20261004-menu-stable-identities' => ['pa_system_menu' => ['menu_key','parent_key','module_key'], 'pa_menu_definition' => ['is_show','is_cache','is_disable']],
            default => [],
        };
        foreach ($additions as $table => $columns) {
            if (array_intersect($columns, array_keys($facts[$table]['columns'])) !== []) {
                throw new RuntimeException('APPLICATION_SCHEMA_PRESTATE_ADDITION_COLLISION:' . $table);
            }
        }
        $proof = [];
        if ($id === '20260909-notification-sms-reservation') {
            foreach (['uk_notice_sms_reservation_key','uk_notice_sms_idempotency','uk_notice_sms_active_receiver','idx_notice_sms_reservation_expiry'] as $index) {
                if (isset($facts['pa_notice_log']['indexes'][$index])) { throw new RuntimeException('APPLICATION_SCHEMA_PRESTATE_INDEX_COLLISION'); }
            }
            if (isset($facts['pa_notice_log']['checks']['chk_notice_sms_reservation_active'])) { throw new RuntimeException('APPLICATION_SCHEMA_PRESTATE_CHECK_COLLISION'); }
            $latest = "SELECT tenant_id,channel,receiver,MAX(id) AS id FROM pa_notice_log WHERE channel=1 AND scene_id>0 AND status=1 AND send_time>UNIX_TIMESTAMP()-60 GROUP BY tenant_id,channel,receiver";
            self::prestateZero($pdo, 'WITH latest AS (' . $latest . ') SELECT COUNT(*) FROM (SELECT l.tenant_id,l.channel,SHA2(l.receiver,256) AS receiver_hash FROM pa_notice_log l JOIN latest ON latest.id=l.id GROUP BY l.tenant_id,l.channel,SHA2(l.receiver,256) HAVING COUNT(*)>1) collisions');
            self::prestateZero($pdo, 'WITH latest AS (' . $latest . ') SELECT COUNT(*) FROM pa_notice_log l JOIN latest ON latest.id=l.id WHERE l.send_time>4294967235 OR l.receiver IS NULL OR l.tenant_id IS NULL');
            $proof['activation_candidates'] = self::prestateCount($pdo, 'SELECT COUNT(*) FROM (' . $latest . ') latest');
        } elseif ($id === '20261004-group-default-navigation') {
            $proof = self::prestateNavigation($pdo, $sql, $facts);
        } elseif ($id === '20261004-menu-stable-identities' && $deferred === []) {
            if (isset($facts['pa_system_menu']['indexes']['uk_system_menu_key'])) { throw new RuntimeException('APPLICATION_SCHEMA_PRESTATE_INDEX_COLLISION'); }
            $seed = self::prestateValues($sql, 'menu_identity_seed', ['type','paths','perms','menu_key','module_key'], 'utf8mb4_unicode_ci');
            $matches = self::prestateSelect($sql, 'menu_identity_matches');
            $unique = self::prestateSelect($sql, 'menu_identity_unique');
            $with = 'WITH menu_identity_seed AS (' . $seed . '),menu_identity_matches AS (' . $matches . '),menu_identity_unique AS (' . $unique . ') ';
            self::prestateZero($pdo, $with . 'SELECT COUNT(*) FROM (SELECT m.id FROM menu_identity_matches m JOIN menu_identity_unique u ON u.id=m.id AND u.menu_key=m.menu_key GROUP BY m.id HAVING COUNT(*)>1) ambiguous');
            $proof['stable_matches'] = self::prestateCount($pdo, $with . 'SELECT COUNT(*) FROM menu_identity_unique');
            $proof['custom_matches'] = self::prestateCount($pdo, $with . 'SELECT COUNT(*) FROM pa_system_menu m LEFT JOIN menu_identity_unique u ON u.id=m.id WHERE u.id IS NULL');
            self::prestateZero($pdo, "SELECT COUNT(*) FROM pa_system_menu WHERE id>=4294967295 OR id<1 OR pid<0 OR pid>4294967295");
        } elseif ($id === '20261007-auth-client-schema') {
            self::prestateZero($pdo, "SELECT COUNT(*) FROM pa_tenant_session WHERE NOT (CHAR_LENGTH(client_key) BETWEEN 1 AND 64 AND REGEXP_LIKE(client_key,'^[a-z]','c') AND NOT REGEXP_LIKE(client_key,'[^a-z0-9-]','c')) OR client_key IS NULL");
            $proof['client_check_rows'] = self::prestateCount($pdo, 'SELECT COUNT(*) FROM pa_tenant_session');
            // The immutable SQL deliberately replaces a differently shaped named index.
            $proof['auth_ip_index'] = $facts['pa_auth_security_event']['indexes']['idx_auth_event_ip'] ?? [];
        } elseif ($id === '20261007-readiness-permission') {
            self::prestateZero($pdo, "SELECT COUNT(*) FROM pa_permission WHERE `key`='readiness/checklist' AND (module_key<>'peanut.admin' OR type<>'api')");
            $proof['catalog_matches'] = self::prestateCount($pdo, "SELECT COUNT(*) FROM pa_permission WHERE `key`='readiness/checklist'");
        } elseif ($id === '20261012-align-department-canonical-permissions') {
            self::prestateUnique($facts['pa_role_permission'], ['tenant_id','role_id','permission_id']);
            $proof = self::prestateDepartment($pdo, $sql, $deferred === []);
        } elseif ($id === '20261012-align-platform-ops-permission-ownership') {
            $row = $pdo->query("SELECT COUNT(*) AS total,SUM(module_key IN ('peanut.admin','platform')) AS valid FROM pa_permission WHERE `key` IN ('platform.ops.module.manage','platform.ops.upgrade.manage')")->fetch(PDO::FETCH_ASSOC);
            if ((int) ($row['total'] ?? 0) !== 2 || (int) ($row['valid'] ?? 0) !== 2) { throw new RuntimeException('APPLICATION_SCHEMA_PRESTATE_OPS_OWNER_UNKNOWN'); }
            $proof['before_and_projected_after_owner_count'] = 2;
        } elseif ($id === '20261012-repair-seeded-permission-parents' && $deferred === []) {
            $candidates = self::prestateSelect($sql, 'pa_seeded_permission_parent_candidates');
            $proof['candidates'] = $pdo->query($candidates)->fetch(PDO::FETCH_ASSOC);
            $proof['selectors'] = self::prestateUpdateSelectors($pdo, $sql, 'WITH pa_seeded_permission_parent_candidates AS (' . $candidates . ') ');
        } elseif ($id === '20261016-module-migration-provenance') {
            $proof = self::prestateProvenance($pdo, $facts['pa_module_migration']);
        }
        if (in_array($id, ['20261007-readiness-permission','20261012-align-department-canonical-permissions','20261012-align-platform-ops-permission-ownership'], true)) {
            self::prestatePermissionChecks($pdo, $id, $facts['pa_permission']);
        }
        if ($deferred !== [] && !$planning) { throw new RuntimeException('APPLICATION_SCHEMA_PRESTATE_DEFERRED'); }
        ksort($deferred, SORT_STRING);
        return ['migration_id' => $id, 'sha256' => $sha, 'status' => $deferred === [] ? 'ready' : 'deferred',
            'complete_preconditions_proven' => $deferred === [], 'application_prerequisites' => array_values($deferred),
            'proof' => $proof, 'tables' => $facts, 'readonly' => true];
    }

    private static function prestateIdentity(array $migration): array
    {
        $id = $migration['id'] ?? null; $sql = $migration['sql'] ?? null;
        $sha = $migration['checksum'] ?? $migration['sha256'] ?? null;
        if (!is_string($id) || !isset(self::PRESTATE_SQL[$id]) || !is_string($sql) || !is_string($sha)
            || $sha !== self::PRESTATE_SQL[$id] || !hash_equals($sha, hash('sha256', $sql))
            || (isset($migration['sha256']) && $migration['sha256'] !== $sha)) {
            throw new RuntimeException('APPLICATION_SCHEMA_PRESTATE_SQL_UNKNOWN');
        }
        return [$id, $sql, $sha];
    }

    /** Schema facts include exact named checks/index components, not column counts alone. */
    private static function prestateTable(PDO $pdo, string $table): array
    {
        $query = $pdo->prepare('SELECT TABLE_TYPE,ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?');
        $query->execute([$table]); $shape = $query->fetch(PDO::FETCH_ASSOC);
        if (!is_array($shape) || $shape['TABLE_TYPE'] !== 'BASE TABLE' || $shape['ENGINE'] !== 'InnoDB') { throw new RuntimeException('APPLICATION_SCHEMA_PRESTATE_TABLE_UNKNOWN:' . $table); }
        $query = $pdo->prepare('SELECT COUNT(*) FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA=DATABASE() AND EVENT_OBJECT_TABLE=?');
        $query->execute([$table]);
        if ((int) $query->fetchColumn() !== 0) { throw new RuntimeException('APPLICATION_SCHEMA_PRESTATE_TRIGGER_UNKNOWN:' . $table); }
        $query = $pdo->prepare('SELECT COLUMN_NAME,COLUMN_TYPE,DATA_TYPE,IS_NULLABLE,COLUMN_DEFAULT,CHARACTER_SET_NAME,COLLATION_NAME,EXTRA FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? ORDER BY ORDINAL_POSITION');
        $query->execute([$table]); $columns = [];
        foreach ($query->fetchAll(PDO::FETCH_ASSOC) as $row) { $columns[$row['COLUMN_NAME']] = $row; }
        $query = $pdo->prepare('SELECT INDEX_NAME,COLUMN_NAME,NON_UNIQUE,SEQ_IN_INDEX,SUB_PART,INDEX_TYPE,IS_VISIBLE,EXPRESSION FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? ORDER BY INDEX_NAME,SEQ_IN_INDEX');
        $query->execute([$table]); $indexes = [];
        foreach ($query->fetchAll(PDO::FETCH_ASSOC) as $row) { $indexes[$row['INDEX_NAME']][] = $row; }
        $query = $pdo->prepare("SELECT t.CONSTRAINT_NAME,t.ENFORCED,c.CHECK_CLAUSE FROM information_schema.TABLE_CONSTRAINTS t JOIN information_schema.CHECK_CONSTRAINTS c ON c.CONSTRAINT_SCHEMA=t.CONSTRAINT_SCHEMA AND c.CONSTRAINT_NAME=t.CONSTRAINT_NAME WHERE t.CONSTRAINT_SCHEMA=DATABASE() AND t.TABLE_NAME=? AND t.CONSTRAINT_TYPE='CHECK'");
        $query->execute([$table]); $checks = [];
        foreach ($query->fetchAll(PDO::FETCH_ASSOC) as $row) { $checks[$row['CONSTRAINT_NAME']] = $row; }
        return ['columns' => $columns, 'indexes' => $indexes, 'checks' => $checks];
    }

    private static function prestateUnique(array $table, array $columns): void
    {
        foreach ($table['indexes'] as $rows) {
            if (array_column($rows, 'COLUMN_NAME') === $columns && count(array_filter($rows, static fn(array $row): bool => (int) $row['NON_UNIQUE'] !== 0 || $row['SUB_PART'] !== null || $row['EXPRESSION'] !== null || $row['INDEX_TYPE'] !== 'BTREE' || $row['IS_VISIBLE'] !== 'YES')) === 0) { return; }
        }
        throw new RuntimeException('APPLICATION_SCHEMA_PRESTATE_UNIQUE_UNKNOWN');
    }

    /** Compare existing columns with the native source definitions, without executing them. */
    private static function prestateColumnShape(string $table, array $columns): void
    {
        $shape = self::prestateNativeTable($table);
        preg_match_all('/^\s*`([a-z0-9_]+)`\s+([A-Z]+(?:\([0-9,]+\))?(?:\s+UNSIGNED)?)([^\r\n]*)/m', $shape, $definitions, PREG_SET_ORDER);
        $native = [];
        foreach ($definitions as $definition) { $native[$definition[1]] = [$definition[2], $definition[3]]; }
        $normalize = static fn(string $type): string => (string) preg_replace('/\b(tinyint|smallint|mediumint|int|bigint)\([0-9]+\)/', '$1', strtolower((string) preg_replace('/\s+/', ' ', trim($type))));
        foreach ($columns as $name => $row) {
            if (!isset($native[$name]) || $normalize($row['COLUMN_TYPE']) !== $normalize($native[$name][0])
                || (($row['IS_NULLABLE'] === 'NO') !== str_contains($native[$name][1], 'NOT NULL')
                    && !($table === 'pa_system_menu' && $name === 'menu_key' && $row['IS_NULLABLE'] === 'NO'))
                || str_contains($row['EXTRA'], 'GENERATED')) {
                throw new RuntimeException('APPLICATION_SCHEMA_PRESTATE_COLUMN_SHAPE_UNKNOWN:' . $table . '.' . $name);
            }
        }
    }

    private static function prestateNativeTable(string $table): string
    {
        static $source = null;
        $database = dirname(__DIR__, 4) . '/database';
        $source ??= (string) file_get_contents($database . '/init.sql') . "\n" . (string) file_get_contents($database . '/schema/KernelSchema.php');
        if (preg_match('/CREATE TABLE `' . preg_quote($table, '/') . '` \((.*?)\)\s*ENGINE=/s', $source, $match) !== 1) {
            throw new RuntimeException('APPLICATION_SCHEMA_PRESTATE_NATIVE_SHAPE_MISSING:' . $table);
        }
        return $match[1];
    }

    private static function prestateConstraintShape(string $table, array $fact): void
    {
        $source = self::prestateNativeTable($table);
        preg_match_all('/(?:PRIMARY KEY|UNIQUE KEY `[^`]+`)\s*\(([^\r\n]+)\)/', $source, $matches);
        $unique = [];
        foreach ($matches[1] as $columns) {
            preg_match_all('/`([^`]+)`/', $columns, $names); $unique[] = $names[1];
        }
        if ($table === 'pa_system_menu' && isset($fact['columns']['menu_key'])) { $unique[] = ['menu_key']; }
        foreach ($fact['indexes'] as $rows) {
            if ((int) $rows[0]['NON_UNIQUE'] !== 0) { continue; }
            if (!in_array(array_column($rows, 'COLUMN_NAME'), $unique, true)
                || count(array_filter($rows, static fn(array $row): bool => $row['SUB_PART'] !== null || $row['EXPRESSION'] !== null || $row['INDEX_TYPE'] !== 'BTREE' || $row['IS_VISIBLE'] !== 'YES')) !== 0) {
                throw new RuntimeException('APPLICATION_SCHEMA_PRESTATE_UNIQUE_SHAPE_UNKNOWN:' . $table);
            }
        }
        preg_match_all('/CONSTRAINT `([^`]+)` CHECK/', $source, $matches);
        foreach ($fact['checks'] as $name => $check) {
            if (!in_array($name, $matches[1], true) || $check['ENFORCED'] !== 'YES') { throw new RuntimeException('APPLICATION_SCHEMA_PRESTATE_CHECK_SHAPE_UNKNOWN:' . $table); }
        }
        if ($table === 'pa_permission' && array_diff($matches[1], array_keys($fact['checks'])) !== []) {
            throw new RuntimeException('APPLICATION_SCHEMA_PRESTATE_CHECK_SHAPE_UNKNOWN:' . $table);
        }
    }

    /** Evaluate the actual enforced catalog CHECKs against the source's postimage. */
    private static function prestatePermissionChecks(PDO $pdo, string $id, array $fact): void
    {
        $overrides = []; $where = '1';
        if ($id === '20261007-readiness-permission') {
            $overrides = ['module_key' => "'peanut.admin'", 'type' => "'api'", 'name' => "'生产准备清单'", 'description' => "'Read the tenant first-run readiness checklist.'", 'risk_level' => "'normal'", 'status' => "'active'", 'manifest_version' => "'5.0.1-readiness-v1'", 'updated_at' => 'UTC_TIMESTAMP(3)', 'retired_at' => 'NULL'];
            $where = "`key`='readiness/checklist'";
        } elseif ($id === '20261012-align-department-canonical-permissions') {
            $overrides = ['status' => "'retired'", 'retired_at' => 'COALESCE(retired_at,UTC_TIMESTAMP(3))', 'updated_at' => 'UTC_TIMESTAMP(3)'];
            $where = "`key` IN ('dept/lists','dept/detail','dept/all','dept/leaderdept','dept/add','dept/edit','dept/delete','dept/status') AND status<>'retired'";
        } else {
            $overrides = ['module_key' => "'platform'"];
            $where = "module_key='peanut.admin' AND `key` IN ('platform.ops.module.manage','platform.ops.upgrade.manage')";
        }
        $columns = [];
        foreach (array_keys($fact['columns']) as $column) { $columns[] = ($overrides[$column] ?? '`' . $column . '`') . ' AS `' . $column . '`'; }
        $projection = 'SELECT ' . implode(',', $columns) . ' FROM pa_permission WHERE ' . $where;
        if ($id === '20261007-readiness-permission') {
            $insert = [];
            foreach (array_keys($fact['columns']) as $column) {
                $insert[] = match ($column) { 'id' => '0', 'key' => "'readiness/checklist'", 'created_at' => 'UTC_TIMESTAMP(3)', default => $overrides[$column] ?? 'NULL' };
            }
            $projection .= ' UNION ALL SELECT ' . implode(',', $insert);
        }
        foreach ($fact['checks'] as $check) {
            self::prestateZero($pdo, 'WITH projected AS (' . $projection . ') SELECT COUNT(*) FROM projected WHERE NOT (' . $check['CHECK_CLAUSE'] . ')');
        }
    }

    private static function prestateCount(PDO $pdo, string $select): int
    {
        return (int) $pdo->query($select)->fetchColumn();
    }

    private static function prestateZero(PDO $pdo, string $select): void
    {
        if (self::prestateCount($pdo, $select) !== 0) { throw new RuntimeException('APPLICATION_SCHEMA_PRESTATE_PREDICATE_FAILED'); }
    }

    /** These extractors are used only after the nine immutable hashes match. */
    private static function prestateStatements(string $sql): array
    {
        return array_values(array_filter(array_map('trim', explode(';', (string) preg_replace('/^\s*--[^\r\n]*/m', '', $sql)))));
    }

    private static function prestateSelect(string $sql, string $name): string
    {
        $selects = [];
        foreach (self::prestateStatements($sql) as $statement) {
            if (preg_match('/\ACREATE TEMPORARY TABLE `?' . preg_quote($name, '/') . '`? AS\s+(SELECT\b.*)\z/isD', $statement, $match) === 1
                || preg_match('/\AINSERT INTO `?' . preg_quote($name, '/') . '`?(?:\s*\([^)]*\))?\s+(SELECT\b.*)\z/isD', $statement, $match) === 1) {
                $selects[] = $match[1];
            }
        }
        if ($selects === []) { throw new RuntimeException('APPLICATION_SCHEMA_PRESTATE_SOURCE_SELECTOR_MISSING'); }
        return implode(' UNION ALL ', $selects);
    }

    /** Split one known VALUES tuple/list without splitting JSON_OBJECT arguments. */
    private static function prestateSqlList(string $value): array
    {
        $parts = []; $start = 0; $depth = 0; $quoted = false; $length = strlen($value);
        for ($i = 0; $i < $length; ++$i) {
            $character = $value[$i];
            if ($quoted) {
                if ($character === "'" && ($value[$i + 1] ?? '') === "'") { ++$i; }
                elseif ($character === "'" && ($i === 0 || $value[$i - 1] !== '\\')) { $quoted = false; }
                continue;
            }
            if ($character === "'") { $quoted = true; }
            elseif ($character === '(') { ++$depth; }
            elseif ($character === ')') { --$depth; }
            elseif ($character === ',' && $depth === 0) { $parts[] = trim(substr($value, $start, $i - $start)); $start = $i + 1; }
            if ($depth < 0) { throw new RuntimeException('APPLICATION_SCHEMA_PRESTATE_SOURCE_TUPLE_INVALID'); }
        }
        if ($quoted || $depth !== 0) { throw new RuntimeException('APPLICATION_SCHEMA_PRESTATE_SOURCE_TUPLE_INVALID'); }
        $parts[] = trim(substr($value, $start));
        return $parts;
    }

    private static function prestateValues(string $sql, string $name, array $columns, ?string $collation = null, array $variables = []): string
    {
        $selects = [];
        foreach (self::prestateStatements($sql) as $statement) {
            if (preg_match('/\AINSERT INTO `?' . preg_quote($name, '/') . '`?\s+VALUES\s+(.*)\z/isD', $statement, $match) !== 1) { continue; }
            foreach (self::prestateSqlList($match[1]) as $tuple) {
                if (!str_starts_with($tuple, '(') || !str_ends_with($tuple, ')')) { throw new RuntimeException('APPLICATION_SCHEMA_PRESTATE_SOURCE_TUPLE_INVALID'); }
                $values = self::prestateSqlList(substr($tuple, 1, -1));
                if (count($values) !== count($columns)) { throw new RuntimeException('APPLICATION_SCHEMA_PRESTATE_SOURCE_TUPLE_INVALID'); }
                $expressions = [];
                foreach ($values as $offset => $value) {
                    $value = strtr($value, $variables);
                    if ($collation !== null && str_starts_with($value, "'")) { $value = 'CONVERT(' . $value . ' USING utf8mb4) COLLATE ' . $collation; }
                    $expressions[] = $value . ' AS `' . $columns[$offset] . '`';
                }
                $selects[] = 'SELECT ' . implode(',', $expressions);
            }
        }
        if ($selects === []) { throw new RuntimeException('APPLICATION_SCHEMA_PRESTATE_SOURCE_VALUES_MISSING'); }
        return implode(' UNION ALL ', $selects);
    }

    private static function prestateUpdateSelectors(PDO $pdo, string $sql, string $with): array
    {
        $counts = [];
        foreach (self::prestateStatements($sql) as $statement) {
            if (!str_starts_with($statement, 'UPDATE pa_system_menu m ') && !str_starts_with($statement, 'UPDATE `pa_system_menu` child')) { continue; }
            if (preg_match('/\AUPDATE\s+(.*?)\s+SET\s+.*?\s+WHERE\s+(.*)\z/isD', $statement, $match) !== 1) { continue; }
            $counts[] = self::prestateCount($pdo, $with . 'SELECT COUNT(*) FROM ' . $match[1] . ' WHERE ' . $match[2]);
        }
        return $counts;
    }

    private static function prestateNavigation(PDO $pdo, string $sql, array $facts): array
    {
        $database = (string) $pdo->query('SELECT DATABASE()')->fetchColumn();
        if (preg_match('/\A[A-Za-z0-9_]+\z/D', $database) !== 1) { throw new RuntimeException('APPLICATION_SCHEMA_PRESTATE_DATABASE_INVALID'); }
        $systemColumns = ['id','pid','type','name','icon','sort','perms','paths','component','is_cache','is_show','is_disable'];
        $definitionColumns = array_keys($facts['pa_menu_definition']['columns']);
        $snapshots = ['pa_system_menu' => [], 'pa_menu_definition' => []];
        foreach (self::prestateStatements($sql) as $statement) {
            if (preg_match('/\AUPDATE (pa_system_menu|pa_menu_definition) SET upstream_defaults_json=(.+) WHERE (.+) AND upstream_defaults_json IS NULL\z/isD', $statement, $match) === 1) {
                $snapshots[$match[1]][] = 'WHEN ' . $match[3] . ' THEN ' . $match[2];
            }
        }
        if ($snapshots['pa_system_menu'] === [] || $snapshots['pa_menu_definition'] === []) { throw new RuntimeException('APPLICATION_SCHEMA_PRESTATE_SOURCE_SNAPSHOT_MISSING'); }
        $project = static fn(array $columns): string => implode(',', array_map(static fn(string $column): string => '`' . $column . '`', $columns));
        $system = 'SELECT ' . $project($systemColumns) . ',CASE ' . implode(' ', $snapshots['pa_system_menu']) . ' ELSE NULL END AS upstream_defaults_json,CAST(NULL AS JSON) AS menu_conflict_json FROM `' . $database . '`.pa_system_menu';
        $definition = 'SELECT ' . $project($definitionColumns) . ',CASE ' . implode(' ', $snapshots['pa_menu_definition']) . ' ELSE NULL END AS upstream_defaults_json,CAST(NULL AS JSON) AS menu_conflict_json FROM `' . $database . '`.pa_menu_definition';
        $next = $pdo->query("SELECT AUTO_INCREMENT FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='pa_system_menu'")->fetchColumn();
        if (!ctype_digit((string) $next) || (int) $next < 1 || (int) $next > 4294967293) { throw new RuntimeException('APPLICATION_SCHEMA_PRESTATE_AUTO_INCREMENT_UNKNOWN'); }
        $inserts = []; $variables = [];
        foreach (self::prestateStatements($sql) as $statement) {
            if (preg_match('/\AINSERT INTO pa_system_menu \((.*?)\) SELECT (.*?) WHERE NOT EXISTS \((SELECT 1 FROM pa_system_menu WHERE paths=\'[^\']+\')\)\z/isD', $statement, $match) === 1) {
                $inserts[] = [$match[2], $match[3]];
            }
            if (preg_match('/\ASET (@peanut_menu_(?:settings|operations)) = \((SELECT .*)\)\z/isD', $statement, $match) === 1) { $variables[$match[1]] = '(' . $match[2] . ')'; }
        }
        if (count($inserts) !== 2 || count($variables) !== 2) { throw new RuntimeException('APPLICATION_SCHEMA_PRESTATE_SOURCE_GROUP_MISSING'); }
        // Virtual IDs model the two guarded inserts; no AUTO_INCREMENT is consumed.
        $rows = 'SELECT * FROM pa_system_menu_snapshots';
        foreach ($inserts as $offset => [$values, $exists]) {
            $advance = $offset === 0 ? '0' : '(SELECT COUNT(*) FROM (SELECT 1 WHERE NOT EXISTS (' . $inserts[0][1] . ')) allocated)';
            $rows .= ' UNION ALL SELECT ' . (int) $next . '+' . $advance . ',' . $values . ',CAST(NULL AS JSON) WHERE NOT EXISTS (' . $exists . ')';
        }
        $rows = str_replace('FROM pa_system_menu WHERE', 'FROM pa_system_menu_snapshots WHERE', $rows);
        $expected = self::prestateValues($sql, 'pa_menu_grouping_expected', ['id','target_defaults','old_parent'], null, $variables);
        $with = 'WITH pa_system_menu_snapshots AS (' . $system . '),pa_system_menu AS (' . $rows . '),pa_menu_definition AS (' . $definition . '),pa_menu_grouping_expected AS (' . $expected . ') ';
        $proof = ['system_snapshots' => self::prestateCount($pdo, $with . 'SELECT COUNT(*) FROM pa_system_menu WHERE upstream_defaults_json IS NOT NULL'),
            'definition_snapshots' => self::prestateCount($pdo, $with . 'SELECT COUNT(*) FROM pa_menu_definition WHERE upstream_defaults_json IS NOT NULL')];
        $proof['preservation_selectors'] = self::prestateUpdateSelectors($pdo, $sql, $with);
        return $proof;
    }

    private static function prestateDepartment(PDO $pdo, string $sql, bool $full): array
    {
        $ctes = ['pa_dept_permission_map AS (' . self::prestateValues($sql, 'pa_dept_permission_map', ['legacy_key','canonical_key'], 'utf8mb4_0900_ai_ci') . ')',
            'pa_dept_action_seed AS (' . self::prestateValues($sql, 'pa_dept_action_seed', ['action_key','action_name','sort_order'], 'utf8mb4_0900_ai_ci') . ')'];
        if ($full) {
            foreach (['pa_dept_default_page_ids','pa_dept_default_menu_ids','pa_dept_default_menu_parents','pa_dept_grant_moves','pa_dept_affected_roles','pa_dept_affected_tenants'] as $name) {
                $ctes[] = $name . ' AS (' . self::prestateSelect($sql, $name) . ')';
            }
        }
        $with = 'WITH ' . implode(',', $ctes) . ' '; $proof = [];
        foreach (self::prestateStatements($sql) as $statement) {
            if (preg_match('/\AINSERT INTO `(pa_dept_permission_assertion|pa_dept_action_assertion)`\s+(SELECT\s+\'([^\']+)\'.*)\z/isD', $statement, $match) !== 1) { continue; }
            if (!$full && !in_array($match[3], ['canonical_catalog','legacy_provenance','platform_grants','resource_operation_grants','resource_operation_selection','native_menu_references'], true)) { continue; }
            $row = $pdo->query($with . $match[2])->fetch(PDO::FETCH_NUM);
            if (!is_array($row) || (int) ($row[1] ?? 0) !== 1) { throw new RuntimeException('APPLICATION_SCHEMA_PRESTATE_DEPARTMENT_ASSERTION_FAILED:' . $match[3]); }
            $proof[$match[3]] = true;
        }
        if (count($proof) !== ($full ? 11 : 6)) { throw new RuntimeException('APPLICATION_SCHEMA_PRESTATE_SOURCE_ASSERTION_MISSING'); }
        if ($full) {
            self::prestateZero($pdo, $with . 'SELECT COUNT(*) FROM pa_role r JOIN pa_dept_affected_roles a ON a.tenant_id=r.tenant_id AND a.role_id=r.id WHERE r.authorization_revision>=18446744073709551615');
            self::prestateZero($pdo, $with . 'SELECT COUNT(*) FROM pa_tenant t JOIN pa_dept_affected_tenants a ON a.tenant_id=t.id WHERE t.authorization_revision>=18446744073709551615');
            $proof['grant_moves'] = self::prestateCount($pdo, $with . 'SELECT COUNT(*) FROM pa_dept_grant_moves');
        }
        return $proof;
    }

    private static function prestateProvenance(PDO $pdo, array $fact): array
    {
        $columns = array_intersect(['resolution_kind','provenance_json'], array_keys($fact['columns']));
        $checks = array_intersect(['chk_module_migration_resolution','chk_module_migration_provenance'], array_keys($fact['checks']));
        if ($columns === [] && $checks === []) { return ['shape' => 'before_additions', 'existing_rows_retain_unknown_origin' => true]; }
        if (count($columns) !== 2 || count($checks) !== 2) { throw new RuntimeException('APPLICATION_SCHEMA_PRESTATE_PROVENANCE_PARTIAL'); }
        foreach (['resolution_kind' => 'varchar(16)', 'provenance_json' => 'json'] as $column => $type) {
            $row = $fact['columns'][$column];
            if ($row['COLUMN_TYPE'] !== $type || $row['IS_NULLABLE'] !== 'YES' || $row['COLUMN_DEFAULT'] !== null) { throw new RuntimeException('APPLICATION_SCHEMA_PRESTATE_PROVENANCE_DIFFERENT'); }
        }
        $expected = ['chk_module_migration_resolution' => "((resolution_kindisnull)or(resolution_kindin('executed','adopted')))",
            'chk_module_migration_provenance' => "((resolution_kind<=>'adopted')=(provenance_jsonisnotnull))"];
        foreach ($expected as $name => $clause) {
            $row = $fact['checks'][$name];
            $normalized = strtolower(str_replace(['`',' ',"\n",'_utf8mb4'], '', $row['CHECK_CLAUSE']));
            if ($row['ENFORCED'] !== 'YES' || $normalized !== $clause) { throw new RuntimeException('APPLICATION_SCHEMA_PRESTATE_PROVENANCE_DIFFERENT'); }
        }
        self::prestateZero($pdo, "SELECT COUNT(*) FROM pa_module_migration WHERE NOT ((resolution_kind IS NULL OR resolution_kind IN ('executed','adopted')) AND ((resolution_kind<=>'adopted')=(provenance_json IS NOT NULL)))");
        return ['shape' => 'exact_noop'];
    }

    public function __construct(private readonly string $serverRoot)
    {
        if (realpath($serverRoot) !== $serverRoot) {
            throw new RuntimeException('APPLICATION_SCHEMA_ROOT_INVALID');
        }
        require_once $serverRoot . '/database/install.php';
        require_once $serverRoot . '/database/environment-guard.php';
    }

    /** Real pending server SQL uses the existing native update window and recovery point. */
    public function serverStage(string $operation, string $workspace): array
    {
        if (!in_array($operation, ['preflight', 'apply', 'verify'], true)
            || !is_file($this->serverRoot . '/.peanut/release-identity.json')
            || is_link($this->serverRoot . '/.peanut/release-identity.json')) {
            throw new RuntimeException('APPLICATION_SCHEMA_NATIVE_SERVER_WORKSPACE_REQUIRED');
        }
        ServerReleaseIdentity::load($this->serverRoot);
        require_once $this->serverRoot . '/docker/scripts/update-database.php';
        return \peanutServerDatabaseStage($operation === 'apply' ? 'migrate' : $operation,
            $this->serverRoot, $workspace, 'application-schema');
    }

    /** No operation record, ledger initialization, maintenance or persistent DB writes. */
    public function preflight(): array
    {
        return $this->observe()['plan'];
    }

    public function apply(string $planPath): array
    {
        $expected = $this->protectedJson($planPath);
        $this->assertDocument($expected, self::PROTOCOL, 'plan_sha256');
        $observation = $this->observe();
        $plan = $observation['plan'];
        if (!hash_equals($expected['plan_sha256'], $plan['plan_sha256'])) {
            throw new RuntimeException('APPLICATION_SCHEMA_PLAN_STALE');
        }
        if ($plan['may_apply'] !== true) {
            return $plan + ['operation' => 'apply', 'execution' => 'not_started'];
        }

        // All admission conditions are re-read, not supplied by an arbitrary JSON
        // recovery flag. Today the public Ops contract cannot qualify pending SQL;
        // only a completely applied chain can reach this native no-op execution.
        $fresh = $this->observe();
        if (!hash_equals($plan['plan_sha256'], $fresh['plan']['plan_sha256'])
            || $fresh['plan']['may_apply'] !== true) {
            throw new RuntimeException('APPLICATION_SCHEMA_PLAN_STALE');
        }
        $result = $fresh['runner']->run($fresh['files'], $fresh['target'], $fresh['default'], false);
        $after = $this->observe();
        if ($result['pending'] !== [] || $after['plan']['pending'] !== []
            || $after['plan']['target'] !== $plan['target']
            || $after['plan']['installation'] !== $plan['installation']) {
            throw new RuntimeException('APPLICATION_SCHEMA_RESULT_INCOMPLETE');
        }
        $record = [
            'schema_version' => 1, 'protocol' => self::RESULT_PROTOCOL,
            'scope' => 'application-schema', 'status' => 'completed',
            'plan_sha256' => $plan['plan_sha256'], 'plan' => $plan,
            'application' => $result, 'application_schema' => 'up_to_date',
            'plugins' => 'not_run', 'product_database' => 'not_verified',
        ];
        $record['result_sha256'] = $this->digest($record);
        $path = $this->saveResult($record);
        return $record + ['result_path' => $path];
    }

    public function verify(string $resultPath): array
    {
        $directory = $this->serverRoot . '/private/application-schema';
        if (realpath(dirname($resultPath)) !== realpath($directory)
            || preg_match('/\Aresult-[a-f0-9]{32}\.json\z/D', basename($resultPath)) !== 1) {
            throw new RuntimeException('APPLICATION_SCHEMA_RESULT_ORIGIN_INVALID');
        }
        $record = $this->protectedJson($resultPath);
        if (!is_file($resultPath . '.hmac') || is_link($resultPath . '.hmac')
            || (fileperms($resultPath . '.hmac') & 0777) !== 0600) {
            throw new RuntimeException('APPLICATION_SCHEMA_RESULT_ORIGIN_INVALID');
        }
        $signature = trim((string) file_get_contents($resultPath . '.hmac'));
        if (!hash_equals(hash_hmac('sha256', (string) file_get_contents($resultPath), $this->resultKey()), $signature)) {
            throw new RuntimeException('APPLICATION_SCHEMA_RESULT_ORIGIN_INVALID');
        }
        $this->assertDocument($record, self::RESULT_PROTOCOL, 'result_sha256');
        $plan = $record['plan'] ?? null;
        if (!is_array($plan)) {
            throw new RuntimeException('APPLICATION_SCHEMA_RESULT_INVALID');
        }
        $this->assertDocument($plan, self::PROTOCOL, 'plan_sha256');
        $current = $this->observe()['plan'];
        if (($record['status'] ?? null) !== 'completed'
            || ($record['application']['pending'] ?? null) !== []
            || ($record['application']['applied'] ?? null) !== []
            || ($record['application']['status'] ?? null) !== 'up_to_date'
            || ($record['plan_sha256'] ?? null) !== $plan['plan_sha256']
            || $current['pending'] !== []
            || !hash_equals($plan['plan_sha256'], $current['plan_sha256'])) {
            throw new RuntimeException('APPLICATION_SCHEMA_VERIFY_INCOMPLETE');
        }
        return [
            'scope' => 'application-schema', 'status' => 'completed',
            'application_schema' => 'verified', 'plugins' => 'not_run',
            'product_database' => 'not_verified', 'result_sha256' => $record['result_sha256'],
            'plan_sha256' => $plan['plan_sha256'],
        ];
    }

    private function observe(): array
    {
        $installation = InstallationExecutionHost::verifiedInstallationFacts($this->serverRoot);
        $target = $this->target($installation);
        $config = \guardedDatabaseConfig();
        \projectResourceRegistry($registrySha256);
        if (($target['deployment_mode'] ?? null) !== getenv('DEPLOYMENT_MODE')) {
            throw new RuntimeException('APPLICATION_SCHEMA_EDITION_MISMATCH');
        }
        $files = \applicationMigrationFiles($this->serverRoot . '/database');
        $versions = \applicationReleaseVersions($this->serverRoot);
        $targetVersion = \applicationMigrationTargetVersion($this->serverRoot, $versions);
        $default = $versions['release_sequence_version'];
        $runner = new ApplicationMigrationRunner($config);
        // Runner checks the existing ledger and immutable hashes before returning.
        // Its GET_LOCK/RELEASE_LOCK are transient; it has no ensureLedger path.
        $selection = $runner->run($files, $targetVersion, $default, true);
        $pdo = \guardedConnection($config);
        $pdo->exec('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
        $pdo->exec('START TRANSACTION WITH CONSISTENT SNAPSHOT, READ ONLY');
        try {
            $chain = [];
            $pending = [];
            foreach ($files as $path) {
                $sql = (string) file_get_contents($path);
                $id = basename($path, '.sql');
                $identity = ApplicationMigrationRunner::releaseIdentity($sql, $default);
                $included = !$identity['peanut_release'] || version_compare($identity['release_version'], $targetVersion, '<=');
                $query = $pdo->prepare('SELECT checksum,status,release_version FROM pa_schema_migration WHERE migration_id=?');
                $query->execute([$id]);
                $row = $query->fetch(PDO::FETCH_ASSOC) ?: null;
                $entry = [
                    'id' => $id, 'sha256' => hash('sha256', $sql),
                    'release_version' => $identity['release_version'],
                    'included' => $included, 'ledger' => $row,
                ];
                if ($included && ($row['status'] ?? null) !== 'applied') {
                    $entry['prestate'] = $this->prestate($pdo, $id, $sql, $pending);
                    $pending[] = $id;
                }
                $chain[] = $entry;
            }
            if ($selection['pending'] !== $pending) {
                throw new RuntimeException('APPLICATION_SCHEMA_LEDGER_CHANGED');
            }
            $lease = $this->lease();
        } finally {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
        }
        $prerequisites = [];
        try {
            $this->resultKey();
        } catch (RuntimeException) {
            $prerequisites[] = ['code' => 'APPLICATION_SCHEMA_NATIVE_RESULT_KEY_UNAVAILABLE', 'status' => 'blocked'];
        }
        if ($pending !== []) {
            foreach ($chain as $entry) {
                if (isset($entry['prestate'])) {
                    $prerequisites[] = [
                        'migration_id' => $entry['id'], 'status' => $entry['prestate']['status'],
                        'code' => 'APPLICATION_SCHEMA_FULL_PRESTATE_UNPROVEN',
                        'dependencies' => $entry['prestate']['dependencies'],
                        'next_step' => $entry['prestate']['next_step'],
                    ];
                }
            }
            if (!$lease['ddl']) {
                $prerequisites[] = ['code' => 'APPLICATION_SCHEMA_DDL_LEASE_REQUIRED', 'status' => 'blocked'];
            }
            // InstanceSafetyQueries exposes a maintenance key and a backup time,
            // not source/DB-bound writer exclusion or a restored recovery scope.
            // Do not invent a proof format, query private Ops tables, or accept a
            // caller-provided JSON assertion as a replacement for that capability.
            $prerequisites[] = [
                'code' => 'APPLICATION_SCHEMA_BOUND_MAINTENANCE_UNAVAILABLE', 'status' => 'blocked',
                'requirement' => 'Native target/DB/owner-bound active window and exclusion of all affected writers.',
            ];
            $prerequisites[] = [
                'code' => 'APPLICATION_SCHEMA_BOUND_RECOVERY_UNAVAILABLE', 'status' => 'blocked',
                'requirement' => 'Native protected recovery evidence covering the complete pending application chain and ledger.',
            ];
        }
        $plan = [
            'schema_version' => 1, 'protocol' => self::PROTOCOL,
            'scope' => 'application-schema', 'status' => $prerequisites === [] ? 'ready' : 'blocked',
            'may_apply' => $prerequisites === [], 'server_root' => $this->serverRoot,
            'installation' => $installation, 'target' => $target,
            'database' => array_intersect_key($config, array_flip(['environment', 'deployment_target', 'resource_id', 'endpoint_id', 'consumer', 'host', 'port', 'database'])),
            'registry_sha256' => $registrySha256,
            'lease' => $lease, 'target_version' => $targetVersion, 'default_release_version' => $default,
            'chain' => $chain, 'pending' => $pending, 'prerequisites' => $prerequisites,
            'observation' => 'Read-only interval; schema metadata is not MVCC and no external-writer exclusion is implied.',
            'transient_effects' => ['native_runner_advisory_lock'],
            'plugins' => 'not_run', 'product_database' => 'not_verified',
        ];
        $plan['plan_sha256'] = $this->digest($plan);
        return ['plan' => $plan, 'runner' => $runner, 'files' => $files, 'target' => $targetVersion, 'default' => $default];
    }

    private function target(array $installation): array
    {
        $root = dirname($this->serverRoot);
        $files = [
            'server/database/install.php', 'server/database/environment-guard.php',
            'server/app/command/ApplicationSchema.php',
            'server/app/common/services/upgrade/ApplicationSchemaHost.php',
            'server/app/common/services/upgrade/ApplicationMigrationRunner.php',
            'server/app/common/services/installation/InstallationExecutionHost.php',
            'server/app/common/value/installation/ApplicationSourceIdentity.php',
            'server/app/common/value/installation/ServerReleaseIdentity.php',
            'server/plugins.lock', 'server/config/console.php',
            'server/composer.json', 'server/composer.lock',
            'server/vendor/composer/installed.json', 'server/vendor/.peanut-complete.json',
        ];
        if (file_exists($this->serverRoot . '/.peanut/release-identity.json') || is_link($this->serverRoot . '/.peanut/release-identity.json')) {
            $identity = ServerReleaseIdentity::load($this->serverRoot);
            $kind = 'server-release';
            $mode = $identity->applicationIdentity()['edition'] ?? null;
            $files[] = 'server/.peanut/release-identity.json';
        } elseif (ApplicationSourceIdentity::hasRuntime($this->serverRoot)
            || file_exists($root . '/.peanut/application-manifest.json') || is_link($root . '/.peanut/application-manifest.json')) {
            $identity = ApplicationSourceIdentity::load($this->serverRoot);
            $kind = ApplicationSourceIdentity::hasRuntime($this->serverRoot) ? 'development-runtime' : 'generated-application';
            $mode = $identity->applicationIdentity()['edition'];
            $files[] = $kind === 'development-runtime' ? 'server/.peanut/development-runtime.json' : '.peanut/application-manifest.json';
            $files[] = $kind === 'development-runtime' ? 'server/.peanut/release-versions.json' : 'release-versions.json';
        } elseif (\installationSourceDevelopmentMode($this->serverRoot)
            && getenv('APP_ENV') === 'development' && filter_var(getenv('APP_DEBUG'), FILTER_VALIDATE_BOOLEAN)) {
            $kind = 'product-source';
            $mode = getenv('DEPLOYMENT_MODE');
            // Reuse the actual Edition profile parser; never infer an Edition
            // simply from a missing server release identity.
            \installationTenantBootstrapContract($this->serverRoot);
            $files = [...$files, 'release-versions.json', 'scaffold/application-template-inventory.json', 'scaffold/edition-profiles.json'];
        } else {
            throw new RuntimeException('APPLICATION_SCHEMA_TARGET_IDENTITY_UNAVAILABLE');
        }
        if ($mode !== ($installation['receipt']['deployment_mode'] ?? null)) {
            throw new RuntimeException('APPLICATION_SCHEMA_EDITION_MISMATCH');
        }
        $fingerprints = [];
        foreach ($files as $file) {
            $fingerprints[$file] = $this->fileDigest($root . '/' . $file);
        }
        $installed = json_decode((string) file_get_contents($this->serverRoot . '/vendor/composer/installed.json'), true, 512, JSON_THROW_ON_ERROR);
        $core = array_values(array_filter($installed['packages'] ?? [], static fn(array $row): bool => ($row['name'] ?? null) === 'peanut-admin/core'));
        if (count($core) !== 1) {
            throw new RuntimeException('APPLICATION_SCHEMA_CORE_IDENTITY_INVALID');
        }
        $corePath = realpath($this->serverRoot . '/vendor/composer/' . ($core[0]['install-path'] ?? ''));
        if (!is_string($corePath) || !is_dir($corePath) || is_link($this->serverRoot . '/vendor/peanut-admin/core')) {
            throw new RuntimeException('APPLICATION_SCHEMA_CORE_IDENTITY_INVALID');
        }
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($corePath, \FilesystemIterator::SKIP_DOTS));
        $coreFiles = [];
        foreach ($iterator as $file) {
            if ($file->isLink()) {
                throw new RuntimeException('APPLICATION_SCHEMA_CORE_IDENTITY_INVALID');
            }
            if ($file->isFile()) {
                $coreFiles[substr($file->getPathname(), strlen($corePath) + 1)] = hash_file('sha256', $file->getPathname());
            }
        }
        ksort($coreFiles, SORT_STRING);
        ksort($fingerprints, SORT_STRING);
        return [
            'kind' => $kind, 'deployment_mode' => $mode,
            'release' => \applicationReleaseVersions($this->serverRoot),
            'commit' => $kind === 'product-source' ? $this->git($root, ['rev-parse', 'HEAD^{commit}']) : null,
            'tree' => $kind === 'product-source' ? $this->git($root, ['rev-parse', 'HEAD^{tree}']) : null,
            'files' => $fingerprints,
            'core' => ['name' => $core[0]['name'], 'version' => $core[0]['version'], 'source' => $core[0]['source'] ?? null, 'files_sha256' => $this->digest($coreFiles)],
            'qualification' => 'not_claimed',
            'php' => ['version' => PHP_VERSION, 'binary_sha256' => $this->fileDigest(PHP_BINARY)],
        ];
    }

    /** Metadata and counts are observations, never a generic SQL safety proof. */
    private function prestate(PDO $pdo, string $id, string $sql, array $precedingPending): array
    {
        preg_match_all('/\bpa_[a-z0-9_]+\b/', $sql, $matches);
        preg_match_all('/CREATE\s+TEMPORARY\s+TABLE\s+`?(pa_[a-z0-9_]+)`?/i', $sql, $temporary);
        $tables = array_values(array_diff(array_unique($matches[0]), $temporary[1]));
        sort($tables, SORT_STRING);
        $facts = [];
        foreach ($tables as $table) {
            $query = $pdo->prepare('SELECT TABLE_TYPE,ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?');
            $query->execute([$table]);
            $shape = $query->fetch(PDO::FETCH_ASSOC) ?: null;
            if ($shape !== null && $shape['TABLE_TYPE'] === 'BASE TABLE') {
                $ddl = $pdo->query('SHOW CREATE TABLE `' . $table . '`')->fetch(PDO::FETCH_NUM);
                $facts[$table] = ['exists' => true, 'engine' => $shape['ENGINE'], 'ddl_sha256' => hash('sha256', (string) $ddl[1]), 'rows' => (string) $pdo->query('SELECT COUNT(*) FROM `' . $table . '`')->fetchColumn()];
                $columns = $pdo->prepare('SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? ORDER BY ORDINAL_POSITION');
                $columns->execute([$table]);
                $facts[$table]['columns'] = $columns->fetchAll(PDO::FETCH_COLUMN);
            } else {
                $facts[$table] = ['exists' => false, 'observed_type' => $shape['TABLE_TYPE'] ?? null];
            }
        }
        // These are dependencies of the immutable application SQL, not a second
        // migration executor. No successor SQL or DDL runs to fill an observation.
        $dependencies = match ($id) {
            '20261004-menu-stable-identities' => ['20261004-group-default-navigation'],
            '20261012-align-department-canonical-permissions',
            '20261012-repair-seeded-permission-parents' => [
                '20261004-group-default-navigation', '20261004-menu-stable-identities',
            ],
            default => [],
        };
        $unappliedDependencies = array_values(array_intersect($dependencies, $precedingPending));
        $status = $unappliedDependencies === [] ? 'unknown' : 'deferred';
        $next = $unappliedDependencies === []
            ? 'Prove the complete SQL prestate and DML predicates from native source-bound facts; do not replay on missing ledger alone.'
            : 'After the named predecessors are legitimately admitted and applied, re-observe the dependent predicates before admitting this successor; no pre-emptive DDL.';
        if ($id === '20261004-group-default-navigation') {
            $next = 'Prove original menu selectors and preservation rules; regroup predicates use columns created earlier inside this SQL and need a source-derived proof before admission.';
        }
        $additions = match ($id) {
            '20260909-notification-sms-reservation' => ['pa_notice_log' => ['reservation_key', 'idempotency_key_hash', 'request_digest', 'receiver_hash', 'reservation_until', 'reservation_active']],
            '20261004-group-default-navigation' => [
                'pa_menu_definition' => ['upstream_defaults_json', 'menu_conflict_json'],
                'pa_system_menu' => ['upstream_defaults_json', 'menu_conflict_json'],
            ],
            '20261004-menu-stable-identities' => [
                'pa_system_menu' => ['menu_key', 'parent_key', 'module_key'],
                'pa_menu_definition' => ['is_show', 'is_cache', 'is_disable'],
            ],
            default => [],
        };
        $structural = [];
        foreach ($additions as $table => $columns) {
            $present = array_values(array_intersect($columns, $facts[$table]['columns'] ?? []));
            $structural[$table] = [
                'expected_additions' => $columns, 'already_present' => $present,
                'classification' => ($facts[$table]['exists'] ?? false) !== true ? 'missing_base_table'
                    : ($present === [] ? 'before_additions' : (count($present) === count($columns) ? 'existing_post_shape_unproven' : 'partial_shape')),
            ];
            if ($present !== [] || ($facts[$table]['exists'] ?? false) !== true) {
                $status = 'collision';
                $next = 'Resolve the existing or partial shape through its native repair/adoption contract; do not replay or fill a ledger row.';
            }
        }
        return [
            'status' => $status, 'tables' => $facts,
            'structural_observations' => $structural,
            'dependencies' => $dependencies, 'unapplied_dependencies' => $unappliedDependencies,
            'next_step' => $next, 'complete_preconditions_proven' => null,
        ];
    }

    private function lease(): array
    {
        $path = \requiredEnvironment('PEANUT_RESOURCE_LEASE_PROOF');
        $metadata = \activeLeaseMetadata($path, time(), null);
        $resources = \activeLeaseResources($path);
        $ddl = false;
        foreach ($resources['mysql-access'] ?? [] as $value) {
            $access = json_decode($value, true, 64, JSON_THROW_ON_ERROR);
            $ddl = $ddl || (($access['complete'] ?? null) === true
                && in_array($access['operation'] ?? null, ['ddl', 'exclusive'], true)
                && ($access['tables'] ?? null) === []);
        }
        return ['lease' => $metadata['lease'], 'owner' => $metadata['owner'], 'thread' => $metadata['thread'], 'candidate' => $metadata['candidate'], 'ddl' => $ddl];
    }

    private function protectedJson(string $path): array
    {
        if (!str_starts_with($path, '/') || !is_file($path) || is_link($path)
            || (fileperms($path) & 0777) !== 0600 || filesize($path) > 4194304) {
            throw new RuntimeException('APPLICATION_SCHEMA_DOCUMENT_UNSAFE');
        }
        $value = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($value)) {
            throw new RuntimeException('APPLICATION_SCHEMA_DOCUMENT_INVALID');
        }
        return $value;
    }

    private function assertDocument(array $value, string $protocol, string $hashKey): void
    {
        $digest = $value[$hashKey] ?? null;
        unset($value[$hashKey]);
        if (($value['schema_version'] ?? null) !== 1 || ($value['protocol'] ?? null) !== $protocol
            || ($value['scope'] ?? null) !== 'application-schema'
            || ($value['plugins'] ?? null) !== 'not_run' || ($value['product_database'] ?? null) !== 'not_verified'
            || !is_string($digest) || !hash_equals($digest, $this->digest($value))) {
            throw new RuntimeException('APPLICATION_SCHEMA_DOCUMENT_INVALID');
        }
    }

    private function saveResult(array $record): string
    {
        $directory = $this->serverRoot . '/private/application-schema';
        if ((!is_dir($directory) && !mkdir($directory, 0700)) || is_link($directory)
            || (fileperms($directory) & 0777) !== 0700) {
            throw new RuntimeException('APPLICATION_SCHEMA_RESULT_DIRECTORY_UNSAFE');
        }
        $path = $directory . '/result-' . bin2hex(random_bytes(16)) . '.json';
        $stream = fopen($path, 'x');
        $bytes = $this->canonical($record) . "\n";
        if ($stream === false || !chmod($path, 0600) || fwrite($stream, $bytes) !== strlen($bytes)
            || !fflush($stream) || !fsync($stream)) {
            throw new RuntimeException('APPLICATION_SCHEMA_RESULT_WRITE_FAILED');
        }
        fclose($stream);
        $signaturePath = $path . '.hmac';
        $signature = hash_hmac('sha256', $bytes, $this->resultKey()) . "\n";
        $stream = fopen($signaturePath, 'x');
        if ($stream === false || !chmod($signaturePath, 0600)
            || fwrite($stream, $signature) !== strlen($signature) || !fflush($stream) || !fsync($stream)) {
            throw new RuntimeException('APPLICATION_SCHEMA_RESULT_WRITE_FAILED');
        }
        fclose($stream);
        return $path;
    }

    /** Reuse the native update result key; never generate a new signing authority. */
    private function resultKey(): string
    {
        $legacy = $this->serverRoot . '/private/installation/update-verification.key';
        $parentPath = $this->serverRoot . '/private';
        $directory = $parentPath . '/maintenance';
        $owner = fileowner($this->serverRoot);
        $parent = is_dir($parentPath) && !is_link($parentPath) ? lstat($parentPath) : false;
        $stat = is_dir($directory) && !is_link($directory) ? lstat($directory) : false;
        // Match the existing native maintenance reader without its create path.
        // A legacy key requires its original owner; never revive or relocate it.
        if (file_exists($legacy) || is_link($legacy)
            || !is_int($owner) || !function_exists('posix_geteuid')
            || !in_array(posix_geteuid(), [0, $owner], true)
            || !is_array($parent) || !in_array($parent['uid'], [0, $owner], true)
            || ($parent['mode'] & 0022) !== 0
            || !is_array($stat) || !in_array($stat['uid'], [0, $owner], true)
            || ($stat['mode'] & 0777) !== 0700) {
            throw new RuntimeException('APPLICATION_SCHEMA_NATIVE_RESULT_KEY_UNAVAILABLE');
        }
        $path = $directory . '/update-verification.key';
        $keyStat = is_file($path) && !is_link($path) ? lstat($path) : false;
        if (!is_array($keyStat) || $keyStat['nlink'] !== 1 || ($keyStat['mode'] & 0777) !== 0600) {
            throw new RuntimeException('APPLICATION_SCHEMA_NATIVE_RESULT_KEY_UNAVAILABLE');
        }
        $key = trim((string) file_get_contents($path));
        if (preg_match('/\A[a-f0-9]{64}\z/D', $key) !== 1) {
            throw new RuntimeException('APPLICATION_SCHEMA_NATIVE_RESULT_KEY_UNAVAILABLE');
        }
        return $key;
    }

    private function fileDigest(string $path): string
    {
        if (!is_file($path) || is_link($path)) {
            throw new RuntimeException('APPLICATION_SCHEMA_SOURCE_FILE_INVALID');
        }
        return (string) hash_file('sha256', $path);
    }

    private function git(string $root, array $arguments): string
    {
        return \leaseGit(['-C', $root, ...$arguments]);
    }

    private function digest(array $value): string
    {
        return hash('sha256', $this->canonical($value));
    }

    private function canonical(array $value): string
    {
        $normalize = static function (array $items) use (&$normalize): array {
            if (!array_is_list($items)) {
                ksort($items, SORT_STRING);
            }
            foreach ($items as $key => $item) {
                if (is_array($item)) {
                    $items[$key] = $normalize($item);
                }
            }
            return $items;
        };
        return json_encode($normalize($value), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
}
