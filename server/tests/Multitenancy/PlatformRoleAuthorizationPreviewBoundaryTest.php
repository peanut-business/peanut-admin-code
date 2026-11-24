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
    private array $endpoint;
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
        $catalogPath = getenv('PEANUT_PLATFORM_PREVIEW_API_CATALOG') ?: $root . '/server/generated/api-catalog.json';
        $catalog = json_decode(file_get_contents($catalogPath), true, 512, JSON_THROW_ON_ERROR);
        $this->catalogEndpoints = $catalog['endpoints'];
        $this->endpoint = $this->installPlatformApi('POST', '/platformapi/roles/permissions/preview', 'platform.role.read');
        self::assertSame(['platform.permission.api.read', 'platform.operator.read'], $this->endpoint['prerequisite_permission_keys']);
        self::assertSame(PlatformControlPlaneQueryController::class, $this->endpoint['controller']);
        self::assertSame('previewRolePermissions', $this->endpoint['action']);
        $this->pdo->prepare('INSERT INTO pa_permission_api (permission_id,api_id) VALUES (?,?)')->execute([1, $this->endpoint['api_id']]);
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
        $candidate = $this->platformSourceCandidate();
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
    }

    public function testExplicitEmptyPlatformSelectionReportsRemovedApisAndOmissionIsDiagnosed(): void
    {
        $candidate = $this->platformSourceCandidate();
        $removed = array_values(array_unique(array_merge(...array_column($candidate['nodes'], 'api_ids'))));
        sort($removed, SORT_STRING);
        self::assertCount(18, $removed);
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
        $this->pdo->exec('INSERT INTO pa_platform_role_permission VALUES (501,15)');

        $insertBinding = $this->pdo->prepare('INSERT INTO pa_permission_api (permission_id,api_id) VALUES (?,?)');
        $apiIds = [];
        foreach ([
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
            'permissions.list' => ['GET', 'permissions', 'platform.permission.read'],
            'bundles.list' => ['GET', 'permission/bundles', 'platform.permission.read'],
            'bundles.create' => ['POST', 'permission/bundles', 'platform.permission.bundle.manage'],
            'bundles.update' => ['PUT', 'permission/bundles', 'platform.permission.bundle.manage'],
            'apis.list' => ['GET', 'permission/apis', 'platform.permission.api.read'],
            'menus.list' => ['GET', 'permission/menus', 'platform.permission.menu.read'],
            'module.task' => ['GET', 'instance-tools/modules/tasks/{task_key}', 'platform.module.read'],
        ] as $name => [$method, $path, $permission]) {
            $endpoint = $this->installPlatformApi($method, '/platformapi/' . $path, $permission);
            $apiIds[$name] = $endpoint['api_id'];
            $insertBinding->execute([15, $apiIds[$name]]);
        }

        $groupKey = 'platform.governance';
        $operatorPageKey = 'platform.operator.list';
        $rolePageKey = 'platform.role.list';
        $bundlePageKey = 'platform.permission.bundle.list';
        $menus = array_column(CoreMenuCatalog::definitions(), null, 'key');
        $insertMenu = $this->pdo->prepare('INSERT INTO pa_system_menu ("key",scope,type,status) VALUES (?,?,?,?)');
        $nodes = [];
        $selected = [];
        foreach ([
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
            [$bundlePageKey, $groupKey, 'page', '/platform/#/permission-bundles', ['bundles.list']],
            ['platform.permission.bundle.create', $bundlePageKey, 'action', null, ['bundles.create']],
            ['platform.permission.bundle.update', $bundlePageKey, 'action', null, ['bundles.update']],
            ['platform.permission.api.list', $groupKey, 'page', '/platform/#/permission-apis', ['apis.list']],
            ['platform.permission.menu.list', $groupKey, 'page', '/platform/#/permission-menus', ['menus.list']],
            ['platform.module.operation-task.read', null, 'operation', null, ['module.task']],
        ] as [$key, $parent, $type, $routePath, $endpoints]) {
            if ($type === 'group' || $type === 'page') {
                self::assertArrayHasKey($key, $menus);
                $menu = $menus[$key];
                self::assertSame([$parent, $type, $routePath, 'platform', ['platform-web']],
                    [$menu->parentKey, $menu->type, $menu->routePath, $menu->scope, $menu->clientKeys]);
                $insertMenu->execute([$menu->key, $menu->scope, $menu->type, 'active']);
            } elseif ($type === 'operation') {
                self::assertArrayNotHasKey($key, $menus);
            }
            $nodes[] = [
                'key' => $key, 'parent_key' => $parent, 'type' => $type,
                'api_ids' => array_map(static fn(string $endpoint): string => $apiIds[$endpoint], $endpoints),
            ];
            if ($type !== 'group') {
                $selected[] = $key;
            }
        }

        return [
            'operator_id' => '55',
            'nodes' => $nodes,
            'role_assignments' => [['role_id' => '501', 'node_keys' => $selected]],
        ];
    }

    private function installPlatformApi(string $method, string $path, string $permission): array
    {
        $matches = array_values(array_filter($this->catalogEndpoints, static fn(array $endpoint): bool =>
            $endpoint['method'] === $method && $endpoint['path'] === $path));
        self::assertCount(1, $matches, $method . ' ' . $path);
        $endpoint = $matches[0];
        self::assertSame('platform', $endpoint['audience']);
        self::assertSame('platform_permission', $endpoint['auth']['mode']);
        self::assertSame(['type' => 'application', 'key' => 'platform'], $endpoint['owner']);
        self::assertSame('complete', $endpoint['contract_quality']);
        self::assertSame([$permission], $endpoint['default_permission_keys']);
        $this->pdo->prepare('INSERT INTO pa_system_api VALUES (?,?,?,?,?,?,?,?,?)')->execute([
            $endpoint['api_id'], $endpoint['method'], $endpoint['path'], $endpoint['audience'], $endpoint['auth']['mode'], 'active', $endpoint['owner']['key'],
            json_encode($endpoint['default_permission_keys'], JSON_THROW_ON_ERROR),
            json_encode($endpoint['prerequisite_permission_keys'], JSON_THROW_ON_ERROR),
        ]);
        return $endpoint;
    }

    private function assertDenied(int $status, ?DemoAccountPolicy $demo = null, ?array $candidate = null): void
    {
        $before = $this->snapshot();
        try {
            $this->call($candidate ?? \PlatformRoleAuthorizationPreviewFixture::exactCandidate(), 'platform', $demo);
            self::fail('The native request must be denied.');
        } catch (ApiProblem $problem) {
            self::assertSame($status, $problem->httpStatus);
        }
        self::assertSame(0, $this->continuations);
        self::assertSame($before, $this->snapshot());
        self::assertTrue($this->store->isEmpty());
    }

    private function call(array $candidate, string $identity = 'platform', ?DemoAccountPolicy $demo = null): \think\response\Json
    {
        $request = (new Request())->withServer(['REQUEST_METHOD' => 'POST'])->withPost($candidate);
        $request->setPathinfo('roles/permissions/preview');
        $request->setRule((new Route($this->app))->rule('roles/permissions/preview', static fn() => null, 'POST'));
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
        $next = function (Request $request) use ($queries): \think\response\Json {
            ++$this->continuations;
            $controller = new PlatformControlPlaneQueryController($this->app, $queries);
            return $this->app->invokeMethod([$controller, 'previewRolePermissions']);
        };
        foreach (array_reverse($this->endpoint['middleware']) as $entry) {
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
