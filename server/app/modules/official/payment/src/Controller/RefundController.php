<?php
declare(strict_types=1);

namespace PeanutAdmin\Modules\Payment\Controller;

use think\App;
use app\common\execution\CurrentExecutionContext;

use app\adminapi\controller\BaseAdminController;
use PeanutAdmin\Modules\Payment\Service\RefundApplicationService;
use PeanutAdmin\Modules\Payment\Validation\RefundValidate;

/**
 * 退款控制器
 */
class RefundController extends BaseAdminController
{
    public function __construct(App $app, CurrentExecutionContext $executionContext, private readonly RefundApplicationService $refunds)
    {
        parent::__construct($app, $executionContext);
    }

    /** 退款统计（四个金额汇总） */
    public function stat()
    {
        return $this->data($this->refunds->stat($this->tenantAdminContext()));
    }

    /** 退款记录列表（分页） */
    public function record()
    {
        $params = $this->request->get();
        $this->validate($params, RefundValidate::class . '.record');
        return $this->data($this->refunds->lists($this->tenantAdminContext(), $params));
    }

    /** 退款日志（某条退款记录的操作流水） */
    public function log()
    {
        $params = $this->request->get();
        $this->validate($params, RefundValidate::class . '.log');
        $recordId = (int)$params['record_id'];
        return $this->data($this->refunds->refundLog(
            $this->tenantAdminContext(),
            $recordId
        ));
    }
}
