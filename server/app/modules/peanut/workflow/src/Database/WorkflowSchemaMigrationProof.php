<?php

declare(strict_types=1);

namespace PeanutAdmin\Modules\Workflow\Database;

use Closure;
use PDO;
use RuntimeException;
use Throwable;

/** Read-only preservation assertions for fixed Workflow SQL and its append-only verification. */
final class WorkflowSchemaMigrationProof
{
    private const INITIAL = 'peanut.workflow:20260921-register-module-schema';
    private const MANAGED = 'peanut.workflow:20261012-workflow-managed-template';
    private const REPAIR = 'peanut.workflow:20261012-workflow-managed-template-repair-20261021';
    private const FACTS = 'peanut.workflow:20261012-workflow-notification-facts';
    private const V2 = 'peanut.workflow:20261012-workflow-v2-execution';
    private const SOURCES = [
        self::INITIAL => 'f0a21d63c83afcd2438fc327bf6ad97a59d82daa571bc4de51d74c32eb487ff7',
        self::MANAGED => 'fd02893a4b7a0ea60aa6c5434ff8b46f11e35bec94f10a76fc0b0f64609282a5',
        self::REPAIR => 'f3416b6c726a4a97ef70df967f14227c328c0ced156ac98656200f44712e5993',
        self::FACTS => 'ff26361f27773b9500fe6484729a26c0655d18f893cac8739bae9edfec8f6378',
        self::V2 => 'f46f8b9a7a567584988e3520669daa1a8f893b69d837a1dedf223deb4f2a1b09',
    ];
    private const BASE_COLUMNS = [
        'pa_workflow_definition' => 'id tenant_id module_key workflow_key status draft_graph_json draft_graph_sha256 latest_version revision created_by_member_id updated_by_member_id created_at updated_at retired_at',
        'pa_workflow_definition_version' => 'id tenant_id definition_id version graph_json graph_sha256 published_by_member_id published_at',
        'pa_workflow_instance' => 'id instance_key tenant_id definition_id definition_version subject_type subject_key subject_revision_key subject_revision_sha256 current_node_key status initiated_by_member_id last_actor_member_id revision created_at updated_at completed_at cancelled_at active_marker',
        'pa_workflow_work_item' => 'id work_item_key tenant_id instance_id node_key round_no assignment_source_kind assignment_source_key assignee_member_id status decision completed_by_member_id revision created_at updated_at completed_at cancelled_at pending_marker',
        'pa_workflow_event' => 'id tenant_id instance_id sequence_no event_key transition_key from_node_key to_node_key actor_type actor_member_id subject_revision_key subject_revision_sha256 comment_text comment_sha256 attachment_snapshots_json metadata_json occurred_at',
    ];
    private const NEW_TABLES = ['pa_workflow_node_execution', 'pa_workflow_task_identity_link'];
    private const NEW_COLUMNS = [
        'pa_workflow_node_execution' => 'id tenant_id instance_id node_execution_key node_key sequence_no cycle_no previous_actor_member_id node_type status revision allocation_mode aggregation_policy input_json action_input_json started_at people_json output_json selected_transition_key blocked_reason effect_key effect_receipt_json task_job_key created_at completed_at',
        'pa_workflow_task_identity_link' => 'id tenant_id work_item_id member_id relation_type sources_json',
    ];
    // Fixed initial + managed SQL shape, observed on native MySQL 8.4.10 and authenticated
    // against the accepted initial graph. Same-schema names and the closed CHECK renders
    // below are canonical; allocator values are independently observed and preserved.
    private const MANAGED_SCHEMA_SHA256 = '5c8fdd9f2ce4a73bb315fd063a44d46ad96f5b219b129b01774fa6385e1b2052';
    private const CHECK_RENDERINGS = [
        'pa_workflow_definition' => [
            'chk_workflow_definition_digest' => 'regexp_like(`draft_graph_sha256`,_ascii\\\'^[0-9a-f]{64}$\\\')',
            'chk_workflow_definition_module_key' => 'regexp_like(`module_key`,_ascii\\\'^[a-z][a-z0-9]*([.-][a-z0-9]+)*$\\\')',
            'chk_workflow_definition_retired_shape' => '(((`status` = _ascii\\\'retired\\\') and (`retired_at` is not null)) or ((`status` <> _ascii\\\'retired\\\') and (`retired_at` is null)))',
            'chk_workflow_definition_status' => '(`status` in (_ascii\\\'draft\\\',_ascii\\\'active\\\',_ascii\\\'retired\\\'))',
            'chk_workflow_definition_workflow_key' => 'regexp_like(`workflow_key`,_ascii\\\'^[a-z][a-z0-9]*([.-][a-z0-9]+)*$\\\')',
        ],
        'pa_workflow_definition_version' => [
            'chk_workflow_definition_version_digest' => 'regexp_like(`graph_sha256`,_ascii\\\'^[0-9a-f]{64}$\\\')',
        ],
        'pa_workflow_instance' => [
            'chk_workflow_instance_digest' => 'regexp_like(`subject_revision_sha256`,_ascii\\\'^[0-9a-f]{64}$\\\')',
            'chk_workflow_instance_key' => 'regexp_like(`instance_key`,_ascii\\\'^instance_[0-9a-f]{32}$\\\')',
            'chk_workflow_instance_status' => '(`status` in (_ascii\\\'active\\\',_ascii\\\'completed\\\',_ascii\\\'cancelled\\\'))',
            'chk_workflow_instance_terminal_shape' => '(((`status` = _ascii\\\'active\\\') and (`completed_at` is null) and (`cancelled_at` is null)) or ((`status` = _ascii\\\'completed\\\') and (`completed_at` is not null) and (`cancelled_at` is null)) or ((`status` = _ascii\\\'cancelled\\\') and (`completed_at` is null) and (`cancelled_at` is not null)))',
        ],
        'pa_workflow_work_item' => [
            'chk_workflow_work_item_key' => 'regexp_like(`work_item_key`,_ascii\\\'^work_[0-9a-f]{32}$\\\')',
            'chk_workflow_work_item_source' => '(`assignment_source_kind` in (_ascii\\\'member\\\',_ascii\\\'role\\\',_ascii\\\'department\\\',_ascii\\\'initiator\\\',_ascii\\\'previous_actor\\\'))',
            'chk_workflow_work_item_status' => '(`status` in (_ascii\\\'pending\\\',_ascii\\\'completed\\\',_ascii\\\'cancelled\\\'))',
            'chk_workflow_work_item_terminal_shape' => '(((`status` = _ascii\\\'pending\\\') and (`decision` is null) and (`completed_by_member_id` is null) and (`completed_at` is null) and (`cancelled_at` is null)) or ((`status` = _ascii\\\'completed\\\') and (`decision` is not null) and (`completed_by_member_id` is not null) and (`completed_at` is not null) and (`cancelled_at` is null)) or ((`status` = _ascii\\\'cancelled\\\') and (`decision` is null) and (`completed_by_member_id` is null) and (`completed_at` is null) and (`cancelled_at` is not null)))',
        ],
        'pa_workflow_event' => [
            'chk_workflow_event_actor_shape' => '(((`actor_type` = _ascii\\\'member\\\') and (`actor_member_id` is not null)) or ((`actor_type` = _ascii\\\'tenant_system\\\') and (`actor_member_id` is null)))',
            'chk_workflow_event_comment_shape' => '(((`comment_text` is null) and (`comment_sha256` is null)) or ((`comment_text` is not null) and regexp_like(`comment_sha256`,_ascii\\\'^[0-9a-f]{64}$\\\')))',
            'chk_workflow_event_key' => 'regexp_like(`event_key`,_ascii\\\'^tenant\\\\\\\\.workflow\\\\\\\\.[a-z_]+$\\\')',
            'chk_workflow_event_subject_digest' => 'regexp_like(`subject_revision_sha256`,_ascii\\\'^[0-9a-f]{64}$\\\')',
        ],
    ];
    private const FACT_COLUMNS = ['notification_fact_json', 'notification_outbox_json', 'notification_last_error'];

