<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/bootstrap/environment.php';

use app\platform\service\plugin\ModuleUninstallPlanCodec;
use app\platform\service\plugin\PluginLifecycleException;
use app\platform\service\plugin\PluginPackageArchiveService;
use app\platform\service\plugin\PluginPackageInstaller;
use app\platform\service\plugin\PluginRuntimeGovernanceService;

require dirname(__DIR__, 2) . '/vendor/autoload.php';
require_once dirname(__DIR__, 2) . '/database/install.php';
require_once dirname(__DIR__) . '/Support/ThinkPhpTestConnection.php';

function moduleGovernanceExpect(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}

function moduleGovernanceCopyTree(string $source, string $target): void
{
    if (!is_dir($target)) mkdir($target, 0777, true);
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($source, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::SELF_FIRST);
    foreach ($iterator as $entry) {
        $relative = substr($entry->getPathname(), strlen($source) + 1);
        $destination = $target . '/' . $relative;
        if ($entry->isDir()) { if (!is_dir($destination)) mkdir($destination, 0777, true); }
        else copy($entry->getPathname(), $destination);
    }
}

function moduleGovernanceRemoveTree(string $path): void
{
    if (!is_dir($path)) return;
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($iterator as $entry) $entry->isDir() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
    rmdir($path);
}

function moduleGovernanceProject(string $sourceRoot, string $targetRoot): void
{
    moduleGovernanceCopyTree($sourceRoot . '/server/app/modules/fixture/delivery_record', $targetRoot . '/server/app/modules/fixture/delivery_record');
    moduleGovernanceCopyTree($sourceRoot . '/web/src/modules/fixture-delivery-record', $targetRoot . '/web/src/modules/fixture-delivery-record');
    if (!is_dir($targetRoot . '/server/resources/schemas')) mkdir($targetRoot . '/server/resources/schemas', 0777, true);
    copy($sourceRoot . '/server/resources/schemas/plugin.schema.json', $targetRoot . '/server/resources/schemas/plugin.schema.json');
    $manifestPath = $targetRoot . '/server/app/modules/fixture/delivery_record/module.json';
    $manifest = json_decode((string)file_get_contents($manifestPath), true, 64, JSON_THROW_ON_ERROR);
    $manifest['database']['owned_tables'] = ['pa_fixture_delivery_record', 'pa_fixture_delivery_aux'];
    file_put_contents($manifestPath, json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n");
}

function moduleGovernanceSeed(PDO $pdo): array
{
    $now = gmdate('Y-m-d H:i:s.v');
    $pdo->exec('CREATE TABLE IF NOT EXISTS pa_fixture_delivery_aux (id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, record_id BIGINT UNSIGNED NOT NULL, CONSTRAINT fk_fixture_aux_record FOREIGN KEY (record_id) REFERENCES pa_fixture_delivery_record(id) ON DELETE RESTRICT) ENGINE=InnoDB');
    $tenantId = (int)$pdo->query("SELECT id FROM pa_tenant WHERE status='active' ORDER BY id LIMIT 1")->fetchColumn();
    $statement = $pdo->prepare('INSERT INTO pa_fixture_delivery_record (tenant_id,reference,status,created_at,updated_at) VALUES (?,?,?,?,?)');
    $statement->execute([$tenantId, 'preserved', 'recorded', $now, $now]);
    $recordId = (int)$pdo->lastInsertId();
    $statement = $pdo->prepare('INSERT INTO pa_fixture_delivery_aux(record_id) VALUES (?)');
    $statement->execute([$recordId]);

    $permissionId = (int)$pdo->query("SELECT id FROM pa_permission WHERE `key`='fixture.delivery-record.create'")->fetchColumn();
    $role = $pdo->query("SELECT tenant_id,id FROM pa_role WHERE status='active' ORDER BY id LIMIT 1")->fetch(PDO::FETCH_ASSOC);
    moduleGovernanceExpect(is_array($role), 'active tenant role is unavailable');
    $binding = $pdo->prepare('INSERT IGNORE INTO pa_role_permission (tenant_id,role_id,permission_id,granted_at) VALUES (?,?,?,?)');
    $binding->execute([(int)$role['tenant_id'], (int)$role['id'], $permissionId, $now]);
    $platformRole = $pdo->query("SELECT id FROM pa_platform_role WHERE status='active' ORDER BY id LIMIT 1")->fetchColumn();
    if ($platformRole !== false) {
        $binding = $pdo->prepare('INSERT IGNORE INTO pa_platform_role_permission (platform_role_id,permission_id,granted_at) VALUES (?,?,?)');
        $binding->execute([(int)$platformRole, $permissionId, $now]);
    }
    return ['permission_id' => $permissionId];
}

$host = getenv('DB_HOST') ?: '';
$port = getenv('DB_PORT') ?: '';
$database = getenv('DB_NAME') ?: '';
$user = getenv('DB_USER') ?: '';
$password = getenv('DB_PASS') ?: '';
moduleGovernanceExpect($host !== '' && $port !== '' && $database !== '' && $user !== '', 'registered database environment is required');
$pdo = new PDO("mysql:host={$host};port={$port};dbname={$database};charset=utf8mb4", $user, $password, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES => false]);
$catalogs = ThinkPhpTestConnection::moduleCatalogs($pdo);
moduleGovernanceExpect((int)$pdo->query('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE()')->fetchColumn() === 0, 'governance test database must start empty');
initializeCoreIdentity(
    $pdo,
    'module-governance@example.test',
    'module-governance-test-password',
    null,
    new \app\common\service\DemoAccountPolicy(false, []),
);
executeSqlFiles($pdo, [dirname(__DIR__, 2) . '/database/init.sql']);

