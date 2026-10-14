<?php

declare(strict_types=1);

namespace PeanutAdmin\Modules\Collaboration\Contract;

use PeanutAdmin\Kernel\Auth\TenantContext;

/** Existing live human-session ticket issuance; an owner resolver never grants access itself. */
interface CollaborationTickets
{
    /** Returns managed wss_url/config_revision/config_sha256 only after current actor authorization. */
    public function ticket(TenantContext $context, int $documentId, string $mode): array;
    /** Write ticket fixed to the caller's compiled business owner; returns server-bound transport identity. */
    public function ownerTicket(TenantContext $context, int $documentId, \PeanutAdmin\Modules\Document\Contract\DocumentOwnerReference $owner): array;
}
