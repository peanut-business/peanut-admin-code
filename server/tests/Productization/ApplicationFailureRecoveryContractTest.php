<?php

declare(strict_types=1);

use app\common\services\upgrade\ApplicationSchemaHost;
use app\common\services\upgrade\SourceApplicationBackup;

require dirname(__DIR__, 2) . '/vendor/autoload.php';

// Synthetic admission fields only; no instance context, database, SSH or restore is executed.
$class = new ReflectionClass(ApplicationSchemaHost::class); $host = $class->newInstanceWithoutConstructor();
$class->getProperty('serverRoot')->setValue($host, dirname(__DIR__, 2));
$profileCheck = $class->getMethod('sourceFailureRestoreProfile');
$sqlRoot = dirname(__DIR__, 2) . '/database/migrations/';
$smsId = '20260909-notification-sms-reservation'; $navigationId = '20261004-group-default-navigation';
$menuId = '20261004-menu-stable-identities';
$menuFailure = ['failure_afterimage' => true, 'executed_prefix' => [$smsId, $navigationId],
    'selected' => [$smsId => hash_file('sha256', $sqlRoot . $smsId . '.sql'), $navigationId => hash_file('sha256', $sqlRoot . $navigationId . '.sql')],
    'failed_migration' => ['migration_id' => $menuId, 'status' => 'failed', 'checksum' => hash_file('sha256', $sqlRoot . $menuId . '.sql'),
        'error_code' => 'IDENTITY_MENU_MIGRATION_CUSTOM_ID_ALLOCATION_RANGE']];
if ($profileCheck->invoke($host, $menuFailure) !== 'menu-stable-identities'
    || SourceApplicationBackup::failureRestoreTables('menu-stable-identities') !== ['pa_menu_definition', 'pa_notice_log', 'pa_schema_migration', 'pa_system_menu']) {
    throw new LogicException('Fixed menu recovery profile lost.');
}
$smsFailure = ['failure_afterimage' => true, 'executed_prefix' => [], 'failed_migration' => [
    'migration_id' => $smsId, 'status' => 'failed', 'checksum' => hash_file('sha256', $sqlRoot . $smsId . '.sql'),
    'error_code' => 'NOTIFICATION_SMS_MIGRATION_SCHEMA_POSTIMAGE_INVALID']];
if ($profileCheck->invoke($host, $smsFailure) !== 'sms-reservation'
    || SourceApplicationBackup::failureRestoreTables('sms-reservation') !== ['pa_notice_log', 'pa_schema_migration']) {
    throw new LogicException('Original SMS recovery profile changed.');
}
$invalidProfiles = [];
$changed = $menuFailure; $changed['failure_afterimage'] = false; $invalidProfiles[] = $changed;
$changed = $menuFailure; $changed['failed_migration']['status'] = 'applied'; $invalidProfiles[] = $changed;
$changed = $menuFailure; $changed['failed_migration']['migration_id'] = 'unknown'; $invalidProfiles[] = $changed;
$changed = $menuFailure; $changed['failed_migration']['checksum'] = str_repeat('0', 64); $invalidProfiles[] = $changed;
$changed = $menuFailure; $changed['failed_migration']['error_code'] = 'OTHER_FAILURE'; $invalidProfiles[] = $changed;
$changed = $menuFailure; $changed['executed_prefix'] = [$smsId]; $invalidProfiles[] = $changed;
$changed = $menuFailure; $changed['executed_prefix'] = [$navigationId, $smsId]; $invalidProfiles[] = $changed;
$changed = $menuFailure; $changed['executed_prefix'][] = $menuId; $invalidProfiles[] = $changed;
$changed = $menuFailure; $changed['selected'][$navigationId] = str_repeat('0', 64); $invalidProfiles[] = $changed;
foreach ($invalidProfiles as $changed) {
    try { $profileCheck->invoke($host, $changed); }
    catch (RuntimeException $error) { if ($error->getMessage() === 'APPLICATION_SCHEMA_FAILURE_RESTORE_UNSUPPORTED') { continue; } throw $error; }
    throw new LogicException('Unsupported failure profile accepted.');
}
try { SourceApplicationBackup::failureRestoreTables('caller-selected-tables'); throw new LogicException('Unknown restore tables accepted.'); }
catch (RuntimeException $error) { if ($error->getMessage() !== 'APPLICATION_SCHEMA_FAILURE_RESTORE_UNSUPPORTED') { throw $error; } }
$foreign = $class->getMethod('sourceFailureForeignKeys');
$fk = ['TABLE_SCHEMA' => 'selected_db', 'TABLE_NAME' => 'pa_notice_log', 'COLUMN_NAME' => 'tenant_id',
    'REFERENCED_TABLE_SCHEMA' => 'selected_db', 'REFERENCED_TABLE_NAME' => 'pa_tenant', 'REFERENCED_COLUMN_NAME' => 'id',
    'DELETE_RULE' => 'RESTRICT', 'UPDATE_RULE' => 'NO ACTION'];
