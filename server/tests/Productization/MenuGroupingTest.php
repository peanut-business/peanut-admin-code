<?php

declare(strict_types=1);

use app\common\infrastructure\authorization\CoreTenantModuleAdminBridge;
use app\common\services\upgrade\ApplicationMigrationRunner;
use app\platform\composition\plugin\PluginModuleRegistryFactory;
use PeanutAdmin\Kernel\Menu\MenuRegistry;
use PeanutAdmin\Modules\Identity\Authorization\ModuleAuthorizationCatalogSynchronizer;
use PeanutAdmin\Modules\Identity\Authorization\Persistence\ThinkPhpAuthorizationCatalogRepository;
use PeanutAdmin\Modules\Identity\Menu\ThinkPhpMenuCatalogRepository;

require dirname(__DIR__, 2) . '/vendor/autoload.php';
require_once dirname(__DIR__, 2) . '/vendor/topthink/framework/src/helper.php';
require_once dirname(__DIR__, 2) . '/database/install.php';
require_once dirname(__DIR__) . '/Support/RegisteredMysqlTestResource.php';

$root = dirname(__DIR__, 3);
new \think\App($root . '/.local/tmp/menu-grouping/think-app');
$server = $root . '/server';
$registry = (new PluginModuleRegistryFactory($server))->fromDeploymentConfig([
    'roots' => array_map(static fn(string $path): string => dirname($path), glob($server . '/app/modules/official/*/module.json')),
    'kernel_version' => '1.0.0', 'registered_client_keys' => ['admin-web', 'platform-web'],
])->compiled();
$assertions = 0;
function menuExpect(bool $condition, string $message): void
{
    global $assertions;
    ++$assertions;
    if (!$condition) {
        throw new RuntimeException($message);
    }
}
function menuRows(PDO $pdo, string $table): array
{
    return $pdo->query('SELECT * FROM ' . $table . ' ORDER BY id')->fetchAll();
}
function menuStable(PDO $pdo): array
{
    $result = [];
    foreach (['pa_system_menu', 'pa_menu_definition', 'pa_role_permission', 'pa_platform_role_permission'] as $table) {
        $result[$table] = array_map(static function (array $row): array {
            unset($row['updated_at'], $row['created_at']);
            return $row;
        }, menuRows($pdo, $table));
    }
    return $result;
}
function menuVisible(ThinkPhpMenuCatalogRepository $repository, array $permissions, array $modules, string $scope = 'tenant'): array
{
    return (new MenuRegistry($repository->activeDefinitions($scope)))->visible(
        $scope === 'tenant' ? 'admin-web' : 'platform-web',
        static fn(string $key): bool => in_array($key, $modules, true),
        static fn(string $key): bool => in_array($key, $modules, true),
        static fn(string $key): bool => in_array($key, $permissions, true),
    );
}

