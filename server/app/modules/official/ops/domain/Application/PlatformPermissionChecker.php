<?php

declare(strict_types=1);

namespace app\modules\official\ops\domain\Application;

use PeanutAdmin\Kernel\Context\PlatformContext;

interface PlatformPermissionChecker
{
    public function allows(PlatformContext $context, string $permissionKey): bool;
}
