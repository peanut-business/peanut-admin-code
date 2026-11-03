<?php

declare(strict_types=1);

namespace PeanutAdmin\Fixtures\WorkflowBusiness\Model;

/** Private durable reference to the real submitting session; never projected to HTTP. */
final class TaskActorReference extends \PeanutAdmin\Kernel\Persistence\Model\TenantModel
{
    protected $name = 'fixture_workflow_task_actor';
}
