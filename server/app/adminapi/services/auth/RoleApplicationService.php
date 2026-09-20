<?php
declare(strict_types=1);

namespace app\adminapi\services\auth;

use app\common\http\PageResult;
use app\common\runtime\authorization\RoleAdministrationRuntime;
use app\common\support\PaginationInput;
use app\common\support\PositiveIds;
use PeanutAdmin\Kernel\Auth\TenantContext;

/** Compatibility role API backed by native pa_role and pa_role_permission. */
final class RoleApplicationService
{
    public function __construct(private readonly RoleAdministrationRuntime $runtime)
    {
    }

    public function validationRules(string $scene): array
    {
        return $scene === 'add'
            ? ['name' => 'require|length:1,120', 'menu_id' => 'array']
            : ['id' => 'require|integer|gt:0', 'name' => 'require|length:1,120', 'menu_id' => 'array'];
    }

    public function lists(TenantContext $context, array $params): PageResult
    {
        $pagination = PaginationInput::from($params);
        $result = $this->service()->list($context->tenantId, $pagination->pageRequest);
        $lists = array_map(fn(array $row): array => $this->compat($context, $row), $result['items']);
        return new PageResult($lists, $result['total'], $pagination->page, $pagination->pageSize);
    }

    public function getAll(TenantContext $context): array
    {
        return self::lists($context, ['page_size' => 100])->items;
    }

    public function detail(TenantContext $context, int $id): array
    {
        return $this->compat($context, $this->service()->get($context->tenantId, $id));
    }

    public function add(TenantContext $context, array $params): bool
    {
        $service = $this->service();
            // Core 写命令以已认证的租户上下文记录操作者、审计与授权修订。
            $role = $service->create($context, 'application.admin.' . bin2hex(random_bytes(8)),
                (string)$params['name'], (string)($params['desc'] ?? ''));
            $keys = $this->runtime->permissionKeys($context->tenantId, self::menuIds($params));
            if ($keys !== []) {
                $service->replacePermissions($context, (int)$role['id'], $keys, (int)$role['revision']);
            }
        return true;
    }

    public function edit(TenantContext $context, array $params): bool
    {
        $service = $this->service();
            $current = $service->get($context->tenantId, (int)$params['id']);
            $role = $service->update($context, (int)$params['id'], (string)$params['name'],
                (string)($params['desc'] ?? ''), (int)$current['revision']);
            if (array_key_exists('menu_id', $params) || array_key_exists('menu_ids', $params)) {
                $service->replacePermissions($context, (int)$params['id'], $this->runtime->permissionKeys($context->tenantId, self::menuIds($params)),
                    (int)$role['revision']);
            }
        return true;
    }

    public function delete(TenantContext $context, int $id): bool
    {
        $service = $this->service();
            $role = $service->get($context->tenantId, $id);
            $service->archive($context, $id, (int)$role['revision']);
        return true;
    }

    private function compat(TenantContext $context, array $role): array
    {
        $keys = $role['permission_keys'] ?? [];
        $menus = $this->runtime->menuIds($context, is_array($keys) ? $keys : []);
        return ['id' => (int)$role['id'], 'name' => $role['name'], 'desc' => $role['description'] ?? '', 'sort' => 0,
            'create_time' => '', 'num' => $this->runtime->memberCount($context->tenantId, (int)$role['id']),
            'menu_id' => $menus, 'menu_ids' => $menus, 'status' => $role['status'], 'revision' => (int)$role['revision']];
    }

    /** @return list<int> */
    private static function menuIds(array $params): array
    {
        $ids = $params['menu_id'] ?? $params['menu_ids'] ?? [];
        return PositiveIds::normalize(
            is_array($ids) ? $ids : [],
            [PositiveIds::FILTER_INVALID],
        );
    }

    private function service(): \PeanutAdmin\Kernel\Authorization\Application\RoleAdminService
    {
        return $this->runtime->service();
    }
}
