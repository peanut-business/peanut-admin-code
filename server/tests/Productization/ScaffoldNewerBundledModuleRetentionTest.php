<?php

declare(strict_types=1);

use app\common\infrastructure\scaffold\ScaffoldUpgradeRunner;
use app\common\value\scaffold\ScaffoldManifest;

require ((getenv('PEANUT_TEST_APPLICATION_ROOT') ?: dirname(__DIR__, 3)) . '/server') . '/vendor/autoload.php';
require_once (getenv('PEANUT_TEST_APPLICATION_ROOT') ?: dirname(__DIR__, 3)) . '/scripts/scaffold-runtime/ScaffoldUpgradeRunner.php';

$root = sys_get_temp_dir() . '/peanut-newer-bundled-module-' . bin2hex(random_bytes(6));
if (!mkdir($root, 0700)) {
    throw new RuntimeException('Fixture directory unavailable');
}

try {
    file_put_contents($root . '/modules.lock', "existing-canonical-module-lock\n");
    $moduleDir = $root . '/server/app/modules/peanut/reference_codes';
    if (!mkdir($moduleDir, 0700, true)) {
        throw new RuntimeException('Module fixture unavailable');
    }
    file_put_contents($moduleDir . '/composer.json', '{"require":{}}');
    $moduleManifest = $moduleDir . '/module.json';
    $writeModule = static function (string $constraint, array $dependencies = []) use ($moduleManifest): void {
        file_put_contents($moduleManifest, json_encode([
            'key' => 'peanut.reference-codes', 'version' => '1.0.27',
            'kernel_constraint' => $constraint,
            'dependencies' => $dependencies,
        ], JSON_THROW_ON_ERROR));
    };
    $writeModule('^1.0');
    $releaseDir = $root . '/release';
    $releaseConfigDir = $releaseDir . '/files/server/config';
    if (!mkdir($releaseConfigDir, 0700, true)) {
        throw new RuntimeException('Release Kernel fixture unavailable');
    }
    $configFile = $releaseConfigDir . '/modules.php';
    file_put_contents($configFile, "<?php return ['kernel_version' => env('PEANUT_MODULE_KERNEL_VERSION', '1.0.0')];\n");
    $key = 'peanut.reference-codes';
    $identity = [
        'manifest' => 'server/app/modules/peanut/reference_codes/module.json',
        'module_keys' => [$key],
        'module_roots' => ['server/app/modules/peanut/reference_codes'],
        'frontend_roots' => ['web/src/modules/peanut-reference-codes'],
    ];
    $current = [
        'modules' => [$key => $identity + ['version' => '1.0.27']],
        'legacy_artifacts' => [],
    ];
    $target = [$key => $identity + ['version' => '1.0.26']];
    $proofs = [$key => ['frontend_roots' => $identity['frontend_roots']]];
    // The real caller has already validated the currently installed source
    // and canonical module lock. A scaffold default is not allowed to downgrade it.
    $runner = new ScaffoldUpgradeRunner();
    $releaseReflection = new ReflectionClass(ScaffoldManifest::class);
    $release = $releaseReflection->newInstanceWithoutConstructor();
    $releaseReflection->getProperty('directory')->setValue($release, $releaseDir);
    $releaseReflection->getProperty('data')->setValue($release, [
        'protocol' => 'peanut.scaffold-release.v3',
        'application' => ['version' => '1.0.0'],
        'release' => ['tokens' => [
            'product_name' => '{{PRODUCT_NAME}}', 'slug' => '{{SLUG}}',
            'package_identity' => '{{PACKAGE_IDENTITY}}',
            'application_version' => '{{APPLICATION_VERSION}}',
        ]],
        'files' => [[
            'path' => 'server/config/modules.php',
            'source' => 'files/server/config/modules.php',
            'mode' => 0644,
            'template_sha256' => hash_file('sha256', $configFile),
        ]],
    ]);
    $project = new ReflectionMethod(ScaffoldUpgradeRunner::class, 'upstreamModuleProjection');
    $parameters = [
        'PRODUCT_NAME' => 'Test', 'SLUG' => 'test',
        'PACKAGE_IDENTITY' => 'test/app', 'APPLICATION_VERSION' => '1.0.0',
    ];
    $projection = static fn(): array => $project->invoke(
        $runner, $root, $current, [], $proofs, $target, $release, $parameters, [], null,
    );
    $out = $projection();
    if ($out['keys'] !== [] || $out['projection'] !== $current
        || $out['lock_contents'] !== "existing-canonical-module-lock\n"
        || ($out['compatibility']['status'] ?? null) !== 'ready') {
        throw new RuntimeException('Newer installed module was not retained with Kernel compatibility');
    }
    $writeModule('^2.0');
    $incompatible = $projection();
    if (($incompatible['compatibility']['status'] ?? null) !== 'blocked'
        || ($incompatible['compatibility']['blockers'][0]['code'] ?? null) !== 'MODULE_KERNEL_INCOMPATIBLE') {
        throw new RuntimeException('Retention bypassed target Kernel minimum version check');
    }
    $writeModule('^1.0');

    foreach ([
        [['module_key' => 'peanut.missing', 'version' => '^1.0'], 'MODULE_PACKAGE_DEPENDENCY_MISSING'],
        [['module_key' => $key, 'version' => '^2.0'], 'MODULE_PACKAGE_DEPENDENCY_INCOMPATIBLE'],
        [['module_key' => $key, 'version' => '^1.0'], 'MODULE_PACKAGE_DEPENDENCY_CYCLE'],
    ] as [$dependency, $errorCode]) {
        $writeModule('^1.0', [$dependency]);
        try {
            $projection();
            throw new RuntimeException('Empty-key retention skipped dependency preflight: ' . $errorCode);
        } catch (app\platform\exception\module\ModulePackageException $exception) {
            if ($exception->errorCode !== $errorCode) {
                throw $exception;
            }
        }
    }
    $writeModule('^1.0');

    // Empty replacement keys must still carry the retained Module's host
    // requirement into the final plan's effective Composer selection.
    file_put_contents($moduleDir . '/composer.json', json_encode([
        'require' => ['peanut-admin/module-composition-host' => '^2.0'],
    ], JSON_THROW_ON_ERROR));
    file_put_contents($root . '/server/composer.json', json_encode([
        'provide' => ['peanut-admin/module-composition-host' => '1.0.0'],
    ], JSON_THROW_ON_ERROR));
    $hostProjection = $projection();
    $hostCheck = new ReflectionMethod(ScaffoldUpgradeRunner::class, 'projectModuleHostCompatibility');
    $hostArguments = [&$hostProjection, $root, $release, [], $parameters];
    $hostCheck->invokeArgs($runner, $hostArguments);
    if (($hostProjection['host_compatibility']['status'] ?? null) !== 'blocked'
        || ($hostProjection['host_compatibility']['blockers'][0]['code'] ?? null) !== 'MODULE_HOST_CAPABILITY_INCOMPATIBLE') {
        throw new RuntimeException('Empty-key retention bypassed host capability compatibility');
    }
    file_put_contents($root . '/server/composer.json', '{"provide":{}}');
    $hostCheck->invokeArgs($runner, $hostArguments);
    if (($hostProjection['host_compatibility']['blockers'][0]['code'] ?? null) !== 'MODULE_HOST_CAPABILITY_MISSING') {
        throw new RuntimeException('Retention accepted a host without the required capability');
    }
    file_put_contents($root . '/server/composer.json', json_encode([
        'provide' => ['peanut-admin/module-composition-host' => '2.0.0'],
    ], JSON_THROW_ON_ERROR));
    $hostCheck->invokeArgs($runner, $hostArguments);
    if (($hostProjection['host_compatibility']['status'] ?? null) !== 'ready') {
        throw new RuntimeException('Retention rejected a compatible host');
    }

    $actions = [[
        'path' => $identity['manifest'],
        'classification' => 'managed',
        'owner' => 'backend',
        'action' => 'conflict',
        'reason' => 'both_project_and_upstream_modified',
        'conflict' => true,
        'current' => ['present' => true, 'sha256' => str_repeat('c', 64), 'mode' => 0644],
        'target_sha256' => str_repeat('d', 64),
        'mode' => 0644,
    ]];
    $boundary = new ReflectionMethod(ScaffoldUpgradeRunner::class, 'projectModuleBoundary');
    $current += ['artifact_paths' => [$identity['manifest']], 'root_paths' => $identity['module_roots']];
    $retained = $boundary->invoke($runner, $actions, $current, $target, $out);
    if (($retained[0]['action'] ?? null) !== 'preserve' || ($retained[0]['conflict'] ?? true) !== false
        || ($retained[0]['target_sha256'] ?? null) !== str_repeat('c', 64)) {
        throw new RuntimeException('Upgrade actions failed to retain newer module source');
    }
    echo "SCAFFOLD-NEWER-BUNDLED-MODULE-RETENTION PASS\n";
} finally {
    unlink($root . '/modules.lock');
    if (is_file($root . '/server/composer.json')) {
        unlink($root . '/server/composer.json');
    }
    unlink($root . '/server/app/modules/peanut/reference_codes/composer.json');
    unlink($root . '/server/app/modules/peanut/reference_codes/module.json');
    unlink($root . '/release/files/server/config/modules.php');
    foreach (['release/files/server/config', 'release/files/server', 'release/files', 'release',
        'server/app/modules/peanut/reference_codes', 'server/app/modules/peanut',
        'server/app/modules', 'server/app', 'server'] as $directory) {
        rmdir($root . '/' . $directory);
    }
    rmdir($root);
}
