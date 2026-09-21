<?php

declare(strict_types=1);

namespace app\modules\official\notification\delivery\Sms;

interface SmsRecipientResolver
{
    /** Resolves transient delivery data for an active member in one Tenant. */
    public function resolve(int $tenantId, int $memberId): SmsRecipient;
}
