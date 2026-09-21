<?php
declare (strict_types = 1);

namespace app;

use app\adminapi\services\config\ConfigApplicationService;
use app\adminapi\services\generator\GeneratorService;
use app\adminapi\services\WorkbenchApplicationService;
use app\adminapi\infrastructure\generator\ThinkPhpGeneratorMetadata;
use app\adminapi\infrastructure\AdminApiAccessRegistry;
use app\adminapi\services\AdminLoginAttemptService;
use app\adminapi\services\OperationLogService;
use app\adminapi\infrastructure\generator\GeneratorImportPersistence;
use app\api\services\IndexApplicationService;
use app\api\services\LoginApplicationService as MemberLoginApplicationService;
use app\api\services\UserTokenService;
use app\modules\official\article\contracts\PublicArticleQueries;
use app\common\composition\ModuleComposition;
use app\common\contract\AdminPermissionPolicy;
use app\common\contract\authorization\AdminAuthorizationQuery;
use app\common\contract\idempotency\IdempotentCommandExecutor;
use app\common\contract\module\ModuleQualificationQuery;
use app\common\services\audit\AuditContractHost;
use app\common\execution\CurrentExecutionContext;
use app\common\execution\ExecutionContextStore;
use app\common\model\TenantOwnedModel;
use app\common\infrastructure\http\GuzzleOutboundHttpTransport;
use app\common\contract\http\OutboundHttpTransport;
use app\common\services\authorization\AdminAuthorizationService;
use app\common\infrastructure\authorization\CoreTenantModuleAdminBridge;
use app\common\enum\instance\DeploymentMode;
use app\common\infrastructure\idempotency\ThinkPhpIdempotentCommandExecutor;
use app\common\services\installation\InstallationExecutionHost;
use app\common\infrastructure\module\ModuleExecutionBoundary;
use app\common\security\ApplicationPasswordPolicy;
use app\common\composition\CoreServiceOverrides;
use app\common\services\CrontabCommandService;
use app\common\policy\DemoAccountPolicy;
use app\modules\official\file\services\FileService;
use app\common\services\ProductAssetReferenceService;
use app\common\services\authorization\MenuPermissionUsageQuery;
use app\common\runtime\authorization\RoleAdministrationRuntime;
use app\modules\official\identity\contracts\AdminDirectoryQuery;
use app\modules\official\identity\contracts\TenantAuditDiagnosticQuery;
use app\modules\official\identity\contracts\PlatformAuditDiagnosticQuery;
use app\modules\official\identity\contracts\PlatformOperatorIdentityQuery;
use app\modules\official\task\contracts\TaskDiagnosticQuery;
use app\modules\official\identity\access\source_read\SourceReadCapabilityRegistry;
use app\common\runtime\org\TenantAdminRuntime;
use app\common\services\tenant\TenantIdentityQuery;
use app\modules\official\file\composition\storage\AliyunStorageClientFactory;
use app\modules\official\file\infrastructure\storage\FailClosedStorageCredentialResolver;
use app\modules\official\file\composition\storage\QcloudStorageClientFactory;
use app\modules\official\file\infrastructure\storage\QiniuStorageHttpTransport;
use app\common\contract\storage\StorageCredentialResolver;
use app\modules\official\file\services\storage\StorageConfigurationService;
use app\modules\official\file\composition\storage\StorageDriverFactory;
use app\modules\official\file\services\storage\StorageService;
use app\common\tenancy\DataScopePolicy;
use app\common\tenancy\MultiTenantDataScopePolicy;
use app\common\tenancy\StandaloneDataScopePolicy;
use app\common\validate\InputValidator;
use app\platform\invitation\OwnerInvitationDeliveryPort;
use app\platform\invitation\OwnerInvitationRuntimePolicy;
use app\platform\invitation\UnavailableOwnerInvitationDeliveryPort;
use app\platform\identity\CorePlatformOperatorIdentityPort;
use app\platform\identity\PlatformOperatorIdentityPort;
use app\platform\services\ApplicationTenantBootstrapService;
use app\platform\services\CoreTenantOwnerAdminProvisioner;
use app\platform\services\PlatformOperatorSessionService;
use app\platform\services\TenantGovernanceService;
use app\platform\contract\TenantOwnerAdminProvisioner;
use app\platform\infrastructure\module\DeployedTenantModuleRegistry;
use app\platform\validation\module\OpisTenantModuleConfigValidator;
use app\platform\services\module\PlatformTenantModuleService;
use app\platform\infrastructure\module\VerifiedTenantModuleRepository;
use app\modules\official\ops\services\PlatformOpsApplicationService;
use app\modules\official\ops\infrastructure\ApplicationRuntimeStatusProvider;
use app\modules\official\ops\services\DeploymentModuleRequestService;
use app\modules\official\ops\infrastructure\PairedBackupProvider;
use app\modules\official\ops\infrastructure\ThinkPhpMaintenanceWindowStore;
use app\modules\official\ops\infrastructure\ThinkPhpModuleOperationTaskExecutionService;
use app\modules\official\ops\infrastructure\ThinkPhpOpsTaskDispatcher;
use app\modules\official\ops\infrastructure\ThinkPhpUpgradeTaskExecutionService;
use app\modules\official\ops\infrastructure\PlatformAuditRuntimeLogProvider;
use app\modules\official\ops\services\PlatformBackupCenterService;
use app\modules\official\ops\services\PlatformDiagnosticBundleService;
use app\modules\official\ops\services\PlatformModuleOperationExecutionService;
use app\modules\official\ops\infrastructure\authorization\PlatformOpsPermissionChecker;
use app\modules\official\ops\services\PlatformUpgradeExecutionService;
use app\modules\official\ops\services\PlatformUpgradeReadinessService;
use app\platform\infrastructure\module\ThinkPhpModuleGovernanceProvider;
use app\platform\services\plugin\PlatformModuleRuntimeService;
use app\platform\composition\plugin\ModuleDefinitionRegistryFactory;
use app\platform\infrastructure\plugin\PluginLockResolver;
use app\platform\infrastructure\plugin\ModuleCatalogApplier;
use app\platform\services\plugin\PluginCatalogSyncService;
use app\platform\services\plugin\PluginRuntimeGovernanceService;
use app\platform\infrastructure\provider\NotificationQualificationContributor;
use app\platform\infrastructure\provider\OauthQualificationContributor;
use app\platform\infrastructure\provider\PaymentQualificationContributor;
use app\platform\services\provider\PlatformProviderQualificationService;
use app\platform\infrastructure\provider\StorageQualificationContributor;
use think\Service;
use think\Model;
use think\facade\Config;
use PeanutAdmin\Kernel\Auth\Persistence\ThinkPhpTenantAuthRepository;
use PeanutAdmin\Kernel\Auth\Persistence\ThinkPhpPlatformAuthRepository;
use PeanutAdmin\Kernel\Auth\PlatformAuthRepository;
use PeanutAdmin\Kernel\Auth\TenantAuthRepository;
use PeanutAdmin\Kernel\Auth\PlatformAuthService;
use PeanutAdmin\Kernel\Auth\SystemClock;
use PeanutAdmin\Kernel\Auth\TenantAuthService;
use PeanutAdmin\Kernel\Auth\TokenIssuer;
use PeanutAdmin\Kernel\Authorization\Application\RoleAdminService;
use PeanutAdmin\Kernel\Authorization\RevisionPermissionCache;
use PeanutAdmin\Kernel\Authorization\ThinkPhpTenantAuthorizationRepository;
use PeanutAdmin\Kernel\Audit\AuditService;
use PeanutAdmin\Kernel\Identity\PasswordHasher;
use PeanutAdmin\Kernel\Identity\SelfService\AccountSelfService;
use PeanutAdmin\Kernel\Idempotency\IdempotencyService;
use PeanutAdmin\Kernel\Http\TenantAuthEndpoint;
use PeanutAdmin\Kernel\Membership\Application\MemberAdminService;
use PeanutAdmin\Kernel\Organization\Application\DepartmentAdminService;
use PeanutAdmin\Kernel\Host\ApplicationHostPolicy;
use PeanutAdmin\Kernel\Module\Persistence\ThinkPhpModuleRuntimeRepository;
use PeanutAdmin\Kernel\Module\CompiledModuleRegistry;
use PeanutAdmin\Kernel\Module\ModuleRuntimeRepository;
use PeanutAdmin\Kernel\Module\TenantModuleConfigurationService;
use PeanutAdmin\Kernel\Module\TenantModuleManager;
use PeanutAdmin\Kernel\Menu\ThinkPhpMenuCatalogRepository;
use PeanutAdmin\Kernel\Platform\Application\PlatformTenantAdminService;
use PeanutAdmin\Kernel\Platform\Application\TenantOwnerAdminService;
use PeanutAdmin\Kernel\Platform\Authorization\ThinkPhpPlatformAuthorizationRepository;
use PeanutAdmin\Kernel\Platform\Authorization\PlatformAuthorizationEvaluator;
use PeanutAdmin\Kernel\Platform\Authorization\PlatformAuthorizationRepository;
use PeanutAdmin\Kernel\Tenancy\DefaultTenantContextResolver;
use PeanutAdmin\Kernel\Tenancy\TenantEntryBindingResolver;
use app\modules\official\ops\domain\Application\PlatformPermissionChecker;
use app\modules\official\ops\domain\Logs\RuntimeLogProviderRegistry;
use app\modules\official\ops\domain\Logs\RuntimeLogService;
use app\modules\official\ops\domain\Logs\SafeLogMessageCatalog;
use app\modules\official\ops\domain\Maintenance\MaintenanceReasonRegistry;
use app\modules\official\ops\domain\Maintenance\MaintenanceService;
use app\modules\official\ops\domain\Maintenance\MaintenanceWindowStore;
use app\modules\official\ops\domain\Status\OpsStatusService;
use app\modules\official\ops\domain\Status\RuntimeStatusProvider;
use app\modules\official\ops\domain\Task\BackupRestoreProviderRegistry;
use app\modules\official\ops\domain\Task\OpsTaskDispatcher;
use app\modules\official\ops\domain\Task\OpsTaskService;
use app\modules\official\settings\Definition\SettingDefinitionSynchronizer;
use app\common\persistence\TenantPersistenceConfiguration;
use PeanutAdmin\Kernel\Persistence\Tenancy\TenantPersistenceMode;

