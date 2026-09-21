<?php
declare(strict_types=1);

namespace tests\Unit;

use app\adminapi\services\generator\GeneratorRenderService;
use app\adminapi\services\generator\GeneratorService;
use app\adminapi\validate\generator\GeneratorValidate;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use RuntimeException;

final class GeneratorSoftDeleteContractTest extends TestCase
{
    public function testExplicitCustomSoftDeleteCapabilityGeneratesOneCompleteContract(): void
    {
        $files = GeneratorRenderService::render(self::definition());
        $byPath = array_column($files, null, 'path');
        $backend = 'server/app/modules/fixture/delivery_record';
        $frontend = 'web/src/modules/fixture-delivery-record';

        $model = $byPath[$backend . '/src/Model/SoftGeneratedNote.php']['content'];
        self::assertStringContainsString('use SoftDelete;', $model);
        self::assertStringContainsString("protected \$deleteTime = 'removed_at';", $model);
        self::assertStringNotContainsString("protected \$deleteTime = 'delete_time';", $model);

        $controller = $byPath[$backend . '/src/Controller/SoftGeneratedNoteController.php']['content'];
        self::assertStringContainsString('CRUD_SOFT_DELETE = true', $controller);
        self::assertStringContainsString("'purge' =>", $controller);

        $service = $byPath[$backend . '/src/Service/SoftGeneratedNoteService.php']['content'];
        foreach (['recycleLists', 'recycleDetail', 'restore', 'purge'] as $method) {
            self::assertStringContainsString('function ' . $method . '(', $service);
        }
        self::assertStringContainsString('RESTORE_UNIQUE_CONFLICT', $service);
        self::assertStringContainsString('RESTORE_RELATION_CONFLICT', $service);
        self::assertStringContainsString('PURGE_REQUIRES_TRASHED', $service);
        self::assertStringContainsString('$model->force(true)->delete() === false', $service);
        self::assertStringNotContainsString('(bool)', $service);

        $permissions = json_decode(
            $byPath[$backend . '/resources/permissions.json']['content'],
            true,
            64,
            JSON_THROW_ON_ERROR,
        );
        $keys = array_column($permissions, 'key');
        foreach (['recycle.list', 'recycle.detail', 'restore', 'purge'] as $action) {
            self::assertContains('fixture.delivery-record.soft-generated-note.' . $action, $keys);
        }

        $openApi = self::evaluatePhp($byPath[$backend . '/api/metadata/generated/soft-generated-note.php']['content']);
        self::assertCount(9, $openApi['paths']);
        self::assertSame(
            ['draft', 'published'],
            $openApi['components']['schemas']['SoftGeneratedNoteCreateRequest']['properties']['state']['enum'],
        );
        self::assertArrayNotHasKey('tenant_id', $openApi['components']['schemas']['SoftGeneratedNoteDetail']['properties']);
        self::assertArrayNotHasKey('removed_at', $openApi['components']['schemas']['SoftGeneratedNoteDetail']['properties']);
        self::assertArrayNotHasKey('delete_time', $openApi['components']['schemas']['SoftGeneratedNoteDetail']['properties']);
        self::assertSame(
            '#/components/schemas/SoftGeneratedNoteBatchRequest',
            $openApi['paths']['/adminapi/fixture.delivery-record.soft-generated-note.restore']['post']['requestBody']['content']['application/json']['schema']['$ref'],
        );
        foreach ([
            'add' => 'SoftGeneratedNoteCreateRequest',
            'edit' => 'SoftGeneratedNoteUpdateRequest',
            'delete' => 'SoftGeneratedNoteDeleteRequest',
            'restore' => 'SoftGeneratedNoteBatchRequest',
            'purge' => 'SoftGeneratedNoteBatchRequest',
        ] as $action => $schema) {
            self::assertSame(
                '#/components/schemas/' . $schema,
                $openApi['paths']['/adminapi/fixture.delivery-record.soft-generated-note.' . $action]['post']['requestBody']['content']['application/json']['schema']['$ref'],
            );
        }
        self::assertSame(
            '#/components/schemas/SoftGeneratedNotePrimaryKey',
            $openApi['components']['schemas']['SoftGeneratedNoteBatchRequest']['properties']['ids']['items']['$ref'],
        );
        $listParameters = array_column(
            $openApi['paths']['/adminapi/fixture.delivery-record.soft-generated-note.list']['get']['parameters'],
            'schema',
            'name',
        );
        self::assertSame(['draft', 'published'], $listParameters['state']['enum']);

        $api = $byPath[$frontend . '/generated/soft-generated-note/api.ts']['content'];
        self::assertStringContainsString('getSoftGeneratedNoteRecycleList', $api);
        self::assertStringContainsString('restoreSoftGeneratedNote', $api);
        self::assertStringContainsString('purgeSoftGeneratedNote', $api);
        self::assertStringContainsString("state: \"draft\" | \"published\";", $api);
        $view = $byPath[$frontend . '/generated/soft-generated-note/index.vue']['content'];
        self::assertStringContainsString("fixture.delivery-record.soft-generated-note.restore", $view);
        self::assertStringContainsString("fixture.delivery-record.soft-generated-note.purge", $view);

        self::loadPhp($byPath[$backend . '/src/Validation/SoftGeneratedNoteValidate.php']['content']);
        $validator = new \PeanutAdmin\Fixtures\DeliveryRecord\Validation\SoftGeneratedNoteValidate();
        self::assertTrue($validator->scene('lists')->check(['state' => 'draft']));
        self::assertFalse($validator->scene('lists')->check(['state' => 'archived']));

        self::lintGeneratedPhp($files);
    }

