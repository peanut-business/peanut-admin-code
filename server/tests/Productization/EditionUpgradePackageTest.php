<?php
declare(strict_types=1);

use app\common\infrastructure\scaffold\EditionUpgradePackage;
use app\common\infrastructure\scaffold\ScaffoldUpgradeRunner;
use app\common\value\scaffold\VersionContract;
use app\platform\infrastructure\plugin\PluginArtifactWriter;
use app\platform\infrastructure\plugin\PluginLockResolver;

$root = dirname(__DIR__, 3);
require_once $root . '/scripts/scaffold-runtime/EditionUpgradePackage.php';
$semverLoader = new ReflectionMethod(EditionUpgradePackage::class, 'loadSemver');
$semverLoader->setAccessible(true);
$semverLoader->invoke(new EditionUpgradePackage(), $root);
if (!\Composer\Semver\Comparator::lessThan('4.0.0-dev', '4.0.0-dev.1')) {
    throw new RuntimeException('isolated upgrade runtime did not load Composer Semver');
}
require_once $root . '/server/vendor/autoload.php';
require_once $root . '/scripts/scaffold-runtime/ScaffoldPathGuard.php';
require_once $root . '/scripts/scaffold-runtime/ScaffoldManifest.php';
require_once $root . '/scripts/scaffold-runtime/ScaffoldUpgradeLedger.php';
require_once $root . '/server/app/platform/exception/plugin/PluginLifecycleException.php';
require_once $root . '/server/app/platform/value/plugin/PluginDescriptor.php';
require_once $root . '/server/app/platform/infrastructure/plugin/PluginLockResolver.php';
require_once $root . '/server/app/platform/exception/plugin/PluginArtifactToolException.php';
require_once $root . '/server/app/platform/infrastructure/plugin/PluginArtifactWriter.php';
require_once $root . '/scripts/scaffold-runtime/ScaffoldUpgradeRunner.php';
require_once $root . '/server/app/common/value/scaffold/VersionContract.php';

function editionUpgradeExpect(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}

$sourceVersions = VersionContract::load($root . '/release-versions.json');
$sourceVersions->assertValid('4.0.0-dev.1', 'prerelease version contract rejected');
$sourceVersions->assertSame('4.0.0-dev.1', '4.0.0-dev.1', 'prerelease version identity changed');

function editionUpgradeRemove(string $path): void
{
    if (is_dir($path) && !is_link($path)) {
        foreach (array_diff(scandir($path) ?: [], ['.', '..']) as $entry) editionUpgradeRemove($path . '/' . $entry);
        rmdir($path);
        return;
    }
    if (file_exists($path) || is_link($path)) unlink($path);
}

