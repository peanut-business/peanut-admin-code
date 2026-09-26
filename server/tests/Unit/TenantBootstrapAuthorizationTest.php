<?php

declare(strict_types=1);

namespace tests\Unit;

use app\common\execution\CurrentExecutionContext;
use app\common\execution\ExecutionContextStore;
use app\common\execution\SystemExecutionContext;
use DomainException;
use PDO;
use PeanutAdmin\Kernel\Context\TenantSystemContext;
use PeanutAdmin\Modules\Identity\Audit\AuditService;
use PeanutAdmin\Modules\Identity\Contract\TenantAuthorizationCommands;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;
use think\App;

/** 验证真实 ORM 授权、修订与审计事务；SQLite 不证明 MySQL 行锁或并发资格。 */
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class TenantBootstrapAuthorizationTest extends TestCase
{
    private PDO $database;
    private ExecutionContextStore $contexts;
    private TenantAuthorizationCommands $commands;

    protected function setUp(): void
    {
        $root = dirname(__DIR__, 3);
        $temporaryRoot = $root . '/.local/tmp/tenant-bootstrap-authorization-tests';
        if (!is_dir($temporaryRoot) && !mkdir($temporaryRoot, 0700, true) && !is_dir($temporaryRoot)) {
            throw new \RuntimeException('TENANT_BOOTSTRAP_TEST_DIRECTORY_UNAVAILABLE');
        }
        $temporary = realpath($temporaryRoot);
        self::assertIsString($temporary);
        self::assertStringStartsWith($root . '/.local/tmp/', $temporary);
        require_once $root . '/server/vendor/topthink/framework/src/helper.php';
        new App($temporary . '/bootstrap-authorization-' . bin2hex(random_bytes(6)));
        $this->database = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $this->database->sqliteCreateFunction('UTC_TIMESTAMP', static fn(int $precision): string => '2026-09-26 00:00:00.000', 1);
        \ThinkPhpTestConnection::fromPdo($this->database);
        $this->database->exec(<<<'SQL'
            CREATE TABLE pa_tenant (id INTEGER PRIMARY KEY, status TEXT, authorization_revision INTEGER);
            CREATE TABLE pa_account (id INTEGER PRIMARY KEY, status TEXT);
            CREATE TABLE pa_tenant_member (id INTEGER PRIMARY KEY, tenant_id INTEGER, account_id INTEGER, status TEXT, authorization_revision INTEGER);
            CREATE TABLE pa_role (id INTEGER PRIMARY KEY, tenant_id INTEGER, "key" TEXT, is_builtin INTEGER, status TEXT);
            CREATE TABLE pa_member_role (tenant_id INTEGER, tenant_member_id INTEGER, role_id INTEGER);
            CREATE TABLE pa_permission (id INTEGER PRIMARY KEY, module_key TEXT, "key" TEXT, status TEXT);
            CREATE TABLE pa_role_permission (id INTEGER PRIMARY KEY AUTOINCREMENT, tenant_id INTEGER, role_id INTEGER, permission_id INTEGER, granted_by_member_id INTEGER, granted_at TEXT, UNIQUE(tenant_id, role_id, permission_id));
            CREATE TABLE pa_tenant_audit_event (id INTEGER PRIMARY KEY AUTOINCREMENT, tenant_id INTEGER, event_type TEXT, action TEXT, outcome TEXT, actor_tenant_id INTEGER, actor_type TEXT, target_count INTEGER, request_id TEXT, metadata_json TEXT, occurred_at TEXT);
            INSERT INTO pa_tenant VALUES (1, 'active', 7), (2, 'active', 9);
            INSERT INTO pa_account VALUES (101, 'active'), (102, 'active'), (202, 'active');
            INSERT INTO pa_tenant_member VALUES (11, 1, 101, 'active', 3), (12, 1, 102, 'active', 5), (22, 2, 202, 'active', 8);
            INSERT INTO pa_role VALUES (31, 1, 'core.tenant-owner', 1, 'active'), (32, 2, 'core.tenant-owner', 1, 'active');
            INSERT INTO pa_member_role VALUES (1, 11, 31), (1, 12, 31), (2, 22, 32);
            INSERT INTO pa_permission VALUES (41, 'peanut.admin', 'article.edit', 'active'), (42, 'peanut.admin', 'article.delete', 'inactive'), (43, 'official.article', 'article.read', 'active');
            SQL);
        $this->contexts = new ExecutionContextStore();
        $this->commands = new TenantAuthorizationCommands(new CurrentExecutionContext($this->contexts), new AuditService());
    }

    private function system(int $tenantId = 1, string $actor = 'platform.tenant-bootstrap', string $operation = 'identity.bootstrap-owner-permissions'): SystemExecutionContext
    {
        return new SystemExecutionContext(new TenantSystemContext($tenantId, $actor, $operation, 'synthetic-bootstrap'));
    }

    private function grant(int $tenantId = 1, int $memberId = 11, int $roleId = 31, string $moduleKey = 'peanut.admin'): void
    {
        $this->commands->grantActiveModulePermissions($tenantId, $memberId, $roleId, $moduleKey);
    }

    private function snapshot(): array
    {
        return [
            $this->database->query('SELECT * FROM pa_role_permission ORDER BY id')->fetchAll(PDO::FETCH_ASSOC),
            $this->database->query('SELECT id, authorization_revision FROM pa_tenant_member ORDER BY id')->fetchAll(PDO::FETCH_ASSOC),
            $this->database->query('SELECT id, authorization_revision FROM pa_tenant ORDER BY id')->fetchAll(PDO::FETCH_ASSOC),
            $this->database->query('SELECT * FROM pa_tenant_audit_event ORDER BY id')->fetchAll(PDO::FETCH_ASSOC),
        ];
    }

    private function assertDeniedWithoutWrites(callable $operation): void
    {
        $before = $this->snapshot();
        $failure = null;
        try {
            $operation();
        } catch (DomainException $exception) {
            $failure = $exception;
        }
        self::assertInstanceOf(DomainException::class, $failure);
        self::assertSame($before, $this->snapshot());
        self::assertTrue($this->contexts->isEmpty());
    }

    public function testAuthorizedBootstrapIsAtomicIdempotentAndTenantScoped(): void
    {
        $this->contexts->run($this->system(), fn() => $this->grant());
        self::assertSame([[1, 31, 41, 11]], $this->database->query('SELECT tenant_id, role_id, permission_id, granted_by_member_id FROM pa_role_permission')->fetchAll(PDO::FETCH_NUM));
        self::assertSame([4, 6, 8], $this->database->query('SELECT authorization_revision FROM pa_tenant_member ORDER BY id')->fetchAll(PDO::FETCH_COLUMN));
        self::assertSame([8, 9], $this->database->query('SELECT authorization_revision FROM pa_tenant ORDER BY id')->fetchAll(PDO::FETCH_COLUMN));
        $audit = $this->database->query('SELECT tenant_id, actor_tenant_id, actor_type, request_id, metadata_json FROM pa_tenant_audit_event')->fetch(PDO::FETCH_ASSOC);
        self::assertSame(1, $audit['tenant_id']);
        self::assertSame(1, $audit['actor_tenant_id']);
        self::assertSame('tenant_system', $audit['actor_type']);
        self::assertSame('synthetic-bootstrap', $audit['request_id']);
        self::assertSame([41], json_decode($audit['metadata_json'], true, 512, JSON_THROW_ON_ERROR)['permission_ids']);
        $once = $this->snapshot();
        $this->contexts->run($this->system(), fn() => $this->grant());
        self::assertSame($once, $this->snapshot());
        self::assertTrue($this->contexts->isEmpty());
    }

    public function testNativeModuleCatalogFixtureConstructsTheCurrentDependencies(): void
    {
        self::assertInstanceOf(\app\platform\infrastructure\plugin\ModuleCatalogApplier::class, \ThinkPhpTestConnection::moduleCatalogs($this->database));
    }

    public function testProvisioningTenantKeepsTheExistingOwnerInvitationContract(): void
    {
        $this->database->exec("UPDATE pa_tenant SET status = 'provisioning' WHERE id = 1");
        $this->contexts->run($this->system(), fn() => $this->grant());
        self::assertSame(1, (int) $this->database->query('SELECT COUNT(*) FROM pa_role_permission')->fetchColumn());
    }

    public function testMissingSystemContextCannotGrantPermissions(): void
    {
        $this->assertDeniedWithoutWrites(fn() => $this->grant());
    }

    public static function invalidContexts(): array
    {
        return [
            'foreign tenant' => [2, 'platform.tenant-bootstrap', 'identity.bootstrap-owner-permissions'],
            'wrong actor' => [1, 'scheduler', 'identity.bootstrap-owner-permissions'],
            'wrong purpose' => [1, 'platform.tenant-bootstrap', 'notification.provision-tenant-defaults'],
        ];
    }

    #[DataProvider('invalidContexts')]
    public function testContextIdentityAndPurposeAreRequired(int $tenantId, string $actor, string $operation): void
    {
        $this->assertDeniedWithoutWrites(fn() => $this->contexts->run($this->system($tenantId, $actor, $operation), fn() => $this->grant()));
    }

    public static function invalidTargets(): array
    {
        return [
            'foreign member' => [1, 22, 31, 'peanut.admin'],
            'foreign role' => [1, 11, 32, 'peanut.admin'],
            'missing member' => [1, 999, 31, 'peanut.admin'],
            'another module' => [1, 11, 31, 'official.article'],
            'zero tenant' => [0, 11, 31, 'peanut.admin'],
        ];
    }

    #[DataProvider('invalidTargets')]
    public function testCannotGrantToUnrelatedObjectsOrArbitraryModules(int $tenantId, int $memberId, int $roleId, string $moduleKey): void
    {
        $this->assertDeniedWithoutWrites(fn() => $this->contexts->run($this->system(), fn() => $this->grant($tenantId, $memberId, $roleId, $moduleKey)));
    }

    public static function revokedOwnership(): array
    {
        return [
            'suspended tenant' => ["UPDATE pa_tenant SET status = 'suspended' WHERE id = 1"],
            'disabled account' => ["UPDATE pa_account SET status = 'disabled' WHERE id = 101"],
            'disabled member' => ["UPDATE pa_tenant_member SET status = 'disabled' WHERE id = 11"],
            'disabled role' => ["UPDATE pa_role SET status = 'disabled' WHERE id = 31"],
            'ordinary role' => ["UPDATE pa_role SET \"key\" = 'viewer', is_builtin = 0 WHERE id = 31"],
            'owner revoked' => ['DELETE FROM pa_member_role WHERE tenant_member_id = 11'],
        ];
    }

    #[DataProvider('revokedOwnership')]
    public function testRevalidatesOwnerStateBeforeWriting(string $mutation): void
    {
        $this->database->exec($mutation);
        $this->assertDeniedWithoutWrites(fn() => $this->contexts->run($this->system(), fn() => $this->grant()));
    }

    public function testAuditFailureRollsBackPermissionsAndAuthorizationRevisions(): void
    {
        $this->database->exec("CREATE TRIGGER reject_audit BEFORE INSERT ON pa_tenant_audit_event BEGIN SELECT RAISE(ABORT, 'SYNTHETIC_AUDIT_FAILURE'); END");
        $before = $this->snapshot();
        $failure = null;
        try {
            $this->contexts->run($this->system(), fn() => $this->grant());
        } catch (\Throwable $exception) {
            $failure = $exception;
        }
        self::assertNotNull($failure);
        self::assertStringContainsString('SYNTHETIC_AUDIT_FAILURE', $failure->getMessage());
        self::assertSame($before, $this->snapshot());
        self::assertTrue($this->contexts->isEmpty());
    }
}
