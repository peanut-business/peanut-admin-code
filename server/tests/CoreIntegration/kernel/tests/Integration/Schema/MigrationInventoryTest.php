<?php

declare(strict_types=1);

namespace PeanutAdmin\Kernel\Tests\Integration\Schema;

use PHPUnit\Framework\TestCase;

final class MigrationInventoryTest extends TestCase
{
    public function testKernelSchemaHasTheFrozenMigrationCount(): void
    {
        $kernel = dirname((new \ReflectionClass(\PeanutAdmin\Kernel\Migration\ModuleSchema::class))->getFileName(), 3);
        $files = [
            ...(glob($kernel . '/database/migrations/*.php') ?: []),
            ...(glob(dirname(__DIR__, 6) . '/database/kernel-migrations/*.php') ?: []),
        ];

        self::assertIsArray($files);
        self::assertCount(40, $files);
    }
}
