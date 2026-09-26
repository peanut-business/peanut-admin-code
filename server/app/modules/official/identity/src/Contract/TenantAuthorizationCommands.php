<?php

declare(strict_types=1);

namespace PeanutAdmin\Modules\Identity\Contract;

use PeanutAdmin\Modules\Identity\Persistence\Model\Permission;
use PeanutAdmin\Modules\Identity\Persistence\Model\RolePermission;
use think\db\Raw;

/** Trusted Tenant bootstrap commands; normal role changes use RoleAdminService. */
final class TenantAuthorizationCommands
{
    public function grantActiveModulePermissions(
        int $tenantId,
        int $ownerMemberId,
        int $ownerRoleId,
        string $moduleKey,
    ): void {
        if (min($tenantId, $ownerMemberId, $ownerRoleId) < 1 || trim($moduleKey) === '') {
            throw new \DomainException('TENANT_AUTHORIZATION_BOOTSTRAP_INPUT_INVALID');
        }
        $permissionIds = array_map('intval', Permission::where('module_key', $moduleKey)
            ->where('status', 'active')->column('id'));
        $existing = $permissionIds === [] ? [] : array_map('intval', RolePermission::where('tenant_id', $tenantId)
            ->where('role_id', $ownerRoleId)
            ->whereIn('permission_id', $permissionIds)->column('permission_id'));
        $missing = array_values(array_diff($permissionIds, $existing));
        if ($missing === []) {
            return;
        }
        (new RolePermission())->saveAll(array_map(static fn(int $permissionId): array => [
            'tenant_id' => $tenantId,
            'role_id' => $ownerRoleId,
            'permission_id' => $permissionId,
            'granted_by_member_id' => $ownerMemberId,
            'granted_at' => new Raw('UTC_TIMESTAMP(3)'),
        ], $missing));
    }
}
