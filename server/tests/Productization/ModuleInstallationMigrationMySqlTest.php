<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';
require dirname(__DIR__, 2) . '/database/migrations/20261103230000_unify_module_installation.php';

use Phinx\Db\Adapter\MysqlAdapter;
use Phinx\Migration\Manager\Environment;

foreach (['DB_HOST', 'DB_PORT', 'DB_NAME', 'DB_USER', 'DB_PASS'] as $key) {
    if (getenv($key) === false || ($key !== 'DB_PASS' && getenv($key) === '')) {
        throw new RuntimeException('Explicit isolated test environment is required: ' . $key);
    }
}
$dsn = sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4', getenv('DB_HOST'), getenv('DB_PORT'), getenv('DB_NAME'));
$pdo = new PDO($dsn, (string) getenv('DB_USER'), (string) getenv('DB_PASS'), [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$database = (string) $pdo->query('SELECT DATABASE()')->fetchColumn();
if ($database !== getenv('DB_NAME')) { throw new RuntimeException('Selected test database differs from the explicit environment.'); }
if ($pdo->query('SHOW TABLES')->fetchAll() !== []) {
    throw new RuntimeException('The first migration acceptance run requires the task-owned empty database.');
}
$assertions = 0;
$expect = static function (bool $value, string $message) use (&$assertions): void {
    ++$assertions;
    if (!$value) { throw new RuntimeException($message); }
};
$server = dirname(__DIR__, 2);
$sources = file_get_contents($server . '/database/schema/baseline-schema.sql')
    . file_get_contents($server . '/app/modules/peanut/identity/database/migrations/20261101010001_initial_identity_schema.php');
foreach (['pa_plugin_installation', 'pa_plugin_module', 'pa_module_installation'] as $name) {
    if (preg_match('/CREATE TABLE `' . $name . '` \(.*?\n\) ENGINE=.*?;/s', $sources, $match) !== 1) {
        throw new RuntimeException('Missing original baseline definition: ' . $name);
    }
    $pdo->exec($match[0]);
}
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
