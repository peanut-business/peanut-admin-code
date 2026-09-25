<?php

declare(strict_types=1);

namespace {
    final class FileMediaRequestStub
    {
        public function domain(): string
        {
            return 'https://admin.example.test';
        }
    }

    function request(): FileMediaRequestStub
    {
        return new FileMediaRequestStub();
    }

    function expectFileMedia(bool $condition, string $message): void
    {
        if (!$condition) {
            throw new RuntimeException($message);
        }
    }

    $serverRoot = dirname(__DIR__, 2);
    $repositoryRoot = dirname($serverRoot);

    require_once $serverRoot . '/vendor/autoload.php';
    require $serverRoot . '/app/modules/official/file/src/Service/FileService.php';

    $apiEvidence = json_decode((string) file_get_contents(
        $repositoryRoot . '/output/playwright/m02/api-db-summary.json',
    ), true, 512, JSON_THROW_ON_ERROR);
    $browserEvidence = json_decode((string) file_get_contents(
        $repositoryRoot . '/output/playwright/m02/browser-summary.json',
    ), true, 512, JSON_THROW_ON_ERROR);
    $storageEvidence = json_decode((string) file_get_contents(
        $repositoryRoot . '/output/playwright/s01/core-summary.json',
    ), true, 512, JSON_THROW_ON_ERROR);

    expectFileMedia(($apiEvidence['result'] ?? null) === 'passed', 'sealed M02 API/DB evidence must pass');
    foreach ([
        'category_tree_and_descendant_query',
        'image_video_file_upload',
        'wrong_extension_no_write',
        'cross_type_move_rejected_without_change',
        'file_and_storage_delete',
        'category_subtree_cascade',
        'permission_grant_and_revoke',
        'storage_column_and_composite_index',
    ] as $check) {
        expectFileMedia(($apiEvidence['assertions'][$check] ?? false) === true, 'missing M02 check: ' . $check);
    }
    foreach ($apiEvidence['cleanup'] ?? [] as $name => $count) {
        expectFileMedia($count === 0, 'M02 cleanup must be zero: ' . $name);
    }
    expectFileMedia(($browserEvidence['result'] ?? null) === 'passed', 'sealed M02 browser evidence must pass');
    expectFileMedia(($browserEvidence['fixtures_cleaned'] ?? false) === true, 'M02 browser fixtures must be clean');
    expectFileMedia(($storageEvidence['status'] ?? null) === 'passed', 'sealed S01 storage evidence must pass');
    expectFileMedia(
        ($storageEvidence['checks']['invalid_storage_engine_switch_rejected'] ?? false) === true,
        'invalid storage switch evidence missing',
    );
    expectFileMedia(($storageEvidence['configuration_restored'] ?? false) === true, 'S01 configuration must be restored');

    $fileService = (new ReflectionClass(\PeanutAdmin\Modules\File\Service\FileService::class))->newInstanceWithoutConstructor();
    expectFileMedia(
        $fileService->getFileUrl('https://cdn.example.test/a.png') === 'https://cdn.example.test/a.png',
        'absolute URL must remain unchanged',
    );

