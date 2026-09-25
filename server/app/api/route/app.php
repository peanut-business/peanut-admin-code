<?php

declare(strict_types=1);

$peanutRouteApplication = 'api';
$serverRoot = dirname(__DIR__, 3);

require $serverRoot . '/route/public_api.php';

foreach ([
    'app/modules/official/file/route/app.php',
    'app/modules/official/notification/route/app.php',
    'app/modules/official/oauth/route/app.php',
    'app/modules/official/payment/route/app.php',
    'app/modules/official/member/route/app.php',
] as $moduleRoute) {
    require $serverRoot . '/' . $moduleRoute;
}

unset($peanutRouteApplication, $serverRoot, $moduleRoute);
