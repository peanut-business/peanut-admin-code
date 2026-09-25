<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/bootstrap/environment.php';

use app\platform\identity\CorePlatformOperatorIdentityPort;
use app\platform\service\module\DeployedTenantModuleRegistry;
use app\platform\service\module\OpisTenantModuleConfigValidator;
use app\platform\service\module\PlatformTenantModuleService;
use app\platform\service\module\VerifiedTenantModuleRepository;
use app\platform\service\PlatformOperatorSessionService;
use app\platform\service\TenantGovernanceService;
use app\platform\service\TenantOwnerAdminProvisioner;
use Opis\JsonSchema\Validator;
use PeanutAdmin\Modules\Identity\Audit\AuditService;
use PeanutAdmin\Modules\Identity\Auth\Persistence\ThinkPhpPlatformAuthRepository;
use PeanutAdmin\Modules\Identity\Auth\PlatformAuthService;
use PeanutAdmin\Kernel\Auth\SystemClock;
use PeanutAdmin\Kernel\Auth\TokenIssuer;
use PeanutAdmin\Modules\Identity\Authorization\Persistence\ThinkPhpAuthorizationCatalogRepository;
use PeanutAdmin\Modules\Identity\Authorization\Persistence\Schema\AuthorizationSchema;
use PeanutAdmin\Modules\Identity\Authorization\CorePermissionCatalogSynchronizer;
use PeanutAdmin\Kernel\Authorization\RevisionPermissionCache;
use PeanutAdmin\Kernel\Identity\PasswordHasher;
use PeanutAdmin\Kernel\Migration\ModuleSchema;
use PeanutAdmin\Kernel\Module\CompiledModuleRegistry;
use PeanutAdmin\Kernel\Module\ContractInspector;
use PeanutAdmin\Kernel\Module\ManifestSchemaValidator;
use PeanutAdmin\Kernel\Module\ModuleException;
use PeanutAdmin\Kernel\Module\ModuleHostLayout;
use PeanutAdmin\Kernel\Module\ModuleProvider;
use PeanutAdmin\Kernel\Module\ModuleRegistryCompiler;
use PeanutAdmin\Modules\Identity\Module\Persistence\ThinkPhpModuleRuntimeRepository;
use PeanutAdmin\Kernel\Module\TenantModuleManager;
use PeanutAdmin\Kernel\Module\VersionConstraintMatcher;
use PeanutAdmin\Kernel\Persistence\Schema\KernelSchema;
use PeanutAdmin\Modules\Identity\Platform\Application\PlatformTenantAdminService;
use PeanutAdmin\Modules\Identity\Platform\Application\TenantOwnerAdminService;
use PeanutAdmin\Modules\Identity\Platform\Authorization\ThinkPhpPlatformAuthorizationRepository;
use PeanutAdmin\Kernel\Platform\Authorization\PlatformAuthorizationEvaluator;
use PeanutAdmin\Modules\Identity\Platform\Bootstrap\BootstrapService;
use PeanutAdmin\Modules\Identity\Tenancy\TenantStatus;

require dirname(__DIR__, 2) . '/vendor/autoload.php';
require __DIR__ . '/../Support/IsolatedBackendEnvironment.php';
require __DIR__ . '/../Support/ThinkPhpTestConnection.php';
require __DIR__ . '/../fixtures/PlatformTenantModule/Content/ModuleProvider.php';

