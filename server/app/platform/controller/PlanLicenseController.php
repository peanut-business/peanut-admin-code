<?php

declare(strict_types=1);

namespace app\platform\controller;

use think\App;

use PeanutAdmin\Modules\Identity\Platform\Application\PlanLicenseAdminService;

final class PlanLicenseController extends BasePlatformController
{
    protected readonly PlanLicenseAdminService $plans;

    public function __construct(App $app, ?PlanLicenseAdminService $plans = null)
    {
        parent::__construct($app);
        $this->plans = $plans ?? $app->make(PlanLicenseAdminService::class);
    }
    public function lists()
    {
        $page = $this->positiveInteger($this->request->get('page', 1));
        $pageSize = $this->positiveInteger($this->request->get('page_size', 20));
        return $this->data($this->plans->plans($page, $pageSize));
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
        return $this->data($this->plans->tenantLicense($this->positiveInteger($this->request->get('tenant_id'))));
    }

    public function updateLicense()
    {
        return $this->data($this->plans->saveLicense($this->platformContext->core, $this->request->put()));
    }

    private function positiveInteger(mixed $value): int
    {
        if ((!is_int($value) && !is_string($value))
            || preg_match('/^[1-9][0-9]*$/D', (string) $value) !== 1
            || filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) === false) {
            throw \PeanutAdmin\Kernel\Authorization\Application\AdminAccessException::invalid('PLAN_INPUT_INVALID', 'A positive integer is required.');
        }
        return (int) $value;
    }
}