/** 应用组合根，集中注册 Host 基础设施、业务服务与官方 Module Runtime。 */
class AppService extends Service
{
    public function register(): void
    {
        $this->registerExecutionContext();
        $this->registerAuthentication();
        $this->registerAuthorization();
        $this->registerStorage();
        $this->registerPlatform();
        $this->registerApplicationServices();
        $this->registerModules();
    }

    private function registerExecutionContext(): void
    {
        $contexts = new ExecutionContextStore();
        $current = new CurrentExecutionContext($contexts);
        $this->app->instance(ExecutionContextStore::class, $contexts);
        $this->app->instance(CurrentExecutionContext::class, $current);
        $configuredOverrides = Config::get('peanut.overrides', []);
        CoreServiceOverrides::configure(is_array($configuredOverrides) ? $configuredOverrides : []);
        $this->app->bind(PasswordHasher::class, fn(): PasswordHasher => ApplicationPasswordPolicy::hasher());
        $this->app->bind(ModuleCatalogApplier::class, fn(): ModuleCatalogApplier => new ModuleCatalogApplier(
            $this->app->make(SettingDefinitionSynchronizer::class),
        ));
        $this->app->bind(TenantPersistenceConfiguration::class, function (): TenantPersistenceConfiguration {
            $mode = DeploymentMode::fromConfiguredValue(Config::get('deployment.mode'));
            if (!$mode instanceof DeploymentMode) {
                throw new \RuntimeException('TENANT_PERSISTENCE_DEPLOYMENT_MODE_INVALID');
            }
            $storageMode = $mode === DeploymentMode::Standalone
                ? TenantPersistenceMode::InstanceScoped
                : TenantPersistenceMode::TenantScoped;
            $instanceTenantId = $mode === DeploymentMode::Standalone
                ? $this->app->make(DefaultTenantContextResolver::class)
                    ->system('peanut-admin', 'resolve-instance-tenant', 'core-tenant-persistence')->tenantId
                : null;

            return new TenantPersistenceConfiguration($storageMode, $instanceTenantId);
        });
        $this->app->bind(IdempotencyService::class, fn(): IdempotencyService => new IdempotencyService(
            $this->app->make(TenantPersistenceConfiguration::class)->mode,
            $this->app->make(TenantPersistenceConfiguration::class)->instanceTenantId,
        ));
        $this->app->bind(IdempotentCommandExecutor::class, ThinkPhpIdempotentCommandExecutor::class);
        $this->app->bind(OutboundHttpTransport::class, fn(): OutboundHttpTransport => new GuzzleOutboundHttpTransport(
            $this->app->make(CurrentExecutionContext::class),
        ));
        $this->app->bind(InputValidator::class, fn(): InputValidator => new InputValidator(
            $this->app,
            $this->app->make(CurrentExecutionContext::class),
        ));
        $this->app->bind(
            InstallationExecutionHost::class,
            fn(): InstallationExecutionHost => new InstallationExecutionHost(
                dirname(__DIR__),
                $this->app->make(ModuleCatalogApplier::class),
            ),
        );
        $this->app->bind(AuditContractHost::class, fn(): AuditContractHost => new AuditContractHost(
            $this->app->make(CurrentExecutionContext::class),
        ));
        $this->app->bind(OperationLogService::class, fn(): OperationLogService => new OperationLogService(
            $this->app->make(AuditContractHost::class),
        ));
    }

