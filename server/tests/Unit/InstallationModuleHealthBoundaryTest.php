<?php

declare(strict_types=1);

use app\AppService;
use app\common\services\installation\InstallationExecutionHost;
use PeanutAdmin\Modules\Identity\Contract\TenantModuleStateQueries;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;
use think\App;
use think\facade\Db;

/** 实际 Identity 查询与原 SQL 在内存 SQLite 中对照；不运行安装、迁移或外部数据库。 */
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class InstallationModuleHealthBoundaryTest extends TestCase
{
    private PDO $database;
    private TenantModuleStateQueries $states;

    protected function setUp(): void
    {
        $root = dirname(__DIR__, 3);
        require_once $root . '/server/vendor/topthink/framework/src/helper.php';
        new App($root . '/.local/tmp/installation-health-test-' . bin2hex(random_bytes(5)));
        $this->database = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        ThinkPhpTestConnection::fromPdo($this->database);
        $this->database->exec(<<<'SQL'
            CREATE TABLE pa_tenant (id INTEGER PRIMARY KEY, code TEXT COLLATE NOCASE, status TEXT);
            CREATE TABLE pa_module_installation (module_key TEXT COLLATE NOCASE, status TEXT);
            CREATE TABLE pa_tenant_module (id INTEGER PRIMARY KEY, tenant_id INTEGER, module_key TEXT COLLATE NOCASE, status TEXT, effective_at TEXT, expires_at TEXT);
            INSERT INTO pa_tenant VALUES (1,'alpha','active'),(2,'beta','suspended');
            INSERT INTO pa_module_installation VALUES ('fixture.active','active'),('fixture.failed','failed'),('fixture.other','active');
            INSERT INTO pa_tenant_module VALUES
                (1,1,'fixture.active','enabled',NULL,NULL),
                (2,1,'fixture.expired','enabled',NULL,'2000-01-01 00:00:00'),
                (3,1,'fixture.future','enabled','2099-01-01 00:00:00',NULL),
                (4,1,'fixture.disabled','disabled',NULL,NULL),
                (5,2,'fixture.active','enabled',NULL,NULL),
                (6,999,'fixture.orphan','enabled',NULL,NULL);
            SQL);
        $this->states = new TenantModuleStateQueries();
    }

    public static function installationSelections(): array
    {
        return [
            'one active' => [['fixture.active'], 1],
            'failed does not count' => [['fixture.active', 'fixture.failed'], 1],
            'missing does not count' => [['fixture.missing'], 0],
            'duplicate requested key remains one row' => [['fixture.active', 'fixture.active'], 1],
            'database equality is retained' => [['FIXTURE.ACTIVE'], 1],
            'bound values are not SQL' => [["fixture.active' OR 1=1 --"], 0],
        ];
    }

    #[DataProvider('installationSelections')]
    public function testActiveInstallationCountMatchesThePreviousBoundSql(array $keys, int $expected): void
    {
        $placeholders = implode(',', array_fill(0, count($keys), '?'));
        $original = $this->database->prepare("SELECT COUNT(*) FROM pa_module_installation WHERE status='active' AND module_key IN ({$placeholders})");
        $original->execute($keys);
        self::assertSame($expected, (int) $original->fetchColumn());
        self::assertSame($expected, $this->states->activeInstallationCount($keys));
    }

    public static function tenantSelections(): array
    {
        return [
            'requested tenant only' => ['alpha', ['fixture.active'], 1],
            'no new tenant-status requirement' => ['beta', ['fixture.active'], 1],
            'registration is not time-window availability' => ['alpha', ['fixture.expired', 'fixture.future'], 2],
            'disabled and missing are excluded' => ['alpha', ['fixture.disabled', 'fixture.missing'], 0],
            'missing tenant excludes orphan rows' => ['missing', ['fixture.orphan'], 0],
            'duplicate keys preserve SQL IN semantics' => ['alpha', ['fixture.active', 'fixture.active'], 1],
            'database code equality is retained' => ['ALPHA', ['FIXTURE.ACTIVE'], 1],
            'tenant code is a bound value' => ["alpha' OR 1=1 --", ['fixture.active'], 0],
        ];
    }

    #[DataProvider('tenantSelections')]
    public function testEnabledSelectionCountPreservesTheExactOldJoin(string $code, array $keys, int $expected): void
    {
        $placeholders = implode(',', array_fill(0, count($keys), '?'));
        $original = $this->database->prepare("SELECT COUNT(*) FROM pa_tenant_module tm JOIN pa_tenant t ON t.id=tm.tenant_id WHERE t.code=? AND tm.status='enabled' AND tm.module_key IN ({$placeholders})");
        $original->execute([$code, ...$keys]);
        self::assertSame($expected, (int) $original->fetchColumn());
        self::assertSame($expected, $this->states->enabledTenantSelectionCount($code, $keys));
    }

    public function testEmptySelectionAvoidsReadingEitherLedger(): void
    {
        $this->database->exec('DROP TABLE pa_module_installation; DROP TABLE pa_tenant_module; DROP TABLE pa_tenant;');
        self::assertSame(0, $this->states->activeInstallationCount([]));
        self::assertSame(0, $this->states->enabledTenantSelectionCount('alpha', []));
    }

    public function testDuplicateStoredRowsAreNotHiddenByDistinctOrDeduplication(): void
    {
        $this->database->exec("INSERT INTO pa_module_installation VALUES ('fixture.active','active'); INSERT INTO pa_tenant_module VALUES (7,1,'fixture.active','enabled',NULL,NULL);");
        self::assertSame(2, $this->states->activeInstallationCount(['fixture.active']));
        self::assertSame(2, $this->states->enabledTenantSelectionCount('alpha', ['fixture.active']));
    }

    public function testOwnerReadsUseTheCurrentConnectionAndDoNotCommitTheCallerTransaction(): void
    {
        // ThinkORM opens its PDO lazily; verify identity after the real owner query initializes it.
        self::assertSame(1, $this->states->activeInstallationCount(['fixture.active']));
        self::assertSame($this->database, Db::connect()->getPdo());
        try {
            Db::transaction(function (): void {
                Db::name('tenant_module')->where('id', 1)->update(['status' => 'disabled']);
                self::assertSame(0, $this->states->enabledTenantSelectionCount('alpha', ['fixture.active']));
                throw new RuntimeException('HEALTH_FIXTURE_ROLLBACK');
            });
            self::fail('The caller failure must propagate.');
        } catch (RuntimeException $exception) {
            self::assertSame('HEALTH_FIXTURE_ROLLBACK', $exception->getMessage());
        }
        self::assertSame(1, $this->states->enabledTenantSelectionCount('alpha', ['fixture.active']));
    }

    public static function missingLedgers(): array
    {
        return [['pa_module_installation', 'activeInstallationCount', [['fixture.active']]],
            ['pa_tenant_module', 'enabledTenantSelectionCount', ['alpha', ['fixture.active']]]];
    }

    #[DataProvider('missingLedgers')]
    public function testMissingStorageDoesNotTurnIntoAnEmptySuccessfulCheck(string $table, string $method, array $arguments): void
    {
        self::assertTrue(method_exists($this->states, $method));
        $this->database->exec('DROP TABLE ' . $table);
        $this->expectException(Throwable::class);
        $this->states->{$method}(...$arguments);
    }

    public function testHostUsesThePublicCountsAndRetainsItsExistingRejections(): void
    {
        $source = file_get_contents((new ReflectionClass(InstallationExecutionHost::class))->getFileName());
        self::assertStringContainsString('TenantModuleStateQueries $moduleStates', $source);
        self::assertStringContainsString('$this->moduleStates->activeInstallationCount($moduleKeys)', $source);
        self::assertStringContainsString('$this->moduleStates->enabledTenantSelectionCount($tenantBootstrap[\'code\'], $tenantManaged)', $source);
        self::assertStringNotContainsString('FROM pa_module_installation', $source);
        self::assertStringNotContainsString('FROM pa_tenant_module', $source);
        self::assertStringContainsString('Official Module installation is incomplete.', $source);
        self::assertStringContainsString('Default Tenant Module selection is incomplete.', $source);
        self::assertStringContainsString('!$definitions->isRequiredTenantFoundation($moduleKey)', $source);
        self::assertStringContainsString('\\assertCurrentDatabase($pdo)', $source);
        $composition = file_get_contents((new ReflectionClass(AppService::class))->getFileName());
        self::assertStringContainsString('$this->app->make(\\PeanutAdmin\\Modules\\Identity\\Contract\\TenantModuleStateQueries::class)', $composition);
        $manifest = json_decode(file_get_contents(dirname(__DIR__, 2) . '/app/modules/official/identity/module.json'), true, 512, JSON_THROW_ON_ERROR);
        self::assertContains(TenantModuleStateQueries::class, $manifest['contracts']['exports']);
        $parameter = (new ReflectionMethod(InstallationExecutionHost::class, '__construct'))->getParameters()[5];
        self::assertSame('moduleStates', $parameter->getName());
        self::assertSame(TenantModuleStateQueries::class, (string) $parameter->getType());
        self::assertFalse($parameter->isOptional());
        self::assertFalse($parameter->allowsNull());
        self::assertSame('int', (string) (new ReflectionMethod($this->states, 'activeInstallationCount'))->getReturnType());
        self::assertSame('int', (string) (new ReflectionMethod($this->states, 'enabledTenantSelectionCount'))->getReturnType());
    }
}
