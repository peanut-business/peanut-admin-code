<?php
declare(strict_types=1);

use app\platform\service\module\OpisManifestSchemaValidator;
use app\platform\service\module\ReflectionContractInspector;
use app\platform\service\module\StrictVersionConstraintMatcher;
use PeanutAdmin\DataPermission\Persistence\Schema\DataPermissionSchema;
use PeanutAdmin\Kernel\Authorization\Persistence\Schema\AuthorizationSchema;
use PeanutAdmin\Kernel\Idempotency\IdempotencySchema;
use PeanutAdmin\Kernel\Migration\ModuleSchema;
use PeanutAdmin\Kernel\Module\CompiledModuleRegistry;
use PeanutAdmin\Kernel\Module\ManifestDocument;
use PeanutAdmin\Kernel\Module\ManifestLoader;
use PeanutAdmin\Kernel\Module\ModuleBoundaryChecker;
use PeanutAdmin\Kernel\Module\ModuleException;
use PeanutAdmin\Kernel\Module\ModuleHostLayout;
use PeanutAdmin\Kernel\Module\ModuleRegistryCompiler;
use PeanutAdmin\Kernel\Persistence\Schema\KernelSchema;
use app\common\composition\ModuleComposition;
use app\common\execution\CurrentExecutionContext;
use app\common\execution\ExecutionContextStore;
use app\Modules\Official\Article\Application\ArticleQueryService;
use app\Modules\Official\Article\Contracts\ArticleQueries;
use think\App;

