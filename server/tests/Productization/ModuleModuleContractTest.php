<?php

declare(strict_types=1);

use app\platform\validation\module\OpisManifestSchemaValidator;
use PeanutAdmin\Kernel\Idempotency\IdempotencySchema;
use PeanutAdmin\Kernel\Migration\ModuleSchema;
use PeanutAdmin\Kernel\Module\CompiledModuleRegistry;
use PeanutAdmin\Kernel\Module\ManifestDocument;
use PeanutAdmin\Kernel\Module\ManifestLoader;
use PeanutAdmin\Kernel\Module\ModuleBoundaryChecker;
use PeanutAdmin\Kernel\Module\ModuleException;
use PeanutAdmin\Kernel\Module\ModuleRegistryCompiler;
use app\common\composition\ModuleComposition;
use app\common\infrastructure\module\ModuleHostLayoutFactory;
use think\App;

require dirname(__DIR__, 2) . '/vendor/autoload.php';
require dirname(__DIR__, 2) . '/fixtures/modules/delivery_record/src/Contract/DeliveryRecordCommands.php';
require dirname(__DIR__, 2) . '/fixtures/modules/delivery_record/src/Service/DeliveryRecordAccess.php';
require dirname(__DIR__, 2) . '/fixtures/modules/delivery_record/src/Infrastructure/Authorization/ThinkPhpDeliveryRecordAccess.php';
require dirname(__DIR__, 2) . '/fixtures/modules/delivery_record/src/Service/DeliveryRecordService.php';
require dirname(__DIR__, 2) . '/fixtures/modules/delivery_record/src/ModuleProvider.php';

interface ModuleModuleAutowireContract {}

final class ModuleModuleAutowireDependency {}

class ModuleModuleControlledLeaf {}

final class ModuleModuleControlledLeafOverride extends ModuleModuleControlledLeaf {}

class ModuleModuleCycleNode {}

class_alias(ModuleModuleCycleNode::class, 'ModuleModuleCycleAlias');

final class ModuleModuleAutowireService implements ModuleModuleAutowireContract
{
    public function __construct(public readonly ModuleModuleAutowireDependency $dependency) {}
}

final class ModuleModuleAutowireProvider implements \PeanutAdmin\Kernel\Module\ModuleProvider
{
    public function moduleKey(): string
    {
        return 'fixture.autowire';
    }

    public function bindings(): array
    {
        return [
            ModuleModuleAutowireContract::class => ModuleModuleAutowireService::class,
            ModuleModuleControlledLeaf::class => static fn(): ModuleModuleControlledLeaf => new ModuleModuleControlledLeaf(),
        ];
    }
}

final class ModuleModuleCycleProvider implements \PeanutAdmin\Kernel\Module\ModuleProvider
{
    public function moduleKey(): string
    {
        return 'fixture.cycle';
    }

    public function bindings(): array
    {
        return [ModuleModuleControlledLeaf::class => ModuleModuleControlledLeaf::class];
    }
}

final class ModuleModuleMultiCycleProvider implements \PeanutAdmin\Kernel\Module\ModuleProvider
{
    public function moduleKey(): string
    {
        return 'fixture.multi-cycle';
    }

    public function bindings(): array
    {
        return [
            ModuleModuleCycleNode::class => 'ModuleModuleCycleAlias',
            'ModuleModuleCycleAlias' => ModuleModuleCycleNode::class,
        ];
    }
}

