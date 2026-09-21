<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/route/registry_source.php';

require dirname(__DIR__, 2) . '/bootstrap/environment.php';

use app\platform\context\PlatformOperatorContext;
use app\platform\identity\CorePlatformOperatorIdentityPort;
use app\platform\service\PlatformOperatorSessionService;
use PeanutAdmin\Modules\Identity\Auth\Persistence\ThinkPhpPlatformAuthRepository;
use PeanutAdmin\Modules\Identity\Auth\PlatformAuthService;
use PeanutAdmin\Kernel\Auth\SystemClock;
use PeanutAdmin\Kernel\Auth\TokenIssuer;
use PeanutAdmin\Kernel\Auth\ValidatedPlatformSession;
use PeanutAdmin\Modules\Identity\Authorization\CorePermissionCatalogSynchronizer;
use PeanutAdmin\Modules\Identity\Authorization\Persistence\ThinkPhpAuthorizationCatalogRepository;
use PeanutAdmin\Modules\Identity\Authorization\Persistence\Schema\AuthorizationSchema;
use PeanutAdmin\Kernel\Authorization\RevisionPermissionCache;
use PeanutAdmin\Kernel\Context\PlatformContext;
use PeanutAdmin\Kernel\Identity\PasswordHasher;
use PeanutAdmin\Kernel\Migration\ModuleSchema;
use PeanutAdmin\Kernel\Persistence\Schema\KernelSchema;
use PeanutAdmin\Modules\Identity\Platform\Authorization\ThinkPhpPlatformAuthorizationRepository;
use PeanutAdmin\Kernel\Platform\Authorization\PlatformAuthorizationEvaluator;
use PeanutAdmin\Modules\Identity\Platform\Bootstrap\BootstrapService;

require dirname(__DIR__, 2) . '/vendor/autoload.php';
require __DIR__ . '/../Support/IsolatedBackendEnvironment.php';
require __DIR__ . '/../Support/ThinkPhpTestConnection.php';

