<?php
declare(strict_types=1);

use PeanutAdmin\Modules\Article\Controller\ArticleCateController;
use PeanutAdmin\Modules\Article\Controller\ArticleController;
use app\adminapi\http\middleware\AuthMiddleware;
use app\adminapi\http\middleware\LoginMiddleware;
use app\adminapi\http\middleware\OperationLogMiddleware;
use app\common\infrastructure\module\OfficialModuleMiddleware;
use think\facade\Route;

if (($peanutRouteApplication ?? null) !== 'adminapi') {
    return;
}

Route::group(function (): void {
    Route::get('official.article.category.list', [ArticleCateController::class, 'lists']);
    Route::get('official.article.category.all', [ArticleCateController::class, 'all']);
    Route::get('official.article.category.detail', [ArticleCateController::class, 'detail']);
    Route::post('official.article.category.add', [ArticleCateController::class, 'add']);
    Route::post('official.article.category.edit', [ArticleCateController::class, 'edit']);
    Route::post('official.article.category.delete', [ArticleCateController::class, 'delete']);
    Route::post('official.article.category.update-status', [ArticleCateController::class, 'updateStatus']);
    Route::get('official.article.category.recycle.list', [ArticleCateController::class, 'recycleLists']);
    Route::get('official.article.category.recycle.detail', [ArticleCateController::class, 'recycleDetail']);
    Route::post('official.article.category.restore', [ArticleCateController::class, 'restore']);
    Route::post('official.article.category.force-delete', [ArticleCateController::class, 'forceDelete']);
    Route::get('official.article.list', [ArticleController::class, 'lists']);
    Route::get('official.article.detail', [ArticleController::class, 'detail']);
    Route::post('official.article.add', [ArticleController::class, 'add']);
    Route::post('official.article.edit', [ArticleController::class, 'edit']);
    Route::post('official.article.delete', [ArticleController::class, 'delete']);
    Route::post('official.article.update-status', [ArticleController::class, 'updateStatus']);
    Route::get('official.article.recycle.list', [ArticleController::class, 'recycleLists']);
    Route::get('official.article.recycle.detail', [ArticleController::class, 'recycleDetail']);
    Route::post('official.article.restore', [ArticleController::class, 'restore']);
    Route::post('official.article.force-delete', [ArticleController::class, 'forceDelete']);
})->middleware([
    LoginMiddleware::class,
    [OfficialModuleMiddleware::class, ['official.article', 'http.admin']],
    AuthMiddleware::class,
    OperationLogMiddleware::class,
]);
