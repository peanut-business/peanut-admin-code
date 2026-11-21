<?php

declare(strict_types=1);

use app\AppService;
use app\common\composition\CoreServiceOverrides;
use PeanutAdmin\Kernel\Identity\PasswordHasher;
use PeanutAdmin\Kernel\Identity\PasswordPolicy;
use PeanutAdmin\Modules\Identity\Auth\PlatformAuthService;
use PeanutAdmin\Modules\Identity\Auth\TenantAuthService;
use PeanutAdmin\Modules\Identity\Identity\SelfService\AccountSelfService;
use PeanutAdmin\Modules\Identity\Invitation\TenantOwnerInvitationPublicService;
use PeanutAdmin\Modules\Identity\Membership\Application\MemberAdminService;
use PeanutAdmin\Modules\Identity\Platform\Application\PlatformAccessAdminService;
use PeanutAdmin\Modules\Identity\Platform\Application\TenantOwnerAdminService;
use PeanutAdmin\Modules\Identity\Platform\Bootstrap\BootstrapService;
use think\App;

use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;

function passwordDependency(object $service, string $property): object
{
    return (new ReflectionProperty($service, $property))->getValue($service);
}

final class ApplicationTestPasswordPolicy extends PasswordPolicy
{
    public function __construct()
    {
        parent::__construct(2, 8);
    }

    public function assertValid(string $plainPassword): void
    {
        parent::assertValid($plainPassword);
        if ($plainPassword === 'denied') {
            throw new RuntimeException('APPLICATION_PASSWORD_REJECTED');
        }
    }
}

final class ApplicationTestPasswordHasher extends PasswordHasher {}

if (interface_exists(\PeanutAdmin\Modules\Identity\Contract\TenantOwnerAdminProvisioner::class)) {
    // This contract test does not exercise the unrelated database-backed tenant provisioning flow.
    final class PasswordOverrideProvisionerFixture implements \PeanutAdmin\Modules\Identity\Contract\TenantOwnerAdminProvisioner
    {
        public function provision(int $tenantId, int $accountId, int $memberId, int $coreRoleId, string $tenantCode, string $displayName): int
        {
            throw new LogicException('Provisioning must not run in the password binding contract');
        }
    }
}