function poExpect(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function poRejected(Closure $operation, string $expected): void
{
    try {
        $operation();
    } catch (Throwable $exception) {
        poExpect(str_contains($exception->getMessage(), $expected), "unexpected rejection: {$exception->getMessage()}");
        return;
    }
    throw new RuntimeException("expected rejection: {$expected}");
}

function poBootstrap(): BootstrapService
{
    return new BootstrapService(passwords: new PasswordHasher());
}

function poSessions(): PlatformOperatorSessionService
{
    $repository = new ThinkPhpPlatformAuthorizationRepository();
    return new PlatformOperatorSessionService(
        new PlatformAuthService(
            new ThinkPhpPlatformAuthRepository(),
            new PasswordHasher(),
            new SystemClock(),
            new TokenIssuer(),
            str_repeat('p', 32)
        ),
        new PlatformAuthorizationEvaluator($repository, new RevisionPermissionCache()),
        $repository
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
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES => false]
);
$database = 'pa_pm01_operator_' . strtolower(bin2hex(random_bytes(6)));
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
    foreach (AuthorizationSchema::tableNames() as $table) {
        $pdo->exec(AuthorizationSchema::createSql($table));
    }
    $pdo->exec('CREATE TABLE pa_admin_session (id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, token VARCHAR(128) NOT NULL, PRIMARY KEY (id)) ENGINE=InnoDB');
    ThinkPhpTestConnection::fromPdo($pdo);
    (new CorePermissionCatalogSynchronizer(new ThinkPhpAuthorizationCatalogRepository()))->synchronize();

    $bootstrap = poBootstrap();
    $platform = $bootstrap->bootstrapPlatformOwner(
        'platform-only@example.test',
        'PlatformPassword2026',
        'Platform Only',
        'pm01-operator-bootstrap'
    );
    $sessions = poSessions();
    $authentication = $sessions->login(
        'platform-only@example.test',
        'PlatformPassword2026',
        '127.0.0.1',
        'PM01 fixture',
        'pm01-operator-login'
    );
    $access = $authentication->tokens->access->expose();
    poExpect(str_starts_with($access, 'pa_pat_'), 'platform access token audience prefix is wrong');
    poExpect((int)$pdo->query('SELECT COUNT(*) FROM pa_admin_session')->fetchColumn() === 0, 'platform login reused admin session storage');
    poExpect((int)$pdo->query('SELECT COUNT(*) FROM pa_platform_session')->fetchColumn() === 1, 'platform session was not persisted independently');
    poExpect((int)$pdo->query('SELECT COUNT(*) FROM pa_tenant_member WHERE account_id=' . $platform->accountId)->fetchColumn() === 0, 'platform operator became a TenantMember');

    $context = $sessions->context($access, 'pm01-context');
    poExpect($context->core->operatorId === $platform->operatorId, 'validated platform context resolved the wrong operator');
    poRejected(
        static fn() => $sessions->context('pa_tat_' . str_repeat('a', 43), 'pm01-forged-tenant-token'),
        'Credential cannot be used for this entry.'
    );
    $wrongClientContext = PlatformContext::fromValidatedSession(
        new ValidatedPlatformSession(999, 'fixture-session', 999, 999, 'admin-web', new DateTimeImmutable()),
        'pm01-wrong-client'
    );
    poRejected(
        static fn() => PlatformOperatorContext::fromValidatedPlatformSession($wrongClientContext),
        'PLATFORM_OPERATOR_CONTEXT_UNTRUSTED'
    );

    $identity = (new CorePlatformOperatorIdentityPort($sessions))->requireActive($access);
    poExpect($identity->operatorId === $platform->operatorId, 'governance identity port did not use the platform session');
    $sessions->assertAllowed($context, 'platform.tenant.read');
    poRejected(static fn() => $sessions->assertAllowed($context, 'core.member.read'), 'AUTHZ_PERMISSION_DENIED');

    // A non-builtin role receives only explicit platform permissions; ordinary admin tables are irrelevant.
    $now = '2026-08-12 12:00:00.000';
    $pdo->exec("INSERT INTO pa_account (display_name,status,created_at,updated_at) VALUES ('Scoped Operator','active','{$now}','{$now}')");
    $scopedAccountId = (int)$pdo->lastInsertId();
    $hash = password_hash('ScopedPassword2026', PASSWORD_ARGON2ID);
    $statement = $pdo->prepare(<<<'SQL'
INSERT INTO pa_credential (
 account_id,kind,identifier_type,identifier_normalized,secret_hash,status,verified_at,secret_changed_at,created_at,updated_at
) VALUES (
 :account_id,'email_password','email','scoped@example.test',:secret_hash,'active',
 :verified_at,:secret_changed_at,:created_at,:updated_at
)
SQL);
    $statement->execute([
        'account_id' => $scopedAccountId,
        'secret_hash' => $hash,
        'verified_at' => $now,
        'secret_changed_at' => $now,
        'created_at' => $now,
        'updated_at' => $now,
    ]);
    $pdo->exec("INSERT INTO pa_platform_operator (account_id,display_name,status,created_at,updated_at) VALUES ({$scopedAccountId},'Scoped Operator','active','{$now}','{$now}')");
    $scopedOperatorId = (int)$pdo->lastInsertId();
    $pdo->exec("INSERT INTO pa_platform_role (`key`,name,is_builtin,status,created_at,updated_at) VALUES ('platform.scoped-reader','Scoped Reader',0,'active','{$now}','{$now}')");
    $roleId = (int)$pdo->lastInsertId();
    $permissionId = (int)$pdo->query("SELECT id FROM pa_permission WHERE `key`='platform.tenant.read'")->fetchColumn();
    $pdo->exec("INSERT INTO pa_platform_role_permission (platform_role_id,permission_id,granted_at) VALUES ({$roleId},{$permissionId},'{$now}')");
    $pdo->exec("INSERT INTO pa_platform_operator_role (platform_operator_id,platform_role_id,assigned_at) VALUES ({$scopedOperatorId},{$roleId},'{$now}')");
    $scoped = $sessions->login('scoped@example.test', 'ScopedPassword2026', '127.0.0.2', 'PM01 fixture', 'pm01-scoped-login');
    $scopedContext = $sessions->context($scoped->tokens->access->expose(), 'pm01-scoped-context');
    $sessions->assertAllowed($scopedContext, 'platform.tenant.read');
    poRejected(static fn() => $sessions->assertAllowed($scopedContext, 'platform.tenant.lifecycle'), 'AUTHZ_PERMISSION_DENIED');

    // The same Account may hold both identities without merging their sessions or permissions.
    $candidate = $bootstrap->provisionTenantOwnerCandidate(
        $platform->operatorId,
        'boundary',
        'Boundary Tenant',
        'scoped@example.test',
        null,
        'Scoped Operator',
        'pm01-dual-identity-membership'
    );
    poExpect($candidate->memberId > 0, 'membership boundary fixture was not established');
    $dualIdentityContext = $sessions->context(
        $scoped->tokens->access->expose(),
        'pm01-membership-recheck'
    );
    poExpect(
        $dualIdentityContext->core->operatorId === $scopedOperatorId,
        'TenantMember identity changed the validated platform operator'
    );
    $sessions->assertAllowed($dualIdentityContext, 'platform.tenant.read');
    poRejected(
        static fn() => $sessions->assertAllowed($dualIdentityContext, 'core.member.read'),
        'AUTHZ_PERMISSION_DENIED'
    );
    poExpect(
        $pdo->query("SELECT status FROM pa_platform_session WHERE platform_operator_id={$scopedOperatorId}")->fetchColumn() === 'active',
        'TenantMember identity revoked the independent platform session'
    );

    $routeSource = peanut_route_registry_source(dirname(__DIR__, 2));
    poExpect(is_string($routeSource) && str_contains($routeSource, "Route::post('session/login'"), 'independent platform route prefix is missing');
    poExpect(str_contains($routeSource, 'PlatformLoginMiddleware::class'), 'platform routes lack their dedicated login guard');
    poExpect(str_contains($routeSource, "'platform.tenant.read'"), 'platform tenant route lacks explicit RBAC');

    echo "PM01-PLATFORM-OPERATOR-BOUNDARY-001 passed\n";
} finally {
    $admin->exec("DROP DATABASE IF EXISTS `{$database}`");
}
