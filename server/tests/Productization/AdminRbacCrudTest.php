<?php

declare(strict_types=1);

use app\adminapi\services\auth\AdminApplicationService;
use app\adminapi\services\auth\MenuApplicationService;
use app\adminapi\services\auth\RoleApplicationService;
use app\adminapi\services\dept\DeptApplicationService;
use app\adminapi\services\dept\JobsApplicationService;
use app\common\exception\BusinessException;
use app\common\execution\AdminExecutionContext;
use app\common\execution\CurrentExecutionContext;
use app\common\execution\ExecutionContextStore;
use app\common\services\authorization\AdminAuthorizationService;
use PeanutAdmin\Kernel\Auth\TenantContext;
use PeanutAdmin\Kernel\Auth\ValidatedTenantSession;
use PeanutAdmin\Kernel\Authorization\Application\AdminAccessException;
use PeanutAdmin\Kernel\Identity\PasswordHasher;
use PeanutAdmin\Kernel\Identity\PasswordPolicy;
use PeanutAdmin\Modules\Identity\Audit\AuditService;
use PeanutAdmin\Modules\Identity\Authorization\Application\RoleAdminService;
use PeanutAdmin\Modules\Identity\Authorization\CorePermissionCatalogSynchronizer;
use PeanutAdmin\Modules\Identity\Authorization\Persistence\ThinkPhpAuthorizationCatalogRepository;
use PeanutAdmin\Modules\Identity\Platform\Bootstrap\BootstrapService;
use think\facade\Db;

require dirname(__DIR__, 2) . '/vendor/autoload.php';
require __DIR__ . '/../Support/RegisteredMysqlTestResource.php';
require __DIR__ . '/../Support/IsolatedBackendEnvironment.php';
require __DIR__ . '/../Support/ThinkPhpTestConnection.php';
require __DIR__ . '/../Support/IdentityMysqlFixture.php';

function expectCrud(bool $condition, string $message): void
{
    if (!$condition) { throw new RuntimeException($message); }
}

function expectCrudFailure(Closure $operation, string $errorCode): void
{
    try {
        $operation();
    } catch (BusinessException|AdminAccessException $exception) {
        expectCrud($exception->errorCode === $errorCode, 'Expected ' . $errorCode . ', received ' . $exception->errorCode);
        return;
    }
    throw new RuntimeException('Operation unexpectedly accepted: ' . $errorCode);
}

