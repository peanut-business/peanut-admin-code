<?php
declare(strict_types=1);

namespace PeanutAdmin\Modules\OAuth\Controller;

use think\App;
use app\common\execution\CurrentExecutionContext;

use app\adminapi\controller\BaseAdminController;
use PeanutAdmin\Modules\OAuth\Service\OpenPlatformApplicationService;
use PeanutAdmin\Modules\OAuth\Validation\OpenPlatformValidate;

class OpenPlatformController extends BaseAdminController
{
    public function __construct(App $app, CurrentExecutionContext $executionContext, private readonly OpenPlatformApplicationService $openPlatforms)
    {
        parent::__construct($app, $executionContext);
    }

    public function getConfig()
    {
        return $this->data($this->openPlatforms->getConfig($this->tenantAdminContext()));
    }

    public function setConfig()
    {
        $params = $this->request->post();
        $this->validate($params, OpenPlatformValidate::class);
        $this->openPlatforms->setConfig($this->tenantAdminContext(), $params);
        return $this->success('操作成功');
    }
}
