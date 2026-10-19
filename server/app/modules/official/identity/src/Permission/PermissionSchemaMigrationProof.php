<?php

declare(strict_types=1);

namespace PeanutAdmin\Modules\Identity\Permission;

use Closure;
use DateTimeImmutable;
use DateTimeZone;
use PDO;
use RuntimeException;
use Throwable;

/** Fixed Identity business assertions; native migration execution and real recovery belong to the host. */
final class PermissionSchemaMigrationProof
{
    private const READINESS = '20261007-readiness-permission';
    private const DEPARTMENT = '20261012-align-department-canonical-permissions';
    private const PLATFORM = '20261012-align-platform-ops-permission-ownership';
    private const SOURCES = [
        self::READINESS => '7a275b6475893fd0076ef8c3f6386d53343ef30cf3d0434dbef317fedcc28034',
        self::DEPARTMENT => '1267c4b33baa4567ae45f12eac6edc7d0538cd9fac4f9c4ccc61e2b37eb793bf',
        self::PLATFORM => '038766e7e9ab5356cacf11cc6bb2dd675af02cd9bd3180efd356a8234d6aa1d9',
    ];
    private const LEGACY_KEYS = "'dept/lists','dept/detail','dept/all','dept/leaderdept','dept/add','dept/edit','dept/delete','dept/status'";
    private const CANONICAL_KEYS = "'core.department.read','core.department.create','core.department.update','core.department.move','core.department.archive'";
    private const MAPPING = <<<'SQL'
SELECT 'dept/lists' AS legacy_key,'core.department.read' AS canonical_key
UNION ALL SELECT 'dept/detail','core.department.read'
UNION ALL SELECT 'dept/all','core.department.read'
UNION ALL SELECT 'dept/leaderdept','core.department.read'
UNION ALL SELECT 'dept/add','core.department.create'
UNION ALL SELECT 'dept/edit','core.department.update'
UNION ALL SELECT 'dept/edit','core.department.move'
UNION ALL SELECT 'dept/delete','core.department.archive'
UNION ALL SELECT 'dept/status','core.department.update'
SQL;
    private const ACTION_SEEDS = <<<'SQL'
SELECT 'core.department.create' AS action_key,'新增部门' AS action_name,40 AS sort_order
UNION ALL SELECT 'core.department.update','编辑部门',30
UNION ALL SELECT 'core.department.move','移动部门',20
UNION ALL SELECT 'core.department.archive','归档部门',10
SQL;

    /** Only safe_summary is public. private_state is held callback memory, never a backup or log payload. */
    public static function before(PDO $pdo, array $migration): array
    {
        $id = $migration['id'] ?? null;
        $sql = $migration['sql'] ?? null;
        $sha = $migration['checksum'] ?? $migration['sha256'] ?? null;
        if (!is_string($id) || !isset(self::SOURCES[$id]) || !is_string($sql)
            || $sha !== self::SOURCES[$id] || !hash_equals($sha, hash('sha256', $sql))
            || (isset($migration['sha256']) && $migration['sha256'] !== $sha)) {
            self::fail('SOURCE_UNKNOWN');
        }
        return self::snapshot($pdo, static function () use ($pdo, $id, $sha): array {
            $connection = self::connection($pdo);
            $business = match ($id) {
                self::READINESS => self::beforeReadiness($pdo),
                self::DEPARTMENT => self::beforeDepartment($pdo),
                self::PLATFORM => self::beforePlatform($pdo),
            };
            return ['migration_id' => $id, 'source_sha256' => $sha, 'readonly' => true,
                'safe_summary' => ['migration_id' => $id, 'source_sha256' => $sha, 'readonly' => true,
                    'protected_checks' => count($business['checks']), 'candidate_count' => $business['candidate_count']],
                'private_state' => ['pdo_object_id' => spl_object_id($pdo), 'connection' => $connection, 'business' => $business]];
        });
    }