    private function registerAuthentication(): void
    {
        $this->app->bind(DemoAccountPolicy::class, fn(): DemoAccountPolicy => new DemoAccountPolicy(
            (bool)Config::get('peanut.demo.enabled', false),
            array_values(array_filter([
                (string)Config::get('peanut.demo.admin_email', ''),
                (string)Config::get('peanut.demo.platform_email', ''),
                (string)Config::get('peanut.demo.tenant_a_email', ''),
                (string)Config::get('peanut.demo.tenant_b_email', ''),
            ], static fn(string $email): bool => trim($email) !== '')),
        ));
        $this->app->bind(TenantAuthService::class, function (): TenantAuthService {
            $key = trim((string)Config::get('tenant_auth.identifier_hmac_key', ''));
            if (strlen($key) < 32) {
                throw new \DomainException('TENANT_AUTH_CONFIGURATION_UNAVAILABLE');
            }
            return new TenantAuthService(
                $this->app->make(TenantAuthRepository::class),
                ApplicationPasswordPolicy::hasher(),
                new SystemClock(),
                new TokenIssuer(),
                $key,
            );
        });
        $this->app->bind(TenantAuthEndpoint::class, fn(): TenantAuthEndpoint => new TenantAuthEndpoint(
            $this->app->make(TenantAuthService::class),
        ));
        $this->app->bind(AdminLoginAttemptService::class, fn(): AdminLoginAttemptService => new AdminLoginAttemptService(
            (int)Config::get('admin_auth.password_error_times', 5),
            (int)Config::get('admin_auth.lock_minutes', 30),
        ));
        $this->app->bind(UserTokenService::class, fn(): UserTokenService => new UserTokenService(
            (string)Config::get('jwt.secret', ''),
            (int)Config::get('jwt.expire', 0),
        ));
    }

