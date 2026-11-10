<?php

declare(strict_types=1);

use app\platform\infrastructure\module\ModuleUninstallPlanCodec;
use app\platform\exception\module\ModuleLifecycleException;
use app\platform\services\module\ModulePackageArchiveService;
use app\platform\infrastructure\module\ModulePackageInstaller;
use app\platform\services\module\ModuleRuntimeGovernanceService;

$applicationRootOptions = array_values(array_filter(array_slice($argv ?? [], 1), static fn(string $value): bool => str_starts_with($value, '--application-root=')));
$expectedUuidOptions = array_values(array_filter(array_slice($argv ?? [], 1), static fn(string $value): bool => str_starts_with($value, '--expected-server-uuid=')));
if (count($applicationRootOptions) !== 1 || count($expectedUuidOptions) !== 1) {
    throw new RuntimeException('An admitted isolated APP root and expected native MySQL UUID are required.');
}
$applicationRoot = realpath(substr($applicationRootOptions[0], strlen('--application-root=')));
$expectedServerUuid = strtolower(substr($expectedUuidOptions[0], strlen('--expected-server-uuid=')));
if ($applicationRoot === false || $applicationRoot === realpath(dirname(__DIR__, 3))
    || !is_file($applicationRoot . '/server/vendor/autoload.php')
    || preg_match('/^[a-f0-9]{8}(?:-[a-f0-9]{4}){3}-[a-f0-9]{12}$/D', $expectedServerUuid) !== 1) {
    throw new RuntimeException('Native qualification APP source/dependencies or UUID are invalid.');
}
$serverRoot = $applicationRoot . '/server';
require $serverRoot . '/vendor/autoload.php';
require_once dirname(__DIR__) . '/Support/ThinkPhpTestConnection.php';
$app = new \think\App($serverRoot);
require_once $app->getThinkPath() . 'helper.php';
$app->loadEnv((string) $app->env->get('env_name', ''));
$app->initialize();
set_exception_handler(static function (Throwable $error): void {
    fwrite(STDERR, get_class($error) . ': ' . $error->getMessage() . "\n");
    exit(1);
});
$databaseEnvironment = [];
foreach (['DB_HOST', 'DB_PORT', 'DB_NAME', 'DB_USER', 'DB_PASS'] as $name) {
    $value = $app->env->get($name);
    if (!is_string($value) || ($name !== 'DB_PASS' && $value === '')) {
        throw new RuntimeException('Explicit native MySQL environment is required: ' . $name);
    }
    $databaseEnvironment[$name] = $value;
}
$app->config->set(['default' => 'mysql', 'migration_table' => 'migrations', 'connections' => ['mysql' => [
    'type' => 'mysql', 'hostname' => $databaseEnvironment['DB_HOST'], 'hostport' => $databaseEnvironment['DB_PORT'], 'database' => $databaseEnvironment['DB_NAME'],
    'username' => $databaseEnvironment['DB_USER'], 'password' => $databaseEnvironment['DB_PASS'], 'charset' => 'utf8mb4', 'prefix' => 'pa_',
    'deploy' => 0, 'trigger_sql' => false,
]]], 'database');
$app->config->set(['mode' => 'multi-tenant'], 'deployment');
$app->config->set(['environment' => 'development'], 'peanut');
$app->env->set('PEANUT_INSTALLATION_SOURCE_MODE', 'development');
$app->bind(\PeanutAdmin\Kernel\Authorization\Persistence\AuthorizationCatalogRepository::class,
    \PeanutAdmin\Modules\Identity\Authorization\Persistence\ThinkPhpAuthorizationCatalogRepository::class);

