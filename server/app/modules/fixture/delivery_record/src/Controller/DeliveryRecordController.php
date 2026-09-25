<?php

declare(strict_types=1);

namespace PeanutAdmin\Fixtures\DeliveryRecord\Controller;

use app\adminapi\controller\BaseAdminController;

/** @property-read DeliveryRecordHttpHandler $handler 当前 App 中声明式解析的控制器依赖。 */
final class DeliveryRecordController extends BaseAdminController
{
    protected string $handlerClass = DeliveryRecordHttpHandler::class;

    public function lists()
    {
        return $this->handler->lists();
    }

    public function record()
    {
        return $this->handler->record((string) $this->request->post('reference', ''));
    }
}
