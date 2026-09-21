<?php
declare(strict_types=1);

use app\modules\official\notification\controller\NoticeChannelController;
use app\modules\official\notification\controller\NoticeLogController;
use app\modules\official\notification\controller\NoticeSceneController;
use app\modules\official\notification\controller\NotificationInboxController;
use app\api\controller\SmsController as ApiSmsController;
use app\api\middleware\PublicTenantModuleMiddleware;
use app\adminapi\http\middleware\AuthMiddleware;
use app\adminapi\http\middleware\LoginMiddleware;
use app\adminapi\http\middleware\OperationLogMiddleware;
use app\common\infrastructure\module\OfficialModuleMiddleware;
use think\facade\Route;

if (($peanutRouteApplication ?? null) === 'adminapi') {
Route::group(function (): void {
    Route::get('official.notification.channel.detail', [NoticeChannelController::class, 'detail']);
    Route::post('official.notification.channel.save', [NoticeChannelController::class, 'save']);
    Route::get('official.notification.log.list', [NoticeLogController::class, 'lists']);
    Route::get('official.notification.log.detail', [NoticeLogController::class, 'detail']);
    Route::get('official.notification.scene.list', [NoticeSceneController::class, 'lists']);
    Route::get('official.notification.scene.detail', [NoticeSceneController::class, 'detail']);
    Route::post('official.notification.scene.save', [NoticeSceneController::class, 'save']);
    Route::get('api/v1/notifications', [NotificationInboxController::class, 'index'])
        ->option(['peanut_permission' => 'official.notification.inbox.read']);
    Route::post('api/v1/notifications/:messageKey/read', [NotificationInboxController::class, 'markRead'])
        ->option(['peanut_permission' => 'official.notification.inbox.manage']);
    Route::post('api/v1/notifications/bulk', [NotificationInboxController::class, 'bulk'])
        ->option(['peanut_permission' => 'official.notification.inbox.manage']);
})->middleware(LoginMiddleware::class)
    ->middleware(OfficialModuleMiddleware::class, 'official.notification', 'http.admin')
    ->middleware(AuthMiddleware::class)
    ->middleware(OperationLogMiddleware::class);
}

if (($peanutRouteApplication ?? null) === 'api') {
Route::post('sms/sendCode', [ApiSmsController::class, 'sendCode'])
    ->middleware(PublicTenantModuleMiddleware::class, 'peanut.notice.verification', 'official.notification', 'notice.verification.send');
}
