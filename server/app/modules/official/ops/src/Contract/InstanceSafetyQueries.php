<?php

declare(strict_types=1);

namespace PeanutAdmin\Modules\Ops\Contract;

use think\facade\Db;

/**
 * 供维护门禁和就绪提示读取的最小实例状态；不授权平台操作，不返回任务、凭据或备份路径。
 * 查询失败必须传播，调用门禁保持拒绝写入；结果不得跨请求缓存。
 */
final readonly class InstanceSafetyQueries
{
    /** @return array{maintenance_key:string,reason_key:string}|null */
    public function blockingMaintenanceWindow(): ?array
    {
        $window = Db::name('ops_maintenance_window')->whereIn('state', ['scheduled', 'active'])
            ->where('starts_at', '<=', Db::raw('UTC_TIMESTAMP(3)'))
            ->where('ends_at', '>', Db::raw('UTC_TIMESTAMP(3)'))
            ->field('maintenance_key,reason_key')->order('id', 'desc')->find();
        return is_array($window) ? [
            'maintenance_key' => (string) $window['maintenance_key'],
            'reason_key' => (string) $window['reason_key'],
        ] : null;
    }

    /** 仅返回原有最新验证时间；null表示无记录，不表示备份或恢复已合格。 */
    public function lastVerifiedBackupAt(): ?string
    {
        $value = Db::name('ops_backup_evidence')->order('verified_at', 'desc')->order('id', 'desc')
            ->value('verified_at');
        return is_string($value) && $value !== '' ? $value : null;
    }
}
