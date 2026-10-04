<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/route/registry_source.php';

require dirname(__DIR__, 2) . '/bootstrap/environment.php';

use app\platform\service\PlatformOperatorSessionService;
use PeanutAdmin\Modules\Identity\Audit\AuditService;
use PeanutAdmin\Modules\Identity\Auth\Persistence\ThinkPhpPlatformAuthRepository;
use PeanutAdmin\Modules\Identity\Auth\PlatformAuthService;
use PeanutAdmin\Kernel\Auth\SystemClock;
use PeanutAdmin\Kernel\Auth\TokenIssuer;
use PeanutAdmin\Kernel\Auth\ValidatedPlatformSession;
use PeanutAdmin\Modules\Identity\Authorization\CorePermissionCatalogSynchronizer;
use PeanutAdmin\Modules\Identity\Authorization\Persistence\ThinkPhpAuthorizationCatalogRepository;
use PeanutAdmin\Modules\Identity\Authorization\Persistence\Schema\AuthorizationSchema;
use PeanutAdmin\Kernel\Authorization\RevisionPermissionCache;
use PeanutAdmin\Kernel\Identity\PasswordHasher;
use PeanutAdmin\Kernel\Migration\ModuleSchema;
use PeanutAdmin\Kernel\Persistence\Schema\KernelSchema;
use PeanutAdmin\Kernel\Context\PlatformContext;
use PeanutAdmin\Modules\Identity\Platform\Application\PlatformAccessAdminService;
use PeanutAdmin\Modules\Identity\Platform\Authorization\ThinkPhpPlatformAuthorizationRepository;
use PeanutAdmin\Kernel\Platform\Authorization\PlatformAuthorizationEvaluator;
use PeanutAdmin\Modules\Identity\Platform\Bootstrap\BootstrapService;

require dirname(__DIR__, 2) . '/vendor/autoload.php';
require __DIR__ . '/../Support/IsolatedBackendEnvironment.php';
require __DIR__ . '/../Support/ThinkPhpTestConnection.php';

function platformAccessHttpExpect(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function platformAccessHttpSessions(): PlatformOperatorSessionService
{
    $permissions = new ThinkPhpPlatformAuthorizationRepository();
    return new PlatformOperatorSessionService(
        new PlatformAuthService(
            new ThinkPhpPlatformAuthRepository(),
            new PasswordHasher(),
            new SystemClock(),
            new TokenIssuer(),
            str_repeat('a', 32),
        ),
        new PlatformAuthorizationEvaluator($permissions, new RevisionPermissionCache()),
        $permissions,
    );
}

$serverRoot = dirname(__DIR__, 2);
$routes = peanut_route_registry_source($serverRoot);
$composition = (string) file_get_contents($serverRoot . '/app/AppService.php');
$controller = (string) file_get_contents($serverRoot . '/app/platform/controller/PlatformAccessController.php');
$problemMapper = (string) file_get_contents($serverRoot . '/app/common/http/ApiProblemMapper.php');

$expectedRoutes = [
    'operators/create' => ['createOperator', 'platform.operator.create'],
    'operators/update' => ['updateOperator', 'platform.operator.update'],
    'operators/roles/replace' => ['replaceOperatorRoles', 'platform.operator.role.assign'],
    'operators/activate' => ['activateOperator', 'platform.operator.lifecycle'],
    'operators/suspend' => ['suspendOperator', 'platform.operator.lifecycle'],
    'operators/close' => ['closeOperator', 'platform.operator.lifecycle'],
    'roles/create' => ['createRole', 'platform.role.create'],
    'roles/update' => ['updateRole', 'platform.role.update'],
    'roles/archive' => ['archiveRole', 'platform.role.archive'],
    'roles/permissions/replace' => ['replaceRolePermissions', 'platform.role.permission.assign'],
];
foreach ($expectedRoutes as $path => [$action, $permission]) {
    $pattern = sprintf(
        "~Route::post\\('%s', \\[PlatformAccessController::class, '%s'\\]\\)\\s*"
        . "->middleware\\(PlatformLoginMiddleware::class\\)\\s*"
        . "->middleware\\(PlatformPermissionMiddleware::class, '%s'\\);~",
        preg_quote($path, '~'),
        preg_quote($action, '~'),
        preg_quote($permission, '~'),
    );
    platformAccessHttpExpect(
        preg_match($pattern, $routes) === 1,
        "{$path} lost its exact action or platform permission wiring",
    );
    platformAccessHttpExpect(
        str_contains($controller, "public function {$action}()"),
        "{$action} controller mutation is missing",
    );
}
platformAccessHttpExpect(
    !str_contains($composition, 'PlatformRuntimeFactory')
        && str_contains($composition, 'bind(PlatformAuthService::class')
        && !str_contains($composition, 'TransactionManager::class')
        && str_contains($composition, 'ThinkPhpPlatformAuthRepository')
        && str_contains($composition, 'bind(PasswordHasher::class')
        && str_contains($composition, 'ApplicationPasswordPolicy::hasher()'),
    'PlatformAccessAdminService is not using native constructor injection',
);
platformAccessHttpExpect(
    str_contains($controller, '$this->platformContext->core')
        && str_contains($controller, '$context->operatorId')
        && str_contains($controller, '$context->accountId')
        && str_contains($controller, '$context->requestId')
        && !str_contains($controller, 'catch (')
        && str_contains($problemMapper, '$exception instanceof AdminAccessException')
        && str_contains($problemMapper, '$exception->httpStatus'),
    'controller lost trusted actor context or stable AdminAccessException mapping',
);

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
$database = 'pa_pm01_access_http_' . strtolower(bin2hex(random_bytes(6)));
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

    $bootstrap = new BootstrapService(passwords: new PasswordHasher());
    $owner = $bootstrap->bootstrapPlatformOwner(
        'access-owner@example.test',
        'AccessOwnerPassword2026',
        'Access Owner',
        'pm01-access-http-bootstrap',
    );
    $created = (new PlatformAccessAdminService(new AuditService()))->createOperator(
        PlatformContext::fromValidatedSession(new ValidatedPlatformSession(
            $owner->operatorId,
            'pm01-access-http-owner-session',
            $owner->operatorId,
            $owner->accountId,
            'platform-web',
            new DateTimeImmutable('+1 hour'),
        ), 'pm01-access-http-create'),
        'access-scoped@example.test',
        'Access Scoped',
        'AccessScopedPassword2026',
    );
    platformAccessHttpExpect($created['status'] === 'active', 'real operator create did not produce an active operator');

    $sessions = platformAccessHttpSessions();
    $login = $sessions->login(
        'access-scoped@example.test',
        'AccessScopedPassword2026',
        '127.0.0.2',
        'PM01 access HTTP fixture',
        'pm01-access-http-login',
    );
    $context = $sessions->context($login->tokens->access->expose(), 'pm01-access-http-context');
    try {
        $sessions->assertAllowed($context, 'platform.operator.create');
        throw new RuntimeException('operator without platform.operator.create unexpectedly passed authorization');
    } catch (Throwable $exception) {
        platformAccessHttpExpect(
            str_contains($exception->getMessage(), 'AUTHZ_PERMISSION_DENIED'),
            'permission denial changed shape',
        );
    }

    echo "PM01-PLATFORM-ACCESS-HTTP-WIRING-001 passed\n";
} finally {
    $admin->exec("DROP DATABASE IF EXISTS `{$database}`");
}
