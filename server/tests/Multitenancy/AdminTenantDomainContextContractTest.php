<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

function expectAdminTenantDomainContext(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$serverRoot = dirname(__DIR__, 2);
foreach ([
    'app/common/service/member/MemberTenantContext.php',
    'app/common/service/article/ArticleTenantContext.php',
    'app/common/service/file/FileTenantContext.php',
    'app/common/service/oauth/OAuthTenantContext.php',
    'app/common/service/hot_search/HotSearchTenantContext.php',
    'app/common/service/decoration/DecorationTenantContext.php',
    'app/common/service/finance/FinanceTenantContext.php',
    'app/common/service/org/OrgTenantContext.php',
    'app/common/service/dict/DictTenantContext.php',
] as $path) {
    expectAdminTenantDomainContext(
        !is_file($serverRoot . '/' . $path),
        'retired domain-specific Tenant context was reintroduced: ' . $path,
    );
}

$current = (string)file_get_contents($serverRoot . '/app/common/execution/CurrentExecutionContext.php');
$store = (string)file_get_contents($serverRoot . '/app/common/execution/ExecutionContextStore.php');
$base = (string)file_get_contents($serverRoot . '/app/BaseController.php');
$adminBase = (string)file_get_contents($serverRoot . '/app/adminapi/controller/BaseAdminController.php');
$publicMiddleware = (string)file_get_contents($serverRoot . '/app/api/middleware/PublicTenantModuleMiddleware.php');
expectAdminTenantDomainContext(
    str_contains($current, 'function tenantAdmin(): TenantContext')
        && str_contains($current, 'function member(): AuthenticatedMemberContext')
        && str_contains($current, 'function system(): TenantSystemContext'),
    'CurrentExecutionContext lost its typed Tenant accessors',
);
expectAdminTenantDomainContext(
    str_contains($store, 'function run(ExecutionContext $context, callable $operation)')
        && str_contains($store, 'finally')
        && str_contains($store, 'array_pop($this->stack)'),
    'ExecutionContextStore no longer restores the scoped context',
);
expectAdminTenantDomainContext(
    str_contains($base, 'function executionContext(): CurrentExecutionContext')
        && str_contains($base, '$this->app->get(CurrentExecutionContext::class)')
        && str_contains($adminBase, '$this->executionContext()->tenantAdmin()')
        && str_contains($publicMiddleware, 'ConsumerExecutionContext::publicTenant($context)'),
    'Admin or public boundary bypasses the typed execution context',
);

echo "ADMIN-TENANT-DOMAIN-CONTEXT-001 passed\n";
