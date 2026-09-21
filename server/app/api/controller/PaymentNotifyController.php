<?php
declare(strict_types=1);

namespace app\api\controller;

use app\api\services\PaymentCallbackApplicationService;
use app\common\dto\payment\CallbackRequest;
use app\common\execution\CurrentExecutionContext;
use app\common\http\RequestTrace;
use think\App;

/** 支付回调 HTTP 适配：保留签名原文和渠道确认格式，不在控制器编排入账。 */
class PaymentNotifyController extends BaseApiController
{
    public function __construct(
        App $app,
        CurrentExecutionContext $executionContext,
        private readonly PaymentCallbackApplicationService $application,
    ) {
        parent::__construct($app, $executionContext);
    }

    public function wechat()
    {
        $this->application->wechat(
            new CallbackRequest((string)$this->request->getContent(), (array)$this->request->header()),
            (string)$this->request->route('binding'),
            $this->operationId(),
        );
        return json(['code' => 'SUCCESS', 'message' => '成功']);
    }

    public function alipay()
    {
        $this->application->alipay(
            new CallbackRequest('', [], $this->request->post()),
            (string)$this->request->route('binding'),
            $this->operationId(),
        );
        return response('success');
    }

    private function operationId(): string
    {
        return RequestTrace::id($this->executionContext, $this->request, 'payment');
    }
}