    /** Native immutable-sql-batch bounds are mandatory only for the two sources that actually assign UTC time. */
    public static function after(PDO $pdo, array $before, ?array $executionBounds): array
    {
        $id = $before['migration_id'] ?? null;
        $private = $before['private_state'] ?? null;
        if (!is_string($id) || !isset(self::SOURCES[$id]) || ($before['source_sha256'] ?? null) !== self::SOURCES[$id]
            || !is_array($private) || ($private['pdo_object_id'] ?? null) !== spl_object_id($pdo)
            || !is_array($private['connection'] ?? null) || !is_array($private['business'] ?? null)) {
            self::fail('INPUT_INVALID');
        }
        return self::snapshot($pdo, static function () use ($pdo, $before, $id, $private, $executionBounds): array {
            $connection = self::connection($pdo);
            foreach (['database', 'server_uuid', 'connection_id'] as $key) {
                if ($private['connection'][$key] !== $connection[$key]) { self::fail('CONNECTION_CHANGED'); }
            }
            $bounds = self::bounds($before, $connection, $executionBounds);
            $state = $private['business'];
            foreach ($state['checks'] as $check) {
                if (!hash_equals($check['digest'], self::digest($pdo, $check['after']))) { self::fail('PROTECTED_FIELDS_CHANGED'); }
            }
            match ($id) {
                self::READINESS => self::afterReadiness($pdo, $state, $bounds),
                self::DEPARTMENT => self::afterDepartment($pdo, $state, $bounds),
                self::PLATFORM => self::afterPlatform($pdo, $state),
            };
            return ['migration_id' => $id, 'source_sha256' => self::SOURCES[$id], 'readonly' => true,
                'business_postconditions_verified' => true, 'protected_checks' => count($state['checks']),
                'candidate_count' => $state['candidate_count'],
                'execution_bounds_scope' => $bounds === null ? null : 'immutable-sql-batch'];
        });
    }

    private static function beforeReadiness(PDO $pdo): array
    {
        $rows = self::query($pdo, "SELECT id FROM pa_permission WHERE `key`='readiness/checklist'");
        if (count($rows) > 1) { self::fail('READINESS_IDENTITY_AMBIGUOUS'); }
        $id = $rows === [] ? null : self::id($rows[0]['id']);
        $checks = [];
        $mutable = ['module_key','type','name','description','risk_level','status','manifest_version','updated_at','retired_at'];
        if ($id !== null) { self::protect($pdo, $checks, 'pa_permission', $mutable, "id=$id"); }
        // This source writes this catalog table only. Protect its original non-target records, not unrelated tables.
        self::protect($pdo, $checks, 'pa_permission', [], "`key`<>'readiness/checklist'");
        self::bindings($pdo, $checks, $id === null ? [] : [$id], true);
        return ['checks' => $checks, 'candidate_count' => count($rows), 'id' => $id,
            'rows' => self::count($pdo, 'SELECT COUNT(*) FROM pa_permission')];
    }

    private static function afterReadiness(PDO $pdo, array $state, ?array $bounds): void
    {
        $rows = self::query($pdo, "SELECT id FROM pa_permission WHERE `key`='readiness/checklist'");
        if (count($rows) !== 1 || ($state['id'] !== null && self::id($rows[0]['id']) !== $state['id'])) { self::fail('READINESS_ID_CHANGED'); }
        $id = self::id($rows[0]['id']);
        self::zero($pdo, "SELECT COUNT(*) FROM pa_permission WHERE id=$id AND NOT (BINARY module_key<=>BINARY 'peanut.admin' AND BINARY type<=>BINARY 'api' AND BINARY name<=>BINARY '生产准备清单' AND BINARY description<=>BINARY 'Read the tenant first-run readiness checklist.' AND BINARY risk_level<=>BINARY 'normal' AND BINARY status<=>BINARY 'active' AND BINARY manifest_version<=>BINARY '5.0.1-readiness-v1' AND retired_at IS NULL)");
        self::time($pdo, 'pa_permission', 'updated_at', [$id], $bounds);
        if ($state['id'] === null) {
            self::time($pdo, 'pa_permission', 'created_at', [$id], $bounds);
            self::zero($pdo, "SELECT COUNT(*) FROM pa_permission WHERE id=$id AND NOT (created_at<=>updated_at)");
            foreach (self::bindingTables(true) as $table => $field) {
                self::zero($pdo, 'SELECT COUNT(*) FROM ' . self::quote($table) . ' WHERE ' . self::quote($field) . "=$id");
            }
        }
        if (self::count($pdo, 'SELECT COUNT(*) FROM pa_permission') !== $state['rows'] + ($state['id'] === null ? 1 : 0)) { self::fail('READINESS_ROW_COUNT'); }
    }

