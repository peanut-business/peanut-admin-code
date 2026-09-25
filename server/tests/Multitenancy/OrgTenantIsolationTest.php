<?php

declare(strict_types=1);

use app\adminapi\services\auth\AdminApplicationService;
use app\adminapi\services\auth\RoleApplicationService;
use app\adminapi\services\dept\DeptApplicationService;
use app\adminapi\services\dept\JobsApplicationService;
use app\common\execution\CurrentExecutionContext;
use app\common\execution\ExecutionContextStore;
use PeanutAdmin\Kernel\Migration\ModuleSchema;
use PeanutAdmin\Kernel\Persistence\Schema\KernelSchema;
use PeanutAdmin\Kernel\Auth\TenantContext;
use PeanutAdmin\Kernel\Auth\ValidatedTenantSession;

require dirname(__DIR__, 2) . '/vendor/autoload.php';
require __DIR__ . '/../Support/IsolatedBackendEnvironment.php';

function expectOrgTenant(bool $condition, string $message): void
{
    $GLOBALS['orgAssertions'] = ($GLOBALS['orgAssertions'] ?? 0) + 1;
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function orgFailure(callable $operation): array
{
    try {
        $operation();
    } catch (Throwable $exception) {
        return [property_exists($exception, 'errorCode') ? $exception->errorCode : null, $exception->getMessage()];
    }
    throw new RuntimeException('expected organization operation to fail');
}

function orgTenantContext(int $tenantId, int $memberId, string $requestId): TenantContext
{
    $accountId = match ($tenantId) {
        101 => 1501,
        202 => 1502,
        default => throw new RuntimeException('organization fixture account is missing'),
    };
    return TenantContext::fromValidatedSession(new ValidatedTenantSession(
        $memberId,
        'org-session-' . $tenantId . '-' . $memberId,
        $tenantId,
        $accountId,
        $memberId,
        'admin-web',
        new DateTimeImmutable('2031-01-01T00:00:00Z'),
        1,
    ), $requestId);
}

function createOrgTenantSchema(PDO $pdo): void
{
    foreach (KernelSchema::tableNames() as $table) {
        $pdo->exec(KernelSchema::createSql($table));
    }
    $pdo->exec(KernelSchema::addTenantMemberDepartmentForeignKeySql());
    foreach (ModuleSchema::tableNames() as $table) {
        $pdo->exec(ModuleSchema::createSql($table));
    }
    $pdo->exec(<<<'SQL'
CREATE TABLE pa_jobs (
 id INT UNSIGNED NOT NULL AUTO_INCREMENT, tenant_id BIGINT UNSIGNED NOT NULL, name VARCHAR(50) NOT NULL DEFAULT '',
 code VARCHAR(64) NOT NULL DEFAULT '', sort SMALLINT NOT NULL DEFAULT 0, is_disable TINYINT NOT NULL DEFAULT 0,
 status TINYINT NOT NULL DEFAULT 1, remark VARCHAR(200) NOT NULL DEFAULT '',
 create_time INT UNSIGNED NOT NULL DEFAULT 0, update_time INT UNSIGNED NOT NULL DEFAULT 0, delete_time INT UNSIGNED NULL,
 PRIMARY KEY (id), KEY idx_jobs_tenant (tenant_id), UNIQUE KEY uk_jobs_tenant_code (tenant_id, code)
) ENGINE=InnoDB;
CREATE TABLE pa_system_menu (
 id INT UNSIGNED NOT NULL, pid INT UNSIGNED NOT NULL DEFAULT 0, type CHAR(1) NOT NULL DEFAULT 'C',
 name VARCHAR(50) NOT NULL DEFAULT '', icon VARCHAR(100) NOT NULL DEFAULT '', sort SMALLINT NOT NULL DEFAULT 0,
 perms VARCHAR(100) NOT NULL DEFAULT '', paths VARCHAR(200) NOT NULL DEFAULT '', component VARCHAR(200) NOT NULL DEFAULT '',
 is_cache TINYINT NOT NULL DEFAULT 0, is_show TINYINT NOT NULL DEFAULT 1, is_disable TINYINT NOT NULL DEFAULT 0,
 PRIMARY KEY (id)
) ENGINE=InnoDB;
INSERT INTO pa_tenant (id,code,name,display_name,status,activated_at,created_at,updated_at)
VALUES
 (101,'alpha','Alpha','Alpha','active',UTC_TIMESTAMP(3),UTC_TIMESTAMP(3),UTC_TIMESTAMP(3)),
 (202,'beta','Beta','Beta','active',UTC_TIMESTAMP(3),UTC_TIMESTAMP(3),UTC_TIMESTAMP(3));
INSERT INTO pa_account (id,display_name,created_at,updated_at) VALUES
 (1501,'Alpha Operator',UTC_TIMESTAMP(3),UTC_TIMESTAMP(3)),
 (1502,'Beta Operator',UTC_TIMESTAMP(3),UTC_TIMESTAMP(3));
INSERT INTO pa_credential (account_id,kind,identifier_type,identifier_normalized,secret_hash,verified_at,secret_changed_at,created_at,updated_at)
VALUES
 (1501,'email_password','email','alpha-operator@example.test','fixture',UTC_TIMESTAMP(3),UTC_TIMESTAMP(3),UTC_TIMESTAMP(3),UTC_TIMESTAMP(3)),
 (1502,'email_password','email','beta-operator@example.test','fixture',UTC_TIMESTAMP(3),UTC_TIMESTAMP(3),UTC_TIMESTAMP(3),UTC_TIMESTAMP(3));
INSERT INTO pa_tenant_member (id,tenant_id,account_id,member_no,display_name,status,joined_at,created_at,updated_at)
VALUES
 (501,101,1501,'alpha-operator','Alpha Operator','active',UTC_TIMESTAMP(3),UTC_TIMESTAMP(3),UTC_TIMESTAMP(3)),
 (502,202,1502,'beta-operator','Beta Operator','active',UTC_TIMESTAMP(3),UTC_TIMESTAMP(3),UTC_TIMESTAMP(3));
INSERT INTO pa_system_menu (id,type,name,perms,is_disable) VALUES
 (1,'C','Role list','core.role.read',0),(2,'C','Role edit','core.role.update',0);
INSERT INTO pa_permission (`key`,module_key,type,name,manifest_version,created_at,updated_at) VALUES
 ('core.role.read','core','api','Role list','1.0.0',UTC_TIMESTAMP(3),UTC_TIMESTAMP(3)),
 ('core.role.update','core','api','Role edit','1.0.0',UTC_TIMESTAMP(3),UTC_TIMESTAMP(3));
SQL);
}

$serverRoot = dirname(__DIR__, 2);
$host = IsolatedBackendEnvironment::required('DB_HOST');
$port = (int) IsolatedBackendEnvironment::required('DB_PORT');
$user = IsolatedBackendEnvironment::required('DB_USER');
$password = IsolatedBackendEnvironment::required('DB_PASS');
$runId = getenv('PEANUT_ORG_TEST_RUN_ID') ?: strtolower(bin2hex(random_bytes(6)));
if (preg_match('/^[a-z0-9_]{1,32}$/D', $runId) !== 1) {
    throw new RuntimeException('Invalid isolated organization test run ID.');
}
$adminPdo = new PDO(
    "mysql:host={$host};port={$port};charset=utf8mb4",
    $user,
    $password,
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::MYSQL_ATTR_MULTI_STATEMENTS => true],
);
$database = 'peanut_admin_mt02_org_' . $runId;
$databaseCreated = false;

