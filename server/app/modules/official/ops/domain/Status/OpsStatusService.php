<?php

declare(strict_types=1);

namespace app\modules\official\ops\domain\Status;

use PeanutAdmin\Kernel\Context\PlatformContext;
use app\modules\official\ops\domain\Application\OpsConsoleException;
use app\modules\official\ops\domain\Application\PlatformPermissionChecker;
use app\modules\official\ops\domain\Package;
use Throwable;

final readonly class OpsStatusService
{
    public function __construct(
        private PlatformPermissionChecker $permissions,
        private RuntimeStatusProvider $provider,
    ) {}

    public function read(PlatformContext $context): OpsStatusSnapshot
    {
        if (!$this->permissions->allows($context, Package::READ_PERMISSION)) {
            throw OpsConsoleException::denied();
        }
        try {
            return $this->provider->snapshot($context);
        } catch (Throwable) {
            throw OpsConsoleException::statusUnavailable();
        }
    }
}
