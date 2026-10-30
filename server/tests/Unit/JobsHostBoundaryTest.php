<?php

declare(strict_types=1);

use app\adminapi\services\dept\JobsApplicationService;
use app\common\exception\BusinessException;
use app\common\execution\AdminExecutionContext;
use app\common\execution\CurrentExecutionContext;
use app\common\execution\ExecutionContextStore;
use app\common\model\TenantOwnedModel;
use app\common\tenancy\MultiTenantDataScopePolicy;
use PeanutAdmin\Kernel\Auth\TenantContext;
use PeanutAdmin\Kernel\Auth\ValidatedTenantSession;
use PeanutAdmin\Modules\Identity\Authorization\CorePermissionCatalog;
use PeanutAdmin\Modules\Identity\Menu\CoreMenuCatalog;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;
use think\Model;

/** Native Jobs/Scope/soft-delete behavior on memory-only SQLite. */
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class JobsHostBoundaryTest extends TestCase
{
    private PDO $pdo;
    private ExecutionContextStore $contexts;
    private TenantContext $alpha;
    private TenantContext $beta;
    private JobsApplicationService $jobs;

    protected function setUp(): void
    {
        $root = dirname(__DIR__,3);
        require_once $root . '/server/vendor/topthink/framework/src/helper.php';
        new think\App($root . '/.local/tmp/jobs-host-boundary');
        $this->pdo = new PDO('sqlite::memory:',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
        ThinkPhpTestConnection::fromPdo($this->pdo);
        $this->pdo->exec(<<<'SQL'
            CREATE TABLE pa_jobs(id INTEGER PRIMARY KEY AUTOINCREMENT,tenant_id INTEGER,name TEXT,code TEXT,sort INTEGER DEFAULT 0,status INTEGER DEFAULT 1,is_disable INTEGER DEFAULT 0,remark TEXT DEFAULT '',create_time INTEGER,update_time INTEGER,delete_time INTEGER);
            CREATE TABLE pa_member_job(tenant_id INTEGER,tenant_member_id INTEGER,job_id INTEGER);
            INSERT INTO pa_jobs(id,tenant_id,name,code,status,is_disable) VALUES(1,101,'Alpha','alpha',1,0),(2,202,'Beta','beta',1,0),(3,101,'Disabled','disabled',0,1);
            INSERT INTO pa_member_job VALUES(101,601,1),(202,602,2);
            SQL);
        $this->contexts = new ExecutionContextStore();
        $policy = new MultiTenantDataScopePolicy(new CurrentExecutionContext($this->contexts));
        Model::maker(static function(Model $model) use($policy): void {
            if ($model instanceof TenantOwnedModel) { $model->setDataScopePolicy($policy); }
        });
        $this->jobs = new JobsApplicationService((new ReflectionClass(app\common\services\XlsxExportService::class))->newInstanceWithoutConstructor());
        $this->alpha = $this->context(101,601);
        $this->beta = $this->context(202,602);
    }

    private function context(int $tenant, int $member): TenantContext
    {
        return TenantContext::fromValidatedSession(new ValidatedTenantSession(1,'jobs-'.$tenant,$tenant,$member,$member,'admin-web',new DateTimeImmutable('2035-01-01'),1),'jobs-'.$tenant);
    }

    private function runAs(TenantContext $context, callable $operation): mixed
    {
        return $this->contexts->run(new AdminExecutionContext($context,'jobs.host.test',[
            'id'=>$context->memberId,'tenant_id'=>$context->tenantId,'account_id'=>$context->accountId,'authorization_revision'=>1,
        ]),$operation);
    }

    public function testCrudPreservesTenantOwnershipAndCanonicalStatus(): void
    {
        $this->runAs($this->alpha,function(): void {
            self::assertTrue($this->jobs->add($this->alpha,['name'=>' New ','code'=>' new ','sort'=>2,'remark'=>'note','status'=>'0']));
            $id = (int)$this->pdo->lastInsertId();
            $row = $this->jobs->detail($this->alpha,$id);
            self::assertSame('New',$row['name']);
            self::assertSame(0,$row['status']);
            self::assertSame(1,$row['is_disable']);
            self::assertSame('停用',$row['status_desc']);
            self::assertSame([1],array_map('intval',array_column($this->jobs->all($this->alpha),'id')));
            self::assertTrue($this->jobs->edit($this->alpha,['id'=>$id,'name'=>'Updated','code'=>'new','status'=>1]));
            self::assertSame(1,$this->jobs->detail($this->alpha,$id)['status']);
            self::assertTrue($this->jobs->delete($this->alpha,$id));
            self::assertGreaterThan(0,(int)$this->pdo->query('SELECT delete_time FROM pa_jobs WHERE id='.$id)->fetchColumn());
            self::assertSame(2,$this->jobs->lists($this->alpha,['page_no'=>1,'page_size'=>15])->total);
        });
        self::assertSame('Beta',$this->runAs($this->beta,fn()=>$this->jobs->detail($this->beta,2))['name']);
    }

    public function testForeignJobIdsFailWithoutChangingOrDeletingTheirRows(): void
    {
        foreach (['detail','edit','delete','status'] as $action) {
            try {
                $this->runAs($this->alpha,fn()=>match($action) {
                    'detail'=>$this->jobs->detail($this->alpha,2),
                    'edit'=>$this->jobs->edit($this->alpha,['id'=>2,'name'=>'Forbidden','code'=>'beta','status'=>0]),
                    'delete'=>$this->jobs->delete($this->alpha,2),
                    'status'=>$this->jobs->updateStatus($this->alpha,2,0),
                });
                self::fail('Foreign Job reached a tenant operation.');
            } catch(BusinessException $error) { self::assertSame('ADMIN_JOB_NOT_FOUND',$error->errorCode); }
        }
        self::assertSame('Beta',$this->pdo->query('SELECT name FROM pa_jobs WHERE id=2')->fetchColumn());
        self::assertNull($this->pdo->query('SELECT delete_time FROM pa_jobs WHERE id=2')->fetchColumn());
    }

    public function testAssignedJobsCannotBeArchivedAndDisablingPreservesAssignments(): void
    {
        $this->runAs($this->alpha,function(): void {
            $this->jobs->updateStatus($this->alpha,1,0);
            self::assertSame([], $this->jobs->all($this->alpha));
            self::assertSame(1,(int)$this->pdo->query('SELECT COUNT(*) FROM pa_member_job WHERE job_id=1')->fetchColumn());
            try { $this->jobs->delete($this->alpha,1); self::fail('Assigned Job was archived.'); }
            catch(BusinessException $error) { self::assertSame('ADMIN_JOB_IN_USE',$error->errorCode); }
            self::assertSame(0,$this->jobs->detail($this->alpha,1)['status']);
        });
    }

    public function testUnknownFieldsAndCoercibleIdentifiersAreRejectedAtTheirInputBoundary(): void
    {
        foreach (['1junk','1.9','1e2',true,1.5,[],0,'9223372036854775808'] as $id) {
            try { $this->jobs->normalizeInput(['id'=>$id],'delete'); self::fail('Noncanonical Job ID was accepted.'); }
            catch(think\exception\ValidateException) { self::assertTrue(true); }
        }
        foreach (['tenant_id'=>202,'status_desc'=>'正常','is_disable'=>0] as $field=>$value) {
            try { $this->jobs->normalizeInput(['name'=>'Bad','code'=>'bad','status'=>1,$field=>$value],'add'); self::fail('Unknown Job field was accepted.'); }
            catch(BusinessException $error) { self::assertSame('JOB_INPUT_INVALID',$error->errorCode); }
        }
        foreach ([true,false,1.5,'1junk',2] as $status) {
            try { $this->jobs->normalizeInput(['id'=>1,'status'=>$status],'status'); self::fail('Invalid Job status was accepted.'); }
            catch(think\exception\ValidateException) { self::assertTrue(true); }
        }
    }

    public function testRegisteredJobAndAuditMenusTargetTheirActualClientPages(): void
    {
        foreach (['read','create','update','archive'] as $operation) { self::assertContains('core.job.'.$operation,CorePermissionCatalog::TENANT); }
        $menus = array_column(CoreMenuCatalog::definitions(),null,'key');
        self::assertSame('/system/jobs',$menus['core.job.list']->routePath);
        self::assertSame('SystemJobs',$menus['core.job.list']->routeName);
        self::assertSame('core.job.read',$menus['core.job.list']->requiredPermission);
        self::assertSame('/system/log',$menus['core.audit.list']->routePath);
        self::assertSame('core.audit.read',$menus['core.audit.list']->requiredPermission);
    }

    public function testAnAmbientTenantCannotCreateAJobForAnotherContext(): void
    {
        $count = (int)$this->pdo->query('SELECT COUNT(*) FROM pa_jobs')->fetchColumn();
        try {
            $this->runAs($this->alpha,fn()=>$this->jobs->add($this->beta,['name'=>'Forbidden','code'=>'foreign','status'=>1]));
            self::fail('A trusted tenant context was replaced during Job creation.');
        } catch(DomainException $error) { self::assertSame('TENANT_WRITE_OWNERSHIP_MISMATCH',$error->getMessage()); }
        self::assertSame($count,(int)$this->pdo->query('SELECT COUNT(*) FROM pa_jobs')->fetchColumn());
    }
}
