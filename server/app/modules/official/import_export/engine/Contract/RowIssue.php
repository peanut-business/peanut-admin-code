<?php

declare(strict_types=1);

namespace app\modules\official\import_export\engine\Contract;

use app\modules\official\import_export\engine\Application\ImportExportException;

final readonly class RowIssue
{
    public function __construct(public string $code, public ?string $columnKey = null)
    {
        if (preg_match('/^[A-Z][A-Z0-9_]{2,63}$/D', $code) !== 1
            || ($columnKey !== null && preg_match('/^[a-z][a-z0-9_]{0,63}$/D', $columnKey) !== 1)
        ) {
            throw ImportExportException::invalid();
        }
    }
}
