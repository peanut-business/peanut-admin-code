<?php

declare(strict_types=1);

namespace tests\Unit;

use app\common\execution\CurrentExecutionContext;
use app\common\execution\ExecutionContextStore;
use DateTimeImmutable;
use PDO;
use PeanutAdmin\Modules\Identity\Contract\AdminDirectoryQuery;
use PeanutAdmin\Modules\Integration\Contract\ExternalTenantAudit;
use PeanutAdmin\Modules\Integration\Contract\ExternalTenantBindingRepository;
use PeanutAdmin\Modules\Integration\Contract\ExternalTenantResolutionException;
use PeanutAdmin\Modules\Integration\Infrastructure\ThinkPhpExternalTenantBindingRepository;
use PeanutAdmin\Modules\Integration\ModuleProvider;
use PeanutAdmin\Modules\Integration\Service\ExternalTenantResolver;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use RuntimeException;
use think\App;
use think\facade\Db;

/** Real ORM on process-local synthetic tables; not MySQL lock/concurrency qualification. */
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class IntegrationTenantBoundaryTest extends TestCase
{
    private PDO $database;
    private AdminDirectoryQuery $directory;
    private ThinkPhpExternalTenantBindingRepository $bindings;
    private ExternalTenantResolver $resolver;

    protected function setUp(): void
    {
        $root = dirname(__DIR__, 3);
        $temporary = $root . '/.local/tmp/integration-tenant-boundary-tests';
        if (!is_dir($temporary) && !mkdir($temporary, 0700, true) && !is_dir($temporary)) {
            throw new RuntimeException('INTEGRATION_BOUNDARY_TEST_DIRECTORY_UNAVAILABLE');
        }
        self::assertStringStartsWith($root . '/.local/tmp/', (string) realpath($temporary));
        require_once $root . '/server/vendor/topthink/framework/src/helper.php';
        $app = new App($temporary . '/case-' . bin2hex(random_bytes(6)));
        $this->database = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        \ThinkPhpTestConnection::fromPdo($this->database);
        $this->database->exec(<<<'SQL'
            CREATE TABLE pa_tenant (id INTEGER PRIMARY KEY, code TEXT, status TEXT);
            CREATE TABLE pa_external_channel_binding (id INTEGER PRIMARY KEY AUTOINCREMENT, tenant_id INTEGER, provider TEXT, callback_key TEXT, identity_hash TEXT, identity_hint TEXT, config_json TEXT, status INTEGER, create_time INTEGER, update_time INTEGER, UNIQUE(tenant_id, provider));
            INSERT INTO pa_tenant VALUES (1, 'alpha', 'active'), (2, 'beta', 'active');
            SQL);
        $this->directory = new AdminDirectoryQuery(new CurrentExecutionContext(new ExecutionContextStore()));
        $app->instance(AdminDirectoryQuery::class, $this->directory);
        $app->bind((new ModuleProvider())->bindings());
        $this->bindings = $app->make(ExternalTenantBindingRepository::class);
        $audit = new class implements ExternalTenantAudit {
            public function record(string $outcome, array $attributes): void {}
        };
        $this->resolver = new ExternalTenantResolver($this->bindings, $audit);
    }

    private function binding(int $tenantId = 1, string $provider = 'payment.wechat', string $callback = 'synthetic-callback'): void
    {
        $statement = $this->database->prepare('INSERT INTO pa_external_channel_binding (tenant_id,provider,callback_key,identity_hash,identity_hint,config_json,status,create_time,update_time) VALUES (?,?,?,?,?,?,1,10,20)');
        $statement->execute([$tenantId, $provider, $callback, hash('sha256', 'synthetic-app'), 'app', '{"app_id":"synthetic-app"}']);
    }

    public function testRepositoryUsesThePublicIdentityProjectionInsteadOfForeignTables(): void
    {
        $constructor = (new ReflectionClass($this->bindings))->getConstructor();
        self::assertNotNull($constructor);
        self::assertSame(AdminDirectoryQuery::class, $constructor->getParameters()[0]->getType()?->getName());
        $source = (string) file_get_contents((new ReflectionClass($this->bindings))->getFileName());
        self::assertStringNotContainsString("Db::name('tenant')", $source);
        self::assertStringNotContainsString("->join('tenant", $source);
        self::assertStringNotContainsString('Identity\\Persistence', $source);
    }

    public function testAllFourLookupsKeepBindingIdentityAndConfiguration(): void
    {
        $this->binding();
        foreach ([
            $this->bindings->byCallbackKey('payment.wechat', 'synthetic-callback'),
            $this->bindings->byClientIdentity('payment.wechat', hash('sha256', 'synthetic-app')),
            $this->bindings->byProvider('payment.wechat'),
            Db::transaction(fn() => $this->bindings->byTenant('payment.wechat', 1, true)),
        ] as $rows) {
            self::assertCount(1, $rows);
            self::assertSame(1, $rows[0]->tenantId);
            self::assertTrue($rows[0]->tenantActive);
            self::assertSame(['app_id' => 'synthetic-app'], $rows[0]->config);
        }
        self::assertSame([], $this->bindings->byTenant('payment.wechat', 2));
    }

    public function testTenantStateIsRecheckedAndDoesNotBecomeAnAuthorizationGrant(): void
    {
        self::assertTrue(method_exists($this->directory, 'tenantStatus'));
        self::assertSame('active', $this->directory->tenantStatus(1));
        self::assertNull($this->directory->tenantStatus(999));
        self::assertNull($this->directory->tenantStatus(0));
        $this->database->exec("UPDATE pa_tenant SET status = 'suspended' WHERE id = 1");
        self::assertSame('suspended', Db::transaction(fn() => $this->directory->tenantStatus(1, true)));
        self::assertFalse($this->bindings->tenantIsActive(1));
    }

    public static function inactiveStates(): array
    {
        return [['suspended'], ['provisioning'], ['archived'], ['disabled']];
    }

    #[DataProvider('inactiveStates')]
    public function testInactiveTenantCannotResolveOrReceiveAConfigurationWrite(string $status): void
    {
        $this->binding();
        $this->database->prepare('UPDATE pa_tenant SET status = ? WHERE id = 1')->execute([$status]);
        self::assertFalse($this->bindings->byTenant('payment.wechat', 1)[0]->tenantActive);
        try {
            $this->resolver->verifiedCallback('payment.wechat', 'synthetic-callback', 'payment.callback', 'synthetic-op', static fn() => true);
            self::fail('Inactive tenant callback was accepted');
        } catch (ExternalTenantResolutionException) {
            self::assertFalse($this->bindings->tenantIsActive(1));
        }
        $before = $this->database->query('SELECT * FROM pa_external_channel_binding')->fetchAll(PDO::FETCH_ASSOC);
        try {
            $this->bindings->updateBinding(1, 'payment.wechat', ['changed' => true], 'other', true);
            self::fail('Inactive tenant write was accepted');
        } catch (ExternalTenantResolutionException) {
            self::assertSame($before, $this->database->query('SELECT * FROM pa_external_channel_binding')->fetchAll(PDO::FETCH_ASSOC));
        }
    }

    public function testInactiveDuplicateIsNotFilteredIntoOneAcceptedBinding(): void
    {
        $this->binding();
        $this->binding(2);
        $this->database->exec("UPDATE pa_tenant SET status = 'suspended' WHERE id = 2");
        self::assertCount(2, $this->bindings->byCallbackKey('payment.wechat', 'synthetic-callback'));
        $this->expectException(ExternalTenantResolutionException::class);
        $this->resolver->verifiedCallback('payment.wechat', 'synthetic-callback', 'payment.callback', 'synthetic-op', static fn() => true);
    }

    public function testOrphanBindingCannotHideAmbiguityByDisappearingInAForeignJoin(): void
    {
        $this->binding();
        $this->binding(999);
        $this->expectException(ExternalTenantResolutionException::class);
        $this->resolver->onlyActiveBinding('payment.wechat', 'payment.callback', 'synthetic-op');
    }

    public function testSuccessfulWritePreservesCallbackAndOuterRollback(): void
    {
        $this->binding();
        $this->bindings->updateBinding(1, 'payment.wechat', ['app_id' => 'changed'], 'changed', true);
        self::assertSame('synthetic-callback', $this->bindings->byTenant('payment.wechat', 1)[0]->callbackKey);
        $before = $this->database->query('SELECT * FROM pa_external_channel_binding')->fetchAll(PDO::FETCH_ASSOC);
        try {
            Db::transaction(function (): void {
                $this->bindings->mutateBinding(1, 'payment.wechat', 'mutated', static fn() => ['config' => ['app_id' => 'mutated'], 'enabled' => false]);
                throw new RuntimeException('synthetic outer failure');
            });
        } catch (RuntimeException $exception) {
            self::assertSame('synthetic outer failure', $exception->getMessage());
        }
        self::assertSame($before, $this->database->query('SELECT * FROM pa_external_channel_binding')->fetchAll(PDO::FETCH_ASSOC));
    }
}
