<?php

declare(strict_types=1);

use app\common\infrastructure\module\ModuleScaffoldGenerator;
use app\platform\composition\module\ModuleDefinitionRegistryFactory;
use app\platform\infrastructure\module\ModuleArtifactWriter;
use app\platform\infrastructure\module\ModuleLockResolver;
use app\platform\services\module\ModuleLifecycleService;
use app\platform\services\module\ModuleRuntimeGovernanceService;
use PeanutAdmin\Modules\Identity\Contract\TenantModuleStateQueries;

require dirname(__DIR__, 2) . '/vendor/autoload.php';
require dirname(__DIR__, 2) . '/vendor/topthink/framework/src/helper.php';
require_once dirname(__DIR__) . '/Support/ThinkPhpTestConnection.php';

foreach (['DB_HOST', 'DB_PORT', 'DB_NAME', 'DB_USER', 'DB_PASS'] as $key) {
    if (getenv($key) === false || ($key !== 'DB_PASS' && getenv($key) === '')) {
        throw new RuntimeException('Explicit synthetic MySQL environment is required: ' . $key);
    }
}
$root = dirname(__DIR__, 3);
$server = $root . '/server';
$state = $root . '/.local/module-lifecycle-mysql';
$moduleRoot = $server . '/app/modules/acme/recovery';
if (!is_file($state . '/source-before.json') || is_dir($moduleRoot)) {
    throw new RuntimeException('Use the retained native synthetic baseline; never overwrite an existing recovery fixture.');
}
$pdo = new PDO(sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4', getenv('DB_HOST'), getenv('DB_PORT'), getenv('DB_NAME')),
    (string) getenv('DB_USER'), (string) getenv('DB_PASS'), [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
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
$catalogs = ThinkPhpTestConnection::moduleCatalogs($pdo);
$config = ['roots' => [], 'module_lock' => '../modules.lock', 'kernel_version' => '1.0.0',
    'registered_client_keys' => ['admin-web', 'platform-web', 'pc-web', 'uniapp']];
$app->config->set($config, 'modules');
(new ModuleScaffoldGenerator($root))->create('acme.recovery', 'acme', 'none');
$module = json_decode((string) file_get_contents($moduleRoot . '/module.json'), true, 128, JSON_THROW_ON_ERROR);
$module['frontend'] = new stdClass();
$module['backend']['migrations'] = 'database/migrations';
$module['database']['owned_tables'] = ['pa_acceptance_recovery'];
file_put_contents($moduleRoot . '/module.json', json_encode($module, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n");
mkdir($moduleRoot . '/database/migrations', 0700, true);
file_put_contents($moduleRoot . '/database/migrations/20261108000010_acceptance_recovery_initial.php', <<<'PHP'
<?php
use think\migration\Migrator;
final class AcceptanceRecoveryInitial extends Migrator {
    public function up(): void {
        if (is_file(root_path().'/../.local/module-lifecycle-mysql/fail-initial')) { throw new RuntimeException('ACCEPTANCE_CONTROLLED_INSTALL_FAILURE'); }
        $this->execute('CREATE TABLE pa_acceptance_recovery (id bigint PRIMARY KEY, value varchar(96) NOT NULL) ENGINE=InnoDB');
    }
}
PHP);
$writer = new ModuleArtifactWriter($server);
$writer->writeLock();
$resolver = new ModuleLockResolver($server, '../modules.lock');
$service = new ModuleLifecycleService($resolver, new ModuleDefinitionRegistryFactory($server), $config, $catalogs, new TenantModuleStateQueries());
$assertions = 0;
$expect = static function (bool $condition, string $reason) use (&$assertions): void {
    ++$assertions;
    if (!$condition) { throw new RuntimeException($reason); }
};
file_put_contents($state . '/fail-initial', 'controlled external prerequisite; not an artifact mutation');
try { $service->install('acme.recovery'); throw new RuntimeException('Controlled install failure was not reached'); }
catch (RuntimeException $exception) { $expect($exception->getMessage() === 'ACCEPTANCE_CONTROLLED_INSTALL_FAILURE', 'Unexpected initial failure'); }
$failed = $pdo->query("SELECT * FROM pa_module_installation WHERE module_key='acme.recovery'")->fetch(PDO::FETCH_ASSOC);
file_put_contents($state . '/initial-failure-identity.json', json_encode($failed, JSON_THROW_ON_ERROR));
$expect($failed['status'] === 'failed' && $failed['last_error_code'] === 'PLUGIN_LIFECYCLE_FAILED', 'Initial failure identity was not retained');
$expect((int) $failed['revision'] === 2, 'Failure was written twice to the unified installation row');
$expect((int) $pdo->query('SELECT COUNT(*) FROM pa_migrations WHERE version=20261108000010')->fetchColumn() === 0, 'Failed migration was falsely recorded applied');
unlink($state . '/fail-initial');
$expect($service->install('acme.recovery')['operation'] === 'resumed', 'Same immutable artifact did not resume');
$expect((int) $pdo->query("SELECT id FROM pa_module_installation WHERE module_key='acme.recovery'")->fetchColumn() === (int) $failed['id'], 'Resume changed the failed Module identity');
$pdo->exec("INSERT INTO pa_acceptance_recovery VALUES (91,'retain-through-forward-repair')");
$module['version'] = '1.1.0';
file_put_contents($moduleRoot . '/module.json', json_encode($module, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n");
file_put_contents($moduleRoot . '/database/migrations/20261108000011_acceptance_recovery_upgrade.php', <<<'PHP'
<?php
use think\migration\Migrator;
final class AcceptanceRecoveryUpgrade extends Migrator {
    public function up(): void {
        if (is_file(root_path().'/../.local/module-lifecycle-mysql/fail-upgrade')) { throw new RuntimeException('ACCEPTANCE_CONTROLLED_UPGRADE_FAILURE'); }
        $this->execute('ALTER TABLE pa_acceptance_recovery ADD COLUMN repaired int NOT NULL DEFAULT 4');
    }
}
PHP);
$writer->writeLock();
file_put_contents($state . '/fail-upgrade', 'controlled external prerequisite');
try { $service->upgrade('acme.recovery', false); throw new RuntimeException('Controlled upgrade failure was not reached'); }
catch (RuntimeException $exception) { $expect($exception->getMessage() === 'ACCEPTANCE_CONTROLLED_UPGRADE_FAILURE', 'Unexpected upgrade failure'); }
$broken = $pdo->query("SELECT * FROM pa_module_installation WHERE module_key='acme.recovery'")->fetch(PDO::FETCH_ASSOC);
file_put_contents($state . '/upgrade-failure-identity.json', json_encode($broken, JSON_THROW_ON_ERROR));
$expect($broken['status'] === 'failed' && $broken['installed_version'] === '1.1.0', 'Failed attempted version was lost');
unlink($state . '/fail-upgrade');
$module['version'] = '1.1.1';
file_put_contents($moduleRoot . '/module.json', json_encode($module, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n");
$writer->writeLock();
$expect($service->install('acme.recovery')['operation'] === 'recovered', 'Higher immutable repair version was not admitted');
$expect($pdo->query('SELECT value FROM pa_acceptance_recovery WHERE id=91')->fetchColumn() === 'retain-through-forward-repair'
    && (int) $pdo->query('SELECT repaired FROM pa_acceptance_recovery WHERE id=91')->fetchColumn() === 4, 'Forward recovery lost data or skipped native pending migration');
$expect((int) $pdo->query("SELECT id FROM pa_module_installation WHERE module_key='acme.recovery'")->fetchColumn() === (int) $failed['id'], 'Forward repair changed Module identity');
$catalogs->disableActivePackageModules(['acme.recovery']);
$governance = new ModuleRuntimeGovernanceService($server, $config, $catalogs, new TenantModuleStateQueries());
$plan = $governance->preview('acme.recovery', false);
$governance->uninstall('acme.recovery', false, $plan['confirm_plan'], $plan['plan_digest']);
$expect($pdo->query('SELECT value FROM pa_acceptance_recovery WHERE id=91')->fetchColumn() === 'retain-through-forward-repair', 'Normal retirement after repair removed business data');
file_put_contents($state . '/failure-recovery-result.json', json_encode(['assertions' => $assertions, 'status' => 'passed'], JSON_THROW_ON_ERROR));
echo "MODULE-FAILURE-RECOVERY-MYSQL passed assertions={$assertions}; same-artifact resume+higher-version repair\n";
