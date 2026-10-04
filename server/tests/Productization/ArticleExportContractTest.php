<?php

declare(strict_types=1);

/** Dependency-free export range/contract regression; NOT a MySQL or browser acceptance test. */
require_once dirname(__DIR__, 2) . '/app/common/support/ExportPageInfo.php';

use app\common\support\ExportPageInfo;

$checks = 0;
$expect = static function (bool $condition, string $message) use (&$checks): void {
    ++$checks;
    if (!$condition) {
        throw new RuntimeException($message);
    }
};
$expect(method_exists(ExportPageInfo::class, 'rowRange'), 'ExportPageInfo must validate the same bounded range for Article and Category exports');
$info = ExportPageInfo::from(53, 25, 25000, 'fixture');
$expect($info->rowRange(0) === [0, 53], 'all rows range changed');
$expect($info->rowRange(1, 2, 3) === [25, 28], 'last partial page range changed');
$expect($info->rowRange(1, 3, 3) === [50, 3], 'last page range changed');
$expect($info->toArray()['count'] === 53 && $info->toArray()['sum_page'] === 3, 'metadata lost the filtered count');
foreach ([
    [$info, 1, 0, 1], [$info, 1, 3, 2], [$info, 1, 4, 4],
    [$info, 1, 1, PHP_INT_MAX], [$info, 2, 1, 1],
    [ExportPageInfo::from(0, 25, 25000, 'empty'), 0, 1, 1],
    [ExportPageInfo::from(25001, 25, 25000, 'too-large'), 0, 1, 1],
    [ExportPageInfo::from(30000, 100, 25000, 'range-too-large'), 1, 1, 251],
] as [$metadata, $type, $start, $end]) {
    try {
        $metadata->rowRange($type, $start, $end);
        throw new RuntimeException('Invalid/empty/oversized export range was accepted');
    } catch (InvalidArgumentException $exception) {
        $expect(str_starts_with($exception->getMessage(), 'EXPORT_'), 'range error must be explicit and stable');
    }
}
$large = ExportPageInfo::from(30000, 100, 25000, 'large');
$expect($large->rowRange(1, 251, 300) === [25000, 5000], 'a later bounded range must remain exportable');

$module = dirname(__DIR__, 2) . '/app/modules/official/article';
$metadata = require $module . '/api/metadata/openapi.php';
foreach ([
    ['ArticleAdministration', 'Article', 'official.article', 'articleLists'],
    ['ArticleCategoryAdministration', 'ArticleCate', 'official.article.category', 'categoryLists'],
] as [$contract, $model, $permission, $queryMethod]) {
    $source = (string) file_get_contents($module . '/src/Service/' . $contract . 'Service.php');
    $expect(!str_contains($source, 'EXPORT_UNSUPPORTED'), $contract . ' still rejects every export');
    $expect(!str_contains($source, '(clone $query)->count()'), $contract . ' must not detach deferred scope callbacks from the count query');
    $expect(str_contains($source, 'private readonly XlsxExportService $xlsxExport'), $contract . ' must reuse injected XLSX storage');
    $expect(str_contains($source, 'ExportPageInfo::from(') && str_contains($source, '->rowRange('), $contract . ' bypasses the public paged export protocol');
    $expect(str_contains($source, '$onlyTrashed ? ' . $model . '::onlyTrashed() : ' . $model . '::where([])'), $contract . ' lost ordinary/trash selection');
    $expect(!str_contains($source, 'withoutTenantScope(') && !str_contains($source, 'withoutGlobalScope('), $contract . ' bypasses data scope');
    $expect(str_contains($source, '$this->' . $queryMethod . '($context, $params, false)'), $contract . ' list is not routed through shared query');
    $expect(str_contains($source, '$this->' . $queryMethod . '($context, $params, true)'), $contract . ' recycle list is not routed through shared query');
    foreach (['.list', '.recycle.list'] as $suffix) {
        $operation = $metadata['paths']['/adminapi/' . $permission . $suffix]['get'];
        $parameters = array_column($operation['parameters'], 'name');
        $expect(in_array('export', $parameters, true), $permission . $suffix . ' does not document export');
        $expect(in_array('file_name', $parameters, true), $permission . $suffix . ' does not accept the existing consumer filename');
        $expect(in_array('ARTICLE_EXPORT_RANGE_INVALID', $operation['x-peanut-errors'], true), 'export range failure is undocumented');
    }
}
echo 'ARTICLE-EXPORT-CONTRACT-001 passed: ' . $checks . " checks (no database, no HTTP)\n";
