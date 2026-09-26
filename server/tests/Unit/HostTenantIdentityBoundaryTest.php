<?php

declare(strict_types=1);

use app\common\execution\CurrentExecutionContext;
use app\common\execution\ExecutionContextStore;
use app\common\execution\SystemExecutionContext;
use app\common\infrastructure\member\MemberApiTenantContextResolver;
use app\common\infrastructure\module\ModuleExecutionBoundary;
use PeanutAdmin\Kernel\Context\TenantSystemContext;
use PeanutAdmin\Kernel\Module\ModuleException;
use PeanutAdmin\Kernel\Module\ModuleRuntimeRepository;
use PeanutAdmin\Modules\Identity\Contract\AdminDirectoryQuery;
use PeanutAdmin\Modules\Identity\Contract\TenantIdentityQuery;
use PeanutAdmin\Modules\Member\Contract\MemberSubjectLookup;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;
use think\App;

/** Actual Identity model and native contexts with SQLite and a synthetic member-subject provider. */
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class HostTenantIdentityBoundaryTest extends TestCase
{
    private PDO $database;
    private App $app;
    private ExecutionContextStore $contexts;
    private CurrentExecutionContext $current;
    private AdminDirectoryQuery $directory;

    protected function setUp(): void
    {
        $root = dirname(__DIR__,3);
        require_once $root . '/server/vendor/topthink/framework/src/helper.php';
        $this->app = new App($root . '/.local/tmp/host-tenant-identity/' . bin2hex(random_bytes(6)));
        $this->database = new PDO('sqlite::memory:',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
        ThinkPhpTestConnection::fromPdo($this->database);
        $this->database->exec("CREATE TABLE pa_tenant (id INTEGER PRIMARY KEY,name TEXT,status TEXT); INSERT INTO pa_tenant VALUES (101,' Alpha ','active'),(202,'Beta','suspended'),(303,'Gamma','closed'),(404,'Delta','provisioning')");
        $this->contexts = new ExecutionContextStore();
        $this->current = new CurrentExecutionContext($this->contexts);
        $this->directory = new AdminDirectoryQuery($this->current);
    }

    public function testHostConsumersDoNotSelectIdentityPrivateTables(): void
    {
        $root=dirname(__DIR__,2);
        foreach (['common/infrastructure/member/MemberApiTenantContextResolver.php','common/infrastructure/module/ModuleExecutionBoundary.php'] as $file) {
            $source=file_get_contents($root . '/app/' . $file);
            self::assertStringNotContainsString("Db::name('tenant')",$source);
            self::assertStringContainsString('AdminDirectoryQuery',$source);
        }
        self::assertFileDoesNotExist($root . '/app/common/services/tenant/TenantIdentityQuery.php');
        $manifest=json_decode(file_get_contents($root . '/app/modules/official/identity/module.json'),true,512,JSON_THROW_ON_ERROR);
        self::assertContains(TenantIdentityQuery::class,$manifest['contracts']['exports']);
    }

    public function testActivePublicNamePreservesExactSelectionAndEmptyDefaults(): void
    {
        $query = new TenantIdentityQuery();
        self::assertSame('Alpha',$query->activeName(101));
        foreach ([0,-1,202,303,404,999] as $id) {
            self::assertSame('',$query->activeName($id));
        }
        self::assertStringContainsString('/app/modules/official/identity/src/Contract/',(new ReflectionClass($query))->getFileName());
    }

    public function testMemberContextRetainsVerifiedOwnerAndCredentialFingerprint(): void
    {
        $lookup=$this->createMock(MemberSubjectLookup::class);
        $lookup->expects(self::once())->method('tenantId')->with(501)->willReturn(101);
        $resolver=$this->resolver($lookup);
        $result=$resolver->resolve(501,'synthetic-token','request-1',101);
        self::assertSame([101,501,hash('sha256','synthetic-token'),'request-1'],[$result->tenantId,$result->memberId,$result->credentialFingerprint,$result->requestId]);
        self::assertTrue($this->contexts->isEmpty());
    }

    public function testUnknownForeignAndInactiveOwnershipNeverCreatesMemberContext(): void
    {
        foreach ([[null,101],[101,202],[202,202],[303,303],[404,404],[999,999]] as [$owner,$verified]) {
            $lookup=$this->createMock(MemberSubjectLookup::class);
            $lookup->expects(self::once())->method('tenantId')->with(501)->willReturn($owner);
            try {
                $this->resolver($lookup)->resolve(501,'synthetic-token','request-1',$verified);
                self::fail('Invalid tenant identity was accepted.');
            } catch (DomainException $exception) {
                self::assertSame('MEMBER_TENANT_CONTEXT_UNAVAILABLE',$exception->getMessage());
            }
        }
    }

    public function testMalformedIdentityDoesNotQueryMemberOwnership(): void
    {
        foreach ([[0,'token','request',101],[501,'','request',101],[501,'token','',101],[501,'token','request',0]] as $arguments) {
            $lookup=$this->createMock(MemberSubjectLookup::class);
            $lookup->expects(self::never())->method('tenantId');
            try {
                $this->resolver($lookup)->resolve(...$arguments);
                self::fail('Malformed identity was accepted.');
            } catch (DomainException $exception) {
                self::assertSame('MEMBER_TENANT_CONTEXT_UNAVAILABLE',$exception->getMessage());
            }
        }
    }

    public function testCoreAndPlatformBackgroundEntrypointsKeepLifecycleChecksAndContexts(): void
    {
        $modules=$this->createMock(ModuleRuntimeRepository::class);
        $modules->expects(self::never())->method('installation');
        $modules->expects(self::never())->method('tenantModule');
        $boundary=$this->boundary($modules);
        foreach (['core','platform'] as $module) {
            foreach (['assertExternalCallback','assertWorker','assertScheduled'] as $method) {
                $active = new SystemExecutionContext(new TenantSystemContext(101,'fixture.system','fixture.operation','fixture-request'));
                $this->contexts->run($active,function () use ($boundary,$method,$module,$active): void {
                    $boundary->$method($module);
                    self::assertSame($active,$this->contexts->current());
                });
                foreach ([202,303,404,999] as $tenantId) {
                    try {
                        $this->contexts->run(new SystemExecutionContext(new TenantSystemContext($tenantId,'fixture.system','fixture.operation','fixture-request')),fn()=>$boundary->$method($module));
                        self::fail('Inactive tenant reached a background entry.');
                    } catch (ModuleException $exception) {
                        self::assertSame('CONTEXT_TENANT_REQUIRED',$exception->errorCode);
                    }
                    self::assertTrue($this->contexts->isEmpty());
                }
            }
        }
    }

    public function testNoTrustedExecutionContextStillFailsClosed(): void
    {
        $boundary=$this->boundary($this->createStub(ModuleRuntimeRepository::class));
        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('EXECUTION_CONTEXT_REQUIRED');
        $boundary->assertWorker('core');
    }

    public function testNativeContainerSuppliesExistingDirectoryWithoutManualConstruction(): void
    {
        $this->app->instance(CurrentExecutionContext::class,$this->current);
        $this->app->instance(MemberSubjectLookup::class,$this->createStub(MemberSubjectLookup::class));
        $this->app->instance(ModuleRuntimeRepository::class,$this->createStub(ModuleRuntimeRepository::class));
        self::assertCount(2,(new ReflectionClass(MemberApiTenantContextResolver::class))->getConstructor()->getParameters());
        self::assertCount(3,(new ReflectionClass(ModuleExecutionBoundary::class))->getConstructor()->getParameters());
        self::assertInstanceOf(AdminDirectoryQuery::class,(new ReflectionProperty(MemberApiTenantContextResolver::class,'tenants'))->getValue($this->app->make(MemberApiTenantContextResolver::class)));
        self::assertInstanceOf(AdminDirectoryQuery::class,(new ReflectionProperty(ModuleExecutionBoundary::class,'tenants'))->getValue($this->app->make(ModuleExecutionBoundary::class)));
    }

    private function resolver(MemberSubjectLookup $lookup): MemberApiTenantContextResolver
    {
        self::assertCount(2,(new ReflectionClass(MemberApiTenantContextResolver::class))->getConstructor()->getParameters());
        return new MemberApiTenantContextResolver($lookup,$this->directory);
    }

    private function boundary(ModuleRuntimeRepository $modules): ModuleExecutionBoundary
    {
        self::assertCount(3,(new ReflectionClass(ModuleExecutionBoundary::class))->getConstructor()->getParameters());
        return new ModuleExecutionBoundary($this->current,$modules,$this->directory);
    }
}
