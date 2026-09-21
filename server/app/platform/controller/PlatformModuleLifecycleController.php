<?php
declare(strict_types=1);

namespace app\platform\controller;

use app\common\http\PageResult;
use app\platform\services\plugin\PlatformModuleRuntimeService;
use app\platform\infrastructure\plugin\DeterministicTarArchive;
use app\platform\exception\plugin\PluginLifecycleException;
use app\platform\exception\plugin\PluginPackageException;

final class PlatformModuleLifecycleController extends BasePlatformController
{
    protected function moduleRuntime(): PlatformModuleRuntimeService
    {
        return $this->app->make(PlatformModuleRuntimeService::class);
    }

    public function lists()
    {
        $page = $this->positiveInteger($this->request->get('page', 1));
        $pageSize = $this->positiveInteger($this->request->get('page_size', 20));
        if ($pageSize > 100) throw new PluginLifecycleException('PAGE_SIZE_INVALID', 'Page size is invalid.');
        $moduleKey = trim((string)$this->request->get('module_key', ''));
        $result = $this->moduleRuntime()->modules($page, $pageSize, $moduleKey === '' ? null : $moduleKey);
        return $this->dataLists(new PageResult($result['items'], $result['total'], $page, $pageSize));
    }

    public function install()
    {
        $uploaded = $this->request->file('package');
        $expected = strtolower(trim((string)$this->request->post('expected_sha256', '')));
        $keyId = trim((string)$this->request->post('signature_key_id', ''));
        if (!$uploaded || strtolower((string)$uploaded->getOriginalExtension()) !== 'tar'
            || $uploaded->getSize() <= 0 || $uploaded->getSize() > DeterministicTarArchive::MAX_TOTAL_BYTES
            || preg_match('/^[a-f0-9]{64}$/D', $expected) !== 1) {
            throw new PluginPackageException('MODULE_PACKAGE_REQUEST_INVALID', 'Module package request is invalid.');
        }
        return $this->data($this->moduleRuntime()->install(
            $uploaded->getPathname(),
            $expected,
            $keyId === '' ? null : $keyId,
        ));
    }

    public function create()
    {
        $moduleKey = trim((string)$this->request->post('module_key', ''));
        $vendor = trim((string)$this->request->post('vendor', ''));
        return $this->data($this->moduleRuntime()->create(
            $moduleKey,
            $vendor === '' ? null : $vendor,
        ));
    }

    public function uninstall()
    {
        $params = $this->request->post();
        $moduleKey = $this->moduleKey($params['module_key'] ?? null);
        $purge = $this->boolean($params['purge'] ?? false, 'purge');
        $preview = $this->boolean($params['preview'] ?? null, 'preview');
        if ($preview) return $this->data($this->moduleRuntime()->uninstallPreview($moduleKey, $purge));
        $this->changeReason($params['change_reason'] ?? null);
        $plan = $params['confirm_plan'] ?? null;
        $digest = strtolower(trim((string)($params['confirm_plan_digest'] ?? '')));
        $packageKey = trim((string)($params['confirm_package_key'] ?? ''));
        if (!is_array($plan) || array_is_list($plan) || preg_match('/^[a-f0-9]{64}$/D', $digest) !== 1
            || $packageKey === '' || ($plan['package_key'] ?? null) !== $packageKey) {
            throw new PluginLifecycleException('MODULE_UNINSTALL_PLAN_CHANGED', 'Module uninstall confirmation is invalid.');
        }
        return $this->data($this->moduleRuntime()->uninstall($moduleKey, $purge, $plan, $digest));
    }

    public function disable()
    {
        $params = $this->request->post();
        $moduleKey = $this->moduleKey($params['module_key'] ?? null);
        $this->changeReason($params['change_reason'] ?? null);
        return $this->data($this->moduleRuntime()->disable($moduleKey));
    }

    public function sync()
    {
        $moduleKey = trim((string)$this->request->post('module_key', ''));
        if ($moduleKey !== '') $this->moduleKey($moduleKey);
        return $this->data($this->moduleRuntime()->sync($moduleKey === '' ? null : $moduleKey));
    }

    private function moduleKey(mixed $value): string
    {
        $key = trim((string)$value);
        if (preg_match('/^[a-z][a-z0-9]*(?:[.-][a-z0-9]+)*$/D', $key) !== 1 || strlen($key) > 96) {
            throw new PluginLifecycleException('MODULE_KEY_INVALID', 'Module key is invalid.');
        }
        return $key;
    }

    private function boolean(mixed $value, string $field): bool
    {
        if (!is_bool($value)) throw new PluginLifecycleException('MODULE_REQUEST_INVALID', "{$field} must be boolean.");
        return $value;
    }

    private function changeReason(mixed $value): string
    {
        $reason = trim((string)$value);
        if (mb_strlen($reason) < 3 || mb_strlen($reason) > 500) throw new PluginLifecycleException('MODULE_CHANGE_REASON_INVALID', 'Change reason is invalid.');
        return $reason;
    }

    private function positiveInteger(mixed $value): int
    {
        $candidate = is_int($value) ? (string)$value : trim((string)$value);
        if (preg_match('/^[1-9][0-9]*$/D', $candidate) !== 1) throw new PluginLifecycleException('PAGE_INVALID', 'Page is invalid.');
        return (int)$candidate;
    }

}
