<?php

declare(strict_types=1);

use app\common\exception\installation\InstallationExecutionException;
use app\common\services\installation\InstallationConfigurationHost;

require_once dirname(__DIR__, 2) . '/app/common/exception/installation/InstallationExecutionException.php';
require_once dirname(__DIR__, 2) . '/app/common/services/installation/InstallationConfigurationHost.php';

function installationConfigExpect(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function installationConfigDelete(string $path): void
{
    if (is_dir($path) && !is_link($path)) {
        foreach (array_diff(scandir($path) ?: [], ['.', '..']) as $entry) {
            installationConfigDelete($path . '/' . $entry);
        }
        rmdir($path);
        return;
    }
    if (file_exists($path) || is_link($path)) {
        unlink($path);
    }
}

/** @return array<string,mixed> */
function installationConfigIdentity(string $edition): array
{
    return [
        'application' => [
            'slug' => 'fixture-app',
            'edition' => $edition,
            'version' => '1.2.3',
            'package_identity' => 'fixture-app/application',
            'name' => 'Fixture App',
        ],
        'versions' => [
            'source_product_version' => '4.0.0-rc.10',
            'release_sequence_version' => '1.2.3',
            'scaffold_template' => '4.0.0-rc.10',
        ],
    ];
}

$root = dirname(__DIR__, 3) . '/.local/tmp';
if (!is_dir($root) && !mkdir($root, 0700, true) && !is_dir($root)) {
    throw new RuntimeException('cannot create checkout-local temporary root');
}
$fixture = $root . '/installation-configuration-' . bin2hex(random_bytes(6));
mkdir($fixture . '/standalone/private/installation', 0700, true);
mkdir($fixture . '/multi/private/installation', 0700, true);
$token = str_repeat('t', 64);
$secret = str_repeat('a', 64);

try {
    $standalone = new InstallationConfigurationHost(
        $fixture . '/standalone',
        static fn(string $serverRoot): array => installationConfigIdentity('standalone'),
        static fn(): string => $secret,
    );
    $status = $standalone->status();
    installationConfigExpect($status['state'] === 'unconfigured', 'fresh server must require configuration');

    try {
        $standalone->configure(str_repeat('x', 64), $token, ['deployment_target' => 'local-production-preview']);
        throw new RuntimeException('invalid setup token was accepted');
    } catch (InstallationExecutionException $exception) {
        installationConfigExpect($exception->errorCode === 'INSTALL_SETUP_TOKEN_INVALID', 'wrong token must fail closed');
    }

    $configured = $standalone->configure($token, $token, [
        'deployment_target' => 'local-production-preview',
        'database_name' => 'fixture_app',
    ]);
    installationConfigExpect($configured['state'] === 'configured', 'standalone configuration must complete');
    $envPath = $fixture . '/standalone/.env';
    $registryPath = $fixture . '/standalone/private/resources/project-resources.json';
    installationConfigExpect(is_file($envPath) && !is_link($envPath), 'final server env must exist');
    installationConfigExpect((fileperms($envPath) & 0777) === 0600, 'final server env must use mode 0600');
    installationConfigExpect(is_file($registryPath) && !is_link($registryPath), 'private instance registry must exist');
    installationConfigExpect((fileperms($registryPath) & 0777) === 0600, 'private registry must use mode 0600');

    $env = (string) file_get_contents($envPath);
    installationConfigExpect(str_contains($env, "DEPLOYMENT_MODE=standalone\n"), 'edition must come from release identity');
    installationConfigExpect(str_contains($env, "DB_HOST=mysql\nDB_PORT=3306\n"), 'bundled DB endpoint must be fixed');
    installationConfigExpect(str_contains($env, "DB_NAME=fixture_app\n"), 'chosen DB name must be stored');
    installationConfigExpect(str_contains($env, "DB_PASS={$secret}\n"), 'generated business password must be stored only in env');
    installationConfigExpect(!str_contains($env, 'PEANUT_INSTALLATION_SETUP_TOKEN='), 'setup token must not persist in final env');
    installationConfigExpect(!str_contains($env, 'MYSQL_ROOT_PASSWORD'), 'MySQL root secret must not enter app env');

    $registryBytes = (string) file_get_contents($registryPath);
    installationConfigExpect(!str_contains($registryBytes, $secret), 'private registry must not contain database/app secrets');
    $registry = json_decode($registryBytes, true, 512, JSON_THROW_ON_ERROR);
    installationConfigExpect(($registry['project_id'] ?? null) === 'fixture-app', 'registry must bind APP slug');
    installationConfigExpect(
        ($registry['resources']['databases'][0]['container_endpoint']['host'] ?? null) === 'mysql',
        'registry must bind bundled MySQL endpoint',
    );

    try {
        $standalone->configure($token, $token, ['deployment_target' => 'local-production-preview']);
        throw new RuntimeException('repeat first configuration was accepted');
    } catch (InstallationExecutionException $exception) {
        installationConfigExpect($exception->errorCode === 'INSTALL_CONFIGURATION_ALREADY_PRESENT', 'repeat configuration must fail closed');
    }

    $multi = new InstallationConfigurationHost(
        $fixture . '/multi',
        static fn(string $serverRoot): array => installationConfigIdentity('multi-tenant'),
        static fn(): string => $secret,
    );
    try {
        $multi->configure($token, $token, ['deployment_target' => 'production-candidate']);
        throw new RuntimeException('multi-tenant configuration without hosts was accepted');
    } catch (InstallationExecutionException $exception) {
        installationConfigExpect($exception->errorCode === 'INSTALL_CONFIGURATION_HOSTS_REQUIRED', 'multi-tenant hosts must be required');
    }
    $multiResult = $multi->configure($token, $token, [
        'deployment_target' => 'production-candidate',
        'platform_hosts' => 'pa-platform.example.test',
        'tenant_admin_hosts' => 'pa-admin.example.test',
    ]);
    installationConfigExpect($multiResult['state'] === 'configured', 'multi-tenant configuration with hosts must complete');
    $multiEnv = (string) file_get_contents($fixture . '/multi/.env');
    installationConfigExpect(str_contains($multiEnv, "PLATFORM_HOSTS=pa-platform.example.test\n"), 'platform hosts must persist');
    installationConfigExpect(str_contains($multiEnv, "TENANT_ADMIN_HOSTS=pa-admin.example.test\n"), 'tenant admin hosts must persist');

    echo "INSTALLATION-CONFIGURATION-HOST passed\n";
} finally {
    installationConfigDelete($fixture);
}