function editionUpgradeJson(string $path, array $data): void
{
    if (!is_dir(dirname($path))) mkdir(dirname($path), 0775, true);
    file_put_contents($path, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n");
}

function editionUpgradeFile(string $path, string $contents, int $mode = 0644): void
{
    if (!is_dir(dirname($path))) mkdir(dirname($path), 0775, true);
    file_put_contents($path, $contents);
    chmod($path, $mode);
}

/** @return array<string,mixed> */
function editionUpgradeEdition(string $edition): array
{
    return [
        'name' => $edition,
        'deployment_mode' => $edition,
        'profile_sha256' => str_repeat('2', 64),
        'source_sha256' => str_repeat('2', 64),
        'generator_version' => 1,
        'module_profile' => 'official-default',
        'tenant_bootstrap' => [
            'kind' => 'real-default-tenant', 'code' => 'default', 'tenant_identity' => 'required',
            'rbac' => 'required', 'execution_context' => 'PeanutAdmin\\Kernel\\Context\\TenantSystemContext',
            'module_lifecycle' => 'required',
        ],
        'schema_projection' => $edition === 'standalone' ? 'single-organization-v1' : 'tenant-owned-v1',
        'schema' => ['projection' => $edition === 'standalone' ? 'single-organization-v1' : 'tenant-owned-v1'],
    ];
}

function editionUpgradeCopyTree(string $source, string $target): void
{
    mkdir($target, 0775, true);
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($source, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST,
    );
    foreach ($iterator as $file) {
        $relative = substr($file->getPathname(), strlen($source) + 1);
        $destination = $target . '/' . $relative;
        if ($file->isDir()) mkdir($destination, $file->getPerms() & 0777, true);
        else {
            copy($file->getPathname(), $destination);
            chmod($destination, $file->getPerms() & 0777);
        }
    }
}

function editionUpgradeFails(callable $operation, string $error): void
{
    try {
        $operation();
        throw new RuntimeException('expected ' . $error);
    } catch (RuntimeException $exception) {
        editionUpgradeExpect(str_starts_with($exception->getMessage(), $error), 'unexpected error: ' . $exception->getMessage());
    }
}

/** @return array{managed:array<string,string>,app_owned:array<string,string>,module_root:string,frontend_root:string} */
function editionUpgradePluginTree(
    string $projectRoot,
    string $key,
    string $moduleRoot,
    string $version,
    string $marker,
): array {
    $frontendRoot = 'web/src/modules/' . str_replace('.', '-', $key);
    $packageName = str_replace('.', '-', $key);
    $phpName = implode('', array_map('ucfirst', preg_split('/[._-]+/', $key) ?: []));
    $phpNamespace = 'Acme\\Modules\\' . $phpName . '\\';
    editionUpgradeJson($projectRoot . '/' . $moduleRoot . '/module.json', [
        'schema_version' => 1,
        'key' => $key,
        'version' => $version,
        'kernel_constraint' => '^1.0',
        'license' => 'MIT',
        'dependencies' => [],
        'backend' => [],
        'frontend' => ['entry' => $frontendRoot . '/contribution.ts'],
        'marker' => $marker,
    ]);
    editionUpgradeJson($projectRoot . '/' . $moduleRoot . '/composer.json', [
        'name' => 'acme/' . $packageName,
        'version' => $version,
        'type' => 'library',
        'autoload' => ['psr-4' => [$phpNamespace => 'src/']],
    ]);
    editionUpgradeFile($projectRoot . '/' . $moduleRoot . '/src/.keep', "");
    editionUpgradeJson($projectRoot . '/' . $frontendRoot . '/package.json', [
        'name' => '@acme/' . $packageName,
        'version' => $version,
        'private' => true,
    ]);
    editionUpgradeFile(
        $projectRoot . '/' . $frontendRoot . '/contribution.ts',
        "export const marker = '" . $marker . "';\n",
    );
    $writer = new PluginArtifactWriter($projectRoot . '/server', false);
    $writer->make($key, $version, [$key . '=' . $moduleRoot]);
    $writer->writeLock();
    $managedPaths = [
        $frontendRoot . '/package.json',
        $frontendRoot . '/contribution.ts',
        'plugins/' . $key . '/plugin.json',
        'plugins.lock',
    ];
    $appOwnedPaths = [$moduleRoot . '/module.json', $moduleRoot . '/composer.json', $moduleRoot . '/src/.keep'];
    $contents = static function (array $paths) use ($projectRoot): array {
        $files = [];
        foreach ($paths as $path) $files[$path] = (string)file_get_contents($projectRoot . '/' . $path);
        return $files;
    };
    return [
        'managed' => $contents($managedPaths),
        'app_owned' => $contents($appOwnedPaths),
        'module_root' => $moduleRoot,
        'frontend_root' => $frontendRoot,
    ];
}

$temporaryRoot = realpath(sys_get_temp_dir());
editionUpgradeExpect(is_string($temporaryRoot), 'temporary root unavailable');
$temporary = $temporaryRoot . '/peanut-edition-upgrade-' . bin2hex(random_bytes(6));
$project = $temporary . '/project';
$package = $temporary . '/package';
mkdir($project . '/.peanut/scaffold-baseline/4.0.0-dev/files/scripts/scaffold-runtime', 0775, true);
mkdir($package, 0775, true);

try {
    $currentPlugin = editionUpgradePluginTree(
        $project,
        'fixture.upgrade-boundary',
        'server/app/modules/fixture/upgrade_boundary',
        '1.0.0',
        'installed',
    );
    $old = [
        'managed.txt' => "old managed\n",
        'scripts/scaffold-upgrade' => "<?php // old cli\n",
        'scripts/scaffold-runtime/EditionUpgradePackage.php' => "<?php // old loader\n",
    ] + $currentPlugin['managed'];
    $files = [];
    foreach ($old as $path => $contents) {
        editionUpgradeFile($project . '/' . $path, $contents, $path === 'scripts/scaffold-upgrade' ? 0755 : 0644);
        editionUpgradeFile($project . '/.peanut/scaffold-baseline/4.0.0-dev/files/' . $path, $contents);
        $files[] = [
            'path' => $path,
            'sha256' => hash('sha256', $contents),
            'mode' => $path === 'scripts/scaffold-upgrade' ? 0755 : 0644,
            'classification' => 'managed',
            'owner' => 'scaffold',
            'source' => $path,
            'baseline_path' => '.peanut/scaffold-baseline/4.0.0-dev/files/' . $path,
        ];
    }
    editionUpgradeFile($project . '/business.php', "<?php // user business\n");
    editionUpgradeFile($project . '/server/.env', "APP_KEY=do-not-touch\n");
    editionUpgradeFile($project . '/server/app/Modules/ThirdParty/Custom.php', "<?php // third-party module\n");
    $files[] = [
        'path' => 'business.php',
        'sha256' => hash('sha256', "<?php // user business\n"),
        'mode' => 0644,
        'classification' => 'app-owned',
        'owner' => 'application',
        'source' => 'business.php',
    ];
    foreach ($currentPlugin['app_owned'] as $path => $contents) {
        $files[] = [
            'path' => $path,
            'sha256' => hash('sha256', $contents),
            'mode' => 0644,
            'classification' => 'app-owned',
            'owner' => 'application',
            'source' => $path,
        ];
    }
    usort($files, static fn(array $left, array $right): int => strcmp($left['path'], $right['path']));
    $managedRows = [];
    $appOwnedRows = [];
    foreach ($files as $file) if ($file['classification'] === 'managed') $managedRows[] = $file['path'] . "\0" . $file['sha256'];
    foreach ($files as $file) if ($file['classification'] === 'app-owned') $appOwnedRows[] = $file['path'] . "\0" . $file['sha256'];
    sort($managedRows, SORT_STRING);
    sort($appOwnedRows, SORT_STRING);
    editionUpgradeJson($project . '/.peanut/application-manifest.json', [
        'schema_version' => 2,
        'protocol' => 'peanut.application-scaffold.v2',
        'application' => [
            'name' => 'Acme App', 'slug' => 'acme-app', 'package_identity' => 'acme/app',
            'version' => '1.4.0', 'profile' => 'full', 'edition' => 'standalone',
        ],
        'edition' => editionUpgradeEdition('standalone'),
        'template' => [
            'version' => '4.0.0-dev', 'inventory_sha256' => str_repeat('c', 64),
            'source_commit' => str_repeat('a', 40), 'source_tree' => str_repeat('b', 40),
        ],
        'ownership' => [
            'baseline_root' => '.peanut/scaffold-baseline/4.0.0-dev/files',
        ],
        'digests' => [
            'managed_tree_sha256' => hash('sha256', implode("\n", $managedRows)),
            'app_owned_tree_sha256' => hash('sha256', implode("\n", $appOwnedRows)),
        ],
        'files' => $files,
    ]);
    editionUpgradeJson($project . '/release-versions.json', [
        'schema_version' => 2,
        'protocol' => 'peanut.release-versions.v2',
        'source_product_version' => '4.0.0-dev',
        'instance_version' => '1.4.0',
        'scaffold_template' => '4.0.0-dev',
        'generated_instance_default' => '0.1.0',
        'core_php' => '4.0.0-dev',
        'core_web' => '4.0.0-dev',
    ]);

    $targetPluginRoot = $temporary . '/target-plugin-template';
    $targetPlugin = editionUpgradePluginTree(
        $targetPluginRoot,
        'fixture.upgrade-boundary',
        'server/app/modules/fixture/upgrade_boundary',
        '1.1.0',
        'target',
    );
    $targetContents = [
        'managed.txt' => "new managed\n",
        'scripts/scaffold-upgrade' => "<?php // new cli\n",
        'scripts/scaffold-runtime/EditionUpgradePackage.php' => "<?php // new loader\n",
        'server/database/migrations/20260830-edition-upgrade.sql' => "SELECT 1;\n",
    ] + $targetPlugin['managed'];
    $targetFiles = [];
    foreach ($targetContents as $path => $contents) {
        editionUpgradeFile($package . '/target/files/' . $path, $contents, $path === 'scripts/scaffold-upgrade' ? 0755 : 0644);
        $targetFiles[] = [
            'path' => $path,
            'source' => 'files/' . $path,
            'template_sha256' => hash('sha256', $contents),
            'classification' => 'managed',
            'transform' => 'tokens',
            'mode' => $path === 'scripts/scaffold-upgrade' ? 0755 : 0644,
            'policy' => 'managed',
            'owner' => str_starts_with($path, 'server/') ? 'backend' : 'host',
        ];
    }
    usort($targetFiles, static fn(array $left, array $right): int => strcmp($left['path'], $right['path']));
    $targetTree = str_repeat('f', 64);
    $targetRelease = [
        'schema_version' => 3,
        'protocol' => 'peanut.scaffold-release.v3',
        'application' => ['version' => '0.1.0'],
        'release' => [
            'version' => '4.0.0-dev.1',
            'source_commit' => str_repeat('d', 40),
            'source_tree' => str_repeat('e', 40),
            'inventory_sha256' => str_repeat('1', 64),
            'inventory_template_version' => '4.0.0-dev.1',
            'managed_tree_sha256' => $targetTree,
            'tokens' => [
                'product_name' => '__TARGET_NAME__',
                'slug' => '__TARGET_SLUG__',
                'package_identity' => '__TARGET_PACKAGE__',
                'application_version' => '__TARGET_VERSION__',
            ],
        ],
        'files' => $targetFiles,
        'renames' => [],
        'edition' => editionUpgradeEdition('standalone'),
    ];
    editionUpgradeJson($package . '/target/scaffold-manifest.json', $targetRelease);
    editionUpgradeFile($package . '/scripts/upgrade', "<?php // product upgrade cli\n", 0755);
    editionUpgradeFile($package . '/scripts/scaffold-upgrade', "<?php // internal scaffold cli\n", 0755);
    editionUpgradeFile($package . '/scripts/scaffold-runtime/EditionUpgradePackage.php', "<?php // package loader\n");
    editionUpgradeFile($package . '/scripts/upgrade-runtime/ApplicationMigrationRunner.php', "<?php // migration runner\n");
    editionUpgradeFile($package . '/scripts/upgrade-runtime/product-upgrade-host', "#!/usr/bin/env bash\n", 0755);
    $upgradeManifest = [
        'schema_version' => 1,
        'protocol' => 'peanut.edition-upgrade-package.v1',
        'product' => ['name' => 'Peanut Admin'],
        'edition' => array_intersect_key(editionUpgradeEdition('standalone'), array_flip([
            'name', 'deployment_mode', 'profile_sha256', 'generator_version', 'module_profile', 'tenant_bootstrap', 'schema_projection',
        ])),
        'compatibility' => [
            'source' => ['minimum_inclusive' => '4.0.0-dev', 'maximum_exclusive' => '4.0.0-dev.1'],
            'major_policy' => 'same-major', 'edition_conversion' => false,
        ],
        'build_source' => [
            'commit' => str_repeat('d', 40), 'tree' => str_repeat('e', 40), 'inventory_sha256' => str_repeat('1', 64),
        ],
        'target' => [
            'version' => '4.0.0-dev.1',
            'scaffold_manifest' => 'target/scaffold-manifest.json',
            'scaffold_manifest_sha256' => hash_file('sha256', $package . '/target/scaffold-manifest.json'),
            'managed_tree_sha256' => $targetTree,
        ],
        'upgrader' => ['entrypoint' => 'scripts/upgrade', 'internal_scaffold_engine' => 'scripts/scaffold-upgrade', 'host_driver' => 'scripts/upgrade-runtime/product-upgrade-host'],
        'migration_chain' => [
            'strategy' => 'append-only-ledger',
            'files' => [[
                'path' => 'server/database/migrations/20260830-edition-upgrade.sql',
                'sha256' => hash('sha256', "SELECT 1;\n"),
            ]],
        ],
        'ownership' => [
            'automatic' => ['managed', 'generated-managed'],
            'preserved' => ['app-owned', 'third-party-module', 'secret'],
        ],
        'recovery' => ['managed_files' => 'scaffold-recovery-plan', 'database' => 'operator-backup-required'],
        'signing' => ['algorithm' => 'ed25519', 'authority' => 'test-release'],
    ];
    editionUpgradeJson($package . '/upgrade-manifest.json', $upgradeManifest);

    $inventory = [];
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($package, FilesystemIterator::SKIP_DOTS));
    foreach ($iterator as $file) {
        if ($file->isFile()) $inventory[str_replace('\\', '/', substr($file->getPathname(), strlen($package) + 1))] = hash_file('sha256', $file->getPathname());
    }
    ksort($inventory, SORT_STRING);
    $inventoryBytes = '';
    foreach ($inventory as $path => $digest) $inventoryBytes .= $path . "\0" . $digest . "\n";
    editionUpgradeFile($package . '/META-INF/files.sha256', $inventoryBytes);
    $keypair = sodium_crypto_sign_keypair();
    $public = sodium_crypto_sign_publickey($keypair);
    $secret = sodium_crypto_sign_secretkey($keypair);
    editionUpgradeJson($package . '/META-INF/signatures/test-release.json', [
        'schema_version' => 1,
        'algorithm' => 'ed25519',
        'key_id' => 'test-release',
        'inventory_sha256' => hash('sha256', $inventoryBytes),
        'signature_base64' => base64_encode(sodium_crypto_sign_detached(hash('sha256', $inventoryBytes, true), $secret)),
    ]);
    putenv('PEANUT_UPGRADE_TRUSTED_KEYS_JSON=' . json_encode(['test-release' => base64_encode($public)], JSON_THROW_ON_ERROR));

    $trusted = ['test-release' => base64_encode($public)];
    $prepared = (new EditionUpgradePackage())->prepare($project, $package, 'test-release', $trusted);
    $runner = new ScaffoldUpgradeRunner();
    editionUpgradeFile($project . '/managed.txt', "user managed customization\n");
    $blocked = $runner->preview($project, $prepared['from_manifest'], $prepared['to_manifest']);
    editionUpgradeExpect(
        $blocked['status'] === 'blocked'
            && count($blocked['impact']['must_resolve']) === 1
            && str_contains($blocked['impact']['message'], 'No files will be changed'),
        'managed conflict must have a business-readable stop plan',
    );
    editionUpgradeFile($project . '/managed.txt', $old['managed.txt']);
    $plan = $runner->preflight($project, $prepared['from_manifest'], $prepared['to_manifest']);
    editionUpgradeExpect(
        $plan['status'] === 'ready'
            && $plan['summary']['conflicts'] === 0
            && $plan['impact']['must_resolve'] === []
            && str_contains($plan['impact']['ownership_notice'], 'third-party Modules'),
        'package plan must be ready and explain the protected ownership boundary',
    );
    $businessDigest = hash_file('sha256', $project . '/business.php');
    $secretDigest = hash_file('sha256', $project . '/server/.env');
    $thirdPartyDigest = hash_file('sha256', $project . '/server/app/Modules/ThirdParty/Custom.php');
    $pluginDigests = [];
    foreach (array_keys($currentPlugin['managed'] + $currentPlugin['app_owned']) as $path) {
        $pluginDigests[$path] = hash_file('sha256', $project . '/' . $path);
    }
    $pluginActions = [];
    foreach ($plan['actions'] as $action) {
        if (isset($pluginDigests[$action['path']])) $pluginActions[$action['path']] = $action;
    }
    foreach ($pluginActions as $path => $action) {
        editionUpgradeExpect(
            $action['action'] === 'preserve'
                && $action['reason'] === 'installed_plugin_projection'
                && $action['target_sha256'] === $pluginDigests[$path],
            'installed Plugin projection was not frozen: ' . $path,
        );
    }
    $planPath = $project . '/' . $plan['plan_path'];
    $modulePath = $currentPlugin['module_root'] . '/module.json';
    $moduleBytes = (string)file_get_contents($project . '/' . $modulePath);
    editionUpgradeFile($project . '/' . $modulePath, $moduleBytes . "\n");
    editionUpgradeFails(fn() => $runner->apply($project, $planPath), 'SCAFFOLD_PLUGIN_PROJECTION_INVALID');
    editionUpgradeFile($project . '/' . $modulePath, $moduleBytes);
    editionUpgradeExpect($runner->apply($project, $planPath)['status'] === 'applied', 'package apply failed');
    editionUpgradeExpect($runner->verify($project, $planPath)['status'] === 'verified', 'package verify failed');
    editionUpgradeExpect($runner->apply($project, $planPath)['idempotent'] === true, 'package apply replay not idempotent');
    editionUpgradeExpect($runner->verify($project, $planPath)['idempotent'] === true, 'package verify replay not idempotent');
    editionUpgradeExpect((string)file_get_contents($project . '/managed.txt') === "new managed\n", 'managed target not applied');
    editionUpgradeExpect(hash_equals((string)$businessDigest, (string)hash_file('sha256', $project . '/business.php')), 'app-owned file changed');
    editionUpgradeExpect(hash_equals((string)$secretDigest, (string)hash_file('sha256', $project . '/server/.env')), 'secret changed');
    editionUpgradeExpect(hash_equals((string)$thirdPartyDigest, (string)hash_file('sha256', $project . '/server/app/Modules/ThirdParty/Custom.php')), 'third-party Module changed');
    foreach ($pluginDigests as $path => $digest) {
        editionUpgradeExpect(hash_equals((string)$digest, (string)hash_file('sha256', $project . '/' . $path)), 'installed Plugin changed: ' . $path);
    }
    editionUpgradeExpect(
        hash_equals(
            (string)$pluginDigests['plugins.lock'],
            (string)hash_file('sha256', $project . '/.peanut/scaffold-baseline/4.0.0-dev.1/files/plugins.lock'),
        ),
        'next Plugin lock baseline did not use the validated installed bytes',
    );
    editionUpgradeExpect(
        array_keys((new PluginLockResolver($project . '/server', '../plugins.lock'))->all()) === ['fixture.upgrade-boundary'],
        'upgraded project Plugin projection is not resolvable',
    );
    $appliedManifest = json_decode((string)file_get_contents($project . '/.peanut/application-manifest.json'), true, 512, JSON_THROW_ON_ERROR);
    foreach ($appliedManifest['files'] as $file) {
        if (in_array($file['classification'], ['managed', 'generated-managed'], true)) {
            editionUpgradeExpect(isset($file['baseline_sha256']), 'upgraded managed file lost its baseline digest');
        }
    }
    editionUpgradeExpect($runner->recover($project, $planPath)['status'] === 'recovered', 'package recovery failed');
    editionUpgradeExpect((string)file_get_contents($project . '/managed.txt') === "old managed\n", 'managed recovery did not restore the source');
    editionUpgradeExpect(!is_file($project . '/server/database/migrations/20260830-edition-upgrade.sql'), 'recovery retained a target-only migration');
    editionUpgradeExpect(hash_equals((string)$businessDigest, (string)hash_file('sha256', $project . '/business.php')), 'recovery changed app-owned file');
    editionUpgradeExpect(hash_equals((string)$secretDigest, (string)hash_file('sha256', $project . '/server/.env')), 'recovery changed secret');
    editionUpgradeExpect(hash_equals((string)$thirdPartyDigest, (string)hash_file('sha256', $project . '/server/app/Modules/ThirdParty/Custom.php')), 'recovery changed third-party Module');
    editionUpgradeExpect(
        array_keys((new PluginLockResolver($project . '/server', '../plugins.lock'))->all()) === ['fixture.upgrade-boundary'],
        'recovered project Plugin projection is not resolvable',
    );

    $newPluginTemplate = $temporary . '/new-plugin-template';
    editionUpgradePluginTree(
        $newPluginTemplate,
        'fixture.upgrade-boundary',
        'server/app/modules/fixture/upgrade_boundary',
        '1.1.0',
        'target',
    );
    $newPlugin = editionUpgradePluginTree(
        $newPluginTemplate,
        'fixture.new-plugin',
        'server/app/modules/fixture/new_plugin',
        '1.0.0',
        'new-plugin',
    );
    $newPluginRelease = $temporary . '/new-plugin-release';
    editionUpgradeCopyTree($package . '/target', $newPluginRelease);
    $newPluginManaged = $newPlugin['managed'];
    $newPluginManaged['plugins.lock'] = (string)file_get_contents($newPluginTemplate . '/plugins.lock');
    foreach ($newPluginManaged as $path => $contents) editionUpgradeFile($newPluginRelease . '/files/' . $path, $contents);
    $newPluginManifestPath = $newPluginRelease . '/scaffold-manifest.json';
    $newPluginManifest = json_decode((string)file_get_contents($newPluginManifestPath), true, 512, JSON_THROW_ON_ERROR);
    foreach ($newPluginManifest['files'] as &$file) {
        if ($file['path'] === 'plugins.lock') $file['template_sha256'] = hash('sha256', $newPluginManaged['plugins.lock']);
    }
    unset($file);
    foreach ($newPlugin['managed'] as $path => $contents) {
        if ($path === 'plugins.lock') continue;
        $newPluginManifest['files'][] = [
            'path' => $path,
            'source' => 'files/' . $path,
            'template_sha256' => hash('sha256', $contents),
            'classification' => 'managed',
            'transform' => 'tokens',
            'mode' => 0644,
            'policy' => 'managed',
            'owner' => str_starts_with($path, 'web/') ? 'frontend' : 'host',
        ];
    }
    usort($newPluginManifest['files'], static fn(array $left, array $right): int => strcmp($left['path'], $right['path']));
    editionUpgradeJson($newPluginManifestPath, $newPluginManifest);
    $newPluginPlan = $runner->preview($project, $prepared['from_manifest'], $newPluginManifestPath);
    editionUpgradeExpect(
        $newPluginPlan['status'] === 'blocked'
            && count(array_filter(
                $newPluginPlan['actions'],
                static fn(array $action): bool => $action['reason'] === 'plugin_adoption_required',
            )) >= 1,
        'a target-only Plugin without installed roots and artifacts must block',
    );

    editionUpgradeFile($package . '/target/files/managed.txt', "tampered\n");
    editionUpgradeFails(fn() => (new EditionUpgradePackage())->prepare($project, $package, 'test-release', $trusted), 'EDITION_UPGRADE_FILE_DIGEST_MISMATCH');
} finally {
    putenv('PEANUT_UPGRADE_TRUSTED_KEYS_JSON');
    editionUpgradeRemove($temporary);
}

