<?php

declare(strict_types=1);

use app\common\value\scaffold\ScaffoldManifest;

$source = dirname(__DIR__, 3);
require $source . '/scripts/scaffold-runtime/ScaffoldPathGuard.php';
require $source . '/scripts/scaffold-runtime/ScaffoldManifest.php';

function recordedReject(callable $operation, string $code): void
{
    try { $operation(); }
    catch (RuntimeException $error) {
        if (str_contains($error->getMessage(), $code)) { return; }
        throw $error;
    }
    throw new RuntimeException('expected rejection: ' . $code);
}
function recordedRemove(string $path): void
{
    if (is_dir($path) && !is_link($path)) {
        foreach (array_diff(scandir($path), ['.', '..']) as $child) { recordedRemove($path . '/' . $child); }
        rmdir($path);
    } else { unlink($path); }
}

$root = sys_get_temp_dir() . '/peanut-recorded-baseline-' . bin2hex(random_bytes(8));
mkdir($root . '/.peanut/scaffold-baseline/5.0.1/files/scripts', 0700, true);
$baseline = $root . '/.peanut/scaffold-baseline/5.0.1/files/scripts/run';
$bytes = "already rendered Acme source\n";
file_put_contents($baseline, $bytes);
chmod($baseline, 0644);
$template = ['version' => '5.0.1', 'source_commit' => str_repeat('a', 40),
    'source_tree' => str_repeat('b', 40), 'inventory_sha256' => str_repeat('c', 64)];
$entry = ['path' => 'scripts/run', 'classification' => 'managed',
    'baseline_path' => '.peanut/scaffold-baseline/5.0.1/files/scripts/run',
    'baseline_sha256' => hash('sha256', $bytes), 'mode' => 0644];
$app = ['schema_version' => 2, 'protocol' => 'peanut.application-scaffold.v2',
    'application' => ['version' => '0.1.7'], 'template' => $template,
    'edition' => null, 'files' => [$entry]];