$scope = ['tables' => ['pa_notice_log', 'pa_schema_migration', 'pa_tenant'], 'foreign_keys' => [$fk], 'triggers' => []];
$foreign->invoke($host, $scope, $scope, 'selected_db');
$invalid = [];
$changed = $scope; $changed['foreign_keys'][0]['DELETE_RULE'] = 'CASCADE'; $invalid[] = $changed;
$changed = $scope; $changed['foreign_keys'][0]['REFERENCED_TABLE_SCHEMA'] = 'outside'; $invalid[] = $changed;
$changed = $scope; $changed['foreign_keys'][0]['REFERENCED_TABLE_NAME'] = 'pa_unobserved'; $invalid[] = $changed;
$changed = $scope; $changed['foreign_keys'][0]['REFERENCED_TABLE_NAME'] = 'pa_notice_log'; $invalid[] = $changed;
$changed = $scope; $changed['foreign_keys'][0]['COLUMN_NAME'] = 'different_parent'; $invalid[] = $changed;
$changed = $scope; $changed['triggers'][] = ['table' => 'pa_notice_log']; $invalid[] = $changed;
foreach ($invalid as $changed) {
    try { $foreign->invoke($host, $scope, $changed, 'selected_db'); }
    catch (RuntimeException $error) { if ($error->getMessage() === 'APPLICATION_SCHEMA_FAILURE_RESTORE_DEPENDENCY_UNKNOWN') { continue; } throw $error; }
    throw new RuntimeException('Unsupported restoration dependency accepted.');
}
$menuTables = SourceApplicationBackup::failureRestoreTables('menu-stable-identities');
$menuScope = ['tables' => [...$menuTables, 'pa_tenant', 'pa_permission'], 'foreign_keys' => [$fk,
    array_replace($fk, ['TABLE_NAME' => 'pa_menu_definition', 'COLUMN_NAME' => 'required_permission_id', 'REFERENCED_TABLE_NAME' => 'pa_permission'])], 'triggers' => []];
$foreign->invoke($host, $menuScope, $menuScope, 'selected_db', $menuTables);
$invalid = [];
$changed = $menuScope; $changed['foreign_keys'][] = array_replace($fk, ['TABLE_NAME' => 'pa_other', 'REFERENCED_TABLE_NAME' => 'pa_system_menu']); $invalid[] = $changed;
$changed = $menuScope; $changed['triggers'][] = ['table' => 'pa_system_menu']; $invalid[] = $changed;
$changed = $menuScope; $changed['foreign_keys'][1]['DELETE_RULE'] = 'CASCADE'; $invalid[] = $changed;
$changed = $menuScope; $changed['foreign_keys'][1]['COLUMN_NAME'] = 'another_permission'; $invalid[] = $changed;
foreach ($invalid as $changed) {
    try { $foreign->invoke($host, $menuScope, $changed, 'selected_db', $menuTables); }
    catch (RuntimeException $error) { if ($error->getMessage() === 'APPLICATION_SCHEMA_FAILURE_RESTORE_DEPENDENCY_UNKNOWN') { continue; } throw $error; }
    throw new LogicException('Unsafe menu restoration dependency accepted.');
}
$windowCheck = $class->getMethod('sourceFailureWindow');
$directory = dirname(__DIR__, 3) . '/.local/tmp/application-failure-window-contract';
if (file_exists($directory) || is_link($directory)) { throw new RuntimeException('Contract temporary directory already exists.'); }
if (!is_dir(dirname($directory))) { mkdir(dirname($directory), 0700, true); }
mkdir($directory, 0700); $path = $directory . '/window.json'; $receipt = $directory . '/receipt.json';
file_put_contents($receipt, '{"synthetic_contract_only":true}'); chmod($receipt, 0600);
$binding = ['source' => ['head' => 'synthetic'], 'database' => ['database' => 'selected_db'],
    'authority' => ['created_at' => time() - 10, 'expires_at' => time() + 600], 'registry_sha256' => str_repeat('a', 64)];
