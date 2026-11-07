<?php

declare(strict_types=1);

namespace PeanutAdmin\Kernel\Tests\Integration\Schema;

use PHPUnit\Framework\TestCase;
use think\migration\Migrator;

final class MigrationInventoryTest extends TestCase
{
    public function testIdentityOwnerMigrationsUseTheNativeMigrationContract(): void
    {
        $root = dirname(__DIR__, 6) . '/app/modules/peanut/identity/database/migrations';
        $files = glob($root . '/*.php');

        self::assertIsArray($files);
        self::assertContains($root . '/20261101010001_initial_identity_schema.php', $files);
        self::assertContains($root . '/20261106000000_tenant_plan_licenses.php', $files);
        $versions = [];
        foreach ($files as $file) {
            self::assertSame(1, preg_match('/^(\d{14})_([a-z_]+)\.php$/D', basename($file), $matches));
            self::assertNotContains($matches[1], $versions, 'Migration versions must be unique.');
            $versions[] = $matches[1];
            $class = str_replace(' ', '', ucwords(str_replace('_', ' ', $matches[2])));
            require_once $file;
            self::assertTrue(is_subclass_of($class, Migrator::class), "Invalid native migration {$class}");
        }
    }
}
