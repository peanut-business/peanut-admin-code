<?php

declare(strict_types=1);

namespace app\adminapi\controller\setting;

use think\App;

use app\adminapi\controller\BaseAdminController;
use app\adminapi\services\setting\HotSearchApplicationService;

class HotSearchController extends BaseAdminController
{
    protected readonly HotSearchApplicationService $hotSearch;

    public function __construct(App $app, ?HotSearchApplicationService $hotSearch = null)
    {
        parent::__construct($app);
        $this->hotSearch = $hotSearch ?? $app->make(HotSearchApplicationService::class);
    }
    public function getConfig()
    {
        return $this->data($this->hotSearch->getConfig($this->tenantAdminContext()));
    }

    public function setConfig()
    {
        $this->hotSearch->setConfig(
            $this->tenantAdminContext(),
            $this->request->post(),
        );
        return $this->success('操作成功');
    }
}
