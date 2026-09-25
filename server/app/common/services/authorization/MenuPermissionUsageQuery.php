<?php

declare(strict_types=1);

namespace app\common\services\authorization;

use PeanutAdmin\Modules\Identity\Persistence\Model\RolePermission;

/** Read boundary for checking whether a menu permission is assigned to a role. */
final class MenuPermissionUsageQuery
{
    public function assigned(string $permission): bool
    {
        return RolePermission::alias('role_permission')
            ->join('permission permission', 'permission.id = role_permission.permission_id')
            ->where('permission.key', $permission)->count() > 0;
    }
}
