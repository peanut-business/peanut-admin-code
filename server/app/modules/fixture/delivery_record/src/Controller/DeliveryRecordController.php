<?php
declare(strict_types=1);

namespace PeanutAdmin\Fixtures\DeliveryRecord\Controller;

use app\adminapi\controller\BaseAdminController;

final class DeliveryRecordController extends BaseAdminController
{
    protected function handler(): DeliveryRecordHttpHandler
    {
        return $this->app->make(DeliveryRecordHttpHandler::class);
    }

    public function lists()
    {
        return $this->handler()->lists();
    }

    public function record()
    {
        return $this->handler()->record((string)$this->request->post('reference', ''));
    }
}
