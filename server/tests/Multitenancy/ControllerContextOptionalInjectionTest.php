<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use app\adminapi\controller\BaseAdminController;
use app\api\controller\BaseApiController;
use app\platform\controller\BasePlatformController;
use app\common\execution\AdminExecutionContext;
use app\common\execution\CurrentExecutionContext;
use app\common\execution\ExecutionContextStore;
use app\platform\context\PlatformOperatorContext;
use PeanutAdmin\Kernel\Auth\TenantContext;
use think\App;
use think\Container;
use think\Request;

function expectOptionalInjection(bool $condition, string $message): void
{
    $GLOBALS['controller_context_assertions'] = ($GLOBALS['controller_context_assertions'] ?? 0) + 1;
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

// 1. 初始化容器与执行上下文
$app = new App();
Container::setInstance($app);

$store = new ExecutionContextStore();
$current = new CurrentExecutionContext($store);
$request = new Request();

$app->instance(App::class, $app);
$app->instance(Request::class, $request);
$app->instance(ExecutionContextStore::class, $store);
$app->instance(CurrentExecutionContext::class, $current);

// 历史测试文件名保留；合同现为明确注入，由 ThinkPHP 原生容器解析，不再从基类兜底查容器。
// 2. 三个派生控制器明确转发框架依赖。
class SampleAdminController extends BaseAdminController
{
    public function __construct(App $app, CurrentExecutionContext $executionContext)
    {
        parent::__construct($app, $executionContext);
    }

    public function exposedContext(): CurrentExecutionContext
    {
        return $this->executionContext();
    }

    public function exposedAdminId(): int
    {
        return $this->adminId;
    }
}

class SampleApiController extends BaseApiController
{
    public function __construct(App $app, CurrentExecutionContext $executionContext)
    {
        parent::__construct($app, $executionContext);
    }

    public function exposedContext(): CurrentExecutionContext
    {
        return $this->executionContext;
    }

    public function exposedMemberId(): int
    {
        return $this->memberId;
    }
}

class SamplePlatformController extends BasePlatformController
{
    public function __construct(App $app, CurrentExecutionContext $executionContext)
    {
        parent::__construct($app, $executionContext);
    }

    public function exposedContext(): CurrentExecutionContext
    {
        return $this->executionContext;
    }

    public function exposedPlatformContext(): ?PlatformOperatorContext
    {
        return $this->platformContext;
    }
}

// 3. ThinkPHP 原生容器为必需构造参数注入当前上下文。
$adminController = $app->make(SampleAdminController::class, [], true);
expectOptionalInjection(
    $adminController->exposedContext() === $current,
    'SampleAdminController did not receive the explicitly required dependency via the ThinkPHP container',
);

// 4. 测试 Admin 控制器：显式传入时优先使用传入实例
$mockStore = new ExecutionContextStore();
$mockCurrent = new CurrentExecutionContext($mockStore);
$explicitAdminController = new class($app, $mockCurrent) extends BaseAdminController {
    public function exposedContext(): CurrentExecutionContext
    {
        return $this->executionContext();
    }
};
expectOptionalInjection(
    $explicitAdminController->exposedContext() === $mockCurrent,
    'Explicit CurrentExecutionContext injection was ignored in BaseAdminController',
);

// 5. Api 通过同一个正式容器机制注入。
$apiController = $app->make(SampleApiController::class, [], true);
expectOptionalInjection(
    $apiController->exposedContext() === $current,
    'SampleApiController did not receive the explicitly required dependency via the ThinkPHP container',
);

// 6. Platform 通过同一个正式容器机制注入。
$platformController = $app->make(SamplePlatformController::class, [], true);
expectOptionalInjection(
    $platformController->exposedContext() === $current,
    'SamplePlatformController did not receive the explicitly required dependency via the ThinkPHP container',
);

// 7. 测试生命周期与上下文提取
use PeanutAdmin\Kernel\Auth\ValidatedTenantSession;

$validatedSession = new ValidatedTenantSession(
    301,
    '01JMT02OPTIONAL0000000000001',
    101,
    201,
    301,
    'admin-web',
    new DateTimeImmutable('2031-01-01T00:00:00Z'),
    1,
);
$tenantContext = TenantContext::fromValidatedSession($validatedSession, 'req-test-admin-001');
$adminExecution = new AdminExecutionContext(
    $tenantContext,
    'http.admin.test',
    ['id' => 301, 'name' => 'admin-tester'],
);

$store->run($adminExecution, function () use ($app) {
    $activeAdminController = $app->make(SampleAdminController::class, [], true);
    expectOptionalInjection(
        $activeAdminController->exposedAdminId() === 301,
        'BaseAdminController initialize() did not extract adminId from CurrentExecutionContext',
    );
});

// 直接实例化遗漏安全上下文必须失败，不能静默从全局状态补齐。
foreach ([SampleAdminController::class, SampleApiController::class, SamplePlatformController::class] as $type) {
    $missingRejected = false;
    try {
        new $type($app);
    } catch (ArgumentCountError) {
        $missingRejected = true;
    }
    expectOptionalInjection($missingRejected, $type . ' unexpectedly accepted a missing context');
    $parameter = (new ReflectionClass($type))->getConstructor()->getParameters()[1];
    expectOptionalInjection(!$parameter->allowsNull() && !$parameter->isDefaultValueAvailable(), 'Context must be required and non-null');
}
expectOptionalInjection($store->isEmpty(), 'Context was not restored after the request');
echo 'CONTROLLER-CONTEXT-EXPLICIT-INJECTION-001 passed: ' . $GLOBALS['controller_context_assertions'] . " assertions\n";
