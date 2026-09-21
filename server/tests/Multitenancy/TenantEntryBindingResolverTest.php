<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/route/registry_source.php';

use app\modules\official\file\services\storage\StorageService;
use app\modules\official\file\composition\storage\StorageDriverFactory;
use app\common\execution\CurrentExecutionContext;
use app\common\execution\ExecutionContextStore;
use app\common\tenancy\MultiTenantDataScopePolicy;
use PeanutAdmin\Kernel\Context\TenantSystemContext;
use PeanutAdmin\Kernel\Host\ApplicationHostPolicy;
use PeanutAdmin\Kernel\Tenancy\DefaultTenantContextResolver;
use PeanutAdmin\Kernel\Tenancy\TenantEntryBindingResolver;

require dirname(__DIR__, 2) . '/vendor/autoload.php';
require_once dirname(__DIR__) . '/Support/ThinkPhpTestConnection.php';

function entryBindingExpect(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function entryBindingRejects(Closure $operation, string $message): void
{
    try {
        $operation();
    } catch (DomainException) {
        return;
    }
    throw new RuntimeException($message);
}

$pdo = new PDO('sqlite::memory:', null, null, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);
$pdo->exec(<<<'SQL'
CREATE TABLE pa_tenant (
  id INTEGER PRIMARY KEY,
  code TEXT NOT NULL UNIQUE,
  status TEXT NOT NULL
);
CREATE TABLE pa_tenant_entry_binding (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  tenant_id INTEGER NOT NULL,
  host TEXT NOT NULL,
  client_key TEXT NOT NULL,
  status TEXT NOT NULL,
  UNIQUE (host, client_key)
);
CREATE TABLE pa_storage_account (
  id INTEGER PRIMARY KEY, account_key TEXT, driver TEXT, name TEXT,
  credential_ciphertext TEXT, credential_key_version INTEGER, status TEXT
);
CREATE TABLE pa_storage_space (
  id INTEGER PRIMARY KEY, account_id INTEGER, space_key TEXT, name TEXT,
  access_type TEXT, bucket TEXT, region TEXT, endpoint TEXT,
  access_domain TEXT, local_path TEXT, status TEXT
);
CREATE TABLE pa_file_object (
  id INTEGER PRIMARY KEY, file_key TEXT, tenant_id INTEGER, purpose TEXT,
  access_type TEXT, storage_space_id INTEGER, object_key TEXT, disposition TEXT,
  original_name TEXT, media_type TEXT, size_bytes INTEGER, sha256 TEXT,
  status TEXT, created_by_member_id INTEGER, revision INTEGER,
  created_at TEXT, updated_at TEXT, archived_at TEXT
);
INSERT INTO pa_tenant (id,code,status) VALUES
  (101,'alpha','active'),
  (202,'beta','active'),
  (303,'paused','suspended');
INSERT INTO pa_storage_account VALUES (1,'local','local','Local',NULL,1,'active');
INSERT INTO pa_storage_space VALUES (1,1,'local-public','Local public','public',NULL,NULL,NULL,NULL,'public/storage','active');
INSERT INTO pa_file_object VALUES (
  1,'file_aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa',101,'material.image','public',1,
  'tenants/v1/101/material/image/file_aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa.png',
  'inline','alpha.png','image/png',5,'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa',
  'ready',NULL,1,'2030-01-01','2030-01-01',NULL
);
SQL);

$fallbackCalls = 0;
$resolver = new TenantEntryBindingResolver(
    static function (string $actor, string $operation, string $operationId) use (&$fallbackCalls): TenantSystemContext {
        $fallbackCalls++;
        return new TenantSystemContext(999, $actor, $operation, $operationId);
    },
    true,
    new \app\modules\official\identity\tenancy\infrastructure\ThinkPhpTenantEntryBindingLookup(),
);
$multiTenantScope = new MultiTenantDataScopePolicy(
    new CurrentExecutionContext(new ExecutionContextStore()),
);
ThinkPhpTestConnection::fromPdo($pdo);
$storage = new StorageService(
    (new ReflectionClass(StorageDriverFactory::class))->newInstanceWithoutConstructor(),
    $multiTenantScope,
    new DefaultTenantContextResolver(),
    str_repeat('s', 32),
    'https://admin.example.test',
);
$deliverable = static function (int $tenantId, string $fileKey) use ($storage): bool {
    try {
        $storage->accessUrlForTenant($tenantId, $fileKey);
        return true;
    } catch (Throwable) {
        return false;
    }
};
entryBindingExpect(
    $deliverable(101, 'file_aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa'),
    'an active Tenant file was not deliverable',
);
$boundRequest = new class {
    public function host(): string { return 'ALPHA.Example.test:443'; }
};
$unboundRequest = new class {
    public function host(): string { return 'unbound.example.test'; }
};
$platformRequest = new class {
    public function host(): string { return 'platform.example.test'; }
};
$sharedAdminRequest = new class {
    public function host(): string { return 'admin.example.test'; }
};

entryBindingExpect(
    TenantEntryBindingResolver::normalizeHost('Alpha.Example.Test.:443') === 'alpha.example.test',
    'Host normalization changed',
);
entryBindingRejects(
    static fn() => TenantEntryBindingResolver::normalizeHost('tenant@example.test'),
    'Host normalization accepted user-info syntax',
);
entryBindingExpect(
    $resolver->loginTenantCode(
        $unboundRequest,
        TenantEntryBindingResolver::ADMIN_CLIENT,
        'alpha',
    ) === 'alpha',
    'an unconfigured Host rejected an explicit Tenant code',
);
entryBindingExpect(
    $resolver->loginTenantCode(
        $unboundRequest,
        TenantEntryBindingResolver::ADMIN_CLIENT,
        null,
    ) === null,
    'an unconfigured Admin Host invented a Tenant candidate',
);
$fallback = $resolver->system(
    $unboundRequest,
    TenantEntryBindingResolver::MEMBER_CLIENT,
    'member-public-auth',
    'member.login',
    'entry-fallback',
);
entryBindingExpect(
    $fallback->tenantId === 999 && $fallbackCalls === 1,
    'an unconfigured member Host did not use the explicit default fallback',
);

$insert = $pdo->prepare(
    'INSERT INTO pa_tenant_entry_binding (tenant_id,host,client_key,status) VALUES (?,?,?,?)'
);
$insert->execute([101, 'alpha.example.test', TenantEntryBindingResolver::ADMIN_CLIENT, 'active']);
$insert->execute([101, 'alpha.example.test', TenantEntryBindingResolver::MEMBER_CLIENT, 'active']);
$multiTenantHosts = new ApplicationHostPolicy(
    'multi-tenant',
    ['platform.example.test'],
    ['admin.example.test'],
    $resolver,
);
$multiTenantHosts->assertPlatform($platformRequest);
$multiTenantHosts->assertTenantAdmin($sharedAdminRequest);
$multiTenantHosts->assertTenantAdmin($boundRequest);
entryBindingRejects(
    static fn() => $multiTenantHosts->assertPlatform($sharedAdminRequest),
    'the shared Tenant Admin Host reached the Platform control plane',
);
entryBindingRejects(
    static fn() => $multiTenantHosts->assertTenantAdmin($platformRequest),
    'the Platform Host reached the Tenant Admin control plane',
);
entryBindingRejects(
    static fn() => $multiTenantHosts->assertTenantAdmin($unboundRequest),
    'an unknown Host became an implicit shared Tenant Admin entry',
);
(new ApplicationHostPolicy('standalone', [], [], $resolver))->assertTenantAdmin($unboundRequest);
entryBindingExpect(
    $resolver->boundTenantId($boundRequest, TenantEntryBindingResolver::ADMIN_CLIENT) === 101,
    'the bound Admin entry did not expose its continuous Tenant boundary',
);
entryBindingExpect(
    $resolver->boundTenantId($unboundRequest, TenantEntryBindingResolver::ADMIN_CLIENT) === null,
    'an unbound Admin entry invented a continuous Tenant boundary',
);
$resolver->assertTenantAccess($boundRequest, TenantEntryBindingResolver::ADMIN_CLIENT, 101);
entryBindingRejects(
    static fn() => $resolver->assertTenantAccess(
        $boundRequest,
        TenantEntryBindingResolver::ADMIN_CLIENT,
        202,
    ),
    'a bound Admin entry accepted a session for another Tenant',
);
entryBindingExpect(
    $resolver->loginTenantCode(
        $boundRequest,
        TenantEntryBindingResolver::ADMIN_CLIENT,
        null,
    ) === 'alpha',
    'a unique active binding did not limit Admin login',
);
entryBindingExpect(
    $resolver->loginTenantCode(
        $boundRequest,
        TenantEntryBindingResolver::ADMIN_CLIENT,
        'alpha',
    ) === 'alpha',
    'a matching explicit Tenant code was rejected',
);
entryBindingRejects(
    static fn() => $resolver->loginTenantCode(
        $boundRequest,
        TenantEntryBindingResolver::ADMIN_CLIENT,
        'beta',
    ),
    'a conflicting explicit Tenant code bypassed its Host binding',
);
try {
    $insert->execute([202, 'alpha.example.test', TenantEntryBindingResolver::ADMIN_CLIENT, 'active']);
    throw new RuntimeException('database accepted two active owners for one Host/client entry');
} catch (PDOException) {
    // The database, not only the service, owns the one Host/client invariant.
}
$member = $resolver->system(
    $boundRequest,
    TenantEntryBindingResolver::MEMBER_CLIENT,
    'member-public-auth',
    'member.register',
    'entry-bound',
);
entryBindingExpect(
    $member->tenantId === 101 && $fallbackCalls === 1,
    'a bound member entry fell through to the default Tenant',
);

$pdo->exec("UPDATE pa_tenant_entry_binding SET status='disabled' WHERE tenant_id=101 AND client_key='member-api'");
entryBindingRejects(
    static fn() => $resolver->system(
        $boundRequest,
        TenantEntryBindingResolver::MEMBER_CLIENT,
        'member-public-auth',
        'member.login',
        'entry-disabled',
    ),
    'a disabled binding silently used the default Tenant',
);
$pdo->exec("UPDATE pa_tenant_entry_binding SET status='active' WHERE tenant_id=101 AND client_key='member-api'");
$pdo->exec("UPDATE pa_tenant SET status='suspended' WHERE id=101");
entryBindingRejects(
    static fn() => $resolver->loginTenantCode(
        $boundRequest,
        TenantEntryBindingResolver::ADMIN_CLIENT,
        'alpha',
    ),
    'a suspended bound Tenant remained available to Admin login',
);
entryBindingRejects(
    static fn() => $resolver->system(
        $boundRequest,
        TenantEntryBindingResolver::MEMBER_CLIENT,
        'member-public-auth',
        'member.login',
        'entry-suspended',
    ),
    'a suspended bound Tenant silently used the default Tenant',
);
entryBindingExpect($fallbackCalls === 1, 'a configured failure reached the compatibility fallback');
entryBindingExpect(
    !$deliverable(101, 'file_aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa'),
    'a suspended Tenant file remained deliverable',
);
$pdo->exec("UPDATE pa_tenant SET status='active' WHERE id=101");
entryBindingExpect(
    $deliverable(101, 'file_aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa'),
    'reactivation did not restore a still-ready Tenant file',
);
$reactivated = $resolver->system(
    $boundRequest,
    TenantEntryBindingResolver::MEMBER_CLIENT,
    'member-public-auth',
    'member.login',
    'entry-reactivated',
);
entryBindingExpect(
    $reactivated->tenantId === 101,
    'reactivation did not restore the legitimate bound Tenant entry',
);

$sessionController = (string)file_get_contents(
    dirname(__DIR__, 2) . '/app/adminapi/controller/auth/TenantSessionController.php'
);
$sessionApplication = (string)file_get_contents(
    dirname(__DIR__, 2) . '/app/adminapi/application/auth/TenantSessionApplicationService.php'
);
$loginMiddleware = (string)file_get_contents(
    dirname(__DIR__, 2) . '/app/adminapi/http/middleware/LoginMiddleware.php'
);
$storageService = (string)file_get_contents(
    dirname(__DIR__, 2) . '/app/common/services/storage/StorageService.php'
);
$storageController = (string)file_get_contents(
    dirname(__DIR__, 2) . '/app/api/controller/StorageController.php'
);
$routes = peanut_route_registry_source(dirname(__DIR__, 2));
$productionNginx = (string)file_get_contents(dirname(__DIR__, 3) . '/deploy/nginx/peanut-admin.conf');
$developmentNginx = (string)file_get_contents(dirname(__DIR__, 3) . '/deploy/nginx/development.conf');
entryBindingExpect(
    str_contains($sessionController, 'TenantSessionApplicationService')
        && str_contains($sessionApplication, 'TENANT_SWITCH_BOUND_ENTRY')
        && str_contains($sessionApplication, 'assertTenantAccess('),
    'Tenant challenge selection or switch lost the continuous Host boundary',
);
entryBindingExpect(
    str_contains($loginMiddleware, 'assertTenantAccess(')
        && str_contains($loginMiddleware, 'boundTenantId(')
        && str_contains($loginMiddleware, 'new \\app\\common\\execution\\AdminExecutionContext('),
    'Admin session requests lost the continuous Host boundary',
);
entryBindingExpect(
    str_contains($routes, "Route::get('storage/delivery'")
        && !str_contains($routes, "Route::get('storage/private'")
        && str_contains($storageService, 'deliverableObjectForTenant')
        && str_contains($storageController, "'Cache-Control' => 'no-store, private'"),
    'Tenant file delivery did not converge on the active-Tenant service boundary',
);
foreach ([$productionNginx, $developmentNginx] as $nginx) {
    entryBindingExpect(
        preg_match('#location \^~ /storage/ \{\s*return 404;\s*\}#s', $nginx) === 1,
        'a direct /storage/ proxy or static path bypasses Tenant state',
    );
}

$schema = (string)file_get_contents(
    dirname(__DIR__, 2) . '/database/init.sql'
);
foreach (['pa_tenant_entry_binding', '`tenant_id`', '`host`', '`client_key`', 'fk_tenant_entry_binding_tenant'] as $token) {
    entryBindingExpect(str_contains($schema, $token), 'Tenant entry schema lost contract token: ' . $token);
}
entryBindingExpect(
    str_contains($schema, 'UNIQUE KEY `uk_tenant_entry_binding` (`host`, `client_key`)'),
    'Tenant entry schema lost the one Host/client invariant',
);
entryBindingExpect(
    str_contains($schema, "CHECK (`status` IN ('active', 'disabled'))"),
    'Tenant entry schema lost its binding status constraint',
);

echo "MT07-TENANT-ENTRY-BINDING-001 passed\n";
