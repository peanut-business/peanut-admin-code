<?php

declare(strict_types=1);

namespace PeanutAdmin\Modules\Identity\Persistence\Model;

final class TenantMember extends \PeanutAdmin\Kernel\Persistence\Model\TenantModel
{
    /** @var string */ protected $name = 'tenant_member';

    /** @var array<string, string> */
    protected $type = [
        'id' => 'integer',
        'tenant_id' => 'integer',
        'account_id' => 'integer',
    ];

    /** @var array<string, string> Keep aligned with the Identity migrations. */
    protected $schema = [
        'id' => 'bigint',
        'tenant_id' => 'bigint',
        'account_id' => 'bigint',
        'member_no' => 'string',
        'display_name' => 'string',
        'member_type' => 'string',
        'primary_department_id' => 'bigint',
        'status' => 'string',
        'security_revision' => 'bigint',
        'authorization_revision' => 'bigint',
        'joined_at' => 'datetime',
        'suspended_at' => 'datetime',
        'left_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];
}
