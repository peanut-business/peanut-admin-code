<?php

declare(strict_types=1);

/** D03：后台写命令必须把认证 TenantContext 原样交给实际加载的 Core。 */
require dirname(__DIR__, 2) . '/vendor/autoload.php';

use PeanutAdmin\Kernel\Auth\TenantContext;

function expectAdminCoreContext(bool $condition, string $message): void
{
    $GLOBALS['adminCoreContextAssertions'] = ($GLOBALS['adminCoreContextAssertions'] ?? 0) + 1;
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function expectFirstTenantContext(string $class, array $methods): void
{
    $reflection = new ReflectionClass($class);
    foreach ($methods as $method) {
        $parameter = $reflection->getMethod($method)->getParameters()[0] ?? null;
        expectAdminCoreContext(
            $parameter?->getType()?->getName() === TenantContext::class,
            $class . '::' . $method . '() no longer accepts TenantContext as its first parameter',
        );
    }
}

function serviceMethod(string $source, string $method): string
{
    expectAdminCoreContext(
        preg_match('/public function ' . $method . '\\(.*?(?=\\n    (?:\/\\*\\*|public function|private function)|\\n})/s', $source, $match) === 1,
        'application service method is missing: ' . $method,
    );

    return $match[0];
}

function expectContextCalls(string $source, string $service, array $methods): void
{
    foreach ($methods as $method => $calls) {
        $body = serviceMethod($source, $method);
        foreach ($calls as $call) {
            expectAdminCoreContext(
                preg_match('/->' . preg_quote($call, '/') . '\\(\\s*\\$context(?=\\s*[,)])/', $body) === 1,
                $service . '::' . $method . '() must pass the authenticated TenantContext to ' . $call . '()',
            );
        }
    }
}

expectFirstTenantContext(
    PeanutAdmin\Modules\Identity\Authorization\Application\RoleAdminService::class,
    ['create', 'update', 'archive', 'replacePermissions'],
);
expectFirstTenantContext(
    PeanutAdmin\Modules\Identity\Organization\Application\DepartmentAdminService::class,
    ['create', 'update', 'move', 'archive'],
);
expectFirstTenantContext(
    PeanutAdmin\Modules\Identity\Membership\Application\MemberAdminService::class,
    ['createAdministrator', 'updateAdministrator', 'activate', 'suspend', 'leave'],
);
expectFirstTenantContext(
    PeanutAdmin\Modules\Identity\Identity\SelfService\AccountSelfService::class,
    ['profile', 'updateProfile', 'changePassword'],
);

$server = dirname(__DIR__, 2);
$role = (string) file_get_contents($server . '/app/adminapi/services/auth/RoleApplicationService.php');
$department = (string) file_get_contents($server . '/app/adminapi/services/dept/DeptApplicationService.php');
$admin = (string) file_get_contents($server . '/app/adminapi/services/auth/AdminApplicationService.php');

expectContextCalls($role, 'RoleApplicationService', [
    'add' => ['create', 'replacePermissions'],
    'edit' => ['update', 'replacePermissions'],
    'delete' => ['archive'],
]);
expectContextCalls($department, 'DeptApplicationService', [
    'add' => ['create'],
    'edit' => ['update', 'move'],
    'delete' => ['archive'],
]);
expectContextCalls($admin, 'AdminApplicationService', [
    'add' => ['createAdministrator'],
    'edit' => ['updateAdministrator'],
    'delete' => ['leave'],
    'editSelf' => ['profile', 'updateProfile', 'changePassword'],
]);

expectAdminCoreContext(
    preg_match('/->suspend\\(\\s*\\$context(?=\\s*[,)])/', $admin) === 1
        && preg_match('/->activate\\(\\s*\\$context(?=\\s*[,)])/', $admin) === 1,
    'AdminApplicationService::updateStatus() must pass TenantContext to Core status commands',
);

echo 'ADMIN-CORE-TENANT-CONTEXT-CONTRACT-001 passed; assertions=' . $GLOBALS['adminCoreContextAssertions'] . "\n";
