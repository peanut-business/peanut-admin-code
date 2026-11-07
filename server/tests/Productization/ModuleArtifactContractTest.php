<?php

declare(strict_types=1);

use app\platform\exception\module\ModuleArtifactToolException;
use app\platform\exception\module\ModuleLifecycleException;
use app\platform\infrastructure\module\ModuleArtifactWriter;
use app\platform\infrastructure\module\ModuleLockResolver;
use app\platform\value\module\ModuleFrontendLayout;
use Opis\JsonSchema\Validator;

require dirname(__DIR__, 2) . '/vendor/autoload.php';

$assertions = 0;
function pluginArtifactExpect(bool $condition, string $message): void
{
    global $assertions;
    ++$assertions;
    if (!$condition) { throw new RuntimeException($message); }
}
function artifactCopy(string $source, string $target): void
{
    if (!is_dir($target)) { mkdir($target, 0700, true); }
    foreach (new DirectoryIterator($source) as $entry) {
        if ($entry->isDot() || in_array($entry->getFilename(), ['node_modules', 'vendor', '.git'], true)) { continue; }
        if ($entry->isLink()) { throw new RuntimeException('TEST_SOURCE_SYMLINK'); }
        $destination = $target . '/' . $entry->getFilename();
        $entry->isDir() ? artifactCopy($entry->getPathname(), $destination) : copy($entry->getPathname(), $destination);
    }
}
function artifactRemove(string $root): void
{
    if (!is_dir($root)) { return; }
    foreach (new DirectoryIterator($root) as $entry) {
        if ($entry->isDot()) { continue; }
        $entry->isDir() && !$entry->isLink() ? artifactRemove($entry->getPathname()) : unlink($entry->getPathname());
    }
    rmdir($root);
}

$serverRoot = dirname(__DIR__, 2);
$repoRoot = dirname($serverRoot);
$key = 'peanut.content-tools';
$module = json_decode((string) file_get_contents($serverRoot . '/app/modules/peanut/content_tools/module.json'), true, 128, JSON_THROW_ON_ERROR);
$root = rtrim(sys_get_temp_dir(), '/') . '/pa-single-module-artifact-' . bin2hex(random_bytes(8));
mkdir($root, 0700, true);
try {
    artifactCopy($serverRoot . '/app/modules/peanut/content_tools', $root . '/server/app/modules/peanut/content_tools');
    foreach (ModuleFrontendLayout::contributions($module['frontend'], $key) as $client) {
        artifactCopy($repoRoot . '/' . $client['root'], $root . '/' . $client['root']);
    }
    mkdir($root . '/server/resources/schemas', 0700, true);
    copy($serverRoot . '/resources/schemas/module-artifact.schema.json', $root . '/server/resources/schemas/module-artifact.schema.json');
    $writer = new ModuleArtifactWriter($root . '/server');
    $writer->make($key, $module['version'], [$key . '=server/app/modules/peanut/content_tools']);
    $writer->writeLock();
    $manifestPath = $root . '/server/app/modules/peanut/content_tools/module.json';
    $lockPath = $root . '/modules.lock';
    $validManifest = $writer->build($key, $module['version'], [$key . '=server/app/modules/peanut/content_tools']);
    $validLock = json_decode((string) file_get_contents($lockPath), true, 128, JSON_THROW_ON_ERROR);
    $descriptor = (new ModuleLockResolver($root . '/server', '../modules.lock'))->require($key);
    pluginArtifactExpect($descriptor->key === $key && $descriptor->version === $module['version'], 'Module and artifact identity differ');
    pluginArtifactExpect(array_keys($descriptor->moduleRoots) === [$key], 'Artifact must contain one named Module');
    $schema = json_decode((string) file_get_contents($serverRoot . '/resources/schemas/module-artifact.schema.json'));
    $validator = new Validator();
    pluginArtifactExpect($validator->validate(json_decode(json_encode($validManifest, JSON_THROW_ON_ERROR)), $schema)->isValid(), 'Native single Module manifest fails its schema');
    $multiple = $validManifest;
    $multiple['modules'][] = ['key' => 'acme.second', 'root' => 'server/app/modules/acme/second'];
    pluginArtifactExpect(!$validator->validate(json_decode(json_encode($multiple, JSON_THROW_ON_ERROR)), $schema)->isValid(), 'Schema still admits a multi-Module bundle');

    foreach ([
        'source' => static function (array &$m, array &$l): void { $m['source']['sha256'] = $l['modules'][0]['source']['sha256'] = str_repeat('0', 64); },
        'composer' => static function (array &$m, array &$l): void { $m['composer'][0]['sha256'] = $l['modules'][0]['composer'][0]['sha256'] = str_repeat('0', 64); },
        'npm' => static function (array &$m, array &$l): void { $m['npm'][0]['integrity'] = $l['modules'][0]['npm'][0]['integrity'] = 'sha256-' . base64_encode(str_repeat("\0", 32)); },
        'frontend' => static function (array &$m, array &$l): void { $m['frontend'][0]['sha256'] = $l['modules'][0]['frontend'][0]['sha256'] = str_repeat('0', 64); },
    ] as $label => $mutate) {
        $manifest = $validManifest;
        $lock = $validLock;
        $mutate($manifest, $lock);
        // Only generated lock metadata is changed; the sole source declaration stays intact.
        $lock['modules'][0]['manifest_sha256'] = hash_file('sha256', $manifestPath);
        file_put_contents($lockPath, json_encode($lock, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n");
        try {
            (new ModuleLockResolver($root . '/server', '../modules.lock'))->all();
            throw new RuntimeException('Tampered ' . $label . ' artifact was accepted');
        } catch (ModuleLifecycleException $exception) {
            pluginArtifactExpect($exception->errorCode === 'PLUGIN_ARTIFACT_MISMATCH', 'Wrong ' . $label . ' rejection: ' . $exception->errorCode);
        }
    }
    // Valid payload hashes cannot authorize a second version identity in the envelope.
    $manifest = $validManifest;
    $lock = $validLock;
    $lock['modules'][0]['version'] = '999.0.0';
    $lock['modules'][0]['manifest_sha256'] = hash_file('sha256', $manifestPath);
    file_put_contents($lockPath, json_encode($lock, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n");
    try {
        (new ModuleLockResolver($root . '/server', '../modules.lock'))->all();
        throw new RuntimeException('Incoming artifact version differed from its Module but was accepted');
    } catch (ModuleLifecycleException $exception) {
        pluginArtifactExpect($exception->errorCode === 'PLUGIN_MANIFEST_MISMATCH', 'Wrong incoming version rejection');
    }
    foreach ([
        [$key, $module['version'], []],
        [$key, $module['version'], [$key . '=server/app/modules/peanut/content_tools', 'acme.second=server/app/modules/acme/second']],
        ['acme.wrong', $module['version'], [$key . '=server/app/modules/peanut/content_tools']],
        [$key, '999.0.0', [$key . '=server/app/modules/peanut/content_tools']],
    ] as $arguments) {
        try {
            $writer->build(...$arguments);
            throw new RuntimeException('Artifact authoring admitted a split identity or multiple Modules');
        } catch (ModuleArtifactToolException $exception) {
            pluginArtifactExpect(in_array($exception->getMessage(), ['MODULE_PACKAGE_SINGLE_MODULE_REQUIRED', 'MODULE_PACKAGE_IDENTITY_MISMATCH'], true), 'Unexpected authoring rejection');
        }
    }
    echo "MODULE-ARTIFACT-CONTRACT-001 passed assertions={$assertions}; single-identity/schema/source/composer/npm/frontend\n";
} finally {
    artifactRemove($root);
}
