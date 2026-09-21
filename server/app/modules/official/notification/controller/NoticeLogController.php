<?php
declare(strict_types=1);

namespace app\modules\official\notification\controller;

use app\adminapi\controller\BaseAdminController;
use app\common\execution\CurrentExecutionContext;
use app\modules\official\notification\services\NotificationAdminApplicationService;
use think\App;

class NoticeLogController extends BaseAdminController
{
    public function __construct(
        App $app,
        CurrentExecutionContext $executionContext,
        private readonly NotificationAdminApplicationService $notifications,
    )
    {
        parent::__construct($app, $executionContext);
    }

    public function lists()
    {
        return $this->data($this->notifications->logs(
            $this->tenantAdminContext(),
            $this->tenantAdminActor(),
            $this->request->get(),
        ));
    }

    public function detail()
    {
        $id = (int) $this->request->get('id', 0);
        return $this->data($this->notifications->log(
            $this->tenantAdminContext(),
            $this->tenantAdminActor(),
            $id,
        ));
    }
}
