<?php

declare(strict_types=1);

namespace PeanutAdmin\Modules\Identity\Persistence\Model;

final class RolePermission extends \PeanutAdmin\Kernel\Persistence\Model\TenantModel
{
    /** @var string */ protected $name = 'role_permission';
    /** @var array<string, string> */ protected $type = [
        'id' => 'integer',
        'tenant_id' => 'integer',
        'role_id' => 'integer',
        'permission_id' => 'integer',
    ];

    /** @var array<string, string> Keep aligned with the Identity migrations. */
    protected $schema = [
        'id' => 'bigint',
        'tenant_id' => 'bigint',
        'role_id' => 'bigint',
        'permission_id' => 'bigint',
        'granted_by_member_id' => 'bigint',
        'granted_at' => 'datetime',
    ];
}
