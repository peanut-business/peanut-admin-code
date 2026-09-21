<?php
declare(strict_types=1);

namespace app\modules\official\article\controller;

use app\adminapi\controller\BaseAdminController;
use app\common\http\PageResult;
use app\common\traits\CrudTrait;
use app\modules\official\article\contracts\ArticleAdministration;
use app\modules\official\article\validate\ArticleCateValidate;
use PeanutAdmin\Kernel\Auth\TenantContext;
use think\response\Json;

class ArticleCateController extends BaseAdminController
{
    use CrudTrait;

    protected const CRUD_VALIDATE = ArticleCateValidate::class;
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

    public function all(): Json
    {
        $this->resolveCrudContext();
        return $this->data($this->articles()->allCategories());
    }

    protected function renderDetail(array $result): Json
    {
        return $this->data($result);
    }

    protected function performLists(mixed $_context, array $params): PageResult|array
    {
        return $this->articles()->categoryLists($params);
    }

    protected function performDetail(mixed $_context, array $params): array
    {
        return $this->articles()->categoryDetail((int)$params['id']);
    }

    protected function performAdd(mixed $_context, array $params): bool
    {
        $this->articles()->addCategory($params);
        return true;
    }

    protected function performEdit(mixed $_context, array $params): bool
    {
        $this->articles()->editCategory($params);
        return true;
    }

    protected function performDelete(mixed $_context, array $params): bool
    {
        $this->articles()->deleteCategory((int)$params['id']);
        return true;
    }

    protected function performStatusUpdate(mixed $_context, array $params): bool
    {
        $this->articles()->updateCategoryStatus((int)$params['id'], (int)$params['is_show']);
        return true;
    }
}
