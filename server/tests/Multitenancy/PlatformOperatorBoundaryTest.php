<?php

declare(strict_types=1);

require_once __DIR__ . '/../Support/CiBootstrap.php';

require_once ((getenv('PEANUT_TEST_APPLICATION_ROOT') ?: dirname(__DIR__, 3)) . '/server') . '/route/registry_source.php';

use app\platform\context\PlatformOperatorContext;
use app\platform\services\PlatformOperatorSessionService;
use app\platform\services\PlatformApiAuthorization;
use app\common\services\authorization\EndpointPermissionGrant;
use PeanutAdmin\Modules\Identity\Auth\Persistence\ThinkPhpPlatformAuthRepository;
use PeanutAdmin\Modules\Identity\Auth\PlatformAuthService;
use PeanutAdmin\Kernel\Auth\SystemClock;
use PeanutAdmin\Kernel\Auth\TokenIssuer;
use PeanutAdmin\Kernel\Auth\ValidatedPlatformSession;
use PeanutAdmin\Modules\Identity\Authorization\CorePermissionCatalogSynchronizer;
use PeanutAdmin\Modules\Identity\Authorization\Persistence\ThinkPhpAuthorizationCatalogRepository;
use PeanutAdmin\Modules\Identity\Audit\AuditService;
use PeanutAdmin\Kernel\Authorization\RevisionPermissionCache;
use PeanutAdmin\Kernel\Context\PlatformContext;
use PeanutAdmin\Kernel\Identity\PasswordHasher;
use PeanutAdmin\Kernel\Identity\PasswordPolicy;
use PeanutAdmin\Modules\Identity\Platform\Authorization\ThinkPhpPlatformAuthorizationRepository;
use PeanutAdmin\Kernel\Platform\Authorization\PlatformAuthorizationEvaluator;
use PeanutAdmin\Modules\Identity\Platform\Bootstrap\BootstrapService;
use PeanutAdmin\Modules\Identity\Platform\Application\PlanLicenseAdminService;
use PeanutAdmin\Modules\Identity\Module\TenantEntitlementService;
use PeanutAdmin\Kernel\Module\CompiledModuleRegistry;
use app\common\execution\CurrentExecutionContext;
use app\common\execution\ExecutionContextStore;
use app\common\http\ApiProblem;
use app\platform\controller\PlanLicenseController;
use app\platform\http\middleware\PlatformLoginMiddleware;
use app\platform\http\middleware\PlatformPermissionMiddleware;
use PeanutAdmin\Kernel\Host\ApplicationHostPolicy;
use PeanutAdmin\Kernel\Tenancy\TenantEntryBindingResolver;
use PeanutAdmin\Modules\Identity\Authorization\ApiPermissionCatalog;
use PeanutAdmin\Modules\Identity\Module\ModuleAvailabilityService;
use PeanutAdmin\Modules\Identity\Policy\DemoAccountPolicy;

require ((getenv('PEANUT_TEST_APPLICATION_ROOT') ?: dirname(__DIR__, 3)) . '/server') . '/vendor/autoload.php';
require __DIR__ . '/../Support/IsolatedBackendEnvironment.php';
require_once __DIR__ . '/../Support/ThinkPhpTestConnection.php';
require __DIR__ . '/../Support/IdentityMysqlFixture.php';
require_once __DIR__ . '/../Support/RegisteredMysqlTestResource.php';

$cache = require ((getenv('PEANUT_TEST_APPLICATION_ROOT') ?: dirname(__DIR__, 3)) . '/server') . '/config/cache.php';
$cache['stores']['file']['path'] = (getenv('PEANUT_TEST_APPLICATION_ROOT') ?: dirname(__DIR__, 3)) . '/.local/tmp/058-PlatformOperatorBoundaryTest-cache-' . bin2hex(random_bytes(6)) . '/';
think\Container::getInstance()->config->set($cache, 'cache');

