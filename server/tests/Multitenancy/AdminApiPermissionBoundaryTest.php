<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/route/registry_source.php';

use app\adminapi\http\middleware\AuthMiddleware;
use app\adminapi\infrastructure\AdminApiAccessRegistry;
use app\common\execution\AdminExecutionContext;
use app\common\execution\CurrentExecutionContext;
use app\common\execution\ExecutionContextStore;
use app\common\http\ApiProblem;
use app\common\services\authorization\AdminAuthorizationService;
use PeanutAdmin\Modules\Identity\Policy\DemoAccountPolicy;
use app\common\policy\permission\RegisteredAdminPermissionPolicy;
use PeanutAdmin\Kernel\Auth\TenantContext;
use PeanutAdmin\Kernel\Auth\ValidatedTenantSession;
use app\common\dto\authorization\AdminPrincipal;
use app\common\infrastructure\authorization\CoreTenantModuleAdminBridge;
use app\common\services\authorization\EndpointPermissionGrant;
use app\platform\infrastructure\module\ThinkPhpModuleGovernanceProvider;
use PeanutAdmin\Kernel\Module\CompiledModuleRegistry;
use PeanutAdmin\Kernel\Module\ModuleAvailability;
use PeanutAdmin\Kernel\Tenancy\TenantScope;
use PeanutAdmin\Modules\Identity\Authorization\ApiPermissionCatalog;
use PeanutAdmin\Modules\Identity\Authorization\CorePermissionCatalog;
use PeanutAdmin\Modules\Identity\Authorization\ThinkPhpTenantAuthorizationRepository;
use PeanutAdmin\Modules\Identity\Contract\AdminDirectoryQuery;
use PeanutAdmin\Modules\Identity\Contract\TenantAuthorizationQuery;
use PeanutAdmin\Modules\Identity\Membership\Query\ThinkPhpTenantMemberDirectory;
use PeanutAdmin\Modules\Identity\Menu\MenuAdministrationService;
use PeanutAdmin\Modules\Identity\Menu\ThinkPhpMenuCatalogRepository;
use PeanutAdmin\Modules\Identity\Module\TenantEntitlementService;

use app\common\execution\PlatformExecutionContext;
use app\platform\context\PlatformOperatorContext;
use app\platform\http\middleware\PlatformPermissionMiddleware;
use app\platform\services\PlatformApiAuthorization;
use app\platform\services\PlatformOperatorSessionService;
use PeanutAdmin\Kernel\Auth\ValidatedPlatformSession;
use PeanutAdmin\Kernel\Authorization\EffectivePermissionSet;
use PeanutAdmin\Kernel\Authorization\RevisionPermissionCache;
use PeanutAdmin\Kernel\Context\PlatformContext;
use PeanutAdmin\Kernel\Platform\Authorization\PlatformAuthorizationEvaluator;
use PeanutAdmin\Kernel\Platform\Authorization\PlatformAuthorizationRepository;
use PeanutAdmin\Modules\Identity\Auth\PlatformAuthService;

require_once dirname(__DIR__) . '/Support/CiBootstrap.php';
require_once dirname(__DIR__, 2) . '/vendor/topthink/framework/src/helper.php';

// Both groups use synthetic sessions and SQLite; network HTTP and LoginMiddleware are separate qualification.
$checks = 0;
function expectAdminApiBoundary(bool $condition, string $message): void
{
    global $checks;
    if (!$condition) {
        throw new RuntimeException($message);
    }
    ++$checks;
}

function problemPayload(callable $operation): array
{
    try {
        $operation();
    } catch (ApiProblem $problem) {
        expectAdminApiBoundary($problem->httpStatus === intdiv($problem->apiCode(), 100), 'HTTP denial status differs from the permission envelope');
        return [
            'code' => $problem->apiCode(),
            'msg' => $problem->getMessage(),
            'data' => $problem->data(),
        ];
    }
    throw new RuntimeException('middleware denial did not throw ApiProblem');
}

