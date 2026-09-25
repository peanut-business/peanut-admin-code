<?php

declare(strict_types=1);

namespace tests\Unit;

use PHPUnit\Framework\TestCase;

final class PluginAuthoringBootstrapTest extends TestCase
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
        self::assertStringContainsString('Canonical Plugin contents digest differs.', $ordinary['output']);

        $http = $this->runPhp([$this->fixtureRoot . '/server/http-entry.php'], $development);
        self::assertNotSame(0, $http['status']);
        self::assertStringContainsString('Canonical Plugin contents digest differs.', $http['output']);

        $productionAuthoring = $this->runThink([
            'plugin:make',
            'fixture.delivery-record',
            '1.0.0',
            '--module=fixture.delivery-record=server/app/modules/fixture/delivery_record',
        ], $production);
        self::assertNotSame(0, $productionAuthoring['status']);
        self::assertStringContainsString('Canonical Plugin contents digest differs.', $productionAuthoring['output']);

        $invalidPath = $this->runThink([
            'plugin:make',
            'fixture.delivery-record',
            '1.0.0',
            '--module=fixture.delivery-record=server/app/modules/fixture/not_the_key_path',
        ], $development);
        self::assertSame(1, $invalidPath['status']);
        self::assertStringContainsString('Module root is not key-derived', $invalidPath['output']);

        $moduleManifest = $this->fixtureRoot . '/server/app/modules/fixture/delivery_record/module.json';
        $validDefinition = file_get_contents($moduleManifest);
        self::assertIsString($validDefinition);
        file_put_contents($moduleManifest, "{\n");
        try {
            $invalidDefinition = $this->runThink([
                'plugin:make',
                'fixture.delivery-record',
                '1.0.0',
                '--module=fixture.delivery-record=server/app/modules/fixture/delivery_record',
            ], $development);
        } finally {
            file_put_contents($moduleManifest, $validDefinition);
        }
        self::assertSame(1, $invalidDefinition['status']);
        self::assertStringContainsString('JSON file is invalid:', $invalidDefinition['output']);

        $staleLock = $this->runThink(['plugin:lock', '--write'], $development);
        self::assertSame(1, $staleLock['status']);
        self::assertStringContainsString('Plugin manifest is not canonical', $staleLock['output']);

        $make = $this->runThink([
            'plugin:make',
            'fixture.delivery-record',
            '1.0.0',
            '--module=fixture.delivery-record=server/app/modules/fixture/delivery_record',
        ], $development);
        self::assertSame(0, $make['status'], $make['output']);

        $lock = $this->runThink(['plugin:lock', '--write'], $development);
        self::assertSame(0, $lock['status'], $lock['output']);
        self::assertSame('written', json_decode(trim($lock['output']), true, 32, JSON_THROW_ON_ERROR)['status'] ?? null);
    }

    private function prepareSourceFixture(): void
    {
        $server = $this->fixtureRoot . '/server';
        foreach (['app/command', 'bootstrap', 'config', 'resources/schemas', 'vendor', 'app/modules/fixture'] as $directory) {
            self::assertTrue(mkdir($server . '/' . $directory, 0700, true) || is_dir($server . '/' . $directory));
        }

        foreach ([
            'release-versions.json',
            'server/think',
            'server/bootstrap/environment.php',
            'server/app/AppService.php',
            'server/app/service.php',
            'server/app/command/PluginMake.php',
            'server/app/command/PluginLock.php',
            'server/resources/schemas/plugin.schema.json',
        ] as $relative) {
            $this->copyFile($relative);
        }
        $this->copyTree($this->repositoryRoot . '/server/config', $server . '/config');
        $this->copyTree(
            $this->repositoryRoot . '/server/app/modules/fixture/delivery_record',
            $server . '/app/modules/fixture/delivery_record',
        );
        $this->copyTree(
            $this->repositoryRoot . '/web/src/modules/fixture-delivery-record',
            $this->fixtureRoot . '/web/src/modules/fixture-delivery-record',
        );
        $this->copyTree(
            $this->repositoryRoot . '/plugins/fixture.delivery-record',
            $this->fixtureRoot . '/plugins/fixture.delivery-record',
        );

        $lock = json_decode(
            (string) file_get_contents($this->repositoryRoot . '/plugins.lock'),
            true,
            64,
            JSON_THROW_ON_ERROR,
        );
        $entry = null;
        foreach ((array) ($lock['plugins'] ?? []) as $candidate) {
            if (is_array($candidate) && ($candidate['key'] ?? null) === 'fixture.delivery-record') {
                $entry = $candidate;
                break;
            }
        }
        self::assertIsArray($entry);
        file_put_contents(
            $this->fixtureRoot . '/plugins.lock',
            json_encode(['schema_version' => 1, 'plugins' => [$entry]], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n",
        );

        $loader = var_export($this->repositoryRoot . '/server/vendor/autoload.php', true);
        file_put_contents(
            $server . '/vendor/autoload.php',
            "<?php\n\$loader = require {$loader};\n\$loader->addPsr4('app\\\\', __DIR__ . '/../app/', true);\nreturn \$loader;\n",
        );
        file_put_contents(
            $server . '/http-entry.php',
            "<?php\nrequire __DIR__ . '/bootstrap/environment.php';\nrequire __DIR__ . '/vendor/autoload.php';\n(new think\\App(__DIR__))->http->run();\n",
        );
        chmod($server . '/think', 0700);

        file_put_contents(
            $server . '/app/modules/fixture/delivery_record/src/ModuleProvider.php',
            "\n// Stale source fixture: the locked canonical digest intentionally predates this line.\n",
            FILE_APPEND,
        );
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
            . "PEANUT_PLUGIN_LOCK=../plugins.lock\nPEANUT_MODULE_KERNEL_VERSION=1.0.0\n"
            . "PEANUT_MODULE_TRUSTED_KEYS_JSON={}\n",
        );
        chmod($path, 0600);
        return $path;
    }

    /** @param list<string> $arguments @return array{status:int,output:string} */
    private function runThink(array $arguments, string $environment): array
    {
        return $this->runPhp([$this->fixtureRoot . '/server/think', ...$arguments], $environment);
    }

    /** @param list<string> $arguments @return array{status:int,output:string} */
    private function runPhp(array $arguments, string $environment): array
    {
        $process = proc_open(
            [PHP_BINARY, ...$arguments],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            $this->fixtureRoot . '/server',
            ['PATH' => (string) getenv('PATH'), 'PEANUT_SERVER_ENV_FILE' => $environment],
        );
        self::assertIsResource($process);
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
