<?php

declare(strict_types=1);

namespace tests\Unit;

use PHPUnit\Framework\TestCase;

final class ModuleAuthoringBootstrapTest extends TestCase
{
    private string $repositoryRoot;
    private string $fixtureRoot;

    protected function setUp(): void
    {
        $this->repositoryRoot = dirname(__DIR__, 3);
        $temporaryRoot = realpath(sys_get_temp_dir());
        self::assertIsString($temporaryRoot);
        $this->fixtureRoot = $temporaryRoot . '/peanut-plugin-authoring-' . bin2hex(random_bytes(8));
        $this->prepareSourceFixture();
    }

    protected function tearDown(): void
    {
        $this->removeTree($this->fixtureRoot);
    }

    public function testRealDispatchRepairsStaleArtifactsWithoutOpeningRuntimeBootstrap(): void
    {
        $development = $this->environment('development', true);
        $production = $this->environment('production', true);

        $ordinary = $this->runThink(['list', '--raw'], $development);
        self::assertNotSame(0, $ordinary['status']);
        self::assertStringContainsString('manifest digest', $ordinary['output']);

        $http = $this->runPhp([$this->fixtureRoot . '/server/http-entry.php'], $development);
        self::assertNotSame(0, $http['status']);
        self::assertStringContainsString('manifest digest', $http['output']);

        $productionAuthoring = $this->runThink([
            'module:make',
            'acme.authoring',
            '1.0.0',
            '--module=acme.authoring=server/app/modules/acme/authoring',
        ], $production);
        self::assertNotSame(0, $productionAuthoring['status']);
        self::assertStringContainsString('manifest digest', $productionAuthoring['output']);

        $invalidPath = $this->runThink([
            'module:make',
            'acme.authoring',
            '1.0.0',
            '--module=acme.authoring=server/app/modules/acme/not_the_key_path',
        ], $development);
        self::assertSame(1, $invalidPath['status']);
        self::assertStringContainsString('Module root is not key-derived', $invalidPath['output']);

        $moduleManifest = $this->fixtureRoot . '/server/app/modules/acme/authoring/module.json';
        $validDefinition = file_get_contents($moduleManifest);
        self::assertIsString($validDefinition);
        file_put_contents($moduleManifest, "{\n");
        try {
            $invalidDefinition = $this->runThink([
                'module:make',
                'acme.authoring',
                '1.0.0',
                '--module=acme.authoring=server/app/modules/acme/authoring',
            ], $development);
        } finally {
            file_put_contents($moduleManifest, $validDefinition);
        }
        self::assertSame(1, $invalidDefinition['status']);
        self::assertStringContainsString('JSON file is invalid:', $invalidDefinition['output']);

        $staleLock = $this->runThink(['module:lock', '--check'], $development);
        self::assertSame(1, $staleLock['status']);
        self::assertStringContainsString('modules.lock is not canonical', $staleLock['output']);

        $make = $this->runThink([
            'module:make',
            'acme.authoring',
            '1.0.0',
            '--module=acme.authoring=server/app/modules/acme/authoring',
        ], $development);
        self::assertSame(0, $make['status'], $make['output']);

        $lock = $this->runThink(['module:lock', '--write'], $development);
        self::assertSame(0, $lock['status'], $lock['output']);
        self::assertSame('written', json_decode(trim($lock['output']), true, 32, JSON_THROW_ON_ERROR)['status'] ?? null);
    }

