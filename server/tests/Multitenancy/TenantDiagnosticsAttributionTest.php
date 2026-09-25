<?php

declare(strict_types=1);

use PeanutAdmin\Kernel\Tenancy\TenantScope;
use PeanutAdmin\Modules\Ops\Domain\Logs\TenantDiagnosticAttributes;

require dirname(__DIR__, 2) . '/vendor/autoload.php';

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

$serverRoot = dirname(__DIR__, 2);
$refund = (string) file_get_contents($serverRoot . '/app/command/RefundReconcile.php');
$refundService = (string) file_get_contents(
    $serverRoot . '/app/modules/official/payment/src/Infrastructure/ThinkPhpRefundReconciliationCommands.php',
);
$demo = (string) file_get_contents($serverRoot . '/app/command/CrontabDemo.php');
expectTenantDiagnostics($refund !== '' && $demo !== '', 'Tenant-aware command source is unavailable');

$refundRequire = strpos($refund, 'ScheduledTenantContext::require()');
$refundAttributes = strpos($refund, 'PaymentTenantDiagnostics::fromScope($scope)');
$refundDispatch = strpos($refund, '$this->refunds->reconcile($scope, $diagnostics)');
expectTenantDiagnostics(
    $refundRequire !== false && $refundAttributes !== false && $refundDispatch !== false
        && $refundRequire < $refundAttributes && $refundAttributes < $refundDispatch
        && str_contains($refundService, 'RefundRecord::where([])'),
    'refund reconciliation no longer refuses before diagnostics and native business queries',
);

$events = [
    'refund_reconcile_related_data_missing',
    'refund_reconcile_gateway_query_failed',
    'refund_reconcile_gateway_status_unknown',
    'refund_reconcile_persist_failed',
];
expectTenantDiagnostics(substr_count($refundService, '$this->warning(') === count($events), 'refund warning event count changed');
foreach ($events as $event) {
    expectTenantDiagnostics(
        str_contains($refundService, "\$this->warning('{$event}', \$diagnostics"),
        "{$event} lost structured Tenant attribution",
    );
}
expectTenantDiagnostics(
    !str_contains($refundService, '$e->getMessage()'),
    'refund diagnostics expose exception messages that may contain sensitive provider data',
);
expectTenantDiagnostics(
    !str_contains($refundService, "'receipt' =>") && !str_contains($refundService, "'token' =>")
        && !str_contains($refundService, "'password' =>") && !str_contains($refundService, "'secret' =>"),
    'refund diagnostic attributes contain prohibited sensitive fields',
);

$demoRequire = strpos($demo, 'ScheduledTenantContext::require()');
$demoAttributes = strpos($demo, 'TenantDiagnosticAttributes::fromScope($scope)');
$demoLog = strpos($demo, 'Log::info($msg, $diagnostics)');
expectTenantDiagnostics(
    $demoRequire !== false && $demoAttributes !== false && $demoLog !== false
        && $demoRequire < $demoAttributes && $demoAttributes < $demoLog,
    'demo command lost fail-closed structured Tenant attribution',
);
expectTenantDiagnostics(
    str_contains($demo, "'[crontab:demo] tenant_id=%d executed at %s'")
        && str_contains($demo, '$output->writeln($msg)'),
    'demo command output compatibility changed',
);

echo "MT03-TENANT-DIAGNOSTICS-ATTRIBUTION-001 passed\n";
