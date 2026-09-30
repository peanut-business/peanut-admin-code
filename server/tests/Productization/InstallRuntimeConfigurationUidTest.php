<?php

declare(strict_types=1);

use app\common\services\installation\InstallationConfigurationHost;

require dirname(__DIR__, 2) . '/app/common/exception/installation/InstallationExecutionException.php';
require dirname(__DIR__, 2) . '/app/common/services/installation/InstallationConfigurationHost.php';

$root = getenv('PEANUT_TEST_ROOT');
if (!is_string($root) || !is_dir($root) || is_link($root)
    || preg_match('#^/(?:var/)?tmp/(?:[^/]+/)?phase1-c-[^/]+/server$#D', $root) !== 1) {
    throw new RuntimeException('A synthetic task-local server root is required.');
}
$identity = static fn(string $path): array => [
    'application' => [
        'slug' => 'permission-fixture',
        'edition' => 'standalone',
        'version' => '0.1.0',
        'package_identity' => 'permission-fixture/application',
        'name' => 'Permission Fixture',
    ],
    'versions' => [
        'source_product_version' => '4.0.0-rc.1',
        'release_sequence_version' => '0.1.0',
        'scaffold_template' => '4.0.0-rc.1',
    ],
];
$host = new InstallationConfigurationHost($root, $identity, static fn(): string => str_repeat('a', 64));
$token = str_repeat('t', 64);
$result = $host->configure($token, $token, ['deployment_target' => 'local-production-preview']);
if (($result['state'] ?? null) !== 'configured' || $host->status()['state'] !== 'configured') {
    throw new RuntimeException('Native configuration did not finish under the application UID.');
}
foreach (['.env', 'private/resources/project-resources.json', 'private/resources/configuration.json'] as $relative) {
    if (!is_file($root . '/' . $relative) || (fileperms($root . '/' . $relative) & 0777) !== 0600) {
        throw new RuntimeException('Native configuration must create mode-0600 files.');
    }
}
echo "NATIVE-CONFIGURATION-APPLICATION-UID passed; no database connection\n";
