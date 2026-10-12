<?php

declare(strict_types=1);

namespace PeanutAdmin\Modules\File\Contract;

use PeanutAdmin\Kernel\Auth\TenantContext;

/** Resource operations; HTTP parameters are never authorization evidence. */
interface FileAccess
{
    /** @return array<string,mixed> Current metadata and a short-lived authorized delivery. */
    public function resolve(TenantContext $context, string $fileKey, string $operation, ?int $documentId = null, ?string $variantKey = null, ?string $viewerAdapterId = null): array;

    /** @param list<string> $fileKeys @return list<array<string,mixed>> */
    public function resolveMany(TenantContext $context, array $fileKeys, string $operation): array;
}
