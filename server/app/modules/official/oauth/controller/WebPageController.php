<?php
declare(strict_types=1);

namespace app\modules\official\oauth\controller;

use app\adminapi\controller\BaseAdminController;
use app\modules\official\oauth\services\WebPageApplicationService;
use app\modules\official\oauth\validate\WebPageValidate;

class WebPageController extends BaseAdminController
{
    protected function webPages(): WebPageApplicationService
    {
        return $this->app->make(WebPageApplicationService::class);
    }

    public function getConfig()
    {
        return $this->data($this->webPages()->getConfig(
            $this->tenantAdminContext(),
            (string)$this->request->domain(),
        ));
    }

    public function setConfig()
    {
        $params = $this->request->post();
        $this->validate($params, WebPageValidate::class);
        $this->webPages()->setConfig($this->tenantAdminContext(), $params);
        return $this->success('操作成功');
    }
}
