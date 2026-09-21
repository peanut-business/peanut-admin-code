<?php
declare(strict_types=1);

namespace PeanutAdmin\Modules\Notification\Controller;

use app\adminapi\controller\BaseAdminController;
use PeanutAdmin\Modules\Notification\Service\NotificationAdminApplicationService;

class NoticeLogController extends BaseAdminController
{
    protected function notifications(): NotificationAdminApplicationService
    {
        return $this->app->make(NotificationAdminApplicationService::class);
    }

    public function lists()
    {
        return $this->data($this->notifications()->logs(
            $this->tenantAdminContext(),
            $this->tenantAdminActor(),
            $this->request->get(),
        ));
    }

    public function detail()
    {
        $id = (int) $this->request->get('id', 0);
        return $this->data($this->notifications()->log(
            $this->tenantAdminContext(),
            $this->tenantAdminActor(),
            $id,
        ));
    }
}
