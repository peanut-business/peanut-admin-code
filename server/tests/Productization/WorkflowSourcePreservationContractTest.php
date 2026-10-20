<?php

declare(strict_types=1);

use app\common\services\upgrade\SourceApplicationBackup;
use PeanutAdmin\Modules\Workflow\Database\WorkflowSchemaMigrationProof;

require dirname(__DIR__, 2) . '/vendor/autoload.php';

function workflowSourceExpect(bool $condition, string $message): void
{
    if (!$condition) { throw new RuntimeException($message); }
}

$root = dirname(__DIR__, 3);
$module = $root . '/server/app/modules/peanut/workflow';
$files = glob($module . '/database/migrations/*.sql');
sort($files, SORT_STRING);
$migrations = array_map(static fn(string $file): array => [
    'migration_key' => 'peanut.workflow:' . basename($file, '.sql'),
    'sha256' => hash_file('sha256', $file),
    'disposition' => 'execute',
], $files);
$plan = ['modules' => [['module_key' => 'peanut.workflow', 'migrations' => $migrations]]];
$effects = SourceApplicationBackup::pluginEffects($plan);
workflowSourceExpect($effects['closure_complete'] === true && count($effects['pending']) === 4, 'The four fixed native SQL stages lack recovery closure.');
foreach (['pa_workflow_definition', 'pa_workflow_definition_version', 'pa_workflow_instance', 'pa_workflow_work_item',
    'pa_workflow_event', 'pa_workflow_node_execution', 'pa_workflow_task_identity_link', 'pa_module_migration',
    'pa_permission', 'pa_plugin_installation', 'pa_module_installation', 'pa_plugin_module'] as $table) {
    workflowSourceExpect(in_array($table, $effects['tables'], true), 'Missing actual DDL/catalog recovery table: ' . $table);
}
workflowSourceExpect(count($effects['write_requirements']) === 4, 'Each SQL stage must retain its business preservation requirement.');
foreach ($effects['write_requirements'] as $requirement) {
    workflowSourceExpect($requirement['contract'] === WorkflowSchemaMigrationProof::class, 'The native source host selected a different preservation contract.');
}

$changed = $plan;
$changed['modules'][0]['migrations'][0]['sha256'] = str_repeat('0', 64);
$rejected = SourceApplicationBackup::pluginEffects($changed);
workflowSourceExpect($rejected['closure_complete'] === false
    && $rejected['requirements'][0]['code'] === 'PLUGIN_SOURCE_SQL_EFFECTS_AND_PRESERVATION_REQUIRED', 'Changed SQL acquired the fixed stage authority.');

$unknown = $plan;
$unknown['modules'][0]['migrations'][] = ['migration_key' => 'peanut.workflow:20990101-unknown',
    'sha256' => str_repeat('a', 64), 'disposition' => 'execute'];
workflowSourceExpect(SourceApplicationBackup::pluginEffects($unknown)['closure_complete'] === false, 'Unknown SQL acquired recovery closure.');

$adoption = $plan;
foreach ($adoption['modules'][0]['migrations'] as &$migration) { $migration['disposition'] = 'adopt'; }
unset($migration);
workflowSourceExpect(SourceApplicationBackup::pluginEffects($adoption)['closure_complete'] === false, 'Workflow execution proof was replaced by inferred adoption.');

$deferred = $plan;
foreach ($deferred['modules'][0]['migrations'] as &$migration) { $migration['disposition'] = 'after_predecessor'; }
unset($migration);
workflowSourceExpect(SourceApplicationBackup::pluginEffects($deferred)['closure_complete'] === true, 'Ordered predecessor stages lost their native proof requirement.');

