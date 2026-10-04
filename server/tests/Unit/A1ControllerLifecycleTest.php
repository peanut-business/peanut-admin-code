<?php

declare(strict_types=1);

namespace tests\Unit\A1Lifecycle;

use app\adminapi\controller\BaseAdminController;
use app\adminapi\controller\WorkbenchController;
use app\adminapi\services\WorkbenchApplicationService;
use app\BaseController;
use app\common\execution\AdminExecutionContext;
use app\common\execution\CurrentExecutionContext;
use app\common\execution\ExecutionContextStore;
use Closure;
use DateTimeImmutable;
use PeanutAdmin\Kernel\Auth\TenantContext;
use PeanutAdmin\Kernel\Auth\ValidatedTenantSession;
use PHPUnit\Framework\TestCase;
use think\App;
use think\Container;
use think\exception\HttpException;
use think\exception\RouteNotFoundException;
use think\app\MultiApp;
use think\Request;
use think\Route;
use think\Service;
use think\response\Json;

/**
 * 用锁定框架的真实路由调度证明注入与生命周期；不启动完整产品，不访问数据库。
 * 这里的合成身份仅用于测试路由顺序，不能替代生产 LoginMiddleware 的真实认证。
 */
final class A1ControllerLifecycleTest extends TestCase
{
    private Container $previousContainer;
    private App $app;
    private ExecutionContextStore $contexts;
    private string $serverRoot;

    protected function setUp(): void
    {
        $this->previousContainer = Container::getInstance();
        $this->serverRoot = dirname(__DIR__, 2);
        require_once $this->serverRoot . '/vendor/topthink/framework/src/helper.php';
        // 不加载当前产品的 provider、环境、路由缓存或 AppService，不建立数据库连接。
        $this->app = new App(sys_get_temp_dir() . '/peanut-a1-no-runtime-' . bin2hex(random_bytes(8)));
        $this->app->config->set(require $this->serverRoot . '/config/route.php', 'route');
        $this->app->config->set(require $this->serverRoot . '/config/app.php', 'app');
        $this->contexts = new ExecutionContextStore();
        $this->app->instance(App::class, $this->app);
        $this->app->instance(ExecutionContextStore::class, $this->contexts);
        $this->app->instance(CurrentExecutionContext::class, new CurrentExecutionContext($this->contexts));
        LifecycleTrace::$events = [];
    }

    protected function tearDown(): void
    {
        Container::setInstance($this->previousContainer);
        LifecycleTrace::$events = [];
    }

    public function testInheritedConstructorAndActionInjectionFollowRealMiddlewareOrder(): void
    {
        $request = $this->request('fixture/17')->withGet(['service' => 'untrusted-http-value']);
        $route = new Route($this->app);
        $route->get('fixture/:id', [ActionInjectedController::class, 'show'])
            ->middleware(AuthenticatedFixtureMiddleware::class);

        $response = $route->dispatch($request);
        self::assertSame(200, $response->getCode());
        self::assertSame(['id' => '17', 'tenant_id' => 101, 'actor_id' => 301], $response->getData());
        self::assertSame([
            'route.before', 'controller.initialize', 'controller.before',
            'action.service', 'action', 'controller.after', 'route.after',
        ], LifecycleTrace::$events);
        self::assertSame(
            BaseController::class,
            (new \ReflectionClass(ActionInjectedController::class))->getConstructor()->getDeclaringClass()->getName(),
        );
        self::assertTrue($this->contexts->isEmpty());
    }

    public function testRealWorkbenchActionUsesFrameworkMethodInjection(): void
    {
        $service = $this->createMock(WorkbenchApplicationService::class);
        $service->expects(self::once())->method('index')->willReturnCallback(
            static function (TenantContext $context): array {
                self::assertSame(101, $context->tenantId);
                return ['fixture' => 'real-action-injection'];
            },
        );
        $this->app->instance(WorkbenchApplicationService::class, $service);
        $request = $this->request('workbench/index')->withGet(['workbench' => 'untrusted-http-value']);
        $route = new Route($this->app);
        $route->get('workbench/index', [WorkbenchController::class, 'index'])
            ->middleware(AuthenticatedFixtureMiddleware::class);

        $response = $route->dispatch($request);
        self::assertSame(200, $response->getCode());
        self::assertSame('real-action-injection', $response->getData()['data']['fixture'] ?? null);
        self::assertTrue($this->contexts->isEmpty());
    }

    public function testExceptionsRestoreTheRequestContextInReverseOrder(): void
    {
        $request = $this->request('fixture-fail');
        $route = new Route($this->app);
        $route->get('fixture-fail', [ActionInjectedController::class, 'failAction'])
            ->middleware(AuthenticatedFixtureMiddleware::class);
        // 原生中间件管线会把动作异常映射为响应；finally 仍必须按逆序执行。
        $response = $route->dispatch($request);
        self::assertSame(500, $response->getCode());
        self::assertSame([
            'route.before', 'controller.initialize', 'controller.before',
            'action.service', 'action.failed', 'controller.after', 'route.after',
        ], LifecycleTrace::$events);
        self::assertTrue($this->contexts->isEmpty());
    }

    public function testUnregisteredUrlCannotFallBackToAutomaticControllerDispatch(): void
    {
        $request = $this->request('not-registered/controller-method');
        self::assertTrue($this->app->config->get('route.url_route_must'));
        $route = new Route($this->app);
        try {
            $route->dispatch($request);
            self::fail('Unregistered URLs must not reach automatic controller dispatch.');
        } catch (RouteNotFoundException) {
            self::assertSame([], LifecycleTrace::$events);
        }
        self::assertTrue($this->contexts->isEmpty());
    }

