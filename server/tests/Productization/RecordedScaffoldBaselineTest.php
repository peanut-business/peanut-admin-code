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

$root = (realpath(sys_get_temp_dir()) ?: sys_get_temp_dir()) . '/peanut-recorded-baseline-' . bin2hex(random_bytes(8));
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
    require_once $source . '/server/vendor/autoload.php';
    require_once $source . '/scripts/scaffold-runtime/ScaffoldUpgradeRunner.php';
    $module = 'server/app/modules/acme/example';
    $frontend = 'web/src/modules/acme-example';
    $moduleManifest = $module . '/module.json';
    $oldFiles = [];
    $ownership = [];
    foreach ([$module . '/src/Example.php', $moduleManifest] as $relative) {
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
    $plugin = ['manifest' => $moduleManifest, 'manifest_sha256' => hash_file('sha256', $root . '/' . $moduleManifest),
        'module_keys' => ['acme.example'], 'module_roots' => [$module], 'frontend_roots' => [],
        'source_contract' => ['type' => 'canonical-contents', 'channel' => 'bundled',
            'origin' => ['type' => 'repository-contents', 'reference' => 'canonical-plugin-contents-v1']]];
    $current = ['modules' => ['acme.example' => $plugin]];
    $target = ['acme.example' => array_replace($plugin, ['frontend_roots' => [$frontend]])];
    $newFiles = $oldFiles + [$frontend . '/index.ts' => ['classification' => 'managed',
        'template_sha256' => hash('sha256', 'target'), 'mode' => 0644]];
    $owned = ['template' => ['version' => '5.0.1'], 'ownership' => ['baseline_root' => '.peanut/scaffold-baseline/5.0.1/files'],
        'files' => $ownership];
    $proof = new ReflectionMethod(\app\common\infrastructure\scaffold\ScaffoldUpgradeRunner::class, 'managedBundledUpstreamProofs');
    $runner = new \app\common\infrastructure\scaffold\ScaffoldUpgradeRunner();
    $prove = static fn(array $application, array $present, array $next): array => $proof->invoke(
        $runner, $root, $application, $present, ['acme.example' => $plugin], $oldFiles, $next, $newFiles, []);
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
    $foreign['modules']['customer.other'] = ['module_roots' => [], 'frontend_roots' => ['web/src/modules']];
    if ($prove($owned, $foreign, $target) !== []) { throw new RuntimeException('overlapping customer root was adopted'); }
    $unproven = $current;
    $unproven['modules']['acme.example']['source_contract']['channel'] = 'private';
    if ($prove($owned, $unproven, $target) !== []) { throw new RuntimeException('private source was adopted'); }
    $membership = $target;
    $membership['acme.example']['module_keys'][] = 'acme.other';
    if ($prove($owned, $current, $membership) !== []) { throw new RuntimeException('backend membership change was adopted'); }
    $removal = $current;
    $removal['modules']['acme.example']['frontend_roots'] = [$frontend];
    if ($prove($owned, $removal, ['acme.example' => $plugin]) !== []) {
        throw new RuntimeException('unproven old frontend membership was adopted');
    }
    echo "Bundled ownership: exact baseline, new frontend, local edits, occupied/reserved/overlapping roots, private source and membership guards passed\n";
    // The current installer retains the historical APP identity in a private v2 receipt.
    $server = $root . '/server';
    $receiptPath = $server . '/private/installation/installed.json';
    mkdir(dirname($receiptPath), 0700, true);
    $initial = hash_file('sha256', $appPath);
    $receipt = ['schema_version' => 1, 'protocol' => 'peanut.installation-receipt.v2', 'state' => 'installed',
        'completed_at' => '2026-10-01T00:00:00Z', 'deployment_mode' => 'multi-tenant', 'official_modules' => [],
        'release' => ['source_product_version' => '5.0.1', 'release_sequence_version' => '5.0.1', 'scaffold_template' => '5.0.1'],
        'source' => ['kind' => 'generated-application', 'application_manifest_sha256' => $initial],
        'application_manifest_sha256' => $initial,
        'database' => ['migration_table' => 'pa_schema_migration', 'schema_version' => '20261001000000',
            'migrations' => [['version' => '20261001000000', 'sha256' => hash('sha256', 'native migration')]]]];
    $writeReceipt = static function (array $value) use ($receiptPath): void {
        file_put_contents($receiptPath, json_encode($value, JSON_THROW_ON_ERROR));
        chmod($receiptPath, 0600);
    };
    $writeReceipt($receipt);
    $facts = static fn(): array => \app\common\services\installation\InstallationExecutionHost::verifiedInstallationFacts($server);
    $original = $facts();
    $app['application']['version'] = '0.2.0';
    $writeApp($app);
    if ($facts() !== $original || $original['receipt']['source']['application_manifest_sha256'] !== $initial) {
        throw new RuntimeException('scaffold successor rewrote the original installation identity');
    }
    $changed = $receipt;
    $changed['application_manifest_sha256'] = str_repeat('0', 64);
    $writeReceipt($changed);
    recordedReject($facts, 'APPLICATION_SCHEMA_INSTALLATION_IDENTITY_INVALID');
    $changed = $receipt;
    $changed['database']['migrations'][] = $changed['database']['migrations'][0];
    $writeReceipt($changed);
    recordedReject($facts, 'APPLICATION_SCHEMA_INSTALLATION_CHAIN_INVALID');
    $writeReceipt($receipt);
    chmod($receiptPath, 0644);
    recordedReject($facts, 'APPLICATION_SCHEMA_INSTALLATION_FILE_UNSAFE');
    echo "Historical installation: retained source identity, migration order and private receipt guard passed\n";
} finally {
    recordedRemove($root);
}
