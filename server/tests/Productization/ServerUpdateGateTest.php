<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/docker/scripts/update-plan.php';

$root = dirname(__DIR__, 3) . '/.local/tmp/phase1-continuation-20260930/php-gate-' . bin2hex(random_bytes(5));
mkdir($root, 0700, true);
$server = $root . '/server';
$workspace = $root . '/workspace';
mkdir($server . '/private/installation', 0700, true);
mkdir($workspace, 0700, true);
$key = str_repeat('a', 64);
file_put_contents($server . '/private/installation/update-verification.key', $key . "\n");
chmod($server . '/private/installation/update-verification.key', 0600);
$assertions = 0;

function gateAssert(bool $condition): void
{
    global $assertions;
    ++$assertions;
    if (!$condition) {
        throw new RuntimeException('server update gate assertion failed: ' . $assertions);
    }
}

function gateWrite(string $path, string $bytes, string $key): void
{
    file_put_contents($path, $bytes);
    chmod($path, 0600);
    file_put_contents($path . '.hmac', hash_hmac('sha256', $bytes, $key) . "\n");
    chmod($path . '.hmac', 0600);
}

function gateReject(ReflectionMethod $method, array $arguments, string $message): void
{
    try {
        $method->invoke(null, ...$arguments);
        throw new RuntimeException('unsafe result was accepted');
    } catch (RuntimeException $error) {
        gateAssert(str_contains($error->getMessage(), $message));
    }
}

try {
    $signed = new ReflectionMethod(PeanutServerUpdatePlan::class, 'assertSignedRecord');
    $backupPath = $workspace . '/backup.json';
    gateWrite($backupPath, '{"update_id":"unit"}', $key);
    $signed->invoke(null, $server, $backupPath, 'backup');
    gateAssert(true);
    file_put_contents($backupPath, '{"update_id":"other"}');
    gateReject($signed, [$server, $backupPath, 'backup'], 'signature');
    gateWrite($backupPath, '{"update_id":"unit"}', $key);
    chmod($backupPath, 0644);
    gateReject($signed, [$server, $backupPath, 'backup'], 'signature');
    chmod($backupPath, 0600);
    link($backupPath, $workspace . '/backup-hardlink.json');
    gateReject($signed, [$server, $backupPath, 'backup'], 'hard link');
    unlink($workspace . '/backup-hardlink.json');

    $updateId = 'server_update_20260930000000_aaaaaaaaaaaa';
    file_put_contents($workspace . '/plan.json', json_encode(['update_id' => $updateId], JSON_THROW_ON_ERROR));
    chmod($workspace . '/plan.json', 0600);
    $plan = ['update_id' => $updateId];
    $migrationPath = $workspace . '/migration.json';
    $migration = ['protocol' => 'peanut.server-update-migration.v1', 'status' => 'completed',
        'update_id' => $updateId, 'plan_sha256' => hash_file('sha256', $workspace . '/plan.json'),
        'backup_sha256' => hash_file('sha256', $backupPath)];
    gateWrite($migrationPath, json_encode($migration, JSON_THROW_ON_ERROR), $key);
    $complete = new ReflectionMethod(PeanutServerUpdatePlan::class, 'assertMigrationComplete');
    $complete->invoke(null, $server, $workspace, $plan);
    gateAssert(true);
    $migration['status'] = 'started';
    gateWrite($migrationPath, json_encode($migration, JSON_THROW_ON_ERROR), $key);
    gateReject($complete, [$server, $workspace, $plan], 'not completed');
    $migration['status'] = 'completed';
    $migration['backup_sha256'] = str_repeat('b', 64);
    gateWrite($migrationPath, json_encode($migration, JSON_THROW_ON_ERROR), $key);
    gateReject($complete, [$server, $workspace, $plan], 'not completed');
    echo 'SERVER-UPDATE-GATE passed assertions=' . $assertions . PHP_EOL;
} finally {
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($iterator as $entry) {
        $entry->isDir() && !$entry->isLink() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
    }
    rmdir($root);
}