    public static function before(PDO $pdo, array $stage): array
    {
        return self::snapshot($pdo, static function () use ($pdo, $stage): array {
            $key = $stage['migration_key'] ?? null;
            if (($stage['module_key'] ?? null) !== 'peanut.workflow' || !is_string($key) || !isset(self::SOURCES[$key])
                || ($stage['checksum'] ?? null) !== self::SOURCES[$key]
                || !($stage['stageLedger'] ?? null) instanceof Closure) { self::fail('IDENTITY_INVALID'); }
            self::source($key);
            $ledger = self::ledger($pdo, $stage);
            $tables = self::tables($pdo);
            if ($key === self::INITIAL) {
                // No inference that pre-existing, unrecorded tables were created by this SQL.
                foreach ($tables as $table) { if ($table !== null) { self::fail('UNRECORDED_SCHEMA_REQUIRES_REVIEW'); } }
            } else {
                self::assertPrefix($tables, $key);
                if ($key !== self::MANAGED) { self::assertManagedSchema($tables, self::connection($pdo)['db'], $key === self::V2); }
            }
            $data = self::data($pdo, $tables);
            if ($key === self::V2 || $key === self::REPAIR) {
                foreach ($data as $rows) { if ($rows !== null && $rows['count'] !== 0) { self::fail('LEGACY_DATA_REQUIRES_REVIEW'); } }
            }
            if ($ledger !== self::ledger($pdo, $stage)) { self::fail('LEDGER_CHANGED'); }
            return ['module_key' => 'peanut.workflow', 'migration_key' => $key, 'checksum' => self::SOURCES[$key],
                'pdo_object_id' => spl_object_id($pdo), 'connection' => self::connection($pdo),
                'stageLedger' => $stage['stageLedger'], 'ledger' => $ledger, 'tables' => $tables, 'data' => $data,
                'safe_summary' => ['migration_key' => $key, 'checksum' => self::SOURCES[$key],
                    'metadata_sha256' => hash('sha256', serialize($tables)), 'ledger_sha256' => hash('sha256', serialize($ledger))],
                'readonly' => true];
        });
    }

    public static function after(PDO $pdo, array $before): array
    {
        return self::snapshot($pdo, static function () use ($pdo, $before): array {
            $key = $before['migration_key'] ?? null;
            if (($before['module_key'] ?? null) !== 'peanut.workflow' || !is_string($key) || !isset(self::SOURCES[$key])
                || ($before['checksum'] ?? null) !== self::SOURCES[$key]
                || ($before['pdo_object_id'] ?? null) !== spl_object_id($pdo)
                || ($before['connection'] ?? null) !== self::connection($pdo)
                || !is_array($before['tables'] ?? null) || !is_array($before['data'] ?? null)
                || !is_array($before['ledger'] ?? null)) { self::fail('INPUT_INVALID'); }
            self::source($key);
            if ($before['ledger'] !== self::ledger($pdo, $before)) { self::fail('LEDGER_CHANGED'); }
            $after = self::tables($pdo);
            $created = 0;
            if ($key === self::INITIAL) {
                foreach ($before['tables'] as $table) { if ($table !== null) { self::fail('INPUT_INVALID'); } }
                self::assertPrefix($after, self::MANAGED);
                foreach (array_keys(self::BASE_COLUMNS) as $table) {
                    if (self::rows($pdo, $table, array_keys($after[$table]['columns']))['count'] !== 0) { self::fail('CREATE_NOT_EMPTY'); }
                    ++$created;
                }
            } elseif ($key === self::REPAIR) {
                self::assertManagedSchema($after, self::connection($pdo)['db']);
                if ($before['tables'] !== $after) { self::fail('REPAIR_METADATA_CHANGED'); }
                foreach ($before['data'] as $rows) { if ($rows !== null && $rows['count'] !== 0) { self::fail('LEGACY_DATA_REQUIRES_REVIEW'); } }
            } elseif ($key === self::V2) {
                foreach ($before['data'] as $rows) { if ($rows !== null && $rows['count'] !== 0) { self::fail('LEGACY_DATA_REQUIRES_REVIEW'); } }
                self::assertV2($before['tables'], $after);
                foreach ($after as $table => $metadata) {
                    if ($metadata === null || self::rows($pdo, $table, array_keys($metadata['columns']))['count'] !== 0) { self::fail('V2_NOT_EMPTY'); }
                    if ($before['tables'][$table] === null) { ++$created; }
                }
            } else {
                $table = $key === self::MANAGED ? 'pa_workflow_definition' : 'pa_workflow_event';
                $added = $key === self::MANAGED ? ['managed_template_json'] : self::FACT_COLUMNS;
                self::assertAdditions($before['tables'], $after, [$table => $added]);
                foreach ($added as $column) {
                    self::nullableColumn($after[$table]['columns'][$column], $column === 'notification_last_error' ? 'varchar(64)' : 'json');
                    if (self::query($pdo, 'SELECT EXISTS(SELECT 1 FROM `' . $table . '` WHERE `' . $column . '` IS NOT NULL) AS present')[0]['present'] !== '0') {
                        self::fail('ADDED_VALUE_CHANGED');
                    }
                }
                self::assertManagedSchema($after, self::connection($pdo)['db'], $key === self::FACTS);
            }
            // Compare every original column of every original row, in primary-key order.
            // Payloads stay in this process and only their framed digest is retained.
            $preserved = self::data($pdo, $before['tables']);
            if ($preserved !== $before['data']) { self::fail('ORIGINAL_ROWS_CHANGED'); }
            if ($before['ledger'] !== self::ledger($pdo, $before)) { self::fail('LEDGER_CHANGED'); }
            return ['module_key' => 'peanut.workflow', 'migration_key' => $key, 'checksum' => self::SOURCES[$key],
                'preservation_verified' => true, 'readonly' => true, 'created_empty_tables' => $created,
                'metadata_sha256' => hash('sha256', serialize($after)), 'ledger_sha256' => hash('sha256', serialize($before['ledger'])),
                'before_sha256' => hash('sha256', serialize($before['data'])), 'after_sha256' => hash('sha256', serialize($preserved)),
                'data_proof' => $key === self::REPAIR ? 'fixed native SELECT 1; complete managed schema, real allocators, original metadata/data/ledger unchanged'
                    : 'same native PDO and stage ledger; original-column row digests; V2 requires empty originals; no graph conversion'];
        });
    }

