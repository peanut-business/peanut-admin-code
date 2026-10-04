<?php

declare(strict_types=1);

namespace tests\Unit;

use app\adminapi\services\generator\GeneratorRenderService;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class GeneratorDeclaredCrudTemplateTest extends TestCase
{
    public function testGeneratedCrudUsesRegisteredModuleAndCompleteAssemblyContracts(): void
    {
        $files = GeneratorRenderService::render(self::definition());
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

        $controller = $byPath[$backend . '/src/Controller/GeneratedNoteController.php']['content'];
        self::assertStringContainsString('namespace PeanutAdmin\\Fixtures\\DeliveryRecord\\Controller;', $controller);
        self::assertStringContainsString("CRUD_PRIMARY_KEY = 'uuid'", $controller);
        self::assertStringContainsString("CRUD_PRIMARY_KEY_TYPE = 'string'", $controller);
        self::assertStringContainsString('CRUD_WRITABLE_FIELDS', $controller);

        $service = $byPath[$backend . '/src/Service/GeneratedNoteService.php']['content'];
        self::assertStringContainsString('private const LIST_FIELDS', $service);
        self::assertStringContainsString('private const DETAIL_FIELDS', $service);
        self::assertStringContainsString('array_intersect_key($data, array_flip($fields))', $service);
        self::assertStringContainsString("=== false", $service);
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
        self::assertSame('merge', $byPath['server/app/adminapi/route/app.php']['operation']);
        self::assertStringContainsString("'server/app/modules/fixture/delivery_record/route/app.php',", $byPath['server/app/adminapi/route/app.php']['content']);
        self::assertStringContainsString('generatedGeneratedNoteContribution', $byPath[$frontend . '/contribution.ts']['content']);

        $model = $byPath[$backend . '/src/Model/GeneratedNote.php']['content'];
        self::assertStringNotContainsString('use SoftDelete;', $model, '字段名不能隐式开启软删除能力');

        $openApi = self::evaluatePhp(
            $byPath[$backend . '/api/metadata/generated/generated-note.php']['content'],
        );
        $detailOperation = $openApi['paths']['/adminapi/fixture.delivery-record.generated-note.detail']['get'];
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
    }

    public function testUnregisteredModuleAndUnsupportedCompositePrimaryKeyFailClosed(): void
    {
        $definition = self::definition();
        $definition['module_name'] = 'not-registered';
        try {
            GeneratorRenderService::render($definition);
            self::fail('An unregistered target Module must be rejected.');
        } catch (RuntimeException $exception) {
            self::assertStringContainsString('目标模块未登记', $exception->getMessage());
        }

        $definition = self::definition();
        $definition['columns'][1]['is_pk'] = true;
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('不支持复合主键');
        GeneratorRenderService::render($definition);
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
        $files = array_column(GeneratorRenderService::render($definition), 'content', 'path');
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
        GeneratorRenderService::render($definition);
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
        GeneratorRenderService::render($definition);
    }

    public function testDefaultIntegerIdContractRemainsExplicit(): void
    {
        $definition = self::definition();
        $definition['table_name'] = 'pa_fixture_generated_integer_note';
        $definition['entity_name'] = 'GeneratedIntegerNote';
        $definition['columns'][0] = [
            'column_name' => 'id', 'php_type' => 'int', 'is_pk' => true, 'is_required' => true, 'is_lists' => true,
        ];
        $files = array_column(GeneratorRenderService::render($definition), 'content', 'path');
        $controller = $files['server/app/modules/fixture/delivery_record/src/Controller/GeneratedIntegerNoteController.php'];
        self::assertStringContainsString("CRUD_PRIMARY_KEY = 'id'", $controller);
        self::assertStringContainsString("CRUD_PRIMARY_KEY_TYPE = 'int'", $controller);
        self::assertStringContainsString('getGeneratedIntegerNoteDetail(id: number)', $files['web/src/modules/fixture-delivery-record/generated/generated-integer-note/api.ts']);
    }

    /** @return array<string,mixed> */
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

    private static function evaluatePhp(string $source): array
    {
        $path = tempnam(sys_get_temp_dir(), 'peanut-generator-openapi-');
        self::assertIsString($path);
        self::assertNotFalse(file_put_contents($path, $source));
        try {
            $value = require $path;
            self::assertIsArray($value);
            return $value;
        } finally {
            unlink($path);
        }
    }
}
