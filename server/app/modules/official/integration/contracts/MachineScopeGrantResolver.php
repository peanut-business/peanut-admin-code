<?php

declare(strict_types=1);

namespace app\modules\official\integration\contracts;

use PeanutAdmin\Kernel\Context\AuthorizedOperationContext;

interface MachineScopeGrantResolver
{
    /** @return list<string> */
    public function grantableScopes(AuthorizedOperationContext $context): array;
}
