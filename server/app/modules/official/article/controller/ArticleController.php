<?php
declare(strict_types=1);

namespace app\modules\official\article\controller;

use app\adminapi\controller\BaseAdminController;
use app\common\http\PageResult;
use app\common\traits\CrudTrait;
use app\modules\official\article\contracts\ArticleAdministration;
use app\modules\official\article\validate\ArticleValidate;
use PeanutAdmin\Kernel\Auth\TenantContext;
use think\response\Json;

class ArticleController extends BaseAdminController
{
    use CrudTrait;

    protected const CRUD_VALIDATE = ArticleValidate::class;
    protected const CRUD_ADD_SUCCESS_MESSAGE = '添加成功';
    protected const CRUD_EDIT_SUCCESS_MESSAGE = '编辑成功';
    protected const CRUD_DELETE_SUCCESS_MESSAGE = '删除成功';
    protected const CRUD_STATUS_SUCCESS_MESSAGE = '修改成功';
    protected const CRUD_VALIDATE_LISTS = true;
    protected const CRUD_STATUS_FIELD = 'is_show';

    protected function articles(): ArticleAdministration
    {
        return $this->app->get(ArticleAdministration::class);
    }

    protected function resolveCrudContext(): TenantContext
    {
        return $this->tenantAdminContext();
    }

    protected function crudService(): object
    {
        return $this->articles();
    }

    protected function renderDetail(array $result): Json
    {
        return $this->data($result);
    }

    protected function performLists(mixed $_context, array $params): PageResult|array
    {
        return $this->articles()->lists($params);
    }

    protected function performDetail(mixed $_context, array $params): array
    {
        return $this->articles()->detail((int)$params['id']);
    }

    protected function performAdd(mixed $_context, array $params): bool
    {
        $this->articles()->add($params);
        return true;
    }

    protected function performEdit(mixed $_context, array $params): bool
    {
        $this->articles()->edit($params);
        return true;
    }

    protected function performDelete(mixed $_context, array $params): bool
    {
        $this->articles()->delete((int)$params['id']);
        return true;
    }

    protected function performStatusUpdate(mixed $_context, array $params): bool
    {
        $this->articles()->updateStatus((int)$params['id'], (int)$params['is_show']);
        return true;
    }
}
