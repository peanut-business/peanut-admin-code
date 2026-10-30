<?php

declare(strict_types=1);

use app\adminapi\services\auth\AdminApplicationService;
use app\common\exception\BusinessException;
use app\common\execution\AdminExecutionContext;
use app\common\execution\CurrentExecutionContext;
use app\common\execution\ExecutionContextStore;
use app\common\infrastructure\authorization\CoreTenantModuleAdminBridge;
use app\common\services\authorization\AdminAuthorizationService;
use PeanutAdmin\Kernel\Auth\TenantContext;
use PeanutAdmin\Kernel\Auth\ValidatedTenantSession;
use PeanutAdmin\Kernel\Authorization\EffectivePermissionSet;
use PeanutAdmin\Kernel\Authorization\TenantAuthorizationRepository;
use PeanutAdmin\Modules\Identity\Contract\AdminDirectoryQuery;
use PeanutAdmin\Modules\Identity\Contract\TenantAuthorizationQuery;
use PeanutAdmin\Modules\Identity\Contract\TenantMemberDirectory;
use PeanutAdmin\Modules\Identity\Contract\Dto\TenantMemberSummary;
use PeanutAdmin\Modules\Identity\Membership\Application\MemberAdminService;
use PeanutAdmin\Modules\Identity\Audit\AuditService;
use PHPUnit\Framework\TestCase;
use think\App;

/** Actual host and Identity commands on memory-only SQLite; no shared database or HTTP. */
final class MemberHostActionPermissionTest extends TestCase
{
    private PDO $pdo;
    private App $app;
    private ExecutionContextStore $contexts;
    private TenantContext $actor;
    private AdminApplicationService $host;
    private MemberAdminService $members;
    private array $permissions = [];

