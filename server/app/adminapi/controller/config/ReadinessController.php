<?php

declare(strict_types=1);

namespace app\adminapi\controller\config;

use think\App;

use app\adminapi\controller\BaseAdminController;
use app\common\services\readiness\FirstRunReadinessHost;
use think\response\Json;

final class ReadinessController extends BaseAdminController
{
    protected readonly FirstRunReadinessHost $readiness;

    public function __construct(App $app, ?FirstRunReadinessHost $readiness = null)
    {
        parent::__construct($app);
        $this->readiness = $readiness ?? $app->make(FirstRunReadinessHost::class);
    }
    public function checklist(): Json
    {
        return $this->data($this->readiness->checklist(
            $this->tenantAdminContext(),
            (string) $this->request->domain(),
            (string) config('deployment.mode'),
        ));
    }
}