    $ownedFiles = [
        'app/modules/official/file/src/Service/FileService.php',
        'app/modules/official/file/src/Service/FileUploadService.php',
        'app/modules/official/file/src/Contract/FileUploads.php',
        'app/modules/official/file/src/Contract/Dto/UploadFile.php',
        'app/modules/official/file/src/ModuleProvider.php',
        'app/api/controller/UploadController.php',
        'app/modules/official/file/src/Controller/UploadController.php',
        'app/modules/official/file/src/Model/File.php',
        'app/modules/official/file/src/Service/FileAdministrationService.php',
        'app/modules/official/file/src/Contract/FileAdministration.php',
        'app/modules/official/file/src/Service/Storage/StorageService.php',
        'app/modules/official/file/src/Value/Storage/StoragePurpose.php',
        'app/modules/official/file/src/Composition/Storage/StorageDriverFactory.php',
        'app/modules/official/file/src/Infrastructure/Storage/ObservedStorageDriver.php',
        'app/modules/official/file/src/Value/Storage/StoragePath.php',
        'app/modules/official/file/src/Infrastructure/Storage/QiniuStorageHttpTransport.php',
    ];
    $sources = [];
    foreach ($ownedFiles as $relativePath) {
        $absolutePath = $serverRoot . '/' . $relativePath;
        expectFileMedia(is_file($absolutePath), 'missing application owner: ' . $relativePath);
        $sources[$relativePath] = (string) file_get_contents($absolutePath);
    }
    expectFileMedia(
        !str_contains($sources['app/modules/official/file/src/Model/File.php'], 'getUrlAttr')
            && str_contains(
                $sources['app/modules/official/file/src/Service/FileAdministrationService.php'],
                "\$this->files->getFileUrl((string) (\$item['file_key'] ?? ''))",
            ),
        'File presentation URL must be resolved by the application boundary from the canonical object key',
    );
    expectFileMedia(
        !is_file($serverRoot . '/app/common/service/UploadService.php')
            && str_contains($sources['app/modules/official/file/src/Service/FileUploadService.php'], '$this->storage->storePath(')
            && str_contains($sources['app/modules/official/file/src/ModuleProvider.php'], 'FileUploads::class'),
        'upload must be owned and explicitly bound by the File Module',
    );
    expectFileMedia(
        !str_contains($sources['app/modules/official/file/src/Service/FileUploadService.php'], 'request()->file')
            && !str_contains($sources['app/modules/official/file/src/Service/FileUploadService.php'], 'think\\file\\UploadedFile')
            && !str_contains($sources['app/modules/official/file/src/Contract/FileUploads.php'], 'think\\file\\UploadedFile')
            && substr_count($sources['app/modules/official/file/src/Service/FileUploadService.php'], 'UploadFile $uploaded') === 4,
        'FileUploadService must receive the framework-neutral upload value',
    );
    foreach ([
        'app/api/controller/UploadController.php',
        'app/modules/official/file/src/Controller/UploadController.php',
    ] as $controller) {
        expectFileMedia(
            str_contains($sources[$controller], "\$this->request->file('file')")
                && str_contains($sources[$controller], 'instanceof UploadedFile')
                && str_contains($sources[$controller], 'new UploadFile(')
                && str_contains($sources[$controller], 'FileUploads $uploads')
                && !str_contains($sources[$controller], 'catch ('),
            $controller . ' must adapt its UploadedFile at the HTTP boundary',
        );
    }
    expectFileMedia(
        str_contains($sources['app/modules/official/file/src/Service/FileAdministrationService.php'], '$this->storage->delete'),
        'delete must use the unified storage service',
    );
    expectFileMedia(
        str_contains($sources['app/modules/official/file/src/Service/Storage/StorageService.php'], 'routeRow(')
        && str_contains($sources['app/modules/official/file/src/Service/Storage/StorageService.php'], 'objectForTenant')
        && str_contains($sources['app/modules/official/file/src/Service/Storage/StorageService.php'], 'objectQuery(')
        && str_contains($sources['app/modules/official/file/src/Service/Storage/StorageService.php'], 'logicalTenantId(')
        && str_contains($sources['app/modules/official/file/src/Service/Storage/StorageService.php'], 'DefaultTenantContextResolver'),
        'sealed File Media evidence requires explicit Multi-tenant and Standalone object ownership predicates',
    );
    expectFileMedia(
        str_contains($sources['app/modules/official/file/src/Value/Storage/StoragePurpose.php'], "'material.image' => StorageAccess::PUBLIC")
        && str_contains($sources['app/modules/official/file/src/Value/Storage/StoragePurpose.php'], "'export.xlsx' => StorageAccess::PRIVATE")
        && str_contains($sources['app/modules/official/file/src/Value/Storage/StoragePurpose.php'], "'export.csv' => StorageAccess::PRIVATE"),
        'public/private purpose routing changed',
    );
    expectFileMedia(
        !is_file($serverRoot . '/app/common/service/storage/StorageDriver.php')
            && !is_file($serverRoot . '/app/common/service/storage/driver/LocalStorageDriver.php')
            && !is_file($serverRoot . '/app/common/service/storage/driver/AliyunStorageDriver.php')
            && !is_file($serverRoot . '/app/common/service/storage/driver/QcloudStorageDriver.php')
            && !is_file($serverRoot . '/app/common/service/storage/driver/QiniuStorageDriver.php'),
        'application must consume the single Core storage Driver implementation',
    );
    expectFileMedia(
        str_contains($sources['app/modules/official/file/src/Composition/Storage/StorageDriverFactory.php'], 'new LocalStorageDriver(')
            && str_contains($sources['app/modules/official/file/src/Composition/Storage/StorageDriverFactory.php'], 'new AliyunStorageDriver(')
            && str_contains($sources['app/modules/official/file/src/Composition/Storage/StorageDriverFactory.php'], 'new QcloudStorageDriver(')
            && str_contains($sources['app/modules/official/file/src/Composition/Storage/StorageDriverFactory.php'], 'new QiniuStorageDriver(')
            && str_contains($sources['app/modules/official/file/src/Value/Storage/StoragePath.php'], 'StorageObjectKey::assert(')
            && str_contains($sources['app/modules/official/file/src/Service/Storage/StorageService.php'], 'StorageObjectKey::assert(')
            && str_contains($sources['app/modules/official/file/src/Infrastructure/Storage/QiniuStorageHttpTransport.php'], 'implements StorageHttpTransport'),
        'application storage assembly must use only the frozen Core technical boundary',
    );
    $allowedCoreStorageImports = [
        'PeanutAdmin\\FileMedia\\Storage\\Driver\\AliyunStorageDriver',
        'PeanutAdmin\\FileMedia\\Storage\\Driver\\LocalStorageDriver',
        'PeanutAdmin\\FileMedia\\Storage\\Driver\\QcloudStorageDriver',
        'PeanutAdmin\\FileMedia\\Storage\\Driver\\QiniuStorageDriver',
        'PeanutAdmin\\FileMedia\\Storage\\StorageDriver',
        'PeanutAdmin\\FileMedia\\Storage\\StorageHttpTransport',
        'PeanutAdmin\\FileMedia\\Storage\\StorageObjectKey',
        'PeanutAdmin\\FileMedia\\Storage\\TenantObjectNamespace',
    ];
    foreach ($sources as $relativePath => $source) {
        preg_match_all('/PeanutAdmin\\\\FileMedia\\\\[A-Za-z0-9_\\\\]+/', $source, $coreImports);
        foreach ($coreImports[0] as $coreImport) {
            expectFileMedia(
                in_array($coreImport, $allowedCoreStorageImports, true),
                'application may import only Core technical storage Drivers: ' . $relativePath . ' -> ' . $coreImport,
            );
        }
    }

