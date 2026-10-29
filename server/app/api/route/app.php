<?php

declare(strict_types=1);

$peanutRouteApplication = 'api';
$serverRoot = dirname(__DIR__, 3);

require $serverRoot . '/route/public_api.php';

foreach (app(\PeanutAdmin\Kernel\Module\CompiledModuleRegistry::class)->modules as $manifest) {
    $moduleRoute = $manifest->root . '/route/app.php';
    if (is_file($moduleRoute)) {
        require $moduleRoute;
    }
}

unset($peanutRouteApplication, $serverRoot, $moduleRoute, $manifest);
