<?php

declare(strict_types=1);

namespace app\platform\infrastructure\provider;

use app\platform\value\provider\ProviderQualificationSubject;

final class NotificationQualificationContributor extends AbstractTenantBindingQualificationContributor
{
    protected function definitions(): array
    {
        return [
            ['provider_key' => 'notification.sms.aliyun', 'binding_provider' => 'notice.sms',
                'category' => 'notification', 'callback_required' => false],
            ['provider_key' => 'notification.sms.tencent', 'binding_provider' => 'notice.sms',
                'category' => 'notification', 'callback_required' => false],
        ];
    }

    public function subjects(): array
    {
        $subjects = parent::subjects();
        $tenantIds = [];
        foreach ($subjects as $subject) {
            $tenantIds[$subject->tenantId] = true;
        }
        foreach (array_keys($tenantIds) as $tenantId) {
            $providerKey = 'notification.email';
            $subjects[] = new ProviderQualificationSubject(
                $providerKey,
                'notification',
                'tenant',
                (int) $tenantId,
                $providerKey,
                false,
                false,
                null,
                hash('sha256', 'not-implemented:' . $tenantId . ':' . $providerKey),
                false,
            );
        }
        return $subjects;
    }

    protected function configured(string $providerKey, array $config, int $bindingStatus): bool
    {
        if ($bindingStatus !== 1) {
            return false;
        }
        $section = $providerKey === 'notification.sms.aliyun' ? 'sms_aliyun' : 'sms_tencent';
        $provider = $config[$section] ?? [];
        if (is_string($provider)) {
            $provider = json_decode($provider, true);
        }
        if (!is_array($provider) || (int) ($provider['status'] ?? 0) !== 1) {
            return false;
        }
        return $providerKey === 'notification.sms.aliyun'
            ? $this->complete($provider, ['access_key_id', 'access_key_secret', 'sign_name'])
            : $this->complete($provider, ['secret_id', 'secret_key', 'sdk_app_id', 'sign_name', 'region']);
    }
}
