<?php

declare(strict_types=1);

namespace tests\Productization;

use app\command\ModuleInstallPackage;
use app\command\ModuleUpdatePackage;
use app\common\execution\CurrentExecutionContext;
use app\common\execution\ExecutionContext;
use app\common\execution\ExecutionContextStore;
use app\common\execution\InstanceExecutionContext;
use app\platform\infrastructure\plugin\ModuleCatalogApplier;
use app\platform\services\plugin\PlatformModuleRuntimeService;
use app\platform\services\plugin\PluginCatalogSyncService;
use app\platform\services\plugin\PluginPackageArchiveService;
use app\platform\services\plugin\PluginRuntimeGovernanceService;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use think\App;
use think\Container;
use think\console\Command;
use think\console\Input;
use think\console\Output;

final class ModuleCommandContractTest extends TestCase
{
    private Container $previousContainer;
    private string $temporary;

    protected function setUp(): void
    {
        $this->previousContainer = Container::getInstance();
        $temporaryRoot = realpath(sys_get_temp_dir());
        self::assertIsString($temporaryRoot);
        $this->temporary = $temporaryRoot . '/peanut-module-command-' . bin2hex(random_bytes(8));
        mkdir($this->temporary . '/server', 0700, true);
    }

    protected function tearDown(): void
    {
        Container::setInstance($this->previousContainer);
        $this->removeTree($this->temporary);
    }

    public function testMultiTenantDevelopmentCommandReachesSignatureVerification(): void
    {
        $signingKeypair = sodium_crypto_sign_keypair();
        $archive = $this->temporary . '/signed-fixture.tar';
        $packed = (new PluginPackageArchiveService(dirname(__DIR__, 2)))->packModule(
            'fixture.delivery-record',
            $archive,
            [
                'key_id' => 'module-command-contract',
                'secret_key' => sodium_crypto_sign_secretkey($signingKeypair),
            ],
        );
        $otherKeypair = sodium_crypto_sign_keypair();
        [$app, $store] = $this->application(
            'multi-tenant',
            'development',
            true,
            ['module-command-contract' => sodium_crypto_sign_publickey($otherKeypair)],
        );

        $command = $this->command($app, ModuleInstallPackage::class);
        $output = new Output('buffer');
        $status = $command->run(new Input([
            $archive,
            '--sha256=' . $packed['sha256'],
            '--signature-key-id=module-command-contract',
        ]), $output);

        self::assertSame(1, $status);
        self::assertSame(
            'MODULE_PACKAGE_SIGNATURE_INVALID',
            json_decode(trim($output->fetch()), true, 16, JSON_THROW_ON_ERROR)['error'] ?? null,
        );
        self::assertTrue($store->isEmpty());
    }

    public function testMultiTenantDevelopmentUpdateStillRejectsChecksumMismatch(): void
    {
        $archive = $this->temporary . '/invalid-package.tar';
        file_put_contents($archive, 'not-a-module-package');
        [$app, $store] = $this->application('multi-tenant', 'development', true);

        $command = $this->command($app, ModuleUpdatePackage::class);
        $output = new Output('buffer');
        $status = $command->run(new Input([
            $archive,
            '--sha256=' . str_repeat('0', 64),
        ]), $output);
        $result = json_decode(trim($output->fetch()), true, 16, JSON_THROW_ON_ERROR);

        self::assertSame(1, $status);
        self::assertSame('MODULE_PACKAGE_ARCHIVE_DIGEST_MISMATCH', $result['code'] ?? null);
        self::assertTrue($store->isEmpty());
    }

    #[DataProvider('deniedRuntimeProvider')]
    public function testCommandLifecycleRejectsNonDevelopmentDebugOrKnownEdition(
        string $mode,
        string $environment,
        bool $debug,
    ): void {
        [$app, $store] = $this->application($mode, $environment, $debug);
        $command = $this->command($app, ModuleInstallPackage::class);
        $output = new Output('buffer');

        self::assertSame(1, $command->run(new Input([$this->temporary . '/absent.tar']), $output));
        self::assertSame(
            'MODULE_RUNTIME_MUTATION_DISABLED',
            json_decode(trim($output->fetch()), true, 16, JSON_THROW_ON_ERROR)['error'] ?? null,
        );
        self::assertTrue($store->isEmpty());
    }

    /** @return iterable<string,array{string,string,bool}> */
    public static function deniedRuntimeProvider(): iterable
    {
        yield 'non-debug' => ['multi-tenant', 'development', false];
        yield 'production' => ['multi-tenant', 'production', true];
        yield 'unknown-mode' => ['unknown', 'development', true];
    }

