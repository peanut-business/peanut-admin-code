<?php

declare(strict_types=1);

namespace tests\Unit;

use app\platform\composition\plugin\ModuleDefinitionRegistryFactory;
use PHPUnit\Framework\TestCase;

/** 编译本候选的真实模块定义和源码边界；不连接数据库，不替代包摘要检查。 */
final class DeployedModuleRegistryTest extends TestCase
{
    public function testAllShippedModulesCompileAgainstTheirPublicContracts(): void
    {
        $server = dirname(__DIR__, 2);
        $paths = glob($server . '/app/modules/*/*/module.json');
        self::assertNotEmpty($paths);
        $expected = array_map(static fn(string $path): string =>
            json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR)['key'], $paths);
        $registry = (new ModuleDefinitionRegistryFactory($server))->fromDeploymentConfig([
            'roots' => array_map('dirname', $paths),
            'kernel_version' => '1.0.0',
            'registered_client_keys' => ['admin-web', 'platform-web', 'pc-web', 'uniapp'],
        ]);
        $actual = $registry->moduleKeys();
        sort($expected, SORT_STRING);
        sort($actual, SORT_STRING);
        self::assertSame($expected, $actual);
    }
}
