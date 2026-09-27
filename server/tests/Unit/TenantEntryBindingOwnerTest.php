<?php

declare(strict_types=1);

use app\common\exception\BusinessException;
use app\common\services\audit\AuditContractHost;
use PeanutAdmin\Kernel\Auth\ValidatedPlatformSession;
use PeanutAdmin\Kernel\Authorization\AuthorizationException;
use PeanutAdmin\Kernel\Authorization\EffectivePermissionSet;
use PeanutAdmin\Kernel\Authorization\RevisionPermissionCache;
use PeanutAdmin\Kernel\Context\PlatformContext;
use PeanutAdmin\Kernel\Platform\Authorization\PlatformAuthorizationEvaluator;
use PeanutAdmin\Kernel\Platform\Authorization\PlatformAuthorizationRepository;
use PeanutAdmin\Modules\Identity\Platform\Application\TenantEntryBindingAdminService;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;
use think\App;

/** Native authorization, owner queries and audit transactions over synthetic SQLite; no real domain binding. */
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class TenantEntryBindingOwnerTest extends TestCase
{
    private PDO $database;
    private TenantEntryBindingAdminService $service;
    private PlatformContext $context;
    private array $allowed = ['platform.tenant.read', 'platform.tenant.update'];

    protected function setUp(): void
    {
        $root = dirname(__DIR__, 3);
        require_once $root . '/server/vendor/topthink/framework/src/helper.php';
        $app = new App($root . '/.local/tmp/entry-binding-' . bin2hex(random_bytes(6)));
        $app->config->set(['default' => 'file', 'stores' => ['file' => ['type' => 'File', 'path' => $app->getRuntimePath() . 'cache/']]], 'cache');
        $this->database = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $this->database->sqliteCreateFunction('UTC_TIMESTAMP', static fn(int $precision): string => '2031-01-01 00:00:00.000', 1);
        ThinkPhpTestConnection::fromPdo($this->database);
        $this->database->exec(<<<'SQL'
            CREATE TABLE pa_tenant(id INTEGER PRIMARY KEY, code TEXT, name TEXT, status TEXT);
            INSERT INTO pa_tenant VALUES(1,'alpha','Alpha','active'),(2,'beta','Beta','active'),(3,'inactive','Inactive','suspended');
            CREATE TABLE pa_tenant_entry_binding(id INTEGER PRIMARY KEY AUTOINCREMENT, tenant_id INTEGER, host TEXT, client_key TEXT, status TEXT, created_at TEXT DEFAULT 'created', updated_at TEXT DEFAULT 'updated', UNIQUE(host,client_key));
            CREATE TABLE pa_platform_audit_event(id INTEGER PRIMARY KEY AUTOINCREMENT, event_type TEXT, action TEXT, outcome TEXT, reason_code TEXT, operator_id INTEGER, account_id INTEGER, target_type TEXT, target_id TEXT, request_id TEXT, operation_id TEXT, ip_address TEXT, user_agent_hash TEXT, before_json TEXT, after_json TEXT, metadata_json TEXT, occurred_at TEXT);
            SQL);
        $permissions = $this->createStub(PlatformAuthorizationRepository::class);
        $permissions->method('revision')->willReturnCallback(fn(int $id): string => hash('sha256', implode(',', $this->allowed)));
        $permissions->method('permissions')->willReturnCallback(fn(int $id): EffectivePermissionSet => new EffectivePermissionSet($this->allowed));
        $authorization = new PlatformAuthorizationEvaluator($permissions, new RevisionPermissionCache());
        $app->instance(PlatformAuthorizationEvaluator::class, $authorization);
        $app->instance(AuditContractHost::class, new AuditContractHost(null));
        $this->service = $app->make(TenantEntryBindingAdminService::class);
        $this->context = PlatformContext::fromValidatedSession(new ValidatedPlatformSession(1, 'fixture-session', 101, 11, 'platform-web', new DateTimeImmutable('2031-01-01T00:00:00Z')), 'entry-binding-test');
    }

    public function testOwnerIsPublicAndControllerUsesTheSameNativeContext(): void
    {
        $root = dirname(__DIR__, 2);
        $manifest = json_decode(file_get_contents($root . '/app/modules/official/identity/module.json'), true, 512, JSON_THROW_ON_ERROR);
        self::assertContains(TenantEntryBindingAdminService::class, $manifest['contracts']['exports']);
        self::assertFileDoesNotExist($root . '/app/platform/services/TenantEntryBindingAdminService.php');
        $controller = file_get_contents($root . '/app/platform/controller/PlatformTenantEntryBindingController.php');
        self::assertStringContainsString('use ' . TenantEntryBindingAdminService::class . ';', $controller);
        self::assertSame(3, substr_count($controller, '$this->platformContext->core'));
    }

    public function testEnableNormalizesHostAndAuditRetainsIdentityAndReason(): void
    {
        $result = $this->service->enable($this->context, 1, ' EXAMPLE.TEST.:443 ', ' admin-web ', ' operator request ');
        self::assertSame(['id' => 1, 'tenant_id' => 1, 'tenant_code' => 'alpha', 'tenant_name' => 'Alpha', 'host' => 'example.test', 'client_key' => 'admin-web', 'status' => 'active'], $result);
        $audit = $this->database->query('SELECT * FROM pa_platform_audit_event')->fetch(PDO::FETCH_ASSOC);
        self::assertSame('tenant.entry-binding.enabled', $audit['event_type']);
        self::assertSame('platform.tenant.update', $audit['action']);
        self::assertSame('entry-binding-test', $audit['request_id']);
        self::assertSame('operator request', $audit['reason_code']);
        self::assertSame([11, 101], [(int) $audit['operator_id'], (int) $audit['account_id']]);
        self::assertSame(['tenant_id' => 1, 'binding_id' => 1, 'host' => 'example.test', 'client_key' => 'admin-web'], json_decode($audit['metadata_json'], true));
    }

    public function testActiveForeignBindingConflictsButDisabledBindingCanBeReassigned(): void
    {
        $first = $this->service->enable($this->context, 1, 'entry.test', 'admin-web', 'create');
        try {
            $this->service->enable($this->context, 2, 'entry.test', 'admin-web', 'collision');
            self::fail('An active foreign entry was reassigned.');
        } catch (BusinessException $error) {
            self::assertSame('TENANT_ENTRY_BINDING_CONFLICT', $error->errorCode);
        }
        self::assertSame(1, (int) $this->database->query('SELECT COUNT(*) FROM pa_platform_audit_event')->fetchColumn());
        $disabled = $this->service->disable($this->context, $first['id'], 'disable');
        self::assertSame('disabled', $disabled['status']);
        $second = $this->service->enable($this->context, 2, 'entry.test', 'admin-web', 'reassign');
        self::assertSame($first['id'], $second['id']);
        self::assertSame(2, $second['tenant_id']);
        self::assertSame(3, (int) $this->database->query('SELECT COUNT(*) FROM pa_platform_audit_event')->fetchColumn());
    }

    public function testClientIsolationRepeatedOperationsAndTenantFilterArePreserved(): void
    {
        $first = $this->service->enable($this->context, 1, 'same.test', 'admin-web', 'create');
        $again = $this->service->enable($this->context, 1, 'same.test', 'admin-web', 'repeat');
        self::assertSame($first, $again);
        $this->service->enable($this->context, 2, 'same.test', 'member-api', 'separate client');
        self::assertCount(2, $this->service->lists($this->context));
        self::assertCount(1, $this->service->lists($this->context, 2));
        $this->service->disable($this->context, $first['id'], 'first disable');
        $this->service->disable($this->context, $first['id'], 'repeat disable');
        self::assertSame('disabled', $this->service->lists($this->context, 1)[0]['status']);
        self::assertSame(5, (int) $this->database->query('SELECT COUNT(*) FROM pa_platform_audit_event')->fetchColumn());
    }

    public function testPermissionsPrecedeAllReadsAndInputValidation(): void
    {
        $this->allowed = [];
        $this->database->exec('DROP TABLE pa_tenant_entry_binding');
        foreach ([fn() => $this->service->lists($this->context), fn() => $this->service->enable($this->context, 0, '', '', ''), fn() => $this->service->disable($this->context, 0, '')] as $operation) {
            try {
                $operation();
                self::fail('Unauthorized entry operation was accepted.');
            } catch (AuthorizationException) {
                self::assertTrue(true);
            }
        }
    }

    public function testMissingTenantInvalidClientAndMissingBindingKeepTheirErrorCodes(): void
    {
        foreach ([
            ['TENANT_ENTRY_TENANT_UNAVAILABLE', fn() => $this->service->enable($this->context, 3, 'entry.test', 'admin-web', 'inactive')],
            ['TENANT_ENTRY_TENANT_UNAVAILABLE', fn() => $this->service->enable($this->context, 999, 'entry.test', 'admin-web', 'missing')],
            ['TENANT_ENTRY_CLIENT_INVALID', fn() => $this->service->enable($this->context, 1, 'entry.test', 'unknown-client', 'client')],
            ['TENANT_ENTRY_INPUT_INVALID', fn() => $this->service->enable($this->context, 1, 'entry.test', 'admin-web', ' ')],
            ['TENANT_ENTRY_BINDING_NOT_FOUND', fn() => $this->service->disable($this->context, 999, 'missing')],
        ] as [$code, $operation]) {
            try {
                $operation();
                self::fail('Invalid entry operation was accepted.');
            } catch (BusinessException $error) {
                self::assertSame($code, $error->errorCode);
                self::assertSame(409, $error->httpStatus);
            }
        }
        self::assertSame(0, (int) $this->database->query('SELECT COUNT(*) FROM pa_platform_audit_event')->fetchColumn());
    }

    public function testAuditFailureRollsBackEnableAndDisable(): void
    {
        $first = $this->service->enable($this->context, 1, 'existing.test', 'admin-web', 'seed');
        $this->database->exec("CREATE TRIGGER reject_fixture_audit BEFORE INSERT ON pa_platform_audit_event BEGIN SELECT RAISE(ABORT, 'fixture audit failure'); END");
        foreach ([fn() => $this->service->enable($this->context, 2, 'new.test', 'admin-web', 'fail'), fn() => $this->service->disable($this->context, $first['id'], 'fail')] as $operation) {
            try {
                $operation();
                self::fail('Audit failure was ignored.');
            } catch (think\db\exception\PDOException) {
                self::assertSame([['tenant_id' => 1, 'host' => 'existing.test', 'status' => 'active']], $this->database->query('SELECT tenant_id,host,status FROM pa_tenant_entry_binding')->fetchAll(PDO::FETCH_ASSOC));
            }
        }
    }

    public function testOuterTransactionFailureRollsBackBothBindingAndAudit(): void
    {
        try {
            think\facade\Db::transaction(function (): void {
                $this->service->enable($this->context, 1, 'rolled-back.test', 'admin-web', 'fixture');
                throw new DomainException('FIXTURE_OUTER_FAILURE');
            });
            self::fail('Outer failure was ignored.');
        } catch (DomainException $error) {
            self::assertSame('FIXTURE_OUTER_FAILURE', $error->getMessage());
        }
        self::assertSame(0, (int) $this->database->query('SELECT COUNT(*) FROM pa_tenant_entry_binding')->fetchColumn());
        self::assertSame(0, (int) $this->database->query('SELECT COUNT(*) FROM pa_platform_audit_event')->fetchColumn());
    }
}
