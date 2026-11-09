<?php

declare(strict_types=1);

use app\command\ModuleReconcile;
use app\platform\exception\module\ModuleLifecycleException;
use app\platform\value\module\ModuleDescriptor;

require dirname(__DIR__, 2) . '/vendor/autoload.php';
require_once dirname(__DIR__) . '/Support/ThinkPhpTestConnection.php';

$pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$pdo->exec('CREATE TABLE pa_module_installation (module_key TEXT PRIMARY KEY, status TEXT NOT NULL, last_error_code TEXT NULL, activated_at TEXT NULL)');
$app = new think\App(dirname(__DIR__, 2));
ThinkPhpTestConnection::fromPdo($pdo);
$selection = new ReflectionMethod(ModuleReconcile::class, 'releaseModuleSelection');
$command = new ModuleReconcile();
$assertions = 0;
$cases = 0;
$empty = ['reconcile' => [], 'preserved_disabled' => [], 'preserved_uninstalled' => []];
$run = static function (array $keys, array $rows, ?array $expected) use ($pdo, $selection, $command, &$cases, &$assertions): void {
    ++$cases;
    $pdo->exec('DELETE FROM pa_module_installation');
    $insert = $pdo->prepare('INSERT INTO pa_module_installation (module_key,status,last_error_code) VALUES (?,?,?)');
    foreach ($rows as $row) { $insert->execute($row); }
    $before = $pdo->query('SELECT * FROM pa_module_installation ORDER BY module_key')->fetchAll(PDO::FETCH_ASSOC);
    $locked = [];
    foreach ($keys as $key) {
        $locked[$key] = new ModuleDescriptor($key, '1.0.0', [], '', '', [], [], [], [$key => '/not-read']);
    }
    try {
        $actual = $selection->invoke($command, $locked);
        ++$assertions;
        if ($expected === null || $actual !== $expected) { throw new RuntimeException('Incorrect Module release selection'); }
    } catch (ModuleLifecycleException $exception) {
        ++$assertions;
        if ($expected !== null || $exception->errorCode !== 'PLUGIN_STATE_INVALID') { throw $exception; }
    }
    ++$assertions;
    if ($before !== $pdo->query('SELECT * FROM pa_module_installation ORDER BY module_key')->fetchAll(PDO::FETCH_ASSOC)) {
        throw new RuntimeException('Read-only selection changed installation history');
    }
};
$run([], [], $empty);
$run(['acme.new'], [], $empty);
$run(['acme.active', 'acme.new'], [['acme.active','active',null]], array_replace($empty, ['reconcile' => ['acme.active']]));
$run(['acme.disabled','acme.new'], [['acme.disabled','maintenance',null]], array_replace($empty, ['preserved_disabled' => ['acme.disabled']]));
$run(['acme.retired'], [['acme.retired','uninstalled',null]], array_replace($empty, ['preserved_uninstalled' => ['acme.retired']]));
$run([], [['acme.retired','uninstalled',null]], array_replace($empty, ['preserved_uninstalled' => ['acme.retired']]));
$run([], [['acme.live','active',null]], null);
foreach (['failed','installing','upgrading'] as $status) { $run(['acme.live'], [['acme.live',$status,'ORIGINAL_FAILURE']], null); }
$run(['acme.live'], [['acme.live','maintenance','MODULE_RETIRE_IN_PROGRESS']], null);
$run(['acme.live'], [['acme.live','active','ORIGINAL_FAILURE']], null);
echo "MODULE-RELEASE-SELECTION-001 passed cases={$cases} assertions={$assertions}; ThinkPHP/SQLite, installation-not-executed\n";
