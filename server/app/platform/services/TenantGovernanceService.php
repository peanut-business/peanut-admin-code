<?php

declare(strict_types=1);

namespace app\platform\services;

use PeanutAdmin\Modules\Identity\Contract\TenantOwnerAdminProvisioner;
use app\common\exception\BusinessException;
use app\platform\identity\PlatformOperatorIdentityPort;
use PeanutAdmin\Modules\Identity\Platform\Application\PlatformTenantAdminService;
use PeanutAdmin\Modules\Identity\Platform\Application\TenantOwnerAdminService;
use think\facade\Db;
use PeanutAdmin\Modules\Identity\Tenancy\TenantStatus;

/**
 * Application adapter for the instance-local Tenant control plane.
 *
 * It accepts no administrator id or request tenant id as authority. The caller must inject a
 * trusted platform identity port; until one exists, use UnavailablePlatformOperatorIdentityPort.
 */
final readonly class TenantGovernanceService
{
    public function __construct(
        private PlatformOperatorIdentityPort $identities,
        private PlatformTenantAdminService $administration,
        private TenantOwnerAdminService $owners,
        private TenantOwnerAdminProvisioner $ownerAdmins,
    ) {}

    /** @return array{tenant_id:int,account_id:int,member_id:int,role_id:int,status:string} */
    public function provision(
        string $operatorCredential,
        string $tenantCode,
        string $tenantName,
        string $ownerEmail,
        ?string $initialPassword,
        string $ownerDisplayName,
        string $requestId,
    ): array {
        try {
            $operator = $this->identities->requireActive($operatorCredential, $requestId);
            return Db::transaction(function () use (
                $operator,
                $tenantCode,
                $tenantName,
                $ownerEmail,
                $initialPassword,
                $ownerDisplayName,
                $requestId
            ): array {
                $tenant = $this->administration->createTenant(
                    $operator->core,
                    $tenantCode,
                    $tenantName,
                    $tenantName,
                    'zh-CN',
                    'Asia/Shanghai',
                );
                $candidate = $this->owners->createCandidate(
                    $operator->core,
                    (int) $tenant['id'],
                    $ownerEmail,
                    $ownerDisplayName,
                    $initialPassword,
                );
                $member = $candidate['member'];
                $this->owners->activateCandidate(
                    $operator->core,
                    (int) $candidate['tenant_id'],
                    (int) $member['id'],
                    (int) $member['revision'],
                    $requestId . ':owner-activation',
                    'Initial Tenant owner activation',
                );
                $this->ownerAdmins->provision(
                    (int) $candidate['tenant_id'],
                    (int) $member['account_id'],
                    (int) $member['id'],
                    (int) $member['role_id'],
                    $tenantCode,
                    $ownerDisplayName,
                );

                return [
                    'tenant_id' => (int) $candidate['tenant_id'],
                    'account_id' => (int) $member['account_id'],
                    'member_id' => (int) $member['id'],
                    'role_id' => (int) $member['role_id'],
                    'status' => 'pending',
                ];
            });
        } catch (\DomainException|\InvalidArgumentException) {
            throw BusinessException::conflict(
                'TENANT_PROVISION_REJECTED',
                'Tenant provisioning was rejected.',
            );
        }
    }

    /** @return array<string,mixed> */
    public function transition(
        string $operatorCredential,
        int $tenantId,
        int $expectedRevision,
        TenantStatus $next,
        string $changeReason,
        string $requestId,
    ): array {
        try {
            $operator = $this->identities->requireActive($operatorCredential, $requestId);
            return $this->administration->transitionTenant(
                $operator->core,
                $tenantId,
                $expectedRevision,
                $next,
                $changeReason,
            );
        } catch (\DomainException|\InvalidArgumentException) {
            [$errorCode, $message] = match ($next) {
                TenantStatus::Active => ['TENANT_ACTIVATION_REJECTED', 'Tenant activation was rejected.'],
                TenantStatus::Suspended => ['TENANT_SUSPENSION_REJECTED', 'Tenant suspension was rejected.'],
                TenantStatus::Closed => ['TENANT_CLOSURE_REJECTED', 'Tenant closure was rejected.'],
                default => ['TENANT_TRANSITION_REJECTED', 'Tenant transition was rejected.'],
            };
            throw BusinessException::conflict($errorCode, $message);
        }
    }

}
