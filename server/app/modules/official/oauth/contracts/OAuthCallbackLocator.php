<?php
declare(strict_types=1);

namespace app\modules\official\oauth\contracts;

use app\modules\official\integration\contracts\ExternalTenantBinding;

interface OAuthCallbackLocator
{
    /** @return list<ExternalTenantBinding> */
    public function locateState(string $provider, string $stateHash): array;

    /** @return list<ExternalTenantBinding> */
    public function locateTicket(string $ticketHash): array;
}
