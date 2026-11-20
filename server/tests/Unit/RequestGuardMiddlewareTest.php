<?php

declare(strict_types=1);

use app\common\execution\AdminExecutionContext;
use app\common\execution\CurrentExecutionContext;
use app\common\execution\ExecutionContextStore;
use app\common\http\ApiProblem;
use app\common\http\middleware\RequestGuardMiddleware;
use PeanutAdmin\Kernel\Auth\TenantContext;
use PeanutAdmin\Kernel\Auth\ValidatedTenantSession;
use PHPUnit\Framework\TestCase;
use think\App;
use think\Container;
use think\Request;
use think\Response;

final class RequestGuardMiddlewareTest extends TestCase
{
    public function testExplicitPoliciesProtectAuthenticatedRouteAndRejectRepeat(): void
    {
        require_once dirname(__DIR__, 2) . '/vendor/topthink/framework/src/helper.php';
        $dir = sys_get_temp_dir() . '/peanut-middleware-' . bin2hex(random_bytes(8));
        mkdir($dir);
        $previous = Container::getInstance();
        try {
            $app = new App($dir);
            Container::setInstance($app);
            $app->config->set(['default' => 'file', 'stores' => ['file' => ['type' => 'File', 'path' => $dir . '/cache']]], 'cache');
            $app->config->set(['policies' => [
                'rate' => ['cache_store' => 'file', 'lock_store' => 'file', 'lock_path' => $dir . '/locks', 'limit' => 1, 'interval' => '1 minute'],
                'repeat' => ['cache_store' => 'file', 'lock_store' => 'file', 'lock_path' => $dir . '/locks', 'repeat_interval' => '1 minute'],
            ]], 'request_guard');
            $store = new ExecutionContextStore();
            $current = new CurrentExecutionContext($store);
            $app->instance(CurrentExecutionContext::class, $current);
            $app->bind(\think\exception\Handle::class, \app\ExceptionHandle::class);
            $middleware = new RequestGuardMiddleware($current);
            $session = new ValidatedTenantSession(1, 'session', 1, 2, 3, 'web', new DateTimeImmutable(), 1, 1);
            $context = new AdminExecutionContext(TenantContext::fromValidatedSession($session, 'request-1'), 'test');
            $request = (new Request())->setMethod('POST')->setPathinfo('guarded')->withHeader(['X-Submit-Token' => 'token']);
            $next = static fn() => Response::create('ok');
            $route = new \think\Route($app);
            $route->rule('guarded', static fn() => 'ok', 'POST')->middleware(RequestGuardMiddleware::class, 'route');
            $route->rule('repeat', static fn() => 'ok', 'POST')->middleware(RequestGuardMiddleware::class, 'route-repeat');
            $app->instance(RequestGuardMiddleware::class, $middleware);
            $app->config->set(['policies' => [
                'route' => ['cache_store' => 'file', 'lock_store' => 'file', 'lock_path' => $dir . '/locks', 'limit' => 1, 'interval' => '1 minute'],
                'route-repeat' => ['cache_store' => 'file', 'lock_store' => 'file', 'lock_path' => $dir . '/locks', 'repeat_interval' => '1 minute'],
            ]], 'request_guard');
            $store->run($context, function () use ($route, $request, $app): void {
                self::assertSame('ok', $route->dispatch($request)->getContent());
                self::assertSame(429, $route->dispatch($request)->getCode());
                $app->instance(\think\Middleware::class, new \think\Middleware($app));
                $request->setPathinfo('repeat');
                self::assertSame('ok', $route->dispatch($request)->getContent());
                self::assertSame(409, $route->dispatch($request)->getCode());
                $request->setPathinfo('guarded');
            });
            $app->config->set(['policies' => [
                'rate' => ['cache_store' => 'file', 'lock_store' => 'file', 'lock_path' => $dir . '/locks', 'limit' => 1, 'interval' => '1 minute'],
                'repeat' => ['cache_store' => 'file', 'lock_store' => 'file', 'lock_path' => $dir . '/locks', 'repeat_interval' => '1 minute'],
            ]], 'request_guard');
            $store->run($context, function () use ($middleware, $request, $next): void {
                self::assertSame('ok', $middleware->handle($request, $next, 'rate')->getContent());
                try {
                    $middleware->handle($request, $next, 'rate');
                    self::fail('Expected 429');
                } catch (ApiProblem $error) { self::assertSame(429, $error->httpStatus); }
                self::assertSame('ok', $middleware->handle($request, $next, 'repeat')->getContent());
                try {
                    $middleware->handle($request, $next, 'repeat');
                    self::fail('Expected 409');
                } catch (ApiProblem $error) { self::assertSame(409, $error->httpStatus); }
            });
        } finally {
            Container::setInstance($previous);
            $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
            foreach ($files as $file) $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
            rmdir($dir);
        }
    }
}