$policy = new RegisteredAdminPermissionPolicy();
$registered = ['core.generator.read', 'core.member.update'];
expectAdminApiBoundary($policy->canAccess(false, 'core.generator.read', $registered, ['core.generator.read']), 'registered grant must pass');
expectAdminApiBoundary(!$policy->canAccess(false, 'core.generator.read', $registered, []), 'registered unowned route must fail');
expectAdminApiBoundary(!$policy->canAccess(false, 'unknown/detail', $registered, ['unknown/detail']), 'unregistered route must fail even if claimed as granted');
expectAdminApiBoundary($policy->canAccess(true, 'core.member.update', $registered, []), 'root must bypass role ownership for a registered permission');
expectAdminApiBoundary(!$policy->canAccess(true, 'core.member.leave', $registered, []), 'root must not bypass permission registration');
expectAdminApiBoundary(!$policy->canAccess(false, 'core.member.update', ['core.member.leave'], ['core.member.leave']), 'status mutation must not inherit an unrelated grant');

$executionContexts = new ExecutionContextStore();
$current = new CurrentExecutionContext($executionContexts);
$root = dirname(__DIR__, 3);
$temporary = $root . '/.local/tmp/admin-api-permission-boundary';
if (!is_dir($temporary) && !mkdir($temporary, 0700, true) && !is_dir($temporary)) {
    throw new RuntimeException('ADMIN_API_TEST_DIRECTORY_UNAVAILABLE');
}
$app = new think\App($temporary);
$database = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
ThinkPhpTestConnection::fromPdo($database);
$database->exec(<<<'SQL'
    CREATE TABLE pa_api_endpoint (id TEXT PRIMARY KEY, method TEXT, path TEXT, audience TEXT, module_key TEXT, access_mode TEXT, status TEXT, default_permission_keys TEXT, prerequisite_permission_keys TEXT);
    CREATE TABLE pa_permission (id INTEGER PRIMARY KEY, "key" TEXT, module_key TEXT, status TEXT);
    CREATE TABLE pa_permission_api (id INTEGER PRIMARY KEY, permission_id INTEGER, api_id TEXT);
    SQL);
// Consume real generated endpoint identities; the instance deliberately has no bundle bindings.
$catalog = json_decode(file_get_contents(dirname(__DIR__, 2) . '/generated/api-catalog.json'), true, 512, JSON_THROW_ON_ERROR);
$insert = $database->prepare('INSERT INTO pa_api_endpoint VALUES (?,?,?,?,?,?,?,?,?)');
foreach ($catalog['endpoints'] as $endpoint) {
    if ($endpoint['application'] !== 'adminapi' || $endpoint['owner']['type'] !== 'application') {
        continue;
    }
    $insert->execute([$endpoint['api_id'], $endpoint['method'], strtolower($endpoint['path']), $endpoint['audience'], 'peanut.admin',
        $endpoint['auth']['mode'], 'active', json_encode($endpoint['default_permission_keys'], JSON_THROW_ON_ERROR),
        json_encode($endpoint['prerequisite_permission_keys'], JSON_THROW_ON_ERROR)]);
}
$registry = new CompiledModuleRegistry([], [], [], [], 'admin-api-boundary');
$entitlements = new TenantEntitlementService($registry);
$identity = new TenantAuthorizationQuery(new ThinkPhpTenantMemberDirectory(), $registry, $entitlements);
$bridge = new CoreTenantModuleAdminBridge(
    new ThinkPhpModuleGovernanceProvider(dirname(__DIR__, 2), [], ThinkPhpTestConnection::moduleCatalogs($database), $registry),
    new ThinkPhpTenantAuthorizationRepository($entitlements, $identity, $registry),
    new ThinkPhpMenuCatalogRepository($entitlements), $identity, new MenuAdministrationService(), $entitlements,
);
$request = new think\Request();
$router = new think\Route($app);
$requestFor = static function (string $method, string $path) use ($request, $router): think\Request {
    $request->setMethod($method)->setPathinfo($path)->setRule($router->rule($path, static fn() => null, $method));
    $request->withMiddleware([AdminAuthorizationService::class => null]);
    return $request;
};
$authorization = new AdminAuthorizationService($bridge, $policy, new AdminDirectoryQuery($current), $identity, new EndpointPermissionGrant($request));
$modules = new class implements ModuleAvailability {
    public function assertAvailable(TenantScope $scope, string $moduleKey, DateTimeImmutable $now, bool $lock = false): void { throw new LogicException('Unexpected Module admission'); }
    public function assertDeployment(string $moduleKey, bool $lock = false): void { throw new LogicException('Unexpected Module admission'); }
    public function assertTenant(TenantScope $scope, string $moduleKey, DateTimeImmutable $now, bool $lock = false): void { throw new LogicException('Unexpected Module admission'); }
};
$accessConfig = require dirname(__DIR__, 2) . '/config/admin_api_access.php';
$middleware = new AuthMiddleware(
    $current,
    $authorization,
    new AdminApiAccessRegistry((int) $accessConfig['version'], $accessConfig),
    new DemoAccountPolicy(false, []), new ApiPermissionCatalog(), $modules, $entitlements,
);
$tenant = TenantContext::fromValidatedSession(new ValidatedTenantSession(
    7,
    'admin-api-permission-session',
    101,
    7007,
    7,
    'admin-web',
    new DateTimeImmutable('2031-01-01T00:00:00Z'),
    1,
    1,
), 'admin-api-permission-request');
$execution = new AdminExecutionContext($tenant, 'test.admin-api-permission', [
    'id' => 7,
    'tenant_id' => 101,
    'account_id' => 7007,
    'authorization_revision' => 1,
    'root' => 0,
]);
$nextCalls = 0;
$next = static function ($request) use (&$nextCalls): string {
    $nextCalls++;
    return 'allowed';
};

