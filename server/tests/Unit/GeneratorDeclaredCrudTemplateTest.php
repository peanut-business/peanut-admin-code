<?php

declare(strict_types=1);

namespace tests\Unit;

use app\adminapi\services\generator\GeneratorRenderService;
use app\common\validate\ListsValidate;
use Tests\Support\GeneratorFixtureApplication;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class GeneratorDeclaredCrudTemplateTest extends TestCase
{
    private GeneratorFixtureApplication $fixtureApplication;

    protected function setUp(): void
    {
        require_once dirname(__DIR__) . '/Support/GeneratorFixtureApplication.php';
        $this->fixtureApplication = new GeneratorFixtureApplication();
    }

    protected function tearDown(): void
    {
        unset($this->fixtureApplication);
    }

    public function testGeneratedCrudUsesRegisteredModuleAndCompleteAssemblyContracts(): void
    {
        $hostRoutePath = $this->fixtureApplication->root . '/server/app/adminapi/route/app.php';
        $hostRouteSource = file_get_contents($hostRoutePath) . "\n// Application-owned host customization.\n";
        self::assertNotFalse(file_put_contents($hostRoutePath, $hostRouteSource));
        $files = GeneratorRenderService::render(self::definition(), $this->fixtureApplication->root);
        $byPath = array_column($files, null, 'path');

        $backend = 'server/app/modules/fixture/delivery_record';
        $frontend = 'web/src/modules/fixture-delivery-record';
        self::assertArrayHasKey($backend . '/src/Model/GeneratedNote.php', $byPath);
        self::assertArrayHasKey($backend . '/src/Service/GeneratedNoteService.php', $byPath);
        self::assertArrayHasKey($backend . '/src/Controller/GeneratedNoteController.php', $byPath);
        self::assertArrayHasKey($backend . '/src/Validation/GeneratedNoteValidate.php', $byPath);
        self::assertArrayHasKey($frontend . '/generated/generated-note/api.ts', $byPath);
        self::assertArrayHasKey($frontend . '/generated/generated-note/index.vue', $byPath);
        self::assertArrayHasKey($backend . '/route/generated/generated-note.php', $byPath);
        self::assertArrayHasKey($backend . '/api/metadata/generated/generated-note.php', $byPath);

        self::assertStringContainsString(
            "protected \$table = 'pa_fixture_generated_note';",
            $byPath[$backend . '/src/Model/GeneratedNote.php']['content'],
        );

        $controller = $byPath[$backend . '/src/Controller/GeneratedNoteController.php']['content'];
        self::assertStringContainsString('namespace PeanutAdmin\\Fixtures\\DeliveryRecord\\Controller;', $controller);
        self::assertStringContainsString("CRUD_PRIMARY_KEY = 'uuid'", $controller);
        self::assertStringContainsString("CRUD_PRIMARY_KEY_TYPE = 'string'", $controller);
        self::assertStringContainsString('CRUD_WRITABLE_FIELDS', $controller);

        $service = $byPath[$backend . '/src/Service/GeneratedNoteService.php']['content'];
        self::assertStringContainsString('extends AbstractCrudService', $service);
        self::assertStringContainsString('protected function query(TenantContext $context): BaseQuery', $service);
        self::assertStringContainsString("where('tenant_id', \$context->tenantId)", $service);
        self::assertStringContainsString('protected function configureListQuery(', $service);
        self::assertStringContainsString('protected function configureDetailQuery(', $service);
        self::assertStringContainsString('private const LIST_FIELDS', $service);
        self::assertStringContainsString('private const DETAIL_FIELDS', $service);
        self::assertStringContainsString('array_intersect_key($data, array_flip($fields))', $service);
        self::assertStringContainsString("GENERATED_NOTE_SAVE_FAILED", $service);
        self::assertStringContainsString("GENERATED_NOTE_DELETE_FAILED", $service);
        self::assertStringNotContainsString("'tenant_id',\n", self::constantBlock($service, 'LIST_FIELDS'));
        self::assertStringNotContainsString('api_secret', self::constantBlock($service, 'DETAIL_FIELDS'));

        $api = $byPath[$frontend . '/generated/generated-note/api.ts']['content'];
        self::assertStringContainsString('uuid: string;', $api);
        self::assertStringContainsString('title: string;', $api);
        self::assertStringNotContainsString('tenant_id', $api);
        self::assertStringNotContainsString('delete_time', $api);
        self::assertStringNotContainsString('api_secret', self::interfaceBlock($api, 'GeneratedNoteListRecord'));
        self::assertStringNotContainsString('api_secret', self::interfaceBlock($api, 'GeneratedNoteDetail'));
        self::assertStringContainsString('api_secret', self::interfaceBlock($api, 'GeneratedNoteCreateInput'));

        $route = $byPath[$backend . '/route/generated/generated-note.php']['content'];
        self::assertStringContainsString("fixture.delivery-record.generated-note.list", $route);
        self::assertStringContainsString('OfficialModuleMiddleware::class', $route);
        self::assertStringContainsString('AuthMiddleware::class', $route);
        self::assertStringNotContainsString('recycle', $route, 'delete_time alone must not publish recycle actions');

        $permissionMerge = $byPath[$backend . '/resources/permissions.json'];
        self::assertSame('merge', $permissionMerge['operation']);
        self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $permissionMerge['base_sha256']);
        $permissions = json_decode($permissionMerge['content'], true, 64, JSON_THROW_ON_ERROR);
        self::assertContains('fixture.delivery-record.generated-note.delete', array_column($permissions, 'key'));
        self::assertNotContains('fixture.delivery-record.generated-note.restore', array_column($permissions, 'key'));

        $manifest = json_decode($byPath[$backend . '/module.json']['content'], true, 64, JSON_THROW_ON_ERROR);
        self::assertContains('pa_fixture_generated_note', $manifest['database']['owned_tables']);
        self::assertSame('merge', $byPath[$backend . '/route/app.php']['operation']);
        self::assertStringContainsString("require __DIR__ . '/generated/generated-note.php';", $byPath[$backend . '/route/app.php']['content']);
        self::assertArrayNotHasKey('server/app/adminapi/route/app.php', $byPath);
        self::assertSame($hostRouteSource, file_get_contents($hostRoutePath));
        self::assertStringContainsString('generatedGeneratedNoteContribution', $byPath[$frontend . '/contribution.ts']['content']);

        $model = $byPath[$backend . '/src/Model/GeneratedNote.php']['content'];
        self::assertStringNotContainsString('use SoftDelete;', $model, '字段名不能隐式开启软删除能力');

        $openApi = self::evaluatePhp(
            $byPath[$backend . '/api/metadata/generated/generated-note.php']['content'],
        );
        self::assertIsArray($openApi);
        $detailOperation = $openApi['paths']['/adminapi/fixture.delivery-record.generated-note.detail']['get'];
        self::assertArrayHasKey(422, $detailOperation['responses']);
        self::assertSame(
            '#/components/schemas/GeneratedNotePrimaryKey',
            $detailOperation['parameters'][0]['schema']['$ref'],
        );
        self::assertSame(
            '#/components/schemas/GeneratedNoteCreateRequest',
            $openApi['paths']['/adminapi/fixture.delivery-record.generated-note.add']['post']['requestBody']['content']['application/json']['schema']['$ref'],
        );
        self::assertArrayNotHasKey('tenant_id', $openApi['components']['schemas']['GeneratedNoteDetail']['properties']);
        self::assertArrayNotHasKey('api_secret', $openApi['components']['schemas']['GeneratedNoteDetail']['properties']);
        $detail = $openApi['components']['schemas']['GeneratedNoteDetail'];
        self::assertFalse($detail['additionalProperties']);
        self::assertNotContains('audit_history', $detail['required']);
        self::assertSame(['id', 'occurred_at', 'before', 'after'], $detail['properties']['audit_history']['items']['required']);
        self::assertStringContainsString("function diffFields(): array { return array (", $service);
        self::assertMatchesRegularExpression("/diffFields\(\).*?'owner_uuid'.*?'title'.*?'body'.*?;/s", $service);
        self::assertDoesNotMatchRegularExpression("/diffFields\(\)[^;]*'api_secret'/s", $service);
    }

    public function testRenderedValidatorSharesPaginationRulesAndRejectsMalformedScalarInput(): void
    {
        $definition = self::definition();
        $definition['entity_name'] = 'ValidatedNote';
        $definition['columns'][0] = [
            'column_name' => 'id', 'php_type' => 'int', 'is_pk' => true, 'is_required' => true, 'is_lists' => true,
        ];
        $definition['columns'][] = [
            'column_name' => 'state', 'php_type' => 'int', 'is_insert' => true, 'is_query' => true,
            'column_type' => "enum('0','1')",
        ];
        $files = array_column(GeneratorRenderService::render($definition, $this->fixtureApplication->root), 'content', 'path');
        self::evaluatePhp($files['server/app/modules/fixture/delivery_record/src/Validation/ValidatedNoteValidate.php']);

        $class = \PeanutAdmin\Fixtures\DeliveryRecord\Validation\ValidatedNoteValidate::class;
        self::assertInstanceOf(ListsValidate::class, new $class());
        self::assertTrue((new $class())->scene('lists')->check(['page_no' => '2', 'page_size' => '100', 'state' => '1']));
        foreach ([
            ['page_no' => '1.5'],
            ['page_no' => '1e2'],
            ['page_no' => true],
            ['page_size' => '101'],
            ['page_size' => [15]],
            ['title' => ['malformed']],
            ['state' => '0.5'],
            ['state' => '2'],
        ] as $params) {
            self::assertFalse((new $class())->scene('lists')->check($params));
        }

        self::assertTrue((new $class())->scene('edit')->check(['id' => '42', 'title' => 'valid']));
        self::assertFalse((new $class())->scene('edit')->check(['id' => '4.2', 'title' => 'valid']));
    }

    public function testUnregisteredModuleAndUnsupportedCompositePrimaryKeyFailClosed(): void
    {
        $definition = self::definition();
        $definition['module_name'] = 'not-registered';
        try {
            GeneratorRenderService::render($definition, $this->fixtureApplication->root);
            self::fail('An unregistered target Module must be rejected.');
        } catch (RuntimeException $exception) {
            self::assertStringContainsString('目标模块未登记', $exception->getMessage());
        }

        $definition = self::definition();
        $definition['columns'][1]['is_pk'] = true;
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('不支持复合主键');
        GeneratorRenderService::render($definition, $this->fixtureApplication->root);
    }

    public function testRelationOutputRequiresExplicitSafeSummaryFields(): void
    {
        $definition = self::definition();
        $definition['relations'] = [[
            'name' => 'owner',
            'module' => 'fixture.delivery-record',
            'model' => 'DeliveryRecord',
            'type' => 'belongsTo',
            'local_key' => 'owner_uuid',
            'foreign_key' => 'uuid',
            'data_owner' => 'tenant-orm',
            'target_edition' => 'multi-tenant',
            'summary_fields' => ['uuid', 'display_name'],
            'summary_types' => ['uuid' => 'string', 'display_name' => 'string'],
        ]];
        $files = array_column(GeneratorRenderService::render($definition, $this->fixtureApplication->root), 'content', 'path');
        $service = $files['server/app/modules/fixture/delivery_record/src/Service/GeneratedNoteService.php'];
        self::assertStringContainsString("'owner' =>", $service);
        self::assertStringContainsString("'display_name'", $service);
        $api = $files['web/src/modules/fixture-delivery-record/generated/generated-note/api.ts'];
        self::assertStringContainsString('owner?: { uuid: string; display_name: string; };', $api);
        self::assertStringNotContainsString('owner?:', self::interfaceBlock($api, 'GeneratedNoteCreateInput'));
        self::assertStringNotContainsString('owner?:', self::interfaceBlock($api, 'GeneratedNoteUpdateInput'));

        $definition['relations'][0]['summary_fields'][] = 'api_secret';
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('关联摘要不能公开');
        GeneratorRenderService::render($definition, $this->fixtureApplication->root);
    }

    public function testCrossModuleOrmRelationIsRejectedInFavourOfPublicQueryContract(): void
    {
        $definition = self::definition();
        $definition['relations'] = [[
            'name' => 'article',
            'module' => 'official.article',
            'model' => 'Article',
            'type' => 'belongsTo',
            'local_key' => 'owner_uuid',
            'foreign_key' => 'id',
            'data_owner' => 'tenant-orm',
            'target_edition' => 'multi-tenant',
            'summary_fields' => [],
            'summary_types' => [],
        ]];

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('不允许跨模块 ORM 关联；请使用目标模块公开 query 合同');
        GeneratorRenderService::render($definition, $this->fixtureApplication->root);
    }

    public function testExplicitBatchCapabilitiesGenerateMatchingRoutesPermissionsAndValidation(): void
    {
        $definition = self::definition();
        $definition['entity_name'] = 'BatchGeneratedNote';
        $definition['columns'][] = ['column_name' => 'state', 'php_type' => 'int', 'column_type' => 'int', 'is_update' => true];
        $definition['tree_config'] = ['capabilities' => ['batchDelete' => true, 'batchUpdateStatus' => true, 'statusField' => 'state']];
        $files = array_column(GeneratorRenderService::render($definition, $this->fixtureApplication->root), 'content', 'path');
        $base = 'server/app/modules/fixture/delivery_record';
        $controller = $files[$base . '/src/Controller/BatchGeneratedNoteController.php'];
        $route = $files[$base . '/route/generated/batch-generated-note.php'];
        $permissions = json_decode($files[$base . '/resources/permissions.json'], true, 64, JSON_THROW_ON_ERROR);
        $openApi = self::evaluatePhp($files[$base . '/api/metadata/generated/batch-generated-note.php']);
        $view = $files['web/src/modules/fixture-delivery-record/generated/batch-generated-note/index.vue'];
        foreach (['batchDelete', 'batchUpdateStatus'] as $action) {
            self::assertStringContainsString("'$action' => true", $controller);
            self::assertStringContainsString("batch-generated-note.$action", $route);
            self::assertContains('fixture.delivery-record.batch-generated-note.' . $action, array_column($permissions, 'key'));
            self::assertArrayHasKey('/adminapi/fixture.delivery-record.batch-generated-note.' . $action, $openApi['paths']);
            self::assertStringContainsString("batch-generated-note.$action", $view);
        }
        self::assertStringContainsString("statusField(): ?string { return 'state'; }", $files[$base . '/src/Service/BatchGeneratedNoteService.php']);
        self::evaluatePhp($files[$base . '/src/Validation/BatchGeneratedNoteValidate.php']);
        $class = \PeanutAdmin\Fixtures\DeliveryRecord\Validation\BatchGeneratedNoteValidate::class;
        self::assertTrue((new $class())->scene('batchDelete')->check(['ids' => ['a', 'b']]));
        self::assertFalse((new $class())->scene('batchDelete')->check(['ids' => []]));
        self::assertTrue((new $class())->scene('batchUpdateStatus')->check(['ids' => ['a'], 'status' => 0]));
        self::assertFalse((new $class())->scene('batchUpdateStatus')->check(['ids' => ['a'], 'status' => 'bad']));
    }

    public function testTreeDeclarationRetainsCustomParentAndScopedDeleteContract(): void
    {
        $definition = self::definition();
        $definition['template_type'] = 'tree';
        $definition['tree_config'] = ['parent_field' => 'parent_uuid', 'label_field' => 'title'];
        $definition['columns'][] = [
            'column_name' => 'parent_uuid', 'php_type' => 'string', 'column_type' => 'varchar(36)',
            'is_insert' => true, 'is_update' => true, 'is_lists' => true,
        ];
        $files = array_column(GeneratorRenderService::render($definition, $this->fixtureApplication->root), 'content', 'path');
        $service = $files['server/app/modules/fixture/delivery_record/src/Service/GeneratedNoteService.php'];
        self::assertStringContainsString('extends AbstractTreeCrudService', $service);
        self::assertStringContainsString("parentField(): string { return 'parent_uuid'; }", $service);
        self::assertStringContainsString('GENERATED_NOTE_HAS_CHILDREN', $service);
    }

    public function testDefaultIntegerIdContractRemainsExplicit(): void
    {
        $definition = self::definition();
        $definition['table_name'] = 'pa_fixture_generated_integer_note';
        $definition['entity_name'] = 'GeneratedIntegerNote';
        $definition['columns'][0] = [
            'column_name' => 'id', 'php_type' => 'int', 'is_pk' => true, 'is_required' => true, 'is_lists' => true,
        ];
        $files = array_column(GeneratorRenderService::render($definition, $this->fixtureApplication->root), 'content', 'path');
        $controller = $files['server/app/modules/fixture/delivery_record/src/Controller/GeneratedIntegerNoteController.php'];
        self::assertStringContainsString("CRUD_PRIMARY_KEY = 'id'", $controller);
        self::assertStringContainsString("CRUD_PRIMARY_KEY_TYPE = 'int'", $controller);
        self::assertStringContainsString('getGeneratedIntegerNoteDetail(id: number, signal?: AbortSignal)', $files['web/src/modules/fixture-delivery-record/generated/generated-integer-note/api.ts']);
    }

    /** @return array<string,mixed> */
    public function testGenerationRejectsATableOwnedByAnotherModule(): void
    {
        $root = $this->fixtureApplication->root;
        $foreign = $root . '/server/app/modules/fixture/other';
        mkdir($foreign, 0775, true);
        file_put_contents($foreign . '/module.json', json_encode([
            'key' => 'fixture.other',
            'database' => ['owned_tables' => ['pa_fixture_generated_note']],
        ], JSON_THROW_ON_ERROR));

        $this->expectExceptionMessage('数据表已由其他模块拥有：pa_fixture_generated_note（fixture.other）');
        GeneratorRenderService::render(self::definition(), $root);
    }

    public function testGenerationRejectsCoreReservedTable(): void
    {
        $definition = array_replace(self::definition(), ['table_name' => 'pa_tenant_idempotency_record']);
        $this->expectExceptionMessage('数据表由 Core 保留：pa_tenant_idempotency_record');
        GeneratorRenderService::render($definition, $this->fixtureApplication->root);
    }

    public function testGenerationRejectsRetiredTableInTargetModule(): void
    {
        $root = $this->fixtureApplication->root;
        $path = $root . '/server/app/modules/fixture/delivery_record/module.json';
        $manifest = json_decode(file_get_contents($path), true, 64, JSON_THROW_ON_ERROR);
        $manifest['database']['retired_tables'] = ['pa_fixture_generated_note'];
        file_put_contents($path, json_encode($manifest, JSON_THROW_ON_ERROR));

        $this->expectExceptionMessage('数据表已退役：pa_fixture_generated_note（fixture.delivery-record）');
        GeneratorRenderService::render(self::definition(), $root);
    }

    public function testSeveralGeneratedResourcesKeepAllOpenApiPaths(): void
    {
        $root = $this->fixtureApplication->root;
        $metadata = $root . '/server/app/modules/fixture/delivery_record/api/metadata';
        foreach (['GeneratedNote', 'SecondNote', 'ThirdNote'] as $index => $entity) {
            $definition = array_replace(self::definition(), [
                'entity_name' => $entity,
                'table_name' => 'pa_fixture_note_' . $index,
            ]);
            foreach (GeneratorRenderService::render($definition, $root) as $file) {
                $target = $root . '/' . $file['path'];
                if (!is_dir(dirname($target))) {
                    mkdir(dirname($target), 0775, true);
                }
                self::assertNotFalse(file_put_contents($target, $file['content']));
            }
        }

        $contract = require $metadata . '/openapi.php';
        foreach (['generated-note', 'second-note', 'third-note'] as $resource) {
            self::assertArrayHasKey('/adminapi/fixture.delivery-record.' . $resource . '.list', $contract['paths']);
        }
    }

    public function testSourceModuleConsumerContractSurvivesGeneratedOpenApiMerge(): void
    {
        $root = $this->fixtureApplication->root;
        $directory = 'server/app/modules/fixture/delivery_record/api/metadata';
        mkdir($root . '/' . $directory, 0775, true);
        file_put_contents($root . '/' . $directory . '/openapi.php', <<<'PHP'
<?php
$authored = ['paths' => ['/authored' => []]];
return array_replace_recursive($authored, require __DIR__ . '/consumer.php');
PHP);
        file_put_contents($root . '/' . $directory . '/consumer.php', '<?php return ["paths" => ["/consumer" => []]];');

        foreach (GeneratorRenderService::render(self::definition(), $root) as $file) {
            if (str_starts_with($file['path'], $directory . '/')) {
                $target = $root . '/' . $file['path'];
                if (!is_dir(dirname($target))) {
                    mkdir(dirname($target), 0775, true);
                }
                self::assertNotFalse(file_put_contents($target, $file['content']));
            }
        }
        $contract = require $root . '/' . $directory . '/openapi.php';
        foreach (['/authored', '/consumer', '/adminapi/fixture.delivery-record.generated-note.list'] as $path) {
            self::assertArrayHasKey($path, $contract['paths']);
        }
    }

    public static function definition(): array
    {
        return [
            'table_name' => 'pa_fixture_generated_note',
            'table_comment' => '生成记录',
            'module_name' => 'fixture.delivery-record',
            'entity_name' => 'GeneratedNote',
            'data_owner' => 'tenant-orm',
            'target_edition' => 'multi-tenant',
            'columns' => [
                ['column_name' => 'uuid', 'php_type' => 'string', 'column_type' => 'varchar(36)', 'is_pk' => true, 'is_required' => true, 'is_lists' => true],
                ['column_name' => 'tenant_id', 'php_type' => 'int', 'is_required' => true, 'is_lists' => true],
                ['column_name' => 'owner_uuid', 'php_type' => 'string', 'column_type' => 'varchar(36)', 'is_insert' => true, 'is_update' => true],
                ['column_name' => 'title', 'php_type' => 'string', 'column_type' => 'varchar(100)', 'is_required' => true, 'is_insert' => true, 'is_update' => true, 'is_lists' => true, 'is_query' => true],
                ['column_name' => 'body', 'php_type' => 'string', 'is_insert' => true, 'is_update' => true],
                ['column_name' => 'api_secret', 'php_type' => 'string', 'is_insert' => true, 'is_update' => true, 'is_lists' => true],
                ['column_name' => 'delete_time', 'php_type' => 'int'],
            ],
        ];
    }

    private static function constantBlock(string $source, string $constant): string
    {
        preg_match('/private const ' . preg_quote($constant, '/') . ' = (.*?);/s', $source, $matches);
        return (string) ($matches[1] ?? '');
    }

    private static function interfaceBlock(string $source, string $interface): string
    {
        preg_match('/export interface ' . preg_quote($interface, '/') . ' \{(.*?)\}/s', $source, $matches);
        return (string) ($matches[1] ?? '');
    }

    private static function evaluatePhp(string $source): mixed
    {
        $path = tempnam(sys_get_temp_dir(), 'peanut-generator-php-');
        self::assertIsString($path);
        self::assertNotFalse(file_put_contents($path, $source));
        try {
            return require $path;
        } finally {
            unlink($path);
        }
    }
}
