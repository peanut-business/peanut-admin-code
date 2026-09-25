<?php

declare(strict_types=1);

namespace app\installation\controller;

use app\BaseController;
use app\common\traits\ApiResponseTrait;
use app\common\services\installation\InstallationExecutionHost;
use think\App;

final class InstallationController extends BaseController
{
    use ApiResponseTrait;

    public function __construct(App $app, private readonly InstallationExecutionHost $host)
    {
        parent::__construct($app);
    }

    public function status()
    {
        return $this->data($this->host->status());
    }

    public function execute()
    {
        if (!$this->sameOriginRequest()) {
            throw \app\common\http\ApiProblem::fromEnvelope(
                '安装请求来源无效。',
                ['error_code' => 'INSTALL_REQUEST_ORIGIN_INVALID'],
                40300,
            );
        }
        $authorization = trim((string) $this->request->header('Authorization', ''));
        $token = str_starts_with($authorization, 'Bearer ')
            ? trim(substr($authorization, strlen('Bearer ')))
            : '';
        return $this->data($this->host->executeGuided($token, $this->request->post()));
    }

    private function sameOriginRequest(): bool
    {
        $fetchSite = strtolower(trim((string) $this->request->header('Sec-Fetch-Site', '')));
        if ($fetchSite === 'cross-site') {
            return false;
        }
        $origin = trim((string) $this->request->header('Origin', ''));
        if ($origin === '') {
            return true;
        }
        $originHost = parse_url($origin, PHP_URL_HOST);
        $requestHost = explode(':', strtolower((string) $this->request->host()))[0];
        return is_string($originHost) && hash_equals($requestHost, strtolower($originHost));
    }
}
