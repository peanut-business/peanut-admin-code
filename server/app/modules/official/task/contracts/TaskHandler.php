<?php

declare(strict_types=1);

namespace app\modules\official\task\contracts;

use PeanutAdmin\Kernel\Context\AuthorizedOperationContext;

interface TaskHandler
{
    public function key(): string;

    /**
     * Implementations must call checkpoint() before every bounded batch or
     * side effect, stop on lease loss, and use $execution->jobKey as their
     * stable side-effect idempotency key before reporting success.
     */
    public function handle(AuthorizedOperationContext $context, JobExecution $execution): void;
}
