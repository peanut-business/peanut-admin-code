<?php
declare(strict_types=1);

use app\platform\validation\module\OpisManifestSchemaValidator;
use app\platform\validation\module\ReflectionContractInspector;
use app\platform\validation\module\StrictVersionConstraintMatcher;
use PeanutAdmin\Modules\Identity\DataPermission\Persistence\Schema\DataPermissionSchema;
use PeanutAdmin\Modules\Identity\Authorization\Persistence\Schema\AuthorizationSchema;
use PeanutAdmin\Kernel\Idempotency\IdempotencySchema;
use PeanutAdmin\Kernel\Migration\ModuleSchema;
use PeanutAdmin\Kernel\Module\CompiledModuleRegistry;
use PeanutAdmin\Kernel\Module\ManifestDocument;
use PeanutAdmin\Kernel\Module\ManifestLoader;
use PeanutAdmin\Kernel\Module\ModuleBoundaryChecker;
use PeanutAdmin\Kernel\Module\ModuleException;
use PeanutAdmin\Kernel\Module\ModuleRegistryCompiler;
use PeanutAdmin\Kernel\Persistence\Schema\KernelSchema;
use app\common\composition\ModuleComposition;
use app\common\infrastructure\module\ModuleHostLayoutFactory;
use app\common\execution\CurrentExecutionContext;
use app\common\execution\ExecutionContextStore;
use PeanutAdmin\Modules\Article\Service\ArticleQueryService;
use PeanutAdmin\Modules\Article\Contract\ArticleQueries;
use think\App;

require dirname(__DIR__, 2) . '/vendor/autoload.php';
require dirname(__DIR__, 2) . '/app/modules/fixture/delivery_record/src/Contract/DeliveryRecordCommands.php';
require dirname(__DIR__, 2) . '/app/modules/fixture/delivery_record/src/Service/DeliveryRecordAccess.php';
require dirname(__DIR__, 2) . '/app/modules/fixture/delivery_record/src/Infrastructure/Authorization/ThinkPhpDeliveryRecordAccess.php';
require dirname(__DIR__, 2) . '/app/modules/fixture/delivery_record/src/Service/DeliveryRecordService.php';
require dirname(__DIR__, 2) . '/app/modules/fixture/delivery_record/src/ModuleProvider.php';

interface PluginModuleAutowireContract {}

final class PluginModuleAutowireDependency {}

class PluginModuleControlledLeaf {}

final class PluginModuleControlledLeafOverride extends PluginModuleControlledLeaf {}

class PluginModuleCycleNode {}

class_alias(PluginModuleCycleNode::class, 'PluginModuleCycleAlias');

final class PluginModuleAutowireService implements PluginModuleAutowireContract
{
    public function __construct(public readonly PluginModuleAutowireDependency $dependency) {}
}

final class PluginModuleAutowireProvider implements \PeanutAdmin\Kernel\Module\ModuleProvider
{
    public function moduleKey(): string { return 'fixture.autowire'; }

    public function bindings(): array
    {
        return [
            PluginModuleAutowireContract::class => PluginModuleAutowireService::class,
            PluginModuleControlledLeaf::class => static fn(): PluginModuleControlledLeaf => new PluginModuleControlledLeaf(),
        ];
    }
}

final class PluginModuleCycleProvider implements \PeanutAdmin\Kernel\Module\ModuleProvider
{
    public function moduleKey(): string { return 'fixture.cycle'; }

    public function bindings(): array
    {
        return [PluginModuleControlledLeaf::class => PluginModuleControlledLeaf::class];
    }
}

final class PluginModuleMultiCycleProvider implements \PeanutAdmin\Kernel\Module\ModuleProvider
{
    public function moduleKey(): string { return 'fixture.multi-cycle'; }

