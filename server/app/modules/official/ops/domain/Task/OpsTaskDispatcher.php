<?php

declare(strict_types=1);

namespace app\modules\official\ops\domain\Task;

use PeanutAdmin\Kernel\Context\PlatformContext;

interface OpsTaskDispatcher
{
    /** Submission and audit must commit atomically. */
    public function dispatch(PlatformContext $context, OpsTaskSubmission $submission): OpsTask;

    public function find(PlatformContext $context, string $taskKey): OpsTask;
}
