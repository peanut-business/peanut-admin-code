<?php

declare(strict_types=1);

use app\platform\service\plugin\PluginDescriptor;
use app\platform\service\plugin\PluginLifecycleService;

require dirname(__DIR__, 2) . '/vendor/autoload.php';

function pluginIdentityExpect(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$descriptor = new PluginDescriptor(
    'fixture.identity',
    '1.2.3',
    ['type' => 'canonical-contents', 'reference' => 'fixture', 'sha256' => str_repeat('a', 64)],
    str_repeat('b', 64),
    str_repeat('c', 64),
    [['name' => 'fixture/composer', 'version' => '1.2.3', 'sha256' => str_repeat('d', 64)]],
    [['name' => '@fixture/npm', 'version' => '1.2.3', 'integrity' => 'sha256-fixture']],
    [[
        'client_key' => 'admin-web',
        'package' => '@fixture/npm',
        'version' => '1.2.3',
        'entry' => 'fixture.ts',
        'sha256' => str_repeat('e', 64),
    ]],
    ['fixture.identity' => '/fixture'],
);

$current = [
    'installed_version' => '1.2.3',
    'artifact_sha256' => str_repeat('a', 64),
    'lock_digest' => str_repeat('b', 64),
    // MySQL JSON display order deliberately differs from the locked descriptor.
    'composer_identity_json' => json_encode([[
        'sha256' => str_repeat('d', 64), 'version' => '1.2.3', 'name' => 'fixture/composer',
    ]], JSON_THROW_ON_ERROR),
    'npm_identity_json' => json_encode([[
        'integrity' => 'sha256-fixture', 'version' => '1.2.3', 'name' => '@fixture/npm',
    ]], JSON_THROW_ON_ERROR),
    'frontend_identity_json' => json_encode([[
        'sha256' => str_repeat('e', 64), 'entry' => 'fixture.ts', 'version' => '1.2.3',
        'package' => '@fixture/npm', 'client_key' => 'admin-web',
    ]], JSON_THROW_ON_ERROR),
];

$service = (new ReflectionClass(PluginLifecycleService::class))->newInstanceWithoutConstructor();
$sameIdentity = new ReflectionMethod(PluginLifecycleService::class, 'sameIdentity');
pluginIdentityExpect(
    $sameIdentity->invoke($service, $descriptor, $current) === true,
    'semantically identical persisted JSON identity was not unchanged',
);

$drifts = [
    'version' => ['installed_version', '1.2.4'],
    'artifact' => ['artifact_sha256', str_repeat('f', 64)],
    'lock' => ['lock_digest', str_repeat('f', 64)],
];
foreach ($drifts as $name => [$field, $value]) {
    $drifted = $current;
    $drifted[$field] = $value;
    pluginIdentityExpect(
        $sameIdentity->invoke($service, $descriptor, $drifted) === false,
        "{$name} identity drift was treated as unchanged",
    );
}

foreach (['composer_identity_json', 'npm_identity_json', 'frontend_identity_json'] as $field) {
    $drifted = $current;
    $decoded = json_decode((string) $drifted[$field], true, 64, JSON_THROW_ON_ERROR);
    $decoded[0][array_key_first($decoded[0])] = 'drifted';
    $drifted[$field] = json_encode($decoded, JSON_THROW_ON_ERROR);
    pluginIdentityExpect(
        $sameIdentity->invoke($service, $descriptor, $drifted) === false,
        "{$field} drift was treated as unchanged",
    );
}

$installSource = (string) file_get_contents(
    dirname(__DIR__, 2) . '/app/platform/service/plugin/PluginLifecycleService.php',
);
pluginIdentityExpect(
    str_contains($installSource, "\$current['status'] === 'active'"),
    'unchanged identity no longer requires an active installation state',
);

echo "PLUGIN-IDENTITY-001 passed\n";
