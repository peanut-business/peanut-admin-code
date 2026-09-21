<?php
declare(strict_types=1);

namespace app\modules\official\ops\infrastructure\authorization;

use PeanutAdmin\Kernel\Context\PlatformContext;
use PeanutAdmin\Kernel\Platform\Authorization\PlatformAuthorizationEvaluator;
use app\modules\official\ops\domain\Application\PlatformPermissionChecker;

/** Core Ops permission adapter over the application's canonical Platform RBAC. */
final readonly class PlatformOpsPermissionChecker implements PlatformPermissionChecker
{
    public function __construct(private PlatformAuthorizationEvaluator $evaluator) {}

    public function allows(PlatformContext $context, string $permissionKey): bool
    {
        return $this->evaluator->allows($context, $permissionKey);
    }
}
