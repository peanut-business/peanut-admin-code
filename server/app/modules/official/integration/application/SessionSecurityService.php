<?php

declare(strict_types=1);

namespace app\modules\official\integration\application;

use PeanutAdmin\IntegrationSecurity\Application\IntegrationSecurityException;
use app\modules\official\integration\Package;
use app\modules\official\integration\contracts\IntegrationSecurityRepository;
use PeanutAdmin\Kernel\Context\AuthorizedOperationContext;

final readonly class SessionSecurityService
{
    public function __construct(private IntegrationSecurityRepository $repository) {}

    /** @return list<SessionDevice> */
    public function list(AuthorizedOperationContext $context): array
    {
        $this->assertOperation($context, 'session-read');
        $tenant = $context->tenantContext;
        return $this->repository->sessionDevices($tenant->tenantId, $tenant->accountId, $tenant->sessionKey);
    }

    public function revoke(AuthorizedOperationContext $context, string $sessionKey): SessionDevice
    {
        $this->assertOperation($context, 'session-revoke');
        if (preg_match('/^[0-9A-HJKMNP-TV-Z]{26}$/D', $sessionKey) !== 1) {
            throw IntegrationSecurityException::sessionNotFound();
        }
        return $this->repository->revokeOwnSession($context->tenantContext, $sessionKey);
    }

    private function assertOperation(AuthorizedOperationContext $context, string $operation): void
    {
        if (!hash_equals(Package::RESOURCE_KEY, $context->resourceKey) || !hash_equals($operation, $context->operation)) {
            throw IntegrationSecurityException::denied();
        }
    }
}
