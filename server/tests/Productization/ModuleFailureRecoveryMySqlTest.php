<?php

declare(strict_types=1);

use app\common\infrastructure\module\ModuleScaffoldGenerator;
use app\common\module\ModuleMigrationRun;
use PeanutAdmin\Kernel\Module\ManifestLoader;
use app\platform\composition\module\ModuleDefinitionRegistryFactory;
use app\platform\infrastructure\module\ModuleArtifactWriter;
use app\platform\infrastructure\module\ModuleLockResolver;
use app\platform\services\module\ModuleLifecycleService;
use app\platform\services\module\ModuleRuntimeGovernanceService;
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

$databaseEnvironment = [];
foreach (['DB_HOST', 'DB_PORT', 'DB_NAME', 'DB_USER', 'DB_PASS'] as $key) {
    $value = $app->env->get($key);
    if (!is_string($value) || ($key !== 'DB_PASS' && $value === '')) {
        throw new RuntimeException('Explicit synthetic MySQL environment is required: ' . $key);
    }
    $databaseEnvironment[$key] = $value;
}
$state = $root . '/.local/module-lifecycle-mysql';
$moduleRoot = $server . '/app/modules/acme/recovery';
if (is_dir($state) || is_dir($moduleRoot)) {
    throw new RuntimeException('Independent failure-recovery APP/state must be new; preserve every earlier attempt.');
}
$pdo = new PDO(sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4', $databaseEnvironment['DB_HOST'], $databaseEnvironment['DB_PORT'], $databaseEnvironment['DB_NAME']),
    $databaseEnvironment['DB_USER'], $databaseEnvironment['DB_PASS'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$databaseRow = $pdo->query('SELECT @@server_uuid AS server_uuid,@@hostname AS server_hostname,@@port AS server_port,DATABASE() AS database_name')->fetch(PDO::FETCH_ASSOC);
if (!is_array($databaseRow) || ($expectedServerUuid !== null && !hash_equals($expectedServerUuid, strtolower((string) $databaseRow['server_uuid'])))
    || ($databaseRow['database_name'] ?? null) !== $databaseEnvironment['DB_NAME']) {
    throw new RuntimeException('Native qualification MySQL UUID/schema differs before the first write.');
}
$databaseIdentity = ['server_uuid' => strtolower($databaseRow['server_uuid']), 'server_hostname' => $databaseRow['server_hostname'],
    'server_port' => (int) $databaseRow['server_port'], 'database' => $databaseRow['database_name'],
    'transport' => $pdo->getAttribute(PDO::ATTR_CONNECTION_STATUS)];
if ($pdo->query('SHOW TABLES')->fetchAll() !== []) {
    throw new RuntimeException('Independent failure-recovery qualification database must start empty.');
}
mkdir($state, 0700, true);
$original = [];
foreach (['server/composer.json', 'server/composer.lock', 'modules.lock', 'server/generated/api-catalog.json',
    'server/generated/openapi.json', 'web/src/generated/openapi.d.ts'] as $file) {
    $original[$file] = is_file($root . '/' . $file) ? base64_encode((string) file_get_contents($root . '/' . $file)) : null;
}
file_put_contents($state . '/source-before.json', json_encode($original, JSON_THROW_ON_ERROR));
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
    foreach ($descriptor->moduleRoots as $key => $directory) {
        $manifests[$key] = (new ManifestLoader())->load($directory);
    }
}
$native = new ModuleMigrationRun(ModuleMigrationRun::sources($manifests, $server, true));
$native->setApp($app);
$native->run(new think\console\Input([]), new think\console\Output('buffer'));
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
$baselineRegistry = (new ModuleDefinitionRegistryFactory($server))->fromModuleLock($baseline, $config);
$catalogs->apply($baselineRegistry, array_keys($manifests));
$nonCaseCatalogBefore = $nonCaseCatalogSnapshot();
if (array_sum(array_map('count', $nonCaseCatalogBefore)) === 0) {
    throw new RuntimeException('Native non-case catalog baseline must not be empty.');
}
file_put_contents($state . '/non-case-catalog-before.json', json_encode($nonCaseCatalogBefore, JSON_THROW_ON_ERROR));
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
// ModuleCatalogApplier scopes the menu revision to the selected manifest digests.
// This activation selected just the case Module; read it before retirement removes its source.
$expectedMenuScope = hash('sha256', (new ManifestLoader())->load($moduleRoot)->digest);
$catalogs->disableActivePackageModules(['acme.recovery']);
$governance = new ModuleRuntimeGovernanceService($server, $config, $catalogs, new TenantModuleStateQueries());
$plan = $governance->preview('acme.recovery', false);
$governance->uninstall('acme.recovery', false, $plan['confirm_plan'], $plan['plan_digest']);
$expect($pdo->query('SELECT value FROM pa_acceptance_recovery WHERE id=91')->fetchColumn() === 'retain-through-forward-repair', 'Normal retirement after repair removed business data');
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
file_put_contents($state . '/failure-recovery-result.json', json_encode(['assertions' => $assertions, 'status' => 'passed', 'database_identity' => $databaseIdentity, 'non_case_catalog_sha256' => hash('sha256', json_encode($nonCaseCatalogBefore, JSON_THROW_ON_ERROR))], JSON_THROW_ON_ERROR));
echo "MODULE-FAILURE-RECOVERY-MYSQL passed assertions={$assertions}; same-artifact resume+higher-version repair\n";