try {
    $adminPdo->exec("CREATE DATABASE `{$database}` CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci");
    $databaseCreated = true;
    $pdo = new PDO("mysql:host={$host};port={$port};dbname={$database};charset=utf8mb4", $user, $password, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::MYSQL_ATTR_MULTI_STATEMENTS => true]);
    createOrgTenantSchema($pdo);

    IsolatedBackendEnvironment::activateDatabase($host, $port, $database, $user, $password, 'multi-tenant');
    $app = new think\App($serverRoot);
    $app->initialize();

    $alpha = orgTenantContext(101, 501, 'mt02-org-alpha-' . $runId);
    $beta = orgTenantContext(202, 502, 'mt02-org-beta-' . $runId);
    expectOrgTenant(orgFailure(fn() => app(CurrentExecutionContext::class)->tenantAdmin())[1] !== '', 'missing context was accepted');

    foreach ([[$alpha, 202], [$beta, 101]] as [$context, $payloadTenantId]) {
        expectOrgTenant(
            app(ExecutionContextStore::class)->run(
                new \app\common\execution\AdminExecutionContext($context, 'test.role.add'),
                fn() => app(RoleApplicationService::class)->add($context, ['tenant_id' => $payloadTenantId, 'name' => 'Manager', 'menu_id' => [1]]),
            ),
            'role add failed',
        );
        expectOrgTenant(
            app(ExecutionContextStore::class)->run(
                new \app\common\execution\AdminExecutionContext($context, 'test.department.add'),
                fn() => app(DeptApplicationService::class)->add($context, ['tenant_id' => $payloadTenantId, 'pid' => 0, 'name' => 'Operations', 'status' => 1]),
            ),
            'department add failed',
        );
        expectOrgTenant(
            app(ExecutionContextStore::class)->run(
                new \app\common\execution\AdminExecutionContext($context, 'test.jobs.add'),
                fn() => app(JobsApplicationService::class)->add($context, ['tenant_id' => $payloadTenantId, 'name' => 'Operator', 'code' => 'OPS', 'status' => 1]),
            ),
            'job add failed',
        );
    }

    $alphaRole = (int) $pdo->query("SELECT id FROM pa_role WHERE tenant_id=101 AND name='Manager'")->fetchColumn();
    $betaRole = (int) $pdo->query("SELECT id FROM pa_role WHERE tenant_id=202 AND name='Manager'")->fetchColumn();
    $alphaDept = (int) $pdo->query("SELECT id FROM pa_department WHERE tenant_id=101 AND name='Operations'")->fetchColumn();
    $betaDept = (int) $pdo->query("SELECT id FROM pa_department WHERE tenant_id=202 AND name='Operations'")->fetchColumn();
    $alphaJobs = (int) $pdo->query("SELECT id FROM pa_jobs WHERE tenant_id=101 AND code='OPS'")->fetchColumn();
    $betaJobs = (int) $pdo->query("SELECT id FROM pa_jobs WHERE tenant_id=202 AND code='OPS'")->fetchColumn();
    expectOrgTenant($alphaRole > 0 && $betaRole > 0 && $alphaRole !== $betaRole, 'same role name was not Tenant-local');
    expectOrgTenant($alphaDept > 0 && $betaDept > 0 && $alphaDept !== $betaDept, 'same department name was not Tenant-local');
    expectOrgTenant($alphaJobs > 0 && $betaJobs > 0 && $alphaJobs !== $betaJobs, 'same job code was not Tenant-local');
    $crossRoleDetail = orgFailure(fn() => app(ExecutionContextStore::class)->run(
        new \app\common\execution\AdminExecutionContext($alpha, 'test.role.detail.cross-tenant'),
        fn() => app(RoleApplicationService::class)->detail($alpha, $betaRole),
    ));
    expectOrgTenant($crossRoleDetail[1] !== '', 'cross-Tenant role detail denial lost shape');
    $crossDeptDetail = orgFailure(fn() => app(ExecutionContextStore::class)->run(
        new \app\common\execution\AdminExecutionContext($alpha, 'test.department.detail.cross-tenant'),
        fn() => app(DeptApplicationService::class)->detail($alpha, $betaDept),
    ));
    expectOrgTenant($crossDeptDetail[1] !== '', 'cross-Tenant department detail denial lost shape');
    expectOrgTenant(
        app(ExecutionContextStore::class)->run(
            new \app\common\execution\AdminExecutionContext($alpha, 'test.jobs.detail.cross-tenant'),
            fn() => app(JobsApplicationService::class)->detail($alpha, $betaJobs),
        ) === [],
        'cross-Tenant job detail leaked',
    );
    expectOrgTenant(
        app(ExecutionContextStore::class)->run(
            new \app\common\execution\AdminExecutionContext($alpha, 'test.role.list'),
            fn() => app(RoleApplicationService::class)->lists($alpha, []),
        )->total === 1,
        'role list crossed Tenant boundary',
    );
    expectOrgTenant(
        count(app(ExecutionContextStore::class)->run(
            new \app\common\execution\AdminExecutionContext($alpha, 'test.department.list'),
            fn() => app(DeptApplicationService::class)->lists($alpha),
        )) === 1,
        'department list crossed Tenant boundary',
    );
    expectOrgTenant(
        app(ExecutionContextStore::class)->run(
            new \app\common\execution\AdminExecutionContext($alpha, 'test.jobs.list.export'),
            fn() => app(JobsApplicationService::class)->lists($alpha, ['export' => 1]),
        )['count'] === 1,
        'job export query crossed Tenant boundary',
    );

    $crossRoleAssignment = orgFailure(fn() => app(ExecutionContextStore::class)->run(
        new \app\common\execution\AdminExecutionContext($alpha, 'test.admin.add.cross-tenant'),
        fn() => app(AdminApplicationService::class)->add($alpha, [
            'tenant_id' => 202, 'account' => 'blocked@example.test', 'name' => 'Blocked', 'password' => 'D03OriginalPassword2026',
            'disable' => 0, 'multipoint_login' => 1, 'role_id' => [$betaRole], 'dept_id' => [$alphaDept], 'jobs_id' => [$alphaJobs],
        ]),
    ));
    expectOrgTenant($crossRoleAssignment[1] !== '', 'cross-Tenant role assignment denial lost shape');
    expectOrgTenant(
        app(ExecutionContextStore::class)->run(
            new \app\common\execution\AdminExecutionContext($alpha, 'test.admin.add.alpha'),
            fn() => app(AdminApplicationService::class)->add($alpha, [
                'tenant_id' => 202, 'account' => 'shared-admin@example.test', 'name' => 'Shared Admin', 'password' => 'D03OriginalPassword2026',
                'disable' => 0, 'multipoint_login' => 1, 'role_id' => [$alphaRole], 'dept_id' => [$alphaDept], 'jobs_id' => [$alphaJobs],
            ]),
        ),
        'Alpha admin add failed',
    );
    expectOrgTenant(
        app(ExecutionContextStore::class)->run(
            new \app\common\execution\AdminExecutionContext($beta, 'test.admin.add.beta'),
            fn() => app(AdminApplicationService::class)->add($beta, [
                'tenant_id' => 101, 'account' => 'shared-admin-beta@example.test', 'name' => 'Shared Admin', 'password' => 'D03OriginalPassword2026',
                'disable' => 0, 'multipoint_login' => 1, 'role_id' => [$betaRole], 'dept_id' => [$betaDept], 'jobs_id' => [$betaJobs],
            ]),
        ),
        'Beta admin add failed',
    );
    $alphaAdmin = (int) $pdo->query("SELECT tm.id FROM pa_tenant_member tm JOIN pa_credential c ON c.account_id=tm.account_id WHERE tm.tenant_id=101 AND c.identifier_normalized='shared-admin@example.test'")->fetchColumn();
    $betaAdmin = (int) $pdo->query("SELECT tm.id FROM pa_tenant_member tm JOIN pa_credential c ON c.account_id=tm.account_id WHERE tm.tenant_id=202 AND c.identifier_normalized='shared-admin-beta@example.test'")->fetchColumn();
    expectOrgTenant(
        app(ExecutionContextStore::class)->run(
            new \app\common\execution\AdminExecutionContext($alpha, 'test.admin.detail.cross-tenant'),
            fn() => app(AdminApplicationService::class)->detail($alpha, $betaAdmin),
        ) === [],
        'cross-Tenant admin detail leaked',
    );
    $alphaAdminList = app(ExecutionContextStore::class)->run(
        new \app\common\execution\AdminExecutionContext($alpha, 'test.admin.list'),
        fn() => app(AdminApplicationService::class)->lists($alpha, []),
    );
    $listedAdminIds = array_column($alphaAdminList['lists'], 'id');
    sort($listedAdminIds);
    $expectedAdminIds = [501, $alphaAdmin];
    sort($expectedAdminIds);
    expectOrgTenant($alphaAdminList['count'] === 2 && $listedAdminIds === $expectedAdminIds, 'admin list must include exactly its fixture operator and created member');
    expectOrgTenant(
        app(ExecutionContextStore::class)->run(
            new \app\common\execution\AdminExecutionContext($alpha, 'test.admin.self-profile'),
            fn() => app(AdminApplicationService::class)->editSelf($alpha, 501, ['name' => 'Alpha Operator Updated'], '127.0.0.1', 'D03 test'),
        ),
        'admin self profile update failed',
    );
    expectOrgTenant(
        $pdo->query('SELECT display_name FROM pa_account WHERE id=1501')->fetchColumn() === 'Alpha Operator Updated',
        'admin self profile update did not use the authenticated account',
    );
    expectOrgTenant(
        orgFailure(fn() => app(AdminApplicationService::class)->editSelf($alpha, 502, ['name' => 'Blocked'], '127.0.0.1', 'D03 test'))[1] !== '',
        'forged member context reached self profile command',
    );
    $crossStatusDenied = orgFailure(fn() => app(ExecutionContextStore::class)->run(
        new \app\common\execution\AdminExecutionContext($alpha, 'test.admin.status.cross-tenant'),
        fn() => app(AdminApplicationService::class)->updateStatus($alpha, $betaAdmin, 1),
    ));
    $crossDeleteDenied = orgFailure(fn() => app(ExecutionContextStore::class)->run(
        new \app\common\execution\AdminExecutionContext($alpha, 'test.admin.delete.cross-tenant'),
        fn() => app(AdminApplicationService::class)->delete($alpha, $betaAdmin),
    ));
    expectOrgTenant($crossStatusDenied === $crossDeleteDenied, 'cross-Tenant admin denial enumerated operation');
    expectOrgTenant($pdo->query("SELECT status FROM pa_tenant_member WHERE tenant_id=202 AND id={$betaAdmin}")->fetchColumn() === 'active', 'cross-Tenant status denial mutated target');

    foreach ([
        "INSERT INTO pa_member_role (tenant_id,tenant_member_id,role_id,assigned_at) VALUES (202,{$alphaAdmin},{$betaRole},UTC_TIMESTAMP(3))",
        "UPDATE pa_tenant_member SET primary_department_id={$betaDept} WHERE tenant_id=101 AND id={$alphaAdmin}",
    ] as $pollution) {
        try {
            $pdo->exec($pollution);
            throw new RuntimeException('database accepted cross-Tenant pivot pollution');
        } catch (PDOException $exception) {
            expectOrgTenant($exception->getCode() === '23000', 'pivot pollution failed with unexpected shape');
        }
    }

    expectOrgTenant((int) $pdo->query("SELECT COUNT(*) FROM pa_member_role WHERE tenant_id=101 AND tenant_member_id={$alphaAdmin} AND role_id={$alphaRole}")->fetchColumn() === 1, 'owned admin role relation missing');
    expectOrgTenant(
        app(ExecutionContextStore::class)->run(
            new \app\common\execution\AdminExecutionContext($alpha, 'test.role.edit'),
            fn() => app(RoleApplicationService::class)->edit($alpha, ['id' => $alphaRole, 'name' => 'Manager Alpha', 'menu_id' => [1]]),
        ),
        'role edit failed',
    );
    expectOrgTenant(
        app(ExecutionContextStore::class)->run(
            new \app\common\execution\AdminExecutionContext($alpha, 'test.department.edit'),
            fn() => app(DeptApplicationService::class)->edit($alpha, ['id' => $alphaDept, 'pid' => 0, 'name' => 'Operations Alpha', 'status' => 1]),
        ),
        'department edit failed',
    );
    expectOrgTenant(
        app(ExecutionContextStore::class)->run(
            new \app\common\execution\AdminExecutionContext($alpha, 'test.jobs.edit'),
            fn() => app(JobsApplicationService::class)->edit($alpha, ['id' => $alphaJobs, 'name' => 'Operator Alpha', 'code' => 'OPS-A', 'status' => 1]),
        ),
        'job edit failed',
    );
    expectOrgTenant(
        app(ExecutionContextStore::class)->run(
            new \app\common\execution\AdminExecutionContext($alpha, 'test.jobs.status'),
            fn() => app(JobsApplicationService::class)->updateStatus($alpha, $alphaJobs, 0),
        ),
        'job status update failed',
    );

    // D03 回归：以下对象没有成员引用，覆盖 Core 的归档、移动和状态写命令。
    $roleService = app(RoleApplicationService::class);
    $departmentService = app(DeptApplicationService::class);
    app(ExecutionContextStore::class)->run(new \app\common\execution\AdminExecutionContext($alpha, 'test.role.archive'), fn() => $roleService->add($alpha, ['name' => 'Disposable role', 'menu_id' => []]));
    $disposableRole = (int) $pdo->query("SELECT id FROM pa_role WHERE tenant_id=101 AND name='Disposable role'")->fetchColumn();
    expectOrgTenant(app(ExecutionContextStore::class)->run(new \app\common\execution\AdminExecutionContext($alpha, 'test.role.archive'), fn() => $roleService->delete($alpha, $disposableRole)), 'role archive failed');
    expectOrgTenant($pdo->query("SELECT status FROM pa_role WHERE id={$disposableRole}")->fetchColumn() === 'archived', 'role archive did not persist');

    app(ExecutionContextStore::class)->run(new \app\common\execution\AdminExecutionContext($alpha, 'test.department.parent'), fn() => $departmentService->add($alpha, ['pid' => 0, 'name' => 'Archive parent', 'status' => 1]));
    $parentDept = (int) $pdo->query("SELECT id FROM pa_department WHERE tenant_id=101 AND name='Archive parent'")->fetchColumn();
    app(ExecutionContextStore::class)->run(new \app\common\execution\AdminExecutionContext($alpha, 'test.department.child'), fn() => $departmentService->add($alpha, ['pid' => $parentDept, 'name' => 'Archive child', 'status' => 1]));
    $childDept = (int) $pdo->query("SELECT id FROM pa_department WHERE tenant_id=101 AND name='Archive child'")->fetchColumn();
    expectOrgTenant(app(ExecutionContextStore::class)->run(new \app\common\execution\AdminExecutionContext($alpha, 'test.department.move'), fn() => $departmentService->edit($alpha, ['id' => $childDept, 'pid' => 0, 'name' => 'Archive child', 'status' => 0])), 'department move/status failed');
    expectOrgTenant($pdo->query("SELECT status FROM pa_department WHERE id={$childDept}")->fetchColumn() === 'disabled', 'department status did not persist');
    expectOrgTenant(app(ExecutionContextStore::class)->run(new \app\common\execution\AdminExecutionContext($alpha, 'test.department.archive'), fn() => $departmentService->delete($alpha, $childDept)), 'department child archive failed');
    expectOrgTenant(app(ExecutionContextStore::class)->run(new \app\common\execution\AdminExecutionContext($alpha, 'test.department.archive'), fn() => $departmentService->delete($alpha, $parentDept)), 'department parent archive failed');

    // 使用实际 Core 持久层验证授权修订、权限替换和审计身份，不只断言 bool。
    $run = fn(string $operation, callable $call) => app(ExecutionContextStore::class)->run(
        new \app\common\execution\AdminExecutionContext($alpha, $operation),
        $call,
    );
    $tenantRevision = (int) $pdo->query('SELECT authorization_revision FROM pa_tenant WHERE id=101')->fetchColumn();
    $roleRevision = (int) $pdo->query("SELECT authorization_revision FROM pa_role WHERE id={$alphaRole}")->fetchColumn();
    expectOrgTenant($run('test.role.permissions.replace', fn() => $roleService->edit($alpha, ['id' => $alphaRole, 'name' => 'Manager Alpha', 'menu_id' => [2]])), 'role permission replacement failed');
    expectOrgTenant($pdo->query("SELECT p.`key` FROM pa_role_permission rp JOIN pa_permission p ON p.id=rp.permission_id WHERE rp.tenant_id=101 AND rp.role_id={$alphaRole}")->fetchAll(PDO::FETCH_COLUMN) === ['core.role.update'], 'role permissions were not replaced exactly');
    expectOrgTenant((int) $pdo->query("SELECT authorization_revision FROM pa_role WHERE id={$alphaRole}")->fetchColumn() > $roleRevision, 'role revision was not advanced');
    expectOrgTenant((int) $pdo->query('SELECT authorization_revision FROM pa_tenant WHERE id=101')->fetchColumn() > $tenantRevision, 'tenant authorization revision was not advanced');
    expectOrgTenant($pdo->query("SELECT parent_id FROM pa_department WHERE id={$childDept}")->fetchColumn() === null, 'department move did not persist');
    expectOrgTenant($pdo->query("SELECT status FROM pa_department WHERE id={$childDept}")->fetchColumn() === 'archived', 'department archive did not persist');

    $admins = app(AdminApplicationService::class);
    $auth = app(\PeanutAdmin\Modules\Identity\Auth\TenantAuthService::class);
    $self = app(\PeanutAdmin\Modules\Identity\Identity\SelfService\AccountSelfService::class);
    $memberBefore = $pdo->query("SELECT authorization_revision,security_revision FROM pa_tenant_member WHERE id={$alphaAdmin}")->fetch(PDO::FETCH_ASSOC);
    expectOrgTenant($run('test.admin.edit', fn() => $admins->edit($alpha, [
        'id' => $alphaAdmin, 'name' => 'Edited Admin', 'role_id' => [$alphaRole], 'dept_id' => [$alphaDept], 'disable' => 0,
    ])), 'member edit failed');
    expectOrgTenant($pdo->query("SELECT display_name FROM pa_tenant_member WHERE id={$alphaAdmin}")->fetchColumn() === 'Edited Admin', 'member edit did not persist');
    expectOrgTenant((int) $pdo->query("SELECT authorization_revision FROM pa_tenant_member WHERE id={$alphaAdmin}")->fetchColumn() > (int) $memberBefore['authorization_revision'], 'member authorization revision was not advanced');
    $login = $auth->login('shared-admin@example.test', 'D03OriginalPassword2026', 'alpha', '127.0.0.1', 'D03 test', 'd03-member-login');
    $oldToken = $login->tokens->access->expose();
    expectOrgTenant($auth->context($oldToken, 'd03-session-before-suspend')->memberId === $alphaAdmin, 'real member session did not authenticate');
    expectOrgTenant($run('test.admin.suspend', fn() => $admins->updateStatus($alpha, $alphaAdmin, 1)), 'member suspend failed');
    expectOrgTenant($pdo->query("SELECT status FROM pa_tenant_member WHERE id={$alphaAdmin}")->fetchColumn() === 'suspended', 'member suspend did not persist');
    expectOrgTenant(orgFailure(fn() => $auth->context($oldToken, 'd03-session-after-suspend'))[1] !== '', 'suspended member retained a valid session');
    expectOrgTenant($run('test.admin.activate', fn() => $admins->updateStatus($alpha, $alphaAdmin, 0)), 'member activate failed');
    expectOrgTenant($pdo->query("SELECT status FROM pa_tenant_member WHERE id={$alphaAdmin}")->fetchColumn() === 'active', 'member activate did not persist');
    expectOrgTenant(orgFailure(fn() => $auth->context($oldToken, 'd03-session-after-activate'))[1] !== '', 'activation resurrected an invalidated session');
    $login = $auth->login('shared-admin@example.test', 'D03OriginalPassword2026', 'alpha', '127.0.0.1', 'D03 test', 'd03-profile-login');
    $memberContext = $login->context;
    $profile = $self->profile($memberContext);
    expectOrgTenant($profile['account_id'] === (string) $memberContext->accountId && !isset($profile['credential']['secret_hash']), 'self profile read leaked credential or selected wrong account');
    expectOrgTenant($admins->editSelf($memberContext, $alphaAdmin, ['name' => 'Self Updated', 'password_old' => 'D03OriginalPassword2026', 'password' => 'D03ChangedPassword2026'], '127.0.0.1', 'D03 test'), 'self password change failed');
    expectOrgTenant($self->profile($memberContext)['display_name'] === 'Self Updated', 'self profile change was lost');
    expectOrgTenant(orgFailure(fn() => $auth->context($login->tokens->access->expose(), 'd03-password-session'))[1] !== '', 'password change retained previous session');
    expectOrgTenant(orgFailure(fn() => $auth->login('shared-admin@example.test', 'D03OriginalPassword2026', 'alpha', '127.0.0.1', 'D03 test', 'd03-old-password'))[1] !== '', 'old password remained valid');
    $newLogin = $auth->login('shared-admin@example.test', 'D03ChangedPassword2026', 'alpha', '127.0.0.1', 'D03 test', 'd03-new-password');
    expectOrgTenant($newLogin->context->accountId === $memberContext->accountId, 'new password did not authenticate');
    $forged = TenantContext::fromValidatedSession(new ValidatedTenantSession(501, 'forged-session', 101, 1502, 501, 'admin-web', new DateTimeImmutable('+1 hour'), 1), 'd03-forged-profile');
    expectOrgTenant(orgFailure(fn() => $self->profile($forged))[1] !== '', 'forged account/member pair could read profile');
    expectOrgTenant(orgFailure(fn() => $admins->editSelf($forged, 501, ['name' => 'Forged'], '127.0.0.1', 'D03 test'))[1] !== '', 'forged account/member pair could update profile');
    expectOrgTenant($run('test.admin.leave', fn() => $admins->delete($alpha, $alphaAdmin)), 'member leave failed');
    expectOrgTenant($pdo->query("SELECT status FROM pa_tenant_member WHERE id={$alphaAdmin}")->fetchColumn() === 'left', 'member leave did not persist');
    expectOrgTenant(orgFailure(fn() => $auth->context($newLogin->tokens->access->expose(), 'd03-left-session'))[1] !== '', 'left member retained valid session');
    foreach (['tenant.role.created', 'tenant.role.updated', 'tenant.role.permissions-replaced', 'tenant.role.archived', 'tenant.department.created', 'tenant.department.updated', 'tenant.department.moved', 'tenant.department.archived', 'tenant.member.suspended', 'tenant.member.active', 'tenant.member.left'] as $event) {
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM pa_tenant_audit_event WHERE tenant_id=101 AND actor_tenant_member_id=501 AND actor_account_id=1501 AND request_id=? AND event_type=?');
        $stmt->execute([$alpha->requestId, $event]);
        expectOrgTenant((int) $stmt->fetchColumn() > 0, 'trusted actor audit missing: ' . $event);
    }
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM pa_tenant_audit_event WHERE tenant_id=101 AND actor_tenant_member_id=? AND actor_account_id=? AND event_type='account.password.changed'");
    $stmt->execute([$alphaAdmin, $memberContext->accountId]);
    expectOrgTenant((int) $stmt->fetchColumn() === 1, 'password audit did not preserve authenticated actor');
} finally {
    if ($databaseCreated) {
        $adminPdo->exec("DROP DATABASE `{$database}`");
    }
}

echo 'MT02-ORG-TENANT-ISOLATION-001 passed; assertions=' . $GLOBALS['orgAssertions'] . "\n";