    public function testCurrentAndHistoricalModuleDirectoryNamesAreNotHttpApplications(): void
    {
        $script = $_SERVER['SCRIPT_FILENAME'] ?? null;
        // MultiApp 按 PHP 入口文件区分绑定模式；本测试模拟真实 public/index.php，而不是 phpunit 入口。
        $_SERVER['SCRIPT_FILENAME'] = $this->serverRoot . '/public/index.php';
        try {
            foreach (['modules', 'Modules'] as $directory) {
                $request = $this->request($directory . '/internal/action');
                try {
                    (new MultiApp($this->app))->handle($request, static function (): never {
                        self::fail('Internal module directories must not become public applications.');
                    });
                    self::fail('Expected an application selection denial.');
                } catch (HttpException $exception) {
                    self::assertSame(404, $exception->getStatusCode());
                }
            }
        } finally {
            if ($script === null) {
                unset($_SERVER['SCRIPT_FILENAME']);
            } else {
                $_SERVER['SCRIPT_FILENAME'] = $script;
            }
        }
    }

    public function testResolvingHookIsNotAControllerPropertyInjectionHookForCallbackRoutes(): void
    {
        $resolutions = 0;
        $this->app->resolving(ActionInjectedController::class, static function () use (&$resolutions): void {
            $resolutions++;
        });
        $request = $this->request('fixture/17');
        $route = new Route($this->app);
        $route->get('fixture/:id', [ActionInjectedController::class, 'show'])
            ->middleware(AuthenticatedFixtureMiddleware::class);
        $route->dispatch($request);
        self::assertSame(0, $resolutions, 'Callback dispatch uses invokeClass, not make/invokeAfter.');
        $this->app->make(ActionInjectedController::class, [], true);
        self::assertSame(1, $resolutions, 'The same hook runs through an explicit container make.');
    }

    public function testServiceBootSupportsMethodInjectionButControllerInitializeIsADirectCall(): void
    {
        $service = new BootInjectedService($this->app);
        $this->app->register($service);
        $this->app->bootService($service);
        self::assertSame(['service.register', 'action.service', 'service.boot'], LifecycleTrace::$events);
        self::assertInstanceOf(ActionService::class, $service->dependency);
        self::assertSame(0, (new \ReflectionMethod(BaseAdminController::class, 'initialize'))->getNumberOfParameters());
    }

    private function request(string $path): Request
    {
        $request = (new Request())->withServer([
            'REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/' . $path,
            'HTTP_HOST' => 'fixture.invalid', 'SERVER_NAME' => 'fixture.invalid',
            'SERVER_PORT' => '80', 'SCRIPT_NAME' => '/index.php',
        ])->setPathinfo($path);
        $this->app->instance(Request::class, $request);
        return $request;
    }
}

final class LifecycleTrace
{
    /** @var list<string> */
    public static array $events = [];
}

final class ActionService
{
    public function __construct()
    {
        LifecycleTrace::$events[] = 'action.service';
    }
}

/** 不声明构造函数：直接继承 BaseAdminController 的框架依赖，业务依赖放到操作方法。 */
final class ActionInjectedController extends BaseAdminController
{
    protected $middleware = [ControllerFixtureMiddleware::class];

    public function initialize(): void
    {
        parent::initialize();
        LifecycleTrace::$events[] = 'controller.initialize';
    }

    public function show(ActionService $service, string $id): Json
    {
        LifecycleTrace::$events[] = 'action';
        return json(['id' => $id, 'tenant_id' => $this->tenantAdminContext()->tenantId, 'actor_id' => $this->adminId]);
    }

    public function failAction(ActionService $service): never
    {
        LifecycleTrace::$events[] = 'action.failed';
        throw new \DomainException('FIXTURE_ACTION_FAILED');
    }
}

final class AuthenticatedFixtureMiddleware
{
    public function __construct(private readonly ExecutionContextStore $contexts) {}

    public function handle(Request $request, Closure $next): mixed
    {
        LifecycleTrace::$events[] = 'route.before';
        $tenant = TenantContext::fromValidatedSession(new ValidatedTenantSession(
            301,
            'fixture-session',
            101,
            201,
            301,
            'admin-web',
            new DateTimeImmutable('2031-01-01T00:00:00Z'),
            1,
        ), 'fixture-request');
        try {
            return $this->contexts->run(new AdminExecutionContext($tenant, 'fixture.read', [
                'id' => 301, 'tenant_id' => 101, 'account_id' => 201, 'authorization_revision' => 1,
            ]), static fn() => $next($request));
        } finally {
            LifecycleTrace::$events[] = 'route.after';
        }
    }
}

final class ControllerFixtureMiddleware
{
    public function handle(Request $request, Closure $next): mixed
    {
        LifecycleTrace::$events[] = 'controller.before';
        try {
            return $next($request);
        } finally {
            LifecycleTrace::$events[] = 'controller.after';
        }
    }
}

final class BootInjectedService extends Service
{
    public ?ActionService $dependency = null;

    public function register(): void
    {
        LifecycleTrace::$events[] = 'service.register';
    }

    public function boot(ActionService $dependency): void
    {
        $this->dependency = $dependency;
        LifecycleTrace::$events[] = 'service.boot';
    }
}