require dirname(__DIR__, 2) . '/vendor/autoload.php';
require dirname(__DIR__, 2) . '/app/Modules/Fixture/DeliveryRecord/Contracts/DeliveryRecordCommands.php';
require dirname(__DIR__, 2) . '/app/Modules/Fixture/DeliveryRecord/Application/DeliveryRecordAccess.php';
require dirname(__DIR__, 2) . '/app/Modules/Fixture/DeliveryRecord/Infrastructure/Authorization/ThinkPhpDeliveryRecordAccess.php';
require dirname(__DIR__, 2) . '/app/Modules/Fixture/DeliveryRecord/Application/DeliveryRecordService.php';
require dirname(__DIR__, 2) . '/app/Modules/Fixture/DeliveryRecord/ModuleProvider.php';

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
        \app\Modules\Official\Task\Contracts\TaskJobRuntime::class => \app\Modules\Official\Task\Infrastructure\Runtime\ThinkPhpTaskJobRuntime::class,
        \app\common\service\http\OutboundHttpTransport::class => \app\common\service\http\GuzzleOutboundHttpTransport::class,
        \app\modules\official\integration\contracts\ExternalTenantAudit::class => \app\modules\official\integration\infrastructure\ThinkPhpExternalTenantAudit::class,
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
$moduleRoot = $serverRoot . '/app/Modules/Fixture/DeliveryRecord';
$officialModuleRoots = glob($serverRoot . '/app/Modules/Official/*', GLOB_ONLYDIR) ?: [];
sort($officialModuleRoots, SORT_STRING);
$moduleRoots = [$moduleRoot, ...$officialModuleRoots];
$layout = new ModuleHostLayout('server/app/Modules', 'app\Modules', 'web/src/modules');
$kernelRoot = dirname((new ReflectionClass(\PeanutAdmin\Kernel\Module\ModuleProvider::class))->getFileName(), 3);
$compiler = new ModuleRegistryCompiler(
    new OpisManifestSchemaValidator($kernelRoot . '/resources/schemas/module-manifest.schema.json'),
    new StrictVersionConstraintMatcher(),
    new ReflectionContractInspector(),
    '1.0.0',
    [
        'fixture.delivery-record.list',
        'official.article.cate', 'official.article.list',
        'official.file.library',
        'official.import-export.configuration',
        'official.notification.channel', 'official.notification.template', 'official.notification.log',
        'official.oauth.channel',
        'official.payment.settings', 'official.payment.recharge', 'official.payment.refund',
        'official.member.list', 'official.member.tag', 'official.member.account-log',
        'official.rich-text.documents',
        'official.task.schedules',
    ],
    $layout,
    [
        ...KernelSchema::tableNames(),
        ...AuthorizationSchema::tableNames(),
        ...ModuleSchema::tableNames(),
        ...IdempotencySchema::tableNames(),
        ...DataPermissionSchema::tableNames(),
    ],
    ['admin-web', 'platform-web'],
        [...\PeanutAdmin\Kernel\Authorization\CorePermissionCatalog::TENANT, ...\PeanutAdmin\Kernel\Authorization\CorePermissionCatalog::PLATFORM],
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
$fixtureProvider = new \app\Modules\Fixture\DeliveryRecord\ModuleProvider();
pluginModuleContractExpect(
    !in_array('app\\common\\composition\\Module' . 'BindingContributor', class_implements($fixtureProvider) ?: [], true),
    'Fixture ModuleProvider still depends on the retired binding marker',
);

$officialAutowireTargets = [
    \app\Modules\Official\Article\Contracts\PublicArticleQueries::class => \app\Modules\Official\Article\Application\PublicArticleService::class,
    \app\Modules\Official\Article\Contracts\ArticleAdministration::class => \app\Modules\Official\Article\Application\ArticleAdministrationService::class,
    \app\Modules\Official\File\Contracts\FileAdministration::class => \app\Modules\Official\File\Application\FileAdministrationService::class,
    \app\Modules\Official\File\Contracts\FileUploads::class => \app\Modules\Official\File\Application\FileUploadService::class,
    \app\Modules\Official\Member\Contracts\MemberQueries::class => \app\Modules\Official\Member\Application\MemberQueryService::class,
    \app\Modules\Official\Member\Contracts\MemberSubjectLookup::class => \app\Modules\Official\Member\Infrastructure\Persistence\ThinkPhpMemberSubjectLookup::class,
    \app\Modules\Official\Member\Contracts\MemberAdministration::class => \app\Modules\Official\Member\Application\MemberAdministrationService::class,
    \app\Modules\Official\ImportExport\Application\TenantConfigurationTransferService::class => \app\Modules\Official\ImportExport\Application\TenantConfigurationTransferService::class,
    \app\Modules\Official\ImportExport\Infrastructure\File\AppFileMediaGateway::class => \app\Modules\Official\ImportExport\Infrastructure\File\AppFileMediaGateway::class,
    \app\Modules\Official\ImportExport\Contracts\ImportExportWorkerRuntime::class => \app\Modules\Official\ImportExport\Application\TaskImportExportRuntime::class,
    \app\Modules\Official\ImportExport\Application\OperationLogExportApplicationService::class => \app\Modules\Official\ImportExport\Application\OperationLogExportApplicationService::class,
    \app\common\services\notice\NoticeChannelService::class => \app\common\services\notice\NoticeChannelService::class,
    \app\Modules\Official\Notification\Contracts\NotificationCommands::class => \app\Modules\Official\Notification\Application\NotificationApplicationService::class,
    \app\modules\official\integration\contracts\ExternalTenantBindingRepository::class => \app\modules\official\integration\infrastructure\ThinkPhpExternalTenantBindingRepository::class,
    \app\modules\official\integration\contracts\ExternalTenantResolutionService::class => \app\modules\official\integration\services\ExternalTenantResolver::class,
    \app\modules\official\integration\contracts\ExternalChannelBindings::class => \app\modules\official\integration\services\ExternalChannelBindingService::class,
    \app\common\service\payment\PaymentServiceFactory::class => \app\common\service\payment\PaymentServiceFactory::class,
    \app\Modules\Official\Payment\Contracts\PaymentChannelGrantCommands::class => \app\Modules\Official\Payment\Infrastructure\ThinkPhpPaymentChannelGrantCommands::class,
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
    \app\Modules\Official\ImportExport\Application\ImportExportApplicationService::class,
    pluginModuleContractControlledObject(\app\Modules\Official\ImportExport\Application\ImportExportApplicationService::class),
);
pluginModuleContractExpect(
    $importAliasApp->make(\app\Modules\Official\ImportExport\Contracts\ImportExportCommands::class)
        === $importAliasApp->make(\app\Modules\Official\ImportExport\Contracts\ImportExportQueries::class),
    'ImportExport Commands and Queries aliases did not share their controlled production implementation',
);

$configurationAliasApp = new App($serverRoot);
(new ModuleComposition($configurationAliasApp))->register($registry);
$configurationAliasApp->instance(
    \app\Modules\Official\ImportExport\Application\ConfigurationTransferApplicationService::class,
    pluginModuleContractControlledObject(\app\Modules\Official\ImportExport\Application\ConfigurationTransferApplicationService::class),
);
pluginModuleContractExpect(
    $configurationAliasApp->make(\app\Modules\Official\ImportExport\Contracts\ConfigurationTransferCommands::class)
        === $configurationAliasApp->make(\app\Modules\Official\ImportExport\Contracts\ConfigurationTransferQueries::class),
    'Configuration transfer Commands and Queries aliases did not share their controlled production implementation',
);

$notificationAliasApp = new App($serverRoot);
$notificationAliasApp->bind(require $serverRoot . '/app/adminapi/provider.php');
(new ModuleComposition($notificationAliasApp))->register($registry);
$notification = pluginModuleContractMakeOfficial(
    $notificationAliasApp,
    \app\Modules\Official\Notification\Contracts\NotificationCommands::class,
    \app\Modules\Official\Notification\Application\NotificationApplicationService::class,
);
pluginModuleContractExpect(
    $notification === $notificationAliasApp->make(\app\Modules\Official\Notification\Contracts\NotificationQueries::class),
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
