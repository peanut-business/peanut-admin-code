<?php

declare(strict_types=1);

namespace tests\Unit;

use app\adminapi\services\generator\GeneratorRenderService;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** 执行真实预览渲染，不落业务源码或访问数据库；前端运行行为由消费者测试另验。 */
final class GeneratorElementPlusViewTest extends TestCase
{
    public static function examples(): array
    {
        $inputs = require dirname(__DIR__) . '/fixtures/generator-element-plus-inputs.php';
        return [
            'string ordinary' => [$inputs['string-plain'], 'StringPlainViewNote', 'string-plain-view-note', 'uuid', false],
            'string recycle' => [$inputs['string-recycle'], 'StringRecycleViewNote', 'string-recycle-view-note', 'uuid', true],
            'integer ordinary' => [$inputs['int-plain'], 'IntPlainViewNote', 'int-plain-view-note', 'id', false],
            'integer recycle' => [$inputs['int-recycle'], 'IntRecycleViewNote', 'int-recycle-view-note', 'id', true],
        ];
    }

    #[DataProvider('examples')]
    public function testActualViewUsesTheDeclaredUiAndNativeComponentContracts(array $input, string $entity, string $resource, string $primary, bool $recycle): void
    {
        $files = array_column(GeneratorRenderService::render($input), 'content', 'path');
        $view = $files['web/src/modules/fixture-delivery-record/generated/' . $resource . '/index.vue'];
        $package = json_decode(file_get_contents(dirname(__DIR__, 3) . '/web/package.json'), true, 512, JSON_THROW_ON_ERROR);
        self::assertArrayHasKey('element-plus', $package['dependencies']);
        self::assertArrayNotHasKey('@arco-design/web-vue', $package['dependencies']);
        self::assertStringNotContainsString('@arco-design', $view);
        self::assertDoesNotMatchRegularExpression('/<\/?a-/', $view);
        self::assertStringNotContainsString('TableColumnData', $view);
        foreach (['ElCard', 'ElTable', 'ElTableColumn', 'ElPagination', 'ElAlert', 'ElButton'] as $component) {
            self::assertStringContainsString('<' . $component, $view);
        }
        self::assertStringContainsString("from 'element-plus'", $view);
        self::assertStringContainsString('row-key="' . $primary . '"', $view);
        self::assertStringContainsString(':data="records"', $view);
        self::assertStringContainsString(':prop="column.prop"', $view);
        self::assertStringContainsString(':label="column.label"', $view);
        self::assertStringContainsString(':current-page="pagination.page"', $view);
        self::assertStringContainsString(':total="pagination.total"', $view);
        self::assertStringContainsString('@current-change="fetchData"', $view);
        self::assertStringNotContainsString(':pagination=', $view);
        self::assertStringNotContainsString('@page-change=', $view);
        self::assertStringContainsString('initialPageSize: 15', $view);
        self::assertStringContainsString('page_no: page, page_size: pageSize', $view);
        self::assertStringContainsString('total: response.data.count', $view);
        self::assertStringContainsString('items: response.data.lists', $view);
    }

    #[DataProvider('examples')]
    public function testProjectionPermissionsAndTypedPrimaryKeysRemainIntact(array $input, string $entity, string $resource, string $primary, bool $recycle): void
    {
        $files = array_column(GeneratorRenderService::render($input), 'content', 'path');
        $prefix = 'web/src/modules/fixture-delivery-record/generated/' . $resource . '/';
        $view = $files[$prefix . 'index.vue'];
        foreach (['api_secret', 'tenant_id', 'removed_at', 'delete_time'] as $hidden) {
            self::assertStringNotContainsString($hidden, $view);
        }
        self::assertStringContainsString('type ' . $entity . 'ListRecord', $view);
        self::assertStringContainsString("'/adminapi/fixture.delivery-record.{$resource}.list'", $files[$prefix . 'api.ts']);
        self::assertStringContainsString("'/generated/{$resource}'", $files[$prefix . 'contribution.ts']);
        if (!$recycle) {
            self::assertStringNotContainsString('recycle.list', $view);
            self::assertStringNotContainsString('handleRestore', $view);
            self::assertStringNotContainsString('ElPopconfirm', $view);
            return;
        }
        foreach (['recycle.list', 'restore', 'purge'] as $permission) {
            self::assertStringContainsString("v-permission=\"['fixture.delivery-record.{$resource}.{$permission}']\"", $view);
        }
        self::assertStringContainsString('<template #default="{ row }">', $view);
        self::assertStringContainsString('<template #reference>', $view);
        self::assertStringContainsString('@confirm="handlePurge(row)"', $view);
        self::assertStringNotContainsString('@ok=', $view);
        self::assertStringContainsString("restore{$entity}([record.{$primary}])", $view);
        self::assertStringContainsString("purge{$entity}([record.{$primary}])", $view);
        self::assertStringContainsString('get' . $entity . 'RecycleList', $view);
    }

    #[DataProvider('examples')]
    public function testTemplateReusesPublicAsyncStateAndDoesNotHideFailure(array $input, string $entity, string $resource, string $primary, bool $recycle): void
    {
        $files = array_column(GeneratorRenderService::render($input), 'content', 'path');
        $view = $files['web/src/modules/fixture-delivery-record/generated/' . $resource . '/index.vue'];
        self::assertStringContainsString("from '@peanut-admin/vue'", $view);
        self::assertStringContainsString('useAsyncList<', $view);
        self::assertStringContainsString('v-loading="loading"', $view);
        self::assertStringContainsString('v-if="error"', $view);
        self::assertStringContainsString('<template #empty>', $view);
        self::assertStringContainsString('list.clear()', $view);
        self::assertStringNotContainsString(' as any', $view);
        self::assertStringNotContainsString(' as unknown as ', $view);
        self::assertStringNotContainsString('@ts-ignore', $view);
        if ($recycle) {
            self::assertStringContainsString('useAsyncAction(', $view);
            self::assertStringContainsString('v-if="actionError"', $view);
            self::assertStringContainsString(':disabled="actionLoading"', $view);
            self::assertStringContainsString("result.status !== 'completed'", $view);
            self::assertStringContainsString('result.data.data.failed.length', $view);
        }
    }

    public function testRepeatedPreviewIsDeterministicAndDoesNotApplyMergeOrCreateFiles(): void
    {
        $input = self::examples()['string recycle'][0];
        $first = GeneratorRenderService::render($input);
        self::assertSame($first, GeneratorRenderService::render($input));
        self::assertNotEmpty($first);
        $root = dirname(__DIR__, 3);
        foreach ($first as $file) {
            if ($file['operation'] === 'create') {
                self::assertFileDoesNotExist($root . '/' . $file['path']);
            } else {
                self::assertSame($file['base_sha256'], hash_file('sha256', $root . '/' . $file['path']));
            }
        }
    }
}
