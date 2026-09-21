<?php
declare(strict_types=1);

namespace app\platform\controller;

use app\platform\services\developer\DeveloperCenterCatalogService;

final class PlatformDeveloperCenterController extends BasePlatformController
{
    public function catalog()
    {
        $service = new DeveloperCenterCatalogService(
            dirname(__DIR__, 3),
            (array)$this->app->config->get('modules', []),
        );
        $moduleKey = trim((string)$this->request->get('module_key', ''));
        return $this->data($service->snapshot($moduleKey === '' ? null : $moduleKey));
    }
}
