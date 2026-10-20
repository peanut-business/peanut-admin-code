<?php

declare(strict_types=1);

use app\common\services\upgrade\ApplicationSchemaHost;
use app\common\services\upgrade\SourceApplicationBackup;

require dirname(__DIR__, 2) . '/vendor/autoload.php';

// Synthetic admission fields only; no instance context, database, SSH or restore is executed.
$class = new ReflectionClass(ApplicationSchemaHost::class); $host = $class->newInstanceWithoutConstructor();
$class->getProperty('serverRoot')->setValue($host, dirname(__DIR__, 2));
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
    try { SourceApplicationBackup::capture([], [], '', [], [], '', static fn() => null, 'application-restoration'); throw new LogicException('Unbound restoration image accepted.'); }
    catch (RuntimeException $error) { if ($error->getMessage() !== 'APPLICATION_SCHEMA_FAILURE_ORIGIN_REQUIRED') { throw $error; } }
} finally { if (is_file($path)) { unlink($path); } unlink($receipt); rmdir($directory); }
echo "Application recovery contract: unchanged restrictive parent allowed; 6 dependency, 8 window and 1 unbound image refusals; restore NOT_RUN.\n";
