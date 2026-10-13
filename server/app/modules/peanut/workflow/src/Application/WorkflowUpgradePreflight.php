<?php

declare(strict_types=1);

namespace PeanutAdmin\Modules\Workflow\Application;

use PeanutAdmin\Modules\Identity\Contract\WorkflowLegacyOrganizationQuery;
use think\facade\Db;

/** Native module upgrade report. Legacy opinions/versions stay untouched; there is no inferred execution conversion. */
final readonly class WorkflowUpgradePreflight
{
    public const MIGRATION_KEY = 'peanut.workflow:20261012-workflow-v2-execution';
    public function __construct(private WorkflowLegacyOrganizationQuery $organization) {}

    public function report(): array
    {
        $tables = ['workflow_definition', 'workflow_definition_version', 'workflow_instance', 'workflow_work_item', 'workflow_event'];
        $counts = []; $missing = [];
        foreach ($tables as $table) {
            $name = Db::name($table)->getTable();
            $present = Db::query('SELECT COUNT(*) AS count FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?', [$name]);
            if ((int) ($present[0]['count'] ?? 0) === 0) { $missing[] = $table; $counts[$table] = 0; }
            else { $counts[$table] = (int) Db::name($table)->count(); }
        }
        $applied = Db::name('module_migration')->where('module_key', 'peanut.workflow')->where('migration_key', self::MIGRATION_KEY)->where('status', 'applied')->count() > 0;
        $report = ['schema_version' => 1, 'module_key' => 'peanut.workflow', 'migration_key' => self::MIGRATION_KEY, 'already_applied' => $applied,
            'table_counts' => $counts, 'missing_tables' => $missing, 'legacy_graph_count' => 0, 'legacy_all_graph_count' => 0, 'unknown_graph_count' => 0,
            'graph_details' => [], 'details_truncated' => false, 'organization' => $this->organization->leaderReport(),
            'history_preserved' => true, 'automatic_conversion' => false,
            'cutover' => 'Keep the original deployment and immutable versions while old instances finish. Stop new starts on that deployment; review a migration explicitly before switching to V2. This report never rewrites graphs, all semantics, leaders, votes or history.'];
        if ($applied) { return $report + ['status' => 'not_required', 'may_apply' => true]; }
        foreach (['workflow_definition' => 'draft_graph_json', 'workflow_definition_version' => 'graph_json'] as $table => $column) {
            if (in_array($table, $missing, true)) { continue; }
            $query = Db::name($table);
            $legacy = "(JSON_EXTRACT(`{$column}`, '$.contract_version') IS NULL OR JSON_UNQUOTE(JSON_EXTRACT(`{$column}`, '$.contract_version')) <> '2')";
            $report['legacy_graph_count'] += (int) (clone $query)->whereRaw($legacy)->count();
            $report['legacy_all_graph_count'] += (int) (clone $query)->whereRaw("JSON_SEARCH(`{$column}`, 'one', 'all', NULL, '$**.completion_policy') IS NOT NULL")->count();
            $report['unknown_graph_count'] += (int) (clone $query)->whereRaw("JSON_TYPE(JSON_EXTRACT(`{$column}`, '$.nodes')) IS NULL OR JSON_TYPE(JSON_EXTRACT(`{$column}`, '$.nodes')) <> 'ARRAY'")->count();
            $capacity = 100 - count($report['graph_details']);
            if ($capacity > 0) {
                foreach ($query->field('id,tenant_id,' . $column)->order('id')->limit($capacity)->select()->toArray() as $row) {
                    $graph = is_array($row[$column]) ? $row[$column] : json_decode((string) $row[$column], true);
                    $report['graph_details'][] = ['table' => $table, 'id' => (string) $row['id'], 'tenant_id' => (string) $row['tenant_id'],
                        'contract_version' => is_array($graph) ? ($graph['contract_version'] ?? null) : null, 'mapping' => 'manual_review_required'];
                }
            }
        }
        $report['details_truncated'] = ($counts['workflow_definition'] + $counts['workflow_definition_version']) > count($report['graph_details']);
        $safe = array_sum($counts) === 0 && (count($missing) === 0 || count($missing) === count($tables));
        return $report + ['status' => $safe ? 'ready' : 'blocked', 'may_apply' => $safe,
            'code' => $safe ? null : 'WORKFLOW_LEGACY_DATA_REQUIRES_REVIEW'];
    }

    public function assertMayApply(): void
    {
        if (!$this->report()['may_apply']) { throw WorkflowException::upgradeRequiresReview(); }
    }
}