    private static function beforePlatform(PDO $pdo): array
    {
        $where = "`key` IN ('platform.ops.module.manage','platform.ops.upgrade.manage')";
        $rows = self::query($pdo, "SELECT id,(module_key='peanut.admin') AS changes FROM pa_permission WHERE $where AND module_key IN ('peanut.admin','platform') ORDER BY id");
        if (count($rows) !== 2 || self::count($pdo, "SELECT COUNT(*) FROM pa_permission WHERE $where") !== 2) { self::fail('PLATFORM_OWNER_UNKNOWN'); }
        $ids = array_map(static fn(array $r): string => self::id($r['id']), $rows);
        $changed = array_map(static fn(array $r): string => self::id($r['id']), array_values(array_filter($rows, static fn(array $r): bool => (string)$r['changes'] === '1')));
        $checks = [];
        self::protect($pdo, $checks, 'pa_permission', ['module_key'], 'id IN (' . self::ids($changed) . ')');
        self::protect($pdo, $checks, 'pa_permission', [], 'id NOT IN (' . self::ids($changed) . ')');
        self::bindings($pdo, $checks, $ids, true);
        return ['checks' => $checks, 'candidate_count' => 2, 'ids' => $ids, 'changed' => $changed];
    }

    private static function afterPlatform(PDO $pdo, array $state): void
    {
        self::zero($pdo, 'SELECT COUNT(*) FROM pa_permission WHERE id IN (' . self::ids($state['ids']) . ") AND NOT (module_key='platform')");
        self::zero($pdo, 'SELECT COUNT(*) FROM pa_permission WHERE id IN (' . self::ids($state['changed']) . ") AND NOT (BINARY module_key=BINARY 'platform')");
    }