$anonymous = $requestFor('GET', 'admin/self');
$anonymousDenial = problemPayload(static fn() => $middleware->handle($anonymous, $next));
expectAdminApiBoundary($anonymousDenial === ['code' => 40100, 'msg' => '请先登录', 'data' => null], 'anonymous denial shape changed');

think\facade\Db::startTrans();
$authenticated = $requestFor('GET', 'admin/self');
expectAdminApiBoundary(
    $executionContexts->run($execution, static fn() => $middleware->handle($authenticated, $next)) === 'allowed',
    'authenticated-only route must pass after login',
);
expectAdminApiBoundary($nextCalls === 1, 'authenticated-only route did not reach its controller exactly once');

$authenticatedWrongMethod = $requestFor('POST', 'admin/self');
$wrongMethodDenial = problemPayload(static fn() => $executionContexts->run(
    $execution,
    static fn() => $middleware->handle($authenticatedWrongMethod, $next),
));
expectAdminApiBoundary($wrongMethodDenial === ['code' => 40300, 'msg' => 'API 未登记或已退役', 'data' => null], 'wrong method must fail the exact API registration gate');

$protected = $requestFor('GET', 'menu/lists');
$principal = AdminPrincipal::fromArray($execution->principal);
$protected->withMiddleware([AdminAuthorizationService::class => ['context' => $tenant, 'principal' => $principal]]);
$noGrant = problemPayload(static fn() => $executionContexts->run($execution, static fn() => $middleware->handle($protected, $next)));
expectAdminApiBoundary($noGrant === ['code' => 40300, 'msg' => '暂无访问权限', 'data' => null], 'registered API with no instance permission bindings must be denied');
expectAdminApiBoundary($protected->middleware(AdminAuthorizationService::class) === null, 'authorization handoff must be consumed');

// A foreign-tenant principal cannot reuse the trusted request/context handoff.
$foreignPrincipal = AdminPrincipal::fromArray([...$execution->principal, 'tenant_id' => 202]);
$protected->withMiddleware([AdminAuthorizationService::class => ['context' => $tenant, 'principal' => $foreignPrincipal]]);
$foreignDecision = $authorization->decideApiForRequest($protected, $tenant, ['path' => '/adminapi/menu/lists'], ['core.menu.read']);
expectAdminApiBoundary(!$foreignDecision->allowed && $foreignDecision->reason === 'INVALID_TENANT_ADMIN_CONTEXT', 'foreign principal must fail identity validation before considering API grants');
$protected->withMiddleware([AdminAuthorizationService::class => ['context' => $tenant, 'principal' => $foreignPrincipal]]);
$crossTenant = problemPayload(static fn() => $executionContexts->run($execution, static fn() => $middleware->handle($protected, $next)));
expectAdminApiBoundary($crossTenant === ['code' => 40300, 'msg' => '暂无访问权限', 'data' => null], 'cross-tenant principal must not reach the controller');
$contextDecision = $authorization->decideApiForRequest($protected, $tenant, ['path' => '/adminapi/menu/lists'], ['core.menu.read']);
expectAdminApiBoundary(!$contextDecision->allowed && $contextDecision->reason === 'INVALID_TENANT_ADMIN_CONTEXT', 'consumed handoff must not be replayable');
expectAdminApiBoundary($nextCalls === 1, 'a rejected API reached its controller');
think\facade\Db::rollback();