    private static function assertPrefix(array $tables, string $next): void
    {
        foreach (self::BASE_COLUMNS as $table => $names) {
            if ($tables[$table] === null) { self::fail('PARTIAL_SCHEMA'); }
            $expected = explode(' ', $names);
            if ($table === 'pa_workflow_definition' && $next !== self::MANAGED) { $expected[] = 'managed_template_json'; }
            if ($table === 'pa_workflow_event' && $next === self::V2) { array_push($expected, ...self::FACT_COLUMNS); }
            if (array_keys($tables[$table]['columns']) !== $expected) { self::fail('SCHEMA_REQUIRES_REVIEW'); }
        }
        foreach (self::NEW_TABLES as $table) { if ($tables[$table] !== null) { self::fail('UNRECORDED_SCHEMA_REQUIRES_REVIEW'); } }
    }

    private static function assertAdditions(array $before, array $after, array $additions): void
    {
        $expected = $after;
        foreach ($additions as $table => $columns) {
            if ($before[$table] === null || $after[$table] === null) { self::fail('PARTIAL_SCHEMA'); }
            $names = array_keys($before[$table]['columns']);
            if (array_keys($after[$table]['columns']) !== [...$names, ...$columns]) { self::fail('COLUMN_DELTA_INVALID'); }
            foreach ($columns as $column) { unset($expected[$table]['columns'][$column]); }
        }
        self::retainedChecks($before, $expected, array_keys($additions));
        if ($expected !== $before) { self::fail('UNDECLARED_METADATA_CHANGED'); }
    }

    /** Fixed complete native schema contract, independent of this instance's private before-image. */
    private static function assertManagedSchema(array $tables, string $database, bool $facts = false): void
    {
        self::source(self::INITIAL); self::source(self::MANAGED);
        self::assertPrefix($tables, $facts ? self::V2 : self::FACTS);
        if ($facts) {
            $position = count(explode(' ', self::BASE_COLUMNS['pa_workflow_event']));
            foreach (self::FACT_COLUMNS as $column) {
                $type = $column === 'notification_last_error' ? 'varchar(64)' : 'json';
                $charset = $type === 'json' ? null : 'utf8mb4';
                $expected = ['COLUMN_NAME' => $column, 'ORDINAL_POSITION' => (string) ++$position,
                    'COLUMN_TYPE' => $type, 'IS_NULLABLE' => 'YES', 'COLUMN_DEFAULT' => null, 'EXTRA' => '',
                    'CHARACTER_SET_NAME' => $charset, 'COLLATION_NAME' => $charset === null ? null : 'utf8mb4_0900_ai_ci',
                    'COLUMN_COMMENT' => '', 'GENERATION_EXPRESSION' => ''];
                if ($tables['pa_workflow_event']['columns'][$column] !== $expected) { self::fail('MANAGED_SCHEMA_INVALID'); }
                unset($tables['pa_workflow_event']['columns'][$column]);
            }
        }
        foreach ($tables as $table => &$metadata) {
            if ($metadata === null) { continue; }
            if ($metadata['info']['TABLE_SCHEMA'] !== $database
                || preg_match('/\A[1-9][0-9]*\z/D', (string) ($metadata['info']['AUTO_INCREMENT'] ?? '')) !== 1) { self::fail('MANAGED_SCHEMA_INVALID'); }
            $metadata['info']['TABLE_SCHEMA'] = '__current_schema__';
            unset($metadata['info']['AUTO_INCREMENT']); // Compared as its real value by the same-stage whole-graph proof.
            foreach ($metadata['foreign_keys'] as &$parts) {
                foreach ($parts as &$part) {
                    if ($part['REFERENCED_TABLE_SCHEMA'] !== $database) { self::fail('MANAGED_SCHEMA_INVALID'); }
                    $part['REFERENCED_TABLE_SCHEMA'] = '__current_schema__';
                }
            }
            unset($parts, $part);
            foreach (self::CHECK_RENDERINGS[$table] ?? [] as $name => $render) {
                $constraint = &$metadata['constraints'][$name];
                if (($constraint['CONSTRAINT_TYPE'] ?? null) !== 'CHECK' || ($constraint['ENFORCED'] ?? null) !== 'YES'
                    || !in_array($constraint['CHECK_CLAUSE'] ?? null, [$render, str_replace('_ascii', '_utf8mb4', $render)], true)) { self::fail('MANAGED_SCHEMA_INVALID'); }
                $constraint['CHECK_CLAUSE'] = $render;
                unset($constraint);
            }
            foreach (['indexes', 'constraints', 'foreign_keys'] as $key) { ksort($metadata[$key], SORT_STRING); }
        }
        unset($metadata);
        if (hash('sha256', serialize($tables)) !== self::MANAGED_SCHEMA_SHA256) { self::fail('MANAGED_SCHEMA_INVALID'); }
    }

