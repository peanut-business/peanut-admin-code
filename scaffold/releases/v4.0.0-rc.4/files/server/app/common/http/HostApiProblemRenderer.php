<?php

declare(strict_types=1);

namespace app\common\http;

use app\common\execution\CurrentExecutionContext;
use app\common\http\JsonResponseFactory;
use app\common\infrastructure\runtime\OperationalLog;
use think\App;
use think\Response;

/** Renders one stable JSON error envelope for each real HTTP Application. */
final readonly class HostApiProblemRenderer
{
    private const FALLBACKS = [
        'adminapi' => ['ADMINAPI_UNEXPECTED_FAILURE', '服务暂时不可用'],
        'api' => ['API_UNEXPECTED_FAILURE', '服务暂时不可用'],
        'platform' => ['PLATFORM_UNEXPECTED_FAILURE', 'Platform request failed.'],
        'installation' => ['INSTALLATION_UNEXPECTED_FAILURE', '安装服务暂时不可用。'],
    ];

    public function __construct(
        private App $app,
        private CurrentExecutionContext $executionContext,
        private ApiProblemMapper $problems,
    ) {}

    public function render($request, \Throwable $exception): ?Response
    {
        $application = $this->app->http->getName();
        $problem = $this->problems->map($exception);
        if (!$problem instanceof ApiProblem) {
            $fallback = self::FALLBACKS[$application] ?? null;
            if ($fallback === null) {
                return null;
            }
            $problem = new ApiProblem($fallback[0], 500, $fallback[1]);
        }

        $requestId = RequestTrace::id($this->executionContext, $request, $application !== '' ? $application : 'http');
        OperationalLog::warning($this->executionContext, 'api_problem', [
            'application' => $application !== '' ? $application : 'unknown',
            'method' => $request->method(),
            'path' => '/' . ltrim($request->pathinfo(), '/'),
            'error_code' => $problem->errorCode,
            'api_code' => $problem->apiCode(),
            'request_id' => $requestId,
        ]);

        return JsonResponseFactory::response(
            $problem->apiCode(),
            $problem->getMessage(),
            $problem->data(),
            $problem->httpStatus,
        )->header(['X-Request-Id' => $requestId] + $problem->headers);
    }
}
