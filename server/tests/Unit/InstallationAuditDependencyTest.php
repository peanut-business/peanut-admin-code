<?php

declare(strict_types=1);

use app\AppService;
use app\common\composition\ModuleComposition;
use app\common\execution\CurrentExecutionContext;
use app\common\execution\ExecutionContextStore;
use app\common\services\audit\AuditContractHost;
use app\common\services\installation\InstallationExecutionHost;
use app\platform\infrastructure\plugin\ModuleCatalogApplier;
use PeanutAdmin\Kernel\Module\CompiledModuleRegistry;
use PeanutAdmin\Kernel\Module\ModuleRuntimeRepository;
use PeanutAdmin\Modules\Identity\Contract\AdminDirectoryQuery;
use PeanutAdmin\Modules\Identity\Contract\TenantModuleStateQueries;
use PHPUnit\Framework\TestCase;

/** 构造真实宿主但只载入本测试的空安装文件；不执行安装、数据库或真实审计写入。 */
final class InstallationAuditDependencyTest extends TestCase
{
    private string $serverRoot;

    protected function setUp(): void
    {
        $parent = dirname(__DIR__, 3) . '/.local/tmp/installation-audit-tests';
        if (!is_dir($parent) && !mkdir($parent, 0700, true) && !is_dir($parent)) {
            throw new RuntimeException('INSTALLATION_AUDIT_TEST_DIRECTORY_UNAVAILABLE');
        }
        self::assertStringStartsWith(dirname(__DIR__, 3) . '/.local/tmp/', (string) realpath($parent));
        $this->serverRoot = $parent . '/case-' . bin2hex(random_bytes(6));
        if (!mkdir($this->serverRoot . '/database', 0700, true)
            || file_put_contents($this->serverRoot . '/database/install.php', "<?php\n// Inert constructor input owned by this test.\n") === false) {
            throw new RuntimeException('INSTALLATION_AUDIT_TEST_FIXTURE_UNAVAILABLE');
        }
    }

    protected function tearDown(): void
    {
        unlink($this->serverRoot . '/database/install.php');
        rmdir($this->serverRoot . '/database');
        rmdir($this->serverRoot);
    }

    /** @return array{string,ModuleCatalogApplier,AdminDirectoryQuery,Closure} */
    private function dependencies(): array
    {
        $runtime = $this->createStub(ModuleRuntimeRepository::class);
        return [
            $this->serverRoot,
            (new ReflectionClass(ModuleCatalogApplier::class))->newInstanceWithoutConstructor(),
            new AdminDirectoryQuery(new CurrentExecutionContext(new ExecutionContextStore())),
            static fn(CompiledModuleRegistry $registry): ModuleRuntimeRepository => $runtime,
        ];
    }

    public function testAuditIsARequiredTypedDependency(): void
    {
        $parameters = (new ReflectionMethod(InstallationExecutionHost::class, '__construct'))->getParameters();
        self::assertCount(7, $parameters);
        self::assertSame('audit', $parameters[4]->getName());
        self::assertSame(AuditContractHost::class, (string) $parameters[4]->getType());
        self::assertFalse($parameters[4]->isOptional());
        self::assertFalse($parameters[4]->allowsNull());
    }

    public function testConstructorKeepsTheExactInjectedAudit(): void
    {
        $audit = new AuditContractHost(null);
        $host = new InstallationExecutionHost(...[...$this->dependencies(), $audit, new TenantModuleStateQueries(), new ModuleComposition(new think\App())]);
        $property = new ReflectionProperty($host, 'audit');
        self::assertTrue($property->isReadOnly());
        self::assertSame($audit, $property->getValue($host));
    }

    public function testInvalidAuditIsRejectedInsteadOfUsingGlobalFallback(): void
    {
        $this->expectException(TypeError::class);
        new InstallationExecutionHost(...[...$this->dependencies(), new stdClass(), new TenantModuleStateQueries(), new ModuleComposition(new think\App())]);
    }

    public function testNativeBindingAndProfileUseTheDeclaredDependency(): void
    {
        $host = file_get_contents((new ReflectionClass(InstallationExecutionHost::class))->getFileName());
        $composition = file_get_contents((new ReflectionClass(AppService::class))->getFileName());
        self::assertDoesNotMatchRegularExpression('/(?<![A-Za-z0-9_])app\s*\(/', $host);
        self::assertStringContainsString("            \$this->audit,\n            \$this->tenantDirectory,", $host);
        self::assertMatchesRegularExpression('/new InstallationExecutionHost\([\s\S]*?\$this->app->make\(AuditContractHost::class\),\s*\$this->app->make\([^\n]*TenantModuleStateQueries::class\),\s*\$this->app->make\(ModuleComposition::class\),\s*\)/', $composition);
    }
}