$database = RegisteredMysqlTestResource::configuredDatabaseName();
[$pdo, $created] = RegisteredMysqlTestResource::openEmptyDatabase($database);
try {
    IsolatedBackendEnvironment::activateDatabase(
        IsolatedBackendEnvironment::required('DB_HOST'), IsolatedBackendEnvironment::required('DB_PORT'),
        $database, IsolatedBackendEnvironment::required('DB_USER'), IsolatedBackendEnvironment::required('DB_PASS'), 'multi-tenant',
    );
    IdentityMysqlFixture::install($pdo);
    $app = think\Container::getInstance();
    expectCrud($app instanceof think\App, 'Explicit environment did not establish the native App');
    $app->initialize();
    ThinkPhpTestConnection::fromPdo($pdo);
    (new CorePermissionCatalogSynchronizer(new ThinkPhpAuthorizationCatalogRepository()))->synchronize();
    $contexts = $app->make(ExecutionContextStore::class);
    $audit = new AuditService($app->make(CurrentExecutionContext::class));
    $bootstrap = new BootstrapService($audit, new PasswordHasher(), new PasswordPolicy());
    $operator = $bootstrap->bootstrapPlatformOwner('platform@example.test', 'PlatformPassword2026', 'Platform', 'rbac-platform');
    $owner = $bootstrap->provisionTenantOwnerCandidate($operator->operatorId, 'rbac', 'RBAC fixture',
        'owner@example.test', 'OwnerPassword2026', 'Owner', 'rbac-owner');
    $bootstrap->activateTenantOwner($operator->operatorId, $owner->tenantId, $owner->memberId, 'rbac-owner-active');
    $bootstrap->activateTenant($operator->operatorId, $owner->tenantId, 'rbac-tenant-active');
    $authorization = $app->make(AdminAuthorizationService::class);
    // Reload revisions and the server-derived principal after each preceding IAM mutation.
    $run = static function (Closure $operation, ?int $memberId = null) use ($owner, $authorization, $contexts): mixed {
        $memberId ??= $owner->memberId;
        $member = Db::name('tenant_member')->where('tenant_id', $owner->tenantId)->where('id', $memberId)->find();
        $tenantRevision = (int) Db::name('tenant')->where('id', $owner->tenantId)->value('authorization_revision');
        $context = TenantContext::fromValidatedSession(new ValidatedTenantSession(
            $memberId, 'rbac-fixture-' . $memberId, $owner->tenantId, (int) $member['account_id'], $memberId,
            'admin-web', new DateTimeImmutable('now', new DateTimeZone('UTC')),
            (int) $member['authorization_revision'], $tenantRevision,
        ), 'rbac-' . bin2hex(random_bytes(8)));
        $principal = $authorization->principal($context)->toArray();
        return $contexts->run(new AdminExecutionContext($context, 'rbac.crud', $principal), static fn() => $operation($context));
    };
    $admins = $app->make(AdminApplicationService::class);
    $roles = $app->make(RoleApplicationService::class);
    $roleCommands = $app->make(RoleAdminService::class);
    $menus = $app->make(MenuApplicationService::class);
    $departments = $app->make(DeptApplicationService::class);
    $jobs = $app->make(JobsApplicationService::class);

    $parentInput = ['name' => 'RBAC directory', 'type' => 'M', 'parent_key' => null,
        'paths' => '/rbac', 'component' => '', 'perms' => '', 'sort' => 0, 'is_show' => 1, 'is_disable' => 0];
    expectCrud($menus->add($parentInput), 'Parent menu creation failed');
    $parentKey = (string) Db::name('system_menu')->where('name', 'RBAC directory')->value('menu_key');
    $childInput = ['name' => 'RBAC members', 'type' => 'C', 'parent_key' => $parentKey,
        'paths' => '/rbac/members', 'component' => 'rbac/members', 'perms' => 'core.member.read',
        'sort' => 0, 'is_show' => 1, 'is_disable' => 0];
    expectCrud($menus->add($childInput), 'Child menu creation failed');
    $childKey = (string) Db::name('system_menu')->where('name', 'RBAC members')->value('menu_key');
    expectCrudFailure(static fn() => $menus->delete($parentKey), 'ADMIN_MENU_HAS_CHILDREN');
    expectCrudFailure(static fn() => $menus->edit(array_replace($parentInput,
        ['menu_key' => $parentKey, 'parent_key' => $childKey])), 'ADMIN_MENU_PARENT_INVALID');

    $run(static fn(TenantContext $context) => $roles->add($context, ['name' => 'RBAC reader', 'desc' => 'Read only']));
    $roleId = (int) Db::name('role')->where('tenant_id', $owner->tenantId)->where('name', 'RBAC reader')->value('id');
    $run(static fn(TenantContext $context) => $roleCommands->replacePermissions($context, $roleId,
        ['core.member.read'], (int) Db::name('role')->where('id', $roleId)->value('authorization_revision')));
    expectCrudFailure(static fn() => $menus->delete($childKey), 'ADMIN_MENU_IN_USE');
    $run(static fn(TenantContext $context) => $departments->add($context,
        ['name' => 'RBAC department', 'pid' => 0, 'code' => 'rbac.department', 'status' => 1]));
    $departmentId = (int) Db::name('department')->where('tenant_id', $owner->tenantId)->where('code', 'rbac.department')->value('id');
    $run(static fn(TenantContext $context) => $jobs->add($context, ['name' => 'RBAC job', 'code' => 'rbac-job', 'status' => 1]));
    $jobId = (int) Db::name('jobs')->where('tenant_id', $owner->tenantId)->where('code', 'rbac-job')->value('id');
    $memberInput = ['account' => 'reader@example.test', 'name' => 'RBAC reader', 'password' => 'ReaderPassword2026',
        'disable' => 0, 'role_id' => [$roleId], 'dept_id' => [$departmentId], 'jobs_id' => [$jobId]];
    $run(static fn(TenantContext $context) => $admins->add($context, $memberInput));
    $memberId = (int) Db::name('tenant_member')->where('tenant_id', $owner->tenantId)->where('display_name', 'RBAC reader')->value('id');
    expectCrud($memberId > 0, 'Administrator membership creation failed');
    expectCrud(Db::name('member_role')->where('tenant_id', $owner->tenantId)->where('tenant_member_id', $memberId)->where('role_id', $roleId)->count() === 1, 'Member role relation missing');
    expectCrud((int) Db::name('tenant_member')->where('id', $memberId)->value('primary_department_id') === $departmentId, 'Primary department missing');
    expectCrud(Db::name('member_job')->where('tenant_id', $owner->tenantId)->where('tenant_member_id', $memberId)->where('job_id', $jobId)->count() === 1, 'Member job relation missing');

    expectCrudFailure(static fn() => $run(static fn(TenantContext $context) => $admins->delete($context, $owner->memberId, $owner->memberId)), 'ADMIN_SELF_OPERATION_FORBIDDEN');
    expectCrudFailure(static fn() => $run(static fn(TenantContext $context) => $admins->updateStatus($context, $owner->memberId, 1, $owner->memberId)), 'ADMIN_SELF_OPERATION_FORBIDDEN');
    expectCrudFailure(static fn() => $run(static fn(TenantContext $context) => $admins->delete($context, $owner->memberId)), 'LAST_ACTIVE_OWNER_REQUIRED');
    expectCrudFailure(static fn() => $run(static fn(TenantContext $context) => $admins->updateStatus($context, $owner->memberId, 1)), 'LAST_ACTIVE_OWNER_REQUIRED');
    expectCrudFailure(static fn() => $run(static fn(TenantContext $context) => $departments->delete($context, $departmentId)), 'DEPARTMENT_NOT_EMPTY');
    expectCrudFailure(static fn() => $run(static fn(TenantContext $context) => $jobs->delete($context, $jobId)), 'ADMIN_JOB_IN_USE');
    $ownerRoleId = (int) Db::name('role')->where('tenant_id', $owner->tenantId)->where('key', 'core.tenant-owner')->value('id');
    expectCrudFailure(static fn() => $run(static fn(TenantContext $context) => $admins->add($context,
        array_replace($memberInput, ['account' => 'escalated@example.test', 'role_id' => [$ownerRoleId]])), $memberId), 'MEMBER_PERMISSION_DENIED');
    expectCrud(Db::name('credential')->where('identifier_normalized', 'escalated@example.test')->count() === 0, 'Denied escalation created an account credential');

    $run(static fn(TenantContext $context) => $admins->updateStatus($context, $memberId, 1));
    expectCrud(Db::name('tenant_member')->where('id', $memberId)->value('status') === 'suspended', 'Suspension failed');
    $run(static fn(TenantContext $context) => $admins->updateStatus($context, $memberId, 0));
    expectCrud(Db::name('tenant_member')->where('id', $memberId)->value('status') === 'active', 'Reactivation failed');
    $run(static fn(TenantContext $context) => $admins->edit($context,
        ['id' => $memberId, 'name' => 'RBAC edited', 'disable' => 0, 'role_id' => [$roleId], 'dept_id' => [], 'jobs_id' => []]));
    $run(static fn(TenantContext $context) => $departments->delete($context, $departmentId));
    $run(static fn(TenantContext $context) => $jobs->delete($context, $jobId));
    expectCrud(Db::name('department')->where('id', $departmentId)->value('status') === 'archived', 'Department archival failed');
    expectCrud(Db::name('jobs')->where('id', $jobId)->value('delete_time') !== null, 'Job soft deletion failed');
    // IAM archives assigned roles and revokes their grants while retaining membership history.
    $run(static fn(TenantContext $context) => $roles->delete($context, $roleId));
    expectCrud(Db::name('role')->where('id', $roleId)->value('status') === 'archived', 'Role archival failed');
    $run(static function (TenantContext $context) use ($authorization): void {
        expectCrud(!$authorization->decide($context, $authorization->principal($context), 'core.member.read')->allowed,
            'Archived role still granted read access');
    }, $memberId);
    $run(static fn(TenantContext $context) => $admins->delete($context, $memberId));
    expectCrud(Db::name('tenant_member')->where('id', $memberId)->value('status') === 'left', 'Administrator departure failed');
    expectCrud($menus->edit(array_replace($childInput, ['menu_key' => $childKey, 'perms' => ''])), 'Menu binding edit failed');
    expectCrud($menus->delete($childKey) && $menus->delete($parentKey), 'Custom menu deletion failed');
    expectCrud(Db::name('system_menu')->whereIn('menu_key', [$parentKey, $childKey])->count() === 0, 'Custom menu rows remained');
    echo "PB04-AUTH-CRUD-001 current IAM CRUD and rejection contracts passed\n";
} finally {
    RegisteredMysqlTestResource::cleanup($pdo, $database, $created);
}
