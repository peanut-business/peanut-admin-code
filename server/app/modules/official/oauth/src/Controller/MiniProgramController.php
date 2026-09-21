<?php
declare(strict_types=1);

namespace PeanutAdmin\Modules\OAuth\Controller;

use think\App;
use app\common\execution\CurrentExecutionContext;

use app\adminapi\controller\BaseAdminController;
use PeanutAdmin\Modules\OAuth\Service\MiniProgramApplicationService;
use PeanutAdmin\Modules\OAuth\Validation\MiniProgramValidate;

class MiniProgramController extends BaseAdminController
{
    public function __construct(App $app, CurrentExecutionContext $executionContext, private readonly MiniProgramApplicationService $miniPrograms)
    {
        parent::__construct($app, $executionContext);
    }

    public function getConfig()
    {
        return $this->data($this->miniPrograms->getConfig(
            $this->tenantAdminContext(),
            (string)$this->request->domain(),
        ));
    }

    public function setConfig()
    {
        $params = $this->request->post();
        $this->validate($params, MiniProgramValidate::class);
        $this->miniPrograms->setConfig($this->tenantAdminContext(), $params);
        return $this->success('操作成功');
    }
}