    private static function beforeDepartment(PDO $pdo): array
    {
        $canonical = self::query($pdo, 'SELECT COUNT(*) AS total,SUM(module_key=\'core\' AND status=\'active\') AS valid FROM pa_permission WHERE `key` IN (' . self::CANONICAL_KEYS . ')')[0];
        if ((int)$canonical['total'] !== 5 || (int)$canonical['valid'] !== 5) { self::fail('DEPARTMENT_CANONICAL_CATALOG'); }
        self::zero($pdo, 'SELECT COUNT(*) FROM pa_permission WHERE `key` IN (' . self::LEGACY_KEYS . ") AND (module_key NOT IN ('application','peanut.admin') OR manifest_version<>'fresh-schema-v1')");
        foreach (self::bindingTables(false) as $table => $field) {
            self::zero($pdo, 'SELECT COUNT(*) FROM ' . self::quote($table) . ' r JOIN pa_permission p ON p.id=r.' . self::quote($field) . ' WHERE p.`key` IN (' . self::LEGACY_KEYS . ')');
        }
        $actions = '(' . self::ACTION_SEEDS . ')';
        self::zero($pdo, "SELECT COUNT(*) FROM pa_system_menu m JOIN $actions seed ON m.menu_key=seed.action_key OR m.perms=seed.action_key WHERE NOT (" . self::actionFingerprint('m', 'seed') . ')');
        self::zero($pdo, "SELECT COUNT(*) FROM (SELECT seed.action_key FROM $actions seed JOIN pa_system_menu m ON m.menu_key=seed.action_key OR m.perms=seed.action_key GROUP BY seed.action_key HAVING COUNT(*)>1) conflicts");

        $pageIds = array_column(self::query($pdo, self::departmentPageSelect()), 'id');
        $childIds = array_column(self::query($pdo, self::departmentChildSelect($pageIds)), 'id');
        $menus = array_merge($pageIds, $childIds);
        $menuIds = self::ids($menus);
        // These are the same explicit pre-write rejections as the immutable source, not an additional policy.
        self::zero($pdo, "SELECT COUNT(*) FROM pa_system_menu WHERE id NOT IN ($menuIds) AND (perms IN (" . self::LEGACY_KEYS . ") OR menu_key IN ('system.dept.lists','system.dept.add','system.dept.edit','system.dept.delete'))");
        self::zero($pdo, "SELECT COUNT(*) FROM (SELECT perms FROM pa_system_menu WHERE id IN ($menuIds) GROUP BY perms HAVING COUNT(*)>1) duplicates");
        self::zero($pdo, "SELECT COUNT(*) FROM pa_system_menu child JOIN pa_system_menu parent ON parent.id IN ($menuIds) AND (child.pid=parent.id OR child.parent_key=parent.menu_key) WHERE child.id NOT IN ($menuIds)");

        $moves = self::departmentMoves();
        $ranked = 'WITH moves AS (' . $moves . '),ranked AS (SELECT legacy_grant_id,tenant_id,role_id,permission_id,granted_by_member_id,granted_at,ROW_NUMBER() OVER (PARTITION BY tenant_id,role_id,permission_id ORDER BY granted_at,legacy_grant_id) AS grant_rank FROM moves) ';
        $existing = 'LEFT JOIN pa_role_permission existing ON existing.tenant_id=moved.tenant_id AND existing.role_id=moved.role_id AND existing.permission_id=moved.permission_id';
        $keys = self::query($pdo, $ranked . 'SELECT tenant_id,role_id,permission_id FROM ranked WHERE grant_rank=1 ORDER BY tenant_id,role_id,permission_id');
        $deleted = array_column(self::query($pdo, 'SELECT DISTINCT legacy_grant_id AS id FROM (' . $moves . ') moved ORDER BY legacy_grant_id'), 'id');
        $newGrantCount = self::count($pdo, $ranked . "SELECT COUNT(*) FROM ranked moved $existing WHERE moved.grant_rank=1 AND existing.id IS NULL");
        $checks = [];
        $expectedGrants = $ranked . 'SELECT moved.tenant_id,moved.role_id,moved.permission_id,CASE WHEN existing.id IS NULL THEN moved.granted_by_member_id ELSE existing.granted_by_member_id END AS granted_by_member_id,CASE WHEN existing.id IS NULL THEN moved.granted_at ELSE existing.granted_at END AS granted_at FROM ranked moved ' . $existing . ' WHERE moved.grant_rank=1 ORDER BY moved.tenant_id,moved.role_id,moved.permission_id';
        self::check($pdo, $checks, $expectedGrants, 'SELECT tenant_id,role_id,permission_id,granted_by_member_id,granted_at FROM pa_role_permission WHERE ' . self::grantKeys($keys) . ' ORDER BY tenant_id,role_id,permission_id');

        $grantMax = self::maximum($pdo, 'pa_role_permission');
        self::protect($pdo, $checks, 'pa_role_permission', [], 'id<=' . $grantMax . ' AND id NOT IN (' . self::ids($deleted) . ')');
        $roles = array_column(self::query($pdo, 'SELECT DISTINCT r.id FROM pa_role r JOIN (' . $moves . ') moved ON moved.tenant_id=r.tenant_id AND moved.role_id=r.id ORDER BY r.id'), 'id');
        $tenants = array_column(self::query($pdo, 'SELECT DISTINCT t.id FROM pa_tenant t JOIN (' . $moves . ') moved ON moved.tenant_id=t.id ORDER BY t.id'), 'id');
        foreach (['pa_role' => $roles, 'pa_tenant' => $tenants] as $table => $ids) {
            $selected = 'id IN (' . self::ids($ids) . ')';
            self::check($pdo, $checks, "SELECT id,authorization_revision+1 AS revision FROM $table WHERE $selected ORDER BY id", "SELECT id,authorization_revision AS revision FROM $table WHERE $selected ORDER BY id");
            self::protect($pdo, $checks, $table, ['authorization_revision','updated_at'], $selected);
            self::protect($pdo, $checks, $table, [], 'id NOT IN (' . self::ids($ids) . ')');
        }
        $retiring = self::query($pdo, 'SELECT id,(retired_at IS NULL) AS needs_retired_time FROM pa_permission WHERE `key` IN (' . self::LEGACY_KEYS . ") AND status<>'retired' ORDER BY id");
        $retireIds = array_column($retiring, 'id');
        $nullRetired = array_column(array_values(array_filter($retiring, static fn(array $r): bool => (string)$r['needs_retired_time'] === '1')), 'id');
        $keptRetired = array_column(array_values(array_filter($retiring, static fn(array $r): bool => (string)$r['needs_retired_time'] !== '1')), 'id');
        $retireWhere = 'id IN (' . self::ids($retireIds) . ')';
        self::protect($pdo, $checks, 'pa_permission', ['status','retired_at','updated_at'], $retireWhere);
        self::protect($pdo, $checks, 'pa_permission', [], 'id NOT IN (' . self::ids($retireIds) . ')');
        self::check($pdo, $checks, 'SELECT id,retired_at FROM pa_permission WHERE id IN (' . self::ids($keptRetired) . ') ORDER BY id', 'SELECT id,retired_at FROM pa_permission WHERE id IN (' . self::ids($keptRetired) . ') ORDER BY id');
        $permissionIds = array_column(self::query($pdo, 'SELECT id FROM pa_permission WHERE `key` IN (' . self::LEGACY_KEYS . ',' . self::CANONICAL_KEYS . ') ORDER BY id'), 'id');
        self::bindings($pdo, $checks, $permissionIds, false);

        $menuMax = self::maximum($pdo, 'pa_system_menu');
        self::protect($pdo, $checks, 'pa_system_menu', ['is_show','is_disable'], "id IN ($menuIds)");
        self::protect($pdo, $checks, 'pa_system_menu', [], "id<=$menuMax AND id NOT IN ($menuIds)");
        $missing = array_column(self::query($pdo, "SELECT seed.action_key FROM $actions seed WHERE NOT EXISTS (SELECT 1 FROM pa_system_menu m WHERE m.menu_key=seed.action_key)"), 'action_key');
        return ['checks' => $checks, 'candidate_count' => count($menus) + count($keys) + count($retireIds),
            'menus' => $menus, 'menu_max' => $menuMax, 'menu_rows' => self::count($pdo, 'SELECT COUNT(*) FROM pa_system_menu'),
            'missing_actions' => $missing,
            'deleted_grants' => $deleted, 'grant_max' => $grantMax, 'grant_rows' => self::count($pdo, 'SELECT COUNT(*) FROM pa_role_permission'),
            'new_grants' => $newGrantCount, 'roles' => $roles, 'tenants' => $tenants,
            'retire_ids' => $retireIds, 'null_retired_ids' => $nullRetired];
    }

