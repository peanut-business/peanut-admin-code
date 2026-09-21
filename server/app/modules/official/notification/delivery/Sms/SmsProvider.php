<?php

declare(strict_types=1);

namespace app\modules\official\notification\delivery\Sms;

interface SmsProvider
{
    public function key(): string;

    /** The provider must deduplicate retries by SmsSendRequest::idempotencyKey(). */
    public function send(SmsSendRequest $request): SmsReceipt;
}
