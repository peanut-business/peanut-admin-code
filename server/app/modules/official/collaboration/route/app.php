<?php

declare(strict_types=1);

use PeanutAdmin\Modules\Collaboration\Controller\InternalController;
use PeanutAdmin\Modules\Collaboration\Controller\TicketController;
use PeanutAdmin\Modules\Collaboration\Middleware\ServiceAuthenticationMiddleware;
use think\facade\Route;

if (($peanutRouteApplication ?? null) !== 'adminapi') {
    return;
}
Route::post('official.collaboration.ticket', [TicketController::class,'issue'])->middleware([
    \app\adminapi\http\middleware\LoginMiddleware::class,
    [\app\common\infrastructure\module\OfficialModuleMiddleware::class,['official.collaboration','http.admin']],
    \app\adminapi\http\middleware\AuthMiddleware::class,
]);
Route::group('internal/collaboration/v1', function (): void {
    foreach (['authorize','check','load','store','receipt','references','target','recovery','revoke','lifecycle'] as $action) {
        Route::post($action, [InternalController::class,$action])->completeMatch();
    }
    Route::post('target/prepare', [InternalController::class,'prepareTarget'])->completeMatch();
})->middleware(ServiceAuthenticationMiddleware::class);
