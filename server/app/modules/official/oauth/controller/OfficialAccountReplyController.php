<?php
declare(strict_types=1);

namespace app\modules\official\oauth\controller;

use app\adminapi\controller\BaseAdminController;
use app\common\traits\CrudTrait;
use app\modules\official\oauth\services\OfficialAccountReplyApplicationService;
use app\modules\official\oauth\validate\OfficialAccountReplyValidate;
use PeanutAdmin\Kernel\Auth\TenantContext;

class OfficialAccountReplyController extends BaseAdminController
{
    use CrudTrait;

    protected const CRUD_VALIDATE = OfficialAccountReplyValidate::class;
    protected const CRUD_NOT_FOUND_MESSAGE = '自动回复不存在';
    protected const CRUD_DELETE_SUCCESS_MESSAGE = '删除成功';
    protected const CRUD_VALIDATE_LISTS = true;
    protected const CRUD_STATUS_FIELD = 'status';

    protected function replies(): OfficialAccountReplyApplicationService
    {
        return $this->app->make(OfficialAccountReplyApplicationService::class);
    }

    protected function resolveCrudContext(): TenantContext
    {
        return $this->tenantAdminContext();
    }

    protected function crudService(): object
    {
        return $this->replies();
    }
}
