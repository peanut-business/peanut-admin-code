<?php
declare(strict_types=1);

namespace app\adminapi\services\auth;

use app\common\exception\BusinessException;
use app\common\execution\CurrentExecutionContext;
use app\platform\http\PlatformRequest;
use PeanutAdmin\Kernel\Auth\AuthException;
use PeanutAdmin\Kernel\Host\ApplicationHostPolicy;
use PeanutAdmin\Modules\Identity\Http\TenantAuthEndpoint;
use PeanutAdmin\Modules\Identity\Http\TenantAuthResponse;
use PeanutAdmin\Kernel\Tenancy\TenantEntryBindingResolver;

final readonly class TenantSessionApplicationService
{
    public function __construct(
        private CurrentExecutionContext $executionContext,
        private TenantAuthEndpoint $tenantAuth,
        private ApplicationHostPolicy $hostPolicy,
        private TenantEntryBindingResolver $entryBindings,
    ) {}

    public function login(object $request, array $params): TenantAuthResponse
    {
        try {
            $this->hostPolicy->assertTenantAdmin($request);
            $tenantCode = $this->entryBindings->loginTenantCode(
                $request,
                TenantEntryBindingResolver::ADMIN_CLIENT,
                isset($params['tenant_code']) ? (string)$params['tenant_code'] : null,
            );
            return $this->tenantAuth->login(
                trim((string)($params['email'] ?? '')),
                (string)($params['password'] ?? ''),
                $tenantCode,
                $request->ip(),
                $request->header('User-Agent'),
                PlatformRequest::requestId($this->executionContext, $request),
            );
        } catch (AuthException|\DomainException|\InvalidArgumentException) {
            throw new BusinessException(
                'TENANT_AUTHENTICATION_REJECTED',
                401,
                'Tenant authentication was rejected.',
            );
        }
    }

    public function select(object $request, array $params): TenantAuthResponse
    {
        try {
            $this->hostPolicy->assertTenantAdmin($request);
            $this->entryBindings->assertTenantAccess(
                $request,
                TenantEntryBindingResolver::ADMIN_CLIENT,
                (int)($params['tenant_id'] ?? 0),
            );
            return $this->tenantAuth->selectTenant(
                trim((string)($params['challenge_token'] ?? '')),
                (int)($params['tenant_id'] ?? 0),
                $request->ip(),
                $request->header('User-Agent'),
                PlatformRequest::requestId($this->executionContext, $request),
            );
        } catch (AuthException|\DomainException|\InvalidArgumentException) {
            throw new BusinessException(
                'TENANT_SELECTION_REJECTED',
                403,
                'Tenant selection was rejected.',
            );
        }
    }

    public function switchChallenge(object $request): TenantAuthResponse
    {
        try {
            $this->hostPolicy->assertTenantAdmin($request);
            if ($this->entryBindings->boundTenantId(
                $request,
                TenantEntryBindingResolver::ADMIN_CLIENT,
            ) !== null) {
                throw new \DomainException('TENANT_SWITCH_BOUND_ENTRY');
            }
            return $this->tenantAuth->switchChallenge(
                PlatformRequest::bearerToken($request),
                $request->ip(),
                $request->header('User-Agent'),
                PlatformRequest::requestId($this->executionContext, $request),
            );
        } catch (AuthException|\DomainException|\InvalidArgumentException) {
            throw new BusinessException(
                'TENANT_SWITCH_REJECTED',
                403,
                'Tenant switch was rejected.',
            );
        }
    }

    public function refresh(object $request): TenantAuthResponse
    {
        try {
            $this->hostPolicy->assertTenantAdmin($request);
            return $this->tenantAuth->refresh(
                trim((string)$request->cookie($this->tenantAuth->refreshCookieName(), '')),
                $this->isTrustedBrowserOrigin($request),
                $request->ip(),
                $request->header('User-Agent'),
                PlatformRequest::requestId($this->executionContext, $request),
            );
        } catch (AuthException|\DomainException|\InvalidArgumentException) {
            throw new BusinessException(
                'TENANT_REFRESH_CREDENTIAL_INVALID',
                401,
                'Tenant refresh credential is invalid.',
            );
        }
    }

    public function logout(object $request): TenantAuthResponse
    {
        try {
            $this->hostPolicy->assertTenantAdmin($request);
            return $this->tenantAuth->logout(
                PlatformRequest::bearerToken($request),
                PlatformRequest::requestId($this->executionContext, $request),
            );
        } catch (AuthException|\DomainException|\InvalidArgumentException) {
            throw new BusinessException(
                'TENANT_SESSION_INVALID',
                401,
                'Tenant session is invalid.',
            );
        }
    }

    private function isTrustedBrowserOrigin(object $request): bool
    {
        if (strtolower(trim((string)$request->header('Sec-Fetch-Site', ''))) !== 'same-origin') {
            return false;
        }
        $origin = trim((string)$request->header('Origin', ''));
        $originHost = parse_url($origin, PHP_URL_HOST);
        if (!is_string($originHost) || $originHost === '') {
            return false;
        }
        return hash_equals(
            TenantEntryBindingResolver::normalizeHost((string)$request->host()),
            TenantEntryBindingResolver::normalizeHost($originHost),
        );
    }
}
