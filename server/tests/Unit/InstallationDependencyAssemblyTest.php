<?php

declare(strict_types=1);

use app\AppService;
use app\common\services\installation\InstallationExecutionHost;
use PeanutAdmin\Kernel\Module\CompiledModuleRegistry;
use PeanutAdmin\Kernel\Module\ModuleRuntimeRepository;
use PeanutAdmin\Modules\Identity\Module\Persistence\ThinkPhpModuleRuntimeRepository;
use PHPUnit\Framework\TestCase;
use think\App;

/** Executes only dependency construction and the typed callback; never constructs the installer or performs I/O. */
final class InstallationDependencyAssemblyTest extends TestCase
{
    public function testCompositionCreatesFreshLockedRepositoriesForTheExactSuppliedRegistry(): void
    {
        $service = new AppService(new App(dirname(__DIR__, 3) . '/.local/tmp/installation-dependency-fixture'));
        $method = new ReflectionMethod($service, 'installationModuleRuntime');
        $firstRegistry = new CompiledModuleRegistry([], [], [], [], 'first');
        $secondRegistry = new CompiledModuleRegistry([], [], [], [], 'second');
        $first = $method->invoke($service, $firstRegistry);
        $second = $method->invoke($service, $secondRegistry);
        self::assertInstanceOf(ThinkPhpModuleRuntimeRepository::class, $first);
        self::assertInstanceOf(ModuleRuntimeRepository::class, $second);
        self::assertNotSame($first, $second);
        self::assertSame($firstRegistry, (new ReflectionProperty($first, 'registry'))->getValue($first));
        self::assertSame($secondRegistry, (new ReflectionProperty($second, 'registry'))->getValue($second));
        self::assertTrue((new ReflectionProperty($first, 'lockAvailabilityReads'))->getValue($first));
        self::assertTrue((new ReflectionProperty($second, 'lockAvailabilityReads'))->getValue($second));
    }

    public function testInstallerUsesTheTypedFactoryOnlyWhenTheCurrentRegistryIsSupplied(): void
    {
        $host = (new ReflectionClass(InstallationExecutionHost::class))->newInstanceWithoutConstructor();
        $repository = $this->createStub(ModuleRuntimeRepository::class);
        $seen = [];
        (new ReflectionProperty($host, 'moduleRuntimeFactory'))->setValue($host, static function (CompiledModuleRegistry $registry) use (&$seen, $repository): ModuleRuntimeRepository {
            $seen[] = $registry;
            return $repository;
        });
        self::assertSame([], $seen);
        $registry = new CompiledModuleRegistry([], [], [], [], 'late-registry');
        self::assertSame($repository, (new ReflectionMethod($host, 'runtimeForProfile'))->invoke($host, $registry));
        self::assertSame([$registry], $seen);
        $source = file_get_contents((new ReflectionClass($host))->getFileName());
        self::assertStringNotContainsString('ThinkPhpModuleRuntimeRepository', $source);
        self::assertStringContainsString('$registry = $this->definitionRegistry();', $source);
        self::assertStringContainsString('$this->runtimeForProfile($registry)', $source);
        self::assertLessThan(strpos($source, '$this->runtimeForProfile($registry)'), strpos($source, '$lifecycle->reconcile($moduleKey)'));
    }

    public function testWrongFactoryResultIsRejectedInsteadOfFallingBackToARepository(): void
    {
        $host = (new ReflectionClass(InstallationExecutionHost::class))->newInstanceWithoutConstructor();
        (new ReflectionProperty($host, 'moduleRuntimeFactory'))->setValue($host, static fn(CompiledModuleRegistry $registry): object => new stdClass());
        $this->expectException(TypeError::class);
        (new ReflectionMethod($host, 'runtimeForProfile'))->invoke($host, new CompiledModuleRegistry([], [], [], [], 'fixture'));
    }

    public function testFactoryFailureIsNotHiddenOrReplacedWithAnotherSource(): void
    {
        $host = (new ReflectionClass(InstallationExecutionHost::class))->newInstanceWithoutConstructor();
        (new ReflectionProperty($host, 'moduleRuntimeFactory'))->setValue($host, static function (CompiledModuleRegistry $registry): never {
            throw new DomainException('FIXTURE_ASSEMBLY_FAILED');
        });
        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('FIXTURE_ASSEMBLY_FAILED');
        (new ReflectionMethod($host, 'runtimeForProfile'))->invoke($host, new CompiledModuleRegistry([], [], [], [], 'fixture'));
    }
}
