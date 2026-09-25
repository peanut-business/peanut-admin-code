<?php

declare(strict_types=1);

use app\adminapi\controller\auth\LoginController;
use app\installation\controller\InstallationController;
use think\facade\Route;

if (($peanutRouteApplication ?? null) === 'installation') {
    Route::get('status', [InstallationController::class, 'status']);
    Route::post('execute', [InstallationController::class, 'execute']);
}

if (($peanutRouteApplication ?? null) === 'adminapi') {
    Route::post('user/login', [LoginController::class, 'login']);
    Route::post('user/logout', [LoginController::class, 'logout']);
}
