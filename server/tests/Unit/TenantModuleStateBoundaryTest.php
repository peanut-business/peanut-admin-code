<?php

declare(strict_types=1);

namespace tests\Unit;

use app\platform\infrastructure\module\DeployedTenantModuleRegistry;
use app\platform\services\module\ModuleQualificationQueryService;
use PDO;
use PDOStatement;
use PeanutAdmin\Kernel\Module\CompiledModuleRegistry;
use PeanutAdmin\Kernel\Module\ManifestDocument;
use PeanutAdmin\Modules\Identity\Contract\TenantModuleStateQueries;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;
use think\App;
use think\DbManager;

/** Native query/registry on synthetic SQLite; only the MySQL clock token is adapted, not predicates or parameters. */
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class TenantModuleStateBoundaryTest extends TestCase
{
    private PDO $database;
    private TenantModuleStateQueries $queries;
    private ModuleQualificationQueryService $qualification;
    private TenantModuleStateFixtureConnection $connection;
    private string $clock = '2031-01-01 12:00:00.000';

    protected function setUp(): void
    {
        $root = dirname(__DIR__, 3);
        $temporary = $root . '/.local/tmp/tenant-module-state-tests';
        if (!is_dir($temporary) && !mkdir($temporary, 0700, true) && !is_dir($temporary)) {
            throw new \RuntimeException('TENANT_MODULE_TEST_DIRECTORY_UNAVAILABLE');
        }
        self::assertStringStartsWith($root . '/.local/tmp/', (string) realpath($temporary));
        require_once $root . '/server/vendor/topthink/framework/src/helper.php';
        $app = new App($temporary . '/case-' . bin2hex(random_bytes(5)));
        $this->database = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $this->database->sqliteCreateFunction('qualification_clock', fn(int $precision): string => $this->clock, 1);
        $this->connection = new TenantModuleStateFixtureConnection($this->database);
        $manager = new \SharedPdoDbManager($this->connection);
        $this->connection->setDb($manager);
        $app->instance(DbManager::class, $manager);
        $this->database->exec(<<<'SQL'
            CREATE TABLE pa_tenant (id INTEGER PRIMARY KEY, status TEXT);
            CREATE TABLE pa_tenant_module (id INTEGER PRIMARY KEY, tenant_id INTEGER, module_key TEXT, status TEXT, source TEXT DEFAULT 'fixture', config_revision INTEGER DEFAULT 7, effective_at TEXT, expires_at TEXT, enabled_at TEXT, disabled_at TEXT, disabled_reason TEXT, created_at TEXT, updated_at TEXT, config_json TEXT DEFAULT 'private-fixture-config');
            CREATE TABLE pa_module_installation (module_key TEXT PRIMARY KEY, installed_version TEXT, manifest_schema_version INTEGER, manifest_digest TEXT, status TEXT, revision INTEGER, activated_at TEXT, created_at TEXT, updated_at TEXT, last_error_code TEXT DEFAULT NULL);
            CREATE TABLE pa_plugin_module (plugin_key TEXT, module_key TEXT);
            INSERT INTO pa_tenant VALUES (1,'active'),(2,'closed'),(3,'provisioning');
            INSERT INTO pa_tenant_module (id,tenant_id,module_key,status,effective_at,expires_at) VALUES
                (1,1,'fixture.current','enabled','2031-01-01 12:00:00.000','2031-01-01 12:00:00.001'),
                (2,1,'fixture.expired','enabled',NULL,'2031-01-01 12:00:00.000'),
                (3,1,'fixture.future','enabled','2031-01-01 12:00:00.001',NULL),
                (4,1,'fixture.disabled','disabled',NULL,NULL),
                (5,1,'fixture.always','enabled',NULL,NULL),
                (6,1,'fixture.foundation','disabled',NULL,NULL),
                (7,2,'fixture.foreign','enabled',NULL,NULL);
            SQL);
        $manifests = [];
        foreach (['fixture.foundation' => true, 'fixture.current' => false] as $key => $foundation) {
            $manifest = ManifestDocument::fromArray($temporary, [
                'schema_version' => 1, 'key' => $key, 'name' => $key, 'version' => '1.0.0',
                'tenant' => ['enableable' => !$foundation], 'lifecycle' => ['protected' => $foundation],
            ]);
            $manifests[] = $manifest;
            $this->database->prepare('INSERT INTO pa_module_installation VALUES (?,?,?,?,?,?,?,?,?,?)')->execute([
                $key, '1.0.0', 1, $manifest->digest, 'active', 9, '2031-01-01 00:00:00.000', '2030-01-01 00:00:00.000', '2031-01-01 00:00:00.000', null,
            ]);
        }
        $compiled = new CompiledModuleRegistry($manifests, [], [], [], hash('sha256', implode('|', array_map(static fn(ManifestDocument $manifest): string => $manifest->digest, $manifests))));
        $this->queries = new TenantModuleStateQueries();
        $this->qualification = new ModuleQualificationQueryService(new DeployedTenantModuleRegistry($compiled), $this->queries);
    }

    public function testPublicOwnerBoundaryDoesNotExposeConfigurationOrQueries(): void
    {
        $manifest = json_decode(file_get_contents(dirname(__DIR__, 2) . '/app/modules/official/identity/module.json'), true, 512, JSON_THROW_ON_ERROR);
        self::assertContains(TenantModuleStateQueries::class, $manifest['contracts']['exports']);
        $source = file_get_contents((new \ReflectionClass(ModuleQualificationQueryService::class))->getFileName());
        self::assertStringContainsString('TenantModuleStateQueries $tenantStates', $source);
        self::assertStringNotContainsString("Db::name('tenant')", $source);
        self::assertStringNotContainsString("Db::name('tenant_module')", $source);
        foreach ($this->queries->stateRows(1) as $row) {
            self::assertArrayNotHasKey('config_json', $row);
            self::assertSame(1, $row['tenant_id']);
        }
    }

    public function testDatabaseWindowAndTenantIsolationRemainUnchanged(): void
    {
        self::assertSame(['fixture.always', 'fixture.current'], $this->queries->activeModuleKeys(1));
        $this->clock = '2031-01-01 12:00:00.001';
        self::assertSame(['fixture.always', 'fixture.future'], $this->queries->activeModuleKeys(1));
        self::assertStringContainsString('CURRENT_TIMESTAMP(3)', implode("\n", $this->connection->observedSql));
        self::assertSame([], $this->queries->stateRows(0));
        self::assertSame([], $this->queries->activeModuleKeys(0));
    }

    public function testRequiredFoundationIsProjectedOnceAndOnlyAfterInstallationValidation(): void
    {
        self::assertSame(['fixture.always', 'fixture.current', 'fixture.foundation'], $this->qualification->activeTenantModuleKeys(1));
        $states = $this->qualification->tenantModuleStates(1);
        $foundation = array_values(array_filter($states, static fn($state): bool => $state->moduleKey === 'fixture.foundation'));
        self::assertCount(1, $foundation);
        self::assertSame('required_foundation', $foundation[0]->source);
        self::assertSame('enabled', $foundation[0]->status);
        self::assertSame(0, $foundation[0]->id);
        self::assertSame(9, $foundation[0]->configRevision);
        self::assertCount(6, $states);
    }

    public function testInstallationStateProjectionIsBoundedAndPreservesMissingRows(): void
    {
        $this->database->exec("UPDATE pa_module_installation SET status='maintenance',last_error_code='RECOVERY_REQUIRED' WHERE module_key='fixture.current'");
        self::assertSame([
            'fixture.current' => ['status' => 'maintenance', 'last_error_code' => 'RECOVERY_REQUIRED'],
            'fixture.foundation' => ['status' => 'active', 'last_error_code' => null],
        ], $this->queries->installationStates(['fixture.foundation', 'fixture.missing', 'fixture.current']));
        self::assertSame([], $this->queries->installationStates([]));
    }

    public function testInactiveOrMissingTenantCannotReceiveEvenFoundationState(): void
    {
        foreach ([0, 2, 3, 999] as $tenantId) {
            self::assertFalse($this->queries->tenantIsActive($tenantId));
            self::assertSame([], $this->qualification->tenantModuleStates($tenantId));
            self::assertSame([], $this->qualification->activeTenantModuleKeys($tenantId));
        }
    }

    public function testFoundationManifestMismatchStillFailsInsteadOfGrantingItsAvailability(): void
    {
        $this->database->exec("UPDATE pa_module_installation SET manifest_digest='wrong' WHERE module_key='fixture.foundation'");
        $this->expectException(\PeanutAdmin\Kernel\Module\ModuleException::class);
        $this->qualification->activeTenantModuleKeys(1);
    }

    public function testLifecycleUsageIsNotReducedToCurrentlyUsableTenantModules(): void
    {
        $this->database->exec("INSERT INTO pa_tenant_module (id,tenant_id,module_key,status,effective_at,expires_at) VALUES (8,2,'fixture.current','enabled',NULL,'2030-01-01 00:00:00.000'),(9,3,'fixture.current','enabled','2032-01-01 00:00:00.000',NULL)");
        $counts = $this->queries->enabledCounts();
        self::assertSame(3, $counts['fixture.current']);
        self::assertSame(1, $counts['fixture.expired']);
        self::assertSame(1, $counts['fixture.future']);
        self::assertArrayNotHasKey('fixture.disabled', $counts);
        self::assertArrayNotHasKey('fixture.foundation', $counts);
        self::assertTrue($this->queries->hasEnabledModules(['fixture.expired']));
        self::assertTrue($this->queries->hasEnabledModules(['fixture.foreign']));
        self::assertFalse($this->queries->hasEnabledModules(['fixture.disabled', 'fixture.foundation']));
        self::assertFalse($this->queries->hasEnabledModules([]));
        self::assertSame(['fixture.current', 'fixture.current', 'fixture.current', 'fixture.expired'], $this->queries->enabledModuleReferences(['fixture.expired', 'fixture.current']));
        self::assertSame([], $this->queries->enabledModuleReferences([]));
    }

    public function testLifecycleConsumersKeepTheGuardButDoNotReadThePrivateStateTable(): void
    {
        $root = dirname(__DIR__, 2) . '/app/platform/services/plugin/';
        foreach (['PlatformModuleRuntimeService.php', 'PluginRuntimeGovernanceService.php', 'PluginLifecycleService.php'] as $file) {
            $source = file_get_contents($root . $file);
            self::assertStringContainsString('TenantModuleStateQueries $tenantStates', $source);
            self::assertStringNotContainsString("Db::name('tenant_module')", $source);
            self::assertStringContainsString('PLUGIN_TENANT_MODULE_ACTIVE', $source);
        }
    }

    public function testAllFormerHostHitsUseIdentityOwnerCapabilitiesInsteadOfThePrivateInstallationTable(): void
    {
        $root = dirname(__DIR__, 2) . '/app/';
        foreach ([
            'command/PluginReconcile.php',
            'modules/official/ops/src/Infrastructure/ThinkPhpModuleOperationTaskExecutionService.php',
            'platform/services/plugin/PlatformModuleRuntimeService.php',
            'platform/services/plugin/PluginLifecycleService.php',
            'platform/services/plugin/PluginRuntimeGovernanceService.php',
            'platform/validation/plugin/PluginReleaseCompositionGuard.php',
        ] as $file) {
            $source = file_get_contents($root . $file);
            self::assertStringNotContainsString('module_installation', $source, $file);
        }
    }

    public function testAffectedNativeFactoriesAndRetainedFixturesSupplyTheRequiredOwnerQuery(): void
    {
        $root = dirname(__DIR__, 3);
        $minimum = [
            \app\platform\services\plugin\PlatformModuleRuntimeService::class => 7,
            \app\platform\services\plugin\PluginRuntimeGovernanceService::class => 4,
            \app\platform\services\plugin\PluginLifecycleService::class => 6,
        ];
        $sites = 0;
        foreach ([
            'server/app/AppService.php', 'server/app/command/ModuleSync.php',
            'server/app/platform/infrastructure/module/ThinkPhpModuleGovernanceProvider.php',
            'server/app/platform/infrastructure/plugin/PluginPackageInstaller.php',
            'server/fixtures/plugin-module-lifecycle/run.php',
            'server/tests/Productization/ModuleCommandContractTest.php',
            'server/tests/Productization/ModuleBundleLifecycleTest.php',
            'server/tests/Productization/ModuleRuntimeGovernanceTest.php',
            'server/tests/Productization/ModuleDeliveryOperationTest.php',
        ] as $path) {
            $traverser = new \PhpParser\NodeTraverser();
            $traverser->addVisitor(new \PhpParser\NodeVisitor\NameResolver());
            $nodes = $traverser->traverse((new \PhpParser\ParserFactory())->createForHostVersion()->parse(file_get_contents($root . '/' . $path)));
            foreach ((new \PhpParser\NodeFinder())->findInstanceOf($nodes, \PhpParser\Node\Expr\New_::class) as $call) {
                if ($call->class instanceof \PhpParser\Node\Name && isset($minimum[$call->class->toString()])) {
                    ++$sites;
                    self::assertGreaterThanOrEqual($minimum[$call->class->toString()], count($call->args), $path . ':' . $call->getStartLine());
                }
            }
        }
        self::assertSame(23, $sites);
    }

    public function testMissingLifecycleLedgerIsNotTreatedAsNoEnabledConsumers(): void
    {
        $this->database->exec('DROP TABLE pa_tenant_module');
        $this->expectException(\Throwable::class);
        $this->queries->hasEnabledModules(['fixture.current']);
    }

    public function testMissingStateLedgerDoesNotSilentlyReturnAnEmptyGrantSet(): void
    {
        $this->database->exec('DROP TABLE pa_tenant_module');
        $this->expectException(\Throwable::class);
        $this->qualification->activeTenantModuleKeys(1);
    }
}

/** The production clock spelling is recorded before substituting the sole unsupported SQLite function token. */
final class TenantModuleStateFixtureConnection extends \think\db\connector\Sqlite
{
    public array $observedSql = [];

    public function __construct(private readonly PDO $fixture)
    {
        parent::__construct(['type' => 'sqlite', 'builder' => \think\db\builder\Sqlite::class, 'prefix' => 'pa_']);
    }

    protected function createPdo($dsn, $username, $password, $params): PDO
    {
        return $this->fixture;
    }

    public function getPDOStatement(string $sql, array $bind = [], bool $master = false, bool $procedure = false): PDOStatement
    {
        $this->observedSql[] = $sql;
        return parent::getPDOStatement(str_replace('CURRENT_TIMESTAMP(3)', 'qualification_clock(3)', $sql), $bind, $master, $procedure);
    }
}
