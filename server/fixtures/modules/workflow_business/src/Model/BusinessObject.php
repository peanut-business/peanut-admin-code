<?php

declare(strict_types=1);

namespace PeanutAdmin\Fixtures\WorkflowBusiness\Model;

final class BusinessObject extends \PeanutAdmin\Kernel\Persistence\Model\TenantModel
{
    protected $name = 'fixture_workflow_object';
    protected $type = ['id' => 'integer', 'tenant_id' => 'integer', 'owner_member_id' => 'integer', 'department_id' => 'integer', 'revision' => 'integer', 'amount_minor' => 'integer', 'quantity' => 'integer', 'completion_ready' => 'boolean'];
}
