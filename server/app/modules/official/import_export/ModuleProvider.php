<?php
declare(strict_types=1);

namespace app\modules\official\import_export;

use app\common\persistence\TenantPersistenceConfiguration;
use app\common\services\audit\AuditContractHost;
use app\common\services\authorization\AdminAuthorizationService;
use app\common\infrastructure\export\OperationLogExportProvider;
use app\modules\official\import_export\services\configurationTransferApplicationService;
use app\modules\official\import_export\services\ImportExportApplicationService;
use app\modules\official\import_export\services\ImportExportTaskWorkerDefinition;
use app\modules\official\import_export\services\TaskImportExportRuntime;
use app\modules\official\import_export\contracts\configurationTransferCommands;
use app\modules\official\import_export\contracts\configurationTransferQueries;
use app\modules\official\import_export\contracts\ImportExportCommands;
use app\modules\official\import_export\contracts\ImportExportQueries;
use app\modules\official\import_export\contracts\ImportExportWorkerRuntime;
use app\modules\official\import_export\infrastructure\authorization\AdminAsyncAuthorization;
use app\modules\official\import_export\infrastructure\configuration\configurationPackageCodec;
use app\modules\official\import_export\infrastructure\configuration\CoreSettingsConfigurationAdapter;
use app\modules\official\import_export\infrastructure\configuration\ExternalBindingConfigurationAdapter;
use app\modules\official\import_export\infrastructure\configuration\TenantModuleConfigurationAdapter;
use app\modules\official\import_export\infrastructure\configuration\TenantSettingsConfigurationAdapter;
use app\modules\official\import_export\infrastructure\file\AppFileMediaGateway;
use app\modules\official\task\contracts\TaskJobRuntime;
use app\modules\official\import_export\engine\Application\ImportExportService;
use app\modules\official\import_export\engine\Contract\DataProviderRegistry;
use app\modules\official\import_export\engine\Execution\CsvOperationRunner;
use app\modules\official\import_export\engine\Execution\ImportExportTaskHandler;
use app\modules\official\import_export\engine\Execution\ImportExportTaskSubmissionProvider;
use PeanutAdmin\Kernel\Module\ModuleProvider as ModuleProviderContract;
use app\modules\official\settings\contracts\DeploymentSettingsTransfer;
use think\App;

final class ModuleProvider implements ModuleProviderContract
{
    public function moduleKey(): string
    {
        return 'official.import-export';
    }

    public function bindings(): array
    {
        return [
            ImportExportApplicationService::class => function (App $app): ImportExportApplicationService {
                $persistence = $app->make(TenantPersistenceConfiguration::class);
                $tasks = $app->make(TaskJobRuntime::class);
                return new ImportExportApplicationService(new ImportExportService(
                    new \app\modules\official\import_export\engine\Persistence\ImportExportStore($persistence->mode, $persistence->instanceTenantId),
                    new DataProviderRegistry([new OperationLogExportProvider()]),
                    $tasks->publisher(new ImportExportTaskSubmissionProvider()),
                    $tasks->jobs(),
                    $app->make(AuditContractHost::class),
                ));
            },
            ImportExportCommands::class => ImportExportApplicationService::class,
            ImportExportQueries::class => ImportExportApplicationService::class,
            ConfigurationTransferApplicationService::class => function (App $app): ConfigurationTransferApplicationService {
                return new ConfigurationTransferApplicationService(
                    [
                        $app->make(TenantSettingsConfigurationAdapter::class),
                        $app->make(TenantModuleConfigurationAdapter::class),
                        $app->make(ExternalBindingConfigurationAdapter::class),
                        new CoreSettingsConfigurationAdapter($app->make(DeploymentSettingsTransfer::class)),
                    ],
                    new ConfigurationPackageCodec(),
                    $app->make(AuditContractHost::class),
                );
            },
            ConfigurationTransferCommands::class => ConfigurationTransferApplicationService::class,
            ConfigurationTransferQueries::class => ConfigurationTransferApplicationService::class,
            ImportExportTaskWorkerDefinition::class => function (App $app): ImportExportTaskWorkerDefinition {
                $persistence = $app->make(TenantPersistenceConfiguration::class);
                return new ImportExportTaskWorkerDefinition(
                    new ImportExportTaskHandler(new CsvOperationRunner(
                        new \app\modules\official\import_export\engine\Persistence\ImportExportStore($persistence->mode, $persistence->instanceTenantId),
                        new DataProviderRegistry([new OperationLogExportProvider()]),
                        $app->make(AppFileMediaGateway::class),
                        $app->make(AuditContractHost::class),
                    )),
                    new AdminAsyncAuthorization($app->make(AdminAuthorizationService::class)),
                );
            },
            ImportExportWorkerRuntime::class => TaskImportExportRuntime::class,
        ];
    }

}
