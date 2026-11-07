<?php

declare(strict_types=1);

namespace PeanutAdmin\Fixtures\WorkflowBusiness\Integration;

use PeanutAdmin\Fixtures\WorkflowBusiness\Package;
use PeanutAdmin\Fixtures\WorkflowBusiness\Service\WorkflowBusinessAccess;
use PeanutAdmin\Fixtures\WorkflowBusiness\Service\WorkflowBusinessService;
use PeanutAdmin\Kernel\Context\AuthorizedOperationContext;
use PeanutAdmin\Modules\Workflow\Adapter\WorkflowScenarioProvider;
use PeanutAdmin\Modules\Workflow\Application\WorkflowException;

final readonly class WorkflowBusinessScenarioProvider implements WorkflowScenarioProvider
{
    public function __construct(private WorkflowBusinessService $business, private WorkflowBusinessAccess $access) {}

    public function scenario(AuthorizedOperationContext $context, string $moduleKey, string $workflowKey): array
    {
        if ($moduleKey !== Package::MODULE_KEY || !in_array($workflowKey, Package::SCENES, true)) {
            throw WorkflowException::definitionInvalid('The business scenario is not registered.');
        }
        $this->access->requirePermission($context, Package::MODULE_KEY . '.read');
        $operation = $workflowKey === 'receipt' ? 'receive' : 'complete';
        return [
            'contract_version' => 1,
            'fields' => [
                'title' => ['type' => 'string', 'nullable' => false],
                'amount_minor' => ['type' => 'integer', 'nullable' => false],
                'amount_decimal' => ['type' => 'decimal', 'nullable' => false],
                'created_at_utc' => ['type' => 'datetime', 'nullable' => false],
                'business_department_key' => ['type' => 'string', 'nullable' => true],
                'sku' => ['type' => 'string', 'nullable' => false],
                'quantity' => ['type' => 'integer', 'nullable' => false],
                'scene' => ['type' => 'enum', 'nullable' => false, 'values' => Package::SCENES],
                'form_saved' => ['type' => 'boolean', 'nullable' => true],
                'result' => ['type' => 'enum', 'nullable' => true, 'values' => ['approved', 'rejected', 'completed']],
            ],
            'result_codes' => ['approved', 'rejected', 'completed'],
            'capabilities' => ['workflow.approval', 'workflow.human_task', 'workflow.direct', 'workflow.claim', 'workflow.all_approve', 'workflow.any_approve', 'workflow.exclusive_gateway', 'workflow.service_task', 'workflow.return', 'workflow.notification'],
            'notification' => ['producer_key' => Package::resource($workflowKey) . '.notification'],
            'actions' => [Package::action($workflowKey) => [
                'contract_version' => 1, 'operation' => $operation,
                'permission_keys' => [Package::MODULE_KEY . '.' . $operation], 'task_type' => Package::action($workflowKey),
                'input_schema' => ['type' => 'object', 'additionalProperties' => false,
                    'required' => ['subject_key', 'revision_key', 'result'],
                    'properties' => ['subject_key' => ['type' => 'string', 'pattern' => '^object_[0-9a-f]{32}$'],
                        'revision_key' => ['type' => 'string', 'pattern' => '^revision_[0-9a-f]{32}$'],
                        'result' => ['type' => 'string', 'enum' => $workflowKey === 'receipt' ? ['completed'] : ['approved', 'rejected']]]],
            ]],
        ];
    }

    public function businessDepartment(AuthorizedOperationContext $context, ?string $requestedKey): ?string
    {
        return $this->business->businessDepartment($context, $requestedKey);
    }

    public function subjectOwner(AuthorizedOperationContext $context, string $subjectType, string $subjectKey): ?int
    {
        return $this->business->subjectOwner($context, $subjectType, $subjectKey);
    }

    public function prepareSubjectSnapshot(AuthorizedOperationContext $context, string $subjectType, string $subjectKey, string $revisionKey): object
    {
        $snapshot = $this->business->snapshot($context, $subjectType, $subjectKey, $revisionKey, false);
        return new PreparedWorkflowBusinessSnapshot($context->tenantContext->tenantId, $subjectType, $subjectKey, $revisionKey, $snapshot['sha256']);
    }

    public function snapshot(AuthorizedOperationContext $context, string $subjectType, string $subjectKey, string $revisionKey, bool $forUpdate, object $prepared): array
    {
        if (!$prepared instanceof PreparedWorkflowBusinessSnapshot || $prepared->tenantId !== $context->tenantContext->tenantId
            || $prepared->resourceKey !== $subjectType || $prepared->subjectKey !== $subjectKey || $prepared->revisionKey !== $revisionKey) {
            throw WorkflowException::subjectRevisionConflict();
        }
        $snapshot = $this->business->snapshot($context, $subjectType, $subjectKey, $revisionKey, $forUpdate);
        if (!hash_equals($prepared->sha256, $snapshot['sha256'])) { throw WorkflowException::subjectRevisionConflict(); }
        return $snapshot;
    }

    public function validateCompletion(AuthorizedOperationContext $context, array $instance, array $input): array
    {
        return $this->business->validateCompletion($context, $instance, $input);
    }

    public function executeAction(AuthorizedOperationContext $context, string $actionKey, int $contractVersion, array $input, string $effectKey): array
    {
        $target = $context->targets[0] ?? null;
        if ($target === null || count($context->targets) !== 1 || $target->targetResourceKey !== $context->resourceKey
            || $target->targetRole !== 'primary' || $target->targetIds !== [$input['subject_key'] ?? '']) {
            throw WorkflowException::assignmentDenied();
        }
        return $this->business->executeAction($context, $actionKey, $contractVersion, $input, $effectKey);
    }
}
