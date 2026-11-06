<?php

declare(strict_types=1);

/** Exercise the common bounded export range used by the host. */
require_once dirname(__DIR__, 2) . '/app/common/support/ExportPageInfo.php';

use app\common\support\ExportPageInfo;

$checks = 0;
$expect = static function (bool $condition, string $message) use (&$checks): void {
    ++$checks;
    if (!$condition) {
        throw new RuntimeException($message);
    }
};
$expect(method_exists(ExportPageInfo::class, 'rowRange'), 'The shared export page contract requires bounded row ranges');
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

echo "EXPORT-PAGE-INFO-001 passed: {$checks} checks\n";
