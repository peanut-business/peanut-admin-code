<?php

declare(strict_types=1);

namespace PeanutAdmin\Modules\File\Contract;

use PeanutAdmin\Kernel\Auth\TenantContext;

interface FileReferenceCommands
{
    /** Rebuild only a currently authorized Document's references, in the caller's transaction. @param list<string> $fileKeys */
    public function replaceDocumentReferences(TenantContext $context, int $documentId, array $fileKeys): void;
}
