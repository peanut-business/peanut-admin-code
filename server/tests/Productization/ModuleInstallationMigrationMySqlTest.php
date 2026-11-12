<?php

declare(strict_types=1);

require ((getenv('PEANUT_TEST_APPLICATION_ROOT') ?: dirname(__DIR__, 3)) . '/server') . '/vendor/autoload.php';
require ((getenv('PEANUT_TEST_APPLICATION_ROOT') ?: dirname(__DIR__, 3)) . '/server') . '/database/migrations/20261103230000_unify_module_installation.php';
require_once dirname(__DIR__) . '/Support/CiBootstrap.php';
require_once dirname(__DIR__) . '/Support/RegisteredMysqlTestResource.php';
$app = \think\Container::getInstance();
if (!$app instanceof \think\App) {
    $app = new \think\App(((getenv('PEANUT_TEST_APPLICATION_ROOT') ?: dirname(__DIR__, 3)) . '/server'));
}
require_once $app->getThinkPath() . 'helper.php';

use Phinx\Db\Adapter\MysqlAdapter;
use Phinx\Migration\Manager\Environment;

$database = RegisteredMysqlTestResource::configuredDatabaseName();
[$pdo, $databaseCreated] = RegisteredMysqlTestResource::openEmptyDatabase($database);
try {
$assertions = 0;
$expect = static function (bool $value, string $message) use (&$assertions): void {
    ++$assertions;
    if (!$value) { throw new RuntimeException($message); }
};
$server = ((getenv('PEANUT_TEST_APPLICATION_ROOT') ?: dirname(__DIR__, 3)) . '/server');
$sources = file_get_contents($server . '/app/modules/peanut/identity/database/migrations/20261101010001_initial_identity_schema.php');
foreach (['pa_module_installation'] as $name) {
    if (preg_match('/CREATE TABLE `' . $name . '` \(.*?\n\) ENGINE=.*?;/s', $sources, $match) !== 1) {
        throw new RuntimeException('Missing original baseline definition: ' . $name);
    }
    $pdo->exec($match[0]);
}
// Historical inputs to the live UnifyModuleInstallation conversion, fixed from
// baseline-schema.sql at a7ef1a4a6^; current installation must never recreate these.
$pdo->exec(<<<'SQL'
CREATE TABLE pa_plugin_installation (
  id bigint unsigned NOT NULL AUTO_INCREMENT PRIMARY KEY,
  plugin_key varchar(96) NOT NULL UNIQUE,
  installed_version varchar(32) NOT NULL,
  source varchar(255) NOT NULL,
  artifact_sha256 char(64) NOT NULL,
  lock_digest char(64) NOT NULL,
  composer_identity_json json NOT NULL,
  npm_identity_json json NOT NULL,
  frontend_identity_json json NOT NULL,
  status varchar(24) NOT NULL,
  revision bigint unsigned NOT NULL DEFAULT 1,
  installed_at datetime(3) DEFAULT NULL,
  activated_at datetime(3) DEFAULT NULL,
  upgraded_at datetime(3) DEFAULT NULL,
  uninstalled_at datetime(3) DEFAULT NULL,
  last_error_code varchar(96) DEFAULT NULL,
  created_at datetime(3) NOT NULL,
  updated_at datetime(3) NOT NULL,
  KEY idx_plugin_installation_status (status,plugin_key),
  CONSTRAINT chk_plugin_installation_status CHECK (status IN ('installing','active','upgrading','maintenance','failed','uninstalled'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
CREATE TABLE pa_plugin_module (
  id bigint unsigned NOT NULL AUTO_INCREMENT PRIMARY KEY,
  plugin_key varchar(96) NOT NULL,
  module_key varchar(96) NOT NULL UNIQUE,
  module_version varchar(32) NOT NULL,
  manifest_digest char(64) NOT NULL,
  created_at datetime(3) NOT NULL,
  updated_at datetime(3) NOT NULL,
  KEY idx_plugin_module_plugin (plugin_key,module_key),
  CONSTRAINT fk_plugin_module_plugin FOREIGN KEY (plugin_key) REFERENCES pa_plugin_installation (plugin_key) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
SQL);
$pdo->exec("CREATE TABLE fictional_business (id bigint PRIMARY KEY, value varchar(96)); INSERT INTO fictional_business VALUES (7,'keep-business')");
$digest = str_repeat('a', 64);
$time = '2026-11-01 01:02:03.004';
$module = $pdo->prepare('INSERT INTO pa_module_installation (id,module_key,installed_version,manifest_schema_version,manifest_digest,status,installed_at,created_at,updated_at) VALUES (?,?,?,?,?,?,?,?,?)');
$package = $pdo->prepare('INSERT INTO pa_plugin_installation (plugin_key,installed_version,source,artifact_sha256,lock_digest,composer_identity_json,npm_identity_json,frontend_identity_json,status,installed_at,last_error_code,created_at,updated_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)');
foreach (['acme.active' => 'active', 'acme.disabled' => 'maintenance'] as $index => $status) {
    $id = $index === 'acme.active' ? 101 : 202;
    $module->execute([$id, $index, '1.2.3', 1, $digest, $status, $time, $time, $time]);
    $package->execute([$index,'1.2.3','canonical-contents:fixture',$digest,$digest,'[]','[]','[]','active',$time,null,$time,$time]);
    $pdo->prepare('INSERT INTO pa_plugin_module (plugin_key,module_key,module_version,manifest_digest,created_at,updated_at) VALUES (?,?,?,?,?,?)')->execute([$index,$index,'1.2.3',$digest,$time,$time]);
}
foreach (['acme.failed' => 'failed', 'acme.installing' => 'installing', 'acme.purged' => 'uninstalled'] as $key => $status) {
    $package->execute([$key,'2.3.4','canonical-contents:original-attempt',$digest,$digest,'[]','[]','[]',$status,$time,$status === 'failed' ? 'ORIGINAL_INSTALL_FAILURE' : null,$time,$time]);
}
$adapter = new MysqlAdapter(['name' => $database, 'migration_table' => 'pa_migrations']);
$adapter->setConnection($pdo);
$environment = new Environment('task-isolated-acceptance', []);
$environment->setAdapter($adapter);
$environment->executeMigration(new UnifyModuleInstallation('task-isolated-acceptance', 20261103230000));
$rows = $pdo->query('SELECT * FROM pa_module_installation')->fetchAll(PDO::FETCH_UNIQUE | PDO::FETCH_ASSOC);
// Primary-key fetch layout is deliberately checked against the original numeric IDs.
$expect($rows[101]['module_key'] === 'acme.active', 'Existing active Module ID changed');
$expect($rows[202]['status'] === 'maintenance', 'Disabled Module resurrected');
$byKey = [];
foreach ($rows as $id => $row) { $byKey[$row['module_key']] = $row + ['id' => $id]; }
$expect(count($byKey) === 5, 'Legacy history was lost');
$expect($byKey['acme.failed']['status'] === 'failed' && $byKey['acme.failed']['last_error_code'] === 'ORIGINAL_INSTALL_FAILURE', 'Original failed attempt changed');
$expect($byKey['acme.failed']['installed_version'] === '2.3.4' && $byKey['acme.failed']['artifact_sha256'] === $digest, 'Recovery identity changed');
$expect($byKey['acme.failed']['manifest_digest'] === null, 'Missing successful manifest identity was fabricated');
$expect($byKey['acme.installing']['status'] === 'installing', 'Interrupted installation was lost');
$expect($byKey['acme.purged']['status'] === 'uninstalled', 'Purged installation history was lost');
$expect($pdo->query("SELECT value FROM fictional_business WHERE id=7")->fetchColumn() === 'keep-business', 'Business data changed');
$expect(!$adapter->hasTable('pa_plugin_installation') && !$adapter->hasTable('pa_plugin_module'), 'Retired parallel ledgers remain');
$expect((int) $pdo->query('SELECT COUNT(*) FROM pa_migrations WHERE version=20261103230000')->fetchColumn() === 1, 'Native migration identity was not recorded');
$snapshot = $pdo->query('SELECT * FROM pa_module_installation ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
// MySQL DDL can commit before the native ledger receipt; rerunning up must preserve the merged identities.
$migration = new UnifyModuleInstallation('task-isolated-acceptance', 20261103230000);
$migration->setAdapter($adapter);
$migration->up();
$expect($snapshot === $pdo->query('SELECT * FROM pa_module_installation ORDER BY id')->fetchAll(PDO::FETCH_ASSOC), 'Interrupted migration resume changed history');
echo 'MODULE-INSTALLATION-MYSQL assertions=' . $assertions . '; MySQL=' . $pdo->query('SELECT VERSION()')->fetchColumn() . "\n";
} finally {
    RegisteredMysqlTestResource::cleanup($pdo, $database, $databaseCreated);
}
