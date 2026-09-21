<?php
declare(strict_types=1);

namespace app\modules\official\payment\controller;

use app\adminapi\controller\BaseAdminController;
use app\modules\official\payment\services\RechargeAdministrationService;
use app\modules\official\payment\validate\RechargeValidate;
use app\common\http\JsonResponseFactory;

class RechargeController extends BaseAdminController
{
    protected function recharges(): RechargeAdministrationService
    {
        return $this->app->make(RechargeAdministrationService::class);
    }

    public function lists()
    {
        $params = $this->request->get();
        $context = $this->tenantAdminContext();
        $this->validate($params, RechargeValidate::class . '.lists');
        $result = $this->recharges()->lists($context, $params);
        if ((int)($params['export'] ?? 0) === 2) {
            // 沿用当前统一响应合同；旧 JsonService/show 参数已退出，不能当成响应 code。
            return JsonResponseFactory::success('', $result);
        }
        return $this->data($result);
    }

    public function refund()
    {
        $params = $this->request->post();
        $context = $this->tenantAdminContext();
        $this->validate($params, RechargeValidate::class . '.refund');
        $message = $this->recharges()->refund(
            $context,
            $params,
            $this->adminId,
            trim((string)$this->request->header('Idempotency-Key', '')),
        );
        return $this->success($message);
    }

    public function refundAgain()
    {
        $params = $this->request->post();
        $context = $this->tenantAdminContext();
        $this->validate($params, RechargeValidate::class . '.again');
        $message = $this->recharges()->refundAgain($context, $params, $this->adminId);
        return $this->success($message);
    }

}
