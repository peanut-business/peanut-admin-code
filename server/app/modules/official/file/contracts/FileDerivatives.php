<?php
declare(strict_types=1);

namespace app\modules\official\file\contracts;

use PeanutAdmin\Kernel\Auth\TenantContext;

interface FileDerivatives
{
    public function bind(
        TenantContext $context,
        string $sourceFileKey,
        string $variantKey,
        string $derivativeFileKey,
        int $width,
        int $height,
        string $mediaType,
    ): void;
}
