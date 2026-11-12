<?php

declare(strict_types=1);

require_once __DIR__ . '/../Support/CiBootstrap.php';
require_once __DIR__ . '/../Support/IdentityMysqlFixture.php';
require_once __DIR__ . '/../Support/RegisteredMysqlTestResource.php';
require_once __DIR__ . '/../Support/ThinkPhpTestConnection.php';
require_once ((getenv('PEANUT_TEST_APPLICATION_ROOT') ?: dirname(__DIR__, 3)) . '/server') . '/app/common.php';

use app\common\execution\CurrentExecutionContext;
use app\common\execution\ExecutionContextStore;
use app\common\infrastructure\authorization\CoreTenantModuleAdminBridge;
use app\common\policy\permission\RegisteredAdminPermissionPolicy;
use app\common\services\authorization\AdminAuthorizationService;
use app\common\services\authorization\EndpointPermissionGrant;
use app\platform\infrastructure\module\ThinkPhpModuleGovernanceProvider;
use PeanutAdmin\Kernel\Auth\TenantContext;
use PeanutAdmin\Kernel\Auth\ValidatedTenantSession;
use PeanutAdmin\Kernel\Menu\MenuDefinition;
use PeanutAdmin\Kernel\Module\CompiledModuleRegistry;
use PeanutAdmin\Kernel\Module\ManifestDocument;
use PeanutAdmin\Modules\Identity\Authorization\ThinkPhpTenantAuthorizationRepository;
use PeanutAdmin\Modules\Identity\Contract\AdminDirectoryQuery;
use PeanutAdmin\Modules\Identity\Contract\TenantAuthorizationQuery;
use PeanutAdmin\Modules\Identity\Membership\Query\ThinkPhpTenantMemberDirectory;
use PeanutAdmin\Modules\Identity\Menu\MenuAdministrationService;
use PeanutAdmin\Modules\Identity\Menu\ThinkPhpMenuCatalogRepository;
use PeanutAdmin\Modules\Identity\Module\TenantEntitlementService;

$rbacAssertions = 0;
function expectRbacTenant(bool $condition, string $message): void
{
    global $rbacAssertions;
    ++$rbacAssertions;
    if (!$condition) { throw new RuntimeException($message); }
}

/** @param list<array<string,mixed>> $menus */
function containsRbacFixtureMenu(array $menus): bool
{
    foreach ($menus as $menu) {
        if (($menu['module_key'] ?? null) === 'fixture.rbac'
            || containsRbacFixtureMenu(is_array($menu['children'] ?? null) ? $menu['children'] : [])) {
            return true;
        }
    }
    return false;
}

function rbacTenantContext(int $tenantId, int $accountId, int $memberId): TenantContext
{
    return TenantContext::fromValidatedSession(new ValidatedTenantSession(
        $memberId, 'rbac-session-' . $memberId, $tenantId, $accountId, $memberId,
        'admin-web', new DateTimeImmutable('2031-01-01T00:00:00Z'), 1, 1,
    ), 'native-rbac-' . $memberId);
}