$sourceRoot = dirname(__DIR__, 3);
$temporary = sys_get_temp_dir() . '/pa-module-governance-' . bin2hex(random_bytes(8));
mkdir($temporary, 0700, true);
try {
    $sourceProject = $temporary . '/source';
    $targetProject = $temporary . '/target';
    moduleGovernanceProject($sourceRoot, $sourceProject);
    mkdir($targetProject . '/server/resources/schemas', 0777, true);
    copy(
        $sourceRoot . '/server/resources/schemas/plugin.schema.json',
        $targetProject . '/server/resources/schemas/plugin.schema.json',
    );
    $archivePath = $temporary . '/fixture-delivery-record.tar';
    $archive = new PluginPackageArchiveService($sourceProject . '/server');
    $packed = $archive->packModule('fixture.delivery-record', $archivePath);
    $moduleConfig = ['kernel_version' => '1.0.0', 'registered_client_keys' => ['admin-web', 'platform-web']];
    $installer = new PluginPackageInstaller($targetProject . '/server', $moduleConfig, [], $catalogs);
    $installed = $installer->install($archivePath, $packed['sha256'], null);
    moduleGovernanceExpect(($installed['operation'] ?? null) === 'installed', 'fixture package install failed');
    $unchangedInstall = $installer->install($archivePath, $packed['sha256'], null);
    moduleGovernanceExpect(($unchangedInstall['operation'] ?? null) === 'unchanged', 'repeated fixture package install was not idempotent');
    moduleGovernanceExpect((int)$pdo->query("SELECT COUNT(*) FROM pa_permission WHERE module_key='fixture.delivery-record' AND status='active'")->fetchColumn() === 2, 'fixture permissions were not activated');
    moduleGovernanceExpect((int)$pdo->query("SELECT COUNT(*) FROM pa_menu_definition WHERE module_key='fixture.delivery-record' AND status='active'")->fetchColumn() === 1, 'fixture menu was not activated');
    moduleGovernanceExpect((int)$pdo->query("SELECT COUNT(*) FROM pa_setting_definition WHERE module_key='fixture.delivery-record' AND status='active'")->fetchColumn() === 1, 'fixture setting definition was not activated');
    moduleGovernanceExpect((int)$pdo->query("SELECT COUNT(*) FROM pa_tenant_module WHERE module_key='fixture.delivery-record'")->fetchColumn() === 0, 'fixture package install changed TenantModule enablement');

    $purgeSeed = moduleGovernanceSeed($pdo);
    $service = new PluginRuntimeGovernanceService($targetProject . '/server', $moduleConfig, $catalogs);
    $retirePreview = $service->preview('fixture.delivery-record', false);
    moduleGovernanceExpect($retirePreview['blockers'] === [], 'retire preview unexpectedly blocked');
    try {
        $service->uninstall('fixture.delivery-record', false, $retirePreview['confirm_plan'], str_repeat('0', 64));
        throw new RuntimeException('invalid retire plan digest was accepted');
    } catch (PluginLifecycleException $exception) {
        moduleGovernanceExpect($exception->errorCode === 'MODULE_UNINSTALL_PLAN_CHANGED', 'invalid plan digest error changed');
    }
    moduleGovernanceExpect((string)$pdo->query("SELECT status FROM pa_plugin_installation WHERE plugin_key='fixture.delivery-record'")->fetchColumn() === 'active', 'invalid plan digest mutated installation state');
    $tenantId = (int)$pdo->query("SELECT id FROM pa_tenant WHERE status='active' ORDER BY id LIMIT 1")->fetchColumn();
    $now = gmdate('Y-m-d H:i:s.v');
    $enabled = $pdo->prepare(<<<'SQL'
INSERT INTO pa_tenant_module (tenant_id,module_key,status,source,config_json,config_revision,authorization_revision,effective_at,enabled_at,created_at,updated_at)
VALUES (?,'fixture.delivery-record','enabled','manual','{}',1,1,?,?,?,?)
SQL);
    $enabled->execute([$tenantId, $now, $now, $now, $now]);
    $blocked = $service->preview('fixture.delivery-record', false);
    moduleGovernanceExpect(in_array('PLUGIN_TENANT_MODULE_ACTIVE', array_column($blocked['blockers'], 'code'), true), 'enabled TenantModule did not block retire preview');
    $pdo->exec("DELETE FROM pa_tenant_module WHERE module_key='fixture.delivery-record'");
    $retirePreview = $service->preview('fixture.delivery-record', false);
    $retire = $service->uninstall('fixture.delivery-record', false, $retirePreview['confirm_plan'], $retirePreview['plan_digest']);
    moduleGovernanceExpect($retire['operation'] === 'retired', 'default uninstall did not retire');
    moduleGovernanceExpect((int)$pdo->query('SELECT COUNT(*) FROM pa_fixture_delivery_record')->fetchColumn() === 1, 'retire deleted business data');
    moduleGovernanceExpect((int)$pdo->query("SELECT COUNT(*) FROM pa_module_migration WHERE module_key='fixture.delivery-record'")->fetchColumn() === 1, 'retire deleted migration ledger');
    moduleGovernanceExpect((int)$pdo->query("SELECT COUNT(*) FROM pa_role_permission rp JOIN pa_permission p ON p.id=rp.permission_id WHERE p.module_key='fixture.delivery-record'")->fetchColumn() === 1, 'retire deleted tenant role binding');
    moduleGovernanceExpect((string)$pdo->query("SELECT status FROM pa_permission WHERE module_key='fixture.delivery-record'")->fetchColumn() === 'retired', 'retire left permission active');

    $previewA = (new PluginRuntimeGovernanceService($targetProject . '/server', $moduleConfig, $catalogs))->preview('fixture.delivery-record', true);
    $previewB = (new PluginRuntimeGovernanceService($targetProject . '/server', $moduleConfig, $catalogs))->preview('fixture.delivery-record', true);
    moduleGovernanceExpect($previewA['plan_digest'] === $previewB['plan_digest'], 'purge preview digest is not deterministic');
    moduleGovernanceExpect($previewA['blockers'] === [], 'purge preview unexpectedly blocked');
    moduleGovernanceExpect(count($previewA['affected_modules']) === 1, 'purge after retire lost the quarantined Module scope');
    moduleGovernanceExpect(count(array_filter($previewA['removed'], static fn(array $entry): bool => in_array($entry['table'], ['pa_role_permission', 'pa_platform_role_permission'], true))) === 2, 'purge preview omitted explicit role bindings');
    try {
        (new PluginRuntimeGovernanceService($targetProject . '/server', $moduleConfig, $catalogs, static function (string $point): void {
            if ($point === 'after-first-drop-statement') throw new RuntimeException('injected interruption');
        }))->uninstall('fixture.delivery-record', true, $previewA['confirm_plan'], $previewA['plan_digest']);
        throw new RuntimeException('purge interruption was not injected');
    } catch (RuntimeException $exception) {
        moduleGovernanceExpect($exception->getMessage() === 'injected interruption', 'unexpected purge interruption');
    }
    $state = $pdo->query("SELECT status,last_error_code FROM pa_plugin_installation WHERE plugin_key='fixture.delivery-record'")->fetch();
    moduleGovernanceExpect($state === ['status' => 'maintenance', 'last_error_code' => 'MODULE_PURGE_IN_PROGRESS'], 'interrupted purge marker changed');
    moduleGovernanceExpect((int)$pdo->query("SELECT COUNT(*) FROM pa_module_migration WHERE module_key='fixture.delivery-record'")->fetchColumn() === 1, 'interrupted purge cleared ledger before all tables');
    $remainingTables = (int)$pdo->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name IN ('pa_fixture_delivery_record','pa_fixture_delivery_aux')")->fetchColumn();
    moduleGovernanceExpect($remainingTables === 1, 'interrupted purge did not leave a deterministic partial table set');

    $purged = (new PluginRuntimeGovernanceService($targetProject . '/server', $moduleConfig, $catalogs))->uninstall('fixture.delivery-record', true, $previewA['confirm_plan'], $previewA['plan_digest']);
    moduleGovernanceExpect($purged['operation'] === 'purged', 'purge recovery did not finish');
    moduleGovernanceExpect((int)$pdo->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name IN ('pa_fixture_delivery_record','pa_fixture_delivery_aux')")->fetchColumn() === 0, 'purge left owned tables');
    moduleGovernanceExpect((int)$pdo->query("SELECT COUNT(*) FROM pa_module_migration WHERE module_key='fixture.delivery-record'")->fetchColumn() === 0, 'purge left migration ledger');
    moduleGovernanceExpect((int)$pdo->query("SELECT COUNT(*) FROM pa_permission WHERE module_key='fixture.delivery-record'")->fetchColumn() === 0, 'purge left catalog permission');
    $bindingCount = $pdo->prepare('SELECT COUNT(*) FROM pa_role_permission WHERE permission_id=?');
    $bindingCount->execute([$purgeSeed['permission_id']]);
    moduleGovernanceExpect((int)$bindingCount->fetchColumn() === 0, 'purge left tenant role binding');
    $bindingCount = $pdo->prepare('SELECT COUNT(*) FROM pa_platform_role_permission WHERE permission_id=?');
    $bindingCount->execute([$purgeSeed['permission_id']]);
    moduleGovernanceExpect((int)$bindingCount->fetchColumn() === 0, 'purge left platform role binding');
    $unchanged = (new PluginRuntimeGovernanceService($targetProject . '/server', $moduleConfig, $catalogs))->uninstall('fixture.delivery-record', true, $previewA['confirm_plan'], $previewA['plan_digest']);
    moduleGovernanceExpect($unchanged['operation'] === 'unchanged', 'repeated clean purge was not idempotent');

    $codec = new ModuleUninstallPlanCodec();
    moduleGovernanceExpect($codec->digest(['b' => 2, 'a' => 1]) === $codec->digest(['a' => 1, 'b' => 2]), 'plan object key ordering changes digest');
    echo "MODULE-RUNTIME-GOVERNANCE-D2-001 passed sha256={$packed['sha256']} plan_digest={$previewA['plan_digest']}\n";
} finally {
    moduleGovernanceRemoveTree($temporary);
}
