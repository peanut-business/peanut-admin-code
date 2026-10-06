<?php

declare(strict_types=1);

namespace tests\Unit;

use app\common\http\ApiProblemMapper;
use app\common\http\SessionRefreshCookie;
use app\platform\exception\PlatformRefreshCredentialException;
use app\platform\http\PlatformRequest;
use app\platform\services\PlatformOperatorSessionService;
use DateTimeImmutable;
use PeanutAdmin\Kernel\Auth\AuthException;
use PeanutAdmin\Kernel\Auth\PlatformRefreshCookie;
use PeanutAdmin\Kernel\Auth\RawToken;
use PeanutAdmin\Kernel\Auth\SystemClock;
use PeanutAdmin\Kernel\Auth\TenantContext;
use PeanutAdmin\Kernel\Auth\TokenIssuer;
use PeanutAdmin\Kernel\Auth\ValidatedTenantSession;
use PeanutAdmin\Kernel\Authorization\RevisionPermissionCache;
use PeanutAdmin\Kernel\Identity\PasswordHasher;
use PeanutAdmin\Kernel\Platform\Authorization\PlatformAuthorizationEvaluator;
use PeanutAdmin\Kernel\Platform\Authorization\PlatformAuthorizationRepository;
use PeanutAdmin\Modules\Identity\Auth\PlatformAuthRepository;
use PeanutAdmin\Modules\Identity\Auth\PlatformAuthService;
use PeanutAdmin\Modules\Identity\Auth\TenantAuthentication;
use PeanutAdmin\Modules\Identity\Auth\TenantAuthRepository;
use PeanutAdmin\Modules\Identity\Auth\TenantAuthService;
use PeanutAdmin\Modules\Identity\Auth\TenantTokenPair;
use PeanutAdmin\Modules\Identity\Http\TenantAuthEndpoint;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use think\Cookie;
use think\Request;
use think\response\Json;

/** Native response Cookie queue contracts with synthetic credentials; no browser or database. */
final class SessionRefreshCookieTest extends TestCase
{
    private function request(array $cookies, string $accessToken = ''): object
    {
        return new class ($cookies, $accessToken) {
            public function __construct(private array $cookies, private string $accessToken) {}
            public function cookie(string $name = '', mixed $default = null): mixed
            {
                return $name === '' ? $this->cookies : ($this->cookies[$name] ?? $default);
            }
            public function header(string $name, string $default = ''): string
            {
                return $name === 'Authorization' && $this->accessToken !== '' ? 'Bearer ' . $this->accessToken : $default;
            }
        };
    }

    private function queued(array $cookies): array
    {
        $response = new Json(new Cookie(new Request()), []);
        SessionRefreshCookie::apply($response, $cookies);
        return $response->getCookie()->getCookie();
    }

    public function testNamesBindTheExactAccessTokenAndKeepAudienceAndClientIsolation(): void
    {
        $tenantBase = '__Host-pa_tenant_refresh_admin-web';
        $first = SessionRefreshCookie::name($tenantBase, 'pa_tat_first');
        self::assertSame($tenantBase . '_' . hash('sha256', 'pa_tat_first'), $first);
        self::assertNotSame($first, SessionRefreshCookie::name($tenantBase, 'pa_tat_second'));
        self::assertNotSame($first, SessionRefreshCookie::name('__Host-pa_tenant_refresh_admin-pc', 'pa_tat_first'));
        self::assertNotSame($first, SessionRefreshCookie::name(PlatformRefreshCookie::NAME, 'pa_tat_first'));
        self::assertStringNotContainsString('pa_tat_first', $first);
    }

    public function testMissingAccessCredentialCannotSelectACookie(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('SESSION_COOKIE_ACCESS_REQUIRED');
        SessionRefreshCookie::name(PlatformRefreshCookie::NAME, '');
    }

    public function testObservedCleanupSelectsOnlyIncomingAccessBoundCookiesForThisClient(): void
    {
        $base = '__Host-pa_tenant_refresh_admin-web';
        $old = SessionRefreshCookie::name($base, 'old');
        $foreign = SessionRefreshCookie::name('__Host-pa_tenant_refresh_admin-pc', 'old');
        self::assertSame([$old], SessionRefreshCookie::observedNames($this->request([
            $old => 'refresh', $foreign => 'foreign', $base => 'legacy', $base . '_invalid' => 'invalid',
        ]), $base));
    }

