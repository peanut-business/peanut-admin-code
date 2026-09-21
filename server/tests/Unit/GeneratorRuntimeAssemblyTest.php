<?php
declare(strict_types=1);

namespace tests\Unit\GeneratorRuntimeAssembly;

use app\adminapi\services\generator\GeneratorRenderService;
use app\common\contract\authorization\AdminAuthorizationQuery;
use app\common\exception\BusinessException;
use app\common\execution\AdminExecutionContext;
use app\common\execution\CurrentExecutionContext;
use app\common\execution\ExecutionContextStore;
use app\common\http\PageResult;
use app\common\validate\InputValidator;
use DateTimeImmutable;
use PeanutAdmin\Kernel\Auth\TenantContext;
use PeanutAdmin\Kernel\Auth\ValidatedTenantSession;
use PHPUnit\Framework\TestCase;
use think\App;
use think\Container;
use think\Request;
use think\Route;

final class GeneratorRuntimeAssemblyTest extends TestCase
{
    private Container $previousContainer;
    private string $temporary;

    protected function setUp(): void
    {
        require_once dirname(__DIR__, 2) . '/vendor/topthink/framework/src/helper.php';
        $this->previousContainer = Container::getInstance();
        $this->temporary = sys_get_temp_dir() . '/peanut-generated-runtime-' . bin2hex(random_bytes(8));
        self::assertTrue(mkdir($this->temporary, 0775, true));
    }

    protected function tearDown(): void
    {
        Container::setInstance($this->previousContainer);
        $this->removeTree($this->temporary);
    }

