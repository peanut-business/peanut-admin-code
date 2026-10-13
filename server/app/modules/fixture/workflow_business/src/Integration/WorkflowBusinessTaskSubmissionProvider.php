<?php

declare(strict_types=1);

namespace PeanutAdmin\Fixtures\WorkflowBusiness\Integration;

use PeanutAdmin\Fixtures\WorkflowBusiness\Package;
use PeanutAdmin\Kernel\Context\AuthorizedOperationContext;
use PeanutAdmin\Modules\Task\Contract\TaskSubmission;
use PeanutAdmin\Modules\Task\Contract\TaskSubmissionProvider;

/** One trusted descriptor per scene; common Workflow handler executes the owned action. */
final readonly class WorkflowBusinessTaskSubmissionProvider implements TaskSubmissionProvider
{
    public function __construct(private string $scene, private WorkflowBusinessTaskAuthorization $authorization)
    {
        Package::resource($scene);
    }

    public function taskType(): string
    {
        return Package::action($this->scene);
    }

    public function resourceKey(): string
    {
        return Package::resource($this->scene);
    }

    public function operation(): string
    {
        return $this->scene === 'receipt' ? 'receive' : 'complete';
    }

    public function build(AuthorizedOperationContext $context, array $input): TaskSubmission
    {
        if ($context->resourceKey !== $this->resourceKey() || $context->operation !== $this->operation()
            || array_diff(array_keys($input), ['instance_key', 'node_execution_key', 'action_key', 'contract_version', 'input', 'effect_key']) !== []
            || ($input['action_key'] ?? null) !== $this->taskType() || ($input['contract_version'] ?? null) !== 1
            || !is_array($input['input'] ?? null) || !is_string($input['effect_key'] ?? null)
            || !is_string($input['instance_key'] ?? null) || !is_string($input['node_execution_key'] ?? null)) {
            throw new \InvalidArgumentException('WORKFLOW_BUSINESS_TASK_CONTEXT_INVALID');
        }
        $this->authorization->bind($context);
        return new TaskSubmission($this->taskType(), $input, 3);
    }
}