$serverRoot = ((getenv('PEANUT_TEST_APPLICATION_ROOT') ?: dirname(__DIR__, 3)) . '/server');
$database = RegisteredMysqlTestResource::configuredDatabaseName();
[$pdo, $created] = RegisteredMysqlTestResource::openEmptyDatabase($database);
try {
    IdentityMysqlFixture::install($pdo);
    ThinkPhpTestConnection::fromPdo($pdo);
    $app = think\Container::getInstance();
    $app->config->set(['mode' => 'multi-tenant'], 'deployment');
    // Only Identity's authorization mechanism is exercised; no optional business implementation is loaded.
    $manifest = ManifestDocument::fromObject($serverRoot . '/fixtures/rbac', json_decode(
        '{"schema_version":1,"key":"fixture.rbac","name":"RBAC fixture","version":"1.0.0","tenant":{"enableable":true,"requires":[]}}',
        false, 32, JSON_THROW_ON_ERROR,
    ));
    $registry = new CompiledModuleRegistry([$manifest], [], [], [], hash('sha256', $manifest->digest));
    $entitlements = new TenantEntitlementService($registry);
    $queries = new TenantAuthorizationQuery(new ThinkPhpTenantMemberDirectory(), $registry);
    $current = new CurrentExecutionContext(new ExecutionContextStore());
    $directory = new AdminDirectoryQuery($current);
    $menus = new ThinkPhpMenuCatalogRepository($entitlements);
    $governance = new ThinkPhpModuleGovernanceProvider($serverRoot, [], ThinkPhpTestConnection::moduleCatalogs($pdo), $registry);
    $bridge = new CoreTenantModuleAdminBridge($governance,
        new ThinkPhpTenantAuthorizationRepository($entitlements, $queries), $menus, $queries,
        new MenuAdministrationService(), $entitlements);
    $authorization = new AdminAuthorizationService($bridge, new RegisteredAdminPermissionPolicy(),
        $directory, $queries, new EndpointPermissionGrant(new think\Request()), $current);

    $now = '2020-01-01 00:00:00.000';
    $pdo->exec(<<<SQL
INSERT INTO pa_tenant (id,code,name,display_name,status,created_at,updated_at) VALUES
 (101,'alpha','Alpha','Alpha','active','{$now}','{$now}'),(202,'beta','Beta','Beta','active','{$now}','{$now}');
INSERT INTO pa_account (id,display_name,status,created_at,updated_at) VALUES
 (1001,'Alpha member','active','{$now}','{$now}'),(1002,'Beta member','active','{$now}','{$now}'),
 (1003,'Alpha owner','active','{$now}','{$now}'),(1004,'Beta owner','active','{$now}','{$now}');
INSERT INTO pa_credential (account_id,kind,identifier_type,identifier_normalized,secret_hash,status,verified_at,secret_changed_at,created_at,updated_at) VALUES
 (1001,'email_password','email','alpha@example.test',REPEAT('a',64),'active','{$now}','{$now}','{$now}','{$now}'),
 (1002,'email_password','email','beta@example.test',REPEAT('b',64),'active','{$now}','{$now}','{$now}','{$now}'),
 (1003,'email_password','email','alpha-owner@example.test',REPEAT('c',64),'active','{$now}','{$now}','{$now}','{$now}'),
 (1004,'email_password','email','beta-owner@example.test',REPEAT('d',64),'active','{$now}','{$now}','{$now}','{$now}');
INSERT INTO pa_tenant_member (id,tenant_id,account_id,display_name,status,created_at,updated_at) VALUES
 (501,101,1001,'Alpha member','active','{$now}','{$now}'),(502,202,1002,'Beta member','active','{$now}','{$now}'),
 (503,101,1003,'Alpha owner','active','{$now}','{$now}'),(504,202,1004,'Beta owner','active','{$now}','{$now}');
INSERT INTO pa_role (id,tenant_id,`key`,name,is_builtin,status,created_at,updated_at) VALUES
 (11,101,'alpha.reader','Alpha reader',0,'active','{$now}','{$now}'),(22,202,'beta.reader','Beta reader',0,'active','{$now}','{$now}'),
 (33,101,'core.tenant-owner','Alpha owner',1,'active','{$now}','{$now}'),(44,202,'core.tenant-owner','Beta owner',1,'active','{$now}','{$now}');
INSERT INTO pa_member_role (tenant_id,tenant_member_id,role_id,assigned_at) VALUES
 (101,501,11,'{$now}'),(202,502,22,'{$now}'),(101,503,33,'{$now}'),(202,504,44,'{$now}');
INSERT INTO pa_permission (`key`,module_key,type,name,status,manifest_version,created_at,updated_at) VALUES
 ('fixture.rbac.export','fixture.rbac','api','Fixture export','active','1','{$now}','{$now}'),
 ('fixture.dictionary.read','peanut.admin','api','Application dictionary','active','1','{$now}','{$now}');
INSERT INTO pa_plan (id,code,name,status,revision,created_at,updated_at) VALUES
 (1,'alpha','Alpha','active',1,'{$now}','{$now}'),(2,'beta','Beta','active',1,'{$now}','{$now}');
INSERT INTO pa_plan_module (plan_id,module_key) VALUES (1,'fixture.rbac');
INSERT INTO pa_plan_permission (plan_id,permission_key) VALUES
 (1,'fixture.rbac.export'),(1,'fixture.dictionary.read'),(2,'fixture.dictionary.read');
INSERT INTO pa_tenant_license (tenant_id,plan_id,status,effective_at,revision,created_at,updated_at) VALUES
 (101,1,'active','{$now}',1,'{$now}','{$now}'),(202,2,'active','{$now}',1,'{$now}','{$now}');
SQL);
    $pdo->prepare('INSERT INTO pa_module_installation (module_key,installed_version,manifest_schema_version,manifest_digest,status,created_at,updated_at) VALUES (?,?,?,?,?,?,?)')
        ->execute(['fixture.rbac', '1.0.0', 1, $manifest->digest, 'active', $now, $now]);
    $permissionId = (int) $pdo->query("SELECT id FROM pa_permission WHERE `key`='fixture.rbac.export'")->fetchColumn();
    $applicationPermissionId = (int) $pdo->query("SELECT id FROM pa_permission WHERE `key`='fixture.dictionary.read'")->fetchColumn();
    $grant = $pdo->prepare('INSERT INTO pa_role_permission (tenant_id,role_id,permission_id,granted_at) VALUES (?,?,?,?)');
    $grant->execute([101,11,$permissionId,$now]);
    $grant->execute([101,11,$applicationPermissionId,$now]);
    $menus->synchronize(new MenuDefinition('fixture.rbac.page', 'fixture.rbac', 'tenant', null, 'page',
        'RBAC fixture', 'FixtureRbac', '/fixture-rbac', 'fixture.rbac.page',
        'fixture.rbac.export', ['admin-web']), $manifest->digest);
    $alphaContext = rbacTenantContext(101,1001,501);
    $betaContext = rbacTenantContext(202,1002,502);
    $alphaOwnerContext = rbacTenantContext(101,1003,503);
    $betaOwnerContext = rbacTenantContext(202,1004,504);
    $alpha = $authorization->principal($alphaContext);
    $beta = $authorization->principal($betaContext);
    $alphaOwner = $authorization->principal($alphaOwnerContext);
    $betaOwner = $authorization->principal($betaOwnerContext);
    expectRbacTenant($authorization->decide($alphaContext,$alpha,'fixture.rbac.export')->allowed, 'same-Tenant RBAC permission denied');
    expectRbacTenant($authorization->decide($alphaContext,$alpha,'fixture.dictionary.read')->allowed, 'application-owned permission denied');
    expectRbacTenant(!$authorization->decide($betaContext,$beta,'fixture.dictionary.read')->allowed, 'application-owned permission crossed Tenants');
    expectRbacTenant(!$authorization->decide($betaContext,$alpha,'fixture.rbac.export')->allowed, 'mismatched TenantContext accepted');
    expectRbacTenant(!$authorization->decide(null,$alpha,'fixture.rbac.export')->allowed, 'missing TenantContext accepted');
    // Current owners add Core's fixed permissions, never implicit commercial business grants.
    expectRbacTenant(!$authorization->decide($alphaOwnerContext,$alphaOwner,'fixture.rbac.export')->allowed, 'owner bypassed explicit business grant');
    foreach ([[101,33,$permissionId],[202,44,$permissionId],[202,44,$applicationPermissionId]] as [$tenant,$role,$permission]) {
        $grant->execute([$tenant,$role,$permission,$now]);
    }
    expectRbacTenant($authorization->decide($alphaOwnerContext,$alphaOwner,'fixture.rbac.export')->allowed, 'licensed explicit owner grant denied');
    expectRbacTenant($authorization->decide($betaOwnerContext,$betaOwner,'fixture.dictionary.read')->allowed, 'explicit application owner grant denied');
    expectRbacTenant(!$authorization->decide($alphaOwnerContext,$alphaOwner,'unregistered/read')->allowed, 'owner bypassed registration');
    expectRbacTenant(!$authorization->decide($betaOwnerContext,$betaOwner,'fixture.rbac.export')->allowed, 'owner bypassed license ceiling');
    expectRbacTenant(containsRbacFixtureMenu($authorization->accessData($alphaContext,$alpha)->menu), 'licensed/granted module menu missing');
    expectRbacTenant(!containsRbacFixtureMenu($authorization->accessData($betaContext,$beta)->menu), 'unlicensed module menu crossed Tenants');
    $pdo->exec('DELETE FROM pa_role_permission WHERE tenant_id=101 AND role_id=11');
    expectRbacTenant(!$authorization->decide($alphaContext,$alpha,'fixture.rbac.export')->allowed, 'revoked role grant still authorized');
    expectRbacTenant(!$authorization->decide($alphaContext,$alpha,'fixture.dictionary.read')->allowed, 'revoked application grant still authorized');
    $grant->execute([101,11,$permissionId,$now]);
    $pdo->exec("UPDATE pa_tenant_license SET status='suspended',revision=revision+1 WHERE tenant_id=101");
    expectRbacTenant(!$authorization->decide($alphaOwnerContext,$alphaOwner,'fixture.rbac.export')->allowed, 'owner bypassed license revocation');
    echo 'NATIVE-ADMIN-RBAC-TENANT-ISOLATION-001 passed; assertions=' . $rbacAssertions . PHP_EOL;
} finally {
    RegisteredMysqlTestResource::cleanup($pdo, $database, $created);
}
