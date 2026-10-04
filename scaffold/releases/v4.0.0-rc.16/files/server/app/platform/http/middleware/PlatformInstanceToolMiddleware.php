<?php

declare(strict_types=1);

namespace app\platform\http\middleware;

use app\common\validation\instance\InstanceToolAccessGuard;
use app\common\http\JsonResponseFactory;
use think\facade\Config;

/** Environment/deployment gate applied after Platform authentication and exact permission checks. */
final class PlatformInstanceToolMiddleware
{
    public function handle($request, \Closure $next)
    {
        if (strtolower(trim((string) Config::get('peanut.environment', ''))) !== 'development'
            || !app()->isDebug()
            || !InstanceToolAccessGuard::fromConfiguredValue(Config::get('deployment.mode'))->allows()) {
            throw \app\common\http\ApiProblem::fromEnvelope(
                'Runtime Module mutation is disabled.',
                ['error_code' => 'MODULE_RUNTIME_MUTATION_DISABLED'],
                40300,
            );
        }
        return $next($request);
    }
}