    private static function afterDepartment(PDO $pdo, array $state, ?array $bounds): void
    {
        self::zero($pdo, 'SELECT COUNT(*) FROM pa_role_permission WHERE id IN (' . self::ids($state['deleted_grants']) . ')');
        if (self::count($pdo, 'SELECT COUNT(*) FROM pa_role_permission') !== $state['grant_rows'] - count($state['deleted_grants']) + $state['new_grants']
            || self::count($pdo, 'SELECT COUNT(*) FROM pa_role_permission WHERE id>' . $state['grant_max']) !== $state['new_grants']) { self::fail('DEPARTMENT_GRANT_SET'); }
        self::time($pdo, 'pa_role', 'updated_at', $state['roles'], $bounds);
        self::time($pdo, 'pa_tenant', 'updated_at', $state['tenants'], $bounds);
        self::time($pdo, 'pa_permission', 'updated_at', $state['retire_ids'], $bounds);
        self::time($pdo, 'pa_permission', 'retired_at', $state['null_retired_ids'], $bounds);
        self::zero($pdo, 'SELECT COUNT(*) FROM pa_permission WHERE id IN (' . self::ids($state['retire_ids']) . ") AND NOT (BINARY status<=>BINARY 'retired')");
        self::zero($pdo, 'SELECT COUNT(*) FROM pa_system_menu WHERE id IN (' . self::ids($state['menus']) . ') AND NOT (is_show<=>0 AND is_disable<=>1)');
        $inserted = self::query($pdo, 'SELECT id,menu_key FROM pa_system_menu WHERE id>' . $state['menu_max']);
        if (count($inserted) !== count($state['missing_actions']) || self::count($pdo, 'SELECT COUNT(*) FROM pa_system_menu') !== $state['menu_rows'] + count($state['missing_actions'])) { self::fail('DEPARTMENT_ACTION_SET'); }
        foreach ($inserted as $row) {
            if (!in_array($row['menu_key'], $state['missing_actions'], true)) { self::fail('DEPARTMENT_ACTION_UNKNOWN'); }
            $key = $pdo->quote($row['menu_key']);
            $id = self::id($row['id']);
            self::zero($pdo, 'SELECT COUNT(*) FROM pa_system_menu m JOIN (' . self::ACTION_SEEDS . ") seed ON seed.action_key=$key WHERE m.id=$id AND NOT (BINARY m.menu_key<=>BINARY seed.action_key AND BINARY m.perms<=>BINARY seed.action_key AND BINARY m.parent_key<=>BINARY 'core.department.list' AND BINARY m.module_key<=>BINARY 'core' AND m.pid<=>0 AND BINARY m.type<=>BINARY 'A' AND BINARY m.name<=>BINARY seed.action_name AND m.sort<=>seed.sort_order AND BINARY m.icon<=>BINARY '' AND BINARY m.paths<=>BINARY '' AND BINARY m.component<=>BINARY '' AND m.is_cache<=>0 AND m.is_show<=>1 AND m.is_disable<=>0 AND m.upstream_defaults_json IS NULL AND m.menu_conflict_json IS NULL)");
        }
        if (count(array_unique(array_column($inserted, 'menu_key'))) !== count($state['missing_actions'])) { self::fail('DEPARTMENT_ACTION_DUPLICATE'); }
    }

