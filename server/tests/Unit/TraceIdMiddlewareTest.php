<?php

declare(strict_types=1);

use app\common\execution\CurrentExecutionContext;
use app\common\execution\ExecutionContextStore;
use app\common\http\RequestTrace;
use app\common\http\middleware\TraceIdMiddleware;
use PHPUnit\Framework\TestCase;
use think\Request;
use think\Response;

final class TraceIdMiddlewareTest extends TestCase
{
    public function testResponseCarriesTheSameValidatedOrGeneratedRequestId(): void
    {
        $context = new CurrentExecutionContext(new ExecutionContextStore());
        $middleware = new TraceIdMiddleware($context);
        foreach (['client-123', "bad id\n"] as $header) {
            $request = (new Request())->withHeader(['X-Request-Id' => $header]);
            $seen = null;
            $response = $middleware->handle($request, function ($request) use ($context, &$seen) {
                $seen = RequestTrace::id($context, $request);
                return Response::create('ok');
            });
            self::assertSame($seen, $response->getHeader('X-Request-Id'));
            self::assertMatchesRegularExpression('/^[A-Za-z0-9][A-Za-z0-9._:-]{0,63}$/D', $seen);
        }
    }
}
