<?php
declare(strict_types=1);

namespace app\adminapi\controller\config;

use app\adminapi\controller\BaseAdminController;
use app\adminapi\services\config\ConfigApplicationService;
use app\adminapi\validate\config\WebsiteValidate;

class ConfigController extends BaseAdminController
{
    protected function configuration(): ConfigApplicationService
    {
        return $this->app->make(ConfigApplicationService::class);
    }

    public function getWebsite()
    {
        return $this->data($this->configuration()->getWebsite($this->tenantAdminContext()));
    }

    public function saveWebsite()
    {
        $this->configuration()->saveWebsite(
                $this->tenantAdminContext(),
                $this->request->post()
        );
        return $this->success('操作成功');
    }

    public function getCopyright() { return $this->data($this->configuration()->getCopyright($this->tenantAdminContext())); }
    public function saveCopyright() { return $this->save('copyright', 'saveCopyright'); }
    public function getAgreement() { return $this->data($this->configuration()->getAgreement($this->tenantAdminContext())); }
    public function saveAgreement() { return $this->save('agreement', 'saveAgreement'); }
    public function getStatistics() { return $this->data($this->configuration()->getStatistics($this->tenantAdminContext())); }
    public function saveStatistics() { return $this->save('statistics', 'saveStatistics'); }
    public function getUser() { return $this->data($this->configuration()->getUser($this->tenantAdminContext())); }
    public function saveUser() { return $this->save('user', 'saveUser'); }
    public function getLogin() { return $this->data($this->configuration()->getLogin($this->tenantAdminContext())); }
    public function saveLogin() { return $this->save('login', 'saveLogin'); }

    private function save(string $scene, string $method)
    {
        $params = $this->request->post();
        $this->validate($params, WebsiteValidate::class . '.' . $scene);
        $this->configuration()->$method($this->tenantAdminContext(), $params);
        return $this->success('操作成功');
    }
}
