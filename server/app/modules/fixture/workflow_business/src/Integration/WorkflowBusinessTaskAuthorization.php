<?php

declare(strict_types=1);

namespace PeanutAdmin\Fixtures\WorkflowBusiness\Integration;

use PeanutAdmin\Fixtures\WorkflowBusiness\Model\TaskActorReference;
use PeanutAdmin\Fixtures\WorkflowBusiness\Package;
use PeanutAdmin\Fixtures\WorkflowBusiness\Service\WorkflowBusinessAccess;
use PeanutAdmin\Kernel\Async\VerifiedJobEnvelope;
use PeanutAdmin\Kernel\Authorization\Application\AdminAccessException;
use PeanutAdmin\Kernel\Context\AuthorizationDecision;
use PeanutAdmin\Kernel\Context\AuthorizedOperationContext;
use think\facade\Db;

final readonly class WorkflowBusinessTaskAuthorization
{
    public function __construct(private WorkflowBusinessAccess $access, private WorkflowTaskSessionRevalidator $sessions) {}

    /** Submission runs inside the same workflow transaction; tuple conflicts are never overwritten. */
    public function bind(AuthorizedOperationContext $context): void
    {
        $target = $context->targets[0] ?? null;
        $pdo = Db::connect()->getPdo();
        if (!$pdo instanceof \PDO || !$pdo->inTransaction() || count($context->targets) !== 1 || $target === null
            || $target->targetRole !== 'primary' || $target->targetResourceKey !== $context->resourceKey || count($target->targetIds) !== 1
            || $context->tenantContext->requestId === '' || strlen($context->tenantContext->requestId) > 128
            || $context->tenantContext->sessionKey === '' || strlen($context->tenantContext->sessionKey) > 128
            || $context->tenantContext->clientKey === '' || strlen($context->tenantContext->clientKey) > 128) {
            throw new AdminAccessException('WORKFLOW_TASK_SESSION_REQUIRED', 403, 'A real submitting session and outer transaction are required.');
        }
        $member = $this->access->currentMember($context);
        $scene = Package::scene($context->resourceKey);
        $operation = $scene === 'receipt' ? 'receive' : 'complete';
        $this->access->authorize($context, $context->resourceKey, $operation, [Package::MODULE_KEY . '.' . $operation], $target->targetIds[0]);
        $tenant = $context->tenantContext;
        $key = WorkflowTaskSessionRevalidator::referenceKey(
            $tenant->tenantId,
            $tenant->accountId,
            $tenant->memberId,
            $context->resourceKey,
            $context->operation,
            $target->targetIds[0],
            $tenant->requestId,
        );
        $values = ['reference_key' => $key, 'tenant_id' => $tenant->tenantId, 'account_id' => $tenant->accountId, 'member_id' => $tenant->memberId,
            'resource_key' => $context->resourceKey, 'operation' => $context->operation, 'object_key' => $target->targetIds[0],
            'request_id' => $tenant->requestId, 'session_key' => $tenant->sessionKey, 'client_key' => $tenant->clientKey,
            'account_security_revision' => $member->accountSecurityRevision, 'tenant_security_revision' => $member->tenantSecurityRevision,
            'member_security_revision' => $member->securityRevision];
        $current = TaskActorReference::where('tenant_id', $tenant->tenantId)->where('reference_key', $key)->lock(true)->find()?->toArray();
        if ($current !== null) {
            foreach ($values as $field => $value) {
                if ((string) ($current[$field] ?? '') !== (string) $value) {
                    throw AdminAccessException::conflict('WORKFLOW_TASK_SESSION_CONFLICT', 'The task actor tuple is already bound to another session or security revision.');
                }
            }
            return;
        }
        TaskActorReference::insert($values + ['created_at' => gmdate('Y-m-d H:i:s') . '.000']);
    }

    public function reauthorize(VerifiedJobEnvelope $envelope): AuthorizedOperationContext
    {
        $subject = WorkflowTaskSessionRevalidator::envelopeSubject($envelope);
        $key = WorkflowTaskSessionRevalidator::referenceKey(
            $envelope->tenantId,
            $envelope->accountId,
            $envelope->memberId,
            $envelope->resourceKey,
            $envelope->operation,
            $subject,
            $envelope->traceId,
        );
        $reference = TaskActorReference::where('tenant_id', $envelope->tenantId)->where('reference_key', $key)->find()?->toArray()
            ?? throw new AdminAccessException('WORKFLOW_TASK_SESSION_REQUIRED', 403, 'The submitting session reference is missing.');
        $tenant = $this->sessions->revalidate($envelope, $reference);
        $scene = Package::scene($envelope->resourceKey);
        $operation = $scene === 'receipt' ? 'receive' : 'complete';
        if ($envelope->operation !== $operation) {
            throw new AdminAccessException('WORKFLOW_TASK_OPERATION_INVALID', 403, 'The registered task operation must match.');
        }
        $basis = AuthorizedOperationContext::fromDecision(AuthorizationDecision::allow(
            $tenant,
            $envelope->resourceKey,
            $operation,
            $envelope->requestedTargets,
            hash('sha256', $key . ':' . $tenant->authorizationRevision),
        ));
        return $this->access->authorize($basis, $envelope->resourceKey, $operation, [Package::MODULE_KEY . '.' . $operation], $subject);
    }
}
