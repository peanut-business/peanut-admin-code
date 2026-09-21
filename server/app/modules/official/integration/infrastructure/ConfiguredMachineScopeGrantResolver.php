<?php
declare(strict_types=1);

namespace app\modules\official\integration\infrastructure;

use app\modules\official\integration\contracts\MachineScopeGrantResolver;
use app\modules\official\integration\Package;
use PeanutAdmin\Kernel\Context\AuthorizedOperationContext;

final readonly class ConfiguredMachineScopeGrantResolver implements MachineScopeGrantResolver
{
    /** @param list<string> $scopes */
    public function __construct(private array $scopes) {}

    public function grantableScopes(AuthorizedOperationContext $context): array
    {
        if (!hash_equals(Package::RESOURCE_KEY, $context->resourceKey)
            || !hash_equals('machine-manage', $context->operation)
        ) {
            return [];
        }
        return $this->scopes;
    }
}
