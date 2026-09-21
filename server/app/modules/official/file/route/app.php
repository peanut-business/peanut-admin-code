<?php
declare(strict_types=1);

use PeanutAdmin\Modules\File\Controller\FileController;
use PeanutAdmin\Modules\File\Controller\UploadController;
use app\api\controller\UploadController as ApiUploadController;
use app\api\controller\StorageController as ApiStorageController;
use app\platform\controller\PlatformStorageController;
use app\api\middleware\CheckTokenMiddleware;
use app\platform\http\middleware\PlatformLoginMiddleware;
use app\platform\http\middleware\PlatformPermissionMiddleware;
use app\adminapi\http\middleware\AuthMiddleware;
use app\adminapi\http\middleware\LoginMiddleware;
use app\adminapi\http\middleware\OperationLogMiddleware;
use app\common\infrastructure\module\OfficialModuleMiddleware;
use think\facade\Route;

if (($peanutRouteApplication ?? null) === 'adminapi') {
Route::group(function (): void {
    Route::post('official.file.upload.image', [UploadController::class, 'image']);
    Route::post('official.file.upload.video', [UploadController::class, 'video']);
    Route::post('official.file.upload.file', [UploadController::class, 'file']);
    Route::get('official.file.list', [FileController::class, 'lists']);
    Route::get('api/v1/files/assets', [FileController::class, 'assets'])
        ->option(['peanut_permission' => 'official.file.list']);
    Route::post('official.file.move', [FileController::class, 'move']);
    Route::post('official.file.rename', [FileController::class, 'rename']);
    Route::post('official.file.delete', [FileController::class, 'delete']);
    Route::get('official.file.category.list', [FileController::class, 'listCate']);
    Route::post('official.file.category.add', [FileController::class, 'addCate']);
    Route::post('official.file.category.edit', [FileController::class, 'editCate']);
    Route::post('official.file.category.delete', [FileController::class, 'delCate']);
})->middleware(LoginMiddleware::class)
    ->middleware(OfficialModuleMiddleware::class, 'official.file', 'http.admin')
    ->middleware(AuthMiddleware::class)
    ->middleware(OperationLogMiddleware::class);
}

if (($peanutRouteApplication ?? null) === 'api') {
Route::get('storage/delivery', [ApiStorageController::class, 'delivery']);
Route::post('upload/image', [ApiUploadController::class, 'image'])
    ->middleware(CheckTokenMiddleware::class)
    ->middleware(OfficialModuleMiddleware::class, 'official.file', 'http.member-upload');
}

if (($peanutRouteApplication ?? null) === 'platform') {
Route::get('infrastructure/storage', [PlatformStorageController::class, 'snapshot'])
    ->middleware(PlatformLoginMiddleware::class)
    ->middleware(PlatformPermissionMiddleware::class, 'platform.ops.read');
Route::post('infrastructure/storage/account', [PlatformStorageController::class, 'createAccount'])
    ->middleware(PlatformLoginMiddleware::class)
    ->middleware(PlatformPermissionMiddleware::class, 'platform.ops.maintenance.manage');
Route::post('infrastructure/storage/account/update', [PlatformStorageController::class, 'updateAccount'])
    ->middleware(PlatformLoginMiddleware::class)
    ->middleware(PlatformPermissionMiddleware::class, 'platform.ops.maintenance.manage');
Route::post('infrastructure/storage/space', [PlatformStorageController::class, 'createSpace'])
    ->middleware(PlatformLoginMiddleware::class)
    ->middleware(PlatformPermissionMiddleware::class, 'platform.ops.maintenance.manage');
Route::post('infrastructure/storage/space/update', [PlatformStorageController::class, 'updateSpace'])
    ->middleware(PlatformLoginMiddleware::class)
    ->middleware(PlatformPermissionMiddleware::class, 'platform.ops.maintenance.manage');
Route::post('infrastructure/storage/route', [PlatformStorageController::class, 'setRoute'])
    ->middleware(PlatformLoginMiddleware::class)
    ->middleware(PlatformPermissionMiddleware::class, 'platform.ops.maintenance.manage');
}
