<?php

declare(strict_types=1);

use app\common\infrastructure\scaffold\DeterministicEditionArchive;

require_once dirname(__DIR__, 2) . '/docker/scripts/update-plan.php';
require_once dirname(__DIR__, 2) . '/app/common/infrastructure/scaffold/DeterministicEditionArchive.php';

function updatePlanExpect(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function updatePlanDelete(string $path): void
{
    if (is_dir($path) && !is_link($path)) {
        foreach (array_diff(scandir($path) ?: [], ['.', '..']) as $entry) {
            updatePlanDelete($path . '/' . $entry);
        }
        rmdir($path);
        return;
    }
    if (file_exists($path) || is_link($path)) {
        unlink($path);
    }
}

/** @param array<string,string> $files */
function updatePlanIdentity(string $slug, string $version, array $files): array
{
    ksort($files, SORT_STRING);
    $rows = [];
    foreach ($files as $path => $bytes) {
        $rows[] = ['path' => $path, 'sha256' => hash('sha256', $bytes), 'mode' => 0644];
    }
    return [
        'schema_version' => 1,
        'protocol' => 'peanut.server-release.v1',
        'application' => [
            'kind' => 'application',
            'manifest_sha256' => str_repeat('1', 64),
            'commit' => str_repeat($version === '1.0.0' ? 'a' : 'b', 40),
            'tree' => str_repeat($version === '1.0.0' ? 'c' : 'd', 40),
            'slug' => $slug,
            'edition' => 'standalone',
            'version' => $version,
            'profile' => 'full',
            'package_identity' => $slug . '/application',
            'name' => 'Update Fixture',
            'source_files_sha256' => str_repeat('2', 64),
        ],
        'files' => $rows,
        'files_sha256' => hash('sha256', json_encode($rows, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)),
    ];
}

/** @param array<string,string> $files */
function updatePlanWriteServer(string $root, string $version, array $files): void
{
    foreach ($files as $path => $bytes) {
        $relative = substr($path, strlen('server/'));
        $target = $root . '/server/' . $relative;
        if (!is_dir(dirname($target)) && !mkdir(dirname($target), 0700, true) && !is_dir(dirname($target))) {
            throw new RuntimeException('cannot create fixture directory');
        }
        file_put_contents($target, $bytes);
        chmod($target, 0644);
    }
    $identity = updatePlanIdentity('fixture-app', $version, $files);
    $identityPath = $root . '/server/.peanut/release-identity.json';
    if (!is_dir(dirname($identityPath)) && !mkdir(dirname($identityPath), 0700, true) && !is_dir(dirname($identityPath))) {
        throw new RuntimeException('cannot create fixture identity directory');
    }
    file_put_contents($identityPath, json_encode($identity, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n");
    chmod($identityPath, 0644);
}

/** @param array<string,string> $files */
function updatePlanArchive(string $root, string $version, array $files, ?callable $mutateIdentity = null): string
{
    $stage = $root . '/stage-' . bin2hex(random_bytes(3));
    updatePlanWriteServer($stage, $version, $files);
    $identityPath = $stage . '/server/.peanut/release-identity.json';
    if ($mutateIdentity !== null) {
        $identity = json_decode((string) file_get_contents($identityPath), true, 512, JSON_THROW_ON_ERROR);
        $identity = $mutateIdentity($identity);
        file_put_contents($identityPath, json_encode($identity, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n");
    }
    $archive = $root . '/target-' . bin2hex(random_bytes(4)) . '.tar.gz';
    (new DeterministicEditionArchive())->write($stage, 'fixture-release', $archive);
    updatePlanDelete($stage);
    return $archive;
}

function updatePlanWorkspace(string $root): string
{
    $workspace = $root . '/workspace-' . bin2hex(random_bytes(3));
    mkdir($workspace, 0700);
    return $workspace;
}

$projectRoot = dirname(__DIR__, 3);
$tmpRoot = $projectRoot . '/.local/tmp';
if (!is_dir($tmpRoot) && !mkdir($tmpRoot, 0700, true) && !is_dir($tmpRoot)) {
    throw new RuntimeException('cannot create checkout-local temporary root');
}
$root = $tmpRoot . '/server-update-plan-' . bin2hex(random_bytes(6));
mkdir($root, 0700);

try {
    $currentFiles = [
        'server/app/a.txt' => "old\n",
        'server/app/remove.txt' => "remove\n",
        'server/composer.lock' => "lock-v1\n",
    ];
    $targetFiles = [
        'server/app/a.txt' => "new\n",
        'server/app/new.txt' => "added\n",
        'server/composer.lock' => "lock-v2\n",
    ];
    $instance = $root . '/instance';
    updatePlanWriteServer($instance, '1.0.0', $currentFiles);
    $archive = updatePlanArchive($root, '1.1.0', $targetFiles);
    $workspace = updatePlanWorkspace($root);
    $plan = PeanutServerUpdatePlan::build($instance . '/server', $archive, (string) hash_file('sha256', $archive), $workspace);
    $ops = array_column($plan['operations'], 'operation', 'path');
    updatePlanExpect(($ops['server/app/a.txt'] ?? null) === 'replace', 'changed program file must replace');
    updatePlanExpect(($ops['server/app/new.txt'] ?? null) === 'add', 'new program file must add');
    updatePlanExpect(($ops['server/app/remove.txt'] ?? null) === 'delete', 'removed program file must delete');
    updatePlanExpect(($ops['server/.peanut/release-identity.json'] ?? null) === 'replace', 'release identity must advance with program files');
    updatePlanExpect(($plan['requirements']['composer_dependencies_changed'] ?? false) === true, 'composer lock change must require dependency preparation');
    updatePlanExpect(is_file($workspace . '/plan.json'), 'plan must be durably written before any apply phase');

    $collisionInstance = $root . '/collision-instance';
    updatePlanWriteServer($collisionInstance, '1.0.0', $currentFiles);
    mkdir($collisionInstance . '/server/app/new.txt', 0700, true);
    $collisionWorkspace = updatePlanWorkspace($root);
    try {
        PeanutServerUpdatePlan::build($collisionInstance . '/server', $archive, (string) hash_file('sha256', $archive), $collisionWorkspace);
        throw new RuntimeException('unknown target collision was accepted');
    } catch (RuntimeException $exception) {
        updatePlanExpect(str_contains($exception->getMessage(), 'unknown existing path'), 'unknown collision must fail closed');
    }

    $protectedFiles = $targetFiles;
    $protectedFiles['server/docker/secrets/root-password'] = "secret\n";
    $protectedArchive = updatePlanArchive($root, '1.2.0', $protectedFiles);
    try {
        PeanutServerUpdatePlan::build($instance . '/server', $protectedArchive, (string) hash_file('sha256', $protectedArchive), updatePlanWorkspace($root));
        throw new RuntimeException('protected target was accepted');
    } catch (RuntimeException $exception) {
        updatePlanExpect(str_contains($exception->getMessage(), 'protected instance data'), 'protected target must fail closed');
    }

    $malformedArchive = updatePlanArchive(
        $root,
        '1.3.0',
        $targetFiles,
        static function (array $identity): array {
            $identity['files'][0]['sha256'] = str_repeat('0', 64);
            $identity['files_sha256'] = hash(
                'sha256',
                json_encode($identity['files'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            );
            return $identity;
        },
    );
    try {
        PeanutServerUpdatePlan::build($instance . '/server', $malformedArchive, (string) hash_file('sha256', $malformedArchive), updatePlanWorkspace($root));
        throw new RuntimeException('archive whose files differ from identity was accepted');
    } catch (RuntimeException $exception) {
        updatePlanExpect(str_contains($exception->getMessage(), 'differ from release identity'), 'tampered file list must fail closed');
    }

    $wrongDigestWorkspace = updatePlanWorkspace($root);
    try {
        PeanutServerUpdatePlan::build($instance . '/server', $archive, str_repeat('0', 64), $wrongDigestWorkspace);
        throw new RuntimeException('wrong trusted digest was accepted');
    } catch (RuntimeException $exception) {
        updatePlanExpect(str_contains($exception->getMessage(), 'differs from trusted'), 'external trusted digest mismatch must fail closed');
    }

    echo "SERVER-UPDATE-PLAN passed\n";
} finally {
    updatePlanDelete($root);
}