    public function testDefinitionParserRequiresExplicitBooleanAndExistingNonSystemField(): void
    {
        $method = new ReflectionMethod(GeneratorService::class, 'normalizeSoftDelete');
        self::assertSame(
            ['enabled' => true, 'field' => 'removed_at'],
            $method->invoke(null, ['enabled' => true, 'field' => 'removed_at'], ['uuid', 'tenant_id', 'removed_at'], ['uuid']),
        );
        self::assertSame(
            ['enabled' => false, 'field' => ''],
            $method->invoke(null, ['enabled' => false, 'field' => 'delete_time'], ['uuid', 'delete_time'], ['uuid']),
        );
        $hydrate = new ReflectionMethod(GeneratorService::class, 'hydrateSoftDelete');
        self::assertSame(
            ['enabled' => true, 'field' => 'removed_at'],
            $hydrate->invoke(null, [
                'tree_config' => ['soft_delete' => ['enabled' => true, 'field' => 'removed_at']],
            ])['soft_delete'],
        );

        foreach ([
            [['enabled' => '1', 'field' => 'removed_at'], '布尔值'],
            [['enabled' => true, 'field' => 'missing'], '必须选择'],
            [['enabled' => true, 'field' => 'tenant_id'], '主键和租户字段'],
            [['enabled' => true, 'field' => 'uuid'], '主键和租户字段'],
        ] as [$input, $message]) {
            try {
                $method->invoke(null, $input, ['uuid', 'tenant_id', 'removed_at'], ['uuid']);
                self::fail('无效软删除定义未被拒绝');
            } catch (\ReflectionException $exception) {
                throw $exception;
            } catch (\Throwable $exception) {
                self::assertStringContainsString($message, $exception->getMessage());
            }
        }

        $validator = new GeneratorValidate();
        self::assertTrue($validator->scene('update')->check([
            'id' => 1,
            'table_comment' => '生成记录',
            'module_name' => 'fixture.delivery-record',
            'entity_name' => 'SoftGeneratedNote',
            'template_type' => 'crud',
            'data_owner' => 'tenant-orm',
            'target_edition' => 'multi-tenant',
            'tree_config' => [],
            'soft_delete' => ['enabled' => true, 'field' => 'removed_at'],
            'relations' => [],
            'columns' => [['id' => 1]],
        ]));
    }

