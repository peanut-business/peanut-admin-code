<?php

declare(strict_types=1);

namespace app\platform\http;

use app\common\execution\CurrentExecutionContext;
use app\common\http\RequestTrace;
use PeanutAdmin\Kernel\Auth\PlatformRefreshCookie;

final class PlatformRequest
{
    public static function bearerToken($request): string
    {
        $authorization = trim((string) $request->header('Authorization', ''));
        if (preg_match('/^Bearer\s+(\S+)$/iD', $authorization, $matches) !== 1) {
            return '';
        }

        return $matches[1];
    }

    public static function refreshToken($request): string
    {
        return trim((string) $request->cookie(PlatformRefreshCookie::NAME, ''));
    }

    public static function requestId(CurrentExecutionContext $executionContext, $request): string
    {
        return RequestTrace::id($executionContext, $request, 'platform');
    }

    private function __construct() {}
}
