<?php

declare(strict_types=1);

use app\common\infrastructure\module\ModuleScaffoldGenerator;
use app\common\module\ModuleMigrationRun;
use app\platform\composition\module\ModuleDefinitionRegistryFactory;
use app\platform\infrastructure\module\ModuleArtifactWriter;
use app\platform\infrastructure\module\ModuleLockResolver;
use app\platform\infrastructure\module\ModulePackageInstaller;
use app\platform\infrastructure\module\ModuleUninstallPlanCodec;
use app\platform\services\module\ModuleLifecycleService;
use app\platform\services\module\ModulePackageArchiveService;
use app\platform\services\module\ModuleRuntimeGovernanceService;
use PeanutAdmin\Kernel\Module\ManifestLoader;
use PeanutAdmin\Modules\Identity\Contract\TenantModuleStateQueries;

$applicationRootOption = array_values(array_filter(array_slice($argv ?? [], 1), static fn(string $value): bool => str_starts_with($value, '--application-root=')));
if (count($applicationRootOption) > 1) {
    throw new RuntimeException('Only one explicit qualification APP root is accepted.');
}
$root = realpath($applicationRootOption === [] ? dirname(__DIR__, 3) : substr($applicationRootOption[0], strlen('--application-root=')));
if ($root === false || !is_dir($root . '/server/app/modules') || !is_file($root . '/server/vendor/autoload.php')) {
    throw new RuntimeException('Native qualification APP source/dependencies are unavailable.');
}
$server = $root . '/server';
$expectedUuidOption = array_values(array_filter(array_slice($argv ?? [], 1), static fn(string $value): bool => str_starts_with($value, '--expected-server-uuid=')));
if (count($expectedUuidOption) > 1) { throw new RuntimeException('Only one native MySQL UUID is accepted.'); }
$expectedServerUuid = $expectedUuidOption === [] ? null : strtolower(substr($expectedUuidOption[0], strlen('--expected-server-uuid=')));
if ($expectedServerUuid !== null && preg_match('/^[a-f0-9]{8}(?:-[a-f0-9]{4}){3}-[a-f0-9]{12}$/D', $expectedServerUuid) !== 1) {
    throw new RuntimeException('Explicit native MySQL UUID is invalid.');
}
$argv = array_values(array_filter($argv ?? [], static fn(string $value): bool =>
    !str_starts_with($value, '--application-root=') && !str_starts_with($value, '--expected-server-uuid=')));
require $server . '/vendor/autoload.php';
require $server . '/vendor/topthink/framework/src/helper.php';
require_once dirname(__DIR__) . '/Support/ThinkPhpTestConnection.php';
$app = new think\App($server);
$app->loadEnv((string) $app->env->get('env_name', ''));
$app->initialize();
set_exception_handler(static function (Throwable $error): void {
    fwrite(STDERR, get_class($error) . ': ' . $error->getMessage() . "\n");
    exit(1);
});

