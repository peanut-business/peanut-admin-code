<?php

declare(strict_types=1);

namespace PeanutAdmin\Modules\Document\Contract;

use PeanutAdmin\Kernel\Auth\TenantContext;

interface DocumentOwnerPolicy
{
    /** Validates existence, ownership, supported slot and soft-delete state; locks native owner row when requested. */
    public function resolveOwner(TenantContext $context, DocumentOwnerReference $owner, bool $forUpdate = false): DocumentOwnerState;
    public function authorize(TenantContext $context, string $action, DocumentOwnerState $owner): void;
    public function stateRevision(DocumentOwnerState $owner): string;
}
