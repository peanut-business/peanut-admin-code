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

$temporary = realpath(sys_get_temp_dir()) . '/peanut-scaffold-source-recovery-' . bin2hex(random_bytes(8));
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
    // A recorded local baseline remains local while adopting an explicitly selected Git target.
    $checkout = $temporary . '/source';
    mkdir($checkout . '/scaffold', 0700, true);
    $inventory = ['protocol' => 'peanut.create-app-inventory.v2', 'template_version' => '1.5.0',
        'files' => array_map(static fn(string $path): array => ['target' => $path,
            'classification' => 'managed', 'profiles' => ['full']], array_keys($old))];
    $writeJson($checkout . '/scaffold/application-template-inventory.json', $inventory);
    $writeJson($checkout . '/scaffold/edition-profiles.json', ['protocol' => 'peanut.edition-profiles.v1']);
    $git = static function (array $args) use ($checkout): string {
        $pipes = [];
        $process = proc_open(['git', '-c', 'user.name=Scaffold fixture', '-c', 'user.email=fixture@example.invalid',
            '-c', 'commit.gpgSign=false', '-c', 'core.hooksPath=/dev/null', '-C', $checkout, ...$args],
            [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        $output = stream_get_contents($pipes[1]); $error = stream_get_contents($pipes[2]);
        fclose($pipes[1]); fclose($pipes[2]);
        scaffoldExpect(proc_close($process) === 0, $error);
        return trim($output);
    };
    $git(['init', '--quiet']);
    $git(['remote', 'add', 'origin', 'https://github.com/peanut-business/peanut-admin-code.git']);
    $git(['add', '--', 'scaffold']);
    $git(['commit', '--quiet', '-m', 'fixed selected Git target']);
    $targetSource = ['repository' => 'peanut-business/peanut-admin-code', 'channel' => 'development',
        'release_version' => null, 'commit' => $git(['rev-parse', 'HEAD']), 'tree' => $git(['rev-parse', 'HEAD^{tree}']),
        'inventory_sha256' => hash_file('sha256', $checkout . '/scaffold/application-template-inventory.json'),
        'edition_profile_sha256' => hash_file('sha256', $checkout . '/scaffold/edition-profiles.json')];
    $targetSource['requested_ref'] = $targetSource['commit'];
    $localIdentity = ['kind' => 'local', 'snapshot_sha256' => str_repeat('e', 64)];
    $application = json_decode($beforeManifest, true, 512, JSON_THROW_ON_ERROR);
    $application['application'] += ['profile' => 'full', 'workflow' => 'full', 'edition' => 'standalone'];
    $application['template'] = ['version' => '1.0.0', 'source_identity' => $localIdentity,
        'inventory_sha256' => str_repeat('a', 64)];
    $application['generation_source'] = ['source_identity' => $localIdentity, 'repository' => null,
        'channel' => 'development', 'release_version' => null, 'requested_ref' => 'local:' . str_repeat('e', 64),
        'inventory_sha256' => str_repeat('a', 64), 'edition_profile_sha256' => str_repeat('d', 64)];
    $application['edition'] = ['name' => 'standalone', 'source_sha256' => str_repeat('d', 64)];
    foreach ($application['files'] as &$entry) { $entry['baseline_mode'] = $entry['mode']; }
    unset($entry);
    $writeJson($appManifest, $application);
    $recorded = \app\common\value\scaffold\ScaffoldManifest::writeRecordedBaseline($project);
    $package = $project . '/.peanut/upgrades/template-packages/' . str_repeat('9', 64);
    $targetPath = $manifest('1.5.0', 'f', $old);
    $targetDocument = json_decode((string) file_get_contents($targetPath), true, 512, JSON_THROW_ON_ERROR);
    $targetDocument['release'] = array_replace($targetDocument['release'], [
        'source_commit' => $targetSource['commit'], 'source_tree' => $targetSource['tree'],
        'inventory_sha256' => $targetSource['inventory_sha256'], 'workflow' => 'full']);
    $targetDocument['edition'] = ['name' => 'standalone', 'deployment_mode' => 'standalone',
        'module_profile' => 'official-default', 'source_sha256' => $targetSource['edition_profile_sha256'],
        'tenant_bootstrap' => ['kind' => 'real-default-tenant', 'code' => 'default', 'tenant_identity' => 'required',
            'rbac' => 'required', 'execution_context' => 'PeanutAdmin\\Kernel\\Context\\TenantSystemContext',
            'module_lifecycle' => 'required']];
    foreach ($old as $file => $content) { scaffoldWrite($package . '/files/' . $file, $content); }
    $writeJson($package . '/scaffold-manifest.json', $targetDocument);
    $writeJson($package . '/template-create.json', ['application_manifest' => []]);
    $metadata = ['protocol' => 'peanut.template-package.v1', 'version' => '1.5.0',
        'edition' => 'standalone', 'profile' => 'full', 'workflow' => 'full', 'source' => $targetSource,
        'archive' => ['sha256' => str_repeat('9', 64)],
        'create_manifest_sha256' => hash_file('sha256', $package . '/template-create.json'),
        'scaffold_manifest_sha256' => hash_file('sha256', $package . '/scaffold-manifest.json')];
    $writeJson($package . '/template-package.json', $metadata);
    $selection = ['repository' => $targetSource['repository'], 'requested_ref' => 'local:' . str_repeat('9', 64),
        'channel' => 'development', 'commit' => $targetSource['commit'], 'tree' => $targetSource['tree'],
        'source_checkout' => $checkout, 'template_package_path' => $package . '/template-package.json',
        'template_package_sha256' => 'sha256:' . hash_file('sha256', $package . '/template-package.json')];
    $nativePlan = $runner->preflight($project, $recorded, $package . '/scaffold-manifest.json', $selection);
    scaffoldExpect($nativePlan['status'] === 'ready', 'Local baseline to Git target did not produce a ready plan.');
    $nativePlanPath = $project . '/' . $nativePlan['plan_path'];
    $saved = json_decode((string) file_get_contents($nativePlanPath), true, 512, JSON_THROW_ON_ERROR);
    scaffoldExpect($saved['identity']['source_relation']['kind'] === 'local_snapshot'
        && $saved['identity']['source_relation']['base'] === $localIdentity
        && $saved['identity']['source_relation']['target']['kind'] === 'git'
        && $saved['identity']['development_source']['workflow'] === 'full',
        'Native plan changed the source identity or explicit Workflow selection.');
    $baselinePath = $project . '/.peanut/scaffold-baseline/1.0.0/files/managed.txt';
    scaffoldWrite($baselinePath, "changed old baseline\n");
    scaffoldFails(fn() => $runner->preflight($project, $recorded, $package . '/scaffold-manifest.json', $selection),
        'SCAFFOLD_RECORDED_BASELINE_DRIFT');
    scaffoldFails(fn() => $runner->apply($project, $nativePlanPath), 'SCAFFOLD_RECORDED_BASELINE_DRIFT');
    scaffoldWrite($baselinePath, $old['managed.txt']);
    $applicationBytes = (string) file_get_contents($appManifest);
    scaffoldWrite($appManifest, $applicationBytes . "\n");
    scaffoldFails(fn() => $runner->preflight($project, $recorded, $package . '/scaffold-manifest.json', $selection),
        'SCAFFOLD_RECORDED_APPLICATION_CHANGED');
    scaffoldFails(fn() => $runner->apply($project, $nativePlanPath), 'SCAFFOLD_PLAN_APPLICATION_LOCK_CHANGED');
    scaffoldWrite($appManifest, $applicationBytes);
    $metadataBytes = (string) file_get_contents($package . '/template-package.json');
    scaffoldWrite($package . '/template-package.json', $metadataBytes . "\n");
    scaffoldFails(fn() => $runner->apply($project, $nativePlanPath), 'SCAFFOLD_TEMPLATE_SOURCE_METADATA_CHANGED');
    scaffoldWrite($package . '/template-package.json', $metadataBytes);
    $targetBytes = (string) file_get_contents($package . '/scaffold-manifest.json');
    scaffoldWrite($package . '/scaffold-manifest.json', $targetBytes . "\n");
    scaffoldFails(fn() => $runner->apply($project, $nativePlanPath), 'SCAFFOLD_MANIFEST_CHECKSUM_DRIFT');
    scaffoldWrite($package . '/scaffold-manifest.json', $targetBytes);
    $git(['remote', 'set-url', 'origin', 'https://example.invalid/foreign.git']);
    scaffoldFails(fn() => $runner->apply($project, $nativePlanPath), 'SCAFFOLD_DEVELOPMENT_REPOSITORY_INVALID');
    $git(['remote', 'set-url', 'origin', 'https://github.com/peanut-business/peanut-admin-code.git']);
    $metadata['workflow'] = 'runtime';
    $writeJson($package . '/template-package.json', $metadata);
    $runtimeSelection = $selection;
    $runtimeSelection['template_package_sha256'] = 'sha256:' . hash_file('sha256', $package . '/template-package.json');
    scaffoldFails(fn() => $runner->preflight($project, $recorded, $package . '/scaffold-manifest.json', $runtimeSelection),
        'SCAFFOLD_DEVELOPMENT_PROFILE_INVALID');
    scaffoldWrite($package . '/template-package.json', $metadataBytes);
    scaffoldExpect($runner->apply($project, $nativePlanPath)['status'] === 'applied', 'Native local to Git plan did not apply.');
    scaffoldExpect($runner->verify($project, $nativePlanPath)['status'] === 'verified', 'Native local to Git result did not verify.');
    $adopted = json_decode((string) file_get_contents($appManifest), true, 512, JSON_THROW_ON_ERROR);
    scaffoldExpect($adopted['generation_source']['commit'] === $targetSource['commit']
        && $adopted['application']['workflow'] === 'full', 'Applied target identity or Workflow was lost.');
    $runner->recover($project, $nativePlanPath);
    echo "SCAFFOLD-SOURCE-RECOVERY passed\n";
} finally {
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($temporary, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($iterator as $entry) {
        $entry->isDir() && !$entry->isLink() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
    }
    rmdir($temporary);
}
