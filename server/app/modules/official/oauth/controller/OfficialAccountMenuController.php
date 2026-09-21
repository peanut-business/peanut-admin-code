<?php
declare(strict_types=1);

namespace app\modules\official\oauth\controller;

use app\adminapi\controller\BaseAdminController;
use app\modules\official\oauth\services\OfficialAccountMenuApplicationService;
use app\modules\official\oauth\validate\OfficialAccountMenuValidate;

class OfficialAccountMenuController extends BaseAdminController
{
    protected function officialAccountMenus(): OfficialAccountMenuApplicationService
    {
        return $this->app->make(OfficialAccountMenuApplicationService::class);
    }

    public function detail()
    {
        return $this->data($this->officialAccountMenus()->detail($this->tenantAdminContext()));
    }

    public function save()
    {
        $params = $this->request->post();
        $this->validate($params, OfficialAccountMenuValidate::class);
        $this->officialAccountMenus()->save(
            $this->tenantAdminContext(),
            (array)$params['menu']
        );
        return $this->success('保存成功');
    }

    public function saveAndPublish()
    {
        $params = $this->request->post();
        $this->validate($params, OfficialAccountMenuValidate::class);
        $this->officialAccountMenus()->saveAndPublish(
            $this->tenantAdminContext(),
            (array)$params['menu']
        );
        return $this->success('发布成功');
    }
}