$routeSource = peanut_route_registry_source(dirname(__DIR__, 2));
expectAdminApiBoundary(str_contains($routeSource, "\$peanutRouteApplication = 'adminapi'"), 'Tenant Admin route entry is missing');
expectAdminApiBoundary(str_contains($routeSource, 'LoginMiddleware::class, AuthMiddleware::class'), 'Tenant Admin guard chain is missing');
expectAdminApiBoundary(!str_contains($routeSource, "Route::group('platformapi'"), 'Platform routes must remain individually guarded');

$loginSource = (string) file_get_contents(dirname(__DIR__, 2) . '/app/adminapi/http/middleware/LoginMiddleware.php');
expectAdminApiBoundary(str_contains($loginSource, "str_starts_with(\$token, 'pa_tat_')"), 'Tenant Admin token audience gate is missing');
expectAdminApiBoundary(!str_contains($loginSource, 'pa_pat_'), 'Platform credential prefix must not enter Tenant Admin login middleware');

$platformLoginSource = (string) file_get_contents(dirname(__DIR__, 2) . '/app/platform/http/middleware/PlatformLoginMiddleware.php');
$platformPermissionSource = (string) file_get_contents(dirname(__DIR__, 2) . '/app/platform/http/middleware/PlatformPermissionMiddleware.php');
expectAdminApiBoundary(str_contains($platformPermissionSource, "str_starts_with(\$permission, 'platform.')"), 'Platform permission catalog boundary is missing');
expectAdminApiBoundary(!str_contains($platformLoginSource, 'AdminTokenService'), 'Platform login must not use Tenant Admin sessions');

$schema = strtolower((string) file_get_contents(dirname(__DIR__, 2) . '/database/schema/baseline-schema.sql'));
$initialSeeder = (string) file_get_contents(dirname(__DIR__, 2) . '/database/seeds/InitialSeeder.php');
expectAdminApiBoundary(str_contains($initialSeeder, 'CorePermissionCatalogSynchronizer'), 'fresh installation must synchronize the native Core catalog');
foreach (['core.member.update', 'core.menu.read', 'core.menu.manage', 'core.generator.generate'] as $exactPermission) {
    expectAdminApiBoundary(in_array($exactPermission, CorePermissionCatalog::TENANT, true), 'exact Core permission is missing: ' . $exactPermission);
}
// Optional retired official.* packages are not part of this application's selected Modules.
$identitySchema = (string) file_get_contents(dirname(__DIR__, 2) . '/app/modules/peanut/identity/database/migrations/20261101010001_initial_identity_schema.php');
expectAdminApiBoundary(str_contains($identitySchema, 'CREATE TABLE `pa_role_permission`'), 'Identity must retain independent role-permission storage');
expectAdminApiBoundary(!str_contains(strtolower($identitySchema), 'pa_system_role_menu'), 'Identity must not restore role-menu storage');
expectAdminApiBoundary(!str_contains($schema, 'pa_system_role_menu'), 'retired role-menu storage remains in the fresh schema');



