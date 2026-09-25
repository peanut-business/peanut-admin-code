<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use app\BaseController;
use app\adminapi\controller\BaseAdminController;
use app\api\controller\BaseApiController;
use app\common\execution\AdminExecutionContext;
use app\common\execution\ConsumerExecutionContext;
use app\common\execution\CurrentExecutionContext;
use app\common\execution\ExecutionContextStore;
use app\platform\context\PlatformOperatorContext;
use app\platform\controller\BasePlatformController;
use PeanutAdmin\Kernel\Auth\TenantContext;
use PeanutAdmin\Kernel\Auth\ValidatedTenantSession;
use think\App;
use think\Container;
use think\Request;

function expectControllerContext(bool $condition, string $message): void
{
    $GLOBALS['controller_context_assertions'] = ($GLOBALS['controller_context_assertions'] ?? 0) + 1;
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function expectControllerContextThrows(callable $operation, string $message): Throwable
{
    $GLOBALS['controller_context_assertions'] = ($GLOBALS['controller_context_assertions'] ?? 0) + 1;
    try {
        $operation();
    } catch (Throwable $error) {
        return $error;
    }
    throw new RuntimeException($message);
}

/** @return array{App,ExecutionContextStore,CurrentExecutionContext} */
function controllerContextApp(): array
{
    $app = new App();
    $store = new ExecutionContextStore();
    $current = new CurrentExecutionContext($store);
    $app->instance(App::class, $app);
    $app->instance(Request::class, new Request());
    $app->instance(ExecutionContextStore::class, $store);
    $app->instance(CurrentExecutionContext::class, $current);
    return [$app, $store, $current];
}

function controllerTenant(int $tenantId, int $accountId, int $memberId): TenantContext
{
    return TenantContext::fromValidatedSession(new ValidatedTenantSession(
        $memberId,
        'controller-context-' . $tenantId,
        $tenantId,
        $accountId,
        $memberId,
        'admin-web',
        new DateTimeImmutable('2031-01-01T00:00:00Z'),
        1,
    ), 'controller-context-request-' . $tenantId);
}

class SampleAdminController extends BaseAdminController
{
    public function exposedContext(): CurrentExecutionContext
    {
        return $this->executionContext();
    }
    public function exposedAdminId(): int
    {
        return $this->adminId;
    }
    public function exposedTenant(): TenantContext
    {
        return $this->tenantAdminContext();
    }
}

class SampleApiController extends BaseApiController
{
    public function exposedContext(): CurrentExecutionContext
    {
        return $this->executionContext();
    }
    public function exposedMember(): mixed
    {
        return $this->memberContext();
    }
}

class SamplePlatformController extends BasePlatformController
{
    public function exposedContext(): CurrentExecutionContext
    {
        return $this->executionContext();
    }
    public function exposedPlatformContext(): ?PlatformOperatorContext
    {
        return $this->platformContext;
    }
}

class SampleRawController extends BaseController {}

$previousContainer = Container::getInstance();
try {
    [$appA, $storeA, $currentA] = controllerContextApp();
    [$appB, $storeB, $currentB] = controllerContextApp();

    // 每个 Controller 只读取构造它的 App；静态全局容器当前指向哪个 App 不影响结果。
    $tenantA = controllerTenant(101, 201, 301);
    $tenantB = controllerTenant(102, 202, 302);
    $storeA->run(new AdminExecutionContext($tenantA, 'controller.test.a', [
        'id' => 301, 'tenant_id' => 101, 'account_id' => 201,
    ]), function () use ($appA, $currentA): void {
        $controller = new SampleAdminController($appA);
        expectControllerContext($controller->exposedContext() === $currentA, 'App A reader was replaced by another App');
        expectControllerContext($controller->exposedAdminId() === 301, 'App A principal was not initialized');
        expectControllerContext(isset($controller->context), 'registered magic context alias is not set');
        expectControllerContext($controller->context === $currentA, 'magic context alias did not use the fixed getter');
        expectControllerContext(!isset($controller->unknown), 'unknown magic property unexpectedly exists');
        expectControllerContextThrows(
            static fn(): mixed => $controller->unknown,
            'unknown magic property read was accepted',
        );
        expectControllerContextThrows(
            static function () use ($controller): void {
                $controller->context = new stdClass();
            },
            'readonly magic context alias accepted a write',
        );
        expectControllerContextThrows(
            static function () use ($controller): void {
                unset($controller->context);
            },
            'readonly magic context alias accepted unset',
        );
    });
    $storeB->run(new AdminExecutionContext($tenantB, 'controller.test.b', [
        'id' => 302, 'tenant_id' => 102, 'account_id' => 202,
    ]), function () use ($appB, $currentB): void {
        $controller = new SampleAdminController($appB);
        expectControllerContext($controller->exposedContext() === $currentB, 'App B reused App A reader');
        expectControllerContext($controller->exposedAdminId() === 302, 'App B reused App A principal');
    });

    // 同一 App 的先后请求每次从 reader 的当前栈初始化，不缓存人员快照。
    $ids = [];
    foreach ([$tenantA, $tenantB] as $tenant) {
        $storeA->run(new AdminExecutionContext($tenant, 'controller.test.sequence', [
            'id' => $tenant->memberId,
            'tenant_id' => $tenant->tenantId,
            'account_id' => $tenant->accountId,
        ]), function () use ($appA, &$ids): void {
            $ids[] = (new SampleAdminController($appA))->exposedAdminId();
        });
    }
    expectControllerContext($ids === [301, 302], 'sequential tenants reused a previous principal snapshot');

    try {
        $storeA->run(new AdminExecutionContext($tenantA, 'controller.test.failure', [
            'id' => 301, 'tenant_id' => 101, 'account_id' => 201,
        ]), static function () use ($appA): never {
            new SampleAdminController($appA);
            throw new DomainException('fixture failure');
        });
    } catch (DomainException $error) {
        expectControllerContext($error->getMessage() === 'fixture failure', 'fixture exception changed');
    }
    expectControllerContext($storeA->isEmpty() && $storeB->isEmpty(), 'exception or request leaked execution context');

    // 安全域不互相降级：存在 reader 不代表 Admin/Member/Platform 身份可互换。
    $storeA->run(ConsumerExecutionContext::anonymous('controller.consumer', 'consumer-request'), function () use ($appA): void {
        $controller = new SampleAdminController($appA);
        $error = expectControllerContextThrows(
            static fn(): TenantContext => $controller->exposedTenant(),
            'consumer context was accepted as tenant admin',
        );
        expectControllerContext($error->getMessage() === 'EXECUTION_TENANT_ADMIN_CONTEXT_REQUIRED', 'admin domain denial changed');
    });
    $storeA->run(new AdminExecutionContext($tenantA, 'controller.admin', [
        'id' => 301, 'tenant_id' => 101, 'account_id' => 201,
    ]), function () use ($appA): void {
        $api = new SampleApiController($appA);
        $error = expectControllerContextThrows(
            static fn(): mixed => $api->exposedMember(),
            'admin context was accepted as member',
        );
        expectControllerContext($error->getMessage() === 'EXECUTION_MEMBER_CONTEXT_REQUIRED', 'member domain denial changed');
        expectControllerContext(
            (new SamplePlatformController($appA))->exposedPlatformContext() === null,
            'admin context was accepted as platform operator',
        );
    });

    // reader 必须事先注册；BaseController 不 make 新 reader，也不读取全局 app()。
    $missingApp = new App();
    $missingApp->instance(Request::class, new Request());
    $raw = new SampleRawController($missingApp);
    expectControllerContext(!isset($raw->context), 'missing reader appears registered');
    expectControllerContextThrows(static fn(): mixed => $raw->context, 'missing reader was synthesized');
    expectControllerContextThrows(
        static fn(): SampleAdminController => new SampleAdminController($missingApp),
        'admin controller accepted a missing registered reader',
    );

    foreach ([SampleAdminController::class, SampleApiController::class, SamplePlatformController::class] as $type) {
        $constructor = (new ReflectionClass($type))->getConstructor();
        expectControllerContext(
            $constructor?->getDeclaringClass()->getName() === BaseController::class
                && count($constructor->getParameters()) === 1
                && $constructor->getParameters()[0]->getType()?->getName() === App::class,
            $type . ' does not inherit the single native App constructor',
        );
    }
} finally {
    Container::setInstance($previousContainer);
}

echo 'CONTROLLER-CONTEXT-GETTER-001 passed: ' . $GLOBALS['controller_context_assertions'] . " assertions\n";
