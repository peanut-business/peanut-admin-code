<?php

declare(strict_types=1);

namespace app\modules\official\notification\delivery\Sms;

final class DisabledSmsProvider implements SmsProvider
{
    public function key(): string
    {
        return 'disabled';
    }

    public function send(SmsSendRequest $request): SmsReceipt
    {
        throw SmsProviderException::permanent('SMS_PROVIDER_UNAVAILABLE');
    }
}