$appPath = $root . '/.peanut/application-manifest.json';
$writeApp = static function (array $value) use ($appPath): string {
    $raw = json_encode($value, JSON_THROW_ON_ERROR);
    file_put_contents($appPath, $raw);
    return hash('sha256', $raw);
};
try {
    $writeApp($app);
    recordedReject(fn() => ScaffoldManifest::writeRecordedBaseline($root), 'SCAFFOLD_BASELINE_MODE_EVIDENCE_REQUIRED');
    $app['files'][0]['baseline_mode'] = 0755;
    $appHash = $writeApp($app);
    $file = ScaffoldManifest::writeRecordedBaseline($root);
    $manifest = ScaffoldManifest::load($file, true);
    $manifest->assertRecordedApplication($root, $app, 'sha256:' . $appHash);
    if (!$manifest->isRecordedBaseline() || $manifest->files()['scripts/run']['mode'] !== 0755
        || $manifest->release()['tokens'] !== [] || ScaffoldManifest::writeRecordedBaseline($root) !== $file) {
        throw new RuntimeException('recorded baseline identity or logical mode changed');
    }
    recordedReject(fn() => ScaffoldManifest::load($file), 'SCAFFOLD_MANIFEST_SCHEMA_UNSUPPORTED');
    recordedReject(fn() => $manifest->assertRecordedApplication($root . '/other', $app, $appHash), 'SCAFFOLD_RECORDED_APPLICATION_CHANGED');
    $changed = $app;
    $changed['files'][0]['baseline_mode'] = 0644;
    recordedReject(fn() => $manifest->assertRecordedApplication($root, $changed, $appHash), 'SCAFFOLD_RECORDED_APPLICATION_CHANGED');
    file_put_contents($baseline, 'drift');
    recordedReject(fn() => ScaffoldManifest::writeRecordedBaseline($root), 'SCAFFOLD_RECORDED_BASELINE_DRIFT');
    file_put_contents($baseline, $bytes);
    $directory = dirname($baseline);
    rename($directory, $directory . '-original');
    symlink($directory . '-original', $directory);
    recordedReject(fn() => ScaffoldManifest::writeRecordedBaseline($root), 'SCAFFOLD_PATH_SYMLINK_REJECTED');
    unlink($directory);
    rename($directory . '-original', $directory);
    // Old APPs may obtain only missing logical modes from an exact original release.
    $proof = ['schema_version' => 3, 'protocol' => 'peanut.scaffold-release.v3',
        'release' => $template + ['inventory_template_version' => '5.0.1',
            'tokens' => ['product_name' => 'Name', 'slug' => 'slug', 'package_identity' => 'vendor/name', 'application_version' => '0.1.0']],
        'application' => ['version' => '0.1.0'], 'files' => array_values($manifest->files()), 'renames' => []];
    $proofPath = $root . '/original-release.json';
    file_put_contents($proofPath, json_encode($proof, JSON_THROW_ON_ERROR));
    unset($app['files'][0]['baseline_mode']);
    $appHash = $writeApp($app);
    unlink($file);
    $old = ScaffoldManifest::load(ScaffoldManifest::writeRecordedBaseline($root, $proofPath), true);
    $old->assertRecordedApplication($root, $app, $appHash);
    $proof['release']['source_commit'] = str_repeat('d', 40);
    file_put_contents($proofPath, json_encode($proof, JSON_THROW_ON_ERROR));
    recordedReject(fn() => ScaffoldManifest::writeRecordedBaseline($root, $proofPath), 'SCAFFOLD_BASELINE_MODE_PROOF_SOURCE_MISMATCH');
    echo "Recorded baseline: identity, logical modes, immutable reuse, target rejection, drift, symlink and exact mode proof passed\n";
    // A bundled label is insufficient: prove every old member and every new frontend root.
    require $source . '/scripts/scaffold-runtime/ScaffoldUpgradeRunner.php';
    $module = 'server/app/modules/acme/example';
    $frontend = 'web/src/modules/acme-example';
    $pluginManifest = 'plugins/acme.example/plugin.json';
    $oldFiles = [];
    $ownership = [];
    foreach ([$module . '/module.json', $pluginManifest] as $relative) {
        $content = $relative . "\n";
        foreach ([$root . '/' . $relative, $root . '/.peanut/scaffold-baseline/5.0.1/files/' . $relative] as $path) {
            if (!is_dir(dirname($path))) { mkdir(dirname($path), 0700, true); }
            file_put_contents($path, $content);
            chmod($path, 0644);
        }
        $digest = hash('sha256', $content);
        $oldFiles[$relative] = ['classification' => 'managed', 'template_sha256' => $digest, 'mode' => 0644];
        $ownership[] = ['path' => $relative, 'source' => $relative, 'classification' => 'managed',
            'owner' => 'scaffold', 'sha256' => $digest, 'mode' => 0644,
            'baseline_path' => '.peanut/scaffold-baseline/5.0.1/files/' . $relative, 'baseline_sha256' => $digest];
    }
    $plugin = ['manifest' => $pluginManifest, 'manifest_sha256' => hash_file('sha256', $root . '/' . $pluginManifest),
        'module_keys' => ['acme.example'], 'module_roots' => [$module], 'frontend_roots' => [],
        'source_contract' => ['type' => 'canonical-contents', 'channel' => 'bundled',
            'origin' => ['type' => 'repository-contents', 'reference' => 'canonical-plugin-contents-v1']]];
    $current = ['plugins' => ['acme.example' => $plugin]];
    $target = ['acme.example' => array_replace($plugin, ['frontend_roots' => [$frontend]])];
    $newFiles = $oldFiles + [$frontend . '/index.ts' => ['classification' => 'managed',
        'template_sha256' => hash('sha256', 'target'), 'mode' => 0644]];
    $owned = ['template' => ['version' => '5.0.1'], 'ownership' => ['baseline_root' => '.peanut/scaffold-baseline/5.0.1/files'],
        'files' => $ownership];
    $proof = new ReflectionMethod(\app\common\infrastructure\scaffold\ScaffoldUpgradeRunner::class, 'managedBundledUpstreamProofs');
    $runner = new \app\common\infrastructure\scaffold\ScaffoldUpgradeRunner();
    $prove = static fn(array $application, array $present, array $next): array => $proof->invoke(
        $runner, $root, $application, $present, ['acme.example' => $plugin], $oldFiles, $next, $newFiles);
    if (($prove($owned, $current, $target)['acme.example']['frontend_roots'] ?? null) !== [$frontend]) {
        throw new RuntimeException('unoccupied managed frontend addition was not proven');
    }
    $edited = $root . '/' . $module . '/module.json';
    $original = file_get_contents($edited);
    file_put_contents($edited, 'customer modification');
    if ($prove($owned, $current, $target) !== []) { throw new RuntimeException('customer source was adopted'); }
    file_put_contents($edited, $original);
    mkdir($root . '/' . $frontend, 0700, true);
    file_put_contents($root . '/' . $frontend . '/customer.ts', 'customer frontend');
    if ($prove($owned, $current, $target) !== []) { throw new RuntimeException('occupied frontend was adopted'); }
    unlink($root . '/' . $frontend . '/customer.ts');
    rmdir($root . '/' . $frontend);
    $reserved = $owned;
    $reserved['files'][] = ['path' => $frontend . '/missing.ts', 'classification' => 'app-owned'];
    if ($prove($reserved, $current, $target) !== []) { throw new RuntimeException('absent application-owned path was adopted'); }
    $foreign = $current;
    $foreign['plugins']['customer.other'] = ['module_roots' => [], 'frontend_roots' => ['web/src/modules']];
    if ($prove($owned, $foreign, $target) !== []) { throw new RuntimeException('overlapping customer root was adopted'); }
    $unproven = $current;
    $unproven['plugins']['acme.example']['source_contract']['channel'] = 'private';
    if ($prove($owned, $unproven, $target) !== []) { throw new RuntimeException('private source was adopted'); }
    $membership = $target;
    $membership['acme.example']['module_keys'][] = 'acme.other';
    if ($prove($owned, $current, $membership) !== []) { throw new RuntimeException('backend membership change was adopted'); }
    $removal = $current;
    $removal['plugins']['acme.example']['frontend_roots'] = [$frontend];
    if ($prove($owned, $removal, ['acme.example' => $plugin]) !== []) {
        throw new RuntimeException('unproven old frontend membership was adopted');
    }
    echo "Bundled ownership: exact baseline, new frontend, local edits, occupied/reserved/overlapping roots, private source and membership guards passed\n";
    // Ordinary generated APP installation anchors stay separate from runtime projections.
    require $source . '/server/app/common/value/installation/ApplicationReleaseVersions.php';
    require $source . '/server/app/common/value/installation/ApplicationSourceIdentity.php';
    require $source . '/server/docker/scripts/update-plan.php';
    $profile = str_repeat('e', 64);
    $application = ['schema_version' => 2, 'protocol' => 'peanut.application-scaffold.v2',
        'application' => ['name' => 'Source fixture', 'slug' => 'source-fixture', 'package_identity' => 'acme/source-fixture',
            'version' => '0.1.7', 'profile' => 'standard', 'edition' => 'multi-tenant'],
        'edition' => ['name' => 'multi-tenant', 'deployment_mode' => 'multi-tenant',
            'source_sha256' => $profile, 'tenant_bootstrap' => []],
        'generation_source' => ['edition_profile_sha256' => $profile]];
    $putJson = static function (string $path, array $value): string {
        if (!is_dir(dirname($path))) { mkdir(dirname($path), 0700, true); }
        $bytes = json_encode($value, JSON_THROW_ON_ERROR);
        file_put_contents($path, $bytes); chmod($path, 0600);
        return hash('sha256', $bytes);
    };
    $appHash = $putJson($appPath, $application);
    $putJson($root . '/release-versions.json', ['schema_version' => 2, 'protocol' => 'peanut.release-versions.v2',
        'source_product_version' => '5.0.1', 'instance_version' => '0.1.7', 'scaffold_template' => '5.0.1',
        'generated_instance_default' => '0.1.0', 'core_php' => '5.0.1', 'core_web' => '5.0.1']);
    $baseline = ['schema_version' => 1, 'protocol' => 'peanut.installation-baseline.v1',
        'deployment_mode' => 'multi-tenant', 'source' => ['kind' => 'generated-application', 'application_manifest_sha256' => $appHash]];
    $baselineHash = $putJson($root . '/server/private/installation/baseline.json', $baseline);
    $receipt = ['schema_version' => 1, 'protocol' => 'peanut.installation-receipt.v1', 'state' => 'installed',
        'deployment_mode' => 'multi-tenant', 'baseline_manifest' => [
            'path' => 'server/private/installation/baseline.json', 'sha256' => $baselineHash]];
    $putJson($root . '/server/private/installation/installed.json', $receipt);
    $server = $root . '/server';
    $context = PeanutServerUpdatePlan::applicationSourceContext($server);
    if ($context['initial_application_manifest_sha256'] !== $appHash) {
        throw new RuntimeException('ordinary installation source was not bound');
    }
    // Synthetic container view for this file-only check; it grants no real runtime qualification.
    $observerPath = getenv('PATH');
    mkdir($root . '/.observer-bin', 0700);
    file_put_contents($root . '/.observer-bin/docker', <<<'PHP'
#!/usr/bin/env php
<?php
$root = dirname(__DIR__);
$control = json_decode(file_get_contents($root . '/.observer-containers.json'), true);
if ($control['status'] === 'unknown') { exit(23); }
if (($argv[1] ?? null) === 'ps') {
    if ($control['status'] !== 'none') { echo str_repeat('a', 12) . "\n"; }
    exit(0);
}
if (($argv[1] ?? null) === 'inspect') {
    echo json_encode(['State' => ['Status' => $control['status']],
        'Mounts' => [['Type' => 'bind', 'Source' => $root]]]);
    exit(0);
}
exit(91);
PHP);
    chmod($root . '/.observer-bin/docker', 0755);
    putenv('PATH=' . $root . '/.observer-bin:' . $observerPath);
    $putJson($root . '/.observer-containers.json', ['status' => 'running']);
    recordedReject(static fn() => PeanutServerUpdatePlan::assertApplicationSourceStopped($server),
        'SOURCE_ONLY_SERVICE_OBSERVATION_REJECTED');
    $putJson($root . '/.observer-containers.json', ['status' => 'unknown']);
    recordedReject(static fn() => PeanutServerUpdatePlan::assertApplicationSourceStopped($server),
        'SOURCE_ONLY_SERVICE_OBSERVATION_REJECTED');
    $putJson($root . '/.observer-containers.json', ['status' => 'none']);
    recordedReject(static fn() => PeanutServerUpdatePlan::beginApplicationSource($server,
        ['identity' => ['source_installation' => $context]], 'apply'), 'SOURCE_ONLY_INSTANCE_LOCK_REQUIRED');
    $active = proc_open(['sleep', '20'], [0 => ['file', '/dev/null', 'r'],
        1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes, $root);
    if (!is_resource($active)) { throw new RuntimeException('active source fixture could not start'); }
    try {
        recordedReject(static fn() => PeanutServerUpdatePlan::assertApplicationSourceStopped($server),
            'SOURCE_ONLY_SERVICE_OBSERVATION_REJECTED');
    } finally { proc_terminate($active); proc_close($active); }
    PeanutServerUpdatePlan::assertApplicationSourceStopped($server);
    $sourcePlan = ['candidate' => 'scaffold-' . str_repeat('a', 24), 'identity' => ['source_installation' => $context]];
    PeanutServerUpdatePlan::withSourceInstanceLock($server, static function () use ($server, $sourcePlan): void {
        PeanutServerUpdatePlan::beginApplicationSource($server, $sourcePlan, 'apply');
        PeanutServerUpdatePlan::finishApplicationSource($server, $sourcePlan['candidate'], 'verify');
    });
    if (file_exists($server . '/runtime/upgrade/.traffic-ready') || file_exists($server . '/runtime/upgrade/maintenance.json')
        || file_exists($server . '/.peanut/development-runtime.json')) {
        throw new RuntimeException('source-only operation fabricated runtime identity or opened traffic');
    }
    $application['last_scaffold_upgrade'] = ['edition_profile_sha256' => $profile, 'source_installation' => $context];
    $putJson($appPath, $application);
    if (PeanutServerUpdatePlan::applicationSourceContext($server) !== $context) {
        throw new RuntimeException('source-only successor lost its immutable installed pair');
    }
    file_put_contents($server . '/.env', "INSTANCE=fixture\n");
    recordedReject(static fn() => PeanutServerUpdatePlan::withSourceInstanceLock($server,
        static fn() => PeanutServerUpdatePlan::beginApplicationSource($server, $sourcePlan, 'apply')),
        'SOURCE_ONLY_INSTALLATION_CHANGED');
    unlink($server . '/.env');
    $putJson($server . '/private/installation/executing.json', ['state' => 'unknown']);
    recordedReject(static fn() => PeanutServerUpdatePlan::applicationSourceContext($server),
        'SOURCE_ONLY_RUNTIME_MAINTENANCE_REQUIRED');
    unlink($server . '/private/installation/executing.json');
    $receipt['baseline_manifest']['sha256'] = str_repeat('0', 64);
    $putJson($server . '/private/installation/installed.json', $receipt);
    recordedReject(static fn() => PeanutServerUpdatePlan::applicationSourceContext($server),
        'installed instance baseline differs from receipt');
    echo "Source-only installation: native stopped observation, immutable anchors, successor chain, config drift, unknown operation and no runtime activation passed\n";
} finally {
    if (isset($observerPath)) { putenv('PATH=' . $observerPath); }
    recordedRemove($root);
}
