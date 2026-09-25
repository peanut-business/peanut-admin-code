<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/route/registry_source.php';

require dirname(__DIR__, 2) . '/bootstrap/environment.php';

use app\platform\service\PlatformOperatorSessionService;
use app\platform\service\PlatformTenantQueryService;
use PeanutAdmin\Modules\Identity\Auth\Persistence\ThinkPhpPlatformAuthRepository;
use PeanutAdmin\Modules\Identity\Auth\PlatformAuthService;
use PeanutAdmin\Kernel\Auth\SystemClock;
use PeanutAdmin\Kernel\Auth\TokenIssuer;
use PeanutAdmin\Kernel\Authorization\Application\AdminAccessException;
use PeanutAdmin\Kernel\Authorization\Application\PageRequest;
use PeanutAdmin\Modules\Identity\Authorization\CorePermissionCatalogSynchronizer;
use PeanutAdmin\Modules\Identity\Authorization\Persistence\ThinkPhpAuthorizationCatalogRepository;
use PeanutAdmin\Modules\Identity\Authorization\Persistence\Schema\AuthorizationSchema;
use PeanutAdmin\Kernel\Authorization\RevisionPermissionCache;
use PeanutAdmin\Kernel\Identity\PasswordHasher;
use PeanutAdmin\Kernel\Migration\ModuleSchema;
use PeanutAdmin\Kernel\Persistence\Schema\KernelSchema;
use PeanutAdmin\Modules\Identity\Platform\Application\PlatformWorkspaceQueryService;
use PeanutAdmin\Modules\Identity\Platform\Authorization\ThinkPhpPlatformAuthorizationRepository;
use PeanutAdmin\Kernel\Platform\Authorization\PlatformAuthorizationEvaluator;
use PeanutAdmin\Modules\Identity\Platform\Bootstrap\BootstrapService;

require dirname(__DIR__, 2) . '/vendor/autoload.php';
require __DIR__ . '/../Support/IsolatedBackendEnvironment.php';
require __DIR__ . '/../Support/ThinkPhpTestConnection.php';

