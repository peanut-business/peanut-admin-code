<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/route/registry_source.php';

require dirname(__DIR__, 2) . '/bootstrap/environment.php';

use PeanutAdmin\Modules\Ops\Service\DeploymentModuleRequestService;
use app\common\services\audit\AuditContractHost;
use PeanutAdmin\Modules\Ops\Infrastructure\ThinkPhpModuleOperationTaskExecutionService;
use PeanutAdmin\Modules\Ops\Infrastructure\ThinkPhpMaintenanceWindowStore;
use PeanutAdmin\Modules\Ops\Infrastructure\ThinkPhpOpsTaskDispatcher;
use PeanutAdmin\Modules\Ops\Infrastructure\PairedBackupProvider;
use PeanutAdmin\Modules\Ops\Domain\Task\BackupRestoreProviderRegistry;
use PeanutAdmin\Modules\Identity\Identity\Query\ThinkPhpPlatformOperatorIdentityQuery;
use PeanutAdmin\Modules\Ops\Service\PlatformModuleOperationExecutionService;
use PeanutAdmin\Modules\Ops\Infrastructure\Authorization\PlatformOpsPermissionChecker;
use app\platform\infrastructure\plugin\PluginPackageInstaller;
use app\platform\services\plugin\PluginPackageArchiveService;
use app\platform\services\plugin\PluginRuntimeGovernanceService;
use PeanutAdmin\Kernel\Auth\ValidatedPlatformSession;
use PeanutAdmin\Kernel\Authorization\RevisionPermissionCache;
use PeanutAdmin\Kernel\Context\PlatformContext;
use PeanutAdmin\Modules\Identity\Platform\Authorization\ThinkPhpPlatformAuthorizationRepository;
use PeanutAdmin\Kernel\Platform\Authorization\PlatformAuthorizationEvaluator;

require dirname(__DIR__, 2) . '/vendor/autoload.php';
require_once dirname(__DIR__, 2) . '/database/install.php';
require_once dirname(__DIR__) . '/Support/IsolatedBackendEnvironment.php';
require_once dirname(__DIR__) . '/Support/ThinkPhpTestConnection.php';

