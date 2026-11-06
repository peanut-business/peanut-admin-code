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
$storage = (new ReflectionClass(\PeanutAdmin\Modules\File\Service\Storage\StorageService::class))->newInstanceWithoutConstructor();
$fileService = new \PeanutAdmin\Modules\File\Service\FileService($storage, 'https://app.example');
$assets = new ProductAssetReferenceService($fileService, 'https://app.example');
$context = new \PeanutAdmin\Kernel\Context\TenantSystemContext(11, 'decoration-test', 'preview', 'fixture');
expectContentDecoration($assets->forStorage('') === '' && $assets->forRead('') === '', 'empty reference must stay empty');
try {
    $assets->forStorage('file_' . str_repeat('a', 32));
    throw new RuntimeException('unscoped file reference accepted');
} catch (DomainException $error) {
    expectContentDecoration($error->getMessage() === 'FILE_REFERENCE_TRUSTED_CONTEXT_REQUIRED', 'unexpected identity rejection');
}
foreach (['https://app.example/storage/cover.png', 'https://cdn.example/cover.png',
    'https://app.example.evil/storage/cover.png', '/storage/uploads/cover.png', 'https://app.example:8443/cover.png'] as $url) {
    expectContentDecoration($assets->forRead($url) === '', 'unowned URL must not resolve to an asset');
    try {
        $assets->forStorage($url, 'https://app.example', $context);
        throw new RuntimeException('URL accepted in place of a stable file key');
    } catch (\app\common\exception\BusinessException $error) {
        expectContentDecoration($error->errorCode === 'FILE_REFERENCE_INVALID', 'unexpected reference rejection');
    }
}
$schema = file_get_contents($serverRoot . '/app/common/services/decoration/DecorationSchemaService.php');
$page = file_get_contents($serverRoot . '/app/adminapi/services/decoration/DecorationPageApplicationService.php');
$tabbar = file_get_contents($serverRoot . '/app/adminapi/services/decoration/DecorationTabbarApplicationService.php');
$read = file_get_contents($serverRoot . '/app/common/services/decoration/DecorationReadService.php');
$controller = file_get_contents($serverRoot . '/app/api/controller/DecorationController.php');
foreach ([[$schema, 'ProductAssetReferenceService'], [$schema, '$articles === null || !$articles->visible($context, $articleId)'],
    [$page, 'DecoratePage::where([])'], [$page, '$this->decoration->formatPage'], [$page, '$this->decoration->pageByType'],
    [$tabbar, '->tabbar('], [$read, 'DecoratePage::where([])'], [$controller, 'publicTenantContext(']] as [$source, $required]) {
    expectContentDecoration(str_contains($source, $required), "missing decoration boundary: {$required}");
}
$baseline = file_get_contents($serverRoot . '/database/schema/baseline-schema.sql');
foreach (['uk_decorate_page_tenant_type', 'uk_decorate_tabbar_tenant_position'] as $index) {
    expectContentDecoration(str_contains($baseline, $index), "missing tenant-scoped uniqueness: {$index}");
}

echo "PB06-CONTENT-DECORATION-001 passed\n";