$configured = RegisteredMysqlTestResource::configuredDatabaseName();
$prefix = substr($configured, 0, -strlen('standalone_fresh'));
$scenarios = ['standalone_fresh', 'multi_tenant_fresh', 'plugin_lifecycle'];
$results = [];
foreach ($scenarios as $scenario) {
    $database = $prefix . $scenario;
    putenv('DB_NAME=' . $database);
    RegisteredMysqlTestResource::preflightEmptyDatabase();
    [$pdo, $created] = RegisteredMysqlTestResource::openEmptyDatabase($database);
    try {
        \ThinkPhpTestConnection::fromPdo($pdo);
        initializeCoreIdentity(
            $pdo,
            'menu-owner@example.test',
            'synthetic-menu-password-1004',
            $scenario === 'standalone_fresh' ? null : ['email' => 'menu-platform@example.test', 'password' => 'synthetic-platform-password-1004'],
            new \PeanutAdmin\Modules\Identity\Policy\DemoAccountPolicy(false, []),
            [
                'kind' => 'real-default-tenant', 'code' => 'default', 'tenant_identity' => 'required', 'rbac' => 'required',
                'execution_context' => \PeanutAdmin\Kernel\Context\TenantSystemContext::class, 'module_lifecycle' => 'required',
            ],
        );
        executeSqlFiles($pdo, [$server . '/database/init.sql']);
        $migration = new ApplicationMigrationRunner([
            'host' => getenv('DB_HOST'), 'port' => getenv('DB_PORT'), 'database' => $database,
            'user' => getenv('DB_USER'), 'password' => getenv('DB_PASS'),
        ]);
        $files = glob($server . '/database/migrations/*.sql');
        $grouping = $server . '/database/migrations/20261004-group-default-navigation.sql';
        $oldFiles = array_values(array_diff($files, [$grouping]));
        $migration->run($oldFiles, '4.0.0-rc.2', '4.0.0-rc.2');
        (new ModuleAuthorizationCatalogSynchronizer(new ThinkPhpAuthorizationCatalogRepository()))->synchronize($registry);
        $legacyBefore = menuRows($pdo, 'pa_system_menu');
        $bindingsBefore = [menuRows($pdo, 'pa_role_permission'), menuRows($pdo, 'pa_platform_role_permission')];
        $oldIds = [];
        if ($scenario === 'plugin_lifecycle') {
            // Reconstruct the committed pre-change catalog, including an old schema.
            foreach (['pa_system_menu', 'pa_menu_definition'] as $table) {
                $pdo->exec('ALTER TABLE ' . $table . ' DROP COLUMN upstream_defaults_json, DROP COLUMN menu_conflict_json');
            }
            $old = json_decode(file_get_contents(dirname(__DIR__) . '/fixtures/menu-grouping/catalog-before.json'), true, 512, JSON_THROW_ON_ERROR);
            foreach ($old['definitions'] as $definition) {
                $permission = $pdo->prepare('SELECT id FROM pa_permission WHERE `key`=?');
                $permission->execute([$definition['required_permission'] ?? '']);
                $permissionId = $permission->fetchColumn();
                $row = $definition;
                unset($row['required_permission'], $row['client_keys']);
                $row['parent_key'] ??= null;
                $row['required_permission_id'] = $permissionId === false ? null : (int) $permissionId;
                $row['client_keys_json'] = json_encode($definition['client_keys'], JSON_THROW_ON_ERROR);
                $row += ['status' => 'active', 'manifest_digest' => str_repeat('a', 64), 'created_at' => gmdate('Y-m-d H:i:s'), 'updated_at' => gmdate('Y-m-d H:i:s')];
                $pdo->prepare('INSERT INTO pa_menu_definition (`' . implode('`,`', array_keys($row)) . '`) VALUES (' . implode(',', array_fill(0, count($row), '?')) . ')')->execute(array_values($row));
                $oldIds[$definition['key']] = (int) $pdo->lastInsertId();
            }
            $pdo->exec("UPDATE pa_system_menu SET name='我的文件',sort=997,icon='my-icon',is_show=0,is_cache=1 WHERE id=84");
            $pdo->exec("UPDATE pa_system_menu SET name='我的业务目录' WHERE id=20");
            $pdo->exec("INSERT INTO pa_system_menu (pid,type,name,paths,sort) VALUES (0,'M','我的目录','/my-category',999)");
            $customGroupId = (int) $pdo->lastInsertId();
            $pdo->exec("INSERT INTO pa_system_menu (pid,type,name,paths,sort) VALUES ({$customGroupId},'C','我的页面','/my-page',998)");
            $customPageId = (int) $pdo->lastInsertId();
            $pdo->exec("UPDATE pa_menu_definition SET name='我的文章',parent_key='core.organization',sort_order=997,icon='my-icon' WHERE `key`='official.article.articles'");
            $customBefore = [$pdo->query('SELECT * FROM pa_system_menu WHERE id=84')->fetch(), $pdo->query('SELECT * FROM pa_menu_definition WHERE `key`=\'official.article.articles\'')->fetch()];
        }
        $firstMigration = $migration->run([$grouping], '4.0.0-rc.2', '4.0.0-rc.2');
        menuExpect($firstMigration['applied'] === ['20261004-group-default-navigation'], 'normal upgrade runner did not apply menu migration');
        $applier = \ThinkPhpTestConnection::moduleCatalogs($pdo);
        $first = $applier->apply($registry);
        $repository = new ThinkPhpMenuCatalogRepository();
        $allPermissions = $pdo->query("SELECT `key` FROM pa_permission WHERE status='active'")->fetchAll(PDO::FETCH_COLUMN);
        $modules = array_map(static fn($manifest): string => $manifest->data['key'], $registry->modules);
        $definitions = $repository->activeDefinitions('tenant');
        $pages = array_values(array_filter($definitions, static fn($definition): bool => $definition->moduleKey !== 'core' && $definition->type === 'page'));
        menuExpect(count($pages) === 19, 'an existing official page was lost');
        foreach ($pages as $page) {
            menuExpect($page->parentKey !== null, 'official page remained at root: ' . $page->key);
            $original = array_values(array_filter($registry->menus, static fn(array $row): bool => $row['key'] === $page->key))[0];
            menuExpect($page->routeName === $original['route_name'] && $page->routePath === $original['route_path'] && $page->requiredPermission === $original['required_permission'], 'route or permission changed: ' . $page->key);
        }
        foreach ($oldIds as $key => $id) {
            $query = $pdo->prepare('SELECT id FROM pa_menu_definition WHERE `key`=?');
            $query->execute([$key]);
            menuExpect((int) $query->fetchColumn() === $id, 'stable catalog identity changed: ' . $key);
        }
        menuExpect([menuRows($pdo, 'pa_role_permission'), menuRows($pdo, 'pa_platform_role_permission')] === $bindingsBefore, 'existing role bindings changed');
        foreach ($legacyBefore as $row) {
            $query = $pdo->prepare('SELECT perms,paths,component FROM pa_system_menu WHERE id=?');
            $query->execute([$row['id']]);
            menuExpect($query->fetch() === array_intersect_key($row, array_flip(['perms', 'paths', 'component'])), 'legacy route/permission identity changed');
        }
        $visible = menuVisible($repository, $allPermissions, $modules);
        menuExpect(count(array_filter($visible, static fn($page): bool => $page->parentKey === null)) === 6, 'tenant category count changed');
        menuExpect(menuVisible($repository, [], $modules) === [], 'empty permission set exposed a page or empty category');
        $articleOnly = menuVisible($repository, ['official.article.article.list'], $modules);
        menuExpect(count(array_filter($articleOnly, static fn($page): bool => $page->type === 'page')) <= 1, 'a category granted additional leaf permissions');
        $withoutArticle = menuVisible($repository, $allPermissions, array_values(array_diff($modules, ['official.article'])));
        menuExpect(count(array_filter($withoutArticle, static fn($page): bool => $page->moduleKey === 'official.article')) === 0, 'disabled module remained visible');
        menuExpect(menuVisible($repository, $allPermissions, [], 'platform') === [], 'platform menus escaped deployment/client scope');
        $bridge = (new ReflectionClass(CoreTenantModuleAdminBridge::class))->newInstanceWithoutConstructor();
        $projection = new ReflectionMethod($bridge, 'serverMenuRecords');
        $records = $projection->invoke($bridge, $visible, $allPermissions);
        foreach ($records as $record) {
            if ($record['type'] === 'C') {
                menuExpect($record['id'] === CoreTenantModuleAdminBridge::virtualMenuId($record['menu_key']), 'virtual page identity changed');
            }
        }
        if ($scenario === 'plugin_lifecycle') {
            $legacy = $pdo->query('SELECT * FROM pa_system_menu WHERE id=84')->fetch();
            foreach ($customBefore[0] as $field => $value) {
                menuExpect($legacy[$field] === $value, 'legacy custom field overwritten: ' . $field);
            }
            $custom = $pdo->query("SELECT * FROM pa_menu_definition WHERE `key`='official.article.articles'")->fetch();
            foreach (['id', 'name', 'parent_key', 'sort_order', 'icon', 'route_path', 'required_permission_id'] as $field) {
                menuExpect($custom[$field] === $customBefore[1][$field], 'catalog custom field overwritten: ' . $field);
            }
            menuExpect($custom['menu_conflict_json'] !== null && $legacy['menu_conflict_json'] !== null, 'custom conflict was not reported');
            menuExpect((int) $pdo->query('SELECT COUNT(*) FROM pa_system_menu WHERE id IN (' . $customGroupId . ',' . $customPageId . ')')->fetchColumn() === 2, 'user-created menus were removed');
            $beforeParent = array_values(array_filter($legacyBefore, static fn(array $row): bool => (int) $row['id'] === 39))[0]['pid'];
            menuExpect((int) $pdo->query('SELECT pid FROM pa_system_menu WHERE id=39')->fetchColumn() === (int) $beforeParent, 'custom directory did not preserve children');
            $pdo->exec("UPDATE pa_system_menu SET is_disable=1 WHERE paths='/operations'");
            $denied = $projection->invoke($bridge, $visible, $allPermissions);
            menuExpect(count(array_filter($denied, static fn(array $row): bool => in_array($row['menu_key'], ['official.notification.log', 'official.task.crontab'], true))) === 0, 'disabled category leaked new or existing module pages');
            $pdo->exec("UPDATE pa_system_menu SET is_disable=0 WHERE paths='/operations'");
            $applier->retire(['official.task']);
            menuExpect(count(array_filter($repository->activeDefinitions('tenant'), static fn($page): bool => $page->moduleKey === 'official.task')) === 0, 'retire left active menus');
            $applier->apply($registry, ['official.task']);
            menuExpect(count(array_filter($repository->activeDefinitions('tenant'), static fn($page): bool => $page->moduleKey === 'official.task')) === 1, 'module reinstall did not reactivate menu');
        }
        $stable = menuStable($pdo);
        menuExpect($migration->run([$grouping], '4.0.0-rc.2', '4.0.0-rc.2')['status'] === 'up_to_date', 'repeat upgrade was not ledger-idempotent');
        $pdo->exec(file_get_contents($grouping));
        $applier->apply($registry);
        menuExpect(menuStable($pdo) === $stable, 'repeat SQL/synchronization changed menus or grants');
        $results[] = ['scenario' => $scenario, 'official_pages' => count($pages), 'tenant_roots' => 6, 'catalog_revision' => $first['catalog_revision']];
    } finally {
        RegisteredMysqlTestResource::cleanup($pdo, $database, $created);
    }
}
echo json_encode(['status' => 'passed', 'assertions' => $assertions, 'scenarios' => $results], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES), PHP_EOL;
