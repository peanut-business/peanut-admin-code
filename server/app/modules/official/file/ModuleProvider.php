<?php
declare(strict_types=1);

namespace app\modules\official\file;

use app\modules\official\file\services\FileAdministrationService;
use app\modules\official\file\services\FileUploadService;
use app\modules\official\file\contracts\FileAdministration;
use app\modules\official\file\contracts\FileUploads;
use app\modules\official\file\contracts\FileStorage;
use app\modules\official\file\contracts\FileReferences;
use app\modules\official\file\contracts\FileDerivatives;
use app\modules\official\file\contracts\StorageConfiguration;
use app\modules\official\file\services\FileService;
use app\modules\official\file\services\FileDerivativeService;
use app\modules\official\file\services\storage\StorageService;
use app\modules\official\file\services\storage\StorageConfigurationService;
use PeanutAdmin\Kernel\Module\ModuleProvider as ModuleProviderContract;

final class ModuleProvider implements ModuleProviderContract
{
    public function moduleKey(): string
    {
        return 'official.file';
    }

    public function bindings(): array
    {
        return [
            FileAdministration::class => FileAdministrationService::class,
            FileUploads::class => FileUploadService::class,
            FileStorage::class => StorageService::class,
            FileReferences::class => FileService::class,
            FileDerivatives::class => FileDerivativeService::class,
            StorageConfiguration::class => StorageConfigurationService::class,
        ];
    }
}
