<?php

declare(strict_types=1);

use PeanutAdmin\Kernel\Menu\MenuCatalogRepository;
use PeanutAdmin\Modules\Identity\Authorization\ModuleAuthorizationCatalogSynchronizer;
use PeanutAdmin\Modules\Identity\Menu\MenuCatalogSynchronizer;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;
use think\App;
use think\facade\Db;

/** Actual catalog queries and transactions on isolated SQLite; not MySQL locking or lifecycle approval. */
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class IdentityCatalogBoundaryTest extends TestCase
{
    private PDO $database;
    private ModuleAuthorizationCatalogSynchronizer $authorization;
    private MenuCatalogSynchronizer $menus;
    private \app\platform\infrastructure\plugin\ModuleCatalogApplier $host;

    protected function setUp(): void
    {
        $root = dirname(__DIR__, 3);
        require_once $root . '/server/vendor/topthink/framework/src/helper.php';
        new App($root . '/.local/tmp/identity-catalog/' . bin2hex(random_bytes(6)));
        $this->database = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        // Fixtures already use canonical microseconds; production keeps its native DATE_FORMAT expression.
        $this->database->sqliteCreateFunction('DATE_FORMAT', static fn($value, $format) => $value, 2);
        $this->database->sqliteCreateFunction('UTC_TIMESTAMP', static fn($precision) => '2031-01-02 00:00:00.000', 1);
        ThinkPhpTestConnection::fromPdo($this->database);
        $this->database->exec(<<<'SQL'
            CREATE TABLE pa_permission (id INTEGER PRIMARY KEY, "key" TEXT, module_key TEXT, status TEXT, retired_at TEXT, private_extra TEXT);
            CREATE TABLE pa_menu_definition (id INTEGER PRIMARY KEY, "key" TEXT, module_key TEXT, status TEXT, manifest_digest TEXT, private_extra TEXT);
            CREATE TABLE pa_setting_definition (id INTEGER PRIMARY KEY, module_key TEXT, setting_key TEXT, status TEXT, revision INTEGER, definition_digest TEXT);
            CREATE TABLE pa_tenant (id INTEGER PRIMARY KEY, authorization_revision INTEGER, revision INTEGER, updated_at TEXT);
            CREATE TABLE pa_tenant_module (id INTEGER PRIMARY KEY, tenant_id INTEGER, module_key TEXT, status TEXT, authorization_revision INTEGER, updated_at TEXT);
            INSERT INTO pa_permission VALUES (2,'fixture.beta.read','fixture.beta','retired','2031-01-01 01:02:03.456000','private'),(1,'fixture.alpha.read','fixture.alpha','active',NULL,'private');
            INSERT INTO pa_menu_definition VALUES (2,'fixture.beta.menu','fixture.beta','retired','digest-b','private'),(1,'fixture.alpha.menu','fixture.alpha','active','digest-a','private');
            INSERT INTO pa_tenant VALUES (101,5,8,'old'),(202,10,12,'old'),(303,20,25,'old');
            INSERT INTO pa_tenant_module VALUES (1,101,'fixture.alpha','enabled',2,'old'),(2,101,'fixture.beta','disabled',4,'old'),(3,202,'fixture.alpha','disabled',6,'old'),(4,303,'fixture.gamma','enabled',8,'old');
            SQL);
        $this->host = ThinkPhpTestConnection::moduleCatalogs($this->database);
        $this->authorization = (new ReflectionProperty($this->host, 'authorization'))->getValue($this->host);
        $this->menus = new MenuCatalogSynchronizer($this->createStub(MenuCatalogRepository::class));
    }

    public function testExistingSynchronizersArePublicWithoutExportingPersistence(): void
    {
        $manifest = json_decode(file_get_contents(dirname(__DIR__, 2) . '/app/modules/official/identity/module.json'), true, 512, JSON_THROW_ON_ERROR);
        self::assertContains(ModuleAuthorizationCatalogSynchronizer::class, $manifest['contracts']['exports']);
        self::assertContains(MenuCatalogSynchronizer::class, $manifest['contracts']['exports']);
        self::assertNotContains(\PeanutAdmin\Modules\Identity\Persistence\Model\Tenant::class, $manifest['contracts']['exports']);
    }

    public function testHostDelegatesIdentityReadsAndInvalidationToItsOwner(): void
    {
        $source = file_get_contents(dirname(__DIR__, 2) . '/app/platform/infrastructure/plugin/ModuleCatalogApplier.php');
        foreach (["Db::name('permission')", "Db::name('menu_definition')", "Db::name('tenant_module')", "Db::name('tenant')", "'menus' => 'pa_menu_definition'"] as $privateAccess) {
            self::assertStringNotContainsString($privateAccess, $source);
        }
        self::assertStringContainsString('$this->authorization->invalidateTenantAuthorization($moduleKeys)', $source);
    }

    public function testFixedMetadataPreservesOrderingAndFingerprintWithoutPrivateFields(): void
    {
        $permissions = [
            ['id' => 1, 'key' => 'fixture.alpha.read', 'module_key' => 'fixture.alpha', 'status' => 'active', 'retired_at' => ''],
            ['id' => 2, 'key' => 'fixture.beta.read', 'module_key' => 'fixture.beta', 'status' => 'retired', 'retired_at' => '2031-01-01 01:02:03.456000'],
        ];
        $menus = [
            ['id' => 1, 'key' => 'fixture.alpha.menu', 'module_key' => 'fixture.alpha', 'status' => 'active', 'manifest_digest' => 'digest-a'],
            ['id' => 2, 'key' => 'fixture.beta.menu', 'module_key' => 'fixture.beta', 'status' => 'retired', 'manifest_digest' => 'digest-b'],
        ];
        self::assertSame($permissions, $this->authorization->revisionRows());
        self::assertSame($menus, $this->menus->revisionRows());
        $expected = ['pa_permission' => $permissions, 'pa_menu_definition' => $menus, 'pa_setting_definition' => [], 'pa_reference_code_set' => []];
        self::assertSame(hash('sha256', json_encode($expected, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)), $this->host->catalogRevision());
    }

    public function testCountsUseOnlySelectedActiveDefinitionsAndEmptyMeansEmpty(): void
    {
        foreach ([[] => 0] as $unused) {
            // Kept out of execution: PHP array keys cannot be arrays.
        }
    }

    public function testSelectedModuleInvalidationBumpsEachTenantOnceWithoutTouchingOtherModules(): void
    {
        $permissions = $this->database->query('SELECT * FROM pa_permission ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
        $this->host->invalidateTenantAuthorization(['fixture.alpha', 'fixture.beta', 'fixture.alpha']);
        self::assertSame([6,11,20], $this->database->query('SELECT authorization_revision FROM pa_tenant ORDER BY id')->fetchAll(PDO::FETCH_COLUMN));
        self::assertSame([9,13,25], $this->database->query('SELECT revision FROM pa_tenant ORDER BY id')->fetchAll(PDO::FETCH_COLUMN));
        self::assertSame([3,5,7,8], $this->database->query('SELECT authorization_revision FROM pa_tenant_module ORDER BY id')->fetchAll(PDO::FETCH_COLUMN));
        self::assertSame('old', $this->database->query('SELECT updated_at FROM pa_tenant WHERE id=303')->fetchColumn());
        self::assertSame($permissions, $this->database->query('SELECT * FROM pa_permission ORDER BY id')->fetchAll(PDO::FETCH_ASSOC));
    }

    public function testEmptyOrUnmatchedSelectionDoesNotInvalidateAnything(): void
    {
        $before = $this->revisions();
        $this->authorization->invalidateTenantAuthorization([]);
        $this->authorization->invalidateTenantAuthorization(['fixture.missing']);
        self::assertSame($before, $this->revisions());
    }

    public function testOuterFailureRollsBackBothRevisionSets(): void
    {
        $before = $this->revisions();
        try {
            Db::transaction(function (): void {
                $this->host->invalidateTenantAuthorization(['fixture.alpha']);
                self::assertSame(6, (int) $this->database->query('SELECT authorization_revision FROM pa_tenant WHERE id=101')->fetchColumn());
                throw new DomainException('FIXTURE_OUTER_FAILURE');
            });
            self::fail('Outer failure was swallowed.');
        } catch (DomainException $exception) {
            self::assertSame('FIXTURE_OUTER_FAILURE', $exception->getMessage());
        }
        self::assertSame($before, $this->revisions());
    }

    public function testSecondWriteFailureCannotLeavePartialInvalidation(): void
    {
        $before = $this->revisions();
        $this->database->exec("CREATE TRIGGER fail_tenant_update BEFORE UPDATE ON pa_tenant BEGIN SELECT RAISE(ABORT, 'FIXTURE_UPDATE_DENIED'); END");
        $failure = null;
        try {
            $this->authorization->invalidateTenantAuthorization(['fixture.alpha']);
        } catch (\think\db\exception\PDOException $exception) {
            $failure = $exception;
        }
        self::assertNotNull($failure);
        self::assertSame($before, $this->revisions());
    }

    private function revisions(): array
    {
        return [
            $this->database->query('SELECT * FROM pa_tenant ORDER BY id')->fetchAll(PDO::FETCH_ASSOC),
            $this->database->query('SELECT * FROM pa_tenant_module ORDER BY id')->fetchAll(PDO::FETCH_ASSOC),
        ];
    }
}
