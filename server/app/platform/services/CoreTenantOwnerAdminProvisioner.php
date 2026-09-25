<?php

declare(strict_types=1);

namespace app\platform\services;

use app\platform\contract\TenantOwnerAdminProvisioner;
use think\facade\Db;

/** Verifies that Core already provisioned the first owner and initializes application capabilities. */
final readonly class CoreTenantOwnerAdminProvisioner implements TenantOwnerAdminProvisioner
{
    public function __construct(
        private ApplicationTenantBootstrapService $applicationBootstrap,
    ) {}

    public function provision(
        int $tenantId,
        int $accountId,
        int $memberId,
        int $coreRoleId,
        string $tenantCode,
        string $displayName,
    ): int {
        if (min($tenantId, $accountId, $memberId, $coreRoleId) < 1 || $tenantCode === '' || $displayName === '') {
            throw new \DomainException('TENANT_OWNER_ADMIN_PRINCIPAL_INVALID');
        }

        $principal = Db::name('tenant_member')->alias('member')
            ->join('account account', "account.id=member.account_id AND account.status='active'")
            ->join('member_role membership', 'membership.tenant_id=member.tenant_id AND membership.tenant_member_id=member.id')
            ->join('role role', "role.tenant_id=membership.tenant_id AND role.id=membership.role_id AND role.`key`='core.tenant-owner' AND role.is_builtin=1 AND role.status='active'")
            ->where('member.tenant_id', $tenantId)->where('member.id', $memberId)
            ->where('member.account_id', $accountId)->where('member.status', 'active')
            ->where('role.id', $coreRoleId)->value('member.id');
        if ($principal === null) {
            throw new \DomainException('TENANT_OWNER_ADMIN_PRINCIPAL_INVALID');
        }

        $this->applicationBootstrap->provision(
            $tenantId,
            $memberId,
            $coreRoleId,
            $tenantCode,
        );

        return $memberId;
    }
}
