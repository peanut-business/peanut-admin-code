<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/bootstrap/environment.php';

use app\platform\context\PlatformOperatorContext;
use app\platform\identity\PlatformOperatorIdentity;
use app\platform\identity\PlatformOperatorIdentityPort;
use app\platform\identity\UnavailablePlatformOperatorIdentityPort;
use app\platform\service\TenantGovernanceService;
use app\platform\service\TenantOwnerAdminProvisioner;
use PeanutAdmin\Modules\Identity\Audit\AuditService;
use PeanutAdmin\Kernel\Auth\ValidatedPlatformSession;
use PeanutAdmin\Kernel\Context\PlatformContext;
use PeanutAdmin\Kernel\Identity\PasswordHasher;
use PeanutAdmin\Kernel\Migration\ModuleSchema;
use PeanutAdmin\Kernel\Module\CompiledModuleRegistry;
use PeanutAdmin\Kernel\Module\ManifestDocument;
use PeanutAdmin\Kernel\Module\ModuleException;
use PeanutAdmin\Modules\Identity\Module\Persistence\ThinkPhpModuleRuntimeRepository;
use PeanutAdmin\Kernel\Module\TenantModuleConfigValidator;
use PeanutAdmin\Kernel\Module\TenantModuleManager;
use PeanutAdmin\Kernel\Persistence\Schema\KernelSchema;
use PeanutAdmin\Modules\Identity\Platform\Application\PlatformTenantAdminService;
use PeanutAdmin\Modules\Identity\Platform\Application\TenantOwnerAdminService;
use PeanutAdmin\Modules\Identity\Platform\Bootstrap\BootstrapService;
use PeanutAdmin\Modules\Identity\Tenancy\TenantStatus;

require dirname(__DIR__, 2) . '/vendor/autoload.php';
require __DIR__ . '/../Support/IsolatedBackendEnvironment.php';
require __DIR__ . '/../Support/ThinkPhpTestConnection.php';

