<?php

declare(strict_types=1);

use app\common\composition\ModuleComposition;
use app\common\composition\ModuleExtensionService;
use app\common\execution\ExecutionContext;
use app\common\execution\ExecutionContextStore;
use app\platform\services\developer\DeveloperCenterCatalogService;
use PeanutAdmin\Kernel\Module\CompiledModuleRegistry;
use PeanutAdmin\Kernel\Module\ManifestDocument;
use PeanutAdmin\Kernel\Module\ModuleProvider;
use PHPUnit\Framework\TestCase;
use think\App;

/** Real framework/Host code, in-memory fixtures; no database, network or installed APP writes. */
final class NativeExtensionBehaviorTest extends TestCase
{
    private string $root;
    private App $app;
    private CompiledModuleRegistry $registry;

    protected function setUp(): void
    {
        $this->root = dirname(__DIR__, 3) . '/.local/tmp/native-extension-' . bin2hex(random_bytes(8)) . '/server';
        mkdir($this->root . '/app/modules/fixture/native_extension', 0700, true);
        mkdir($this->root . '/config', 0700);
        file_put_contents($this->root . '/config/console.php', '<?php return ["commands" => [], "module_commands" => []];');
        file_put_contents($this->root . '/config/cache.php', '<?php return ["default" => "file", "stores" => ["file" => ["type" => "File", "path" => ' . var_export($this->root . '/runtime/cache', true) . ']]];');
        file_put_contents($this->root . '/app/service.php', '<?php return [\\app\\common\\composition\\ModuleExtensionService::class];');
        $data = (object) [
            'key' => 'fixture.native-extension',
            'name' => 'Native extension behavior fixture',
            'version' => '1.0.0',
            'backend' => (object) ['provider' => NativeExtensionFixtureProvider::class],
            'contracts' => (object) ['exports' => [NativeExtensionFixtureQueries::class], 'events' => []],
        ];
        file_put_contents($this->root . '/app/modules/fixture/native_extension/module.json', json_encode($data, JSON_THROW_ON_ERROR));
        file_put_contents($this->root . '/app/modules/fixture/native_extension/composer.json', json_encode([
            'name' => 'fixture/native-extension',
            'autoload' => ['psr-4' => ['Fixture\\Modules\\NativeExtension\\' => 'src/']],
        ], JSON_THROW_ON_ERROR));
        $manifest = ManifestDocument::fromObject($this->root . '/app/modules/fixture/native_extension', $data);
        $this->registry = new CompiledModuleRegistry([$manifest], [], [], [], $manifest->digest);
        NativeExtensionFixtureProvider::$constructions = 0;
        $this->app = new App($this->root);
        $this->app->instance(CompiledModuleRegistry::class, $this->registry);
        (new ModuleComposition($this->app))->register($this->registry);
    }

