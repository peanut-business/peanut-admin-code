<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/route/registry_source.php';

use app\adminapi\http\middleware\AuthMiddleware;
use app\adminapi\service\AdminApiAccessRegistry;
use app\common\execution\AdminExecutionContext;
use app\common\execution\CurrentExecutionContext;
use app\common\execution\ExecutionContextStore;
use app\common\http\ApiProblem;
use app\common\service\authorization\AdminAuthorizationService;
use app\common\service\DemoAccountPolicy;
use app\common\service\permission\RegisteredAdminPermissionPolicy;
use PeanutAdmin\Kernel\Auth\TenantContext;
use PeanutAdmin\Kernel\Auth\ValidatedTenantSession;

require dirname(__DIR__, 2) . '/bootstrap/environment.php';
require dirname(__DIR__, 2) . '/vendor/autoload.php';

function expectAdminApiBoundary(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function problemPayload(callable $operation): array
{
    try {
        $operation();
    } catch (ApiProblem $problem) {
        return [
            'code' => $problem->apiCode(),
            'msg' => $problem->getMessage(),
            'data' => $problem->data(),
        ];
    }
    throw new RuntimeException('middleware denial did not throw ApiProblem');
}

$policy = new RegisteredAdminPermissionPolicy();
$registered = ['official.article.list', 'admin/status'];
expectAdminApiBoundary($policy->canAccess(false, 'official.article.list', $registered, ['official.article.list']), 'registered grant must pass');
expectAdminApiBoundary(!$policy->canAccess(false, 'official.article.list', $registered, []), 'registered unowned route must fail');
expectAdminApiBoundary(!$policy->canAccess(false, 'unknown/detail', $registered, ['unknown/detail']), 'unregistered route must fail even if claimed as granted');
expectAdminApiBoundary($policy->canAccess(true, 'admin/status', $registered, []), 'root must bypass role ownership for a registered route');
expectAdminApiBoundary(!$policy->canAccess(true, 'admin/edit', $registered, []), 'root must not bypass route registration');
expectAdminApiBoundary(!$policy->canAccess(false, 'admin/status', ['admin/edit'], ['admin/edit']), 'status route must not inherit through a runtime alias');

$executionContexts = new ExecutionContextStore();
$accessConfig = require dirname(__DIR__, 2) . '/config/admin_api_access.php';
$middleware = new AuthMiddleware(
    new CurrentExecutionContext($executionContexts),
    (new ReflectionClass(AdminAuthorizationService::class))->newInstanceWithoutConstructor(),
    new AdminApiAccessRegistry((int)$accessConfig['version'], $accessConfig),
    (new ReflectionClass(DemoAccountPolicy::class))->newInstanceWithoutConstructor(),
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
), 'admin-api-permission-request');
$execution = new AdminExecutionContext($tenant, 'test.admin-api-permission', [
    'id' => 7,
    'tenant_id' => 202,
    'account_id' => 7007,
    'authorization_revision' => 1,
    'root' => 0,
]);
$nextCalls = 0;
$next = static function ($request) use (&$nextCalls): string {
    $nextCalls++;
    return 'allowed';
};

$anonymous = new class {
    public function pathinfo(): string { return 'admin/self'; }
    public function method(): string { return 'GET'; }
};
$anonymousDenial = problemPayload(static fn() => $middleware->handle($anonymous, $next));
expectAdminApiBoundary($anonymousDenial === ['code' => 40100, 'msg' => '请先登录', 'data' => null], 'anonymous denial shape changed');

$authenticated = new class {
    public array $adminInfo = ['id' => 7, 'tenant_id' => 101, 'root' => 0];
    public object $tenantContext;
    public function __construct() { $this->tenantContext = new stdClass(); }
    public function pathinfo(): string { return 'admin/self'; }
    public function method(): string { return 'GET'; }
};
expectAdminApiBoundary(
    $executionContexts->run($execution, static fn() => $middleware->handle($authenticated, $next)) === 'allowed',
    'authenticated-only route must pass after login',
);
expectAdminApiBoundary($nextCalls === 1, 'authenticated-only route did not reach its controller exactly once');

$authenticatedWrongMethod = new class {
    public array $adminInfo = ['id' => 7, 'tenant_id' => 101, 'root' => 0];
    public object $tenantContext;
    public function __construct() { $this->tenantContext = new stdClass(); }
    public function pathinfo(): string { return 'admin/self'; }
    public function method(): string { return 'POST'; }
};
$wrongMethodDenial = problemPayload(static fn() => $executionContexts->run(
    $execution,
    static fn() => $middleware->handle($authenticatedWrongMethod, $next),
));
expectAdminApiBoundary($wrongMethodDenial === ['code' => 40300, 'msg' => '暂无访问权限', 'data' => null], 'wrong-method denial must keep the generic permission shape');

$routeSource = peanut_route_registry_source(dirname(__DIR__, 2));
expectAdminApiBoundary(str_contains($routeSource, "\$peanutRouteApplication = 'adminapi'"), 'Tenant Admin route entry is missing');
expectAdminApiBoundary(str_contains($routeSource, 'LoginMiddleware::class, AuthMiddleware::class'), 'Tenant Admin guard chain is missing');
expectAdminApiBoundary(!str_contains($routeSource, "Route::group('platformapi'"), 'Platform routes must remain individually guarded');

$loginSource = (string)file_get_contents(dirname(__DIR__, 2) . '/app/adminapi/http/middleware/LoginMiddleware.php');
expectAdminApiBoundary(str_contains($loginSource, "str_starts_with(\$token, 'pa_tat_')"), 'Tenant Admin token audience gate is missing');
expectAdminApiBoundary(!str_contains($loginSource, 'pa_pat_'), 'Platform credential prefix must not enter Tenant Admin login middleware');

$platformLoginSource = (string)file_get_contents(dirname(__DIR__, 2) . '/app/platform/http/middleware/PlatformLoginMiddleware.php');
$platformPermissionSource = (string)file_get_contents(dirname(__DIR__, 2) . '/app/platform/http/middleware/PlatformPermissionMiddleware.php');
expectAdminApiBoundary(str_contains($platformPermissionSource, "str_starts_with(\$permission, 'platform.')"), 'Platform permission catalog boundary is missing');
expectAdminApiBoundary(!str_contains($platformLoginSource, 'AdminTokenService'), 'Platform login must not use Tenant Admin sessions');

$schema = strtolower((string)file_get_contents(dirname(__DIR__, 2) . '/database/init.sql'));
$officialPermissions = $schema
    . strtolower((string)file_get_contents(dirname(__DIR__, 2) . '/app/modules/official/payment/resources/permissions.json'))
    . strtolower((string)file_get_contents(dirname(__DIR__, 2) . '/app/modules/official/import_export/resources/permissions.json'));
foreach (['admin/status', 'official.payment.recharge.refund', 'official.payment.refund.stat', 'official.import-export.operation.status'] as $exactPermission) {
    expectAdminApiBoundary(str_contains($officialPermissions, $exactPermission), 'exact status permission is missing: ' . $exactPermission);
}
expectAdminApiBoundary(str_contains($schema, 'insert into `pa_permission`'), 'fresh schema must seed the Core permission catalog');
expectAdminApiBoundary(str_contains($schema, 'insert ignore into `pa_role_permission`'), 'fresh schema must grant permissions through Core RBAC');
expectAdminApiBoundary(!str_contains($schema, 'pa_system_role_menu'), 'retired role-menu storage remains in the fresh schema');

echo "ADMIN-API-PERMISSION-BOUNDARY-001 passed\n";
