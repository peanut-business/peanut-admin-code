<?php
declare(strict_types=1);

namespace app\modules\official\oauth\controller;

use app\adminapi\controller\BaseAdminController;
use app\modules\official\oauth\services\OfficialAccountApplicationService;
use app\modules\official\oauth\validate\OfficialAccountValidate;

class OfficialAccountController extends BaseAdminController
{
    protected function officialAccounts(): OfficialAccountApplicationService
    {
        return $this->app->make(OfficialAccountApplicationService::class);
    }

    public function getConfig()
    {
        return $this->data($this->officialAccounts()->getConfig(
            $this->tenantAdminContext(),
            (string)$this->request->domain(),
        ));
    }

    public function setConfig()
    {
        $params = $this->request->post();
        $this->validate($params, OfficialAccountValidate::class);
        $this->officialAccounts()->setConfig($this->tenantAdminContext(), $params);
        return $this->success('操作成功');
    }
}
