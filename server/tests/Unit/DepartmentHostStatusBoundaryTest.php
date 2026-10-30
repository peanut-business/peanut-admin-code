<?php

declare(strict_types=1);

namespace tests\Unit;

use app\adminapi\services\dept\DeptApplicationService;
use app\common\contract\AdminPermissionPolicy;
use app\common\execution\AdminExecutionContext;
use app\common\execution\CurrentExecutionContext;
use app\common\execution\ExecutionContextStore;
use app\common\infrastructure\authorization\CoreTenantModuleAdminBridge;
use app\common\services\authorization\AdminAuthorizationService;
use app\platform\infrastructure\module\ThinkPhpModuleGovernanceProvider;
use DateTimeImmutable;
use PDO;
use PeanutAdmin\Kernel\Auth\TenantContext;
use PeanutAdmin\Kernel\Auth\ValidatedTenantSession;
use PeanutAdmin\Modules\Identity\Audit\AuditService;
use PeanutAdmin\Modules\Identity\Organization\Application\DepartmentAdminService;
use PeanutAdmin\Modules\Identity\Contract\AdminDirectoryQuery;
use PeanutAdmin\Modules\Identity\Contract\TenantAuthorizationQuery;
use PeanutAdmin\Modules\Identity\Contract\TenantMemberDirectory;
use PeanutAdmin\Modules\Identity\Contract\Dto\TenantMemberSummary;
use PeanutAdmin\Modules\Identity\Menu\MenuAdministrationService;
use PeanutAdmin\Kernel\Authorization\TenantAuthorizationRepository;
use PeanutAdmin\Kernel\Authorization\EffectivePermissionSet;
use PeanutAdmin\Kernel\Menu\MenuCatalogRepository;
use PHPUnit\Framework\TestCase;
use think\App;

/** 原宿主与Identity用例在SQLite实际执行，不替代人员权限或MySQL资格。 */
final class DepartmentHostStatusBoundaryTest extends TestCase
{
    private PDO $pdo;
    private DeptApplicationService $host;
    private DepartmentAdminService $owner;
    private TenantContext $actor;
    private ExecutionContextStore $contexts;

