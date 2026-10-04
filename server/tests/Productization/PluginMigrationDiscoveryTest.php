<?php

declare(strict_types=1);

use app\platform\service\plugin\PluginLifecycleException;
use app\platform\service\plugin\PluginLifecycleService;
use PeanutAdmin\Kernel\Module\ManifestDocument;

require dirname(__DIR__, 2) . '/vendor/autoload.php';

function pluginMigrationDiscoveryExpect(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function pluginMigrationDiscoveryRemove(string $path): void
{
    if (is_link($path) || is_file($path)) {
        unlink($path);
        return;
    }
    if (!is_dir($path)) {
        return;
    }
    foreach (scandir($path) ?: [] as $entry) {
        if ($entry !== '.' && $entry !== '..') {
            pluginMigrationDiscoveryRemove($path . '/' . $entry);
        }
    }
    rmdir($path);
}

$fixtureRoot = sys_get_temp_dir() . '/pa-plugin-migration-discovery-' . bin2hex(random_bytes(8));
mkdir($fixtureRoot, 0700, true);
$service = (new ReflectionClass(PluginLifecycleService::class))->newInstanceWithoutConstructor();
$discover = new ReflectionMethod(PluginLifecycleService::class, 'migrationFiles');
$identity = new ReflectionMethod(PluginLifecycleService::class, 'migrationDirectoryIdentity');

try {
    $sameRoot = $fixtureRoot . '/same-root';
    $physical = $sameRoot . '/physical-migrations';
    mkdir($physical, 0700, true);
    file_put_contents($physical . '/20260814050101_same.sql', "SELECT 1;\n");
    mkdir($sameRoot . '/Database', 0700, true);
    symlink($physical, $sameRoot . '/manifest-alias');
    symlink($physical, $sameRoot . '/Database/Migrations');

    pluginMigrationDiscoveryExpect(
        $identity->invoke(null, $sameRoot . '/manifest-alias')
            === $identity->invoke(null, $sameRoot . '/Database/Migrations'),
        'directory aliases did not resolve to one device/inode identity',
    );
    $sameManifest = ManifestDocument::fromArray($sameRoot, [
        'key' => 'fixture.same-root',
        'backend' => ['migrations' => 'manifest-alias'],
    ]);
    $sameFiles = $discover->invoke($service, $sameRoot, $sameManifest);
    pluginMigrationDiscoveryExpect(
        array_keys($sameFiles) === ['fixture.same-root:20260814050101_same'],
        'the same physical migration directory was traversed more than once',
    );

    $differentRoot = $fixtureRoot . '/different-roots';
    mkdir($differentRoot . '/first', 0700, true);
    mkdir($differentRoot . '/Database/Migrations', 0700, true);
    file_put_contents($differentRoot . '/first/20260814050102_duplicate.sql', "SELECT 1;\n");
    file_put_contents($differentRoot . '/Database/Migrations/20260814050102_duplicate.sql', "SELECT 2;\n");
    $differentManifest = ManifestDocument::fromArray($differentRoot, [
        'key' => 'fixture.different-roots',
        'backend' => ['migrations' => 'first'],
    ]);
    try {
        $discover->invoke($service, $differentRoot, $differentManifest);
        throw new RuntimeException('different physical roots with the same migration key were accepted');
    } catch (PluginLifecycleException $exception) {
        pluginMigrationDiscoveryExpect(
            $exception->errorCode === 'MODULE_MIGRATION_INVALID'
                && str_contains($exception->getMessage(), 'Duplicate migration key'),
            'different physical roots did not fail closed on a duplicate migration key',
        );
    }
} finally {
    pluginMigrationDiscoveryRemove($fixtureRoot);
}

pluginMigrationDiscoveryExpect(!file_exists($fixtureRoot), 'migration discovery fixture cleanup failed');
echo "PLUGIN-MIGRATION-DISCOVERY-001 passed\n";