    /** Wires current native Admin authorization services to their exact constructor contracts. */
    private function registerAuthorization(): void
    {
        // Cross-Tenant read capabilities are server registrations supplied by source Modules.
        // The base product intentionally starts empty, so unknown/wide scopes fail closed.
        $this->app->instance(SourceReadCapabilityRegistry::class, new SourceReadCapabilityRegistry([]));
        $this->app->bind(AdminPermissionPolicy::class, fn(): AdminPermissionPolicy =>
            CoreServiceOverrides::adminPermissionPolicy());
        $this->app->bind(\PeanutAdmin\Kernel\Authorization\TenantAuthorizationRepository::class, ThinkPhpTenantAuthorizationRepository::class);
        $this->app->bind(\PeanutAdmin\Kernel\Menu\MenuCatalogRepository::class, ThinkPhpMenuCatalogRepository::class);
        $this->app->bind(AdminAuthorizationService::class, fn(): AdminAuthorizationService => new AdminAuthorizationService(
            $this->app->make(CoreTenantModuleAdminBridge::class),
            $this->app->make(AdminPermissionPolicy::class),
        ));
        $this->app->bind(AdminAuthorizationQuery::class, fn(): AdminAuthorizationQuery => $this->app->make(AdminAuthorizationService::class));
        $this->app->bind(CoreTenantModuleAdminBridge::class, fn(): CoreTenantModuleAdminBridge => new CoreTenantModuleAdminBridge(
            $this->app->make(ThinkPhpModuleGovernanceProvider::class),
            $this->app->make(\PeanutAdmin\Kernel\Authorization\TenantAuthorizationRepository::class),
            $this->app->make(\PeanutAdmin\Kernel\Menu\MenuCatalogRepository::class),
        ));
        $this->app->bind(RoleAdministrationRuntime::class, fn(): RoleAdministrationRuntime => new RoleAdministrationRuntime(
            new RoleAdminService($this->app->make(AuditService::class)),
            $this->app->make(AdminAuthorizationService::class),
        ));
        $this->app->bind(AdminDirectoryQuery::class, fn(): AdminDirectoryQuery => new AdminDirectoryQuery(
            $this->app->make(CurrentExecutionContext::class),
        ));
        $this->app->bind(TenantAdminRuntime::class, fn(): TenantAdminRuntime => new TenantAdminRuntime(
            new MemberAdminService($this->app->make(AuditService::class), $this->app->make(PasswordHasher::class)),
            new AccountSelfService($this->app->make(AuditService::class), $this->app->make(PasswordHasher::class)),
            $this->app->make(DemoAccountPolicy::class),
        ));
        $this->app->bind(AdminApiAccessRegistry::class, function (): AdminApiAccessRegistry {
            $routes = Config::get('admin_api_access', []);
            return new AdminApiAccessRegistry(
                (int)Config::get('admin_api_access.version', 0),
                is_array($routes) ? $routes : [],
            );
        });
    }