    protected function setUp(): void
    {
        $root = dirname(__DIR__, 3);
        require_once $root . '/server/vendor/topthink/framework/src/helper.php';
        new App($root . '/.local/tmp/department-host-status');
        $this->pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $this->pdo->sqliteCreateFunction('UTC_TIMESTAMP', static fn(int $precision): string => '2031-01-02 00:00:00.000', 1);
        \ThinkPhpTestConnection::fromPdo($this->pdo);
        $this->pdo->exec(<<<'SQL'
            CREATE TABLE pa_tenant (id INTEGER PRIMARY KEY,status TEXT,authorization_revision INTEGER,updated_at TEXT);
            CREATE TABLE pa_plan (id INTEGER PRIMARY KEY,code TEXT,name TEXT,revision INTEGER);
            CREATE TABLE pa_tenant_license (id INTEGER PRIMARY KEY,tenant_id INTEGER,plan_id INTEGER);
            INSERT INTO pa_tenant VALUES (101,'active',1,NULL),(202,'active',1,NULL);
            CREATE TABLE pa_department (id INTEGER PRIMARY KEY AUTOINCREMENT,tenant_id INTEGER,parent_id INTEGER,code TEXT,name TEXT,leader TEXT,leader_member_id INTEGER,mobile TEXT,sort_order INTEGER,status TEXT,revision INTEGER DEFAULT 1,created_at TEXT,updated_at TEXT);
            CREATE TABLE pa_tenant_member (id INTEGER PRIMARY KEY,tenant_id INTEGER,primary_department_id INTEGER,status TEXT);
            CREATE TABLE pa_tenant_audit_event (id INTEGER PRIMARY KEY AUTOINCREMENT,tenant_id INTEGER,actor_type TEXT,tenant_member_id INTEGER,platform_operator_id INTEGER,account_id INTEGER,event_type TEXT,action TEXT,outcome TEXT,reason_code TEXT,target_resource_type TEXT,target_resource_id TEXT,request_id TEXT,operation_id TEXT,ip_address TEXT,user_agent_hash TEXT,before_json TEXT,after_json TEXT,metadata_json TEXT,occurred_at TEXT);
            ALTER TABLE pa_tenant ADD COLUMN name TEXT DEFAULT 'Unit tenant';
            CREATE TABLE pa_account (id INTEGER PRIMARY KEY,status TEXT,avatar_uri TEXT,last_login_at TEXT);
            INSERT INTO pa_account VALUES (501,'active','',NULL);
            CREATE TABLE pa_credential (id INTEGER PRIMARY KEY,account_id INTEGER,kind TEXT,identifier_type TEXT,status TEXT,identifier_normalized TEXT);
            INSERT INTO pa_credential VALUES (1,501,'email_password','email','active','department@unit.invalid');
            INSERT INTO pa_tenant_member (id,tenant_id,status) VALUES (601,101,'active');
            ALTER TABLE pa_tenant_member ADD COLUMN account_id INTEGER DEFAULT 501;
            ALTER TABLE pa_tenant_member ADD COLUMN display_name TEXT DEFAULT 'Department actor';
            CREATE TABLE pa_member_role (id INTEGER PRIMARY KEY,tenant_id INTEGER,tenant_member_id INTEGER,role_id INTEGER);
            CREATE TABLE pa_role (id INTEGER PRIMARY KEY,tenant_id INTEGER,"key" TEXT,name TEXT,is_builtin INTEGER,status TEXT);
            CREATE TABLE pa_permission (id INTEGER PRIMARY KEY,"key" TEXT,module_key TEXT,status TEXT);
            INSERT INTO pa_permission VALUES (1,'core.department.create','core','active'),(2,'core.department.update','core','active'),(3,'core.department.move','core','active');
            SQL);
        $this->contexts = new ExecutionContextStore();
        $current = new CurrentExecutionContext($this->contexts);
        $members = $this->createStub(TenantMemberDirectory::class);
        $members->method('activeMembership')->willReturnCallback(static fn(int $tenant, int $member): ?TenantMemberSummary =>
            $tenant === 101 && $member === 601 ? new TenantMemberSummary(101, 601, 501, 'Department actor', 1, 1, 1, 1) : null);
        $members->method('activeMembershipCount')->willReturn(1);
        $identity = new TenantAuthorizationQuery($members);
        $grants = $this->createStub(TenantAuthorizationRepository::class);
        $grants->method('permissions')->willReturn(new EffectivePermissionSet([
            'core.department.create', 'core.department.update', 'core.department.move',
        ]));
        $bridge = new CoreTenantModuleAdminBridge(
            (new \ReflectionClass(ThinkPhpModuleGovernanceProvider::class))->newInstanceWithoutConstructor(),
            $grants,
            $this->createStub(MenuCatalogRepository::class), $identity, new MenuAdministrationService(),
            new \PeanutAdmin\Modules\Identity\Module\TenantEntitlementService(
                new \PeanutAdmin\Kernel\Module\CompiledModuleRegistry([], [], [], [], 'fixture-department'),
            ),
        );
        $policy = $this->createStub(AdminPermissionPolicy::class);
        $policy->method('canAccess')->willReturnCallback(static fn(bool $root, string $uri, iterable $registered, iterable $granted): bool =>
            in_array($uri, [...$registered], true) && in_array($uri, [...$granted], true));
        $this->owner = new DepartmentAdminService(new AuditService());
        $this->host = new DeptApplicationService($this->owner, $current, new AdminAuthorizationService(
            $bridge, $policy, $members, new AdminDirectoryQuery($current), $identity,
            new \app\common\services\authorization\EndpointPermissionGrant(new \think\Request()),
        ));
        $this->actor = TenantContext::fromValidatedSession(new ValidatedTenantSession(1, 'dept-status', 101, 501, 601, 'admin-web', new DateTimeImmutable('2035-01-01'), 1), 'dept-status-request');
    }

