<?php

declare(strict_types=1);

use app\common\composition\ModuleComposition;
use Composer\Autoload\ClassLoader;
use PeanutAdmin\Kernel\Module\CompiledModuleRegistry;
use PeanutAdmin\Kernel\Module\ManifestDocument;
use PeanutAdmin\Kernel\Module\ModuleProvider;
use PeanutAdmin\Modules\Notification\Contract\NotificationProducer;
use PeanutAdmin\Modules\Notification\Contract\NotificationProducerRegistry;
use PHPUnit\Framework\TestCase;
use think\App;

/** Real Notification source + native composition, synthetic producer; no DB, queue or external delivery. */
final class NativeNotificationCompositionTest extends TestCase
{
    private static ClassLoader $moduleLoader;
    private static string $notificationRoot;

    public static function setUpBeforeClass(): void
    {
        $root = getenv('PEANUT_NOTIFICATION_MODULE_ROOT');
        if (!is_string($root) || !is_file($root . '/composer.json') || !is_file($root . '/module.json')) {
            throw new RuntimeException('Set PEANUT_NOTIFICATION_MODULE_ROOT to the actual Notification module source.');
        }
        self::$notificationRoot = realpath($root);
        $composer = json_decode(file_get_contents($root . '/composer.json'), true, 512, JSON_THROW_ON_ERROR);
        self::$moduleLoader = new ClassLoader();
        foreach ($composer['autoload']['psr-4'] as $prefix => $paths) {
            self::$moduleLoader->addPsr4($prefix, array_map(
                static fn(string $path): string => $root . '/' . $path,
                (array) $paths,
            ));
        }
        self::$moduleLoader->register(true);
    }

    public static function tearDownAfterClass(): void
    {
        if (isset(self::$moduleLoader)) {
            self::$moduleLoader->unregister();
        }
    }

    public function testRealNotificationAndDependenciesAcceptANonWorkflowProducer(): void
    {
        [$app, $producer] = $this->appWithProducer();
        $registry = $this->registry();
        $app->instance(CompiledModuleRegistry::class, $registry);
        self::assertNotContains('peanut.workflow', $registry->moduleKeys());
        foreach ($registry->requireManifest('peanut.notification')->data['dependencies'] as $dependency) {
            self::assertContains($dependency['module_key'], $registry->moduleKeys());
        }
        (new ModuleComposition($app))->register($registry);
        $producerRegistry = $app->make(NotificationProducerRegistry::class);
        self::assertSame($producer, $producerRegistry->require('fixture.notification'));
        self::assertSame($producerRegistry, $app->make(NotificationProducerRegistry::class));
        self::assertSame($app->make(IndependentNotificationFixtureProvider::class)->calls, 1);
        self::assertStringStartsWith(self::$notificationRoot . '/', (new ReflectionClass(NotificationProducerRegistry::class))->getFileName());
    }

    public function testDuplicateBusinessProducerKeyStillFails(): void
    {
        [$app] = $this->appWithProducer();
        $registry = $this->registry(true);
        $app->instance(CompiledModuleRegistry::class, $registry);
        (new ModuleComposition($app))->register($registry);
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('NOTIFICATION_PRODUCER_CONFLICT');
        $app->make(NotificationProducerRegistry::class);
    }

    public function testUnselectedNotificationDoesNotInvokeTheProducerMethod(): void
    {
        [$app] = $this->appWithProducer();
        $manifest = $this->fixtureManifest(IndependentNotificationFixtureProvider::class, 'fixture.notification');
        (new ModuleComposition($app))->register(new CompiledModuleRegistry([$manifest], [], [], [], $manifest->digest));
        self::assertSame(0, $app->make(IndependentNotificationFixtureProvider::class)->calls);
        self::assertFalse($app->bound(NotificationProducerRegistry::class));
    }

    private function registry(bool $duplicate = false): CompiledModuleRegistry
    {
        $manifests = [];
        foreach ([
            \PeanutAdmin\Modules\Identity\ModuleProvider::class,
            \PeanutAdmin\Modules\Task\ModuleProvider::class,
            \PeanutAdmin\Modules\Integration\ModuleProvider::class,
        ] as $provider) {
            $root = dirname((new ReflectionClass($provider))->getFileName(), 2);
            $manifests[] = ManifestDocument::fromObject($root, json_decode(file_get_contents($root . '/module.json'), false, 512, JSON_THROW_ON_ERROR));
        }
        $manifests[] = ManifestDocument::fromObject(self::$notificationRoot, json_decode(file_get_contents(self::$notificationRoot . '/module.json'), false, 512, JSON_THROW_ON_ERROR));
        $manifests[] = $this->fixtureManifest(IndependentNotificationFixtureProvider::class, 'fixture.notification');
        if ($duplicate) {
            $manifests[] = $this->fixtureManifest(DuplicateNotificationFixtureProvider::class, 'fixture.duplicate');
        }
        return new CompiledModuleRegistry($manifests, [], [], [], hash('sha256', implode('', array_column($manifests, 'digest'))));
    }

    private function fixtureManifest(string $provider, string $key): ManifestDocument
    {
        return ManifestDocument::fromObject('/fixture', (object) [
            'key' => $key,
            'backend' => (object) ['provider' => $provider],
        ]);
    }

    private function appWithProducer(): array
    {
        $app = new App(dirname(__DIR__, 2));
        $producer = $this->createMock(NotificationProducer::class);
        $producer->method('key')->willReturn('fixture.notification');
        $producer->expects(self::never())->method('authorize');
        $producer->expects(self::never())->method('authorizeRead');
        $producer->expects(self::never())->method('publication');
        $app->instance('fixture.notification.producer', $producer);
        return [$app, $producer];
    }
}

class IndependentNotificationFixtureProvider implements ModuleProvider
{
    public int $calls = 0;

    public function __construct(private App $app) {}

    public function moduleKey(): string
    {
        return 'fixture.notification';
    }

    public function bindings(): array
    {
        return [];
    }

    public function notificationProducers(App $app): array
    {
        ++$this->calls;
        return [$this->app->make('fixture.notification.producer')];
    }
}

final class DuplicateNotificationFixtureProvider extends IndependentNotificationFixtureProvider
{
    public function moduleKey(): string
    {
        return 'fixture.duplicate';
    }
}