// Explicit manual acceptance entry. It uses the selected checkout's native authoring,
// dependency, migration and lifecycle services; no running application or business data is imported.
$databaseEnvironment = [];
foreach (['DB_HOST', 'DB_PORT', 'DB_NAME', 'DB_USER', 'DB_PASS'] as $name) {
    $value = $app->env->get($name);
    if (!is_string($value) || ($name !== 'DB_PASS' && $value === '')) {
        throw new RuntimeException('Explicit empty synthetic MySQL environment is required: ' . $name);
    }
    $databaseEnvironment[$name] = $value;
}
$state = $root . '/.local/module-lifecycle-mysql';
$resumeBaseline = array_slice($argv ?? [], 1) === ['--resume-baseline'];
$resumeSource = array_slice($argv ?? [], 1) === ['--resume-source'];
$resumeRuntime = array_slice($argv ?? [], 1) === ['--resume-runtime'];
$resumeReinstall = array_slice($argv ?? [], 1) === ['--resume-reinstall'];
$resume = $resumeBaseline || $resumeSource || $resumeRuntime || $resumeReinstall;
if ((!$resume && is_dir($state)) || (!($resumeSource || $resumeRuntime) && is_dir($server . '/app/modules/acme/lifecycle')) || is_dir($server . '/app/modules/acme/dependent')) {
    throw new RuntimeException('Retain the previous fixture/result and reconcile it before another acceptance run.');
}
$dsn = sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4', $databaseEnvironment['DB_HOST'], $databaseEnvironment['DB_PORT'], $databaseEnvironment['DB_NAME']);
$pdo = new PDO($dsn, $databaseEnvironment['DB_USER'], $databaseEnvironment['DB_PASS'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$databaseRow = $pdo->query('SELECT @@server_uuid AS server_uuid,@@hostname AS server_hostname,@@port AS server_port,DATABASE() AS database_name')->fetch(PDO::FETCH_ASSOC);
if (!is_array($databaseRow) || ($expectedServerUuid !== null && !hash_equals($expectedServerUuid, strtolower((string) $databaseRow['server_uuid'])))
    || ($databaseRow['database_name'] ?? null) !== $databaseEnvironment['DB_NAME']) {
    throw new RuntimeException('Native qualification MySQL UUID/schema differs before the first write.');
}
$databaseIdentity = ['server_uuid' => strtolower($databaseRow['server_uuid']), 'server_hostname' => $databaseRow['server_hostname'],
    'server_port' => (int) $databaseRow['server_port'], 'database' => $databaseRow['database_name'],
    'transport' => $pdo->getAttribute(PDO::ATTR_CONNECTION_STATUS)];

if (!$resume && $pdo->query('SHOW TABLES')->fetchAll() !== []) { throw new RuntimeException('Synthetic lifecycle database must start empty.'); }
if ($resume && (!is_file($state . '/source-before.json')
    || (int) $pdo->query('SELECT COUNT(*) FROM pa_migrations WHERE version=20261103230000')->fetchColumn() !== 1
    || (!($resumeRuntime || $resumeReinstall) && (int) $pdo->query('SELECT COUNT(*) FROM pa_module_installation')->fetchColumn() !== 0)
    || ($resumeRuntime && $pdo->query("SELECT CONCAT(status,':',installed_version) FROM pa_module_installation WHERE module_key='acme.lifecycle'")->fetchColumn() !== 'active:1.1.0')
    || ($resumeReinstall && $pdo->query("SELECT CONCAT(status,':',installed_version) FROM pa_module_installation WHERE module_key='acme.lifecycle'")->fetchColumn() !== 'uninstalled:1.2.0'))) {
    throw new RuntimeException('Only the evidenced pre-fixture native baseline failure can be resumed here.');
}
if (!$resume) { mkdir($state, 0700, true); }
$original = [];
foreach (['server/composer.json', 'server/composer.lock', 'modules.lock', 'server/generated/api-catalog.json',
    'server/generated/openapi.json', 'web/src/generated/openapi.d.ts', ...array_map(
        static fn(string $file): string => substr($file, strlen($root) + 1), glob($root . '/web/src/modules/*/generated/openapi.ts') ?: [],
    )] as $file) {
    $original[$file] = is_file($root . '/' . $file) ? base64_encode((string) file_get_contents($root . '/' . $file)) : null;
}
if (!$resume) { file_put_contents($state . '/source-before.json', json_encode($original, JSON_THROW_ON_ERROR)); }
$app->config->set(['default' => 'mysql', 'migration_table' => 'migrations', 'connections' => ['mysql' => [
    'type' => 'mysql', 'hostname' => $databaseEnvironment['DB_HOST'], 'hostport' => $databaseEnvironment['DB_PORT'], 'database' => $databaseEnvironment['DB_NAME'],
    'username' => $databaseEnvironment['DB_USER'], 'password' => $databaseEnvironment['DB_PASS'], 'charset' => 'utf8mb4', 'prefix' => 'pa_',
    'deploy' => 0, 'trigger_sql' => false,
]]], 'database');
$app->config->set(['mode' => 'multi-tenant'], 'deployment');
$app->config->set(['environment' => 'development'], 'peanut');
$app->env->set('PEANUT_INSTALLATION_SOURCE_MODE', 'development');
$app->bind(PeanutAdmin\Kernel\Authorization\Persistence\AuthorizationCatalogRepository::class,
    PeanutAdmin\Modules\Identity\Authorization\Persistence\ThinkPhpAuthorizationCatalogRepository::class);
ThinkPhpTestConnection::fromPdo($pdo);
$manifests = [];
$baseline = new ModuleLockResolver($server, '../modules.lock');
foreach ($baseline->all() as $descriptor) {
    foreach ($descriptor->moduleRoots as $key => $directory) { $manifests[$key] = (new ManifestLoader())->load($directory); }
}
if (!$resume || $resumeBaseline) {
    $native = new ModuleMigrationRun(ModuleMigrationRun::sources($manifests, $server, true));
    $native->setApp($app);
    $native->run(new think\console\Input([]), new think\console\Output('buffer'));
}
$catalogs = ThinkPhpTestConnection::moduleCatalogs($pdo);
$config = ['roots' => [], 'module_lock' => '../modules.lock', 'kernel_version' => '1.0.0',
    'registered_client_keys' => ['admin-web', 'platform-web', 'pc-web', 'uniapp']];
$app->config->set($config, 'modules');
$nonCaseCatalogSnapshot = static function () use ($pdo): array {
    $snapshot = [];
    foreach (['permission', 'menu_definition', 'setting_definition'] as $table) {
        $snapshot[$table] = $pdo->query("SELECT * FROM pa_{$table} WHERE (module_key IS NULL OR module_key NOT IN ('acme.lifecycle','acme.dependent','acme.recovery')) ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);
    }
    return $snapshot;
};
$catalogBaselinePath = $state . '/non-case-catalog-before.json';
if (!$resume) {
    $baselineRegistry = (new ModuleDefinitionRegistryFactory($server))->fromModuleLock($baseline, $config);
    $catalogs->apply($baselineRegistry, array_keys($manifests));
    $nonCaseCatalogBefore = $nonCaseCatalogSnapshot();
    if (array_sum(array_map('count', $nonCaseCatalogBefore)) === 0) {
        throw new RuntimeException('Native non-case catalog baseline must not be empty.');
    }
    file_put_contents($catalogBaselinePath, json_encode($nonCaseCatalogBefore, JSON_THROW_ON_ERROR));
} else {
    $nonCaseCatalogBefore = json_decode((string) file_get_contents($catalogBaselinePath), true, 128, JSON_THROW_ON_ERROR);
}
$writer = new ModuleArtifactWriter($server);
$moduleRoot = $server . '/app/modules/acme/lifecycle';
$resolver = new ModuleLockResolver($server, '../modules.lock');
$service = new ModuleLifecycleService($resolver, new ModuleDefinitionRegistryFactory($server), $config, $catalogs, new TenantModuleStateQueries());
$assertions = 0;
$expect = static function (bool $value, string $reason) use (&$assertions): void {
    ++$assertions;
    if (!$value) { throw new RuntimeException($reason); }
};
$generator = new ModuleScaffoldGenerator($root);
if ($resumeReinstall) {
    $id = (int) $pdo->query("SELECT id FROM pa_module_installation WHERE module_key='acme.lifecycle'")->fetchColumn();
    $archive = ['sha256' => hash_file('sha256', $state . '/lifecycle.tar')];
    $governance = new ModuleRuntimeGovernanceService($server, $config, $catalogs, new TenantModuleStateQueries());
    $expect(!is_dir($moduleRoot) && $pdo->query('SELECT value FROM pa_acceptance_lifecycle WHERE id=81')->fetchColumn() === 'original-fictional-record', 'Native default retirement did not preserve the registered history/data');
    goto archiveReinstall;
}
if (!$resumeRuntime) {
if (!$resumeSource) { $generator->create('acme.lifecycle', 'acme', 'none'); }
$module = json_decode((string) file_get_contents($moduleRoot . '/module.json'), true, 128, JSON_THROW_ON_ERROR);
$module['frontend'] = new stdClass();
$module['backend']['migrations'] = 'database/migrations';
$module['database']['owned_tables'] = ['pa_acceptance_lifecycle'];
file_put_contents($moduleRoot . '/module.json', json_encode($module, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n");
if (!is_dir($moduleRoot . '/database/migrations')) { mkdir($moduleRoot . '/database/migrations', 0700, true); }
file_put_contents($moduleRoot . '/database/migrations/20261108000001_acceptance_lifecycle_schema.php', <<<'PHP'
<?php
use think\migration\Migrator;
final class AcceptanceLifecycleSchema extends Migrator {
    public function up(): void { $this->execute('CREATE TABLE pa_acceptance_lifecycle (id bigint PRIMARY KEY, value varchar(96) NOT NULL) ENGINE=InnoDB'); }
}
PHP);
$writer->writeLock();
$result = $service->install('acme.lifecycle');
$expect($result['operation'] === 'installed', 'New Module did not install through native dependencies/migrations/catalogs');
$id = (int) $pdo->query("SELECT id FROM pa_module_installation WHERE module_key='acme.lifecycle'")->fetchColumn();
$pdo->exec("INSERT INTO pa_acceptance_lifecycle VALUES (81,'original-fictional-record')");
$expect($id > 0, 'Module identity was not registered');
$catalogs->disableActivePackageModules(['acme.lifecycle']);
$module['version'] = '1.1.0';
file_put_contents($moduleRoot . '/module.json', json_encode($module, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n");
file_put_contents($moduleRoot . '/database/migrations/20261108000002_acceptance_lifecycle_upgrade.php', <<<'PHP'
<?php
use think\migration\Migrator;
final class AcceptanceLifecycleUpgrade extends Migrator {
    public function up(): void { $this->execute('ALTER TABLE pa_acceptance_lifecycle ADD COLUMN upgraded int NOT NULL DEFAULT 7'); }
}
PHP);
$writer->writeLock();
$plan = $service->reconcilePlan('acme.lifecycle', true);
$expect($plan['action'] === 'preserve_disabled' && $plan['may_apply'] === true, 'Disabled pending migration blocks release plan');
$selection = (new ReflectionMethod(app\command\ModuleReconcile::class, 'releaseModuleSelection'))->invoke(new app\command\ModuleReconcile(), $resolver->all());
$expect(in_array('acme.lifecycle', $selection['preserved_disabled'], true) && !in_array('acme.lifecycle', $selection['reconcile'], true), 'Release selection resurrects disabled Module');
$expect($pdo->query("SELECT status FROM pa_module_installation WHERE id={$id}")->fetchColumn() === 'maintenance', 'Release plan reactivated Module');
$beforePlan = $pdo->query("SELECT * FROM pa_module_installation WHERE id={$id}")->fetch(PDO::FETCH_ASSOC);
$expect(($service->upgrade('acme.lifecycle', true)['dry_run'] ?? null) === true, 'Native upgrade dry-run is missing');
$expect(($service->rollbackPlan('acme.lifecycle')['automatic'] ?? null) === false, 'Rollback became automatic');
$expect($pdo->query("SELECT * FROM pa_module_installation WHERE id={$id}")->fetch(PDO::FETCH_ASSOC) === $beforePlan,
    'Read-only upgrade/rollback plans changed installed state');
$result = $service->upgrade('acme.lifecycle', false);
$expect($result['operation'] === 'upgraded', 'Explicit upgrade did not activate the selected Module');
$expect((int) $pdo->query('SELECT upgraded FROM pa_acceptance_lifecycle WHERE id=81')->fetchColumn() === 7, 'Native pending migration did not execute');
$catalogs->disableActivePackageModules(['acme.lifecycle']);
$expect($service->install('acme.lifecycle')['operation'] === 'reactivated', 'Existing explicit reactivation contract was lost');
$result = $service->uninstall('acme.lifecycle');
$expect($result['preserve_data'] === true, 'Default retirement does not preserve data');
$expect($pdo->query('SELECT value FROM pa_acceptance_lifecycle WHERE id=81')->fetchColumn() === 'original-fictional-record', 'Default uninstall removed business data');
$service->install('acme.lifecycle');
$expect((int) $pdo->query("SELECT id FROM pa_module_installation WHERE module_key='acme.lifecycle'")->fetchColumn() === $id, 'Reinstallation changed Module identity');
}
$id = (int) $pdo->query("SELECT id FROM pa_module_installation WHERE module_key='acme.lifecycle'")->fetchColumn();
$module = json_decode((string) file_get_contents($moduleRoot . '/module.json'), true, 128, JSON_THROW_ON_ERROR);
$module['frontend'] = new stdClass();
$catalogs->disableActivePackageModules(['acme.lifecycle']);
$module['version'] = '1.2.0';
file_put_contents($moduleRoot . '/module.json', json_encode($module, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n");
file_put_contents($moduleRoot . '/database/migrations/20261108000003_acceptance_lifecycle_current.php', <<<'PHP'
<?php
use think\migration\Migrator;
final class AcceptanceLifecycleCurrent extends Migrator {
    public function up(): void { $this->execute('ALTER TABLE pa_acceptance_lifecycle ADD COLUMN current_revision int NOT NULL DEFAULT 9'); }
}
PHP);
$writer->writeLock();
$plan = $service->reconcilePlan('acme.lifecycle', true);
$expect($plan['action'] === 'preserve_disabled' && $plan['may_apply'] === true, 'Final deployment factory did not preserve disabled pending migration');
$selection = (new ReflectionMethod(app\command\ModuleReconcile::class, 'releaseModuleSelection'))->invoke(new app\command\ModuleReconcile(), $resolver->all());
$expect(in_array('acme.lifecycle', $selection['preserved_disabled'], true), 'Final release selection would reactivate disabled Module');
$expect($service->upgrade('acme.lifecycle', false)['operation'] === 'upgraded', 'Explicit native upgrade did not resume disabled Module');
$expect((int) $pdo->query('SELECT current_revision FROM pa_acceptance_lifecycle WHERE id=81')->fetchColumn() === 9, 'Final native pending migration was not executed');
$catalogs->disableActivePackageModules(['acme.lifecycle']);
$expect($service->install('acme.lifecycle')['operation'] === 'reactivated', 'Final explicit reactivation was rejected');
$generator->create('acme.dependent', 'acme', 'none');
$childPath = $server . '/app/modules/acme/dependent/module.json';
$child = json_decode((string) file_get_contents($childPath), true, 128, JSON_THROW_ON_ERROR);
$child['frontend'] = new stdClass();
$child['dependencies'] = [['module_key' => 'acme.lifecycle', 'version' => '^1.0.0']];
file_put_contents($childPath, json_encode($child, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n");
$writer->writeLock();
$expect($service->install('acme.dependent')['operation'] === 'installed', 'A genuinely new dependent Module did not install through the final deployment factory');
try { $service->uninstall('acme.lifecycle'); throw new RuntimeException('Installed dependency was not protected'); }
catch (app\platform\exception\module\ModuleLifecycleException $exception) { $expect($exception->errorCode === 'MODULE_DEPENDENT_INSTALLED', 'Wrong dependency uninstall refusal'); }
$catalogs->disableActivePackageModules(['acme.dependent']);
try { $service->uninstall('acme.lifecycle'); throw new RuntimeException('Disabled dependency was not protected'); }
catch (app\platform\exception\module\ModuleLifecycleException $exception) { $expect($exception->errorCode === 'MODULE_DEPENDENT_INSTALLED', 'Disabled installation lost dependency protection'); }
$governance = new ModuleRuntimeGovernanceService($server, $config, $catalogs, new TenantModuleStateQueries());
$childPlan = $governance->preview('acme.dependent', false);
$governance->uninstall('acme.dependent', false, $childPlan['confirm_plan'], $childPlan['plan_digest']);
$expect($service->uninstall('acme.lifecycle')['preserve_data'] === true, 'Final default unregister did not preserve data');
$service->install('acme.lifecycle');
$expect((int) $pdo->query("SELECT id FROM pa_module_installation WHERE module_key='acme.lifecycle'")->fetchColumn() === $id
    && $pdo->query('SELECT value FROM pa_acceptance_lifecycle WHERE id=81')->fetchColumn() === 'original-fictional-record', 'Final normal reinstallation changed Module/data identity');
$archive = (new ModulePackageArchiveService($server))->packModule('acme.lifecycle', $state . '/lifecycle.tar');
$governance = new ModuleRuntimeGovernanceService($server, $config, $catalogs, new TenantModuleStateQueries());
$catalogs->disableActivePackageModules(['acme.lifecycle']);
$preview = $governance->preview('acme.lifecycle', false);
file_put_contents($state . '/retirement-preview.json', json_encode($preview, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
$governance->uninstall('acme.lifecycle', false, $preview['confirm_plan'], $preview['plan_digest']);
$expect($pdo->query('SELECT value FROM pa_acceptance_lifecycle WHERE id=81')->fetchColumn() === 'original-fictional-record', 'Native source retirement removed business data');
archiveReinstall:
(new ModulePackageInstaller($server, $config, [], $catalogs))->install($state . '/lifecycle.tar', $archive['sha256'], null);
$expect((int) $pdo->query("SELECT id FROM pa_module_installation WHERE module_key='acme.lifecycle'")->fetchColumn() === $id, 'Archive reinstallation changed the preserved identity');
// ModuleCatalogApplier scopes the menu revision to the selected manifest digests.
// This activation selected just the case Module; read it before retirement removes its source.
$expectedMenuScope = hash('sha256', (new ManifestLoader())->load($moduleRoot)->digest);
$faulting = new ModuleRuntimeGovernanceService($server, $config, $catalogs, new TenantModuleStateQueries(),
    static function (string $point): void { if ($point === 'after-first-drop-statement') { throw new RuntimeException('ACCEPTANCE_CONTROLLED_PURGE_FAILURE'); } });
$catalogs->disableActivePackageModules(['acme.lifecycle']);
$purge = $faulting->preview('acme.lifecycle', true);
try { $faulting->uninstall('acme.lifecycle', true, $purge['confirm_plan'], $purge['plan_digest']); throw new RuntimeException('Controlled failure was not reached'); }
catch (RuntimeException $exception) { if ($exception->getMessage() !== 'ACCEPTANCE_CONTROLLED_PURGE_FAILURE') { throw $exception; } }
$expect($pdo->query("SELECT last_error_code FROM pa_module_installation WHERE id={$id}")->fetchColumn() === 'MODULE_PURGE_IN_PROGRESS', 'Interrupted purge lost its recovery marker');
$governance->uninstall('acme.lifecycle', true, $purge['confirm_plan'], $purge['plan_digest']);
$expect($pdo->query("SELECT status FROM pa_module_installation WHERE id={$id}")->fetchColumn() === 'uninstalled', 'Purge recovery deleted the only installation/history row');
$expect((int) $pdo->query("SELECT COUNT(*) FROM pa_migrations WHERE version IN (20261108000001,20261108000002,20261108000003)")->fetchColumn() === 3, 'Uninstall/purge deleted native migration history');
$nonCaseCatalogAfter = $nonCaseCatalogSnapshot();
$coreMetadataValid = true;
foreach (['permission', 'menu_definition'] as $table) {
    $beforeById = array_column($nonCaseCatalogBefore[$table], null, 'id');
    foreach ($nonCaseCatalogAfter[$table] as &$row) {
        $before = $beforeById[$row['id']] ?? null;
        if ($before === null || !in_array($row['module_key'], ['core', 'platform'], true)) {
            continue;
        }
        $coreMetadataValid = $coreMetadataValid && $row['updated_at'] >= $before['updated_at'];
        $row['updated_at'] = $before['updated_at'];
        if ($table === 'menu_definition') {
            $coreMetadataValid = $coreMetadataValid && hash_equals($expectedMenuScope, $row['manifest_digest']);
            $row['manifest_digest'] = $before['manifest_digest'];
        }
    }
    unset($row);
}
$expect($coreMetadataValid && $nonCaseCatalogAfter === $nonCaseCatalogBefore,
    'Native lifecycle changed non-case catalog contents or produced unexpected Core/Platform metadata');
file_put_contents($state . '/result.json', json_encode(['assertions' => $assertions, 'module_id' => $id, 'database' => $databaseEnvironment['DB_NAME'], 'status' => 'passed', 'database_identity' => $databaseIdentity, 'non_case_catalog_sha256' => hash('sha256', json_encode($nonCaseCatalogBefore, JSON_THROW_ON_ERROR))], JSON_THROW_ON_ERROR));
echo "MODULE-LIFECYCLE-MYSQL passed assertions={$assertions}; native dependency/migration/catalog/retire/reinstall/purge-recovery\n";