    public function testGeneratedModuleLoadsThroughContainerAndDispatchesTypedCrudResponse(): void
    {
        $files = GeneratorRenderService::render(self::definition());
        $byPath = array_column($files, null, 'path');
        foreach ($files as $file) {
            $path = $this->temporary . '/' . $file['path'];
            self::assertTrue(is_dir(dirname($path)) || mkdir(dirname($path), 0775, true));
            self::assertNotFalse(file_put_contents($path, $file['content']));
        }

        $backend = 'server/app/modules/fixture/delivery_record';
        foreach ([
            '/src/Model/RuntimeNote.php',
            '/src/Service/RuntimeNoteService.php',
            '/src/Validation/RuntimeNoteValidate.php',
            '/src/Controller/RuntimeNoteController.php',
        ] as $relative) {
            require_once $this->temporary . '/' . $backend . $relative;
        }

        $routeFile = $this->temporary . '/' . $backend . '/route/generated/runtime-note.php';
        require_once dirname(__DIR__, 2) . '/route/registry_source.php';
        if (!class_exists('think\\facade\\Route', false)) {
            self::assertTrue(class_alias(\PeanutRouteInventoryRoute::class, 'think\\facade\\Route'));
        }
        \PeanutRouteInventoryRoute::reset((string)realpath($this->temporary . '/server'));
        \PeanutRouteInventoryRoute::beginApplication('adminapi', 'adminapi');
        $peanutRouteApplication = 'adminapi';
        require $routeFile;
        $endpoints = \PeanutRouteInventoryRoute::endpoints();
        self::assertCount(5, $endpoints);
        self::assertSame('/adminapi/fixture.delivery-record.runtime-note.list', $endpoints[0]['path']);
        self::assertSame('fixture.delivery-record.runtime-note.list', $endpoints[0]['permission']);

        $openApi = require $this->temporary . '/' . $backend . '/api/metadata/openapi.php';
        self::assertArrayHasKey('/adminapi/fixture.delivery-record.runtime-note.list', $openApi['paths']);
        self::assertArrayNotHasKey('tenant_id', $openApi['components']['schemas']['RuntimeNoteDetail']['properties']);
        self::assertArrayNotHasKey('api_secret', $openApi['components']['schemas']['RuntimeNoteDetail']['properties']);
        self::assertSame(
            ['title', 'body', 'api_secret'],
            array_keys($openApi['components']['schemas']['RuntimeNoteCreateRequest']['properties']),
        );
        self::assertSame(
            '#/components/schemas/RuntimeNoteUpdateRequest',
            $openApi['paths']['/adminapi/fixture.delivery-record.runtime-note.edit']['post']['requestBody']['content']['application/json']['schema']['$ref'],
        );
        $listParameters = array_column(
            $openApi['paths']['/adminapi/fixture.delivery-record.runtime-note.list']['get']['parameters'],
            'schema',
            'name',
        );
        self::assertSame('string', $listParameters['title']['type']);
        self::assertSame(['type' => 'integer', 'minimum' => 1], $listParameters['page_no']);
        self::assertSame(100, $listParameters['page_size']['maximum']);

        $app = new App($this->temporary . '/runtime-app');
        $app->config->set(require dirname(__DIR__, 2) . '/config/route.php', 'route');
        $app->config->set(require dirname(__DIR__, 2) . '/config/app.php', 'app');
        $app->config->set([
            'default' => 'file',
            'stores' => ['file' => ['type' => 'File', 'path' => $this->temporary . '/cache', 'prefix' => 'generated-test:']],
        ], 'cache');
        // A non-routable placeholder lets ThinkORM construct a Model; the injected
        // BeforeWrite=false event below returns before any connection is opened.
        $app->config->set([
            'default' => 'mysql',
            'auto_timestamp' => false,
            'connections' => ['mysql' => [
                'type' => 'mysql', 'hostname' => 'invalid.local', 'database' => 'unused',
                'username' => 'unused', 'password' => 'unused', 'hostport' => '3306',
                'charset' => 'utf8mb4', 'prefix' => 'pa_',
            ]],
        ], 'database');
        $store = new ExecutionContextStore();
        $current = new CurrentExecutionContext($store);
        $app->instance(App::class, $app);
        $app->instance(ExecutionContextStore::class, $store);
        $app->instance(CurrentExecutionContext::class, $current);
        $app->instance(InputValidator::class, new InputValidator($app, $current));
        $authorization = $this->createStub(AdminAuthorizationQuery::class);
        $app->instance(AdminAuthorizationQuery::class, $authorization);

        $realService = $app->make(\PeanutAdmin\Fixtures\DeliveryRecord\Service\RuntimeNoteService::class, [], true);
        self::assertInstanceOf(\PeanutAdmin\Fixtures\DeliveryRecord\Service\RuntimeNoteService::class, $realService);
        $projection = new \ReflectionMethod($realService, 'project');
        self::assertSame(
            ['uuid' => 'note-A', 'title' => 'visible'],
            $projection->invoke($realService, [
                'uuid' => 'note-A', 'title' => 'visible', 'tenant_id' => 101, 'api_secret' => 'never-public',
            ], ['uuid', 'title']),
        );

        $failingModel = new class extends \PeanutAdmin\Fixtures\DeliveryRecord\Model\RuntimeNote {
            protected function getOptions(): array
            {
                return parent::getOptions() + ['schema' => ['uuid' => 'string', 'title' => 'string']];
            }
        };
        $failingModel->setOption('event', new class {
            public function trigger(string $event, object $model): array
            {
                return [false];
            }
        });
        self::assertFalse($failingModel->save(['title' => 'blocked']), 'ThinkORM hooks can return false without an exception');

        try {
            $realService->lists($this->adminContext()->tenant, []);
            self::fail('A non-HTTP call without a trusted current identity must be denied before persistence.');
        } catch (BusinessException $exception) {
            self::assertSame('RUNTIME_NOTE_PERMISSION_DENIED', $exception->errorCode);
            self::assertSame(403, $exception->httpStatus);
        }

        $stub = new class extends \PeanutAdmin\Fixtures\DeliveryRecord\Service\RuntimeNoteService {
            public array $lastList = [];
            public array $lastAdd = [];

            public function __construct() {}

            public function lists(TenantContext $context, array $params): array|PageResult
            {
                $this->lastList = $params;
                return new PageResult([['uuid' => 'note-A', 'title' => 'visible']], 1, 1, 20);
            }

            public function add(TenantContext $context, array $params): bool
            {
                $this->lastAdd = $params;
                return true;
            }
        };
        $app->instance(\PeanutAdmin\Fixtures\DeliveryRecord\Service\RuntimeNoteService::class, $stub);

        $route = new Route($app);
        $route->get('fixture.delivery-record.runtime-note.list', [
            \PeanutAdmin\Fixtures\DeliveryRecord\Controller\RuntimeNoteController::class,
            'lists',
        ]);
        $route->post('fixture.delivery-record.runtime-note.add', [
            \PeanutAdmin\Fixtures\DeliveryRecord\Controller\RuntimeNoteController::class,
            'add',
        ]);

        $listRequest = $this->request('GET', 'fixture.delivery-record.runtime-note.list')
            ->withGet(['title' => 'visible', 'page_no' => 1]);
        $app->instance(Request::class, $listRequest);
        $listResponse = $store->run($this->adminContext(), static fn() => $route->dispatch($listRequest));
        self::assertSame(200, $listResponse->getCode());
        self::assertSame('note-A', $listResponse->getData()['data']['lists'][0]['uuid']);
        self::assertSame(['title' => 'visible', 'page_no' => 1], $stub->lastList);

        $addRequest = $this->request('POST', 'fixture.delivery-record.runtime-note.add')
            ->withPost(['title' => 'created', 'body' => 'body']);
        $app->instance(Request::class, $addRequest);
        $addResponse = $store->run($this->adminContext(), static fn() => $route->dispatch($addRequest));
        self::assertSame(200, $addResponse->getCode());
        self::assertSame(20000, $addResponse->getData()['code']);
        self::assertSame(['title' => 'created', 'body' => 'body'], $stub->lastAdd);

        self::assertSame('create', $byPath[$backend . '/src/Controller/RuntimeNoteController.php']['operation']);
        self::assertSame('merge', $byPath[$backend . '/route/app.php']['operation']);
    }

