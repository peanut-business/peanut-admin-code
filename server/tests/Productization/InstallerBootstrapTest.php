<?php

declare(strict_types=1);

use app\common\services\installation\InstallationPreflightHost;

require dirname(__DIR__, 2) . '/app/common/services/installation/InstallationPreflightHost.php';

function installerExpect(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function installerRemoveTemporaryDirectory(string $path): void
{
    if (!is_dir($path)) {
        return;
    }
    foreach (scandir($path) ?: [] as $entry) {
        if ($entry === '.' || $entry === '..') {
            continue;
        }
        $target = $path . '/' . $entry;
        if (is_dir($target)) {
            installerRemoveTemporaryDirectory($target);
        } else {
            unlink($target);
        }
    }
    rmdir($path);
}

$fixture = sys_get_temp_dir() . '/peanut-install-preflight-' . bin2hex(random_bytes(6));
$temporary = $fixture . '/server';
foreach (['vendor', 'database', 'config', 'runtime', 'public/storage', 'private/storage'] as $directory) {
    installerExpect(mkdir($temporary . '/' . $directory, 0775, true), 'unable to create preflight fixture directory');
}
installerExpect(mkdir($temporary . '/resources/schemas', 0775, true), 'unable to create Plugin schema fixture directory');
foreach (['vendor/autoload.php', 'database/init.sql', 'config/brand.json', 'resources/schemas/plugin.schema.json'] as $file) {
    installerExpect(file_put_contents($temporary . '/' . $file, "fixture\n") !== false, 'unable to create preflight fixture file');
}
foreach (['RELEASE_METADATA.json', 'plugins.lock'] as $file) {
    installerExpect(file_put_contents($fixture . '/' . $file, "fixture\n") !== false, 'unable to create release fixture file');
}

$resourceIdentity = [
    'environment' => 'production',
    'deployment_target' => 'production',
    'resource_id' => 'registered-production-database',
    'endpoint_id' => 'registered-container-endpoint',
    'consumer' => 'container',
    'host' => 'must-not-appear.example',
    'database' => 'must_not_appear',
    'user' => 'must-not-appear-user',
    'password' => 'must-not-appear-secret',
];

try {
    $host = new InstallationPreflightHost(
        $temporary,
        static fn(string $extension): bool => true,
        static fn(): array => $resourceIdentity,
        '8.3.0',
    );
    $ready = $host->inspect();
    installerExpect($ready['status'] === 'ready', 'valid preflight fixture must be ready');
    installerExpect($ready['code'] === 'INSTALL_PREFLIGHT_READY', 'ready preflight code changed');
    installerExpect(count($ready['checks']) === 7, 'preflight check set changed');
    installerExpect($ready['resource'] === array_intersect_key(
        $resourceIdentity,
        array_flip(['environment', 'deployment_target', 'resource_id', 'endpoint_id', 'consumer']),
    ), 'preflight must expose only stable resource identity fields');
    $encoded = json_encode($ready, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    foreach (['must-not-appear.example', 'must_not_appear', 'must-not-appear-user', 'must-not-appear-secret'] as $secret) {
        installerExpect(!str_contains($encoded, $secret), 'preflight leaked a resource detail');
    }

    $missingExtension = (new InstallationPreflightHost(
        $temporary,
        static fn(string $extension): bool => $extension !== 'zip',
        static fn(): array => $resourceIdentity,
        '8.3.0',
    ))->inspect();
    installerExpect($missingExtension['status'] === 'blocked', 'missing extension must block preflight');
    installerExpect($missingExtension['code'] === 'INSTALL_PREFLIGHT_BLOCKED', 'blocked preflight code changed');
    $extensionCheck = array_values(array_filter(
        $missingExtension['checks'],
        static fn(array $check): bool => $check['id'] === 'php-extensions',
    ))[0] ?? null;
    installerExpect(
        ($extensionCheck['code'] ?? null) === 'INSTALL_PHP_EXTENSIONS_MISSING',
        'missing extension reason code changed',
    );

    $resourceFailure = (new InstallationPreflightHost(
        $temporary,
        static fn(string $extension): bool => true,
        static fn(): array => throw new RuntimeException('must-not-appear-resource-error'),
        '8.3.0',
    ))->inspect();
    installerExpect($resourceFailure['status'] === 'blocked', 'invalid resource identity must block preflight');
    installerExpect($resourceFailure['resource'] === null, 'invalid resource identity must not be exposed');
    installerExpect(
        !str_contains(json_encode($resourceFailure, JSON_THROW_ON_ERROR), 'must-not-appear-resource-error'),
        'resource validation exception leaked through preflight',
    );

    $hostSource = (string) file_get_contents(
        dirname(__DIR__, 2) . '/app/common/services/installation/InstallationPreflightHost.php',
    );
    foreach (['new PDO', 'guardedConnection(', 'waitForDatabase(', 'file_put_contents(', 'touch(', 'mkdir(', 'unlink('] as $mutation) {
        installerExpect(!str_contains($hostSource, $mutation), 'preflight host must stay read-only: ' . $mutation);
    }
} finally {
    installerRemoveTemporaryDirectory($fixture);
}

echo "PC10-INSTALL-PREFLIGHT-001 passed\n";

if (in_array('--preflight-only', $_SERVER['argv'] ?? [], true)) {
    exit(0);
}

require dirname(__DIR__, 2) . '/database/install.php';

foreach (['', '12345678901'] as $weakPassword) {
    try {
        validateInitialAdminPassword($weakPassword);
        throw new RuntimeException('weak initial password must fail');
    } catch (RuntimeException $exception) {
        installerExpect(
            $exception->getMessage() === 'ADMIN_INITIAL_PASSWORD 至少 12 位',
            'weak password must fail at the installer boundary',
        );
    }
}

foreach (['123456789012', 'abcdefghijkl'] as $validPassword) {
    validateInitialAdminPassword($validPassword);
}

$website = brandWebsiteDefaults(dirname(__DIR__, 2));
installerExpect($website['name'] === 'Peanut Admin', 'installer must load the canonical brand manifest');
installerExpect($website['web_logo'] === 'brand/logo.svg', 'installer must seed canonical asset paths');

echo "PB08A-INSTALL-001 bootstrap passed\n";
