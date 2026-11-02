<?php

declare(strict_types=1);

use app\common\value\scaffold\ScaffoldManifest;

$source = dirname(__DIR__, 3);
require $source . '/scripts/scaffold-runtime/ScaffoldPathGuard.php';
require $source . '/scripts/scaffold-runtime/ScaffoldManifest.php';

$checks = 0;
$expect = static function (bool $condition, string $message) use (&$checks): void {
    if (!$condition) { throw new RuntimeException($message); }
    ++$checks;
};
$reject = static function (callable $operation, string $code) use ($expect): void {
    try { $operation(); }
    catch (RuntimeException $exception) {
        $expect(str_contains($exception->getMessage(), $code), 'Unexpected rejection: ' . $exception->getMessage());
        return;
    }
    throw new RuntimeException('Expected rejection: ' . $code);
};
$remove = static function (string $path) use (&$remove): void {
    if (is_dir($path) && !is_link($path)) {
        foreach (array_diff(scandir($path), ['.', '..']) as $entry) { $remove($path . '/' . $entry); }
        rmdir($path);
    } else { unlink($path); }
};

$root = sys_get_temp_dir() . '/peanut-owner-only-baseline-' . bin2hex(random_bytes(8));
$relative = 'plugins/acme.example/plugin.json';
$baseline = '.peanut/scaffold-baseline/5.0.15/files/' . $relative;
mkdir(dirname($root . '/' . $baseline), 0700, true);
$root = realpath($root);
$content = "{\"key\":\"acme.example\"}\n";
file_put_contents($root . '/' . $baseline, $content);
chmod($root . '/' . $baseline, 0644);
$template = ['version' => '5.0.15', 'source_commit' => str_repeat('a', 40),
    'source_tree' => str_repeat('b', 40), 'inventory_sha256' => str_repeat('c', 64)];
$app = ['schema_version' => 2, 'protocol' => 'peanut.application-scaffold.v2',
    'application' => ['version' => '0.1.0'], 'template' => $template, 'edition' => null,
    'files' => [['path' => $relative, 'classification' => 'managed', 'baseline_path' => $baseline,
        'baseline_sha256' => hash('sha256', $content), 'mode' => 0600, 'baseline_mode' => 0600]]];
$write = static function (array $value) use ($root): string {
    $bytes = json_encode($value, JSON_THROW_ON_ERROR);
    file_put_contents($root . '/.peanut/application-manifest.json', $bytes);
    return hash('sha256', $bytes);
};

try {
    foreach ([0600, 0644, 0755] as $mode) {
        $app['files'][0]['mode'] = $app['files'][0]['baseline_mode'] = $mode;
        $digest = $write($app);
        $path = ScaffoldManifest::writeRecordedBaseline($root);
        $record = ScaffoldManifest::load($path, true);
        $record->assertRecordedApplication($root, $app, 'sha256:' . $digest);
        $expect($record->files()[$relative]['mode'] === $mode, 'Recorded logical permissions changed');
        $expect(ScaffoldManifest::writeRecordedBaseline($root) === $path, 'Recorded baseline reuse changed');
        $reject(fn() => ScaffoldManifest::load($path), 'SCAFFOLD_MANIFEST_SCHEMA_UNSUPPORTED');
    }
    foreach ([null, '0600', 0666, 0777] as $mode) {
        $app['files'][0]['baseline_mode'] = $mode;
        $write($app);
        $reject(fn() => ScaffoldManifest::writeRecordedBaseline($root), 'SCAFFOLD_BASELINE_MODE_EVIDENCE_REQUIRED');
    }
    $app['files'][0]['baseline_mode'] = 0600;
    $digest = $write($app);
    $record = ScaffoldManifest::load(ScaffoldManifest::writeRecordedBaseline($root), true);
    $changed = $app;
    $changed['files'][0]['baseline_mode'] = 0644;
    $reject(fn() => $record->assertRecordedApplication($root, $changed, 'sha256:' . $digest), 'SCAFFOLD_RECORDED_APPLICATION_CHANGED');
    file_put_contents($root . '/' . $baseline, 'changed');
    $reject(fn() => ScaffoldManifest::writeRecordedBaseline($root), 'SCAFFOLD_RECORDED_BASELINE_DRIFT');
    file_put_contents($root . '/' . $baseline, $content);
    $release = ['schema_version' => 3, 'protocol' => 'peanut.scaffold-release.v3',
        'release' => $template + ['inventory_template_version' => '5.0.15',
            'tokens' => ['product_name' => 'Name', 'slug' => 'slug', 'package_identity' => 'acme/example', 'application_version' => '0.1.0']],
        'application' => ['version' => '0.1.0'], 'files' => array_values($record->files()), 'renames' => []];
    $releasePath = $root . '/release.json';
    file_put_contents($releasePath, json_encode($release, JSON_THROW_ON_ERROR));
    $reject(fn() => ScaffoldManifest::load($releasePath), 'SCAFFOLD_MANIFEST_FILE_INVALID');
    echo 'Recorded owner-only baseline: PASS (' . $checks . " assertions; release modes unchanged; no DB or runtime changes)\n";
} finally {
    $remove($root);
}
