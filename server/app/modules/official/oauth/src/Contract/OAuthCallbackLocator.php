<?php
declare(strict_types=1);

namespace PeanutAdmin\Modules\OAuth\Contract;

use PeanutAdmin\Modules\Integration\Contract\ExternalTenantBinding;

interface OAuthCallbackLocator
{
    /** @return list<ExternalTenantBinding> */
    public function locateState(string $provider, string $stateHash): array;

    /** @return list<ExternalTenantBinding> */
    public function locateTicket(string $ticketHash): array;
}