$manifest = json_decode(file_get_contents($module . '/module.json'), true, 512, JSON_THROW_ON_ERROR);
workflowSourceExpect(in_array(WorkflowSchemaMigrationProof::class, $manifest['contracts']['exports'], true), 'Host preservation contract is not exported by Workflow.');
$check = new ReflectionMethod(WorkflowSchemaMigrationProof::class, 'check');
$expression = "(assignment_state='unassigned' AND assignee_member_id IS NULL) OR (assignment_state='assigned' AND assignee_member_id IS NOT NULL)";
$constraint = ['constraints' => ['assignment' => ['CONSTRAINT_TYPE' => 'CHECK', 'ENFORCED' => 'YES',
    'CHECK_CLAUSE' => "(((`assignment_state` = _utf8mb4'unassigned') and (`assignee_member_id` is null)) or ((`assignment_state` = _utf8mb4'assigned') and (`assignee_member_id` is not null)))"]]];
$check->invoke(null, $constraint, 'assignment', $expression);
foreach ([
    "((assignment_state='unassigned' AND assignee_member_id IS NULL) OR assignment_state='assigned') AND assignee_member_id IS NOT NULL",
    str_replace("'unassigned'", "'UNASSIGNED'", $expression),
] as $invalid) {
    $changed = $constraint; $changed['constraints']['assignment']['CHECK_CLAUSE'] = $invalid;
    try { $check->invoke(null, $changed, 'assignment', $expression); throw new LogicException('Changed CHECK semantics accepted.'); }
    catch (RuntimeException $error) { workflowSourceExpect($error->getMessage() === 'WORKFLOW_SCHEMA_STAGE_V2_CHECK_INVALID', 'Unexpected predicate rejection.'); }
}
$changed = $constraint; $changed['constraints']['assignment']['ENFORCED'] = 'NO';
try { $check->invoke(null, $changed, 'assignment', $expression); throw new LogicException('Unenforced CHECK accepted.'); }
catch (RuntimeException $error) { workflowSourceExpect($error->getMessage() === 'WORKFLOW_SCHEMA_STAGE_V2_CHECK_INVALID', 'Unexpected enforcement rejection.'); }
$foreign = new ReflectionMethod(WorkflowSchemaMigrationProof::class, 'foreignKey');
$table = ['constraints' => ['link' => ['CONSTRAINT_TYPE' => 'FOREIGN KEY']], 'foreign_keys' => ['link' => [
    ['ORDINAL_POSITION' => '1', 'COLUMN_NAME' => 'tenant_id', 'REFERENCED_TABLE_SCHEMA' => 'expected_db', 'REFERENCED_TABLE_NAME' => 'pa_workflow_instance', 'REFERENCED_COLUMN_NAME' => 'tenant_id', 'UPDATE_RULE' => 'RESTRICT', 'DELETE_RULE' => 'RESTRICT'],
    ['ORDINAL_POSITION' => '2', 'COLUMN_NAME' => 'instance_id', 'REFERENCED_TABLE_SCHEMA' => 'expected_db', 'REFERENCED_TABLE_NAME' => 'pa_workflow_instance', 'REFERENCED_COLUMN_NAME' => 'id', 'UPDATE_RULE' => 'RESTRICT', 'DELETE_RULE' => 'RESTRICT'],
]]];
$foreign->invoke(null, $table, 'link', ['tenant_id', 'instance_id'], 'expected_db', 'pa_workflow_instance', ['tenant_id', 'id']);
foreach (['missing', 'wrong_schema', 'cascade'] as $fault) {
    $changed = $table;
    if ($fault === 'missing') { unset($changed['foreign_keys']['link']); }
    elseif ($fault === 'wrong_schema') { $changed['foreign_keys']['link'][0]['REFERENCED_TABLE_SCHEMA'] = 'other_db'; }
    else { $changed['foreign_keys']['link'][0]['DELETE_RULE'] = 'CASCADE'; }
    try { $foreign->invoke(null, $changed, 'link', ['tenant_id', 'instance_id'], 'expected_db', 'pa_workflow_instance', ['tenant_id', 'id']); throw new LogicException('Changed FK accepted.'); }
    catch (RuntimeException $error) { workflowSourceExpect($error->getMessage() === 'WORKFLOW_SCHEMA_STAGE_V2_FOREIGN_KEY_INVALID', 'Unexpected foreign-key rejection.'); }
}
echo "WORKFLOW-SOURCE-PRESERVATION-CONTRACT passed; static SQL/effects closure only, MySQL execution NOT_RUN\n";
