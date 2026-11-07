<?php

declare(strict_types=1);

use app\platform\exception\module\ModuleLifecycleException;
use app\common\module\ModuleMigrationRun;
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
try {
    $sameRoot = $fixtureRoot . '/same-root';
    $physical = $sameRoot . '/database/migrations';
    mkdir($physical, 0700, true);
    $source = "<?php\nthrow new RuntimeException('discovery must not execute migration PHP');\n";
    file_put_contents($physical . '/20260814050101_same.php', $source);
    symlink($physical, $sameRoot . '/manifest-alias');
    $sameManifest = ManifestDocument::fromObject($sameRoot, (object) [
        'key' => 'fixture.same-root',
        'backend' => ['migrations' => 'database/migrations'],
    ]);
    $sameFiles = ModuleMigrationRun::sources(['fixture.same-root' => $sameManifest], dirname(__DIR__, 2));
    pluginMigrationDiscoveryExpect(
        count($sameFiles) === 1 && $sameFiles[0]['migration_key'] === '20260814050101'
            && $sameFiles[0]['module_key'] === 'fixture.same-root'
            && $sameFiles[0]['sha256'] === hash('sha256', $source),
        'native migration identity, ownership or source digest changed',
    );
    $aliasManifest = ManifestDocument::fromObject($sameRoot, (object) [
        'key' => 'fixture.same-root', 'backend' => ['migrations' => 'manifest-alias'],
    ]);
    try {
        ModuleMigrationRun::sources(['fixture.same-root' => $aliasManifest], dirname(__DIR__, 2));
        throw new RuntimeException('noncanonical migration alias was accepted');
    } catch (ModuleLifecycleException $exception) {
        pluginMigrationDiscoveryExpect($exception->errorCode === 'MODULE_MIGRATION_PATH_INVALID', 'migration alias error changed');
    }

    $differentRoot = $fixtureRoot . '/different-roots';
    mkdir($differentRoot . '/database/migrations', 0700, true);
    file_put_contents($differentRoot . '/database/migrations/20260814050101_duplicate.php', $source);
    $differentManifest = ManifestDocument::fromObject($differentRoot, (object) [
        'key' => 'fixture.different-roots',
        'backend' => ['migrations' => 'database/migrations'],
    ]);
    try {
        ModuleMigrationRun::sources([
            'fixture.same-root' => $sameManifest, 'fixture.different-roots' => $differentManifest,
        ], dirname(__DIR__, 2));
        throw new RuntimeException('different physical roots with the same migration key were accepted');
    } catch (ModuleLifecycleException $exception) {
        pluginMigrationDiscoveryExpect(
            $exception->errorCode === 'MODULE_MIGRATION_IDENTITY_CONFLICT'
                && str_contains($exception->getMessage(), 'Duplicate migration version or class'),
            'different physical roots did not fail closed on a duplicate migration key',
        );
    }
} finally {
    pluginMigrationDiscoveryRemove($fixtureRoot);
}

pluginMigrationDiscoveryExpect(!file_exists($fixtureRoot), 'migration discovery fixture cleanup failed');
echo "PLUGIN-MIGRATION-DISCOVERY-001 passed\n";