// Preserve the 059 platform audience, deny-side-effect and identity-release matrix.
(static function (): void {
    global $checks;
$cachePath = dirname(__DIR__, 3) . '/.local/tmp/admin-api-permission-boundary/' . bin2hex(random_bytes(6)) . '/';
$app = new think\App($cachePath);
$app->config->set(['default' => 'file', 'stores' => ['file' => ['type' => 'File', 'path' => $cachePath . 'cache/']]], 'cache');
$pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
ThinkPhpTestConnection::fromPdo($pdo);
$pdo->exec(<<<'SQL'
    CREATE TABLE pa_api_endpoint (id TEXT PRIMARY KEY, method TEXT, path TEXT, audience TEXT, module_key TEXT, access_mode TEXT, status TEXT, default_permission_keys TEXT, prerequisite_permission_keys TEXT);
    CREATE TABLE pa_permission (id INTEGER PRIMARY KEY, "key" TEXT, module_key TEXT, status TEXT);
    CREATE TABLE pa_permission_api (id INTEGER PRIMARY KEY, permission_id INTEGER, api_id TEXT);
    CREATE TABLE business_writes (id INTEGER PRIMARY KEY);
    INSERT INTO pa_api_endpoint VALUES
      ('self','GET','/adminapi/admin/self','tenant','peanut.admin','authenticated','active','[]','[]'),
      ('protected','POST','/adminapi/secure','tenant','peanut.admin','tenant_permission','active','["app.write"]','[]'),
      ('platform','GET','/platformapi/secure','platform','platform','platform_permission','active','["platform.read"]','["platform.prerequisite"]');
    INSERT INTO pa_permission VALUES (1,'platform.read','platform','active');
    INSERT INTO pa_permission_api VALUES (1,1,'platform');
    SQL);
$catalog = new ApiPermissionCatalog();
$store = new ExecutionContextStore();
$current = new CurrentExecutionContext($store);
$empty = static fn(string $class): object => (new ReflectionClass($class))->newInstanceWithoutConstructor();
$modules = new class implements ModuleAvailability {
    public function assertAvailable(\PeanutAdmin\Kernel\Tenancy\TenantScope $scope, string $moduleKey, DateTimeImmutable $now, bool $lock = false): void {}
    public function assertDeployment(string $moduleKey, bool $lock = false): void {}
    public function assertTenant(\PeanutAdmin\Kernel\Tenancy\TenantScope $scope, string $moduleKey, DateTimeImmutable $now, bool $lock = false): void {}
};
$demo = new DemoAccountPolicy(false, []);
$admin = new AuthMiddleware($current, $empty(AdminAuthorizationService::class),
    new AdminApiAccessRegistry(1, require dirname(__DIR__, 2) . '/config/admin_api_access.php'),
    $demo, $catalog, $modules, new TenantEntitlementService(new CompiledModuleRegistry([], [], [], [], 'security-fixture')));
$tenant = TenantContext::fromValidatedSession(new ValidatedTenantSession(
    7, 'synthetic-admin-session', 101, 7007, 7, 'admin-web', new DateTimeImmutable('2031-01-01'), 1, 1,
), 'admin-api-permission-request');
$execution = new AdminExecutionContext($tenant, 'test.admin-api-permission', [
    'id' => 7, 'tenant_id' => 101, 'account_id' => 7007, 'authorization_revision' => 1, 'root' => 1,
]);
$platformContext = PlatformOperatorContext::fromValidatedPlatformSession(PlatformContext::fromValidatedSession(
    new ValidatedPlatformSession(1, 'synthetic-platform-session', 8008, 8, 'platform-web', new DateTimeImmutable('2031-01-01')),
    'platform-api-permission-request',
));
$platformExecution = new PlatformExecutionContext($platformContext, 'test.platform-api-permission');
$permissions = new class implements PlatformAuthorizationRepository {
    public array $keys = ['platform.read', 'platform.prerequisite'];
    public function revision(int $operatorId): string { return implode(',', $this->keys); }
    public function permissions(int $operatorId): EffectivePermissionSet { return new EffectivePermissionSet($this->keys); }
};
$request = static function (string $path, string $method = 'GET') use ($app): think\Request {
    $request = (new think\Request())->withServer(['REQUEST_METHOD' => $method]);
    $request->setPathinfo($path);
    $request->setRule((new think\Route($app))->rule($path, static fn() => null, $method));
    return $request->withGet(['tenant_id' => 999, 'token' => 'forged']);
};
$nextCalls = 0;
$next = static function () use (&$nextCalls, $pdo): string {
    ++$nextCalls;
    $pdo->exec('INSERT INTO business_writes DEFAULT VALUES');
    return 'allowed';
};
$deny = static function (callable $operation, int $status, ?string $message = null) use ($pdo, $store, &$nextCalls): void {
    $before = $pdo->query('SELECT * FROM business_writes')->fetchAll();
    $calls = $nextCalls;
    try {
        $operation();
        throw new RuntimeException('middleware denial did not throw ApiProblem');
    } catch (ApiProblem $problem) {
        expectAdminApiBoundary($problem->httpStatus === $status, 'wrong denial HTTP status');
        expectAdminApiBoundary($problem->apiCode() === $status * 100, 'wrong denial envelope code');
        expectAdminApiBoundary($problem->data() === null, 'wrong denial envelope data');
        if ($message !== null) {
            expectAdminApiBoundary($problem->getMessage() === $message, 'wrong denial envelope message');
        }
    }
    expectAdminApiBoundary($nextCalls === $calls, 'denial reached business continuation');
    expectAdminApiBoundary($before === $pdo->query('SELECT * FROM business_writes')->fetchAll(), 'denial changed business rows');
    expectAdminApiBoundary($store->isEmpty() && $store->rootIdentity() === null, 'denial leaked execution identity');
};
$policy = new RegisteredAdminPermissionPolicy();
$registered = ['app.write', 'admin/status'];
expectAdminApiBoundary($policy->canAccess(false, 'app.write', $registered, ['app.write']), 'registered grant must pass');
expectAdminApiBoundary(!$policy->canAccess(false, 'app.write', $registered, []), 'unowned route must fail');
expectAdminApiBoundary(!$policy->canAccess(false, 'unknown', $registered, ['unknown']), 'unregistered grant must fail');
expectAdminApiBoundary($policy->canAccess(true, 'admin/status', $registered, []), 'root registered policy failed');
expectAdminApiBoundary(!$policy->canAccess(true, 'admin/edit', $registered, []), 'root bypassed registration');
expectAdminApiBoundary(!$policy->canAccess(false, 'admin/status', ['admin/edit'], ['admin/edit']), 'runtime alias granted permission');
$deny(fn() => $admin->handle($request('admin/self'), $next), 401, '请先登录');
$deny(fn() => $store->run($platformExecution, fn() => $admin->handle($request('admin/self'), $next)), 401);
expectAdminApiBoundary($store->run($execution, fn() => $admin->handle($request('admin/self'), $next)) === 'allowed', 'authenticated endpoint refused');
expectAdminApiBoundary($nextCalls === 1 && $store->isEmpty(), 'allowed path failed to release context');
$deny(fn() => $store->run($execution, fn() => $admin->handle($request('admin/self', 'POST'), $next)), 403, 'API 未登记或已退役');
$deny(fn() => $store->run($execution, fn() => $admin->handle($request('unknown'), $next)), 403);
// Missing trusted LoginMiddleware handoff must fail even for a root principal.
$deny(fn() => $store->run($execution, fn() => $admin->handle($request('secure', 'POST'), $next)), 403);
$pdo->exec("UPDATE pa_api_endpoint SET status='retired' WHERE id='self'");
$app->cache->clear();
$deny(fn() => $store->run($execution, fn() => $admin->handle($request('admin/self'), $next)), 403);
$platformRequest = $request('secure');
$grant = new EndpointPermissionGrant($platformRequest);
$sessions = new PlatformOperatorSessionService($empty(PlatformAuthService::class),
    new PlatformApiAuthorization(new PlatformAuthorizationEvaluator($permissions, new RevisionPermissionCache()), $grant), $permissions);
$platform = new PlatformPermissionMiddleware($current, $sessions, $demo, $catalog, $grant, $modules);
$callPlatform = fn() => $platform->handle($platformRequest, $next, 'platform.read');
$deny($callPlatform, 401);
$deny(fn() => $store->run($execution, $callPlatform), 401);
expectAdminApiBoundary($store->run($platformExecution, $callPlatform) === 'allowed', 'granted platform endpoint refused');
expectAdminApiBoundary($nextCalls === 2 && $store->isEmpty(), 'platform allow leaked context');
$permissions->keys = ['platform.read'];
$deny(fn() => $store->run($platformExecution, $callPlatform), 403);
$permissions->keys = ['platform.prerequisite'];
$deny(fn() => $store->run($platformExecution, $callPlatform), 403);
$permissions->keys = ['platform.read', 'platform.prerequisite'];
$deny(fn() => $store->run($platformExecution, fn() => $platform->handle($platformRequest, $next, 'core.member.read')), 403);
$pdo->exec("UPDATE pa_api_endpoint SET status='retired' WHERE id='platform'");
$deny(fn() => $store->run($platformExecution, $callPlatform), 403);
expectAdminApiBoundary((int) $pdo->query('SELECT COUNT(*) FROM business_writes')->fetchColumn() === 2, 'unexpected business writes');
$pdo->exec("UPDATE pa_api_endpoint SET status='active' WHERE id='platform'");
try {
    $store->run($platformExecution, fn() => $platform->handle($platformRequest,
        static fn() => throw new RuntimeException('synthetic-controller-failure'), 'platform.read'));
    throw new LogicException('expected controller failure');
} catch (RuntimeException $exception) {
    expectAdminApiBoundary($exception->getMessage() === 'synthetic-controller-failure', 'business exception changed');
}
expectAdminApiBoundary($store->isEmpty() && $store->rootIdentity() === null, 'business exception leaked context');
$app->cache->clear();
echo "ADMIN-API-PERMISSION-BOUNDARY-001 passed; {$checks} checks; sqlite memory; network HTTP NOT_RUN\n";

})();
