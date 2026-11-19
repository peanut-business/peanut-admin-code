<?php

declare(strict_types=1);

namespace app\platform\controller;

use think\App;

use app\platform\services\developer\DeveloperCenterCatalogService;

final class PlatformDeveloperCenterController extends BasePlatformController
{
    protected readonly DeveloperCenterCatalogService $developerCatalog;

    public function __construct(App $app, ?DeveloperCenterCatalogService $developerCatalog = null)
    {
        parent::__construct($app);
        $this->developerCatalog = $developerCatalog ?? $app->make(DeveloperCenterCatalogService::class);
    }
    public function catalog()
    {
        $moduleKey = trim((string) $this->request->get('module_key', ''));
        return $this->data($this->developerCatalog->snapshot($moduleKey === '' ? null : $moduleKey));
    }
}
