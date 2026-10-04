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
$queryPath = $serverRoot . '/app/modules/official/identity/src/Contract/PlatformDirectoryQueries.php';
$querySource = (string) file_get_contents($queryPath);
$hostQuerySource = (string) file_get_contents($serverRoot . '/app/platform/query/PlatformControlPlaneQueryService.php');
platformQueryExpect(!str_contains($hostQuerySource, 'Db::'), 'Platform query adapter bypasses the Identity owner');
$controllerSource = (string) file_get_contents(
    $serverRoot . '/app/platform/controller/PlatformControlPlaneQueryController.php',
);
$routesSource = peanut_route_registry_source($serverRoot);

// Parse current ThinkPHP calls rather than assuming the retired raw-SQL representation still exists.
require_once dirname($serverRoot) . '/tools/quality/vendor/autoload.php';
$traverser = new \PhpParser\NodeTraverser();
$traverser->addVisitor(new \PhpParser\NodeVisitor\NameResolver());
$nodes = $traverser->traverse((new \PhpParser\ParserFactory())->createForHostVersion()->parse($querySource));
$calls = (new \PhpParser\NodeFinder())->find($nodes, static function (\PhpParser\Node $node): bool {
    return ($node instanceof \PhpParser\Node\Expr\StaticCall
            && $node->class instanceof \PhpParser\Node\Name
            && $node->class->toString() === 'think\\facade\\Db'
            && $node->name instanceof \PhpParser\Node\Identifier
            && in_array(strtolower($node->name->toString()), ['name', 'table'], true))
        || ($node instanceof \PhpParser\Node\Expr\MethodCall
            && $node->name instanceof \PhpParser\Node\Identifier
            && in_array(strtolower($node->name->toString()), ['join', 'leftjoin', 'rightjoin'], true));
});
$tables = [];
foreach ($calls as $call) {
    platformQueryExpect(($call->args[0]->value ?? null) instanceof \PhpParser\Node\Scalar\String_, 'Platform directory table must remain a fixed owner declaration');
    $logical = preg_split('/\\s+/', trim($call->args[0]->value->value))[0];
    $tables[] = str_starts_with($logical, 'pa_') ? $logical : 'pa_' . $logical;
}
$tables = array_values(array_unique($tables));
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
    str_contains($hostQuerySource, 'ModuleQualificationQuery')
        && str_contains($hostQuerySource, 'installedModules()')
        && str_contains($hostQuerySource, 'tenantModuleStates('),
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
    $methodSource = $method === 'moduleStates' ? $hostQuerySource : $querySource;
    platformQueryExpect(
        str_contains($methodSource, "function {$method}(")
            && str_contains($methodSource, $permissionOrRole),
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