    public function testPlatformMissingBearerOrAnotherSelectorsCookieFailsBeforePersistence(): void
    {
        $base = PlatformRefreshCookie::NAME;
        $cookies = [SessionRefreshCookie::name($base, 'pa_pat_other') => 'pa_prt_other', $base => 'pa_prt_legacy'];
        $repository = $this->createMock(PlatformAuthRepository::class);
        $repository->expects(self::never())->method('sessionByTokenHash');
        $permissions = $this->createStub(PlatformAuthorizationRepository::class);
        $sessions = new PlatformOperatorSessionService(
            new PlatformAuthService($repository, new PasswordHasher(), new SystemClock(), new TokenIssuer(), str_repeat('h', 32)),
            new PlatformAuthorizationEvaluator($permissions, new RevisionPermissionCache()),
            $permissions,
        );
        foreach (['', 'pa_pat_current'] as $accessToken) {
            $refresh = PlatformRequest::refreshToken($this->request($cookies, $accessToken));
            self::assertSame('', $refresh);
            try {
                $sessions->refresh($refresh, '127.0.0.1', null, 'cookie-contract');
                self::fail('A missing selected credential must be rejected.');
            } catch (PlatformRefreshCredentialException $error) {
                self::assertInstanceOf(AuthException::class, $error->getPrevious());
            }
        }
        $current = SessionRefreshCookie::name($base, 'pa_pat_current');
        self::assertSame('pa_prt_current', PlatformRequest::refreshToken($this->request([$current => 'pa_prt_current', ...$cookies], 'pa_pat_current')));
    }

    public function testLateRefreshAndLogoutCannotOverwriteOrDeleteANewerLoginCookie(): void
    {
        $base = PlatformRefreshCookie::NAME;
        $old = SessionRefreshCookie::name($base, 'old-access');
        $rotated = SessionRefreshCookie::name($base, 'rotated-access');
        $new = SessionRefreshCookie::name($base, 'new-login-access');
        $loginSnapshot = SessionRefreshCookie::observedNames($this->request([$old => 'old-refresh']), $base);
        $login = $this->queued([...SessionRefreshCookie::clear($loginSnapshot), $new => 'new-login-refresh']);
        $lateRefresh = $this->queued([$old => null, $rotated => 'rotated-refresh']);
        $lateLogout = $this->queued([$old => null]);
        self::assertSame([$old, $new], array_keys($login));
        self::assertSame([$old, $rotated], array_keys($lateRefresh));
        self::assertSame([$old], array_keys($lateLogout));
        self::assertArrayNotHasKey($new, $lateRefresh);
        self::assertArrayNotHasKey($new, $lateLogout);
        $jar = [];
        foreach ([$login, $lateRefresh, $lateLogout] as $queued) {
            foreach ($queued as $name => [$value, $expires, $options]) {
                self::assertSame('/', $options['path']);
                self::assertSame('', $options['domain']);
                self::assertTrue($options['secure']);
                self::assertTrue($options['httponly']);
                self::assertSame('Lax', $options['samesite']);
                if ($expires < time()) {
                    unset($jar[$name]);
                } else {
                    $jar[$name] = $value;
                }
            }
        }
        self::assertSame('new-login-refresh', $jar[$new]);
        self::assertSame('rotated-refresh', $jar[$rotated]);
        self::assertArrayNotHasKey($old, $jar);
    }

    public function testTenantEndpointUsesItsRealClientAndStructuredNativeCookieMutations(): void
    {
        $auth = new TenantAuthService($this->createStub(TenantAuthRepository::class), new PasswordHasher(), new SystemClock(), new TokenIssuer(), str_repeat('t', 32));
        $endpoint = new TenantAuthEndpoint($auth);
        $old = $endpoint->refreshCookieName('pa_tat_old');
        $now = new DateTimeImmutable();
        $context = TenantContext::fromValidatedSession(new ValidatedTenantSession(1, 'synthetic', 11, 21, 31, $auth->client()->key, $now, 1), 'cookie-contract');
        $authentication = new TenantAuthentication(new TenantTokenPair(new RawToken('pa_tat_new'), new RawToken('pa_trt_new'), $now, $now), $context);
        $response = (new ReflectionMethod($endpoint, 'authenticated'))->invoke($endpoint, $authentication, 'cookie-contract', [$old]);
        self::assertSame([], $response->headers);
        self::assertSame([$old => null, $endpoint->refreshCookieName('pa_tat_new') => 'pa_trt_new'], $response->cookies);
        self::assertSame('pa_tat_new', $response->body['data']['access_token']);
        self::assertCount(2, $this->queued($response->cookies));
    }

    public function testRefreshFailureMappingDoesNotClearAnyBrowserSessionCookie(): void
    {
        $problem = (new ApiProblemMapper())->map(new PlatformRefreshCredentialException(new AuthException('AUTH_TOKEN_INVALID', 401)));
        self::assertNotNull($problem);
        self::assertSame(40100, $problem->responseCode);
        self::assertSame([], $problem->headers);
    }

    public function testTenantExpectedAccessAndOriginChecksStillRejectBeforePersistence(): void
    {
        $repository = $this->createMock(TenantAuthRepository::class);
        $repository->expects(self::never())->method('sessionByTokenHash');
        $endpoint = new TenantAuthEndpoint(new TenantAuthService($repository, new PasswordHasher(), new SystemClock(), new TokenIssuer(), str_repeat('t', 32)));
        foreach ([['', true], ['pa_tat_selected', false]] as [$accessToken, $trustedOrigin]) {
            try {
                $endpoint->refresh('pa_trt_refresh', $accessToken, $trustedOrigin, '127.0.0.1', null, 'cookie-contract');
                self::fail('Invalid expected access or origin must not refresh a session.');
            } catch (AuthException $error) {
                self::assertSame('AUTH_TOKEN_INVALID', $error->errorCode);
            }
        }
    }
}