    private static function departmentMoves(): string
    {
        return 'SELECT r.id AS legacy_grant_id,r.tenant_id,r.role_id,canonical.id AS permission_id,r.granted_by_member_id,r.granted_at FROM pa_role_permission r JOIN pa_permission legacy ON legacy.id=r.permission_id JOIN (' . self::MAPPING . ") mapping ON mapping.legacy_key=legacy.`key` JOIN pa_permission canonical ON canonical.`key`=mapping.canonical_key WHERE legacy.status='active'";
    }

    /** Source's complete historical page fingerprint; only its PK is retained. */
    private static function departmentPageSelect(): string
    {
        return <<<'SQL'
SELECT m.id FROM pa_system_menu m
JOIN pa_system_menu parent ON parent.id=m.pid AND parent.menu_key=m.parent_key
WHERE m.menu_key='system.dept.lists' AND m.module_key='peanut.admin'
AND m.type='C' AND m.name='部门管理' AND m.perms='dept/lists'
AND m.paths='/system/dept' AND m.component='system/dept/index'
AND m.icon='icon-mind-mapping' AND m.sort=70
AND m.is_cache=0 AND m.is_show=1 AND m.is_disable=0
AND parent.menu_key='core.organization' AND parent.type='M' AND parent.paths='/system'
AND m.menu_conflict_json IS NULL
AND (m.upstream_defaults_json IS NULL OR m.upstream_defaults_json=JSON_OBJECT('pid',m.pid,'type',m.type,'name',m.name,'icon',m.icon,'sort',m.sort,'perms',m.perms,'paths',m.paths,'component',m.component,'is_cache',m.is_cache,'is_show',m.is_show,'is_disable',m.is_disable))
SQL;
    }

    private static function departmentChildSelect(array $pageIds): string
    {
        return 'SELECT m.id FROM pa_system_menu m JOIN pa_system_menu parent ON parent.id=m.pid AND parent.menu_key=m.parent_key AND parent.id IN (' . self::ids($pageIds) . ") WHERE m.type='A' AND m.paths='' AND m.component='' AND m.icon='' AND m.is_cache=0 AND m.is_show=1 AND m.is_disable=0 AND m.upstream_defaults_json IS NULL AND m.menu_conflict_json IS NULL AND ((m.module_key='peanut.admin' AND ((m.menu_key='system.dept.add' AND m.perms='dept/add' AND m.name='新增部门' AND m.sort=30) OR (m.menu_key='system.dept.edit' AND m.perms='dept/edit' AND m.name='编辑部门' AND m.sort=20) OR (m.menu_key='system.dept.delete' AND m.perms='dept/delete' AND m.name='删除部门' AND m.sort=10))) OR (m.module_key='application' AND m.sort=0 AND ((m.perms='dept/all' AND m.name='部门选择') OR (m.perms='dept/detail' AND m.name='部门详情') OR (m.perms='dept/leaderdept' AND m.name='负责人部门') OR (m.perms='dept/status' AND m.name='部门状态'))))";
    }

    private static function actionFingerprint(string $menu, string $seed): string
    {
        return "$menu.menu_key<=>$seed.action_key AND $menu.perms=$seed.action_key AND $menu.parent_key<=>'core.department.list' AND $menu.module_key='core' AND $menu.pid=0 AND $menu.type='A' AND $menu.name=$seed.action_name AND $menu.sort=$seed.sort_order AND $menu.icon='' AND $menu.paths='' AND $menu.component='' AND $menu.is_cache=0 AND $menu.is_show=1 AND $menu.is_disable=0 AND $menu.upstream_defaults_json IS NULL AND $menu.menu_conflict_json IS NULL";
    }

