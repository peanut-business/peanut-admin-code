<?php

declare(strict_types=1);

namespace PeanutAdmin\Modules\Notification\Delivery\Sms;

interface SmsRecipientResolver
{
    /** Resolves transient delivery data for an active member in one Tenant. */
    public function resolve(int $tenantId, int $memberId): SmsRecipient;
}
