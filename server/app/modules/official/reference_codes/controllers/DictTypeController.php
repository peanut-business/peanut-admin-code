<?php
declare(strict_types=1);

namespace app\modules\official\reference_codes\controllers;

use app\adminapi\controller\BaseAdminController;
use app\modules\official\reference_codes\services\DictTypeApplicationService;
use app\modules\official\reference_codes\validation\DictTypeValidate;
use app\common\traits\CrudTrait;
use PeanutAdmin\Kernel\Auth\TenantContext;
use think\response\Json;

class DictTypeController extends BaseAdminController
{
    use CrudTrait;

    protected function dictionaryTypes(): DictTypeApplicationService
    {
        return $this->app->make(DictTypeApplicationService::class);
    }
    protected const CRUD_VALIDATE = DictTypeValidate::class;
    protected const CRUD_NOT_FOUND_MESSAGE = '字典类型不存在';

    protected function resolveCrudContext(): TenantContext
    {
        return $this->tenantAdminContext();
    }

    protected function crudService(): object
    {
        return $this->dictionaryTypes();
    }

    public function all(): Json
    {
        return $this->data($this->dictionaryTypes()->all($this->resolveCrudContext()));
    }
}