    private static function grantKeys(array $keys): string
    {
        if ($keys === []) { return '0=1'; }
        return '(tenant_id,role_id,permission_id) IN (' . implode(',', array_map(static fn(array $r): string => '(' . self::id($r['tenant_id']) . ',' . self::id($r['role_id']) . ',' . self::id($r['permission_id']) . ')', $keys)) . ')';
    }

    private static function maximum(PDO $pdo, string $table): string
    {
        return self::id(self::query($pdo, 'SELECT CAST(COALESCE(MAX(id),0) AS CHAR) AS id FROM ' . self::quote($table))[0]['id']);
    }

    private static function bindingTables(bool $roles): array
    {
        return ($roles ? ['pa_role_permission' => 'permission_id'] : []) + [
            'pa_platform_role_permission' => 'permission_id', 'pa_resource_operation_permission' => 'permission_id',
            'pa_resource_operation_target_type' => 'policy_selection_permission_id', 'pa_menu_definition' => 'required_permission_id',
        ];
    }

    private static function bindings(PDO $pdo, array &$checks, array $permissionIds, bool $roles): void
    {
        foreach (self::bindingTables($roles) as $table => $field) {
            self::protect($pdo, $checks, $table, [], self::quote($field) . ' IN (' . self::ids($permissionIds) . ')');
        }
    }

    /** Actual written tables' protected records, or exact permission FK binding subsets; no row images leave SQL. */
    private static function protect(PDO $pdo, array &$checks, string $table, array $except, string $where): void
    {
        $parts = [];
        foreach (self::query($pdo, 'SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=' . $pdo->quote($table) . ' ORDER BY ORDINAL_POSITION') as $column) {
            if (!in_array($column['COLUMN_NAME'], $except, true)) {
                $field = self::quote($column['COLUMN_NAME']);
                $parts[] = "IF($field IS NULL,'N',CONCAT('S',SHA2(CAST($field AS BINARY),256)))";
            }
        }
        if ($parts === []) { self::fail('PROTECTED_COLUMNS_MISSING'); }
        $select = 'SELECT SHA2(CONCAT(' . implode(',', $parts) . '),256) AS row_digest FROM ' . self::quote($table) . " WHERE $where ORDER BY row_digest";
        self::check($pdo, $checks, $select, $select);
    }

    private static function check(PDO $pdo, array &$checks, string $before, string $after): void
    {
        $checks[] = ['digest' => self::digest($pdo, $before), 'after' => $after];
    }

    /** Fixed SELECT summaries are streamed; string fetching preserves native BIGINT/DECIMAL precision. */
    private static function digest(PDO $pdo, string $select): string
    {
        $stringify = $pdo->getAttribute(PDO::ATTR_STRINGIFY_FETCHES);
        $statement = null;
        $hash = hash_init('sha256');
        try {
            $pdo->setAttribute(PDO::ATTR_STRINGIFY_FETCHES, true);
            $statement = $pdo->query($select);
            while (($row = $statement->fetch(PDO::FETCH_NUM)) !== false) {
                foreach ($row as $value) { hash_update($hash, $value === null ? 'N;' : 'S' . strlen($value) . ':' . $value . ';'); }
                hash_update($hash, "\n");
            }
            return hash_final($hash);
        } finally { $statement?->closeCursor(); $pdo->setAttribute(PDO::ATTR_STRINGIFY_FETCHES, $stringify); }
    }

    private static function time(PDO $pdo, string $table, string $column, array $ids, ?array $bounds): void
    {
        if ($bounds === null) { self::fail('EXECUTION_BOUNDS_REQUIRED'); }
        $field = self::quote($column);
        $low = $pdo->quote($bounds[0]); $high = $pdo->quote($bounds[1]);
        self::zero($pdo, 'SELECT COUNT(*) FROM ' . self::quote($table) . ' WHERE id IN (' . self::ids($ids) . ") AND ($field IS NULL OR $field<CAST($low AS DATETIME(3)) OR $field>CAST($high AS DATETIME(3)) OR MOD(MICROSECOND($field),1000)<>0)");
    }