/** @return array{project:string,package:string,public:string,secret:string} */
function editionAdoptionFixture(string $temporary, string $edition, bool $customize = false): array
{
    $project = $temporary . '/project';
    $package = $temporary . '/package';
    mkdir($project . '/.peanut', 0775, true);
    mkdir($package . '/target/files', 0775, true);
    $emptyPluginLock = json_encode(
        ['schema_version' => 1, 'plugins' => []],
        JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
    ) . "\n";
    editionUpgradeFile($project . '/plugins.lock', $emptyPluginLock);
    editionUpgradeFile($project . '/.peanut/scaffold-baseline/3.0.14/files/plugins.lock', $emptyPluginLock);
    editionUpgradeFile($package . '/target/files/plugins.lock', $emptyPluginLock);
    $applicationFiles = [];
    $targetFiles = [];
    $adoptionFiles = [];
    foreach (EditionUpgradePackage::OWNERSHIP_ADOPTION_PATHS as $index => $path) {
        $old = "<?php // old {$path}\n";
        $current = $customize && $index === 0 ? "<?php // customer customization\n" : $old;
        $target = "<?php // target {$path}\n";
        editionUpgradeFile($project . '/' . $path, $current);
        editionUpgradeFile($package . '/adoption/files/' . $path, $old);
        editionUpgradeFile($package . '/target/files/' . $path, $target);
        $applicationFiles[] = [
            'path' => $path, 'sha256' => hash('sha256', $current), 'mode' => 0644,
            'classification' => 'app-owned', 'owner' => 'application', 'source' => $path,
        ];
        $targetFiles[] = [
            'path' => $path, 'source' => 'files/' . $path, 'template_sha256' => hash('sha256', $target),
            'classification' => 'managed', 'transform' => 'tokens', 'mode' => 0644,
            'policy' => 'managed', 'owner' => 'backend',
        ];
        $adoptionFiles[] = [
            'path' => $path, 'source' => 'adoption/files/' . $path, 'sha256' => hash('sha256', $old),
            'mode' => 0644, 'classification' => 'managed', 'owner' => 'scaffold',
        ];
    }
    foreach ([
        ['business.php', "<?php // business\n", 'app-owned'],
        ['server/.env', "APP_KEY=protected\n", 'secret'],
        ['server/app/Modules/ThirdParty/Custom.php', "<?php // module\n", 'third-party-module'],
    ] as [$path, $contents, $classification]) {
        editionUpgradeFile($project . '/' . $path, $contents);
        $applicationFiles[] = [
            'path' => $path, 'sha256' => hash('sha256', $contents), 'mode' => 0644,
            'classification' => $classification, 'owner' => 'application', 'source' => $path,
        ];
    }
    $applicationFiles[] = [
        'path' => 'plugins.lock', 'sha256' => hash('sha256', $emptyPluginLock), 'mode' => 0644,
        'classification' => 'managed', 'owner' => 'scaffold', 'source' => 'plugins.lock',
        'baseline_path' => '.peanut/scaffold-baseline/3.0.14/files/plugins.lock',
        'baseline_sha256' => hash('sha256', $emptyPluginLock),
    ];
    $targetFiles[] = [
        'path' => 'plugins.lock', 'source' => 'files/plugins.lock',
        'template_sha256' => hash('sha256', $emptyPluginLock), 'classification' => 'managed',
        'transform' => 'tokens', 'mode' => 0644, 'policy' => 'managed', 'owner' => 'host',
    ];
    usort($applicationFiles, static fn(array $a, array $b): int => strcmp($a['path'], $b['path']));
    usort($targetFiles, static fn(array $a, array $b): int => strcmp($a['path'], $b['path']));
    $appRows = [];
    foreach ($applicationFiles as $file) if ($file['classification'] === 'app-owned') $appRows[] = $file['path'] . "\0" . $file['sha256'];
    sort($appRows, SORT_STRING);
    editionUpgradeJson($project . '/.peanut/application-manifest.json', [
        'schema_version' => 2, 'protocol' => 'peanut.application-scaffold.v2',
        'application' => ['name' => 'Acme', 'slug' => 'acme', 'package_identity' => 'acme/app', 'version' => '1.4.0', 'profile' => 'full', 'edition' => $edition],
        'edition' => editionUpgradeEdition($edition),
        'template' => ['version' => '3.0.14', 'inventory_sha256' => str_repeat('c', 64), 'source_commit' => str_repeat('a', 40), 'source_tree' => str_repeat('b', 40)],
        'ownership' => ['baseline_root' => '.peanut/scaffold-baseline/3.0.14/files'],
        'digests' => [
            'managed_tree_sha256' => hash('sha256', 'plugins.lock' . "\0" . hash('sha256', $emptyPluginLock)),
            'app_owned_tree_sha256' => hash('sha256', implode("\n", $appRows)),
        ],
        'generation_source' => ['kind' => 'edition-installer', 'version' => '3.0.14'],
        'files' => $applicationFiles,
    ]);
    editionUpgradeJson($project . '/release-versions.json', [
        'schema_version' => 2, 'protocol' => 'peanut.release-versions.v2', 'source_product_version' => '3.0.14',
        'instance_version' => '1.4.0', 'scaffold_template' => '3.0.14', 'generated_instance_default' => '0.1.0',
        'core_php' => '3.0.14', 'core_web' => '3.0.14',
    ]);
    $targetManifest = [
        'schema_version' => 3, 'protocol' => 'peanut.scaffold-release.v3', 'application' => ['version' => '0.1.0'],
        'release' => [
            'version' => '3.1.0', 'source_commit' => str_repeat('d', 40), 'source_tree' => str_repeat('e', 40),
            'inventory_sha256' => str_repeat('1', 64), 'inventory_template_version' => '3.1.0',
            'managed_tree_sha256' => str_repeat('f', 64),
            'tokens' => ['product_name' => '__PN__', 'slug' => '__SLUG__', 'package_identity' => '__PKG__', 'application_version' => '__APPV__'],
        ],
        'files' => $targetFiles, 'renames' => [], 'edition' => editionUpgradeEdition($edition),
    ];
    editionUpgradeJson($package . '/target/scaffold-manifest.json', $targetManifest);
    editionUpgradeFile($package . '/scripts/upgrade', "<?php\n", 0755);
    editionUpgradeFile($package . '/scripts/scaffold-upgrade', "<?php\n", 0755);
    editionUpgradeFile($package . '/scripts/scaffold-runtime/EditionUpgradePackage.php', "<?php\n");
    editionUpgradeFile($package . '/scripts/upgrade-runtime/ApplicationMigrationRunner.php', "<?php\n");
    editionUpgradeFile($package . '/scripts/upgrade-runtime/product-upgrade-host', "#!/usr/bin/env bash\n", 0755);
    $upgradeManifest = [
        'schema_version' => 1, 'protocol' => 'peanut.edition-upgrade-package.v1', 'product' => ['name' => 'Peanut Admin'],
        'edition' => array_intersect_key(editionUpgradeEdition($edition), array_flip([
            'name', 'deployment_mode', 'profile_sha256', 'generator_version', 'module_profile', 'tenant_bootstrap', 'schema_projection',
        ])),
        'compatibility' => ['source' => ['minimum_inclusive' => '3.0.14', 'maximum_exclusive' => '3.1.0'], 'major_policy' => 'same-major', 'edition_conversion' => false],
        'build_source' => ['commit' => str_repeat('d', 40), 'tree' => str_repeat('e', 40), 'inventory_sha256' => str_repeat('1', 64)],
        'target' => ['version' => '3.1.0', 'scaffold_manifest' => 'target/scaffold-manifest.json', 'scaffold_manifest_sha256' => hash_file('sha256', $package . '/target/scaffold-manifest.json'), 'managed_tree_sha256' => str_repeat('f', 64)],
        'upgrader' => ['entrypoint' => 'scripts/upgrade', 'internal_scaffold_engine' => 'scripts/scaffold-upgrade', 'host_driver' => 'scripts/upgrade-runtime/product-upgrade-host'],
        'migration_chain' => ['strategy' => 'append-only-ledger', 'files' => []],
        'ownership' => [
            'automatic' => ['managed', 'generated-managed'], 'preserved' => ['app-owned', 'third-party-module', 'secret'],
            'adoption' => ['protocol' => 'peanut.ownership-adoption.v1', 'source' => ['version' => '3.0.14', 'commit' => str_repeat('a', 40), 'tree' => str_repeat('b', 40)], 'files' => $adoptionFiles],
        ],
        'recovery' => ['managed_files' => 'scaffold-recovery-plan', 'database' => 'operator-backup-required'],
        'signing' => ['algorithm' => 'ed25519', 'authority' => 'adoption-test'],
    ];
    editionUpgradeJson($package . '/upgrade-manifest.json', $upgradeManifest);
    $inventory = [];
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($package, FilesystemIterator::SKIP_DOTS));
    foreach ($iterator as $file) if ($file->isFile()) $inventory[str_replace('\\', '/', substr($file->getPathname(), strlen($package) + 1))] = hash_file('sha256', $file->getPathname());
    ksort($inventory, SORT_STRING);
    $inventoryBytes = '';
    foreach ($inventory as $path => $digest) $inventoryBytes .= $path . "\0" . $digest . "\n";
    editionUpgradeFile($package . '/META-INF/files.sha256', $inventoryBytes);
    $keypair = sodium_crypto_sign_keypair();
    $public = sodium_crypto_sign_publickey($keypair);
    $secret = sodium_crypto_sign_secretkey($keypair);
    editionUpgradeJson($package . '/META-INF/signatures/adoption-test.json', [
        'schema_version' => 1, 'algorithm' => 'ed25519', 'key_id' => 'adoption-test',
        'inventory_sha256' => hash('sha256', $inventoryBytes),
        'signature_base64' => base64_encode(sodium_crypto_sign_detached(hash('sha256', $inventoryBytes, true), $secret)),
    ]);
    return compact('project', 'package', 'public', 'secret');
}