function pluginModuleContractExpect(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

/** @param class-string<\PeanutAdmin\Kernel\Module\ModuleProvider> $provider */
function pluginModuleContractRegistry(string $key, string $provider): CompiledModuleRegistry
{
    return new CompiledModuleRegistry([
        ManifestDocument::fromObject('/fixture/' . $key, (object) [
            'key' => $key,
            'backend' => (object) ['provider' => $provider],
        ]),
    ], [], [], [], 'test');
}

function pluginModuleContractControlledObject(string $class): object
{
    if ($class === PDO::class) {
        return new class extends PDO {
            public function __construct() {}
        };
    }

    return (new ReflectionClass($class))->newInstanceWithoutConstructor();
}

/** @param class-string $concrete */
function pluginModuleContractMakeOfficial(App $app, string $abstract, string $concrete): object
{
    $controlledImplementations = [
        \app\common\contract\idempotency\IdempotentCommandExecutor::class => \app\common\infrastructure\idempotency\ThinkPhpIdempotentCommandExecutor::class,
        \PeanutAdmin\Modules\Task\Contract\TaskJobRuntime::class => \PeanutAdmin\Modules\Task\Infrastructure\Runtime\ThinkPhpTaskJobRuntime::class,
        \app\common\contract\http\OutboundHttpTransport::class => \app\common\infrastructure\http\GuzzleOutboundHttpTransport::class,
        \PeanutAdmin\Modules\Integration\Contract\ExternalTenantAudit::class => \PeanutAdmin\Modules\Integration\Infrastructure\ThinkPhpExternalTenantAudit::class,
        \app\common\contract\payment\PaymentTransportInterface::class => \app\common\infrastructure\payment\CurlPaymentTransport::class,
    ];
    $reflection = new ReflectionClass($concrete);
    $constructor = $reflection->getConstructor();
    foreach ($constructor?->getParameters() ?? [] as $parameter) {
        $type = $parameter->getType();
        if (!$type instanceof ReflectionNamedType || $type->isBuiltin()) {
            continue;
        }
        $dependency = $type->getName();
        $implementation = $controlledImplementations[$dependency] ?? $app->getAlias($dependency);
        if (!class_exists($implementation)) {
            throw new RuntimeException("controlled leaf has no concrete implementation: {$dependency}");
        }
        $app->instance($dependency, pluginModuleContractControlledObject($implementation));
    }

    return $app->make($abstract);
}

$serverRoot = dirname(__DIR__, 2);
$moduleRoot = $serverRoot . '/fixtures/modules/delivery_record';
$officialModuleRoots = glob($serverRoot . '/app/modules/peanut/*', GLOB_ONLYDIR) ?: [];
sort($officialModuleRoots, SORT_STRING);
$moduleRoots = [$moduleRoot, ...$officialModuleRoots];
$moduleRootsByKey = [];
foreach ($moduleRoots as $root) {
    $document = json_decode((string) file_get_contents($root . '/module.json'), true, 32, JSON_THROW_ON_ERROR);
    $moduleRootsByKey[(string) $document['key']] = $root;
}
$layout = ModuleHostLayoutFactory::registerRuntimeAutoload($moduleRootsByKey, $serverRoot);
$frontendComponents = [];
foreach ($moduleRoots as $root) {
    $document = json_decode((string) file_get_contents($root . '/module.json'), true, 32, JSON_THROW_ON_ERROR);
    $menus = $document['backend']['menus'] ?? null;
    if (!is_string($menus) || !is_file($root . '/' . $menus)) {
        continue;
    }
    foreach (json_decode((string) file_get_contents($root . '/' . $menus), true, 32, JSON_THROW_ON_ERROR) as $menu) {
        if (($menu['type'] ?? null) === 'page' && is_string($menu['component_key'] ?? null)) {
            $frontendComponents[] = $menu['component_key'];
        }
    }
}
$frontendComponents = array_values(array_unique($frontendComponents));
sort($frontendComponents, SORT_STRING);
$historicalTableOwners = [];
foreach (['peanut.identity', 'peanut.ops'] as $protectedKey) {
    $protected = json_decode(
        (string) file_get_contents($moduleRootsByKey[$protectedKey] . '/module.json'),
        true,
        32,
        JSON_THROW_ON_ERROR,
    );
    foreach ($protected['database']['owned_tables'] as $table) {
        $historicalTableOwners[$table] = $protectedKey;
    }
}
$kernelRoot = dirname((new ReflectionClass(\PeanutAdmin\Kernel\Module\ModuleProvider::class))->getFileName(), 3);
$compiler = new ModuleRegistryCompiler(
    new OpisManifestSchemaValidator($kernelRoot . '/resources/schemas/module-manifest.schema.json'),
    '1.0.0',
    $frontendComponents,
    $layout,
    [
        ...array_diff(ModuleSchema::tableNames(), ['pa_module_migration']),
        ...IdempotencySchema::tableNames(),
        ...array_keys($historicalTableOwners),
    ],
    ['admin-web', 'platform-web', 'pc-web', 'uniapp'],
    [...\PeanutAdmin\Modules\Identity\Authorization\CorePermissionCatalog::TENANT, ...\PeanutAdmin\Modules\Identity\Authorization\CorePermissionCatalog::PLATFORM],
    $historicalTableOwners,
);

$loader = new ManifestLoader();
$manifests = array_map(static fn(string $root) => $loader->load($root), $moduleRoots);
$manifest = $manifests[0];
$registry = $compiler->compile($manifests);
pluginModuleContractExpect(
    ($historicalTableOwners['pa_data_permission_group'] ?? null) === 'peanut.identity',
    'current protected Identity manifest lost data-permission table ownership',
);
foreach (['pa_data_permission_group', 'pa_file', 'pa_tenant_idempotency_record'] as $claimedTable) {
    $invalidOwner = json_decode(json_encode($manifest->object, JSON_THROW_ON_ERROR), false, 512, JSON_THROW_ON_ERROR);
    $invalidOwner->database->owned_tables[] = $claimedTable;
    try {
        $compiler->compile([
            ManifestDocument::fromObject($manifest->root, $invalidOwner),
            ...array_slice($manifests, 1),
        ]);
        throw new RuntimeException("foreign or technical table ownership was accepted: {$claimedTable}");
    } catch (ModuleException $exception) {
        pluginModuleContractExpect(
            $exception->errorCode === 'MODULE_REGISTRY_CONFLICT',
            "table ownership rejection changed for {$claimedTable}: {$exception->errorCode}",
        );
    }
}
$expectedKeys = array_map(
    static fn(ManifestDocument $manifest): string => (string) $manifest->data['key'],
    $manifests,
);
$actualKeys = $registry->moduleKeys();
sort($expectedKeys, SORT_STRING);
sort($actualKeys, SORT_STRING);
pluginModuleContractExpect(
    $actualKeys === $expectedKeys,
    'source official Modules did not compile together',
);
(new ModuleBoundaryChecker($registry, $layout, ['pa_']))->check();

$fixtureProvider = new \PeanutAdmin\Fixtures\DeliveryRecord\ModuleProvider();
pluginModuleContractExpect(
    !in_array('app\\common\\composition\\Module' . 'BindingContributor', class_implements($fixtureProvider) ?: [], true),
    'Fixture ModuleProvider still depends on the retired binding marker',
);

$officialAutowireTargets = [
    \PeanutAdmin\Modules\File\Contract\FileAdministration::class => \PeanutAdmin\Modules\File\Service\FileAdministrationService::class,
    \PeanutAdmin\Modules\File\Contract\FileUploads::class => \PeanutAdmin\Modules\File\Service\FileUploadService::class,
    \PeanutAdmin\Modules\Integration\Contract\ExternalTenantBindingRepository::class => \PeanutAdmin\Modules\Integration\Infrastructure\ThinkPhpExternalTenantBindingRepository::class,
    \PeanutAdmin\Modules\Integration\Contract\ExternalTenantResolutionService::class => \PeanutAdmin\Modules\Integration\Service\ExternalTenantResolver::class,
    \PeanutAdmin\Modules\Integration\Contract\ExternalChannelBindings::class => \PeanutAdmin\Modules\Integration\Service\ExternalChannelBindingService::class,
];
foreach ($officialAutowireTargets as $abstract => $concrete) {
    $officialApp = new App($serverRoot);
    $officialApp->bind(require $serverRoot . '/app/adminapi/provider.php');
    (new ModuleComposition($officialApp))->register($registry);
    $resolved = pluginModuleContractMakeOfficial($officialApp, $abstract, $concrete);
    pluginModuleContractExpect(
        $resolved instanceof $concrete && $resolved === $officialApp->make($concrete),
        "native ThinkPHP alias did not make actual official service: {$abstract}",
    );
}

$autowireApp = new App($serverRoot);
(new ModuleComposition($autowireApp))->register(
    pluginModuleContractRegistry('fixture.autowire', ModuleModuleAutowireProvider::class),
);
$autowired = $autowireApp->make(ModuleModuleAutowireContract::class);
pluginModuleContractExpect(
    $autowired instanceof ModuleModuleAutowireService
        && $autowired->dependency instanceof ModuleModuleAutowireDependency,
    'native ThinkPHP alias did not autowire the concrete Module service',
);
pluginModuleContractExpect(
    $autowired === $autowireApp->make(ModuleModuleAutowireService::class),
    'native ThinkPHP alias did not preserve the concrete service identity',
);
pluginModuleContractExpect(
    $autowireApp->make(ModuleModuleControlledLeaf::class) instanceof ModuleModuleControlledLeaf,
    'controlled Module Closure leaf did not resolve through ThinkPHP',
);

$cycleApp = new App($serverRoot);
try {
    (new ModuleComposition($cycleApp))->register(
        pluginModuleContractRegistry('fixture.cycle', ModuleModuleCycleProvider::class),
    );
    throw new RuntimeException('self-referential Module binding was accepted');
} catch (ModuleException $exception) {
    pluginModuleContractExpect(
        $exception->errorCode === 'MODULE_BINDING_CONFLICT'
            && !$cycleApp->bound(ModuleModuleControlledLeaf::class),
        'self-referential Module binding was not rejected atomically before binding',
    );
}

$multiCycleApp = new App($serverRoot);
try {
    (new ModuleComposition($multiCycleApp))->register(
        pluginModuleContractRegistry('fixture.multi-cycle', ModuleModuleMultiCycleProvider::class),
    );
    throw new RuntimeException('multi-hop Module binding cycle was accepted');
} catch (ModuleException $exception) {
    pluginModuleContractExpect(
        $exception->errorCode === 'MODULE_BINDING_CONFLICT'
            && !$multiCycleApp->bound(ModuleModuleCycleNode::class)
            && !$multiCycleApp->bound('ModuleModuleCycleAlias'),
        'multi-hop Module binding cycle was not rejected atomically before binding',
    );
}

$hostBindingApp = new App($serverRoot);
$hostBindingApp->bind(ModuleModuleControlledLeaf::class, ModuleModuleControlledLeafOverride::class);
try {
    (new ModuleComposition($hostBindingApp))->register(
        pluginModuleContractRegistry('fixture.autowire', ModuleModuleAutowireProvider::class),
    );
    throw new RuntimeException('Host Module binding conflict was accepted');
} catch (ModuleException $exception) {
    pluginModuleContractExpect(
        $exception->errorCode === 'MODULE_BINDING_CONFLICT'
            && !$hostBindingApp->bound(ModuleModuleAutowireContract::class),
        'Host Module binding conflict was not rejected atomically before earlier bindings',
    );
}

$missingDependency = json_decode(json_encode($manifests[0]->object, JSON_THROW_ON_ERROR), false, 512, JSON_THROW_ON_ERROR);
$missingDependency->dependencies = [(object) ['module_key' => 'fixture.missing', 'version' => '^1.0']];
try {
    $compiler->compile([ManifestDocument::fromObject($manifest->root, $missingDependency)]);
    throw new RuntimeException('missing Module dependency was accepted');
} catch (ModuleException $exception) {
    pluginModuleContractExpect(
        $exception->errorCode === 'MODULE_DEPENDENCY_MISSING',
        "missing dependency rejection changed: {$exception->errorCode}",
    );
}

echo "PLUGIN-MODULE-CONTRACT-001 passed\n";
