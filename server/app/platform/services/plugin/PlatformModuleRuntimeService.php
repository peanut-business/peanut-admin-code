<?php
declare(strict_types=1);

namespace app\platform\services\plugin;

use app\platform\exception\plugin\PluginLifecycleException;
use app\platform\infrastructure\plugin\ModuleCatalogApplier;
use app\platform\infrastructure\plugin\PluginLockResolver;
use app\platform\infrastructure\plugin\PluginPackageInstaller;
use app\platform\policy\plugin\ModuleLifecyclePolicy;
use app\common\persistence\AdvisoryLockExecution;
use app\common\persistence\AdvisoryLockUnavailable;
use app\common\infrastructure\module\ModuleScaffoldGenerator;
use PeanutAdmin\Kernel\Module\ManifestLoader;
use think\facade\Db;

/** Application service shared by Platform HTTP adapters and module:* commands. */
final readonly class PlatformModuleRuntimeService
{
    /** @param array<string,mixed> $moduleConfig @param array<string,string> $trustedPublicKeys */
    public function __construct(
        private string $serverRoot,
        private array $moduleConfig,
        private array $trustedPublicKeys,
        private PluginRuntimeGovernanceService $governance,
        private PluginCatalogSyncService $catalog,
        private ModuleCatalogApplier $catalogs,
    ) {
    }

    /** @return array{items:list<array<string,mixed>>,total:int} */
    public function modules(int $page, int $pageSize, ?string $moduleKey): array
    {
        $descriptors = (new PluginLockResolver($this->serverRoot, (string)($this->moduleConfig['plugin_lock'] ?? '../plugins.lock')))->all();
        $details = [];
        $dependents = [];
        foreach ($descriptors as $descriptor) {
            $packageDetails = [];
            $packageProtected = false;
            foreach ($descriptor->moduleRoots as $key => $root) {
                $manifest = (new ManifestLoader())->load($root);
                $packageProtected = $packageProtected || ModuleLifecyclePolicy::isProtected($manifest);
                $dependencies = [];
                foreach ((array)($manifest->data['dependencies'] ?? []) as $dependency) {
                    if (!is_array($dependency) || !is_string($dependency['module_key'] ?? null)) continue;
                    $dependencies[] = ['module_key' => $dependency['module_key'], 'version' => (string)($dependency['version'] ?? '')];
                    $dependents[$dependency['module_key']][] = $key;
                }
                $packageDetails[$key] = [
                    'module_key' => $key,
                    'name' => (string)($manifest->data['name'] ?? $key),
                    'version' => (string)($manifest->data['version'] ?? ''),
                    'manifest_digest' => $manifest->digest,
                    'package_key' => $descriptor->key,
                    'package_version' => $descriptor->version,
                    'dependencies' => $dependencies,
                ];
            }
            $packageModules = array_keys($packageDetails);
            sort($packageModules, SORT_STRING);
            foreach ($packageDetails as $key => $detail) {
                $detail['package_modules'] = $packageModules;
                $detail['lifecycle_protected'] = $packageProtected;
                $details[$key] = $detail;
            }
        }
        $rows = Db::name('plugin_module')->alias('member')
            ->join('plugin_installation plugin', 'plugin.plugin_key=member.plugin_key')
            ->leftJoin('module_installation installation', 'installation.module_key=member.module_key')
            ->field('member.module_key,member.module_version,member.manifest_digest,member.plugin_key,plugin.installed_version AS package_version,plugin.status AS package_status,installation.status AS module_status,installation.last_error_code')
            ->order('member.module_key')->select()->toArray();
        $enabledCounts = Db::name('tenant_module')->where('status', 'enabled')
            ->field('module_key')->fieldRaw('COUNT(*) AS enabled_count')->group('module_key')->column('enabled_count', 'module_key');
        foreach ($rows as $row) {
            $key = (string)$row['module_key'];
            $details[$key] ??= [
                'module_key' => $key,
                'name' => $key,
                'version' => (string)$row['module_version'],
                'manifest_digest' => (string)$row['manifest_digest'],
                'package_key' => (string)$row['plugin_key'],
                'package_version' => (string)$row['package_version'],
                'dependencies' => [],
                'package_modules' => [$key],
                'lifecycle_protected' => false,
            ];
            $details[$key]['status'] = $row['module_status'] ?? ($row['package_status'] === 'uninstalled' ? 'clean' : $row['package_status']);
            $details[$key]['tenant_enabled_count'] = (int)($enabledCounts[$key] ?? 0);
            $details[$key]['blockers'] = $row['last_error_code'] === null ? [] : [(string)$row['last_error_code']];
        }
        foreach ($details as $key => &$detail) {
            $detail['status'] ??= 'locked';
            $detail['tenant_enabled_count'] ??= 0;
            $detail['blockers'] ??= [];
            $detail['dependents'] = array_values(array_unique($dependents[$key] ?? []));
            sort($detail['dependents'], SORT_STRING);
        }
        unset($detail);
        ksort($details, SORT_STRING);
        if ($moduleKey !== null) $details = isset($details[$moduleKey]) ? [$moduleKey => $details[$moduleKey]] : [];
        $total = count($details);
        return ['items' => array_slice(array_values($details), ($page - 1) * $pageSize, $pageSize), 'total' => $total];
    }

    /** @return array<string,mixed> */
    public function install(string $archivePath, ?string $expectedSha256, ?string $signatureKeyId): array
    {
        $result = (new PluginPackageInstaller(
            $this->serverRoot,
            $this->moduleConfig,
            $this->trustedPublicKeys,
            $this->catalogs,
        ))
            ->install($archivePath, $expectedSha256, $signatureKeyId);
        $moduleKeys = array_values(array_map(static fn(array $module): string => (string)$module['module_key'], $result['modules'] ?? []));
        $catalog = $this->catalog();
        $operation = ($result['operation'] ?? null) === 'unchanged'
            ? 'unchanged'
            : (($result['operation'] ?? null) === 'installed' ? 'installed' : 'reactivated');
        if ($operation !== 'unchanged') $catalog->invalidateTenantAuthorization($moduleKeys);
        $result['operation'] = $operation;
        $result['catalog_revision'] = $catalog->catalogRevision();
        return $result;
    }

    /** @return array<string,mixed> */
    public function update(
        string $archivePath,
        ?string $expectedSha256,
        ?string $signatureKeyId,
        bool $dryRun,
    ): array {
        return (new PluginPackageInstaller(
            $this->serverRoot,
            $this->moduleConfig,
            $this->trustedPublicKeys,
            $this->catalogs,
        ))->update($archivePath, $expectedSha256, $signatureKeyId, $dryRun);
    }

    /** @return array<string,mixed> */
    public function create(string $moduleKey, ?string $vendor = null): array
    {
        return (new ModuleScaffoldGenerator(dirname($this->serverRoot)))->create($moduleKey, $vendor);
    }

    /** @return array<string,mixed> */
    public function uninstallPreview(string $key, bool $purge): array
    {
        return $this->governance->preview($key, $purge);
    }

    /** @param array<string,mixed> $plan @return array<string,mixed> */
    public function uninstall(string $key, bool $purge, array $plan, string $digest): array
    {
        $result = $this->governance->uninstall($key, $purge, $plan, $digest);
        $moduleKeys = array_values(array_map(static fn(array $module): string => (string)$module['module_key'], $result['affected_modules'] ?? []));
        $catalog = $this->catalog();
        $catalog->invalidateTenantAuthorization($moduleKeys);
        return $result + ['catalog_revision' => $catalog->catalogRevision()];
    }

    /** @return array<string,mixed> */
    public function disable(string $moduleKey): array
    {
        $scope = $this->disableScope($moduleKey);
        $packageKey = $scope['package_key'];
        $moduleKeys = array_keys($scope['manifests']);
        $lockName = 'pa:module-runtime:' . substr(hash('sha256', $packageKey), 0, 40);
        $unchanged = null;
        try {
            (new AdvisoryLockExecution())->run($lockName, 0, function () use (
                $scope,
                $packageKey,
                $moduleKeys,
                &$unchanged,
            ): void {
                ModuleLifecyclePolicy::assertMutable($scope['manifests']);
                $statuses = $this->moduleStatuses($moduleKeys);
                if (count($statuses) !== count($moduleKeys)
                    || array_diff(array_values($statuses), ['active', 'maintenance']) !== []) {
                    throw new PluginLifecycleException('MODULE_STATE_INVALID', 'Every Bundle Module must be active or already disabled.');
                }
                if (count(array_filter($statuses, static fn(string $status): bool => $status === 'maintenance')) === count($moduleKeys)) {
                    $unchanged = [
                        'operation' => 'unchanged',
                        'package_key' => $packageKey,
                        'affected_modules' => $moduleKeys,
                        'status' => 'maintenance',
                        'catalog_revision' => $this->catalog()->catalogRevision(),
                    ];
                    return;
                }
                ModuleLifecyclePolicy::assertNoActiveBusinessDependents(
                    new PluginLockResolver(
                        $this->serverRoot,
                        (string)($this->moduleConfig['plugin_lock'] ?? '../plugins.lock'),
                    ),
                    $moduleKeys,
                );
                if (Db::name('tenant_module')->whereIn('module_key', $moduleKeys)->where('status', 'enabled')->count() !== 0) {
                    throw new PluginLifecycleException('PLUGIN_TENANT_MODULE_ACTIVE', 'Disable every TenantModule in the Bundle first.');
                }
                Db::transaction(function () use ($moduleKeys): void {
                    $this->catalogs->retire($moduleKeys);
                    Db::name('module_installation')->whereIn('module_key', $moduleKeys)->where('status', 'active')->update([
                        'status' => 'maintenance', 'last_error_code' => null,
                        'revision' => Db::raw('revision+1'), 'updated_at' => Db::raw('UTC_TIMESTAMP(3)'),
                    ]);
                });
            });
        } catch (AdvisoryLockUnavailable) {
            throw new PluginLifecycleException('MODULE_LIFECYCLE_BUSY', 'Module lifecycle is busy.');
        }
        if (is_array($unchanged)) return $unchanged;
        $catalog = $this->catalog();
        $catalog->invalidateTenantAuthorization($moduleKeys);
        return [
            'operation' => 'disabled',
            'package_key' => $packageKey,
            'affected_modules' => $moduleKeys,
            'status' => 'maintenance',
            'catalog_revision' => $catalog->catalogRevision(),
        ];
    }

    /** @return array<string,mixed> */
    public function sync(?string $moduleKey): array
    {
        $result = $this->catalog()->sync($moduleKey);
        if ($result['operation'] !== 'unchanged') {
            $this->catalog()->invalidateTenantAuthorization($result['modules']);
        }
        return $result;
    }

    private function catalog(): PluginCatalogSyncService
    {
        return $this->catalog;
    }

    /** @return array{package_key:string,manifests:array<string,\PeanutAdmin\Kernel\Module\ManifestDocument>} */
    private function disableScope(string $moduleKey): array
    {
        $packageKey = Db::name('plugin_module')->where('module_key', $moduleKey)->value('plugin_key');
        if (!is_string($packageKey) || $packageKey === '') {
            throw new PluginLifecycleException('PLUGIN_NOT_INSTALLED', 'Module package is not installed.');
        }
        $descriptor = (new PluginLockResolver(
            $this->serverRoot,
            (string)($this->moduleConfig['plugin_lock'] ?? '../plugins.lock'),
        ))->require($packageKey);
        $manifests = [];
        foreach ($descriptor->moduleRoots as $key => $root) {
            $manifests[$key] = (new ManifestLoader())->load($root);
        }
        ksort($manifests, SORT_STRING);
        return ['package_key' => $packageKey, 'manifests' => $manifests];
    }

    /** @param list<string> $moduleKeys @return array<string,string> */
    private function moduleStatuses(array $moduleKeys): array
    {
        return array_map('strval', Db::name('module_installation')->whereIn('module_key', $moduleKeys)
            ->order('module_key')->column('status', 'module_key'));
    }

}
