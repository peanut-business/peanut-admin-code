<?php

declare(strict_types=1);

use PeanutAdmin\Kernel\Migration\ModuleSchema;

require dirname(__DIR__, 2) . '/vendor/autoload.php';
require __DIR__ . '/../Support/IsolatedBackendEnvironment.php';

function mt05ModuleInstallExpect(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

/** @return array{exit:int,output:string,json:array<string,mixed>} */
function mt05ModuleInstallRun(string $serverRoot, string $database, string $moduleRoots, string $moduleKey): array
{
    IsolatedBackendEnvironment::activate([
        'DB_HOST' => IsolatedBackendEnvironment::required('DB_HOST'),
        'DB_PORT' => IsolatedBackendEnvironment::required('DB_PORT'),
        'DB_NAME' => $database,
        'DB_USER' => IsolatedBackendEnvironment::required('DB_USER'),
        'DB_PASS' => IsolatedBackendEnvironment::required('DB_PASS'),
        'DB_PREFIX' => 'pa_',
        'PEANUT_MODULE_ROOTS' => $moduleRoots,
        'PEANUT_MODULE_KERNEL_VERSION' => '1.0.0',
    ]);
    $environment = getenv() ?: [];
    $pipes = [];
    $process = proc_open(
        [PHP_BINARY, $serverRoot . '/think', 'module:install', $moduleKey],
        [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes,
        $serverRoot,
        $environment,
    );
    mt05ModuleInstallExpect(is_resource($process), 'module:install process did not start');
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exit = proc_close($process);
    $output = trim((string) $stdout . (string) $stderr);
    $lines = preg_split('/\R/', $output) ?: [];
    $json = json_decode((string) end($lines), true);
    mt05ModuleInstallExpect(is_array($json), "module:install did not return JSON: {$output}");

    return ['exit' => $exit, 'output' => $output, 'json' => $json];
}

function mt05ModuleInstallRemoveTree(string $path): void
{
    if (!is_dir($path)) {
        return;
    }
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST,
    );
    foreach ($iterator as $entry) {
        $entry->isDir() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
    }
    rmdir($path);
}

$serverRoot = dirname(__DIR__, 2);
$fixtureRoot = $serverRoot . '/app/Modules/Mt05/DeploymentFixture';
$fixtureParent = $serverRoot . '/app/Modules';
$host = IsolatedBackendEnvironment::required('DB_HOST');
$port = IsolatedBackendEnvironment::required('DB_PORT');
$user = IsolatedBackendEnvironment::required('DB_USER');
$password = IsolatedBackendEnvironment::required('DB_PASS');
$admin = new PDO(
    "mysql:host={$host};port={$port};charset=utf8mb4",
    $user,
    $password,
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES => false],
);
$database = 'pa_mt05_module_install_' . strtolower(bin2hex(random_bytes(6)));
$admin->exec("CREATE DATABASE `{$database}` CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci");