$capsule = ['path' => '/synthetic/failure', 'sha256' => str_repeat('b', 64)];
$writes = ['pa_notice_log', 'pa_schema_migration']; $reads = $scope['tables'];
$window = ['protocol' => 'peanut.source-development-failure-maintenance.v1', 'state' => 'active',
    'source' => $binding['source'], 'database' => $binding['database'], 'authority' => $binding['authority'], 'registry_sha256' => $binding['registry_sha256'],
    'failure_snapshot' => $capsule, 'write_tables' => $writes, 'read_tables' => $reads,
    'excluded_writers' => ['http', 'workers', 'other-writers'], 'observed_at' => time(), 'expires_at' => time() + 300,
    'receipts' => [['path' => $receipt, 'sha256' => hash_file('sha256', $receipt)]]];
$run = static function (array $value) use ($path, $windowCheck, $host, $binding, $capsule, $writes, $reads): array {
    file_put_contents($path, json_encode($value, JSON_THROW_ON_ERROR)); chmod($path, 0600);
    return $windowCheck->invoke($host, $path, $binding, $capsule, $writes, $reads);
};
try {
    $accepted = $run($window);
    if ($accepted['files'][$path] !== hash_file('sha256', $path)) { throw new RuntimeException('Window byte identity lost.'); }
    $invalid = [];
    $changed = $window; $changed['expires_at'] = time() - 1; $invalid[] = $changed;
    $changed = $window; $changed['expires_at'] = time() + 1000; $invalid[] = $changed;
    $changed = $window; $changed['excluded_writers'] = ['http']; $invalid[] = $changed;
    $changed = $window; $changed['write_tables'][] = 'pa_tenant'; $invalid[] = $changed;
    $changed = $window; $changed['source']['head'] = 'other'; $invalid[] = $changed;
    $changed = $window; $changed['failure_snapshot']['sha256'] = str_repeat('c', 64); $invalid[] = $changed;
    $changed = $window; $changed['receipts'] = []; $invalid[] = $changed;
    $changed = $window; $changed['receipts'][0]['sha256'] = str_repeat('d', 64); $invalid[] = $changed;
    foreach ($invalid as $changed) {
        try { $run($changed); }
        catch (RuntimeException $error) { if ($error->getMessage() === 'APPLICATION_SCHEMA_SOURCE_WINDOW_REQUIRED') { continue; } throw $error; }
        throw new RuntimeException('Invalid restoration window accepted.');
    }
    $historicalCheck = $class->getMethod('sourceFailureHistoricalWindow');
    $intervalCheck = $class->getMethod('sourceFailureHistoricalInterval');
    $at = time() - 7200;
    $oldBinding = $binding; $oldBinding['authority'] = ['created_at' => $at - 10, 'expires_at' => $at + 1000];
    $baseline = ['pending' => [$smsId, $navigationId, $menuId], 'selected' => $menuFailure['selected'] + [$menuId => $menuFailure['failed_migration']['checksum']]];
    $digest = $class->getMethod('digest');
    $oldWindow = ['schema_version' => 1, 'protocol' => 'peanut.source-development-maintenance.v1', 'state' => 'active',
        'source' => $oldBinding['source'], 'database' => $oldBinding['database'], 'authority' => $oldBinding['authority'],
        'registry_sha256' => $oldBinding['registry_sha256'], 'pending' => $baseline['pending'], 'selected' => $baseline['selected'],
        'baseline_sha256' => $digest->invoke($host, $baseline), 'scope' => $reads, 'excluded_writers' => ['http', 'workers', 'other-writers'],
        'observed_at' => $at, 'expires_at' => $at + 900, 'receipts' => [['path' => $receipt, 'sha256' => hash_file('sha256', $receipt)]]];
    $oldBackup = ['capture_started_at' => $at + 10, 'observed_at' => $at + 20];
    $oldPlan = ['binding' => $oldBinding, 'baseline' => $baseline, 'backup_scope' => ['tables' => $reads]];
    $runHistory = static function (array $value, array $backup) use ($path, $oldPlan, $historicalCheck, $host): array {
        file_put_contents($path, json_encode($value, JSON_THROW_ON_ERROR)); chmod($path, 0600);
        $plan = $oldPlan; $plan['evidence']['maintenance'] = ['path' => $path, 'sha256' => hash_file('sha256', $path)];
        return $historicalCheck->invoke($host, $plan, $backup);
    };
    $history = $runHistory($oldWindow, $oldBackup);
    if ($history['files'][$receipt] !== hash_file('sha256', $receipt)) { throw new LogicException('Historical receipt not retained.'); }
    $invalid = [];
    foreach (['protocol', 'state', 'registry_sha256', 'baseline_sha256'] as $key) { $changed = $oldWindow; $changed[$key] = 'changed'; $invalid[] = [$changed, $oldBackup]; }
    foreach (['source', 'database', 'authority', 'pending', 'selected', 'scope', 'excluded_writers', 'receipts'] as $key) { $changed = $oldWindow; $changed[$key] = []; $invalid[] = [$changed, $oldBackup]; }
    $changed = $oldWindow; $changed['receipts'][0]['sha256'] = str_repeat('0', 64); $invalid[] = [$changed, $oldBackup];
    $changed = $oldWindow; $changed['observed_at'] = $at - 11; $invalid[] = [$changed, $oldBackup];
    $changed = $oldWindow; $changed['expires_at'] = $at + 1001; $invalid[] = [$changed, $oldBackup];
    $invalid[] = [$oldWindow, ['capture_started_at' => $at - 1, 'observed_at' => $at + 20]];
    $invalid[] = [$oldWindow, ['capture_started_at' => $at + 10, 'observed_at' => $at + 900]];
    foreach ($invalid as [$changed, $backup]) {
        try { $runHistory($changed, $backup); }
        catch (RuntimeException $error) { if ($error->getMessage() === 'APPLICATION_SCHEMA_FAILURE_HISTORICAL_WINDOW_REQUIRED') { continue; } throw $error; }
        throw new LogicException('Invalid historical maintenance accepted.');
    }
    $runHistory($oldWindow, $oldBackup);
    $wrongHashPlan = $oldPlan; $wrongHashPlan['evidence']['maintenance'] = ['path' => $path, 'sha256' => str_repeat('0', 64)];
    try { $historicalCheck->invoke($host, $wrongHashPlan, $oldBackup); throw new LogicException('Changed original maintenance bytes accepted.'); }
    catch (RuntimeException $error) { if ($error->getMessage() !== 'APPLICATION_SCHEMA_FAILURE_HISTORICAL_WINDOW_REQUIRED') { throw $error; } }
    $format = static fn(int $value): string => gmdate('Y-m-d H:i:s', $value);
    $oldCapsule = ['capture_started_at' => $at + 100, 'observed_at' => $at + 120,
        'failure' => ['executed_prefix' => [$smsId, $navigationId], 'failed_migration' => ['migration_id' => $menuId], 'ledger' => []]];
    foreach ($baseline['pending'] as $i => $id) { $oldCapsule['failure']['ledger'][] = ['migration_id' => $id, 'started_at' => $format($at + 30 + $i * 10), 'finished_at' => $format($at + 31 + $i * 10)]; }
    $intervalCheck->invoke($host, $history, $oldCapsule, $baseline['pending']);
    $invalid = [];
    $changed = $oldCapsule; $changed['observed_at'] = $at + 900; $invalid[] = $changed;
    $changed = $oldCapsule; $changed['capture_started_at'] = $at + 19; $invalid[] = $changed;
    $changed = $oldCapsule; $changed['failure']['ledger'][0]['started_at'] = $format($at + 19); $invalid[] = $changed;
    $changed = $oldCapsule; $changed['failure']['ledger'][1]['started_at'] = $format($at + 30); $invalid[] = $changed;
    $changed = $oldCapsule; $changed['failure']['ledger'][2]['finished_at'] = $format($at + 101); $invalid[] = $changed;
    $changed = $oldCapsule; $changed['failure']['ledger'][2]['finished_at'] .= '+08:00'; $invalid[] = $changed;
    $changed = $oldCapsule; $changed['failure']['ledger'][2]['finished_at'] = '2026-02-30 00:00:00'; $invalid[] = $changed;
    $changed = $oldCapsule; array_pop($changed['failure']['ledger']); $invalid[] = $changed;
    foreach ($invalid as $changed) {
        try { $intervalCheck->invoke($host, $history, $changed, $baseline['pending']); }
        catch (RuntimeException $error) { if ($error->getMessage() === 'APPLICATION_SCHEMA_FAILURE_HISTORICAL_WINDOW_REQUIRED') { continue; } throw $error; }
        throw new LogicException('Invalid historical interval accepted.');
    }
    try { SourceApplicationBackup::capture([], [], '', [], [], '', static fn() => null, 'application-restoration'); throw new LogicException('Unbound restoration image accepted.'); }
    catch (RuntimeException $error) { if ($error->getMessage() !== 'APPLICATION_SCHEMA_FAILURE_ORIGIN_REQUIRED') { throw $error; } }
} finally { if (is_file($path)) { unlink($path); } unlink($receipt); rmdir($directory); }
echo "Application recovery contract: 2 fixed profiles, restrictive parents, expired historical window and ordered UTC interval accepted; 10 profile, 10 dependency, 8 current-window, 18 historical-window, 8 interval and 1 unbound image refusals; restore NOT_RUN.\n";