    /** Wires the Edition-aware storage ledger to the host-configured Core drivers. */
    private function registerStorage(): void
    {
        $this->app->bind(StorageCredentialResolver::class, FailClosedStorageCredentialResolver::class);
        $this->app->bind(StorageDriverFactory::class, fn(): StorageDriverFactory => new StorageDriverFactory(
            $this->app->make(StorageCredentialResolver::class),
            new QiniuStorageHttpTransport($this->app->make(OutboundHttpTransport::class)),
            $this->app->make(AliyunStorageClientFactory::class),
            $this->app->make(QcloudStorageClientFactory::class),
            $this->app->make(CurrentExecutionContext::class),
            $this->app,
        ));
        $this->app->bind(StorageService::class, fn(): StorageService => new StorageService(
            $this->app->make(StorageDriverFactory::class),
            $this->app->make(DataScopePolicy::class),
            $this->app->make(DefaultTenantContextResolver::class),
            (string)Config::get('jwt.secret', ''),
            (string)$this->app->request->domain(),
        ));
        $this->app->bind(FileService::class, fn(): FileService => new FileService(
            $this->app->make(StorageService::class),
            (string)$this->app->request->domain(),
        ));
        $this->app->bind(ProductAssetReferenceService::class, fn(): ProductAssetReferenceService => new ProductAssetReferenceService(
            $this->app->make(FileService::class),
            (string)$this->app->request->domain(),
        ));
        $this->app->bind(StorageConfigurationService::class, fn(): StorageConfigurationService => new StorageConfigurationService(
            $this->app->make(AuditContractHost::class),
        ));
    }