    public function testExplicitSoftDeleteRoutesDispatchTypedPurgeThroughContainer(): void
    {
        $files = GeneratorRenderService::render(self::softDeleteDefinition());
        foreach ($files as $file) {
            $path = $this->temporary . '/' . $file['path'];
            self::assertTrue(is_dir(dirname($path)) || mkdir(dirname($path), 0775, true));
            self::assertNotFalse(file_put_contents($path, $file['content']));
        }

        $backend = 'server/app/modules/fixture/delivery_record';
        foreach ([
            '/src/Model/SoftRuntimeNote.php',
            '/src/Service/SoftRuntimeNoteService.php',
            '/src/Validation/SoftRuntimeNoteValidate.php',
            '/src/Controller/SoftRuntimeNoteController.php',
        ] as $relative) {
            require_once $this->temporary . '/' . $backend . $relative;
        }

        require_once dirname(__DIR__, 2) . '/route/registry_source.php';
        if (!class_exists('think\\facade\\Route', false)) {
            self::assertTrue(class_alias(\PeanutRouteInventoryRoute::class, 'think\\facade\\Route'));
        }
        \PeanutRouteInventoryRoute::reset((string)realpath($this->temporary . '/server'));
        \PeanutRouteInventoryRoute::beginApplication('adminapi', 'adminapi');
        $peanutRouteApplication = 'adminapi';
        require $this->temporary . '/' . $backend . '/route/generated/soft-runtime-note.php';
        $endpoints = \PeanutRouteInventoryRoute::endpoints();
        self::assertCount(9, $endpoints);
        $mapped = array_column($endpoints, 'permission', 'path');
        self::assertSame(
            'fixture.delivery-record.soft-runtime-note.purge',
            $mapped['/adminapi/fixture.delivery-record.soft-runtime-note.purge'],
        );

        $app = new App($this->temporary . '/soft-runtime-app');
        $app->config->set(require dirname(__DIR__, 2) . '/config/route.php', 'route');
        $app->config->set(require dirname(__DIR__, 2) . '/config/app.php', 'app');
        $store = new ExecutionContextStore();
        $current = new CurrentExecutionContext($store);
        $app->instance(App::class, $app);
        $app->instance(ExecutionContextStore::class, $store);
        $app->instance(CurrentExecutionContext::class, $current);
        $app->instance(InputValidator::class, new InputValidator($app, $current));

        $stub = new class extends \PeanutAdmin\Fixtures\DeliveryRecord\Service\SoftRuntimeNoteService {
            public array $lastPurge = [];

            public function __construct() {}

            public function purge(TenantContext $context, array $ids): array
            {
                $this->lastPurge = $ids;
                return ['requested' => $ids, 'purged' => $ids, 'already_active' => [], 'failed' => []];
            }
        };
        $app->instance(\PeanutAdmin\Fixtures\DeliveryRecord\Service\SoftRuntimeNoteService::class, $stub);

        $route = new Route($app);
        $route->post('fixture.delivery-record.soft-runtime-note.purge', [
            \PeanutAdmin\Fixtures\DeliveryRecord\Controller\SoftRuntimeNoteController::class,
            'purge',
        ]);
        $request = $this->request('POST', 'fixture.delivery-record.soft-runtime-note.purge')
            ->withPost(['ids' => ['note-A', 'note-B', 'note-A']]);
        $app->instance(Request::class, $request);
        $response = $store->run($this->adminContext(), static fn() => $route->dispatch($request));
        self::assertSame(200, $response->getCode());
        self::assertSame(['note-A', 'note-B'], $stub->lastPurge);
        self::assertSame(['note-A', 'note-B'], $response->getData()['data']['purged']);
    }

