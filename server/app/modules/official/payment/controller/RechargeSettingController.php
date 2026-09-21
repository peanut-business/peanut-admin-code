<?php
declare(strict_types=1);

namespace app\modules\official\payment\controller;

use app\adminapi\controller\BaseAdminController;
use app\modules\official\payment\services\RechargeSettingApplicationService;
use app\modules\official\payment\validate\RechargeSettingValidate;

class RechargeSettingController extends BaseAdminController
{
    protected function rechargeSettings(): RechargeSettingApplicationService
    {
        return $this->app->make(RechargeSettingApplicationService::class);
    }

    public function config()
    {
        return $this->data($this->rechargeSettings()->getConfig($this->tenantAdminContext()));
    }

    public function save()
    {
        $params = $this->request->post();
        $this->validate($params, RechargeSettingValidate::class . '.save');
        $this->rechargeSettings()->save($this->tenantAdminContext(), $params);
        return $this->success('保存成功');
    }
}