    private static function bounds(array $before, array $connection, ?array $bounds): ?array
    {
        if ($before['migration_id'] === self::PLATFORM) {
            if ($bounds !== null) { self::fail('UNEXPECTED_TIME_ALLOWANCE'); }
            return null;
        }
        $private = $before['private_state'];
        if (($bounds['scope'] ?? null) !== 'immutable-sql-batch' || ($bounds['migration_key'] ?? null) !== $before['migration_id']
            || ($bounds['sha256'] ?? null) !== $before['source_sha256'] || ($bounds['pdo_object_id'] ?? null) !== $private['pdo_object_id']) {
            self::fail('EXECUTION_BOUNDS_INVALID');
        }
        foreach (['before','after'] as $stage) {
            $point = $bounds[$stage] ?? [];
            if (($point['database'] ?? null) !== $connection['database'] || ($point['connection_id'] ?? null) !== $connection['connection_id']
                || !is_string($point['unix_second'] ?? null) || preg_match('/^[0-9]+$/D', $point['unix_second']) !== 1
                || !self::utc($point['utc_timestamp'] ?? null)) { self::fail('EXECUTION_BOUNDS_INVALID'); }
        }
        $low = $bounds['before']['utc_timestamp']; $high = $bounds['after']['utc_timestamp'];
        if ($low < $private['connection']['utc_timestamp'] || $low > $high || $high > $connection['utc_timestamp']) { self::fail('EXECUTION_BOUNDS_INVALID'); }
        return [$low, $high];
    }

    private static function utc(mixed $value): bool
    {
        if (!is_string($value)) { return false; }
        $date = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s.v', $value, new DateTimeZone('UTC'));
        return $date !== false && $date->format('Y-m-d H:i:s.v') === $value;
    }

    private static function connection(PDO $pdo): array
    {
        $row = self::query($pdo, 'SELECT DATABASE() AS `database`,@@server_uuid AS server_uuid,CAST(CONNECTION_ID() AS CHAR) AS connection_id,UTC_TIMESTAMP(3) AS utc_timestamp')[0];
        if (!is_string($row['database']) || $row['database'] === '' || !is_string($row['server_uuid']) || !self::utc($row['utc_timestamp'])) { self::fail('CONNECTION_OBSERVATION_INVALID'); }
        return $row;
    }

    private static function snapshot(PDO $pdo, Closure $read): array
    {
        $started = false;
        try {
            if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'mysql' || $pdo->getAttribute(PDO::ATTR_CASE) !== PDO::CASE_NATURAL || $pdo->inTransaction()) { self::fail('OWN_READONLY_SNAPSHOT_REQUIRED'); }
            $pdo->exec('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ, READ ONLY');
            $pdo->beginTransaction(); $started = true;
            $result = $read();
            $pdo->rollBack(); $started = false;
            return $result;
        } catch (Throwable $error) {
            if ($started && $pdo->inTransaction()) {
                try { $pdo->rollBack(); }
                catch (Throwable $close) { throw new RuntimeException('IDENTITY_PERMISSION_MIGRATION_SNAPSHOT_CLOSE_FAILED', 0, $close); }
            }
            if ($error instanceof RuntimeException && str_starts_with($error->getMessage(), 'IDENTITY_PERMISSION_MIGRATION_')) { throw $error; }
            throw new RuntimeException('IDENTITY_PERMISSION_MIGRATION_OBSERVATION_FAILED', 0, $error);
        }
    }

    private static function query(PDO $pdo, string $sql): array
    {
        $statement = $pdo->query($sql);
        try { return $statement->fetchAll(PDO::FETCH_ASSOC); } finally { $statement->closeCursor(); }
    }
    private static function count(PDO $pdo, string $sql): int
    {
        $statement = $pdo->query($sql);
        try { return (int)$statement->fetchColumn(); } finally { $statement->closeCursor(); }
    }
    private static function zero(PDO $pdo, string $sql): void { if (self::count($pdo, $sql) !== 0) { self::fail('BUSINESS_POSTSTATE'); } }
    private static function id(mixed $value): string
    {
        if ((!is_int($value) && !is_string($value)) || preg_match('/^(0|[1-9][0-9]*)$/D', (string)$value) !== 1) { self::fail('IDENTITY_INVALID'); }
        return (string)$value;
    }
    private static function ids(array $ids): string { return $ids === [] ? 'SELECT NULL WHERE FALSE' : implode(',', array_map(self::id(...), $ids)); }
    private static function quote(string $value): string { return '`' . str_replace('`','``',$value) . '`'; }
    private static function fail(string $reason): never { throw new RuntimeException('IDENTITY_PERMISSION_MIGRATION_' . $reason); }
}