    private function runAs(callable $operation): mixed
    {
        return $this->contexts->run(new AdminExecutionContext($this->actor, 'department.status.unit', [
            'id' => 601, 'tenant_id' => 101, 'account_id' => 501, 'authorization_revision' => 1,
        ]), $operation);
    }

    public function testDisabledCreationUsesDeclaredOwnerAndCurrentRevision(): void
    {
        self::assertTrue($this->runAs(fn() => $this->host->add($this->actor, ['name' => 'Disabled', 'pid' => 0, 'sort' => 1, 'status' => 0])));
        $row = $this->pdo->query('SELECT * FROM pa_department')->fetch(PDO::FETCH_ASSOC);
        self::assertSame('disabled', $row['status']);
        self::assertSame(2, $row['revision']);
        self::assertSame(2, (int) $this->pdo->query('SELECT COUNT(*) FROM pa_tenant_audit_event')->fetchColumn());
    }

    public function testEditUsesRevisionAfterMetadataAndMoveThenUpdatesStatus(): void
    {
        $parent = $this->owner->create($this->actor, 'parent', 'Parent', null, 0, '', '');
        $row = $this->owner->create($this->actor, 'child', 'Child', null, 0, '', '');
        self::assertTrue($this->runAs(fn() => $this->host->edit($this->actor, ['id' => (int) $row['id'], 'name' => 'Edited', 'pid' => (int) $parent['id'], 'sort' => 2, 'status' => 0])));
        $after = $this->owner->get(101, (int) $row['id']);
        self::assertSame('disabled', $after['status']);
        self::assertSame($parent['id'], $after['parent_id']);
        self::assertSame('Edited', $after['name']);
        self::assertSame('4', $after['revision']);
    }

    public function testFailedStatusChangeRollsBackWholeCreateAndEdit(): void
    {
        $row = $this->owner->create($this->actor, 'existing', 'Original', null, 0, '', '');
        $before = $this->pdo->query('SELECT * FROM pa_department ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
        $audits = (int) $this->pdo->query('SELECT COUNT(*) FROM pa_tenant_audit_event')->fetchColumn();
        $this->pdo->exec("CREATE TRIGGER reject_status BEFORE UPDATE ON pa_department WHEN NEW.status='disabled' BEGIN SELECT RAISE(ABORT,'status-write-fail'); END");
        foreach ([
            fn() => $this->runAs(fn() => $this->host->add($this->actor, ['name' => 'Failed', 'pid' => 0, 'status' => 0])),
            fn() => $this->runAs(fn() => $this->host->edit($this->actor, ['id' => (int) $row['id'], 'name' => 'Failed edit', 'pid' => 0, 'status' => 0])),
        ] as $operation) {
            try {
                $operation();
                self::fail('Rejected status change committed.');
            } catch (\Throwable $failure) {
                if ($failure instanceof \PHPUnit\Framework\AssertionFailedError) {
                    throw $failure;
                }
            }
            self::assertSame($before, $this->pdo->query('SELECT * FROM pa_department ORDER BY id')->fetchAll(PDO::FETCH_ASSOC));
            self::assertSame($audits, (int) $this->pdo->query('SELECT COUNT(*) FROM pa_tenant_audit_event')->fetchColumn());
            self::assertFalse($this->pdo->inTransaction());
        }
    }

    public function testForeignDepartmentCannotBeEditedThroughHost(): void
    {
        $this->pdo->exec("INSERT INTO pa_department(id,tenant_id,code,name,sort_order,status,revision) VALUES(90,202,'foreign','Other',0,'active',1)");
        $this->expectException(\PeanutAdmin\Kernel\Authorization\Application\AdminAccessException::class);
        $this->runAs(fn() => $this->host->edit($this->actor, ['id' => 90, 'name' => 'Changed', 'pid' => 0, 'status' => 0]));
    }
}
