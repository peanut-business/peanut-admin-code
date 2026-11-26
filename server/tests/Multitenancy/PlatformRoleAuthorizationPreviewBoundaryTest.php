<?php

declare(strict_types=1);

namespace tests\Multitenancy;

use app\common\contract\module\ModuleQualificationQuery;
use app\common\execution\AdminExecutionContext;
use app\common\execution\CurrentExecutionContext;
use app\common\execution\ExecutionContextStore;
use app\common\execution\PlatformExecutionContext;
use app\common\http\ApiProblem;
use app\common\services\authorization\EndpointPermissionGrant;
use app\common\services\authorization\GeneratedApiCatalog;
use app\platform\context\PlatformOperatorContext;
use app\platform\controller\PlatformControlPlaneQueryController;
use app\platform\http\middleware\PlatformPermissionMiddleware;
use app\platform\query\PlatformControlPlaneQueryService;
use app\platform\services\PlatformApiAuthorization;
use app\platform\services\PlatformOperatorSessionService;
use Composer\Autoload\ClassLoader;
use DateTimeImmutable;
use PDO;
use PeanutAdmin\Kernel\Auth\TenantContext;
use PeanutAdmin\Kernel\Auth\ValidatedPlatformSession;
use PeanutAdmin\Kernel\Auth\ValidatedTenantSession;
use PeanutAdmin\Kernel\Authorization\Application\AdminAccessException;
use PeanutAdmin\Kernel\Authorization\AuthorizationException;
use PeanutAdmin\Kernel\Authorization\RevisionPermissionCache;
use PeanutAdmin\Kernel\Context\PlatformContext;
use PeanutAdmin\Kernel\Menu\MenuRegistry;
use PeanutAdmin\Kernel\Module\ModuleAvailability;
use PeanutAdmin\Kernel\Tenancy\TenantScope;
use PeanutAdmin\Kernel\Platform\Authorization\PlatformAuthorizationEvaluator;
use PeanutAdmin\Modules\Identity\Auth\PlatformAuthService;
use PeanutAdmin\Modules\Identity\Authorization\ApiPermissionCatalog;
use PeanutAdmin\Modules\Identity\Contract\PlatformDirectoryQueries;
use PeanutAdmin\Modules\Identity\Contract\PlatformRoleAuthorizationPreview;
use PeanutAdmin\Modules\Identity\Menu\CoreMenuCatalog;
use PeanutAdmin\Modules\Identity\Platform\Authorization\ThinkPhpPlatformAuthorizationRepository;
use PeanutAdmin\Modules\Identity\Policy\DemoAccountPolicy;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use think\App;
use think\Request;
use think\Route;