function moduleGovernanceExpect(bool $condition, string $message): void
{
    ++$GLOBALS['moduleGovernanceAssertions'];
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function moduleGovernanceRemoveTree(string $path): void
{
    if (!is_dir($path)) {
        return;
    }
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($iterator as $entry) {
        $entry->isDir() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
    }
    rmdir($path);
}

function moduleGovernanceProject(string $sourceRoot, string $targetRoot, string $composerBinary): void
{
    foreach (['server/app/modules', 'server/tests', 'web/src/modules'] as $directory) {
        mkdir($targetRoot . '/' . $directory, 0700, true);
    }
    // fixture.* is deliberately excluded from canonical deployment locks. Exercise
    // package delivery with a native authored Module, as a real consumer does.
    (new \app\common\infrastructure\module\ModuleScaffoldGenerator(
        $targetRoot, $sourceRoot . '/server/resources/module-scaffold', $composerBinary,
    ))->create('acme.governance', 'acme', 'none');
    if (!is_dir($targetRoot . '/server/resources/schemas')) {
        mkdir($targetRoot . '/server/resources/schemas', 0777, true);
    }
    copy($sourceRoot . '/server/resources/schemas/module-artifact.schema.json', $targetRoot . '/server/resources/schemas/module-artifact.schema.json');
    $manifestPath = $targetRoot . '/server/app/modules/acme/governance/module.json';
    $manifest = json_decode((string) file_get_contents($manifestPath), true, 64, JSON_THROW_ON_ERROR);
    $manifest['frontend'] = new stdClass();
    $manifest['database']['owned_tables'] = ['pa_fixture_delivery_record', 'pa_fixture_delivery_aux'];
    $manifest['backend']['migrations'] = 'database/migrations';
    file_put_contents($manifestPath, json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n");
    $resourceRoot = dirname($manifestPath) . '/resources';
    file_put_contents($resourceRoot . '/permissions.json', json_encode([
        ['key' => 'acme.governance.read', 'type' => 'api', 'name' => 'Read records', 'risk_level' => 'normal'],
        ['key' => 'acme.governance.create', 'type' => 'action', 'name' => 'Create records', 'risk_level' => 'sensitive'],
    ], JSON_THROW_ON_ERROR));
    $menus = json_decode((string) file_get_contents($sourceRoot . '/server/fixtures/modules/delivery_record/resources/menus.json'), true, 64, JSON_THROW_ON_ERROR);
    $menus[0]['key'] = 'acme.governance.list';
    $menus[0]['required_permission'] = 'acme.governance.read';
    file_put_contents($resourceRoot . '/menus.json', json_encode($menus, JSON_THROW_ON_ERROR));
    copy($sourceRoot . '/server/fixtures/modules/delivery_record/resources/setting-definitions.json', $resourceRoot . '/setting-definitions.json');
    $migrationDirectory = dirname($manifestPath) . '/database/migrations';
    mkdir($migrationDirectory, 0777, true);
    file_put_contents($migrationDirectory . '/20261108005801_create_governance_delivery_records.php', <<<'PHP'
<?php

declare(strict_types=1);

use think\migration\Migrator;

final class CreateGovernanceDeliveryRecords extends Migrator
{
    public function up(): void
    {
        $this->execute('CREATE TABLE pa_fixture_delivery_record (id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, tenant_id BIGINT UNSIGNED NOT NULL, reference VARCHAR(160) NOT NULL, status VARCHAR(32) NOT NULL, created_at DATETIME(3) NOT NULL, updated_at DATETIME(3) NOT NULL) ENGINE=InnoDB');
        $this->execute('CREATE TABLE pa_fixture_delivery_aux (id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, record_id BIGINT UNSIGNED NOT NULL, CONSTRAINT fk_fixture_aux_record FOREIGN KEY (record_id) REFERENCES pa_fixture_delivery_record(id) ON DELETE RESTRICT) ENGINE=InnoDB');
    }
}
PHP
    );
}

function moduleGovernanceSeed(PDO $pdo, int $tenantId, int $roleId, int $platformRoleId): array
{
    $now = gmdate('Y-m-d H:i:s.v');
    $pdo->exec('CREATE TABLE IF NOT EXISTS pa_fixture_delivery_aux (id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, record_id BIGINT UNSIGNED NOT NULL, CONSTRAINT fk_fixture_aux_record FOREIGN KEY (record_id) REFERENCES pa_fixture_delivery_record(id) ON DELETE RESTRICT) ENGINE=InnoDB');
    $statement = $pdo->prepare('INSERT INTO pa_fixture_delivery_record (tenant_id,reference,status,created_at,updated_at) VALUES (?,?,?,?,?)');
    $statement->execute([$tenantId, 'preserved', 'recorded', $now, $now]);
    $recordId = (int) $pdo->lastInsertId();
    $statement = $pdo->prepare('INSERT INTO pa_fixture_delivery_aux(record_id) VALUES (?)');
    $statement->execute([$recordId]);

    $permissionId = (int) $pdo->query("SELECT id FROM pa_permission WHERE `key`='acme.governance.create'")->fetchColumn();
    $binding = $pdo->prepare('INSERT IGNORE INTO pa_role_permission (tenant_id,role_id,permission_id,granted_at) VALUES (?,?,?,?)');
    $binding->execute([$tenantId, $roleId, $permissionId, $now]);
    $binding = $pdo->prepare('INSERT IGNORE INTO pa_platform_role_permission (platform_role_id,permission_id,granted_at) VALUES (?,?,?)');
    $binding->execute([$platformRoleId, $permissionId, $now]);
    return ['permission_id' => $permissionId];
}

// P0E verify-module-context admits this installed APP before invoking this entry.
$GLOBALS['moduleGovernanceAssertions'] = 0;
$database = $databaseEnvironment['DB_NAME'];
$pdo = new PDO(sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4', $databaseEnvironment['DB_HOST'], $databaseEnvironment['DB_PORT'], $database),
    $databaseEnvironment['DB_USER'], $databaseEnvironment['DB_PASS'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
$identity = $pdo->query('SELECT @@server_uuid AS server_uuid,DATABASE() AS database_name')->fetch();
moduleGovernanceExpect(is_array($identity) && hash_equals($expectedServerUuid, strtolower((string) $identity['server_uuid']))
    && $identity['database_name'] === $database, 'Native qualification MySQL UUID/schema differs before the first write');
moduleGovernanceExpect((int) $pdo->query("SELECT COUNT(*) FROM pa_module_installation WHERE module_key='acme.governance'")->fetchColumn() === 0,
    'Retain the previous governance fixture and reconcile it before another run');
$sourceRoot = dirname(__DIR__, 3);
$temporary = sys_get_temp_dir() . '/pa-module-governance-' . bin2hex(random_bytes(8));
mkdir($temporary, 0700, true);
try {
    $catalogs = ThinkPhpTestConnection::moduleCatalogs($pdo);
    if (in_array('--resume-preinstall', $argv ?? [], true)) {
        $tenantId = (int) $pdo->query("SELECT id FROM pa_tenant WHERE code='module-runtime-governance' AND status='active'")->fetchColumn();
        $roleId = (int) $pdo->query("SELECT id FROM pa_role WHERE tenant_id={$tenantId} AND `key`='governance' AND status='active'")->fetchColumn();
        $platformRoleId = (int) $pdo->query("SELECT id FROM pa_platform_role WHERE `key`='module-governance' AND status='active'")->fetchColumn();
        moduleGovernanceExpect($tenantId > 0 && $roleId > 0 && $platformRoleId > 0,
            'Only the retained preinstall fixture actors can be resumed');
        moduleGovernanceExpect((int) $pdo->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name IN ('pa_fixture_delivery_record','pa_fixture_delivery_aux')")->fetchColumn() === 0,
            'A partial Module installation requires its native recovery entry');
    } else {
        $pdo->exec("INSERT INTO pa_tenant (code,name,display_name,status,created_at,updated_at) VALUES ('module-runtime-governance','Governance','Governance','active',UTC_TIMESTAMP(3),UTC_TIMESTAMP(3))");
        $tenantId = (int) $pdo->lastInsertId();
        $pdo->exec("INSERT INTO pa_role (tenant_id,`key`,name,status,created_at,updated_at) VALUES ({$tenantId},'governance','Governance','active',UTC_TIMESTAMP(3),UTC_TIMESTAMP(3))");
        $roleId = (int) $pdo->lastInsertId();
        $pdo->exec("INSERT INTO pa_platform_role (`key`,name,status,created_at,updated_at) VALUES ('module-governance','Governance','active',UTC_TIMESTAMP(3),UTC_TIMESTAMP(3))");
        $platformRoleId = (int) $pdo->lastInsertId();
    }
    $sourceProject = $temporary . '/source';
    $targetProject = $applicationRoot;
    moduleGovernanceProject($sourceRoot, $sourceProject, $applicationRoot . '/scripts/project-composer');
    $archivePath = $temporary . '/fixture-delivery-record.tar';
    $archive = new ModulePackageArchiveService($sourceProject . '/server');
    $packed = $archive->packModule('acme.governance', $archivePath);
    $moduleConfig = ['kernel_version' => '1.0.0', 'registered_client_keys' => ['admin-web', 'platform-web'], 'module_lock' => '../modules.lock'];
    $app->config->set($moduleConfig, 'modules');
    $installer = new ModulePackageInstaller($targetProject . '/server', $moduleConfig, [], $catalogs);
    $installed = $installer->install($archivePath, $packed['sha256'], null);
    moduleGovernanceExpect(($installed['operation'] ?? null) === 'installed', 'fixture package install failed');
    $unchangedInstall = $installer->install($archivePath, $packed['sha256'], null);
    moduleGovernanceExpect(($unchangedInstall['operation'] ?? null) === 'unchanged', 'repeated fixture package install was not idempotent');
    moduleGovernanceExpect((int) $pdo->query("SELECT COUNT(*) FROM pa_permission WHERE module_key='acme.governance' AND status='active'")->fetchColumn() === 2, 'fixture permissions were not activated');
    moduleGovernanceExpect((int) $pdo->query("SELECT COUNT(*) FROM pa_menu_definition WHERE module_key='acme.governance' AND status='active'")->fetchColumn() === 1, 'fixture menu was not activated');
    moduleGovernanceExpect((int) $pdo->query("SELECT COUNT(*) FROM pa_setting_definition WHERE module_key='acme.governance' AND status='active'")->fetchColumn() === 1, 'fixture setting definition was not activated');
    moduleGovernanceExpect((int) $pdo->query("SELECT COUNT(*) FROM pa_plan_module WHERE module_key='acme.governance'")->fetchColumn() === 0, 'fixture package install changed tenant plan selection');

    $purgeSeed = moduleGovernanceSeed($pdo, $tenantId, $roleId, $platformRoleId);
    $service = new ModuleRuntimeGovernanceService($targetProject . '/server', $moduleConfig, $catalogs, new \PeanutAdmin\Modules\Identity\Contract\TenantModuleStateQueries());
    $disableRequired = $service->preview('acme.governance', false);
    moduleGovernanceExpect(in_array('MODULE_DISABLE_REQUIRED', array_column($disableRequired['blockers'], 'code'), true), 'active installation did not require disabling before retire');
    $catalogs->disableActivePackageModules(['acme.governance']);
    $retirePreview = $service->preview('acme.governance', false);
    moduleGovernanceExpect($retirePreview['blockers'] === [], 'retire preview unexpectedly blocked');
    try {
        $service->uninstall('acme.governance', false, $retirePreview['confirm_plan'], str_repeat('0', 64));
        throw new RuntimeException('invalid retire plan digest was accepted');
    } catch (ModuleLifecycleException $exception) {
        moduleGovernanceExpect($exception->errorCode === 'MODULE_UNINSTALL_PLAN_CHANGED', 'invalid plan digest error changed');
    }
    moduleGovernanceExpect((string) $pdo->query("SELECT status FROM pa_module_installation WHERE module_key='acme.governance'")->fetchColumn() === 'maintenance', 'invalid plan digest mutated installation state');
    $now = gmdate('Y-m-d H:i:s.v');
    $pdo->exec("INSERT INTO pa_plan (code,name,status,created_at,updated_at) VALUES ('governance','Governance','active',UTC_TIMESTAMP(3),UTC_TIMESTAMP(3))");
    $planId = (int) $pdo->lastInsertId();
    $enabled = $pdo->prepare("INSERT INTO pa_plan_module (plan_id,module_key) VALUES (?,'acme.governance')");
    $enabled->execute([$planId]);
    $license = $pdo->prepare('INSERT INTO pa_tenant_license (tenant_id,plan_id,status,effective_at,created_at,updated_at) VALUES (?,?,\'active\',?,?,?)');
    $license->execute([$tenantId, $planId, $now, $now, $now]);
    $blocked = $service->preview('acme.governance', false);
    moduleGovernanceExpect(in_array('PLUGIN_TENANT_MODULE_ACTIVE', array_column($blocked['blockers'], 'code'), true), 'tenant licensed plan reference did not block retire preview');
    $pdo->exec("DELETE FROM pa_plan_module WHERE module_key='acme.governance'");
    $retirePreview = $service->preview('acme.governance', false);
    $retire = $service->uninstall('acme.governance', false, $retirePreview['confirm_plan'], $retirePreview['plan_digest']);
    moduleGovernanceExpect($retire['operation'] === 'retired', 'default uninstall did not retire');
    moduleGovernanceExpect((int) $pdo->query('SELECT COUNT(*) FROM pa_fixture_delivery_record')->fetchColumn() === 1, 'retire deleted business data');
    moduleGovernanceExpect((int) $pdo->query('SELECT COUNT(*) FROM pa_migrations WHERE version=20261108005801')->fetchColumn() === 1, 'retire deleted native migration history');
    moduleGovernanceExpect((int) $pdo->query("SELECT COUNT(*) FROM pa_role_permission rp JOIN pa_permission p ON p.id=rp.permission_id WHERE p.module_key='acme.governance'")->fetchColumn() === 1, 'retire deleted tenant role binding');
    moduleGovernanceExpect((string) $pdo->query("SELECT status FROM pa_permission WHERE module_key='acme.governance'")->fetchColumn() === 'retired', 'retire left permission active');

    $previewA = (new ModuleRuntimeGovernanceService($targetProject . '/server', $moduleConfig, $catalogs, new \PeanutAdmin\Modules\Identity\Contract\TenantModuleStateQueries()))->preview('acme.governance', true);
    $previewB = (new ModuleRuntimeGovernanceService($targetProject . '/server', $moduleConfig, $catalogs, new \PeanutAdmin\Modules\Identity\Contract\TenantModuleStateQueries()))->preview('acme.governance', true);
    moduleGovernanceExpect($previewA['plan_digest'] === $previewB['plan_digest'], 'purge preview digest is not deterministic');
    moduleGovernanceExpect($previewA['blockers'] === [], 'purge preview unexpectedly blocked');
    moduleGovernanceExpect(count($previewA['affected_modules']) === 1, 'purge after retire lost the quarantined Module scope');
    moduleGovernanceExpect(count(array_filter($previewA['removed'], static fn(array $entry): bool => in_array($entry['table'], ['pa_role_permission', 'pa_platform_role_permission'], true))) === 2, 'purge preview omitted explicit role bindings');
    try {
        (new ModuleRuntimeGovernanceService($targetProject . '/server', $moduleConfig, $catalogs, new \PeanutAdmin\Modules\Identity\Contract\TenantModuleStateQueries(), static function (string $point): void {
            if ($point === 'after-first-drop-statement') {
                throw new RuntimeException('injected interruption');
            }
        }))->uninstall('acme.governance', true, $previewA['confirm_plan'], $previewA['plan_digest']);
        throw new RuntimeException('purge interruption was not injected');
    } catch (RuntimeException $exception) {
        moduleGovernanceExpect($exception->getMessage() === 'injected interruption', 'unexpected purge interruption');
    }
    $state = $pdo->query("SELECT status,last_error_code FROM pa_module_installation WHERE module_key='acme.governance'")->fetch();
    moduleGovernanceExpect($state === ['status' => 'maintenance', 'last_error_code' => 'MODULE_PURGE_IN_PROGRESS'], 'interrupted purge marker changed');
    moduleGovernanceExpect((int) $pdo->query('SELECT COUNT(*) FROM pa_migrations WHERE version=20261108005801')->fetchColumn() === 1, 'interrupted purge cleared native migration history');
    $remainingTables = (int) $pdo->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name IN ('pa_fixture_delivery_record','pa_fixture_delivery_aux')")->fetchColumn();
    moduleGovernanceExpect($remainingTables === 1, 'interrupted purge did not leave a deterministic partial table set');

    $purged = (new ModuleRuntimeGovernanceService($targetProject . '/server', $moduleConfig, $catalogs, new \PeanutAdmin\Modules\Identity\Contract\TenantModuleStateQueries()))->uninstall('acme.governance', true, $previewA['confirm_plan'], $previewA['plan_digest']);
    moduleGovernanceExpect($purged['operation'] === 'purged', 'purge recovery did not finish');
    moduleGovernanceExpect((int) $pdo->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name IN ('pa_fixture_delivery_record','pa_fixture_delivery_aux')")->fetchColumn() === 0, 'purge left owned tables');
    moduleGovernanceExpect((int) $pdo->query('SELECT COUNT(*) FROM pa_migrations WHERE version=20261108005801')->fetchColumn() === 1, 'purge erased native migration history');
    moduleGovernanceExpect((int) $pdo->query("SELECT COUNT(*) FROM pa_permission WHERE module_key='acme.governance'")->fetchColumn() === 0, 'purge left catalog permission');
    $bindingCount = $pdo->prepare('SELECT COUNT(*) FROM pa_role_permission WHERE permission_id=?');
    $bindingCount->execute([$purgeSeed['permission_id']]);
    moduleGovernanceExpect((int) $bindingCount->fetchColumn() === 0, 'purge left tenant role binding');
    $bindingCount = $pdo->prepare('SELECT COUNT(*) FROM pa_platform_role_permission WHERE permission_id=?');
    $bindingCount->execute([$purgeSeed['permission_id']]);
    moduleGovernanceExpect((int) $bindingCount->fetchColumn() === 0, 'purge left platform role binding');
    $unchanged = (new ModuleRuntimeGovernanceService($targetProject . '/server', $moduleConfig, $catalogs, new \PeanutAdmin\Modules\Identity\Contract\TenantModuleStateQueries()))->uninstall('acme.governance', true, $previewA['confirm_plan'], $previewA['plan_digest']);
    moduleGovernanceExpect($unchanged['operation'] === 'unchanged', 'repeated clean purge was not idempotent');

    $codec = new ModuleUninstallPlanCodec();
    moduleGovernanceExpect($codec->digest(['b' => 2, 'a' => 1]) === $codec->digest(['a' => 1, 'b' => 2]), 'plan object key ordering changes digest');
    echo "MODULE-RUNTIME-GOVERNANCE-D2-001 passed assertions={$GLOBALS['moduleGovernanceAssertions']} sha256={$packed['sha256']} plan_digest={$previewA['plan_digest']}\n";
} finally {
    moduleGovernanceRemoveTree($temporary);
}
