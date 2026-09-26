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

    /**
     * 生命周期阻断按已启用登记，不按当前可用时间或租户状态过滤；不可复用activeModuleKeys缩小阻断。
     * @return array<string,int>
     */
    public function enabledCounts(): array
    {
        $counts = [];
        $rows = Db::name('tenant_module')->where('status', 'enabled')
            ->field('module_key')->fieldRaw('COUNT(*) AS enabled_count')->group('module_key')->select()->toArray();
        foreach ($rows as $row) {
            $counts[(string) $row['module_key']] = (int) $row['enabled_count'];
        }
        return $counts;
    }

    /** @param list<string> $moduleKeys */
    public function hasEnabledModules(array $moduleKeys): bool
    {
        return $moduleKeys !== [] && Db::name('tenant_module')->whereIn('module_key', $moduleKeys)
            ->where('status', 'enabled')->count() !== 0;
    }

    /** 每条启用登记保留一个键，重复值参与现有卸载计划，不返回租户配置或账户信息。 @param list<string> $moduleKeys @return list<string> */
    public function enabledModuleReferences(array $moduleKeys): array
    {
        if ($moduleKeys === []) {
            return [];
        }
        return array_map('strval', Db::name('tenant_module')->whereIn('module_key', $moduleKeys)
            ->where('status', 'enabled')->order('module_key')->column('module_key'));
    }
}
