<?php

declare(strict_types=1);

use app\command\ModuleReconcile;
use app\platform\exception\module\ModuleLifecycleException;

require dirname(__DIR__, 2) . '/vendor/autoload.php';

// The production preflight must run before it can obtain the lifecycle writer.
$source = (string) file_get_contents(dirname(__DIR__, 2) . '/app/command/ModuleReconcile.php');
$guardPosition = strpos($source, 'self::assertSourceApiCatalogReady(dirname(__DIR__, 2));');
$writerPosition = strpos($source, 'return $operation($this->moduleLifecycle());');
if ($guardPosition === false || $writerPosition === false || $guardPosition >= $writerPosition) {
    throw new RuntimeException('MODULE_RECONCILE_PREFLIGHT_NOT_BEFORE_LIFECYCLE');
}

$temp = realpath(sys_get_temp_dir());
if ($temp === false) throw new RuntimeException('MODULE_RECONCILE_TEST_TEMP_INVALID');
$root = $temp . '/peanut-module-reconcile-api-catalog-' . bin2hex(random_bytes(8));
$server = $root . '/server';
if (!mkdir($server . '/generated', 0700, true)) {
    throw new RuntimeException('MODULE_RECONCILE_TEST_SETUP_FAILED');
}
$remove = static function (string $path) use (&$remove): void {
    if (is_dir($path) && !is_link($path)) {
        foreach (new FilesystemIterator($path) as $entry) $remove($entry->getPathname());
        if (!rmdir($path)) throw new RuntimeException('MODULE_RECONCILE_TEST_CLEANUP_FAILED');
    } elseif (is_file($path) || is_link($path)) {
        if (!unlink($path)) throw new RuntimeException('MODULE_RECONCILE_TEST_CLEANUP_FAILED');
    }
};
$assertions = 0;
$check = new ReflectionMethod(ModuleReconcile::class, 'assertSourceApiCatalogReady');
$accept = static function (bool $ok, string $case) use (&$assertions): void {
    ++$assertions;
    if (!$ok) throw new RuntimeException('MODULE_RECONCILE_API_CATALOG_TEST_' . $case);
};
$reject = static function (string $case) use ($check, $server, $accept): void {
    try {
        $check->invoke(null, $server);
    } catch (ModuleLifecycleException $error) {
        $accept($error->errorCode === 'MODULE_API_CATALOG_STALE', $case);
        return;
    }
    throw new RuntimeException('MODULE_RECONCILE_CATALOG_EXPECTED_REJECTION_' . $case);
};
try {
    $lock = $root . '/modules.lock';
    file_put_contents($lock, '{"modules":[{"key":"peanut.file","version":"1.0.0"}]}' . "\n");
    $catalog = $server . '/generated/api-catalog.json';
    $document = [
        'schema_version' => 1,
        'inputs' => [['path' => 'modules.lock', 'sha256' => hash_file('sha256', $lock)]],
        'endpoints' => [],
    ];
    file_put_contents($catalog, json_encode($document, JSON_THROW_ON_ERROR) . "\n");
    $check->invoke(null, $server);
    $accept(true, 'fresh_current_lock_accepted');

    $catalogBefore = hash_file('sha256', $catalog);
    file_put_contents($lock, '{"modules":[{"key":"peanut.file","version":"1.0.1"}]}' . "\n");
    $reject('stale_modified_lock_denied_before_lifecycle');

    // A failed preflight does not change even the fixture's current lock/catalog.
    $accept(hash_file('sha256', $catalog) === $catalogBefore, 'stale_denial_did_not_mutate_catalog');
    $document['inputs'][0]['sha256'] = hash_file('sha256', $lock);
    file_put_contents($catalog, json_encode($document, JSON_THROW_ON_ERROR) . "\n");
    $check->invoke(null, $server);
    $accept(true, 'regenerated_catalog_accepted');

    file_put_contents($catalog, '{bad json');
    $reject('invalid_json_denied');
    unlink($catalog);
    $reject('missing_catalog_denied');
    $accept(is_file($lock), 'no_database_or_lock_changes');
    echo 'MODULE-RECONCILE-API-CATALOG-PREFLIGHT-001 passed; assertions=' . $assertions . "\n";
} finally {
    $remove($root);
}
