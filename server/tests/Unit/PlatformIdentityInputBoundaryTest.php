<?php

declare(strict_types=1);

use app\platform\controller\BasePlatformController;
use app\platform\controller\PlanLicenseController;
use app\platform\controller\PlatformAccessController;
use app\platform\controller\PlatformControlPlaneQueryController;
use app\platform\controller\PlatformTenantController;
use app\platform\controller\PlatformTenantEntryBindingController;
use app\platform\controller\PlatformTenantInvitationController;
use app\platform\context\PlatformOperatorContext;
use app\platform\validate\PlatformAccessValidate;
use app\platform\validate\PlatformTenantLifecycleValidate;
use app\platform\validate\TenantEntryBindingValidate;
use app\platform\validate\TenantOwnerInvitationValidate;
use PeanutAdmin\Kernel\Auth\ValidatedPlatformSession;
use PeanutAdmin\Kernel\Context\PlatformContext;
use PeanutAdmin\Kernel\Authorization\Application\AdminAccessException;
use PeanutAdmin\Modules\Identity\Platform\Application\PlanLicenseAdminService;
use PHPUnit\Framework\TestCase;
use think\exception\ValidateException;

/** Real platform controller/validator boundaries; business/database calls are forbidden. */
final class PlatformIdentityInputBoundaryTest extends TestCase
{
    private think\App $app;
    private PlatformOperatorContext $platform;
    private int $databaseAttempts = 0;

    protected function setUp(): void
    {
        $root = dirname(__DIR__,3);
        require_once $root.'/server/vendor/topthink/framework/src/helper.php';
        $this->app = new think\App($root.'/.local/tmp/platform-identity-input');
        $this->app->instance(app\common\execution\CurrentExecutionContext::class,new app\common\execution\CurrentExecutionContext(new app\common\execution\ExecutionContextStore()));
        $this->app->instance(PlanLicenseAdminService::class,(new ReflectionClass(PlanLicenseAdminService::class))->newInstanceWithoutConstructor());
        $queries = app\platform\query\PlatformControlPlaneQueryService::class;
        $this->app->instance($queries,(new ReflectionClass($queries))->newInstanceWithoutConstructor());
        $this->app->bind(think\DbManager::class,function(): never {
            ++$this->databaseAttempts;
            throw new LogicException('FIXTURE_DATABASE_FORBIDDEN');
        });
        $this->platform = PlatformOperatorContext::fromValidatedPlatformSession(PlatformContext::fromValidatedSession(
            new ValidatedPlatformSession(1,'synthetic-platform',501,601,'platform-web',new DateTimeImmutable('2031-01-01')),
            'platform-input-request',
        ));
    }

    private function call(string $type,string $action,array $input): void
    {
        $controller = (new ReflectionClass($type))->newInstanceWithoutConstructor();
        (new ReflectionProperty(app\BaseController::class,'app'))->setValue($controller,$this->app);
        (new ReflectionProperty(app\BaseController::class,'request'))->setValue($controller,(new think\Request())->withGet($input)->withPost($input));
        (new ReflectionProperty(BasePlatformController::class,'platformContext'))->setValue($controller,$this->platform);
        $controller->$action();
    }

    public function testCurrentPlatformObjectAndRevisionInputsRejectCoercionBeforeBusinessCalls(): void
    {
        $cases = [
            [PlatformAccessController::class,'updateOperator','operator_id',['operator_id'=>602,'expected_revision'=>1,'display_name'=>'Actor','change_reason'=>'fixture']],
            [PlatformAccessController::class,'updateOperator','expected_revision',['operator_id'=>602,'expected_revision'=>1,'display_name'=>'Actor','change_reason'=>'fixture']],
            [PlatformAccessController::class,'archiveRole','role_id',['role_id'=>602,'expected_revision'=>1,'change_reason'=>'fixture']],
            [PlatformTenantController::class,'activate','tenant_id',['tenant_id'=>602,'expected_revision'=>1,'change_reason'=>'fixture']],
            [PlatformTenantController::class,'activate','expected_revision',['tenant_id'=>602,'expected_revision'=>1,'change_reason'=>'fixture']],
            [PlatformTenantEntryBindingController::class,'enable','tenant_id',['tenant_id'=>602,'host'=>'tenant.example.test','client_key'=>'admin-web','change_reason'=>'fixture']],
            [PlatformTenantEntryBindingController::class,'disable','binding_id',['binding_id'=>602,'change_reason'=>'fixture']],
            [PlatformTenantEntryBindingController::class,'lists','tenant_id',[]],
            [PlatformTenantInvitationController::class,'invite','tenant_id',['tenant_id'=>602,'owner_email'=>'owner@example.test','owner_display_name'=>'Owner']],
            [PlatformTenantInvitationController::class,'resend','invitation_id',['invitation_id'=>602]],
            [PlatformTenantInvitationController::class,'lists','tenant_id',[]],
            [PlatformControlPlaneQueryController::class,'owner','tenant_id',[]],
            [PlatformControlPlaneQueryController::class,'moduleStates','tenant_id',[]],
            [PlatformTenantController::class,'detail','id',[]],
            [PlanLicenseController::class,'license','tenant_id',[]],
        ];
        foreach ($cases as [$type,$action,$field,$base]) {
            foreach (['602.9','6.02e2','602junk',true,false,1.5,0,[602],'9223372036854775808'] as $value) {
                try { $this->call($type,$action,array_replace($base,[$field=>$value])); self::fail('A malformed platform identity reached the business layer.'); }
                catch(ValidateException|AdminAccessException) { self::assertTrue(true); }
            }
        }
        self::assertSame(0,$this->databaseAttempts);
    }

