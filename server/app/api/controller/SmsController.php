<?php
declare(strict_types=1);

namespace app\api\controller;

use app\api\services\SmsApplicationService;
use app\api\validate\SmsValidate;

class SmsController extends BaseApiController
{
    protected function sms(): SmsApplicationService
    {
        return $this->app->make(SmsApplicationService::class);
    }


    public function sendCode()
    {
        $params = $this->request->post();
        $this->validate($params, SmsValidate::class . '.send');
        $this->sms()->sendCode(
            $this->publicTenantContext('notice.verification.send'),
            $params
        );
        return $this->success('发送成功');
    }
}
