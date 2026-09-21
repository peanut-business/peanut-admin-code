<?php
declare(strict_types=1);

namespace tests\Unit;

use app\common\infrastructure\scaffold\ApplicationCreator;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/** 应用改名不能改动 Core 包名、归档路径或已锁定的依赖图。 */
final class GeneratedPackageIdentityTest extends TestCase
{
    public function testEveryClientKeepsItsRuntimeDependenciesWhileRenamingTheApplication(): void
    {
        foreach (['web', 'platform', 'pc', 'uniapp'] as $client) {
            $source = $this->read($client . '/package.json');
            $before = json_decode($source, true, 512, JSON_THROW_ON_ERROR);
            $after = json_decode($this->transform($source, $client . '/package.json'), true, 512, JSON_THROW_ON_ERROR);
            self::assertSame('acme-console-' . $client, $after['name']);
            self::assertSame('0.1.0', $after['version']);
            self::assertSame($before['dependencies'], $after['dependencies']);
            self::assertSame($before['devDependencies'], $after['devDependencies']);
        }
    }

    public function testEveryNpmLockChangesOnlyApplicationIdentityAndVersion(): void
    {
        foreach (['platform', 'pc', 'uniapp'] as $client) {
            $source = $this->read($client . '/package-lock.json');
            $before = json_decode($source, true, 512, JSON_THROW_ON_ERROR);
            $after = json_decode($this->transform($source, $client . '/package-lock.json'), true, 512, JSON_THROW_ON_ERROR);
            self::assertSame('acme-console-' . $client, $after['name']);
            self::assertSame('0.1.0', $after['version']);
            self::assertSame('0.1.0', $after['packages']['']['version']);
            self::assertSame($before['packages']['']['dependencies'], $after['packages']['']['dependencies']);
            unset($before['packages'][''], $after['packages']['']);
            self::assertSame($before['packages'], $after['packages']);
        }
    }

    public function testPnpmLockRetainsItsExactDependencyBytes(): void
    {
        $source = $this->read('web/pnpm-lock.yaml');
        self::assertSame($source, $this->transform($source, 'web/pnpm-lock.yaml'));
    }

    private function transform(string $source, string $path): string
    {
        $root = dirname(__DIR__, 3);
        $creator = new ApplicationCreator($root, $root . '/scaffold/application-template-inventory.json');
        return (new ReflectionMethod($creator, 'packageTransform'))->invoke($creator, $source, [
            'PACKAGE_IDENTITY' => 'acme/console', 'SLUG' => 'acme-console',
            'PRODUCT_NAME' => 'Acme Console', 'APPLICATION_VERSION' => '0.1.0',
        ], $path);
    }

    private function read(string $path): string
    {
        return (string)file_get_contents(dirname(__DIR__, 3) . '/' . $path);
    }
}
