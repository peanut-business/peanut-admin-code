<?php

declare(strict_types=1);

$roots = array_values(array_filter(array_map(
    'trim',
    explode(',', (string) env('PEANUT_MODULE_ROOTS', '')),
)));

$serverRoot = dirname(__DIR__);
$sourceRoot = dirname($serverRoot);
$serverIdentity = $serverRoot . '/.peanut/release-identity.json';
$sourceDevelopment = !file_exists($serverIdentity)
    && !is_link($serverIdentity)
    && getenv('PEANUT_INSTALLATION_SOURCE_MODE') === 'development'
    && file_exists($sourceRoot . '/.git')
    && !is_link($sourceRoot . '/.git')
    && is_file($sourceRoot . '/release-versions.json')
    && (is_file($sourceRoot . '/.peanut/application-manifest.json')
        || (is_file($sourceRoot . '/scaffold/application-template-inventory.json')
            && is_file($sourceRoot . '/scaffold/edition-profiles.json')));
$pluginLockDefault = $sourceDevelopment ? '../plugins.lock' : 'plugins.lock';

return [
    // Module roots are an explicit deployment input. An empty list keeps the
    // platform control plane available while TenantModule management fails closed.
    'roots' => $roots,
    'plugin_lock' => (string) env('PEANUT_PLUGIN_LOCK', $pluginLockDefault),
    'kernel_version' => (string) env('PEANUT_MODULE_KERNEL_VERSION', '1.0.0'),
    'registered_client_keys' => ['admin-web', 'platform-web', 'pc-web', 'uniapp'],
];
