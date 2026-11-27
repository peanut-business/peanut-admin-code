<?php

declare(strict_types=1);

use app\platform\infrastructure\module\ModuleArtifactWriter;
use app\platform\infrastructure\module\ModuleLockResolver;

/**
 * Source-only native bridge regression. No application database, installer,
 * real module, production environment, or existing modules.lock is modified.
 */
$sourceServer = dirname(__DIR__, 2);
$toolingRoot = getenv('PEANUT_TEST_TOOLING_ROOT');
$toolingServer = is_string($toolingRoot) && $toolingRoot !== ''
    ? rtrim($toolingRoot, '/') . '/server' : $sourceServer;
require $toolingServer . '/vendor/autoload.php';

function lockBridgeCheck(bool $result, string $message): void
{
    if (!$result) {
        throw new RuntimeException($message);
    }
}

function lockBridgeRemove(string $path): void
{
    if (is_link($path) || is_file($path)) {
        unlink($path);
        return;
    }
    if (is_dir($path)) {
        foreach (new FilesystemIterator($path, FilesystemIterator::SKIP_DOTS) as $child) {
            lockBridgeRemove($child->getPathname());
        }
        rmdir($path);
    }
}

$sandbox = sys_get_temp_dir() . '/peanut-module-lock-bridge-' . bin2hex(random_bytes(8));
mkdir($sandbox . '/server', 0700, true);
$server = $sandbox . '/server';
try {
    foreach ([
        'module', 'app/command/ModuleLock.php',
        'config/peanut.php', 'config/deployment.php',
        'config/module_packages.php', 'config/modules.php',
        'config/module_composition.json', 'composer.json', 'composer.lock',
    ] as $relative) {
        $target = $server . '/' . $relative;
        if (!is_dir(dirname($target))) mkdir(dirname($target), 0700, true);
        lockBridgeCheck(copy($sourceServer . '/' . $relative, $target), 'Cannot stage ' . $relative);
    }
    mkdir($server . '/resources/schemas', 0700, true);
    foreach (glob($toolingServer . '/resources/schemas/*.json') ?: [] as $schema) {
        lockBridgeCheck(copy($schema, $server . '/resources/schemas/' . basename($schema)), 'Cannot stage schema.');
    }
    // The native ModuleDefinitionRegistryFactory requires the canonical
    // registered Composer vendor root. This is a disposable test-only link.
    lockBridgeCheck(symlink($toolingServer . '/vendor', $server . '/vendor'), 'Cannot stage test Composer loader.');
    $bridge = (string) file_get_contents($server . '/module');
    $marker = "require __DIR__ . '/vendor/autoload.php';";
    lockBridgeCheck(substr_count($bridge, $marker) === 1, 'Native bridge entry moved.');
    // Fixture class must resolve dirname(__DIR__, 2) to the sandbox server,
    // never the tooling application whose autoload the test reuses.
    file_put_contents($server . '/module', str_replace($marker,
        $marker . "\nrequire_once __DIR__ . '/app/command/ModuleLock.php';", $bridge));

    $stale = json_encode(['schema_version' => 1, 'modules' => [['key' => 'synthetic.stale-source']]],
        JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    $write = static function (string $env) use ($server, $sandbox, $stale): array {
        file_put_contents($sandbox . '/modules.lock', $stale);
        file_put_contents($server . '/.env', $env);
        chmod($server . '/.env', 0600);
        $process = proc_open([PHP_BINARY, $server . '/module'],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes, $sandbox, ['PATH' => (string) getenv('PATH')]);
        lockBridgeCheck(is_resource($process), 'Native source-only bridge failed to start.');
        fwrite($pipes[0], json_encode(['operation' => 'lock', 'arguments' => ['--write']],
            JSON_THROW_ON_ERROR));
        fclose($pipes[0]);
        $result = stream_get_contents($pipes[1]);
        $error = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        return [proc_close($process), $result . $error];
    };
    $developer = "APP_ENV=development\nAPP_DEBUG=true\nDEPLOYMENT_MODE=multi-tenant\n";
    [$code, $output] = $write($developer);
    lockBridgeCheck($code === 0, 'Legitimate development maintenance failed: ' . $output);
    $receipt = json_decode(trim($output), true, 512, JSON_THROW_ON_ERROR);
    lockBridgeCheck($receipt['status'] === 'written' && $receipt['modules'] === 0,
        'Source maintenance did not return its canonical receipt.');
    (new ModuleArtifactWriter($server))->checkLock();
    lockBridgeCheck((new ModuleLockResolver($server, '../modules.lock'))->all() === [],
        'Written lock failed the original strict resolver.');
    $registry = glob($server . '/runtime/module-registry/**/*', GLOB_BRACE) ?: [];
    lockBridgeCheck($registry !== [] || count(glob($server . '/runtime/module-registry/*') ?: []) > 0,
        'Writing a canonical lock did not publish compiled Registry evidence.');

    foreach ([
        'debug-off' => "APP_ENV=development\nAPP_DEBUG=false\nDEPLOYMENT_MODE=multi-tenant\n",
        'production' => "APP_ENV=production\nAPP_DEBUG=true\nDEPLOYMENT_MODE=multi-tenant\n",
        'invalid-mode' => "APP_ENV=development\nAPP_DEBUG=true\nDEPLOYMENT_MODE=invalid\n",
    ] as $case => $environment) {
        [$exit, $output] = $write($environment);
        lockBridgeCheck($exit !== 0
            && str_contains($output, 'Plugin source authoring requires a development/debug CLI command.')
            && (string) file_get_contents($sandbox . '/modules.lock') === $stale,
            'Unauthorized source maintenance changed lock or bypassed its gate: ' . $case . ' / ' . $output);
    }
    echo "MODULE-LOCK-SOURCE-AUTHORING-001 passed; old lock canonicalized and compiled only in development/debug CLI; three denials preserved the old lock.\n";
} finally {
    lockBridgeRemove($sandbox);
}
