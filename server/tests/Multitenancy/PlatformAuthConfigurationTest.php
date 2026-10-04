<?php

declare(strict_types=1);

/**
 * D04：验证平台认证装配的密钥合同，同时确认独立应用的租户认证不被该配置阻塞。
 * 该测试读取组合根的实际实现，避免只验证一个脱离装配的假服务。
 */

$platformAuthAssertionCount = 0;

function platformAuthConfigurationExpect(bool $condition, string $message): void
{
    global $platformAuthAssertionCount;
    $platformAuthAssertionCount++;
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

require dirname(__DIR__, 2) . '/vendor/autoload.php';

/** @return object|Throwable */
function platformAuthResolve(string $platformKey, string $tenantKey = '', bool $platformKeyPresent = true)
{
    $app = new \think\App(dirname(__DIR__, 2));
    $app->config->set($platformKeyPresent ? ['identifier_hmac_key' => $platformKey] : [], 'platform_auth');
    $app->config->set(['identifier_hmac_key' => $tenantKey], 'tenant_auth');
    $service = new \app\AppService($app);
    (new ReflectionMethod(\app\AppService::class, 'registerPlatform'))->invoke($service);
    try {
        return $app->make(\PeanutAdmin\Modules\Identity\Auth\PlatformAuthService::class);
    } catch (Throwable $exception) {
        return $exception;
    }
}

foreach ([['', false], ['', true], ['   ', true], [str_repeat('s', 31), true]] as [$invalidKey, $present]) {
    $exception = platformAuthResolve($invalidKey, '', $present);
    platformAuthConfigurationExpect(
        $exception instanceof DomainException
            && $exception->getMessage() === 'PLATFORM_AUTH_CONFIGURATION_UNAVAILABLE',
        'platform auth must reject missing, whitespace, and short HMAC keys',
    );
}
$platformAuth = platformAuthResolve(str_repeat('v', 32));
platformAuthConfigurationExpect(
    $platformAuth instanceof \PeanutAdmin\Modules\Identity\Auth\PlatformAuthService,
    'platform auth must accept a valid 32-byte HMAC key',
);
$keyProperty = new ReflectionProperty(\PeanutAdmin\Modules\Identity\Auth\PlatformAuthService::class, 'identifierHmacKey');
$keyProperty->setAccessible(true);
platformAuthConfigurationExpect(
    $keyProperty->getValue($platformAuth) === str_repeat('v', 32),
    'platform auth must retain the legal HMAC key unchanged',
);

// 平台能力未启用时，独立应用的租户登录只依赖自己的认证密钥。
$standalone = new \think\App(dirname(__DIR__, 2));
$standalone->config->set(['identifier_hmac_key' => ''], 'platform_auth');
$standalone->config->set(['identifier_hmac_key' => str_repeat('t', 32)], 'tenant_auth');
$standaloneService = new \app\AppService($standalone);
(new ReflectionMethod(\app\AppService::class, 'registerAuthentication'))->invoke($standaloneService);
(new ReflectionMethod(\app\AppService::class, 'registerPlatform'))->invoke($standaloneService);
$standalone->make(\PeanutAdmin\Modules\Identity\Auth\TenantAuthService::class);

echo "D04 platform auth configuration passed; assertions={$platformAuthAssertionCount}; scenarios=7\n";
