<?php

declare(strict_types=1);

namespace app\platform\services\plugin;

use app\platform\composition\plugin\PluginModuleRegistryFactory;
use app\platform\exception\plugin\PluginLifecycleException;
use app\platform\infrastructure\plugin\ModuleCatalogApplier;
use app\platform\infrastructure\plugin\ModuleMigrationSqlExecutor;
use app\platform\infrastructure\plugin\PluginLockResolver;
use app\platform\policy\plugin\ModuleLifecyclePolicy;
use app\platform\value\plugin\PluginDescriptor;
use app\common\contract\module\PluginLifecycleCommands;
use app\common\persistence\AdvisoryLockExecution;
use app\common\persistence\AdvisoryLockUnavailable;
use PeanutAdmin\Kernel\Module\ManifestDocument;
use think\facade\Db;

/** Deployment-scoped Plugin lifecycle. It deliberately never mutates pa_tenant_module. */
final readonly class PluginLifecycleService implements PluginLifecycleCommands
{
    /** @param array<string,mixed> $moduleConfig */
    public function __construct(
        private PluginLockResolver $resolver,
        private PluginModuleRegistryFactory $registries,
        private array $moduleConfig,
        private ModuleCatalogApplier $catalogs,
        private ModuleMigrationSqlExecutor $migrationSql,
    ) {}

    /** @return array<string,mixed> */
    public function install(string $pluginKey, bool $acquireLock = true): array
    {
        $plugin = $this->resolver->require($pluginKey);
        $this->assertTrustEligible($plugin);
        $lockName = $this->lockName($pluginKey);
        $operation = function () use ($plugin, $pluginKey): array {
            $manifests = $this->pluginManifests($plugin);
            $this->assertPreflightOwnership($plugin, $manifests, false);
            $current = $this->pluginInstallation($pluginKey, false);
            if (is_array($current) && $this->sameIdentity($plugin, $current) && $current['status'] === 'active') {
                if ($this->allPluginModulesActive($pluginKey)) {
                    return $plugin->publicIdentity() + ['operation' => 'unchanged'];
                }
                $result = $this->activate($plugin, $manifests, false);
                $result['operation'] = 'reactivated';
                return $result;
            }
            if (is_array($current) && $current['status'] === 'maintenance'
                && in_array($current['last_error_code'] ?? null, ['MODULE_PURGE_IN_PROGRESS', 'MODULE_RETIRE_IN_PROGRESS'], true)) {
                throw new PluginLifecycleException((string) $current['last_error_code'], 'Module uninstall recovery must finish before install.');
            }
            if (is_array($current) && in_array($current['status'], ['failed', 'installing'], true)) {
                $sameIdentity = $this->sameIdentity($plugin, $current);
                $forwardRepair = version_compare($plugin->version, (string) $current['installed_version'], '>');
                if (!$sameIdentity && !$forwardRepair) {
                    throw new PluginLifecycleException(
                        'PLUGIN_INSTALL_RECOVERY_IDENTITY_MISMATCH',
                        'Install recovery requires the same immutable package or a higher repair version.',
                    );
                }
                $result = $this->activate($plugin, $manifests, false);
                $result['operation'] = $forwardRepair ? 'recovered' : 'resumed';
                return $result;
            }
            if (is_array($current) && !in_array($current['status'], ['uninstalled'], true)) {
                throw new PluginLifecycleException('PLUGIN_ALREADY_INSTALLED', 'Use plugin:upgrade for an installed Plugin.');
            }
            return $this->activate($plugin, $manifests, false);
        };
        if (!$acquireLock) {
            return $operation();
        }
        try {
            return (new AdvisoryLockExecution())->run($lockName, 0, $operation);
        } catch (AdvisoryLockUnavailable) {
            throw new PluginLifecycleException('MODULE_LIFECYCLE_BUSY', 'Module lifecycle is busy.');
        }
    }

    /** @return array<string,mixed> */
    public function reconcile(string $pluginKey): array
    {
        $current = $this->pluginInstallation($pluginKey, false);
        if (!is_array($current)) {
            return $this->install($pluginKey);
        }

        if ((string) $current['status'] !== 'active') {
            throw new PluginLifecycleException(
                'PLUGIN_STATE_INVALID',
                'Plugin reconciliation only accepts an active installation.',
            );
        }

        $plugin = $this->resolver->require($pluginKey);
        $this->assertTrustEligible($plugin);
        if ($this->sameIdentity($plugin, $current)) {
            return $plugin->publicIdentity() + ['operation' => 'unchanged'];
        }

        return $this->upgrade($pluginKey, false);
    }

    /** @return array<string,mixed> */
    public function upgrade(string $pluginKey, bool $dryRun): array
    {
        $plugin = $this->resolver->require($pluginKey);
        $this->assertTrustEligible($plugin);
        $manifests = $this->pluginManifests($plugin);
        $this->assertPreflightOwnership($plugin, $manifests, true);
        $current = $this->pluginInstallation($pluginKey, false);
        if (!is_array($current)) {
            throw new PluginLifecycleException('PLUGIN_NOT_INSTALLED', 'Plugin must be installed before upgrade.');
        }
        if (!in_array($current['status'], ['active', 'failed'], true)) {
            throw new PluginLifecycleException('PLUGIN_STATE_INVALID', 'Plugin cannot be upgraded from its current state.');
        }
        if (version_compare($plugin->version, (string) $current['installed_version'], '<')) {
            throw new PluginLifecycleException('PLUGIN_DOWNGRADE_REJECTED', 'Upgrade cannot install an older Plugin version.');
        }
        $plan = $this->plan($plugin, $manifests, $current);
        if ($dryRun || ($this->sameIdentity($plugin, $current) && $current['status'] === 'active')) {
            return $plan + ['dry_run' => $dryRun, 'operation' => $dryRun ? 'upgrade' : 'unchanged'];
        }
        return $this->activate($plugin, $manifests, true) + ['plan' => $plan];
    }

    /** @return array<string,mixed> */
    public function rollbackPlan(string $pluginKey): array
    {
        $current = $this->pluginInstallation($pluginKey, false);
        if (!is_array($current)) {
            throw new PluginLifecycleException('PLUGIN_NOT_INSTALLED', 'Plugin is not installed.');
        }
        $moduleKeys = Db::name('plugin_module')->where('plugin_key', $pluginKey)->column('module_key');
        return [
            'plugin_key' => $pluginKey,
            'installed_version' => (string) $current['installed_version'],
            'operation' => 'rollback-plan',
            'automatic' => false,
            'preserve_data' => true,
            'steps' => [
                'place the previously verified Plugin artifact in plugins.lock',
                'restore a verified database backup when an applied migration is irreversible',
                'run plugin:upgrade with the restored immutable identity',
            ],
            'applied_migrations' => $moduleKeys === [] ? [] : Db::name('module_migration')
                ->whereIn('module_key', $moduleKeys)->field('module_key,migration_key,module_version,checksum,status')
                ->order('id', 'desc')->select()->toArray(),
        ];
    }

    /** @return array<string,mixed> */
    public function uninstall(string $pluginKey): array
    {
        $current = $this->pluginInstallation($pluginKey, false);
        if (!is_array($current) || $current['status'] === 'uninstalled') {
            throw new PluginLifecycleException('PLUGIN_NOT_INSTALLED', 'Plugin is not installed.');
        }
        $plugin = $this->resolver->require($pluginKey);
        $manifests = $this->pluginManifests($plugin);
        ModuleLifecyclePolicy::assertMutable($manifests);
        ModuleLifecyclePolicy::assertNoActiveBusinessDependents(
            $this->resolver,
            array_keys($manifests),
        );
        $ownedModuleKeys = Db::name('plugin_module')->where('plugin_key', $pluginKey)->column('module_key');
        if ($ownedModuleKeys !== [] && Db::name('tenant_module')->whereIn('module_key', $ownedModuleKeys)->where('status', 'enabled')->count() !== 0) {
            throw new PluginLifecycleException('PLUGIN_TENANT_MODULE_ACTIVE', 'Disable every TenantModule before uninstall.');
        }
        $modules = $this->pluginModuleRows($pluginKey);
        $now = $this->now();
        Db::transaction(function () use ($pluginKey, $modules, $now): void {
            $this->catalogs->retire(array_map(
                static fn(array $row): string => (string) $row['module_key'],
                $modules,
            ));
            foreach ($modules as $row) {
                Db::name('module_installation')->where('module_key', $row['module_key'])->update([
                    'status' => 'maintenance', 'revision' => Db::raw('revision+1'), 'updated_at' => $now,
                ]);
            }
            Db::name('plugin_installation')->where('plugin_key', $pluginKey)->update([
                'status' => 'uninstalled',
                'revision' => Db::raw('revision+1'),
                'uninstalled_at' => $now,
                'last_error_code' => null,
                'updated_at' => $now,
            ]);
        });
        return [
            'plugin_key' => $pluginKey,
            'operation' => 'uninstall',
            'status' => 'uninstalled',
            'preserve_data' => true,
            'preserved' => ['module_migrations', 'plugin_module_ownership', 'business_data'],
        ];
    }

    /** @param array<string,ManifestDocument> $manifests @return array<string,mixed> */
    private function activate(PluginDescriptor $plugin, array $manifests, bool $upgrade): array
    {
        $this->beginLifecycle($plugin, $manifests, $upgrade);
        try {
            $this->applyMigrations($plugin, $manifests);
            $this->registerCatalog($plugin, $manifests, $upgrade);
            return $plugin->publicIdentity() + ['operation' => $upgrade ? 'upgraded' : 'installed'];
        } catch (\Throwable $exception) {
            $errorCode = $exception instanceof PluginLifecycleException
                ? $exception->errorCode
                : 'PLUGIN_LIFECYCLE_FAILED';
            $this->markFailed($plugin, $manifests, $errorCode);
            throw $exception;
        }
    }

    /** @param array<string,ManifestDocument> $manifests */
    private function beginLifecycle(PluginDescriptor $plugin, array $manifests, bool $upgrade): void
    {
        $now = $this->now();
        Db::transaction(function () use ($plugin, $manifests, $upgrade, $now): void {
            $parameters = $this->pluginParameters($plugin);
            $values = [
                'installed_version' => $parameters['version'], 'source' => $parameters['source'],
                'artifact_sha256' => $parameters['artifact_sha256'], 'lock_digest' => $parameters['lock_digest'],
                'composer_identity_json' => $parameters['composer'], 'npm_identity_json' => $parameters['npm'],
                'frontend_identity_json' => $parameters['frontend'], 'status' => 'installing',
                'last_error_code' => null, 'updated_at' => $now,
            ];
            $existing = Db::name('plugin_installation')->where('plugin_key', $plugin->key)->lock(true)->value('plugin_key');
            if ($existing === null) {
                Db::name('plugin_installation')->insert([
                    'plugin_key' => $plugin->key, ...$values, 'revision' => 1,
                    'installed_at' => $now, 'created_at' => $now,
                ]);
            } else {
                Db::name('plugin_installation')->where('plugin_key', $plugin->key)
                    ->update([...$values, 'revision' => Db::raw('revision+1')]);
            }
            foreach ($manifests as $manifest) {
                $moduleKey = (string) $manifest->data['key'];
                $moduleValues = [
                    'installed_version' => $manifest->data['version'],
                    'manifest_schema_version' => $manifest->data['schema_version'],
                    'manifest_digest' => $manifest->digest,
                    'status' => $upgrade ? 'upgrading' : 'installing',
                    'last_error_code' => null, 'updated_at' => $now,
                ];
                $moduleExists = Db::name('module_installation')->where('module_key', $moduleKey)->lock(true)->value('module_key');
                if ($moduleExists === null) {
                    Db::name('module_installation')->insert([
                        'module_key' => $moduleKey, ...$moduleValues, 'revision' => 1,
                        'installed_at' => $now, 'created_at' => $now,
                    ]);
                } else {
                    Db::name('module_installation')->where('module_key', $moduleKey)
                        ->update([...$moduleValues, 'revision' => Db::raw('revision+1')]);
                }
            }
        });
    }

    /** @param array<string,ManifestDocument> $manifests */
    private function assertPreflightOwnership(
        PluginDescriptor $plugin,
        array $manifests,
        bool $upgrade,
    ): void {
        foreach ($manifests as $moduleKey => $_manifest) {
            $existing = Db::name('plugin_module')->where('module_key', $moduleKey)->value('plugin_key');
            if (is_string($existing) && $existing !== $plugin->key) {
                throw new PluginLifecycleException('PLUGIN_MODULE_CONFLICT', "Module has another Plugin owner: {$moduleKey}");
            }
            if ($upgrade && !is_string($existing)) {
                throw new PluginLifecycleException('PLUGIN_MODULE_OWNERSHIP_MISSING', "Plugin does not own Module: {$moduleKey}");
            }
        }
    }

    /** @param array<string,ManifestDocument> $manifests */
    private function applyMigrations(PluginDescriptor $plugin, array $manifests): void
    {
        $batch = (int) Db::name('module_migration')->max('batch_no') + 1;
        foreach ($manifests as $moduleKey => $manifest) {
            $files = $this->migrationFiles($plugin->moduleRoots[$moduleKey], $manifest);
            $repairs = $this->migrationRepairMap($moduleKey, $files);
            foreach ($files as $migrationKey => $path) {
                $checksum = hash_file('sha256', $path);
                if (!is_string($checksum)) {
                    throw new PluginLifecycleException('MODULE_MIGRATION_INVALID', "Migration is unreadable: {$path}");
                }
                $row = Db::name('module_migration')->where('module_key', $moduleKey)
                    ->where('migration_key', $migrationKey)->field('checksum,status')->find();
                if ($row !== null) {
                    if (!hash_equals((string) $row['checksum'], $checksum)) {
                        throw new PluginLifecycleException(
                            'MODULE_MIGRATION_CHECKSUM_MISMATCH',
                            "Applied Module migration changed: {$migrationKey}",
                        );
                    }
                    if ($row['status'] === 'applied') {
                        continue;
                    }
                    if (!isset($repairs[$migrationKey])) {
                        throw new PluginLifecycleException(
                            'MODULE_MIGRATION_REPAIR_REQUIRED',
                            "Migration has an uncertain partial state and requires an append-only repair: {$migrationKey}",
                        );
                    }
                    continue;
                }
                $now = $this->now();
                Db::transaction(function () use ($moduleKey, $migrationKey, $manifest, $checksum, $batch, $now): void {
                    Db::name('module_migration')->insert([
                        'module_key' => $moduleKey,
                        'migration_key' => $migrationKey,
                        'module_version' => $manifest->data['version'],
                        'checksum' => $checksum,
                        'batch_no' => $batch,
                        'status' => 'applying',
                        'started_at' => $now,
                    ]);
                });
                try {
                    $sql = trim((string) file_get_contents($path));
                    if ($sql === '') {
                        throw new PluginLifecycleException('MODULE_MIGRATION_INVALID', "Migration is empty: {$migrationKey}");
                    }
                    // MySQL DDL commits implicitly. Keep the durable migration ledger outside the DDL boundary.
                    $this->migrationSql->execute($sql);
                    Db::transaction(function () use ($moduleKey, $migrationKey): void {
                        Db::name('module_migration')->where('module_key', $moduleKey)
                            ->where('migration_key', $migrationKey)->update([
                                'status' => 'applied',
                                'finished_at' => $this->now(),
                                'error_code' => null,
                            ]);
                    });
                } catch (\Throwable $exception) {
                    Db::name('module_migration')->where('module_key', $moduleKey)
                        ->where('migration_key', $migrationKey)->where('status', 'applying')->update([
                            'status' => 'failed',
                            'finished_at' => $this->now(),
                            'error_code' => $exception instanceof PluginLifecycleException
                                ? $exception->errorCode
                                : 'MODULE_MIGRATION_FAILED',
                        ]);
                    throw $exception;
                }
            }
        }
    }

    /** @param array<string,ManifestDocument> $manifests */
    private function registerCatalog(PluginDescriptor $plugin, array $manifests, bool $upgrade): void
    {
        $now = $this->now();
        Db::transaction(function () use ($plugin, $manifests, $upgrade, $now): void {
            $compiled = $this->registries
                ->fromPluginLock($this->resolver, $this->moduleConfig)
                ->compiled();
            $this->catalogs->apply($compiled, array_keys($manifests));
            foreach ($manifests as $moduleKey => $manifest) {
                $ownership = Db::name('plugin_module')->where('module_key', $moduleKey)
                    ->field('plugin_key,created_at')->lock(true)->find();
                $existingOwner = $ownership['plugin_key'] ?? null;
                if (is_string($existingOwner) && $existingOwner !== $plugin->key) {
                    throw new PluginLifecycleException('PLUGIN_MODULE_CONFLICT', "Module has another Plugin owner: {$moduleKey}");
                }
                $catalogValues = [
                    'plugin_key' => $plugin->key,
                    'module_key' => $moduleKey,
                    'module_version' => $manifest->data['version'],
                    'manifest_digest' => $manifest->digest,
                    'updated_at' => $now,
                ];
                if ($ownership === null) {
                    Db::name('plugin_module')->insert([...$catalogValues, 'created_at' => $now]);
                } else {
                    Db::name('plugin_module')->where('module_key', $moduleKey)->update($catalogValues);
                }
                $activatedAt = Db::name('module_installation')->where('module_key', $moduleKey)
                    ->lock(true)->value('activated_at');
                Db::name('module_installation')->where('module_key', $moduleKey)->update([
                    'installed_version' => $manifest->data['version'],
                    'manifest_schema_version' => $manifest->data['schema_version'],
                    'manifest_digest' => $manifest->digest,
                    'status' => 'active',
                    'revision' => Db::raw('revision+1'),
                    'activated_at' => $activatedAt ?? $now,
                    'upgraded_at' => $upgrade ? $now : null,
                    'last_error_code' => null,
                    'updated_at' => $now,
                ]);
            }
            $parameters = $this->pluginParameters($plugin);
            $activatedAt = Db::name('plugin_installation')->where('plugin_key', $plugin->key)
                ->lock(true)->value('activated_at');
            Db::name('plugin_installation')->where('plugin_key', $plugin->key)->update([
                'installed_version' => $parameters['version'], 'source' => $parameters['source'],
                'artifact_sha256' => $parameters['artifact_sha256'], 'lock_digest' => $parameters['lock_digest'],
                'composer_identity_json' => $parameters['composer'], 'npm_identity_json' => $parameters['npm'],
                'frontend_identity_json' => $parameters['frontend'], 'status' => 'active',
                'revision' => Db::raw('revision+1'), 'activated_at' => $activatedAt ?? $now,
                'upgraded_at' => $upgrade ? $now : null,
                'uninstalled_at' => null, 'last_error_code' => null, 'updated_at' => $now,
            ]);
        });
    }

    /** @param array<string,ManifestDocument> $manifests */
    private function markFailed(PluginDescriptor $plugin, array $manifests, string $errorCode): void
    {
        try {
            $now = $this->now();
            Db::transaction(function () use ($plugin, $manifests, $errorCode, $now): void {
                Db::name('plugin_installation')->where('plugin_key', $plugin->key)->update([
                    'status' => 'failed', 'revision' => Db::raw('revision+1'),
                    'last_error_code' => $errorCode, 'updated_at' => $now,
                ]);
                Db::name('module_installation')->whereIn('module_key', array_keys($manifests))->update([
                    'status' => 'failed', 'revision' => Db::raw('revision+1'),
                    'last_error_code' => $errorCode, 'updated_at' => $now,
                ]);
            });
        } catch (\Throwable) {
        }
    }

    /** @return array<string,ManifestDocument> */
    private function pluginManifests(PluginDescriptor $plugin): array
    {
        $registry = $this->registries->fromPluginLock($this->resolver, $this->moduleConfig);
        $manifests = [];
        foreach ($registry->compiled()->modules as $manifest) {
            $key = (string) ($manifest->data['key'] ?? '');
            if (isset($plugin->moduleRoots[$key])) {
                $manifests[$key] = $manifest;
            }
        }
        $compiledKeys = array_keys($manifests);
        $lockedKeys = array_keys($plugin->moduleRoots);
        sort($compiledKeys, SORT_STRING);
        sort($lockedKeys, SORT_STRING);
        if ($compiledKeys !== $lockedKeys) {
            throw new PluginLifecycleException('PLUGIN_MODULE_MISMATCH', 'Compiled Plugin modules differ from plugins.lock.');
        }
        ksort($manifests, SORT_STRING);
        return $manifests;
    }

    private function assertTrustEligible(PluginDescriptor $plugin): void
    {
        if (($plugin->trustResult()['status'] ?? null) !== 'eligible') {
            throw new PluginLifecycleException(
                'PLUGIN_TRUST_QUALIFICATION_INVALID',
                'Plugin is not eligible for installation from the locked bundled channel.',
            );
        }
    }

    /** @param array<string,ManifestDocument> $manifests @param array<string,mixed> $current */
    private function plan(PluginDescriptor $plugin, array $manifests, array $current): array
    {
        $pending = [];
        foreach ($manifests as $moduleKey => $manifest) {
            foreach ($this->migrationFiles($plugin->moduleRoots[$moduleKey], $manifest) as $key => $path) {
                $row = Db::name('module_migration')->where('module_key', $moduleKey)
                    ->where('migration_key', $key)->field('checksum,status')->find();
                $checksum = (string) hash_file('sha256', $path);
                if ($row !== null && !hash_equals((string) $row['checksum'], $checksum)) {
                    throw new PluginLifecycleException('MODULE_MIGRATION_CHECKSUM_MISMATCH', "Migration changed: {$key}");
                }
                if ($row === null || $row['status'] !== 'applied') {
                    $pending[] = ['module_key' => $moduleKey, 'migration_key' => $key, 'sha256' => $checksum];
                }
            }
        }
        return [
            'plugin_key' => $plugin->key,
            'from_version' => (string) $current['installed_version'],
            'to_version' => $plugin->version,
            'identity_changed' => !$this->sameIdentity($plugin, $current),
            'pending_migrations' => $pending,
            'rollback' => ['automatic' => false, 'requires_verified_backup' => $pending !== []],
            'trust' => $plugin->trustResult(),
        ];
    }

    /** @return array<string,string> */
    private function migrationFiles(string $root, ManifestDocument $manifest): array
    {
        $directories = [];
        $backend = $manifest->data['backend'] ?? null;
        if (is_array($backend) && is_string($backend['migrations'] ?? null)) {
            $directories[] = $root . '/' . ltrim($backend['migrations'], '/');
        }
        $directories[] = $root . '/database/migrations';
        $files = [];
        $resolvedDirectories = [];
        foreach ($directories as $directory) {
            $resolved = realpath($directory);
            if ($resolved === false) {
                continue;
            }
            $identity = self::migrationDirectoryIdentity($resolved);
            if (isset($resolvedDirectories[$identity])) {
                continue;
            }
            $resolvedDirectories[$identity] = $resolved;
        }
        foreach (array_values($resolvedDirectories) as $directory) {
            if (!is_dir($directory)) {
                continue;
            }
            foreach (glob($directory . '/*.sql') ?: [] as $path) {
                $key = (string) $manifest->data['key'] . ':' . basename($path, '.sql');
                if (isset($files[$key])) {
                    throw new PluginLifecycleException('MODULE_MIGRATION_INVALID', "Duplicate migration key: {$key}");
                }
                $files[$key] = $path;
            }
        }
        ksort($files, SORT_STRING);
        return $files;
    }

    /** @param array<string,string> $files @return array<string,string> predecessor => repair */
    private function migrationRepairMap(string $moduleKey, array $files): array
    {
        $repairs = [];
        foreach ($files as $repairKey => $path) {
            $contents = file_get_contents($path, false, null, 0, 4096);
            if (!is_string($contents)) {
                throw new PluginLifecycleException('MODULE_MIGRATION_INVALID', "Migration is unreadable: {$repairKey}");
            }
            if (preg_match('/\A(?:\xEF\xBB\xBF)?\s*--\s*peanut-admin-repairs\s*:\s*([^\r\n]+)(?:\r?\n|\z)/i', $contents, $match) !== 1) {
                continue;
            }
            foreach (array_map('trim', explode(',', $match[1])) as $predecessor) {
                if (preg_match('/^' . preg_quote($moduleKey, '/') . ':[a-zA-Z0-9][a-zA-Z0-9._-]*$/D', $predecessor) !== 1
                    || strcmp($predecessor, $repairKey) >= 0
                    || isset($repairs[$predecessor])) {
                    throw new PluginLifecycleException('MODULE_MIGRATION_REPAIR_INVALID', "Migration repair declaration is invalid: {$repairKey}");
                }
                $repairs[$predecessor] = $repairKey;
            }
        }
        return $repairs;
    }

    private static function migrationDirectoryIdentity(string $directory): string
    {
        $stat = stat($directory);
        if (!is_array($stat) || !isset($stat['dev'], $stat['ino'])) {
            throw new PluginLifecycleException(
                'MODULE_MIGRATION_INVALID',
                "Migration directory identity is unavailable: {$directory}",
            );
        }
        return (string) $stat['dev'] . ':' . (string) $stat['ino'];
    }

    /** @return array<string,mixed>|false */
    private function pluginInstallation(string $pluginKey, bool $lock): array|false
    {
        $query = Db::name('plugin_installation')->where('plugin_key', $pluginKey);
        if ($lock) {
            $query->lock(true);
        }
        return $query->find() ?? false;
    }

    /** @return list<array<string,mixed>> */
    private function pluginModuleRows(string $pluginKey): array
    {
        return Db::name('plugin_module')->where('plugin_key', $pluginKey)->order('module_key')->select()->toArray();
    }

    private function allPluginModulesActive(string $pluginKey): bool
    {
        $moduleKeys = Db::name('plugin_module')->where('plugin_key', $pluginKey)->column('module_key');
        return $moduleKeys !== []
            && Db::name('module_installation')->whereIn('module_key', $moduleKeys)->where('status', 'active')->count()
                === count($moduleKeys);
    }

    /** @param array<string,mixed> $current */
    private function sameIdentity(PluginDescriptor $plugin, array $current): bool
    {
        return (string) $current['installed_version'] === $plugin->version
            && hash_equals((string) $current['artifact_sha256'], $plugin->source['sha256'])
            && hash_equals((string) $current['lock_digest'], $plugin->lockDigest)
            && $this->jsonColumn($current['composer_identity_json']) === $this->json($plugin->composer)
            && $this->jsonColumn($current['npm_identity_json']) === $this->json($plugin->npm)
            && $this->jsonColumn($current['frontend_identity_json']) === $this->json($plugin->frontend);
    }

    /** @return array<string,mixed> */
    private function pluginParameters(PluginDescriptor $plugin): array
    {
        return [
            'plugin_key' => $plugin->key,
            'version' => $plugin->version,
            'source' => $plugin->source['type'] . ':' . $plugin->source['reference'],
            'artifact_sha256' => $plugin->source['sha256'],
            'lock_digest' => $plugin->lockDigest,
            'composer' => $this->json($plugin->composer),
            'npm' => $this->json($plugin->npm),
            'frontend' => $this->json($plugin->frontend),
        ];
    }

    private function json(mixed $value): string
    {
        return (string) json_encode(
            $this->canonicalJsonValue($value),
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES,
        );
    }

    private function jsonColumn(mixed $value): string
    {
        if (!is_string($value)) {
            return '';
        }
        try {
            return $this->json(json_decode($value, true, 64, JSON_THROW_ON_ERROR));
        } catch (\JsonException) {
            return '';
        }
    }

    private function canonicalJsonValue(mixed $value): mixed
    {
        if (!is_array($value)) {
            return $value;
        }
        if (array_is_list($value)) {
            return array_map(fn(mixed $item): mixed => $this->canonicalJsonValue($item), $value);
        }
        ksort($value, SORT_STRING);
        foreach ($value as $key => $item) {
            $value[$key] = $this->canonicalJsonValue($item);
        }
        return $value;
    }

    private function now(): string
    {
        return gmdate('Y-m-d H:i:s.v');
    }

    private function lockName(string $pluginKey): string
    {
        return 'pa:module-runtime:' . substr(hash('sha256', $pluginKey), 0, 40);
    }

}
