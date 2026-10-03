<?php

declare(strict_types=1);

namespace PeanutAdmin\Modules\Identity\Contract;

use PeanutAdmin\Kernel\Auth\TenantContext;
use PeanutAdmin\Modules\Identity\Contract\Dto\TenantSessionSummary;

/** Self-service session query and revocation owned by Identity. */
interface TenantSessionAccess
{
    /** @return list<TenantSessionSummary> */
    public function ownedSessions(TenantContext $context): array;

    public function revokeOwnedSession(TenantContext $context, string $sessionKey): TenantSessionSummary;
}
