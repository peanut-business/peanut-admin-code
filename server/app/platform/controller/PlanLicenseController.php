<?php

declare(strict_types=1);

namespace app\platform\controller;

use PeanutAdmin\Modules\Identity\Platform\Application\PlanLicenseAdminService;

/** @property-read PlanLicenseAdminService $plans */
final class PlanLicenseController extends BasePlatformController
{
    protected string $plansClass = PlanLicenseAdminService::class;

    public function lists()
    {
        $page = filter_var($this->request->get('page', 1), FILTER_VALIDATE_INT);
        $pageSize = filter_var($this->request->get('page_size', 20), FILTER_VALIDATE_INT);
        return $this->data($this->plans->plans($page === false ? 0 : $page, $pageSize === false ? 0 : $pageSize));
    }

    public function create()
    {
        return $this->data($this->plans->savePlan($this->platformContext->core, $this->request->post(), true));
    }

    public function update()
    {
        return $this->data($this->plans->savePlan($this->platformContext->core, $this->request->put(), false));
    }

    public function license()
    {
        $id = filter_var($this->request->get('tenant_id'), FILTER_VALIDATE_INT);
        return $this->data($this->plans->tenantLicense($id === false ? 0 : $id));
    }

    public function updateLicense()
    {
        return $this->data($this->plans->saveLicense($this->platformContext->core, $this->request->put()));
    }
}