try {
    mt05ModuleInstallExpect(!file_exists($fixtureRoot), 'exclusive Module fixture path already exists');
    mkdir($fixtureRoot, 0777, true);
    file_put_contents($fixtureRoot . '/ModuleProvider.php', <<<'PHP'
<?php
declare(strict_types=1);

namespace app\Modules\Mt05\DeploymentFixture;

use PeanutAdmin\Kernel\Module\ModuleProvider as ModuleProviderContract;

final class ModuleProvider implements ModuleProviderContract
{
    public function moduleKey(): string
    {
        return 'mt05.deployment-fixture';
    }

    public function bindings(): array
    {
        return [];
    }
}
PHP);
    file_put_contents($fixtureRoot . '/module.json', <<<'JSON'
{
  "schema_version": 1,
  "key": "mt05.deployment-fixture",
  "name": "MT05 Deployment Fixture",
  "description": "Real app Module layout fixture for deployment installation",
  "version": "1.2.3",
  "kernel_constraint": "^1.0",
  "license": "Apache-2.0",
  "dependencies": [],
  "backend": { "provider": "app\\Modules\\Mt05\\DeploymentFixture\\ModuleProvider" },
  "frontend": { "entry": "web/src/modules/mt05-deployment-fixture/index.ts" },
  "database": { "owned_tables": [] },
  "contracts": { "exports": [], "events": [] },
  "tenant": {
    "enableable": true,
    "disable_behavior": "reject_new_operations",
    "requires": []
  }
}
JSON);

    $pdo = new PDO(
        "mysql:host={$host};port={$port};dbname={$database};charset=utf8mb4",
        $user,
        $password,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ],
    );
    $pdo->exec(ModuleSchema::createSql('pa_module_installation'));
    $pdo->exec(<<<'SQL'
CREATE TABLE pa_tenant_module (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    tenant_id BIGINT UNSIGNED NOT NULL,
    module_key VARCHAR(96) NOT NULL
) ENGINE=InnoDB
SQL);

    $empty = mt05ModuleInstallRun($serverRoot, $database, '', 'mt05.deployment-fixture');
    mt05ModuleInstallExpect($empty['exit'] === 1, 'empty PEANUT_MODULE_ROOTS did not fail');
    mt05ModuleInstallExpect(($empty['json']['error'] ?? null) === 'MODULE_REGISTRY_UNAVAILABLE', 'empty roots error changed');

    $unknown = mt05ModuleInstallRun($serverRoot, $database, $fixtureRoot, 'mt05.unknown');
    mt05ModuleInstallExpect($unknown['exit'] === 1, 'unknown Module did not fail');
    mt05ModuleInstallExpect(($unknown['json']['error'] ?? null) === 'MODULE_NOT_REGISTERED', 'unknown Module error changed');
    $invalid = mt05ModuleInstallRun($serverRoot, $database, $fixtureRoot, 'INVALID MODULE KEY');
    mt05ModuleInstallExpect($invalid['exit'] === 1, 'invalid Module key did not fail');
    mt05ModuleInstallExpect(($invalid['json']['error'] ?? null) === 'MODULE_NOT_REGISTERED', 'invalid Module key error changed');
    mt05ModuleInstallExpect((int) $pdo->query('SELECT COUNT(*) FROM pa_module_installation')->fetchColumn() === 0, 'failed commands wrote installation state');

    $first = mt05ModuleInstallRun($serverRoot, $database, $fixtureRoot, 'mt05.deployment-fixture');
    mt05ModuleInstallExpect($first['exit'] === 0, "first installation failed: {$first['output']}");
    mt05ModuleInstallExpect(
        array_keys($first['json']) === ['key', 'version', 'digest', 'status'],
        'command output exposed fields outside the public Module identity',
    );
    mt05ModuleInstallExpect(($first['json']['key'] ?? null) === 'mt05.deployment-fixture', 'installed key changed');
    mt05ModuleInstallExpect(($first['json']['version'] ?? null) === '1.2.3', 'installed version changed');
    mt05ModuleInstallExpect(($first['json']['status'] ?? null) === 'active', 'installed status changed');
    mt05ModuleInstallExpect(preg_match('/^[a-f0-9]{64}$/D', (string) ($first['json']['digest'] ?? '')) === 1, 'installed digest is invalid');
    mt05ModuleInstallExpect(!str_contains($first['output'], $password), 'command output exposed a database secret');

    $record = $pdo->query(<<<'SQL'
SELECT id,module_key,installed_version,manifest_schema_version,manifest_digest,status,revision,
       installed_at,activated_at,created_at,updated_at
FROM pa_module_installation
SQL)->fetch();
    mt05ModuleInstallExpect(is_array($record), 'installation record is missing');
    mt05ModuleInstallExpect((string) $record['module_key'] === $first['json']['key'], 'record key differs');
    mt05ModuleInstallExpect((string) $record['installed_version'] === $first['json']['version'], 'record version differs');
    mt05ModuleInstallExpect((int) $record['manifest_schema_version'] === 1, 'record schema differs');
    mt05ModuleInstallExpect((string) $record['manifest_digest'] === $first['json']['digest'], 'record digest differs');
    mt05ModuleInstallExpect((string) $record['status'] === 'active', 'record status differs');
    mt05ModuleInstallExpect((int) $pdo->query('SELECT COUNT(*) FROM pa_tenant_module')->fetchColumn() === 0, 'deployment install enabled a Tenant Module');

    $second = mt05ModuleInstallRun($serverRoot, $database, $fixtureRoot, 'mt05.deployment-fixture');
    $recordAgain = $pdo->query(<<<'SQL'
SELECT id,module_key,installed_version,manifest_schema_version,manifest_digest,status,revision,
       installed_at,activated_at,created_at,updated_at
FROM pa_module_installation
SQL)->fetch();
    mt05ModuleInstallExpect($second['exit'] === 0 && $second['json'] === $first['json'], 'same identity was not idempotent');
    mt05ModuleInstallExpect($recordAgain === $record, 'idempotent installation mutated the record');

    $pdo->exec("UPDATE pa_module_installation SET installed_version='9.9.9'");
    $drift = mt05ModuleInstallRun($serverRoot, $database, $fixtureRoot, 'mt05.deployment-fixture');
    mt05ModuleInstallExpect($drift['exit'] === 1, 'installation identity drift did not fail');
    mt05ModuleInstallExpect(($drift['json']['error'] ?? null) === 'MODULE_INSTALLATION_MISMATCH', 'identity drift error changed');
    mt05ModuleInstallExpect((string) $pdo->query('SELECT installed_version FROM pa_module_installation')->fetchColumn() === '9.9.9', 'identity drift was overwritten');

    echo 'MT05-DEPLOYMENT-MODULE-INSTALL-COMMAND-001 passed identity='
        . json_encode($first['json'], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)
        . "\n";
} finally {
    $admin->exec("DROP DATABASE IF EXISTS `{$database}`");
    mt05ModuleInstallRemoveTree($fixtureRoot);
    $mt05Root = dirname($fixtureRoot);
    if (is_dir($mt05Root) && (scandir($mt05Root) ?: []) === ['.', '..']) {
        rmdir($mt05Root);
    }
    if (is_dir($fixtureParent) && (scandir($fixtureParent) ?: []) === ['.', '..']) {
        rmdir($fixtureParent);
    }
}