    private function registerPlatform(): void
    {
        $this->app->bind(OwnerInvitationDeliveryPort::class, UnavailableOwnerInvitationDeliveryPort::class);
        $this->app->bind(OwnerInvitationRuntimePolicy::class, fn(): OwnerInvitationRuntimePolicy =>
            OwnerInvitationRuntimePolicy::fromEnvironment(
                (string)Config::get('peanut.environment', ''),
                (string)Config::get('platform_invitation.delivery_mode', 'auto'),
            ));
        $this->app->bind(TenantEntryBindingResolver::class, function (): TenantEntryBindingResolver {
            $mode = DeploymentMode::fromConfiguredValue(Config::get('deployment.mode'));
            $defaultSystem = $mode === DeploymentMode::Standalone
                && (bool)Config::get('deployment.public_default_tenant_fallback', true)
                ? fn(string $actor, string $operation, string $operationId) => $this->app
                    ->make(DefaultTenantContextResolver::class)
                    ->system($actor, $operation, $operationId)
                : null;
            return new TenantEntryBindingResolver(
                $defaultSystem,
                $mode === DeploymentMode::MultiTenant,
                $this->app->make(\app\modules\official\identity\tenancy\infrastructure\ThinkPhpTenantEntryBindingLookup::class),
            );
        });
        $this->app->bind(ApplicationHostPolicy::class, fn(): ApplicationHostPolicy => new ApplicationHostPolicy(
            (string)Config::get('deployment.mode', ''),
            self::hostList((string)Config::get('deployment.platform_hosts', '')),
            self::hostList((string)Config::get('deployment.tenant_admin_hosts', '')),
            $this->app->make(TenantEntryBindingResolver::class),
        ));
        $this->app->bind(DataScopePolicy::class, function (): DataScopePolicy {
            $mode = DeploymentMode::fromConfiguredValue(Config::get('deployment.mode'));
            return match ($mode) {
                DeploymentMode::MultiTenant => new MultiTenantDataScopePolicy(
                    $this->app->make(CurrentExecutionContext::class),
                ),
                DeploymentMode::Standalone => new StandaloneDataScopePolicy(),
                default => throw new \RuntimeException('DEPLOYMENT_MODE_UNCONFIGURED'),
            };
        });
        $this->app->bind(TenantAuthRepository::class, ThinkPhpTenantAuthRepository::class);
        $this->app->bind(PlatformAuthRepository::class, ThinkPhpPlatformAuthRepository::class);
        $this->app->bind(PlatformAuthorizationRepository::class, ThinkPhpPlatformAuthorizationRepository::class);
        $this->app->bind(PlatformAuthService::class, fn(): PlatformAuthService => new PlatformAuthService(
            $this->app->make(PlatformAuthRepository::class),
            $this->app->make(PasswordHasher::class),
            new SystemClock(),
            new TokenIssuer(),
            $this->platformIdentifierHmacKey(),
        ));
        $this->app->bind(PlatformOperatorIdentityPort::class, CorePlatformOperatorIdentityPort::class);
        $this->app->bind(TenantOwnerAdminProvisioner::class, CoreTenantOwnerAdminProvisioner::class);
        $this->app->bind(DeployedTenantModuleRegistry::class, fn(): DeployedTenantModuleRegistry =>
            $this->app->make(ThinkPhpModuleGovernanceProvider::class)->registry());
        $this->app->bind(ModuleRuntimeRepository::class, ThinkPhpModuleRuntimeRepository::class);
        $this->app->bind(TenantModuleConfigurationService::class, fn(): TenantModuleConfigurationService => new TenantModuleConfigurationService(
            $this->app->make(DeployedTenantModuleRegistry::class)->compiled(),
            $this->app->make(OpisTenantModuleConfigValidator::class),
            $this->app->make(ModuleRuntimeRepository::class),
            $this->app->make(AuditService::class),
        ));
        $this->app->bind(TenantModuleManager::class, fn(): TenantModuleManager => new TenantModuleManager(
            $this->app->make(DeployedTenantModuleRegistry::class)->compiled(),
            new VerifiedTenantModuleRepository(
                new ThinkPhpModuleRuntimeRepository($this->app->make(CompiledModuleRegistry::class)),
                $this->app->make(DeployedTenantModuleRegistry::class),
            ),
            $this->app->make(OpisTenantModuleConfigValidator::class),
        ));
        $this->app->bind(TenantOwnerAdminService::class, fn(): TenantOwnerAdminService => new TenantOwnerAdminService(
            $this->app->make(AuditService::class),
            $this->app->make(PasswordHasher::class),
        ));
        $this->app->bind(PluginCatalogSyncService::class, fn(): PluginCatalogSyncService =>
            new PluginCatalogSyncService(
                dirname(__DIR__),
                $this->moduleConfiguration(),
                $this->app->make(ModuleCatalogApplier::class),
            ));
        $this->app->bind(PlatformModuleRuntimeService::class, fn(): PlatformModuleRuntimeService =>
            new PlatformModuleRuntimeService(
                dirname(__DIR__),
                $this->moduleConfiguration(),
                $this->trustedModuleKeys(),
                $this->app->make(PluginRuntimeGovernanceService::class),
                $this->app->make(PluginCatalogSyncService::class),
                $this->app->make(ModuleCatalogApplier::class),
            ));
        $this->app->bind(PlatformPermissionChecker::class, PlatformOpsPermissionChecker::class);
        $this->app->bind(OpsTaskDispatcher::class, ThinkPhpOpsTaskDispatcher::class);
        $this->app->bind(MaintenanceWindowStore::class, ThinkPhpMaintenanceWindowStore::class);
        $this->app->bind(BackupRestoreProviderRegistry::class, fn(): BackupRestoreProviderRegistry =>
            new BackupRestoreProviderRegistry([new PairedBackupProvider()]));
        $this->app->bind(MaintenanceReasonRegistry::class, fn(): MaintenanceReasonRegistry =>
            new MaintenanceReasonRegistry([
                'planned-upgrade',
                'database-maintenance',
                'security-maintenance',
                'module-lifecycle',
            ]));
        $this->app->bind(ThinkPhpModuleGovernanceProvider::class, fn(): ThinkPhpModuleGovernanceProvider =>
            new ThinkPhpModuleGovernanceProvider(
                dirname(__DIR__),
                $this->moduleConfiguration(),
                $this->app->make(ModuleCatalogApplier::class),
            ));
        $this->app->bind(ModuleQualificationQuery::class, fn(): ModuleQualificationQuery => $this->app
            ->make(ThinkPhpModuleGovernanceProvider::class)
            ->qualification());
        $this->app->bind(PluginRuntimeGovernanceService::class, fn(): PluginRuntimeGovernanceService =>
            new PluginRuntimeGovernanceService(
                dirname(__DIR__),
                $this->moduleConfiguration(),
                $this->app->make(ModuleCatalogApplier::class),
            ));
        $this->app->bind(DeploymentModuleRequestService::class, fn(): DeploymentModuleRequestService =>
            new DeploymentModuleRequestService(
                dirname(__DIR__, 2),
                $this->moduleConfiguration(),
                $this->trustedModuleKeys(),
                $this->app->make(PluginRuntimeGovernanceService::class),
                $this->app->make(ModuleCatalogApplier::class),
            ));
        $this->app->bind(PlatformUpgradeReadinessService::class, fn(): PlatformUpgradeReadinessService =>
            new PlatformUpgradeReadinessService(
                dirname(__DIR__, 2),
                $this->app->make(ThinkPhpModuleGovernanceProvider::class),
                $this->app->make(PlatformBackupCenterService::class),
                $this->app->make(MaintenanceService::class),
                $this->app->make(PlatformPermissionChecker::class),
            ));
        $this->app->bind(ApplicationRuntimeStatusProvider::class, fn(): ApplicationRuntimeStatusProvider =>
            new ApplicationRuntimeStatusProvider(
                dirname(__DIR__, 2),
                $this->app->make(PlatformUpgradeReadinessService::class),
                $this->app->make(ThinkPhpModuleGovernanceProvider::class),
            ));
        $this->app->bind(RuntimeStatusProvider::class, ApplicationRuntimeStatusProvider::class);
        $this->app->bind(OpsStatusService::class, fn(): OpsStatusService => new OpsStatusService(
            $this->app->make(PlatformPermissionChecker::class),
            $this->app->make(RuntimeStatusProvider::class),
        ));
        $this->app->bind(PlatformProviderQualificationService::class, fn(): PlatformProviderQualificationService =>
            new PlatformProviderQualificationService(
                $this->app->make(PlatformPermissionChecker::class),
                [
                    new PaymentQualificationContributor($this->platformIdentifierHmacKey()),
                    new NotificationQualificationContributor($this->platformIdentifierHmacKey()),
                    new OauthQualificationContributor($this->platformIdentifierHmacKey()),
                    new StorageQualificationContributor($this->platformIdentifierHmacKey()),
                ],
                $this->platformIdentifierHmacKey(),
            ));
        $this->app->bind(PlatformDiagnosticBundleService::class, function (): PlatformDiagnosticBundleService {
            $permissions = $this->app->make(PlatformPermissionChecker::class);
            return new PlatformDiagnosticBundleService(
                $permissions,
                fn(\DateTimeImmutable $since): RuntimeLogService => new RuntimeLogService(
                    $permissions,
                    new RuntimeLogProviderRegistry([
                        new PlatformAuditRuntimeLogProvider(
                            $since,
                            $this->app->make(PlatformAuditDiagnosticQuery::class),
                        ),
                    ]),
                    new SafeLogMessageCatalog([]),
                ),
                $this->app->make(OpsStatusService::class),
                $this->app->make(ThinkPhpModuleGovernanceProvider::class),
                $this->app->make(TaskDiagnosticQuery::class),
                $this->app->make(TenantAuditDiagnosticQuery::class),
                (string)Config::get('deployment.mode', ''),
                (bool)Config::get('app.app_debug', false),
            );
        });
        $this->app->bind(PlatformUpgradeExecutionService::class, fn(): PlatformUpgradeExecutionService =>
            new PlatformUpgradeExecutionService(
                $this->app->make(ThinkPhpOpsTaskDispatcher::class),
                dirname(__DIR__, 2),
                $this->app->make(ApplicationRuntimeStatusProvider::class),
                $this->app->make(PlatformPermissionChecker::class),
            ));
        $this->app->bind(PlatformModuleOperationExecutionService::class, fn(): PlatformModuleOperationExecutionService =>
            new PlatformModuleOperationExecutionService(
                $this->app->make(ThinkPhpOpsTaskDispatcher::class),
                $this->app->make(DeploymentModuleRequestService::class),
                $this->app->make(ApplicationRuntimeStatusProvider::class),
                $this->app->make(PlatformPermissionChecker::class),
            ));
        $this->app->bind(ThinkPhpUpgradeTaskExecutionService::class, fn(): ThinkPhpUpgradeTaskExecutionService =>
            new ThinkPhpUpgradeTaskExecutionService(
                $this->app->make(AuditContractHost::class),
                $this->app->make(ThinkPhpOpsTaskDispatcher::class),
                $this->app->make(ThinkPhpMaintenanceWindowStore::class),
                dirname(__DIR__, 2),
                $this->app->make(BackupRestoreProviderRegistry::class),
                $this->app->make(ApplicationRuntimeStatusProvider::class),
                $this->app->make(PlatformOperatorIdentityQuery::class),
            ));
        $this->app->bind(ThinkPhpModuleOperationTaskExecutionService::class, fn(): ThinkPhpModuleOperationTaskExecutionService =>
            new ThinkPhpModuleOperationTaskExecutionService(
                $this->app->make(AuditContractHost::class),
                $this->app->make(ThinkPhpOpsTaskDispatcher::class),
                $this->app->make(ThinkPhpMaintenanceWindowStore::class),
                $this->app->make(DeploymentModuleRequestService::class),
                $this->app->make(BackupRestoreProviderRegistry::class),
                $this->app->make(ApplicationRuntimeStatusProvider::class),
                $this->app->make(PlatformOperatorIdentityQuery::class),
            ));
        $this->app->bind(CrontabCommandService::class, fn(): CrontabCommandService => new CrontabCommandService(
            (array)Config::get('console.commands', []),
            (array)Config::get('console.module_commands', []),
        ));
    }

