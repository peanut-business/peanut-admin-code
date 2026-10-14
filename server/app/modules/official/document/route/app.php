<?php

declare(strict_types=1);
use PeanutAdmin\Modules\Document\Controller\DocumentController;
use think\facade\Route;

if (($peanutRouteApplication ?? null) !== 'adminapi') {
    return;
}
Route::group(function (): void {
    Route::get('official.document.detail', [DocumentController::class,'detail']);
    Route::get('official.document.versions', [DocumentController::class,'versions']);
    Route::get('official.document.capabilities', [DocumentController::class,'capabilities']);
    Route::get('official.document.operation', [DocumentController::class,'operation']);
    Route::get('official.document.target', [DocumentController::class,'target']);
    Route::post('official.document.target.cancel', [DocumentController::class,'cancelTarget']);
    Route::post('official.document.solo.save', [DocumentController::class,'soloSave']);
    Route::post('official.document.resource.bind', [DocumentController::class,'bindResource']);
    Route::post('official.document.version.create', [DocumentController::class,'createVersion']);
    Route::post('official.document.version.restore', [DocumentController::class,'restoreVersion']);
    Route::post('official.document.archive', [DocumentController::class,'archive']);
    Route::post('official.document.restore', [DocumentController::class,'restore']);
})->middleware([
    \app\adminapi\http\middleware\LoginMiddleware::class,
    [\app\common\infrastructure\module\OfficialModuleMiddleware::class,['official.document','http.admin']],
    \app\adminapi\http\middleware\AuthMiddleware::class,
    \app\adminapi\http\middleware\OperationLogMiddleware::class,
]);
