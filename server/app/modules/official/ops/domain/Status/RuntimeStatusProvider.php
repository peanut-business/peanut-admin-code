<?php

declare(strict_types=1);

namespace app\modules\official\ops\domain\Status;

use PeanutAdmin\Kernel\Context\PlatformContext;

interface RuntimeStatusProvider
{
    public function snapshot(PlatformContext $context): OpsStatusSnapshot;
}