    private function registerApplicationServices(): void
    {
        $this->app->bind(WorkbenchApplicationService::class, fn(): WorkbenchApplicationService => new WorkbenchApplicationService(
            $this->app->make(AdminAuthorizationService::class),
            $this->app->make(FileService::class),
            $this->app->make(\app\modules\official\settings\services\WebsiteConfigService::class),
            (string)Config::get('project.version', ''),
            (string)Config::get('project.based', ''),
            (array)Config::get('project.default_image', []),
        ));
        $this->app->bind(ConfigApplicationService::class, fn(): ConfigApplicationService => new ConfigApplicationService(
            $this->app->make(\app\modules\official\settings\services\TenantApplicationSettingService::class),
            $this->app->make(FileService::class),
            $this->app->make(\app\common\services\RichTextResourceService::class),
            $this->app->make(\app\modules\official\settings\services\WebsiteConfigService::class),
            (string)Config::get('project.default_image.user_avatar', ''),
        ));
        $this->app->bind(GeneratorService::class, fn(): GeneratorService => new GeneratorService(
            $this->app->make(GeneratorImportPersistence::class),
            $this->app->make(ThinkPhpGeneratorMetadata::class),
            $this->databasePrefix(),
        ));
        $this->app->bind(IndexApplicationService::class, fn(): IndexApplicationService => new IndexApplicationService(
            $this->app->make(TenantIdentityQuery::class),
            $this->app->make(\app\modules\official\settings\services\TenantApplicationSettingService::class),
            $this->app->make(PublicArticleQueries::class),
            $this->app->make(\app\common\services\RichTextResourceService::class),
            $this->app->make(\app\common\services\decoration\DecorationReadService::class),
            $this->app->make(\app\modules\official\settings\services\WebsiteConfigService::class),
            (string)Config::get('project.version', ''),
            [
                'enabled' => $this->app->make(DemoAccountPolicy::class)->enabled(),
                'tenant_a_host' => (string)Config::get('peanut.demo.tenant_a_host', ''),
                'tenant_b_host' => (string)Config::get('peanut.demo.tenant_b_host', ''),
                'shared_hosts' => self::hostList((string)Config::get('deployment.tenant_admin_hosts', '')),
                'tenant_a_email' => (string)Config::get('peanut.demo.tenant_a_email', ''),
                'tenant_b_email' => (string)Config::get('peanut.demo.tenant_b_email', ''),
                'password' => (string)Config::get('peanut.demo.shared_password', ''),
            ],
        ));
        $this->app->bind(MemberLoginApplicationService::class, fn(): MemberLoginApplicationService => new MemberLoginApplicationService(
            $this->app->make(\app\modules\official\member\contracts\MemberIdentityCommands::class),
            $this->app->make(\app\modules\official\notification\contracts\VerificationCodeCommands::class),
            $this->app->make(\app\modules\official\settings\services\TenantApplicationSettingService::class),
            $this->app->make(FileService::class),
            $this->app->make(UserTokenService::class),
            (string)Config::get('project.default_image.user_avatar', ''),
        ));
    }

