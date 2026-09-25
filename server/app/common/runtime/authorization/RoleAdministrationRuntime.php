<?php

declare(strict_types=1);

namespace app\common\runtime\authorization;

use app\common\services\authorization\AdminAuthorizationService;
use app\common\model\auth\SystemMenu;
use PeanutAdmin\Kernel\Auth\TenantContext;
use PeanutAdmin\Modules\Identity\Authorization\Application\RoleAdminService;
use PeanutAdmin\Modules\Identity\Persistence\Model\MemberRole;

/** Container-owned assembly and read projections for native Tenant roles. */
final readonly class RoleAdministrationRuntime
{
    public function __construct(
        private RoleAdminService $roles,
        private AdminAuthorizationService $authorization,
    ) {}

    public function service(): RoleAdminService
    {
        return $this->roles;
    }

    /** @param list<string> $permissionKeys @return list<int> */
    public function menuIds(TenantContext $context, array $permissionKeys): array
    {
        if ($permissionKeys === []) {
            return [];
        }
        $ids = array_map('intval', SystemMenu::where('is_disable', 0)
            ->whereIn('perms', $permissionKeys)->order('id')->column('id'));
        foreach ($this->authorization->assignableMenuRecords($context) as $menu) {
            if (in_array((string) $menu['required_permission'], $permissionKeys, true)) {
                $ids[] = (int) $menu['id'];
            }
        }
        return array_values(array_unique($ids));
    }

    public function memberCount(int $tenantId, int $roleId): int
    {
        return MemberRole::where('tenant_id', $tenantId)->where('role_id', $roleId)->count();
    }

    /** @param list<int> $menuIds @return list<string> */
    public function permissionKeys(int $tenantId, array $menuIds): array
    {
        if ($menuIds === []) {
            return [];
        }
        $keys = array_values(array_unique(array_map('strval', SystemMenu::alias('menu')
            ->join('permission permission', 'permission.`key`=menu.perms')
            ->where('menu.is_disable', 0)
            ->whereIn('menu.id', $menuIds)
            ->where('menu.perms', '<>', '')
            ->where('permission.status', 'active')
            ->order('permission.key')
            ->column('permission.key'))));
        $selected = array_fill_keys($menuIds, true);
        foreach ($this->authorization->assignableMenuRecordsForTenant($tenantId) as $menu) {
            if (isset($selected[(int) $menu['id']]) && trim((string) $menu['required_permission']) !== '') {
                $keys[] = (string) $menu['required_permission'];
            }
        }
        return array_values(array_unique($keys));
    }
}
