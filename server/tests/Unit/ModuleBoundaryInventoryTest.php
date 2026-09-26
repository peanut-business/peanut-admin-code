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