    /** Accept only the two complete native renders of this module's fixed retained CHECKs. */
    private static function retainedChecks(array $before, array &$after, array $tables): void
    {
        foreach ($tables as $table) {
            foreach (self::CHECK_RENDERINGS[$table] ?? [] as $name => $render) {
                if (!isset($before[$table]['constraints'][$name], $after[$table]['constraints'][$name])) { continue; }
                $old = $before[$table]['constraints'][$name]; $new = $after[$table]['constraints'][$name];
                $allowed = [$render, str_replace('_ascii', '_utf8mb4', $render)];
                if (($old['ENFORCED'] ?? null) !== 'YES' || ($new['ENFORCED'] ?? null) !== 'YES'
                    || !in_array($old['CHECK_CLAUSE'] ?? null, $allowed, true) || !in_array($new['CHECK_CLAUSE'] ?? null, $allowed, true)) { self::fail('RETAINED_CHECK_INVALID'); }
                $new['CHECK_CLAUSE'] = $old['CHECK_CLAUSE'];
                if ($new !== $old) { self::fail('RETAINED_CHECK_INVALID'); }
                $after[$table]['constraints'][$name] = $old;
            }
        }
    }

    private static function assertV2(array $before, array $after): void
    {
        $added = [
            'pa_workflow_definition' => ['layout_json', 'active_version'],
            'pa_workflow_definition_version' => ['contract_version'],
            'pa_workflow_instance' => ['module_key', 'workflow_key', 'business_department_key', 'snapshot_json', 'cycle_no', 'result_code', 'blocked_reason', 'substantive_at'],
            'pa_workflow_work_item' => ['node_execution_id', 'owner_member_id', 'owner_source', 'assignment_state', 'seat_no', 'sources_json'],
            'pa_workflow_event' => ['node_execution_id'],
        ];
        $expected = $after;
        foreach ($added as $table => $columns) {
            if ($after[$table] === null) { self::fail('V2_SCHEMA_INVALID'); }
            foreach ($columns as $column) {
                if (isset($before[$table]['columns'][$column]) || !isset($after[$table]['columns'][$column])) { self::fail('V2_SCHEMA_INVALID'); }
                unset($expected[$table]['columns'][$column]);
            }
        }
        foreach (['status', 'active_marker'] as $column) {
            unset($before['pa_workflow_instance']['columns'][$column], $expected['pa_workflow_instance']['columns'][$column]);
        }
        $oldAssignee = $before['pa_workflow_work_item']['columns']['assignee_member_id'];
        $oldAssignee['IS_NULLABLE'] = 'YES';
        if ($after['pa_workflow_work_item']['columns']['assignee_member_id'] !== $oldAssignee) { self::fail('V2_ASSIGNEE_DELTA_INVALID'); }
        $before['pa_workflow_work_item']['columns']['assignee_member_id'] = $oldAssignee;
        foreach (['uk_workflow_instance_active_subject'] as $name) {
            unset($before['pa_workflow_instance']['indexes'][$name], $expected['pa_workflow_instance']['indexes'][$name]);
        }
        unset($before['pa_workflow_work_item']['indexes']['uk_workflow_work_item_pending_assignee']);
        foreach (['uk_workflow_work_item_seat', 'uk_workflow_work_item_assignee_once'] as $name) { unset($expected['pa_workflow_work_item']['indexes'][$name]); }
        unset($before['pa_workflow_work_item']['constraints']['uk_workflow_work_item_pending_assignee']);
        foreach (['uk_workflow_work_item_seat', 'uk_workflow_work_item_assignee_once'] as $name) { unset($expected['pa_workflow_work_item']['constraints'][$name]); }
        foreach (['chk_workflow_instance_status', 'chk_workflow_instance_terminal_shape'] as $name) { unset($before['pa_workflow_instance']['constraints'][$name]); }
        unset($expected['pa_workflow_instance']['constraints']['chk_workflow_instance_status']);
        unset($before['pa_workflow_work_item']['constraints']['chk_workflow_work_item_source']);
        foreach (['chk_workflow_work_item_assignment', 'fk_workflow_work_item_node'] as $name) { unset($expected['pa_workflow_work_item']['constraints'][$name]); }
        unset($expected['pa_workflow_work_item']['foreign_keys']['fk_workflow_work_item_node']);
        foreach (self::NEW_TABLES as $table) {
            if ($before[$table] !== null || $after[$table] === null) { self::fail('V2_CREATE_INVALID'); }
            if (array_keys($after[$table]['columns']) !== explode(' ', self::NEW_COLUMNS[$table])) { self::fail('V2_CREATE_INVALID'); }
            $expected[$table] = null;
        }
        self::retainedChecks($before, $expected, array_keys($added));
        if ($expected !== $before) { self::fail('UNDECLARED_METADATA_CHANGED'); }
        self::nullableColumn($after['pa_workflow_definition']['columns']['layout_json'], 'json');
        self::nullableColumn($after['pa_workflow_definition']['columns']['active_version'], 'int unsigned');
        self::nullableColumn($after['pa_workflow_event']['columns']['node_execution_id'], 'bigint unsigned');
        foreach (['pa_workflow_definition_version' => 'contract_version', 'pa_workflow_instance' => 'cycle_no'] as $table => $column) {
            $c = $after[$table]['columns'][$column];
            if ($c['COLUMN_TYPE'] !== 'int unsigned' || $c['IS_NULLABLE'] !== 'NO'
                || $c['COLUMN_DEFAULT'] !== ($column === 'contract_version' ? '2' : '1')) { self::fail('V2_COLUMN_INVALID'); }
        }
        $status = $after['pa_workflow_instance']['columns']['status'];
        $marker = $after['pa_workflow_instance']['columns']['active_marker'];
        self::column($status, 'varchar(16)', false, 'running', '', 'ascii_bin');
        if ($marker['COLUMN_TYPE'] !== 'tinyint' || $marker['IS_NULLABLE'] !== 'YES' || $marker['COLUMN_DEFAULT'] !== null
            || $marker['EXTRA'] !== 'STORED GENERATED') { self::fail('V2_COLUMN_INVALID'); }
        $expression = self::tokens($marker['GENERATION_EXPRESSION']);
        if (array_slice($expression, 0, 2) !== ['case', 'when'] || array_slice($expression, -5) !== ['then', '1', 'else', 'null', 'end']
            || self::predicate(array_slice($expression, 2, -5)) !== self::predicate(self::tokens("status IN ('running','blocked')"))) { self::fail('V2_GENERATED_EXPRESSION_INVALID'); }
        foreach ([
            'pa_workflow_instance' => [
                'module_key' => ['varchar(64)', false, null, '', 'ascii_bin'],
                'workflow_key' => ['varchar(64)', false, null, '', 'ascii_bin'],
                'business_department_key' => ['varchar(160)', true], 'snapshot_json' => ['json', false],
                'cycle_no' => ['int unsigned', false, '1'], 'result_code' => ['varchar(64)', true],
                'blocked_reason' => ['varchar(500)', true], 'substantive_at' => ['datetime(3)', true],
            ],
            'pa_workflow_work_item' => [
                'node_execution_id' => ['bigint unsigned', false], 'owner_member_id' => ['bigint unsigned', true],
                'owner_source' => ['varchar(16)', false, 'automatic'], 'assignment_state' => ['varchar(16)', false, 'unassigned'],
                'seat_no' => ['int unsigned', false], 'sources_json' => ['json', false],
            ],
        ] as $table => $definitions) {
            foreach ($definitions as $column => $definition) { self::column($after[$table]['columns'][$column], ...$definition); }
        }
        self::indexColumns($after['pa_workflow_instance'], 'uk_workflow_instance_active_subject', ['tenant_id', 'module_key', 'workflow_key', 'subject_type', 'subject_key', 'active_marker']);
        self::indexColumns($after['pa_workflow_work_item'], 'uk_workflow_work_item_seat', ['tenant_id', 'node_execution_id', 'seat_no']);
        self::indexColumns($after['pa_workflow_work_item'], 'uk_workflow_work_item_assignee_once', ['tenant_id', 'node_execution_id', 'assignee_member_id']);
        self::check($after['pa_workflow_instance'], 'chk_workflow_instance_status', "status IN ('running','blocked','completed','cancelled','terminated')");
        self::check($after['pa_workflow_work_item'], 'chk_workflow_work_item_assignment', "(assignment_state='unassigned' AND assignee_member_id IS NULL) OR (assignment_state='assigned' AND assignee_member_id IS NOT NULL)");
        self::foreignKey($after['pa_workflow_work_item'], 'fk_workflow_work_item_node', ['tenant_id', 'node_execution_id'],
            $after['pa_workflow_work_item']['info']['TABLE_SCHEMA'], 'pa_workflow_node_execution', ['tenant_id', 'id']);
        self::newTables($after);
    }

