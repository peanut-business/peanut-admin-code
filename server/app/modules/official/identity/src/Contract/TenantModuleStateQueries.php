<?php

declare(strict_types=1);

namespace PeanutAdmin\Modules\Identity\Contract;

use think\facade\Db;

/** 固定的租户模块状态投影，不返回配置正文或授权能力；安装清单与必需基础模块仍由原部署登记器决定。 */
final readonly class TenantModuleStateQueries
{
    public function tenantIsActive(int $tenantId): bool
    {
        return $tenantId > 0
            && Db::name('tenant')->where('id', $tenantId)->where('status', 'active')->value('id') !== null;
    }

    /** @return list<array{id:mixed,tenant_id:mixed,module_key:mixed,status:mixed,source:mixed,config_revision:mixed,effective_at:mixed,expires_at:mixed,enabled_at:mixed,disabled_at:mixed,disabled_reason:mixed,created_at:mixed,updated_at:mixed}> */
    public function stateRows(int $tenantId): array
    {
        if ($tenantId < 1) {
            return [];
        }
        return Db::name('tenant_module')->where('tenant_id', $tenantId)
            ->field('id,tenant_id,module_key,status,source,config_revision,effective_at,expires_at,enabled_at,disabled_at,disabled_reason,created_at,updated_at')
            ->order('module_key')->select()->toArray();
    }

    /** 保持数据库时钟的生效包含/到期排除条件，不将代码存在当作租户已开通。 @return list<string> */
    public function activeModuleKeys(int $tenantId): array
    {
        if ($tenantId < 1) {
            return [];
        }
        return array_values(array_map('strval', Db::name('tenant_module')->where('tenant_id', $tenantId)
            ->where('status', 'enabled')
            ->where(fn($query) => $query->whereNull('effective_at')->whereOr('effective_at', '<=', Db::raw('CURRENT_TIMESTAMP(3)')))
            ->where(fn($query) => $query->whereNull('expires_at')->whereOr('expires_at', '>', Db::raw('CURRENT_TIMESTAMP(3)')))
            ->order('module_key')->column('module_key')));
    }
}
