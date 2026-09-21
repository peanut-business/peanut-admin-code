<?php
declare(strict_types=1);

namespace app\modules\official\payment\controller;

use app\adminapi\controller\BaseAdminController;
use app\modules\official\payment\services\PayConfigApplicationService;
use app\modules\official\payment\validate\PayConfigValidate;

class PayConfigController extends BaseAdminController
{
    protected function payConfigs(): PayConfigApplicationService
    {
        return $this->app->make(PayConfigApplicationService::class);
    }

    public function getConfig()
    {
        return $this->data($this->payConfigs()->getConfig($this->tenantAdminContext()));
    }

    public function setConfig()
    {
        $params = $this->request->post();
        $this->validate($params, PayConfigValidate::class);
        $this->payConfigs()->setConfig($this->tenantAdminContext(), $params);
        return $this->success('操作成功');
    }
}
