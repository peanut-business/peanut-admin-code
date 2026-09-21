<?php

declare(strict_types=1);

namespace app\modules\official\task\job\Persistence\Model;

use PeanutAdmin\Kernel\Persistence\Model\TenantModel;

final class TaskJobEventRecord extends TenantModel
{
    /** @var string */ protected $name = 'task_job_event';
    /** @var array<string, string> */ protected $type = [
        'id' => 'integer', 'tenant_id' => 'integer', 'job_id' => 'integer', 'actor_member_id' => 'integer',
    ];
}
