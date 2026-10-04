<?php

declare(strict_types=1);

use PeanutAdmin\Modules\Member\Controller\MemberController;
use PeanutAdmin\Modules\Member\Controller\MemberTagController;
use PeanutAdmin\Modules\Member\Controller\AccountLogController;
use app\api\controller\LoginController as ApiLoginController;
use app\api\controller\UserController as ApiUserController;
use app\api\controller\AccountLogController as ApiAccountLogController;
use app\api\middleware\CheckTokenMiddleware;
use app\api\middleware\PublicTenantModuleMiddleware;
use app\adminapi\http\middleware\AuthMiddleware;
use app\adminapi\http\middleware\LoginMiddleware;
use app\adminapi\http\middleware\OperationLogMiddleware;
use app\common\infrastructure\module\OfficialModuleMiddleware;
use think\facade\Route;

if (($peanutRouteApplication ?? null) === 'adminapi') {
    Route::group(function (): void {
        Route::get('official.member.list', [MemberController::class, 'lists']);
        Route::get('official.member.detail', [MemberController::class, 'detail']);
        Route::post('official.member.add', [MemberController::class, 'add']);
        Route::post('official.member.edit', [MemberController::class, 'edit']);
        Route::post('official.member.update-status', [MemberController::class, 'updateStatus']);
        Route::post('official.member.balance.adjust', [MemberController::class, 'adjustMoney']);
        Route::get('official.member.tag.list', [MemberTagController::class, 'lists']);
        Route::post('official.member.tag.add', [MemberTagController::class, 'add']);
        Route::post('official.member.tag.edit', [MemberTagController::class, 'edit']);
        Route::post('official.member.tag.delete', [MemberTagController::class, 'delete']);
        Route::get('official.member.account-log.list', [AccountLogController::class, 'lists']);
        Route::get('official.member.account-log.change-types', [AccountLogController::class, 'getUmChangeType']);
    })->middleware(LoginMiddleware::class)
        ->middleware(OfficialModuleMiddleware::class, 'official.member', 'http.admin')
        ->middleware(AuthMiddleware::class)
        ->middleware(OperationLogMiddleware::class);
}

if (($peanutRouteApplication ?? null) !== 'api') {
    return;
}

Route::post('login/register', [ApiLoginController::class, 'register'])
    ->middleware(PublicTenantModuleMiddleware::class, 'peanut.member.public-auth', 'official.member', 'member.register');
Route::post('login/account', [ApiLoginController::class, 'account'])
    ->middleware(PublicTenantModuleMiddleware::class, 'peanut.member.public-auth', 'official.member', 'member.login');
foreach ([
    ['login/mobile', 'mobile', 'notice.verification.verify', 'http.mobile-login'],
    ['login/resetPassword', 'resetPassword', 'notice.verification.verify', 'http.reset-password'],
] as [$path, $action, $noticeOperation, $memberOperation]) {
    Route::post($path, [ApiLoginController::class, $action])
        ->middleware(PublicTenantModuleMiddleware::class, 'peanut.notice.verification', 'official.notification', $noticeOperation)
        ->middleware(OfficialModuleMiddleware::class, 'official.member', $memberOperation);
}

Route::group(function (): void {
    Route::get('user/center', [ApiUserController::class, 'center']);
    Route::get('user/info', [ApiUserController::class, 'info']);
    Route::post('user/setInfo', [ApiUserController::class, 'setInfo']);
    Route::post('user/changePassword', [ApiUserController::class, 'changePassword']);
    Route::post('user/bindMobile', [ApiUserController::class, 'bindMobile']);
    Route::get('account_log/lists', [ApiAccountLogController::class, 'lists']);
})->middleware(CheckTokenMiddleware::class)
    ->middleware(OfficialModuleMiddleware::class, 'official.member', 'http.member');
