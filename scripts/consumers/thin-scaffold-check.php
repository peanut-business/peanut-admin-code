<?php

declare(strict_types=1);

// Explicit consumer acceptance. A clean Code checkout is NOT an installed application.
// The CI Create app job first creates the app and installs its exact Composer lock.
// No database or alternate fixture source is used.
if (count($argv) !== 2 || !is_string($argv[1]) || !str_starts_with($argv[1], '/')) {
    throw new RuntimeException('Usage: php scripts/consumers/thin-scaffold-check.php /absolute/staged-application');
}
$root = realpath($argv[1]);
if ($root === false || !is_file($root . '/.peanut/application-manifest.json')
    || !is_file($root . '/server/vendor/autoload.php')) {
    throw new RuntimeException('Staged consumer and its own locked Composer installation are required');
}
$assertions = 0;
$expect = static function (bool $value, string $message) use (&$assertions): void {
    ++$assertions;
    if (!$value) { throw new RuntimeException($message); }
};
$json = static fn(string $path): array => json_decode((string) file_get_contents($root . '/' . $path), true, 512, JSON_THROW_ON_ERROR);
$manifest = $json('.peanut/application-manifest.json');
$edition = $manifest['application']['edition'];
$expect(in_array($edition, ['standalone', 'multi-tenant'], true), 'Supported consumer Edition required');
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
$expect(!file_exists($root . '/server/.env'), 'Use a new consumer with no configured environment');
$public = $root . '/server/public';
$process = proc_open(
    [PHP_BINARY, '-S', '127.0.0.1:0', '-t', $public, $public . '/index.php'],
    [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['pipe', 'w']],
    $pipes,
    $root . '/server',
    ['PHP_APP_ENV' => 'development', 'PHP_DEPLOYMENT_MODE' => $edition],
);
if (!is_resource($process)) { throw new RuntimeException('Cannot start the local HTTP consumer'); }
try {
    stream_set_timeout($pipes[2], 5);
    $startup = fgets($pipes[2]);
    $expect(is_string($startup) && preg_match('~http://127\.0\.0\.1:([1-9][0-9]*)\) started~', $startup, $match) === 1,
        'The local HTTP consumer did not bind');
    foreach (['/installapi/status' => 'GET', '/adminapi/user/info' => 'POST'] as $route => $method) {
        $http = stream_context_create(['http' => [
            'method' => $method, 'ignore_errors' => true, 'follow_location' => 0, 'timeout' => 5,
        ]]);
        $body = file_get_contents('http://127.0.0.1:' . $match[1] . $route, false, $http);
        $expect(is_string($body) && preg_match('~^HTTP/1\.[01] 503\b~', $http_response_header[0] ?? '') === 1,
            "Unexpected HTTP status for {$route}");
        $response = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        $expect(($response['data']['error_code'] ?? null) === 'INSTALL_MODULE_SOURCE_MISSING', "Missing-source admission failed for {$route}");
        $expect(($response['data']['state'] ?? null) === 'uninstalled', "Installation state was lost for {$route}");
        $expect(in_array($response['data']['module'] ?? null, $composition['mandatory'], true), "Missing required Module was not identified for {$route}");
    }
} finally {
    proc_terminate($process);
    fclose($pipes[2]);
    proc_close($process);
}
$expect(!file_exists($root . '/server/private/installation/installed.json'), 'Admission wrote installation identity');
echo "THIN-SCAFFOLD-001 passed: {$assertions} assertions, installation and admin HTTP admission; DB NOT_RUN\n";
