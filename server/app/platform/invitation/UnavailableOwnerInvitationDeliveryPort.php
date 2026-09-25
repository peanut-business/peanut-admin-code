<?php

declare(strict_types=1);

namespace app\platform\invitation;

final class UnavailableOwnerInvitationDeliveryPort implements OwnerInvitationDeliveryPort
{
    public function isConfigured(): bool
    {
        return false;
    }

    public function deliver(OwnerInvitationDelivery $delivery): OwnerInvitationDeliveryResult
    {
        return OwnerInvitationDeliveryResult::pending();
    }
}
