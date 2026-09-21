<?php
declare(strict_types=1);

namespace app\modules\official\member\controller;

use app\adminapi\controller\BaseAdminController;
use app\modules\official\member\contracts\MemberAdministration;
use app\modules\official\member\validate\MemberTagValidate;

class MemberTagController extends BaseAdminController
{
    protected function members(): MemberAdministration
    {
        return $this->app->get(MemberAdministration::class);
    }

    public function lists()
    {
        return $this->data($this->members()->tags());
    }

    public function add()
    {
        $params = $this->request->post();
        $this->validate($params, MemberTagValidate::class . '.add');
        $this->members()->createTag($params);
        return $this->success('操作成功');
    }

    public function edit()
    {
        $params = $this->request->post();
        $this->validate($params, MemberTagValidate::class . '.edit');
        $this->members()->updateTag($params);
        return $this->success('操作成功');
    }

    public function delete()
    {
        $this->members()->deleteTag((int)$this->request->post('id'));
        return $this->success('操作成功');
    }
}