final class PasswordPolicyOverrideTest extends TestCase
{
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testNativeBindingsAndInstallerUseTheConfiguredPolicy(): void
    {
        $server = (getenv('PEANUT_TEST_APPLICATION_ROOT') ?: dirname(__DIR__, 3)) . '/server';
        require_once $server . '/vendor/autoload.php';
        require_once $server . '/vendor/topthink/framework/src/helper.php';
        $source = \app\common\value\installation\ApplicationSourceIdentity::class;
        $edition = $source::hasRuntime($server) || is_file(dirname($server) . '/.peanut/application-manifest.json')
            ? $source::load($server)->applicationIdentity()['edition']
            : 'multi-tenant';
        $app = new App($server);
        // Exercise real Provider/Service/Module bindings without starting resource-dependent initializers.
        $app->config->set(['mode' => $edition], 'deployment');
        $app->config->load($server . '/config/modules.php', 'modules');
        (new AppService($app))->register();
        $policy = $app->make(PasswordPolicy::class);
        self::assertTrue($policy->minimumLength() === 6 && $policy->maximumLength() === 32, 'Core defaults lost');
        $policy->assertValid('中文');
        self::assertTrue(true, 'UTF-8 byte boundary');
        try {
            $policy->assertValid('abcde');
            throw new LogicException('Short password accepted');
        } catch (RuntimeException $exception) {
            self::assertTrue(str_contains($exception->getMessage(), 'bytes'), 'Invalid length error lost');
        }

        $app->config->set(['password' => ['minimum_length' => 64, 'maximum_length' => 128]], 'peanut');
        $app->delete(PasswordPolicy::class);
        $strong = $app->make(PasswordPolicy::class);
        self::assertTrue($strong->minimumLength() === 64, 'Native configuration ignored');
        $app->config->set(['identifier_hmac_key' => str_repeat('synthetic', 8)], 'tenant_auth');
        $app->config->set(['identifier_hmac_key' => str_repeat('synthetic', 8)], 'platform_auth');
        if (class_exists(TenantAuthService::class)) {
            // Authentication's internal hashes and existing credential verification must remain constructible.
            self::assertTrue($app->make(TenantAuthService::class) instanceof TenantAuthService, 'Strong policy broke tenant authentication');
            self::assertTrue($app->make(PlatformAuthService::class) instanceof PlatformAuthService, 'Strong policy broke platform authentication');
        }

        $app->config->set(['password' => ['minimum_length' => '12']], 'peanut');
        $app->delete(PasswordPolicy::class);
        try {
            $app->make(PasswordPolicy::class);
            throw new LogicException('Invalid config accepted');
        } catch (InvalidArgumentException) {
            self::assertTrue(true, 'Invalid configuration must fail');
        }

        $customPolicy = new ApplicationTestPasswordPolicy();
        $customHasher = new ApplicationTestPasswordHasher();
        $app = new App($server);
        $app->config->load($server . '/config/modules.php', 'modules');
        $app->config->set(['mode' => $edition], 'deployment');
        $app->config->set(['identifier_hmac_key' => str_repeat('synthetic', 8)], 'tenant_auth');
        $app->config->set(['identifier_hmac_key' => str_repeat('synthetic', 8)], 'platform_auth');
        $app->bind(PasswordPolicy::class, $customPolicy);
        $app->bind(PasswordHasher::class, $customHasher);
        (new AppService($app))->register();
        if (class_exists(PasswordOverrideProvisionerFixture::class, false)) {
            $app->instance(\PeanutAdmin\Modules\Identity\Contract\TenantOwnerAdminProvisioner::class, new PasswordOverrideProvisionerFixture());
        }
        self::assertTrue($app->make(PasswordPolicy::class) === $customPolicy, 'Composition overwrote application policy');
        self::assertTrue($app->make(PasswordHasher::class) === $customHasher, 'Composition overwrote application hasher');
        $policyController = (new ReflectionClass(\app\installation\controller\InstallationController::class))->newInstanceWithoutConstructor();
        $policyResponse = $app->invokeMethod([$policyController, 'passwordPolicy'])->getData();
        self::assertTrue($policyResponse['data'] === [
            'minimum_length' => 2,
            'maximum_length' => 8,
            'length_unit' => 'utf8_bytes',
            'require_uppercase' => false,
            'require_lowercase' => false,
            'require_digit' => false,
            'require_special' => false,
        ], 'Public limits bypassed native policy binding');

        if (class_exists(MemberAdminService::class)) {
            foreach ([MemberAdminService::class, AccountSelfService::class, PlatformAccessAdminService::class,
                TenantOwnerAdminService::class, BootstrapService::class, TenantOwnerInvitationPublicService::class] as $class) {
                $app->delete($class);
                $service = $app->make($class);
                self::assertTrue(passwordDependency($service, 'passwords') === $customHasher, $class . ' bypassed hasher binding');
                self::assertTrue(passwordDependency($service, 'passwordPolicy') === $customPolicy, $class . ' bypassed policy binding');
            }
            $validator = new \app\platform\validate\TenantOwnerInvitationValidate();
            self::assertTrue(!$validator->scene('accept')->check(['token' => str_repeat('x', 43), 'new_account_password' => 'denied']), 'Application rejection bypassed');
            self::assertTrue($validator->getError() === 'APPLICATION_PASSWORD_REJECTED', 'Application rejection was hidden');
            self::assertTrue($validator->scene('accept')->check(['token' => str_repeat('x', 43), 'new_account_password' => 'ok']), 'Application shorter policy ignored');
        }

        $environmentPath = $server . '/.env.password-policy-' . getmypid();
        $environmentFile = fopen($environmentPath, 'x');
        if ($environmentFile === false) {
            throw new RuntimeException('Cannot create isolated password contract environment');
        }
        fwrite($environmentFile, "APP_ENV=development\nDEPLOYMENT_MODE={$edition}\n");
        fclose($environmentFile);
        chmod($environmentPath, 0600);
        register_shutdown_function(static function () use ($environmentPath): void {
            if (is_file($environmentPath)) {
                unlink($environmentPath);
            }
        });
        putenv('PEANUT_SERVER_ENV_FILE=' . $environmentPath);
        require $server . '/database/install.php';
        validateInitialAdminPassword('ok');
        self::assertTrue(true, 'Installer bypassed native policy');
        try {
            validateInitialAdminPassword('denied');
            throw new LogicException('Installer accepted rejected password');
        } catch (RuntimeException $exception) {
            self::assertTrue($exception->getMessage() === 'APPLICATION_PASSWORD_REJECTED', 'Installer rejection lost');
        }

        CoreServiceOverrides::configure(['unknown.slot' => stdClass::class]);
        try {
            CoreServiceOverrides::registry();
            throw new LogicException('Unknown override accepted');
        } catch (RuntimeException $exception) {
            self::assertTrue(str_contains($exception->getMessage(), '未知'), 'Override failure not explicit');
        } finally {
            CoreServiceOverrides::configure([]);
        }
    }
}
