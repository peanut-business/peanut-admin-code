<?php

declare(strict_types=1);

use PeanutAdmin\Kernel\Module\ManifestDocument;
use PeanutAdmin\Kernel\Module\ModuleException;
use PeanutAdmin\Modules\ReferenceCodes\Service\ReferenceCodeCatalogService;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;
use think\App;
use think\facade\Db;

/** Real owner service/loader/store with SQLite fixtures; no install, lifecycle purge or production resource. */
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class ReferenceCodeCatalogBoundaryTest extends TestCase
{
    private PDO $database;
    private ReferenceCodeCatalogService $catalog;
    private string $directory;

    protected function setUp(): void
    {
        $root = dirname(__DIR__, 3);
        require_once $root . '/server/vendor/topthink/framework/src/helper.php';
        $this->directory = $root . '/.local/tmp/reference-code-catalog/' . bin2hex(random_bytes(6));
        mkdir($this->directory, 0700, true);
        new App($this->directory);
        $this->database = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        ThinkPhpTestConnection::fromPdo($this->database);
        $this->catalog = new ReferenceCodeCatalogService();
    }

    public function testHostCallsPublishedOwnerWithoutLoadingItsPersistenceTypes(): void
    {
        $root = dirname(__DIR__, 3);
        $manifest = json_decode(file_get_contents($root . '/server/app/modules/official/reference_codes/module.json'), true, 512, JSON_THROW_ON_ERROR);
        self::assertContains(ReferenceCodeCatalogService::class, $manifest['contracts']['exports']);
        $host = file_get_contents($root . '/server/app/platform/infrastructure/plugin/ModuleCatalogApplier.php');
        foreach (['ReferenceCodeSetLoader', 'ReferenceCodeSetRegistry', 'ReferenceCodeStore', "Db::name('reference_code_set')", "Db::table('pa_reference_code_set')", 'SHOW TABLES LIKE'] as $internal) {
            self::assertStringNotContainsString($internal, $host);
        }
        self::assertStringContainsString('ReferenceCodeCatalogService', $host);
        self::assertStringContainsString('$this->referenceCodes->synchronize($selected, $now)', $host);
    }

    public function testNoStorageAndNoContributionsRemainAnEmptyOptionalCapability(): void
    {
        $this->catalog->synchronize([], $this->now());
        $manifest = ManifestDocument::fromArray($this->directory, ['key' => 'fixture.alpha']);
        $this->catalog->synchronize(['fixture.alpha' => $manifest], $this->now());
        self::assertSame([], $this->catalog->revisionRows());
        self::assertSame(0, $this->catalog->activeCount(['fixture.alpha']));
        self::assertSame(0, (int) $this->database->query("SELECT COUNT(*) FROM sqlite_master WHERE type='table'")->fetchColumn());
    }

    public function testDeclaredContributionsCannotSilentlySucceedWithoutStorage(): void
    {
        try {
            $this->catalog->synchronize(['fixture.alpha' => $this->manifest('fixture.alpha', 'Initial')], $this->now());
            self::fail('A declared catalog was accepted without its storage.');
        } catch (ModuleException $exception) {
            self::assertSame('MODULE_REFERENCE_CODE_STORAGE_REQUIRED', $exception->errorCode);
        }
        self::assertSame([], $this->catalog->revisionRows());
    }

    public function testSelectionRetirementAndReactivationPreserveUnselectedModules(): void
    {
        $this->installFixtureTable();
        $alpha = $this->manifest('fixture.alpha', 'Alpha');
        $beta = $this->manifest('fixture.beta', 'Beta');
        $this->catalog->synchronize(['fixture.alpha' => $alpha, 'fixture.beta' => $beta], $this->now());
        self::assertSame(2, $this->catalog->activeCount(['fixture.alpha', 'fixture.beta']));
        $before = $this->catalog->revisionRows();
        self::assertCount(2, $before);
        self::assertSame(['id', 'module_key', 'set_key', 'lifecycle', 'revision', 'definition_digest'], array_keys($before[0]));
        $emptyAlpha = ManifestDocument::fromArray($alpha->root, ['key' => 'fixture.alpha']);
        $this->catalog->synchronize(['fixture.alpha' => $emptyAlpha], $this->now());
        self::assertSame(0, $this->catalog->activeCount(['fixture.alpha']));
        self::assertSame(1, $this->catalog->activeCount(['fixture.beta']));
        $retired = $this->catalog->revisionRows();
        self::assertSame('retired', $retired[0]['lifecycle']);
        self::assertSame(2, (int) $retired[0]['revision']);
        self::assertSame($before[1], $retired[1]);
        $this->catalog->synchronize(['fixture.alpha' => $alpha], $this->now());
        $reactivated = $this->catalog->revisionRows();
        self::assertSame('active', $reactivated[0]['lifecycle']);
        self::assertSame(3, (int) $reactivated[0]['revision']);
        self::assertSame($before[1], $reactivated[1]);
    }

    public function testRepeatedSameIdentityDoesNotChangeRevisionOrRows(): void
    {
        $this->installFixtureTable();
        $manifest = $this->manifest('fixture.alpha', 'Alpha');
        $this->catalog->synchronize(['fixture.alpha' => $manifest], $this->now());
        $before = $this->database->query('SELECT * FROM pa_reference_code_set ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
        $this->catalog->synchronize(['fixture.alpha' => $manifest], $this->now()->modify('+1 hour'));
        self::assertSame($before, $this->database->query('SELECT * FROM pa_reference_code_set ORDER BY id')->fetchAll(PDO::FETCH_ASSOC));
        self::assertSame(0, $this->catalog->activeCount([]));
    }

    public function testOwnerSynchronizationParticipatesInTheEnclosingTransaction(): void
    {
        $this->installFixtureTable();
        $manifest = $this->manifest('fixture.alpha', 'Alpha');
        try {
            Db::transaction(function () use ($manifest): void {
                $this->catalog->synchronize(['fixture.alpha' => $manifest], $this->now());
                self::assertSame(1, $this->catalog->activeCount(['fixture.alpha']));
                throw new DomainException('FIXTURE_OUTER_FAILURE');
            });
            self::fail('The enclosing transaction did not fail.');
        } catch (DomainException $exception) {
            self::assertSame('FIXTURE_OUTER_FAILURE', $exception->getMessage());
        }
        self::assertSame([], $this->catalog->revisionRows());
    }

    public function testResourceEscapeAndOwnerMismatchFailBeforeWrites(): void
    {
        $this->installFixtureTable();
        $wrongOwner = ManifestDocument::fromArray($this->directory, ['key' => 'fixture.beta']);
        $escape = ManifestDocument::fromArray($this->directory, ['key' => 'fixture.alpha', 'backend' => ['reference_code_sets' => '../outside.json']]);
        foreach ([$wrongOwner, $escape] as $manifest) {
            try {
                $this->catalog->synchronize(['fixture.alpha' => $manifest], $this->now());
                self::fail('Invalid selected contribution was accepted.');
            } catch (ModuleException $exception) {
                self::assertContains($exception->errorCode, ['MODULE_REFERENCE_CODE_OWNER_MISMATCH', 'MODULE_REFERENCE_CODE_RESOURCE_INVALID']);
            }
        }
        self::assertSame([], $this->catalog->revisionRows());
    }

    private function installFixtureTable(): void
    {
        $this->database->exec('CREATE TABLE pa_reference_code_set (id INTEGER PRIMARY KEY AUTOINCREMENT, module_key TEXT NOT NULL, set_key TEXT NOT NULL, name TEXT NOT NULL, description TEXT NOT NULL, definition_digest TEXT NOT NULL, lifecycle TEXT NOT NULL, revision INTEGER NOT NULL, created_at TEXT NOT NULL, updated_at TEXT NOT NULL, UNIQUE(module_key,set_key))');
    }

    private function manifest(string $key, string $name): ManifestDocument
    {
        $directory = $this->directory . '/' . $key;
        mkdir($directory, 0700, true);
        file_put_contents($directory . '/sets.json', json_encode([['key' => 'status', 'name' => $name, 'description' => 'Fixture set']], JSON_THROW_ON_ERROR));
        return ManifestDocument::fromArray($directory, ['key' => $key, 'backend' => ['reference_code_sets' => 'sets.json']]);
    }

    private function now(): DateTimeImmutable
    {
        return new DateTimeImmutable('2031-01-01T00:00:00.000Z');
    }
}
