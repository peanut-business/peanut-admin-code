<?php
declare(strict_types=1);

namespace app\modules\official\member\controller;

use app\adminapi\controller\BaseAdminController;
use app\modules\official\member\contracts\MemberAdministration;
use app\modules\official\member\validate\AccountLogValidate;
use app\common\enum\AccountLogEnum;

class AccountLogController extends BaseAdminController
{
    protected function members(): MemberAdministration
    {
        return $this->app->get(MemberAdministration::class);
    }

    public function lists()
    {
        $params = $this->request->get();
        $this->validate($params, AccountLogValidate::class . '.lists');
        return $this->data($this->members()->balanceLogs($params));
    }

    public function getUmChangeType()
    {
        return $this->data(AccountLogEnum::getUserMoneyChangeTypeDesc());
    }
}
