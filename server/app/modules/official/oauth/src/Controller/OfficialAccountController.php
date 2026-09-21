<?php
declare(strict_types=1);

namespace PeanutAdmin\Modules\OAuth\Controller;

use app\adminapi\controller\BaseAdminController;
use PeanutAdmin\Modules\OAuth\Service\OfficialAccountApplicationService;
use PeanutAdmin\Modules\OAuth\Validation\OfficialAccountValidate;

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
