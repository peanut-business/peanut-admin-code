<?php

declare(strict_types=1);

namespace PeanutAdmin\Fixtures\WorkflowBusiness\Integration;

use PeanutAdmin\Fixtures\WorkflowBusiness\Package;
use PeanutAdmin\Kernel\Context\AuthorizedOperationContext;
use PeanutAdmin\Modules\Task\Contract\JobExecution;
use PeanutAdmin\Modules\Task\Contract\TaskHandler;
use PeanutAdmin\Modules\Workflow\Application\WorkflowCommands;

/** Unique scene key; all execution, fencing, retries and transitions remain in Workflow. */
final readonly class WorkflowBusinessTaskHandler implements TaskHandler
{
    public function __construct(private string $scene, private \think\App $app) {}

    public function key(): string
    {
        return Package::action($this->scene);
    }

    public function handle(AuthorizedOperationContext $context, JobExecution $execution): void
    {
        if ($context->resourceKey !== Package::resource($this->scene)) {
            throw new \InvalidArgumentException('WORKFLOW_BUSINESS_TASK_SCENE_INVALID');
        }
        $this->app->make(WorkflowCommands::class)->executeServiceTask($context, $execution);
    }
}
