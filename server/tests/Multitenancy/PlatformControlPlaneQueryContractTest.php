<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/route/registry_source.php';

function platformQueryExpect(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$serverRoot = dirname(__DIR__, 2);
$queryPath = $serverRoot . '/app/platform/query/PlatformControlPlaneQueryService.php';
$querySource = (string) file_get_contents($queryPath);
$controllerSource = (string) file_get_contents(
    $serverRoot . '/app/platform/controller/PlatformControlPlaneQueryController.php',
);
$routesSource = peanut_route_registry_source($serverRoot);

preg_match_all('/\b(?:FROM|JOIN)\s+(pa_[a-z0-9_]+)/i', $querySource, $matches);
$tables = array_values(array_unique(array_map('strtolower', $matches[1] ?? [])));
sort($tables);
$allowed = [
    'pa_account',
    'pa_credential',
    'pa_member_role',
    'pa_permission',
    'pa_platform_audit_event',
    'pa_platform_operator',
    'pa_platform_operator_role',
    'pa_platform_role',
    'pa_platform_role_permission',
    'pa_role',
    'pa_tenant_member',
];
sort($allowed);
platformQueryExpect($tables === $allowed, 'Platform query table boundary changed: ' . implode(', ', $tables));
platformQueryExpect(
    str_contains($querySource, 'ModuleQualificationQuery')
        && str_contains($querySource, 'installedModules()')
        && str_contains($querySource, 'tenantModuleStates('),
    'Platform Module catalog no longer consumes the Module Governance qualification contract',
);

$genericTenantList = strpos($routesSource, "tenants',");
platformQueryExpect($genericTenantList !== false, 'generic Tenant list route is missing');
foreach ([
    'operators' => 'platform.operator.read',
    'roles' => 'platform.role.read',
    'permissions' => 'platform.permission.read',
    'audit' => 'platform.audit.read',
    'moduleStates' => 'platform.tenant.read',
    'owner' => 'core.tenant-owner',
] as $method => $permissionOrRole) {
    platformQueryExpect(
        str_contains($querySource, "function {$method}(")
            && str_contains($querySource, $permissionOrRole),
        "Platform query contract missing: {$method}",
    );
    platformQueryExpect(
        str_contains($controllerSource, "function {$method}()"),
        "Platform query controller method missing: {$method}",
    );
}
foreach (['pa_member', 'pa_article', 'pa_recharge_order', 'pa_config', 'pa_file'] as $businessTable) {
    platformQueryExpect(
        !in_array($businessTable, $tables, true),
        "Platform query crossed into a Tenant business table: {$businessTable}",
    );
}
foreach ([
    "tenants/detail",
    "tenants/invitations",
    "tenants/owner",
    "tenants/modules",
] as $specificRoute) {
    $specificPosition = strpos($routesSource, $specificRoute);
    platformQueryExpect(
        $specificPosition !== false && $specificPosition < $genericTenantList,
        "generic Tenant list shadows specific route: {$specificRoute}",
    );
}

echo "PLATFORM-CONTROL-PLANE-QUERY-CONTRACT-001 passed\n";
