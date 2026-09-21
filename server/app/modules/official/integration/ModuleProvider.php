<?php
declare(strict_types=1);

namespace app\modules\official\integration;

use app\modules\official\integration\application\MachineScopeCatalog;
use app\modules\official\integration\contracts\ExternalChannelBindingStore;
use app\modules\official\integration\contracts\ExternalChannelBindings;
use app\modules\official\integration\contracts\ExternalTenantAudit;
use app\modules\official\integration\contracts\ExternalTenantBindingRepository;
use app\modules\official\integration\contracts\ExternalTenantResolutionService;
use app\modules\official\integration\contracts\IntegrationSecurityRepository;
use app\modules\official\integration\contracts\MachineScopeGrantResolver;
use app\modules\official\integration\infrastructure\ConfiguredMachineScopeGrantResolver;
use app\modules\official\integration\infrastructure\ThinkPhpExternalTenantAudit;
use app\modules\official\integration\infrastructure\ThinkPhpExternalTenantBindingRepository;
use app\modules\official\integration\infrastructure\UnavailableWebhookSecretProtector;
use app\modules\official\integration\infrastructure\persistence\ThinkPhpIntegrationSecurityRepository;
use app\modules\official\integration\services\ExternalChannelBindingService;
use app\modules\official\integration\services\ExternalTenantResolver;
use PeanutAdmin\IntegrationSecurity\Crypto\AesGcmWebhookSecretProtector;
use PeanutAdmin\IntegrationSecurity\Crypto\WebhookSecretProtector;
use PeanutAdmin\IntegrationSecurity\Webhook\HostAddressResolver;
use PeanutAdmin\IntegrationSecurity\Webhook\SystemHostAddressResolver;
use PeanutAdmin\IntegrationSecurity\Webhook\WebhookDestinationPolicy;
use PeanutAdmin\Kernel\Module\ModuleProvider as ModuleProviderContract;
use think\App;

final class ModuleProvider implements ModuleProviderContract
{
    public function moduleKey(): string
    {
        return 'official.integration';
    }

    public function bindings(): array
    {
        return [
            ExternalTenantAudit::class => ThinkPhpExternalTenantAudit::class,
            ExternalTenantBindingRepository::class => ThinkPhpExternalTenantBindingRepository::class,
            ExternalChannelBindingStore::class => ThinkPhpExternalTenantBindingRepository::class,
            ExternalTenantResolutionService::class => ExternalTenantResolver::class,
            ExternalChannelBindings::class => ExternalChannelBindingService::class,
            IntegrationSecurityRepository::class => ThinkPhpIntegrationSecurityRepository::class,
            HostAddressResolver::class => SystemHostAddressResolver::class,
            WebhookDestinationPolicy::class => fn(App $app): WebhookDestinationPolicy => new WebhookDestinationPolicy(
                $app->make(HostAddressResolver::class),
            ),
            WebhookSecretProtector::class => fn(App $app): WebhookSecretProtector => $this->webhookSecrets($app),
            MachineScopeCatalog::class => fn(App $app): MachineScopeCatalog => new MachineScopeCatalog(
                $this->machineScopes($app),
            ),
            MachineScopeGrantResolver::class => fn(App $app): MachineScopeGrantResolver => new ConfiguredMachineScopeGrantResolver(
                $this->machineScopes($app),
            ),
        ];
    }

    /** @return list<string> */
    private function machineScopes(App $app): array
    {
        $scopes = [];
        foreach (explode(',', (string)$app->config->get('integration.machine_scopes', '')) as $scope) {
            $scope = trim($scope);
            if ($scope !== '') {
                $scopes[$scope] = true;
            }
        }
        $result = array_keys($scopes);
        sort($result, SORT_STRING);
        return $result;
    }

    private function webhookSecrets(App $app): WebhookSecretProtector
    {
        $keyId = trim((string)$app->config->get('integration.webhook_secret_key_id', ''));
        $key = trim((string)$app->config->get('integration.webhook_secret_key', ''));
        if ($keyId === '' || $key === '') {
            return new UnavailableWebhookSecretProtector();
        }
        try {
            return new AesGcmWebhookSecretProtector($keyId, $key);
        } catch (\Throwable) {
            return new UnavailableWebhookSecretProtector();
        }
    }
}
