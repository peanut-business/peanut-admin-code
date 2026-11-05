<?php

declare(strict_types=1);

use app\adminapi\services\auth\MenuApplicationService;
use app\common\exception\BusinessException;
use app\common\infrastructure\authorization\CoreTenantModuleAdminBridge;
use app\platform\composition\module\ModuleModuleRegistryFactory;
use PeanutAdmin\Kernel\Menu\MenuRegistry;
use PeanutAdmin\Modules\Identity\Menu\MenuAdministrationService;
use PeanutAdmin\Modules\Identity\Menu\ThinkPhpMenuCatalogRepository;
use PeanutAdmin\Modules\Identity\Module\TenantEntitlementService;

require_once dirname(__DIR__, 2) . '/vendor/autoload.php';
require_once dirname(__DIR__) . '/Support/CiBootstrap.php';
require_once dirname(__DIR__) . '/Support/RegisteredMysqlTestResource.php';
require_once dirname(__DIR__) . '/Support/ThinkPhpTestConnection.php';
require_once dirname(__DIR__) . '/Support/IdentityMysqlFixture.php';

$assertions = 0;
function menuExpect(bool $condition, string $message): void
{
    global $assertions;
    if (!$condition) {
        throw new RuntimeException($message);
    }
    ++$assertions;
}
function menuRows(PDO $pdo, string $table): array
{
    return $pdo->query('SELECT * FROM ' . $table . ' ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
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
function menuVisible(ThinkPhpMenuCatalogRepository $repository, array $permissions, array $modules, string $scope = 'tenant', ?array $tenantModules = null): array
{
    $tenantModules ??= $modules;
    return (new MenuRegistry($repository->activeDefinitions($scope)))->visible(
        $scope === 'tenant' ? 'admin-web' : 'platform-web',
        static fn(string $key): bool => in_array($key, $modules, true),
        static fn(string $key): bool => in_array($key, $tenantModules, true),
        static fn(string $key): bool => in_array($key, $permissions, true),
    );
}

$server = dirname(__DIR__, 2);
$database = RegisteredMysqlTestResource::configuredDatabaseName();
$results = [];
foreach (['multi_tenant_fresh', 'module_lifecycle'] as $scenario) {
    [$pdo, $created] = RegisteredMysqlTestResource::openEmptyDatabase($database);
    try {
        $app = think\Container::getInstance();
        $app->env->set(['DEPLOYMENT_MODE' => 'multi-tenant', 'APP_ENV' => 'development', 'APP_DEBUG' => true]);
        $app->initialize();
        $app->cache->clear();
        ThinkPhpTestConnection::fromPdo($pdo);
        $registry = (new ModuleModuleRegistryFactory($server))->fromDeploymentConfig([
            'roots' => array_map('dirname', glob($server . '/app/modules/peanut/*/module.json')),
            'kernel_version' => '1.0.0', 'registered_client_keys' => ['admin-web', 'platform-web'],
        ])->compiled();
        $directories = [$server . '/database/installation', $server . '/database/migrations'];
        $manifests = [];
        foreach ($registry->modules as $manifest) {
            $key = $manifest->data['key'];
            $manifests[$key] = $manifest;
            if ($key !== 'peanut.identity' && isset($manifest->data['backend']['migrations'])) {
                $directories[] = $manifest->root . '/' . $manifest->data['backend']['migrations'];
            }
        }
        IdentityMysqlFixture::install($pdo, $directories);
        $applier = ThinkPhpTestConnection::moduleCatalogs($pdo);
        $first = $applier->apply($registry);
        $repository = new ThinkPhpMenuCatalogRepository(new TenantEntitlementService($registry));
        $permissions = $pdo->query("SELECT `key` FROM pa_permission WHERE status='active'")->fetchAll(PDO::FETCH_COLUMN);
        $modules = array_keys($manifests);
        $expected = array_column(array_filter($registry->menus, static fn(array $row): bool => $row['scope'] === 'tenant' && $row['type'] === 'page'), null, 'key');
        $pages = array_values(array_filter($repository->activeDefinitions('tenant'), static fn($row): bool => $row->moduleKey !== 'core' && $row->type === 'page'));
        menuExpect($expected !== [] && count($pages) === count($expected), 'current Module page was lost');
        foreach ($pages as $page) {
            $source = $expected[$page->key];
            menuExpect($page->parentKey !== null && $page->routeName === $source['route_name']
                && $page->routePath === $source['route_path'] && $page->requiredPermission === $source['required_permission'], 'route, parent or permission changed: ' . $page->key);
        }
        $ids = $pdo->query('SELECT `key`,id FROM pa_menu_definition')->fetchAll(PDO::FETCH_KEY_PAIR);
        $bindings = [menuRows($pdo, 'pa_role_permission'), menuRows($pdo, 'pa_platform_role_permission')];
        $visible = menuVisible($repository, $permissions, $modules);
        menuExpect(count(array_filter($visible, static fn($row): bool => $row->parentKey === null)) === 6, 'current tenant root projection changed');
        menuExpect(menuVisible($repository, [], $modules) === [], 'empty permission set exposed menus');
        menuExpect(count(array_filter(menuVisible($repository, ['peanut.file.list'], $modules), static fn($row): bool => $row->type === 'page')) === 1, 'category lost permitted File page or granted extra leaves');
        menuExpect(count(array_filter(menuVisible($repository, $permissions, array_values(array_diff($modules, ['peanut.file']))), static fn($row): bool => $row->moduleKey === 'peanut.file')) === 0, 'disabled Module remained visible');
        menuExpect(menuVisible($repository, $permissions, [], 'platform') === [], 'platform menus escaped deployment/client scope');
        $bridge = (new ReflectionClass(CoreTenantModuleAdminBridge::class))->newInstanceWithoutConstructor();
        (new ReflectionProperty($bridge, 'menuAdministration'))->setValue($bridge, new MenuAdministrationService());
        $projection = new ReflectionMethod($bridge, 'serverMenuRecords');
        foreach ($projection->invoke($bridge, $visible, $permissions) as $record) {
            if ($record['type'] === 'C') {
                menuExpect($record['id'] === (int) $ids[$record['menu_key']], 'projection changed real catalog ID');
            }
        }
        $management = new MenuApplicationService(
            (new ReflectionClass(app\common\services\authorization\AdminAuthorizationService::class))->newInstanceWithoutConstructor(),
            (new ReflectionClass(app\common\services\authorization\MenuPermissionUsageQuery::class))->newInstanceWithoutConstructor(),
            new MenuAdministrationService(),
        );
        if ($scenario === 'module_lifecycle') {
            $pdo->exec("UPDATE pa_menu_definition SET name='我的文件',parent_key='core.organization',sort_order=997 WHERE `key`='peanut.file.library'");
            $pdo->exec("INSERT INTO pa_menu_definition(`key`,module_key,scope,parent_key,type,name,sort_order,client_keys_json,status,manifest_digest,created_at,updated_at) VALUES('customer.category','core','tenant','core.organization','group','我的目录',999,JSON_ARRAY('admin-web'),'active',REPEAT('b',64),UTC_TIMESTAMP(3),UTC_TIMESTAMP(3))");
            $management->add(['type' => 'M', 'name' => '自建目录', 'parent_key' => 'core.content']);
            $group = array_values(array_filter($management->records(), static fn(array $row): bool => $row['name'] === '自建目录'))[0];
            $management->add(['type' => 'C', 'name' => '自建页面', 'parent_key' => $group['menu_key'], 'paths' => '/my-page']);
            $customBefore = menuRows($pdo, 'pa_system_menu');
            $applier->apply($registry);
            $customized = $pdo->query("SELECT name,parent_key,menu_conflict_json FROM pa_menu_definition WHERE `key`='peanut.file.library'")->fetch(PDO::FETCH_ASSOC);
            menuExpect($customized['name'] === '我的文件' && $customized['parent_key'] === 'core.organization' && $customized['menu_conflict_json'] !== null, 'custom presentation or conflict lost');
            menuExpect(menuRows($pdo, 'pa_system_menu') === $customBefore, 'user-created hierarchy changed');
            menuExpect($pdo->query("SELECT status FROM pa_menu_definition WHERE `key`='customer.category'")->fetchColumn() === 'active', 'unknown user menu retired');
            $applier->beginPackageDeployment($manifests, false, gmdate('Y-m-d H:i:s'));
            $applier->activatePackageDeployment($manifests, false, gmdate('Y-m-d H:i:s'));
            $applier->disableActivePackageModules(['peanut.task']);
            menuExpect(!in_array('peanut.task', $repository->activeDeploymentModules(), true), 'deployment disable did not affect menu availability');
            menuExpect(count(array_filter(menuVisible($repository, $permissions, $repository->activeDeploymentModules()), static fn($row): bool => $row->moduleKey === 'peanut.task')) === 0, 'deployment-disabled page visible');
            $applier->activatePackageDeployment(['peanut.task' => $manifests['peanut.task']], false, gmdate('Y-m-d H:i:s'));
            $pdo->exec("INSERT INTO pa_tenant(id,code,name,display_name,status,activated_at,created_at,updated_at) VALUES(101,'menu-fixture','Menu fixture','Menu fixture','active',UTC_TIMESTAMP(3),UTC_TIMESTAMP(3),UTC_TIMESTAMP(3))");
            $pdo->exec("INSERT INTO pa_plan(id,code,name,status,created_at,updated_at) VALUES(1,'menu-plan','Menu plan','active',UTC_TIMESTAMP(3),UTC_TIMESTAMP(3))");
            $pdo->exec("INSERT INTO pa_plan_module(plan_id,module_key) VALUES(1,'peanut.task')");
            $pdo->exec("INSERT INTO pa_tenant_license(tenant_id,plan_id,status,effective_at,created_at,updated_at) VALUES(101,1,'active','2020-01-01 00:00:00.000',UTC_TIMESTAMP(3),UTC_TIMESTAMP(3))");
            menuExpect(in_array('peanut.task', $repository->activeTenantModules(101), true), 'licensed Module unavailable');
            $pdo->exec("UPDATE pa_tenant_license SET status='suspended',revision=revision+1 WHERE tenant_id=101");
            menuExpect(count(array_filter(menuVisible($repository, $permissions, $repository->activeDeploymentModules(), 'tenant', $repository->activeTenantModules(101)), static fn($row): bool => $row->moduleKey === 'peanut.task')) === 0, 'license-disabled page visible after deployment reactivation');
            $pdo->exec("UPDATE pa_tenant_license SET status='active',revision=revision+1 WHERE tenant_id=101");
            $applier->retire(['peanut.task']);
            menuExpect(count(array_filter($repository->activeDefinitions('tenant'), static fn($row): bool => $row->moduleKey === 'peanut.task')) === 0, 'retire left active menus');
            $applier->apply($registry, ['peanut.task']);
            menuExpect(count(array_filter($repository->activeDefinitions('tenant'), static fn($row): bool => $row->moduleKey === 'peanut.task')) === 2, 'reinstall did not reactivate both Task menus');
            $applier->retire(['peanut.file']);
            $applier->apply($registry, ['peanut.file']);
            menuExpect($pdo->query("SELECT status,name,parent_key FROM pa_menu_definition WHERE `key`='peanut.file.library'")->fetch(PDO::FETCH_ASSOC) === ['status' => 'active', 'name' => '我的文件', 'parent_key' => 'core.organization'], 'reinstall lost customized menu');
            $applier->apply($registry);
        }
        foreach (['peanut.reference-codes.dictionary', 'peanut.file.library'] as $key) {
            $record = $management->detail($key);
            try {
                $management->edit([...$record, 'perms' => 'malicious.grant']);
                throw new RuntimeException('unregistered permission accepted');
            } catch (BusinessException $error) {
                menuExpect($error->errorCode === 'MENU_PERMISSION_INVALID', 'unregistered permission denial changed');
            }
            $management->edit([...$record, 'name' => '自定义展示名', 'sort' => 888, 'is_show' => 0, 'is_cache' => 1]);
            $applier->apply($registry);
            $edited = $management->detail($key);
            menuExpect($edited['name'] === '自定义展示名' && $edited['sort'] === 888 && $edited['is_show'] === 0 && $edited['is_cache'] === 1, 'presentation edit lost on sync');
            menuExpect($edited['perms'] === $record['perms'] && $edited['paths'] === $record['paths'] && $edited['component'] === $record['component'], 'management changed immutable identity');
            $management->updateStatus($key, 1);
            $applier->apply($registry);
            menuExpect($management->detail($key)['is_disable'] === 1, 'disable lost on sync');
            menuExpect(count(array_filter($projection->invoke($bridge, menuVisible($repository, $permissions, $modules), $permissions), static fn(array $row): bool => $row['menu_key'] === $key)) === 0, 'disabled menu projected');
            $management->updateStatus($key, 0);
            try {
                $management->delete($key);
                throw new RuntimeException('managed menu deletion accepted');
            } catch (BusinessException) {
                menuExpect(true, 'managed deletion rejected');
            }
        }
        $management->add(['type' => 'M', 'name' => '编号预留验证', 'parent_key' => 'core.content']);
        $custom = array_values(array_filter($management->records(), static fn(array $row): bool => $row['name'] === '编号预留验证'))[0];
        menuExpect($custom['id'] >= MenuApplicationService::CUSTOM_ID_START && str_starts_with($custom['menu_key'], 'custom.') && $custom['parent_key'] === 'core.content' && $custom['module_key'] === 'application', 'reserved custom menu identity changed');
        try {
            $management->edit([...$custom, 'parent_key' => $custom['menu_key']]);
            throw new RuntimeException('parent cycle accepted');
        } catch (BusinessException) {
            menuExpect(true, 'parent cycle rejected');
        }
        $management->delete($custom['menu_key']);
        $applier->apply($registry);
        foreach ($ids as $key => $id) {
            $statement = $pdo->prepare('SELECT id FROM pa_menu_definition WHERE `key`=?');
            $statement->execute([$key]);
            menuExpect((int) $statement->fetchColumn() === (int) $id, 'stable catalog ID changed: ' . $key);
        }
        menuExpect([menuRows($pdo, 'pa_role_permission'), menuRows($pdo, 'pa_platform_role_permission')] === $bindings, 'role bindings changed');
        $stable = menuStable($pdo);
        $applier->apply($registry);
        menuExpect(menuStable($pdo) === $stable, 'replay changed presentation, IDs or grants');
        $results[] = ['scenario' => $scenario, 'module_pages' => count($pages), 'catalog_revision' => $first['catalog_revision']];
    } finally {
        RegisteredMysqlTestResource::cleanup($pdo, $database, $created);
    }
}
echo json_encode(['status' => 'passed', 'assertions' => $assertions, 'scenarios' => $results], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES), PHP_EOL;
