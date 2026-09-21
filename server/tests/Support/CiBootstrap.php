<?php
declare(strict_types=1);

/** The same explicit environment as executable tests; no implicit database or service creation. */
$server = dirname(__DIR__, 2);
if (($environment = getenv('PEANUT_SERVER_ENV_FILE')) !== false && $environment !== '') {
    require_once $server . '/bootstrap/environment.php';
}
require_once $server . '/vendor/autoload.php';
