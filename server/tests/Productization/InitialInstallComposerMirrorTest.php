<?php

declare(strict_types=1);

use app\platform\infrastructure\module\ModuleArtifactWriter;

function initialInstallMirrorCopy(string $source, string $target): void
{
    if (is_link($source)) {
        throw new RuntimeException('TEST_MIRROR_INPUT_NOT_ORDINARY');
    }
    if (!is_dir($source)) {
        if (!copy($source, $target)) {
            throw new RuntimeException('TEST_MIRROR_COPY_FAILED');
        }
        return;
    }
    if (!mkdir($target, 0700, true)) {
        throw new RuntimeException('TEST_MIRROR_DIRECTORY_FAILED');
    }
    foreach (new FilesystemIterator($source) as $entry) {
        initialInstallMirrorCopy($entry->getPathname(), $target . '/' . $entry->getFilename());
    }
}

function initialInstallMirrorRemove(string $path): void
{
    if (!is_link($path) && is_dir($path)) {
        foreach (new FilesystemIterator($path) as $entry) {
            initialInstallMirrorRemove($entry->getPathname());
        }
        rmdir($path);
    } else {
        unlink($path);
    }
}

function initialInstallMirrorAssert(string $contract, string $root, int $exit, string $output): void
{
    $process = proc_open(
        [PHP_BINARY, '-d', 'display_errors=0', '-d', 'log_errors=0', '-d', 'zend.exception_ignore_args=1', $contract, $root],
        [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes,
    );
    if (!is_resource($process)) {
        throw new RuntimeException('TEST_MIRROR_PROCESS_FAILED');
    }
    fclose($pipes[0]);
    $stdout = trim((string) stream_get_contents($pipes[1]));
    $stderr = trim((string) stream_get_contents($pipes[2]));
    fclose($pipes[1]);
    fclose($pipes[2]);
    if (proc_close($process) !== $exit
        || ($exit === 0 ? $stdout !== $output || $stderr !== '' : $stderr !== $output || $stdout !== '')) {
        throw new RuntimeException('TEST_MIRROR_RESULT_MISMATCH');
    }
}

$consumer = realpath($argv[1] ?? '');
$identityPackage = 'peanut-business/peanut-identity';
$sourceRelative = '/app/modules/peanut/identity';
$mirrorRelative = '/vendor/' . $identityPackage;
if ($argc !== 2 || $consumer === false || !is_file($consumer . '/modules.lock')
    || realpath($consumer . '/server' . $mirrorRelative) !== $consumer . '/server' . $mirrorRelative
    || !is_file($consumer . '/server' . $mirrorRelative . '/module.json')) {
    throw new RuntimeException('Provide a prepared consumer with an ordinary Composer Identity mirror.');
}

$fixture = sys_get_temp_dir() . '/peanut-initial-install-mirror-' . bin2hex(random_bytes(12));
if (!mkdir($fixture, 0700)) {
    throw new RuntimeException('TEST_MIRROR_DIRECTORY_FAILED');
}
try {
    $server = $fixture . '/server';
    $native = $consumer . '/server';
    mkdir($server . '/app/modules/peanut', 0700, true);
    mkdir($server . '/vendor/peanut-business', 0700, true);
    initialInstallMirrorCopy($native . $sourceRelative, $server . $sourceRelative);
    initialInstallMirrorCopy($native . $mirrorRelative, $server . $mirrorRelative);
    initialInstallMirrorCopy($native . '/vendor/composer', $server . '/vendor/composer');
    initialInstallMirrorCopy($native . '/vendor/autoload.php', $server . '/vendor/autoload.php');
    // Reuse unchanged prepared dependencies and Host code. The source and vendor Identity trees are independent copies.
    foreach ([
        ['/app', ['modules']],
        ['/vendor', ['composer', 'autoload.php', 'peanut-business']],
        ['/vendor/peanut-business', ['peanut-identity']],
    ] as [$relative, $excluded]) {
        foreach (new FilesystemIterator($native . $relative) as $entry) {
            if (!in_array($entry->getFilename(), $excluded, true)
                && !symlink($entry->getPathname(), $server . $relative . '/' . $entry->getFilename())) {
                throw new RuntimeException('TEST_MIRROR_DEPENDENCY_LINK_FAILED');
            }
        }
    }
    require $server . '/vendor/autoload.php';
    (new ModuleArtifactWriter($server, false))->writeLock();
    $contract = dirname(__DIR__, 3) . '/scripts/check-installation-contract.php';
    initialInstallMirrorAssert($contract, $fixture, 0, 'INITIAL-INSTALL-SEED-CONTRACT passed');

    $relative = '/src/Authorization/ApiPermissionCatalog.php';
    $mirroredDependency = $server . $mirrorRelative . $relative;
    if (!is_file($mirroredDependency) || !file_put_contents($mirroredDependency, PHP_EOL, FILE_APPEND)) {
        throw new RuntimeException('TEST_MIRROR_DRIFT_FIXTURE_FAILED');
    }
    initialInstallMirrorAssert($contract, $fixture, 1, 'INSTALL_SEED_CONTRACT_SOURCE_REQUIRED');
    if (!copy($server . $sourceRelative . $relative, $mirroredDependency)) {
        throw new RuntimeException('TEST_MIRROR_DRIFT_FIXTURE_FAILED');
    }

    $metadata = $server . '/vendor/composer/installed.php';
    $nativeMetadata = file_get_contents($metadata);
    $installed = require $metadata;
    if (!isset($installed['versions'][$identityPackage]['pretty_version'])) {
        throw new RuntimeException('TEST_MIRROR_IDENTITY_METADATA_REQUIRED');
    }
    $installed['versions'][$identityPackage]['pretty_version'] = '0.0.0';
    if (!file_put_contents($metadata, '<?php return ' . var_export($installed, true) . ';' . PHP_EOL)) {
        throw new RuntimeException('TEST_MIRROR_IDENTITY_FIXTURE_FAILED');
    }
    initialInstallMirrorAssert($contract, $fixture, 1, 'INSTALL_SEED_CONTRACT_SOURCE_REQUIRED');

    $mirror = $server . $mirrorRelative;
    $alternate = $server . '/vendor/unbound-identity';
    if ($nativeMetadata === false || !file_put_contents($metadata, $nativeMetadata)
        || !rename($mirror, $alternate) || !symlink($alternate, $mirror)) {
        throw new RuntimeException('TEST_MIRROR_PATH_FIXTURE_FAILED');
    }
    initialInstallMirrorAssert($contract, $fixture, 1, 'INSTALL_SEED_CONTRACT_SOURCE_REQUIRED');

    if (file_exists($server . '/.env') || file_exists($server . '/runtime')) {
        throw new RuntimeException('TEST_MIRROR_RUNTIME_SIDE_EFFECT');
    }
    echo 'INITIAL-INSTALL-COMPOSER-MIRROR passed: native=1 drift_rejected=1 version_rejected=1 path_rejected=1' . PHP_EOL;
} finally {
    initialInstallMirrorRemove($fixture);
}
