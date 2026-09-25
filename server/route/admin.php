<?php

declare(strict_types=1);

use app\adminapi\controller\auth\AdminController;
use app\adminapi\controller\auth\LoginController;
use app\adminapi\controller\auth\MenuController;
use app\adminapi\controller\auth\RoleController;
use app\adminapi\controller\WorkbenchController;
use app\adminapi\controller\config\ConfigController;
use app\adminapi\controller\config\ReadinessController;
use app\adminapi\controller\dept\DeptController;
use app\adminapi\controller\dept\JobsController;
use PeanutAdmin\Modules\ReferenceCodes\Controller\DictTypeController;
use PeanutAdmin\Modules\ReferenceCodes\Controller\DictDataController;
use app\adminapi\controller\generator\GeneratorController;
use app\adminapi\controller\system\SystemController;
use app\adminapi\controller\setting\HotSearchController;
use app\adminapi\controller\setting\TransactionSettingsController;
use app\adminapi\controller\decoration\DecorationPageController;
use app\adminapi\controller\decoration\DecorationTabbarController;
use app\adminapi\controller\log\OperationLogController;
use app\adminapi\http\middleware\AuthMiddleware;
use app\adminapi\http\middleware\LoginMiddleware;
use app\adminapi\http\middleware\OperationLogMiddleware;
use think\facade\Route;

if (($peanutRouteApplication ?? null) !== 'adminapi') {
    return;
}

// ─── 管理端会话与菜单路由（仅需登录，不做 RBAC） ───────────────────────────
Route::group(function () {
    Route::post('user/info', [LoginController::class, 'info']);
    Route::post('user/menu', [MenuController::class, 'route']);
})->middleware(LoginMiddleware::class);

