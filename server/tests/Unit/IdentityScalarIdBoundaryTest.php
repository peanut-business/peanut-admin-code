<?php

declare(strict_types=1);

use app\adminapi\controller\auth\RoleController;
use app\adminapi\controller\dept\DeptController;
use app\adminapi\services\auth\RoleApplicationService;
use app\adminapi\services\dept\DeptApplicationService;
use app\adminapi\validate\auth\AdminValidate;
use app\common\execution\AdminExecutionContext;
use app\common\execution\CurrentExecutionContext;
use app\common\execution\ExecutionContextStore;
use PeanutAdmin\Kernel\Auth\TenantContext;
use PeanutAdmin\Kernel\Auth\ValidatedTenantSession;
use PHPUnit\Framework\TestCase;
use think\exception\ValidateException;

/** Executes the actual controller input paths with all database access forbidden. */
final class IdentityScalarIdBoundaryTest extends TestCase
{
    private think\App $app;
    private ExecutionContextStore $contexts;
    private TenantContext $actor;
    private int $databaseAttempts = 0;

    protected function setUp(): void
    {
        $root = dirname(__DIR__,3);
        require_once $root.'/server/vendor/topthink/framework/src/helper.php';
        $this->app = new think\App($root.'/.local/tmp/identity-scalar-ids');
        $this->contexts = new ExecutionContextStore();
        $this->app->instance(CurrentExecutionContext::class,new CurrentExecutionContext($this->contexts));
        foreach ([RoleApplicationService::class,DeptApplicationService::class] as $type) {
            $this->app->instance($type,(new ReflectionClass($type))->newInstanceWithoutConstructor());
        }
        $this->app->bind(think\DbManager::class,function(): never {
            ++$this->databaseAttempts;
            throw new LogicException('FIXTURE_DATABASE_FORBIDDEN');
        });
        $this->actor = TenantContext::fromValidatedSession(new ValidatedTenantSession(1,'scalar-id',101,501,601,'admin-web',new DateTimeImmutable('2035-01-01'),1),'scalar-id-request');
    }

    private function call(string $type,string $action,array $params): void
    {
        $controller = (new ReflectionClass($type))->newInstanceWithoutConstructor();
        $request = (new think\Request())->withGet($params)->withPost($params);
        (new ReflectionProperty(app\BaseController::class,'app'))->setValue($controller,$this->app);
        (new ReflectionProperty(app\BaseController::class,'request'))->setValue($controller,$request);
        $this->contexts->run(new AdminExecutionContext($this->actor,'identity.scalar.test',[
            'id'=>601,'tenant_id'=>101,'account_id'=>501,'authorization_revision'=>1,
        ]),fn()=>$controller->$action());
    }

    public function testRoleAndDepartmentActionsRejectNoncanonicalIdsBeforeTheirBusinessCalls(): void
    {
        foreach ([RoleController::class=>['detail','edit','delete'],DeptController::class=>['detail','edit','delete','updateStatus']] as $type=>$actions) {
            foreach ($actions as $action) {
                foreach (['602.9','6.02e2','602junk',true,1.5,0,'9223372036854775808'] as $id) {
                    try {
                        $params = ['id'=>$id,'name'=>'Untouched'];
                        if ($type === DeptController::class) { $params += ['pid'=>0,'status'=>1]; }
                        $this->call($type,$action,$params);
                        self::fail('A malformed identifier reached a business operation.');
                    } catch(ValidateException) { self::assertTrue(true); }
                }
            }
        }
        self::assertSame(0,$this->databaseAttempts);
    }

    public function testDepartmentParentIdsRejectCoercionWhileKeepingTheRootZero(): void
    {
        $rules = $this->app->make(DeptApplicationService::class)->validationRules('add');
        foreach (['602.9','6.02e2',true,false,-1,'9223372036854775808'] as $parent) {
            self::assertFalse((new think\Validate())->rule($rules)->check(['name'=>'Untouched','pid'=>$parent,'status'=>1]));
        }
        foreach ([0,'0',602,'602'] as $parent) {
            self::assertTrue((new think\Validate())->rule($rules)->check(['name'=>'Valid','pid'=>$parent,'status'=>1]));
        }
        self::assertSame(0,$this->databaseAttempts);
    }

    public function testRoleRulesAndAdministratorRoleFiltersKeepValidAndEmptyInputContracts(): void
    {
        $rules = $this->app->make(RoleApplicationService::class)->validationRules('detail');
        foreach ([602,'602'] as $id) { self::assertTrue((new think\Validate())->rule($rules)->check(['id'=>$id])); }
        foreach (['602.9','6.02e2',true,1.5,'9223372036854775808'] as $id) {
            self::assertFalse((new AdminValidate())->scene('lists')->check(['role_id'=>$id]));
        }
        foreach ([602,'602',null,''] as $id) {
            self::assertTrue((new AdminValidate())->scene('lists')->check(['role_id'=>$id]));
        }
        self::assertTrue((new AdminValidate())->scene('lists')->check([]));
        self::assertSame(0,$this->databaseAttempts);
    }
}
