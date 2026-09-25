<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/bootstrap/environment.php';

use app\common\persistence\AdvisoryLockExecution;
use app\common\persistence\AdvisoryLockUnavailable;
use app\common\value\runtime\RuntimeNamespace;
use app\platform\exception\plugin\PluginLifecycleException;
use app\platform\exception\plugin\PluginPackageException;
use app\platform\infrastructure\plugin\DeterministicTarArchive;
use app\platform\infrastructure\plugin\PluginPackageInstaller;
use app\platform\services\plugin\PlatformModuleRuntimeService;
use app\platform\services\plugin\PluginCatalogSyncService;
use app\platform\services\plugin\PluginPackageArchiveService;
use app\platform\services\plugin\PluginRuntimeGovernanceService;
use app\platform\validation\plugin\PluginReleaseCompositionGuard;
use PeanutAdmin\Kernel\Module\ManifestLoader;

require dirname(__DIR__, 2) . '/vendor/autoload.php';
require_once dirname(__DIR__, 2) . '/database/install.php';
require_once dirname(__DIR__) . '/Support/IsolatedBackendEnvironment.php';
require_once dirname(__DIR__) . '/Support/ThinkPhpTestConnection.php';

function moduleBundleExpect(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function moduleBundleExpectLifecycleError(callable $operation, string $errorCode, string $message): void
{
    try {
        $operation();
    } catch (PluginLifecycleException $exception) {
        moduleBundleExpect($exception->errorCode === $errorCode, $message . ': ' . $exception->errorCode);
        return;
    }
    throw new RuntimeException($message . ': no error');
}

function moduleBundleExpectPackageError(callable $operation, string $errorCode, string $message): void
{
    try {
        $operation();
    } catch (PluginPackageException $exception) {
        moduleBundleExpect($exception->errorCode === $errorCode, $message . ': ' . $exception->errorCode);
        return;
    }
    throw new RuntimeException($message . ': no error');
}

function moduleBundleCopyTree(string $source, string $target): void
{
    if (!is_dir($target)) {
        mkdir($target, 0777, true);
    }
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($source, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST,
    );
    foreach ($iterator as $entry) {
        $relative = substr($entry->getPathname(), strlen($source) + 1);
        $destination = $target . '/' . $relative;
        if ($entry->isDir()) {
            if (!is_dir($destination)) {
                mkdir($destination, 0777, true);
            }
        } else {
            copy($entry->getPathname(), $destination);
        }
    }
}

function moduleBundleRemoveTree(string $path): void
{
    if (is_link($path) || is_file($path)) {
        unlink($path);
        return;
    }
    if (!is_dir($path)) {
        return;
    }
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST,
    );
    foreach ($iterator as $entry) {
        if ($entry->isLink() || !$entry->isDir()) {
            unlink($entry->getPathname());
        } else {
            rmdir($entry->getPathname());
        }
    }
    rmdir($path);
}

/** @return array{0:\Composer\Autoload\ClassLoader,1:\Composer\Autoload\ClassLoader} */
function moduleBundleSwapTargetComposerLoader(string $serverRoot, string $target): array
{
    $vendorRoot = realpath($serverRoot . '/vendor');
    $hostLoader = null;
    foreach (\Composer\Autoload\ClassLoader::getRegisteredLoaders() as $registeredVendor => $loader) {
        if ($vendorRoot !== false && realpath($registeredVendor) === $vendorRoot) {
            $hostLoader = $loader;
            break;
        }
    }
    moduleBundleExpect($hostLoader instanceof \Composer\Autoload\ClassLoader, 'verified host Composer loader is unavailable');
    $targetLoader = clone $hostLoader;
    $targetModuleRoots = [
        'app/modules/official/article/src',
        'app/modules/official/file/src',
        'app/modules/official/identity/src',
        'app/modules/official/integration/src',
        'app/modules/official/notification/src',
        'app/modules/official/ops/src',
        'app/modules/official/task/src',
        'app/modules/fixture/delivery_record/src',
    ];
    foreach ($targetLoader->getPrefixesPsr4() as $prefix => $directories) {
        $mapped = [];
        foreach ($directories as $directory) {
            $resolved = realpath($directory);
            $relative = $resolved === false ? '' : substr($resolved, strlen($serverRoot) + 1);
            $mapped[] = in_array($relative, $targetModuleRoots, true)
                ? $target . '/server/' . $relative
                : $directory;
        }
        $targetLoader->setPsr4($prefix, $mapped);
    }
    $hostLoader->unregister();
    $targetLoader->register(true);
    return [$hostLoader, $targetLoader];
}