    private function prepareSourceFixture(): void
    {
        $server = $this->fixtureRoot . '/server';
        foreach (['app/command', 'config', 'resources/schemas', 'vendor', 'app/modules/acme/authoring/src'] as $directory) {
            self::assertTrue(mkdir($server . '/' . $directory, 0700, true) || is_dir($server . '/' . $directory));
        }

        foreach ([
            'release-versions.json',
            'server/think',
            'server/module',
            'server/composer.json',
            'server/composer.lock',
            'server/vendor/composer/installed.json',
            'server/app/AppService.php',
            'server/app/service.php',
            'server/app/command/ModuleMake.php',
            'server/app/command/ModuleLock.php',
            'server/resources/schemas/module-artifact.schema.json',
        ] as $relative) {
            $this->copyFile($relative);
        }
        $this->copyTree($this->repositoryRoot . '/server/config', $server . '/config');
        // Native compilation requires the shipped protected owners and their selected graph.
        foreach (glob($this->repositoryRoot . '/server/app/modules/peanut/*/module.json') as $manifestPath) {
            $manifest = json_decode((string) file_get_contents($manifestPath), true, 64, JSON_THROW_ON_ERROR);
            $relative = substr(dirname($manifestPath), strlen($this->repositoryRoot) + 1);
            $this->copyTree(dirname($manifestPath), $this->fixtureRoot . '/' . $relative);
            foreach (\app\platform\value\module\ModuleFrontendLayout::contributions($manifest['frontend'] ?? [], $manifest['key']) as $frontend) {
                $this->copyTree($this->repositoryRoot . '/' . $frontend['root'], $this->fixtureRoot . '/' . $frontend['root']);
            }
        }
        $moduleRoot = $server . '/app/modules/acme/authoring';
        file_put_contents($moduleRoot . '/module.json', json_encode([
            'schema_version' => 1, 'key' => 'acme.authoring', 'name' => 'Authoring fixture',
            'description' => 'Native source authoring contract fixture', 'version' => '1.0.0',
            'kernel_constraint' => '^1.0', 'license' => 'MIT', 'dependencies' => [],
            'php_package' => 'acme/authoring',
            'backend' => ['provider' => 'Acme\\Modules\\Authoring\\ModuleProvider'],
            'frontend' => (object) [], 'database' => ['owned_tables' => []],
            'contracts' => ['exports' => [], 'events' => []],
            'tenant' => ['enableable' => true, 'disable_behavior' => 'reject_new_operations', 'requires' => []],
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n");
        file_put_contents($moduleRoot . '/composer.json', json_encode([
            'name' => 'acme/authoring', 'version' => '1.0.0', 'type' => 'library',
            'autoload' => ['psr-4' => ['Acme\\Modules\\Authoring\\' => 'src/']],
            'extra' => ['peanut' => ['editions' => ['multi-tenant', 'standalone']]],
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n");
        file_put_contents($moduleRoot . '/src/ModuleProvider.php', <<<'PHP'
<?php
namespace Acme\Modules\Authoring;
final class ModuleProvider implements \PeanutAdmin\Kernel\Module\ModuleProvider
{
    public function moduleKey(): string { return 'acme.authoring'; }
    public function bindings(): array { return []; }
}
PHP);
        (new \app\platform\infrastructure\module\ModuleArtifactWriter($server))->writeLock();

        $loader = var_export($this->repositoryRoot . '/server/vendor/autoload.php', true);
        file_put_contents(
            $server . '/vendor/autoload.php',
            "<?php\nrequire {$loader};\n\$loader = new Composer\\Autoload\\ClassLoader(__DIR__);\n\$loader->addPsr4('app\\\\', __DIR__ . '/../app/');\n\$loader->register(true);\nreturn \$loader;\n",
        );
        file_put_contents(
            $server . '/http-entry.php',
            "<?php\nrequire __DIR__ . '/vendor/autoload.php';\n(new think\\App(__DIR__))->http->run();\n",
        );
        chmod($server . '/think', 0700);

        $manifestPath = $moduleRoot . '/module.json';
        $manifest = json_decode((string) file_get_contents($manifestPath), false, 64, JSON_THROW_ON_ERROR);
        $manifest->name = 'Edited authoring fixture';
        file_put_contents($manifestPath, json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n");
    }

    private function environment(string $environment, bool $debug): string
    {
        $path = $this->fixtureRoot . '/server/.env.' . $environment . '-' . ($debug ? 'debug' : 'nodebug');
        file_put_contents(
            $path,
            'APP_ENV=' . $environment . "\n"
            . 'APP_DEBUG=' . ($debug ? 'true' : 'false') . "\n"
            . "DEPLOYMENT_MODE=multi-tenant\n"
            . "PEANUT_DATABASE_RESOURCE_ID=plugin-authoring-fixture\n"
            . "DB_HOST=127.0.0.1\nDB_PORT=1\nDB_NAME=plugin_authoring_fixture\n"
            . "DB_USER=fixture\nDB_PASS=fixture\nDB_PREFIX=pa_\n"
            . "PEANUT_MODULE_LOCK=../modules.lock\nPEANUT_MODULE_KERNEL_VERSION=1.0.0\n"
            . "PEANUT_MODULE_TRUSTED_KEYS_JSON={}\n",
        );
        chmod($path, 0600);
        return $path;
    }

    /** @param list<string> $arguments @return array{status:int,output:string} */
    private function runThink(array $arguments, string $environment): array
    {
        if (in_array($arguments[0], ['module:make', 'module:lock'], true)) {
            return $this->runPhp([$this->fixtureRoot . '/server/module'], $environment, json_encode([
                'operation' => substr($arguments[0], 7), 'arguments' => array_slice($arguments, 1),
            ], JSON_THROW_ON_ERROR));
        }
        return $this->runPhp([$this->fixtureRoot . '/server/think', ...$arguments], $environment);
    }

    /** @param list<string> $arguments @return array{status:int,output:string} */
    private function runPhp(array $arguments, string $environment, string $input = ''): array
    {
        $nativeEnvironment = ['PATH' => (string) getenv('PATH')];
        foreach (parse_ini_file($environment, false, INI_SCANNER_RAW) as $key => $value) {
            $nativeEnvironment['PHP_' . $key] = $value;
        }
        $process = proc_open(
            [PHP_BINARY, ...$arguments],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            $this->fixtureRoot . '/server',
            $nativeEnvironment,
        );
        self::assertIsResource($process);
        fwrite($pipes[0], $input);
        fclose($pipes[0]);
        $output = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        return ['status' => proc_close($process), 'output' => $output];
    }

    private function copyFile(string $relative): void
    {
        $source = $this->repositoryRoot . '/' . $relative;
        $target = $this->fixtureRoot . '/' . $relative;
        self::assertTrue(is_dir(dirname($target)) || mkdir(dirname($target), 0700, true));
        self::assertTrue(copy($source, $target), 'Unable to copy fixture file: ' . $relative);
    }

    private function copyTree(string $source, string $target): void
    {
        self::assertTrue(is_dir($source));
        if (!is_dir($target)) {
            self::assertTrue(mkdir($target, 0700, true));
        }
        $iterator = new \FilesystemIterator($source, \FilesystemIterator::SKIP_DOTS);
        foreach ($iterator as $item) {
            $destination = $target . '/' . $item->getFilename();
            if ($item->isDir()) {
                $this->copyTree($item->getPathname(), $destination);
            } else {
                self::assertTrue(copy($item->getPathname(), $destination));
            }
        }
    }

    private function removeTree(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($iterator as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($path);
    }
}