    protected function tearDown(): void
    {
        $files = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($this->root, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($files as $file) {
            $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
        }
        rmdir($this->root);
        rmdir(dirname($this->root));
    }

    public function testRealAppInitializationOrdersRegisterAppInitAndBoot(): void
    {
        $provider = $this->provider();
        self::assertSame([], $provider->trace);
        $this->initializeApp();
        self::assertSame(['register', 'app-init', 'boot:ready'], $provider->trace);
        self::assertSame(1, NativeExtensionFixtureProvider::$constructions);
        self::assertSame($provider, $this->app->getService(NativeExtensionFixtureProvider::class));
        $this->app->event->trigger('fixture.saved.v1', 'saved');
        self::assertSame('event:saved', $provider->trace[3]);
    }

    public function testRepeatedNativeRegistrationDoesNotDuplicateListeners(): void
    {
        $extension = new ModuleExtensionService($this->app);
        $extension->register();
        $extension->register();
        $this->app->boot();
        $this->app->event->trigger('fixture.saved.v1', 'one');
        self::assertSame(['register', 'boot:ready', 'event:one'], $this->provider()->trace);
        self::assertSame(1, NativeExtensionFixtureProvider::$constructions);
    }

    public function testNativeBootFailurePropagatesWithoutRegisteringLateListeners(): void
    {
        $this->provider()->failBoot = true;
        try {
            $this->initializeApp();
            self::fail('Boot failure must propagate.');
        } catch (RuntimeException $exception) {
            self::assertSame('fixture boot failure', $exception->getMessage());
        }
        self::assertSame(['register', 'app-init'], $this->provider()->trace);
        self::assertSame([], $this->app->event->trigger('fixture.saved.v1', 'not delivered'));
    }

    public function testReadonlyCatalogUsesTheSameInjectedProviderWithoutBooting(): void
    {
        $provider = $this->provider();
        $reads = $provider->bindingReads;
        $catalog = new DeveloperCenterCatalogService($this->root, $this->registry, $this->app);
        $snapshot = $catalog->snapshot('fixture.native-extension');
        self::assertCount(1, $snapshot['modules']);
        $module = $snapshot['modules'][0];
        self::assertSame(NativeExtensionFixtureQueries::class, $module['provider']['bindings'][0]['contract']);
        self::assertTrue($module['public_services'][0]['provider_binding_declared']);
        self::assertSame($reads + 1, $provider->bindingReads);
        self::assertSame(1, NativeExtensionFixtureProvider::$constructions);
        self::assertSame([], $provider->trace);
        self::assertSame('not_checked', $module['provider']['runtime_effective']);
    }

    public function testCatalogDoesNotConstructAnUnselectedProvider(): void
    {
        $app = new App($this->root);
        $registry = new CompiledModuleRegistry([], [], [], [], hash('sha256', 'empty'));
        $count = NativeExtensionFixtureProvider::$constructions;
        $snapshot = (new DeveloperCenterCatalogService($this->root, $registry, $app))->snapshot('fixture.native-extension');
        self::assertSame([], $snapshot['modules'][0]['provider']['bindings']);
        self::assertFalse($snapshot['modules'][0]['public_services'][0]['provider_binding_declared']);
        self::assertSame($count, NativeExtensionFixtureProvider::$constructions);
        self::assertFalse($app->exists(NativeExtensionFixtureProvider::class));
    }

    public function testMutableInputObjectAndExplicitListenerReturns(): void
    {
        $input = (object) ['title' => '  draft  '];
        $this->app->event->listen('fixture.prepare.v1', static function (object $value): string {
            $value->title = trim($value->title);
            return $value->title;
        });
        self::assertSame(['draft'], $this->app->event->trigger('fixture.prepare.v1', $input));
        self::assertSame('draft', $input->title);
    }

    public function testFalseStopsListenersButDoesNotCancelTheCallingUseCase(): void
    {
        $trace = [];
        $this->app->event->listen('fixture.before.v1', static function () use (&$trace): bool {
            $trace[] = 'first';
            return false;
        });
        $this->app->event->listen('fixture.before.v1', static function () use (&$trace): void {
            $trace[] = 'unexpected';
        });
        $results = $this->app->event->trigger('fixture.before.v1');
        $trace[] = 'use-case-continues';
        self::assertSame([false], $results);
        self::assertSame(['first', 'use-case-continues'], $trace);
    }

    public function testUntilUsesFirstNonNullIncludingZero(): void
    {
        $trace = [];
        $this->app->event->listen('fixture.decision.v1', static fn() => null);
        $this->app->event->listen('fixture.decision.v1', static fn() => 0);
        $this->app->event->listen('fixture.decision.v1', static function () use (&$trace): int {
            $trace[] = 'unexpected';
            return 1;
        });
        self::assertSame(0, $this->app->event->until('fixture.decision.v1'));
        self::assertSame([], $trace);
    }

    public function testArrayPayloadIsNotAnImplicitMutablePipeline(): void
    {
        $input = ['title' => 'original'];
        $this->app->event->listen('fixture.array.v1', static function (array $value): array {
            $value['title'] = 'changed';
            return $value;
        });
        self::assertSame([['title' => 'changed']], $this->app->event->trigger('fixture.array.v1', $input));
        self::assertSame(['title' => 'original'], $input);
    }

    public function testListenerExceptionPropagatesUnchanged(): void
    {
        $failure = new DomainException('input refused');
        $this->app->event->listen('fixture.fail.v1', static function () use ($failure): never {
            throw $failure;
        });
        try {
            $this->app->event->trigger('fixture.fail.v1');
            self::fail('Listener exception was swallowed.');
        } catch (DomainException $exception) {
            self::assertSame($failure, $exception);
        }
    }

    public function testListenerFailureRestoresOuterAndThenReleasesRootContext(): void
    {
        $contexts = new ExecutionContextStore();
        $outer = $this->createStub(ExecutionContext::class);
        $outer->method('tenantId')->willReturn(11);
        $inner = clone $outer;
        $failure = new RuntimeException('listener failed');
        $this->app->event->listen('fixture.context.v1', static function () use ($failure): never {
            throw $failure;
        });
        $contexts->run($outer, function () use ($contexts, $outer, $inner, $failure): void {
            try {
                $contexts->run($inner, fn() => $this->app->event->trigger('fixture.context.v1'));
                self::fail('Nested listener failure was swallowed.');
            } catch (RuntimeException $exception) {
                self::assertSame($failure, $exception);
            }
            self::assertSame($outer, $contexts->current());
            self::assertNotNull($contexts->rootIdentity());
        });
        self::assertTrue($contexts->isEmpty());
        self::assertNull($contexts->rootIdentity());
    }

    private function initializeApp(): void
    {
        $timezone = date_default_timezone_get();
        try {
            $this->app->initialize();
        } finally {
            // Native initialization installs one pair of handlers; leave PHPUnit's handlers intact.
            restore_error_handler();
            restore_exception_handler();
            date_default_timezone_set($timezone);
        }
    }

    private function provider(): NativeExtensionFixtureProvider
    {
        return $this->app->make(NativeExtensionFixtureProvider::class);
    }
}

interface NativeExtensionFixtureQueries
{
    public function value(): string;
}

final class NativeExtensionFixtureQueryService implements NativeExtensionFixtureQueries
{
    public function value(): string
    {
        return 'ready';
    }
}

final class NativeExtensionFixtureProvider extends \think\Service implements ModuleProvider
{
    public static int $constructions = 0;
    public array $trace = [];
    public int $bindingReads = 0;
    public bool $failBoot = false;

    public function __construct(App $app)
    {
        parent::__construct($app);
        ++self::$constructions;
    }

    public function moduleKey(): string
    {
        return 'fixture.native-extension';
    }

    public function bindings(): array
    {
        ++$this->bindingReads;
        return [NativeExtensionFixtureQueries::class => NativeExtensionFixtureQueryService::class];
    }

    public function register(): void
    {
        $this->trace[] = 'register';
        $this->app->event->listen(\think\event\AppInit::class, function (): void {
            $this->trace[] = 'app-init';
        });
    }

    public function boot(NativeExtensionFixtureQueries $queries): void
    {
        if ($this->failBoot) {
            throw new RuntimeException('fixture boot failure');
        }
        $this->trace[] = 'boot:' . $queries->value();
        $this->app->event->listen('fixture.saved.v1', function (string $value): void {
            $this->trace[] = 'event:' . $value;
        });
    }
}
