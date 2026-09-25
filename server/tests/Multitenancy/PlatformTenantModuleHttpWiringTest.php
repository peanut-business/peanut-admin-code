<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/route/registry_source.php';

use app\platform\service\module\OpisManifestSchemaValidator;
use app\platform\service\module\ReflectionContractInspector;
use app\platform\service\module\StrictVersionConstraintMatcher;
use Opis\JsonSchema\Validator;
use PeanutAdmin\Modules\Identity\Authorization\Persistence\Schema\AuthorizationSchema;
use PeanutAdmin\Kernel\Migration\ModuleSchema;
use PeanutAdmin\Kernel\Module\ManifestLoader;
use PeanutAdmin\Kernel\Module\ModuleHostLayout;
use PeanutAdmin\Kernel\Module\ModuleProvider;
use PeanutAdmin\Kernel\Module\ModuleRegistryCompiler;
use PeanutAdmin\Kernel\Persistence\Schema\KernelSchema;

require dirname(__DIR__, 2) . '/vendor/autoload.php';
require __DIR__ . '/../fixtures/PlatformTenantModule/Content/ModuleProvider.php';

function pm01ModuleHttpExpect(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$matcher = new StrictVersionConstraintMatcher();
pm01ModuleHttpExpect($matcher->matches('1.0.0', '^1.0'), 'caret version match failed');
pm01ModuleHttpExpect(!$matcher->matches('2.0.0', '^1.0'), 'caret upper bound failed');
pm01ModuleHttpExpect(!$matcher->matches('1.0.0', '>=1.0'), 'unsupported constraint was accepted');

$kernelRoot = dirname((new ReflectionClass(ModuleProvider::class))->getFileName(), 3);
$fixtureRoot = realpath(__DIR__ . '/../fixtures/PlatformTenantModule/Content');
pm01ModuleHttpExpect(is_string($fixtureRoot), 'Module fixture root is unavailable');
$document = (new ManifestLoader())->load($fixtureRoot);
$registry = (new ModuleRegistryCompiler(
    new OpisManifestSchemaValidator($kernelRoot . '/resources/schemas/module-manifest.schema.json'),
    $matcher,
    new ReflectionContractInspector(),
    '1.0.0',
    ['fixture.content.page'],
    new ModuleHostLayout('server/tests/Fixtures', 'Fixture', 'web/src/modules'),
    [...KernelSchema::tableNames(), ...AuthorizationSchema::tableNames(), ...ModuleSchema::tableNames()],
    ['admin-web', 'platform-web'],
    [...\PeanutAdmin\Modules\Identity\Authorization\CorePermissionCatalog::TENANT, ...\PeanutAdmin\Modules\Identity\Authorization\CorePermissionCatalog::PLATFORM],
))->compile([$document]);
pm01ModuleHttpExpect($registry->moduleKeys() === ['fixture.content'], 'deployed Module did not compile');

$configSource = (string) file_get_contents(dirname(__DIR__, 2) . '/config/modules.php');
pm01ModuleHttpExpect(
    str_contains($configSource, "env('PEANUT_MODULE_ROOTS', '')")
        && str_contains($configSource, "'roots' => \$roots"),
    'default Module roots must be an explicit empty deployment input',
);
$composition = (string) file_get_contents(dirname(__DIR__, 2) . '/app/AppService.php');
$registryFactory = (string) file_get_contents(
    dirname(__DIR__, 2) . '/app/platform/service/plugin/ModuleDefinitionRegistryFactory.php',
);
$routes = peanut_route_registry_source(dirname(__DIR__, 2));
$adminBridge = (string) file_get_contents(
    dirname(__DIR__, 2) . '/app/common/service/authorization/CoreTenantModuleAdminBridge.php',
);
$serverMenuMapper = (string) file_get_contents(
    dirname(__DIR__, 3) . '/web/src/store/modules/app/server-menu.ts',
);
pm01ModuleHttpExpect(
    str_contains($composition, "'MODULE_REGISTRY_UNAVAILABLE'")
        && !str_contains($composition, 'PlatformRuntimeFactory')
        && str_contains($composition, 'ThinkPhpModuleGovernanceProvider')
        && str_contains($registryFactory, 'ModuleBoundaryChecker')
        && str_contains($composition, 'VerifiedTenantModuleRepository'),
    'production Module runtime lost fail-closed deployment verification',
);
pm01ModuleHttpExpect(
    str_contains($adminBridge, 'ThinkPhpMenuCatalogRepository')
        && str_contains($adminBridge, 'ThinkPhpTenantAuthorizationRepository')
        && str_contains($adminBridge, 'MenuRegistry')
        && str_contains($adminBridge, "'module_key' => \$definition->moduleKey")
        && str_contains($adminBridge, "'required_permission' => \$definition->requiredPermission")
        && str_contains($serverMenuMapper, 'tenantModuleKey:')
        && str_contains($serverMenuMapper, 'requiredPermissions:')
        && str_contains($serverMenuMapper, 'path: menu.module_key')
        && str_contains($serverMenuMapper, '!menu.module_key &&'),
    'Core TenantModule menu/member Permission compatibility bridge is missing',
);
pm01ModuleHttpExpect(
    str_contains($routes, "Route::post('tenants/modules/enable'")
        && str_contains($routes, "Route::post('tenants/modules/disable'")
        && substr_count($routes, "PlatformPermissionMiddleware::class, 'platform.tenant.module.manage'") >= 2,
    'TenantModule routes lost their dedicated platform permission',
);

echo "PM01-PLATFORM-TENANT-MODULE-HTTP-WIRING-001 passed\n";