    // Verify the application can actually assemble every retained provider from
    // the installed Core package. The sealed historical evidence above does not
    // prove that current Composer autoloading or constructor contracts still work.
    $credentialResolver = new class implements \app\common\contract\storage\StorageCredentialResolver {
        /** @var list<string> */
        public array $resolvedDrivers = [];

        public function resolve(array $account): array
        {
            $this->resolvedDrivers[] = (string) ($account['driver'] ?? '');
            return ['access_key' => 'fixture-access-key', 'secret_key' => 'fixture-secret-key'];
        }
    };
    $outboundTransport = new class implements \app\common\contract\http\OutboundHttpTransport {
        public function send(\app\common\value\http\OutboundHttpRequest $request): \app\common\value\http\OutboundHttpResponse
        {
            throw new RuntimeException('provider assembly must not perform network I/O');
        }
    };
    $factory = new \PeanutAdmin\Modules\File\Composition\Storage\StorageDriverFactory(
        $credentialResolver,
        new \PeanutAdmin\Modules\File\Infrastructure\Storage\QiniuStorageHttpTransport($outboundTransport),
        new \PeanutAdmin\Modules\File\Composition\Storage\AliyunStorageClientFactory(),
        new \PeanutAdmin\Modules\File\Composition\Storage\QcloudStorageClientFactory(
            new \app\common\execution\CurrentExecutionContext(new \app\common\execution\ExecutionContextStore()),
        ),
        new \app\common\execution\CurrentExecutionContext(new \app\common\execution\ExecutionContextStore()),
        new \think\App($serverRoot . DIRECTORY_SEPARATOR),
    );
    $delegateProperty = new ReflectionProperty(\PeanutAdmin\Modules\File\Infrastructure\Storage\ObservedStorageDriver::class, 'delegate');
    foreach ([
        'local' => [
            ['driver' => 'local'],
            ['local_path' => 'private/storage', 'access_type' => \PeanutAdmin\Modules\File\Infrastructure\Storage\StorageAccess::PRIVATE],
            \PeanutAdmin\FileMedia\Storage\Driver\LocalStorageDriver::class,
        ],
        'qiniu' => [
            ['driver' => 'qiniu'],
            ['bucket' => 'fixture', 'endpoint' => '', 'access_domain' => 'https://cdn.example.test/'],
            \PeanutAdmin\FileMedia\Storage\Driver\QiniuStorageDriver::class,
        ],
        'aliyun' => [
            ['driver' => 'aliyun'],
            ['bucket' => 'fixture', 'endpoint' => 'https://oss-cn-hangzhou.aliyuncs.com'],
            \PeanutAdmin\FileMedia\Storage\Driver\AliyunStorageDriver::class,
        ],
        'qcloud' => [
            ['driver' => 'qcloud'],
            ['bucket' => 'fixture-1250000000', 'region' => 'ap-guangzhou'],
            \PeanutAdmin\FileMedia\Storage\Driver\QcloudStorageDriver::class,
        ],
    ] as $provider => [$account, $space, $expectedDriver]) {
        $driver = $factory->make($account, $space);
        expectFileMedia($driver instanceof \PeanutAdmin\FileMedia\Storage\StorageDriver, $provider . ' did not produce a Core driver');
        expectFileMedia($delegateProperty->getValue($driver) instanceof $expectedDriver, $provider . ' application assembly drifted');
    }
    expectFileMedia(
        $credentialResolver->resolvedDrivers === ['qiniu', 'aliyun', 'qcloud'],
        'cloud credentials must resolve per assembly while local storage remains credential-free',
    );

    echo "PB04-FILE-MEDIA-HOST-001 passed\n";
}