function pm01ModuleExpect(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function pm01ModuleRejects(Closure $operation, string $expected): void
{
    try {
        $operation();
    } catch (Throwable $exception) {
        $code = property_exists($exception, 'errorCode') ? (string) $exception->errorCode : '';
        pm01ModuleExpect(
            $code === $expected || str_contains($exception->getMessage(), $expected),
            "unexpected rejection: {$code} {$exception->getMessage()}",
        );
        return;
    }
    throw new RuntimeException("expected rejection: {$expected}");
}

function pm01ModuleBootstrap(): BootstrapService
{
    return new BootstrapService(passwords: new PasswordHasher());
}

function pm01ModuleSessions(): PlatformOperatorSessionService
{
    $permissions = new ThinkPhpPlatformAuthorizationRepository();
    return new PlatformOperatorSessionService(
        new PlatformAuthService(
            new ThinkPhpPlatformAuthRepository(),
            new PasswordHasher(),
            new SystemClock(),
            new TokenIssuer(),
            str_repeat('m', 32),
        ),
        new PlatformAuthorizationEvaluator($permissions, new RevisionPermissionCache()),
        $permissions,
    );
}

function pm01ModuleCompiler(string $schemaPath): ModuleRegistryCompiler
{
    $schemaValidator = new class ($schemaPath) implements ManifestSchemaValidator {
        public function __construct(private readonly string $schemaPath) {}

        public function assertValid(object $manifest): void
        {
            $schema = json_decode((string) file_get_contents($this->schemaPath));
            if (!is_object($schema) || !(new Validator())->validate($manifest, $schema)->isValid()) {
                throw new ModuleException('MODULE_MANIFEST_INVALID', 'Fixture manifest schema validation failed.');
            }
        }
    };
    $versions = new class implements VersionConstraintMatcher {
        public function matches(string $version, string $constraint): bool
        {
            return $constraint === '^1.0' && preg_match('/^1\.[0-9]+\.[0-9]+(?:[-+][0-9A-Za-z.-]+)?$/D', $version) === 1;
        }
    };
    $contracts = new class implements ContractInspector {
        public function classExists(string $class): bool
        {
            return class_exists($class) || interface_exists($class);
        }

        public function implements(string $class, string $contract): bool
        {
            return $contract === ModuleProvider::class && is_a($class, ModuleProvider::class, true);
        }
    };

    return new ModuleRegistryCompiler(
        $schemaValidator,
        $versions,
        $contracts,
        '1.0.0',
        [],
        new ModuleHostLayout('server/tests/Fixtures/PlatformTenantModule', 'Fixture', 'web/src/modules'),
        [...KernelSchema::tableNames(), ...AuthorizationSchema::tableNames(), ...ModuleSchema::tableNames()],
        ['admin-web', 'platform-web'],
        [...\PeanutAdmin\Modules\Identity\Authorization\CorePermissionCatalog::TENANT, ...\PeanutAdmin\Modules\Identity\Authorization\CorePermissionCatalog::PLATFORM],
    );
}

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
$database = 'pa_pm01_module_' . strtolower(bin2hex(random_bytes(6)));
$admin->exec("CREATE DATABASE `{$database}` CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci");

try {
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
    foreach (KernelSchema::tableNames() as $table) {
        $pdo->exec(KernelSchema::createSql($table));
    }
    $pdo->exec(KernelSchema::addTenantMemberDepartmentForeignKeySql());
    foreach (ModuleSchema::tableNames() as $table) {
        $pdo->exec(ModuleSchema::createSql($table));
    }
    foreach (AuthorizationSchema::tableNames() as $table) {
        $pdo->exec(AuthorizationSchema::createSql($table));
    }
    ThinkPhpTestConnection::fromPdo($pdo);
    (new CorePermissionCatalogSynchronizer(new ThinkPhpAuthorizationCatalogRepository()))->synchronize();

    $bootstrap = pm01ModuleBootstrap();
    $platform = $bootstrap->bootstrapPlatformOwner(
        'module-owner@example.test',
        'ModuleOwnerPassword2026',
        'Module Owner',
        'pm01-module-platform-bootstrap',
    );
    $sessions = pm01ModuleSessions();
    $authentication = $sessions->login(
        'module-owner@example.test',
        'ModuleOwnerPassword2026',
        '127.0.0.1',
        'PM01 TenantModule fixture',
        'pm01-module-login',
    );
    $credential = $authentication->tokens->access->expose();

    $moduleRoot = realpath(__DIR__ . '/../fixtures/PlatformTenantModule/Content');
    $kernelRoot = dirname((new ReflectionClass(ModuleProvider::class))->getFileName(), 3);
    $registry = DeployedTenantModuleRegistry::compile(
        [$moduleRoot],
        pm01ModuleCompiler($kernelRoot . '/resources/schemas/module-manifest.schema.json'),
    );
    $validator = new OpisTenantModuleConfigValidator();
    $repository = new VerifiedTenantModuleRepository(
        new ThinkPhpModuleRuntimeRepository(true),
        $registry,
    );
    $governance = new TenantGovernanceService(
        new CorePlatformOperatorIdentityPort($sessions),
        new PlatformTenantAdminService(
            new TenantModuleManager($registry->compiled(), $repository, $validator),
            new AuditService(),
        ),
        new TenantOwnerAdminService(new AuditService()),
        new class implements TenantOwnerAdminProvisioner {
            public function provision(
                int $tenantId,
                int $accountId,
                int $memberId,
                int $coreRoleId,
                string $tenantCode,
                string $displayName,
            ): int {
                return 1;
            }
        },
    );
    $service = new PlatformTenantModuleService($sessions, $governance, $registry, $validator);

    pm01ModuleRejects(
        static fn() => new DeployedTenantModuleRegistry(
            new CompiledModuleRegistry([], [], [], [], hash('sha256', '')),
        ),
        'MODULE_REGISTRY_UNAVAILABLE',
    );

    $candidate = $governance->provision(
        $credential,
        'module-active',
        'Module Active',
        'module-tenant-owner@example.test',
        'ModuleTenantOwner2026',
        'Module Tenant Owner',
        'pm01-module-provision',
    );
    $tenantId = (int) $candidate['tenant_id'];
    $governance->transition(
        $credential,
        $tenantId,
        1,
        TenantStatus::Active,
        'module fixture ready',
        'pm01-module-activate',
    );
    pm01ModuleRejects(
        static fn() => $registry->requireInstalled('fixture.content'),
        'MODULE_NOT_INSTALLED',
    );
    $manifest = $registry->compiled()->modules[0];
    $pdo->prepare(<<<'SQL'
INSERT INTO pa_module_installation (
 module_key,installed_version,manifest_schema_version,manifest_digest,status,
 installed_at,activated_at,created_at,updated_at
) VALUES (?, ?, 1, ?, 'active', CURRENT_TIMESTAMP(3), CURRENT_TIMESTAMP(3), CURRENT_TIMESTAMP(3), CURRENT_TIMESTAMP(3))
SQL)->execute(['fixture.content', '1.0.0', $manifest->digest]);

    pm01ModuleRejects(
        static fn() => $service->enable(
            $credential,
            $tenantId,
            'fixture.unknown',
            [],
            'manual',
            null,
            null,
            'unknown module',
            'pm01-module-unknown',
        ),
        'MODULE_NOT_INSTALLED',
    );
    pm01ModuleRejects(
        static fn() => $service->enable(
            $credential,
            $tenantId,
            'fixture.content',
            ['region' => 'forged'],
            'manual',
            null,
            null,
            'invalid config',
            'pm01-module-invalid',
        ),
        'MODULE_CONFIG_INVALID',
    );
    pm01ModuleExpect((int) $pdo->query('SELECT COUNT(*) FROM pa_tenant_module')->fetchColumn() === 0, 'invalid config wrote state');

    $pdo->exec("UPDATE pa_module_installation SET manifest_digest=REPEAT('0',64) WHERE module_key='fixture.content'");
    pm01ModuleRejects(
        static fn() => $service->enable(
            $credential,
            $tenantId,
            'fixture.content',
            ['region' => 'cn-east'],
            'manual',
            null,
            null,
            'mismatched manifest',
            'pm01-module-mismatch',
        ),
        'MODULE_INSTALLATION_MISMATCH',
    );
    $pdo->prepare('UPDATE pa_module_installation SET manifest_digest=? WHERE module_key=?')
        ->execute([$manifest->digest, 'fixture.content']);

    $enabled = $service->enable(
        $credential,
        $tenantId,
        'fixture.content',
        ['region' => 'cn-east'],
        'manual',
        null,
        null,
        'enable content',
        'pm01-module-enable',
    );
    pm01ModuleExpect($enabled['status'] === 'enabled', 'module was not enabled');
    pm01ModuleExpect((int) $enabled['config_revision'] === 1, 'config revision did not start at one');
    pm01ModuleExpect(
        (int) $pdo->query("SELECT revision FROM pa_tenant WHERE id={$tenantId}")->fetchColumn() === 3,
        'Core Tenant revision did not advance after module enable',
    );
    pm01ModuleExpect(
        (int) $pdo->query("SELECT COUNT(*) FROM pa_platform_audit_event WHERE event_type='tenant-module.enabled'")->fetchColumn() === 1,
        'platform audit was not recorded',
    );
    pm01ModuleExpect(
        (int) $pdo->query("SELECT COUNT(*) FROM pa_tenant_audit_event WHERE tenant_id={$tenantId} AND event_type='tenant-module.enabled'")->fetchColumn() === 1,
        'tenant audit was not recorded',
    );

    $ownerRoleId = (int) $pdo->query("SELECT id FROM pa_platform_role WHERE `key`='platform.bootstrap-owner'")
        ->fetchColumn();
    $pdo->exec("UPDATE pa_platform_role SET status='disabled', revision=revision+1 WHERE id={$ownerRoleId}");
    pm01ModuleRejects(
        static fn() => $service->disable(
            $credential,
            $tenantId,
            'fixture.content',
            'permission denied',
            'pm01-module-denied',
        ),
        'AUTHZ_PERMISSION_DENIED',
    );
    pm01ModuleExpect(
        $pdo->query("SELECT status FROM pa_tenant_module WHERE tenant_id={$tenantId}")->fetchColumn() === 'enabled',
        'permission denial changed state',
    );
    $pdo->exec("UPDATE pa_platform_role SET status='active', revision=revision+1 WHERE id={$ownerRoleId}");

    $disabled = $service->disable(
        $credential,
        $tenantId,
        'fixture.content',
        'disable content',
        'pm01-module-disable',
    );
    pm01ModuleExpect($disabled['status'] === 'disabled', 'module was not disabled');
    pm01ModuleExpect((int) $disabled['config_revision'] === 1, 'disable changed config revision');
    pm01ModuleExpect(
        (int) $pdo->query("SELECT revision FROM pa_tenant WHERE id={$tenantId}")->fetchColumn() === 4,
        'Core Tenant revision did not advance after module disable',
    );
    pm01ModuleExpect(
        (int) $pdo->query("SELECT COUNT(*) FROM pa_platform_audit_event WHERE event_type LIKE 'tenant-module.%'")->fetchColumn() === 2,
        'platform module audit is incomplete',
    );
    pm01ModuleExpect(
        (int) $pdo->query("SELECT COUNT(*) FROM pa_tenant_audit_event WHERE tenant_id={$tenantId} AND event_type LIKE 'tenant-module.%'")->fetchColumn() === 2,
        'tenant module audit is incomplete',
    );

    echo "PM01-TENANT-MODULE-SERVICE-001 passed\n";
} finally {
    $admin->exec("DROP DATABASE IF EXISTS `{$database}`");
}
