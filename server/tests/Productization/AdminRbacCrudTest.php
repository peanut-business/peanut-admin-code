<?php

declare(strict_types=1);

use app\adminapi\application\auth\AdminApplicationService;
use app\adminapi\application\auth\MenuApplicationService;
use app\adminapi\application\auth\RoleApplicationService;
use app\adminapi\application\dept\DeptApplicationService;
use app\adminapi\application\dept\JobsApplicationService;
use app\common\model\auth\SystemMenu;
use app\common\model\dept\Jobs;
use think\facade\Db;

require dirname(__DIR__, 2) . '/bootstrap/environment.php';
require dirname(__DIR__, 2) . '/vendor/autoload.php';

function expectCrud(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function expectCrudFailure(bool $result, string $actualError, string $expectedError): void
{
    expectCrud($result === false, 'operation unexpectedly succeeded: ' . $expectedError);
    expectCrud(str_contains($actualError, $expectedError), sprintf(
        'unexpected error: expected "%s", got "%s"',
        $expectedError,
        $actualError,
    ));
}

$app = new think\App();
$app->initialize();

$suffix = strtolower(substr(bin2hex(random_bytes(6)), 0, 12));
$names = [
    'admin' => 'pb04_admin_' . $suffix,
    'nickname' => 'PB04管理员' . $suffix,
    'role' => 'PB04角色' . $suffix,
    'dept' => 'PB04部门' . $suffix,
    'jobs' => 'PB04岗位' . $suffix,
    'jobs_code' => 'pb04_jobs_' . $suffix,
    'menu_parent' => 'PB04目录' . $suffix,
    'menu_child' => 'PB04菜单' . $suffix,
    'menu_parent_perm' => 'pb04/' . $suffix . '/parent',
    'menu_child_perm' => 'pb04/' . $suffix . '/child',
];
$ids = [
    'admin' => 0,
    'role' => 0,
    'dept' => 0,
    'jobs' => 0,
    'menu_parent' => 0,
    'menu_child' => 0,
];

$rootAdmin = Db::name('admin')->where('root', 1)->where('disable', 0)->find();
expectCrud(is_array($rootAdmin), 'active root administrator is required');
$rootDept = Db::name('dept')->where('pid', 0)->where('status', 1)->find();
expectCrud(is_array($rootDept), 'active root department is required');

try {
    expectCrud(app(MenuApplicationService::class)->add([
        'pid' => 0,
        'type' => 'M',
        'name' => $names['menu_parent'],
        'perms' => $names['menu_parent_perm'],
        'paths' => '/pb04-' . $suffix,
        'component' => '',
        'is_cache' => 0,
        'is_show' => 1,
        'is_disable' => 0,
    ]), app(MenuApplicationService::class)->getError());
    $ids['menu_parent'] = (int) SystemMenu::where('perms', $names['menu_parent_perm'])->value('id');
    expectCrud($ids['menu_parent'] > 0, 'parent menu was not created');

    expectCrud(app(MenuApplicationService::class)->add([
        'pid' => $ids['menu_parent'],
        'type' => 'C',
        'name' => $names['menu_child'],
        'perms' => $names['menu_child_perm'],
        'paths' => '/pb04-' . $suffix . '/child',
        'component' => 'pb04/child',
        'is_cache' => 0,
        'is_show' => 1,
        'is_disable' => 0,
    ]), app(MenuApplicationService::class)->getError());
    $ids['menu_child'] = (int) SystemMenu::where('perms', $names['menu_child_perm'])->value('id');
    expectCrud($ids['menu_child'] > 0, 'child menu was not created');

    expectCrudFailure(
        app(MenuApplicationService::class)->delete($ids['menu_parent']),
        app(MenuApplicationService::class)->getError(),
        '已关联下级菜单',
    );

    $parent = SystemMenu::findOrEmpty($ids['menu_parent'])->toArray();
    $parent['pid'] = $ids['menu_child'];
    expectCrudFailure(
        app(MenuApplicationService::class)->edit($parent),
        app(MenuApplicationService::class)->getError(),
        '上级菜单不可是当前菜单或其下级菜单',
    );

    expectCrud(app(RoleApplicationService::class)->add([
        'name' => $names['role'],
        'desc' => 'PB04 productization probe',
        'sort' => 0,
        'menu_id' => [$ids['menu_child']],
    ]), app(RoleApplicationService::class)->getError());
    $ids['role'] = (int) Db::name('system_role')->where('name', $names['role'])->value('id');
    expectCrud($ids['role'] > 0, 'role was not created');

    expectCrudFailure(
        app(MenuApplicationService::class)->delete($ids['menu_child']),
        app(MenuApplicationService::class)->getError(),
        '菜单已被角色使用',
    );

    expectCrud(app(DeptApplicationService::class)->add([
        'pid' => (int) $rootDept['id'],
        'name' => $names['dept'],
        'leader' => '',
        'mobile' => '',
        'sort' => 0,
        'status' => 1,
    ]), app(DeptApplicationService::class)->getError());
    $ids['dept'] = (int) Db::name('dept')->where('name', $names['dept'])->value('id');
    expectCrud($ids['dept'] > 0, 'department was not created');

    expectCrud(app(JobsApplicationService::class)->add([
        'name' => $names['jobs'],
        'code' => $names['jobs_code'],
        'sort' => 0,
        'status' => 1,
        'remark' => 'PB04 productization probe',
    ]), app(JobsApplicationService::class)->getError());
    $ids['jobs'] = (int) Jobs::where('code', $names['jobs_code'])->value('id');
    expectCrud($ids['jobs'] > 0, 'job was not created');

    expectCrud(app(AdminApplicationService::class)->add([
        'account' => $names['admin'],
        'name' => $names['nickname'],
        'password' => 'pb04-test-password',
        'avatar' => '',
        'disable' => 0,
        'multipoint_login' => 1,
        'role_id' => [$ids['role']],
        'dept_id' => [$ids['dept']],
        'jobs_id' => [$ids['jobs']],
    ]), app(AdminApplicationService::class)->getError());
    $ids['admin'] = (int) Db::name('admin')->where('username', $names['admin'])->value('id');
    expectCrud($ids['admin'] > 0, 'administrator was not created');
    expectCrud(Db::name('admin_role')->where(['admin_id' => $ids['admin'], 'role_id' => $ids['role']])->count() === 1, 'admin role relation missing');
    expectCrud(Db::name('admin_dept')->where(['admin_id' => $ids['admin'], 'dept_id' => $ids['dept']])->count() === 1, 'admin department relation missing');
    expectCrud(Db::name('admin_jobs')->where(['admin_id' => $ids['admin'], 'jobs_id' => $ids['jobs']])->count() === 1, 'admin job relation missing');

    expectCrudFailure(
        app(AdminApplicationService::class)->delete($ids['admin'], $ids['admin']),
        app(AdminApplicationService::class)->getError(),
        '不能操作当前登录的管理员',
    );
    expectCrudFailure(
        app(AdminApplicationService::class)->updateStatus($ids['admin'], 1, $ids['admin']),
        app(AdminApplicationService::class)->getError(),
        '不能操作当前登录的管理员',
    );
    expectCrudFailure(
        app(AdminApplicationService::class)->delete((int) $rootAdmin['id']),
        app(AdminApplicationService::class)->getError(),
        '超级管理员不允许被删除',
    );
    expectCrudFailure(
        app(AdminApplicationService::class)->updateStatus((int) $rootAdmin['id'], 1),
        app(AdminApplicationService::class)->getError(),
        '超级管理员不允许被禁用',
    );
    expectCrudFailure(app(RoleApplicationService::class)->delete($ids['role']), app(RoleApplicationService::class)->getError(), '有管理员在使用该角色');
    expectCrudFailure(app(DeptApplicationService::class)->delete($ids['dept']), app(DeptApplicationService::class)->getError(), '已关联管理员');
    expectCrudFailure(app(JobsApplicationService::class)->delete($ids['jobs']), app(JobsApplicationService::class)->getError(), '已关联管理员');

    expectCrud(app(AdminApplicationService::class)->delete($ids['admin'], (int) $rootAdmin['id']), app(AdminApplicationService::class)->getError());
    expectCrud(app(RoleApplicationService::class)->delete($ids['role']), app(RoleApplicationService::class)->getError());
    expectCrud(app(MenuApplicationService::class)->delete($ids['menu_child']), app(MenuApplicationService::class)->getError());
    expectCrud(app(MenuApplicationService::class)->delete($ids['menu_parent']), app(MenuApplicationService::class)->getError());
    expectCrud(app(DeptApplicationService::class)->delete($ids['dept']), app(DeptApplicationService::class)->getError());
    expectCrud(app(JobsApplicationService::class)->delete($ids['jobs']), app(JobsApplicationService::class)->getError());
} finally {
    Db::transaction(static function () use ($ids): void {
        $adminIds = array_values(array_filter([(int) $ids['admin']]));
        $roleIds = array_values(array_filter([(int) $ids['role']]));
        $deptIds = array_values(array_filter([(int) $ids['dept']]));
        $jobsIds = array_values(array_filter([(int) $ids['jobs']]));
        $menuIds = array_values(array_filter([
            (int) $ids['menu_parent'],
            (int) $ids['menu_child'],
        ]));

        if ($adminIds !== []) {
            Db::name('admin_session')->whereIn('admin_id', $adminIds)->delete();
            Db::name('admin_role')->whereIn('admin_id', $adminIds)->delete();
            Db::name('admin_dept')->whereIn('admin_id', $adminIds)->delete();
            Db::name('admin_jobs')->whereIn('admin_id', $adminIds)->delete();
            Db::name('admin')->whereIn('id', $adminIds)->delete();
        }
        if ($roleIds !== []) {
            Db::name('admin_role')->whereIn('role_id', $roleIds)->delete();
            Db::name('system_role_menu')->whereIn('role_id', $roleIds)->delete();
            Db::name('system_role')->whereIn('id', $roleIds)->delete();
        }
        if ($menuIds !== []) {
            Db::name('system_role_menu')->whereIn('menu_id', $menuIds)->delete();
            Db::name('system_menu')->whereIn('id', $menuIds)->delete();
        }
        if ($deptIds !== []) {
            Db::name('admin_dept')->whereIn('dept_id', $deptIds)->delete();
            Db::name('dept')->whereIn('id', $deptIds)->delete();
        }
        if ($jobsIds !== []) {
            Db::name('admin_jobs')->whereIn('jobs_id', $jobsIds)->delete();
            Db::name('jobs')->whereIn('id', $jobsIds)->delete();
        }
    });
}

expectCrud(Db::name('admin')->where('username', $names['admin'])->count() === 0, 'temporary administrator cleanup failed');
expectCrud(Db::name('system_role')->where('name', $names['role'])->count() === 0, 'temporary role cleanup failed');
expectCrud(Db::name('dept')->where('name', $names['dept'])->count() === 0, 'temporary department cleanup failed');
expectCrud(Db::name('jobs')->where('code', $names['jobs_code'])->count() === 0, 'temporary job cleanup failed');
expectCrud(Db::name('system_menu')->whereIn('perms', [$names['menu_parent_perm'], $names['menu_child_perm']])->count() === 0, 'temporary menu cleanup failed');

echo "PB04-AUTH-CRUD-001 passed\n";