function platformTenantReadExpect(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function platformTenantReadBootstrap(): BootstrapService
{
    return new BootstrapService(passwords: new PasswordHasher());
}

function platformTenantReadSessions(): PlatformOperatorSessionService
{
    $repository = new ThinkPhpPlatformAuthorizationRepository();
    return new PlatformOperatorSessionService(
        new PlatformAuthService(
            new ThinkPhpPlatformAuthRepository(),
            new PasswordHasher(),
            new SystemClock(),
            new TokenIssuer(),
            str_repeat('r', 32),
        ),
        new PlatformAuthorizationEvaluator($repository, new RevisionPermissionCache()),
        $repository,
    );
}

function platformTenantReadCreateOperator(PDO $pdo, string $email, string $password): void
{
    $now = '2026-08-13 09:00:00.000';
    $pdo->exec("INSERT INTO pa_account (display_name,status,created_at,updated_at) VALUES ('No Access','active','{$now}','{$now}')");
    $accountId = (int) $pdo->lastInsertId();
    $statement = $pdo->prepare(<<<'SQL'
INSERT INTO pa_credential (
  account_id,kind,identifier_type,identifier_normalized,secret_hash,status,
  verified_at,secret_changed_at,created_at,updated_at
) VALUES (
  :account_id,'email_password','email',:email,:secret_hash,'active',
  :verified_at,:secret_changed_at,:created_at,:updated_at
)
SQL);
    $statement->execute([
        'account_id' => $accountId,
        'email' => $email,
        'secret_hash' => password_hash($password, PASSWORD_ARGON2ID),
        'verified_at' => $now,
        'secret_changed_at' => $now,
        'created_at' => $now,
        'updated_at' => $now,
    ]);
    $pdo->exec("INSERT INTO pa_platform_operator (account_id,display_name,status,created_at,updated_at) VALUES ({$accountId},'No Access','active','{$now}','{$now}')");
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
$database = 'pa_pm01_tenant_read_' . strtolower(bin2hex(random_bytes(6)));
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

    $bootstrap = platformTenantReadBootstrap();
    $platform = $bootstrap->bootstrapPlatformOwner(
        'reader@example.test',
        'ReaderPassword2026',
        'Platform Reader',
        'pm01-tenant-read-bootstrap',
    );
    $now = '2026-08-13 09:00:00.000';
    $tenantInsert = $pdo->prepare(<<<'SQL'
INSERT INTO pa_tenant (
  code,name,display_name,status,locale,timezone,revision,suspended_at,closed_at,created_at,updated_at
) VALUES (
  :code,:name,:display_name,:status,'zh-CN','Asia/Shanghai',:revision,:suspended_at,:closed_at,:created_at,:updated_at
)
SQL);
    foreach ([
        ['alpha', 'Alpha', 'Alpha Tenant', 'provisioning', 1],
        ['beta', 'Beta', 'Beta Tenant', 'suspended', 3],
        ['gamma', 'Gamma', 'Gamma Tenant', 'closed', 7],
    ] as [$code, $name, $displayName, $status, $revision]) {
        $tenantInsert->execute(compact('code', 'name', 'status', 'revision') + [
            'display_name' => $displayName,
            'suspended_at' => $status === 'suspended' ? $now : null,
            'closed_at' => $status === 'closed' ? $now : null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    $sessions = platformTenantReadSessions();
    $queries = new PlatformTenantQueryService($sessions, new PlatformWorkspaceQueryService());
    $authentication = $sessions->login(
        'reader@example.test',
        'ReaderPassword2026',
        '127.0.0.1',
        'PM01 tenant read fixture',
        'pm01-tenant-read-login',
    );
    $context = $sessions->context(
        $authentication->tokens->access->expose(),
        'pm01-tenant-read-context',
    );

    $before = [];
    foreach (['pa_tenant', 'pa_tenant_member', 'pa_tenant_module', 'pa_platform_audit_event', 'pa_tenant_audit_event'] as $table) {
        $before[$table] = (int) $pdo->query("SELECT COUNT(*) FROM {$table}")->fetchColumn();
    }

    $firstPage = $queries->tenants($context, new PageRequest(1, 2));
    platformTenantReadExpect($firstPage['total'] === 3, 'platform Tenant total is incorrect');
    platformTenantReadExpect(count($firstPage['items']) === 2, 'platform Tenant page size is incorrect');
    platformTenantReadExpect(
        array_column($firstPage['items'], 'status') === ['provisioning', 'suspended'],
        'platform Tenant list lost governance-visible lifecycle states',
    );
    $secondPage = $queries->tenants($context, new PageRequest(2, 2));
    platformTenantReadExpect(count($secondPage['items']) === 1, 'platform Tenant second page is incorrect');
    $closedId = (int) $secondPage['items'][0]['id'];
    $closed = $queries->tenant($context, $closedId);
    platformTenantReadExpect($closed['status'] === 'closed', 'closed Tenant detail was not governance-visible');
    platformTenantReadExpect($closed['revision'] === '7', 'Tenant detail revision changed shape');

    try {
        $queries->tenant($context, 999999);
        throw new RuntimeException('missing Tenant detail unexpectedly succeeded');
    } catch (AdminAccessException $exception) {
        platformTenantReadExpect(
            $exception->errorCode === 'RESOURCE_NOT_FOUND' && $exception->httpStatus === 404,
            'missing Tenant detail lost the stable not-found shape',
        );
    }
    try {
        new PageRequest(1, 101);
        throw new RuntimeException('oversized platform Tenant page unexpectedly succeeded');
    } catch (AdminAccessException $exception) {
        platformTenantReadExpect($exception->httpStatus === 422, 'page-size denial lost validation shape');
    }

    platformTenantReadCreateOperator($pdo, 'no-access@example.test', 'NoAccessPassword2026');
    $noAccess = $sessions->login(
        'no-access@example.test',
        'NoAccessPassword2026',
        '127.0.0.2',
        'PM01 tenant read fixture',
        'pm01-tenant-read-no-access-login',
    );
    $noAccessContext = $sessions->context(
        $noAccess->tokens->access->expose(),
        'pm01-tenant-read-no-access-context',
    );
    try {
        $queries->tenants($noAccessContext, new PageRequest());
        throw new RuntimeException('operator without platform.tenant.read unexpectedly queried Tenants');
    } catch (Throwable $exception) {
        platformTenantReadExpect(
            str_contains($exception->getMessage(), 'AUTHZ_PERMISSION_DENIED'),
            'permission denial changed shape',
        );
    }
    foreach (['pa_tat_' . str_repeat('a', 43), 'admin-session-token'] as $wrongAudience) {
        try {
            $sessions->context($wrongAudience, 'pm01-tenant-read-wrong-audience');
            throw new RuntimeException('non-platform audience unexpectedly established PlatformContext');
        } catch (Throwable $exception) {
            platformTenantReadExpect($exception->getMessage() !== '', 'wrong-audience denial lost its shape');
        }
    }

    foreach ($before as $table => $count) {
        platformTenantReadExpect(
            (int) $pdo->query("SELECT COUNT(*) FROM {$table}")->fetchColumn() === $count,
            "platform Tenant read changed {$table}",
        );
    }

    $route = peanut_route_registry_source(dirname(__DIR__, 2));
    platformTenantReadExpect(
        str_contains($route, "Route::get('tenants'")
            && str_contains($route, "Route::get('tenants/detail'")
            && substr_count($route, "PlatformPermissionMiddleware::class, 'platform.tenant.read'") >= 3,
        'platform Tenant read routes are not guarded by the dedicated permission',
    );

    echo "PM01-PLATFORM-TENANT-READ-001 passed\n";
} finally {
    $admin->exec("DROP DATABASE IF EXISTS `{$database}`");
}
