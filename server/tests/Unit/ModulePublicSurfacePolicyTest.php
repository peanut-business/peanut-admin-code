<?php

declare(strict_types=1);

namespace tests\Unit;

use app\platform\validation\module\ModulePublicSurfacePolicy;
use PeanutAdmin\Kernel\Module\ModuleException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ModulePublicSurfacePolicyTest extends TestCase
{
    public static function rejectedExports(): array
    {
        return [
            ['Acme\\Catalog\\Model\\Product'],
            ['Acme\\Catalog\\Persistence\\Schema'],
            ['Acme\\Catalog\\Contract\\ProductRepository'],
            ['Acme\\Catalog\\Contract\\ProductStore'],
            ['Acme\\Catalog\\Contract\\ProductQueryBuilder'],
            ['Acme\\Catalog\\model\\Product'],
        ];
    }

    #[DataProvider('rejectedExports')]
    public function testPersistenceDeclarationIsRejected(string $symbol): void
    {
        $this->expectException(ModuleException::class);
        $this->expectExceptionMessage('cannot expose persistence');
        ModulePublicSurfacePolicy::assertValid((object) ['contracts' => (object) ['exports' => [$symbol]]]);
    }

    public function testExplicitOperationsAndValuesAreAccepted(): void
    {
        ModulePublicSurfacePolicy::assertValid((object) ['contracts' => (object) ['exports' => [
            'Acme\\Catalog\\Contract\\ProductQueries',
            'Acme\\Catalog\\Contract\\ProductCommands',
            'Acme\\Catalog\\Value\\ProductSummary',
        ]]]);
        self::assertFalse(ModulePublicSurfacePolicy::isInternalPersistence('Acme\\Catalog\\Service\\ProductService'));
    }

    public function testCaseAliasCannotDuplicateAnExport(): void
    {
        $this->expectException(ModuleException::class);
        $this->expectExceptionMessage('duplicate or case-alias');
        ModulePublicSurfacePolicy::assertValid((object) ['contracts' => (object) ['exports' => [
            'Acme\\Catalog\\ProductQueries', 'Acme\\Catalog\\productQueries',
        ]]]);
    }

    public function testAllCurrentModuleManifestsUseThePolicy(): void
    {
        $root = dirname(__DIR__, 3);
        $manifests = glob($root . '/server/app/modules/*/*/module.json');
        self::assertNotEmpty($manifests);
        foreach ($manifests as $path) {
            ModulePublicSurfacePolicy::assertValid(json_decode((string) file_get_contents($path), false, 512, JSON_THROW_ON_ERROR));
            self::assertFileExists($path);
        }
    }
}
