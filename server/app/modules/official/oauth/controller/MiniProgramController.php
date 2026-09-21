<?php
declare(strict_types=1);

namespace app\modules\official\oauth\controller;

use app\adminapi\controller\BaseAdminController;
use app\modules\official\oauth\services\MiniProgramApplicationService;
use app\modules\official\oauth\validate\MiniProgramValidate;

class MiniProgramController extends BaseAdminController
{
    protected function miniPrograms(): MiniProgramApplicationService
    {
        return $this->app->make(MiniProgramApplicationService::class);
    }

    public function getConfig()
    {
        return $this->data($this->miniPrograms()->getConfig(
            $this->tenantAdminContext(),
            (string)$this->request->domain(),
        ));
    }

    public function setConfig()
    {
        $params = $this->request->post();
        $this->validate($params, MiniProgramValidate::class);
        $this->miniPrograms()->setConfig($this->tenantAdminContext(), $params);
        return $this->success('操作成功');
    }
}
