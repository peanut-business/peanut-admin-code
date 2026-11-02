<?php

declare(strict_types=1);

use app\command\PluginReconcile;
use app\platform\exception\plugin\PluginLifecycleException;
use app\platform\value\plugin\PluginDescriptor;

require dirname(__DIR__, 2) . '/vendor/autoload.php';
require_once dirname(__DIR__) . '/Support/ThinkPhpTestConnection.php';

// The actual ThinkPHP selection query runs against an isolated in-memory database.
// This is not a MySQL installation/migration or external APP acceptance test.
$pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$pdo->exec('CREATE TABLE pa_plugin_installation (plugin_key TEXT PRIMARY KEY, status TEXT NOT NULL)');
$pdo->exec('CREATE TABLE pa_plugin_module (plugin_key TEXT NOT NULL, module_key TEXT NOT NULL)');
$pdo->exec('CREATE TABLE pa_module_installation (module_key TEXT PRIMARY KEY, status TEXT NOT NULL, last_error_code TEXT NULL)');
$app = new think\App(dirname(__DIR__, 2));
ThinkPhpTestConnection::fromPdo($pdo);
$selection = new ReflectionMethod(PluginReconcile::class, 'releasePluginSelection');
$command = new PluginReconcile();
$assertions = 0;
$cases = 0;

function selectionAssert(bool $condition, string $reason): void
{
    global $assertions;
    ++$assertions;
    if (!$condition) { throw new RuntimeException($reason); }
}

function selectionSnapshot(PDO $pdo): array
{
    $rows = [];
    foreach (['pa_plugin_installation', 'pa_plugin_module', 'pa_module_installation'] as $table) {
        $rows[$table] = $pdo->query('SELECT * FROM ' . $table . ' ORDER BY 1,2')->fetchAll(PDO::FETCH_ASSOC);
    }
    return $rows;
}

/** @param list<string> $keys @param list<array> $installations @param list<array> $members @param list<array> $states */
function selectionCase(array $keys, array $installations, array $members, array $states, ?array $expected): void
{
    global $pdo, $selection, $command, $cases;
    ++$cases;
    foreach (['pa_plugin_installation', 'pa_plugin_module', 'pa_module_installation'] as $table) {
        $pdo->exec('DELETE FROM ' . $table);
    }
    foreach ([['pa_plugin_installation', $installations, 2], ['pa_plugin_module', $members, 2], ['pa_module_installation', $states, 3]] as [$table, $rows, $columns]) {
        $statement = $pdo->prepare('INSERT INTO ' . $table . ' VALUES (' . implode(',', array_fill(0, $columns, '?')) . ')');
        foreach ($rows as $row) { $statement->execute($row); }
    }
    $locked = [];
    foreach ($keys as $key) {
        $locked[$key] = new PluginDescriptor($key, '1.0.0', ['type' => 'bundled', 'reference' => 'selection-fixture', 'sha256' => str_repeat('a', 64)],
            str_repeat('b', 64), str_repeat('c', 64), [], [], [], [$key => '/unused-selection-fixture']);
    }
    $before = selectionSnapshot($pdo);
    try {
        $result = $selection->invoke($command, $locked);
        selectionAssert($expected !== null, 'An invalid installation was admitted');
        selectionAssert($result === $expected, 'The deployment selection changed unexpectedly: ' . json_encode($result));
    } catch (PluginLifecycleException $exception) {
        selectionAssert($expected === null, 'A valid deployment selection was rejected: ' . $exception->errorCode);
        selectionAssert($exception->errorCode === 'PLUGIN_STATE_INVALID', 'Unexpected rejection contract');
    }
    selectionAssert(selectionSnapshot($pdo) === $before, 'A read-only selection changed installation or Module state');
}

$empty = ['reconcile' => [], 'preserved_disabled' => [], 'preserved_uninstalled' => []];
selectionCase([], [], [], [], $empty);
selectionCase(['acme.new-business'], [], [], [], array_replace($empty, ['reconcile' => ['acme.new-business']]));
selectionCase(['peanut.chart', 'acme.new-business'], [['peanut.chart', 'active']], [['peanut.chart', 'peanut.chart']],
    [['peanut.chart', 'active', null]], array_replace($empty, ['reconcile' => ['peanut.chart', 'acme.new-business']]));
selectionCase(['acme.disabled', 'acme.new-business'], [['acme.disabled', 'active']], [['acme.disabled', 'acme.disabled']],
    [['acme.disabled', 'maintenance', null]], array_replace($empty, ['reconcile' => ['acme.new-business'], 'preserved_disabled' => ['acme.disabled']]));
selectionCase(['acme.retired'], [['acme.retired', 'uninstalled']], [['acme.retired', 'acme.retired']],
    [['acme.retired', 'maintenance', null]], array_replace($empty, ['preserved_uninstalled' => ['acme.retired']]));
selectionCase([], [['acme.retired', 'uninstalled']], [['acme.retired', 'acme.retired']],
    [['acme.retired', 'maintenance', null]], array_replace($empty, ['preserved_uninstalled' => ['acme.retired']]));
selectionCase([], [['acme.live', 'active']], [['acme.live', 'acme.live']], [['acme.live', 'active', null]], null);
selectionCase(['acme.live'], [['acme.live', 'failed']], [['acme.live', 'acme.live']], [['acme.live', 'failed', 'TEST_FAILURE']], null);
selectionCase(['acme.live'], [['acme.live', 'active']], [], [['acme.live', 'active', null]], null);
selectionCase(['acme.live'], [['acme.live', 'active']], [['acme.live', 'acme.other']], [['acme.other', 'active', null]], null);
selectionCase(['acme.live'], [['acme.live', 'active']], [['acme.live', 'acme.live'], ['acme.live', 'acme.other']],
    [['acme.live', 'active', null], ['acme.other', 'active', null]], null);
selectionCase(['acme.live'], [['acme.live', 'active']], [['acme.live', 'acme.live']], [['acme.live', 'maintenance', 'TEST_FAILURE']], null);
selectionCase(['acme.live'], [['acme.live', 'active']], [['acme.live', 'acme.live']], [], null);
echo "MODULE-RELEASE-SELECTION-001 passed cases={$cases} assertions={$assertions}; read-only native ThinkPHP/SQLite; installation-not-executed\n";