function moduleBundleSetVersion(string $root, string $module, string $version): void
{
    $directory = strtolower((string) preg_replace('/(?<!^)[A-Z]/', '_$0', $module));
    $backend = $root . '/server/app/modules/official/' . $directory;
    foreach ([$backend . '/module.json', $backend . '/composer.json'] as $path) {
        $document = json_decode((string) file_get_contents($path), true, 64, JSON_THROW_ON_ERROR);
        $document['version'] = $version;
        if ($module === 'Article' && str_ends_with($path, '/module.json')) {
            $document['dependencies'][0]['version'] = '^' . explode('.', $version)[0] . '.0';
        }
        file_put_contents($path, json_encode($document, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n");
    }
    $frontend = $root . '/web/src/modules/official-' . strtolower($module) . '/package.json';
    $document = json_decode((string) file_get_contents($frontend), true, 64, JSON_THROW_ON_ERROR);
    $document['version'] = $version;
    file_put_contents($frontend, json_encode($document, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n");
}

/** @return list<string> */
function moduleBundleTables(array $affectedModules): array
{
    $tables = [];
    foreach ($affectedModules as $module) {
        foreach ((array) ($module['owned_tables'] ?? []) as $table) {
            $tables[] = (string) $table;
        }
    }
    sort($tables, SORT_STRING);
    return $tables;
}

/** @param list<string> $tables */
function moduleBundleExistingTableCount(PDO $pdo, array $tables): int
{
    if ($tables === []) {
        return 0;
    }
    $statement = $pdo->prepare(
        'SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name IN ('
        . implode(',', array_fill(0, count($tables), '?')) . ')',
    );
    $statement->execute($tables);
    return (int) $statement->fetchColumn();
}

/** @param list<string> $moduleKeys */
function moduleBundleCount(PDO $pdo, string $table, array $moduleKeys, ?string $status = null): int
{
    $statement = $pdo->prepare(
        "SELECT COUNT(*) FROM {$table} WHERE module_key IN ("
        . implode(',', array_fill(0, count($moduleKeys), '?')) . ')'
        . ($status === null ? '' : ' AND status=?'),
    );
    $statement->execute($status === null ? $moduleKeys : [...$moduleKeys, $status]);
    return (int) $statement->fetchColumn();
}

$serverRoot = dirname(__DIR__, 2);
$projectRoot = dirname($serverRoot);
$resourceId = IsolatedBackendEnvironment::required('PEANUT_DATABASE_RESOURCE_ID');
$resource = IsolatedBackendEnvironment::requireRegisteredDatabase(
    $projectRoot . '/resources/project-resources.json',
    $resourceId,
);
$database = $argv[1] ?? IsolatedBackendEnvironment::required('DB_NAME');
moduleBundleExpect($database === IsolatedBackendEnvironment::required('DB_NAME'), 'test database must match the selected backend environment');
moduleBundleExpect(
    ($resource['upstream_endpoint']['endpoint_id'] ?? null) === IsolatedBackendEnvironment::required('PEANUT_DATABASE_ENDPOINT_ID'),
    'registered database endpoint identity is required',
);
$namespaceDatabase = (string) ($resource['synthetic_databases']['module_bundle_multi'][0] ?? '');
moduleBundleExpect(
    preg_match('/^peanut_admin_dual_20260922_bundle_multi_namespace$/D', $namespaceDatabase) === 1
        && $namespaceDatabase !== $database,
    'registered bundle namespace database is required',
);

$host = IsolatedBackendEnvironment::required('DB_HOST');
$port = IsolatedBackendEnvironment::required('DB_PORT');
$user = IsolatedBackendEnvironment::required('DB_USER');
$password = IsolatedBackendEnvironment::required('DB_PASS');
$admin = new PDO(
    "mysql:host={$host};port={$port};charset=utf8mb4",
    $user,
    $password,
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC],
);
$exists = $admin->prepare('SELECT COUNT(*) FROM information_schema.schemata WHERE schema_name=?');
$exists->execute([$database]);
moduleBundleExpect((int) $exists->fetchColumn() === 0, 'isolated bundle database already exists');
$admin->exec("CREATE DATABASE `{$database}` CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci");
$exists->execute([$namespaceDatabase]);
moduleBundleExpect((int) $exists->fetchColumn() === 0, 'isolated namespace database already exists');
$admin->exec("CREATE DATABASE `{$namespaceDatabase}` CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci");

IsolatedBackendEnvironment::activate([
    'APP_ENV' => 'development',
    'APP_DEBUG' => 'true',
    'DEPLOYMENT_MODE' => 'multi-tenant',
    'PEANUT_DATABASE_RESOURCE_ID' => $resourceId,
    'PEANUT_DATABASE_ENDPOINT_ID' => IsolatedBackendEnvironment::required('PEANUT_DATABASE_ENDPOINT_ID'),
    'PEANUT_DATABASE_CONSUMER' => 'host',
    'DB_HOST' => $host,
    'DB_PORT' => $port,
    'DB_NAME' => $database,
    'DB_USER' => $user,
    'DB_PASS' => $password,
    'DB_PREFIX' => 'pa_',
]);

$pdo = new PDO(
    "mysql:host={$host};port={$port};dbname={$database};charset=utf8mb4",
    $user,
    $password,
    [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ],
);
moduleBundleExpect((string) $pdo->query('SELECT DATABASE()')->fetchColumn() === $database, 'isolated database selection changed');
$app = new think\App($serverRoot);
$app->initialize();
$catalogs = ThinkPhpTestConnection::moduleCatalogs($pdo);
$contender = new PDO(
    "mysql:host={$host};port={$port};dbname={$database};charset=utf8mb4",
    $user,
    $password,
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES => false],
);
$otherDatabase = new PDO(
    "mysql:host={$host};port={$port};dbname={$namespaceDatabase};charset=utf8mb4",
    $user,
    $password,
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES => false],
);
moduleBundleExpect(
    ThinkPhpTestConnection::fromPdo($pdo) instanceof \think\db\PDOConnection
        &&
    RuntimeNamespace::fromResourceId($resourceId, 'development')
        ->advisoryLockName('module-bundle-lock-environment')
        !== RuntimeNamespace::fromResourceId($resourceId, 'production')
            ->advisoryLockName('module-bundle-lock-environment'),
    'runtime environment is absent from the advisory-lock namespace',
);
moduleBundleExpect(
    RuntimeNamespace::fromResourceId($resourceId, 'development')
        ->advisoryLockName('module-bundle-lock-resource')
        !== RuntimeNamespace::fromResourceId('peanut-admin-a1-review-mysql84', 'development')
            ->advisoryLockName('module-bundle-lock-resource'),
    'database resource identity is absent from the advisory-lock namespace',
);
$busy = false;
$otherDatabaseRan = false;
(new AdvisoryLockExecution())->run('module-bundle-lock-contract', 0, static function () use (
    $pdo,
    $contender,
    $otherDatabase,
    &$busy,
    &$otherDatabaseRan,
): void {
    try {
        ThinkPhpTestConnection::fromPdo($contender);
        try {
            (new AdvisoryLockExecution())->run('module-bundle-lock-contract', 0, static fn() => null);
        } catch (AdvisoryLockUnavailable) {
            $busy = true;
        }
        ThinkPhpTestConnection::fromPdo($otherDatabase);
        $otherDatabaseRan = (new AdvisoryLockExecution())->run(
            'module-bundle-lock-contract',
            0,
            static fn(): bool => true,
        );
    } finally {
        ThinkPhpTestConnection::fromPdo($pdo);
    }
});
moduleBundleExpect($busy, 'same database resource did not preserve advisory-lock mutual exclusion');
moduleBundleExpect($otherDatabaseRan, 'different database resource shared an advisory lock');
$callbackFailed = false;
try {
    ThinkPhpTestConnection::fromPdo($pdo);
    (new AdvisoryLockExecution())->run(
        'module-bundle-lock-release',
        0,
        static fn() => throw new RuntimeException('lock callback failed'),
    );
} catch (RuntimeException $exception) {
    $callbackFailed = $exception->getMessage() === 'lock callback failed';
}
moduleBundleExpect($callbackFailed, 'advisory-lock callback failure contract changed');
moduleBundleExpect(
    ThinkPhpTestConnection::fromPdo($contender) instanceof \think\db\PDOConnection
        && (new AdvisoryLockExecution())->run(
            'module-bundle-lock-release',
            0,
            static fn(): bool => true,
        ),
    'callback failure did not release the advisory lock',
);
ThinkPhpTestConnection::fromPdo($pdo);
initializeCoreIdentity(
    $pdo,
    'module-bundle@example.test',
    'module-bundle-test-password',
    null,
    new \app\common\policy\DemoAccountPolicy(false, []),
    [
        'kind' => 'real-default-tenant',
        'code' => 'default',
        'tenant_identity' => 'required',
        'rbac' => 'required',
        'execution_context' => \PeanutAdmin\Kernel\Context\TenantSystemContext::class,
        'module_lifecycle' => 'required',
    ],
);
$lockSqlOwners = [];
$applicationFiles = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($serverRoot . '/app', FilesystemIterator::SKIP_DOTS),
);
foreach ($applicationFiles as $file) {
    if (!$file->isFile() || $file->getExtension() !== 'php') {
        continue;
    }
    $source = (string) file_get_contents($file->getPathname());
    if (str_contains($source, 'GET_LOCK') || str_contains($source, 'RELEASE_LOCK')) {
        $lockSqlOwners[] = substr($file->getPathname(), strlen($serverRoot) + 1);
    }
}
sort($lockSqlOwners, SORT_STRING);
moduleBundleExpect(
    $lockSqlOwners === [
        'app/common/persistence/AdvisoryLockExecution.php',
        'app/modules/official/identity/src/Identity/SelfService/AccountSelfService.php',
        'app/modules/official/identity/src/Platform/Bootstrap/BootstrapService.php',
    ],
    'production application advisory-lock SQL owners changed unexpectedly',
);
executeSqlFiles($pdo, [$serverRoot . '/database/init.sql']);
$applicationMigrations = glob($serverRoot . '/database/migrations/*.sql') ?: [];
sort($applicationMigrations, SORT_STRING);
executeSqlFiles($pdo, $applicationMigrations);
executeSqlFiles($pdo, [
    $serverRoot . '/app/modules/official/reference_codes/database/migrations/20260921-adopt-reference-codes-schema.sql',
]);

