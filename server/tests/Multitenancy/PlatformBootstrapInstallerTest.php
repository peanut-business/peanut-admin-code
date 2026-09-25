<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/bootstrap/environment.php';

use app\platform\service\PlatformOperatorSessionService;
use PeanutAdmin\Modules\Identity\Auth\Persistence\ThinkPhpPlatformAuthRepository;
use PeanutAdmin\Modules\Identity\Auth\PlatformAuthService;
use PeanutAdmin\Kernel\Auth\SystemClock;
use PeanutAdmin\Kernel\Auth\TokenIssuer;
use PeanutAdmin\Kernel\Authorization\RevisionPermissionCache;
use PeanutAdmin\Kernel\Identity\PasswordHasher;
use PeanutAdmin\Modules\Identity\Platform\Authorization\ThinkPhpPlatformAuthorizationRepository;
use PeanutAdmin\Kernel\Platform\Authorization\PlatformAuthorizationEvaluator;

require dirname(__DIR__, 2) . '/vendor/autoload.php';
require dirname(__DIR__, 2) . '/database/install.php';
require __DIR__ . '/../Support/IsolatedBackendEnvironment.php';
require __DIR__ . '/../Support/ThinkPhpTestConnection.php';

function mt05BootstrapExpect(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function mt05BootstrapAdminConnection(): PDO
{
    $host = IsolatedBackendEnvironment::required('DB_HOST');
    $port = IsolatedBackendEnvironment::required('DB_PORT');
    $user = IsolatedBackendEnvironment::required('DB_USER');
    $password = IsolatedBackendEnvironment::required('DB_PASS');
    return new PDO(
        "mysql:host={$host};port={$port};charset=utf8mb4",
        $user,
        $password,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES => false],
    );
}

function mt05BootstrapDatabase(PDO $admin, string $database): PDO
{
    $admin->exec("CREATE DATABASE `{$database}` CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci");
    $host = IsolatedBackendEnvironment::required('DB_HOST');
    $port = IsolatedBackendEnvironment::required('DB_PORT');
    $user = IsolatedBackendEnvironment::required('DB_USER');
    $password = IsolatedBackendEnvironment::required('DB_PASS');
    return new PDO(
        "mysql:host={$host};port={$port};dbname={$database};charset=utf8mb4",
        $user,
        $password,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
            PDO::MYSQL_ATTR_MULTI_STATEMENTS => true,
        ],
    );
}

/** @param array<string,string> $overrides */
function mt05BootstrapUseDatabase(string $database, array $overrides = []): void
{
    IsolatedBackendEnvironment::activate(array_replace([
        'DB_HOST' => IsolatedBackendEnvironment::required('DB_HOST'),
        'DB_PORT' => IsolatedBackendEnvironment::required('DB_PORT'),
        'DB_NAME' => $database,
        'DB_USER' => IsolatedBackendEnvironment::required('DB_USER'),
        'DB_PASS' => IsolatedBackendEnvironment::required('DB_PASS'),
    ], $overrides));
}

function mt05BootstrapPlatformSessions(PDO $pdo): PlatformOperatorSessionService
{
    ThinkPhpTestConnection::fromPdo($pdo);
    $permissions = new ThinkPhpPlatformAuthorizationRepository();
    return new PlatformOperatorSessionService(
        new PlatformAuthService(
            new ThinkPhpPlatformAuthRepository(),
            new PasswordHasher(),
            new SystemClock(),
            new TokenIssuer(),
            str_repeat('h', 32),
        ),
        new PlatformAuthorizationEvaluator($permissions, new RevisionPermissionCache()),
        $permissions,
    );
}

$serverDir = dirname(__DIR__, 2);
$admin = mt05BootstrapAdminConnection();
$run = strtolower(bin2hex(random_bytes(5)));
$databases = [];
$adminEmail = 'owner+' . $run . '@example.test';
$adminPassword = 'TenantOwner2026';
$platformEmail = 'platform+' . $run . '@example.test';
$platformPassword = 'PlatformOperator2026';

