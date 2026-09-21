<?php
declare(strict_types=1);

namespace app\modules\official\reference_codes\controllers;

use app\adminapi\controller\BaseAdminController;
use app\modules\official\reference_codes\services\DictDataApplicationService;
use app\modules\official\reference_codes\validation\DictDataValidate;
use app\common\traits\CrudTrait;
use PeanutAdmin\Kernel\Auth\TenantContext;
use think\response\Json;

class DictDataController extends BaseAdminController
{
    use CrudTrait;

    protected function dictionaryData(): DictDataApplicationService
    {
        return $this->app->make(DictDataApplicationService::class);
    }
    protected const CRUD_VALIDATE = DictDataValidate::class;
    protected const CRUD_NOT_FOUND_MESSAGE = '字典数据不存在';

    protected function resolveCrudContext(): TenantContext
    {
        return $this->tenantAdminContext();
    }

    protected function crudService(): object
    {
        return $this->dictionaryData();
    }

    /** 按类型标识取启用数据项（业务前端用） */
    public function byType(): Json
    {
        return $this->data($this->dictionaryData()->byType(
            $this->resolveCrudContext(),
            (string) $this->request->get('type_value', ''),
        ));
    }
}
