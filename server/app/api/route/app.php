<?php

declare(strict_types=1);

$peanutRouteApplication = 'api';
$serverRoot = dirname(__DIR__, 3);

require $serverRoot . '/route/public_api.php';

foreach ([
    'app/modules/peanut/file/route/app.php',
    'app/modules/peanut/notification/route/app.php',
    'app/modules/peanut/oauth/route/app.php',
    'app/modules/peanut/payment/route/app.php',
    'app/modules/peanut/member/route/app.php',
] as $moduleRoute) {
    require $serverRoot . '/' . $moduleRoute;
}

unset($peanutRouteApplication, $serverRoot, $moduleRoute);