try {
    foreach ([
        'missing_email' => ['', $platformPassword],
        'weak_password' => [$platformEmail, 'weak-password'],
        'same_email' => [$adminEmail, $platformPassword],
    ] as $case => [$candidateEmail, $candidatePassword]) {
        IsolatedBackendEnvironment::activate([
            'DEPLOYMENT_MODE' => 'multi-tenant',
            'PLATFORM_INITIAL_EMAIL' => $candidateEmail,
            'PLATFORM_INITIAL_PASSWORD' => $candidatePassword,
        ]);
        $failed = false;
        try {
            initialPlatformCredentials($serverDir, $adminEmail);
        } catch (RuntimeException $exception) {
            $failed = true;
            mt05BootstrapExpect(
                !str_contains($exception->getMessage(), $platformPassword),
                "{$case} exposed the platform password",
            );
        }
        mt05BootstrapExpect($failed, "{$case} must fail closed");
    }

    $installName = 'pa_mt05_platform_install_' . $run;
    $databases[] = $installName;
    $install = mt05BootstrapDatabase($admin, $installName);
    mt05BootstrapUseDatabase($installName, [
        'ADMIN_INITIAL_EMAIL' => $adminEmail,
        'ADMIN_INITIAL_PASSWORD' => $adminPassword,
        'DEPLOYMENT_MODE' => 'multi-tenant',
        'PLATFORM_INITIAL_EMAIL' => $platformEmail,
        'PLATFORM_INITIAL_PASSWORD' => $platformPassword,
    ]);
    ob_start();
    $installCode = main();
    $installOutput = (string) ob_get_clean();
    mt05BootstrapExpect($installCode === 0, 'multi-tenant fresh installer failed');
    mt05BootstrapExpect(
        !str_contains($installOutput, $adminPassword)
            && !str_contains($installOutput, $platformPassword),
        'installer output exposed a bootstrap password',
    );

    $identity = $install->query(<<<'SQL'
SELECT po.account_id AS platform_account_id, tm.account_id AS owner_account_id,
       a.status AS owner_account_status, tm.status AS owner_member_status,
       c.status AS owner_credential_status, COUNT(DISTINCT r.id) AS owner_role_count
FROM pa_platform_operator po
JOIN pa_account platform_account ON platform_account.id = po.account_id AND platform_account.status = 'active'
JOIN pa_tenant t ON t.code = 'default' AND t.status = 'active'
JOIN pa_tenant_member tm ON tm.tenant_id = t.id AND tm.status = 'active'
JOIN pa_account a ON a.id = tm.account_id AND a.status = 'active'
JOIN pa_credential c ON c.account_id = a.id AND c.status = 'active'
JOIN pa_member_role mr ON mr.tenant_id = tm.tenant_id AND mr.tenant_member_id = tm.id
JOIN pa_role r ON r.tenant_id = mr.tenant_id AND r.id = mr.role_id
  AND r.`key` = 'core.tenant-owner' AND r.is_builtin = 1 AND r.status = 'active'
WHERE po.status = 'active'
GROUP BY po.account_id, tm.account_id, a.status, tm.status, c.status
SQL)->fetch();
    mt05BootstrapExpect(is_array($identity), 'multi-tenant identities missing');
    mt05BootstrapExpect((string) $identity['owner_account_status'] === 'active', 'default owner account is not active');
    mt05BootstrapExpect((string) $identity['owner_member_status'] === 'active', 'default owner member is not active');
    mt05BootstrapExpect((string) $identity['owner_credential_status'] === 'active', 'default owner credential is not active');
    mt05BootstrapExpect((int) $identity['owner_role_count'] === 1, 'default owner role is invalid');
    mt05BootstrapExpect(
        (int) $identity['platform_account_id'] !== (int) $identity['owner_account_id'],
        'platform operator and default owner reused one Account',
    );
    mt05BootstrapExpect(
        (int) $install->query(
            'SELECT COUNT(*) FROM pa_tenant_member WHERE account_id=' . (int) $identity['platform_account_id'],
        )->fetchColumn() === 0,
        'platform operator became a TenantMember',
    );
    $authentication = mt05BootstrapPlatformSessions($install)->login(
        $platformEmail,
        $platformPassword,
        '127.0.0.1',
        'MT05 bootstrap fixture',
        'mt05-platform-login',
    );
    mt05BootstrapExpect(
        str_starts_with($authentication->tokens->access->expose(), 'pa_pat_'),
        'fresh multi-tenant platform operator cannot login',
    );

    echo "MT05-PLATFORM-BOOTSTRAP-001 passed\n";
} finally {
    foreach ($databases as $database) {
        $admin->exec("DROP DATABASE IF EXISTS `{$database}`");
    }
}