    public function boot(): void
    {
        $policy = $this->app->make(DataScopePolicy::class);
        if (!$policy instanceof DataScopePolicy) {
            throw new \LogicException('DATA_SCOPE_POLICY_UNAVAILABLE');
        }
        Model::maker(static function (Model $model) use ($policy): void {
            if ($model instanceof TenantOwnedModel) {
                $model->setDataScopePolicy($policy);
            }
        });
    }

    private function databasePrefix(): string
    {
        $connection = (string)Config::get('database.default', 'mysql');
        return (string)Config::get('database.connections.' . $connection . '.prefix', '');
    }

    private function registerModules(): void
    {
        // Recovery must boot while a pending source journal deliberately blocks the Module resolver.
        if ($this->app->runningInConsole()
            && ($_SERVER['argv'][1] ?? null) === 'module:adopt-package'
            && Config::get('peanut.environment', '') === 'development') {
            return;
        }
        $config = Config::get('modules', []);
        if (!is_array($config)) {
            throw new \RuntimeException('MODULE_REGISTRY_UNAVAILABLE');
        }
        $serverRoot = dirname(__DIR__);
        $lockPath = trim((string)($config['plugin_lock'] ?? ''));
        if ($lockPath === '') {
            throw new \RuntimeException('PLUGIN_LOCK_INVALID');
        }
        $registry = (new ModuleDefinitionRegistryFactory($serverRoot))->fromPluginLock(
            new PluginLockResolver($serverRoot, $lockPath),
            $config,
            false,
        );
        $this->app->instance(CompiledModuleRegistry::class, $registry);
        (new ModuleComposition($this->app))->register($registry);
    }

    /** @return array<string,mixed> */
    private function moduleConfiguration(): array
    {
        $config = Config::get('modules', []);
        if (!is_array($config)) {
            throw new \RuntimeException('MODULE_REGISTRY_UNAVAILABLE');
        }
        return $config;
    }

    /** @return array<string,string> */
    private function trustedModuleKeys(): array
    {
        $trustedKeys = [];
        foreach ((array)Config::get('module_packages.trusted_ed25519_keys', []) as $keyId => $encoded) {
            $decoded = is_string($encoded) ? base64_decode($encoded, true) : false;
            if (is_string($keyId) && is_string($decoded)
                && strlen($decoded) === SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES) {
                $trustedKeys[$keyId] = $decoded;
            }
        }
        return $trustedKeys;
    }

    private function platformIdentifierHmacKey(): string
    {
        $key = trim((string)Config::get('platform_auth.identifier_hmac_key', ''));
        // 平台认证标识依赖专用 HMAC 密钥；缺失或过短时必须在装配边界拒绝。
        if (strlen($key) < 32) {
            throw new \DomainException('PLATFORM_AUTH_CONFIGURATION_UNAVAILABLE');
        }
        return $key;
    }

    /** @return list<string> */
    private static function hostList(string $hosts): array
    {
        return array_values(array_filter(array_map('trim', explode(',', $hosts))));
    }
}
