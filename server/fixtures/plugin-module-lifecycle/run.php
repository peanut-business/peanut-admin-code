<?php

declare(strict_types=1);

use PeanutAdmin\Fixtures\DeliveryRecord\ModuleProvider;
use app\common\execution\AdminExecutionContext;
use app\common\execution\CurrentExecutionContext;
use app\common\execution\ExecutionContextStore;
use app\common\infrastructure\module\ModuleExecutionBoundary;
use app\platform\exception\plugin\PluginLifecycleException;
use app\platform\services\plugin\PluginLifecycleService;
use app\platform\infrastructure\plugin\PluginLockResolver;
use app\platform\composition\plugin\PluginModuleRegistryFactory;
use app\platform\infrastructure\plugin\ModuleCatalogApplier;
use app\platform\infrastructure\plugin\ModuleMigrationSqlExecutor;
use PeanutAdmin\Kernel\Auth\TenantContext;
use PeanutAdmin\Kernel\Auth\ValidatedTenantSession;
use PeanutAdmin\Kernel\Module\ModuleException;
use PeanutAdmin\Modules\Settings\Definition\SettingDefinitionSynchronizer;
use think\App;

require dirname(__DIR__, 2) . '/bootstrap/environment.php';
require dirname(__DIR__, 2) . '/vendor/autoload.php';

function pluginLifecycleExpect(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function pluginLifecycleTableExists(PDO $pdo, string $table): bool
{
    $statement = $pdo->prepare(
        'SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name=?',
    );
    $statement->execute([$table]);
    return (int) $statement->fetchColumn() === 1;
}

/** @param list<string> $roots */
function pluginLifecycleCanonicalDigest(string $projectRoot, array $roots): string
{
    $files = [];
    foreach ($roots as $root) {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
        );
        foreach ($iterator as $file) {
            if ($file->isFile() && !$file->isLink()) {
                $relative = str_replace('\\', '/', substr($file->getPathname(), strlen($projectRoot) + 1));
                $files[$relative] = hash_file('sha256', $file->getPathname());
            }
        }
    }
    ksort($files, SORT_STRING);
    $canonical = '';
    foreach ($files as $relative => $digest) {
        $canonical .= $relative . "\0" . $digest . "\n";
    }
    return hash('sha256', $canonical);
}

