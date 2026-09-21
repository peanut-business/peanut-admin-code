<?php

declare(strict_types=1);

namespace app\modules\official\ops\domain\Logs;

use PeanutAdmin\Kernel\Context\PlatformContext;

interface RuntimeLogProvider
{
    public function sourceKey(): string;

    public function read(PlatformContext $context, RuntimeLogQuery $query): StructuredLogBatch;
}