    private static function nullableColumn(array $column, string $type): void
    {
        self::column($column, $type, true);
    }

    private static function column(array $column, string $type, bool $nullable, ?string $default = null, string $extra = '', ?string $collation = null): void
    {
        $collation ??= str_starts_with($type, 'varchar(') || str_starts_with($type, 'char(') ? 'utf8mb4_0900_ai_ci' : null;
        if ($column['COLUMN_TYPE'] !== $type || $column['IS_NULLABLE'] !== ($nullable ? 'YES' : 'NO')
            || $column['COLUMN_DEFAULT'] !== $default || $column['EXTRA'] !== $extra
            || $column['GENERATION_EXPRESSION'] !== '' || $column['COLLATION_NAME'] !== $collation) { self::fail('COLUMN_DELTA_INVALID'); }
    }

    private static function indexColumns(array $table, string $name, array $columns, bool $unique = true): void
    {
        $index = $table['indexes'][$name] ?? [];
        if (array_column($index, 'COLUMN_NAME') !== $columns) { self::fail('V2_INDEX_INVALID'); }
        foreach ($index as $position => $part) {
            if ($part['NON_UNIQUE'] !== ($unique ? '0' : '1') || $part['SEQ_IN_INDEX'] !== (string) ($position + 1)
                || $part['SUB_PART'] !== null || $part['EXPRESSION'] !== null || $part['COLLATION'] !== 'A'
                || $part['INDEX_TYPE'] !== 'BTREE' || $part['IS_VISIBLE'] !== 'YES') { self::fail('V2_INDEX_INVALID'); }
        }
    }

