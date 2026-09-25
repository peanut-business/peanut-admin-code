<?php

declare(strict_types=1);

namespace app\platform\infrastructure\provider;

final class PaymentQualificationContributor extends AbstractTenantBindingQualificationContributor
{
    protected function definitions(): array
    {
        return [
            ['provider_key' => 'payment.wechat', 'binding_provider' => 'payment.wechat',
                'category' => 'payment', 'callback_required' => true],
            ['provider_key' => 'payment.alipay', 'binding_provider' => 'payment.alipay',
                'category' => 'payment', 'callback_required' => true],
        ];
    }

    protected function configured(string $providerKey, array $config, int $bindingStatus): bool
    {
        if ($bindingStatus !== 1) {
            return false;
        }
        return $providerKey === 'payment.wechat'
            ? (int) ($config['wx_pay_status'] ?? 0) === 1 && $this->complete($config, [
                'wx_pay_appid', 'wx_pay_mch_id', 'wx_pay_secret', 'wx_pay_cert_path',
                'wx_pay_cert_key_path', 'wx_pay_platform_cert_path',
            ])
            : (int) ($config['ali_pay_status'] ?? 0) === 1 && $this->complete($config, [
                'ali_pay_app_id', 'ali_pay_private_key', 'ali_pay_public_key', 'ali_pay_seller_id',
            ]);
    }
}
