<?php
declare(strict_types=1);

namespace app\platform\controller;

use app\platform\http\PlatformRequest;
use app\platform\services\PlatformOperatorSessionService;
use app\platform\validate\PlatformLoginValidate;
use PeanutAdmin\Kernel\Auth\PlatformRefreshCookie;

final class PlatformSessionController extends BasePlatformController
{
    protected function sessions(): PlatformOperatorSessionService
    {
        return $this->app->make(PlatformOperatorSessionService::class);
    }

    public function login()
    {
        $params = $this->request->post();
        $this->validate($params, PlatformLoginValidate::class);
        $authentication = $this->sessions()->login(
            trim((string)$params['email']),
            (string)$params['password'],
            $this->request->ip(),
            $this->request->header('User-Agent'),
            $this->requestId()
        );

        return $this->data($authentication->responseData())
            ->header(['Set-Cookie' => PlatformRefreshCookie::issue($authentication->tokens->refresh)]);
    }

    public function refresh()
    {
        $token = PlatformRequest::refreshToken($this->request);
        $authentication = $this->sessions()->refresh(
            $token,
            $this->request->ip(),
            $this->request->header('User-Agent'),
            $this->requestId()
        );

        return $this->data($authentication->responseData())
            ->header(['Set-Cookie' => PlatformRefreshCookie::issue($authentication->tokens->refresh)]);
    }

    public function logout()
    {
        $token = PlatformRequest::bearerToken($this->request);
        if ($token !== '') {
            $this->sessions()->logout($token);
        }

        return $this->success('success')->header(['Set-Cookie' => PlatformRefreshCookie::clear()]);
    }

    public function info()
    {
        if ($this->platformContext === null) {
            throw \app\common\http\ApiProblem::fromEnvelope('Platform authentication is required.', null, 40100);
        }
        $permissions = $this->sessions()->permissionKeys($this->platformContext);

        return $this->data([
            'audience' => 'platform',
            'account_id' => (string)$this->platformContext->core->accountId,
            'platform_operator_id' => (string)$this->platformContext->core->operatorId,
            'permissions' => $permissions,
            'navigation' => array_values(array_filter([
                in_array('platform.tenant.read', $permissions, true) ? '/platform/tenants' : null,
                in_array('platform.ops.read', $permissions, true) ? '/platform/ops' : null,
                in_array('platform.operator.read', $permissions, true) ? '/platform/operators' : null,
                in_array('platform.role.read', $permissions, true) ? '/platform/roles' : null,
            ])),
        ]);
    }
}