    /** @return array<string,mixed> */
    public static function definition(): array
    {
        return [
            'table_name' => 'pa_fixture_soft_generated_note',
            'table_comment' => '生成软删除记录',
            'module_name' => 'fixture.delivery-record',
            'entity_name' => 'SoftGeneratedNote',
            'data_owner' => 'tenant-orm',
            'target_edition' => 'multi-tenant',
            'soft_delete' => ['enabled' => true, 'field' => 'removed_at'],
            'relations' => [
                [
                    'name' => 'owner', 'module' => 'fixture.delivery-record', 'model' => 'DeliveryRecord',
                    'type' => 'belongsTo', 'local_key' => 'owner_uuid', 'foreign_key' => 'uuid',
                    'data_owner' => 'tenant-orm', 'target_edition' => 'multi-tenant',
                    'summary_fields' => [], 'summary_types' => [],
                ],
                [
                    'name' => 'deliveries', 'module' => 'fixture.delivery-record', 'model' => 'DeliveryRecord',
                    'type' => 'hasMany', 'local_key' => 'uuid', 'foreign_key' => 'owner_uuid',
                    'data_owner' => 'tenant-orm', 'target_edition' => 'multi-tenant',
                    'summary_fields' => [], 'summary_types' => [],
                ],
            ],
            'columns' => [
                ['column_name' => 'uuid', 'php_type' => 'string', 'column_type' => 'varchar(36)', 'is_pk' => true, 'is_required' => true, 'is_lists' => true],
                ['column_name' => 'tenant_id', 'php_type' => 'int', 'is_required' => true, 'is_lists' => true],
                ['column_name' => 'title', 'php_type' => 'string', 'column_type' => 'varchar(100)', 'is_required' => true, 'is_insert' => true, 'is_update' => true, 'is_lists' => true, 'is_query' => true],
                ['column_name' => 'owner_uuid', 'php_type' => 'string', 'column_type' => 'varchar(36)', 'is_insert' => true, 'is_update' => true],
                ['column_name' => 'api_secret', 'php_type' => 'string', 'is_insert' => true, 'is_update' => true, 'is_lists' => true],
                ['column_name' => 'delete_time', 'php_type' => 'int'],
                ['column_name' => 'removed_at', 'php_type' => 'int'],
                [
                    'column_name' => 'state',
                    'php_type' => 'string',
                    'column_type' => "enum('draft','published')",
                    'is_required' => true,
                    'is_insert' => true,
                    'is_update' => true,
                    'is_lists' => true,
                    'is_query' => true,
                ],
            ],
        ];
    }

    /** @param list<array<string,mixed>> $files */
    private static function lintGeneratedPhp(array $files): void
    {
        $directory = sys_get_temp_dir() . '/peanut-generator-soft-lint-' . bin2hex(random_bytes(8));
        self::assertTrue(mkdir($directory, 0775, true));
        try {
            foreach ($files as $file) {
                if (($file['language'] ?? null) !== 'php') continue;
                $path = $directory . '/' . str_replace('/', '-', (string)$file['path']);
                self::assertNotFalse(file_put_contents($path, $file['content']));
                $process = proc_open(
                    [PHP_BINARY, '-l', $path],
                    [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
                    $pipes,
                );
                self::assertIsResource($process);
                $output = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
                fclose($pipes[1]);
                fclose($pipes[2]);
                self::assertSame(0, proc_close($process), $file['path'] . "\n" . $output);
            }
        } finally {
            foreach (glob($directory . '/*') ?: [] as $path) unlink($path);
            rmdir($directory);
        }
    }

    private static function evaluatePhp(string $source): array
    {
        $path = tempnam(sys_get_temp_dir(), 'peanut-generator-soft-openapi-');
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

    private static function loadPhp(string $source): void
    {
        $path = tempnam(sys_get_temp_dir(), 'peanut-generator-soft-php-');
        self::assertIsString($path);
        self::assertNotFalse(file_put_contents($path, $source));
        try {
            require $path;
        } finally {
            unlink($path);
        }
    }
}
