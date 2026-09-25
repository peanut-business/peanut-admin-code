<?php

declare(strict_types=1);

namespace tests\Unit\ModuleNamespaceHostRegistration;

use app\common\infrastructure\module\ModuleHostLayoutFactory;
use app\common\value\module\ModulePhpNamespace;
use Composer\Autoload\ClassLoader;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

/** 使用真实 Composer loader，验证登记的原子性、第三方前缀和路径边界；不连接数据库。 */
final class ModuleNamespaceHostRegistrationTest extends TestCase
{
    private string $root;
    private ClassLoader $loader;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/peanut-namespace-host-' . bin2hex(random_bytes(8));
        mkdir($this->root . '/vendor', 0700, true);
        $this->loader = new ClassLoader($this->root . '/vendor');
        $this->loader->register();
    }

    protected function tearDown(): void
    {
        $this->loader->unregister();
        $this->removeOwnTree($this->root);
    }

    public function testVettedThirdPartyPrefixLoadsFromItsOwnModule(): void
    {
        $prefix = 'ExampleVendor\\Billing' . bin2hex(random_bytes(4)) . '\\';
        $module = $this->module('billing', $prefix);
        file_put_contents($module . '/src/Probe.php', '<?php namespace ' . rtrim($prefix, '\\') . '; final class Probe {}');
        $layout = ModuleHostLayoutFactory::registerRuntimeAutoload(['example.billing' => $module], $this->root);
        self::assertTrue($layout->hasExplicitBackendNamespace(\PeanutAdmin\Kernel\Module\ModuleKey::fromString('example.billing')));
        self::assertTrue(class_exists($prefix . 'Probe'));
        self::assertSame(realpath($module . '/src/Probe.php'), (new \ReflectionClass($prefix . 'Probe'))->getFileName());
    }

    public function testLaterConflictDoesNotPartiallyRegisterEarlierModule(): void
    {
        $first = $this->module('first', 'ExampleVendor\\First\\');
        $second = $this->module('second', 'ExampleVendor\\Second\\');
        mkdir($this->root . '/dependency', 0700);
        $this->loader->setPsr4('ExampleVendor\\Second\\', [$this->root . '/dependency']);
        $before = $this->loader->getPrefixesPsr4();
        try {
            ModuleHostLayoutFactory::registerRuntimeAutoload(['example.first' => $first, 'example.second' => $second], $this->root);
            self::fail('Conflicting namespace was accepted.');
        } catch (InvalidArgumentException $exception) {
            self::assertStringContainsString('conflicts', $exception->getMessage());
        }
        self::assertSame($before, $this->loader->getPrefixesPsr4());
    }

    public function testCaseOnlyAliasCannotReplaceRegisteredPrefix(): void
    {
        $module = $this->module('case', 'ExampleVendor\\CaseProbe\\');
        $this->loader->setPsr4('examplevendor\\caseprobe\\', [$module . '/src']);
        $before = $this->loader->getPrefixesPsr4();
        try {
            ModuleHostLayoutFactory::registerRuntimeAutoload(['example.case' => $module], $this->root);
            self::fail('Case-only prefix alias was accepted.');
        } catch (InvalidArgumentException) {
            self::assertSame($before, $this->loader->getPrefixesPsr4());
        }
    }

    public function testReservedAndOverlappingNamespacesFailBeforeRegistration(): void
    {
        foreach (['app\\Injected\\', 'PeanutAdmin\\Kernel\\Injected\\', 'PeanutAdmin\\Modules\\'] as $prefix) {
            try {
                ModulePhpNamespace::validated($prefix);
                self::fail('Reserved prefix was accepted: ' . $prefix);
            } catch (InvalidArgumentException) {
                self::assertTrue(true);
            }
        }
        $first = $this->module('parent', 'ExampleVendor\\Parent\\');
        $child = $this->module('child', 'ExampleVendor\\Parent\\Child\\');
        try {
            ModuleHostLayoutFactory::registerRuntimeAutoload(['example.parent' => $first, 'example.child' => $child], $this->root);
            self::fail('Overlapping module prefixes were accepted.');
        } catch (InvalidArgumentException) {
            self::assertSame([], $this->loader->getPrefixesPsr4());
        }
    }

    public function testSourceSymlinkCannotEscapeItsVerifiedModuleRoot(): void
    {
        $module = $this->module('escape', 'ExampleVendor\\Escape\\');
        rmdir($module . '/src');
        mkdir($this->root . '/outside', 0700);
        symlink($this->root . '/outside', $module . '/src');
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('source root is unavailable');
        ModuleHostLayoutFactory::registerRuntimeAutoload(['example.escape' => $module], $this->root);
    }

    private function module(string $directory, string $prefix): string
    {
        $root = $this->root . '/modules/' . $directory;
        mkdir($root . '/src', 0700, true);
        file_put_contents($root . '/composer.json', json_encode(['autoload' => ['psr-4' => [$prefix => 'src/']]], JSON_THROW_ON_ERROR));
        return $root;
    }

    private function removeOwnTree(string $path): void
    {
        if (is_link($path) || is_file($path)) {
            unlink($path);
            return;
        }
        foreach (array_diff(scandir($path) ?: [], ['.', '..']) as $entry) {
            $this->removeOwnTree($path . '/' . $entry);
        }
        rmdir($path);
    }
}
