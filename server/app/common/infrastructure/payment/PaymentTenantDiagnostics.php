<?php
declare(strict_types=1);

namespace app\common\infrastructure\payment;

use app\modules\official\ops\domain\Logs\TenantDiagnosticAttributes;
use PeanutAdmin\Kernel\Tenancy\TenantScope;

/** Payment-owned adapter for reconciliation diagnostics. */
final class PaymentTenantDiagnostics
{
    /** @return array<string,mixed> */
    public static function fromScope(TenantScope $scope): array
    {
        return TenantDiagnosticAttributes::fromScope($scope);
    }
}
