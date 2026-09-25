<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/route/registry_source.php';

require dirname(__DIR__, 2) . '/bootstrap/environment.php';

use PeanutAdmin\Kernel\Auth\ValidatedPlatformSession;
use PeanutAdmin\Kernel\Authorization\AuthorizationException;
use PeanutAdmin\Modules\Identity\Authorization\CorePermissionCatalog;
use PeanutAdmin\Modules\Identity\Authorization\CorePermissionCatalogSynchronizer;
use PeanutAdmin\Modules\Identity\Authorization\Persistence\ThinkPhpAuthorizationCatalogRepository;
use PeanutAdmin\Kernel\Authorization\RevisionPermissionCache;
use PeanutAdmin\Kernel\Context\PlatformContext;
use PeanutAdmin\Modules\Identity\Platform\Authorization\ThinkPhpPlatformAuthorizationRepository;
use PeanutAdmin\Kernel\Platform\Authorization\PlatformAuthorizationEvaluator;

require dirname(__DIR__, 2) . '/vendor/autoload.php';
require_once dirname(__DIR__, 2) . '/database/install.php';
require_once dirname(__DIR__) . '/Support/IsolatedBackendEnvironment.php';

function platformModuleHttpExpect(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function platformModuleHttpDenied(
    PlatformAuthorizationEvaluator $evaluator,
    PlatformContext $context,
    string $permission,
): void {
    try {
        $evaluator->assertAllowed($context, $permission);
    } catch (AuthorizationException) {
        return;
    }
    throw new RuntimeException("permission unexpectedly allowed: {$permission}");
}

function platformModuleHttpContext(int $operatorId, int $accountId, string $sessionKey): PlatformContext
{
    return PlatformContext::fromValidatedSession(new ValidatedPlatformSession(
        $operatorId,
        $sessionKey,
        $operatorId,
        $accountId,
        'platform-web',
        new DateTimeImmutable('+1 hour'),
    ), "{$sessionKey}-request");
}

$serverRoot = dirname(__DIR__, 2);
$routeSource = peanut_route_registry_source($serverRoot);
$controllerSource = (string) file_get_contents($serverRoot . '/app/platform/controller/PlatformModuleLifecycleController.php');
$middlewareSource = (string) file_get_contents($serverRoot . '/app/platform/http/middleware/PlatformInstanceToolMiddleware.php');
$serviceSource = (string) file_get_contents($serverRoot . '/app/platform/service/plugin/PlatformModuleRuntimeService.php');
$commandSource = (string) file_get_contents($serverRoot . '/app/command/ModuleSync.php');
$consoleSource = (string) file_get_contents($serverRoot . '/config/console.php');
$moduleConfigSource = (string) file_get_contents($serverRoot . '/config/modules.php');
$webRouteSource = (string) file_get_contents(dirname($serverRoot) . '/web/src/router/routes/modules/dev-tools.ts');
$webApiSource = (string) file_get_contents(dirname($serverRoot) . '/web/src/api/dev-tools/modules.ts');

$routes = [
    ['get', 'instance-tools/modules', 'lists', 'platform.module.read'],
    ['post', 'instance-tools/modules/create', 'create', 'platform.module.create'],
    ['post', 'instance-tools/modules/install', 'install', 'platform.module.install'],
    ['post', 'instance-tools/modules/uninstall', 'uninstall', 'platform.module.uninstall'],
    ['post', 'instance-tools/modules/disable', 'disable', 'platform.module.disable'],
    ['post', 'instance-tools/modules/sync', 'sync', 'platform.module.sync'],
];
$permissionKeys = [];
foreach ($routes as [$method, $path, $action, $permission]) {
    $permissionKeys[] = $permission;
    $pattern = sprintf(
        "~Route::%s\\('%s', \\[PlatformModuleLifecycleController::class, '%s'\\]\\)\\s*"
        . "->middleware\\(PlatformLoginMiddleware::class\\)\\s*"
        . "->middleware\\(PlatformPermissionMiddleware::class, '%s'\\)\\s*"
        . "->middleware\\(PlatformInstanceToolMiddleware::class\\);~",
        $method,
        preg_quote($path, '~'),
        preg_quote($action, '~'),
        preg_quote($permission, '~'),
    );
    platformModuleHttpExpect(preg_match($pattern, $routeSource) === 1, "{$path} lost its exact Platform middleware chain");
    platformModuleHttpExpect(str_contains($controllerSource, "public function {$action}()"), "{$action} controller adapter is missing");
}
sort($permissionKeys, SORT_STRING);
platformModuleHttpExpect(
    !str_contains($routeSource, "core.module")
        && !str_contains($controllerSource . $serviceSource, 'AdminPermissionService')
        && !str_contains($controllerSource . $serviceSource, 'TenantAuthorizationEvaluator')
        && !str_contains($controllerSource . $serviceSource, 'TenantContext'),
    'instance package lifecycle crossed into Tenant Admin authorization',
);
platformModuleHttpExpect(
    str_contains($middlewareSource, "Config::get('peanut.environment', '')")
        && str_contains($middlewareSource, "!== 'development'")
        && str_contains($middlewareSource, '!app()->isDebug()')
        && str_contains($middlewareSource, 'InstanceToolAccessGuard::fromConfiguredValue')
        && str_contains($middlewareSource, 'MODULE_RUNTIME_MUTATION_DISABLED'),
    'instance-tool environment/deployment gate is incomplete',
);
platformModuleHttpExpect(
    str_contains($webRouteSource, "path: 'modules'")
        && str_contains($webRouteSource, "instanceTool: true")
        && str_contains($webApiSource, "const PLATFORM_TOKEN_KEY = 'peanut-platform-token'")
        && str_contains($webApiSource, "client.post('/platformapi/instance-tools/modules/create'")
        && str_contains($serviceSource, 'new ModuleScaffoldGenerator(dirname($this->serverRoot))')
        && !str_contains($webApiSource, 'peanut-admin-token'),
    'Admin Web module page lost its existing /dev-tools plane, shared generator, or independent Platform token',
);
platformModuleHttpExpect(
    str_contains($consoleSource, "'module:sync'")
        && str_contains($commandSource, "setName('module:sync')")
        && str_contains($commandSource, "addOption('module'")
        && !str_contains($moduleConfigSource, 'frontend_components'),
    'module:sync command contract or module.json-derived frontend catalog regressed',
);

$database = $argv[1] ?? '';
platformModuleHttpExpect(
    preg_match('/^peanut_admin_development_p0e_[a-z0-9]{1,11}_plugin_lifecycle$/D', $database) === 1,
    'registered isolated plugin-lifecycle database name is required',
);
$host = IsolatedBackendEnvironment::required('DB_HOST');
$port = IsolatedBackendEnvironment::required('DB_PORT');
$user = IsolatedBackendEnvironment::required('DB_USER');
$password = IsolatedBackendEnvironment::required('DB_PASS');
$admin = new PDO(
    "mysql:host={$host};port={$port};charset=utf8mb4",
    $user,
    $password,
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES => false],
);
$exists = $admin->prepare('SELECT COUNT(*) FROM information_schema.schemata WHERE schema_name=?');
$exists->execute([$database]);
platformModuleHttpExpect((int) $exists->fetchColumn() === 0, 'isolated Platform Module HTTP database already exists');
$admin->exec("CREATE DATABASE `{$database}` CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci");

