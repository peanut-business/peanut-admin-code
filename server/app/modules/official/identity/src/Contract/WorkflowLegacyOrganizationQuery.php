<?php

declare(strict_types=1);

namespace PeanutAdmin\Modules\Identity\Contract;

use think\facade\Db;

/** Bounded installation/maintenance diagnostic, never a membership grant or a name-to-account mapping. */
final class WorkflowLegacyOrganizationQuery
{
    public function leaderReport(): array
    {
        $table = Db::name('department')->getTable();
        $columns = Db::query('SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?', [$table]);
        $names = array_column($columns, 'COLUMN_NAME');
        if (!in_array('leader', $names, true)) { return ['unbound_legacy_leaders' => 0, 'department_ids' => [], 'truncated' => false, 'binding_column_present' => in_array('leader_member_id', $names, true)]; }
        $query = Db::name('department')->whereNotNull('leader')->where('leader', '<>', '');
        $bound = in_array('leader_member_id', $names, true);
        if ($bound) { $query->whereNull('leader_member_id'); }
        $count = (int) (clone $query)->count();
        $rows = $query->field('tenant_id,id')->order('tenant_id')->order('id')->limit(100)->select()->toArray();
        return ['unbound_legacy_leaders' => $count, 'department_ids' => array_map(static fn(array $row): array => ['tenant_id' => (string) $row['tenant_id'], 'department_id' => (string) $row['id']], $rows),
            'truncated' => $count > 100, 'binding_column_present' => $bound];
    }
}
