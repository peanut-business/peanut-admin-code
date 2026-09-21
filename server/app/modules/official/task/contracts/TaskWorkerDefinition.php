<?php
declare(strict_types=1);

namespace app\modules\official\task\contracts;

use PeanutAdmin\Kernel\Async\VerifiedJobEnvelope;
use PeanutAdmin\Kernel\Context\AuthorizedOperationContext;

/** A business Module's handler and its authoritative async reauthorization rule. */
interface TaskWorkerDefinition
{
    public function ownerModuleKey(): string;

    public function resourceKey(): string;

    public function operation(): string;

    public function handler(): TaskHandler;

    public function reauthorize(VerifiedJobEnvelope $envelope): AuthorizedOperationContext;
}
