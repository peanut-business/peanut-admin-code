<?php
declare(strict_types=1);

namespace app\adminapi\services\auth;

use app\common\services\authorization\AdminAuthorizationService;
use app\common\infrastructure\authorization\CoreTenantModuleAdminBridge;
use app\common\services\authorization\MenuPermissionUsageQuery;
use app\common\exception\BusinessException;
use app\common\model\auth\SystemMenu;
use think\facade\Db;
use PeanutAdmin\Modules\Identity\Platform\InstanceControlPlanePolicy;
use PeanutAdmin\Kernel\Auth\TenantContext;

class MenuApplicationService
{
    public function __construct(
        private readonly AdminAuthorizationService $authorization,
        private readonly MenuPermissionUsageQuery $permissionUsage,
    ) {}

    public function getMenuByAdminId(mixed $tenantContext, int $adminId): array
    {
        return $this->authorization->menusForAdminId($tenantContext, $adminId);
    }

    public function getAll(): array
    {
        $menus = SystemMenu::whereNotIn('perms', InstanceControlPlanePolicy::tenantAdminPermissions())
            ->whereNotIn('paths', [
                '/article',
                '/article/cate',
                '/article/list',
                ...CoreTenantModuleAdminBridge::officialModuleMenuPaths(),
            ])->order(['sort' => 'desc', 'id' => 'asc'])->select()->toArray();
        return linear_to_tree($menus);
    }

    public function getAllSimple(TenantContext $context): array
    {
        $data = SystemMenu::where('is_disable', 0)
            ->whereNotIn('perms', InstanceControlPlanePolicy::tenantAdminPermissions())
            ->whereNotIn('paths', [
                '/article',
                '/article/cate',
                '/article/list',
                ...CoreTenantModuleAdminBridge::officialModuleMenuPaths(),
            ])->field(['id', 'pid', 'name'])
            ->order(['sort' => 'desc', 'id' => 'asc'])->select()->toArray();
        $moduleMenus = array_map(
            static fn(array $menu): array => [
                'id' => (int)$menu['id'],
                'pid' => 0,
                'name' => (string)$menu['name'],
                'module_key' => (string)$menu['module_key'],
                'managed' => true,
            ],
            $this->authorization->assignableMenuRecords($context)
        );
        return [...linear_to_tree($data), ...$moduleMenus];
    }

    public function detail(int $id): array
    {
        $menu = SystemMenu::where('id', $id)->findOrEmpty();
        return $menu->isEmpty() ? [] : $menu->toArray();
    }

    public function add(array $params): bool
    {
        return (bool) Db::transaction(function () use ($params): bool {
                $this->assertParent((int)($params['pid'] ?? 0));
                SystemMenu::create([
                    'pid' => $params['pid'] ?? 0, 'type' => $params['type'] ?? 'C',
                    'name' => $params['name'], 'icon' => $params['icon'] ?? '',
                    'sort' => $params['sort'] ?? 0, 'perms' => $params['perms'] ?? '',
                    'paths' => $params['paths'] ?? '', 'component' => $params['component'] ?? '',
                    'is_cache' => $params['is_cache'] ?? 0, 'is_show' => $params['is_show'] ?? 1,
                    'is_disable' => $params['is_disable'] ?? 0,
                ]);
                return true;
        });
    }

    public function edit(array $params): bool
    {
        return (bool) Db::transaction(function () use ($params): bool {
                $id = (int)$params['id'];
                if (SystemMenu::where('id', $id)->lock(true)->findOrEmpty()->isEmpty()) throw BusinessException::notFound('ADMIN_MENU_NOT_FOUND', '菜单不存在');
                $this->assertParent((int)($params['pid'] ?? 0), $id);
                SystemMenu::where('id', $id)->update([
                    'pid' => $params['pid'] ?? 0,
                    'type' => $params['type'] ?? 'C', 'name' => $params['name'],
                    'icon' => $params['icon'] ?? '', 'sort' => $params['sort'] ?? 0,
                    'perms' => $params['perms'] ?? '', 'paths' => $params['paths'] ?? '',
                    'component' => $params['component'] ?? '',
                    'is_cache' => $params['is_cache'] ?? 0, 'is_show' => $params['is_show'] ?? 1,
                    'is_disable' => $params['is_disable'] ?? 0,
                ]);
                return true;
        });
    }

    public function delete(int $id): bool
    {
        return (bool) Db::transaction(function () use ($id): bool {
                $menuModel = SystemMenu::where('id', $id)->lock(true)->findOrEmpty();
                if ($menuModel->isEmpty()) throw BusinessException::notFound('ADMIN_MENU_NOT_FOUND', '菜单不存在');
                $menu = $menuModel->toArray();
                if (SystemMenu::where('pid', $id)->count() > 0) throw BusinessException::conflict('ADMIN_MENU_HAS_CHILDREN', '已关联下级菜单，暂不可删除');
                $permission = trim((string)($menu['perms'] ?? ''));
                if ($permission !== '' && $this->permissionUsage->assigned($permission)) throw BusinessException::conflict('ADMIN_MENU_IN_USE', '菜单已被角色使用，暂不可删除');
                SystemMenu::where('id', $id)->delete();
                return true;
        });
    }

    public function updateStatus(int $id, int $isDisable): bool
    {
        return (bool) Db::transaction(function () use ($id, $isDisable): bool {
                if (SystemMenu::where('id', $id)->lock(true)->findOrEmpty()->isEmpty()) throw BusinessException::notFound('ADMIN_MENU_NOT_FOUND', '菜单不存在');
                SystemMenu::where('id', $id)->update(['is_disable' => $isDisable]);
                return true;
        });
    }

    private function assertParent(int $parentId, int $menuId = 0): void
    {
        if ($parentId === 0) {
            return;
        }
        if ($parentId === $menuId) {
            throw BusinessException::invalid('ADMIN_MENU_PARENT_INVALID', '上级菜单不可是当前菜单');
        }

        $parents = SystemMenu::lock(true)->column(['id', 'pid', 'type'], 'id');
        $visited = [];
        while ($parentId > 0) {
            if ($parentId === $menuId) {
                throw BusinessException::invalid('ADMIN_MENU_PARENT_INVALID', '上级菜单不可是当前菜单或其下级菜单');
            }
            if (isset($visited[$parentId])) {
                throw BusinessException::conflict('ADMIN_MENU_HIERARCHY_INVALID', '菜单层级关系异常');
            }
            $visited[$parentId] = true;

            $parent = $parents[$parentId] ?? null;
            if (!is_array($parent)) {
                throw BusinessException::notFound('ADMIN_MENU_PARENT_NOT_FOUND', '上级菜单不存在');
            }
            if ((string)$parent['type'] === 'A') {
                throw BusinessException::invalid('ADMIN_MENU_PARENT_INVALID', '按钮不可作为上级菜单');
            }
            $parentId = (int)$parent['pid'];
        }
    }

}
