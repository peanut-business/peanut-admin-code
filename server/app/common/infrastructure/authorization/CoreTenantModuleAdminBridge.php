<?php
declare(strict_types=1);

namespace app\common\infrastructure\authorization;

use app\common\model\auth\SystemMenu;
use app\platform\infrastructure\module\ThinkPhpModuleGovernanceProvider;
use PeanutAdmin\Kernel\Auth\TenantContext;
use PeanutAdmin\Kernel\Authorization\TenantAuthorizationRepository;
use PeanutAdmin\Kernel\Menu\MenuDefinition;
use PeanutAdmin\Kernel\Menu\MenuCatalogRepository;
use PeanutAdmin\Kernel\Menu\MenuRegistry;
use PeanutAdmin\Modules\Identity\Persistence\Model\MemberRole;
use PeanutAdmin\Modules\Identity\Persistence\Model\Permission;
use PeanutAdmin\Modules\Identity\Persistence\Model\Tenant;

/**
 * Adapts the Core Module/TenantModule catalog to the Admin Shell menu payload.
 *
 * The bridge is read-only: Plugin installation owns deployment state, the platform
 * owns TenantModule enablement, and Core RBAC owns member Permission grants.
 */
final readonly class CoreTenantModuleAdminBridge
{
    private const APPLICATION_PERMISSION_OWNER = 'peanut.admin';

    /** @return list<string> */
    public static function officialModuleMenuPaths(): array
    {
        return [
            '/system/file',
            '/system/crontab',
            '/notice/channel',
            '/notice/template',
            '/notice/log',
            '/app-setting/channel',
            '/app-setting/pay',
            '/member/list',
            '/member/tag',
            '/finance/account-log',
            '/finance/recharge',
            '/finance/refund',
        ];
    }

    public function __construct(
        private ThinkPhpModuleGovernanceProvider $moduleGovernance,
        private TenantAuthorizationRepository $authorization,
        private MenuCatalogRepository $menuCatalog,
    ) {
    }

    /** @return array{menu:list<array<string,mixed>>,permissions:list<string>} */
    public function accessData(mixed $tenantContext): array
    {
        if (!$tenantContext instanceof TenantContext
            || $tenantContext->tenantId < 1
            || $tenantContext->memberId < 1) {
            return ['menu' => [], 'permissions' => []];
        }

        $permissions = array_values(array_unique([
            ...$this->authorization->permissions(
                $tenantContext->tenantId,
                $tenantContext->memberId
            )->keys(),
            ...$this->applicationPermissions($tenantContext),
        ]));
        if ($this->isTenantOwner($tenantContext)) {
            $permissions = array_values(array_unique([
                ...$permissions,
                ...$this->registeredPermissions($tenantContext->tenantId),
            ]));
        }
        $definitions = $this->menuCatalog->activeDefinitions('tenant');
        $qualification = $this->moduleGovernance->qualification();
        $deploymentModules = array_map(
            static fn($module): string => $module->moduleKey,
            $qualification->installedModules()
        );
        $tenantModules = $qualification->activeTenantModuleKeys($tenantContext->tenantId);
        $visible = (new MenuRegistry($definitions))->visible(
            'admin-web',
            static fn(string $moduleKey): bool => in_array($moduleKey, $deploymentModules, true),
            static fn(string $moduleKey): bool => in_array($moduleKey, $tenantModules, true),
            static fn(string $permission): bool => in_array($permission, $permissions, true)
        );

        return [
            'menu' => $this->serverMenuRecords($visible),
            'permissions' => array_values(array_unique($permissions)),
        ];
    }

    /** @return list<array<string,mixed>> */
    public function assignableMenuRecords(int $tenantId): array
    {
        if ($tenantId < 1) {
            return [];
        }
        $qualification = $this->moduleGovernance->qualification();
        $deploymentModules = array_map(
            static fn($module): string => $module->moduleKey,
            $qualification->installedModules()
        );
        $tenantModules = $qualification->activeTenantModuleKeys($tenantId);
        $visible = (new MenuRegistry($this->menuCatalog->activeDefinitions('tenant')))->visible(
            'admin-web',
            static fn(string $moduleKey): bool => in_array($moduleKey, $deploymentModules, true),
            static fn(string $moduleKey): bool => in_array($moduleKey, $tenantModules, true),
            static fn(string $_permission): bool => true
        );

        return $this->serverMenuRecords($visible);
    }

    /** @return list<string> */
    public function registeredPermissions(int $tenantId): array
    {
        if ($tenantId < 1) {
            return [];
        }
        $qualification = $this->moduleGovernance->qualification();
        $installed = array_fill_keys(array_map(
            static fn($module): string => $module->moduleKey,
            $qualification->installedModules()
        ), true);
        $active = array_values(array_unique([
            self::APPLICATION_PERMISSION_OWNER,
            ...array_filter(
                $qualification->activeTenantModuleKeys($tenantId),
                static fn(string $moduleKey): bool => isset($installed[$moduleKey])
            ),
        ]));
        return array_values(array_map('strval', Permission::where('status', 'active')
            ->whereIn('module_key', $active)->distinct(true)->order('key')->column('key')));
    }

    /** @return list<string> */
    public function registeredSystemMenuPermissions(int $tenantId): array
    {
        if ($tenantId < 1) {
            return [];
        }
        $qualification = $this->moduleGovernance->qualification();
        $installed = array_fill_keys(array_map(
            static fn($module): string => $module->moduleKey,
            $qualification->installedModules()
        ), true);
        $active = array_fill_keys(array_values(array_unique([
            self::APPLICATION_PERMISSION_OWNER,
            ...array_filter(
                $qualification->activeTenantModuleKeys($tenantId),
                static fn(string $moduleKey): bool => isset($installed[$moduleKey])
            ),
        ])), true);
        $permissions = [];
        $rows = SystemMenu::alias('menu')
            ->join('permission permission', 'permission.`key`=menu.perms', 'LEFT')
            ->where('menu.is_disable', 0)
            ->where('menu.perms', '<>', '')
            ->field(['menu.perms', 'permission.module_key', 'permission.status' => 'permission_status'])
            ->distinct(true)->select()->toArray();
        foreach ($rows as $row) {
            $moduleKey = $row['module_key'] ?? null;
            if ($moduleKey !== null && $moduleKey !== '') {
                if (($row['permission_status'] ?? null) !== 'active' || !isset($active[$moduleKey])) {
                    continue;
                }
            }
            $permissions[] = (string)$row['perms'];
        }

        return array_values(array_unique($permissions));
    }

    /** @return list<string> */
    private function applicationPermissions(TenantContext $context): array
    {
        return array_values(array_map('strval', Tenant::alias('tenant')
            ->join('tenant_member member', "member.tenant_id=tenant.id AND member.status='active'")
            ->join('member_role membership', 'membership.tenant_id=tenant.id AND membership.tenant_member_id=member.id')
            ->join('role role', "role.tenant_id=tenant.id AND role.id=membership.role_id AND role.status='active'")
            ->join('role_permission binding', 'binding.tenant_id=tenant.id AND binding.role_id=role.id')
            ->join('permission permission', "permission.id=binding.permission_id AND permission.module_key='peanut.admin' AND permission.status='active'")
            ->where('tenant.id', $context->tenantId)->where('tenant.status', 'active')->where('member.id', $context->memberId)
            ->distinct(true)->order('permission.key')->column('permission.key')));
    }

    /**
     * @param list<MenuDefinition> $definitions
     * @return list<array<string,mixed>>
     */
    private function serverMenuRecords(array $definitions): array
    {
        $ids = [];
        foreach ($definitions as $definition) {
            $ids[$definition->key] = self::virtualMenuId($definition->key);
        }

        $records = [];
        foreach ($definitions as $definition) {
            // Admin Shell groups require a concrete client route. Core groups do
            // not, so page/link records are flattened for this adapter.
            if ($definition->type === 'group' || $definition->routePath === null) {
                continue;
            }
            $records[] = [
                'id' => $ids[$definition->key],
                'pid' => 0,
                'type' => 'C',
                'name' => $definition->name,
                'icon' => $definition->icon ?? '',
                'sort' => $definition->sortOrder,
                'perms' => $definition->requiredPermission ?? '',
                'paths' => $definition->routePath,
                'component' => $definition->componentKey ?? '',
                'is_cache' => 0,
                'is_show' => 1,
                'is_disable' => 0,
                'module_key' => $definition->moduleKey,
                'required_permission' => $definition->requiredPermission,
                'children' => [],
            ];
        }

        return $records;
    }

    public static function virtualMenuId(string $menuKey): int
    {
        return 2_000_000_000 + (int)sprintf('%u', crc32($menuKey)) % 100_000_000;
    }

    private function isTenantOwner(TenantContext $context): bool
    {
        return MemberRole::alias('membership')
            ->join('role role', "role.tenant_id=membership.tenant_id AND role.id=membership.role_id AND role.`key`='core.tenant-owner' AND role.is_builtin=1 AND role.status='active'")
            ->where('membership.tenant_id', $context->tenantId)
            ->where('membership.tenant_member_id', $context->memberId)
            ->value('membership.tenant_member_id') !== null;
    }
}
