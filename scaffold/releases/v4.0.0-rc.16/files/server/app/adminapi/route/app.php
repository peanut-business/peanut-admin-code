<?php

declare(strict_types=1);

$peanutRouteApplication = 'adminapi';
$serverRoot = dirname(__DIR__, 3);

require $serverRoot . '/route/app.php';
require $serverRoot . '/route/tenant.php';
require $serverRoot . '/route/admin.php';

foreach ([
    'app/modules/official/article/route/app.php',
    'app/modules/official/file/route/app.php',
    'app/modules/official/notification/route/app.php',
    'app/modules/official/oauth/route/app.php',
    'app/modules/official/payment/route/app.php',
    'app/modules/official/member/route/app.php',
    'app/modules/official/task/route/app.php',
    'app/modules/official/import_export/route/app.php',
    'app/modules/official/settings/route/app.php',
    'app/modules/official/reference_codes/route/app.php',
    'app/modules/official/integration/route/app.php',
] as $moduleRoute) {
    require $serverRoot . '/' . $moduleRoute;
}

unset($peanutRouteApplication, $serverRoot, $moduleRoute);
