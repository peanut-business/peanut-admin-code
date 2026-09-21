<?php
declare(strict_types=1);

namespace PeanutAdmin\Fixtures\DeliveryRecord\Controller;

use app\adminapi\controller\BaseAdminController;
use app\common\execution\CurrentExecutionContext;
use think\App;

final class DeliveryRecordController extends BaseAdminController
{
    public function __construct(
        App $app,
        CurrentExecutionContext $executionContext,
        private readonly DeliveryRecordHttpHandler $handler,
    )
    {
        parent::__construct($app, $executionContext);
    }

    public function lists()
    {
        return $this->handler->lists();
    }

    public function record()
    {
        return $this->handler->record((string)$this->request->post('reference', ''));
    }
}
