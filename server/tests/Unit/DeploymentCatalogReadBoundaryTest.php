<?php

declare(strict_types=1);

namespace tests\Unit;

use app\platform\infrastructure\module\DeployedTenantModuleRegistry;
use app\platform\infrastructure\plugin\ScopedMenuCatalogRepository;
use app\platform\services\module\ModuleQualificationQueryService;
use PDO;
use PeanutAdmin\Kernel\Menu\MenuCatalogRepository;
use PeanutAdmin\Kernel\Module\CompiledModuleRegistry;
use PeanutAdmin\Kernel\Module\ManifestDocument;
use PeanutAdmin\Kernel\Module\ModuleException;
use PeanutAdmin\Modules\Identity\Contract\TenantModuleStateQueries;
use PeanutAdmin\Modules\Identity\Menu\MenuCatalogSynchronizer;
use PHPUnit\Framework\TestCase;
use think\App;
use think\facade\Db;

/** Actual owner queries on private in-memory data; no deployment, catalog mutation or business database. */
final class DeploymentCatalogReadBoundaryTest extends TestCase
{
    private PDO $pdo;
    private TenantModuleStateQueries $states;
    private DeployedTenantModuleRegistry $registry;
    private array $manifests;

    protected function setUp(): void
    {
        $root = dirname(__DIR__, 3);
        require_once $root . '/server/vendor/topthink/framework/src/helper.php';
        new App($root . '/.local/tmp/deployment-catalog-read');
        $this->pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        \ThinkPhpTestConnection::fromPdo($this->pdo);
        $this->pdo->exec(<<<'SQL'
            CREATE TABLE pa_module_installation (id INTEGER PRIMARY KEY, module_key TEXT UNIQUE, installed_version TEXT, manifest_schema_version INTEGER, manifest_digest TEXT, status TEXT, revision INTEGER, activated_at TEXT, created_at TEXT, updated_at TEXT, private_note TEXT);
            CREATE TABLE pa_plugin_module (module_key TEXT,plugin_key TEXT);
            CREATE TABLE pa_menu_definition (id INTEGER PRIMARY KEY,"key" TEXT,module_key TEXT,status TEXT,private_note TEXT);
            INSERT INTO pa_menu_definition VALUES (1,'alpha.old','fixture.alpha','active','private'),(2,'beta.live','fixture.beta','active','private'),(3,'beta.old','fixture.beta','retired','private'),(4,'core.root','core','active','private');
            SQL);
        $this->manifests = [];
        foreach (['alpha', 'beta'] as $key) {
            $document = ManifestDocument::fromArray($root . '/.local/tmp/' . $key, [
                'key' => 'fixture.' . $key, 'version' => '1.2.3', 'schema_version' => 1,
                'tenant' => ['enableable' => true], 'dependencies' => [],
            ]);
            $this->manifests['fixture.' . $key] = $document;
            $this->pdo->prepare('INSERT INTO pa_module_installation VALUES (?,?,?,?,?,?,?,?,?,?,?)')->execute([
                count($this->manifests), 'fixture.' . $key, '1.2.3', 1, $document->digest,
                $key === 'alpha' ? 'active' : 'disabled', 7, 'activated', 'created', 'updated', 'never-expose',
            ]);
        }
        $documents = array_values($this->manifests);
        $compiled = new CompiledModuleRegistry($documents, [], [], [], hash('sha256', implode('|', array_map(static fn($doc): string => $doc->digest, $documents))));
        $this->states = new TenantModuleStateQueries();
        $this->registry = new DeployedTenantModuleRegistry($compiled, $this->states);
    }

