<?php

declare(strict_types=1);

require_once __DIR__ . '/../Support/CiBootstrap.php';

use app\platform\context\PlatformOperatorContext;
use app\platform\services\TenantGovernanceService;
use PeanutAdmin\Modules\Identity\Contract\TenantOwnerAdminProvisioner;
use PeanutAdmin\Modules\Identity\Audit\AuditService;
use PeanutAdmin\Kernel\Auth\ValidatedPlatformSession;
use PeanutAdmin\Kernel\Context\PlatformContext;
use PeanutAdmin\Kernel\Identity\PasswordHasher;
use PeanutAdmin\Kernel\Identity\PasswordPolicy;
use PeanutAdmin\Modules\Identity\Platform\Application\PlatformTenantAdminService;
use PeanutAdmin\Modules\Identity\Platform\Application\TenantOwnerAdminService;
use PeanutAdmin\Modules\Identity\Platform\Bootstrap\BootstrapService;
use PeanutAdmin\Modules\Identity\Tenancy\TenantStatus;

require dirname(__DIR__, 2) . '/vendor/autoload.php';
require __DIR__ . '/../Support/IsolatedBackendEnvironment.php';
require_once __DIR__ . '/../Support/ThinkPhpTestConnection.php';
require_once __DIR__ . '/../Support/IdentityMysqlFixture.php';
require_once __DIR__ . '/../Support/RegisteredMysqlTestResource.php';

$cache = require dirname(__DIR__, 2) . '/config/cache.php';
$cache['stores']['file']['path'] = dirname(__DIR__, 3) . '/.local/tmp/TenantGovernanceTest-cache-' . bin2hex(random_bytes(6)) . '/';
think\Container::getInstance()->config->set($cache, 'cache');

function pm01Expect(bool $condition, string $message): void
{
    $GLOBALS['pm01ExpectAssertions'] = ($GLOBALS['pm01ExpectAssertions'] ?? 0) + 1;
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
            "unexpected rejection: {$exception->getMessage()}",
        );
        return;
    }
    throw new RuntimeException("expected rejection: {$expectedMessage}");
}

/** Context handoff only; production Identity verifies the operator row within its transaction. */
function pm01Context(int $operatorId, int $accountId, string $requestId): PlatformOperatorContext
{
    return PlatformOperatorContext::fromValidatedPlatformSession(PlatformContext::fromValidatedSession(
        new ValidatedPlatformSession(
            $operatorId,
            'pm01-platform-session',
            $operatorId,
            $accountId,
            'platform-web',
            new DateTimeImmutable('+1 hour'),
        ),
        $requestId,
    ));
}

function pm01Bootstrap(): BootstrapService
{
    return new BootstrapService(new AuditService(), new PasswordHasher(), new PasswordPolicy());
}

$database = RegisteredMysqlTestResource::configuredDatabaseName();
[$pdo, $createdDatabase] = RegisteredMysqlTestResource::openEmptyDatabase($database);

try {
    IdentityMysqlFixture::install($pdo);
    ThinkPhpTestConnection::fromPdo($pdo);

    $bootstrap = pm01Bootstrap();
    $platform = $bootstrap->bootstrapPlatformOwner(
        'operator@example.test',
        'OperatorPassword2026',
        'Fixture Operator',
        'pm01-platform-bootstrap',
    );
    $administration = new PlatformTenantAdminService(new AuditService());
    $owners = new TenantOwnerAdminService(new AuditService(), new PasswordHasher(), new PasswordPolicy());
    $ownerAdmins = new class implements TenantOwnerAdminProvisioner {
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
    };
    $governance = new TenantGovernanceService(
        $administration,
        $owners,
        $ownerAdmins,
    );

    pm01Rejected(
        static fn() => $governance->provision(
            pm01Context($platform->operatorId + 1000000, $platform->accountId, 'pm01-forged'),
            'forged',
            'Forged',
            'forged@example.test',
            'ForgedPassword2026',
            'Forged',
        ),
        'An active platform operator is required.',
    );
    pm01Expect((int) $pdo->query('SELECT COUNT(*) FROM pa_tenant')->fetchColumn() === 0, 'unbound platform operator wrote a tenant');

    $candidate = $governance->provision(
        pm01Context($platform->operatorId, $platform->accountId, 'pm01-provision'),
        'alpha',
        'Alpha Tenant',
        'owner@example.test',
        'OwnerPassword2026',
        'Alpha Owner',
    );
    $tenantId = $candidate['tenant_id'];
    pm01Expect($candidate['status'] === 'pending', 'provision must return the Core owner candidate state');
    pm01Expect(
        $pdo->query("SELECT status FROM pa_tenant WHERE id={$tenantId}")->fetchColumn() === 'provisioning',
        'tenant must remain provisioning until an explicit lifecycle transition',
    );
    pm01Expect(
        (int) $pdo->query("SELECT COUNT(*) FROM pa_tenant_member WHERE tenant_id={$tenantId} AND status='active'")->fetchColumn() === 1,
        'first owner must be active before tenant activation',
    );

    $active = $governance->transition(
        pm01Context($platform->operatorId, $platform->accountId, 'pm01-active'),
        $tenantId,
        1,
        TenantStatus::Active,
        'provisioning complete',
    );
    pm01Expect($active['status'] === 'active', 'provisioning tenant did not activate');

    $revision = (int) $pdo->query("SELECT revision FROM pa_tenant WHERE id={$tenantId}")->fetchColumn();
    $suspended = $governance->transition(
        pm01Context($platform->operatorId, $platform->accountId, 'pm01-suspend'),
        $tenantId,
        $revision,
        TenantStatus::Suspended,
        'support hold',
    );
    pm01Expect($suspended['status'] === 'suspended', 'active tenant did not suspend');
    $reactivated = $governance->transition(
        pm01Context($platform->operatorId, $platform->accountId, 'pm01-reactivate'),
        $tenantId,
        (int) $suspended['revision'],
        TenantStatus::Active,
        'hold cleared',
    );
    $closed = $governance->transition(
        pm01Context($platform->operatorId, $platform->accountId, 'pm01-close'),
        $tenantId,
        (int) $reactivated['revision'],
        TenantStatus::Closed,
        'customer closure',
    );
    pm01Expect($closed['status'] === 'closed', 'tenant did not close');
    pm01Rejected(
        static fn() => $governance->transition(
            pm01Context($platform->operatorId, $platform->accountId, 'pm01-reopen'),
            $tenantId,
            (int) $closed['revision'],
            TenantStatus::Active,
            'forbidden reopen',
        ),
        'Tenant cannot transition from closed to active',
    );
    pm01Expect(
        (int) $pdo->query("SELECT COUNT(*) FROM pa_tenant_audit_event WHERE tenant_id={$tenantId}")->fetchColumn() >= 5,
        'Core tenant governance audit evidence is incomplete',
    );

    echo "PM01-TENANT-GOVERNANCE-001 passed; assertions={$GLOBALS['pm01ExpectAssertions']}\n";
} finally {
    RegisteredMysqlTestResource::cleanup($pdo, $database, $createdDatabase);
}
