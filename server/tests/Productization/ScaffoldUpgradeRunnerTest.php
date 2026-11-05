<?php

declare(strict_types=1);

use app\common\infrastructure\scaffold\ScaffoldUpgradeRunner;

require dirname(__DIR__, 2) . '/vendor/autoload.php';
require_once dirname(__DIR__, 3) . '/scripts/scaffold-runtime/Semver.php';
require_once dirname(__DIR__, 3) . '/scripts/scaffold-runtime/ScaffoldUpgradeLedger.php';

function scaffoldExpect(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function scaffoldFails(callable $run, string $reason): void
{
    try {
        $run();
    } catch (RuntimeException $error) {
        scaffoldExpect($error->getMessage() === $reason, $error->getMessage());
        return;
    }
    throw new RuntimeException("Expected rejection: {$reason}");
}

function scaffoldWrite(string $path, string $bytes, int $mode = 0644): void
{
    if (!is_dir(dirname($path))) {
        mkdir(dirname($path), 0700, true);
    }
    file_put_contents($path, $bytes);
    chmod($path, $mode);
}

$temporary = sys_get_temp_dir() . '/peanut-scaffold-source-recovery-' . bin2hex(random_bytes(8));
mkdir($temporary, 0700);
$project = $temporary . '/application';
$releases = $temporary . '/templates';
$writeJson = static fn(string $path, array $data) => scaffoldWrite($path, json_encode($data, JSON_THROW_ON_ERROR));
$old = ['managed.txt' => "old\n", 'renamed-old.txt' => "rename source\n"];
$new = ['managed.txt' => "new\n", 'renamed-new.txt' => "renamed source\n"];
$exportProvider = 'server/app/common/infrastructure/export/OperationLogExportProvider.php';
$old[$exportProvider] = $new[$exportProvider] = "application export provider\n";
$manifest = static function (string $version, string $ref, array $files, array $renames = []) use ($releases, $writeJson): string {
    $rows = [];
    foreach ($files as $path => $bytes) {
        scaffoldWrite("{$releases}/{$version}/files/{$path}", $bytes);
        $rows[] = ['path' => $path, 'source' => 'files/' . $path, 'owner' => 'host', 'policy' => 'managed',
            'classification' => 'managed', 'mode' => 0644, 'transform' => 'tokens', 'template_sha256' => hash('sha256', $bytes)];
    }
    $path = "{$releases}/{$version}/scaffold-manifest.json";
    $writeJson($path, ['schema_version' => 2, 'protocol' => 'peanut.scaffold-release.v2',
        'release' => ['version' => $version, 'source_commit' => str_repeat($ref, 40), 'source_tree' => str_repeat($ref, 40),
            'inventory_sha256' => str_repeat($ref, 64), 'inventory_template_version' => $version,
            'tokens' => ['product_name' => 'PRODUCT_TOKEN', 'slug' => 'SLUG_TOKEN', 'package_identity' => 'PACKAGE_TOKEN']],
        'files' => $rows, 'renames' => $renames]);
    return $path;
};

try {
    $from = $manifest('1.0.0', 'a', $old);
    $to = $manifest('1.1.0', 'b', $new, [['from' => 'renamed-old.txt', 'to' => 'renamed-new.txt', 'owner' => 'host']]);
    $owned = [];
    foreach ($old as $path => $bytes) {
        $baseline = '.peanut/scaffold-baseline/1.0.0/files/' . $path;
        $mode = $path === 'renamed-old.txt' ? 0755 : 0644;
        scaffoldWrite($project . '/' . $path, $bytes, $mode);
        scaffoldWrite($project . '/' . $baseline, $bytes);
        $owned[] = ['path' => $path, 'source' => $path, 'owner' => 'host', 'classification' => 'managed',
            'mode' => $mode, 'baseline_path' => $baseline, 'baseline_sha256' => hash('sha256', $bytes)];
    }
    // The old template records the executable bit of the renamed source.
    $fromDocument = json_decode((string) file_get_contents($from), true, 512, JSON_THROW_ON_ERROR);
    $fromDocument['files'][1]['mode'] = 0755;
    $writeJson($from, $fromDocument);
    $appManifest = $project . '/.peanut/application-manifest.json';
    $writeJson($appManifest, ['schema_version' => 2, 'protocol' => 'peanut.application-scaffold.v2',
        'application' => ['name' => 'Acme', 'slug' => 'acme', 'package_identity' => 'acme/app', 'version' => '0.1.0'],
        'template' => ['version' => '1.0.0', 'source_commit' => str_repeat('a', 40), 'source_tree' => str_repeat('a', 40),
            'inventory_sha256' => str_repeat('a', 64)],
        'files' => $owned]);
    $beforeManifest = file_get_contents($appManifest);
    $writeJson($project . '/release-versions.json', ['schema_version' => 1, 'protocol' => 'peanut.release-versions.v1',
        'product_release' => '0.1.0', 'scaffold_template' => '1.0.0', 'generated_application_default' => '0.1.0',
        'core_php' => '1.0.0', 'core_web' => '1.0.0']);
    scaffoldWrite($project . '/business.txt', "instance owned\n");
    $runner = new ScaffoldUpgradeRunner();
    scaffoldWrite($project . '/managed.txt', "local edit\n");
    $blocked = $runner->preflight($project, $from, $to);
    scaffoldExpect($blocked['status'] === 'blocked', 'Concurrent local and upstream edits were accepted.');
    scaffoldFails(fn() => $runner->apply($project, $project . '/' . $blocked['plan_path']), 'SCAFFOLD_PLAN_BLOCKED');
    scaffoldExpect(file_get_contents($project . '/managed.txt') === "local edit\n"
        && !file_exists($project . '/renamed-new.txt'), 'Blocked plan wrote source files.');
    scaffoldWrite($project . '/managed.txt', $old['managed.txt']);

    $plan = $runner->preflight($project, $from, $to);
    scaffoldExpect($plan['status'] === 'ready', 'Source fixture plan is not ready.');
    $path = $project . '/' . $plan['plan_path'];
    $planBytes = (string) file_get_contents($path);
    $tampered = json_decode($planBytes, true, 512, JSON_THROW_ON_ERROR);
    $tampered['actions'][0]['mode'] = 0600;
    scaffoldWrite($path, json_encode($tampered, JSON_THROW_ON_ERROR), 0600);
    scaffoldFails(fn() => $runner->apply($project, $path), 'SCAFFOLD_PLAN_CHECKSUM_DRIFT');
    scaffoldWrite($path, $planBytes, 0600);
    $targetBytes = (string) file_get_contents($to);
    scaffoldWrite($to, $targetBytes . "\n");
    scaffoldFails(fn() => $runner->apply($project, $path), 'SCAFFOLD_MANIFEST_CHECKSUM_DRIFT');
    scaffoldWrite($to, $targetBytes);
    scaffoldWrite($project . '/managed.txt', "changed after plan\n");
    scaffoldFails(fn() => $runner->apply($project, $path), 'SCAFFOLD_PLAN_PROJECT_CHANGED');
    scaffoldWrite($project . '/managed.txt', $old['managed.txt']);
    scaffoldFails(fn() => (new ScaffoldUpgradeRunner(1))->apply($project, $path), 'SCAFFOLD_FAULT_INJECTED');
    scaffoldExpect(!file_exists($project . '/renamed-old.txt')
        && file_get_contents($project . '/renamed-new.txt') === $new['renamed-new.txt'], 'Fault did not interrupt a real rename.');
    scaffoldExpect($runner->recover($project, $path)['status'] === 'recovered', 'Source recovery failed.');
    clearstatcache();
    scaffoldExpect(file_get_contents($project . '/renamed-old.txt') === $old['renamed-old.txt']
        && (fileperms($project . '/renamed-old.txt') & 0777) === 0755
        && !file_exists($project . '/renamed-new.txt')
        && !file_exists($project . '/.peanut/scaffold-baseline/1.1.0/files/renamed-new.txt')
        && file_get_contents($appManifest) === $beforeManifest
        && file_get_contents($project . '/business.txt') === "instance owned\n", 'Recovery changed the pre-apply source tree.');
    scaffoldExpect($runner->verifyRecovery($project, $path)['read_only'] === true, 'Restored tree failed read-only verification.');
    scaffoldExpect($runner->recover($project, $path)['idempotent'] === true, 'Recovery replay did not remain idempotent.');
    $replaceTarget = $manifest('1.2.0', 'c', array_replace($old, ['managed.txt' => "replacement\n"]));
    $replace = $runner->preflight($project, $from, $replaceTarget);
    scaffoldExpect($replace['status'] === 'ready', 'Replacement plan was blocked.');
    $replacePath = $project . '/' . $replace['plan_path'];
    scaffoldFails(fn() => (new ScaffoldUpgradeRunner(1))->apply($project, $replacePath), 'SCAFFOLD_FAULT_INJECTED');
    scaffoldExpect(file_get_contents($project . '/managed.txt') === "replacement\n", 'Fault preceded the replacement.');
    scaffoldExpect($runner->recover($project, $replacePath)['status'] === 'recovered'
        && file_get_contents($project . '/managed.txt') === $old['managed.txt'], 'Interrupted replacement did not recover.');

    $replayTarget = $manifest('1.3.0', 'd', array_replace($old, ['managed.txt' => "replay target\n"]));
    $replay = $runner->preflight($project, $from, $replayTarget);
    $replayPath = $project . '/' . $replay['plan_path'];
    scaffoldExpect($runner->apply($project, $replayPath)['status'] === 'applied', 'Replay fixture did not apply.');
    $verified = $runner->verify($project, $replayPath);
    scaffoldExpect($verified['status'] === 'verified' && $verified['idempotent'] === false,
        'First verification did not transition the applied plan to verified.');
    $verifiedAgain = $runner->verify($project, $replayPath);
    scaffoldExpect($verifiedAgain['status'] === 'verified' && $verifiedAgain['idempotent'] === true,
        'Verification replay was not idempotent.');
    scaffoldWrite($project . '/managed.txt', "post-apply drift\n");
    scaffoldFails(fn() => $runner->verify($project, $replayPath), 'SCAFFOLD_VERIFY_MANAGED_MISMATCH: managed.txt');
    scaffoldFails(fn() => $runner->apply($project, $replayPath), 'SCAFFOLD_VERIFY_MANAGED_MISMATCH: managed.txt');
    scaffoldExpect(file_get_contents($project . '/managed.txt') === "post-apply drift\n", 'Replay rewrote drift.');
    $runner->recover($project, $replayPath);

    $removedProviderFiles = $old;
    unset($removedProviderFiles[$exportProvider]);
    $removedProviderTarget = $manifest('1.4.0', 'e', $removedProviderFiles);
    $ownership = $runner->preflight($project, $from, $removedProviderTarget);
    $providerAction = array_column($ownership['actions'], null, 'path')[$exportProvider];
    scaffoldExpect($ownership['status'] === 'blocked'
        && $providerAction['action'] === 'conflict'
        && $providerAction['reason'] === 'module_source_ownership_transition_required',
        'Removing the consumed export adapter did not require an ownership decision.');
    $ownershipPath = $project . '/' . $ownership['plan_path'];
    scaffoldFails(fn() => $runner->apply($project, $ownershipPath), 'SCAFFOLD_PLAN_BLOCKED');
    $preserved = $runner->resolve($project, $ownershipPath, $ownership['plan_sha256'], [$exportProvider], []);
    $preservedPath = $project . '/' . $preserved['plan_path'];
    scaffoldExpect($runner->apply($project, $preservedPath)['status'] === 'applied', 'Export adapter preservation did not apply.');
    $adoptedFiles = json_decode((string) file_get_contents($appManifest), true, 512, JSON_THROW_ON_ERROR)['files'];
    $adoptedProvider = array_column($adoptedFiles, null, 'path')[$exportProvider];
    scaffoldExpect(file_get_contents($project . '/' . $exportProvider) === $old[$exportProvider]
        && $adoptedProvider['classification'] === 'app-owned'
        && $adoptedProvider['owner'] === 'application'
        && !isset($adoptedProvider['baseline_path'])
        && !isset($adoptedProvider['baseline_sha256']),
        'Preservation did not retain adapter bytes with application ownership.');
    scaffoldExpect($runner->verify($project, $preservedPath)['status'] === 'verified', 'Preserved export adapter failed verification.');
    $runner->recover($project, $preservedPath);

    $outside = $temporary . '/outside.txt';
    scaffoldWrite($outside, "outside\n");
    unlink($project . '/managed.txt');
    symlink($outside, $project . '/managed.txt');
    scaffoldFails(fn() => $runner->preflight($project, $from, $to), 'SCAFFOLD_PATH_SYMLINK_REJECTED: managed.txt');
    unlink($project . '/managed.txt');
    scaffoldWrite($project . '/managed.txt', $old['managed.txt']);
    link($project . '/managed.txt', $project . '/alias.txt');
    scaffoldFails(fn() => $runner->preflight($project, $from, $to), 'SCAFFOLD_PATH_HARDLINK_REJECTED: managed.txt');
    unlink($project . '/alias.txt');
    $unsafe = json_decode((string) file_get_contents($to), true, 512, JSON_THROW_ON_ERROR);
    $unsafe['files'][0]['path'] = '../outside.txt';
    $unsafePath = dirname($to) . '/unsafe-manifest.json';
    $writeJson($unsafePath, $unsafe);
    scaffoldFails(fn() => $runner->preflight($project, $from, $unsafePath), 'SCAFFOLD_PATH_OUTSIDE_PROJECT: ../outside.txt');
    scaffoldExpect(file_get_contents($outside) === "outside\n", 'A rejected path touched external bytes.');
    echo "SCAFFOLD-SOURCE-RECOVERY passed\n";
} finally {
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($temporary, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($iterator as $entry) {
        $entry->isDir() && !$entry->isLink() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
    }
    rmdir($temporary);
}
