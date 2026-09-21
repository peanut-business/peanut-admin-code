<?php
declare(strict_types=1);

use app\common\services\ProductAssetReferenceService;

require dirname(__DIR__, 2) . '/vendor/autoload.php';

function expectContentDecoration(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$serverRoot = dirname(__DIR__, 2);
$repositoryRoot = dirname($serverRoot);
$fileService = (new ReflectionClass(\app\modules\official\file\services\FileService::class))->newInstanceWithoutConstructor();
$assetReferences = new ProductAssetReferenceService($fileService, 'https://app.example');

expectContentDecoration(
    $assetReferences->forStorage(
        'https://app.example/storage/uploads/cover.png',
        'https://app.example'
    ) === 'storage/uploads/cover.png',
    'same-origin local storage URL must become a relative URI'
);
expectContentDecoration(
    $assetReferences->forStorage(
        'https://cdn.example/uploads/cover.png',
        'https://app.example'
    ) === 'https://cdn.example/uploads/cover.png',
    'cloud/CDN provenance must remain absolute'
);
expectContentDecoration(
    $assetReferences->forStorage(
        'https://app.example.evil/storage/cover.png',
        'https://app.example'
    ) === 'https://app.example.evil/storage/cover.png',
    'lookalike origin must not be stripped'
);
expectContentDecoration(
    $assetReferences->forStorage('/storage/uploads/cover.png', 'https://app.example')
        === 'storage/uploads/cover.png',
    'relative local resource normalization changed'
);
expectContentDecoration(
    $assetReferences->forStorage(
        'https://app.example:8443/storage/uploads/cover.png',
        'https://app.example'
    ) === 'https://app.example:8443/storage/uploads/cover.png',
    'different effective ports must not be treated as the same origin'
);
expectContentDecoration(
    $assetReferences->forRead('HTTPS://cdn.example/uploads/cover.png')
        === 'HTTPS://cdn.example/uploads/cover.png',
    'HTTP scheme casing must not turn an absolute resource into a relative URI'
);

$articleModel = (string)file_get_contents($serverRoot . '/app/Modules/Official/Article/Model/Article.php');
$decorationSchema = (string)file_get_contents(
    $serverRoot . '/app/common/service/decoration/DecorationSchemaService.php'
);
foreach ([$articleModel, $decorationSchema] as $source) {
    expectContentDecoration(
        str_contains($source, 'ProductAssetReferenceService'),
        'content or decoration bypasses the product asset owner'
    );
    expectContentDecoration(
        !str_contains($source, 'FileService::setFileUrl'),
        'product reference still strips the current cloud provider domain'
    );
    expectContentDecoration(
        !str_contains($source, 'PeanutAdmin\\'),
        'application content/decoration owner deep imports core'
    );
}

$articleValidate = (string)file_get_contents(
    $serverRoot . '/app/Modules/Official/Article/Validation/ArticleValidate.php'
);
expectContentDecoration(
    str_contains($articleValidate, "'cid'        => 'require|integer|gt:0|checkCategory'"),
    'article category ownership validation is missing'
);
expectContentDecoration(
    str_contains($articleValidate, 'ArticleCate::where([])'),
    'article category existence bypasses Tenant-first ownership'
);

$articleAdministration = (string)file_get_contents(
    $serverRoot . '/app/Modules/Official/Article/Application/ArticleAdministrationService.php'
);
expectContentDecoration(str_contains($articleAdministration, 'ArticleCate::where([])'), 'category delete bypasses Tenant-first ownership');
expectContentDecoration(str_contains($articleAdministration, 'Article::where([])'), 'occupied category check bypasses Tenant-first ownership');
expectContentDecoration(str_contains($articleAdministration, 'lock(true)'), 'category delete must lock tenant-owned rows');
expectContentDecoration(str_contains($articleAdministration, 'Article::create('), 'article create bypasses Tenant-first ownership');

$pageLogic = (string)file_get_contents(
    $serverRoot . '/app/adminapi/application/decoration/DecorationPageApplicationService.php'
);
$tabbarLogic = (string)file_get_contents(
    $serverRoot . '/app/adminapi/application/decoration/DecorationTabbarApplicationService.php'
);
expectContentDecoration(str_contains($pageLogic, 'DecorationReadService::formatPage'), 'admin page detail bypasses shared read DTO');
expectContentDecoration(str_contains($pageLogic, 'DecorationReadService::pageByType'), 'admin type detail bypasses shared read DTO');
expectContentDecoration(str_contains($pageLogic, 'DecoratePage::where([])'), 'admin decoration page bypasses Tenant-first ownership');
expectContentDecoration(!str_contains($pageLogic, 'resourcesForRead'), 'admin page keeps duplicate resource formatting');
expectContentDecoration(str_contains($tabbarLogic, 'DecorationReadService::tabbar('), 'admin tabbar bypasses shared read DTO');

foreach ([
    'app/api/controller/DecorationController.php',
    'app/api/application/IndexApplicationService.php',
    'app/api/application/PcApplicationService.php',
] as $relativePath) {
    $source = (string)file_get_contents($serverRoot . '/' . $relativePath);
    expectContentDecoration(
        str_contains($source, 'DecorationReadService'),
        'client-facing decoration bypasses the shared read DTO: ' . $relativePath
    );
}

$decorationRead = (string)file_get_contents(
    $serverRoot . '/app/common/service/decoration/DecorationReadService.php'
);
expectContentDecoration(
    str_contains($decorationRead, 'DecoratePage::where([])'),
    'shared decoration page read bypasses Tenant-first ownership'
);

$assetSchema = (string)file_get_contents(
    $serverRoot . '/database/init.sql'
);
expectContentDecoration(
    preg_match('/`image`\s+VARCHAR\(2048\)/i', $assetSchema) === 1
        || str_contains($assetSchema, 'MODIFY COLUMN `image` VARCHAR(2048)'),
    'article cover cannot hold stable absolute provenance'
);
expectContentDecoration(
    (preg_match('/`selected`\s+VARCHAR\(2048\)/i', $assetSchema) === 1
        || str_contains($assetSchema, 'MODIFY COLUMN `selected` VARCHAR(2048)'))
        && (preg_match('/`unselected`\s+VARCHAR\(2048\)/i', $assetSchema) === 1
        || str_contains($assetSchema, 'MODIFY COLUMN `unselected` VARCHAR(2048)')),
    'tabbar icons cannot hold stable absolute provenance'
);
$initSql = (string)file_get_contents($serverRoot . '/database/init.sql');
expectContentDecoration(
    str_contains($initSql, 'uk_member_article')
        || str_contains($initSql, 'uk_article_collect_tenant_member_article'),
    'article collection idempotency key is missing'
);
$decorationMigration = (string)file_get_contents(
    $serverRoot . '/database/init.sql'
);
expectContentDecoration(
    str_contains($decorationMigration, 'uk_decorate_page_type')
        || str_contains($decorationMigration, 'uk_decorate_page_tenant_type'),
    'decoration page type owner is not unique'
);
expectContentDecoration(
    str_contains($decorationMigration, 'uk_decorate_tabbar_position')
        || str_contains($decorationMigration, 'uk_decorate_tabbar_tenant_position'),
    'tabbar position owner is not unique'
);

$articleEvidence = json_decode((string)file_get_contents(
    $repositoryRoot . '/output/playwright/c02/peanut.json'
), true, 512, JSON_THROW_ON_ERROR);
expectContentDecoration(($articleEvidence['ok'] ?? false) === true, 'sealed article evidence is not passed');
foreach (['admin_crud', 'mobile', 'status_flow', 'collection', 'soft_delete', 'cleanup'] as $check) {
    expectContentDecoration(
        ($articleEvidence['checks'][$check] ?? false) === true,
        'sealed article evidence is missing: ' . $check
    );
}
expectContentDecoration(
    ($articleEvidence['checks']['detail_click_delta'] ?? 0) === 1,
    'sealed article detail counter evidence changed'
);
expectContentDecoration(($articleEvidence['baseline_restored'] ?? false) === true, 'article fixtures were not restored');

$categoryEvidence = json_decode((string)file_get_contents(
    $repositoryRoot . '/output/playwright/c01/invariants/peanut.json'
), true, 512, JSON_THROW_ON_ERROR);
expectContentDecoration(($categoryEvidence['passed'] ?? false) === true, 'sealed category evidence is not passed');
expectContentDecoration(
    ($categoryEvidence['delete_state_flow']['occupied_delete_left_category_and_article_unchanged'] ?? false) === true,
    'sealed occupied category delete evidence changed'
);
expectContentDecoration(
    ($categoryEvidence['data_model']['category_status_did_not_change_article_is_show'] ?? false) === true,
    'sealed category/article status independence changed'
);

$decorationEvidence = json_decode((string)file_get_contents(
    $repositoryRoot . '/output/playwright/de01-de02/frontend-summary.json'
), true, 512, JSON_THROW_ON_ERROR);
expectContentDecoration(($decorationEvidence['ok'] ?? false) === true, 'sealed decoration evidence is not passed');
foreach ([
    'mobile_banner_saved_and_immediately_consumed',
    'dynamic_tabbar_saved_and_immediately_consumed',
    'pc_banner_saved_and_immediately_consumed',
    'decoration_state_restored',
] as $check) {
    expectContentDecoration(
        ($decorationEvidence['checks'][$check] ?? false) === true,
        'sealed decoration evidence is missing: ' . $check
    );
}

echo "PB06-CONTENT-DECORATION-001 passed\n";