function moduleDeliveryExpect(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function moduleDeliveryCopyTree(string $source, string $target): void
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

function moduleDeliveryRemoveTree(string $path): void
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
function moduleDeliverySwapTargetComposerLoader(string $serverRoot, string $target): array
{
    $vendorRoot = realpath($serverRoot . '/vendor');
    $hostLoader = null;
    foreach (\Composer\Autoload\ClassLoader::getRegisteredLoaders() as $registeredVendor => $loader) {
        if ($vendorRoot !== false && realpath($registeredVendor) === $vendorRoot) {
            $hostLoader = $loader;
            break;
        }
    }
    moduleDeliveryExpect($hostLoader instanceof \Composer\Autoload\ClassLoader, 'verified host Composer loader is unavailable');
    $targetLoader = clone $hostLoader;
    $targetModuleRoots = [
        'app/modules/official/article/src',
        'app/modules/official/file/src',
        'app/modules/official/identity/src',
        'app/modules/official/ops/src',
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

function moduleDeliverySetVersion(string $root, string $module, string $version): void
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

$serverRoot = dirname(__DIR__, 2);
$projectRoot = dirname($serverRoot);
$resourceId = IsolatedBackendEnvironment::required('PEANUT_DATABASE_RESOURCE_ID');
$resource = IsolatedBackendEnvironment::requireRegisteredDatabase(
    $projectRoot . '/resources/project-resources.json',
    $resourceId,
);
$database = $argv[1] ?? IsolatedBackendEnvironment::required('DB_NAME');
moduleDeliveryExpect($database === IsolatedBackendEnvironment::required('DB_NAME'), 'test database must match the selected backend environment');
moduleDeliveryExpect(
    ($resource['upstream_endpoint']['endpoint_id'] ?? null) === IsolatedBackendEnvironment::required('PEANUT_DATABASE_ENDPOINT_ID'),
    'registered database endpoint identity is required',
);
moduleDeliveryExpect(
    in_array($database, (array) ($resource['synthetic_databases']['module_delivery_standalone'] ?? []), true),
    'registered delivery database is required',
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
moduleDeliveryExpect((int) $exists->fetchColumn() === 0, 'isolated database already exists');
$admin->exec("CREATE DATABASE `{$database}` CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci");

IsolatedBackendEnvironment::activate([
    'APP_ENV' => 'development',
    'APP_DEBUG' => 'true',
    'DEPLOYMENT_MODE' => 'standalone',
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
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES => false],
);
$app = new think\App($serverRoot);
$app->initialize();
$catalogs = ThinkPhpTestConnection::moduleCatalogs($pdo);
$identity = initializeCoreIdentity(
    $pdo,
    'module-delivery@example.test',
    'module-delivery-password',
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
executeSqlFiles($pdo, [$serverRoot . '/database/init.sql']);
$migrations = glob($serverRoot . '/database/migrations/*.sql') ?: [];
sort($migrations, SORT_STRING);
executeSqlFiles($pdo, $migrations);
executeSqlFiles($pdo, [
    $serverRoot . '/app/modules/official/reference_codes/database/migrations/20260921-adopt-reference-codes-schema.sql',
]);

$projectRoot = dirname($serverRoot);
$temporary = realpath(sys_get_temp_dir()) . '/pa-module-delivery-' . substr(hash('sha256', $database), 0, 11);
moduleDeliveryExpect(!file_exists($temporary), 'isolated output already exists');
$source = $temporary . '/source';
$target = $temporary . '/target';
$packageDirectory = $target . '/.ops/module-packages';
$requestDirectory = $target . '/.ops/module-requests';
$registryPath = $target . '/resources/project-resources.json';
$hostLoader = null;
$targetLoader = null;
$completed = false;

try {
    foreach (['Article', 'File'] as $module) {
        $directory = strtolower((string) preg_replace('/(?<!^)[A-Z]/', '_$0', $module));
        moduleDeliveryCopyTree(
            $projectRoot . '/server/app/modules/official/' . $directory,
            $source . '/server/app/modules/official/' . $directory,
        );
        moduleDeliveryCopyTree(
            $projectRoot . '/web/src/modules/official-' . strtolower($module),
            $source . '/web/src/modules/official-' . strtolower($module),
        );
    }
    moduleDeliveryCopyTree(
        $projectRoot . '/server/app/modules/official/identity',
        $source . '/server/app/modules/official/identity',
    );
    moduleDeliveryCopyTree(
        $projectRoot . '/server/app/modules/official/identity',
        $target . '/server/app/modules/official/identity',
    );
    moduleDeliveryCopyTree(
        $projectRoot . '/server/app/modules/official/ops',
        $target . '/server/app/modules/official/ops',
    );
    moduleDeliveryCopyTree(
        $projectRoot . '/plugins/official.identity',
        $target . '/plugins/official.identity',
    );
    moduleDeliveryExpect(
        symlink($serverRoot . '/vendor', $target . '/server/vendor'),
        'isolated target must use the verified host Composer vendor root',
    );
    $baseLock = json_decode((string) file_get_contents($projectRoot . '/plugins.lock'), true, 64, JSON_THROW_ON_ERROR);
    $identityEntries = array_values(array_filter(
        (array) ($baseLock['plugins'] ?? []),
        static fn(mixed $entry): bool => is_array($entry) && ($entry['key'] ?? null) === 'official.identity',
    ));
    moduleDeliveryExpect(count($identityEntries) === 1, 'installed identity baseline is missing from the source lock');
    file_put_contents(
        $target . '/plugins.lock',
        json_encode(['schema_version' => 1, 'plugins' => $identityEntries], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n",
    );
    foreach ([$source, $target] as $root) {
        mkdir($root . '/server/resources/schemas', 0777, true);
        copy($serverRoot . '/resources/schemas/plugin.schema.json', $root . '/server/resources/schemas/plugin.schema.json');
    }
    mkdir($packageDirectory, 0700, true);
    mkdir($requestDirectory, 0700, true);
    mkdir(dirname($registryPath), 0777, true);
    $registry = [
        'resources' => [
            'tooling' => [[
                'stable_resource_id' => 'fixture-module-delivery',
                'environments' => ['development'],
                'service_type' => 'operator-triggered repository CLI worker over registered SSH deployment transport',
                'deployment_resource_id' => 'fixture-target',
                'deployment_root' => $target,
                'request_directory' => $requestDirectory,
                'package_directory' => $packageDirectory,
                'fallback' => 'none',
            ]],
            'deployments' => [[
                'stable_resource_id' => 'fixture-target',
                'environments' => ['development'],
                'deployment_root' => $target,
            ]],
        ],
    ];
    file_put_contents($registryPath, json_encode($registry, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n");

    $config = ['kernel_version' => '1.0.0', 'registered_client_keys' => ['admin-web', 'platform-web']];
    $archive = new PluginPackageArchiveService($source . '/server');
    $v1Path = $temporary . '/v1.tar';
    $v1 = $archive->packBundle('official-content-bundle', '1.0.0', ['official.article', 'official.file'], $v1Path);
    [$hostLoader, $targetLoader] = moduleDeliverySwapTargetComposerLoader($serverRoot, $target);
    $installed = (new PluginPackageInstaller($target . '/server', $config, [], $catalogs))
        ->install($v1Path, $v1['sha256'], null);
    moduleDeliveryExpect(($installed['operation'] ?? null) === 'installed', 'fixture v1 install failed');

    moduleDeliverySetVersion($source, 'Article', '2.0.0');
    moduleDeliverySetVersion($source, 'File', '2.0.0');
    $v2Temporary = $temporary . '/v2.tar';
    $v2 = $archive->packBundle('official-content-bundle', '2.0.0', ['official.article', 'official.file'], $v2Temporary);
    $v2Path = $packageDirectory . '/' . $v2['sha256'] . '.tar';
    rename($v2Temporary, $v2Path);

    $requests = new DeploymentModuleRequestService(
        $target,
        $config,
        [],
        new PluginRuntimeGovernanceService($target . '/server', $config, $catalogs),
        $catalogs,
        $registryPath,
    );
    $preview = $requests->preview(
        'fixture-module-delivery',
        'fixture-target',
        'update',
        'official-content-bundle',
        $v2['sha256'],
        null,
    );
    moduleDeliveryExpect(($preview['plan']['dry_run'] ?? null) === true, 'request preview was not dry-run');
    $prepared = $requests->prepare(
        'fixture-module-delivery',
        'fixture-target',
        'update',
        'official-content-bundle',
        $v2['sha256'],
        null,
        null,
    );
    moduleDeliveryExpect(preg_match('/^modreq_[a-f0-9]{32}$/D', (string) $prepared['request_key']) === 1, 'opaque request key changed');

    $operator = $pdo->prepare('SELECT account_id FROM pa_platform_operator WHERE id=?');
    $operator->execute([$identity['operator_id']]);
    $operatorAccountId = (int) $operator->fetchColumn();
    $context = PlatformContext::fromValidatedSession(new ValidatedPlatformSession(
        $identity['operator_id'],
        'module-delivery-session',
        $operatorAccountId,
        $identity['operator_id'],
        'platform-web',
        new DateTimeImmutable('+1 hour'),
    ), 'module-delivery-request');
    $runtime = static fn(): array => [
        'commit' => str_repeat('a', 40),
        'tree' => str_repeat('b', 40),
        'health' => 'healthy',
        'repository_clean' => true,
    ];
    $audit = new AuditContractHost(null);
    $tasks = new ThinkPhpOpsTaskDispatcher($audit);
    $maintenance = new ThinkPhpMaintenanceWindowStore($audit);
    $platform = new PlatformModuleOperationExecutionService(
        $tasks,
        $requests,
        $runtime,
        new PlatformOpsPermissionChecker(new PlatformAuthorizationEvaluator(
            new ThinkPhpPlatformAuthorizationRepository(),
            new RevisionPermissionCache(),
        )),
    );
    $submitted = $platform->submit($context, (string) $prepared['request_key'], 'module-delivery-idempotency');
    moduleDeliveryExpect(($submitted['status'] ?? null) === 'queued', 'Module operation was not queued');

    $executor = new ThinkPhpModuleOperationTaskExecutionService(
        $audit,
        $tasks,
        $maintenance,
        $requests,
        new BackupRestoreProviderRegistry([new PairedBackupProvider()]),
        $runtime,
        new ThinkPhpPlatformOperatorIdentityQuery(),
    );
    $claimed = $executor->claim();
    moduleDeliveryExpect(is_array($claimed) && ($claimed['current_step'] ?? null) === 'preflight', 'Module operation was not claimed');
    $taskKey = (string) $claimed['task_key'];
    $revision = (int) $claimed['execution_revision'];
    $backupAction = $executor->advance($taskKey, $revision);
    moduleDeliveryExpect(($backupAction['action'] ?? null) === 'run_backup', 'preflight did not dispatch backup');
    $backupTask = (string) $backupAction['child_task_key'];
    $pdo->prepare("UPDATE pa_ops_task SET status='succeeded',completed_at=UTC_TIMESTAMP(3) WHERE task_key=?")
        ->execute([$backupTask]);
    $backupReference = 'backup_' . str_repeat('c', 32);
    $pdo->prepare(<<<'SQL'
INSERT INTO pa_ops_backup_evidence (
 backup_reference_key,task_key,provider_key,manifest_sha256,source_commit,source_tree,
 consistency_started_at,consistency_completed_at,verified_at,manifest_json
) VALUES (?,?,'peanut.paired-db-files',?,?,?,UTC_TIMESTAMP(3),UTC_TIMESTAMP(3),UTC_TIMESTAMP(3),'{}')
SQL)->execute([$backupReference, $backupTask, str_repeat('d', 64), str_repeat('a', 40), str_repeat('b', 40)]);

    $restoreAction = $executor->advance($taskKey, $revision);
    moduleDeliveryExpect(($restoreAction['action'] ?? null) === 'run_restore', 'backup did not dispatch restore verification');
    $restoreTask = (string) $restoreAction['child_task_key'];
    $pdo->prepare("UPDATE pa_ops_task SET status='succeeded',completed_at=UTC_TIMESTAMP(3) WHERE task_key=?")
        ->execute([$restoreTask]);
    $pdo->prepare(<<<'SQL'
INSERT INTO pa_ops_restore_evidence (
 task_key,backup_reference_key,provider_key,target_key,manifest_sha256,evidence_sha256,
 source_commit,source_tree,target_deployment_resource_id,target_database_resource_id,
 target_runtime_resource_id,table_count,schema_migration_count,critical_table_count,
 account_count,tenant_count,tenant_member_count,storage_file_count,storage_bytes,
 protected_runtime_sha256,verified_at,evidence_json
) VALUES (?,?,'peanut.paired-db-files','isolated-new-target',?,?,?,?,?,'fixture-db','fixture-runtime',
 1,0,6,1,1,1,0,0,?,UTC_TIMESTAMP(3),'{}')
SQL)->execute([
        $restoreTask, $backupReference, str_repeat('d', 64), str_repeat('e', 64),
        str_repeat('a', 40), str_repeat('b', 40), 'fixture-restore', str_repeat('f', 64),
    ]);

    $executeAction = $executor->advance($taskKey, $revision);
    moduleDeliveryExpect(($executeAction['action'] ?? null) === 'execute', 'restore did not establish maintenance');
    moduleDeliveryExpect((int) $pdo->query("SELECT COUNT(*) FROM pa_ops_maintenance_window WHERE state='active' AND reason_key='module-lifecycle'")->fetchColumn() === 1, 'maintenance window is not active');
    $operation = $executor->execute($taskKey, $revision);
    moduleDeliveryExpect(($operation['action'] ?? null) === 'run_smoke', 'Package update did not enter smoke');
    $succeeded = $executor->succeed($taskKey, $revision);
    moduleDeliveryExpect(($succeeded['status'] ?? null) === 'succeeded', 'Module operation did not complete');
    moduleDeliveryExpect((int) $pdo->query("SELECT COUNT(*) FROM pa_plugin_installation WHERE plugin_key='official-content-bundle' AND installed_version='2.0.0' AND status='active'")->fetchColumn() === 1, 'Package identity did not reach v2');
    moduleDeliveryExpect((int) $pdo->query("SELECT COUNT(*) FROM pa_tenant_module WHERE module_key IN ('official.article','official.file')")->fetchColumn() === 0, 'Module operation changed TenantModule state');
    moduleDeliveryExpect((int) $pdo->query("SELECT COUNT(*) FROM pa_ops_maintenance_window WHERE state='closed'")->fetchColumn() === 1, 'successful smoke did not close maintenance');
    moduleDeliveryExpect((int) $pdo->query("SELECT COUNT(*) FROM pa_ops_module_execution WHERE current_step='completed' AND recovery_pointer_sha256 IS NOT NULL")->fetchColumn() === 1, 'recovery pointer was not persisted');

    $route = peanut_route_registry_source($serverRoot);
    $controller = (string) file_get_contents($serverRoot . '/app/platform/controller/PlatformOpsController.php');
    moduleDeliveryExpect(str_contains($route, "v1/ops/tasks/module"), 'opaque Module task route is missing');
    moduleDeliveryExpect(!str_contains($controller, "archive_sha256'") && !str_contains($controller, "package_key'"), 'production HTTP accepts Module package details');

    $completed = true;
    echo "MODULE-DELIVERY-OPERATION-001 passed database={$database} request={$prepared['request_key']} task={$taskKey}\n";
} finally {
    if ($targetLoader instanceof \Composer\Autoload\ClassLoader && $hostLoader instanceof \Composer\Autoload\ClassLoader) {
        $targetLoader->unregister();
        $hostLoader->register(true);
    }
    moduleDeliveryRemoveTree($temporary);
    IsolatedBackendEnvironment::cleanup();
    $pdo = null;
    if ($completed) {
        $admin->exec("DROP DATABASE `{$database}`");
    } else {
        fwrite(STDERR, "MODULE_DELIVERY_TEST_DATABASE_RETAINED={$database}\n");
    }
}
