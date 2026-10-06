<?php

declare(strict_types=1);

namespace tests\Unit;

use app\AppService;
use PeanutAdmin\DataPermission\Catalog\ResourceOperationCatalog;
use PeanutAdmin\DataPermission\Policy\PolicyRepository;
use PeanutAdmin\Modules\Identity\DataPermission\Application\EffectiveAccessPreviewService;
use PeanutAdmin\Modules\Identity\DataPermission\Catalog\ThinkPhpResourceOperationCatalog;
use PeanutAdmin\Modules\Identity\DataPermission\Policy\ThinkPhpPolicyRepository;
use PeanutAdmin\Modules\Identity\ModuleProvider;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;
use think\App;
use think\DbManager;

/** 原生应用授权注册与模块绑定；只构造对象，不执行权限预览、SQL或HTTP请求。 */
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class EffectiveAccessPreviewAssemblyTest extends TestCase
{
    private App $app;
    private int $databaseAttempts = 0;

    protected function setUp(): void
    {
        $root = dirname(__DIR__, 3);
        require_once $root . '/server/vendor/topthink/framework/src/helper.php';
        $this->app = new App($root . '/.local/tmp/effective-preview-assembly');
        $this->app->bind(DbManager::class, function (): never {
            ++$this->databaseAttempts;
            throw new \LogicException('FIXTURE_DATABASE_FORBIDDEN');
        });
        foreach (require $root . '/server/app/provider.php' as $abstract => $concrete) {
            $this->app->bind($abstract, $concrete);
        }
        $host = new AppService($this->app);
        foreach (['registerExecutionContext', 'registerAuthorization'] as $method) {
            (new \ReflectionMethod($host, $method))->invoke($host);
        }
        foreach ((new ModuleProvider())->bindings() as $abstract => $concrete) {
            $this->app->bind($abstract, $concrete);
        }
    }

    public function testNativeContainerConstructsThePreviewWithTheExistingOwnerImplementations(): void
    {
        $preview = $this->app->make(EffectiveAccessPreviewService::class);
        self::assertInstanceOf(EffectiveAccessPreviewService::class, $preview);
        $catalog = (new \ReflectionProperty($preview, 'catalog'))->getValue($preview);
        $policies = (new \ReflectionProperty($preview, 'policies'))->getValue($preview);
        self::assertInstanceOf(ThinkPhpResourceOperationCatalog::class, $catalog);
        self::assertInstanceOf(ThinkPhpPolicyRepository::class, $policies);
        foreach ([$catalog, $policies, $preview] as $object) {
            self::assertStringStartsWith(dirname(__DIR__, 2) . '/app/modules/official/identity/', (new \ReflectionClass($object))->getFileName());
        }
        self::assertSame(0, $this->databaseAttempts, 'Registering or constructing a service must not query production data.');
    }

    public function testExistingCatalogContractStaysPublicAndPolicyStoragePrivate(): void
    {
        $bindings = (new ModuleProvider())->bindings();
        self::assertSame(ThinkPhpResourceOperationCatalog::class, $bindings[ResourceOperationCatalog::class] ?? null);
        self::assertSame(ThinkPhpPolicyRepository::class, $bindings[PolicyRepository::class] ?? null);
        $manifest = json_decode(file_get_contents(dirname(__DIR__, 2) . '/app/modules/official/identity/module.json'), true, 512, JSON_THROW_ON_ERROR);
        // The fixed business catalog was already public; binding its Core interface must not revoke that contract.
        self::assertContains(ThinkPhpResourceOperationCatalog::class, $manifest['contracts']['exports']);
        self::assertNotContains(\PeanutAdmin\Modules\Identity\DataPermission\Model\DataPermissionPolicyRecord::class, $manifest['contracts']['exports']);
        self::assertNotContains(ThinkPhpPolicyRepository::class, $manifest['contracts']['exports']);
        self::assertSame(0, $this->databaseAttempts);
    }

    public function testExplicitNativeInstancesAreNotReplacedByHiddenConstruction(): void
    {
        $catalog = $this->createStub(ResourceOperationCatalog::class);
        $policies = $this->createStub(PolicyRepository::class);
        $this->app->instance(ResourceOperationCatalog::class, $catalog);
        $this->app->instance(PolicyRepository::class, $policies);
        $preview = $this->app->make(EffectiveAccessPreviewService::class);
        self::assertSame($catalog, (new \ReflectionProperty($preview, 'catalog'))->getValue($preview));
        self::assertSame($policies, (new \ReflectionProperty($preview, 'policies'))->getValue($preview));
        self::assertSame(0, $this->databaseAttempts);
    }
}