    public function bindings(): array
    {
        return [
            PluginModuleCycleNode::class => 'PluginModuleCycleAlias',
            'PluginModuleCycleAlias' => PluginModuleCycleNode::class,
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
        ManifestDocument::fromArray('/fixture/' . $key, [
            'key' => $key,
            'backend' => ['provider' => $provider],
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
        \app\common\contract\idempotency\IdempotentCommandExecutor::class => \app\common\service\idempotency\ThinkPhpIdempotentCommandExecutor::class,
        \PeanutAdmin\Modules\Task\Contract\TaskJobRuntime::class => \PeanutAdmin\Modules\Task\Infrastructure\Runtime\ThinkPhpTaskJobRuntime::class,
        \app\common\service\http\OutboundHttpTransport::class => \app\common\service\http\GuzzleOutboundHttpTransport::class,
        \PeanutAdmin\Modules\Integration\Contract\ExternalTenantAudit::class => \PeanutAdmin\Modules\Integration\Infrastructure\ThinkPhpExternalTenantAudit::class,
        \app\common\service\payment\contract\PaymentTransportInterface::class => \app\common\service\payment\transport\CurlPaymentTransport::class,
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
$moduleRoot = $serverRoot . '/app/modules/fixture/delivery_record';
$officialModuleRoots = glob($serverRoot . '/app/modules/official/*', GLOB_ONLYDIR) ?: [];
sort($officialModuleRoots, SORT_STRING);
$moduleRoots = [$moduleRoot, ...$officialModuleRoots];
$moduleRootsByKey = [];
foreach ($moduleRoots as $root) {
    $document = json_decode((string)file_get_contents($root . '/module.json'), true, 32, JSON_THROW_ON_ERROR);
    $moduleRootsByKey[(string)$document['key']] = $root;
}
$layout = ModuleHostLayoutFactory::fromModuleRoots($moduleRootsByKey);
$frontendComponents = [];
foreach ($moduleRoots as $root) {
    $document = json_decode((string)file_get_contents($root . '/module.json'), true, 32, JSON_THROW_ON_ERROR);
    $menus = $document['backend']['menus'] ?? null;
    if (!is_string($menus) || !is_file($root . '/' . $menus)) {
        continue;
    }
    foreach (json_decode((string)file_get_contents($root . '/' . $menus), true, 32, JSON_THROW_ON_ERROR) as $menu) {
        if (($menu['type'] ?? null) === 'page' && is_string($menu['component_key'] ?? null)) {
            $frontendComponents[] = $menu['component_key'];
        }
    }
}
$frontendComponents = array_values(array_unique($frontendComponents));
sort($frontendComponents, SORT_STRING);
$historicalTableOwners = [];
foreach (['official.identity', 'official.ops'] as $protectedKey) {
    $protected = json_decode(
        (string)file_get_contents($moduleRootsByKey[$protectedKey] . '/module.json'),
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
    new StrictVersionConstraintMatcher(),
    new ReflectionContractInspector(),
    '1.0.0',
    $frontendComponents,
    $layout,
    [
        ...KernelSchema::tableNames(),
        ...AuthorizationSchema::tableNames(),
        ...ModuleSchema::tableNames(),
        ...IdempotencySchema::tableNames(),
        ...DataPermissionSchema::tableNames(),
    ],
    ['admin-web', 'platform-web'],
    [...\PeanutAdmin\Modules\Identity\Authorization\CorePermissionCatalog::TENANT, ...\PeanutAdmin\Modules\Identity\Authorization\CorePermissionCatalog::PLATFORM],
    $historicalTableOwners,
);

$loader = new ManifestLoader();
$manifests = array_map(static fn(string $root) => $loader->load($root), $moduleRoots);
$manifest = $manifests[0];
$registry = $compiler->compile($manifests);
$expectedKeys = array_map(
    static fn(ManifestDocument $manifest): string => (string)$manifest->data['key'],
    $manifests,
);
$actualKeys = $registry->moduleKeys();
sort($expectedKeys, SORT_STRING);
sort($actualKeys, SORT_STRING);
pluginModuleContractExpect(
    $actualKeys === $expectedKeys,
    'source official Modules did not compile together'
);
(new ModuleBoundaryChecker($registry, $layout, ['pa_']))->check();

$app = new App($serverRoot);
$store = new ExecutionContextStore();
$app->instance(CurrentExecutionContext::class, new CurrentExecutionContext($store));
(new ModuleComposition($app))->register($registry);
pluginModuleContractExpect(
    $app->make(ArticleQueries::class) === $app->make(ArticleQueryService::class),
    'native ThinkPHP alias did not preserve ArticleQueries object identity',
);
$fixtureProvider = new \PeanutAdmin\Fixtures\DeliveryRecord\ModuleProvider();
pluginModuleContractExpect(
    !in_array('app\\common\\composition\\Module' . 'BindingContributor', class_implements($fixtureProvider) ?: [], true),
    'Fixture ModuleProvider still depends on the retired binding marker',
);

$officialAutowireTargets = [
    \PeanutAdmin\Modules\Article\Contract\PublicArticleQueries::class => \PeanutAdmin\Modules\Article\Service\PublicArticleService::class,
    \PeanutAdmin\Modules\Article\Contract\ArticleAdministration::class => \PeanutAdmin\Modules\Article\Service\ArticleAdministrationService::class,
    \PeanutAdmin\Modules\Article\Contract\ArticleCategoryAdministration::class => \PeanutAdmin\Modules\Article\Service\ArticleCategoryAdministrationService::class,
    \PeanutAdmin\Modules\File\Contract\FileAdministration::class => \PeanutAdmin\Modules\File\Service\FileAdministrationService::class,
    \PeanutAdmin\Modules\File\Contract\FileUploads::class => \PeanutAdmin\Modules\File\Service\FileUploadService::class,
    \PeanutAdmin\Modules\Member\Contract\MemberQueries::class => \PeanutAdmin\Modules\Member\Service\MemberQueryService::class,
    \PeanutAdmin\Modules\Member\Contract\MemberSubjectLookup::class => \PeanutAdmin\Modules\Member\Infrastructure\Persistence\ThinkPhpMemberSubjectLookup::class,
    \PeanutAdmin\Modules\Member\Contract\MemberAdministration::class => \PeanutAdmin\Modules\Member\Service\MemberAdministrationService::class,
    \PeanutAdmin\Modules\ImportExport\Service\TenantConfigurationTransferService::class => \PeanutAdmin\Modules\ImportExport\Service\TenantConfigurationTransferService::class,
    \PeanutAdmin\Modules\ImportExport\Infrastructure\File\AppFileMediaGateway::class => \PeanutAdmin\Modules\ImportExport\Infrastructure\File\AppFileMediaGateway::class,
    \PeanutAdmin\Modules\ImportExport\Contract\ImportExportWorkerRuntime::class => \PeanutAdmin\Modules\ImportExport\Service\TaskImportExportRuntime::class,
    \PeanutAdmin\Modules\ImportExport\Service\OperationLogExportApplicationService::class => \PeanutAdmin\Modules\ImportExport\Service\OperationLogExportApplicationService::class,
    \app\common\services\notice\NoticeChannelService::class => \app\common\services\notice\NoticeChannelService::class,
    \PeanutAdmin\Modules\Notification\Contract\NotificationCommands::class => \PeanutAdmin\Modules\Notification\Service\NotificationApplicationService::class,
    \PeanutAdmin\Modules\Integration\Contract\ExternalTenantBindingRepository::class => \PeanutAdmin\Modules\Integration\Infrastructure\ThinkPhpExternalTenantBindingRepository::class,
    \PeanutAdmin\Modules\Integration\Contract\ExternalTenantResolutionService::class => \PeanutAdmin\Modules\Integration\Service\ExternalTenantResolver::class,
    \PeanutAdmin\Modules\Integration\Contract\ExternalChannelBindings::class => \PeanutAdmin\Modules\Integration\Service\ExternalChannelBindingService::class,
    \app\common\service\payment\PaymentServiceFactory::class => \app\common\service\payment\PaymentServiceFactory::class,
    \PeanutAdmin\Modules\Payment\Contract\PaymentChannelGrantCommands::class => \PeanutAdmin\Modules\Payment\Infrastructure\ThinkPhpPaymentChannelGrantCommands::class,
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

$importAliasApp = new App($serverRoot);
(new ModuleComposition($importAliasApp))->register($registry);
$importAliasApp->instance(
    \PeanutAdmin\Modules\ImportExport\Service\ImportExportApplicationService::class,
    pluginModuleContractControlledObject(\PeanutAdmin\Modules\ImportExport\Service\ImportExportApplicationService::class),
);
pluginModuleContractExpect(
    $importAliasApp->make(\PeanutAdmin\Modules\ImportExport\Contract\ImportExportCommands::class)
        === $importAliasApp->make(\PeanutAdmin\Modules\ImportExport\Contract\ImportExportQueries::class),
    'ImportExport Commands and Queries aliases did not share their controlled production implementation',
);

$configurationAliasApp = new App($serverRoot);
(new ModuleComposition($configurationAliasApp))->register($registry);
$configurationAliasApp->instance(
    \PeanutAdmin\Modules\ImportExport\Service\ConfigurationTransferApplicationService::class,
    pluginModuleContractControlledObject(\PeanutAdmin\Modules\ImportExport\Service\ConfigurationTransferApplicationService::class),
);
pluginModuleContractExpect(
    $configurationAliasApp->make(\PeanutAdmin\Modules\ImportExport\Contract\ConfigurationTransferCommands::class)
        === $configurationAliasApp->make(\PeanutAdmin\Modules\ImportExport\Contract\ConfigurationTransferQueries::class),
    'Configuration transfer Commands and Queries aliases did not share their controlled production implementation',
);

$notificationAliasApp = new App($serverRoot);
$notificationAliasApp->bind(require $serverRoot . '/app/adminapi/provider.php');
(new ModuleComposition($notificationAliasApp))->register($registry);
$notification = pluginModuleContractMakeOfficial(
    $notificationAliasApp,
    \PeanutAdmin\Modules\Notification\Contract\NotificationCommands::class,
    \PeanutAdmin\Modules\Notification\Service\NotificationApplicationService::class,
);
pluginModuleContractExpect(
    $notification === $notificationAliasApp->make(\PeanutAdmin\Modules\Notification\Contract\NotificationQueries::class),
    'Notification Commands and Queries aliases did not preserve production object identity',
);

$autowireApp = new App($serverRoot);
(new ModuleComposition($autowireApp))->register(
    pluginModuleContractRegistry('fixture.autowire', PluginModuleAutowireProvider::class),
);
$autowired = $autowireApp->make(PluginModuleAutowireContract::class);
pluginModuleContractExpect(
    $autowired instanceof PluginModuleAutowireService
        && $autowired->dependency instanceof PluginModuleAutowireDependency,
    'native ThinkPHP alias did not autowire the concrete Module service',
);
pluginModuleContractExpect(
    $autowired === $autowireApp->make(PluginModuleAutowireService::class),
    'native ThinkPHP alias did not preserve the concrete service identity',
);
pluginModuleContractExpect(
    $autowireApp->make(PluginModuleControlledLeaf::class) instanceof PluginModuleControlledLeaf,
    'controlled Module Closure leaf did not resolve through ThinkPHP',
);

$cycleApp = new App($serverRoot);
try {
    (new ModuleComposition($cycleApp))->register(
        pluginModuleContractRegistry('fixture.cycle', PluginModuleCycleProvider::class),
    );
    throw new RuntimeException('self-referential Module binding was accepted');
} catch (ModuleException $exception) {
    pluginModuleContractExpect(
        $exception->errorCode === 'MODULE_BINDING_CONFLICT'
            && !$cycleApp->bound(PluginModuleControlledLeaf::class),
        'self-referential Module binding was not rejected atomically before binding',
    );
}

$multiCycleApp = new App($serverRoot);
try {
    (new ModuleComposition($multiCycleApp))->register(
        pluginModuleContractRegistry('fixture.multi-cycle', PluginModuleMultiCycleProvider::class),
    );
    throw new RuntimeException('multi-hop Module binding cycle was accepted');
} catch (ModuleException $exception) {
    pluginModuleContractExpect(
        $exception->errorCode === 'MODULE_BINDING_CONFLICT'
            && !$multiCycleApp->bound(PluginModuleCycleNode::class)
            && !$multiCycleApp->bound('PluginModuleCycleAlias'),
        'multi-hop Module binding cycle was not rejected atomically before binding',
    );
}

$hostBindingApp = new App($serverRoot);
$hostBindingApp->bind(PluginModuleControlledLeaf::class, PluginModuleControlledLeafOverride::class);
try {
    (new ModuleComposition($hostBindingApp))->register(
        pluginModuleContractRegistry('fixture.autowire', PluginModuleAutowireProvider::class),
    );
    throw new RuntimeException('Host Module binding conflict was accepted');
} catch (ModuleException $exception) {
    pluginModuleContractExpect(
        $exception->errorCode === 'MODULE_BINDING_CONFLICT'
            && !$hostBindingApp->bound(PluginModuleAutowireContract::class),
        'Host Module binding conflict was not rejected atomically before earlier bindings',
    );
}

$missingDependency = $manifests[0]->data;
$missingDependency['dependencies'] = [['module_key' => 'fixture.missing', 'version' => '^1.0']];
try {
    $compiler->compile([ManifestDocument::fromArray($manifest->root, $missingDependency)]);
    throw new RuntimeException('missing Module dependency was accepted');
} catch (ModuleException $exception) {
    pluginModuleContractExpect(
        $exception->errorCode === 'MODULE_DEPENDENCY_MISSING',
        "missing dependency rejection changed: {$exception->errorCode}"
    );
}

echo "PLUGIN-MODULE-CONTRACT-001 passed\n";
