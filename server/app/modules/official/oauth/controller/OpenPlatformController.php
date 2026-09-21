<?php
declare(strict_types=1);

namespace app\modules\official\oauth\controller;

use app\adminapi\controller\BaseAdminController;
use app\modules\official\oauth\services\OpenPlatformApplicationService;
use app\modules\official\oauth\validate\OpenPlatformValidate;

class OpenPlatformController extends BaseAdminController
{
    protected function openPlatforms(): OpenPlatformApplicationService
    {
        return $this->app->make(OpenPlatformApplicationService::class);
    }

    public function getConfig()
    {
        return $this->data($this->openPlatforms()->getConfig($this->tenantAdminContext()));
    }

    public function setConfig()
    {
        $params = $this->request->post();
        $this->validate($params, OpenPlatformValidate::class);
        $this->openPlatforms()->setConfig($this->tenantAdminContext(), $params);
        return $this->success('操作成功');
    }
}
