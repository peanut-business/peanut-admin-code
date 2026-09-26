<?php

declare(strict_types=1);

namespace tests\Unit;

use app\common\execution\CurrentExecutionContext;
use app\common\execution\ExecutionContextStore;
use app\common\execution\SystemExecutionContext;
use app\platform\services\ApplicationTenantBootstrapService;
use DomainException;
use PDO;
use PeanutAdmin\Kernel\Context\TenantSystemContext;
use PeanutAdmin\Modules\Identity\Contract\AdminDirectoryQuery;
use PeanutAdmin\Modules\Integration\Contract\ExternalChannelBindingStore;
use PeanutAdmin\Modules\Integration\Contract\ExternalIntegrationBootstrapCommands;
use PeanutAdmin\Modules\Integration\Infrastructure\ThinkPhpExternalTenantBindingRepository;
use PeanutAdmin\Modules\Integration\ModuleProvider;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;
use ReflectionProperty;
use think\App;

/** 使用真实 ORM、合成租户与原生绑定存储；不代表 MySQL 并发、完整安装或 HTTP 验收。 */
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class IntegrationBootstrapAuthorizationTest extends TestCase
{
    private PDO $database;
    private App $application;
    private ExecutionContextStore $contexts;
    private CurrentExecutionContext $current;
    private AdminDirectoryQuery $directory;
    private ExternalChannelBindingStore $bindings;
    private ExternalIntegrationBootstrapCommands $commands;

    protected function setUp(): void
    {
        $root = dirname(__DIR__, 3);
        $temporaryRoot = $root . '/.local/tmp/integration-bootstrap-authorization-tests';
        if (!is_dir($temporaryRoot) && !mkdir($temporaryRoot, 0700, true) && !is_dir($temporaryRoot)) {
            throw new \RuntimeException('INTEGRATION_BOOTSTRAP_TEST_DIRECTORY_UNAVAILABLE');
        }
        $temporary = realpath($temporaryRoot);
        self::assertIsString($temporary);
        self::assertStringStartsWith($root . '/.local/tmp/', $temporary);
        require_once $root . '/server/vendor/topthink/framework/src/helper.php';
        $this->application = new App($temporary . '/case-' . bin2hex(random_bytes(6)));
        $this->database = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        \ThinkPhpTestConnection::fromPdo($this->database);
        $this->database->exec(<<<'SQL'
            CREATE TABLE pa_tenant (id INTEGER PRIMARY KEY, code TEXT, status TEXT);
            CREATE TABLE pa_external_channel_binding (id INTEGER PRIMARY KEY AUTOINCREMENT, tenant_id INTEGER, provider TEXT, callback_key TEXT, identity_hash TEXT, identity_hint TEXT, config_json TEXT, status INTEGER, create_time INTEGER, update_time INTEGER, UNIQUE(tenant_id, provider));
            INSERT INTO pa_tenant VALUES (1, 'alpha', 'active'), (2, 'beta', 'provisioning');
            SQL);
        $this->contexts = new ExecutionContextStore();
        $this->current = new CurrentExecutionContext($this->contexts);
        $this->directory = new AdminDirectoryQuery($this->current);
        $this->bindings = new ThinkPhpExternalTenantBindingRepository();
        $this->commands = new ExternalIntegrationBootstrapCommands($this->bindings, $this->current, $this->directory);
    }

    private function system(int $tenantId = 1, string $actor = 'platform.tenant-bootstrap', string $operation = 'integration.bootstrap-bindings'): SystemExecutionContext
    {
        return new SystemExecutionContext(new TenantSystemContext($tenantId, $actor, $operation, 'synthetic-integration-bootstrap'));
    }

    private function seed(int $tenantId = 1, string $tenantCode = 'alpha', string $provider = 'payment.wechat'): void
    {
        $this->commands->ensureUnconfiguredBinding($tenantId, $tenantCode, $provider);
    }

    private function rows(): array
    {
        return $this->database->query('SELECT * FROM pa_external_channel_binding ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
    }

    private function assertDeniedWithoutWrites(callable $operation): void
    {
        $before = $this->rows();
        $failure = null;
        try {
            $operation();
        } catch (DomainException $exception) {
            $failure = $exception;
        }
        self::assertInstanceOf(DomainException::class, $failure);
        self::assertSame($before, $this->rows());
        self::assertTrue($this->contexts->isEmpty());
    }

    public function testCreatesOnlyDisabledDefaultsWithStableTenantIdentityAndPreservesRepeatedCalls(): void
    {
        $providers = ['payment.wechat', 'payment.alipay', 'wechat.official-account', 'oauth.wechat.oa', 'oauth.wechat.mini-program', 'oauth.wechat.open-pc'];
        foreach ($providers as $provider) {
            $this->contexts->run($this->system(), fn() => $this->seed(provider: $provider));
        }
        $rows = $this->rows();
        self::assertCount(6, $rows);
        foreach ($rows as $row) {
            self::assertSame(1, $row['tenant_id']);
            self::assertSame(0, $row['status']);
            self::assertSame('{}', $row['config_json']);
            self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $row['callback_key']);
            self::assertSame(hash('sha256', 'unconfigured:alpha:' . $row['provider']), $row['identity_hash']);
        }
        $this->contexts->run($this->system(), fn() => $this->seed());
        self::assertSame($rows, $this->rows());
    }

    public function testProvisioningTenantRemainsSupportedBeforeOwnerInvitationCompletes(): void
    {
        $this->contexts->run($this->system(2), fn() => $this->seed(2, 'beta'));
        self::assertSame(2, $this->rows()[0]['tenant_id']);
        self::assertSame(0, $this->rows()[0]['status']);
    }

    public function testMissingTrustedContextCannotWrite(): void
    {
        $this->assertDeniedWithoutWrites(fn() => $this->seed());
    }

    public static function invalidContexts(): array
    {
        return [
            'cross tenant' => [2, 'platform.tenant-bootstrap', 'integration.bootstrap-bindings'],
            'wrong actor' => [1, 'scheduler', 'integration.bootstrap-bindings'],
            'wrong purpose' => [1, 'platform.tenant-bootstrap', 'notification.provision-tenant-defaults'],
        ];
    }

    #[DataProvider('invalidContexts')]
    public function testRequiresExactTenantActorAndPurpose(int $tenantId, string $actor, string $operation): void
    {
        $this->assertDeniedWithoutWrites(fn() => $this->contexts->run($this->system($tenantId, $actor, $operation), fn() => $this->seed()));
    }

    public static function invalidTargets(): array
    {
        return [
            'wrong code' => [1, 'beta', 'payment.wechat'],
            'blank code' => [1, '', 'payment.wechat'],
            'unknown provider' => [1, 'alpha', 'custom.unreviewed'],
            'invalid tenant' => [0, 'alpha', 'payment.wechat'],
        ];
    }

    #[DataProvider('invalidTargets')]
    public function testRejectsInvalidTargetsWithoutCallingPersistence(int $tenantId, string $tenantCode, string $provider): void
    {
        $this->assertDeniedWithoutWrites(fn() => $this->contexts->run($this->system(), fn() => $this->seed($tenantId, $tenantCode, $provider)));
    }

    public function testUnknownTenantCannotBeCreatedThroughBindings(): void
    {
        $this->assertDeniedWithoutWrites(fn() => $this->contexts->run($this->system(999), fn() => $this->seed(999, 'missing')));
    }

    public function testSuspendedTenantCannotReceiveDefaults(): void
    {
        $this->database->exec("UPDATE pa_tenant SET status = 'suspended' WHERE id = 1");
        $this->assertDeniedWithoutWrites(fn() => $this->contexts->run($this->system(), fn() => $this->seed()));
    }

    public function testStateIsRecheckedRatherThanCachedInTheCommand(): void
    {
        $this->contexts->run($this->system(), fn() => $this->seed());
        $this->database->exec("UPDATE pa_tenant SET status = 'suspended' WHERE id = 1");
        $this->assertDeniedWithoutWrites(fn() => $this->contexts->run($this->system(), fn() => $this->seed(provider: 'payment.alipay')));
    }

    public function testExistingConfiguredBindingAndItsCredentialsAreNeverReset(): void
    {
        $this->contexts->run($this->system(), fn() => $this->seed());
        $this->database->exec(<<<'SQL'
            UPDATE pa_external_channel_binding SET callback_key = 'existing-callback', identity_hash = 'existing-identity', identity_hint = 'existing-hint', config_json = '{"app_id":"synthetic-configured-app"}', status = 1, create_time = 100, update_time = 200 WHERE tenant_id = 1;
            SQL);
        $before = $this->rows();
        $this->contexts->run($this->system(), fn() => $this->seed());
        self::assertSame($before, $this->rows());
    }

    public function testModuleProviderBuildsTheGuardedCommandWithItsRealDependencies(): void
    {
        $this->application->instance(ExternalChannelBindingStore::class, $this->bindings);
        $this->application->instance(CurrentExecutionContext::class, $this->current);
        $this->application->instance(AdminDirectoryQuery::class, $this->directory);
        $factory = (new ModuleProvider())->bindings()[ExternalIntegrationBootstrapCommands::class];
        self::assertIsCallable($factory);
        $command = $factory($this->application);
        $this->contexts->run($this->system(), fn() => $command->ensureUnconfiguredBinding(1, 'alpha', 'payment.wechat'));
        self::assertCount(1, $this->rows());
    }

    public function testActualHostSeedHelperUsesItsOwnPurposeAndRestoresTheOuterContext(): void
    {
        // Isolate this actual helper, not a full application install or reconstructed replacement.
        $host = (new ReflectionClass(ApplicationTenantBootstrapService::class))->newInstanceWithoutConstructor();
        (new ReflectionProperty($host, 'executionContexts'))->setValue($host, $this->contexts);
        (new ReflectionProperty($host, 'externalBindings'))->setValue($host, $this->commands);
        $method = new ReflectionMethod($host, 'seedExternalBindings');
        $outer = $this->system(operation: 'notification.provision-tenant-defaults');
        $this->contexts->run($outer, function () use ($method, $host, $outer): void {
            $method->invoke($host, 1, 'alpha');
            self::assertSame($outer, $this->contexts->current());
        });
        self::assertCount(6, $this->rows());
        self::assertTrue($this->contexts->isEmpty());
    }
}
