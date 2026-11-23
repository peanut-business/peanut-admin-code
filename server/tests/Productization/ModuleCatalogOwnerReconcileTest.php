<?php

declare(strict_types=1);

use app\common\module\ModuleMigrationRun;
use app\common\module\PackageCheck;
use app\platform\composition\module\ModuleDefinitionRegistryFactory;
use app\platform\exception\module\ModuleLifecycleException;
use app\platform\infrastructure\module\ModuleCatalogApplier;
use app\platform\infrastructure\module\ModuleLockResolver;
use app\platform\services\module\ModuleLifecycleService;
use PeanutAdmin\Kernel\Module\CompiledModuleRegistry;
use PeanutAdmin\Kernel\Module\ManifestDocument;
use PeanutAdmin\Kernel\Module\ManifestLoader;
use PeanutAdmin\Modules\Identity\Authorization\ApiPermissionCatalog;
use PeanutAdmin\Modules\Identity\Menu\MenuCatalogSynchronizer;
use PeanutAdmin\Modules\Identity\Contract\TenantModuleStateQueries;
use think\facade\Config;

$consumer = realpath($argv[1] ?? '');
if ($argc !== 2 || $consumer === false || !is_file($consumer . '/server/vendor/autoload.php')) {
    throw new RuntimeException('Provide the prepared Module consumer root.');
}
$server = $consumer . '/server';
$loader = require $server . '/vendor/autoload.php';
$loader->setPsr4('app\\', [dirname(__DIR__, 2) . '/app']);
require_once $server . '/vendor/topthink/framework/src/helper.php';
require_once dirname(__DIR__) . '/Support/ThinkPhpTestConnection.php';

function catalogOwnerExpect(bool $condition, string $label): void
{
    if (!$condition) {
        throw new RuntimeException($label);
    }
}

$app = new think\App($server);
Config::set(['migration_table' => 'migrations'], 'database');
$resolver = ModuleLockResolver::forRuntime($server, '../modules.lock');
$contentKey = 'peanut.content-tools';
$ownerKey = ModuleCatalogApplier::AUTHORIZATION_CATALOG_OWNER;
$content = $resolver->require($contentKey);
$owner = $resolver->require($ownerKey);
$loader = new ManifestLoader();
$manifests = [
    $contentKey => $loader->load($content->moduleRoots[$contentKey]),
    $ownerKey => $loader->load($owner->moduleRoots[$ownerKey]),
];
$registry = new CompiledModuleRegistry(array_values($manifests), [], [], [], 'catalog-owner-test');
$packages = array_map(static fn($manifest): array => [
    'version' => $manifest->data['version'],
    'dependencies' => $manifest->data['dependencies'] ?? [],
], $manifests);
$order = new PackageCheck($consumer);
catalogOwnerExpect($order->dependencyOrder($packages, []) === [$contentKey, $ownerKey], 'The original independent-root order must reproduce the incident.');
catalogOwnerExpect($order->dependencyOrder($packages, [], [$ownerKey]) === [$ownerKey, $contentKey], 'The selected catalog owner must run first.');
catalogOwnerExpect($order->dependencyOrder([$contentKey => $packages[$contentKey]], [], [$ownerKey]) === [$contentKey],
    'Root priority must not select a package outside the requested scope.');
$prerequisite = 'acme.identity-prerequisite';
$withDependency = $packages;
$withDependency[$prerequisite] = ['version' => '1.0.0', 'dependencies' => []];
$withDependency[$ownerKey]['dependencies'][] = ['module_key' => $prerequisite, 'version' => '^1.0.0'];
catalogOwnerExpect($order->dependencyOrder($withDependency, [], [$ownerKey]) === [$prerequisite, $ownerKey, $contentKey],
    'Root priority must preserve a real dependency of the owner.');

