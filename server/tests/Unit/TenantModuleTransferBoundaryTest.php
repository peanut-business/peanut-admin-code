<?php

declare(strict_types=1);

namespace tests\Unit;

use app\platform\validation\module\OpisTenantModuleConfigValidator;
use DateTimeImmutable;
use PDO;
use PeanutAdmin\Kernel\Auth\TenantContext;
use PeanutAdmin\Kernel\Auth\ValidatedTenantSession;
use PeanutAdmin\Kernel\Authorization\Application\AdminAccessException;
use PeanutAdmin\Kernel\Module\CompiledModuleRegistry;
use PeanutAdmin\Kernel\Module\ManifestDocument;
use PeanutAdmin\Kernel\Module\ModuleException;
use PeanutAdmin\Modules\Identity\Audit\AuditService;
use PeanutAdmin\Modules\Identity\Module\Persistence\ThinkPhpModuleRuntimeRepository;
use PeanutAdmin\Modules\Identity\Module\TenantModuleConfigurationService;
use PeanutAdmin\Modules\ImportExport\Infrastructure\configuration\TenantModuleConfigurationAdapter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use RuntimeException;
use think\App;
use think\facade\Db;

/** Actual application validator, runtime repository, ORM and audit; synthetic SQLite, not MySQL locking. */
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class TenantModuleTransferBoundaryTest extends TestCase
{
    private PDO $database;
    private TenantModuleConfigurationService $service;
    private TenantModuleConfigurationAdapter $adapter;

    protected function setUp(): void
    {
        $root = dirname(__DIR__, 3);
        $temporary = $root . '/.local/tmp/tenant-module-transfer-tests';
        if (!is_dir($temporary) && !mkdir($temporary, 0700, true) && !is_dir($temporary)) {
            throw new RuntimeException('MODULE_TRANSFER_TEST_DIRECTORY_UNAVAILABLE');
        }
        self::assertStringStartsWith($root . '/.local/tmp/', (string) realpath($temporary));
        $moduleRoot = $temporary . '/case-' . bin2hex(random_bytes(6));
        self::assertTrue(mkdir($moduleRoot, 0700));
        file_put_contents($moduleRoot . '/config-schema.json', '{"type":"object","properties":{"mode":{"enum":["strict","relaxed"]},"api_secret":{"type":"string"}},"required":["mode"],"additionalProperties":false}');
        require_once $root . '/server/vendor/topthink/framework/src/helper.php';
        new App($moduleRoot);
        $this->database = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $this->database->sqliteCreateFunction('UTC_TIMESTAMP', static fn(int $precision): string => '2026-09-26 00:00:00.000', 1);
        \ThinkPhpTestConnection::fromPdo($this->database);
        $this->database->exec(<<<'SQL'
            CREATE TABLE pa_tenant (id INTEGER PRIMARY KEY, status TEXT, authorization_revision INTEGER, revision INTEGER, updated_at TEXT);
            CREATE TABLE pa_module_installation (id INTEGER PRIMARY KEY, module_key TEXT, installed_version TEXT, manifest_schema_version INTEGER, status TEXT, revision INTEGER, manifest_digest TEXT);
            CREATE TABLE pa_tenant_module (id INTEGER PRIMARY KEY, tenant_id INTEGER, module_key TEXT, status TEXT, source TEXT, config_json TEXT, config_revision INTEGER, authorization_revision INTEGER, effective_at TEXT, expires_at TEXT, enabled_at TEXT, disabled_at TEXT, updated_at TEXT, UNIQUE(tenant_id,module_key));
            CREATE TABLE pa_tenant_audit_event (id INTEGER PRIMARY KEY AUTOINCREMENT, tenant_id INTEGER, event_type TEXT, action TEXT, outcome TEXT, actor_tenant_id INTEGER, actor_tenant_member_id INTEGER, actor_account_id INTEGER, actor_type TEXT, target_resource_type TEXT, target_resource_id TEXT, boundary_target_type TEXT, boundary_target_id TEXT, target_count INTEGER, target_set_digest TEXT, request_id TEXT, metadata_json TEXT, occurred_at TEXT);
            INSERT INTO pa_tenant VALUES (1,'active',7,1,NULL),(2,'active',9,1,NULL);
            INSERT INTO pa_tenant_module VALUES (1,1,'example.configured','enabled','manual','{"mode":"strict","api_secret":"synthetic-secret"}',1,3,NULL,NULL,NULL,NULL,NULL),(2,2,'example.configured','enabled','manual','{"mode":"relaxed"}',4,6,NULL,NULL,NULL,NULL,NULL);
            SQL);
        $manifest = ManifestDocument::fromArray($moduleRoot, ['schema_version' => 1, 'key' => 'example.configured', 'version' => '1.0.0', 'backend' => ['config_schema' => 'config-schema.json'], 'tenant' => ['requires' => []]]);
        $registry = new CompiledModuleRegistry([$manifest], [], [], [], $manifest->digest);
        $this->database->prepare('INSERT INTO pa_module_installation VALUES (1,?,?,?,?,?,?)')->execute(['example.configured', '1.0.0', 1, 'active', 1, $manifest->digest]);
        $this->service = new TenantModuleConfigurationService($registry, new OpisTenantModuleConfigValidator(), new ThinkPhpModuleRuntimeRepository($registry), new AuditService());
        $this->adapter = new TenantModuleConfigurationAdapter($this->service);
    }

    private function actor(int $tenantId = 1): TenantContext
    {
        return TenantContext::fromValidatedSession(new ValidatedTenantSession(
            $tenantId,
            'synthetic-session',
            $tenantId,
            101,
            201,
            'admin-web',
            new DateTimeImmutable('2099-01-01T00:00:00Z'),
            3,
        ), 'synthetic-module-transfer');
    }

    private function snapshot(): array
    {
        return [
            $this->database->query('SELECT * FROM pa_tenant_module ORDER BY id')->fetchAll(PDO::FETCH_ASSOC),
            $this->database->query('SELECT * FROM pa_tenant ORDER BY id')->fetchAll(PDO::FETCH_ASSOC),
            $this->database->query('SELECT * FROM pa_tenant_audit_event ORDER BY id')->fetchAll(PDO::FETCH_ASSOC),
        ];
    }

    public function testReadBoundaryStaysInIdentityAndAdapterHasNoPrivateTableAccess(): void
    {
        $source = (string) file_get_contents((new ReflectionClass($this->adapter))->getFileName());
        self::assertStringNotContainsString('Db::', $source);
        self::assertStringNotContainsString('tenant_module', $source);
        self::assertTrue(method_exists($this->service, 'transferCurrent'));
        self::assertTrue(method_exists($this->service, 'transferSnapshot'));
    }

    public function testExportAndCurrentAreTenantScopedAndSecretSafe(): void
    {
        self::assertTrue(method_exists($this->service, 'transferSnapshot'));
        $rows = $this->adapter->export($this->actor());
        self::assertCount(1, $rows);
        self::assertSame('strict', $rows[0]['value']['mode']);
        self::assertArrayHasKey('$secret', $rows[0]['value']['api_secret']);
        self::assertStringNotContainsString('synthetic-secret', json_encode($rows, JSON_THROW_ON_ERROR));
        self::assertSame('relaxed', $this->adapter->current($this->actor(2), 'example.configured')['value']['mode']);
        self::assertSame(['exists' => false, 'value' => null, 'revision' => null], $this->adapter->current($this->actor(999), 'example.configured'));
        self::assertFalse($this->adapter->supportsCreate());
    }

    public function testUpdateUsesNativeSchemaRevisionAuthorizationRevisionAndAudit(): void
    {
        $before = $this->snapshot();
        $this->adapter->apply($this->actor(), 'example.configured', ['mode' => 'relaxed'], [], 1);
        $state = $this->adapter->current($this->actor(), 'example.configured');
        self::assertSame(2, $state['revision']);
        self::assertSame(['mode' => 'relaxed'], $state['value']);
        $after = $this->snapshot();
        self::assertSame(4, $after[0][0]['authorization_revision']);
        self::assertSame(8, $after[1][0]['authorization_revision']);
        self::assertSame($before[0][1], $after[0][1]);
        self::assertCount(1, $after[2]);
        self::assertSame('tenant.module.configured', $after[2][0]['event_type']);
    }

    public function testStaleRevisionDoesNotMutateState(): void
    {
        $before = $this->snapshot();
        try {
            $this->adapter->apply($this->actor(), 'example.configured', ['mode' => 'relaxed'], [], 0);
            self::fail('Stale revision was accepted');
        } catch (AdminAccessException $exception) {
            self::assertSame('REVISION_MISMATCH', $exception->errorCode);
            self::assertSame($before, $this->snapshot());
        }
    }

    public static function ineffectiveStates(): array
    {
        return [
            ['status', 'disabled'],
            ['effective_at', '2099-01-01 00:00:00.000'],
            ['expires_at', '2000-01-01 00:00:00.000'],
            ['expires_at', 'not-a-date'],
        ];
    }

    #[DataProvider('ineffectiveStates')]
    public function testIneffectiveModulesCannotBeImported(string $field, string $value): void
    {
        $this->database->prepare('UPDATE pa_tenant_module SET ' . $field . ' = ? WHERE tenant_id = 1')->execute([$value]);
        self::assertFalse($this->adapter->current($this->actor(), 'example.configured')['exists']);
        $before = $this->snapshot();
        try {
            $this->adapter->apply($this->actor(), 'example.configured', ['mode' => 'relaxed'], [], 1);
            self::fail('Ineffective module was imported');
        } catch (RuntimeException $exception) {
            self::assertSame('TRANSFER_TENANT_MODULE_NOT_ENABLED', $exception->getMessage());
            self::assertSame($before, $this->snapshot());
        }
    }

    public function testNativeSchemaFailureDoesNotWriteOrAudit(): void
    {
        $before = $this->snapshot();
        try {
            $this->adapter->apply($this->actor(), 'example.configured', ['mode' => 'invalid'], [], 1);
            self::fail('Invalid configuration was accepted');
        } catch (ModuleException $exception) {
            self::assertSame('MODULE_CONFIG_INVALID', $exception->errorCode);
            self::assertSame($before, $this->snapshot());
        }
    }

    public function testAuditFailureRollsBackConfigurationAndAuthorizationRevisions(): void
    {
        $this->database->exec("CREATE TRIGGER reject_audit BEFORE INSERT ON pa_tenant_audit_event BEGIN SELECT RAISE(ABORT, 'synthetic audit failure'); END;");
        $before = $this->snapshot();
        $failure = null;
        try {
            $this->adapter->apply($this->actor(), 'example.configured', ['mode' => 'relaxed'], [], 1);
        } catch (\Throwable $exception) {
            $failure = $exception;
        }
        self::assertNotNull($failure);
        self::assertStringContainsString('synthetic audit failure', $failure->getMessage());
        self::assertSame($before, $this->snapshot());
    }

    public function testOuterFailureAlsoRollsBackAuditAndRevisions(): void
    {
        $before = $this->snapshot();
        try {
            Db::transaction(function (): void {
                $this->adapter->apply($this->actor(), 'example.configured', ['mode' => 'relaxed'], [], 1);
                throw new RuntimeException('synthetic outer failure');
            });
        } catch (RuntimeException $exception) {
            self::assertSame('synthetic outer failure', $exception->getMessage());
        }
        self::assertSame($before, $this->snapshot());
    }
}
