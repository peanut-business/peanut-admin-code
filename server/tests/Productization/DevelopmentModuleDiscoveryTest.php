<?php

declare(strict_types=1);

use app\platform\infrastructure\module\DevelopmentModuleDiscovery;
use app\platform\exception\module\ModuleLifecycleException;
use app\common\infrastructure\module\ModuleHostLayoutFactory;
use PeanutAdmin\Kernel\Module\ModuleKey;

$projectRoot = realpath($argv[1] ?? dirname(__DIR__, 3));
if ($projectRoot === false) { throw new RuntimeException('Prepared application root is unavailable'); }
require $projectRoot . '/server/vendor/autoload.php';

function developmentDiscoveryExpect(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function developmentDiscoveryRemoveTree(string $path): void
{
    if (!is_dir($path)) {
        return;
    }
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST,
    );
    foreach ($iterator as $entry) {
        $entry->isDir() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
    }
    rmdir($path);
}

$first = (new DevelopmentModuleDiscovery($projectRoot))->moduleRoots();
$second = (new DevelopmentModuleDiscovery($projectRoot))->moduleRoots();
developmentDiscoveryExpect($first === $second, 'development Module discovery is not deterministic');
developmentDiscoveryExpect(isset($first['peanut.identity']), 'peanut.identity was not discovered without modules.lock');
developmentDiscoveryExpect(
    $first['peanut.identity'] === $projectRoot . '/' . rtrim(ModuleHostLayoutFactory::pathLayout()
        ->backendRelativePath(ModuleKey::fromString('peanut.identity')), '/'),
    'peanut.identity backend path was not derived from its key',
);
$manifestCount = iterator_count(new CallbackFilterIterator(
    new RecursiveIteratorIterator(new RecursiveDirectoryIterator($projectRoot . '/server/app/modules', FilesystemIterator::SKIP_DOTS)),
    static fn(SplFileInfo $entry): bool => $entry->isFile() && $entry->getFilename() === 'module.json',
));
developmentDiscoveryExpect(count($first) === $manifestCount, 'development Module discovery omitted a module.json');

$temporary = sys_get_temp_dir() . '/pa-development-module-discovery-' . bin2hex(random_bytes(8));
$wrongRoot = $temporary . '/server/app/modules/wrong/location';
mkdir($wrongRoot, 0700, true);
file_put_contents($wrongRoot . '/module.json', json_encode([
    'key' => 'fixture.invalid-path',
], JSON_THROW_ON_ERROR));
try {
    (new DevelopmentModuleDiscovery($temporary))->moduleRoots();
    throw new RuntimeException('non-derived development Module path was accepted');
} catch (ModuleLifecycleException $exception) {
    developmentDiscoveryExpect($exception->errorCode === 'MODULE_PATH_INVALID', 'development discovery failure code changed');
} finally {
    developmentDiscoveryRemoveTree($temporary);
}

echo 'DEVELOPMENT-MODULE-DISCOVERY-C-001 passed modules=' . count($first) . "\n";