function editionAdoptionResign(string $package, string $secret): void
{
    @unlink($package . '/META-INF/files.sha256');
    @unlink($package . '/META-INF/signatures/adoption-test.json');
    $inventory = [];
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($package, FilesystemIterator::SKIP_DOTS));
    foreach ($iterator as $file) {
        if (!$file->isFile()) continue;
        $path = str_replace('\\', '/', substr($file->getPathname(), strlen($package) + 1));
        if (!str_starts_with($path, 'META-INF/')) $inventory[$path] = hash_file('sha256', $file->getPathname());
    }
    ksort($inventory, SORT_STRING);
    $bytes = '';
    foreach ($inventory as $path => $digest) $bytes .= $path . "\0" . $digest . "\n";
    editionUpgradeFile($package . '/META-INF/files.sha256', $bytes);
    editionUpgradeJson($package . '/META-INF/signatures/adoption-test.json', [
        'schema_version' => 1, 'algorithm' => 'ed25519', 'key_id' => 'adoption-test',
        'inventory_sha256' => hash('sha256', $bytes),
        'signature_base64' => base64_encode(sodium_crypto_sign_detached(hash('sha256', $bytes, true), $secret)),
    ]);
}

foreach (['standalone', 'multi-tenant'] as $edition) {
    $temporary = $temporaryRoot . '/peanut-ownership-adoption-' . $edition . '-' . bin2hex(random_bytes(4));
    mkdir($temporary, 0775, true);
    try {
        $fixture = editionAdoptionFixture($temporary, $edition);
        putenv('PEANUT_UPGRADE_TRUSTED_KEYS_JSON=' . json_encode(['adoption-test' => base64_encode($fixture['public'])], JSON_THROW_ON_ERROR));
        $trusted = ['adoption-test' => base64_encode($fixture['public'])];
        $runner = new ScaffoldUpgradeRunner();
        $protected = [];
        foreach (['business.php', 'server/.env', 'server/app/Modules/ThirdParty/Custom.php'] as $path) $protected[$path] = hash_file('sha256', $fixture['project'] . '/' . $path);
        $plan = $runner->adoptionPlan($fixture['project'], $fixture['package'], 'adoption-test', $trusted);
        editionUpgradeExpect(
            count($plan['paths']) === count(EditionUpgradePackage::OWNERSHIP_ADOPTION_PATHS)
                && count($plan['metadata_writes']) === count($plan['paths']) + 1,
            $edition . ' adoption scope mismatch',
        );
        $manifestBeforeConfirmation = hash_file('sha256', $fixture['project'] . '/.peanut/application-manifest.json');
        editionUpgradeFails(fn() => $runner->adoptionApply($fixture['project'], $fixture['project'] . '/' . $plan['plan_path'], $plan['plan_sha256'], array_slice($plan['paths'], 1), $trusted), 'SCAFFOLD_ADOPTION_CONFIRMATION_MISMATCH');
        editionUpgradeExpect(
            hash_equals((string)$manifestBeforeConfirmation, (string)hash_file('sha256', $fixture['project'] . '/.peanut/application-manifest.json'))
                && !is_file($fixture['project'] . '/' . $plan['actions'][0]['baseline_path']),
            $edition . ' rejected confirmation wrote ownership metadata',
        );
        $adopted = $runner->adoptionApply($fixture['project'], $fixture['project'] . '/' . $plan['plan_path'], $plan['plan_sha256'], $plan['paths'], $trusted);
        editionUpgradeExpect($adopted['status'] === 'adopted' && !$adopted['idempotent'], $edition . ' adoption failed');
        editionUpgradeExpect($runner->adoptionApply($fixture['project'], $fixture['project'] . '/' . $plan['plan_path'], $plan['plan_sha256'], $plan['paths'], $trusted)['idempotent'], $edition . ' adoption replay not idempotent');
        foreach ($protected as $path => $digest) editionUpgradeExpect(hash_equals((string)$digest, (string)hash_file('sha256', $fixture['project'] . '/' . $path)), $edition . ' protected file changed');
        $prepared = (new EditionUpgradePackage())->prepare(
            $fixture['project'], $fixture['package'], 'adoption-test',
            ['adoption-test' => base64_encode($fixture['public'])],
        );
        $upgrade = $runner->preflight($fixture['project'], $prepared['from_manifest'], $prepared['to_manifest']);
        editionUpgradeExpect($upgrade['status'] === 'ready', $edition . ' adopted upgrade not ready');
        $upgradePath = $fixture['project'] . '/' . $upgrade['plan_path'];
        editionUpgradeExpect($runner->apply($fixture['project'], $upgradePath)['status'] === 'applied', $edition . ' upgrade apply failed');
        editionUpgradeExpect($runner->verify($fixture['project'], $upgradePath)['status'] === 'verified', $edition . ' upgrade verify failed');
        editionUpgradeExpect($runner->recover($fixture['project'], $upgradePath)['status'] === 'recovered', $edition . ' upgrade recover failed');
        editionUpgradeExpect($runner->adoptionRecover($fixture['project'], $fixture['project'] . '/' . $plan['plan_path'])['status'] === 'recovered', $edition . ' adoption recover failed');
    } finally {
        putenv('PEANUT_UPGRADE_TRUSTED_KEYS_JSON');
        editionUpgradeRemove($temporary);
    }
}

