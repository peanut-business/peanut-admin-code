<?php
declare(strict_types=1);

namespace app\modules\official\file\contracts;

use PeanutAdmin\Kernel\Auth\TenantContext;
use PeanutAdmin\Kernel\Context\AuthenticatedMemberContext;
use PeanutAdmin\Kernel\Context\TenantSystemContext;

interface FileReferences
{
    public function getFileUrl(string $reference = ''): string;

    public function setTenantFileUrl(AuthenticatedMemberContext|TenantContext|TenantSystemContext $context, string $value = ''): string;
}