    public function testCommandLifecycleRejectsPersonnelContext(): void
    {
        [$app, $store] = $this->application('multi-tenant', 'development', true);
        $command = $this->command($app, ModuleInstallPackage::class);
        $person = new class implements ExecutionContext {
            public function operation(): string
            {
                return 'console.module:install-package';
            }
            public function requestId(): string
            {
                return 'http-personnel-command';
            }
            public function tenantId(): int
            {
                return 101;
            }
            public function actor(): array
            {
                return ['tenant_id' => 101, 'id' => 301];
            }
        };

        $output = new Output('buffer');
        $status = $store->run($person, function () use ($command, $output, $store): int {
            $status = $command->run(new Input([$this->temporary . '/absent.tar']), $output);
            self::assertSame('http-personnel-command', $store->require()->requestId());
            return $status;
        });

        self::assertSame(1, $status);
        self::assertSame(
            'MODULE_RUNTIME_MUTATION_DISABLED',
            json_decode(trim($output->fetch()), true, 16, JSON_THROW_ON_ERROR)['error'] ?? null,
        );
        self::assertTrue($store->isEmpty());
    }

    public function testCommandRejectsAContextNotEstablishedByItsOwnLifecycle(): void
    {
        [$app, $store] = $this->application('multi-tenant', 'development', true);
        $command = $this->command($app, ModuleInstallPackage::class);
        $preloaded = new InstanceExecutionContext(
            'console.module:install-package',
            'preloaded-console-context',
        );
        $output = new Output('buffer');

        $status = $store->run(
            $preloaded,
            fn(): int => $command->run(new Input([$this->temporary . '/absent.tar']), $output),
        );

        self::assertSame(1, $status);
        self::assertSame(
            'MODULE_RUNTIME_MUTATION_DISABLED',
            json_decode(trim($output->fetch()), true, 16, JSON_THROW_ON_ERROR)['error'] ?? null,
        );
        self::assertTrue($store->isEmpty());
    }

    public function testPackageCommandsUseTheSharedRuntimeAndCommandBoundary(): void
    {
        $serverRoot = dirname(__DIR__, 2);
        foreach ([
            'ModuleInstallPackage.php',
            'ModuleUpdatePackage.php',
            'ModuleDisablePackage.php',
            'ModuleUninstallPackage.php',
        ] as $file) {
            $source = (string) file_get_contents($serverRoot . '/app/command/' . $file);
            self::assertStringContainsString('$this->assertDevelopmentInstanceMaintenanceAccess()', $source);
            self::assertStringContainsString('$this->moduleRuntime()->', $source);
            self::assertStringNotContainsString('app()->isDebug()', $source);
            self::assertStringNotContainsString('Config::get(', $source);
        }
    }

    /**
     * @param array<string,string> $trustedKeys
     * @return array{App,ExecutionContextStore}
     */
    private function application(
        string $mode,
        string $environment,
        bool $debug,
        array $trustedKeys = [],
    ): array {
        $app = (new App($this->temporary . '/server'))->debug($debug);
        $app->config->set(['mode' => $mode], 'deployment');
        $app->config->set(['environment' => $environment], 'peanut');
        $store = new ExecutionContextStore();
        $current = new CurrentExecutionContext($store);
        $catalogs = (new \ReflectionClass(ModuleCatalogApplier::class))->newInstanceWithoutConstructor();
        $moduleConfig = ['plugin_lock' => '../plugins.lock'];
        $runtime = new PlatformModuleRuntimeService(
            $this->temporary . '/server',
            $moduleConfig,
            $trustedKeys,
            new PluginRuntimeGovernanceService($this->temporary . '/server', $moduleConfig, $catalogs),
            new PluginCatalogSyncService($this->temporary . '/server', $moduleConfig, $catalogs),
            $catalogs,
        );
        $app->instance(ExecutionContextStore::class, $store);
        $app->instance(CurrentExecutionContext::class, $current);
        $app->instance(ModuleCatalogApplier::class, $catalogs);
        $app->instance(PlatformModuleRuntimeService::class, $runtime);
        return [$app, $store];
    }

    /** @param class-string<Command> $class */
    private function command(App $app, string $class): Command
    {
        $command = $app->make($class);
        self::assertInstanceOf($class, $command);
        $command->setApp($app);
        return $command;
    }

    private function removeTree(string $path): void
    {
        if (!is_dir($path)) {
            if (is_file($path)) {
                unlink($path);
            }
            return;
        }
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($iterator as $entry) {
            $entry->isDir() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
        }
        rmdir($path);
    }
}
