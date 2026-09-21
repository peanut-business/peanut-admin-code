<?php
declare(strict_types=1);

namespace PeanutAdmin\Modules\ReferenceCodes\Controller;

use think\App;
use app\common\execution\CurrentExecutionContext;

use app\adminapi\controller\BaseAdminController;
use PeanutAdmin\Modules\ReferenceCodes\Service\DictTypeApplicationService;
use PeanutAdmin\Modules\ReferenceCodes\Validation\DictTypeValidate;
use app\common\traits\CrudTrait;
use PeanutAdmin\Kernel\Auth\TenantContext;
use think\response\Json;

class DictTypeController extends BaseAdminController
{
    use CrudTrait;

    public function __construct(App $app, CurrentExecutionContext $executionContext, private readonly DictTypeApplicationService $dictionaryTypes)
    {
        parent::__construct($app, $executionContext);
    }
    protected const CRUD_VALIDATE = DictTypeValidate::class;
    protected const CRUD_NOT_FOUND_MESSAGE = '字典类型不存在';

    protected function resolveCrudContext(): TenantContext
    {
        return $this->tenantAdminContext();
    }

    protected function crudService(): object
    {
        return $this->dictionaryTypes;
    }

    public function all(): Json
    {
        return $this->data($this->dictionaryTypes->all($this->resolveCrudContext()));
    }
}
