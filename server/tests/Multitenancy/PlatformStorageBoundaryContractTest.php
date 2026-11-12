<?php

declare(strict_types=1);

require_once ((getenv('PEANUT_TEST_APPLICATION_ROOT') ?: dirname(__DIR__, 3)) . '/server') . '/route/registry_source.php';

function expectPlatformStorageBoundary(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$serverRoot = ((getenv('PEANUT_TEST_APPLICATION_ROOT') ?: dirname(__DIR__, 3)) . '/server');
$routes = peanut_route_registry_source($serverRoot);
$policy = (string) file_get_contents($serverRoot . '/app/modules/peanut/identity/src/Platform/InstanceControlPlanePolicy.php');
$permissions = (string) file_get_contents($serverRoot . '/app/common/services/authorization/AdminAuthorizationService.php');

foreach (['infrastructure/storage', 'platform.ops.read',
    'platform.ops.maintenance.manage', 'PlatformStorageController'] as $marker) {
    expectPlatformStorageBoundary(str_contains($routes, $marker), 'Platform storage route missing: ' . $marker);
}
foreach (["Route::get('storage/lists'", "Route::post('storage/setup'", "Route::post('storage/change'"] as $retired) {
    expectPlatformStorageBoundary(!str_contains($routes, $retired), 'Tenant Admin storage route remains: ' . $retired);
}
foreach (['storage/lists', 'storage/detail', 'storage/setup', 'storage/change'] as $permission) {
    expectPlatformStorageBoundary(str_contains($policy, "'{$permission}'"), 'instance permission not classified: ' . $permission);
}
expectPlatformStorageBoundary(
    str_contains($permissions, 'InstanceControlPlanePolicy::isTenantAdminRoute')
        && str_contains($permissions, 'InstanceControlPlanePolicy::tenantAdminPaths()'),
    'Tenant Admin menu or root bypass can still reach instance storage control',
);

echo "PLATFORM-STORAGE-BOUNDARY-001 passed\n";