function pm01Expect(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function pm01Rejected(Closure $operation, string $expectedMessage): void
{
    try {
        $operation();
    } catch (Throwable $exception) {
        pm01Expect(
            str_contains($exception->getMessage(), $expectedMessage),
            "unexpected rejection: {$exception->getMessage()}"
        );
        return;
    }
    throw new RuntimeException("expected rejection: {$expectedMessage}");
}

final readonly class Pm01FixtureIdentity implements PlatformOperatorIdentityPort
{
    public function __construct(private PlatformOperatorIdentity $identity)
    {
    }

    public function requireActive(string $credential, string $requestId): PlatformOperatorContext
    {
        if (!hash_equals('fixture-platform-credential', $credential)) {
            throw new DomainException('PLATFORM_OPERATOR_AUTHENTICATION_FAILED');
        }
        return PlatformOperatorContext::fromValidatedPlatformSession(PlatformContext::fromValidatedSession(
            new ValidatedPlatformSession(
                $this->identity->operatorId,
                'fixture-platform-session',
                $this->identity->operatorId,
                $this->identity->accountId,
                'platform-web',
                new DateTimeImmutable('+1 hour'),
            ),
            $requestId,
        ));
    }
}

final class Pm01FixtureConfigValidator implements TenantModuleConfigValidator
{
    public function assertValid(ManifestDocument $manifest, array $config): void
    {
        if (array_keys($config) !== ['region'] || !is_string($config['region']) || $config['region'] === '') {
            throw new ModuleException('MODULE_CONFIG_INVALID', 'region is required');
        }
    }
}

function pm01Bootstrap(): BootstrapService
{
    return new BootstrapService(passwords: new PasswordHasher());
}

$host = IsolatedBackendEnvironment::required('DB_HOST');
$port = IsolatedBackendEnvironment::required('DB_PORT');
$user = IsolatedBackendEnvironment::required('DB_USER');
$password = IsolatedBackendEnvironment::required('DB_PASS');
$admin = new PDO(
    "mysql:host={$host};port={$port};charset=utf8mb4",
    $user,
    $password,
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES => false]
);
$database = 'pa_pm01_' . strtolower(bin2hex(random_bytes(6)));
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
        ]
    );
    foreach (KernelSchema::tableNames() as $table) {
        $pdo->exec(KernelSchema::createSql($table));
    }
    $pdo->exec(KernelSchema::addTenantMemberDepartmentForeignKeySql());
    foreach (ModuleSchema::tableNames() as $table) {
        $pdo->exec(ModuleSchema::createSql($table));
    }
    ThinkPhpTestConnection::fromPdo($pdo);

    $bootstrap = pm01Bootstrap();
    $platform = $bootstrap->bootstrapPlatformOwner(
        'operator@example.test',
        'OperatorPassword2026',
        'Fixture Operator',
        'pm01-platform-bootstrap'
    );
    $manifest = ManifestDocument::fromArray('/fixture/pm01', [
        'key' => 'peanut.fixture-governance',
        'tenant' => ['requires' => []],
    ]);
    $registry = new CompiledModuleRegistry([$manifest], [], [], [], $manifest->digest);
    $moduleRepository = new ThinkPhpModuleRuntimeRepository();
    $validator = new Pm01FixtureConfigValidator();
    $modules = new TenantModuleManager($registry, $moduleRepository, $validator);
    $administration = new PlatformTenantAdminService($modules, new AuditService());
    $owners = new TenantOwnerAdminService(new AuditService());
    $ownerAdmins = new class implements TenantOwnerAdminProvisioner {
        public function provision(
            int $tenantId,
            int $accountId,
            int $memberId,
            int $coreRoleId,
            string $tenantCode,
            string $displayName
        ): int {
            return 1;
        }
    };
    $identity = new PlatformOperatorIdentity($platform->operatorId, $platform->accountId);
    $governance = new TenantGovernanceService(
        new Pm01FixtureIdentity($identity),
        $administration,
        $owners,
        $ownerAdmins
    );

    $failClosed = new TenantGovernanceService(
        new UnavailablePlatformOperatorIdentityPort(),
        $administration,
        $owners,
        $ownerAdmins
    );
    pm01Rejected(
        static fn() => $failClosed->provision(
            '', 'forged', 'Forged', 'forged@example.test', 'ForgedPassword2026', 'Forged', 'pm01-forged'
        ),
        'PLATFORM_OPERATOR_AUTHENTICATION_UNAVAILABLE'
    );
    pm01Expect((int)$pdo->query('SELECT COUNT(*) FROM pa_tenant')->fetchColumn() === 0, 'fail-closed identity wrote a tenant');

    $candidate = $governance->provision(
        'fixture-platform-credential',
        'alpha',
        'Alpha Tenant',
        'owner@example.test',
        'OwnerPassword2026',
        'Alpha Owner',
        'pm01-provision'
    );
    $tenantId = $candidate['tenant_id'];
    pm01Expect($candidate['status'] === 'pending', 'provision must return the Core owner candidate state');
    pm01Expect(
        $pdo->query("SELECT status FROM pa_tenant WHERE id={$tenantId}")->fetchColumn() === 'provisioning',
        'tenant must remain provisioning until an explicit lifecycle transition'
    );
    pm01Expect(
        (int)$pdo->query("SELECT COUNT(*) FROM pa_tenant_member WHERE tenant_id={$tenantId} AND status='active'")->fetchColumn() === 1,
        'first owner must be active before tenant activation'
    );

    $active = $governance->transition(
        'fixture-platform-credential', $tenantId, 1, TenantStatus::Active, 'provisioning complete', 'pm01-active'
    );
    pm01Expect($active['status'] === 'active', 'provisioning tenant did not activate');

    $pdo->prepare(<<<'SQL'
INSERT INTO pa_module_installation (
    module_key, installed_version, manifest_schema_version, manifest_digest,
    status, installed_at, activated_at, created_at, updated_at
) VALUES (?, '1.0.0', 1, ?, 'active', CURRENT_TIMESTAMP(3), CURRENT_TIMESTAMP(3), CURRENT_TIMESTAMP(3), CURRENT_TIMESTAMP(3))
SQL)->execute(['peanut.fixture-governance', $manifest->digest]);

    pm01Rejected(
        static fn() => $governance->enableModule(
            'fixture-platform-credential', $tenantId, 'peanut.fixture-governance', [], 'manual', null, null,
            'invalid config fixture', 'pm01-module-invalid'
        ),
        'region is required'
    );
    pm01Expect((int)$pdo->query('SELECT COUNT(*) FROM pa_tenant_module')->fetchColumn() === 0, 'invalid module config was persisted');

    $enabled = $governance->enableModule(
        'fixture-platform-credential', $tenantId, 'peanut.fixture-governance', ['region' => 'cn-east'],
        'manual', null, null, 'enable fixture module', 'pm01-module-enable'
    );
    pm01Expect($enabled['status'] === 'enabled', 'valid module configuration was not enabled');

    $revision = (int)$pdo->query("SELECT revision FROM pa_tenant WHERE id={$tenantId}")->fetchColumn();
    $suspended = $governance->transition(
        'fixture-platform-credential', $tenantId, $revision, TenantStatus::Suspended, 'support hold', 'pm01-suspend'
    );
    pm01Expect($suspended['status'] === 'suspended', 'active tenant did not suspend');
    pm01Rejected(
        static fn() => $governance->enableModule(
            'fixture-platform-credential', $tenantId, 'peanut.fixture-governance', ['region' => 'cn-west'],
            'manual', null, null, 'suspended write', 'pm01-module-suspended'
        ),
        'Only an active tenant'
    );

    $reactivated = $governance->transition(
        'fixture-platform-credential', $tenantId, (int)$suspended['revision'], TenantStatus::Active,
        'hold cleared', 'pm01-reactivate'
    );
    $closed = $governance->transition(
        'fixture-platform-credential', $tenantId, (int)$reactivated['revision'], TenantStatus::Closed,
        'customer closure', 'pm01-close'
    );
    pm01Expect($closed['status'] === 'closed', 'tenant did not close');
    pm01Rejected(
        static fn() => $governance->transition(
            'fixture-platform-credential', $tenantId, (int)$closed['revision'], TenantStatus::Active,
            'forbidden reopen', 'pm01-reopen'
        ),
        'Tenant cannot transition from closed to active'
    );
    pm01Expect(
        (int)$pdo->query("SELECT COUNT(*) FROM pa_tenant_audit_event WHERE tenant_id={$tenantId}")->fetchColumn() >= 5,
        'Core tenant governance audit evidence is incomplete'
    );

    echo "PM01-TENANT-GOVERNANCE-001 passed\n";
} finally {
    $admin->exec("DROP DATABASE IF EXISTS `{$database}`");
}
