<?php

declare(strict_types=1);

namespace app\platform\contract;

interface TenantOwnerAdminProvisioner
{
    public function provision(
        int $tenantId,
        int $accountId,
        int $memberId,
        int $coreRoleId,
        string $tenantCode,
        string $displayName,
    ): int;
}
