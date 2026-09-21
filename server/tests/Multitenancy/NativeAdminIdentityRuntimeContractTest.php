<?php
declare(strict_types=1);

$serverDir = dirname(__DIR__, 2);
$runtimeFiles = [
    'app/adminapi',
    'app/common/service/authorization',
    'app/common/service/org/AdminDirectoryQuery.php',
    'app/platform/service/CoreTenantOwnerAdminProvisioner.php',
    'app/modules/official/import_export/src/Infrastructure/Authorization/AdminAsyncAuthorization.php',
];
$forbidden = [
    'pa_legacy_admin_tenant_map',
    'pa_legacy_role_tenant_map',
    'pa_legacy_dept_tenant_map',
    'pa_default_tenant_bootstrap',
];
$source = '';
if (is_file($serverDir . '/app/common/service/tenant/DefaultTenantBootstrap.php')) {
    throw new RuntimeException('Retired legacy bootstrap service remains in Runtime');
}
foreach ($runtimeFiles as $relative) {
    $path = $serverDir . '/' . $relative;
    if (is_dir($path)) {
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path));
        foreach ($iterator as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $source .= (string)file_get_contents($file->getPathname());
            }
        }
        continue;
    }
    $source .= (string)file_get_contents($path);
}

foreach ($forbidden as $table) {
    if (str_contains($source, $table)) {
        throw new RuntimeException("Forbidden identity dependency remains: {$table}");
    }
}
foreach (['pa_account', 'pa_credential', 'pa_tenant_member', 'pa_member_role', 'pa_role', 'pa_role_permission', 'pa_permission', 'pa_department'] as $table) {
    if (!str_contains($source, $table)) {
        throw new RuntimeException("Native identity dependency is missing: {$table}");
    }
}

$admin = (string)file_get_contents($serverDir . '/app/adminapi/application/auth/AdminApplicationService.php');
foreach (['add' => 'createAdministrator', 'edit' => 'updateAdministrator'] as $method => $command) {
    if (!preg_match('/public function ' . $method . '\\(.*?(?=\\n    (?:\/\\*\\*|public function))/s', $admin, $match)) {
        throw new RuntimeException("Administrator method is missing: {$method}");
    }
    if (!str_contains($match[0], '$service->' . $command . '(')) {
        throw new RuntimeException("Administrator {$method} must use the Core atomic command");
    }
    foreach (['createPending', 'update', 'replaceRoles', 'activate', 'suspend', 'transitionStatus'] as $primitive) {
        if (preg_match('/(?:->|::)' . $primitive . '\\(/', $match[0])) {
            throw new RuntimeException("Administrator {$method} splits the atomic command: {$primitive}");
        }
    }
}

echo "Native Admin identity runtime contract passed.\n";