function poExpect(bool $condition, string $message): void
{
    $GLOBALS['poExpectAssertions'] = ($GLOBALS['poExpectAssertions'] ?? 0) + 1;
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
    return new BootstrapService(new AuditService(), new PasswordHasher(), new PasswordPolicy());
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
            str_repeat('p', 32),
        ),
        new PlatformApiAuthorization(new PlatformAuthorizationEvaluator($repository, new RevisionPermissionCache()), new EndpointPermissionGrant(new think\Request())),
        $repository,
    );
}

$database = RegisteredMysqlTestResource::configuredDatabaseName();
[$pdo, $createdDatabase] = RegisteredMysqlTestResource::openEmptyDatabase($database);

try {
    IdentityMysqlFixture::install($pdo);
    $pdo->exec('CREATE TABLE pa_admin_session (id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, token VARCHAR(128) NOT NULL, PRIMARY KEY (id)) ENGINE=InnoDB');
    ThinkPhpTestConnection::fromPdo($pdo);
    (new CorePermissionCatalogSynchronizer(new ThinkPhpAuthorizationCatalogRepository()))->synchronize();

    $bootstrap = poBootstrap();
    $platform = $bootstrap->bootstrapPlatformOwner(
        'platform-only@example.test',
        'PlatformPassword2026',
        'Platform Only',
        'pm01-operator-bootstrap',
    );
    $sessions = poSessions();
    $authentication = $sessions->login(
        'platform-only@example.test',
        'PlatformPassword2026',
        '127.0.0.1',
        'PM01 fixture',
        'pm01-operator-login',
    );
    $access = $authentication->tokens->access->expose();
    poExpect(str_starts_with($access, 'pa_pat_'), 'platform access token audience prefix is wrong');
    poExpect((int) $pdo->query('SELECT COUNT(*) FROM pa_admin_session')->fetchColumn() === 0, 'platform login reused admin session storage');
    poExpect((int) $pdo->query('SELECT COUNT(*) FROM pa_platform_session')->fetchColumn() === 1, 'platform session was not persisted independently');
    poExpect((int) $pdo->query('SELECT COUNT(*) FROM pa_tenant_member WHERE account_id=' . $platform->accountId)->fetchColumn() === 0, 'platform operator became a TenantMember');

    $context = $sessions->context($access, 'pm01-context');
    poExpect($context->core->operatorId === $platform->operatorId, 'validated platform context resolved the wrong operator');
    poRejected(
        static fn() => $sessions->context('pa_tat_' . str_repeat('a', 43), 'pm01-forged-tenant-token'),
        'Credential cannot be used for this entry.',
    );
    $wrongClientContext = PlatformContext::fromValidatedSession(
        new ValidatedPlatformSession(999, 'fixture-session', 999, 999, 'admin-web', new DateTimeImmutable()),
        'pm01-wrong-client',
    );
    poRejected(
        static fn() => PlatformOperatorContext::fromValidatedPlatformSession($wrongClientContext),
        'PLATFORM_OPERATOR_CONTEXT_UNTRUSTED',
    );

    $identity = $sessions->context($access, 'pm01-identity')->identity();
    poExpect($identity->operatorId === $platform->operatorId, 'governance identity port did not use the platform session');
    $sessions->assertAllowed($context, 'platform.tenant.read');
    poRejected(static fn() => $sessions->assertAllowed($context, 'core.member.read'), 'AUTHZ_PERMISSION_DENIED');

    // A non-builtin role receives only explicit platform permissions; ordinary admin tables are irrelevant.
    $now = '2026-08-12 12:00:00.000';
    $pdo->exec("INSERT INTO pa_account (display_name,status,created_at,updated_at) VALUES ('Scoped Operator','active','{$now}','{$now}')");
    $scopedAccountId = (int) $pdo->lastInsertId();
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
    $scopedOperatorId = (int) $pdo->lastInsertId();
    $pdo->exec("INSERT INTO pa_platform_role (`key`,name,is_builtin,status,created_at,updated_at) VALUES ('platform.scoped-reader','Scoped Reader',0,'active','{$now}','{$now}')");
    $roleId = (int) $pdo->lastInsertId();
    $permissionId = (int) $pdo->query("SELECT id FROM pa_permission WHERE `key`='platform.tenant.read'")->fetchColumn();
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
        'pm01-dual-identity-membership',
    );
    poExpect($candidate->memberId > 0, 'membership boundary fixture was not established');
    $dualIdentityContext = $sessions->context(
        $scoped->tokens->access->expose(),
        'pm01-membership-recheck',
    );
    poExpect(
        $dualIdentityContext->core->operatorId === $scopedOperatorId,
        'TenantMember identity changed the validated platform operator',
    );
    $sessions->assertAllowed($dualIdentityContext, 'platform.tenant.read');
    poRejected(
        static fn() => $sessions->assertAllowed($dualIdentityContext, 'core.member.read'),
        'AUTHZ_PERMISSION_DENIED',
    );
    poExpect(
        $pdo->query("SELECT status FROM pa_platform_session WHERE platform_operator_id={$scopedOperatorId}")->fetchColumn() === 'active',
        'TenantMember identity revoked the independent platform session',
    );

    // Shared plans/licenses replace the retired per-tenant module mutation model.
    $registry = new CompiledModuleRegistry([], [], [], [], 'platform-plan-license-boundary');
    $entitlements = new TenantEntitlementService($registry);
    $plans = new PlanLicenseAdminService($registry, $entitlements, new AuditService());
    $app = think\Container::getInstance();
    $contexts = new ExecutionContextStore();
    $current = new CurrentExecutionContext($contexts);
    $app->instance(CurrentExecutionContext::class, $current);
    $app->instance(PlanLicenseAdminService::class, $plans);
    $app->config->set(require ((getenv('PEANUT_TEST_APPLICATION_ROOT') ?: dirname(__DIR__, 3)) . '/server') . '/config/route.php', 'route');
    $apis = new ApiPermissionCatalog();
    $apis->synchronize(json_decode((string) file_get_contents(((getenv('PEANUT_TEST_APPLICATION_ROOT') ?: dirname(__DIR__, 3)) . '/server') . '/generated/api-catalog.json'), true, 512, JSON_THROW_ON_ERROR), [], false);
    $loginMiddleware = new PlatformLoginMiddleware($contexts, $current,
        new ApplicationHostPolicy('multi-tenant', ['platform-boundary.example.test'], new TenantEntryBindingResolver()), $sessions);
    $writeHttp = static function (string $method, string $path, string $action, string $permission, array $input, string $requestId) use (
        $app, $contexts, $current, $sessions, $access, $apis, $registry, $entitlements, $loginMiddleware,
    ): array {
        $request = (new think\Request())->withHeader([
            'authorization' => 'Bearer ' . $access, 'x-request-id' => $requestId,
            'content-type' => 'application/json',
        ])->withInput(json_encode($input, JSON_THROW_ON_ERROR));
        $request->setMethod($method)->setPathinfo($path)->setHost('platform-boundary.example.test');
        $route = new think\Route($app);
        $request->setRule($route->rule($path, [PlanLicenseController::class, $action], $method));
        $app->instance(think\Request::class, $request);
        $permissions = new PlatformPermissionMiddleware($current, $sessions, new DemoAccountPolicy(false, []),
            $apis, new EndpointPermissionGrant($request), new ModuleAvailabilityService($registry, $entitlements));
        try {
            $response = $loginMiddleware->handle($request,
                static fn($authenticated) => $permissions->handle($authenticated,
                    static fn() => (new PlanLicenseController($app))->{$action}(), $permission));
            $body = $response->getData();
            poExpect($body['code'] === 20000 && is_array($body['data']), 'plan/license HTTP action did not return its normal response');
            return $body['data'];
        } finally {
            poExpect($contexts->isEmpty(), 'plan/license HTTP context was not restored');
        }
    };
    $plan = $writeHttp('POST', 'plans', 'create', 'platform.plan.manage', [
        'code' => 'boundary-plan', 'name' => 'Boundary plan', 'module_keys' => [],
        'permission_keys' => [], 'change_reason' => 'Platform boundary fixture',
    ], 'pm01-entitlement-plan-write');
    poExpect($plan['revision'] === 1 && $plan['module_keys'] === [] && $plan['permission_keys'] === [], 'plan write did not preserve explicit selections');
    $licenseInput = [
        'tenant_id' => $candidate->tenantId, 'plan_id' => $plan['id'], 'status' => 'active',
        'effective_at' => '2020-01-01T00:00:00Z', 'expires_at' => null,
        'expected_revision' => 0, 'change_reason' => 'Platform boundary fixture',
    ];
    $license = $writeHttp('PUT', 'tenants/license', 'updateLicense', 'platform.tenant.license.manage', $licenseInput, 'pm01-entitlement-license-write');
    poExpect((int) $license['license']['revision'] === 1 && (int) $license['license']['plan_id'] === $plan['id'], 'tenant license did not use the selected plan/revision');
    $audits = $pdo->query("SELECT event_type,action,operator_id,account_id,target_type,target_id,after_json FROM pa_platform_audit_event WHERE request_id IN ('pm01-entitlement-plan-write','pm01-entitlement-license-write') ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);
    poExpect(array_column($audits, 'event_type') === ['plan.saved', 'tenant.license.saved'], 'plan/license success audit events are incomplete');
    poExpect(array_column($audits, 'action') === ['platform.plan.manage', 'platform.tenant.license.manage'], 'plan/license audit actions changed');
    foreach ($audits as $audit) {
        poExpect((int) $audit['operator_id'] === $platform->operatorId && (int) $audit['account_id'] === $platform->accountId, 'plan/license audit lost the platform actor');
        poExpect(is_array(json_decode($audit['after_json'], true, 512, JSON_THROW_ON_ERROR)), 'plan/license audit omitted the persisted result');
    }
    $entitlementSnapshot = static function () use ($pdo): array {
        return [
            $pdo->query('SELECT * FROM pa_plan ORDER BY id')->fetchAll(PDO::FETCH_ASSOC),
            $pdo->query('SELECT * FROM pa_plan_module ORDER BY plan_id,module_key')->fetchAll(PDO::FETCH_ASSOC),
            $pdo->query('SELECT * FROM pa_plan_permission ORDER BY plan_id,permission_key')->fetchAll(PDO::FETCH_ASSOC),
            $pdo->query('SELECT * FROM pa_tenant_license ORDER BY id')->fetchAll(PDO::FETCH_ASSOC),
            $pdo->query('SELECT id,revision,authorization_revision FROM pa_tenant ORDER BY id')->fetchAll(PDO::FETCH_ASSOC),
            $pdo->query('SELECT * FROM pa_platform_audit_event ORDER BY id')->fetchAll(PDO::FETCH_ASSOC),
            $pdo->query('SELECT * FROM pa_tenant_audit_event ORDER BY id')->fetchAll(PDO::FETCH_ASSOC),
        ];
    };
    $updatePlan = [
        'id' => $plan['id'], 'expected_revision' => 1, 'name' => 'Changed boundary plan',
        'status' => 'active', 'module_keys' => [], 'permission_keys' => [],
        'change_reason' => 'Platform boundary fixture update',
    ];
    $updateLicense = array_replace($licenseInput, ['expected_revision' => 1, 'status' => 'suspended']);
    $beforeRollback = $entitlementSnapshot();
    $pdo->exec("ALTER TABLE pa_platform_audit_event ADD CONSTRAINT chk_entitlement_audit_failure CHECK (request_id <> 'pm01-entitlement-rollback')");
    try {
        poRejected(static fn() => $writeHttp('PUT', 'plans', 'update', 'platform.plan.manage', $updatePlan, 'pm01-entitlement-rollback'), 'chk_entitlement_audit_failure');
        poExpect($entitlementSnapshot() === $beforeRollback, 'failed plan audit did not roll back plan/details/tenant revision');
        poRejected(static fn() => $writeHttp('PUT', 'tenants/license', 'updateLicense', 'platform.tenant.license.manage', $updateLicense, 'pm01-entitlement-rollback'), 'chk_entitlement_audit_failure');
        poExpect($entitlementSnapshot() === $beforeRollback, 'failed license audit did not roll back license/tenant revision');
    } finally {
        $pdo->exec('ALTER TABLE pa_platform_audit_event DROP CHECK chk_entitlement_audit_failure');
    }

    // The external write boundary is the production login/permission/controller chain.
    $pdo->exec("DELETE assignment FROM pa_platform_operator_role assignment JOIN pa_platform_role role ON role.id=assignment.platform_role_id WHERE assignment.platform_operator_id={$platform->operatorId} AND role.`key`='platform.bootstrap-owner'");
    poRejected(static fn() => $sessions->assertAllowed($context, 'platform.plan.manage'), 'AUTHZ_PERMISSION_DENIED');
    poRejected(static fn() => $sessions->assertAllowed($context, 'platform.tenant.license.manage'), 'AUTHZ_PERMISSION_DENIED');
    $beforeRevokedWrites = $entitlementSnapshot();
    foreach ([
        ['plans', 'update', 'platform.plan.manage', $updatePlan],
        ['tenants/license', 'updateLicense', 'platform.tenant.license.manage', $updateLicense],
    ] as [$path, $action, $permission, $input]) {
        $denied = false;
        try {
            $writeHttp('PUT', $path, $action, $permission, $input, 'pm01-entitlement-revoked');
        } catch (ApiProblem $exception) {
            $denied = $exception->httpStatus === 403 && $exception->getMessage() === 'Platform permission is required.';
        }
        poExpect($denied, 'revoked platform role did not receive a permission denial at the HTTP write boundary');
        poExpect($entitlementSnapshot() === $beforeRevokedWrites, 'revoked platform role mutated plan/license/tenant state or audit');
    }

    $routeSource = peanut_route_registry_source(((getenv('PEANUT_TEST_APPLICATION_ROOT') ?: dirname(__DIR__, 3)) . '/server'));
    foreach ([
        ['post', 'plans', 'create', 'platform.plan.manage'],
        ['put', 'plans', 'update', 'platform.plan.manage'],
        ['put', 'tenants/license', 'updateLicense', 'platform.tenant.license.manage'],
    ] as [$method, $path, $action, $permission]) {
        $declaration = "Route::{$method}('{$path}', [\\app\\platform\\controller\\PlanLicenseController::class, '{$action}'])";
        $pattern = '~' . preg_quote($declaration, '~') . "\\s*->completeMatch\\(\\)->middleware\\(PlatformLoginMiddleware::class\\)\\s*"
            . "->middleware\\(PlatformPermissionMiddleware::class, '" . preg_quote($permission, '~') . "'\\);~";
        poExpect(preg_match($pattern, $routeSource) === 1, 'plan/license write route lost its production login/permission chain');
    }
    poExpect(is_string($routeSource) && str_contains($routeSource, "Route::post('session/login'"), 'independent platform route prefix is missing');
    poExpect(str_contains($routeSource, 'PlatformLoginMiddleware::class'), 'platform routes lack their dedicated login guard');
    poExpect(str_contains($routeSource, "'platform.tenant.read'"), 'platform tenant route lacks explicit RBAC');

    echo "PM01-PLATFORM-OPERATOR-BOUNDARY-001 passed; assertions={$GLOBALS['poExpectAssertions']}\n";
} finally {
    RegisteredMysqlTestResource::cleanup($pdo, $database, $createdDatabase);
}
