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
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function moduleGovernanceCopyTree(string $source, string $target): void
{
    if (!is_dir($target)) {
        mkdir($target, 0777, true);
    }
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($source, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::SELF_FIRST);
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

function moduleGovernanceProject(string $sourceRoot, string $targetRoot): void
{
    moduleGovernanceCopyTree($sourceRoot . '/server/fixtures/modules/delivery_record', $targetRoot . '/server/app/modules/fixture/delivery_record');
    moduleGovernanceCopyTree($sourceRoot . '/web/tests/fixtures/modules/fixture-delivery-record', $targetRoot . '/web/src/modules/fixture-delivery-record');
    if (!is_dir($targetRoot . '/server/resources/schemas')) {
        mkdir($targetRoot . '/server/resources/schemas', 0777, true);
    }
    copy($sourceRoot . '/server/resources/schemas/module-artifact.schema.json', $targetRoot . '/server/resources/schemas/module-artifact.schema.json');
    $manifestPath = $targetRoot . '/server/app/modules/fixture/delivery_record/module.json';
    $manifest = json_decode((string) file_get_contents($manifestPath), true, 64, JSON_THROW_ON_ERROR);
    $manifest['database']['owned_tables'] = ['pa_fixture_delivery_record', 'pa_fixture_delivery_aux'];
    $manifest['backend']['migrations'] = 'database/migrations';
    file_put_contents($manifestPath, json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n");
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

    $permissionId = (int) $pdo->query("SELECT id FROM pa_permission WHERE `key`='fixture.delivery-record.create'")->fetchColumn();
    $binding = $pdo->prepare('INSERT IGNORE INTO pa_role_permission (tenant_id,role_id,permission_id,granted_at) VALUES (?,?,?,?)');
    $binding->execute([$tenantId, $roleId, $permissionId, $now]);
    $binding = $pdo->prepare('INSERT IGNORE INTO pa_platform_role_permission (platform_role_id,permission_id,granted_at) VALUES (?,?,?)');
    $binding->execute([$platformRoleId, $permissionId, $now]);
    return ['permission_id' => $permissionId];
}

// P0E verify-module-context admits this installed APP before invoking this entry.
$database = $databaseEnvironment['DB_NAME'];
$pdo = new PDO(sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4', $databaseEnvironment['DB_HOST'], $databaseEnvironment['DB_PORT'], $database),
    $databaseEnvironment['DB_USER'], $databaseEnvironment['DB_PASS'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
$identity = $pdo->query('SELECT @@server_uuid AS server_uuid,DATABASE() AS database_name')->fetch();
moduleGovernanceExpect(is_array($identity) && hash_equals($expectedServerUuid, strtolower((string) $identity['server_uuid']))
    && $identity['database_name'] === $database, 'Native qualification MySQL UUID/schema differs before the first write');
moduleGovernanceExpect((int) $pdo->query("SELECT COUNT(*) FROM pa_module_installation WHERE module_key='fixture.delivery-record'")->fetchColumn() === 0,
    'Retain the previous governance fixture and reconcile it before another run');
$sourceRoot = dirname(__DIR__, 3);
$temporary = sys_get_temp_dir() . '/pa-module-governance-' . bin2hex(random_bytes(8));
mkdir($temporary, 0700, true);
try {
    $catalogs = ThinkPhpTestConnection::moduleCatalogs($pdo);
    $pdo->exec("INSERT INTO pa_tenant (code,name,display_name,status,created_at,updated_at) VALUES ('runtime-governance','Governance','Governance','active',UTC_TIMESTAMP(3),UTC_TIMESTAMP(3))");
    $tenantId = (int) $pdo->lastInsertId();
    $pdo->exec("INSERT INTO pa_role (tenant_id,`key`,name,status,created_at,updated_at) VALUES ({$tenantId},'governance','Governance','active',UTC_TIMESTAMP(3),UTC_TIMESTAMP(3))");
    $roleId = (int) $pdo->lastInsertId();
    $pdo->exec("INSERT INTO pa_platform_role (`key`,name,status,created_at,updated_at) VALUES ('governance','Governance','active',UTC_TIMESTAMP(3),UTC_TIMESTAMP(3))");
    $platformRoleId = (int) $pdo->lastInsertId();
    $sourceProject = $temporary . '/source';
    $targetProject = $applicationRoot;
    moduleGovernanceProject($sourceRoot, $sourceProject);
    $archivePath = $temporary . '/fixture-delivery-record.tar';
    $archive = new ModulePackageArchiveService($sourceProject . '/server');
    $packed = $archive->packModule('fixture.delivery-record', $archivePath);
    $moduleConfig = ['kernel_version' => '1.0.0', 'registered_client_keys' => ['admin-web', 'platform-web'], 'module_lock' => '../modules.lock'];
    $app->config->set($moduleConfig, 'modules');
    $installer = new ModulePackageInstaller($targetProject . '/server', $moduleConfig, [], $catalogs);
    $installed = $installer->install($archivePath, $packed['sha256'], null);
    moduleGovernanceExpect(($installed['operation'] ?? null) === 'installed', 'fixture package install failed');
    $unchangedInstall = $installer->install($archivePath, $packed['sha256'], null);
    moduleGovernanceExpect(($unchangedInstall['operation'] ?? null) === 'unchanged', 'repeated fixture package install was not idempotent');
    moduleGovernanceExpect((int) $pdo->query("SELECT COUNT(*) FROM pa_permission WHERE module_key='fixture.delivery-record' AND status='active'")->fetchColumn() === 2, 'fixture permissions were not activated');
    moduleGovernanceExpect((int) $pdo->query("SELECT COUNT(*) FROM pa_menu_definition WHERE module_key='fixture.delivery-record' AND status='active'")->fetchColumn() === 1, 'fixture menu was not activated');
    moduleGovernanceExpect((int) $pdo->query("SELECT COUNT(*) FROM pa_setting_definition WHERE module_key='fixture.delivery-record' AND status='active'")->fetchColumn() === 1, 'fixture setting definition was not activated');
    moduleGovernanceExpect((int) $pdo->query("SELECT COUNT(*) FROM pa_plan_module WHERE module_key='fixture.delivery-record'")->fetchColumn() === 0, 'fixture package install changed tenant plan selection');

    $purgeSeed = moduleGovernanceSeed($pdo, $tenantId, $roleId, $platformRoleId);
    $service = new ModuleRuntimeGovernanceService($targetProject . '/server', $moduleConfig, $catalogs, new \PeanutAdmin\Modules\Identity\Contract\TenantModuleStateQueries());
    $disableRequired = $service->preview('fixture.delivery-record', false);
    moduleGovernanceExpect(in_array('MODULE_DISABLE_REQUIRED', array_column($disableRequired['blockers'], 'code'), true), 'active installation did not require disabling before retire');
    $catalogs->disableActivePackageModules(['fixture.delivery-record']);
    $retirePreview = $service->preview('fixture.delivery-record', false);
    moduleGovernanceExpect($retirePreview['blockers'] === [], 'retire preview unexpectedly blocked');
    try {
        $service->uninstall('fixture.delivery-record', false, $retirePreview['confirm_plan'], str_repeat('0', 64));
        throw new RuntimeException('invalid retire plan digest was accepted');
    } catch (ModuleLifecycleException $exception) {
        moduleGovernanceExpect($exception->errorCode === 'MODULE_UNINSTALL_PLAN_CHANGED', 'invalid plan digest error changed');
    }
    moduleGovernanceExpect((string) $pdo->query("SELECT status FROM pa_module_installation WHERE module_key='fixture.delivery-record'")->fetchColumn() === 'maintenance', 'invalid plan digest mutated installation state');
    $now = gmdate('Y-m-d H:i:s.v');
    $pdo->exec("INSERT INTO pa_plan (code,name,status,created_at,updated_at) VALUES ('governance','Governance','active',UTC_TIMESTAMP(3),UTC_TIMESTAMP(3))");
    $planId = (int) $pdo->lastInsertId();
    $enabled = $pdo->prepare("INSERT INTO pa_plan_module (plan_id,module_key) VALUES (?,'fixture.delivery-record')");
    $enabled->execute([$planId]);
    $license = $pdo->prepare('INSERT INTO pa_tenant_license (tenant_id,plan_id,status,effective_at,created_at,updated_at) VALUES (?,?,\'active\',?,?,?)');
    $license->execute([$tenantId, $planId, $now, $now, $now]);
    $blocked = $service->preview('fixture.delivery-record', false);
    moduleGovernanceExpect(in_array('PLUGIN_TENANT_MODULE_ACTIVE', array_column($blocked['blockers'], 'code'), true), 'tenant licensed plan reference did not block retire preview');
    $pdo->exec("DELETE FROM pa_plan_module WHERE module_key='fixture.delivery-record'");
    $retirePreview = $service->preview('fixture.delivery-record', false);
    $retire = $service->uninstall('fixture.delivery-record', false, $retirePreview['confirm_plan'], $retirePreview['plan_digest']);
    moduleGovernanceExpect($retire['operation'] === 'retired', 'default uninstall did not retire');
    moduleGovernanceExpect((int) $pdo->query('SELECT COUNT(*) FROM pa_fixture_delivery_record')->fetchColumn() === 1, 'retire deleted business data');
    moduleGovernanceExpect((int) $pdo->query('SELECT COUNT(*) FROM pa_migrations WHERE version=20261108005801')->fetchColumn() === 1, 'retire deleted native migration history');
    moduleGovernanceExpect((int) $pdo->query("SELECT COUNT(*) FROM pa_role_permission rp JOIN pa_permission p ON p.id=rp.permission_id WHERE p.module_key='fixture.delivery-record'")->fetchColumn() === 1, 'retire deleted tenant role binding');
    moduleGovernanceExpect((string) $pdo->query("SELECT status FROM pa_permission WHERE module_key='fixture.delivery-record'")->fetchColumn() === 'retired', 'retire left permission active');

    $previewA = (new ModuleRuntimeGovernanceService($targetProject . '/server', $moduleConfig, $catalogs, new \PeanutAdmin\Modules\Identity\Contract\TenantModuleStateQueries()))->preview('fixture.delivery-record', true);
    $previewB = (new ModuleRuntimeGovernanceService($targetProject . '/server', $moduleConfig, $catalogs, new \PeanutAdmin\Modules\Identity\Contract\TenantModuleStateQueries()))->preview('fixture.delivery-record', true);
    moduleGovernanceExpect($previewA['plan_digest'] === $previewB['plan_digest'], 'purge preview digest is not deterministic');
    moduleGovernanceExpect($previewA['blockers'] === [], 'purge preview unexpectedly blocked');
    moduleGovernanceExpect(count($previewA['affected_modules']) === 1, 'purge after retire lost the quarantined Module scope');
    moduleGovernanceExpect(count(array_filter($previewA['removed'], static fn(array $entry): bool => in_array($entry['table'], ['pa_role_permission', 'pa_platform_role_permission'], true))) === 2, 'purge preview omitted explicit role bindings');
    try {
        (new ModuleRuntimeGovernanceService($targetProject . '/server', $moduleConfig, $catalogs, new \PeanutAdmin\Modules\Identity\Contract\TenantModuleStateQueries(), static function (string $point): void {
            if ($point === 'after-first-drop-statement') {
                throw new RuntimeException('injected interruption');
            }
        }))->uninstall('fixture.delivery-record', true, $previewA['confirm_plan'], $previewA['plan_digest']);
        throw new RuntimeException('purge interruption was not injected');
    } catch (RuntimeException $exception) {
        moduleGovernanceExpect($exception->getMessage() === 'injected interruption', 'unexpected purge interruption');
    }
    $state = $pdo->query("SELECT status,last_error_code FROM pa_module_installation WHERE module_key='fixture.delivery-record'")->fetch();
    moduleGovernanceExpect($state === ['status' => 'maintenance', 'last_error_code' => 'MODULE_PURGE_IN_PROGRESS'], 'interrupted purge marker changed');
    moduleGovernanceExpect((int) $pdo->query('SELECT COUNT(*) FROM pa_migrations WHERE version=20261108005801')->fetchColumn() === 1, 'interrupted purge cleared native migration history');
    $remainingTables = (int) $pdo->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name IN ('pa_fixture_delivery_record','pa_fixture_delivery_aux')")->fetchColumn();
    moduleGovernanceExpect($remainingTables === 1, 'interrupted purge did not leave a deterministic partial table set');

    $purged = (new ModuleRuntimeGovernanceService($targetProject . '/server', $moduleConfig, $catalogs, new \PeanutAdmin\Modules\Identity\Contract\TenantModuleStateQueries()))->uninstall('fixture.delivery-record', true, $previewA['confirm_plan'], $previewA['plan_digest']);
    moduleGovernanceExpect($purged['operation'] === 'purged', 'purge recovery did not finish');
    moduleGovernanceExpect((int) $pdo->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name IN ('pa_fixture_delivery_record','pa_fixture_delivery_aux')")->fetchColumn() === 0, 'purge left owned tables');
    moduleGovernanceExpect((int) $pdo->query('SELECT COUNT(*) FROM pa_migrations WHERE version=20261108005801')->fetchColumn() === 1, 'purge erased native migration history');
    moduleGovernanceExpect((int) $pdo->query("SELECT COUNT(*) FROM pa_permission WHERE module_key='fixture.delivery-record'")->fetchColumn() === 0, 'purge left catalog permission');
    $bindingCount = $pdo->prepare('SELECT COUNT(*) FROM pa_role_permission WHERE permission_id=?');
    $bindingCount->execute([$purgeSeed['permission_id']]);
    moduleGovernanceExpect((int) $bindingCount->fetchColumn() === 0, 'purge left tenant role binding');
    $bindingCount = $pdo->prepare('SELECT COUNT(*) FROM pa_platform_role_permission WHERE permission_id=?');
    $bindingCount->execute([$purgeSeed['permission_id']]);
    moduleGovernanceExpect((int) $bindingCount->fetchColumn() === 0, 'purge left platform role binding');
    $unchanged = (new ModuleRuntimeGovernanceService($targetProject . '/server', $moduleConfig, $catalogs, new \PeanutAdmin\Modules\Identity\Contract\TenantModuleStateQueries()))->uninstall('fixture.delivery-record', true, $previewA['confirm_plan'], $previewA['plan_digest']);
    moduleGovernanceExpect($unchanged['operation'] === 'unchanged', 'repeated clean purge was not idempotent');

    $codec = new ModuleUninstallPlanCodec();
    moduleGovernanceExpect($codec->digest(['b' => 2, 'a' => 1]) === $codec->digest(['a' => 1, 'b' => 2]), 'plan object key ordering changes digest');
    echo "MODULE-RUNTIME-GOVERNANCE-D2-001 passed sha256={$packed['sha256']} plan_digest={$previewA['plan_digest']}\n";
} finally {
    moduleGovernanceRemoveTree($temporary);
}
