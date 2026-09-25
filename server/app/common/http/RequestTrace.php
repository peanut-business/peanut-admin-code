<?php

declare(strict_types=1);

namespace app\common\http;

use app\common\execution\CurrentExecutionContext;

/** One stable request ID without mutating the framework Request object. */
final class RequestTrace
{
    /** @var \WeakMap<object,string>|null */
    private static ?\WeakMap $requestIds = null;

    public static function id(
        CurrentExecutionContext $executionContext,
        object $request,
        string $prefix = 'http',
    ): string {
        $candidate = method_exists($request, 'header')
            ? trim((string) $request->header('X-Request-Id', ''))
            : '';
        if (preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]{0,63}$/D', $candidate) === 1) {
            return self::remember($request, $candidate);
        }

        try {
            $current = $executionContext->current();
        } catch (\Throwable) {
            $current = null;
        }
        if ($current !== null) {
            return self::remember($request, $current->requestId());
        }

        $known = self::map()[$request] ?? null;
        if (is_string($known)) {
            return $known;
        }

        $prefix = strtolower(trim($prefix));
        if (preg_match('/^[a-z][a-z0-9-]{0,15}$/D', $prefix) !== 1) {
            throw new \InvalidArgumentException('REQUEST_TRACE_PREFIX_INVALID');
        }
        return self::remember($request, $prefix . '-' . bin2hex(random_bytes(16)));
    }

    /** @return \WeakMap<object,string> */
    private static function map(): \WeakMap
    {
        return self::$requestIds ??= new \WeakMap();
    }

    private static function remember(object $request, string $requestId): string
    {
        self::map()[$request] = $requestId;
        return $requestId;
    }

    private function __construct() {}
}
