<?php

declare(strict_types=1);

namespace PeanutAdmin\Modules\Document\Contract;

use PeanutAdmin\Kernel\Auth\TenantContext;

interface DocumentQueries
{
    /** Metadata/projection only: no Yjs binary, storage address or collaboration ticket. */
    public function detail(TenantContext $context, int $documentId, ?int $revisionId = null): array;
    public function capabilities(TenantContext $context, int $documentId, string $intent, ?int $revisionId = null): array;
    /** Only committed versions; rejects callers holding an outer transaction. */
    public function versionSnapshot(TenantContext $context, int $documentId, int $revisionId): VersionSnapshot;
}
