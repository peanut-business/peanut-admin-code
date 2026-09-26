<?php

declare(strict_types=1);

use app\common\execution\CurrentExecutionContext;
use app\common\execution\ExecutionContextStore;
use app\common\execution\SystemExecutionContext;
use app\common\model\TenantOwnedModel;
use app\common\tenancy\MultiTenantDataScopePolicy;
use app\platform\services\ApplicationTenantBootstrapService;
use PeanutAdmin\Kernel\Context\TenantSystemContext;
use PeanutAdmin\Modules\Settings\Contract\TenantSettingSnapshot;
use PeanutAdmin\Modules\Settings\Contract\TenantSettingsCommands;
use PeanutAdmin\Modules\Settings\Contract\TenantSettingsQuery;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;
use think\App;
use think\Model;

/** Real seed helper and scoped host Models with SQLite fixtures; not a tenant-install qualification. */
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class TenantBootstrapSettingsBoundaryTest extends TestCase
{
    private PDO $database;
    private ExecutionContextStore $contexts;
    private App $application;

    protected function setUp(): void
    {
        $root = dirname(__DIR__, 3);
        require_once $root . '/server/vendor/topthink/framework/src/helper.php';
        $this->application = new App($root . '/.local/tmp/tenant-bootstrap-settings/' . bin2hex(random_bytes(6)));
        $this->database = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        ThinkPhpTestConnection::fromPdo($this->database);
        $this->database->exec(<<<'SQL'
            CREATE TABLE pa_customer_service_setting (id INTEGER PRIMARY KEY AUTOINCREMENT, tenant_id INTEGER NOT NULL, qr_file_id INTEGER, wechat TEXT, phone TEXT, service_time TEXT, create_time INTEGER, update_time INTEGER);
            CREATE TABLE pa_decorate_tabbar_setting (id INTEGER PRIMARY KEY AUTOINCREMENT, tenant_id INTEGER NOT NULL, style TEXT, create_time INTEGER, update_time INTEGER);
            CREATE TABLE pa_transaction_setting (id INTEGER PRIMARY KEY AUTOINCREMENT, tenant_id INTEGER NOT NULL, cancel_unpaid_orders INTEGER, cancel_unpaid_orders_times INTEGER, verification_orders INTEGER, verification_orders_times INTEGER, create_time INTEGER, update_time INTEGER);
            SQL);
        $this->contexts = new ExecutionContextStore();
        $policy = new MultiTenantDataScopePolicy(new CurrentExecutionContext($this->contexts));
        Model::maker(static function (Model $model) use ($policy): void {
            if ($model instanceof TenantOwnedModel) {
                $model->setDataScopePolicy($policy);
            }
        });
    }

    public function testConstructorAndNativeContainerUseTheTwoExistingPublicPorts(): void
    {
        $constructor = (new ReflectionClass(ApplicationTenantBootstrapService::class))->getConstructor();
        $types = [];
        foreach ($constructor->getParameters() as $parameter) {
            $type = $parameter->getType()->getName();
            $types[$parameter->getName()] = $type;
            $reflection = new ReflectionClass($type);
            $dependency = $reflection->isInterface() ? $this->createStub($type) : $reflection->newInstanceWithoutConstructor();
            $this->application->instance($type, $dependency);
        }
        self::assertSame(TenantSettingsQuery::class, $types['tenantSettings']);
        self::assertSame(TenantSettingsCommands::class, $types['settingCommands']);
        $host = $this->application->make(ApplicationTenantBootstrapService::class);
        self::assertInstanceOf(TenantSettingsQuery::class, (new ReflectionProperty($host, 'tenantSettings'))->getValue($host));
        self::assertInstanceOf(TenantSettingsCommands::class, (new ReflectionProperty($host, 'settingCommands'))->getValue($host));
        self::assertSame(0, (int) $this->database->query('SELECT COUNT(*) FROM pa_transaction_setting')->fetchColumn());
    }

    public function testRealHelperPreservesExistingSettingsAndIsIdempotentPerTenant(): void
    {
        $stored = ['101:website' => ['revision' => 7, 'document' => ['name' => 'Existing tenant name']]];
        $query = $this->createMock(TenantSettingsQuery::class);
        $query->expects(self::exactly(24))->method('get')->willReturnCallback(static function ($context, string $namespace, array $defaults = []) use (&$stored): TenantSettingSnapshot {
            $value = $stored[$context->tenantId . ':' . $namespace] ?? ['revision' => 0, 'document' => $defaults];
            return new TenantSettingSnapshot($context->tenantId, $namespace, $value['document'], $value['revision'], 0, 0);
        });
        $commands = $this->createMock(TenantSettingsCommands::class);
        $commands->expects(self::exactly(15))->method('replace')->willReturnCallback(static function ($context, string $namespace, array $document) use (&$stored): TenantSettingSnapshot {
            $key = $context->tenantId . ':' . $namespace;
            self::assertArrayNotHasKey($key, $stored, 'Existing settings must not be overwritten.');
            $stored[$key] = ['revision' => 1, 'document' => $document];
            return new TenantSettingSnapshot($context->tenantId, $namespace, $document, 1, 1, 1);
        });
        $host = $this->host($query, $commands);
        foreach ([101, 101, 202] as $tenant) {
            $this->seed($host, $tenant);
        }
        self::assertSame(['revision' => 7, 'document' => ['name' => 'Existing tenant name']], $stored['101:website']);
        self::assertSame([1, 2], $stored['202:login']['document']['login_way']);
        self::assertSame(['status' => 0], $stored['101:hot-search']['document']);
        foreach (['customer_service_setting', 'decorate_tabbar_setting', 'transaction_setting'] as $table) {
            self::assertSame([101, 202], array_map('intval', $this->database->query('SELECT tenant_id FROM pa_' . $table . ' ORDER BY tenant_id')->fetchAll(PDO::FETCH_COLUMN)));
        }
        self::assertTrue($this->contexts->isEmpty());
    }

    public function testSettingFailurePropagatesBeforeHostDefaultsAreWritten(): void
    {
        $query = $this->createMock(TenantSettingsQuery::class);
        $query->expects(self::once())->method('get')->willReturn(new TenantSettingSnapshot(101, 'website', [], 0, 0, 0));
        $commands = $this->createMock(TenantSettingsCommands::class);
        $commands->expects(self::once())->method('replace')->willThrowException(new DomainException('FIXTURE_SETTING_WRITE_DENIED'));
        try {
            $this->seed($this->host($query, $commands), 101);
            self::fail('Settings error was swallowed.');
        } catch (DomainException $exception) {
            self::assertSame('FIXTURE_SETTING_WRITE_DENIED', $exception->getMessage());
        }
        foreach (['customer_service_setting', 'decorate_tabbar_setting', 'transaction_setting'] as $table) {
            self::assertSame(0, (int) $this->database->query('SELECT COUNT(*) FROM pa_' . $table)->fetchColumn());
        }
        self::assertTrue($this->contexts->isEmpty());
    }

    private function host(TenantSettingsQuery $query, TenantSettingsCommands $commands): ApplicationTenantBootstrapService
    {
        $host = (new ReflectionClass(ApplicationTenantBootstrapService::class))->newInstanceWithoutConstructor();
        (new ReflectionProperty($host, 'tenantSettings'))->setValue($host, $query);
        (new ReflectionProperty($host, 'settingCommands'))->setValue($host, $commands);
        return $host;
    }

    private function seed(ApplicationTenantBootstrapService $host, int $tenant): void
    {
        $context = new TenantSystemContext($tenant, 'platform.tenant-bootstrap', 'fixture.seed-settings', 'fixture-bootstrap');
        $this->contexts->run(new SystemExecutionContext($context), static function () use ($host, $context): void {
            (new ReflectionMethod($host, 'seedSettings'))->invoke($host, $context);
        });
    }
}
