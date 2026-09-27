<?php

declare(strict_types=1);

namespace tests\Unit;

use app\adminapi\services\dept\DeptApplicationService;
use DateTimeImmutable;
use PDO;
use PeanutAdmin\Kernel\Auth\TenantContext;
use PeanutAdmin\Kernel\Auth\ValidatedTenantSession;
use PeanutAdmin\Modules\Identity\Audit\AuditService;
use PeanutAdmin\Modules\Identity\Organization\Application\DepartmentAdminService;
use PHPUnit\Framework\TestCase;
use think\App;

/** 原宿主与Identity用例在SQLite实际执行，不替代人员权限或MySQL资格。 */
final class DepartmentHostStatusBoundaryTest extends TestCase
{
    private PDO $pdo;
    private DeptApplicationService $host;
    private DepartmentAdminService $owner;
    private TenantContext $actor;

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
            INSERT INTO pa_tenant VALUES (101,'active',1,NULL),(202,'active',1,NULL);
            CREATE TABLE pa_department (id INTEGER PRIMARY KEY AUTOINCREMENT,tenant_id INTEGER,parent_id INTEGER,code TEXT,name TEXT,sort_order INTEGER,status TEXT,revision INTEGER DEFAULT 1,created_at TEXT,updated_at TEXT);
            CREATE TABLE pa_tenant_member (id INTEGER PRIMARY KEY,tenant_id INTEGER,primary_department_id INTEGER,status TEXT);
            CREATE TABLE pa_tenant_audit_event (id INTEGER PRIMARY KEY AUTOINCREMENT,tenant_id INTEGER,actor_type TEXT,tenant_member_id INTEGER,platform_operator_id INTEGER,account_id INTEGER,event_type TEXT,action TEXT,outcome TEXT,reason_code TEXT,target_resource_type TEXT,target_resource_id TEXT,request_id TEXT,operation_id TEXT,ip_address TEXT,user_agent_hash TEXT,before_json TEXT,after_json TEXT,metadata_json TEXT,occurred_at TEXT);
            SQL);
        $this->owner = new DepartmentAdminService(new AuditService());
        $this->host = new DeptApplicationService($this->owner);
        $this->actor = TenantContext::fromValidatedSession(new ValidatedTenantSession(1, 'dept-status', 101, 501, 601, 'admin-web', new DateTimeImmutable('2035-01-01'), 1), 'dept-status-request');
    }

    public function testDisabledCreationUsesDeclaredOwnerAndCurrentRevision(): void
    {
        self::assertTrue($this->host->add($this->actor, ['name' => 'Disabled', 'pid' => 0, 'sort' => 1, 'status' => 0]));
        $row = $this->pdo->query('SELECT * FROM pa_department')->fetch(PDO::FETCH_ASSOC);
        self::assertSame('disabled', $row['status']);
        self::assertSame(2, $row['revision']);
        self::assertSame(2, (int) $this->pdo->query('SELECT COUNT(*) FROM pa_tenant_audit_event')->fetchColumn());
    }

    public function testEditUsesRevisionAfterMetadataAndMoveThenUpdatesStatus(): void
    {
        $parent = $this->owner->create($this->actor, 'parent', 'Parent', null, 0);
        $row = $this->owner->create($this->actor, 'child', 'Child', null, 0);
        self::assertTrue($this->host->edit($this->actor, ['id' => (int) $row['id'], 'name' => 'Edited', 'pid' => (int) $parent['id'], 'sort' => 2, 'status' => 0]));
        $after = $this->owner->get(101, (int) $row['id']);
        self::assertSame('disabled', $after['status']);
        self::assertSame($parent['id'], $after['parent_id']);
        self::assertSame('Edited', $after['name']);
        self::assertSame('4', $after['revision']);
    }

    public function testFailedStatusChangeRollsBackWholeCreateAndEdit(): void
    {
        $row = $this->owner->create($this->actor, 'existing', 'Original', null, 0);
        $before = $this->pdo->query('SELECT * FROM pa_department ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
        $audits = (int) $this->pdo->query('SELECT COUNT(*) FROM pa_tenant_audit_event')->fetchColumn();
        $this->pdo->exec("CREATE TRIGGER reject_status BEFORE UPDATE ON pa_department WHEN NEW.status='disabled' BEGIN SELECT RAISE(ABORT,'status-write-fail'); END");
        foreach ([
            fn() => $this->host->add($this->actor, ['name' => 'Failed', 'pid' => 0, 'status' => 0]),
            fn() => $this->host->edit($this->actor, ['id' => (int) $row['id'], 'name' => 'Failed edit', 'pid' => 0, 'status' => 0]),
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
        $this->host->edit($this->actor, ['id' => 90, 'name' => 'Changed', 'pid' => 0, 'status' => 0]);
    }
}