    private static function newTables(array $tables): void
    {
        $node = $tables['pa_workflow_node_execution'];
        $link = $tables['pa_workflow_task_identity_link'];
        $definitions = [
            'pa_workflow_node_execution' => [
                'id' => ['bigint unsigned', false, null, 'auto_increment'], 'tenant_id' => ['bigint unsigned', false],
                'instance_id' => ['bigint unsigned', false], 'node_execution_key' => ['varchar(64)', false, null, '', 'ascii_bin'],
                'node_key' => ['varchar(64)', false], 'sequence_no' => ['int unsigned', false], 'cycle_no' => ['int unsigned', false],
                'previous_actor_member_id' => ['bigint unsigned', true], 'node_type' => ['varchar(32)', false], 'status' => ['varchar(16)', false],
                'revision' => ['bigint unsigned', false, '1'], 'allocation_mode' => ['varchar(16)', true], 'aggregation_policy' => ['varchar(32)', true],
                'input_json' => ['json', false], 'action_input_json' => ['json', true], 'started_at' => ['datetime(3)', true],
                'people_json' => ['json', false], 'output_json' => ['json', true], 'selected_transition_key' => ['varchar(64)', true],
                'blocked_reason' => ['varchar(500)', true], 'effect_key' => ['varchar(200)', true], 'effect_receipt_json' => ['json', true],
                'task_job_key' => ['varchar(64)', true], 'created_at' => ['datetime(3)', false], 'completed_at' => ['datetime(3)', true],
            ],
            'pa_workflow_task_identity_link' => [
                'id' => ['bigint unsigned', false, null, 'auto_increment'], 'tenant_id' => ['bigint unsigned', false],
                'work_item_id' => ['bigint unsigned', false], 'member_id' => ['bigint unsigned', false],
                'relation_type' => ['varchar(16)', false], 'sources_json' => ['json', false],
            ],
        ];
        foreach ($definitions as $table => $columns) {
            $actual = $tables[$table];
            if ($actual['info']['TABLE_COLLATION'] !== 'utf8mb4_0900_ai_ci' || $actual['info']['CREATE_OPTIONS'] !== '') { self::fail('V2_CREATE_INVALID'); }
            foreach ($columns as $name => $definition) { self::column($actual['columns'][$name], ...$definition); }
            self::indexColumns($actual, 'PRIMARY', ['id']);
        }
        foreach ([
            'uk_workflow_node_tenant_id' => ['tenant_id', 'id'], 'uk_workflow_node_key' => ['node_execution_key'],
            'uk_workflow_node_sequence' => ['tenant_id', 'instance_id', 'sequence_no'],
        ] as $name => $columns) { self::indexColumns($node, $name, $columns); }
        self::indexColumns($link, 'uk_workflow_identity_relation', ['tenant_id', 'work_item_id', 'member_id', 'relation_type']);
        self::indexColumns($link, 'idx_workflow_identity_member', ['tenant_id', 'member_id', 'relation_type', 'work_item_id'], false);
        self::foreignKey($node, 'fk_workflow_node_instance', ['tenant_id', 'instance_id'], $node['info']['TABLE_SCHEMA'], 'pa_workflow_instance', ['tenant_id', 'id']);
        self::foreignKey($link, 'fk_workflow_identity_item', ['tenant_id', 'work_item_id'], $link['info']['TABLE_SCHEMA'], 'pa_workflow_work_item', ['tenant_id', 'id']);
        self::check($node, 'chk_workflow_node_status', "status IN ('pending','active','blocked','completed','cancelled')");
        self::check($node, 'chk_workflow_node_revision', 'revision >= 1');
        self::check($link, 'chk_workflow_identity_type', "relation_type IN ('candidate','participant','watcher')");
        self::exactKeys($node['indexes'], ['PRIMARY', 'uk_workflow_node_tenant_id', 'uk_workflow_node_key', 'uk_workflow_node_sequence']);
        self::exactKeys($link['indexes'], ['PRIMARY', 'uk_workflow_identity_relation', 'idx_workflow_identity_member']);
        self::exactKeys($node['foreign_keys'], ['fk_workflow_node_instance']);
        self::exactKeys($link['foreign_keys'], ['fk_workflow_identity_item']);
        self::exactKeys($node['constraints'], ['PRIMARY', 'uk_workflow_node_tenant_id', 'uk_workflow_node_key', 'uk_workflow_node_sequence',
            'fk_workflow_node_instance', 'chk_workflow_node_status', 'chk_workflow_node_revision']);
        self::exactKeys($link['constraints'], ['PRIMARY', 'uk_workflow_identity_relation', 'fk_workflow_identity_item', 'chk_workflow_identity_type']);
    }

    private static function exactKeys(array $actual, array $expected): void
    {
        $keys = array_keys($actual); sort($keys, SORT_STRING); sort($expected, SORT_STRING);
        if ($keys !== $expected) { self::fail('V2_CREATE_INVALID'); }
    }

    private static function foreignKey(array $table, string $name, array $columns, string $database, string $target, array $targetColumns): void
    {
        $rows = $table['foreign_keys'][$name] ?? [];
        if (array_column($rows, 'COLUMN_NAME') !== $columns || array_column($rows, 'REFERENCED_COLUMN_NAME') !== $targetColumns
            || ($table['constraints'][$name]['CONSTRAINT_TYPE'] ?? null) !== 'FOREIGN KEY') { self::fail('V2_FOREIGN_KEY_INVALID'); }
        foreach ($rows as $position => $row) {
            if ($row['ORDINAL_POSITION'] !== (string) ($position + 1) || $row['REFERENCED_TABLE_SCHEMA'] !== $database
                || $row['REFERENCED_TABLE_NAME'] !== $target || ($row['UPDATE_RULE'] !== 'NO ACTION' && $row['UPDATE_RULE'] !== 'RESTRICT')
                || $row['DELETE_RULE'] !== 'RESTRICT') { self::fail('V2_FOREIGN_KEY_INVALID'); }
        }
    }

    private static function check(array $table, string $name, string $expression): void
    {
        $constraint = $table['constraints'][$name] ?? [];
        if (($constraint['CONSTRAINT_TYPE'] ?? null) !== 'CHECK' || ($constraint['ENFORCED'] ?? null) !== 'YES'
            || self::predicate(self::tokens((string) ($constraint['CHECK_CLAUSE'] ?? ''))) !== self::predicate(self::tokens($expression))) {
            self::fail('V2_CHECK_INVALID');
        }
    }