$projectRoot = dirname($serverRoot);
$temporary = realpath(sys_get_temp_dir()) . '/pa-module-bundle-' . substr(hash('sha256', $database), 0, 11);
moduleBundleExpect(!file_exists($temporary), 'isolated bundle output path already exists');
$source = $temporary . '/source';
$target = $temporary . '/target';
$archivePath = $temporary . '/official-content-bundle.tar';
$updateArchivePath = $temporary . '/official-content-bundle-v2.tar';
$conflictArchivePath = $temporary . '/official-content-bundle-v2-conflict.tar';
$recoverableArchivePath = $temporary . '/official-runtime-bundle.tar';
$releaseV1 = $temporary . '/release-v1';
$releaseV2 = $temporary . '/release-v2';
$hostLoader = null;
$targetLoader = null;
$completed = false;

try {
    foreach (['Article', 'File', 'Integration', 'Notification', 'Task'] as $module) {
        $directory = strtolower((string) preg_replace('/(?<!^)[A-Z]/', '_$0', $module));
        moduleBundleCopyTree(
            $projectRoot . "/server/app/modules/official/{$directory}",
            $source . "/server/app/modules/official/{$directory}",
        );
        $slug = 'official-' . strtolower($module);
        moduleBundleCopyTree($projectRoot . "/web/src/modules/{$slug}", $source . "/web/src/modules/{$slug}");
    }
    // File declares official.identity; model the generated consumer's installed base instead of hiding that dependency in the bundle.
    moduleBundleCopyTree(
        $projectRoot . '/server/app/modules/official/identity',
        $source . '/server/app/modules/official/identity',
    );
    moduleBundleCopyTree(
        $projectRoot . '/server/app/modules/official/identity',
        $target . '/server/app/modules/official/identity',
    );
    moduleBundleCopyTree(
        $projectRoot . '/server/app/modules/official/ops',
        $target . '/server/app/modules/official/ops',
    );
    moduleBundleCopyTree(
        $projectRoot . '/server/app/modules/official/integration',
        $target . '/server/app/modules/official/integration',
    );
    moduleBundleCopyTree(
        $projectRoot . '/web/src/modules/official-integration',
        $target . '/web/src/modules/official-integration',
    );
    moduleBundleCopyTree(
        $projectRoot . '/plugins/official.identity',
        $target . '/plugins/official.identity',
    );
    moduleBundleCopyTree(
        $projectRoot . '/plugins/official.integration',
        $target . '/plugins/official.integration',
    );
    moduleBundleExpect(
        symlink($serverRoot . '/vendor', $target . '/server/vendor'),
        'isolated target must use the verified host Composer vendor root',
    );
    $baseLock = json_decode((string) file_get_contents($projectRoot . '/plugins.lock'), true, 64, JSON_THROW_ON_ERROR);
    $baselineEntries = array_values(array_filter(
        (array) ($baseLock['plugins'] ?? []),
        static fn(mixed $entry): bool => is_array($entry)
            && in_array($entry['key'] ?? null, ['official.identity', 'official.integration'], true),
    ));
    moduleBundleExpect(count($baselineEntries) === 2, 'installed identity/integration baseline is missing from the source lock');
    file_put_contents(
        $target . '/plugins.lock',
        json_encode(['schema_version' => 1, 'plugins' => $baselineEntries], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n",
    );
    foreach ([$source, $target] as $root) {
        if (!is_dir($root . '/server/resources/schemas')) {
            mkdir($root . '/server/resources/schemas', 0777, true);
        }
        copy(
            $serverRoot . '/resources/schemas/plugin.schema.json',
            $root . '/server/resources/schemas/plugin.schema.json',
        );
    }

    $archive = new PluginPackageArchiveService($source . '/server');
    $packed = $archive->packBundle(
        'official-content-bundle',
        '1.0.0',
        ['official.article', 'official.file'],
        $archivePath,
    );
    moduleBundleExpect($packed['modules'] === ['official.article', 'official.file'], 'bundle member order changed');

    $tar = new DeterministicTarArchive();
    $entries = $tar->scan($archivePath);
    $pluginPath = 'plugins/official-content-bundle/plugin.json';
    moduleBundleExpect(isset($entries['META-INF/files.sha256'], $entries[$pluginPath]), 'bundle metadata is incomplete');
    $plugin = json_decode($tar->read($archivePath, $entries[$pluginPath]), true, 64, JSON_THROW_ON_ERROR);
    moduleBundleExpect(($plugin['key'] ?? null) === 'official-content-bundle', 'bundle package key changed');
    moduleBundleExpect(($plugin['key'] ?? null) !== 'official.article' && ($plugin['key'] ?? null) !== 'official.file', 'bundle impersonates a member Module');
    moduleBundleExpect(
        array_column((array) ($plugin['modules'] ?? []), 'key') === ['official.article', 'official.file'],
        'bundle plugin manifest lost a member Module',
    );

    $moduleConfig = ['kernel_version' => '1.0.0', 'registered_client_keys' => ['admin-web', 'platform-web']];
    $installer = new PluginPackageInstaller($target . '/server', $moduleConfig, [], $catalogs);
    $connection = (string) \think\facade\Config::get('database.default', 'mysql');
    $databaseConfig = \think\facade\Config::get('database', []);
    $prefix = $databaseConfig['connections'][$connection]['prefix'] ?? null;
    moduleBundleExpect(is_string($prefix) && $prefix !== '', 'database prefix is unavailable for installation precondition check');
    $missingPrefixConfig = $databaseConfig;
    $missingPrefixConfig['connections'][$connection]['prefix'] = 'missing_';
    \think\facade\Config::set($missingPrefixConfig, 'database');
    try {
        moduleBundleExpectPackageError(
            static fn() => $installer->install($archivePath, $packed['sha256'], null),
            'INSTALLATION_REQUIRED',
            'package delivery did not reject an uninstalled configured table namespace',
        );
    } finally {
        $databaseConfig['connections'][$connection]['prefix'] = $prefix;
        \think\facade\Config::set($databaseConfig, 'database');
    }
    moduleBundleExpect(
        (glob($source . '/.local/module-staging/*') ?: []) === [],
        'installation precondition failure left a verified archive staging directory',
    );
    [$hostLoader, $targetLoader] = moduleBundleSwapTargetComposerLoader($serverRoot, $target);
    $installed = $installer->install($archivePath, $packed['sha256'], null);
    moduleBundleExpect(($installed['operation'] ?? null) === 'installed', 'bundle was not installed');
    moduleBundleExpect(array_column((array) $installed['modules'], 'module_key') === ['official.article', 'official.file'], 'bundle install returned another scope');
    $unchangedInstall = $installer->install($archivePath, $packed['sha256'], null);
    moduleBundleExpect(($unchangedInstall['operation'] ?? null) === 'unchanged', 'repeated bundle install was not idempotent');
    moduleBundleCopyTree($target, $releaseV1);

    $lockBeforeDryRun = (string) file_get_contents($target . '/plugins.lock');
    $databaseBeforeDryRun = (string) json_encode([
        'plugin' => $pdo->query("SELECT * FROM pa_plugin_installation WHERE plugin_key='official-content-bundle'")->fetchAll(),
        'modules' => $pdo->query("SELECT * FROM pa_module_installation WHERE module_key IN ('official.article','official.file') ORDER BY module_key")->fetchAll(),
        'migrations' => $pdo->query("SELECT * FROM pa_module_migration WHERE module_key IN ('official.article','official.file') ORDER BY id")->fetchAll(),
        'tenant_modules' => $pdo->query("SELECT * FROM pa_tenant_module WHERE module_key IN ('official.article','official.file') ORDER BY tenant_id,module_key")->fetchAll(),
    ], JSON_THROW_ON_ERROR);
    moduleBundleSetVersion($source, 'Article', '2.0.0');
    moduleBundleSetVersion($source, 'File', '2.0.0');
    $updatedPacked = $archive->packBundle(
        'official-content-bundle',
        '2.0.0',
        ['official.article', 'official.file'],
        $updateArchivePath,
    );
    $dryRun = $installer->update($updateArchivePath, $updatedPacked['sha256'], null, true);
    moduleBundleExpect(($dryRun['operation'] ?? null) === 'update' && ($dryRun['dry_run'] ?? null) === true, 'bundle update dry-run did not return its plan');
    moduleBundleExpect(($dryRun['source']['version'] ?? null) === '1.0.0', 'bundle update dry-run source identity changed');
    moduleBundleExpect(($dryRun['target']['version'] ?? null) === '2.0.0', 'bundle update dry-run target identity changed');
    moduleBundleExpect((string) file_get_contents($target . '/plugins.lock') === $lockBeforeDryRun, 'bundle update dry-run changed plugins.lock');
    moduleBundleExpect((string) json_encode([
        'plugin' => $pdo->query("SELECT * FROM pa_plugin_installation WHERE plugin_key='official-content-bundle'")->fetchAll(),
        'modules' => $pdo->query("SELECT * FROM pa_module_installation WHERE module_key IN ('official.article','official.file') ORDER BY module_key")->fetchAll(),
        'migrations' => $pdo->query("SELECT * FROM pa_module_migration WHERE module_key IN ('official.article','official.file') ORDER BY id")->fetchAll(),
        'tenant_modules' => $pdo->query("SELECT * FROM pa_tenant_module WHERE module_key IN ('official.article','official.file') ORDER BY tenant_id,module_key")->fetchAll(),
    ], JSON_THROW_ON_ERROR) === $databaseBeforeDryRun, 'bundle update dry-run changed database state');
    $updated = $installer->update($updateArchivePath, $updatedPacked['sha256'], null, false);
    moduleBundleExpect(($updated['operation'] ?? null) === 'upgraded', 'bundle package update did not execute');
    moduleBundleExpect(($updated['dry_run'] ?? null) === false, 'bundle package update reported dry-run');
    moduleBundleExpect((int) $pdo->query("SELECT COUNT(*) FROM pa_plugin_installation WHERE plugin_key='official-content-bundle' AND installed_version='2.0.0' AND status='active'")->fetchColumn() === 1, 'bundle Package identity did not update');
    moduleBundleExpect((int) $pdo->query("SELECT COUNT(*) FROM pa_module_installation WHERE module_key IN ('official.article','official.file') AND installed_version='2.0.0' AND status='active'")->fetchColumn() === 2, 'bundle Module identities did not update');
    moduleBundleExpect((int) $pdo->query("SELECT COUNT(*) FROM pa_tenant_module WHERE module_key IN ('official.article','official.file')")->fetchColumn() === 0, 'bundle update changed TenantModule enablement');
    moduleBundleCopyTree($target, $releaseV2);
    $composition = (new PluginReleaseCompositionGuard($target, $moduleConfig, $catalogs))->verify($releaseV2);
    moduleBundleExpect(
        ($composition['status'] ?? null) === 'ready'
            && ($composition['checked'][0]['operation'] ?? null) === 'preserve',
        'same-package application release did not pass composition verification',
    );

    $pdo->beginTransaction();
    try {
        $pdo->exec("UPDATE pa_plugin_installation SET composer_identity_json='{}' WHERE plugin_key='official-content-bundle'");
        moduleBundleExpectLifecycleError(
            static fn() => (new PluginReleaseCompositionGuard($target, $moduleConfig, $catalogs))->verify($releaseV2),
            'PLUGIN_RELEASE_CURRENT_IDENTITY_INVALID',
            'invalid installed JSON identity did not fail closed',
        );
    } finally {
        $pdo->rollBack();
    }

    $pdo->beginTransaction();
    try {
        $pdo->exec("DELETE FROM pa_plugin_module WHERE plugin_key='official-content-bundle' AND module_key='official.file'");
        moduleBundleExpectLifecycleError(
            static fn() => (new PluginReleaseCompositionGuard($target, $moduleConfig, $catalogs))->verify($releaseV2),
            'PLUGIN_RELEASE_PACKAGE_SCOPE_CHANGED',
            'database Package member loss did not block release composition',
        );
    } finally {
        $pdo->rollBack();
    }

    moduleBundleExpectLifecycleError(
        static fn() => (new PluginReleaseCompositionGuard($releaseV1, $moduleConfig, $catalogs))->verify($releaseV2),
        'PLUGIN_RELEASE_PACKAGE_DOWNGRADE',
        'application release Package downgrade was not blocked',
    );

    $missingRelease = $temporary . '/release-v2-missing';
    moduleBundleCopyTree($releaseV2, $missingRelease);
    $missingLockPath = $missingRelease . '/plugins.lock';
    $missingLock = json_decode((string) file_get_contents($missingLockPath), true, 64, JSON_THROW_ON_ERROR);
    $missingLock['plugins'] = [];
    file_put_contents($missingLockPath, json_encode($missingLock, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n");
    moduleBundleExpectLifecycleError(
        static fn() => (new PluginReleaseCompositionGuard($missingRelease, $moduleConfig, $catalogs))->verify($releaseV2),
        'PLUGIN_RELEASE_PACKAGE_REMOVED',
        'application release Package removal was not blocked',
    );

    $identityConflictRelease = $temporary . '/release-v2-identity-conflict';
    moduleBundleCopyTree($releaseV2, $identityConflictRelease);
    $conflictLockPath = $identityConflictRelease . '/plugins.lock';
    $conflictLock = json_decode((string) file_get_contents($conflictLockPath), true, 64, JSON_THROW_ON_ERROR);
    $conflictEntry = &$conflictLock['plugins'][0];
    $conflictManifestPath = $identityConflictRelease . '/' . $conflictEntry['manifest'];
    $conflictManifest = json_decode((string) file_get_contents($conflictManifestPath), true, 64, JSON_THROW_ON_ERROR);
    $conflictManifest['trust']['license']['identifier'] = 'MIT';
    $conflictEntry['trust']['license']['identifier'] = 'MIT';
    file_put_contents($conflictManifestPath, json_encode($conflictManifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n");
    $conflictEntry['manifest_sha256'] = hash_file('sha256', $conflictManifestPath);
    unset($conflictEntry);
    file_put_contents($conflictLockPath, json_encode($conflictLock, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n");
    moduleBundleExpectLifecycleError(
        static fn() => (new PluginReleaseCompositionGuard($identityConflictRelease, $moduleConfig, $catalogs))->verify($releaseV2),
        'PLUGIN_RELEASE_PACKAGE_IDENTITY_CHANGED',
        'same-version Package identity change was not blocked',
    );
    try {
        $installer->update($archivePath, $packed['sha256'], null, false);
        throw new RuntimeException('bundle downgrade unexpectedly executed');
    } catch (\app\platform\exception\plugin\PluginPackageException $exception) {
        moduleBundleExpect($exception->errorCode === 'PLUGIN_DOWNGRADE_REJECTED', 'bundle downgrade returned another error');
    }
    file_put_contents($source . '/server/app/modules/official/article/Module.php', "\n", FILE_APPEND);
    $conflictPacked = $archive->packBundle(
        'official-content-bundle',
        '2.0.0',
        ['official.article', 'official.file'],
        $conflictArchivePath,
    );
    try {
        $installer->update($conflictArchivePath, $conflictPacked['sha256'], null, false);
        throw new RuntimeException('same-version conflicting bundle update unexpectedly executed');
    } catch (\app\platform\exception\plugin\PluginPackageException $exception) {
        moduleBundleExpect($exception->errorCode === 'PACKAGE_VERSION_IDENTITY_CONFLICT', 'same-version conflict returned another error');
    }

    $moduleKeys = ['official.article', 'official.file'];
    moduleBundleExpect((int) $pdo->query("SELECT COUNT(*) FROM pa_plugin_installation WHERE plugin_key='official-content-bundle' AND status='active'")->fetchColumn() === 1, 'bundle installation row is invalid');
    moduleBundleExpect(moduleBundleCount($pdo, 'pa_module_installation', $moduleKeys, 'active') === 2, 'bundle Module installation rows are invalid');
    moduleBundleExpect((int) $pdo->query("SELECT COUNT(*) FROM pa_plugin_module WHERE plugin_key='official-content-bundle'")->fetchColumn() === 2, 'bundle ownership rows are invalid');
    moduleBundleExpect(moduleBundleCount($pdo, 'pa_tenant_module', $moduleKeys) === 0, 'package install changed TenantModule enablement');

    $catalogTables = [
        'permissions' => 'pa_permission',
        'menus' => 'pa_menu_definition',
        'settings' => 'pa_setting_definition',
    ];
    $catalogExpected = array_fill_keys(array_keys($catalogTables), 0);
    foreach ($moduleKeys as $moduleKey) {
        $root = $target . '/server/app/modules/official/' . ($moduleKey === 'official.article' ? 'article' : 'file');
        $manifest = (new ManifestLoader())->load($root);
        $catalogExpected['permissions'] += count((array) ($manifest->data['catalog']['permissions'] ?? []));
        $catalogExpected['menus'] += count((array) ($manifest->data['catalog']['menus'] ?? []));
        $definitions = json_decode((string) file_get_contents($root . '/resources/setting-definitions.json'), true, 64, JSON_THROW_ON_ERROR);
        $catalogExpected['settings'] += count((array) $definitions);
    }
    foreach ($catalogTables as $name => $table) {
        moduleBundleExpect(moduleBundleCount($pdo, $table, $moduleKeys, 'active') === $catalogExpected[$name], "bundle {$name} catalog is not active");
    }

    $governance = new PluginRuntimeGovernanceService($target . '/server', $moduleConfig, $catalogs);
    $retirePreview = $governance->preview('official.article', false);
    moduleBundleExpect(($retirePreview['confirm_plan']['package_key'] ?? null) === 'official-content-bundle', 'member key did not resolve the bundle package');
    moduleBundleExpect(array_column($retirePreview['affected_modules'], 'module_key') === $moduleKeys, 'retire preview did not display the complete bundle scope');
    moduleBundleExpect(
        in_array('MODULE_LIFECYCLE_PROTECTED', array_column($retirePreview['blockers'], 'code'), true),
        'protected content bundle retire preview lost its lifecycle blocker',
    );
    $ownedTables = moduleBundleTables($retirePreview['affected_modules']);
    $migrationCount = moduleBundleCount($pdo, 'pa_module_migration', $moduleKeys);
    moduleBundleExpect($migrationCount > 0, 'bundle install did not apply Module migrations');
    moduleBundleExpect(moduleBundleExistingTableCount($pdo, $ownedTables) === count($ownedTables), 'bundle owned table baseline is incomplete');

    try {
        $governance->uninstall('official.article', false, $retirePreview['confirm_plan'], $retirePreview['plan_digest']);
        throw new RuntimeException('protected content bundle retire unexpectedly executed');
    } catch (PluginLifecycleException $exception) {
        moduleBundleExpect($exception->errorCode === 'MODULE_UNINSTALL_BLOCKED', 'protected content bundle retire returned another blocker error');
    }
    moduleBundleExpect((int) $pdo->query("SELECT COUNT(*) FROM pa_plugin_installation WHERE plugin_key='official-content-bundle' AND status='active'")->fetchColumn() === 1, 'blocked content bundle retire changed package state');
    moduleBundleExpect(moduleBundleCount($pdo, 'pa_module_installation', $moduleKeys, 'active') === 2, 'blocked content bundle retire changed Module state');
    moduleBundleExpect(moduleBundleExistingTableCount($pdo, $ownedTables) === count($ownedTables), 'blocked content bundle retire changed owned tables');
    moduleBundleExpect(moduleBundleCount($pdo, 'pa_module_migration', $moduleKeys) === $migrationCount, 'blocked content bundle retire changed migration ledger');

    $purgePreview = $governance->preview('official.file', true);
    moduleBundleExpect(($purgePreview['confirm_plan']['package_key'] ?? null) === 'official-content-bundle', 'protected member key lost the bundle package');
    moduleBundleExpect(array_column($purgePreview['affected_modules'], 'module_key') === $moduleKeys, 'purge preview did not display the complete bundle scope');
    moduleBundleExpect(
        in_array('MODULE_LIFECYCLE_PROTECTED', array_column($purgePreview['blockers'], 'code'), true),
        'protected content bundle purge preview lost its lifecycle blocker',
    );
    $externalReferenceBlockers = array_values(array_filter(
        $purgePreview['blockers'],
        static fn(array $blocker): bool => ($blocker['code'] ?? null) === 'MODULE_OWNED_TABLE_EXTERNAL_REFERENCE',
    ));
    moduleBundleExpect(count($externalReferenceBlockers) === 1, 'content bundle purge did not expose its external file references');
    moduleBundleExpect(
        in_array(
            'pa_customer_service_setting.fk_customer_service_setting_qr_file->pa_file',
            (array) $externalReferenceBlockers[0]['identifiers'],
            true,
        ),
        'content bundle purge lost the customer-service file reference blocker',
    );
    try {
        $governance->uninstall('official.file', true, $purgePreview['confirm_plan'], $purgePreview['plan_digest']);
        throw new RuntimeException('protected content bundle purge unexpectedly executed');
    } catch (PluginLifecycleException $exception) {
        moduleBundleExpect($exception->errorCode === 'MODULE_UNINSTALL_BLOCKED', 'protected content bundle purge returned another blocker error');
    }
    moduleBundleExpect((int) $pdo->query("SELECT COUNT(*) FROM pa_plugin_installation WHERE plugin_key='official-content-bundle' AND status='active'")->fetchColumn() === 1, 'blocked content bundle purge changed package state');
    moduleBundleExpect(moduleBundleCount($pdo, 'pa_module_installation', $moduleKeys, 'active') === 2, 'blocked content bundle purge changed Module state');
    moduleBundleExpect(moduleBundleExistingTableCount($pdo, $ownedTables) === count($ownedTables), 'blocked content bundle purge changed owned tables');
    moduleBundleExpect(moduleBundleCount($pdo, 'pa_module_migration', $moduleKeys) === $migrationCount, 'blocked content bundle purge changed migration ledger');

    $taskManifestPath = $source . '/server/app/modules/official/task/module.json';
    $taskManifest = json_decode((string) file_get_contents($taskManifestPath), true, 64, JSON_THROW_ON_ERROR);
    $taskManifest['dependencies'] = [
        [
            'module_key' => 'official.file',
            'version' => '^2.0',
        ],
        [
            'module_key' => 'official.identity',
            'version' => '4.0.0-dev',
        ],
    ];
    $taskManifest['tenant']['requires'] = ['official.article'];
    file_put_contents($taskManifestPath, json_encode($taskManifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n");
    $recoverablePacked = $archive->packBundle(
        'official-runtime-bundle',
        '1.0.0',
        ['official.notification', 'official.task'],
        $recoverableArchivePath,
    );
    $recoverableModuleKeys = ['official.notification', 'official.task'];
    moduleBundleExpect($recoverablePacked['modules'] === $recoverableModuleKeys, 'recoverable bundle member order changed');
    $recoverableInstalled = $installer->install($recoverableArchivePath, $recoverablePacked['sha256'], null);
    moduleBundleExpect(($recoverableInstalled['operation'] ?? null) === 'installed', 'recoverable bundle was not installed');
    moduleBundleExpect(
        array_column((array) $recoverableInstalled['modules'], 'module_key') === $recoverableModuleKeys,
        'recoverable bundle install returned another scope',
    );
    $recoverableUnchanged = $installer->install($recoverableArchivePath, $recoverablePacked['sha256'], null);
    moduleBundleExpect(($recoverableUnchanged['operation'] ?? null) === 'unchanged', 'repeated recoverable bundle install was not idempotent');
    moduleBundleExpect((int) $pdo->query("SELECT COUNT(*) FROM pa_plugin_installation WHERE plugin_key='official-runtime-bundle' AND status='active'")->fetchColumn() === 1, 'recoverable bundle installation row is invalid');
    moduleBundleExpect(moduleBundleCount($pdo, 'pa_module_installation', $recoverableModuleKeys, 'active') === 2, 'recoverable bundle Module installation rows are invalid');
    moduleBundleExpect((int) $pdo->query("SELECT COUNT(*) FROM pa_plugin_module WHERE plugin_key='official-runtime-bundle'")->fetchColumn() === 2, 'recoverable bundle ownership rows are invalid');
    moduleBundleExpect(moduleBundleCount($pdo, 'pa_tenant_module', $recoverableModuleKeys) === 0, 'recoverable package install changed TenantModule enablement');

    $recoverableCatalogExpected = array_fill_keys(array_keys($catalogTables), 0);
    $recoverableDirectories = ['official.notification' => 'notification', 'official.task' => 'task'];
    foreach ($recoverableModuleKeys as $moduleKey) {
        $root = $target . '/server/app/modules/official/' . $recoverableDirectories[$moduleKey];
        $manifest = (new ManifestLoader())->load($root);
        $recoverableCatalogExpected['permissions'] += count((array) ($manifest->data['catalog']['permissions'] ?? []));
        $recoverableCatalogExpected['menus'] += count((array) ($manifest->data['catalog']['menus'] ?? []));
        $definitions = json_decode((string) file_get_contents($root . '/resources/setting-definitions.json'), true, 64, JSON_THROW_ON_ERROR);
        $recoverableCatalogExpected['settings'] += count((array) $definitions);
    }
    foreach ($catalogTables as $name => $table) {
        moduleBundleExpect(
            moduleBundleCount($pdo, $table, $recoverableModuleKeys, 'active') === $recoverableCatalogExpected[$name],
            "recoverable bundle {$name} catalog is not active",
        );
    }

    $moduleRuntime = new PlatformModuleRuntimeService(
        $target . '/server',
        $moduleConfig,
        [],
        $governance,
        new PluginCatalogSyncService($target . '/server', $moduleConfig, $catalogs),
        $catalogs,
    );
    $runtimeProjection = $moduleRuntime->modules(1, 100, 'official.task');
    $runtimeTask = $runtimeProjection['items'][0] ?? null;
    moduleBundleExpect(is_array($runtimeTask), 'runtime Module projection lost the Task member');
    moduleBundleExpect(
        ($runtimeTask['dependencies'] ?? null) === [
            ['module_key' => 'official.file', 'version' => '^2.0'],
            ['module_key' => 'official.identity', 'version' => '4.0.0-dev'],
        ],
        'runtime dependency projection did not distinguish explicit business dependencies from tenant requires',
    );
    $dependentPreview = $governance->preview('official.file', false);
    $businessBlockers = array_values(array_filter(
        $dependentPreview['blockers'],
        static fn(array $blocker): bool => ($blocker['kind'] ?? null) === 'business_dependency',
    ));
    moduleBundleExpect(
        ($businessBlockers[0]['identifiers'] ?? null) === ['official.task->official.file'],
        'active explicit business dependency did not block its provider Bundle',
    );
    $disabled = $moduleRuntime->disable('official.notification');
    moduleBundleExpect(($disabled['operation'] ?? null) === 'disabled', 'runtime bundle disable did not execute');
    moduleBundleExpect(($disabled['package_key'] ?? null) === 'official-runtime-bundle', 'runtime bundle disable returned another package');
    moduleBundleExpect(($disabled['affected_modules'] ?? null) === $recoverableModuleKeys, 'runtime bundle disable did not cover the complete Bundle');
    moduleBundleExpect(($disabled['status'] ?? null) === 'maintenance', 'runtime bundle disable returned another status');
    moduleBundleExpect(moduleBundleCount($pdo, 'pa_module_installation', $recoverableModuleKeys, 'maintenance') === 2, 'runtime bundle disable did not disable every member');
    $repeatDisable = $moduleRuntime->disable('official.task');
    moduleBundleExpect(($repeatDisable['operation'] ?? null) === 'unchanged', 'repeated runtime bundle disable was not idempotent');
    moduleBundleExpect(($repeatDisable['affected_modules'] ?? null) === $recoverableModuleKeys, 'repeated runtime bundle disable changed Bundle scope');
    $disabledDependentPreview = $governance->preview('official.file', false);
    moduleBundleExpect(
        !in_array('business_dependency', array_column($disabledDependentPreview['blockers'], 'kind'), true),
        'disabled business dependent continued to block its provider Bundle',
    );
    $disabledCurrentRelease = $temporary . '/release-disabled-current';
    $disabledTargetRelease = $temporary . '/release-disabled-target';
    moduleBundleCopyTree($target, $disabledCurrentRelease);
    moduleBundleCopyTree($target, $disabledTargetRelease);
    $disabledComposition = (new PluginReleaseCompositionGuard(
        $disabledTargetRelease,
        $moduleConfig,
        $catalogs,
    ))->verify($disabledCurrentRelease);
    $disabledOperations = array_column($disabledComposition['checked'], 'operation', 'key');
    moduleBundleExpect(
        ($disabledOperations['official-runtime-bundle'] ?? null) === 'preserve-disabled',
        'same-identity application release did not preserve a disabled Package',
    );

    $recoverableGovernance = new PluginRuntimeGovernanceService($target . '/server', $moduleConfig, $catalogs);
    $recoverableRetirePreview = $recoverableGovernance->preview('official.task', false);
    moduleBundleExpect(($recoverableRetirePreview['confirm_plan']['package_key'] ?? null) === 'official-runtime-bundle', 'recoverable member key did not resolve its bundle');
    moduleBundleExpect(array_column($recoverableRetirePreview['affected_modules'], 'module_key') === $recoverableModuleKeys, 'recoverable retire preview split its bundle scope');
    moduleBundleExpect($recoverableRetirePreview['blockers'] === [], 'recoverable bundle retire preview unexpectedly blocked');
    $recoverableOwnedTables = moduleBundleTables($recoverableRetirePreview['affected_modules']);
    $recoverableMigrationCount = moduleBundleCount($pdo, 'pa_module_migration', $recoverableModuleKeys);
    moduleBundleExpect($recoverableMigrationCount > 0, 'recoverable bundle did not apply Module migrations');
    moduleBundleExpect(moduleBundleExistingTableCount($pdo, $recoverableOwnedTables) === count($recoverableOwnedTables), 'recoverable bundle owned table baseline is incomplete');

    $recoverableRetired = $recoverableGovernance->uninstall(
        'official.task',
        false,
        $recoverableRetirePreview['confirm_plan'],
        $recoverableRetirePreview['plan_digest'],
    );
    moduleBundleExpect(($recoverableRetired['operation'] ?? null) === 'retired', 'recoverable bundle retire failed');
    moduleBundleExpect(moduleBundleExistingTableCount($pdo, $recoverableOwnedTables) === count($recoverableOwnedTables), 'recoverable bundle retire deleted owned tables');
    moduleBundleExpect(moduleBundleCount($pdo, 'pa_module_migration', $recoverableModuleKeys) === $recoverableMigrationCount, 'recoverable bundle retire deleted migration ledger');
    foreach ($catalogTables as $table) {
        moduleBundleExpect(moduleBundleCount($pdo, $table, $recoverableModuleKeys, 'active') === 0, 'recoverable bundle retire left active catalog rows');
    }
    $recoverableRepeatRetire = $recoverableGovernance->uninstall(
        'official.task',
        false,
        $recoverableRetirePreview['confirm_plan'],
        $recoverableRetirePreview['plan_digest'],
    );
    moduleBundleExpect(($recoverableRepeatRetire['operation'] ?? null) === 'unchanged', 'repeated recoverable bundle retire was not idempotent');

    $recoverablePurgePreview = (new PluginRuntimeGovernanceService($target . '/server', $moduleConfig, $catalogs))
        ->preview('official.notification', true);
    moduleBundleExpect(($recoverablePurgePreview['confirm_plan']['package_key'] ?? null) === 'official-runtime-bundle', 'retired recoverable member key lost its bundle');
    moduleBundleExpect(array_column($recoverablePurgePreview['affected_modules'], 'module_key') === $recoverableModuleKeys, 'recoverable purge preview split its bundle scope');
    moduleBundleExpect($recoverablePurgePreview['blockers'] === [], 'recoverable bundle purge preview unexpectedly blocked');

    try {
        (new PluginRuntimeGovernanceService(
            $target . '/server',
            $moduleConfig,
            $catalogs,
            static function (string $point): void {
                if ($point === 'after-first-module-drop') {
                    throw new RuntimeException('injected bundle interruption');
                }
            },
        ))->uninstall(
            'official.notification',
            true,
            $recoverablePurgePreview['confirm_plan'],
            $recoverablePurgePreview['plan_digest'],
        );
        throw new RuntimeException('bundle purge interruption was not injected');
    } catch (RuntimeException $exception) {
        moduleBundleExpect($exception->getMessage() === 'injected bundle interruption', 'unexpected bundle purge interruption');
    }
    $state = $pdo->query("SELECT status,last_error_code FROM pa_plugin_installation WHERE plugin_key='official-runtime-bundle'")->fetch();
    moduleBundleExpect($state === ['status' => 'maintenance', 'last_error_code' => 'MODULE_PURGE_IN_PROGRESS'], 'interrupted bundle purge marker changed');
    $interruptedModuleTableCounts = [];
    foreach ($recoverablePurgePreview['affected_modules'] as $module) {
        $interruptedModuleTableCounts[] = moduleBundleExistingTableCount($pdo, moduleBundleTables([$module]));
    }
    sort($interruptedModuleTableCounts, SORT_NUMERIC);
    moduleBundleExpect($interruptedModuleTableCounts[0] === 0 && $interruptedModuleTableCounts[1] > 0, 'bundle interruption did not stop between member completion points');
    moduleBundleExpect(moduleBundleCount($pdo, 'pa_module_migration', $recoverableModuleKeys) === $recoverableMigrationCount, 'interrupted bundle purge deleted migration ledger early');

    $purged = (new PluginRuntimeGovernanceService($target . '/server', $moduleConfig, $catalogs))
        ->uninstall(
            'official.notification',
            true,
            $recoverablePurgePreview['confirm_plan'],
            $recoverablePurgePreview['plan_digest'],
        );
    moduleBundleExpect(($purged['operation'] ?? null) === 'purged', 'bundle purge recovery did not finish');
    moduleBundleExpect(moduleBundleExistingTableCount($pdo, $recoverableOwnedTables) === 0, 'bundle purge left owned tables');
    moduleBundleExpect(moduleBundleCount($pdo, 'pa_module_migration', $recoverableModuleKeys) === 0, 'bundle purge left migration ledger');
    foreach ($catalogTables as $table) {
        moduleBundleExpect(moduleBundleCount($pdo, $table, $recoverableModuleKeys) === 0, 'bundle purge left catalog rows');
    }
    moduleBundleExpect(moduleBundleCount($pdo, 'pa_module_installation', $recoverableModuleKeys) === 0, 'bundle purge left Module installation rows');
    moduleBundleExpect((int) $pdo->query("SELECT COUNT(*) FROM pa_plugin_module WHERE plugin_key='official-runtime-bundle'")->fetchColumn() === 2, 'bundle purge deleted ownership history');
    $repeatPurge = (new PluginRuntimeGovernanceService($target . '/server', $moduleConfig, $catalogs))
        ->uninstall(
            'official.notification',
            true,
            $recoverablePurgePreview['confirm_plan'],
            $recoverablePurgePreview['plan_digest'],
        );
    moduleBundleExpect(($repeatPurge['operation'] ?? null) === 'unchanged', 'repeated bundle purge was not idempotent');

    $privateArchive = $temporary . '/private-fixture.tar';
    $privatePackage = (new PluginPackageArchiveService($projectRoot . '/server'))->packModule('fixture.delivery-record', $privateArchive);
    (new PluginPackageInstaller($target . '/server', $moduleConfig, [], $catalogs))->install($privateArchive, $privatePackage['sha256'], null);
    $privateLock = new \app\platform\infrastructure\plugin\PluginLockResolver($target . '/server', '../plugins.lock');
    $moduleGovernance = new \app\platform\infrastructure\module\ThinkPhpModuleGovernanceProvider(
        $target . '/server',
        $moduleConfig + ['plugin_lock' => '../plugins.lock'],
        $catalogs,
    );
    $profile = new \app\platform\services\module\ProductTenantModuleProfileService(
        new \PeanutAdmin\Modules\Identity\Module\Persistence\ThinkPhpModuleRuntimeRepository($moduleGovernance->registry()->compiled(), true),
        $moduleGovernance,
        new \app\common\services\audit\AuditContractHost(null),
    );
    foreach ([
        [['fixture.delivery-record'], \app\common\enum\instance\DeploymentMode::MultiTenant, 'PRIVATE_TENANT_MODULE_STANDALONE_REQUIRED'],
        [['official.file'], \app\common\enum\instance\DeploymentMode::Standalone, 'PRIVATE_TENANT_MODULE_NOT_LOCKED'],
        [['acme.absent'], \app\common\enum\instance\DeploymentMode::Standalone, 'PRIVATE_TENANT_MODULE_NOT_LOCKED'],
    ] as [$selection, $edition, $error]) {
        try {
            $profile->applyAdditionalInstallationSelection($selection, $edition, $privateLock);
            throw new RuntimeException('invalid private selection was accepted');
        } catch (\PeanutAdmin\Kernel\Module\ModuleException $exception) {
            moduleBundleExpect($exception->errorCode === $error, 'private selection boundary changed');
        }
    }
    $rbacBefore = $pdo->query('SELECT * FROM pa_role_permission ORDER BY role_id,permission_id')->fetchAll();
    $tenantRevision = $pdo->query("SELECT authorization_revision FROM pa_tenant WHERE code='default'")->fetchColumn();
    // A real database failure after enable proves row/revision/audit changes share the transaction.
    $pdo->exec("ALTER TABLE pa_tenant_audit_event ADD CONSTRAINT private_adoption_audit_failure CHECK (event_type <> 'tenant-module.profile-enabled')");
    try {
        $profile->applyAdditionalInstallationSelection(['fixture.delivery-record'], \app\common\enum\instance\DeploymentMode::Standalone, $privateLock);
        throw new RuntimeException('private additive audit failure was not injected');
    } catch (\think\db\exception\PDOException $exception) {
        moduleBundleExpect(str_contains($exception->getMessage(), 'private_adoption_audit_failure'), 'unexpected additive failure');
    } finally {
        $pdo->exec('ALTER TABLE pa_tenant_audit_event DROP CHECK private_adoption_audit_failure');
    }
    moduleBundleExpect((int) $pdo->query("SELECT COUNT(*) FROM pa_tenant_module WHERE module_key='fixture.delivery-record'")->fetchColumn() === 0, 'failed additive enable left a TenantModule row');
    moduleBundleExpect($pdo->query("SELECT authorization_revision FROM pa_tenant WHERE code='default'")->fetchColumn() === $tenantRevision, 'failed additive enable changed Tenant revision');
    $selection = $profile->applyAdditionalInstallationSelection(['fixture.delivery-record'], \app\common\enum\instance\DeploymentMode::Standalone, $privateLock);
    moduleBundleExpect($selection['binding_count'] === 1, 'private additive selection was not enabled');
    $openingBefore = $pdo->query("SELECT * FROM pa_tenant_module WHERE module_key='fixture.delivery-record'")->fetchAll();
    moduleBundleExpect($profile->applyAdditionalInstallationSelection(['fixture.delivery-record'], \app\common\enum\instance\DeploymentMode::Standalone, $privateLock)['binding_count'] === 0, 'private selection rewrote an effective opening');
    moduleBundleExpect($pdo->query("SELECT * FROM pa_tenant_module WHERE module_key='fixture.delivery-record'")->fetchAll() === $openingBefore, 'private selection changed existing opening configuration');
    moduleBundleExpect($pdo->query('SELECT * FROM pa_role_permission ORDER BY role_id,permission_id')->fetchAll() === $rbacBefore, 'private selection granted RBAC');
    $completed = true;
    echo "MODULE-BUNDLE-LIFECYCLE-001 passed database={$database} content_sha256={$packed['sha256']} recoverable_sha256={$recoverablePacked['sha256']}\n";
} finally {
    if ($targetLoader instanceof \Composer\Autoload\ClassLoader && $hostLoader instanceof \Composer\Autoload\ClassLoader) {
        $targetLoader->unregister();
        $hostLoader->register(true);
    }
    moduleBundleRemoveTree($temporary);
    IsolatedBackendEnvironment::cleanup();
    $pdo = null;
    $contender = null;
    $otherDatabase = null;
    if ($completed) {
        $admin->exec("DROP DATABASE `{$database}`");
        $admin->exec("DROP DATABASE `{$namespaceDatabase}`");
    } else {
        fwrite(STDERR, "BUNDLE_TEST_DATABASE_RETAINED={$database},{$namespaceDatabase}\n");
    }
}
