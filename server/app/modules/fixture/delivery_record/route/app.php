<?php
declare(strict_types=1);

use PeanutAdmin\Fixtures\DeliveryRecord\Controller\DeliveryRecordController;
use app\adminapi\http\middleware\LoginMiddleware;
use app\adminapi\http\middleware\OperationLogMiddleware;
use app\common\infrastructure\module\OfficialModuleMiddleware;
use think\facade\Route;

if (($peanutRouteApplication ?? null) !== 'adminapi') {
    return;
}

// Module commands own permission checks; the generic root-bypass RBAC middleware is not used.
Route::get('fixtures/delivery-records', [DeliveryRecordController::class, 'lists'])
    ->middleware(LoginMiddleware::class)
    ->middleware(OfficialModuleMiddleware::class, 'fixture.delivery-record', 'http.admin');
Route::post('fixtures/delivery-records', [DeliveryRecordController::class, 'record'])
    ->middleware(LoginMiddleware::class)
    ->middleware(OfficialModuleMiddleware::class, 'fixture.delivery-record', 'http.admin')
    ->middleware(OperationLogMiddleware::class);