/** @return array{lock:string,manifest:string,migration:string} */
function pluginLifecycleFailureArtifact(string $projectRoot): array
{
    $migration = $projectRoot
        . '/server/app/modules/fixture/delivery_record/database/migrations/20260814999999_failure_fixture.sql';
    file_put_contents($migration, "THIS IS AN INTENTIONAL FIXTURE FAILURE;\n");
    $manifest = json_decode(
        (string) file_get_contents($projectRoot . '/plugins/fixture.delivery-record/plugin.json'),
        true,
        64,
        JSON_THROW_ON_ERROR,
    );
    $digest = pluginLifecycleCanonicalDigest($projectRoot, [
        $projectRoot . '/server/app/modules/fixture/delivery_record',
        $projectRoot . '/web/src/modules/fixture-delivery-record',
    ]);
    $manifest['source']['sha256'] = $digest;
    $manifestPath = tempnam(sys_get_temp_dir(), 'pa-plugin-manifest-');
    pluginLifecycleExpect(is_string($manifestPath), 'temporary Plugin manifest is unavailable');
    file_put_contents(
        $manifestPath,
        json_encode($manifest, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n",
    );
    $lock = json_decode(
        (string) file_get_contents($projectRoot . '/plugins.lock'),
        true,
        64,
        JSON_THROW_ON_ERROR,
    );
    $lock['plugins'][0]['source']['sha256'] = $digest;
    $lock['plugins'][0]['manifest'] = $manifestPath;
    $lock['plugins'][0]['manifest_sha256'] = hash_file('sha256', $manifestPath);
    $lockPath = tempnam(sys_get_temp_dir(), 'pa-plugin-lock-');
    pluginLifecycleExpect(is_string($lockPath), 'temporary Plugin lock is unavailable');
    file_put_contents(
        $lockPath,
        json_encode($lock, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n",
    );
    return ['lock' => $lockPath, 'manifest' => $manifestPath, 'migration' => $migration];
}

/** @return array{lock:string,manifest:string,migration:string} */
function pluginLifecycleRepairArtifact(string $projectRoot): array
{
    $migration = $projectRoot
        . '/server/app/modules/fixture/delivery_record/database/migrations/20260815000000_failure_repair.sql';
    file_put_contents(
        $migration,
        "-- peanut-admin-repairs: fixture.delivery-record:20260814999999_failure_fixture\nSELECT 1;\n",
    );
    $manifest = json_decode(
        (string) file_get_contents($projectRoot . '/plugins/fixture.delivery-record/plugin.json'),
        true,
        64,
        JSON_THROW_ON_ERROR,
    );
    $manifest['version'] = '1.0.1';
    $manifest['source']['sha256'] = pluginLifecycleCanonicalDigest($projectRoot, [
        $projectRoot . '/server/app/modules/fixture/delivery_record',
        $projectRoot . '/web/src/modules/fixture-delivery-record',
    ]);
    $manifestPath = tempnam(sys_get_temp_dir(), 'pa-plugin-repair-manifest-');
    pluginLifecycleExpect(is_string($manifestPath), 'temporary repair Plugin manifest is unavailable');
    file_put_contents(
        $manifestPath,
        json_encode($manifest, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n",
    );
    $lock = json_decode(
        (string) file_get_contents($projectRoot . '/plugins.lock'),
        true,
        64,
        JSON_THROW_ON_ERROR,
    );
    foreach ($lock['plugins'] as &$plugin) {
        if (($plugin['key'] ?? null) !== 'fixture.delivery-record') {
            continue;
        }
        $plugin['version'] = '1.0.1';
        $plugin['source']['sha256'] = $manifest['source']['sha256'];
        $plugin['manifest'] = $manifestPath;
        $plugin['manifest_sha256'] = hash_file('sha256', $manifestPath);
    }
    unset($plugin);
    $lockPath = tempnam(sys_get_temp_dir(), 'pa-plugin-repair-lock-');
    pluginLifecycleExpect(is_string($lockPath), 'temporary repair Plugin lock is unavailable');
    file_put_contents(
        $lockPath,
        json_encode($lock, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n",
    );
    return ['lock' => $lockPath, 'manifest' => $manifestPath, 'migration' => $migration];
}

/** @return array{permissions:list<array<string,mixed>>,menus:list<array<string,mixed>>} */
function pluginLifecycleCatalogSnapshot(PDO $pdo): array
{
    return [
        'permissions' => $pdo->query('SELECT * FROM pa_permission ORDER BY id')->fetchAll(),
        'menus' => $pdo->query('SELECT * FROM pa_menu_definition ORDER BY id')->fetchAll(),
    ];
}

/** @param list<array<string,mixed>> $rows */
function pluginLifecycleRestoreRows(PDO $pdo, string $table, array $rows): void
{
    pluginLifecycleExpect(
        in_array($table, ['pa_permission', 'pa_menu_definition'], true),
        'fixture catalog restore table is invalid',
    );
    $originalIds = array_map(static fn(array $row): int => (int) $row['id'], $rows);
    $currentIds = $pdo->query("SELECT id FROM {$table}")->fetchAll(PDO::FETCH_COLUMN);
    $delete = $pdo->prepare("DELETE FROM {$table} WHERE id=?");
    foreach ($currentIds as $id) {
        if (!in_array((int) $id, $originalIds, true)) {
            $delete->execute([(int) $id]);
        }
    }
    foreach ($rows as $row) {
        $id = (int) $row['id'];
        unset($row['id']);
        $assignments = array_map(static fn(string $column): string => "`{$column}`=?", array_keys($row));
        $statement = $pdo->prepare(
            "UPDATE {$table} SET " . implode(',', $assignments) . ' WHERE id=?',
        );
        $statement->execute([...array_values($row), $id]);
    }
}

/** @param array{permissions:list<array<string,mixed>>,menus:list<array<string,mixed>>} $snapshot */
function pluginLifecycleCleanup(PDO $pdo, array $snapshot): void
{
    $pdo->exec("DELETE rp FROM pa_role_permission rp JOIN pa_permission p ON p.id=rp.permission_id WHERE p.module_key='fixture.delivery-record'");
    $pdo->exec("DELETE FROM pa_tenant_module WHERE module_key='fixture.delivery-record'");
    $pdo->exec("DELETE FROM pa_setting_target_value WHERE definition_id IN (SELECT id FROM pa_setting_definition WHERE module_key='fixture.delivery-record')");
    $pdo->exec("DELETE FROM pa_setting_tenant_value WHERE definition_id IN (SELECT id FROM pa_setting_definition WHERE module_key='fixture.delivery-record')");
    $pdo->exec("DELETE FROM pa_setting_deployment_value WHERE definition_id IN (SELECT id FROM pa_setting_definition WHERE module_key='fixture.delivery-record')");
    $pdo->exec("DELETE FROM pa_setting_definition WHERE module_key='fixture.delivery-record'");
    pluginLifecycleRestoreRows($pdo, 'pa_menu_definition', $snapshot['menus']);
    pluginLifecycleRestoreRows($pdo, 'pa_permission', $snapshot['permissions']);
    $pdo->exec("DELETE FROM pa_module_migration WHERE module_key='fixture.delivery-record'");
    $pdo->exec("DELETE FROM pa_plugin_module WHERE module_key='fixture.delivery-record'");
    $pdo->exec("DELETE FROM pa_module_installation WHERE module_key='fixture.delivery-record'");
    $pdo->exec("DELETE FROM pa_plugin_installation WHERE plugin_key='fixture.delivery-record'");
    $pdo->exec('DROP TABLE IF EXISTS pa_fixture_delivery_record');
}

$serverRoot = dirname(__DIR__, 2);
$projectRoot = dirname($serverRoot);
$host = getenv('DB_HOST') ?: '';
$port = getenv('DB_PORT') ?: '';
$name = getenv('DB_NAME') ?: '';
$user = getenv('DB_USER') ?: '';
$pass = getenv('DB_PASS') ?: '';
pluginLifecycleExpect($host !== '' && $port !== '' && $name !== '' && $user !== '', 'registered database environment is required');
(new App($serverRoot))->initialize();
$pdo = new PDO(
    "mysql:host={$host};port={$port};dbname={$name};charset=utf8mb4",
    $user,
    $pass,
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC],
);

$requiredTables = [
    'pa_tenant', 'pa_tenant_member', 'pa_role', 'pa_member_role', 'pa_role_permission',
    'pa_tenant_module', 'pa_module_installation', 'pa_plugin_installation', 'pa_plugin_module',
    'pa_module_migration', 'pa_permission', 'pa_menu_definition', 'pa_setting_definition',
];
foreach ($requiredTables as $table) {
    pluginLifecycleExpect(pluginLifecycleTableExists($pdo, $table), "standard migration is missing: {$table}");
}
pluginLifecycleExpect(
    (int) $pdo->query("SELECT COUNT(*) FROM pa_plugin_installation WHERE plugin_key='fixture.delivery-record'")->fetchColumn() === 0,
    'fixture Plugin state already exists; refusing non-auditable cleanup',
);
pluginLifecycleExpect(
    (int) $pdo->query("SELECT COUNT(*) FROM pa_module_installation WHERE module_key<>'fixture.delivery-record' AND status='active'")->fetchColumn() === 0,
    'another active deployment Module exists; refusing global catalog synchronization in a shared database',
);
$catalogSnapshot = pluginLifecycleCatalogSnapshot($pdo);

$config = [
    'plugin_lock' => '../plugins.lock',
    'kernel_version' => '1.0.0',
    'registered_client_keys' => ['admin-web', 'platform-web'],
];
$catalogs = new ModuleCatalogApplier(
    new SettingDefinitionSynchronizer(),
    new \PeanutAdmin\Modules\Identity\Authorization\ModuleAuthorizationCatalogSynchronizer(
        new \PeanutAdmin\Modules\Identity\Authorization\Persistence\ThinkPhpAuthorizationCatalogRepository(),
    ),
    new \PeanutAdmin\Modules\Identity\Menu\ThinkPhpMenuCatalogRepository(),
);
$artifact = null;
$repairArtifact = null;
try {
    $resolver = new PluginLockResolver($serverRoot, '../plugins.lock');
    $service = new PluginLifecycleService(
        $resolver,
        new PluginModuleRegistryFactory($serverRoot),
        $config,
        $catalogs,
        new ModuleMigrationSqlExecutor(),
    );
    $first = $service->install('fixture.delivery-record');
    pluginLifecycleExpect(($first['status'] ?? null) === 'active', 'Plugin did not activate');
    pluginLifecycleExpect(
        (int) $pdo->query("SELECT COUNT(*) FROM pa_tenant_module WHERE module_key='fixture.delivery-record'")->fetchColumn() === 0,
        'install enabled a TenantModule',
    );
    pluginLifecycleExpect(($service->install('fixture.delivery-record')['operation'] ?? null) === 'unchanged', 'repeat install was not idempotent');
    $migrationCountBeforeResume = (int) $pdo->query("SELECT COUNT(*) FROM pa_module_migration WHERE module_key='fixture.delivery-record'")->fetchColumn();
    $pdo->exec("UPDATE pa_plugin_installation SET status='installing' WHERE plugin_key='fixture.delivery-record'");
    $pdo->exec("UPDATE pa_module_installation SET status='installing' WHERE module_key='fixture.delivery-record'");
    $resumed = $service->install('fixture.delivery-record');
    pluginLifecycleExpect(($resumed['operation'] ?? null) === 'resumed', 'interrupted installing state did not resume');
    pluginLifecycleExpect(
        (int) $pdo->query("SELECT COUNT(*) FROM pa_module_migration WHERE module_key='fixture.delivery-record'")->fetchColumn() === $migrationCountBeforeResume,
        'install resume duplicated an applied migration ledger row',
    );
    pluginLifecycleExpect(
        (string) $pdo->query("SELECT status FROM pa_plugin_installation WHERE plugin_key='fixture.delivery-record'")->fetchColumn() === 'active',
        'install resume did not finalize Plugin activation',
    );
    pluginLifecycleExpect(($service->upgrade('fixture.delivery-record', true)['dry_run'] ?? null) === true, 'upgrade dry-run was not a plan');
    pluginLifecycleExpect(($service->rollbackPlan('fixture.delivery-record')['automatic'] ?? null) === false, 'rollback became automatic');

    $member = $pdo->query(<<<'SQL'
SELECT tm.tenant_id,tm.id AS member_id,tm.account_id,mr.role_id
FROM pa_tenant_member tm
JOIN pa_tenant t ON t.id=tm.tenant_id AND t.status='active'
JOIN pa_member_role mr ON mr.tenant_id=tm.tenant_id AND mr.tenant_member_id=tm.id
JOIN pa_role r ON r.tenant_id=mr.tenant_id AND r.id=mr.role_id AND r.status='active'
WHERE tm.status='active' ORDER BY tm.tenant_id,tm.id,mr.role_id LIMIT 1
SQL)->fetch();
    pluginLifecycleExpect(is_array($member), 'active tenant member with role is unavailable');
    $context = TenantContext::fromValidatedSession(new ValidatedTenantSession(
        (int) $member['member_id'],
        '01JPLUGINMODULEFIXTURE000001',
        (int) $member['tenant_id'],
        (int) $member['account_id'],
        (int) $member['member_id'],
        'admin-web',
        new DateTimeImmutable('now', new DateTimeZone('UTC')),
        1,
    ), 'plugin-module-fixture');
    $executionContexts = new ExecutionContextStore();
    $executionContext = new CurrentExecutionContext($executionContexts);
    $commands = (new ModuleProvider())->commands($executionContext);
    $modules = new ModuleExecutionBoundary($executionContext);
    $record = static fn(string $reference): array => $executionContexts->run(
        new AdminExecutionContext($context, 'fixture.delivery-record.record'),
        static function () use ($modules, $commands, $reference): array {
            $modules->assertHttp('fixture.delivery-record', 'fixture.delivery-record.record');
            return $commands->record($reference);
        },
    );
    foreach (['not-enabled', 'not-authorized'] as $phase) {
        if ($phase === 'not-authorized') {
            $now = gmdate('Y-m-d H:i:s.v');
            $enable = $pdo->prepare(<<<'SQL'
INSERT INTO pa_tenant_module (
 tenant_id,module_key,status,source,config_json,config_revision,authorization_revision,
 effective_at,enabled_at,created_at,updated_at
) VALUES (:tenant_id,'fixture.delivery-record','enabled','manual','{}',1,1,:now,:now,:now,:now)
SQL);
            $enable->execute(['tenant_id' => (int) $member['tenant_id'], 'now' => $now]);
        }
        try {
            $record($phase);
            throw new RuntimeException("{$phase} write was accepted");
        } catch (ModuleException $exception) {
            $expectedCode = $phase === 'not-enabled'
                ? 'MODULE_TENANT_DISABLED'
                : 'AUTHORIZATION_PERMISSION_DENIED';
            pluginLifecycleExpect($exception->errorCode === $expectedCode, "{$phase} refusal changed");
        }
    }
    $permissionId = (int) $pdo->query("SELECT id FROM pa_permission WHERE `key`='fixture.delivery-record.create'")->fetchColumn();
    $grant = $pdo->prepare(<<<'SQL'
INSERT INTO pa_role_permission (tenant_id,role_id,permission_id,granted_by_member_id,granted_at)
VALUES (:tenant_id,:role_id,:permission_id,:member_id,:now)
SQL);
    $grant->execute([
        'tenant_id' => (int) $member['tenant_id'],
        'role_id' => (int) $member['role_id'],
        'permission_id' => $permissionId,
        'member_id' => (int) $member['member_id'],
        'now' => gmdate('Y-m-d H:i:s.v'),
    ]);
    pluginLifecycleExpect($record('authorized')['status'] === 'recorded', 'authorized write failed');
    try {
        $service->uninstall('fixture.delivery-record');
        throw new RuntimeException('uninstall ignored an enabled TenantModule');
    } catch (PluginLifecycleException $exception) {
        pluginLifecycleExpect($exception->errorCode === 'PLUGIN_TENANT_MODULE_ACTIVE', 'uninstall refusal code changed');
    }
    $pdo->exec("UPDATE pa_tenant_module SET status='disabled',disabled_at=UTC_TIMESTAMP(3),disabled_reason='fixture-test' WHERE module_key='fixture.delivery-record'");
    try {
        $record('disabled-after-grant');
        throw new RuntimeException('disabled Module accepted a command after permission grant');
    } catch (ModuleException $exception) {
        pluginLifecycleExpect(
            $exception->errorCode === 'MODULE_TENANT_DISABLED',
            'disabled Module command refusal changed',
        );
    }
    pluginLifecycleExpect(($service->uninstall('fixture.delivery-record')['preserve_data'] ?? null) === true, 'uninstall did not preserve data');
    pluginLifecycleExpect((int) $pdo->query('SELECT COUNT(*) FROM pa_fixture_delivery_record')->fetchColumn() === 1, 'uninstall removed business data');

    $artifact = pluginLifecycleFailureArtifact($projectRoot);
    $failureResolver = new PluginLockResolver($serverRoot, $artifact['lock']);
    $failureService = new PluginLifecycleService(
        $failureResolver,
        new PluginModuleRegistryFactory($serverRoot),
        $config + ['plugin_lock' => $artifact['lock']],
        $catalogs,
        new ModuleMigrationSqlExecutor(),
    );
    try {
        $failureService->install('fixture.delivery-record');
        throw new RuntimeException('failing Module migration activated the Plugin');
    } catch (Throwable) {
        $state = $pdo->query("SELECT status FROM pa_plugin_installation WHERE plugin_key='fixture.delivery-record'")->fetchColumn();
        pluginLifecycleExpect($state === 'failed', 'failed migration left Plugin active');
        $moduleState = $pdo->query("SELECT status FROM pa_module_installation WHERE module_key='fixture.delivery-record'")->fetchColumn();
        pluginLifecycleExpect($moduleState === 'failed', 'failed migration left Module active');
    }
    try {
        $failureService->install('fixture.delivery-record');
        throw new RuntimeException('uncertain failed migration was replayed');
    } catch (PluginLifecycleException $exception) {
        pluginLifecycleExpect(
            $exception->errorCode === 'MODULE_MIGRATION_REPAIR_REQUIRED',
            'failed migration retry did not require an append-only repair',
        );
    }
    $repairArtifact = pluginLifecycleRepairArtifact($projectRoot);
    $repairResolver = new PluginLockResolver($serverRoot, $repairArtifact['lock']);
    $repairService = new PluginLifecycleService(
        $repairResolver,
        new PluginModuleRegistryFactory($serverRoot),
        $config + ['plugin_lock' => $repairArtifact['lock']],
        $catalogs,
        new ModuleMigrationSqlExecutor(),
    );
    $recovered = $repairService->install('fixture.delivery-record');
    pluginLifecycleExpect(($recovered['operation'] ?? null) === 'recovered', 'higher repair package did not recover the install');
    pluginLifecycleExpect(
        (string) $pdo->query("SELECT status FROM pa_module_migration WHERE migration_key='fixture.delivery-record:20260814999999_failure_fixture'")->fetchColumn() === 'failed',
        'repair rewrote the immutable failed migration ledger row',
    );
    pluginLifecycleExpect(
        (string) $pdo->query("SELECT status FROM pa_module_migration WHERE migration_key='fixture.delivery-record:20260815000000_failure_repair'")->fetchColumn() === 'applied',
        'append-only repair migration was not applied',
    );
    pluginLifecycleExpect(
        (string) $pdo->query("SELECT status FROM pa_plugin_installation WHERE plugin_key='fixture.delivery-record'")->fetchColumn() === 'active',
        'repair package did not finalize Plugin activation',
    );
    echo "PLUGIN-MODULE-LIFECYCLE-DB-001 passed\n";
} finally {
    if (is_array($artifact)) {
        @unlink($artifact['lock']);
        @unlink($artifact['manifest']);
        @unlink($artifact['migration']);
    }
    if (is_array($repairArtifact)) {
        @unlink($repairArtifact['lock']);
        @unlink($repairArtifact['manifest']);
        @unlink($repairArtifact['migration']);
    }
    pluginLifecycleCleanup($pdo, $catalogSnapshot);
    echo json_encode([
        'cleanup' => 'complete',
        'module_key' => 'fixture.delivery-record',
        'dropped' => ['pa_fixture_delivery_record'],
        'preserved' => ['plugin_lifecycle_schema'],
    ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES) . "\n";
}
