<?php
declare(strict_types=1);

namespace app\modules\official\settings;

use app\common\contract\idempotency\IdempotentCommandExecutor;
use app\common\persistence\TenantPersistenceConfiguration;
use app\common\tenancy\DataScopePolicy;
use app\modules\official\settings\Application\SettingAdminService;
use app\modules\official\settings\Application\SettingResolver;
use app\modules\official\settings\Cache\ArrayRevisionedSettingCache;
use app\modules\official\settings\contracts\TenantSettingsCommands;
use app\modules\official\settings\contracts\TenantApplicationSettings;
use app\modules\official\settings\contracts\DeploymentSettingsTransfer;
use app\modules\official\settings\contracts\TenantSettingsProvider;
use app\modules\official\settings\contracts\TenantSettingsQuery;
use app\modules\official\settings\infrastructure\ThinkPhpTenantSettingsProvider;
use app\modules\official\settings\infrastructure\UnavailableSecretProtector;
use app\modules\official\settings\Definition\DeployedSettingDefinitionRegistry;
use app\modules\official\settings\Definition\SettingDefinitionRegistry;
use app\modules\official\settings\services\SettingsHttpApplicationService;
use app\modules\official\settings\services\TenantSettingService;
use app\modules\official\settings\services\TenantApplicationSettingService;
use app\modules\official\settings\services\DeploymentSettingsTransferService;
use PeanutAdmin\Kernel\Module\ModuleProvider as ModuleProviderContract;
use PeanutAdmin\Kernel\Module\ModuleRuntimeRepository;
use PeanutAdmin\Settings\Secret\SecretProtector;
use PeanutAdmin\Settings\Secret\SodiumSecretProtector;
use think\App;
use Throwable;

final class ModuleProvider implements ModuleProviderContract
{
    public function moduleKey(): string
    {
        return 'official.settings';
    }

    public function bindings(): array
    {
        return [
            TenantSettingsProvider::class => fn(App $app): TenantSettingsProvider => new ThinkPhpTenantSettingsProvider(
                $app->make(DataScopePolicy::class),
            ),
            TenantSettingService::class => fn(App $app): TenantSettingService => new TenantSettingService(
                $app->make(TenantSettingsProvider::class),
            ),
            TenantSettingsCommands::class => TenantSettingService::class,
            TenantSettingsQuery::class => TenantSettingService::class,
            TenantApplicationSettings::class => TenantApplicationSettingService::class,
            DeploymentSettingsTransfer::class => fn(App $app): DeploymentSettingsTransfer => new DeploymentSettingsTransferService(
                $app->make(SettingDefinitionRegistry::class), $app->make(SettingAdminService::class),
            ),
            SecretProtector::class => fn(App $app): SecretProtector => $this->secretProtector($app),
            SettingDefinitionRegistry::class => fn(App $app): SettingDefinitionRegistry =>
                $app->make(DeployedSettingDefinitionRegistry::class)->build(),
            SettingAdminService::class => function (App $app): SettingAdminService {
                $persistence = $app->make(TenantPersistenceConfiguration::class);
                return new SettingAdminService($app->make(SecretProtector::class), $persistence->mode, $persistence->instanceTenantId);
            },
            SettingResolver::class => function (App $app): SettingResolver {
                $persistence = $app->make(TenantPersistenceConfiguration::class);
                return new SettingResolver(
                    $app->make(SecretProtector::class), new ArrayRevisionedSettingCache(),
                    $persistence->mode, $persistence->instanceTenantId,
                );
            },
            SettingsHttpApplicationService::class => fn(App $app): SettingsHttpApplicationService => new SettingsHttpApplicationService(
                $app->make(SettingDefinitionRegistry::class),
                $app->make(SettingAdminService::class),
                $app->make(SettingResolver::class),
                $app->make(IdempotentCommandExecutor::class),
                $app->make(ModuleRuntimeRepository::class),
            ),
        ];
    }

    private function secretProtector(App $app): SecretProtector
    {
        $encoded = trim((string)$app->config->get('peanut.settings_secrets.keys', ''));
        $activeKeyId = trim((string)$app->config->get('peanut.settings_secrets.active_key_id', ''));
        if ($encoded === '' || $activeKeyId === '') return new UnavailableSecretProtector();
        try {
            return SodiumSecretProtector::fromJson($encoded, $activeKeyId);
        } catch (Throwable) {
            return new UnavailableSecretProtector();
        }
    }
}