$pdo = new PDO('sqlite::memory:', options: [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$pdo->sqliteCreateFunction('DATABASE', static fn (): string => 'main', 0);
$pdo->sqliteCreateFunction('IS_USED_LOCK', static fn (string $lock): int => 1, 1);
$pdo->sqliteCreateFunction('CONNECTION_ID', static fn (): int => 1, 0);
ThinkPhpTestConnection::fromPdo($pdo);
$pdo->exec(<<<'SQL'
    ATTACH DATABASE ':memory:' AS information_schema;
    CREATE TABLE information_schema.COLUMNS (TABLE_SCHEMA TEXT, TABLE_NAME TEXT, COLUMN_NAME TEXT);
    CREATE TABLE information_schema.TABLES (TABLE_SCHEMA TEXT, TABLE_NAME TEXT, TABLE_TYPE TEXT);
    CREATE TABLE pa_module_installation (module_key TEXT PRIMARY KEY, installed_version TEXT, status TEXT, activated_at TEXT,
        artifact_sha256 TEXT, composer_identity_json TEXT, npm_identity_json TEXT, frontend_identity_json TEXT);
    CREATE TABLE pa_migrations (version TEXT PRIMARY KEY, migration_name TEXT);
    CREATE TABLE pa_tenant (authorization_revision INTEGER, revision INTEGER, updated_at TEXT);
    CREATE TABLE pa_api_endpoint (id TEXT PRIMARY KEY, method TEXT, path TEXT, audience TEXT, module_key TEXT, access_mode TEXT,
        status TEXT, default_permission_keys TEXT, prerequisite_permission_keys TEXT, created_at TEXT, updated_at TEXT, retired_at TEXT);
    CREATE TABLE pa_system_menu (id INTEGER PRIMARY KEY, menu_key TEXT);
    CREATE TABLE pa_menu_definition (id INTEGER PRIMARY KEY, key TEXT, module_key TEXT, scope TEXT, parent_key TEXT, type TEXT,
        name TEXT, route_name TEXT, route_path TEXT, component_key TEXT, icon TEXT, sort_order INTEGER, required_permission_id INTEGER,
        client_keys_json TEXT, status TEXT, manifest_digest TEXT, created_at TEXT, updated_at TEXT, upstream_defaults_json TEXT,
        menu_conflict_json TEXT, is_show INTEGER, is_cache INTEGER, is_disable INTEGER);
    CREATE TABLE pa_permission_api_config (permission_id INTEGER PRIMARY KEY, revision INTEGER, configured INTEGER, updated_at TEXT);
    CREATE TABLE pa_permission (id INTEGER PRIMARY KEY, key TEXT, module_key TEXT, type TEXT, name TEXT, risk_level TEXT, status TEXT,
        manifest_version TEXT, created_at TEXT, updated_at TEXT, retired_at TEXT);
    CREATE TABLE pa_permission_api (id INTEGER PRIMARY KEY, permission_id INTEGER, api_id TEXT, created_at TEXT);
    CREATE TABLE pa_protected_resource (id INTEGER PRIMARY KEY, key TEXT, module_key TEXT, name TEXT, ownership TEXT, provider_key TEXT,
        status TEXT, manifest_version TEXT, manifest_digest TEXT, created_at TEXT, updated_at TEXT, retired_at TEXT);
    CREATE TABLE pa_target_type (id INTEGER PRIMARY KEY, key TEXT, module_key TEXT, name TEXT, resolver_key TEXT, catalog_provider_key TEXT,
        id_format TEXT, status TEXT, manifest_version TEXT, manifest_digest TEXT, created_at TEXT, updated_at TEXT);
    CREATE TABLE pa_data_condition_definition (id INTEGER PRIMARY KEY, key TEXT, module_key TEXT, category TEXT, target_mode TEXT,
        config_schema_json TEXT, status TEXT, manifest_version TEXT, manifest_digest TEXT, created_at TEXT, updated_at TEXT);
    CREATE TABLE pa_resource_operation (id INTEGER PRIMARY KEY, protected_resource_id INTEGER, operation TEXT, access_mode TEXT,
        target_cardinality TEXT, permission_match TEXT, audit_level TEXT, status TEXT, manifest_digest TEXT, created_at TEXT, updated_at TEXT);
    CREATE TABLE pa_resource_operation_permission (id INTEGER PRIMARY KEY, resource_operation_id INTEGER, permission_id INTEGER, sort_order INTEGER);
    CREATE TABLE pa_resource_operation_target_type (id INTEGER PRIMARY KEY, resource_operation_id INTEGER, target_type_id INTEGER, target_role TEXT,
        input_mode TEXT, policy_selection_permission_id INTEGER, status TEXT);
    CREATE TABLE pa_resource_operation_condition (id INTEGER PRIMARY KEY, resource_operation_id INTEGER, condition_definition_id INTEGER,
        selector_resource_key TEXT, selector_resource_key_norm TEXT, status TEXT);
    INSERT INTO pa_api_endpoint (id,method,path,prerequisite_permission_keys) VALUES ('legacy-api-1','GET','/legacy','[]');
    INSERT INTO pa_system_menu VALUES (1, 'legacy-menu');
    INSERT INTO pa_menu_definition (id,key,module_key,status,manifest_digest) VALUES (1,'legacy-menu','peanut.identity','active','legacy-digest');
    INSERT INTO pa_permission (id) VALUES (1);
    INSERT INTO pa_permission_api (id,permission_id,api_id) VALUES (1,1,'legacy-api-1');
    INSERT INTO pa_module_installation (module_key,installed_version,status,activated_at) VALUES
        ('peanut.content-tools', '1.0.0', 'active', 'old'),
        ('peanut.identity', '4.0.0-rc.64', 'active', 'old');
    SQL);
$observeSchema = static function () use ($pdo): void {
    $pdo->exec(<<<'SQL'
        DELETE FROM information_schema.COLUMNS;
        DELETE FROM information_schema.TABLES;
        INSERT INTO information_schema.COLUMNS
        SELECT 'main', m.name, p.name FROM sqlite_schema m JOIN pragma_table_info(m.name) p WHERE m.type = 'table';
        INSERT INTO information_schema.TABLES SELECT 'main', name, 'BASE TABLE' FROM sqlite_schema WHERE type = 'table';
        SQL);
};
$observeSchema();
$record = $pdo->prepare('INSERT INTO pa_migrations VALUES (?, ?)');
foreach (ModuleMigrationRun::sources([$contentKey => $manifests[$contentKey]], $server, true) as $source) {
    $record->execute([$source['migration_key'], $source['name']]);
}
$catalogs = (new ReflectionClass(ModuleCatalogApplier::class))->newInstanceWithoutConstructor();
$lifecycle = new ModuleLifecycleService($resolver, new ModuleDefinitionRegistryFactory($server), [],
    $catalogs, new TenantModuleStateQueries(), $registry);
$snapshot = static fn (): array => [
    $pdo->query('SELECT * FROM pa_module_installation ORDER BY module_key')->fetchAll(PDO::FETCH_ASSOC),
    $pdo->query('SELECT * FROM pa_migrations ORDER BY version')->fetchAll(PDO::FETCH_ASSOC),
    $pdo->query('SELECT * FROM pa_permission_api ORDER BY permission_id, api_id')->fetchAll(PDO::FETCH_ASSOC),
];
$before = $snapshot();

try {
    $lifecycle->reconcileInstalled([$contentKey]);
    throw new RuntimeException('Old schema allowed a selected package to enter dependency preparation.');
} catch (ModuleLifecycleException $error) {
    catalogOwnerExpect($error->errorCode === 'MODULE_CATALOG_OWNER_MIGRATION_REQUIRED', 'The exclusive package must fail with the catalog-owner blocker.');
}
$plan = $lifecycle->reconcilePlan($contentKey);
catalogOwnerExpect($plan['may_apply'] === false
    && in_array('MODULE_CATALOG_OWNER_MIGRATION_REQUIRED', array_column($plan['migration_scope']['blockers'], 'code'), true),
    'The same blocker must appear in the read-only plan.');
$ownerPlan = $lifecycle->reconcilePlan($ownerKey);
catalogOwnerExpect($ownerPlan['may_apply'] === true && $ownerPlan['action'] === 'planned_upgrade',
    'The Identity owner can plan its own native migration on legacy schema.');
$orderedPlan = $lifecycle->reconcilePlan($contentKey, catalogOwnerScheduled: $ownerPlan['may_apply']);
catalogOwnerExpect($orderedPlan['may_apply'] === true, 'An earlier, admissible owner in the read-only plan must satisfy its scheduling prerequisite.');
catalogOwnerExpect(!$catalogs->authorizationCatalogSchemaReady($manifests[$ownerKey]) && $before === $snapshot(),
    'Planning and rejection must leave installation, migration and binding rows unchanged.');
$legacyOwner = clone $manifests[$ownerKey]->object;
$legacyOwner->database = (object) ['owned_tables' => ['pa_api_endpoint', 'pa_menu_definition', 'pa_permission_api_config', 'pa_permission_api']];
$legacyManifest = ManifestDocument::fromObject($manifests[$ownerKey]->root, $legacyOwner);
catalogOwnerExpect($catalogs->authorizationCatalogSchemaReady($legacyManifest),
    'The same existing legacy catalog remains valid for the still-selected legacy owner.');
$pdo->exec('ALTER TABLE pa_permission_api_config DROP COLUMN revision');
$observeSchema();
catalogOwnerExpect(!$catalogs->authorizationCatalogSchemaReady($legacyManifest),
    'The split catalog must also contain the columns used by its write path.');
$pdo->exec('ALTER TABLE pa_permission_api_config ADD COLUMN revision INTEGER');
$observeSchema();

// The failed row in MT already carries the target identity and its earlier activation timestamp.
$failed = $pdo->prepare(<<<'SQL'
    UPDATE pa_module_installation SET status='failed', installed_version=?, artifact_sha256=?,
        composer_identity_json=?, npm_identity_json=?, frontend_identity_json=? WHERE module_key=?
    SQL);
$failed->execute([$content->version, $content->source['sha256'], json_encode($content->composer, JSON_THROW_ON_ERROR),
    json_encode($content->npm, JSON_THROW_ON_ERROR), json_encode($content->frontend, JSON_THROW_ON_ERROR), $contentKey]);
$failedBefore = $snapshot();
$failedPlan = $lifecycle->reconcilePlan($contentKey, preparedRelease: true);
catalogOwnerExpect($failedPlan['may_apply'] === false && $failedPlan['identity_changed'] === false
    && in_array('MODULE_CATALOG_OWNER_MIGRATION_REQUIRED', array_column($failedPlan['migration_scope']['blockers'], 'code'), true),
    'Prepared release must diagnose the catalog prerequisite for a previously activated failed target.');
$failedOrderedPlan = $lifecycle->reconcilePlan($contentKey, preparedRelease: true, catalogOwnerScheduled: true);
catalogOwnerExpect($failedOrderedPlan['may_apply'] === true && $failedOrderedPlan['operation'] === 'upgrade'
    && $failedOrderedPlan['execution_admitted'] === false && $failedBefore === $snapshot(),
    'Prepared failure recovery can be planned after its owner without writing any state.');
$pdo->exec("UPDATE pa_module_installation SET installed_version='1.0.0', status='active',
    artifact_sha256=NULL, composer_identity_json=NULL, npm_identity_json=NULL, frontend_identity_json=NULL
    WHERE module_key='peanut.content-tools'");

$pdo->exec(<<<'SQL'
    ALTER TABLE pa_api_endpoint RENAME TO pa_system_api;
    ALTER TABLE pa_system_menu RENAME TO pa_system_menu_legacy;
    ALTER TABLE pa_menu_definition RENAME TO pa_system_menu;
    ALTER TABLE pa_system_menu ADD COLUMN source TEXT;
    ALTER TABLE pa_permission ADD COLUMN revision INTEGER NOT NULL DEFAULT 1;
    ALTER TABLE pa_permission ADD COLUMN api_configured INTEGER NOT NULL DEFAULT 0;
    SQL);
$observeSchema();
$canonicalPlan = $lifecycle->reconcilePlan($contentKey);
catalogOwnerExpect($catalogs->authorizationCatalogSchemaReady($manifests[$ownerKey]) && $canonicalPlan['may_apply'] === true,
    'The canonical schema permits a partial upgrade that does not select the owner.');
$api = (new ApiPermissionCatalog())->revisionRows();
$menus = (new ReflectionClass(MenuCatalogSynchronizer::class))->newInstanceWithoutConstructor()->revisionRows();
catalogOwnerExpect($api['endpoints'][0]['id'] === 'legacy-api-1' && $api['bindings'][0]['api_id'] === 'legacy-api-1'
    && $menus[0]['manifest_digest'] === 'legacy-digest' && $before === $snapshot(),
    'Native directory fingerprint reads must preserve existing object identity and all recorded state.');

foreach (['system_api' => 'path', 'system_menu' => 'manifest_digest', 'permission' => 'retired_at', 'permission_api' => 'created_at'] as $table => $column) {
    $pdo->exec("ALTER TABLE pa_{$table} DROP COLUMN {$column}");
    $observeSchema();
    $beforeMissingColumn = $snapshot();
    $missingColumn = $lifecycle->reconcilePlan($contentKey);
    catalogOwnerExpect($missingColumn['may_apply'] === false
        && in_array('MODULE_CATALOG_OWNER_MIGRATION_REQUIRED', array_column($missingColumn['migration_scope']['blockers'], 'code'), true)
        && $beforeMissingColumn === $snapshot(), "Missing {$table}.{$column} must fail closed without state writes.");
    $pdo->exec("ALTER TABLE pa_{$table} ADD COLUMN {$column} TEXT");
    $observeSchema();
}

$pdo->exec('DELETE FROM pa_module_installation');
$fresh = $lifecycle->installPlan($contentKey);
catalogOwnerExpect($fresh['may_apply'] === true && $fresh['action'] === 'planned_install'
    && $pdo->query('SELECT COUNT(*) FROM pa_module_installation')->fetchColumn() === 0,
    'Fresh canonical schema permits the first independent activation without any owner installation row.');

echo 'MODULE-CATALOG-OWNER-RECONCILE passed: ordering, blocker, no-state-writes, scheduled-plan, canonical columns, prepared failure recovery, fresh owner state' . PHP_EOL;
