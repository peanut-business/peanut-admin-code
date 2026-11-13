<?php

declare(strict_types=1);

// Use an explicitly selected, already prepared complete consumer. No database calls or builds.
$root = $argv[1] ?? (getenv('PEANUT_TEST_APPLICATION_ROOT') ?: '');
if (!is_string($root) || !str_starts_with($root, '/')) {
    throw new RuntimeException('INITIAL_INSTALL_PREPARED_CONSUMER_REQUIRED');
}
$root = realpath($root);
if ($root === false || !is_file($root . '/server/vendor/autoload.php')) {
    throw new RuntimeException('INITIAL_INSTALL_PREPARED_CONSUMER_INVALID');
}
$server = $root . '/server';
require_once $server . '/vendor/autoload.php';
require_once $server . '/vendor/topthink/framework/src/helper.php';
// Exercise the current Code candidate, not a public consumer's earlier Host implementation.
require_once dirname(__DIR__, 2) . '/app/common/module/Dependencies.php';

$lock = json_decode((string) file_get_contents($root . '/modules.lock'), true, 512, JSON_THROW_ON_ERROR);
$keys = array_column($lock['modules'], 'key');
if ($keys === [] || count($keys) !== count(array_unique($keys))) {
    throw new RuntimeException('INITIAL_INSTALL_SELECTED_MODULES_INVALID');
}
$files = ['server/composer.json', 'server/composer.lock', 'web/pnpm-lock.yaml', 'platform/package-lock.json'];
$before = [];
foreach ($files as $file) {
    $path = $root . '/' . $file;
    $before[$file] = is_file($path) ? hash_file('sha256', $path) : null;
}
$app = new \think\App($server);
\think\facade\Config::set(['mode' => 'multi-tenant'], 'deployment');
$resolver = new \app\platform\infrastructure\module\ModuleLockResolver($server, '../modules.lock');
$result = (new \app\common\module\Dependencies($server))->verifyPreparedSelection($resolver, $keys, []);
if (($result['status'] ?? null) !== 'dependencies-already-installed' || ($result['operations'] ?? null) !== []
    || !isset($result['frontend_adoption']['web'], $result['frontend_adoption']['platform'])) {
    throw new RuntimeException('INITIAL_INSTALL_SELECTED_GRAPH_NOT_PREPARED');
}
foreach ($before as $file => $digest) {
    $path = $root . '/' . $file;
    if ($digest !== (is_file($path) ? hash_file('sha256', $path) : null)) {
        throw new RuntimeException('INITIAL_INSTALL_DEPENDENCY_VERIFY_MUTATED_INPUT');
    }
}
echo 'INITIAL-INSTALL-PREPARED-001 passed: selected=' . count($keys)
    . ' dependency_tool_invocations=0 manifest_changes=0' . PHP_EOL;
