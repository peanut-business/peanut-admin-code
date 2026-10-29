<?php

declare(strict_types=1);

$peanutRouteApplication = 'adminapi';
$serverRoot = dirname(__DIR__, 3);

require $serverRoot . '/route/app.php';
require $serverRoot . '/route/tenant.php';
require $serverRoot . '/route/admin.php';

foreach (app(\PeanutAdmin\Kernel\Module\CompiledModuleRegistry::class)->modules as $manifest) {
    $moduleRoute = $manifest->root . '/route/app.php';
    if (is_file($moduleRoute)) {
        require $moduleRoute;
    }
}

unset($peanutRouteApplication, $serverRoot, $moduleRoute, $manifest);
