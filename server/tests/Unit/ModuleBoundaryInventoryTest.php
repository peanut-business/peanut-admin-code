<?php

declare(strict_types=1);

namespace tests\Unit;

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 3) . '/scripts/check-module-boundaries.php';

/** Synthetic source trees only; declarations are parsed, never executed. */
final class ModuleBoundaryInventoryTest extends TestCase
{
    private string $root;
    private array $ownedFiles = [];
    private array $ownedDirectories = [];

    protected function setUp(): void
    {
        $this->root = dirname(__DIR__, 3) . '/.local/tmp/module-boundary-policy/' . bin2hex(random_bytes(8));
        foreach (['alpha', 'beta'] as $key) {
            $directory = $this->root . '/server/app/modules/fixture/' . $key;
            $this->write($directory . '/src/Placeholder.php', '<?php namespace Fixture\\' . ucfirst($key) . '; final class Placeholder {}');
            $this->write($directory . '/composer.json', json_encode(['autoload' => ['psr-4' => ['Fixture\\' . ucfirst($key) . '\\' => 'src/']]], JSON_THROW_ON_ERROR));
            $this->write($directory . '/module.json', json_encode([
                'key' => 'fixture.' . $key,
                'database' => ['owned_tables' => ['pa_' . $key]],
                'contracts' => ['exports' => ['Fixture\\' . ucfirst($key) . '\\PublicQueries']],
            ], JSON_THROW_ON_ERROR));
        }
    }

    private function write(string $path, string $content): void
    {
        $missing = [];
        for ($directory = dirname($path); !is_dir($directory); $directory = dirname($directory)) {
            $missing[] = $directory;
        }
        foreach (array_reverse($missing) as $directory) {
            mkdir($directory);
            $this->ownedDirectories[] = $directory;
        }
        file_put_contents($path, $content);
        $this->ownedFiles[$path] = true;
    }

    private function source(string $code): array
    {
        $this->write($this->root . '/server/app/modules/fixture/alpha/src/Consumer.php', '<?php namespace Fixture\\Alpha; ' . $code);
        return \moduleBoundaryInventory($this->root);
    }

    public function testPublicCapabilityIsAcceptedWithoutExecutingIt(): void
    {
        $report = $this->source('use Fixture\\Beta\\PublicQueries as Queries; new Queries();');
        self::assertSame('passed', $report['status']);
        self::assertSame(1, $report['cross_module_type_references']);
        self::assertSame([], $report['findings']);
    }

    public function testPrivateTypeAliasIsRejected(): void
    {
        $report = $this->source('use Fixture\\Beta\\PrivateRecord as InnocentName; new InnocentName();');
        self::assertSame('failed', $report['status']);
        self::assertSame('PRIVATE_MODULE_TYPE', $report['findings'][0]['code']);
        self::assertSame('Fixture\\Beta\\PrivateRecord', $report['findings'][0]['target']);
    }

    public function testOwnImplementationAndOwnTableRemainLegal(): void
    {
        $report = $this->source('new \\Fixture\\Alpha\\PrivateRecord(); \\think\\facade\\Db::name("alpha")->select(); $query->leftJoin("pa_alpha a", "a.tenant_id = b.tenant_id");');
        self::assertSame('passed', $report['status']);
        self::assertSame(2, $report['literal_table_calls']);
    }

    public function testTenantPredicateDoesNotMakeForeignPrivateTablePublic(): void
    {
        $report = $this->source('use think\\facade\\Db; Db::name("beta")->where("tenant_id", 1); $query->join("pa_beta b", "b.tenant_id = a.tenant_id AND b.id = a.id");');
        self::assertSame('failed', $report['status']);
        self::assertCount(2, $report['findings']);
        foreach ($report['findings'] as $finding) {
            self::assertSame('FOREIGN_MODULE_TABLE', $finding['code']);
            self::assertSame('fixture.beta', $finding['target_owner']);
        }
    }

    public function testStringsAndCommentsAreNotPretendedToBeExecutedTypeReferences(): void
    {
        $marker = $this->root . '/must-not-exist';
        $report = $this->source('/* \\Fixture\\Beta\\PrivateRecord */ $text="Fixture\\\\Beta\\\\PrivateRecord"; file_put_contents(' . var_export($marker, true) . ', "executed");');
        self::assertSame('passed', $report['status']);
        self::assertFileDoesNotExist($marker);
        self::assertStringContainsString('not computed class names', $report['scope']);
    }

    public function testDuplicateTableOwnershipFailsBeforeSourceAnalysis(): void
    {
        $path = $this->root . '/server/app/modules/fixture/beta/module.json';
        $manifest = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        $manifest['database']['owned_tables'] = ['pa_alpha'];
        $this->write($path, json_encode($manifest, JSON_THROW_ON_ERROR));
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('MODULE_BOUNDARY_DUPLICATE_TABLE');
        \moduleBoundaryInventory($this->root);
    }

    public function testHostPrivateTypeAndTableRemainFindings(): void
    {
        $this->write($this->root . '/server/composer.json', '{"autoload":{"psr-4":{"app\\\\":"app/"}}}');
        $this->write($this->root . '/server/app/api/Consumer.php', '<?php namespace app\\api; use Fixture\\Beta\\PrivateRecord; new PrivateRecord(); \\think\\facade\\Db::name("beta")->select();');
        $report = \moduleBoundaryInventory($this->root, true);
        self::assertSame(1, $report['host_php_files']);
        self::assertContains('PRIVATE_MODULE_TYPE', array_column($report['findings'], 'code'));
        self::assertContains('FOREIGN_MODULE_TABLE', array_column($report['findings'], 'code'));
    }

