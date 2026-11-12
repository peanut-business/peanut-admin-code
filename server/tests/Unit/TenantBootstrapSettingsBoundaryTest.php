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
        $root = (getenv('PEANUT_TEST_APPLICATION_ROOT') ?: dirname(__DIR__, 3));
        require_once $root . '/server/vendor/topthink/framework/src/helper.php';
        $this->application = new App($root . '/.local/tmp/tenant-bootstrap-settings/' . bin2hex(random_bytes(6)));
        $this->database = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        ThinkPhpTestConnection::fromPdo($this->database);
        $this->database->exec(<<<'SQL'
            CREATE TABLE pa_customer_service_setting (id INTEGER PRIMARY KEY AUTOINCREMENT, tenant_id INTEGER NOT NULL, qr_file_id INTEGER, wechat TEXT, phone TEXT, service_time TEXT, create_time INTEGER, update_time INTEGER);
            CREATE TABLE pa_decorate_tabbar_setting (id INTEGER PRIMARY KEY AUTOINCREMENT, tenant_id INTEGER NOT NULL, style TEXT, create_time INTEGER, update_time INTEGER);
            CREATE TABLE pa_transaction_setting (id INTEGER PRIMARY KEY AUTOINCREMENT, tenant_id INTEGER NOT NULL, cancel_unpaid_orders INTEGER, cancel_unpaid_orders_times INTEGER, verification_orders INTEGER, verification_orders_times INTEGER, create_time INTEGER, update_time INTEGER);
            CREATE TABLE pa_decorate_page (id INTEGER PRIMARY KEY AUTOINCREMENT, tenant_id INTEGER NOT NULL, type INTEGER NOT NULL, name TEXT, data TEXT, meta TEXT, create_time INTEGER, update_time INTEGER);
            CREATE TABLE pa_tenant (id INTEGER PRIMARY KEY, status TEXT);
            INSERT INTO pa_tenant VALUES (101, 'active'), (202, 'active'), (303, 'disabled');
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
            if ($parameter->isDefaultValueAvailable()) {
                self::assertNull($parameter->getDefaultValue());
                continue;
            }
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
        self::assertNull((new ReflectionProperty($host, 'notifications'))->getValue($host));
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
        foreach (['customer_service_setting', 'decorate_tabbar_setting'] as $table) {
            self::assertSame([101, 202], array_map('intval', $this->database->query('SELECT tenant_id FROM pa_' . $table . ' ORDER BY tenant_id')->fetchAll(PDO::FETCH_COLUMN)));
        }
        // Optional business defaults are outside the required-module bootstrap.
        self::assertSame(0, (int) $this->database->query('SELECT COUNT(*) FROM pa_transaction_setting')->fetchColumn());
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

    public function testPageCommandPreviewsCompletesAndPreservesExistingPagesAndOtherTenant(): void
    {
        $this->database->exec(<<<'SQL'
            INSERT INTO pa_decorate_page VALUES (7,101,5,'custom theme','{"themeColor1":"custom"}','[]',123,456);
            INSERT INTO pa_decorate_page VALUES (8,202,1,'other home','["keep"]','[]',321,654);
            INSERT INTO pa_decorate_tabbar_setting VALUES (9,101,'{"selected_color":"custom"}',123,456);
            SQL);
        $before = $this->database->query('SELECT * FROM pa_decorate_page ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
        $tabbar = $this->database->query('SELECT * FROM pa_decorate_tabbar_setting')->fetchAll(PDO::FETCH_ASSOC);
        $preview = $this->pageCommand(['--tenant-id=101', '--dry-run']);
        self::assertSame([1, 2, 3, 4], $preview['missing_types']);
        self::assertSame(0, $preview['inserted']);
        self::assertSame($before, $this->database->query('SELECT * FROM pa_decorate_page ORDER BY id')->fetchAll(PDO::FETCH_ASSOC));
        self::assertSame(2, $this->pageCommand(['--tenant-id=101', '--types=4,1'])['inserted']);
        self::assertSame([2, 3], $this->pageCommand(['--tenant-id=101', '--dry-run'])['missing_types']);
        self::assertSame(2, $this->pageCommand(['--tenant-id=101'])['inserted']);
        self::assertSame(0, $this->pageCommand(['--tenant-id=101'])['inserted']);
        self::assertSame([1, 2, 3, 4, 5], array_map('intval', $this->database->query('SELECT type FROM pa_decorate_page WHERE tenant_id=101 ORDER BY type')->fetchAll(PDO::FETCH_COLUMN)));
        self::assertSame($before, $this->database->query('SELECT * FROM pa_decorate_page WHERE id IN (7,8) ORDER BY id')->fetchAll(PDO::FETCH_ASSOC));
        self::assertSame($tabbar, $this->database->query('SELECT * FROM pa_decorate_tabbar_setting')->fetchAll(PDO::FETCH_ASSOC));
        self::assertSame(0, (int) $this->database->query('SELECT COUNT(*) FROM pa_customer_service_setting')->fetchColumn());
        self::assertSame(0, (int) $this->database->query('SELECT COUNT(*) FROM pa_transaction_setting')->fetchColumn());
        self::assertTrue($this->contexts->isEmpty());
    }

    public function testEmptyTenantGetsOriginalThemeAndPageDefaults(): void
    {
        self::assertSame(5, $this->pageCommand(['--tenant-id=101'])['inserted']);
        $theme = json_decode($this->database->query('SELECT data FROM pa_decorate_page WHERE type=5')->fetchColumn(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('#A74BFD', $theme['themeColor1']);
        self::assertSame(0, $this->pageCommand(['--tenant-id=101'])['inserted']);
    }

    public function testPageCommandRejectsInvalidTenantTypesAndNestedContextWithoutWrites(): void
    {
        foreach ([[], ['--tenant-id=0'], ['--tenant-id=999'], ['--tenant-id=303'], ['--tenant-id=101', '--types=6'], ['--tenant-id=101', '--types=']] as $arguments) {
            try {
                $this->pageCommand($arguments);
                self::fail('Invalid maintenance input was accepted.');
            } catch (InvalidArgumentException|DomainException $exception) {
                self::assertContains($exception->getMessage(), ['DECORATION_TENANT_INVALID', 'DECORATION_ACTIVE_TENANT_REQUIRED', 'DECORATION_PAGE_TYPES_INVALID']);
            }
            self::assertTrue($this->contexts->isEmpty());
        }
        $context = new TenantSystemContext(202, 'fixture', 'fixture', 'fixture');
        $this->contexts->run(new SystemExecutionContext($context), function (): void {
            try {
                $this->pageCommand(['--tenant-id=101']);
                self::fail('Nested maintenance was accepted.');
            } catch (DomainException $exception) {
                self::assertSame('PLATFORM_MAINTENANCE_CONTEXT_REQUIRED', $exception->getMessage());
            }
        });
        self::assertTrue($this->contexts->isEmpty());
        self::assertSame(0, (int) $this->database->query('SELECT COUNT(*) FROM pa_decorate_page')->fetchColumn());
    }

    public function testPageCompletionRollsBackAllInsertsOnFailure(): void
    {
        $this->database->exec("CREATE TRIGGER refuse_page BEFORE INSERT ON pa_decorate_page WHEN NEW.type=4 BEGIN SELECT RAISE(ABORT, 'fixture page denied'); END");
        try {
            $this->pageCommand(['--tenant-id=101']);
            self::fail('Page write error was swallowed.');
        } catch (\think\db\exception\PDOException $exception) {
            self::assertStringContainsString('fixture page denied', $exception->getMessage());
        }
        self::assertSame(0, (int) $this->database->query('SELECT COUNT(*) FROM pa_decorate_page')->fetchColumn());
        self::assertTrue($this->contexts->isEmpty());
    }

    private function pageCommand(array $arguments): array
    {
        $current = new CurrentExecutionContext($this->contexts);
        $command = new \app\command\TenantDecorationPages(
            new \app\common\services\decoration\DecorationPageDefaultsService($this->application->make(\think\DbManager::class)),
            new \PeanutAdmin\Modules\Identity\Contract\TenantModuleStateQueries(),
            $this->contexts,
            $current,
        );
        $command->setApp($this->application);
        $output = new \think\console\Output('buffer');
        self::assertSame(0, $command->run(new \think\console\Input($arguments), $output));
        return json_decode(trim($output->fetch()), true, 512, JSON_THROW_ON_ERROR);
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