    /** @return array<string,mixed> */
    private static function definition(): array
    {
        return [
            'table_name' => 'pa_fixture_runtime_note',
            'table_comment' => '运行期生成记录',
            'module_name' => 'fixture.delivery-record',
            'entity_name' => 'RuntimeNote',
            'data_owner' => 'tenant-orm',
            'target_edition' => 'multi-tenant',
            'columns' => [
                ['column_name' => 'uuid', 'php_type' => 'string', 'column_type' => 'varchar(36)', 'is_pk' => true, 'is_required' => true, 'is_lists' => true],
                ['column_name' => 'tenant_id', 'php_type' => 'int', 'is_required' => true],
                ['column_name' => 'title', 'php_type' => 'string', 'column_type' => 'varchar(100)', 'is_required' => true, 'is_insert' => true, 'is_update' => true, 'is_lists' => true, 'is_query' => true],
                ['column_name' => 'body', 'php_type' => 'string', 'is_insert' => true, 'is_update' => true],
                ['column_name' => 'api_secret', 'php_type' => 'string', 'is_insert' => true, 'is_update' => true, 'is_lists' => true],
            ],
        ];
    }

    /** @return array<string,mixed> */
    private static function softDeleteDefinition(): array
    {
        $definition = self::definition();
        $definition['table_name'] = 'pa_fixture_soft_runtime_note';
        $definition['entity_name'] = 'SoftRuntimeNote';
        $definition['soft_delete'] = ['enabled' => true, 'field' => 'removed_at'];
        $definition['columns'][] = ['column_name' => 'removed_at', 'php_type' => 'int'];
        return $definition;
    }

    private function request(string $method, string $path): Request
    {
        return (new Request())->withServer([
            'REQUEST_METHOD' => $method,
            'REQUEST_URI' => '/' . $path,
            'HTTP_HOST' => 'fixture.invalid',
            'SERVER_NAME' => 'fixture.invalid',
            'SERVER_PORT' => '80',
            'SCRIPT_NAME' => '/index.php',
        ])->setPathinfo($path);
    }

    private function adminContext(): AdminExecutionContext
    {
        $tenant = TenantContext::fromValidatedSession(new ValidatedTenantSession(
            301,
            'generated-runtime-session',
            101,
            201,
            301,
            'admin-web',
            new DateTimeImmutable('2031-01-01T00:00:00Z'),
            1,
        ), 'generated-runtime-request');
        return new AdminExecutionContext($tenant, 'generated-runtime', [
            'id' => 301,
            'tenant_id' => 101,
            'account_id' => 201,
        ]);
    }

    private function removeTree(string $directory): void
    {
        if (!is_dir($directory)) return;
        foreach (scandir($directory) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') continue;
            $path = $directory . '/' . $entry;
            if (is_dir($path) && !is_link($path)) {
                $this->removeTree($path);
            } else {
                unlink($path);
            }
        }
        rmdir($directory);
    }
}
