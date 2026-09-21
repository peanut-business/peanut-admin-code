<?php
declare(strict_types=1);

namespace app\modules\official\settings\contracts;

interface TenantSettingsProvider
{
    public function find(int $tenantId, string $namespace): ?TenantSettingSnapshot;

    /** @param array<string, mixed> $document */
    public function replace(int $tenantId, string $namespace, array $document): TenantSettingSnapshot;
}
