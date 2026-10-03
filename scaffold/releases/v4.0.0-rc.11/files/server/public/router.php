<?php

// +----------------------------------------------------------------------
// | ThinkPHP [ WE CAN DO IT JUST THINK ]
// +----------------------------------------------------------------------
// | Copyright (c) 2006~2019 http://thinkphp.cn All rights reserved.
// +----------------------------------------------------------------------
// | Licensed ( http://www.apache.org/licenses/LICENSE-2.0 )
// +----------------------------------------------------------------------
// | Author: liu21st <liu21st@gmail.com>
// +----------------------------------------------------------------------
// $Id$

$requestPath = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$requestPath = rawurldecode($requestPath);

if ($requestPath === '/') {
    header('Location: /admin/', true, 302);
    return true;
}

if ($requestPath === '/admin') {
    header('Location: /admin/', true, 302);
    return true;
}

if (str_starts_with($requestPath, '/admin/')) {
    $staticPath = __DIR__ . $requestPath;

    if (is_file($staticPath)) {
        return false;
    }

    $_SERVER['SCRIPT_FILENAME'] = __DIR__ . '/admin/index.html';
    readfile($_SERVER['SCRIPT_FILENAME']);
    return true;
}

if (is_file($_SERVER['DOCUMENT_ROOT'] . ($_SERVER['SCRIPT_NAME'] ?? ''))) {
    return false;
}

$_SERVER['SCRIPT_FILENAME'] = __DIR__ . '/index.php';
require __DIR__ . '/index.php';
return true;
