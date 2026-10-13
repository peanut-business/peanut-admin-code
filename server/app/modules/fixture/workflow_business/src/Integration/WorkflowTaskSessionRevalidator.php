<?php

declare(strict_types=1);

namespace PeanutAdmin\Fixtures\WorkflowBusiness\Integration;

use PeanutAdmin\Fixtures\WorkflowBusiness\Package;
use PeanutAdmin\Kernel\Async\VerifiedJobEnvelope;
use PeanutAdmin\Kernel\Auth\TenantContext;
use PeanutAdmin\Kernel\Authorization\Application\AdminAccessException;
use PeanutAdmin\Modules\Identity\Contract\TenantMemberDirectory;
use PeanutAdmin\Modules\Identity\Contract\TenantSessionAccess;

/** Uses the original native session; no synthesized worker session or identity. */
final readonly class WorkflowTaskSessionRevalidator
{
    public function __construct(private TenantSessionAccess $sessions, private TenantMemberDirectory $members) {}

    public static function referenceKey(int $tenantId, int $accountId, int $memberId, string $resource, string $operation, string $subject, string $requestId): string
    {
        return hash('sha256', json_encode([$tenantId, $accountId, $memberId, $resource, $operation, $subject, $requestId], JSON_THROW_ON_ERROR));
    }

    public static function envelopeSubject(VerifiedJobEnvelope $envelope): string
    {
        $target = $envelope->requestedTargets[0] ?? null;
        Package::scene($envelope->resourceKey);
        if ($envelope->tenantId < 1 || $envelope->accountId < 1 || $envelope->memberId < 1 || $envelope->traceId === ''
            || count($envelope->requestedTargets) !== 1 || $target === null || $target->targetRole !== 'primary'
            || $target->targetResourceKey !== $envelope->resourceKey || count($target->targetIds) !== 1
            || preg_match('/^object_[0-9a-f]{32}$/D', $target->targetIds[0]) !== 1) {
            throw new AdminAccessException('WORKFLOW_TASK_ACTOR_INVALID', 403, 'The trusted job actor and object must match.');
        }
        return $target->targetIds[0];
    }

    public function revalidate(VerifiedJobEnvelope $envelope, array $reference): TenantContext
    {
        $subject = self::envelopeSubject($envelope);
        $key = self::referenceKey(
            $envelope->tenantId,
            $envelope->accountId,
            $envelope->memberId,
            $envelope->resourceKey,
            $envelope->operation,
            $subject,
            $envelope->traceId,
        );
        if (($reference['reference_key'] ?? null) !== $key || (int) ($reference['tenant_id'] ?? 0) !== $envelope->tenantId
            || (int) ($reference['account_id'] ?? 0) !== $envelope->accountId || (int) ($reference['member_id'] ?? 0) !== $envelope->memberId
            || ($reference['resource_key'] ?? null) !== $envelope->resourceKey || ($reference['operation'] ?? null) !== $envelope->operation
            || ($reference['object_key'] ?? null) !== $subject || ($reference['request_id'] ?? null) !== $envelope->traceId
            || !is_string($reference['session_key'] ?? null) || $reference['session_key'] === ''
            || !is_string($reference['client_key'] ?? null) || $reference['client_key'] === '') {
            throw new AdminAccessException('WORKFLOW_TASK_ACTOR_INVALID', 403, 'The submitting session reference is missing or mismatched.');
        }
        // C1 is the Identity-owned authoritative public session revalidation capability.
        $context = $this->sessions->revalidateSession(
            $envelope->tenantId,
            $envelope->accountId,
            $envelope->memberId,
            $reference['session_key'],
            $reference['client_key'],
            $envelope->traceId,
        );
        $member = $this->members->current($context);
        if ($context->tenantId !== $envelope->tenantId || $context->accountId !== $envelope->accountId || $context->memberId !== $envelope->memberId
            || $context->sessionKey !== $reference['session_key'] || $context->clientKey !== $reference['client_key'] || $context->requestId !== $envelope->traceId
            || $member === null || $member->accountSecurityRevision !== (int) ($reference['account_security_revision'] ?? 0)
            || $member->tenantSecurityRevision !== (int) ($reference['tenant_security_revision'] ?? 0)
            || $member->securityRevision !== (int) ($reference['member_security_revision'] ?? 0)) {
            throw new AdminAccessException('WORKFLOW_TASK_ACTOR_INVALID', 403, 'The original task identity or security revision is no longer current.');
        }
        return $context;
    }
}