/** In-process controller and middleware with Identity author source and SQLite memory data. */
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class PlatformRoleAuthorizationPreviewBoundaryTest extends TestCase
{
    private App $app;
    private PDO $pdo;
    private ExecutionContextStore $store;
    private CurrentExecutionContext $current;
    private ModuleAvailability $modules;
    private array $catalogEndpoints;
    private ?array $fileCatalogEndpoints = null;
    private array $endpoint;
    private array $auditEndpoint;
    private int $continuations = 0;
    private ClassLoader $loader;
    private string $caseRoot;

    protected function setUp(): void
    {
        $root = dirname(__DIR__, 3);
        $moduleRoot = rtrim((string) getenv('PEANUT_OPTIONAL_MODULE_APPLICATION_ROOT'), '/');
        self::assertNotSame('', $moduleRoot, 'An explicit Identity author application is required.');
        $identity = $moduleRoot . '/server/app/modules/peanut/identity';
        self::assertFileExists($identity . '/composer.json');
        $composer = json_decode(file_get_contents($identity . '/composer.json'), true, 512, JSON_THROW_ON_ERROR);
        $this->loader = new ClassLoader();
        $this->loader->addPsr4('app\\', $root . '/server/app');
        foreach ($composer['autoload']['psr-4'] as $prefix => $paths) {
            $this->loader->addPsr4($prefix, array_map(static fn(string $path): string => $identity . '/' . $path, (array) $paths));
        }
        $this->loader->register(true);
        foreach ([PlatformRoleAuthorizationPreview::class, ThinkPhpPlatformAuthorizationRepository::class, ApiPermissionCatalog::class, CoreMenuCatalog::class] as $class) {
            self::assertStringStartsWith($identity . '/src/', (new ReflectionClass($class))->getFileName());
        }
        self::assertSame($root . '/server/app/platform/controller/PlatformControlPlaneQueryController.php',
            (new ReflectionClass(PlatformControlPlaneQueryController::class))->getFileName());
        require_once $root . '/server/vendor/topthink/framework/src/helper.php';
        require_once $root . '/server/tests/Support/ThinkPhpTestConnection.php';
        require_once $moduleRoot . '/server/tests/Support/PlatformRoleAuthorizationPreviewFixture.php';
        $this->caseRoot = $root . '/.local/tmp/platform-role-preview-boundary/' . bin2hex(random_bytes(6)) . '/';
        $this->app = new App($this->caseRoot);
        $this->app->config->set(['default' => 'file', 'stores' => ['file' => ['type' => 'File', 'path' => $this->caseRoot . 'cache/']]], 'cache');
        $this->pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        \ThinkPhpTestConnection::fromPdo($this->pdo);
        \PlatformRoleAuthorizationPreviewFixture::install($this->pdo);
        // The original test fixture keeps minimal menu columns. The trusted
        // resolver reads the real registration fields; only extend this SQLite
        // fixture, not Identity production schema or the shared database.
        $this->pdo->exec(<<<'SQL'
            ALTER TABLE pa_system_menu ADD COLUMN source TEXT DEFAULT 'module';
            ALTER TABLE pa_system_menu ADD COLUMN module_key TEXT DEFAULT 'platform';
            ALTER TABLE pa_system_menu ADD COLUMN parent_key TEXT;
            ALTER TABLE pa_system_menu ADD COLUMN route_path TEXT;
            ALTER TABLE pa_system_menu ADD COLUMN client_keys_json TEXT DEFAULT '["platform-web"]';
            SQL);
        $catalogPath = getenv('PEANUT_PLATFORM_PREVIEW_API_CATALOG') ?: $root . '/server/generated/api-catalog.json';
        $catalog = json_decode(file_get_contents($catalogPath), true, 512, JSON_THROW_ON_ERROR);
        $this->catalogEndpoints = $catalog['endpoints'];
        $fileCatalogPath = getenv('PEANUT_PLATFORM_FILE_API_CATALOG');
        if ($fileCatalogPath !== false && $fileCatalogPath !== '') {
            self::assertFileExists($fileCatalogPath);
            $fileCatalog = json_decode(file_get_contents($fileCatalogPath), true, 512, JSON_THROW_ON_ERROR);
            self::assertNull(GeneratedApiCatalog::freshness($moduleRoot . '/server', $fileCatalog['inputs']));
            $this->fileCatalogEndpoints = $fileCatalog['endpoints'];
        }
        $this->endpoint = $this->installPlatformApi('POST', '/platformapi/roles/permissions/preview', 'platform.role.read');
        self::assertSame(['platform.permission.api.read', 'platform.operator.read'], $this->endpoint['prerequisite_permission_keys']);
        self::assertSame(PlatformControlPlaneQueryController::class, $this->endpoint['controller']);
        self::assertSame('previewRolePermissions', $this->endpoint['action']);
        $this->pdo->prepare('INSERT INTO pa_permission_api (permission_id,api_id) VALUES (?,?)')->execute([1, $this->endpoint['api_id']]);
        $this->auditEndpoint = $this->installPlatformApi('POST', '/platformapi/roles/permissions/catalog-audit', 'platform.role.read');
        self::assertSame(['platform.permission.api.read', 'platform.operator.read'], $this->auditEndpoint['prerequisite_permission_keys']);
        self::assertSame(PlatformControlPlaneQueryController::class, $this->auditEndpoint['controller']);
        self::assertSame('auditRoleCatalog', $this->auditEndpoint['action']);
        $this->pdo->prepare('INSERT INTO pa_permission_api (permission_id,api_id) VALUES (?,?)')->execute([1, $this->auditEndpoint['api_id']]);
        $this->store = new ExecutionContextStore();
        $this->current = new CurrentExecutionContext($this->store);
        $this->app->instance(CurrentExecutionContext::class, $this->current);
        $this->modules = new class implements ModuleAvailability {
            public function assertAvailable(TenantScope $scope, string $moduleKey, DateTimeImmutable $now, bool $lock = false): void {}
            public function assertDeployment(string $moduleKey, bool $lock = false): void {}
            public function assertTenant(TenantScope $scope, string $moduleKey, DateTimeImmutable $now, bool $lock = false): void {}
        };
    }

    protected function tearDown(): void
    {
        $this->loader->unregister();
        if (isset($this->caseRoot) && is_dir($this->caseRoot)) {
            $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->caseRoot, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
            foreach ($files as $file) {
                $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
            }
            rmdir($this->caseRoot);
        }
    }

    public function testNativeControllerConsumesExactPreviewWithoutChangingAnyRow(): void
    {
        $before = $this->snapshot();
        $response = $this->call(\PlatformRoleAuthorizationPreviewFixture::exactCandidate());
        self::assertSame(200, $response->getCode());
        $body = $response->getData();
        self::assertSame(20000, $body['code']);
        self::assertTrue($body['data']['capability_equal']);
        self::assertTrue($body['data']['relation_coverage_complete']);
        self::assertSame([], $body['data']['added']);
        self::assertSame([], $body['data']['removed']);
        self::assertSame([], $body['data']['unresolved']);
        self::assertSame(1, $this->continuations);
        self::assertSame($before, $this->snapshot());
        self::assertTrue($this->store->isEmpty());
    }

    public function testCatalogAuditsMemberlessRoleAndReportsIncompleteWholeCatalogWithoutWrites(): void
    {
        $this->platformSourceCandidate(); // Register the existing Shell's real menu and API consumers.
        $this->pdo->exec("INSERT INTO pa_platform_role (id,\"key\",status,revision) VALUES (601,'platform.fixture.future','active',4)");
        $candidate = [
            'role_ids' => ['601'],
            'role_assignments' => [['role_id' => '601', 'expected_revision' => '4', 'node_keys' => []]],
        ];
        $before = $this->snapshot();
        $response = $this->call($candidate, catalogAudit: true);
        self::assertSame(200, $response->getCode());
        $data = $response->getData()['data'];
        self::assertSame(7, $data['catalog_total']);
        self::assertFalse($data['scope_covers_catalog']);
        self::assertTrue($data['equivalent']);
        self::assertSame('601', $data['roles'][0]['role_id']);
        self::assertSame(0, $data['roles'][0]['member_count']);
        self::assertSame(4, $data['roles'][0]['observed_revision']);
        self::assertTrue($data['roles'][0]['relation_equal']);
        self::assertSame([], $data['unresolved']);
        self::assertSame($before, $this->snapshot());

        $candidate['role_assignments'][0]['expected_revision'] = '3';
        $stale = $this->call($candidate, catalogAudit: true)->getData()['data'];
        self::assertFalse($stale['equivalent']);
        self::assertFalse($stale['roles'][0]['relation_equal']);
        self::assertContains(['code' => 'role_revision_mismatch', 'role_id' => '601'], $stale['unresolved']);
        self::assertSame($before, $this->snapshot());

        $candidate['role_ids'] = ['101', '201', '202', '301', '401', '501', '601'];
        $candidate['role_assignments'] = [];
        $all = $this->call($candidate, catalogAudit: true)->getData()['data'];
        self::assertTrue($all['scope_covers_catalog']);
        self::assertFalse($all['equivalent']);
        self::assertCount(7, $all['roles']);
        self::assertCount(7, array_filter($all['unresolved'], fn(array $row): bool => $row['code'] === 'role_assignment_missing'));
        self::assertSame($before, $this->snapshot());
        self::assertTrue($this->store->isEmpty());
    }


    public function testCanonicalCatalogAuditUsesOnlyRegisteredPlatformNodesAndCurrentApiRows(): void
    {
        $source = $this->platformSourceCandidate();
        $candidate = [
            'role_ids' => ['501'],
            'role_assignments' => [
                ['role_id' => '501', 'expected_revision' => '1',
                    'node_keys' => $source['role_assignments'][0]['node_keys']],
            ],
        ];
        $before = $this->snapshot();
        $response = $this->call($candidate, catalogAudit: true);
        self::assertSame(200, $response->getCode());
        $data = $response->getData()['data'];
        self::assertSame(6, $data['catalog_total']);
        self::assertFalse($data['scope_covers_catalog']);
        self::assertTrue($data['equivalent']);
        self::assertSame([], $data['unresolved']);
        self::assertSame([['role_id' => '501', 'relation_equal' => true]],
            array_map(static fn(array $row): array => [
                'role_id' => $row['role_id'], 'relation_equal' => $row['relation_equal'],
            ], $data['roles']));
        self::assertSame($before, $this->snapshot());
        self::assertTrue($this->store->isEmpty());
    }

    public function testCatalogAuditRejectsForgedNodesAndStaleRegisteredSourcesWithoutWrites(): void
    {
        $source = $this->platformSourceCandidate();
        $candidate = [
            'role_ids' => ['501'],
            'role_assignments' => [
                ['role_id' => '501', 'expected_revision' => '1',
                    'node_keys' => $source['role_assignments'][0]['node_keys']],
            ],
        ];
        $before = $this->snapshot();
        $forged = $candidate;
        $forged['nodes'] = $source['nodes'];
        $this->assertCatalogRejected('PLATFORM_ROLE_CANDIDATE_INVALID', $forged);
        $forged = $candidate;
        $forged['role_assignments'][0]['node_keys'][] = 'platform.fixture.forced-api';
        $this->assertCatalogRejected('PLATFORM_ROLE_NODE_NOT_REGISTERED', $forged);
        self::assertSame($before, $this->snapshot());

        // The role revision stays unchanged while a current API or registered
        // menu diverges from the generated native source.
        $id = $this->auditEndpoint['api_id'];
        $this->pdo->prepare("UPDATE pa_system_api SET module_key='tenant' WHERE id=?")->execute([$id]);
        $drift = $this->snapshot();
        $this->assertCatalogRejected('PLATFORM_ROLE_API_CATALOG_DRIFT', $candidate);
        self::assertSame($drift, $this->snapshot());
        $this->pdo->prepare("UPDATE pa_system_api SET module_key='platform' WHERE id=?")->execute([$id]);
        // Retire a genuine candidate API, not the catalog-audit endpoint itself:
        // endpoint retirement is correctly denied by middleware before source resolution.
        $roleCreateApi = $this->pdo->query("SELECT id FROM pa_system_api WHERE path='/platformapi/roles/create'")->fetchColumn();
        self::assertIsString($roleCreateApi);
        $this->pdo->prepare("UPDATE pa_system_api SET status='retired' WHERE id=?")->execute([$roleCreateApi]);
        $retired = $this->snapshot();
        $this->assertCatalogRejected('PLATFORM_ROLE_API_CATALOG_DRIFT', $candidate);
        self::assertSame($retired, $this->snapshot());
        $this->pdo->prepare("UPDATE pa_system_api SET status='active' WHERE id=?")->execute([$roleCreateApi]);

        $this->pdo->exec("UPDATE pa_system_menu SET scope='tenant' WHERE \"key\"='platform.role.list'");
        $menuDrift = $this->snapshot();
        $this->assertCatalogRejected('PLATFORM_ROLE_MENU_CATALOG_DRIFT', $candidate);
        self::assertSame($menuDrift, $this->snapshot());
    }

    private function assertCatalogRejected(string $errorCode, array $candidate): void
    {
        try {
            $this->call($candidate, catalogAudit: true);
            self::fail('The native catalog audit accepted an unregistered source.');
        } catch (AdminAccessException $exception) {
            self::assertSame($errorCode, $exception->errorCode);
        }
        self::assertTrue($this->store->isEmpty());
    }

    public static function catalogReadGrants(): array
    {
        return ['role' => [1], 'api' => [2], 'operator' => [3]];
    }

    #[DataProvider('catalogReadGrants')]
    public function testCatalogAuditRequiresEachNativeReadPermission(int $permissionId): void
    {
        $this->pdo->exec('DELETE FROM pa_platform_role_permission WHERE platform_role_id=101 AND permission_id=' . $permissionId);
        $candidate = ['role_ids' => ['101'], 'role_assignments' => []];
        $this->assertDenied(403, candidate: $candidate, catalog: true);
    }

    public function testCatalogAuditRejectsTenantAndMissingIdentityBeforeController(): void
    {
        $candidate = ['role_ids' => ['101'], 'role_assignments' => []];
        $before = $this->snapshot();
        foreach (['none', 'tenant'] as $identity) {
            try {
                $this->call($candidate, $identity, catalogAudit: true);
                self::fail('A Platform session is required for the catalog audit.');
            } catch (ApiProblem $problem) {
                self::assertSame(401, $problem->httpStatus);
            }
        }
        self::assertSame($before, $this->snapshot());
    }

    public static function readGrants(): array
    {
        return ['role' => [1], 'api' => [2], 'operator' => [3]];
    }

    #[DataProvider('readGrants')]
    public function testEachMissingReadPermissionDeniesBeforeController(int $permissionId): void
    {
        $candidate = $this->platformSourceCandidate();
        $this->pdo->exec('DELETE FROM pa_platform_role_permission WHERE platform_role_id=101 AND permission_id=' . $permissionId);
        $this->assertDenied(403, candidate: $candidate);
    }

    public function testCustomEndpointBindingCannotReplaceStrictRoleReadPermission(): void
    {
        $this->pdo->exec('DELETE FROM pa_platform_role_permission WHERE platform_role_id=101 AND permission_id=1');
        $this->pdo->exec('INSERT INTO pa_platform_role_permission VALUES (101,13)');
        $this->pdo->prepare('UPDATE pa_permission_api SET permission_id=13 WHERE api_id=?')->execute([$this->endpoint['api_id']]);
        $before = $this->snapshot();
        try {
            $this->call(\PlatformRoleAuthorizationPreviewFixture::exactCandidate());
            self::fail('The strict Identity query authorization must deny the substituted grant.');
        } catch (AuthorizationException) {
            self::assertSame(1, $this->continuations, 'The native middleware admitted its configured primary binding before Identity refused.');
        }
        self::assertSame($before, $this->snapshot());
        self::assertTrue($this->store->isEmpty());
    }

    public function testTenantAndMissingIdentityCannotReachController(): void
    {
        $candidate = $this->platformSourceCandidate();
        $before = $this->snapshot();
        foreach (['none', 'tenant'] as $identity) {
            try {
                $this->call($candidate, $identity);
                self::fail('A trusted Platform context is required.');
            } catch (ApiProblem $problem) {
                self::assertSame(401, $problem->httpStatus);
            }
            self::assertSame(0, $this->continuations);
            self::assertTrue($this->store->isEmpty());
        }
        self::assertSame($before, $this->snapshot());
    }

    public function testRetiredPreviewEndpointIsDeniedBeforeController(): void
    {
        $this->pdo->prepare("UPDATE pa_system_api SET status='retired' WHERE id=?")->execute([$this->endpoint['api_id']]);
        $this->assertDenied(403);
    }

    public function testCallerDemoLockStillDeniesThePost(): void
    {
        $this->pdo->exec("INSERT INTO pa_credential VALUES (2,111,'email_password','email','demo-caller@example.test','active')");
        $this->assertDenied(403, new DemoAccountPolicy(true, ['demo-caller@example.test']));
    }

    public function testInjectedOwnerFieldIsRejectedWithoutWrites(): void
    {
        $candidate = \PlatformRoleAuthorizationPreviewFixture::exactCandidate();
        $candidate['is_owner'] = true;
        $before = $this->snapshot();
        try {
            $this->call($candidate);
            self::fail('Unknown candidate fields must not become authorization facts.');
        } catch (AdminAccessException) {
            self::assertSame(1, $this->continuations);
        }
        self::assertSame($before, $this->snapshot());
        self::assertTrue($this->store->isEmpty());
    }

    public function testRegisteredPlatformBranchAndOperationMatchLegacyBindings(): void
    {
        $this->assertCanonicalPlatformMenuRegistration();
        $candidate = $this->platformSourceCandidate();
        self::assertCount($this->fileCatalogEndpoints === null ? 77 : 87, $candidate['nodes']);
        $fullCandidate = $candidate;
        $before = $this->snapshot();
        $response = $this->call($candidate);
        self::assertSame(200, $response->getCode());
        self::assertSame(20000, $response->getData()['code']);
        $data = $response->getData()['data'];
        self::assertSame('55', $data['operator_id']);
        self::assertTrue($data['capability_equal']);
        self::assertTrue($data['relation_coverage_complete']);
        self::assertSame([], $data['added']);
        self::assertSame([], $data['removed']);
        self::assertSame([], $data['unresolved']);
        self::assertSame([[
            'role_id' => '501', 'relation_equal' => true, 'owner_dynamic' => false, 'added' => [], 'removed' => [],
        ]], $data['roles']);
        self::assertSame(1, $this->continuations);
        self::assertSame($before, $this->snapshot());
        self::assertTrue($this->store->isEmpty());

        // The workbench still links the Roles API when the Roles page is unselected.
        $candidate['role_assignments'][0]['node_keys'] = array_values(array_diff(
            $candidate['role_assignments'][0]['node_keys'],
            ['platform.role.list'],
        ));
        $response = $this->call($candidate);
        self::assertSame(200, $response->getCode());
        self::assertSame($data, $response->getData()['data']);
        self::assertSame(2, $this->continuations);
        self::assertSame($before, $this->snapshot());
        self::assertTrue($this->store->isEmpty());

        $candidate['role_assignments'][0]['node_keys'] = array_values(array_diff(
            $candidate['role_assignments'][0]['node_keys'],
            ['platform.operator.role.assign'],
        ));
        $operatorRoleApis = array_column($candidate['nodes'], 'api_ids', 'key')['platform.operator.role.assign'];
        $response = $this->call($candidate);
        self::assertSame(200, $response->getCode());
        $data = $response->getData()['data'];
        self::assertFalse($data['capability_equal']);
        self::assertSame([$operatorRoleApis[1]], $data['removed']);
        self::assertSame([$operatorRoleApis[1]], $data['roles'][0]['removed']);
        self::assertSame([], $data['unresolved']);
        self::assertSame(3, $this->continuations);
        self::assertSame($before, $this->snapshot());
        self::assertTrue($this->store->isEmpty());

        $this->assertIndependentOpsReads($fullCandidate, $before);
        $this->assertPreviewApiIsAnIndependentOperation($fullCandidate, $before);
        if ($this->fileCatalogEndpoints !== null) {
            $this->assertIndependentFileReads($fullCandidate, $before);
        }
    }

    private function assertPreviewApiIsAnIndependentOperation(array $candidate, array $before): void
    {
        $candidate['role_assignments'][0]['node_keys'] = array_values(array_diff(
            $candidate['role_assignments'][0]['node_keys'],
            ['platform.role.authorization.preview'],
        ));
        $this->assertOnlyApisRemoved($candidate, [$this->endpoint['api_id']], $before);
    }

    private function assertCanonicalPlatformMenuRegistration(): void
    {
        $registry = new MenuRegistry(CoreMenuCatalog::definitions());
        $available = static fn(string $key): bool => $key === 'platform';
        $permissionGranted = static fn(string $key): bool => true;
        $platform = array_column($registry->visible('platform-web', $available, $available, $permissionGranted), 'requiredPermission', 'key');
        foreach ([
            'platform.tenant.owner-invitation.list' => 'platform.tenant.provision-owner',
            'platform.tenant.entry-binding.list' => 'platform.tenant.read',
            'platform.tenant.entitlements' => 'platform.tenant.read',
            'platform.plan.list' => 'platform.plan.read',
            'platform.developer.catalog' => 'platform.module.read',
        ] as $key => $permission) {
            self::assertSame($permission, $platform[$key] ?? null, $key);
        }
        $tenant = $registry->visible('admin-web', $available, $available, $permissionGranted);
        self::assertEqualsCanonicalizing([
            'core.workbench.index', 'core.organization', 'core.member.list',
            'core.department.list', 'core.role.list', 'core.job.list',
            'core.operations', 'core.audit.list',
        ], array_column($tenant, 'key'));
    }

    private function assertIndependentFileReads(array $candidate, array $before): void
    {
        $apiIdsByNode = array_column($candidate['nodes'], 'api_ids', 'key');
        self::assertSame([], $apiIdsByNode['platform.storage']);
        $storageApiIdsByNode = array_filter($apiIdsByNode, static fn(string $key): bool => str_starts_with($key, 'platform.storage.'), ARRAY_FILTER_USE_KEY);
        $storageApiIds = array_values(array_unique(array_merge(...array_values($storageApiIdsByNode))));
        self::assertCount(7, $storageApiIds);
        $hostSelections = array_values(array_diff($candidate['role_assignments'][0]['node_keys'], array_keys($storageApiIdsByNode)));

        $accountRead = $apiIdsByNode['platform.storage.account.list'][0];
        $spaceRead = $apiIdsByNode['platform.storage.space.list'][0];
        $legacyRead = $apiIdsByNode['platform.storage.legacy.read'][0];
        $spaceFine = 'platform.storage.space.create.fine';
        $spaceLegacy = 'platform.storage.space.create.legacy';
        $routeFine = 'platform.storage.route.update.fine';
        $routeLegacy = 'platform.storage.route.update.legacy';
        [$spaceWrite] = array_values(array_intersect($apiIdsByNode[$spaceFine], $apiIdsByNode[$spaceLegacy]));
        [$routeWrite] = array_values(array_intersect($apiIdsByNode[$routeFine], $apiIdsByNode[$routeLegacy]));
        foreach ([
            [['platform.storage', 'platform.storage.account.list'], [$accountRead]],
            [[$spaceFine], [$spaceWrite, $accountRead]],
            [[$spaceLegacy], [$spaceWrite, $legacyRead]],
            [[$routeFine], [$routeWrite, $spaceRead]],
            [[$routeLegacy], [$routeWrite, $legacyRead]],
            [[$spaceFine, $spaceLegacy, $routeFine, $routeLegacy], [$spaceWrite, $routeWrite, $accountRead, $spaceRead, $legacyRead]],
        ] as [$selected, $retained]) {
            $candidate['role_assignments'][0]['node_keys'] = [...$hostSelections, ...$selected];
            $this->assertOnlyApisRemoved($candidate, array_diff($storageApiIds, $retained), $before);
        }
    }

    private function assertIndependentOpsReads(array $candidate, array $before): void
    {
        $alternatives = [
            'platform.upgrade.readiness.read', 'platform.upgrade.history.read',
            'platform.ops.logs.read', 'platform.ops.diagnostics.download',
        ];
        $apiIdsByNode = array_column($candidate['nodes'], 'api_ids', 'key');
        self::assertSame([], $apiIdsByNode['platform.upgrade.status']);
        self::assertSame([], $apiIdsByNode['platform.ops.diagnostics']);
        $upgrade = 'platform.ops.upgrade.execute';
        $otherSelections = array_values(array_diff($candidate['role_assignments'][0]['node_keys'], [...$alternatives, $upgrade]));
        $upgradeWrite = array_values(array_diff($apiIdsByNode[$upgrade], $apiIdsByNode[$alternatives[0]], $apiIdsByNode[$alternatives[1]]));
        self::assertCount(1, $upgradeWrite);
        $parents = ['platform.upgrade.status', 'platform.ops.diagnostics'];
        foreach ([[], ['platform.upgrade.readiness.read', 'platform.ops.logs.read']] as $selected) {
            $candidate['role_assignments'][0]['node_keys'] = [...$otherSelections, ...$parents, ...$selected];
            $removed = array_map(
                static fn(string $key): string => $apiIdsByNode[$key][0],
                array_diff($alternatives, $selected),
            );
            $this->assertOnlyApisRemoved($candidate, [...$removed, ...$upgradeWrite], $before);
        }
        $candidate['role_assignments'][0]['node_keys'] = [...$otherSelections, ...$parents, $upgrade, $alternatives[2], $alternatives[3]];
        $response = $this->call($candidate);
        self::assertSame(200, $response->getCode());
        $data = $response->getData()['data'];
        self::assertTrue($data['capability_equal']);
        self::assertTrue($data['relation_coverage_complete']);
        self::assertSame([], $data['unresolved']);
        self::assertSame($before, $this->snapshot());
        self::assertTrue($this->store->isEmpty());

        $close = 'platform.ops.maintenance.close';
        $maintenance = 'platform.ops.maintenance';
        $candidate['role_assignments'][0]['node_keys'] = array_values(array_diff(
            $candidate['role_assignments'][0]['node_keys'],
            [$close, $maintenance],
        ));
        $this->assertOnlyApisRemoved($candidate, array_diff($apiIdsByNode[$close], $apiIdsByNode[$maintenance]), $before);
    }

    private function assertOnlyApisRemoved(array $candidate, array $removed, array $before): void
    {
        sort($removed, SORT_STRING);
        $response = $this->call($candidate);
        self::assertSame(200, $response->getCode());
        $data = $response->getData()['data'];
        self::assertFalse($data['capability_equal']);
        self::assertSame([], $data['added']);
        self::assertSame([], $data['unresolved']);
        self::assertSame($removed, $data['removed']);
        self::assertSame($removed, $data['roles'][0]['removed']);
        self::assertSame($before, $this->snapshot());
        self::assertTrue($this->store->isEmpty());
    }

    public function testExplicitEmptyPlatformSelectionReportsRemovedApisAndOmissionIsDiagnosed(): void
    {
        $candidate = $this->platformSourceCandidate();
        $removed = array_values(array_unique(array_merge(...array_column($candidate['nodes'], 'api_ids'))));
        sort($removed, SORT_STRING);
        self::assertCount($this->fileCatalogEndpoints === null ? 68 : 75, $removed);
        $before = $this->snapshot();

        $candidate['role_assignments'][0]['node_keys'] = [];
        $response = $this->call($candidate);
        self::assertSame(200, $response->getCode());
        $explicit = $response->getData()['data'];
        self::assertFalse($explicit['capability_equal']);
        self::assertFalse($explicit['relation_coverage_complete']);
        self::assertSame([], $explicit['added']);
        self::assertSame($removed, $explicit['removed']);
        self::assertSame([], $explicit['unresolved']);
        self::assertSame([[
            'role_id' => '501', 'relation_equal' => false, 'owner_dynamic' => false, 'added' => [], 'removed' => $removed,
        ]], $explicit['roles']);
        self::assertSame($before, $this->snapshot());
        self::assertTrue($this->store->isEmpty());

        $candidate['role_assignments'] = [];
        $omitted = $this->call($candidate)->getData()['data'];
        self::assertSame($removed, $omitted['removed']);
        self::assertSame([['code' => 'role_assignment_missing', 'role_id' => '501']], $omitted['unresolved']);
        self::assertSame(2, $this->continuations);
        self::assertSame($before, $this->snapshot());
        self::assertTrue($this->store->isEmpty());
    }

    private function platformSourceCandidate(): array
    {
        // An existing instance bundle can cover APIs from different source defaults.
        $this->pdo->exec("INSERT INTO pa_platform_operator (id,account_id,status) VALUES (55,555,'active')");
        $this->pdo->exec("INSERT INTO pa_platform_role (id,\"key\",status) VALUES (501,'platform.fixture.production-reader','active')");
        $this->pdo->exec('INSERT INTO pa_platform_operator_role VALUES (55,501)');
        $this->pdo->exec("INSERT INTO pa_permission VALUES (15,'platform.custom.fixture-platform-apis','active',1)");
        // Keep the real preview grants on the target role without the unrelated fixture endpoint.
        $this->pdo->exec("DELETE FROM pa_permission_api WHERE api_id IN (SELECT id FROM pa_system_api WHERE path='/platformapi/fixture/owner')");
        $this->pdo->exec("DELETE FROM pa_system_api WHERE path='/platformapi/fixture/owner'");
        $this->pdo->exec('INSERT INTO pa_platform_role_permission VALUES (501,15),(501,1),(501,2),(501,3)');
        // The old role keeps the prerequisite shared by logs and diagnostic downloads.
        $this->pdo->exec("INSERT INTO pa_permission VALUES (16,'platform.ops.read','active',1)");
        $this->pdo->exec('INSERT INTO pa_platform_role_permission VALUES (501,16)');

        $insertBinding = $this->pdo->prepare('INSERT INTO pa_permission_api (permission_id,api_id) VALUES (?,?)');
        $apiIds = ['roles.preview' => $this->endpoint['api_id'], 'roles.catalog-audit' => $this->auditEndpoint['api_id']];
        $insertBinding->execute([15, $apiIds['roles.preview']]);
        $insertBinding->execute([15, $apiIds['roles.catalog-audit']]);
        foreach ([
            'tenants.list' => ['GET', 'tenants', 'platform.tenant.read'],
            'tenants.create' => ['POST', 'tenants/provision', 'platform.tenant.create'],
            'tenants.detail' => ['GET', 'tenants/detail', 'platform.tenant.read'],
            'tenants.activate' => ['POST', 'tenants/activate', 'platform.tenant.lifecycle'],
            'tenants.suspend' => ['POST', 'tenants/suspend', 'platform.tenant.lifecycle'],
            'tenants.close' => ['POST', 'tenants/close', 'platform.tenant.lifecycle'],
            'owner.invitations' => ['GET', 'tenants/invitations', 'platform.tenant.provision-owner'],
            'owner.read' => ['GET', 'tenants/owner', 'platform.tenant.read'],
            'owner.invite' => ['POST', 'tenants/invitations', 'platform.tenant.provision-owner'],
            'owner.resend' => ['POST', 'tenants/invitations/resend', 'platform.tenant.provision-owner'],
            'owner.revoke' => ['POST', 'tenants/invitations/revoke', 'platform.tenant.provision-owner'],
            'bindings.list' => ['GET', 'tenant-entry-bindings', 'platform.tenant.read'],
            'bindings.enable' => ['POST', 'tenant-entry-bindings/enable', 'platform.tenant.update'],
            'bindings.disable' => ['POST', 'tenant-entry-bindings/disable', 'platform.tenant.update'],
            'tenants.license' => ['GET', 'tenants/license', 'platform.tenant.read'],
            'tenants.modules' => ['GET', 'tenants/modules', 'platform.tenant.read'],
            'tenants.license.update' => ['PUT', 'tenants/license', 'platform.tenant.license.manage'],
            'plans.list' => ['GET', 'plans', 'platform.plan.read'],
            'plans.create' => ['POST', 'plans', 'platform.plan.manage'],
            'plans.update' => ['PUT', 'plans', 'platform.plan.manage'],
            'operators.list' => ['GET', 'operators', 'platform.operator.read'],
            'operators.create' => ['POST', 'operators/create', 'platform.operator.create'],
            'operators.update' => ['POST', 'operators/update', 'platform.operator.update'],
            'operators.roles' => ['POST', 'operators/roles/replace', 'platform.operator.role.assign'],
            'operators.activate' => ['POST', 'operators/activate', 'platform.operator.lifecycle'],
            'operators.suspend' => ['POST', 'operators/suspend', 'platform.operator.lifecycle'],
            'operators.close' => ['POST', 'operators/close', 'platform.operator.lifecycle'],
            'roles.list' => ['GET', 'roles', 'platform.role.read'],
            'roles.create' => ['POST', 'roles/create', 'platform.role.create'],
            'roles.update' => ['POST', 'roles/update', 'platform.role.update'],
            'roles.archive' => ['POST', 'roles/archive', 'platform.role.archive'],
            'roles.permissions.replace' => ['POST', 'roles/permissions/replace', 'platform.role.permission.assign'],
            'roles.nodes.prepare' => ['POST', 'roles/nodes/prepare', 'platform.role.read'],
            'roles.nodes.save' => ['POST', 'roles/nodes/save', 'platform.role.permission.assign'],
            'permissions.list' => ['GET', 'permissions', 'platform.permission.read'],
            'bundles.list' => ['GET', 'permission/bundles', 'platform.permission.read'],
            'bundles.create' => ['POST', 'permission/bundles', 'platform.permission.bundle.manage'],
            'bundles.update' => ['PUT', 'permission/bundles', 'platform.permission.bundle.manage'],
            'apis.list' => ['GET', 'permission/apis', 'platform.permission.api.read'],
            'apis.bindings.read' => ['GET', 'permission/apis/bindings', 'platform.permission.api.read'],
            'apis.bindings.update' => ['PUT', 'permission/apis/bindings', 'platform.permission.api.manage'],
            'menus.list' => ['GET', 'permission/menus', 'platform.permission.menu.read'],
            'menus.bindings.update' => ['PUT', 'permission/menus/binding', 'platform.permission.menu.manage'],
            'audit.list' => ['GET', 'audit', 'platform.audit.read'],
            'ops.status' => ['GET', 'v1/ops/status', 'platform.ops.read'],
            'ops.maintenance' => ['GET', 'v1/ops/maintenance', 'platform.ops.read'],
            'ops.backups' => ['GET', 'v1/ops/backups', 'platform.ops.read'],
            'ops.providers' => ['GET', 'v1/ops/providers', 'platform.ops.read'],
            'ops.upgrade-readiness' => ['GET', 'v1/ops/upgrade-readiness', 'platform.ops.read'],
            'ops.upgrades' => ['GET', 'v1/ops/upgrades', 'platform.ops.read'],
            'ops.logs' => ['GET', 'v1/ops/logs', 'platform.ops.logs.read'],
            'ops.diagnostics' => ['GET', 'v1/ops/diagnostics', 'platform.ops.logs.read'],
            'ops.backup' => ['POST', 'v1/ops/tasks/backup', 'platform.ops.backup.manage'],
            'ops.restore' => ['POST', 'v1/ops/tasks/restore', 'platform.ops.restore.manage'],
            'ops.task' => ['GET', 'v1/ops/tasks/{task_key}', 'platform.ops.read'],
            'ops.maintenance.schedule' => ['PUT', 'v1/ops/maintenance', 'platform.ops.maintenance.manage'],
            'ops.maintenance.close' => ['POST', 'v1/ops/maintenance/{maintenance_key}/close', 'platform.ops.maintenance.manage'],
            'ops.upgrade.execute' => ['POST', 'v1/ops/tasks/upgrade', 'platform.ops.upgrade.manage'],
            'developer.catalog' => ['GET', 'developer-center/catalog', 'platform.module.read'],
            'developer.runtime' => ['GET', 'instance-tools/modules', 'platform.module.read'],
            'developer.updates' => ['GET', 'instance-tools/modules/updates', 'platform.module.read'],
            'developer.upload-install' => ['POST', 'instance-tools/modules/install', 'platform.module.install'],
            'developer.upload-update' => ['POST', 'instance-tools/modules/update', 'platform.module.update'],
            'developer.official-install' => ['POST', 'instance-tools/modules/official-install', 'platform.module.install'],
            'developer.official-update' => ['POST', 'instance-tools/modules/official-update', 'platform.module.update'],
            'module.task' => ['GET', 'instance-tools/modules/tasks/{task_key}', 'platform.module.read'],
        ] as $name => [$method, $path, $permission]) {
            $endpoint = $this->installPlatformApi($method, '/platformapi/' . $path, $permission);
            $prerequisites = match ($name) {
                'roles.nodes.prepare' => ['platform.permission.api.read', 'platform.operator.read'],
                'roles.nodes.save' => ['platform.permission.api.read', 'platform.operator.read', 'platform.role.read'],
                default => in_array($permission, ['platform.ops.logs.read', 'platform.ops.upgrade.manage'], true)
                    ? ['platform.ops.read'] : [],
            };
            self::assertSame($prerequisites, $endpoint['prerequisite_permission_keys']);
            $apiIds[$name] = $endpoint['api_id'];
            $insertBinding->execute([15, $apiIds[$name]]);
            // These source-default associations are materialized alongside the custom bundle.
            $sourceBundle = ['platform.role.read' => 1, 'platform.permission.api.read' => 2, 'platform.operator.read' => 3][$permission] ?? null;
            if ($sourceBundle !== null) {
                $insertBinding->execute([$sourceBundle, $apiIds[$name]]);
            }
        }

        $storageNodes = [];
        if ($this->fileCatalogEndpoints !== null) {
            $storageNodes[] = ['platform.storage', null, 'group', null, []];
            foreach (['accounts' => 'account', 'spaces' => 'space', 'routes' => 'route'] as $section => $kind) {
                $endpoint = $this->installPlatformApi(
                    'GET', '/platformapi/infrastructure/storage/' . $section, 'platform.storage.' . $kind . '.read',
                    $this->fileCatalogEndpoints, ['type' => 'module', 'key' => 'peanut.file'],
                );
                self::assertSame([], $endpoint['prerequisite_permission_keys']);
                $name = 'storage.' . $section;
                $apiIds[$name] = $endpoint['api_id'];
                $insertBinding->execute([15, $apiIds[$name]]);
                $storageNodes[] = ['platform.storage.' . $kind . '.list', 'platform.storage', 'page', '/platform/#/storage-' . $section, [$name]];
            }
            foreach ([
                'storage.legacy' => ['GET', '', 'platform.storage.legacy.read'],
                'storage.account.create' => ['POST', '/account', 'platform.ops.maintenance.manage'],
                'storage.space.create' => ['POST', '/space', 'platform.ops.maintenance.manage'],
                'storage.route.update' => ['POST', '/route', 'platform.ops.maintenance.manage'],
            ] as $name => [$method, $suffix, $permission]) {
                $endpoint = $this->installPlatformApi(
                    $method, '/platformapi/infrastructure/storage' . $suffix, $permission,
                    $this->fileCatalogEndpoints, ['type' => 'module', 'key' => 'peanut.file'],
                );
                self::assertSame([], $endpoint['prerequisite_permission_keys']);
                $apiIds[$name] = $endpoint['api_id'];
                $insertBinding->execute([15, $apiIds[$name]]);
            }
            $storageNodes = [
                ...$storageNodes,
                ['platform.storage.legacy.read', 'platform.storage', 'operation', null, ['storage.legacy']],
                ['platform.storage.account.create', 'platform.storage.account.list', 'action', null, ['storage.account.create']],
                ['platform.storage.space.create.fine', 'platform.storage.space.list', 'action', null, ['storage.space.create', 'storage.accounts']],
                ['platform.storage.space.create.legacy', 'platform.storage.space.list', 'action', null, ['storage.space.create', 'storage.legacy']],
                ['platform.storage.route.update.fine', 'platform.storage.route.list', 'action', null, ['storage.route.update', 'storage.spaces']],
                ['platform.storage.route.update.legacy', 'platform.storage.route.list', 'action', null, ['storage.route.update', 'storage.legacy']],
            ];
        }

        $groupKey = 'platform.governance';
        $tenancyKey = 'platform.tenancy';
        $tenantPageKey = 'platform.tenant.list';
        $ownerPageKey = 'platform.tenant.owner-invitation.list';
        $bindingsPageKey = 'platform.tenant.entry-binding.list';
        $entitlementsPageKey = 'platform.tenant.entitlements';
        $planPageKey = 'platform.plan.list';
        $operationsKey = 'platform.operations';
        $developerPageKey = 'platform.developer.catalog';
        $developerRoot = 'platform.developer.tools';
        $developerInstall = 'platform.developer.installations';
        $developerOfficial = 'platform.developer.official';
        $developerOps = 'platform.developer.operations';
        $developerDiagnostics = 'platform.developer.diagnostics';
        $upgradesKey = 'platform.upgrade.status';
        $diagnosticsKey = 'platform.ops.diagnostics';
        $operatorPageKey = 'platform.operator.list';
        $rolePageKey = 'platform.role.list';
        $bundlePageKey = 'platform.permission.bundle.list';
        $menus = array_column(CoreMenuCatalog::definitions(), null, 'key');
        $insertMenu = $this->pdo->prepare('INSERT INTO pa_system_menu ("key",scope,type,status,parent_key,route_path) VALUES (?,?,?,?,?,?)');
        $nodes = [];
        $selected = [];
        foreach ([
            [$tenancyKey, null, 'group', null, []],
            [$tenantPageKey, $tenancyKey, 'page', '/platform/#/tenants', ['tenants.list']],
            ['platform.tenant.create', $tenantPageKey, 'action', null, ['tenants.create']],
            ['platform.tenant.detail', $tenantPageKey, 'action', null, ['tenants.detail']],
            ['platform.tenant.activate', $tenantPageKey, 'action', null, ['tenants.activate']],
            ['platform.tenant.suspend', $tenantPageKey, 'action', null, ['tenants.suspend']],
            ['platform.tenant.close', $tenantPageKey, 'action', null, ['tenants.close']],
            [$ownerPageKey, $tenancyKey, 'page', '/platform/#/owners', ['tenants.list', 'owner.invitations']],
            ['platform.tenant.owner.read', $ownerPageKey, 'operation', null, ['owner.read']],
            ['platform.tenant.owner-invitation.create', $ownerPageKey, 'action', null, ['owner.invite']],
            ['platform.tenant.owner-invitation.resend', $ownerPageKey, 'action', null, ['owner.resend']],
            ['platform.tenant.owner-invitation.revoke', $ownerPageKey, 'action', null, ['owner.revoke']],
            [$bindingsPageKey, $tenancyKey, 'page', '/platform/#/endpoints', ['tenants.list', 'bindings.list']],
            ['platform.tenant.entry-binding.enable', $bindingsPageKey, 'action', null, ['bindings.enable']],
            ['platform.tenant.entry-binding.disable', $bindingsPageKey, 'action', null, ['bindings.disable']],
            [$entitlementsPageKey, $tenancyKey, 'page', '/platform/#/modules', ['tenants.list', 'tenants.license', 'tenants.modules']],
            ['platform.tenant.license.update', $entitlementsPageKey, 'action', null, ['plans.list', 'tenants.license.update']],
            [$planPageKey, $tenancyKey, 'page', '/platform/#/plans', ['plans.list']],
            ['platform.plan.create', $planPageKey, 'action', null, ['developer.runtime', 'bundles.list', 'plans.create']],
            ['platform.plan.update', $planPageKey, 'action', null, ['developer.runtime', 'bundles.list', 'plans.update']],
            [$groupKey, null, 'group', null, []],
            [$operatorPageKey, $groupKey, 'page', '/platform/#/operators', ['operators.list']],
            ['platform.operator.create', $operatorPageKey, 'action', null, ['operators.create']],
            ['platform.operator.update', $operatorPageKey, 'action', null, ['operators.update']],
            ['platform.operator.role.assign', $operatorPageKey, 'action', null, ['roles.list', 'operators.roles']],
            ['platform.operator.activate', $operatorPageKey, 'action', null, ['operators.activate']],
            ['platform.operator.suspend', $operatorPageKey, 'action', null, ['operators.suspend']],
            ['platform.operator.close', $operatorPageKey, 'action', null, ['operators.close']],
            [$rolePageKey, $groupKey, 'page', '/platform/#/roles', ['roles.list']],
            ['platform.role.create', $rolePageKey, 'action', null, ['roles.create']],
            ['platform.role.update', $rolePageKey, 'action', null, ['roles.update']],
            ['platform.role.archive', $rolePageKey, 'action', null, ['roles.archive']],
            ['platform.governance.workbench', $groupKey, 'page', '/platform/#/role-permissions', ['roles.list', 'permissions.list']],
            ['platform.role.nodes.prepare', 'platform.governance.workbench', 'operation', null, ['roles.nodes.prepare']],
            ['platform.role.nodes.save', 'platform.governance.workbench', 'action', null, ['roles.nodes.save']],
            ['platform.role.permission.assign', 'platform.governance.workbench', 'action', null, ['roles.permissions.replace', 'permissions.list']],
            [$bundlePageKey, $groupKey, 'page', '/platform/#/permission-bundles', ['bundles.list']],
            ['platform.permission.bundle.create', $bundlePageKey, 'action', null, ['bundles.create']],
            ['platform.permission.bundle.update', $bundlePageKey, 'action', null, ['bundles.update']],
            ['platform.permission.api.list', $groupKey, 'page', '/platform/#/permission-apis', ['apis.list']],
            ['platform.permission.api.binding.read', 'platform.permission.api.list', 'operation', null, ['apis.bindings.read', 'bundles.list']],
            ['platform.permission.api.binding.update', 'platform.permission.api.list', 'action', null, ['apis.bindings.read', 'bundles.list', 'apis.bindings.update']],
            ['platform.permission.menu.list', $groupKey, 'page', '/platform/#/permission-menus', ['menus.list']],
            ['platform.permission.menu.binding.update', 'platform.permission.menu.list', 'action', null, ['menus.bindings.update', 'bundles.list']],
            ...$storageNodes,
            [$operationsKey, null, 'group', null, []],
            ['platform.audit.list', $operationsKey, 'page', '/platform/#/audit', ['audit.list']],
            ['platform.ops.console', $operationsKey, 'page', '/platform/#/ops', ['ops.status']],
            ['platform.ops.maintenance', $operationsKey, 'page', '/platform/#/maintenance', ['ops.maintenance']],
            ['platform.ops.maintenance.schedule', 'platform.ops.maintenance', 'action', null, ['ops.maintenance.schedule', 'ops.maintenance']],
            ['platform.ops.maintenance.close', 'platform.ops.maintenance', 'action', null, ['ops.maintenance.close', 'ops.maintenance']],
            ['platform.ops.backups', $operationsKey, 'page', '/platform/#/backups', ['ops.backups']],
            ['platform.ops.backup.create', 'platform.ops.backups', 'action', null, ['ops.backup']],
            ['platform.ops.restore.verify', 'platform.ops.backups', 'action', null, ['ops.restore']],
            ['platform.ops.task.read', 'platform.ops.backups', 'operation', null, ['ops.task']],
            ['platform.ops.providers', $operationsKey, 'page', '/platform/#/providers', ['ops.providers']],
            [$upgradesKey, $operationsKey, 'page', '/platform/#/upgrades', []],
            ['platform.upgrade.readiness.read', $upgradesKey, 'operation', null, ['ops.upgrade-readiness']],
            ['platform.upgrade.history.read', $upgradesKey, 'operation', null, ['ops.upgrades']],
            ['platform.ops.upgrade.execute', $upgradesKey, 'action', null, ['ops.upgrade.execute', 'ops.upgrade-readiness', 'ops.upgrades']],
            [$diagnosticsKey, $operationsKey, 'page', '/platform/#/diagnostics', []],
            ['platform.ops.logs.read', $diagnosticsKey, 'operation', null, ['ops.logs']],
            ['platform.ops.diagnostics.download', $diagnosticsKey, 'operation', null, ['ops.diagnostics']],
            [$developerRoot, null, 'group', null, []],
            [$developerPageKey, $developerRoot, 'page', '/platform/#/developer/modules', ['developer.catalog']],
            [$developerInstall, $developerRoot, 'page', '/platform/#/developer/installations', ['developer.runtime']],
            [$developerOfficial, $developerRoot, 'page', '/platform/#/developer/official', ['developer.updates']],
            [$developerOps, $developerRoot, 'page', '/platform/#/developer/operations', ['module.task']],
            [$developerDiagnostics, $developerRoot, 'page', '/platform/#/developer/diagnostics', ['developer.catalog']],
            ['platform.developer.runtime.read', $developerInstall, 'operation', null, ['developer.runtime']],
            ['platform.developer.official-updates.read', $developerOfficial, 'operation', null, ['developer.updates']],
            ['platform.developer.module.upload-install', $developerOps, 'action', null, ['developer.upload-install']],
            ['platform.developer.module.upload-update', $developerOps, 'action', null, ['developer.upload-update']],
            ['platform.developer.module.official-install', $developerOps, 'action', null, ['developer.official-install']],
            ['platform.developer.module.official-update', $developerOps, 'action', null, ['developer.official-update']],
            ['platform.module.operation-task.read', $developerOps, 'operation', null, ['module.task']],
            ['platform.role.authorization.preview', null, 'operation', null, ['roles.preview', 'operators.list', 'roles.list', 'apis.list']],
            ['platform.role.authorization.catalog-audit', null, 'operation', null, ['roles.catalog-audit', 'operators.list', 'roles.list', 'apis.list']],
        ] as [$key, $parent, $type, $routePath, $endpoints]) {
            if ($type === 'group' || $type === 'page') {
                self::assertArrayHasKey($key, $menus);
                $menu = $menus[$key];
                self::assertSame([$parent, $type, $routePath, 'platform', ['platform-web']],
                    [$menu->parentKey, $menu->type, $menu->routePath, $menu->scope, $menu->clientKeys]);
                $insertMenu->execute([$menu->key, $menu->scope, $menu->type, 'active', $menu->parentKey, $menu->routePath]);
            } elseif ($type === 'operation') {
                self::assertArrayNotHasKey($key, $menus);
            }
            $nodes[] = [
                'key' => $key, 'parent_key' => $parent, 'type' => $type,
                'api_ids' => array_map(static fn(string $endpoint): string => $apiIds[$endpoint], $endpoints),
            ];
            if ($endpoints !== []) {
                $selected[] = $key;
            }
        }

        return [
            'operator_id' => '55',
            'nodes' => $nodes,
            'role_assignments' => [['role_id' => '501', 'node_keys' => $selected]],
        ];
    }

    private function installPlatformApi(
        string $method,
        string $path,
        string $permission,
        ?array $catalog = null,
        array $owner = ['type' => 'application', 'key' => 'platform'],
    ): array {
        $matches = array_values(array_filter($catalog ?? $this->catalogEndpoints, static fn(array $endpoint): bool =>
            $endpoint['method'] === $method && $endpoint['path'] === $path));
        self::assertCount(1, $matches, $method . ' ' . $path);
        $endpoint = $matches[0];
        self::assertSame('platform', $endpoint['audience']);
        self::assertSame('platform_permission', $endpoint['auth']['mode']);
        self::assertSame($owner, $endpoint['owner']);
        self::assertSame('complete', $endpoint['contract_quality']);
        self::assertSame([$permission], $endpoint['default_permission_keys']);
        $this->pdo->prepare('INSERT INTO pa_system_api VALUES (?,?,?,?,?,?,?,?,?)')->execute([
            $endpoint['api_id'], $endpoint['method'], $endpoint['path'], $endpoint['audience'], $endpoint['auth']['mode'], 'active', $endpoint['owner']['key'],
            json_encode($endpoint['default_permission_keys'], JSON_THROW_ON_ERROR),
            json_encode($endpoint['prerequisite_permission_keys'], JSON_THROW_ON_ERROR),
        ]);
        return $endpoint;
    }

    private function assertDenied(int $status, ?DemoAccountPolicy $demo = null, ?array $candidate = null, bool $catalog = false): void
    {
        $before = $this->snapshot();
        try {
            $this->call($candidate ?? \PlatformRoleAuthorizationPreviewFixture::exactCandidate(), 'platform', $demo, $catalog);
            self::fail('The native request must be denied.');
        } catch (ApiProblem $problem) {
            self::assertSame($status, $problem->httpStatus);
        }
        self::assertSame(0, $this->continuations);
        self::assertSame($before, $this->snapshot());
        self::assertTrue($this->store->isEmpty());
    }

    private function call(array $candidate, string $identity = 'platform', ?DemoAccountPolicy $demo = null, bool $catalogAudit = false): \think\response\Json
    {
        $path = $catalogAudit ? 'roles/permissions/catalog-audit' : 'roles/permissions/preview';
        $endpoint = $catalogAudit ? $this->auditEndpoint : $this->endpoint;
        $request = (new Request())->withServer(['REQUEST_METHOD' => 'POST'])->withPost($candidate);
        $request->setPathinfo($path);
        $request->setRule((new Route($this->app))->rule($path, static fn() => null, 'POST'));
        $this->app->instance('request', $request);
        $this->app->instance(Request::class, $request);
        $repository = new ThinkPhpPlatformAuthorizationRepository();
        $evaluator = new PlatformAuthorizationEvaluator($repository, new RevisionPermissionCache());
        $catalog = new ApiPermissionCatalog();
        $grant = new EndpointPermissionGrant($request);
        $authorization = new PlatformApiAuthorization($evaluator, $grant);
        $sessions = new PlatformOperatorSessionService(
            (new ReflectionClass(PlatformAuthService::class))->newInstanceWithoutConstructor(),
            $authorization,
            $repository,
        );
        $queries = new PlatformControlPlaneQueryService(
            $sessions,
            $this->createStub(ModuleQualificationQuery::class),
            new PlatformDirectoryQueries($authorization),
        );
        $demo ??= new DemoAccountPolicy(false, []);
        $preview = new PlatformRoleAuthorizationPreview($evaluator, $repository, $catalog, $demo);
        $this->app->instance(PlatformRoleAuthorizationPreview::class, $preview);
        $this->app->instance(ModuleAvailability::class, $this->modules);
        $middleware = new PlatformPermissionMiddleware($this->current, $sessions, $demo, $catalog, $grant, $this->modules);
        $next = function (Request $request) use ($queries, $catalogAudit): \think\response\Json {
            ++$this->continuations;
            $controller = new PlatformControlPlaneQueryController($this->app, $queries);
            return $this->app->invokeMethod([$controller, $catalogAudit ? 'auditRoleCatalog' : 'previewRolePermissions']);
        };
        foreach (array_reverse($endpoint['middleware']) as $entry) {
            if ($entry['class'] !== PlatformPermissionMiddleware::class) {
                continue; // Login is represented by a validated synthetic session, never bypassed in production.
            }
            $continuation = $next;
            $permission = $entry['arguments'][0];
            $next = fn(Request $request) => $middleware->handle($request, $continuation, $permission);
        }
        if ($identity === 'none') {
            return $next($request);
        }
        if ($identity === 'tenant') {
            $tenant = TenantContext::fromValidatedSession(new ValidatedTenantSession(
                7, 'fixture-tenant-session', 101, 7007, 7, 'admin-web', new DateTimeImmutable('2031-01-01'), 1, 1,
            ), 'fixture-preview-tenant');
            $context = new AdminExecutionContext($tenant, 'test.platform-preview', [
                'id' => 7, 'tenant_id' => 101, 'account_id' => 7007, 'authorization_revision' => 1, 'root' => 1,
            ]);
        } else {
            $platform = PlatformOperatorContext::fromValidatedPlatformSession(PlatformContext::fromValidatedSession(
                new ValidatedPlatformSession(1, 'fixture-platform-session', 111, 11, 'platform-web', new DateTimeImmutable('2031-01-01')),
                'fixture-preview-platform',
            ));
            $context = new PlatformExecutionContext($platform, 'test.platform-preview');
        }
        return $this->store->run($context, fn() => $next($request));
    }

    private function snapshot(): array
    {
        $result = [];
        foreach ($this->pdo->query("SELECT name FROM sqlite_master WHERE type='table' ORDER BY name")->fetchAll(PDO::FETCH_COLUMN) as $table) {
            $result[$table] = $this->pdo->query('SELECT * FROM "' . $table . '" ORDER BY rowid')->fetchAll(PDO::FETCH_ASSOC);
        }
        return $result;
    }
}
