<?php
declare(strict_types=1);

namespace PeanutAdmin\Fixtures\DeliveryRecord\Infrastructure\Authorization;

use PeanutAdmin\Fixtures\DeliveryRecord\Service\DeliveryRecordAccess;
use app\common\execution\CurrentExecutionContext;
use PeanutAdmin\Kernel\authorization\TenantAuthorizationRepository;
use PeanutAdmin\Kernel\Module\ModuleException;

final readonly class ThinkPhpDeliveryRecordAccess implements DeliveryRecordAccess
{
    public function __construct(
        private CurrentExecutionContext $executionContext,
        private TenantAuthorizationRepository $authorization,
    )
    {
    }

    public function requirePermission(string $permission): void
    {
        $context = $this->executionContext->tenantAdmin();
        $permissions = $this->authorization->permissions(
            $context->tenantId,
            $context->memberId,
        );
        if (!$permissions->allows($permission)) {
            throw new ModuleException('AUTHORIZATION_PERMISSION_DENIED', 'Member permission is required.');
        }
    }
}
