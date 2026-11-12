<?php

declare(strict_types=1);

namespace PeanutAdmin\Kernel\Tests\Unit\Authorization;

use PeanutAdmin\Modules\Identity\Authorization\CorePermissionCatalog;
use PeanutAdmin\Modules\Identity\Authorization\CorePermissionCatalogSynchronizer;
use PeanutAdmin\Kernel\Authorization\Persistence\AuthorizationCatalogRepository;
use PeanutAdmin\Kernel\Authorization\Persistence\PermissionDefinition;
use app\common\policy\permission\RegisteredAdminPermissionPolicy;
use PHPUnit\Framework\TestCase;

final class CorePermissionCatalogTest extends TestCase
{
    public function testPlatformModuleRuntimePermissionsAreRegisteredExactly(): void
    {
        self::assertSame([
            'platform.module.read',
            'platform.module.create',
            'platform.module.install',
            'platform.module.update',
            'platform.module.uninstall',
            'platform.module.disable',
            'platform.module.sync',
        ], array_values(array_filter(
            CorePermissionCatalog::PLATFORM,
            static fn(string $permission): bool => str_starts_with($permission, 'platform.module.'),
        )));
    }

    public function testMaintenanceRoutesKeepExactBundlesAndSeparateReadFromMutation(): void
    {
        $root = (getenv('PEANUT_TEST_APPLICATION_ROOT') ?: dirname(__DIR__, 7));
        require_once $root . '/server/route/registry_source.php';
        $expected = [
            'GET adminapi/generator/source-tables' => 'core.generator.read',
            'GET adminapi/generator/lists' => 'core.generator.read',
            'GET adminapi/generator/detail' => 'core.generator.read',
            'GET adminapi/generator/models' => 'core.generator.read',
            'POST adminapi/generator/preview' => 'core.generator.read',
            'POST adminapi/generator/import' => 'core.generator.manage',
            'POST adminapi/generator/sync' => 'core.generator.manage',
            'POST adminapi/generator/update' => 'core.generator.manage',
            'POST adminapi/generator/delete' => 'core.generator.manage',
            'POST adminapi/generator/generate' => 'core.generator.generate',
            'GET adminapi/generator/download' => 'core.generator.generate',
            'GET adminapi/menu/lists' => 'core.menu.read',
            'GET adminapi/menu/all' => 'core.menu.read',
            'GET adminapi/menu/detail' => 'core.menu.read',
            'POST adminapi/menu/add' => 'core.menu.manage',
            'POST adminapi/menu/edit' => 'core.menu.manage',
            'POST adminapi/menu/delete' => 'core.menu.manage',
            'POST adminapi/menu/status' => 'core.menu.manage',
            'GET adminapi/system/info' => 'core.system.read',
            'POST adminapi/system/clearcache' => 'core.system.cache.clear',
            'POST adminapi/log/clear' => 'core.audit.clear',
        ];
        $routes = [];
        foreach (peanut_route_endpoint_inventory($root . '/server')['endpoints'] as $endpoint) {
            $routes[$endpoint['method'] . ' ' . ltrim(strtolower($endpoint['path']), '/')] = $endpoint;
        }
        $access = require $root . '/server/config/admin_api_access.php';
        $exceptions = array_map(static fn(string $route): string => strtolower(str_replace(' /', ' ', $route)), [...$access['public'], ...$access['authenticated']]);
        $catalog = json_decode(file_get_contents($root . '/server/generated/api-catalog.json'), true, 512, JSON_THROW_ON_ERROR);
        $apis = [];
        foreach ($catalog['endpoints'] as $endpoint) {
            $apis[$endpoint['method'] . ' ' . ltrim(strtolower($endpoint['path']), '/')] = $endpoint;
        }
        $policy = new RegisteredAdminPermissionPolicy();
        $reads = ['core.generator.read', 'core.menu.read', 'core.system.read', 'core.audit.read', 'core.permission.read'];
        foreach ($expected as $route => $permission) {
            self::assertSame($permission, $routes[$route]['permission'], $route);
            self::assertNotContains(strtolower($route), $exceptions, $route);
            self::assertSame('tenant_permission', $apis[$route]['auth']['mode'], $route);
            self::assertSame([$permission], $apis[$route]['default_permission_keys'], $route);
            self::assertTrue($policy->canAccess(false, $permission, CorePermissionCatalog::TENANT, [$permission]), $route);
            self::assertFalse($policy->canAccess(false, $permission, CorePermissionCatalog::TENANT, []), $route);
            self::assertFalse($policy->canAccess(true, $permission, [], [$permission]), $route);
            self::assertFalse($policy->canAccess(false, $permission, CorePermissionCatalog::TENANT, [substr($route, strpos($route, '/') + 1)]), $route);
            if (!in_array($permission, $reads, true)) {
                self::assertFalse($policy->canAccess(false, $permission, CorePermissionCatalog::TENANT, $reads), $route);
            }
        }
        self::assertFalse($policy->canAccess(false, 'core.generator.manage', CorePermissionCatalog::TENANT, ['core.generator.generate']));
        self::assertFalse($policy->canAccess(false, 'core.generator.generate', CorePermissionCatalog::TENANT, ['core.generator.manage']));
        self::assertCount(21, $expected);
    }

    public function testNativeCatalogSynchronizerUsesVersionedDefinitionsWithoutRoleGrants(): void
    {
        $catalog = $this->createMock(AuthorizationCatalogRepository::class);
        $keys = [];
        $catalog->expects(self::exactly(count(CorePermissionCatalog::TENANT) + count(CorePermissionCatalog::PLATFORM)))
            ->method('syncPermission')->willReturnCallback(static function (PermissionDefinition $definition) use (&$keys): int {
                self::assertSame('0.2.0', $definition->manifestVersion);
                $keys[] = $definition->key;
                return count($keys);
            });
        $catalog->expects(self::exactly(6))->method('syncDataCondition')->willReturn(1);
        (new CorePermissionCatalogSynchronizer($catalog))->synchronize();
        self::assertSame([...CorePermissionCatalog::TENANT, ...CorePermissionCatalog::PLATFORM], $keys);
    }
}
