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

require dirname(__DIR__, 2) . '/vendor/autoload.php';
require dirname(__DIR__, 2) . '/vendor/topthink/framework/src/helper.php';
require_once dirname(__DIR__) . '/Support/ThinkPhpTestConnection.php';

// Explicit manual acceptance entry. It uses the selected checkout's native authoring,
// dependency, migration and lifecycle services; no running application or business data is imported.
foreach (['DB_HOST', 'DB_PORT', 'DB_NAME', 'DB_USER', 'DB_PASS'] as $name) {
    if (getenv($name) === false || ($name !== 'DB_PASS' && getenv($name) === '')) {
        throw new RuntimeException('Explicit empty synthetic MySQL environment is required: ' . $name);
    }
}
$root = dirname(__DIR__, 3);
$server = $root . '/server';
$state = $root . '/.local/module-lifecycle-mysql';
$resumeBaseline = array_slice($argv ?? [], 1) === ['--resume-baseline'];
$resumeSource = array_slice($argv ?? [], 1) === ['--resume-source'];
$resumeRuntime = array_slice($argv ?? [], 1) === ['--resume-runtime'];
$resumeReinstall = array_slice($argv ?? [], 1) === ['--resume-reinstall'];
$resume = $resumeBaseline || $resumeSource || $resumeRuntime || $resumeReinstall;
if ((!$resume && is_dir($state)) || (!($resumeSource || $resumeRuntime) && is_dir($server . '/app/modules/acme/lifecycle')) || is_dir($server . '/app/modules/acme/dependent')) {
    throw new RuntimeException('Retain the previous fixture/result and reconcile it before another acceptance run.');
}
$dsn = sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4', getenv('DB_HOST'), getenv('DB_PORT'), getenv('DB_NAME'));
$pdo = new PDO($dsn, (string) getenv('DB_USER'), (string) getenv('DB_PASS'), [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
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
$app = new think\App($server);
$app->loadEnv('');
$app->config->set(['default' => 'mysql', 'migration_table' => 'migrations', 'connections' => ['mysql' => [
    'type' => 'mysql', 'hostname' => getenv('DB_HOST'), 'hostport' => getenv('DB_PORT'), 'database' => getenv('DB_NAME'),
    'username' => getenv('DB_USER'), 'password' => getenv('DB_PASS'), 'charset' => 'utf8mb4', 'prefix' => 'pa_',
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
if (!$resume) {
    $native = new ModuleMigrationRun(ModuleMigrationRun::sources($manifests, $server, true));
    $native->setApp($app);
    $native->run(new think\console\Input([]), new think\console\Output('buffer'));
}
$catalogs = ThinkPhpTestConnection::moduleCatalogs($pdo);
$config = ['roots' => [], 'module_lock' => '../modules.lock', 'kernel_version' => '1.0.0',
    'registered_client_keys' => ['admin-web', 'platform-web', 'pc-web', 'uniapp']];
$app->config->set($config, 'modules');
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
file_put_contents($state . '/result.json', json_encode(['assertions' => $assertions, 'module_id' => $id, 'database' => getenv('DB_NAME'), 'status' => 'passed'], JSON_THROW_ON_ERROR));
echo "MODULE-LIFECYCLE-MYSQL passed assertions={$assertions}; native dependency/migration/catalog/retire/reinstall/purge-recovery\n";