    protected function setUp(): void
    {
        $root = dirname(__DIR__, 3);
        require_once $root . '/server/vendor/topthink/framework/src/helper.php';
        $this->app = new App($root . '/.local/tmp/member-host-action');
        $this->pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $this->pdo->sqliteCreateFunction('UTC_TIMESTAMP', static fn(int $precision): string => '2031-01-02 00:00:00.000', 1);
        ThinkPhpTestConnection::fromPdo($this->pdo);
        $this->pdo->exec(<<<'SQL'
            CREATE TABLE pa_tenant (id INTEGER PRIMARY KEY,name TEXT,status TEXT,authorization_revision INTEGER,updated_at TEXT);
            INSERT INTO pa_tenant VALUES (101,'Alpha','active',1,NULL),(202,'Beta','active',1,NULL);
            CREATE TABLE pa_account (id INTEGER PRIMARY KEY,status TEXT,avatar_uri TEXT,last_login_at TEXT);
            INSERT INTO pa_account VALUES (501,'active','',NULL);
            CREATE TABLE pa_credential (id INTEGER PRIMARY KEY,account_id INTEGER,kind TEXT,identifier_type TEXT,status TEXT,identifier_normalized TEXT);
            INSERT INTO pa_credential VALUES (1,501,'email_password','email','active','actor@example.test');
            CREATE TABLE pa_tenant_member (id INTEGER PRIMARY KEY,tenant_id INTEGER,account_id INTEGER,display_name TEXT,member_no TEXT,member_type TEXT,primary_department_id INTEGER,status TEXT,security_revision INTEGER DEFAULT 1,authorization_revision INTEGER DEFAULT 1,updated_at TEXT,joined_at TEXT);
            INSERT INTO pa_tenant_member VALUES (601,101,501,'Actor',NULL,'employee',NULL,'active',1,1,NULL,NULL),(602,101,502,'Target',NULL,'employee',NULL,'active',1,1,NULL,NULL),(603,202,503,'Foreign',NULL,'employee',NULL,'active',1,1,NULL,NULL);
            CREATE TABLE pa_role (id INTEGER PRIMARY KEY,tenant_id INTEGER,"key" TEXT,name TEXT,is_builtin INTEGER,status TEXT);
            INSERT INTO pa_role VALUES (701,101,'fixture.reader','Reader',0,'active'),(702,101,'fixture.writer','Writer',0,'active'),(703,202,'fixture.foreign','Foreign',0,'active');
            CREATE TABLE pa_member_role (id INTEGER PRIMARY KEY AUTOINCREMENT,tenant_id INTEGER,tenant_member_id INTEGER,role_id INTEGER,assigned_by_member_id INTEGER,assigned_at TEXT);
            INSERT INTO pa_member_role(tenant_id,tenant_member_id,role_id) VALUES (101,602,701),(202,603,703);
            CREATE TABLE pa_permission (id INTEGER PRIMARY KEY,"key" TEXT,module_key TEXT,status TEXT);
            CREATE TABLE pa_plan (id INTEGER PRIMARY KEY,code TEXT,name TEXT,revision INTEGER);
            CREATE TABLE pa_tenant_license (id INTEGER PRIMARY KEY,tenant_id INTEGER,plan_id INTEGER);
            CREATE TABLE pa_tenant_audit_event (id INTEGER PRIMARY KEY AUTOINCREMENT,tenant_id INTEGER,actor_type TEXT,tenant_member_id INTEGER,platform_operator_id INTEGER,account_id INTEGER,event_type TEXT,action TEXT,outcome TEXT,reason_code TEXT,target_resource_type TEXT,target_resource_id TEXT,request_id TEXT,operation_id TEXT,ip_address TEXT,user_agent_hash TEXT,before_json TEXT,after_json TEXT,metadata_json TEXT,occurred_at TEXT);
            SQL);
        foreach (['create','update','role.assign','activate','suspend','leave'] as $index => $operation) {
            $this->pdo->prepare('INSERT INTO pa_permission VALUES (?,?,?,?)')->execute([$index + 1, 'core.member.' . $operation, 'core', 'active']);
        }
        $this->contexts = new ExecutionContextStore();
        $current = new CurrentExecutionContext($this->contexts);
        $this->app->instance(CurrentExecutionContext::class, $current);
        $directory = new AdminDirectoryQuery($current);
        $lookup = $this->createStub(TenantMemberDirectory::class);
        $lookup->method('activeMembership')->willReturnCallback(static fn(int $tenant, int $member): ?TenantMemberSummary =>
            $tenant === 101 && $member === 601 ? new TenantMemberSummary(101,601,501,'Actor',1,1,1,1) : null);
        $lookup->method('activeMembershipCount')->willReturn(1);
        $identity = new TenantAuthorizationQuery($lookup);
        $grants = $this->createStub(TenantAuthorizationRepository::class);
        $grants->method('permissions')->willReturnCallback(fn() => new EffectivePermissionSet($this->permissions));
        $bridge = new CoreTenantModuleAdminBridge(
            (new ReflectionClass(\app\platform\infrastructure\module\ThinkPhpModuleGovernanceProvider::class))->newInstanceWithoutConstructor(),
            $grants, $this->createStub(\PeanutAdmin\Kernel\Menu\MenuCatalogRepository::class), $identity,
            new \PeanutAdmin\Modules\Identity\Menu\MenuAdministrationService(),
            new \PeanutAdmin\Modules\Identity\Module\TenantEntitlementService(new \PeanutAdmin\Kernel\Module\CompiledModuleRegistry([],[],[],[],'fixture-member')),
        );
        $authorization = new AdminAuthorizationService($bridge, new \app\common\policy\permission\RegisteredAdminPermissionPolicy(), $lookup, $directory, $identity,
            new \app\common\services\authorization\EndpointPermissionGrant(new \think\Request()));
        $this->members = new MemberAdminService(new AuditService($current), new \PeanutAdmin\Kernel\Identity\PasswordHasher(), new \PeanutAdmin\Kernel\Identity\PasswordPolicy());
        $runtime = new \app\common\runtime\org\TenantAdminRuntime($this->members,
            (new ReflectionClass(\PeanutAdmin\Modules\Identity\Identity\SelfService\AccountSelfService::class))->newInstanceWithoutConstructor(),
            new \PeanutAdmin\Modules\Identity\Policy\DemoAccountPolicy(false, []));
        $this->host = new AdminApplicationService(
            (new ReflectionClass(\app\common\services\XlsxExportService::class))->newInstanceWithoutConstructor(),
            $directory, $runtime, $this->createStub(\PeanutAdmin\Modules\File\Contract\FileReferences::class),
            new \PeanutAdmin\Kernel\Identity\PasswordPolicy(), $current, $authorization,
        );
        $this->actor = TenantContext::fromValidatedSession(new ValidatedTenantSession(1,'member-host',101,501,601,'admin-web',new DateTimeImmutable('2035-01-01'),1),'member-host-request');
    }

