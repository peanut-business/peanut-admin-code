<?php
declare(strict_types=1);

namespace PeanutAdmin\Modules\Payment\Controller;

use app\adminapi\controller\BaseAdminController;
use PeanutAdmin\Modules\Payment\Service\PayConfigApplicationService;
use PeanutAdmin\Modules\Payment\Validation\PayConfigValidate;

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