    public function testOperatorRoleListsRejectInvalidElementsButKeepExplicitEmptyRevocation(): void
    {
        $input = ['operator_id'=>602,'expected_revision'=>1,'change_reason'=>'fixture'];
        foreach ([[],[602,'603']] as $roles) {
            self::assertTrue((new PlatformAccessValidate())->scene('replaceOperatorRoles')->check($input+['role_ids'=>$roles]));
        }
        self::assertFalse((new PlatformAccessValidate())->scene('replaceOperatorRoles')->check($input));
        foreach ([['602.9'],['6.02e2'],[true],[1.5],[[602]],['9223372036854775808'],['id'=>602],array_fill(0,101,602)] as $roles) {
            try { $this->call(PlatformAccessController::class,'replaceOperatorRoles',$input+['role_ids'=>$roles]); self::fail('Invalid role assignments reached map(int).'); }
            catch(ValidateException) { self::assertTrue(true); }
        }
        self::assertSame(0,$this->databaseAttempts);
    }

    public function testValidIdentifiersAndOptionalQueryDefaultsKeepTheirExistingContracts(): void
    {
        foreach ([602,'602'] as $id) {
            self::assertTrue((new PlatformAccessValidate())->scene('updateRole')->check(['role_id'=>$id,'expected_revision'=>$id,'name'=>'Role','change_reason'=>'fixture']));
            self::assertTrue((new PlatformTenantLifecycleValidate())->scene('activate')->check(['tenant_id'=>$id,'expected_revision'=>$id,'change_reason'=>'fixture']));
            self::assertTrue((new TenantEntryBindingValidate())->scene('lists')->check(['tenant_id'=>$id]));
            self::assertTrue((new TenantOwnerInvitationValidate())->scene('lists')->check(['tenant_id'=>$id]));
        }
        self::assertTrue((new TenantOwnerInvitationValidate())->scene('resend')->check(['invitation_id'=>602]));
        self::assertTrue((new TenantOwnerInvitationValidate())->scene('lists')->check(['tenant_id'=>602,'page'=>1,'page_size'=>20]));
        foreach ([PlatformTenantController::class,PlatformControlPlaneQueryController::class,PlanLicenseController::class] as $type) {
            $controller = (new ReflectionClass($type))->newInstanceWithoutConstructor();
            $parse = new ReflectionMethod($type,'positiveInteger');
            $extra = $type === PlatformTenantController::class ? ['SYNTHETIC_ID_INVALID'] : [];
            self::assertSame(1,$parse->invoke($controller,1,...$extra));
            self::assertSame(20,$parse->invoke($controller,'20',...$extra));
            foreach ([true,false,1.0,'602.9','6.02e2'] as $bad) {
                try { $parse->invoke($controller,$bad,...$extra); self::fail('Query parsing accepted a malformed numeric input.'); }
                catch(AdminAccessException) { self::assertTrue(true); }
            }
        }
        self::assertSame(0,$this->databaseAttempts);
    }

    public function testPlanAndLicenseWritesAlreadyRejectMalformedIdsInsideTheirOwningService(): void
    {
        $service = (new ReflectionClass(PlanLicenseAdminService::class))->newInstanceWithoutConstructor();
        $integer = new ReflectionMethod($service,'integer');
        self::assertSame(602,$integer->invoke($service,'602'));
        foreach (['602.9','6.02e2',true,false,1.5,[602],'9223372036854775808'] as $bad) {
            try { $integer->invoke($service,$bad); self::fail('Plan/License identity parsing accepted coercion.'); }
            catch(AdminAccessException) { self::assertTrue(true); }
        }
        self::assertSame(0,$this->databaseAttempts);
    }
}
