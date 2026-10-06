<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';
require dirname(__DIR__, 2) . '/vendor/topthink/framework/src/helper.php';

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
use PeanutAdmin\Modules\Member\Service\MemberIdentityContractService;
use think\App;

$assertions = 0;
function passwordExpect(bool $condition, string $message): void
{
    global $assertions;
    if (!$condition) {
        throw new RuntimeException($message);
    }
    $assertions++;
}

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

// This contract test does not exercise the unrelated database-backed tenant provisioning flow.
final class PasswordOverrideProvisionerFixture implements \PeanutAdmin\Modules\Identity\Contract\TenantOwnerAdminProvisioner
{
    public function provision(int $tenantId, int $accountId, int $memberId, int $coreRoleId, string $tenantCode, string $displayName): int
    {
        throw new LogicException('Provisioning must not run in the password binding contract');
    }
}

$app = new App(dirname(__DIR__, 2));
// Exercise real Provider/Service/Module bindings without starting resource-dependent initializers.
$app->config->load(dirname(__DIR__, 2) . '/config/modules.php', 'modules');
(new AppService($app))->register();
$policy = $app->make(PasswordPolicy::class);
passwordExpect($policy->minimumLength() === 6 && $policy->maximumLength() === 1024, 'Core defaults lost');
$policy->assertValid('中文');
passwordExpect(true, 'UTF-8 byte boundary');
try {
    $policy->assertValid('abcde');
    throw new LogicException('Short password accepted');
} catch (RuntimeException $exception) {
    passwordExpect(str_contains($exception->getMessage(), 'bytes'), 'Invalid length error lost');
}

$app->config->set(['password' => ['minimum_length' => 64, 'maximum_length' => 128]], 'peanut');
$app->delete(PasswordPolicy::class);
$strong = $app->make(PasswordPolicy::class);
passwordExpect($strong->minimumLength() === 64, 'Native configuration ignored');
$app->config->set(['identifier_hmac_key' => str_repeat('synthetic', 8)], 'tenant_auth');
$app->config->set(['identifier_hmac_key' => str_repeat('synthetic', 8)], 'platform_auth');
// Authentication's internal hashes and existing credential verification must remain constructible.
passwordExpect($app->make(TenantAuthService::class) instanceof TenantAuthService, 'Strong policy broke tenant authentication');
passwordExpect($app->make(PlatformAuthService::class) instanceof PlatformAuthService, 'Strong policy broke platform authentication');

$app->config->set(['password' => ['minimum_length' => '12']], 'peanut');
$app->delete(PasswordPolicy::class);
try {
    $app->make(PasswordPolicy::class);
    throw new LogicException('Invalid config accepted');
} catch (InvalidArgumentException) {
    passwordExpect(true, 'Invalid configuration must fail');
}

$customPolicy = new ApplicationTestPasswordPolicy();
$customHasher = new ApplicationTestPasswordHasher();
$app = new App(dirname(__DIR__, 2));
$app->config->load(dirname(__DIR__, 2) . '/config/modules.php', 'modules');
$app->config->set(['mode' => 'standalone'], 'deployment');
$app->config->set(['identifier_hmac_key' => str_repeat('synthetic', 8)], 'tenant_auth');
$app->config->set(['identifier_hmac_key' => str_repeat('synthetic', 8)], 'platform_auth');
$app->bind(PasswordPolicy::class, $customPolicy);
$app->bind(PasswordHasher::class, $customHasher);
(new AppService($app))->register();
$app->instance(\PeanutAdmin\Modules\Identity\Contract\TenantOwnerAdminProvisioner::class, new PasswordOverrideProvisionerFixture());
passwordExpect($app->make(PasswordPolicy::class) === $customPolicy, 'Composition overwrote application policy');
passwordExpect($app->make(PasswordHasher::class) === $customHasher, 'Composition overwrote application hasher');
$policyController = (new ReflectionClass(\app\installation\controller\InstallationController::class))->newInstanceWithoutConstructor();
$policyResponse = $app->invokeMethod([$policyController, 'passwordPolicy'])->getData();
passwordExpect($policyResponse['data'] === ['minimum_length' => 2, 'maximum_length' => 8, 'length_unit' => 'utf8_bytes'], 'Public limits bypassed native policy binding');

foreach ([MemberAdminService::class, AccountSelfService::class, PlatformAccessAdminService::class,
    TenantOwnerAdminService::class, BootstrapService::class, TenantOwnerInvitationPublicService::class,
    MemberIdentityContractService::class] as $class) {
    $app->delete($class);
    $service = $app->make($class);
    passwordExpect(passwordDependency($service, 'passwords') === $customHasher, $class . ' bypassed hasher binding');
    passwordExpect(passwordDependency($service, 'passwordPolicy') === $customPolicy, $class . ' bypassed policy binding');
}
$validator = new \app\platform\validate\TenantOwnerInvitationValidate();
passwordExpect(!$validator->scene('accept')->check(['token' => str_repeat('x', 43), 'new_account_password' => 'denied']), 'Application rejection bypassed');
passwordExpect($validator->getError() === 'APPLICATION_PASSWORD_REJECTED', 'Application rejection was hidden');
passwordExpect($validator->scene('accept')->check(['token' => str_repeat('x', 43), 'new_account_password' => 'ok']), 'Application shorter policy ignored');

$environmentPath = dirname(__DIR__, 2) . '/.env.password-policy-' . getmypid();
$environmentFile = fopen($environmentPath, 'x');
if ($environmentFile === false) {
    throw new RuntimeException('Cannot create isolated password contract environment');
}
fwrite($environmentFile, "APP_ENV=development\nDEPLOYMENT_MODE=standalone\n");
fclose($environmentFile);
chmod($environmentPath, 0600);
register_shutdown_function(static function () use ($environmentPath): void {
    if (is_file($environmentPath)) {
        unlink($environmentPath);
    }
});
putenv('PEANUT_SERVER_ENV_FILE=' . $environmentPath);
require dirname(__DIR__, 2) . '/database/install.php';
validateInitialAdminPassword('ok');
passwordExpect(true, 'Installer bypassed native policy');
try {
    validateInitialAdminPassword('denied');
    throw new LogicException('Installer accepted rejected password');
} catch (RuntimeException $exception) {
    passwordExpect($exception->getMessage() === 'APPLICATION_PASSWORD_REJECTED', 'Installer rejection lost');
}

CoreServiceOverrides::configure(['unknown.slot' => stdClass::class]);
try {
    CoreServiceOverrides::registry();
    throw new LogicException('Unknown override accepted');
} catch (RuntimeException $exception) {
    passwordExpect(str_contains($exception->getMessage(), '未知'), 'Override failure not explicit');
} finally {
    CoreServiceOverrides::configure([]);
}
printf("PASSWORD-POLICY-OVERRIDE-001 passed: %d assertions; no database operations\n", $assertions);
