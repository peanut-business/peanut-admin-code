<?php

// +----------------------------------------------------------------------
// | ThinkPHP [ WE CAN DO IT JUST THINK ]
// +----------------------------------------------------------------------
// | Copyright (c) 2006-2019 http://thinkphp.cn All rights reserved.
// +----------------------------------------------------------------------
// | Licensed ( http://www.apache.org/licenses/LICENSE-2.0 )
// +----------------------------------------------------------------------
// | Author: liu21st <liu21st@gmail.com>
// +----------------------------------------------------------------------

use think\App;

// [ 应用入口文件 ]

require __DIR__ . '/../bootstrap/environment.php';
require __DIR__ . '/../vendor/autoload.php';

// 执行HTTP应用并响应
$http = (new App())->http;

try {
    $response = $http->run();
} catch (RuntimeException $exception) {
    if (preg_match('/^(?:SERVER_DEPLOYMENT_|HTTP_(?:MOUNT_|IMAGE_|WORKER_|PROGRAM_|MUTABLE_|OWNER_))/', $exception->getMessage()) !== 1) {
        throw $exception;
    }
    error_log('HTTP admission blocked: ' . $exception->getMessage());
    http_response_code(503);
    header('Content-Type: application/json');
    header('Retry-After: 3');
    echo json_encode(['code' => 'INSTALL_RUNTIME_RELOADING', 'restart_required' => true]);
    exit;
}

$response->send();

$http->end($response);
