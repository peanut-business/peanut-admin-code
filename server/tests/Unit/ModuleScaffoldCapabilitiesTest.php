<?php

declare(strict_types=1);

namespace tests\Unit;

use app\common\exception\module\ModuleScaffoldException;
use app\common\infrastructure\module\ModuleScaffoldGenerator;
use PHPUnit\Framework\TestCase;

final class ModuleScaffoldCapabilitiesTest extends TestCase
{
    private string $root;
    private string $fixture;

    protected function setUp(): void
    {
        $this->root = dirname(__DIR__, 3);
        $temporary = realpath(sys_get_temp_dir());
        self::assertIsString($temporary);
        self::assertStringStartsWith($this->root . '/.local/tmp/', $temporary);
        $this->fixture = $temporary . '/module-capabilities-' . bin2hex(random_bytes(6));
        self::assertTrue(mkdir($this->fixture, 0700));
        foreach (['server/app/modules', 'server/tests'] as $directory) {
            self::assertTrue(mkdir($this->fixture . '/' . $directory, 0700, true));
        }
    }

    protected function tearDown(): void
    {
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->fixture, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($files as $entry) {
            $entry->isDir() && !$entry->isLink() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
        }
        rmdir($this->fixture);
    }

    private function generator(?string $composer = null): ModuleScaffoldGenerator
    {
        $composer ??= getenv('PEANUT_TEST_COMPOSER') ?: $this->root . '/scripts/project-composer';
        return new ModuleScaffoldGenerator($this->fixture, $this->root . '/server/resources/module-scaffold', $composer);
    }

    public function testDefaultIsBackendOnlyWithoutInventedBusinessContract(): void
    {
        $result = $this->generator()->create('acme.capability');
        self::assertNull($result['frontend_path']);
        self::assertNull($result['web_package']);
        self::assertDirectoryDoesNotExist($this->fixture . '/web');
        $backend = $this->fixture . '/' . $result['backend_path'];
        $manifest = json_decode((string) file_get_contents($backend . '/module.json'), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame([], $manifest['contracts']['exports']);
        self::assertArrayNotHasKey('web_package', $manifest);
        foreach (['Contract', 'Model', 'Service', 'Controller', 'Validation', 'Infrastructure'] as $layer) {
            self::assertDirectoryDoesNotExist($backend . '/src/' . $layer);
        }
        self::assertFileExists($this->fixture . '/' . $result['test_path'] . '/SECURITY.md');
        $file = $this->fixture . '/' . $result['test_path'] . '/ModuleStructureTest.php';
        $process = proc_open([PHP_BINARY, $file], [1 => ['pipe', 'w'], 2 => ['redirect', 1]], $pipes);
        self::assertIsResource($process);
        $output = stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        self::assertSame(0, proc_close($process), $output);
        self::assertStringContainsString('business/security qualification not executed', $output);
    }

    public function testExplicitAdminClientGeneratesOnlyItsDeclaredContribution(): void
    {
        self::assertTrue(mkdir($this->fixture . '/web/src/modules', 0700, true));
        $result = $this->generator()->create('acme.client-capability', 'acme', 'admin-web');
        self::assertSame('web/src/modules/acme-client-capability', $result['frontend_path']);
        $frontend = $this->fixture . '/' . $result['frontend_path'];
        self::assertFileExists($frontend . '/contribution.ts');
        self::assertFileExists($frontend . '/package.json');
        self::assertFileDoesNotExist($frontend . '/api.ts');
        self::assertDirectoryDoesNotExist($frontend . '/views');
    }

    public function testUnknownClientFailsBeforeWriting(): void
    {
        try {
            $this->generator()->create('acme.invalid', 'acme', 'all-platforms');
            self::fail('Unsupported client was accepted.');
        } catch (ModuleScaffoldException $error) {
            self::assertSame('MODULE_CREATE_CLIENT_INVALID', $error->errorCode);
        }
        self::assertSame(['.', '..'], scandir($this->fixture . '/server/app/modules'));
    }

    public function testComposerFailureRollsBackOnlyNewModule(): void
    {
        try {
            $this->generator('/usr/bin/false')->create('acme.failed');
            self::fail('Invalid generated Composer acceptance.');
        } catch (ModuleScaffoldException $error) {
            self::assertSame('MODULE_CREATE_COMPOSER_INVALID', $error->errorCode);
        }
        self::assertDirectoryDoesNotExist($this->fixture . '/server/app/modules/acme/failed');
        self::assertDirectoryExists($this->fixture . '/server/tests');
        self::assertDirectoryDoesNotExist($this->fixture . '/web');
    }
}