    /** Compare this module's fixed predicates, retaining AND/OR grouping and literal case. Never execute metadata SQL. */
    private static function tokens(string $expression): array
    {
        // MySQL metadata may escape literal delimiters. Recognize only a complete
        // literal token; never unescape operators, grouping or arbitrary SQL.
        $pattern = <<<'REGEX'
~\G(?:\s+|\\'(?<metadata_literal>[^'\\]*)\\'|(?<literal>'(?:''|[^'])*')|(?<token>`[a-z][a-z0-9_]*`|[A-Za-z_][A-Za-z0-9_]*|[0-9]+|>=|<=|<>|!=|[(),=<>]))~
REGEX;
        $tokens = []; $offset = 0;
        while ($offset < strlen($expression)) {
            if (preg_match($pattern, $expression, $match, PREG_UNMATCHED_AS_NULL, $offset) !== 1) { self::fail('EXPRESSION_UNSUPPORTED'); }
            $offset += strlen($match[0]);
            if (trim($match[0]) === '') { continue; }
            $tokens[] = $match['metadata_literal'] !== null ? "'" . $match['metadata_literal'] . "'"
                : ($match['literal'] ?? strtolower(trim($match['token'], '`')));
        }
        if ($tokens === []) { self::fail('EXPRESSION_UNSUPPORTED'); }
        $normalized = [];
        foreach ($tokens as $index => $token) {
            if (in_array($token, ['_ascii', '_utf8mb4', '_utf8mb3', '_binary'], true) && str_starts_with($tokens[$index + 1] ?? '', "'")) { continue; }
            $normalized[] = $token;
        }
        return self::unwrap($normalized);
    }

    private static function unwrap(array $tokens): array
    {
        do {
            $depth = 0; $wrapped = ($tokens[0] ?? null) === '(' && end($tokens) === ')';
            foreach ($tokens as $index => $token) {
                if ($token === '(') { ++$depth; }
                if ($token === ')') { --$depth; }
                if ($depth < 0) { self::fail('EXPRESSION_UNSUPPORTED'); }
                if ($depth === 0 && $index < count($tokens) - 1) { $wrapped = false; }
            }
            if ($depth !== 0) { self::fail('EXPRESSION_UNSUPPORTED'); }
            if ($wrapped) { $tokens = array_slice($tokens, 1, -1); }
        } while ($wrapped);
        if ($tokens === []) { self::fail('EXPRESSION_UNSUPPORTED'); }
        return $tokens;
    }

    private static function predicate(array $tokens): array
    {
        $tokens = self::unwrap($tokens);
        foreach (['or', 'and'] as $operator) {
            $depth = 0; $parts = []; $start = 0;
            foreach ($tokens as $index => $token) {
                if ($token === '(') { ++$depth; }
                if ($token === ')') { --$depth; }
                if ($depth === 0 && $token === $operator) { $parts[] = self::predicate(array_slice($tokens, $start, $index - $start)); $start = $index + 1; }
            }
            if ($parts !== []) { $parts[] = self::predicate(array_slice($tokens, $start)); return [$operator, $parts]; }
        }
        return ['atom', $tokens];
    }

    private static function tables(PDO $pdo): array
    {
        $result = [];
        foreach ([...array_keys(self::BASE_COLUMNS), ...self::NEW_TABLES] as $table) {
            $info = self::query($pdo, 'SELECT TABLE_SCHEMA,TABLE_TYPE,ENGINE,TABLE_COLLATION,CREATE_OPTIONS,TABLE_COMMENT FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?', [$table]);
            if ($info === []) { $result[$table] = null; continue; }
            if (count($info) !== 1 || $info[0]['TABLE_TYPE'] !== 'BASE TABLE' || $info[0]['ENGINE'] !== 'InnoDB') { self::fail('SCHEMA_REQUIRES_REVIEW'); }
            // Initialize the native empty InnoDB allocator before reading uncached statistics.
            self::query($pdo, 'SHOW CREATE TABLE `' . $table . '`');
            $allocator = self::query($pdo, 'SELECT AUTO_INCREMENT FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?', [$table]);
            if (count($allocator) !== 1 || preg_match('/\A[1-9][0-9]*\z/D', (string) ($allocator[0]['AUTO_INCREMENT'] ?? '')) !== 1) { self::fail('STATISTICS_UNAVAILABLE'); }
            $info[0]['AUTO_INCREMENT'] = $allocator[0]['AUTO_INCREMENT'];
            $columns = self::query($pdo, 'SELECT COLUMN_NAME,ORDINAL_POSITION,COLUMN_TYPE,IS_NULLABLE,COLUMN_DEFAULT,EXTRA,CHARACTER_SET_NAME,COLLATION_NAME,COLUMN_COMMENT,GENERATION_EXPRESSION FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? ORDER BY ORDINAL_POSITION', [$table]);
            if (($columns[0]['COLUMN_NAME'] ?? null) !== 'id') { self::fail('SCHEMA_REQUIRES_REVIEW'); }
            $indexes = [];
            foreach (self::query($pdo, 'SELECT INDEX_NAME,NON_UNIQUE,SEQ_IN_INDEX,COLUMN_NAME,COLLATION,SUB_PART,INDEX_TYPE,IS_VISIBLE,EXPRESSION FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? ORDER BY INDEX_NAME,SEQ_IN_INDEX', [$table]) as $index) { $indexes[$index['INDEX_NAME']][] = $index; }
            if (array_column($indexes['PRIMARY'] ?? [], 'COLUMN_NAME') !== ['id']) { self::fail('SCHEMA_REQUIRES_REVIEW'); }
            $constraints = self::query($pdo, 'SELECT T.CONSTRAINT_NAME,T.CONSTRAINT_TYPE,T.ENFORCED,C.CHECK_CLAUSE FROM information_schema.TABLE_CONSTRAINTS T LEFT JOIN information_schema.CHECK_CONSTRAINTS C ON C.CONSTRAINT_SCHEMA=T.CONSTRAINT_SCHEMA AND C.CONSTRAINT_NAME=T.CONSTRAINT_NAME WHERE T.TABLE_SCHEMA=DATABASE() AND T.TABLE_NAME=? ORDER BY T.CONSTRAINT_NAME', [$table]);
            $foreign = [];
            foreach (self::query($pdo, 'SELECT K.CONSTRAINT_NAME,K.ORDINAL_POSITION,K.COLUMN_NAME,K.REFERENCED_TABLE_SCHEMA,K.REFERENCED_TABLE_NAME,K.REFERENCED_COLUMN_NAME,R.UPDATE_RULE,R.DELETE_RULE FROM information_schema.KEY_COLUMN_USAGE K JOIN information_schema.REFERENTIAL_CONSTRAINTS R ON R.CONSTRAINT_SCHEMA=K.CONSTRAINT_SCHEMA AND R.TABLE_NAME=K.TABLE_NAME AND R.CONSTRAINT_NAME=K.CONSTRAINT_NAME WHERE K.TABLE_SCHEMA=DATABASE() AND K.TABLE_NAME=? ORDER BY K.CONSTRAINT_NAME,K.ORDINAL_POSITION', [$table]) as $key) { $foreign[$key['CONSTRAINT_NAME']][] = $key; }
            $result[$table] = ['info' => $info[0], 'columns' => array_column($columns, null, 'COLUMN_NAME'),
                'indexes' => $indexes, 'constraints' => array_column($constraints, null, 'CONSTRAINT_NAME'), 'foreign_keys' => $foreign];
        }
        return $result;
    }

    private static function data(PDO $pdo, array $tables): array
    {
        $data = [];
        foreach ($tables as $table => $metadata) { $data[$table] = $metadata === null ? null : self::rows($pdo, $table, array_keys($metadata['columns'])); }
        return $data;
    }

    private static function rows(PDO $pdo, string $table, array $columns): array
    {
        $quoted = array_map(static fn(string $name): string => '`' . str_replace('`', '``', $name) . '`', $columns);
        $query = $pdo->query('SELECT ' . implode(',', $quoted) . ' FROM `' . $table . '` ORDER BY id');
        $digest = hash_init('sha256'); $count = 0;
        try {
            while ($row = $query->fetch(PDO::FETCH_NUM)) {
                foreach ($row as $value) {
                    hash_update($digest, $value === null ? 'N;' : 'S' . strlen((string) $value) . ':' . (string) $value . ';');
                }
                hash_update($digest, "\n"); ++$count;
            }
        } finally { $query->closeCursor(); }
        return ['count' => $count, 'sha256' => hash_final($digest)];
    }

    private static function ledger(PDO $pdo, array $stage): array
    {
        if (!($stage['stageLedger'] ?? null) instanceof Closure) { self::fail('LEDGER_READER_REQUIRED'); }
        $rows = ($stage['stageLedger'])($pdo); $seen = [];
        if (!is_array($rows)) { self::fail('LEDGER_INVALID'); }
        foreach ($rows as $row) {
            $key = $row['migration_key'] ?? null;
            $repaired = $key === self::MANAGED && ($row['status'] ?? null) === 'failed'
                && ($row['resolution_kind'] ?? null) === null && ($row['provenance_json'] ?? null) === null
                && ($row['error_code'] ?? null) === 'MODULE_MIGRATION_FAILED' && is_string($row['finished_at'] ?? null)
                && isset(array_column($rows, null, 'migration_key')[self::REPAIR]);
            if (($row['module_key'] ?? null) !== 'peanut.workflow' || !is_string($key) || !isset(self::SOURCES[$key])
                || isset($seen[$key]) || ($row['checksum'] ?? null) !== self::SOURCES[$key]
                || (!$repaired && ($row['status'] ?? null) !== ($key === $stage['migration_key'] ? 'applying' : 'applied'))
                || strcmp($key, $stage['migration_key']) > 0) { self::fail('LEDGER_INVALID'); }
            $seen[$key] = true;
        }
        $required = array_slice(array_keys(self::SOURCES), 0, array_search($stage['migration_key'], array_keys(self::SOURCES), true) + 1);
        // A genuine successful original needs no repair ledger on historical consumers.
        $original = array_column($rows, null, 'migration_key')[self::MANAGED] ?? [];
        if (($original['status'] ?? null) === 'applied' && !isset($seen[self::REPAIR])) { $required = array_values(array_diff($required, [self::REPAIR])); }
        if (array_diff($required, array_keys($seen)) !== []) { self::fail('LEDGER_INVALID'); }
        return $rows;
    }

    private static function source(string $key): void
    {
        $path = dirname(__DIR__, 2) . '/database/migrations/' . substr($key, strlen('peanut.workflow:')) . '.sql';
        if (!is_file($path) || is_link($path) || hash_file('sha256', $path) !== self::SOURCES[$key]) { self::fail('SOURCE_CHANGED'); }
    }

    private static function connection(PDO $pdo): array
    {
        return self::query($pdo, 'SELECT DATABASE() AS db,@@server_uuid AS server_uuid,CONNECTION_ID() AS connection_id')[0];
    }

    private static function query(PDO $pdo, string $sql, array $parameters = []): array
    {
        $statement = $pdo->prepare($sql); $statement->execute($parameters);
        try { return array_map(static fn(array $row): array => array_map(static fn($value) => $value === null ? null : (string) $value, $row), $statement->fetchAll(PDO::FETCH_ASSOC)); }
        finally { $statement->closeCursor(); }
    }

    private static function snapshot(PDO $pdo, Closure $read): array
    {
        $started = false; $statistics = null;
        try {
            if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'mysql' || $pdo->getAttribute(PDO::ATTR_CASE) !== PDO::CASE_NATURAL || $pdo->inTransaction()) { self::fail('CONNECTION_UNSUPPORTED'); }
            $statement = $pdo->query('SELECT @@SESSION.information_schema_stats_expiry');
            try { $value = $statement->fetchColumn(); } finally { $statement->closeCursor(); }
            if (!is_int($value) && (!is_string($value) || preg_match('/\A[0-9]+\z/D', $value) !== 1)) { self::fail('STATISTICS_UNAVAILABLE'); }
            $statistics = (int) $value;
            $pdo->exec('SET SESSION information_schema_stats_expiry=0');
            $pdo->exec('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ, READ ONLY'); $pdo->beginTransaction(); $started = true;
            $result = $read(); if (!$pdo->inTransaction()) { self::fail('SNAPSHOT_CHANGED'); } return $result;
        } catch (Throwable $error) {
            if ($error instanceof RuntimeException && str_starts_with($error->getMessage(), 'WORKFLOW_SCHEMA_STAGE_')) { throw $error; }
            throw new RuntimeException('WORKFLOW_SCHEMA_STAGE_OBSERVATION_FAILED', 0, $error);
        } finally {
            try { if ($started && $pdo->inTransaction()) { $pdo->rollBack(); } }
            finally { if ($statistics !== null) { $pdo->exec('SET SESSION information_schema_stats_expiry=' . $statistics); } }
        }
    }

    private static function fail(string $code): never
    {
        throw new RuntimeException('WORKFLOW_SCHEMA_STAGE_' . $code);
    }
}
