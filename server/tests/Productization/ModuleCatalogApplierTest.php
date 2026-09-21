<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/bootstrap/environment.php';

use app\platform\service\plugin\ModuleCatalogApplier;
use app\platform\service\plugin\PluginModuleRegistryFactory;

require dirname(__DIR__, 2) . '/vendor/autoload.php';
require_once dirname(__DIR__, 2) . '/database/install.php';
require_once dirname(__DIR__) . '/Support/ThinkPhpTestConnection.php';

function moduleCatalogExpect(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}

$host = getenv('DB_HOST') ?: '';
$port = getenv('DB_PORT') ?: '';
$database = getenv('DB_NAME') ?: '';
$user = getenv('DB_USER') ?: '';
$password = getenv('DB_PASS') ?: '';
moduleCatalogExpect($host !== '' && $port !== '' && $database !== '' && $user !== '', 'registered database environment is required');
moduleCatalogExpect(
    preg_match('/^peanut_admin_development_p0e_[a-z0-9]{1,11}_plugin_lifecycle$/D', $database) === 1,
    'catalog test requires the exact registered P0-E plugin_lifecycle database',
);
$pdo = new PDO(
    "mysql:host={$host};port={$port};dbname={$database};charset=utf8mb4",
    $user,
    $password,
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES => false],
);
moduleCatalogExpect((int)$pdo->query('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE()')->fetchColumn() === 0, 'catalog test database must start empty');
initializeCoreIdentity(
    $pdo,
    'module-catalog@example.test',
    'module-catalog-test-password',
    null,
    new \app\common\service\DemoAccountPolicy(false, []),
);
executeSqlFiles($pdo, [dirname(__DIR__, 2) . '/database/init.sql']);

$serverRoot = dirname(__DIR__, 2);
$registry = (new PluginModuleRegistryFactory($serverRoot))->fromDeploymentConfig([
    'roots' => ['app/modules/fixture/delivery_record'],
    'kernel_version' => '1.0.0',
    'registered_client_keys' => ['admin-web', 'platform-web'],
])->compiled();
$applier = ThinkPhpTestConnection::moduleCatalogs($pdo);

$first = $applier->apply($registry);
moduleCatalogExpect($first['operation'] === 'synced', 'first catalog apply was not synchronized');
moduleCatalogExpect($first['modules'] === ['fixture.delivery-record'], 'applied Module scope changed');
moduleCatalogExpect($first['changes'] === ['menus' => 1, 'permissions' => 2, 'settings' => 1], 'active catalog counts changed');
$identity = $pdo->query(<<<'SQL'
SELECT
 (SELECT GROUP_CONCAT(CONCAT(`key`,':',id) ORDER BY `key`) FROM pa_permission WHERE module_key='fixture.delivery-record') permissions,
 (SELECT GROUP_CONCAT(CONCAT(`key`,':',id) ORDER BY `key`) FROM pa_menu_definition WHERE module_key='fixture.delivery-record') menus,
 (SELECT GROUP_CONCAT(CONCAT(setting_key,':',id) ORDER BY setting_key) FROM pa_setting_definition WHERE module_key='fixture.delivery-record') settings
SQL)->fetch();
$second = $applier->apply($registry);
moduleCatalogExpect($second['operation'] === 'unchanged', 'repeated catalog apply was not idempotent');
moduleCatalogExpect($second['catalog_revision'] === $first['catalog_revision'], 'repeated catalog apply changed revision');
moduleCatalogExpect($pdo->query(<<<'SQL'
SELECT
 (SELECT GROUP_CONCAT(CONCAT(`key`,':',id) ORDER BY `key`) FROM pa_permission WHERE module_key='fixture.delivery-record') permissions,
 (SELECT GROUP_CONCAT(CONCAT(`key`,':',id) ORDER BY `key`) FROM pa_menu_definition WHERE module_key='fixture.delivery-record') menus,
 (SELECT GROUP_CONCAT(CONCAT(setting_key,':',id) ORDER BY setting_key) FROM pa_setting_definition WHERE module_key='fixture.delivery-record') settings
SQL)->fetch() === $identity, 'idempotent catalog apply changed string-key identities');

$applier->retire(['fixture.delivery-record']);
foreach (['pa_permission', 'pa_menu_definition', 'pa_setting_definition'] as $table) {
    moduleCatalogExpect((int)$pdo->query("SELECT COUNT(*) FROM `{$table}` WHERE module_key='fixture.delivery-record' AND status='active'")->fetchColumn() === 0, "retire left active rows in {$table}");
    moduleCatalogExpect((int)$pdo->query("SELECT COUNT(*) FROM `{$table}` WHERE module_key='fixture.delivery-record' AND status='retired'")->fetchColumn() > 0, "retire deleted or omitted rows in {$table}");
}
$reactivated = $applier->apply($registry);
moduleCatalogExpect($reactivated['operation'] === 'synced', 'catalog apply did not reactivate retired rows');
moduleCatalogExpect((int)$pdo->query("SELECT COUNT(*) FROM pa_permission WHERE module_key='fixture.delivery-record' AND retired_at IS NOT NULL")->fetchColumn() === 0, 'permission reactivation retained retired_at');

$pdo->exec("UPDATE pa_permission SET status='retired',retired_at=UTC_TIMESTAMP(3) WHERE `key`='fixture.delivery-record.read'");
$pdo->exec("UPDATE pa_permission SET module_key='fixture.conflict-owner' WHERE `key`='fixture.delivery-record.create'");
$beforeFailure = $applier->catalogRevision();
try {
    $applier->apply($registry);
    throw new RuntimeException('catalog owner conflict was accepted');
} catch (DomainException $exception) {
    moduleCatalogExpect($exception->getMessage() === 'Catalog key is already owned by another module.', 'catalog owner conflict error changed');
}
moduleCatalogExpect($applier->catalogRevision() === $beforeFailure, 'failed catalog apply was not atomic');
moduleCatalogExpect((string)$pdo->query("SELECT status FROM pa_permission WHERE `key`='fixture.delivery-record.read'")->fetchColumn() === 'retired', 'failed apply leaked a partial permission reactivation');

echo 'MODULE-CATALOG-APPLIER-B-001 passed first=' . $first['catalog_revision']
    . ' reactivated=' . $reactivated['catalog_revision'] . "\n";
