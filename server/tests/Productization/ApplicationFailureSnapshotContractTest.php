<?php

declare(strict_types=1);

use app\common\services\upgrade\ApplicationSchemaHost;
use app\common\services\upgrade\SourceApplicationBackup;

require dirname(__DIR__, 2) . '/vendor/autoload.php';

$class = new ReflectionClass(ApplicationSchemaHost::class);
$host = $class->newInstanceWithoutConstructor();
$digest = $class->getMethod('digest');
$check = $class->getMethod('sourceFailureLedger');
$old = [['migration_id' => 'old', 'status' => 'applied', 'checksum' => str_repeat('a', 64)]];
$failed = ['migration_id' => 'first', 'status' => 'failed', 'checksum' => str_repeat('b', 64),
    'release_version' => '5.0.2', 'started_at' => '2030-01-01 00:00:00', 'finished_at' => '2030-01-01 00:00:01', 'error_code' => 'POSTIMAGE_INVALID'];
$shape = ['columns' => ['migration_id', 'status']];
$baseline = ['pending' => ['first', 'second'], 'selected' => ['first' => str_repeat('b', 64), 'second' => str_repeat('c', 64)],
    'release_versions' => ['first' => '5.0.2', 'second' => '5.0.2'], 'application_rows_count' => 1,
    'application_rows_sha256' => $digest->invoke($host, $old), 'application_shape_sha256' => $digest->invoke($host, $shape)];
$ledger = [...$old, $failed];
if ($check->invoke($host, $ledger, $baseline, $shape) !== $failed) { throw new RuntimeException('Failed row identity lost.'); }
$invalid = [];
$changed = $ledger; $changed[1]['status'] = 'applying'; $invalid[] = $changed;
$changed = $ledger; $changed[1]['checksum'] = str_repeat('c', 64); $invalid[] = $changed;
$changed = $ledger; $changed[1]['finished_at'] = null; $invalid[] = $changed;
$changed = $ledger; $changed[0]['checksum'] = str_repeat('d', 64); $invalid[] = $changed;
$changed = $ledger; $changed[] = array_replace($failed, ['migration_id' => 'second', 'status' => 'applied']); $invalid[] = $changed;
$changed = $ledger; $changed[1]['migration_id'] = 'second'; $invalid[] = $changed;
$invalid[] = [...$ledger, array_replace($failed, ['migration_id' => 'unknown'])];
foreach ($invalid as $changed) {
    try { $check->invoke($host, $changed, $baseline, $shape); }
    catch (RuntimeException $error) {
        if ($error->getMessage() === 'APPLICATION_SCHEMA_FAILED_LEDGER_REQUIRED') { continue; }
        throw $error;
    }
    throw new RuntimeException('Invalid failure history accepted.');
}
try { $check->invoke($host, $ledger, $baseline, ['columns' => []]); throw new LogicException('Changed ledger shape accepted.'); }
catch (RuntimeException $error) { if ($error->getMessage() !== 'APPLICATION_SCHEMA_FAILED_LEDGER_REQUIRED') { throw $error; } }
try { SourceApplicationBackup::capture([], [], '', [], [], '', static fn() => null, 'application-failure'); throw new LogicException('Unbound afterimage accepted.'); }
catch (RuntimeException $error) { if ($error->getMessage() !== 'APPLICATION_SCHEMA_FAILURE_ORIGIN_REQUIRED') { throw $error; } }
echo "Application failure snapshot contract: first failed identity retained; 9 invalid histories/origins rejected; native capture NOT_RUN.\n";
