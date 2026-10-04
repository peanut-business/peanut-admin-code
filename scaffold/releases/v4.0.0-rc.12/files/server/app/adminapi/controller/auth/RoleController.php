<?php

declare(strict_types=1);

namespace app\adminapi\controller\auth;

use app\adminapi\controller\BaseAdminController;
use app\adminapi\services\auth\RoleApplicationService;

/** @property-read RoleApplicationService $roles 当前 App 中声明式解析的控制器依赖。 */
class RoleController extends BaseAdminController
{
    protected string $rolesClass = RoleApplicationService::class;

    public function lists()
    {
        $result = $this->roles->lists($this->tenantAdminContext(), $this->request->get());
        return $this->data($result);
    }

    public function all()
    {
        return $this->data($this->roles->getAll($this->tenantAdminContext()));
    }

    public function detail()
    {
        $params = $this->request->get();
        $this->validate($params, ['id' => 'require|integer|gt:0']);
        return $this->data($this->roles->detail($this->tenantAdminContext(), (int) $params['id']));
    }

    public function add()
    {
        $params = $this->roleParams();
        $this->validate($params, $this->roles->validationRules('add'));
        $this->roles->add($this->tenantAdminContext(), $params);
        return $this->success('操作成功');
    }

    public function edit()
    {
        $params = $this->roleParams();
        $this->validate($params, $this->roles->validationRules('edit'));
        $this->roles->edit($this->tenantAdminContext(), $params);
        return $this->success('操作成功');
    }

    public function delete()
    {
        $params = $this->request->post();
        $this->validate($params, ['id' => 'require|integer|gt:0']);
        $this->roles->delete($this->tenantAdminContext(), (int) $params['id']);
        return $this->success('操作成功');
    }

    /**
     * menu_id 是正式契约；menu_ids 仅兼容现有 Peanut 前端。
     * 两者同时存在时始终以 menu_id 为准。
     */
    private function roleParams(): array
    {
        $params = $this->request->post();
        if (!array_key_exists('menu_id', $params) && array_key_exists('menu_ids', $params)) {
            $params['menu_id'] = $params['menu_ids'];
        }
        return $params;
    }
}
