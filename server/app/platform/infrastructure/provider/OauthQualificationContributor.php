<?php

declare(strict_types=1);

namespace app\platform\infrastructure\provider;

final class OauthQualificationContributor extends AbstractTenantBindingQualificationContributor
{
    protected function definitions(): array
    {
        return array_map(static fn(string $provider): array => [
            'provider_key' => $provider,
            'binding_provider' => $provider,
            'category' => 'oauth',
            'callback_required' => true,
        ], [
            'wechat.official-account',
            'oauth.wechat.oa',
            'oauth.wechat.mini-program',
            'oauth.wechat.open-pc',
        ]);
    }

    protected function configured(string $providerKey, array $config, int $bindingStatus): bool
    {
        if ($bindingStatus !== 1) {
            return false;
        }
        $required = $providerKey === 'wechat.official-account'
            ? ['app_id', 'app_secret', 'token']
            : ['app_id', 'app_secret'];
        return $this->complete($config, $required);
    }
}
