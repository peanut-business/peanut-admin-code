<?php

declare(strict_types=1);

use PeanutAdmin\Kernel\Tenancy\TenantScope;
use PeanutAdmin\Modules\Ops\Domain\Logs\TenantDiagnosticAttributes;

require ((getenv('PEANUT_TEST_APPLICATION_ROOT') ?: dirname(__DIR__, 3)) . '/server') . '/vendor/autoload.php';

function expectTenantDiagnostics(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$scope = TenantScope::fromTrustedContext(101, 'crontab:v1:tenant=101:job=7:window=42');
$attributes = TenantDiagnosticAttributes::fromScope($scope);
expectTenantDiagnostics($attributes === [
    'scope' => 'tenant',
    'tenant_id' => 101,
    'correlation_id' => 'crontab:v1:tenant=101:job=7:window=42',
], 'trusted Tenant diagnostic attributes changed shape');
expectTenantDiagnostics(
    TenantDiagnosticAttributes::fromScope($scope) === $attributes,
    'one trusted command scope did not retain a stable correlation ID',
);

echo "MT03-TENANT-DIAGNOSTICS-ATTRIBUTION-001 passed\n";
