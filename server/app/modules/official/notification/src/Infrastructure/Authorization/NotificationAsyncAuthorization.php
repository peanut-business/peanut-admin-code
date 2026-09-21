<?php

declare(strict_types=1);

namespace PeanutAdmin\Modules\Notification\Infrastructure\Authorization;

use DateTimeImmutable;
use DateTimeZone;
use app\common\services\authorization\AdminAuthorizationService;
use PeanutAdmin\Kernel\Async\VerifiedJobEnvelope;
use PeanutAdmin\Kernel\Auth\AuthException;
use PeanutAdmin\Kernel\Auth\TenantContext;
use PeanutAdmin\Kernel\Auth\ValidatedTenantSession;
use PeanutAdmin\Kernel\Context\AuthorizationDecision;
use PeanutAdmin\Kernel\Context\AuthorizedOperationContext;
use PeanutAdmin\Modules\Notification\Delivery\Package;

/** Revalidates the fixed Notification delivery permission for every worker fence. */
final readonly class NotificationAsyncAuthorization
{
    public function __construct(private AdminAuthorizationService $authorization) {}

    public function reauthorize(VerifiedJobEnvelope $envelope): AuthorizedOperationContext
    {
        if ($envelope->tenantId < 1 || $envelope->accountId < 1 || $envelope->memberId < 1
            || trim($envelope->operationId) === '' || trim($envelope->traceId) === ''
            || !hash_equals(Package::RESOURCE_KEY, $envelope->resourceKey)
            || !hash_equals('manage', $envelope->operation)
            || $envelope->requestedTargets !== []
        ) {
            throw $this->denied();
        }

        try {
            $context = TenantContext::fromValidatedSession(new ValidatedTenantSession(
                $envelope->memberId,
                'async-' . hash('sha256', $envelope->operationId),
                $envelope->tenantId,
                $envelope->accountId,
                $envelope->memberId,
                'admin-async-worker',
                new DateTimeImmutable('now', new DateTimeZone('UTC')),
                1,
            ), $envelope->traceId);
            $principal = $this->authorization->principal($context);
            $context = TenantContext::fromValidatedSession(new ValidatedTenantSession(
                $envelope->memberId,
                'async-' . hash('sha256', $envelope->operationId),
                $envelope->tenantId,
                $envelope->accountId,
                $envelope->memberId,
                'admin-async-worker',
                new DateTimeImmutable('now', new DateTimeZone('UTC')),
                $principal->authorizationRevision,
            ), $envelope->traceId);
            if (!$this->authorization->decide($context, $principal, Package::MANAGE_PERMISSION)->allowed) {
                throw $this->denied();
            }

            return AuthorizedOperationContext::fromDecision(AuthorizationDecision::allow(
                $context,
                Package::RESOURCE_KEY,
                'manage',
                [],
                hash('sha256', implode("\0", [
                    (string)$envelope->tenantId,
                    (string)$envelope->memberId,
                    (string)$principal->authorizationRevision,
                    Package::MANAGE_PERMISSION,
                    $envelope->operationId,
                ])),
            ));
        } catch (\Throwable) {
            throw $this->denied();
        }
    }

    private function denied(): AuthException
    {
        return new AuthException('CONTEXT_SYSTEM_ACTOR_INVALID', 403);
    }
}