    public function testFixedIdentityAndActiveProjectionsPreserveValuesWithoutPrivateColumns(): void
    {
        $identity = $this->states->installationIdentity('fixture.alpha');
        self::assertSame(['installed_version', 'manifest_schema_version', 'manifest_digest', 'status'], array_keys($identity));
        self::assertSame($this->manifests['fixture.alpha']->digest, $identity['manifest_digest']);
        self::assertSame('disabled', $this->states->installationIdentity('fixture.beta')['status']);
        self::assertNull($this->states->installationIdentity('fixture.missing'));
        self::assertSame(['fixture.alpha'], $this->states->activeInstallationKeys());
        self::assertSame([['module_key' => 'fixture.alpha', 'revision' => 7, 'activated_at' => 'activated', 'created_at' => 'created', 'updated_at' => 'updated']], $this->states->activeInstallationMetadata());
        self::assertSame(1, $this->states->activeInstallationCount(['fixture.alpha', 'fixture.beta']));
        self::assertStringNotContainsString('never-expose', json_encode([$identity, $this->states->activeInstallationMetadata()], JSON_THROW_ON_ERROR));
    }

    public function testRegistryPreservesNotInstalledInactiveAndMismatchFailureContracts(): void
    {
        self::assertSame($this->manifests['fixture.alpha'], $this->registry->requireInstalled('fixture.alpha'));
        foreach ([
            ['fixture.missing', 'MODULE_NOT_INSTALLED'],
            ['fixture.beta', 'MODULE_INSTALLATION_FAILED'],
        ] as [$key, $error]) {
            try {
                $this->registry->requireInstalled($key);
                self::fail('Invalid deployment admitted.');
            } catch (ModuleException $failure) {
                self::assertSame($error, $failure->errorCode);
            }
        }
        $this->pdo->exec("UPDATE pa_module_installation SET installed_version='2.0.0' WHERE module_key='fixture.alpha'");
        $this->expectException(ModuleException::class);
        $this->expectExceptionMessage('Installed Module manifest does not match');
        $this->registry->requireInstalled('fixture.alpha');
    }

    public function testHostQualificationUsesOnlyActiveIdentitiesAndKeepsDeploymentFallback(): void
    {
        $service = new ModuleQualificationQueryService($this->registry, $this->states);
        $items = $service->installedModules();
        self::assertCount(1, $items);
        self::assertSame('fixture.alpha', $items[0]->moduleKey);
        self::assertSame('deployment', $items[0]->pluginKey);
        self::assertSame('1.2.3', $items[0]->version);
    }

    public function testScopedMenusKeepOtherModulesAndDoNotResurrectRetiredKeys(): void
    {
        $inner = $this->createMock(MenuCatalogRepository::class);
        $inner->expects(self::once())->method('retireMissing')->with(['alpha.new', 'beta.live', 'core.root']);
        $synchronizer = new MenuCatalogSynchronizer($inner);
        self::assertSame(['beta.live', 'core.root'], $synchronizer->activeKeysOutsideModules(['fixture.alpha']));
        $before = $this->pdo->query('SELECT * FROM pa_menu_definition ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
        (new ScopedMenuCatalogRepository($inner, ['fixture.alpha']))->retireMissing(['alpha.new', 'alpha.new']);
        self::assertSame($before, $this->pdo->query('SELECT * FROM pa_menu_definition ORDER BY id')->fetchAll(PDO::FETCH_ASSOC));
    }

    public function testOwnerReadsUseCallersTransactionAndStorageFailuresStayFailures(): void
    {
        Db::transaction(function (): void {
            self::assertTrue($this->pdo->inTransaction());
            self::assertNotNull($this->states->installationIdentity('fixture.alpha', true));
            self::assertTrue($this->pdo->inTransaction());
        });
        self::assertFalse($this->pdo->inTransaction());
        $this->pdo->exec('DROP TABLE pa_module_installation');
        $this->expectException(\Throwable::class);
        $this->states->activeInstallationKeys();
    }

    public function testReadConsumersDoNotReachOwnerStorageDirectly(): void
    {
        $root = dirname(__DIR__, 2) . '/app/';
        foreach ([
            'platform/infrastructure/module/DeployedTenantModuleRegistry.php',
            'platform/services/module/ModuleQualificationQueryService.php',
            'platform/policy/plugin/ModuleLifecyclePolicy.php',
            'platform/infrastructure/plugin/ScopedMenuCatalogRepository.php',
        ] as $path) {
            $source = file_get_contents($root . $path);
            self::assertStringNotContainsString("Db::name('module_installation')", $source, $path);
            self::assertStringNotContainsString("Db::name('menu_definition')", $source, $path);
        }
    }
}
