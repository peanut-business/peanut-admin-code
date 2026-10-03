<?php

declare(strict_types=1);

namespace app\installation\controller;

use app\BaseController;
use app\common\traits\ApiResponseTrait;
use app\common\services\installation\InstallationExecutionHost;
use app\common\services\installation\InstallationConfigurationHost;
use think\facade\Config;
use think\App;

final class InstallationController extends BaseController
{
    use ApiResponseTrait;

    public function __construct(
        App $app,
        private readonly InstallationExecutionHost $host,
        private readonly InstallationConfigurationHost $configuration,
    ) {
        parent::__construct($app);
    }

    public function configuration()
    {
        return $this->data($this->configuration->status());
    }

    public function configure()
    {
        $this->assertSameOrigin();
        return $this->data($this->configuration->configure(
            $this->setupToken(),
            (string) Config::get('peanut.installation.setup_token', ''),
            $this->request->post(),
        ));
    }

    public function status()
    {
        return $this->data($this->host->status());
    }

    public function execute()
    {
        $this->assertSameOrigin();
        return $this->data($this->host->executeGuided($this->setupToken(), $this->request->post()));
    }

    private function assertSameOrigin(): void
    {
        if (!$this->sameOriginRequest()) {
            throw \app\common\http\ApiProblem::fromEnvelope(
                '安装请求来源无效。',
                ['error_code' => 'INSTALL_REQUEST_ORIGIN_INVALID'],
                40300,
            );
        }
    }

    private function setupToken(): string
    {
        $authorization = trim((string) $this->request->header('Authorization', ''));
        return str_starts_with($authorization, 'Bearer ')
            ? trim(substr($authorization, strlen('Bearer ')))
            : '';
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
