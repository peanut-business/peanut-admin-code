<?php

declare(strict_types=1);

namespace PeanutAdmin\Fixtures\WorkflowBusiness\Integration;

use PeanutAdmin\Fixtures\WorkflowBusiness\Package;
use PeanutAdmin\Kernel\Async\VerifiedJobEnvelope;
use PeanutAdmin\Kernel\Context\AuthorizedOperationContext;
use PeanutAdmin\Modules\Task\Contract\TaskWorkerDefinition;
use PeanutAdmin\Modules\Workflow\Application\WorkflowCommands;

abstract readonly class WorkflowBusinessWorkerDefinition implements TaskWorkerDefinition
{
    abstract protected function scene(): string;

    public function __construct(private \think\App $app, private WorkflowBusinessTaskAuthorization $authorization) {}

    public function ownerModuleKey(): string
    {
        return Package::MODULE_KEY;
    }

    public function resourceKey(): string
    {
        return Package::resource($this->scene());
    }

    public function operation(): string
    {
        return $this->scene() === 'receipt' ? 'receive' : 'complete';
    }

    public function handlers(): array
    {
        return [new WorkflowBusinessTaskHandler($this->scene(), $this->app)];
    }

    public function reauthorize(VerifiedJobEnvelope $envelope): AuthorizedOperationContext
    {
        if ($envelope->resourceKey !== $this->resourceKey() || $envelope->operation !== $this->operation()) {
            throw new \InvalidArgumentException('WORKFLOW_BUSINESS_TASK_SCENE_INVALID');
        }
        return $this->authorization->reauthorize($envelope);
    }
}
