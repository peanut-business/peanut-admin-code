<?php

declare(strict_types=1);

function expectReferenceCodes(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$serverRoot = dirname(__DIR__, 4);
$repositoryRoot = dirname($serverRoot);

$coreEvidence = json_decode((string) file_get_contents(
    $repositoryRoot . '/output/playwright/t01/system-tools-core-summary.json',
), true, 512, JSON_THROW_ON_ERROR);
$frontendEvidence = json_decode((string) file_get_contents(
    $repositoryRoot . '/output/playwright/t01/frontend-summary.json',
), true, 512, JSON_THROW_ON_ERROR);

expectReferenceCodes(($coreEvidence['status'] ?? null) === 'passed', 'sealed T01 core evidence must pass');
foreach ([
    'dict_rename_synchronized',
    'dict_occupied_delete_rejected',
    'dict_invalid_delete_rejected',
    'dict_cleanup_delete_succeeded',
] as $check) {
    expectReferenceCodes(($coreEvidence['checks'][$check] ?? false) === true, 'missing T01 check: ' . $check);
}
expectReferenceCodes(($coreEvidence['fixtures_cleaned'] ?? false) === true, 'T01 core fixtures must be clean');
expectReferenceCodes(($frontendEvidence['ok'] ?? false) === true, 'sealed T01 frontend evidence must pass');
expectReferenceCodes(($frontendEvidence['reference']['read_only'] ?? false) === true, 'reference verification must remain read-only');
expectReferenceCodes(($frontendEvidence['peanut']['dict']['type_renamed'] ?? false) === true, 'type rename evidence missing');
expectReferenceCodes(($frontendEvidence['peanut']['dict']['data_item_visible'] ?? false) === true, 'data visibility evidence missing');
expectReferenceCodes(
    ($frontendEvidence['peanut']['dict']['occupied_delete_rejected_with_visible_message'] ?? false) === true,
    'occupied delete UI evidence missing',
);
expectReferenceCodes(($frontendEvidence['cleanup']['dict_types'] ?? -1) === 0, 'T01 type fixtures must be zero');
expectReferenceCodes(($frontendEvidence['cleanup']['dict_data'] ?? -1) === 0, 'T01 data fixtures must be zero');

$ownedFiles = [
    'app/modules/official/reference_codes/src/Controller/DictTypeController.php',
    'app/modules/official/reference_codes/src/Controller/DictDataController.php',
    'app/modules/official/reference_codes/src/Validation/DictTypeValidate.php',
    'app/modules/official/reference_codes/src/Validation/DictDataValidate.php',
    'app/modules/official/reference_codes/src/Service/DictTypeApplicationService.php',
    'app/modules/official/reference_codes/src/Service/DictDataApplicationService.php',
    'app/modules/official/reference_codes/src/Model/DictType.php',
    'app/modules/official/reference_codes/src/Model/DictData.php',
    'app/modules/official/reference_codes/src/Infrastructure/ThinkPhpTenantDictionaryProvider.php',
    'app/modules/official/reference_codes/src/Infrastructure/ThinkPhpSystemDictionaryProvider.php',
];
$sources = [];
foreach ($ownedFiles as $relativePath) {
    $absolutePath = $serverRoot . '/' . $relativePath;
    expectReferenceCodes(is_file($absolutePath), 'missing application owner: ' . $relativePath);
    $sources[$relativePath] = (string) file_get_contents($absolutePath);
}

$typeLogic = $sources['app/modules/official/reference_codes/src/Service/DictTypeApplicationService.php'];
$dataLogic = $sources['app/modules/official/reference_codes/src/Service/DictDataApplicationService.php'];
$tenantProvider = $sources['app/modules/official/reference_codes/src/Infrastructure/ThinkPhpTenantDictionaryProvider.php'];
expectReferenceCodes(str_contains($tenantProvider, 'Db::transaction('), 'type mutations must retain a transaction');
expectReferenceCodes(str_contains($tenantProvider, '->lock(true)'), 'type mutations must retain row locks');
expectReferenceCodes(
    str_contains($tenantProvider, "->where('type_id'")
        && str_contains($tenantProvider, "'type_value' =>"),
    'type rename must synchronize data.type_value',
);
expectReferenceCodes(
    str_contains($tenantProvider, '字典类型已被数据项使用，请先删除数据项'),
    'occupied type deletion must fail closed',
);
expectReferenceCodes(
    str_contains($tenantProvider, "->where('is_disable', 0)")
        && str_contains($dataLogic, 'enabledByType'),
    'byType must expose enabled data only',
);

foreach ($sources as $relativePath => $source) {
    expectReferenceCodes(
        !str_contains($source, 'PeanutAdmin\\ReferenceCodes'),
        'application dict owner must not deep import core: ' . $relativePath,
    );
    expectReferenceCodes(
        !str_contains($source, 'reference_code_'),
        'application dict owner must not bind core schema: ' . $relativePath,
    );
}

$zhLocale = (string) file_get_contents(
    $repositoryRoot . '/web/src/views/system/dict/locale/zh-CN.ts',
);
$enLocale = (string) file_get_contents(
    $repositoryRoot . '/web/src/views/system/dict/locale/en-US.ts',
);
expectReferenceCodes(str_contains($zhLocale, '存在字典数据时不能删除'), 'Chinese delete copy must describe rejection');
expectReferenceCodes(str_contains($enLocale, 'cannot be deleted while it contains data'), 'English delete copy must describe rejection');

echo "PB04-REFERENCE-CODES-HOST-001 passed\n";