$completed = false;
$pdo = null;
try {
    IsolatedBackendEnvironment::activate([
        'APP_ENV' => 'development',
        'APP_DEBUG' => 'true',
        'DEPLOYMENT_MODE' => 'standalone',
        'PEANUT_DATABASE_RESOURCE_ID' => 'peanut-admin-p0e-mysql84-gate',
        'PEANUT_DATABASE_ENDPOINT_ID' => 'peanut-admin-p0e-mysql84-gate-host-direct',
        'PEANUT_DATABASE_CONSUMER' => 'host',
        'DB_HOST' => $host,
        'DB_PORT' => $port,
        'DB_NAME' => $database,
        'DB_USER' => $user,
        'DB_PASS' => $password,
        'DB_PREFIX' => 'pa_',
    ]);
    $pdo = new PDO(
        "mysql:host={$host};port={$port};dbname={$database};charset=utf8mb4",
        $user,
        $password,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES => false],
    );
    platformModuleHttpExpect((int) $pdo->query('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE()')->fetchColumn() === 0, 'Platform Module HTTP test database must start empty');
    $identity = initializeCoreIdentity(
        $pdo,
        'module-http-owner@example.test',
        'ModuleHttpOwnerPassword2026',
        null,
        new \app\common\service\DemoAccountPolicy(false, []),
    );
    executeSqlFiles($pdo, [$serverRoot . '/database/init.sql']);
    (new CorePermissionCatalogSynchronizer(new ThinkPhpAuthorizationCatalogRepository()))->synchronize();

    $catalogKeys = CorePermissionCatalog::PLATFORM;
    sort($catalogKeys, SORT_STRING);
    platformModuleHttpExpect(
        array_values(array_intersect($catalogKeys, $permissionKeys)) === $permissionKeys,
        'CorePermissionCatalog::PLATFORM is missing a Platform Module route key',
    );
    $placeholders = implode(',', array_fill(0, count($permissionKeys), '?'));
    $registered = $pdo->prepare("SELECT `key` FROM pa_permission WHERE module_key='platform' AND status='active' AND `key` IN ({$placeholders}) ORDER BY `key`");
    $registered->execute($permissionKeys);
    platformModuleHttpExpect($registered->fetchAll(PDO::FETCH_COLUMN) === $permissionKeys, 'Platform Module route keys did not synchronize as active platform permissions');

    $now = '2026-08-26 00:00:00.000';
    $pdo->exec("INSERT INTO pa_account (display_name,status,created_at,updated_at) VALUES ('Module HTTP Scoped','active','{$now}','{$now}')");
    $scopedAccountId = (int) $pdo->lastInsertId();
    $pdo->exec("INSERT INTO pa_platform_operator (account_id,display_name,status,created_at,updated_at) VALUES ({$scopedAccountId},'Module HTTP Scoped','active','{$now}','{$now}')");
    $scopedOperatorId = (int) $pdo->lastInsertId();
    $scopedContext = platformModuleHttpContext($scopedOperatorId, $scopedAccountId, 'module-http-scoped');
    $repository = new ThinkPhpPlatformAuthorizationRepository();
    $evaluator = new PlatformAuthorizationEvaluator($repository, new RevisionPermissionCache());
    foreach ($permissionKeys as $permission) {
        platformModuleHttpDenied($evaluator, $scopedContext, $permission);
    }

    $pdo->exec("INSERT INTO pa_platform_role (`key`,name,is_builtin,status,created_at,updated_at) VALUES ('platform.module-runtime-operator','Module Runtime Operator',0,'active','{$now}','{$now}')");
    $roleId = (int) $pdo->lastInsertId();
    $pdo->exec("INSERT INTO pa_platform_operator_role (platform_operator_id,platform_role_id,assigned_at) VALUES ({$scopedOperatorId},{$roleId},'{$now}')");
    $permissionIds = $pdo->prepare("SELECT id FROM pa_permission WHERE `key` IN ({$placeholders}) ORDER BY `key`");
    $permissionIds->execute($permissionKeys);
    $bind = $pdo->prepare('INSERT INTO pa_platform_role_permission (platform_role_id,permission_id,granted_at) VALUES (?,?,?)');
    foreach ($permissionIds->fetchAll(PDO::FETCH_COLUMN) as $permissionId) {
        $bind->execute([$roleId, (int) $permissionId, $now]);
    }
    $evaluator = new PlatformAuthorizationEvaluator($repository, new RevisionPermissionCache());
    foreach ($permissionKeys as $permission) {
        $evaluator->assertAllowed($scopedContext, $permission);
    }

    $ownerAccountId = (int) $pdo->query('SELECT account_id FROM pa_platform_operator WHERE id=' . (int) $identity['operator_id'])->fetchColumn();
    $ownerContext = platformModuleHttpContext((int) $identity['operator_id'], $ownerAccountId, 'module-http-owner');
    platformModuleHttpDenied(
        new PlatformAuthorizationEvaluator($repository, new RevisionPermissionCache()),
        $ownerContext,
        'platform.module.unregistered-probe',
    );

    $completed = true;
    echo "PLATFORM-MODULE-RUNTIME-HTTP-D3-001 passed database={$database}\n";
} finally {
    IsolatedBackendEnvironment::cleanup();
    $pdo = null;
    if ($completed) {
        $admin->exec("DROP DATABASE `{$database}`");
    } else {
        fwrite(STDERR, "PLATFORM_MODULE_HTTP_TEST_DATABASE_RETAINED={$database}\n");
    }
}
