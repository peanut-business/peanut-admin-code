<?php

declare(strict_types=1);

namespace tests\Unit\ControllerDependencyResolution;

require_once dirname(__DIR__, 2) . '/vendor/topthink/framework/src/helper.php';

use app\BaseController;
use app\common\execution\AdminExecutionContext;
use app\common\execution\CurrentExecutionContext;
use app\common\execution\ExecutionContextStore;
use app\common\http\PageResult;
use PeanutAdmin\Modules\Article\Contract\ArticleAdministration;
use PeanutAdmin\Modules\Article\Controller\ArticleController;
use DateTimeImmutable;
use PeanutAdmin\Kernel\Auth\TenantContext;
use PeanutAdmin\Kernel\Auth\ValidatedTenantSession;
use PHPUnit\Framework\TestCase;
use think\App;
use think\Container;
use think\Request;

/** 真实 CrudTrait Controller 的 xxClass 只读属性与当前 App 绑定回归；不连接数据库。 */
final class ControllerDependencyResolutionTest extends TestCase
{
    private Container $previousContainer;
    private App $app;
    private ExecutionContextStore $contexts;

    protected function setUp(): void
    {
        $this->previousContainer = Container::getInstance();
        $this->app = new App(sys_get_temp_dir() . '/peanut-controller-getter-' . bin2hex(random_bytes(8)));
        $this->contexts = new ExecutionContextStore();
        $request = (new Request())->withGet([]);
        $this->app->instance(App::class, $this->app);
        $this->app->instance(Request::class, $request);
        $this->app->instance(ExecutionContextStore::class, $this->contexts);
        $this->app->instance(CurrentExecutionContext::class, new CurrentExecutionContext($this->contexts));
    }

    protected function tearDown(): void
    {
        Container::setInstance($this->previousContainer);
    }

    public function testRealCrudControllerUsesTheCurrentAppsReplaceableInterfaceBinding(): void
    {
        $first = $this->createMock(ArticleAdministration::class);
        $first->expects(self::once())->method('lists')->with(
            self::isInstanceOf(TenantContext::class),
            [],
        )->willReturn(
            new PageResult([['source' => 'first']], 1, 1, 20),
        );
        $second = $this->createMock(ArticleAdministration::class);
        $second->expects(self::once())->method('lists')->with(
            self::isInstanceOf(TenantContext::class),
            [],
        )->willReturn(
            new PageResult([['source' => 'second']], 1, 1, 20),
        );
        $this->app->instance(ArticleAdministration::class, $first);

        $constructor = (new \ReflectionClass(ArticleController::class))->getConstructor();
        self::assertSame(BaseController::class, $constructor?->getDeclaringClass()->getName());
        self::assertSame([App::class], array_map(
            static fn(\ReflectionParameter $parameter): ?string => $parameter->getType()?->getName(),
            $constructor?->getParameters() ?? [],
        ));
        $declaration = (new \ReflectionClass(ArticleController::class))->getProperty('crudClass');
        self::assertTrue($declaration->isProtected());
        self::assertFalse($declaration->isStatic());
        self::assertSame('string', $declaration->getType()?->getName());
        self::assertSame(ArticleAdministration::class, $declaration->getDefaultValue());

        $responses = $this->contexts->run($this->adminContext(), function () use ($second): array {
            $controller = new ArticleController($this->app);
            $firstResponse = $controller->lists();
            $this->app->instance(ArticleAdministration::class, $second);
            $secondResponse = $controller->lists();
            return [$firstResponse->getData(), $secondResponse->getData()];
        });

        self::assertSame('first', $responses[0]['data']['lists'][0]['source'] ?? null);
        self::assertSame('second', $responses[1]['data']['lists'][0]['source'] ?? null);
        self::assertTrue($this->contexts->isEmpty());
    }

    private function adminContext(): AdminExecutionContext
    {
        $tenant = TenantContext::fromValidatedSession(new ValidatedTenantSession(
            301,
            'controller-getter-session',
            101,
            201,
            301,
            'admin-web',
            new DateTimeImmutable('2031-01-01T00:00:00Z'),
            1,
        ), 'controller-getter-request');
        return new AdminExecutionContext($tenant, 'article.lists', [
            'id' => 301,
            'tenant_id' => 101,
            'account_id' => 201,
        ]);
    }
}
