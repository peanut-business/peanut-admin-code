<?php

declare(strict_types=1);

use app\platform\exception\plugin\PluginLifecycleException;
use app\platform\infrastructure\plugin\PluginLockResolver;
use Opis\JsonSchema\Errors\ErrorFormatter;
use Opis\JsonSchema\Validator;

require dirname(__DIR__, 2) . '/vendor/autoload.php';

function pluginArtifactExpect(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

/** @param callable(array<string,mixed>&,array<string,mixed>&):void $mutate */
function pluginArtifactRejects(string $repoRoot, callable $mutate, string $expectedCode): void
{
    $fixtureRoot = sys_get_temp_dir() . '/pa-plugin-artifact-' . bin2hex(random_bytes(8));
    $moduleRoot = $fixtureRoot . '/server/app/modules/fixture/delivery_record';
    mkdir($moduleRoot, 0777, true);
    $sourceRoot = $repoRoot . '/server/app/modules/fixture/delivery_record';
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($sourceRoot, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST,
    );
    foreach ($iterator as $entry) {
        $relative = substr($entry->getPathname(), strlen($sourceRoot) + 1);
        $target = $moduleRoot . '/' . $relative;
        if ($entry->isDir()) {
            mkdir($target, 0777, true);
        } else {
            copy($entry->getPathname(), $target);
        }
    }
    $frontendSourceRoot = $repoRoot . '/web/src/modules/fixture-delivery-record';
    $frontendRoot = $fixtureRoot . '/web/src/modules/fixture-delivery-record';
    mkdir($frontendRoot, 0777, true);
    $frontendIterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($frontendSourceRoot, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST,
    );
    foreach ($frontendIterator as $entry) {
        $relative = substr($entry->getPathname(), strlen($frontendSourceRoot) + 1);
        $target = $frontendRoot . '/' . $relative;
        if ($entry->isDir()) {
            mkdir($target, 0777, true);
        } else {
            copy($entry->getPathname(), $target);
        }
    }
    mkdir($fixtureRoot . '/plugins/fixture.delivery-record', 0777, true);
    $lock = json_decode((string) file_get_contents($repoRoot . '/plugins.lock'), true, 64, JSON_THROW_ON_ERROR);
    $manifest = json_decode(
        (string) file_get_contents($repoRoot . '/plugins/fixture.delivery-record/plugin.json'),
        true,
        64,
        JSON_THROW_ON_ERROR,
    );
    $mutate($lock, $manifest);
    $manifestPath = $fixtureRoot . '/plugins/fixture.delivery-record/plugin.json';
    file_put_contents(
        $manifestPath,
        json_encode($manifest, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n",
    );
    $lock['plugins'][0]['manifest_sha256'] = hash_file('sha256', $manifestPath);
    file_put_contents(
        $fixtureRoot . '/plugins.lock',
        json_encode($lock, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n",
    );
    try {
        (new PluginLockResolver($fixtureRoot . '/server', '../plugins.lock'))->all();
        throw new RuntimeException("Plugin artifact mutation was accepted: {$expectedCode}");
    } catch (PluginLifecycleException $exception) {
        pluginArtifactExpect($exception->errorCode === $expectedCode, "Unexpected rejection: {$exception->errorCode}");
    } finally {
        $cleanup = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($fixtureRoot, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($cleanup as $entry) {
            $entry->isDir() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
        }
        rmdir($fixtureRoot);
    }
}

$serverRoot = dirname(__DIR__, 2);
$repoRoot = dirname($serverRoot);
$descriptor = (new PluginLockResolver($serverRoot, '../plugins.lock'))->require('fixture.delivery-record');
pluginArtifactExpect($descriptor->key === 'fixture.delivery-record', 'locked Plugin did not resolve');
pluginArtifactExpect(array_keys($descriptor->moduleRoots) === ['fixture.delivery-record'], 'Module root identity changed');
$articleDescriptor = (new PluginLockResolver($serverRoot, '../plugins.lock'))->require('official.article');
pluginArtifactExpect($articleDescriptor->key === 'official.article', 'official Article Plugin did not resolve');
pluginArtifactExpect(
    array_keys($articleDescriptor->moduleRoots) === ['official.article'],
    'official Article Module root identity changed',
);
$resolver = new PluginLockResolver($serverRoot, '../plugins.lock');
$sameJson = \Closure::bind(
    fn(mixed $left, mixed $right): bool => $this->sameJson($left, $right),
    $resolver,
    PluginLockResolver::class,
);
pluginArtifactExpect(is_callable($sameJson), 'Plugin manifest comparator is unavailable');
pluginArtifactExpect($sameJson(
    [
        ['key' => 'fixture.alpha', 'root' => 'server/app/modules/fixture/alpha'],
        ['key' => 'fixture.beta', 'root' => 'server/app/modules/fixture/beta'],
    ],
    [
        ['root' => 'server/app/modules/fixture/beta', 'key' => 'fixture.beta'],
        ['root' => 'server/app/modules/fixture/alpha', 'key' => 'fixture.alpha'],
    ],
), 'multi-Module manifest comparison depends on declaration order');
pluginArtifactExpect(!$sameJson(
    [['key' => 'fixture.alpha', 'root' => 'server/app/modules/fixture/alpha']],
    [['key' => 'fixture.alpha', 'root' => 'server/app/modules/fixture/changed']],
), 'multi-Module manifest comparison ignored a changed root');

$validator = new Validator();
$schema = json_decode((string) file_get_contents($serverRoot . '/resources/schemas/plugin.schema.json'));
$manifest = json_decode((string) file_get_contents($repoRoot . '/plugins/fixture.delivery-record/plugin.json'));
$validation = $validator->validate($manifest, $schema);
if (!$validation->isValid()) {
    throw new RuntimeException(
        'plugin.json schema failed: ' . json_encode((new ErrorFormatter())->format($validation->error())),
    );
}
$articlePluginManifest = json_decode(
    (string) file_get_contents($repoRoot . '/plugins/official.article/plugin.json'),
);
$articleValidation = $validator->validate($articlePluginManifest, $schema);
if (!$articleValidation->isValid()) {
    throw new RuntimeException(
        'official article plugin.json schema failed: '
        . json_encode((new ErrorFormatter())->format($articleValidation->error())),
    );
}

pluginArtifactRejects($repoRoot, static function (array &$lock, array &$manifest): void {
    $lock['plugins'][0]['source']['sha256'] = str_repeat('0', 64);
    $manifest['source']['sha256'] = str_repeat('0', 64);
}, 'PLUGIN_ARTIFACT_MISMATCH');
pluginArtifactRejects($repoRoot, static function (array &$lock, array &$manifest): void {
    $lock['plugins'][0]['composer'][0]['sha256'] = str_repeat('0', 64);
    $manifest['composer'][0]['sha256'] = str_repeat('0', 64);
}, 'PLUGIN_ARTIFACT_MISMATCH');
pluginArtifactRejects($repoRoot, static function (array &$lock, array &$manifest): void {
    $lock['plugins'][0]['npm'][0]['integrity'] = 'sha256-' . base64_encode(str_repeat("\0", 32));
    $manifest['npm'][0]['integrity'] = $lock['plugins'][0]['npm'][0]['integrity'];
}, 'PLUGIN_ARTIFACT_MISMATCH');
pluginArtifactRejects($repoRoot, static function (array &$lock, array &$manifest): void {
    $lock['plugins'][0]['frontend'][0]['sha256'] = str_repeat('0', 64);
    $manifest['frontend'][0]['sha256'] = str_repeat('0', 64);
}, 'PLUGIN_ARTIFACT_MISMATCH');

echo "PLUGIN-ARTIFACT-CONTRACT-001 passed\n";
