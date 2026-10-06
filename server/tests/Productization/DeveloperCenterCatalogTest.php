<?php

declare(strict_types=1);

use app\platform\services\developer\DeveloperCenterCatalogService;

require dirname(__DIR__, 2) . '/vendor/autoload.php';

function developerCenterExpect(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$serverRoot = dirname(__DIR__, 2);
$configuration = [
    'roots' => [],
    'plugin_lock' => '../plugins.lock',
    'kernel_version' => '1.0.0',
    'registered_client_keys' => ['admin-web', 'platform-web'],
];
$registry = (new \app\platform\composition\plugin\ModuleDefinitionRegistryFactory($serverRoot))->fromPluginLock(
    new \app\platform\infrastructure\plugin\PluginLockResolver($serverRoot, $configuration['plugin_lock']),
    $configuration,
);
$service = new DeveloperCenterCatalogService($serverRoot, $registry);
$first = $service->snapshot('official.article');
$second = $service->snapshot('official.article');

developerCenterExpect($first['read_only'] === true, 'developer catalog is not explicitly read-only');
developerCenterExpect(
    ($first['status']['registration']['revision'] ?? null) === $registry->revision
    && ($second['status']['registration']['revision'] ?? null) === $registry->revision,
    'developer catalog must use the injected App registry across read-only projections',
);
developerCenterExpect(
    ($first['status']['api_catalog']['status'] ?? null) === 'available',
    'generated API catalog inputs are missing or stale',
);
developerCenterExpect(count($first['modules']) === 1, 'exact Module filtering changed');
$module = $first['modules'][0];
developerCenterExpect($module['key'] === 'official.article', 'wrong Module returned');
developerCenterExpect(count($module['routes']) > 0, 'route registry was not joined to the Module');
developerCenterExpect(count($module['generated_api']) > 0, 'generated API catalog was not joined to the Module');
developerCenterExpect(count($module['permissions']) > 0, 'permission catalog was not loaded from the manifest source');
developerCenterExpect(count($module['public_services']) > 0, 'public service declarations are missing');
developerCenterExpect(($module['provider']['implements_module_provider'] ?? false) === true, 'Module Provider contract was not inspected');
developerCenterExpect($module['evidence']['runtime_effective']['status'] === 'not_checked', 'runtime validity was inferred without a probe');
developerCenterExpect($module['evidence']['tests']['status'] === 'not_recorded', 'test success was inferred without a receipt');
developerCenterExpect($module['package_preview']['status'] === 'ready', 'package preview did not reuse the pack contract');
developerCenterExpect(
    $module['package_preview']['inventory_sha256'] === $second['modules'][0]['package_preview']['inventory_sha256'],
    'package preview is not deterministic',
);
foreach ($module['package_preview']['files'] as $file) {
    $path = strtolower((string) $file['path']);
    developerCenterExpect(!str_contains($path, '/vendor/'), 'package preview included vendor');
    developerCenterExpect(!str_contains($path, '/node_modules/'), 'package preview included node_modules');
    developerCenterExpect(!str_contains(basename($path), '.env'), 'package preview included an environment file');
}

foreach (['official.identity', 'peanut.artifact-revision', 'peanut.entitlement-quota', 'peanut.workflow'] as $backendOnlyKey) {
    $backendOnly = $service->snapshot($backendOnlyKey)['modules'][0] ?? null;
    developerCenterExpect(is_array($backendOnly), 'backend-only Module was not discovered: ' . $backendOnlyKey);
    developerCenterExpect(
        ($backendOnly['package_preview']['status'] ?? null) === 'ready',
        'backend-only Module package preview is blocked: ' . $backendOnlyKey,
    );
    $frontendFiles = array_filter(
        $backendOnly['package_preview']['files'] ?? [],
        static fn(array $file): bool => str_starts_with((string) ($file['path'] ?? ''), 'frontend/'),
    );
    developerCenterExpect($frontendFiles === [], 'backend-only preview invented frontend files: ' . $backendOnlyKey);
}

$ops = $service->snapshot('official.ops')['modules'][0] ?? null;
developerCenterExpect(is_array($ops), 'official.ops was not discovered');
developerCenterExpect(
    ($ops['source']['frontend_clients'][0]['client_key'] ?? null) === 'platform-web'
        && ($ops['source']['frontend_clients'][0]['entry'] ?? null) === 'platform/src/modules/official-ops/contribution.ts',
    'official.ops platform contribution is missing from the catalog',
);
developerCenterExpect(
    in_array(
        'platform/src/modules/official-ops/contribution.ts',
        array_column($ops['package_preview']['files'] ?? [], 'path'),
        true,
    ),
    'official.ops package preview omitted the platform contribution',
);

$routes = (string) file_get_contents($serverRoot . '/route/platform.php');
$command = (string) file_get_contents($serverRoot . '/app/command/DeveloperCenterCatalog.php');
developerCenterExpect(
    str_contains($routes, "Route::get('developer-center/catalog'")
        && str_contains($routes, "PlatformPermissionMiddleware::class, 'platform.module.read'")
        && !str_contains($routes, "Route::post('developer-center/"),
    'developer center HTTP boundary is not read-only and permission guarded',
);
developerCenterExpect(
    str_contains($command, 'DeveloperCenterCatalogService') && str_contains($command, '->snapshot('),
    'CLI does not use the shared developer catalog service',
);

try {
    $service->snapshot('../official.article');
    throw new RuntimeException('invalid Module key was accepted');
} catch (InvalidArgumentException) {
    // Expected: no arbitrary path reaches discovery or package tooling.
}

$fixture = dirname($serverRoot) . '/.local/tmp/developer-catalog-input-' . bin2hex(random_bytes(6));
mkdir($fixture . '/server/config', 0700, true);
file_put_contents($fixture . '/release-versions.json', "{\"source_product_version\":\"5.0.1\"}\n");
file_put_contents($fixture . '/server/config/input.php', "<?php\n");
$inputs = [
    ['path' => 'release-versions.json', 'sha256' => hash_file('sha256', $fixture . '/release-versions.json')],
    ['path' => 'server/config/input.php', 'sha256' => hash_file('sha256', $fixture . '/server/config/input.php')],
];
$freshness = new ReflectionMethod(DeveloperCenterCatalogService::class, 'apiCatalogFreshness');
$fixtureService = new DeveloperCenterCatalogService($fixture . '/server', $registry);
try {
    developerCenterExpect(
        $freshness->invoke($fixtureService, $inputs) === null,
        'the canonical generator root version input and server inputs must be accepted together',
    );
    foreach (['other-root.json', 'server/../release-versions.json', 'server//config/input.php'] as $invalidPath) {
        developerCenterExpect(
            $freshness->invoke($fixtureService, [['path' => $invalidPath, 'sha256' => str_repeat('a', 64)]]) !== null,
            'undeclared or noncanonical API input path was accepted',
        );
    }
    developerCenterExpect(
        $freshness->invoke($fixtureService, array_reverse($inputs)) !== null,
        'catalog input ordering must remain canonical',
    );
    developerCenterExpect(
        $freshness->invoke($fixtureService, [$inputs[0], $inputs[0]]) !== null,
        'duplicate catalog source identities must be rejected',
    );
    mkdir($fixture . '/server/.peanut', 0700);
    copy($fixture . '/release-versions.json', $fixture . '/server/.peanut/release-versions.json');
    file_put_contents($fixture . '/server/.peanut/release-identity.json', "{}\n");
    unlink($fixture . '/release-versions.json');
    developerCenterExpect(
        $freshness->invoke($fixtureService, $inputs) === null,
        'a Server release must use its exact canonical version-source projection',
    );
    file_put_contents($fixture . '/server/.peanut/release-versions.json', "{}\n");
    developerCenterExpect(
        $freshness->invoke($fixtureService, $inputs) !== null,
        'a changed Server version projection must invalidate the catalog',
    );
    unlink($fixture . '/server/.peanut/release-versions.json');
    file_put_contents($fixture . '/release-versions.json', "{\"source_product_version\":\"5.0.1\"}\n");
    developerCenterExpect(
        $freshness->invoke($fixtureService, $inputs) !== null,
        'a missing Server projection must not fall back to an unrelated root version file',
    );
    unlink($fixture . '/server/.peanut/release-identity.json');
    rmdir($fixture . '/server/.peanut');
    file_put_contents($fixture . '/release-versions.json', "{}\n");
    developerCenterExpect(
        $freshness->invoke($fixtureService, $inputs) !== null,
        'changing the root version source must invalidate the catalog',
    );
    unlink($fixture . '/server/config/input.php');
    symlink($fixture . '/release-versions.json', $fixture . '/server/config/input.php');
    developerCenterExpect(
        str_contains((string) $freshness->invoke($fixtureService, [$inputs[1]]), 'linked'),
        'links must not bypass API source freshness checks',
    );
} finally {
    unlink($fixture . '/server/config/input.php');
    unlink($fixture . '/release-versions.json');
    rmdir($fixture . '/server/config');
    rmdir($fixture . '/server');
    rmdir($fixture);
}

echo 'DEVELOPER-CENTER-CATALOG-R6-001 passed routes=' . count($module['routes'])
    . ' api=' . count($module['generated_api'])
    . ' preview_files=' . $module['package_preview']['file_count'] . "\n";
