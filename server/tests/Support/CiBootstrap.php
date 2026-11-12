<?php

declare(strict_types=1);

/** The same explicit environment as executable tests; no implicit database or service creation. */
$applicationRoot = getenv('PEANUT_TEST_APPLICATION_ROOT');
$server = $applicationRoot !== false && $applicationRoot !== '' ? $applicationRoot . '/server' : dirname(__DIR__, 2);
require_once $server . '/vendor/autoload.php';
if ($applicationRoot !== false && $applicationRoot !== '') {
    $roots = [];
    foreach ((new \app\platform\infrastructure\module\ModuleLockResolver($server, '../modules.lock'))->all() as $module) {
        $roots += $module->moduleRoots;
    }
    \app\common\infrastructure\module\ModuleHostLayoutFactory::registerRuntimeAutoload($roots, $server);
}
if (($environment = getenv('PEANUT_SERVER_ENV_FILE')) !== false && $environment !== '') {
    if (!str_starts_with($environment, '/') || !is_file($environment) || is_link($environment)
        || !is_readable($environment)) {
        throw new RuntimeException('Invalid explicit backend environment file');
    }
    // Use the framework store without initializing application services or mirroring secrets.
    $app = new \think\App($server);
    require_once $app->getThinkPath() . 'helper.php';
    $app->env->load($environment);
}
