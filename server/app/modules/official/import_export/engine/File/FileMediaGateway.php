<?php

declare(strict_types=1);

namespace app\modules\official\import_export\engine\File;

use PeanutAdmin\Kernel\Context\AuthorizedOperationContext;

interface FileMediaGateway
{
    /** @return resource */
    public function openCsvInput(AuthorizedOperationContext $context, string $fileKey);

    /** @param resource $stream */
    public function storePrivateCsv(
        AuthorizedOperationContext $context,
        string $operationKey,
        string $purpose,
        string $filename,
        $stream,
    ): string;
}
