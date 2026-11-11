<?php

declare(strict_types=1);

// Explicit consumer acceptance. A clean Code checkout is NOT an installed application.
// The CI Create app job first creates the MT app and installs its exact Composer lock.
// No database or alternate fixture source is used.
if (count($argv) !== 2 || !is_string($argv[1]) || !str_starts_with($argv[1], '/')) {
    throw new RuntimeException('Usage: php scripts/consumers/thin-scaffold-check.php /absolute/staged-mt-application');
}
$root = realpath($argv[1]);
if ($root === false || !is_file($root . '/.peanut/application-manifest.json')
    || !is_file($root . '/server/vendor/autoload.php')) {
    throw new RuntimeException('Staged MT consumer and its own locked Composer installation are required');
}
$assertions = 0;
$expect = static function (bool $value, string $message) use (&$assertions): void {
    ++$assertions;
    if (!$value) { throw new RuntimeException($message); }
};
$json = static fn(string $path): array => json_decode((string) file_get_contents($root . '/' . $path), true, 512, JSON_THROW_ON_ERROR);
$manifest = $json('.peanut/application-manifest.json');
$expect($manifest['application']['edition'] === 'multi-tenant', 'MT consumer required');
$expect((glob($root . '/server/app/modules/*/*/module.json') ?: []) === [], 'Template carried backend author source');
foreach (['web/src/modules', 'platform/src/modules', 'pc/modules', 'uniapp/src/modules'] as $directory) {
    $expect((glob($root . '/' . $directory . '/peanut-*/contribution.ts') ?: []) === [], 'Template carried frontend author source: ' . $directory);
}
$lock = $json('modules.lock');
$expect($lock === ['schema_version' => 1, 'modules' => []], 'Fresh host inherited an author Module lock');
$composer = $json('server/composer.json');
$expect(!isset($composer['repositories']), 'Fresh host inherited Module path repositories');
$expect(array_keys($composer['autoload']['psr-4']) === ['app\\'], 'Fresh host inherited Module autoload mappings');
$locked = $json('server/composer.lock');
foreach ($locked['packages'] as $package) {
    $expect(($package['dist']['type'] ?? null) !== 'path', 'Composer lock contains author path package');
    $expect(!str_starts_with($package['name'], 'peanut-business/peanut-'), 'Composer lock contains official author package');
}
$expect($locked['packages-dev'] === [], 'Bootstrap lock contains author/test development dependencies');
foreach (['peanut-admin/core', 'composer/semver', 'opis/json-schema', 'topthink/think-migration'] as $package) {
    $expect(in_array($package, array_column($locked['packages'], 'name'), true), 'Missing native bootstrap dependency: ' . $package);
}
$composition = $json('server/config/module_composition.json');
$expect($composition['mandatory'] !== [] && $composition['default'] === [] && $composition['optional'] === [], 'Composition roots were discarded');
foreach ($manifest['files'] as $file) {
    $expect(preg_match('#^(?:server/app/modules/peanut/|(?:web|platform|uniapp)/src/modules/peanut-|pc/modules/peanut-)#', $file['path']) !== 1,
        'Author source is scaffold-managed: ' . $file['path']);
}
$catalog = $json('server/generated/api-catalog.json');
$expect(($catalog['summary']['route_count'] ?? 0) > 0, 'Host API projection is empty');
$pipes = [];
$process = proc_open([PHP_BINARY, $root . '/server/public/index.php'], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $root . '/server');
if (!is_resource($process)) { throw new RuntimeException('Cannot start native missing-source admission'); }
$stdout = stream_get_contents($pipes[1]);
$stderr = stream_get_contents($pipes[2]);
fclose($pipes[1]); fclose($pipes[2]);
$status = proc_close($process);
$response = json_decode($stdout, true, 512, JSON_THROW_ON_ERROR);
$expect($status === 0 && $stderr === '', 'Missing-source admission caused a bootstrap error: ' . $stderr);
$expect(($response['data']['error_code'] ?? null) === 'INSTALL_MODULE_SOURCE_MISSING', 'Missing Module source was not blocked');
$expect(($response['data']['state'] ?? null) === 'uninstalled', 'Missing-source admission lost installation state');
$expect(!file_exists($root . '/server/private/installation/installed.json'), 'Admission wrote installation identity');
echo "THIN-SCAFFOLD-001 passed: {$assertions} assertions; DB/real HTTP NOT_RUN\n";