// ─── 管理后台完整 API（Login → Auth 两层中间件） ─────────────────────────────
Route::group(function () {
    Route::get('login/info', [LoginController::class, 'info']);

    // 工作台
    Route::get('workbench/index', [WorkbenchController::class, 'index']);

    // 菜单
    Route::get('menu/route', [MenuController::class, 'route']);
    Route::get('menu/lists', [MenuController::class, 'lists']);
    Route::get('menu/all', [MenuController::class, 'all']);
    Route::get('menu/detail', [MenuController::class, 'detail']);
    Route::post('menu/add', [MenuController::class, 'add']);
    Route::post('menu/edit', [MenuController::class, 'edit']);
    Route::post('menu/delete', [MenuController::class, 'delete']);
    Route::post('menu/status', [MenuController::class, 'updateStatus']);

    // 角色
    Route::get('role/lists', [RoleController::class, 'lists']);
    Route::get('role/all', [RoleController::class, 'all']);
    Route::get('role/detail', [RoleController::class, 'detail']);
    Route::post('role/add', [RoleController::class, 'add']);
    Route::post('role/edit', [RoleController::class, 'edit']);
    Route::post('role/delete', [RoleController::class, 'delete']);

    // 管理员
    Route::get('admin/lists', [AdminController::class, 'lists']);
    Route::get('admin/detail', [AdminController::class, 'detail']);
    Route::get('admin/self', [AdminController::class, 'self']);
    Route::post('admin/editSelf', [AdminController::class, 'editSelf']);
    Route::post('admin/add', [AdminController::class, 'add']);
    Route::post('admin/edit', [AdminController::class, 'edit']);
    Route::post('admin/delete', [AdminController::class, 'delete']);
    Route::post('admin/status', [AdminController::class, 'updateStatus']);

    // 部门
    Route::get('dept/lists', [DeptController::class, 'lists']);
    Route::get('dept/all', [DeptController::class, 'all']);
    Route::get('dept/leaderDept', [DeptController::class, 'leaderDept']);
    Route::get('dept/detail', [DeptController::class, 'detail']);
    Route::post('dept/add', [DeptController::class, 'add']);
    Route::post('dept/edit', [DeptController::class, 'edit']);
    Route::post('dept/delete', [DeptController::class, 'delete']);
    Route::post('dept/status', [DeptController::class, 'updateStatus']);

    // 岗位
    Route::get('jobs/lists', [JobsController::class, 'lists']);
    Route::get('jobs/all', [JobsController::class, 'all']);
    Route::get('jobs/detail', [JobsController::class, 'detail']);
    Route::post('jobs/add', [JobsController::class, 'add']);
    Route::post('jobs/edit', [JobsController::class, 'edit']);
    Route::post('jobs/delete', [JobsController::class, 'delete']);
    Route::post('jobs/status', [JobsController::class, 'updateStatus']);

    // 字典类型
    Route::get('dict/type/lists', [DictTypeController::class, 'lists']);
    Route::get('dict/type/all', [DictTypeController::class, 'all']);
    Route::get('dict/type/detail', [DictTypeController::class, 'detail']);
    Route::post('dict/type/add', [DictTypeController::class, 'add']);
    Route::post('dict/type/edit', [DictTypeController::class, 'edit']);
    Route::post('dict/type/delete', [DictTypeController::class, 'delete']);
    Route::post('dict/type/status', [DictTypeController::class, 'updateStatus']);

    // 字典数据
    Route::get('dict/data/lists', [DictDataController::class, 'lists']);
    Route::get('dict/data/byType', [DictDataController::class, 'byType']);
    Route::get('dict/data/detail', [DictDataController::class, 'detail']);
    Route::post('dict/data/add', [DictDataController::class, 'add']);
    Route::post('dict/data/edit', [DictDataController::class, 'edit']);
    Route::post('dict/data/delete', [DictDataController::class, 'delete']);
    Route::post('dict/data/status', [DictDataController::class, 'updateStatus']);

    // 开发工具 - 安全代码生成器
    Route::get('generator/source-tables', [GeneratorController::class, 'sourceTables']);
    Route::get('generator/lists', [GeneratorController::class, 'lists']);
    Route::get('generator/detail', [GeneratorController::class, 'detail']);
    Route::post('generator/import', [GeneratorController::class, 'import']);
    Route::post('generator/sync', [GeneratorController::class, 'sync']);
    Route::post('generator/update', [GeneratorController::class, 'update']);
    Route::post('generator/delete', [GeneratorController::class, 'delete']);
    Route::post('generator/preview', [GeneratorController::class, 'preview']);
    Route::post('generator/generate', [GeneratorController::class, 'generate']);
    Route::get('generator/download', [GeneratorController::class, 'download']);
    Route::get('generator/models', [GeneratorController::class, 'models']);

    // 系统维护
    Route::get('system/info', [SystemController::class, 'info']);
    Route::post('system/clearCache', [SystemController::class, 'clearCache']);

    // 操作日志
    Route::get('log/lists', [OperationLogController::class, 'lists']);
    Route::post('log/clear', [OperationLogController::class, 'clear']);
    // 系统配置 - 网站设置
    Route::get('config/website', [ConfigController::class, 'getWebsite']);
    Route::post('config/website/save', [ConfigController::class, 'saveWebsite']);
    Route::get('config/copyright', [ConfigController::class, 'getCopyright']);
    Route::post('config/copyright/save', [ConfigController::class, 'saveCopyright']);
    Route::get('config/agreement', [ConfigController::class, 'getAgreement']);
    Route::post('config/agreement/save', [ConfigController::class, 'saveAgreement']);
    Route::get('config/statistics', [ConfigController::class, 'getStatistics']);
    Route::post('config/statistics/save', [ConfigController::class, 'saveStatistics']);
    Route::get('config/user', [ConfigController::class, 'getUser']);
    Route::post('config/user/save', [ConfigController::class, 'saveUser']);
    Route::get('config/login', [ConfigController::class, 'getLogin']);
    Route::post('config/login/save', [ConfigController::class, 'saveLogin']);

    // 应用设置 - 首次运行生产准备清单（只读，不执行外部探测）
    Route::get('readiness/checklist', [ReadinessController::class, 'checklist']);

    // 应用设置 - 热门搜索
    Route::get('setting/hot-search/config', [HotSearchController::class, 'getConfig']);
    Route::post('setting/hot-search/save', [HotSearchController::class, 'setConfig']);

    // 应用设置 - 交易设置
    Route::get('setting/transaction/config', [TransactionSettingsController::class, 'getConfig']);
    Route::post('setting/transaction/save', [TransactionSettingsController::class, 'setConfig']);

    // 装修管理：移动端、Tabbar 与 PC 权限域严格分离
    Route::get('decoration/mobile/page/lists', [DecorationPageController::class, 'mobileLists']);
    Route::get('decoration/mobile/page/detail', [DecorationPageController::class, 'mobileDetail']);
    Route::post('decoration/mobile/page/save', [DecorationPageController::class, 'mobileSave']);
    Route::get('decoration/tabbar/detail', [DecorationTabbarController::class, 'detail']);
    Route::post('decoration/tabbar/save', [DecorationTabbarController::class, 'save']);
    Route::get('decoration/pc/page/lists', [DecorationPageController::class, 'pcLists']);
    Route::get('decoration/pc/page/detail', [DecorationPageController::class, 'pcDetail']);
    Route::post('decoration/pc/page/save', [DecorationPageController::class, 'pcSave']);
    Route::get('decoration/mobile/article', [DecorationPageController::class, 'article']);

})->middleware([LoginMiddleware::class, AuthMiddleware::class, OperationLogMiddleware::class]);
