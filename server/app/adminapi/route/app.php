<?php

declare(strict_types=1);

$peanutRouteApplication = 'adminapi';
$serverRoot = dirname(__DIR__, 3);

require $serverRoot . '/route/app.php';
require $serverRoot . '/route/tenant.php';
require $serverRoot . '/route/admin.php';

foreach ([
    'app/modules/peanut/article/route/app.php',
    'app/modules/peanut/file/route/app.php',
    'app/modules/peanut/notification/route/app.php',
    'app/modules/peanut/oauth/route/app.php',
    'app/modules/peanut/payment/route/app.php',
    'app/modules/peanut/member/route/app.php',
    'app/modules/peanut/task/route/app.php',
    'app/modules/peanut/import_export/route/app.php',
    'app/modules/peanut/settings/route/app.php',
    'app/modules/peanut/reference_codes/route/app.php',
    'app/modules/peanut/rich_text/route/app.php',
    'app/modules/peanut/document/route/app.php',
    'app/modules/peanut/collaboration/route/app.php',
    'app/modules/peanut/integration/route/app.php',
] as $moduleRoute) {
    require $serverRoot . '/' . $moduleRoute;
}

foreach (['app/modules/peanut/workflow/route/app.php', 'app/modules/fixture/workflow_business/route/app.php'] as $moduleRoute) {
    if (is_file($serverRoot . '/' . $moduleRoute)) {
        require $serverRoot . '/' . $moduleRoute;
    }
}

unset($peanutRouteApplication, $serverRoot, $moduleRoute);
