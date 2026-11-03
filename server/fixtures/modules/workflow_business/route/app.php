<?php

declare(strict_types=1);

use app\adminapi\http\middleware\AuthMiddleware;
use app\adminapi\http\middleware\LoginMiddleware;
use app\adminapi\http\middleware\OperationLogMiddleware;
use app\common\infrastructure\module\OfficialModuleMiddleware;
use PeanutAdmin\Fixtures\WorkflowBusiness\Controller\WorkflowBusinessController;
use think\facade\Route;

if (($peanutRouteApplication ?? null) !== 'adminapi') {
    return;
}

Route::group(function (): void {
    $base = 'api/v1/fixture/workflow-business/:scene/objects';
    Route::get($base, [WorkflowBusinessController::class, 'index'])->option(['peanut_permission' => 'fixture.workflow-business.read']);
    Route::get($base . '/:objectKey', [WorkflowBusinessController::class, 'detail'])->option(['peanut_permission' => 'fixture.workflow-business.read']);
    Route::post($base, [WorkflowBusinessController::class, 'create'])->option(['peanut_permission' => 'fixture.workflow-business.create']);
    Route::put($base . '/:objectKey', [WorkflowBusinessController::class, 'update'])->option(['peanut_permission' => 'fixture.workflow-business.update']);
    Route::post($base . '/:objectKey/confirm', [WorkflowBusinessController::class, 'confirm'])->option(['peanut_permission' => 'fixture.workflow-business.confirm']);
    Route::put($base . '/:objectKey/form', [WorkflowBusinessController::class, 'saveForm'])->option(['peanut_permission' => 'fixture.workflow-business.complete']);
    Route::get($base . '/:objectKey/collaboration', [WorkflowBusinessController::class, 'collaboration'])->option(['peanut_permission' => 'fixture.workflow-business.read']);
    Route::post($base . '/:objectKey/collaboration', [WorkflowBusinessController::class, 'appendCollaboration'])->option(['peanut_permission' => 'fixture.workflow-business.collaborate']);
})->completeMatch()->middleware(LoginMiddleware::class)
    ->middleware(OfficialModuleMiddleware::class, 'fixture.workflow-business', 'http.admin')
    ->middleware(AuthMiddleware::class)
    ->middleware(OperationLogMiddleware::class);
