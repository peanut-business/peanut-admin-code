<?php

declare(strict_types=1);

namespace app\modules\official\task\contracts;

use PeanutAdmin\Kernel\Context\AuthorizedOperationContext;

interface TaskSubmissionProvider
{
    public function taskType(): string;

    public function resourceKey(): string;

    public function operation(): string;

    /** @param array<string, mixed> $input */
    public function build(AuthorizedOperationContext $context, array $input): TaskSubmission;
}
