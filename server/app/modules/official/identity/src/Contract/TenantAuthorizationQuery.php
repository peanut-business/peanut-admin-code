<?php

declare(strict_types=1);

namespace PeanutAdmin\Modules\Identity\Contract;

use PeanutAdmin\Kernel\Auth\TenantContext;
use PeanutAdmin\Modules\Identity\Persistence\Model\MemberRole;
use PeanutAdmin\Modules\Identity\Persistence\Model\Permission;
use PeanutAdmin\Modules\Identity\Persistence\Model\RolePermission;
use PeanutAdmin\Modules\Identity\Persistence\Model\Tenant;

/** 公开授权投影；人员权限查询逐次复核原生会话与修订，不缓存跨请求身份。 */
final readonly class TenantAuthorizationQuery
{
    public function __construct(private TenantMemberDirectory $members) {}

    /** @param list<string> $moduleKeys @return list<string> */
    public function registeredPermissionKeys(array $moduleKeys): array
    {
        return array_values(array_map('strval', Permission::where('status', 'active')
            ->whereIn('module_key', array_values(array_unique($moduleKeys)))
            ->distinct(true)->order('key')->column('key')));
    }

    /**
     * 只读取调用方已选菜单引用的定义元数据；不存在与停用必须区分，不授予人员权限。
     * 以有界批次查询，返回缺失项为空缺，存储失败原样传播。
     * @param list<string> $permissionKeys
     * @return array<string,array{module_key:string|null,status:string}>
     */
    public function permissionStates(array $permissionKeys): array
    {
        foreach ($permissionKeys as $key) {
            if (!is_string($key) || $key === '') {
                throw new \InvalidArgumentException('PERMISSION_KEY_INVALID');
            }
        }
        $states = [];
        foreach (array_chunk(array_values(array_unique($permissionKeys)), 500) as $keys) {
            $rows = Permission::whereIn('key', $keys)->field('key,module_key,status')->order('key')->select()->toArray();
            foreach ($rows as $row) {
                $states[(string) $row['key']] = [
                    'module_key' => $row['module_key'] === null ? null : (string) $row['module_key'],
                    'status' => (string) $row['status'],
                ];
            }
        }
        ksort($states, SORT_STRING);
        return $states;
    }

    /** @return list<string> */
    public function applicationPermissionKeys(TenantContext $context): array
    {
        if ($this->members->current($context) === null) {
            return [];
        }
        return array_values(array_map('strval', Tenant::alias('tenant')
            ->join('tenant_member member', "member.tenant_id=tenant.id AND member.status='active'")
            ->join('member_role membership', 'membership.tenant_id=tenant.id AND membership.tenant_member_id=member.id')
            ->join('role role', "role.tenant_id=tenant.id AND role.id=membership.role_id AND role.status='active'")
            ->join('role_permission binding', 'binding.tenant_id=tenant.id AND binding.role_id=role.id')
            ->join('permission permission', "permission.id=binding.permission_id AND permission.module_key='peanut.admin' AND permission.status='active'")
            ->where('tenant.id', $context->tenantId)->where('tenant.status', 'active')->where('member.id', $context->memberId)
            ->distinct(true)->order('permission.key')->column('permission.key')));
    }

    public function isTenantOwner(TenantContext $context): bool
    {
        if ($this->members->current($context) === null) {
            return false;
        }
        return MemberRole::alias('membership')
            ->join('role role', "role.tenant_id=membership.tenant_id AND role.id=membership.role_id AND role.`key`='core.tenant-owner' AND role.is_builtin=1 AND role.status='active'")
            ->where('membership.tenant_id', $context->tenantId)
            ->where('membership.tenant_member_id', $context->memberId)
            ->value('membership.tenant_member_id') !== null;
    }

    /** @return list<array{id:int,key:string,name:string,is_builtin:bool}> */
    public function roles(int $tenantId, int $memberId): array
    {
        $rows = MemberRole::alias('membership')
            ->join('role role', "role.tenant_id=membership.tenant_id AND role.id=membership.role_id AND role.status='active'")
            ->where('membership.tenant_id', $tenantId)
            ->where('membership.tenant_member_id', $memberId)
            ->field('role.id,role.key,role.name,role.is_builtin')
            ->order('role.key')->order('role.id')->select()->toArray();

        return array_map(static fn(array $row): array => [
            'id' => (int) $row['id'],
            'key' => (string) $row['key'],
            'name' => (string) $row['name'],
            'is_builtin' => (int) $row['is_builtin'],
        ], $rows);
    }

    public function roleMemberCount(int $tenantId, int $roleId): int
    {
        return MemberRole::where('tenant_id', $tenantId)->where('role_id', $roleId)->count();
    }

    public function permissionAssigned(string $permission): bool
    {
        return RolePermission::alias('role_permission')
            ->join('permission permission', 'permission.id = role_permission.permission_id')
            ->where('permission.key', $permission)->count() > 0;
    }
}
