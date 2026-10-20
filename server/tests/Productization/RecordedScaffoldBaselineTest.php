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
} finally { recordedRemove($root); }