    private function runAs(callable $operation): mixed
    {
        return $this->contexts->run(new AdminExecutionContext($this->actor,'member.host.test',[
            'id'=>601,'tenant_id'=>101,'account_id'=>501,'authorization_revision'=>1,
        ]), $operation);
    }

    private function denied(callable $operation): void
    {
        $before = $this->pdo->query('SELECT * FROM pa_tenant_member ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
        try {
            $this->runAs($operation);
            self::fail('Sensitive member mutation was accepted without its permission.');
        } catch (BusinessException $error) {
            self::assertSame('MEMBER_PERMISSION_DENIED', $error->errorCode);
        }
        self::assertSame($before, $this->pdo->query('SELECT * FROM pa_tenant_member ORDER BY id')->fetchAll(PDO::FETCH_ASSOC));
    }

    public function testCreatePermissionDoesNotAssignRolesOrActivateAnAccount(): void
    {
        $input = ['account'=>'new@example.test','name'=>'New','password'=>'synthetic-password','role_id'=>[701],'disable'=>0];
        $this->permissions = ['core.member.create'];
        $this->runAs(fn() => (new ReflectionMethod($this->host, 'assertMemberPermission'))->invoke($this->host, $this->actor, 'create'));
        $this->denied(fn() => $this->host->add($this->actor, $input));
        $this->permissions[] = 'core.member.role.assign';
        $this->denied(fn() => $this->host->add($this->actor, $input));
        self::assertSame(1, (int) $this->pdo->query('SELECT COUNT(*) FROM pa_account')->fetchColumn());
    }

    public function testUpdatePermissionDoesNotChangeRolesOrStatus(): void
    {
        $this->permissions = ['core.member.update'];
        $this->denied(fn() => $this->host->edit($this->actor,['id'=>602,'name'=>'Target','role_id'=>[702],'disable'=>0]));
        $this->denied(fn() => $this->host->edit($this->actor,['id'=>602,'name'=>'Target','role_id'=>[701],'disable'=>1]));
        $this->denied(fn() => $this->host->updateStatus($this->actor,602,1));
        $this->pdo->exec("UPDATE pa_tenant_member SET status='suspended' WHERE id=602");
        $this->denied(fn() => $this->host->updateStatus($this->actor,602,0));
    }

    public function testUnchangedRolesAndStatusPermitAnOrdinaryProfileEdit(): void
    {
        $this->permissions = ['core.member.update'];
        self::assertTrue($this->runAs(fn() => $this->host->edit($this->actor,['id'=>602,'name'=>'Renamed','role_id'=>['701'],'disable'=>0])));
        $member = $this->members->get(101,602);
        self::assertSame('Renamed', $member['display_name']);
        self::assertSame([701], $member['role_ids']);
        self::assertSame(['fixture.reader'], $member['role_keys']);
        self::assertSame([703], $this->members->get(202,603)['role_ids']);
    }

    public function testMalformedRoleDepartmentAndJobIdsNeverBecomeValidIdentifiers(): void
    {
        self::assertSame([1,2], $this->host->normalizeInput(['role_id'=>['2',1,1]], true)['role_id']);
        self::assertSame('701', $this->host->normalizeInput(['role_id'=>'701'])['role_id']);
        foreach (['role_id','dept_id','jobs_id'] as $field) {
            foreach ([['1junk'],['1e3'],[true],[1.5],[[1]],['9223372036854775808'],[0],['x'=>1]] as $ids) {
                try {
                    $this->host->normalizeInput([$field=>$ids], true);
                    self::fail('Malformed identifier list was accepted.');
                } catch (BusinessException $error) { self::assertSame('MEMBER_IDS_INVALID', $error->errorCode); }
            }
        }
    }

    public function testForeignMembersRemainOutsideTheHostWriteScope(): void
    {
        $this->permissions = ['core.member.update','core.member.role.assign','core.member.suspend'];
        try {
            $this->runAs(fn() => $this->host->edit($this->actor,['id'=>603,'name'=>'Forbidden','role_id'=>[701],'disable'=>1]));
            self::fail('A foreign tenant member reached the mutation command.');
        } catch (\PeanutAdmin\Kernel\Authorization\Application\AdminAccessException $error) {
            self::assertSame('RESOURCE_NOT_FOUND', $error->errorCode);
        }
        self::assertSame('Foreign', $this->pdo->query('SELECT display_name FROM pa_tenant_member WHERE id=603')->fetchColumn());
    }

    public function testIndependentRoleAndActivationPermissionsEnableTheirActualChanges(): void
    {
        $this->permissions = ['core.member.update','core.member.role.assign'];
        self::assertTrue($this->runAs(fn() => $this->host->edit($this->actor,['id'=>602,'name'=>'Target','role_id'=>[701,702],'disable'=>0])));
        self::assertSame([701,702], $this->members->get(101,602)['role_ids']);
        $this->pdo->exec("UPDATE pa_tenant_member SET status='suspended' WHERE id=602");
        $this->permissions = ['core.member.update','core.member.activate'];
        self::assertTrue($this->runAs(fn() => $this->host->updateStatus($this->actor,602,0)));
        self::assertSame('active', $this->members->get(101,602)['status']);
    }

    public function testControllerValidatesMemberIdsBeforeCasting(): void
    {
        $controller = (new ReflectionClass(\app\adminapi\controller\auth\AdminController::class))->newInstanceWithoutConstructor();
        (new ReflectionProperty(\app\BaseController::class,'app'))->setValue($controller,$this->app);
        $read = new ReflectionMethod($controller,'memberId');
        self::assertSame(602, $read->invoke($controller,'602'));
        $before = $this->pdo->query('SELECT * FROM pa_tenant_member ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
        $validator = new \think\Validate();
        $validator->rule(['id' => $this->host->validationRules('edit')['id']]);
        foreach (['602junk','602.9','6.02e2',true,1.5,[],0,-1] as $id) {
            try { $read->invoke($controller,$id); self::fail('Malformed member ID was cast into a real member.'); }
            catch (\think\exception\ValidateException) { self::assertTrue(true); }
            self::assertFalse($validator->check(['id' => $id]));
        }
        self::assertSame($before, $this->pdo->query('SELECT * FROM pa_tenant_member ORDER BY id')->fetchAll(PDO::FETCH_ASSOC));
    }

    public function testPendingAdministratorStaysPendingUntilExplicitlyAuthorizedActivation(): void
    {
        $this->pdo->exec("INSERT INTO pa_account VALUES (504,'active','',NULL)");
        $this->pdo->exec("INSERT INTO pa_credential VALUES (2,504,'email_password','email','active','pending@example.test')");
        $this->pdo->exec('ALTER TABLE pa_tenant_member ADD COLUMN created_at TEXT');
        $this->pdo->exec('CREATE TABLE pa_member_job(tenant_id INTEGER,tenant_member_id INTEGER,job_id INTEGER)');
        $this->permissions = ['core.member.create','core.member.role.assign','core.member.update'];
        $created = $this->runAs(fn() => $this->members->createAdministrator(
            $this->actor,'pending@example.test','Pending',null,null,[701],false,
        ));
        self::assertSame('pending', $created['status']);
        $id = (int) $created['id'];
        self::assertTrue($this->runAs(fn() => $this->host->edit($this->actor,[
            'id'=>$id,'name'=>'Pending renamed','role_id'=>[701],'disable'=>1,
        ])));
        self::assertSame('pending', $this->members->get(101,$id)['status']);
        self::assertSame('Pending renamed', $this->members->get(101,$id)['display_name']);
        $this->denied(fn() => $this->host->updateStatus($this->actor,$id,0));
        $this->permissions[] = 'core.member.activate';
        self::assertTrue($this->runAs(fn() => $this->host->updateStatus($this->actor,$id,0)));
        self::assertSame('active', $this->members->get(101,$id)['status']);
    }

    public function testAdministratorWritesRejectAvatarInsteadOfChangingTheGlobalAccount(): void
    {
        $this->permissions = ['core.member.create','core.member.update','core.member.role.assign','core.member.activate'];
        $before = $this->pdo->query('SELECT * FROM pa_account ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
        foreach (['add','edit'] as $action) {
            try {
                $this->runAs(fn()=>$this->host->$action($this->actor,[
                    'id'=>602,'name'=>'Target','account'=>'new@example.test','role_id'=>[701],'disable'=>0,
                    'avatar'=>'file_'.str_repeat('a',32),
                ]));
                self::fail('Administrator silently accepted a global account avatar.');
            } catch(BusinessException $error) { self::assertSame('ADMIN_AVATAR_SELF_SERVICE_REQUIRED',$error->errorCode); }
        }
        self::assertSame($before,$this->pdo->query('SELECT * FROM pa_account ORDER BY id')->fetchAll(PDO::FETCH_ASSOC));
        self::assertSame('Target',$this->members->get(101,602)['display_name']);
    }

    public function testOwnProfileKeepsTheStableAvatarAfterTheTenantReferenceCheck(): void
    {
        $this->pdo->exec('ALTER TABLE pa_account ADD COLUMN updated_at TEXT');
        $this->pdo->exec('ALTER TABLE pa_credential ADD COLUMN verified_at TEXT');
        $this->pdo->exec('ALTER TABLE pa_credential ADD COLUMN secret_changed_at TEXT');
        $current = $this->app->make(CurrentExecutionContext::class);
        $key = 'file_'.str_repeat('a',32);
        $references = $this->createMock(\PeanutAdmin\Modules\File\Contract\FileReferences::class);
        $references->expects(self::once())->method('setTenantFileUrl')->with($this->actor,$key)->willReturn($key);
        $self = new \PeanutAdmin\Modules\Identity\Identity\SelfService\AccountSelfService(
            new AuditService($current),new \PeanutAdmin\Kernel\Identity\PasswordHasher(),new \PeanutAdmin\Kernel\Identity\PasswordPolicy(),
        );
        $runtime = new \app\common\runtime\org\TenantAdminRuntime($this->members,$self,new \PeanutAdmin\Modules\Identity\Policy\DemoAccountPolicy(false,[]));
        $host = new AdminApplicationService(
            (new ReflectionProperty($this->host,'xlsxExport'))->getValue($this->host),new AdminDirectoryQuery($current),$runtime,$references,
            new \PeanutAdmin\Kernel\Identity\PasswordPolicy(),$current,(new ReflectionProperty($this->host,'authorization'))->getValue($this->host),
        );
        self::assertTrue($this->runAs(fn()=>$host->editSelf($this->actor,601,['nickname'=>'Actor renamed','avatar'=>$key],'192.0.2.1','fixture')));
        self::assertSame($key,$this->pdo->query('SELECT avatar_uri FROM pa_account WHERE id=501')->fetchColumn());
        self::assertSame('Actor renamed',$this->members->get(101,601)['display_name']);
    }
}
