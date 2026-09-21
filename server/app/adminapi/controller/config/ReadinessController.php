<?php
declare(strict_types=1);

namespace app\adminapi\controller\config;

use app\adminapi\controller\BaseAdminController;
use app\common\services\readiness\FirstRunReadinessHost;
use think\response\Json;

final class ReadinessController extends BaseAdminController
{
    protected function readiness(): FirstRunReadinessHost
    {
        return $this->app->make(FirstRunReadinessHost::class);
    }

    public function checklist(): Json
    {
        return $this->data($this->readiness()->checklist(
            $this->tenantAdminContext(),
            (string)$this->request->domain(),
            (string)config('deployment.mode'),
        ));
    }
}