$temporary = $temporaryRoot . '/peanut-ownership-adoption-controls-' . bin2hex(random_bytes(4));
mkdir($temporary, 0775, true);
try {
    $fixture = editionAdoptionFixture($temporary, 'standalone', true);
    $trusted = ['adoption-test' => base64_encode($fixture['public'])];
    putenv('PEANUT_UPGRADE_TRUSTED_KEYS_JSON=' . json_encode(['adoption-test' => base64_encode($fixture['public'])], JSON_THROW_ON_ERROR));
    $runner = new ScaffoldUpgradeRunner();
    $untrusted = sodium_crypto_sign_publickey(sodium_crypto_sign_keypair());
    putenv('PEANUT_UPGRADE_TRUSTED_KEYS_JSON=' . json_encode(['adoption-test' => base64_encode($untrusted)], JSON_THROW_ON_ERROR));
    editionUpgradeFails(fn() => (new EditionUpgradePackage())->prepareAdoption(
        $fixture['project'], $fixture['package'], 'adoption-test', ['adoption-test' => base64_encode($untrusted)],
    ), 'EDITION_UPGRADE_SOURCE_UNTRUSTED');
    putenv('PEANUT_UPGRADE_TRUSTED_KEYS_JSON=' . json_encode(['adoption-test' => base64_encode($fixture['public'])], JSON_THROW_ON_ERROR));
    $applicationPath = $fixture['project'] . '/.peanut/application-manifest.json';
    $applicationRaw = (string)file_get_contents($applicationPath);
    $wrongEdition = json_decode($applicationRaw, true, 512, JSON_THROW_ON_ERROR);
    $wrongEdition['application']['edition'] = 'multi-tenant';
    editionUpgradeJson($applicationPath, $wrongEdition);
    editionUpgradeFails(fn() => (new EditionUpgradePackage())->prepareAdoption(
        $fixture['project'], $fixture['package'], 'adoption-test', ['adoption-test' => base64_encode($fixture['public'])],
    ), 'EDITION_UPGRADE_EDITION_MISMATCH');
    editionUpgradeFile($applicationPath, $applicationRaw);
    $upgradeManifestPath = $fixture['package'] . '/upgrade-manifest.json';
    $upgradeManifest = json_decode((string)file_get_contents($upgradeManifestPath), true, 512, JSON_THROW_ON_ERROR);
    $invalidScope = $upgradeManifest;
    array_pop($invalidScope['ownership']['adoption']['files']);
    editionUpgradeJson($upgradeManifestPath, $invalidScope);
    editionAdoptionResign($fixture['package'], $fixture['secret']);
    editionUpgradeFails(fn() => (new EditionUpgradePackage())->prepareAdoption(
        $fixture['project'], $fixture['package'], 'adoption-test', ['adoption-test' => base64_encode($fixture['public'])],
    ), 'EDITION_UPGRADE_ADOPTION_SCOPE_INVALID');
    editionUpgradeJson($upgradeManifestPath, $upgradeManifest);
    editionAdoptionResign($fixture['package'], $fixture['secret']);
    $plan = $runner->adoptionPlan($fixture['project'], $fixture['package'], 'adoption-test', $trusted);
    $driftPath = EditionUpgradePackage::OWNERSHIP_ADOPTION_PATHS[1];
    editionUpgradeFile($fixture['project'] . '/' . $driftPath, "<?php // drift\n");
    editionUpgradeFails(fn() => $runner->adoptionApply($fixture['project'], $fixture['project'] . '/' . $plan['plan_path'], $plan['plan_sha256'], $plan['paths'], $trusted), 'SCAFFOLD_ADOPTION_PLAN_REBIND_FAILED');
    editionUpgradeFile($fixture['project'] . '/' . $driftPath, "<?php // old {$driftPath}\n");
    $plan = $runner->adoptionPlan($fixture['project'], $fixture['package'], 'adoption-test', $trusted);
    editionUpgradeFails(fn() => (new ScaffoldUpgradeRunner(null, 1))->adoptionApply($fixture['project'], $fixture['project'] . '/' . $plan['plan_path'], $plan['plan_sha256'], $plan['paths'], $trusted), 'SCAFFOLD_ADOPTION_FAULT_INJECTED');
    editionUpgradeExpect($runner->adoptionRecover($fixture['project'], $fixture['project'] . '/' . $plan['plan_path'])['status'] === 'recovered', 'adoption metadata recovery failed');
    $plan = $runner->adoptionPlan($fixture['project'], $fixture['package'], 'adoption-test', $trusted);
    editionUpgradeExpect($runner->adoptionApply($fixture['project'], $fixture['project'] . '/' . $plan['plan_path'], $plan['plan_sha256'], $plan['paths'], $trusted)['status'] === 'adopted', 'custom adoption failed');
    $prepared = (new EditionUpgradePackage())->prepare(
        $fixture['project'], $fixture['package'], 'adoption-test',
        ['adoption-test' => base64_encode($fixture['public'])],
    );
    $blocked = $runner->preview($fixture['project'], $prepared['from_manifest'], $prepared['to_manifest']);
    editionUpgradeExpect(
        $blocked['status'] === 'blocked'
            && count(array_filter(
                $blocked['actions'],
                static fn(array $action): bool => $action['reason'] === 'both_project_and_upstream_modified',
            )) === 1,
        'custom conflict was auto-resolved',
    );
} finally {
    putenv('PEANUT_SCAFFOLD_ADOPTION_FAIL_AFTER_WRITES');
    putenv('PEANUT_UPGRADE_TRUSTED_KEYS_JSON');
    editionUpgradeRemove($temporary);
}

echo "EDITION-UPGRADE-PACKAGE-001 passed\n";
