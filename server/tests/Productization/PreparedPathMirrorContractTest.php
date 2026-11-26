<?php

declare(strict_types=1);

use app\common\module\Dependencies;
use app\platform\exception\module\ModulePackageException;

require_once dirname(__DIR__, 2) . '/app/platform/exception/module/ModulePackageException.php';
require_once dirname(__DIR__, 2) . '/app/common/module/Dependencies.php';

/** Isolated fake Composer path mirror: checks real code rather than a lock/version snapshot. */
$root = sys_get_temp_dir() . '/peanut-prepared-path-mirror-' . bin2hex(random_bytes(9));
$server = $root . '/server';
$source = $server . '/app/modules/acme/demo';
$target = $server . '/vendor/acme/demo';
$mkdir = static function (string $path): void {
    if (!mkdir($path, 0700, true) && !is_dir($path)) {
        throw new RuntimeException('MIRROR_TEST_DIRECTORY_FAILED');
    }
};
$remove = static function (string $path) use (&$remove): void {
    if (is_dir($path) && !is_link($path)) {
        foreach (new FilesystemIterator($path) as $item) {
            $remove($item->getPathname());
        }
        if (!rmdir($path)) {
            throw new RuntimeException('MIRROR_TEST_CLEANUP_FAILED');
        }
    } elseif (file_exists($path) || is_link($path)) {
        if (!unlink($path)) {
            throw new RuntimeException('MIRROR_TEST_CLEANUP_FAILED');
        }
    }
};
$mkdir($source . '/src');
$mkdir($target . '/src');
try {
    file_put_contents($source . '/composer.json', '{"name":"acme/demo","version":"1.0.0"}');
    file_put_contents($source . '/src/Service.php', '<?php return "new";');
    file_put_contents($target . '/src/Service.php', '<?php return "new";');
    $repositories = ['repositories' => [[
        'type' => 'path',
        'url' => 'app/modules/acme/demo',
        'options' => ['symlink' => false],
    ]]];
    $method = new ReflectionMethod(Dependencies::class, 'assertPreparedPathMirrors');
    $check = static fn(?array $composer = null): mixed => $method->invoke(
        new Dependencies($server), ['acme.demo' => $source], $composer ?? $repositories,
    );
    $assertions = 0;
    $passes = static function (bool $condition, string $name) use (&$assertions): void {
        ++$assertions;
        if (!$condition) throw new RuntimeException('MIRROR_TEST_' . $name);
    };
    $reject = static function (string $name) use ($check, $passes): void {
        try {
            $check();
        } catch (ModulePackageException $exception) {
            $passes($exception->getMessage() !== '', $name);
            return;
        }
        throw new RuntimeException('MIRROR_TEST_EXPECTED_REJECTION_' . $name);
    };
    $check();
    $passes(true, 'original_mirror_valid');
    file_put_contents($source . '/src/Service.php', '<?php return "revised";');
    $reject('changed_existing_file');
    file_put_contents($target . '/src/Service.php', '<?php return "revised";');
    $check();
    $passes(true, 'identical_reprepared');
    file_put_contents($source . '/src/Additional.php', '<?php return 1;');
    $reject('missing_in_installed_vendor');
    file_put_contents($target . '/src/Additional.php', '<?php return 1;');
    $check();
    $passes(true, 'all_sources_present');
    file_put_contents($target . '/src/Old.php', '<?php return 1;');
    $reject('stale_vendor_extra_file');
    unlink($target . '/src/Old.php');
    unlink($target . '/src/Service.php');
    symlink($source . '/src/Service.php', $target . '/src/Service.php');
    $reject('symlink_in_mirror');
    unlink($target . '/src/Service.php');
    file_put_contents($target . '/src/Service.php', '<?php return "revised";');
    $check(['repositories' => []]);
    $passes(true, 'non_path_repository_does_not_force_mirror');
    echo 'PREPARED-PATH-MIRROR-001 passed; assertions=' . $assertions . PHP_EOL;
} finally {
    $remove($root);
}