    public function testHostPublicCapabilityDoesNotDoubleScanModuleSourcesOrMigrations(): void
    {
        $this->write($this->root . '/server/composer.json', '{"autoload":{"psr-4":{"app\\\\":"app/"}}}');
        $this->write($this->root . '/server/app/api/Consumer.php', '<?php namespace app\\api; use Fixture\\Beta\\PublicQueries; new PublicQueries();');
        $this->write($this->root . '/server/app/modules/fixture/beta/database/migrations/fixture.php', '<?php throw new RuntimeException("not production source"); \\think\\facade\\Db::name("alpha");');
        $report = \moduleBoundaryInventory($this->root, true);
        self::assertSame('passed', $report['status']);
        self::assertSame(2, $report['modules']);
        self::assertSame(3, $report['php_files']);
        self::assertSame(1, $report['host_php_files']);
        self::assertSame(0, $report['composition_type_references']);
    }

    public function testCompositionRootMayWirePrivateTypesButNotReadPrivateTables(): void
    {
        $this->write($this->root . '/server/composer.json', '{"autoload":{"psr-4":{"app\\\\":"app/"}}}');
        $this->write($this->root . '/server/app/AppService.php', '<?php namespace app; new \\Fixture\\Beta\\PrivateRecord(); \\think\\facade\\Db::name("beta")->select();');
        $report = \moduleBoundaryInventory($this->root, true);
        self::assertSame(1, $report['composition_type_references']);
        self::assertCount(1, $report['findings']);
        self::assertSame('FOREIGN_MODULE_TABLE', $report['findings'][0]['code']);
    }

    public function testMissingHostMappingCannotProduceAnEmptyPassingCheck(): void
    {
        $this->write($this->root . '/server/composer.json', '{"autoload":{"psr-4":{}}}');
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('MODULE_BOUNDARY_HOST_MAPPING_REQUIRED');
        \moduleBoundaryInventory($this->root, true);
    }

    public function testHostMappingCannotEscapeApplicationRoot(): void
    {
        $this->write($this->root . '/server/composer.json', '{"autoload":{"psr-4":{"app\\\\":"../"}}}');
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('MODULE_BOUNDARY_HOST_SOURCE_INVALID');
        \moduleBoundaryInventory($this->root, true);
    }

    private function readAssociation(string $condition = 'b.tenant_id = a.tenant_id AND b.id = a.member_id', string $fields = 'a.id,b.name', string $consumer = 'return self::query()->select()->toArray();'): array
    {
        $this->write($this->root . '/server/app/modules/fixture/alpha/src/Model/Item.php', '<?php namespace Fixture\\Alpha\\Model; class Item extends \\app\\common\\model\\TenantOwnedModel {}');
        return $this->source('final class Reader { public function list(): array {' . $consumer . '}
            private static function query(): \\think\\db\\Query {
                $query = \\Fixture\\Alpha\\Model\\Item::alias("a")->join("beta b", ' . var_export($condition, true) . ')->field(' . var_export($fields, true) . ');
                return $query;
            }}');
    }

    public function testApprovedOwnScopedReadJoinIsNotAForbiddenPrivateRead(): void
    {
        $report = $this->readAssociation();
        self::assertSame('passed', $report['status']);
        self::assertCount(1, $report['read_only_associations']);
        self::assertSame('fixture.beta', $report['read_only_associations'][0]['target_owner']);
    }

    public function testReadApprovalDoesNotAdmitWrongScopeSensitiveFieldsOrWrites(): void
    {
        foreach ([
            ['b.id = a.member_id', 'a.id,b.name', 'return self::query()->select()->toArray();'],
            ['b.tenant_id = a.tenant_id OR b.id = a.member_id', 'a.id,b.name', 'return self::query()->select()->toArray();'],
            ['b.tenant_id = a.tenant_id AND b.id = a.member_id', 'a.id,b.*', 'return self::query()->select()->toArray();'],
            ['b.tenant_id = a.tenant_id AND b.id = a.member_id', 'a.id,b.password AS name', 'return self::query()->select()->toArray();'],
            ['b.tenant_id = a.tenant_id AND b.id = a.member_id', 'a.id,b.name', 'self::query()->update(["b.name"=>"changed"]); return [];'],
            ['b.tenant_id = a.tenant_id AND b.id = a.member_id', 'a.id,b.name', '$q=self::query(); $q->delete(); return [];'],
            ['b.tenant_id = a.tenant_id AND b.id = a.member_id', 'a.id,b.name', '$q=self::query(); expose($q); return [];'],
        ] as [$condition, $fields, $consumer]) {
            self::assertSame('failed', $this->readAssociation($condition, $fields, $consumer)['status']);
        }
    }

    public function testIdentityDeclaresItsNativeCatalogTablesWithoutExportingPersistence(): void
    {
        $manifest = json_decode(file_get_contents(dirname(__DIR__, 2) . '/app/modules/official/identity/module.json'), true, 512, JSON_THROW_ON_ERROR);
        foreach ([
            \PeanutAdmin\Modules\Identity\Module\Model\ModuleInstallation::class => 'pa_module_installation',
            \PeanutAdmin\Modules\Identity\Persistence\Model\MenuDefinition::class => 'pa_menu_definition',
        ] as $model => $table) {
            $properties = (new \ReflectionClass($model))->getDefaultProperties();
            self::assertSame($table, 'pa_' . $properties['name']);
            self::assertSame(1, count(array_keys($manifest['database']['owned_tables'], $table, true)), $table . ' must have one explicit owner declaration.');
            self::assertNotContains($model, $manifest['contracts']['exports']);
        }
    }

    protected function tearDown(): void
    {
        foreach (array_keys($this->ownedFiles) as $file) {
            unlink($file);
        }
        foreach (array_reverse($this->ownedDirectories) as $directory) {
            rmdir($directory);
        }
    }
}
