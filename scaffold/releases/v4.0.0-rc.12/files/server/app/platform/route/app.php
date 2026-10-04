<?php

declare(strict_types=1);

$peanutRouteApplication = 'platform';
require dirname(__DIR__, 3) . '/route/platform.php';
unset($peanutRouteApplication);
