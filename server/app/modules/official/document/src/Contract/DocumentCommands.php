<?php

declare(strict_types=1);

namespace PeanutAdmin\Modules\Document\Contract;

use PeanutAdmin\Kernel\Auth\TenantContext;

interface DocumentCommands
{
    /** Current owner purge authorization and retention protection, inside the business transaction. */
    public function assertOwnerDeletionAllowed(TenantContext $context, DocumentOwnerReference $owner): void;
    public function create(TenantContext $context, DocumentOwnerReference $owner, string $contentKind, string $requestId, ?string $fileKey = null): array;
    public function saveSolo(TenantContext $context, int $documentId, int $expectedStateSeq, array $structuredContent, string $requestId): array;
    public function bindResource(TenantContext $context, int $documentId, string $fileKey, int $expectedStateSeq, string $requestId): array;
    public function freeze(TenantContext $context, int $documentId, int $expectedStateSeq, string $reason, string $requestId): array;
    public function restoreRevision(TenantContext $context, int $documentId, int $revisionId, int $expectedStateSeq, string $requestId): array;
    public function archive(TenantContext $context, int $documentId, int $expectedAuthRevision, string $requestId): array;
    public function restore(TenantContext $context, int $documentId, int $expectedAuthRevision, string $requestId): array;
    /** Called inside the owner's native lifecycle transaction after its state changes. */
    public function archiveOwner(TenantContext $context, string $moduleKey, string $objectType, string $objectId): void;
    public function restoreOwner(TenantContext $context, string $moduleKey, string $objectType, string $objectId): void;
}
