<?php

declare(strict_types=1);

namespace app\common\http\middleware;

use app\common\execution\CurrentExecutionContext;
use app\common\http\RequestTrace;
use app\common\services\audit\AuditContractHost;
use PeanutAdmin\Kernel\Audit\AuditOutcome;
use think\facade\Db;

/** Fails closed for every HTTP mutation while an active maintenance window is in effect. */
final class MaintenanceWriteGateMiddleware
{
    private const WRITE_METHODS = ['POST', 'PUT', 'PATCH', 'DELETE'];

    public function __construct(
        private readonly AuditContractHost $audit,
        private readonly CurrentExecutionContext $executionContext,
    ) {}

    public function handle($request, \Closure $next)
    {
        if (!in_array(strtoupper((string) $request->method()), self::WRITE_METHODS, true)
            || $this->isMaintenanceControlRequest($request)
        ) {
            return $next($request);
        }

        $requestId = RequestTrace::id($this->executionContext, $request, 'maintenance');
        try {
            $window = $this->activeWindow();
            if ($window !== null) {
                $this->audit->recordPlatform(
                    'platform.maintenance.write-blocked',
                    'maintenance.write',
                    $requestId,
                    null,
                    null,
                    [
                        'maintenance_key' => (string) $window['maintenance_key'],
                        'reason_key' => (string) $window['reason_key'],
                        'request_method' => strtoupper((string) $request->method()),
                        'request_path' => trim((string) $request->pathinfo(), '/'),
                    ],
                    AuditOutcome::Denied,
                    'MAINTENANCE_WRITE_BLOCKED',
                );
            }
        } catch (\Throwable) {
            throw \app\common\http\ApiProblem::fromEnvelope(
                '系统维护状态不可用，写入操作已拒绝。',
                ['error_code' => 'MAINTENANCE_GATE_UNAVAILABLE'],
                50300,
            )->withHeaders(['Cache-Control' => 'no-store', 'X-Request-Id' => $requestId]);
        }

        if ($window === null) {
            return $next($request);
        }

        throw \app\common\http\ApiProblem::fromEnvelope(
            '系统维护中，暂不支持写入操作。',
            ['error_code' => 'MAINTENANCE_WRITE_BLOCKED'],
            50300,
        )->withHeaders(['Cache-Control' => 'no-store', 'X-Request-Id' => $requestId]);
    }

    /** @return array{maintenance_key:string,reason_key:string}|null */
    private function activeWindow(): ?array
    {
        $window = Db::name('ops_maintenance_window')->whereIn('state', ['scheduled', 'active'])
            ->where('starts_at', '<=', Db::raw('UTC_TIMESTAMP(3)'))
            ->where('ends_at', '>', Db::raw('UTC_TIMESTAMP(3)'))
            ->field('maintenance_key,reason_key')->order('id', 'desc')->find();
        return is_array($window) ? $window : null;
    }

    private function isMaintenanceControlRequest($request): bool
    {
        $method = strtoupper((string) $request->method());
        $path = trim((string) $request->pathinfo(), '/');
        return ($method === 'PUT' && $path === 'v1/ops/maintenance')
            || ($method === 'POST'
                && preg_match('#^v1/ops/maintenance/maintenance_[a-f0-9]{32}/close$#D', $path) === 1);
    }
}
