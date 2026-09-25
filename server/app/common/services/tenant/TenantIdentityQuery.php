<?php

declare(strict_types=1);

namespace app\common\services\tenant;

use think\facade\Db;

/** Instance-owned read boundary for public Tenant identity projection. */
final class TenantIdentityQuery
{
    public function activeName(int $tenantId): string
    {
        if ($tenantId < 1) {
            return '';
        }
        return trim((string) Db::name('tenant')->where('id', $tenantId)->where('status', 'active')->value('name'));
    }
}
