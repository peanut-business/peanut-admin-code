<?php

declare(strict_types=1);

namespace app\platform\services\plugin;

use PeanutAdmin\Modules\Identity\Contract\TenantModuleStateQueries;
use app\platform\composition\plugin\PluginModuleRegistryFactory;
use app\platform\exception\plugin\PluginLifecycleException;
use app\platform\infrastructure\plugin\ModuleCatalogApplier;
use app\common\infrastructure\migration\PeanutMigrationAdapter;
use app\common\infrastructure\migration\PeanutMigrationRun;
use app\platform\infrastructure\plugin\PluginLockResolver;
use app\platform\policy\plugin\ModuleLifecyclePolicy;
use app\platform\value\plugin\PluginDescriptor;
use app\common\contract\module\PluginLifecycleCommands;
use app\common\persistence\AdvisoryLockExecution;
use app\common\persistence\AdvisoryLockUnavailable;
use PeanutAdmin\Kernel\Module\ManifestDocument;
use think\facade\Db;
use app\common\execution\CurrentExecutionContext;
use app\common\execution\InstanceExecutionContext;
use app\common\execution\InstallationExecutionContext;
use PeanutAdmin\Modules\File\Contract\FileMigrationAdoptionProof;
use PeanutAdmin\Modules\File\Contract\FileMigrationPreservation;
use PeanutAdmin\Modules\Document\Contract\DocumentMigrationPreservation;
use PeanutAdmin\Modules\Article\Contract\ArticleMigrationPreservation;
use PeanutAdmin\Modules\Notification\Contract\NotificationUpgradePreflight;
use PeanutAdmin\Modules\Task\Job\Database\TaskSchemaMigrationProof;
use PeanutAdmin\Modules\Identity\Audit\AuditService;
use app\common\services\installation\InstallationExecutionHost;

/** Deployment-scoped Plugin lifecycle. It deliberately never mutates pa_tenant_module. */
final readonly class PluginLifecycleService implements PluginLifecycleCommands
{
    private const ARTICLE_STAGES = [
        'official.article:20261013230000_add_article_document_save_binding' => '67e504df714ee1a1d0cc6959506eaa584cfaeb0ea26c3e95597586b5ec2576fe',
    ];
    private const DOCUMENT_STAGES = [
        'official.document:20261012050000_create_business_documents' => '103003e0a0cbb8ec11ed2d1f039772500d7c610ad9b66bc19623fb012fa31f00',
        'official.document:20261013120000_add_document_business_barriers' => 'e015617440e433d0d443f7c4c13b2bf02e35b4403e25c1009747d5d1d707f579',
    ];
    private const PRESERVATION_STAGES = [
        'official.notification:20260921-adopt-notification-delivery-schema' => ['eaef582cd762c803f3f7d213687f61d43fd3634e9f0b984471f11648e10979fb', NotificationUpgradePreflight::class,
            ['official.notification:20260826-namespace-permission-keys']],
        'official.notification:20261012-notification-producer-identity' => ['7c01bc1bf1f309e879ff3014f89418157abbce45374a58064d1a6dc94fca07ce', NotificationUpgradePreflight::class,
            ['official.notification:20260826-namespace-permission-keys', 'official.notification:20260921-adopt-notification-delivery-schema']],
        'official.task:20260921-adopt-task-job-schema' => ['f04762aeab007bea0b0c4ce277d8f7781a694b17891f1193f303d0481366f21a', TaskSchemaMigrationProof::class,
            ['official.task:20260826-namespace-permission-keys']],
        'official.task:20261015020000_add_negative_fact_job_profile' => ['2217e8a0cfc44e2c4c6c1a76a0413ed2448c5695e32f2a31a6a2a3072f44dbd4', TaskSchemaMigrationProof::class,
            ['official.task:20260826-namespace-permission-keys', 'official.task:20260921-adopt-task-job-schema']],
    ];

    /** @param array<string,mixed> $moduleConfig */
    public function __construct(
        private PluginLockResolver $resolver,
        private PluginModuleRegistryFactory $registries,
        private array $moduleConfig,
        private ModuleCatalogApplier $catalogs,
        private TenantModuleStateQueries $tenantStates,
    ) {}

    /**
     * Installs or resumes the immutable package selected by the deployment lock.
     *
     * @return array<string,mixed>
     */
    public function install(string $pluginKey): array
    {
        return $this->withPackageLock($pluginKey, fn(): array => $this->installLocked($pluginKey));
    }

    private function installLocked(string $pluginKey): array
    {
        $plugin = $this->resolver->require($pluginKey);
        $this->assertTrustEligible($plugin);
        $operation = function () use ($plugin, $pluginKey): array {
            $manifests = $this->pluginManifests($plugin);
            $this->assertPreflightOwnership($plugin, $manifests, false);
            $current = $this->pluginInstallation($pluginKey, false);
            if (is_array($current) && $this->sameIdentity($plugin, $current) && $current['status'] === 'active') {
                if ($this->allPluginModulesActive($pluginKey)) {
                    foreach ($manifests as $moduleKey => $manifest) {
                        if (!$this->moduleMigrationPlan($plugin, $moduleKey, $manifest, false)['may_apply']) {
                            throw new PluginLifecycleException('MODULE_MIGRATION_REPAIR_REQUIRED', 'An unchanged installation still requires a complete ledger.');
                        }
                    }
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
        return $operation();
    }

    /**
     * Installs a missing package or reconciles the active install to the locked identity.
     *
     * @return array<string,mixed>
     */
    public function reconcile(string $pluginKey): array
    {
        return $this->withPackageLock($pluginKey, fn(): array => $this->reconcileLocked($pluginKey));
    }

    /** Uses the native lock/manifest/ownership rules, without entering lifecycle mutation or recovery. */
    public function reconcilePlan(string $pluginKey, bool $preserveDisabled = false, ?callable $consumePlan = null): array
    {
        return $this->withPackageLock($pluginKey, function () use ($pluginKey, $preserveDisabled, $consumePlan): array {
            $plugin = $this->resolver->require($pluginKey);
            $this->assertTrustEligible($plugin);
            $manifests = $this->pluginManifests($plugin);
            $current = $this->pluginInstallation($pluginKey, false);
            if (is_array($current) && $current['status'] !== 'active') {
                throw new PluginLifecycleException('PLUGIN_STATE_INVALID', 'Release plans require an active installation.');
            }
            $this->assertPreflightOwnership($plugin, $manifests, is_array($current));
            if ($preserveDisabled && !is_array($current)) {
                throw new PluginLifecycleException('PLUGIN_STATE_INVALID', 'A missing package cannot be preserved disabled.');
            }
            if (is_array($current) && version_compare($plugin->version, (string) $current['installed_version'], '<')) {
                throw new PluginLifecycleException('PLUGIN_DOWNGRADE_REJECTED', 'Release plans cannot downgrade a Plugin.');
            }
            $action = $preserveDisabled ? 'preserve_disabled' : (!is_array($current) ? 'planned_install'
                : ($this->sameIdentity($plugin, $current) ? 'planned_unchanged' : 'planned_upgrade'));
            $willMigrate = in_array($action, ['planned_install', 'planned_upgrade'], true);
            $modules = [];
            $mayApply = true;
            $planningComplete = true;
            $prerequisites = [];
            foreach ($manifests as $moduleKey => $manifest) {
                $migrationPlan = $this->moduleMigrationPlan($plugin, $moduleKey, $manifest, $willMigrate);
                $mayApply = $mayApply && $migrationPlan['may_apply'];
                $planningComplete = $planningComplete && ($migrationPlan['planning_complete'] ?? $migrationPlan['may_apply']);
                foreach ($migrationPlan['application_prerequisites'] ?? [] as $prerequisite) {
                    $prerequisites[$prerequisite['migration_id']] = $prerequisite;
                }
                $manifestSha = hash_file('sha256', $plugin->moduleRoots[$moduleKey] . '/module.json');
                if (!is_string($manifestSha)) {
                    throw new PluginLifecycleException('PLUGIN_MODULE_MISMATCH', 'Module manifest is unreadable.');
                }
                $modules[] = ['module_key' => $moduleKey, 'manifest_sha256' => $manifestSha, ...$migrationPlan];
            }
            usort($modules, static fn(array $left, array $right): int => strcmp($left['module_key'], $right['module_key']));
            $plan = $plugin->publicIdentity() + ['action' => $action, 'may_apply' => $mayApply,
                'planning_complete' => $planningComplete, 'application_prerequisites' => array_values($prerequisites),
                'status' => $mayApply ? 'ready' : ($planningComplete ? 'planned' : 'blocked'), 'execution_admitted' => false,
                'from_version' => is_array($current) ? (string) $current['installed_version'] : null,
                'manifest_sha256' => $plugin->manifestDigest, 'modules' => $modules,
                'observation' => 'read-only interval; execution revalidates all native guards'];
            // Native source backup consumes the actual plan before this same
            // package lock is released. It cannot enter the mutation branch.
            return $consumePlan === null ? $plan : $consumePlan($plan);
        });
    }

    private function reconcileLocked(string $pluginKey): array
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
            foreach ($this->pluginManifests($plugin) as $moduleKey => $manifest) {
                if (!$this->moduleMigrationPlan($plugin, $moduleKey, $manifest, false)['may_apply']) {
                    throw new PluginLifecycleException('MODULE_MIGRATION_REPAIR_REQUIRED', 'An unchanged installation still requires a complete ledger.');
                }
            }
            return $plugin->publicIdentity() + ['operation' => 'unchanged'];
        }

        return $this->upgrade($pluginKey, false);
    }

    /**
     * Returns an upgrade plan in dry-run mode; lower package versions are rejected.
     *
     * @return array<string,mixed>
     */
    public function upgrade(string $pluginKey, bool $dryRun, ?callable $consumePlan = null): array
    {
        if (!$dryRun && $consumePlan !== null) { throw new PluginLifecycleException('PLUGIN_PLAN_CONSUMER_READ_ONLY', 'A plan consumer cannot authorize lifecycle writes.'); }
        return $this->withPackageLock($pluginKey, fn(): array => $this->upgradeLocked($pluginKey, $dryRun, $consumePlan));
    }

    private function upgradeLocked(string $pluginKey, bool $dryRun, ?callable $consumePlan = null): array
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
        if ($dryRun) {
            $plan += ['dry_run' => true, 'operation' => 'upgrade'];
            return $consumePlan === null ? $plan : $consumePlan($plan);
        }
        if ($this->sameIdentity($plugin, $current) && $current['status'] === 'active') {
            if (!$plan['may_apply'] || $plan['pending_migrations'] !== []) {
                throw new PluginLifecycleException('MODULE_MIGRATION_REPAIR_REQUIRED', 'An unchanged installation still requires a complete resolved ledger.');
            }
            return $plan + ['dry_run' => false, 'operation' => 'unchanged'];
        }
        if (!$plan['may_apply']) {
            foreach ($plan['modules'] as $module) { self::assertAdoptionPrerequisites($module['migrations']); }
            throw new PluginLifecycleException('MODULE_MIGRATION_ADOPTION_REJECTED', 'Migration requirements are unresolved or unknown.');
        }
        return $this->activate($plugin, $manifests, true) + ['plan' => $plan];
    }

    /** Describes manual rollback steps; it does not change the installed package or database. */
    public function rollbackPlan(string $pluginKey): array
    {
        $current = $this->pluginInstallation($pluginKey, false);
        if (!is_array($current)) {
            throw new PluginLifecycleException('PLUGIN_NOT_INSTALLED', 'Plugin is not installed.');
        }
        $moduleKeys = Db::name('plugin_module')->where('plugin_key', $pluginKey)->column('module_key');
        self::assertMigrationSchema();
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
                'adopted entries prove existing postconditions, not historical SQL execution; never drop their pre-existing objects or replay the original SQL',
            ],
            'applied_migrations' => array_map(static fn(array $row): array => $row + [
                'resolution_origin' => ($row['resolution_kind'] ?? null) ?: 'unknown',
                'module_sql_executed' => ($row['resolution_kind'] ?? null) === 'executed' ? true
                    : (($row['resolution_kind'] ?? null) === 'adopted' ? false : null),
                'resolution_verification' => 'manual_review_required',
            ], $moduleKeys === [] ? [] : Db::name('module_migration')
                ->whereIn('module_key', $moduleKeys)->field('module_key,migration_key,module_version,checksum,status,resolution_kind,provenance_json')
                ->order('id', 'desc')->select()->toArray()),
        ];
    }

    /**
     * Retires the package only after dependent and tenant-enabled modules are disabled.
     *
     * @return array<string,mixed>
     */
    public function uninstall(string $pluginKey): array
    {
        return $this->withPackageLock($pluginKey, fn(): array => $this->uninstallLocked($pluginKey));
    }

    private function uninstallLocked(string $pluginKey): array
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
        if ($ownedModuleKeys !== [] && $this->tenantStates->hasEnabledModules($ownedModuleKeys)) {
            throw new PluginLifecycleException('PLUGIN_TENANT_MODULE_ACTIVE', 'Disable every TenantModule before uninstall.');
        }
        $modules = $this->pluginModuleRows($pluginKey);
        $now = $this->now();
        Db::transaction(function () use ($pluginKey, $modules, $now): void {
            $moduleKeys = array_column($modules, 'module_key');
            ModuleLifecyclePolicy::assertNoOutstandingWorkflow($moduleKeys);
            $this->tenantStates->lockLifecycleRows($moduleKeys, [$pluginKey]);
            if ($this->tenantStates->enabledReferencesForUpdate($moduleKeys) !== []) {
                throw new PluginLifecycleException('PLUGIN_TENANT_MODULE_ACTIVE', 'Disable every TenantModule before uninstall.');
            }
            $this->catalogs->retire(array_map(
                static fn(array $row): string => (string) $row['module_key'],
                $modules,
            ));
            $this->catalogs->retirePackageModules(array_values(array_map(
                static fn(array $row): string => (string) $row['module_key'],
                $modules,
            )), $now);
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
        foreach ($manifests as $moduleKey => $manifest) {
            $preflight = $this->moduleMigrationPlan($plugin, $moduleKey, $manifest, true);
            if (!$preflight['may_apply']) {
                self::assertAdoptionPrerequisites($preflight['migrations']);
                throw new PluginLifecycleException('MODULE_MIGRATION_ADOPTION_REJECTED', 'Complete migration postconditions or a safe complete pre-state are required.');
            }
        }
        $notificationForward = $this->prepareNotificationForward($plugin, $manifests, $upgrade);
        $this->beginLifecycle($plugin, $manifests, $upgrade);
        try {
            $this->applyMigrations($plugin, $manifests, $notificationForward);
            if ($notificationForward) { $this->assertNotificationForward($plugin); }
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
            $this->tenantStates->lockLifecycleRows(array_keys($manifests), [$plugin->key]);
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
            $this->catalogs->beginPackageDeployment($manifests, $upgrade, $now);
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
    private function applyMigrations(PluginDescriptor $plugin, array $manifests, bool $notificationForward = false): void
    {
        $batch = (int) Db::name('module_migration')->max('batch_no') + 1;
        foreach ($manifests as $moduleKey => $manifest) {
            $files = self::migrationFiles($plugin->moduleRoots[$moduleKey], $manifest);
            $repairs = $this->migrationRepairMap($moduleKey, $files);
            $adoptions = self::migrationAdoptions($moduleKey, $files, $repairs);
            $plan = $this->moduleMigrationPlan($plugin, $moduleKey, $manifest, true, $moduleKey === 'official.notification' ? $notificationForward : null);
            if (!$plan['may_apply']) { throw new PluginLifecycleException('MODULE_MIGRATION_ADOPTION_REJECTED', 'Migration state changed after lifecycle preflight.'); }
            $decisions = array_column($plan['migrations'], null, 'migration_key');
            foreach ($files as $migrationKey => $path) {
                if (isset($adoptions['declarations'][$migrationKey])) { continue; }
                $checksum = hash_file('sha256', $path);
                if (!is_string($checksum)) {
                    throw new PluginLifecycleException('MODULE_MIGRATION_INVALID', "Migration is unreadable: {$path}");
                }
                $row = Db::name('module_migration')->where('module_key', $moduleKey)
                    ->where('migration_key', $migrationKey)->find();
                if ($row !== null) {
                    if (!hash_equals((string) $row['checksum'], $checksum)) {
                        throw new PluginLifecycleException(
                            'MODULE_MIGRATION_CHECKSUM_MISMATCH',
                            "Applied Module migration changed: {$migrationKey}",
                        );
                    }
                    if ($row['status'] === 'applied') {
                        $pdo = Db::connect()->getPdo();
                        if (!$pdo instanceof \PDO) { throw new PluginLifecycleException('MODULE_MIGRATION_PDO_MISMATCH', 'Master connection is unavailable.'); }
                        self::assertResolution($row, $adoptions['targets'][$migrationKey] ?? null, $pdo, $plugin->key);
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
                if (($decisions[$migrationKey]['disposition'] ?? null) === 'adopt') {
                    $this->adoptMigration($plugin, $moduleKey, $manifest, $adoptions['targets'][$migrationKey], $batch);
                    continue;
                }
                if (isset($adoptions['targets'][$migrationKey])) {
                    $this->resolver->reload();
                    $actual = $this->resolver->require($plugin->key);
                    if (self::json($actual->publicIdentity()) !== self::json($plugin->publicIdentity()) || $actual->manifestDigest !== $plugin->manifestDigest) {
                        throw new PluginLifecycleException('MODULE_MIGRATION_ADOPTION_SOURCE_CHANGED', 'Canonical package changed before SQL execution.');
                    }
                    $actualFiles = self::migrationFiles($actual->moduleRoots[$moduleKey], $manifest, true);
                    if (self::migrationAdoptions($moduleKey, $actualFiles, self::migrationRepairMap($moduleKey, $actualFiles)) !== $adoptions) {
                        throw new PluginLifecycleException('MODULE_MIGRATION_ADOPTION_SOURCE_CHANGED', 'Canonical declaration changed before SQL execution.');
                    }
                    $report = self::fileMigrationProof($migrationKey);
                    if (($report['disposition'] ?? null) !== 'execute' || ($report['may_apply'] ?? null) !== true) {
                        throw new PluginLifecycleException('MODULE_MIGRATION_ADOPTION_REJECTED', 'The complete safe pre-state must still hold before executing SQL.');
                    }
                }
                if ($notificationForward && $moduleKey === 'official.notification') { $this->assertNotificationForward($plugin); }
                if ($moduleKey === 'peanut.workflow' && $migrationKey === \PeanutAdmin\Modules\Workflow\Application\WorkflowUpgradePreflight::MIGRATION_KEY) {
                    try { (new \PeanutAdmin\Modules\Workflow\Application\WorkflowUpgradePreflight(new \PeanutAdmin\Modules\Identity\Contract\WorkflowLegacyOrganizationQuery()))->assertMayApply(); }
                    catch (\PeanutAdmin\Modules\Workflow\Application\WorkflowException $exception) { throw new PluginLifecycleException($exception->errorCode, $exception->getMessage()); }
                }
                if ($moduleKey === 'official.notification' && $migrationKey === \PeanutAdmin\Modules\Notification\Contract\NotificationUpgradePreflight::MIGRATION_KEY) {
                    try { app()->make(\PeanutAdmin\Modules\Notification\Contract\NotificationUpgradePreflight::class)->assertMayApply(); }
                    catch (\RuntimeException $exception) { throw new PluginLifecycleException($exception->getMessage(), 'Notification upgrade requires a known empty legacy delivery queue.'); }
                }
                $connection = Db::connect();
                $pdo = $connection->getPdo();
                if (!$pdo instanceof \PDO || $pdo->inTransaction()) {
                    throw new PluginLifecycleException('MODULE_MIGRATION_PDO_MISMATCH', 'An admitted master outside a transaction is required.');
                }
                $filePrivate = $moduleKey === 'official.file' && isset($adoptions['targets'][$migrationKey])
                    ? self::captureFilePreservation($pdo, $migrationKey, $checksum) : null;
                $documentStage = $moduleKey === 'official.document' && isset(self::DOCUMENT_STAGES[$migrationKey]);
                $documentAdmission = $documentStage ? self::adoptionAdmission($pdo) : null;
                $documentPrivate = $documentStage ? self::captureDocumentPreservation($pdo, $migrationKey, $checksum) : null;
                $articleStage = $moduleKey === 'official.article' && isset(self::ARTICLE_STAGES[$migrationKey]);
                $articleAdmission = $articleStage ? self::adoptionAdmission($pdo) : null;
                $articlePredecessors = $articleStage ? $this->articleDocumentPredecessors($pdo) : null;
                $articlePrivate = $articleStage ? self::captureArticlePreservation($pdo, $migrationKey, $checksum) : null;
                $stageContract = self::PRESERVATION_STAGES[$migrationKey] ?? null;
                $stageAdmission = $stageContract === null ? null : self::adoptionAdmission($pdo);
                $stageBefore = null;
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
                    $sourceSql = file_get_contents($path);
                    if (!is_string($sourceSql) || hash('sha256', $sourceSql) !== $checksum) {
                        throw new PluginLifecycleException('MODULE_MIGRATION_CHECKSUM_MISMATCH', 'Migration source changed before execution.');
                    }
                    if (trim($sourceSql) === '') {
                        throw new PluginLifecycleException('MODULE_MIGRATION_INVALID', "Migration is empty: {$migrationKey}");
                    }
                    if ($stageContract !== null) {
                        if ($stageContract[0] !== $checksum || !class_exists($stageContract[1])) {
                            throw new PluginLifecycleException('MODULE_MIGRATION_PRESERVATION_REQUIRED', 'The fixed stage contract and immutable source must match.');
                        }
                        $stageLedger = $this->stageLedgerReader($pdo, $connection, $plugin, $manifest, $files, $adoptions,
                            $moduleKey, $migrationKey, $checksum, $batch, $now, $stageAdmission);
                        $stageBefore = $stageContract[1]::before($pdo, ['module_key' => $moduleKey,
                            'migration_key' => $migrationKey, 'checksum' => $checksum, 'stageLedger' => $stageLedger]);
                        self::assertStageProofIdentity($pdo, $moduleKey, $migrationKey, $checksum, $stageBefore, false);
                        if ($stageBefore['stageLedger'] !== $stageLedger) {
                            throw new PluginLifecycleException('MODULE_MIGRATION_STAGE_CHANGED', 'The original live stage reader must be retained.');
                        }
                    }
                    // MySQL DDL commits implicitly. Keep the durable migration ledger outside the DDL boundary.
                    PeanutMigrationRun::apply($pdo, $moduleKey, $migrationKey, $sourceSql, $checksum,
                        function (?array $executionBounds) use ($connection, $pdo, $moduleKey, $migrationKey, $adoptions, $checksum, &$filePrivate,
                            $documentStage, $documentAdmission, &$documentPrivate, $articleStage, $articleAdmission, $articlePredecessors, &$articlePrivate,
                            $stageContract, &$stageBefore): void {
                            if (Db::connect() !== $connection || $connection->getPdo() !== $pdo || $pdo->inTransaction()) {
                                throw new PluginLifecycleException('MODULE_MIGRATION_PDO_MISMATCH', 'Master connection changed during migration.');
                            }
                            if ($documentStage) {
                                if (!is_array($documentPrivate) || $executionBounds !== null
                                    || self::json(self::adoptionAdmission($pdo)) !== self::json($documentAdmission)) {
                                    throw new PluginLifecycleException('MODULE_MIGRATION_PRESERVATION_REQUIRED', 'Document requires its original private facts and held native admission.');
                                }
                                self::assertDocumentPreserved($pdo, $migrationKey, $checksum, $documentPrivate);
                                $documentPrivate = null;
                                if (Db::connect() !== $connection || $connection->getPdo() !== $pdo || $pdo->inTransaction()
                                    || self::json(self::adoptionAdmission($pdo)) !== self::json($documentAdmission)) {
                                    throw new PluginLifecycleException('MODULE_MIGRATION_PDO_MISMATCH', 'Document completion changed its native connection or admission.');
                                }
                            }
                            if ($articleStage) {
                                if (!is_array($articlePrivate) || $executionBounds !== null
                                    || self::json(self::adoptionAdmission($pdo)) !== self::json($articleAdmission)
                                    || $this->articleDocumentPredecessors($pdo) !== $articlePredecessors) {
                                    throw new PluginLifecycleException('MODULE_MIGRATION_PRESERVATION_REQUIRED', 'Article requires its original private facts, Document predecessors and native window.');
                                }
                                self::assertArticlePreserved($pdo, $migrationKey, $checksum, $articlePrivate);
                                $articlePrivate = null;
                                if (Db::connect() !== $connection || $connection->getPdo() !== $pdo || $pdo->inTransaction()
                                    || self::json(self::adoptionAdmission($pdo)) !== self::json($articleAdmission)) {
                                    throw new PluginLifecycleException('MODULE_MIGRATION_PDO_MISMATCH', 'Article completion changed its native connection or admission.');
                                }
                            }
                            if ($stageContract !== null) {
                                if (!is_array($stageBefore) || $executionBounds !== null) {
                                    throw new PluginLifecycleException('MODULE_MIGRATION_PRESERVATION_REQUIRED', 'The original DDL stage requires its private facts and no time allowance.');
                                }
                                $observed = $stageContract[1]::after($pdo, $stageBefore);
                                self::assertStageProofIdentity($pdo, $moduleKey, $migrationKey, $checksum, $observed, true);
                                $stageBefore = null;
                            }
                            if ($moduleKey === 'official.file' && isset($adoptions['targets'][$migrationKey])) {
                                // The original File proof must now observe the complete
                                // postshape before the official adapter records applied.
                                $proof = self::fileMigrationProof($migrationKey);
                                if (($proof['may_apply'] ?? null) !== true || ($proof['disposition'] ?? null) !== 'adopt') {
                                    throw new PluginLifecycleException('MODULE_MIGRATION_POSTSTATE_REJECTED', 'File migration did not establish its canonical postconditions.');
                                }
                                if (!is_array($filePrivate)) { throw new PluginLifecycleException('MODULE_MIGRATION_PRESERVATION_REQUIRED', 'The current File stage requires its held private facts.'); }
                                self::assertFilePreserved($pdo, $migrationKey, $checksum, $filePrivate);
                                $filePrivate = null;
                                if (Db::connect() !== $connection || $connection->getPdo() !== $pdo || $pdo->inTransaction()) {
                                    throw new PluginLifecycleException('MODULE_MIGRATION_PDO_MISMATCH', 'Master connection changed during the File poststate proof.');
                                }
                            }
                        });
                    if ($notificationForward && $moduleKey === 'official.notification') { $this->assertNotificationForward($plugin); }
                } catch (\Throwable $exception) {
                    try {
                        if (Db::connect() !== $connection || $connection->getPdo() !== $pdo) {
                            throw new \RuntimeException('MODULE_MIGRATION_NATIVE_MASTER_CHANGED');
                        }
                        if ($pdo->inTransaction()) { $pdo->rollBack(); }
                        Db::name('module_migration')->where('module_key', $moduleKey)
                            ->where('migration_key', $migrationKey)->where('status', 'applying')->update([
                                'status' => 'failed',
                                'finished_at' => $this->now(),
                                'error_code' => $exception instanceof PluginLifecycleException
                                    ? $exception->errorCode
                                    : 'MODULE_MIGRATION_FAILED',
                            ]);
                    } catch (\Throwable $recordingException) {
                        throw new \RuntimeException('MODULE_MIGRATION_FAILED: ' . $migrationKey
                            . '; FAILURE_RECORDING_FAILED: ' . $recordingException->getMessage(), 0, $exception);
                    }
                    throw $exception;
                } finally {
                    // Never accumulate private business snapshots across stages or
                    // serialize them into the sole migration ledger/result.
                    $filePrivate = null;
                    $documentPrivate = null;
                    $articlePrivate = null;
                    $articlePredecessors = null;
                    $stageBefore = null;
                    unset($stageLedger);
                }
            }
        }
    }

    /** @param array<string,ManifestDocument> $manifests */
    private function registerCatalog(PluginDescriptor $plugin, array $manifests, bool $upgrade): void
    {
        $now = $this->now();
        Db::transaction(function () use ($plugin, $manifests, $upgrade, $now): void {
            $this->tenantStates->lockLifecycleRows(array_keys($manifests), [$plugin->key]);
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
            }
            $this->catalogs->activatePackageDeployment($manifests, $upgrade, $now);
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
                $this->tenantStates->lockLifecycleRows(array_keys($manifests), [$plugin->key]);
                Db::name('plugin_installation')->where('plugin_key', $plugin->key)->update([
                    'status' => 'failed', 'revision' => Db::raw('revision+1'),
                    'last_error_code' => $errorCode, 'updated_at' => $now,
                ]);
                $this->catalogs->markPackageDeploymentFailed(array_keys($manifests), $errorCode, $now);
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
        $workflowPreflight = null;
        $notificationPreflight = null;
        $modules = []; $mayApply = true;
        $willMigrate = !$this->sameIdentity($plugin, $current) || $current['status'] !== 'active';
        foreach ($manifests as $moduleKey => $manifest) {
            $module = $this->moduleMigrationPlan($plugin, $moduleKey, $manifest, $willMigrate);
            $modules[] = ['module_key' => $moduleKey, ...$module];
            $mayApply = $mayApply && $module['may_apply'];
            foreach ($module['migrations'] as $migration) {
                if ($migration['disposition'] !== 'already_applied') { $pending[] = ['module_key' => $moduleKey, ...$migration]; }
            }
            foreach ($module['preflight_reports'] as $report) {
                if ($moduleKey === 'peanut.workflow') { $workflowPreflight = $report; }
                if ($moduleKey === 'official.notification') { $notificationPreflight = $report; }
            }
        }
        return [
            'plugin_key' => $plugin->key,
            'from_version' => (string) $current['installed_version'],
            'to_version' => $plugin->version,
            'identity_changed' => !$this->sameIdentity($plugin, $current),
            'pending_migrations' => $pending,
            'modules' => $modules, 'may_apply' => $mayApply,
            'workflow_preflight' => $workflowPreflight,
            'notification_preflight' => $notificationPreflight,
            'notification_forward_schema' => $modules[0]['notification_forward_schema'] ?? null,
            'notification_forward_admission' => $modules[0]['notification_forward_admission'] ?? null,
            'rollback' => ['automatic' => false, 'requires_verified_backup' => $pending !== []],
            'trust' => $plugin->trustResult(),
        ];
    }

    /** The two provenance columns are an explicit application migration prerequisite, never hidden DDL. */
    private static function assertMigrationSchema(): void
    {
        $rows = Db::connect()->query('SELECT COLUMN_NAME,DATA_TYPE,COLUMN_TYPE,IS_NULLABLE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME IN (?,?)',
            [Db::name('module_migration')->getTable(), 'resolution_kind', 'provenance_json'], true);
        $columns = array_column($rows, null, 'COLUMN_NAME');
        if (($columns['resolution_kind']['COLUMN_TYPE'] ?? null) !== 'varchar(16)'
            || ($columns['resolution_kind']['IS_NULLABLE'] ?? null) !== 'YES'
            || ($columns['provenance_json']['DATA_TYPE'] ?? null) !== 'json'
            || ($columns['provenance_json']['IS_NULLABLE'] ?? null) !== 'YES') {
            throw new PluginLifecycleException('MODULE_MIGRATION_PROVENANCE_SCHEMA_REQUIRED', 'Apply the explicit application migration before module lifecycle operations.');
        }
        $constraints = Db::connect()->query('SELECT t.CONSTRAINT_NAME,c.CHECK_CLAUSE,t.ENFORCED FROM information_schema.TABLE_CONSTRAINTS t JOIN information_schema.CHECK_CONSTRAINTS c ON c.CONSTRAINT_SCHEMA=t.CONSTRAINT_SCHEMA AND c.CONSTRAINT_NAME=t.CONSTRAINT_NAME WHERE t.CONSTRAINT_SCHEMA=DATABASE() AND t.TABLE_NAME=? AND t.CONSTRAINT_NAME IN (?,?)',
            [Db::name('module_migration')->getTable(), 'chk_module_migration_resolution', 'chk_module_migration_provenance'], true);
        $expected = [
            'chk_module_migration_resolution' => "((resolution_kindisnull)or(resolution_kindin('executed','adopted')))",
            'chk_module_migration_provenance' => "((resolution_kind<=>'adopted')=(provenance_jsonisnotnull))",
        ];
        if (count($constraints) !== 2) { throw new PluginLifecycleException('MODULE_MIGRATION_PROVENANCE_SCHEMA_REQUIRED', 'Provenance CHECK constraints are missing.'); }
        foreach ($constraints as $row) {
            $clause = strtolower((string) preg_replace('/\s+|`|_utf8mb4/', '', $row['CHECK_CLAUSE']));
            $required = $expected[$row['CONSTRAINT_NAME']] ?? null;
            // Admit only complete fixed predicates, including MySQL's escaped-delimiter metadata rendering.
            if (($row['ENFORCED'] ?? null) !== 'YES' || !is_string($required)
                || !in_array($clause, [$required, str_replace("'", "\\'", $required)], true)) {
                throw new PluginLifecycleException('MODULE_MIGRATION_PROVENANCE_SCHEMA_REQUIRED', 'Provenance CHECK semantics differ from the application schema.');
            }
        }
    }

    /** Only held native release/source read plans may observe this exact future schema. */
    private static function pendingProvenancePrerequisite(): ?array
    {
        $pdo = Db::connect()->getPdo();
        if (!$pdo instanceof \PDO) { return null; }
        $source = \app\common\services\upgrade\PluginSourceDevelopmentHost::class;
        $prerequisite = app()->exists($source) ? app()->make($source)->pendingModuleProvenanceMigration($pdo) : null;
        if ($prerequisite === null && class_exists('PeanutServerUpdatePlan', false)) {
            $prerequisite = \PeanutServerUpdatePlan::pendingModuleProvenanceMigration($pdo);
        }
        if ($prerequisite === null) { return null; }
        if (($prerequisite['migration_id'] ?? null) !== '20261016-module-migration-provenance'
            || ($prerequisite['reason'] ?? null) !== 'module_migration_provenance_schema'
            || preg_match('/^[a-f0-9]{64}$/D', (string) ($prerequisite['sha256'] ?? '')) !== 1) {
            throw new PluginLifecycleException('MODULE_MIGRATION_PROVENANCE_SCHEMA_REQUIRED', 'Native application prerequisite is invalid.');
        }
        $statement = $pdo->prepare('SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME IN (?,?)');
        $statement->execute([Db::name('module_migration')->getTable(), 'resolution_kind', 'provenance_json']);
        $columns = (int) $statement->fetchColumn();
        $statement = $pdo->prepare('SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA=DATABASE() AND TABLE_NAME=? AND CONSTRAINT_NAME IN (?,?)');
        $statement->execute([Db::name('module_migration')->getTable(), 'chk_module_migration_resolution', 'chk_module_migration_provenance']);
        $constraints = (int) $statement->fetchColumn();
        if ($columns !== 0 || $constraints !== 0) {
            throw new PluginLifecycleException('MODULE_MIGRATION_PROVENANCE_SCHEMA_REQUIRED', 'Partial or different provenance schema cannot be deferred.');
        }
        return $prerequisite;
    }

    /** @return array{targets:array<string,array<string,string>>,declarations:array<string,string>} */
    private static function migrationAdoptions(string $moduleKey, array $files, array $repairs): array
    {
        $targets = []; $declarations = [];
        foreach ($files as $key => $path) {
            $body = file_get_contents($path);
            if (!is_string($body) || realpath($path) !== $path || is_link($path) || lstat($path)['nlink'] !== 1) {
                throw new PluginLifecycleException('MODULE_MIGRATION_INVALID', 'Migration input is unreadable or linked.');
            }
            if (!str_contains($body, 'peanut-admin-adopts')) { continue; }
            if (strlen($body) > 4096 || preg_match('/\A-- peanut-admin-adopts: (\{[^\r\n]+\})\n\z/D', $body, $match) !== 1) {
                throw new PluginLifecycleException('MODULE_MIGRATION_ADOPTION_INVALID', 'An adoption declaration must contain exactly one canonical comment and no SQL.');
            }
            try { $value = json_decode($match[1], true, 16, JSON_THROW_ON_ERROR); }
            catch (\JsonException) { throw new PluginLifecycleException('MODULE_MIGRATION_ADOPTION_INVALID', 'Invalid adoption declaration.'); }
            $old = $value['migration_key'] ?? null;
            if (!is_array($value) || array_keys($value) !== ['migration_key', 'proof_contract', 'sha256']
                || self::json($value) !== $match[1] || $moduleKey !== 'official.file'
                || !is_string($old) || !in_array($old, FileMigrationAdoptionProof::MIGRATION_KEYS, true)
                || $old === $key || !isset($files[$old]) || isset($targets[$old]) || isset($repairs[$old])
                || isset($repairs[$key]) || in_array($key, $repairs, true)
                || ($value['proof_contract'] ?? null) !== FileMigrationAdoptionProof::PROOF_CONTRACT
                || preg_match('/^[a-f0-9]{64}$/D', (string) ($value['sha256'] ?? '')) !== 1
                || hash_file('sha256', $files[$old]) !== $value['sha256']) {
                throw new PluginLifecycleException('MODULE_MIGRATION_ADOPTION_INVALID', 'Adoption must bind a unique known same-module original SQL and its exact checksum.');
            }
            $targets[$old] = ['declaration_key' => $key, 'declaration_sha256' => hash('sha256', $body),
                'migration_key' => $old, 'sha256' => $value['sha256'], 'proof_contract' => $value['proof_contract']];
            $declarations[$key] = $old;
        }
        foreach ($targets as $old => $_) {
            if (isset($declarations[$old]) || in_array($old, $repairs, true)) {
                throw new PluginLifecycleException('MODULE_MIGRATION_ADOPTION_INVALID', 'Adoption chains, cycles and repair conflicts are forbidden.');
            }
        }
        return ['targets' => $targets, 'declarations' => $declarations];
    }

    private static function migrationInstance(\PDO $pdo, ?array $recorded = null): array
    {
        if (Db::connect()->getPdo() !== $pdo) { throw new PluginLifecycleException('MODULE_MIGRATION_PDO_MISMATCH', 'Migration connection changed.'); }
        $root = rtrim(root_path(), DIRECTORY_SEPARATOR);
        $databaseIdentity = InstallationExecutionHost::migrationDatabaseIdentity($pdo);
        $database = $databaseIdentity['database'];
        $receiptPath = $root . '/private/installation/installed.json';
        $baselinePath = $root . '/private/installation/baseline.json';
        $progressPath = $root . '/private/installation/executing.json';
        // Fresh installation reconciles modules before writing its completion receipt. Bind the real
        // execution marker and applied application migration, without manufacturing installed state.
        if (!file_exists($receiptPath) && !is_link($receiptPath) && !file_exists($baselinePath) && !is_link($baselinePath)) {
            $context = app()->make(CurrentExecutionContext::class)->current();
            if (!$context instanceof InstallationExecutionContext || realpath($progressPath) !== $progressPath
                || !is_file($progressPath) || is_link($progressPath) || lstat($progressPath)['nlink'] !== 1) {
                throw new PluginLifecycleException('MODULE_MIGRATION_INSTALLATION_UNKNOWN', 'Adoption requires a native installation execution or installed instance.');
            }
            $progress = json_decode((string) file_get_contents($progressPath), true, 32, JSON_THROW_ON_ERROR);
            $admission = self::adoptionAdmission($pdo);
            if (($progress['schema_version'] ?? null) !== 1 || ($progress['state'] ?? null) !== 'executing'
                || !in_array($progress['deployment_mode'] ?? null, ['standalone', 'multi-tenant'], true)
                || $progress['deployment_mode'] !== config('deployment.mode')
                || !is_array($progress['official_modules'] ?? null) || !array_is_list($progress['official_modules'])
                || !in_array('official.file', $progress['official_modules'], true)
                || !is_string($progress['started_at'] ?? null) || strtotime($progress['started_at']) === false) {
                throw new PluginLifecycleException('MODULE_MIGRATION_INSTALLATION_UNKNOWN', 'Native installation execution evidence is incomplete.');
            }
            if ($admission['progress_sha256'] !== hash_file('sha256', $progressPath)
                || self::json($admission['database_identity']) !== self::json($databaseIdentity)) {
                throw new PluginLifecycleException('MODULE_MIGRATION_INSTALLATION_UNKNOWN', 'Native installation admission does not bind this execution.');
            }
            $instance = ['database' => $database, 'database_identity' => $databaseIdentity, 'deployment_mode' => $progress['deployment_mode'],
                'installation_execution' => ['marker_sha256' => hash_file('sha256', $progressPath),
                    'started_at' => $progress['started_at'], 'official_modules' => $progress['official_modules']],
                'fresh_schema_sha256' => $admission['fresh_schema_sha256'],
                'application_migration' => self::freshInstallationMigration($pdo, $root)];
            if ($recorded !== null && self::json($recorded) !== self::json($instance)) {
                throw new PluginLifecycleException('MODULE_MIGRATION_INSTALLATION_UNKNOWN', 'Native installation execution changed.');
            }
            return $instance;
        }
        foreach ([$receiptPath, $baselinePath] as $path) {
            if (realpath($path) !== $path || !is_file($path) || is_link($path) || lstat($path)['nlink'] !== 1) {
                throw new PluginLifecycleException('MODULE_MIGRATION_INSTALLATION_UNKNOWN', 'Adoption requires an ordinary installed instance proof.');
            }
        }
        $receipt = json_decode((string) file_get_contents($receiptPath), true, 32, JSON_THROW_ON_ERROR);
        $baseline = json_decode((string) file_get_contents($baselinePath), true, 32, JSON_THROW_ON_ERROR);
        if (($receipt['protocol'] ?? null) !== 'peanut.installation-receipt.v1' || ($receipt['schema_version'] ?? null) !== 1
            || ($receipt['state'] ?? null) !== 'installed' || ($baseline['protocol'] ?? null) !== 'peanut.installation-baseline.v1'
            || ($baseline['schema_version'] ?? null) !== 1 || ($receipt['deployment_mode'] ?? null) !== ($baseline['deployment_mode'] ?? null)
            || ($receipt['baseline_manifest']['path'] ?? null) !== 'server/private/installation/baseline.json'
            || ($receipt['baseline_manifest']['sha256'] ?? null) !== hash_file('sha256', $baselinePath)) {
            throw new PluginLifecycleException('MODULE_MIGRATION_INSTALLATION_UNKNOWN', 'Installed receipt and baseline are not a pair.');
        }
        if (isset($recorded['installation_execution'])) {
            $execution = $recorded['installation_execution'];
            if (($recorded['database'] ?? null) !== $database || ($recorded['deployment_mode'] ?? null) !== $receipt['deployment_mode']
                || self::json($recorded['database_identity'] ?? null) !== self::json($databaseIdentity)
                || !is_array($execution) || preg_match('/^[a-f0-9]{64}$/D', (string) ($execution['marker_sha256'] ?? '')) !== 1
                || ($execution['official_modules'] ?? null) !== ($baseline['official_modules'] ?? null)
                || ($execution['official_modules'] ?? null) !== ($receipt['official_modules'] ?? null)
                || !is_string($execution['started_at'] ?? null) || strtotime($execution['started_at']) === false
                || !is_string($receipt['completed_at'] ?? null) || strtotime($receipt['completed_at']) === false
                || strtotime($receipt['completed_at']) < strtotime($execution['started_at'])
                || ($recorded['fresh_schema_sha256'] ?? null) !== ($baseline['database']['fresh_schema_sha256'] ?? null)
                || preg_match('/^[a-f0-9]{64}$/D', (string) ($recorded['fresh_schema_sha256'] ?? '')) !== 1
                || self::json($recorded['application_migration'] ?? null) !== self::json(self::freshInstallationMigration($pdo, $root, $recorded['application_migration'] ?? null))) {
                throw new PluginLifecycleException('MODULE_MIGRATION_INSTALLATION_UNKNOWN', 'Completed installation does not bind the recorded native execution.');
            }
            $chain = array_column($baseline['database']['migration_chain'] ?? [], 'sha256', 'id');
            if (($chain[$recorded['application_migration']['migration_id']] ?? null) !== $recorded['application_migration']['checksum']) {
                throw new PluginLifecycleException('MODULE_MIGRATION_INSTALLATION_UNKNOWN', 'Installation baseline does not bind the recorded application migration.');
            }
            return $recorded;
        }
        return ['database' => $database, 'database_identity' => $databaseIdentity, 'installed_receipt_sha256' => hash_file('sha256', $receiptPath),
            'baseline_sha256' => hash_file('sha256', $baselinePath), 'deployment_mode' => $receipt['deployment_mode']];
    }

    private static function freshInstallationMigration(\PDO $pdo, string $root, ?array $recorded = null): array
    {
        $id = '20260823-unify-storage-service';
        $path = $root . '/database/migrations/' . $id . '.sql';
        if ($recorded === null && (realpath($path) !== $path || !is_file($path) || is_link($path) || lstat($path)['nlink'] !== 1)) {
            throw new PluginLifecycleException('MODULE_MIGRATION_INSTALLATION_UNKNOWN', 'Native application migration source is unavailable.');
        }
        $statement = $pdo->prepare('SELECT migration_id,release_version,checksum,status FROM pa_schema_migration WHERE migration_id=?');
        $statement->execute([$id]); $rows = $statement->fetchAll(\PDO::FETCH_ASSOC); $statement->closeCursor();
        $row = $rows[0] ?? [];
        $checksum = $recorded['checksum'] ?? hash_file('sha256', $path);
        if (count($rows) !== 1 || ($row['migration_id'] ?? null) !== $id || ($row['status'] ?? null) !== 'applied'
            || preg_match('/^[a-f0-9]{64}$/D', (string) $checksum) !== 1 || ($row['checksum'] ?? null) !== $checksum
            || !is_string($row['release_version'] ?? null)
            || preg_match('/^[0-9]+\.[0-9]+\.[0-9]+(?:[-+][0-9A-Za-z.+-]+)?$/D', $row['release_version']) !== 1) {
            throw new PluginLifecycleException('MODULE_MIGRATION_INSTALLATION_UNKNOWN', 'Native application migration is not recorded applied.');
        }
        return $row;
    }

    private static function captureFilePreservation(\PDO $pdo, string $key, string $sha): array
    {
        if (!class_exists(FileMigrationPreservation::class)) {
            throw new PluginLifecycleException('MODULE_MIGRATION_FILE_PRESERVATION_UNAVAILABLE', 'The canonical File preservation contract is unavailable.');
        }
        $before = app()->make(FileMigrationPreservation::class)->before($pdo, $key);
        $safe = $before['safe_summary'] ?? null;
        if (!is_array($safe) || ($safe['module_key'] ?? null) !== 'official.file' || ($safe['migration_key'] ?? null) !== $key
            || ($safe['sql_sha256'] ?? null) !== $sha || ($safe['preservation_verified'] ?? null) !== false
            || !is_array($before['private_state'] ?? null)) {
            throw new PluginLifecycleException('MODULE_MIGRATION_PRESERVATION_REQUIRED', 'File preservation facts do not match the current canonical stage.');
        }
        // This contains the actual PDO object. Pass only this inner state to E;
        // neither the outer DTO nor a reconstructed public summary is accepted.
        return $before['private_state'];
    }

    private static function assertFilePreserved(\PDO $pdo, string $key, string $sha, array $privateBefore): void
    {
        $after = app()->make(FileMigrationPreservation::class)->after($pdo, $privateBefore);
        if (($after['module_key'] ?? null) !== 'official.file' || ($after['migration_key'] ?? null) !== $key
            || ($after['sql_sha256'] ?? null) !== $sha || ($after['preservation_verified'] ?? null) !== true) {
            throw new PluginLifecycleException('MODULE_MIGRATION_POSTSTATE_REJECTED', 'File preservation did not prove this canonical stage.');
        }
    }

    /** Same native caller as File, but Document consumes only its own fixed DDL contract. */
    private static function captureDocumentPreservation(\PDO $pdo, string $key, string $sha): array
    {
        if ((self::DOCUMENT_STAGES[$key] ?? null) !== $sha || !class_exists(DocumentMigrationPreservation::class)) {
            throw new PluginLifecycleException('MODULE_MIGRATION_PRESERVATION_REQUIRED', 'The fixed Document source contract is unavailable.');
        }
        $before = app()->make(DocumentMigrationPreservation::class)->before($pdo, $key);
        $safe = $before['safe_summary'] ?? null;
        if ($pdo->inTransaction() || !is_array($safe) || ($safe['module_key'] ?? null) !== 'official.document'
            || ($safe['migration_key'] ?? null) !== $key || ($safe['sql_sha256'] ?? null) !== $sha
            || ($safe['readonly'] ?? null) !== true || ($safe['preservation_verified'] ?? null) !== false
            || !is_array($before['private_state'] ?? null)) {
            throw new PluginLifecycleException('MODULE_MIGRATION_PRESERVATION_REQUIRED', 'Document private facts do not bind the current native stage.');
        }
        return $before['private_state'];
    }

    private static function assertDocumentPreserved(\PDO $pdo, string $key, string $sha, array $privateBefore): void
    {
        $after = app()->make(DocumentMigrationPreservation::class)->after($pdo, $privateBefore);
        $delta = $key === 'official.document:20261012050000_create_business_documents'
            ? 'six_empty_document_tables' : 'operation_progress_columns';
        if (Db::connect()->getPdo() !== $pdo || $pdo->inTransaction() || ($after['module_key'] ?? null) !== 'official.document'
            || ($after['migration_key'] ?? null) !== $key || ($after['sql_sha256'] ?? null) !== $sha
            || ($after['readonly'] ?? null) !== true || ($after['preservation_verified'] ?? null) !== true
            || ($after['proof_kind'] ?? null) !== 'fixed_native_additive_ddl' || ($after['delta_kind'] ?? null) !== $delta
            || ($after['table_count'] ?? null) !== 6) {
            throw new PluginLifecycleException('MODULE_MIGRATION_POSTSTATE_REJECTED', 'Document did not prove the fixed native DDL postconditions.');
        }
    }

    /** Genuine canonical Document2 completion; metadata alone never supplies these predecessors. */
    private function articleDocumentPredecessors(\PDO $pdo): array
    {
        if (Db::connect()->getPdo() !== $pdo || $pdo->inTransaction()) {
            throw new PluginLifecycleException('MODULE_MIGRATION_PDO_MISMATCH', 'Article predecessors require the admitted master.');
        }
        $this->resolver->reload();
        $document = $this->resolver->require('official.document');
        $manifest = $this->pluginManifests($document)['official.document'] ?? null;
        $installation = $this->pluginInstallation($document->key, false);
        if (!$manifest instanceof ManifestDocument || !is_array($installation) || $installation['status'] !== 'active'
            || Db::name('module_installation')->where('module_key', 'official.document')->value('status') !== 'active'
            || Db::name('plugin_module')->where('module_key', 'official.document')->column('plugin_key') !== ['official.document']) {
            throw new PluginLifecycleException('MODULE_MIGRATION_PREDECESSOR_REQUIRED', 'Article requires the installed canonical Document owner.');
        }
        $files = self::migrationFiles($document->moduleRoots['official.document'], $manifest, true);
        if (array_keys($files) !== array_keys(self::DOCUMENT_STAGES)) {
            throw new PluginLifecycleException('MODULE_MIGRATION_PREDECESSOR_REQUIRED', 'The fixed Document stage sources are required.');
        }
        foreach ($files as $key => $file) {
            if (hash_file('sha256', $file) !== self::DOCUMENT_STAGES[$key]) {
                throw new PluginLifecycleException('MODULE_MIGRATION_CHECKSUM_MISMATCH', 'Document predecessor source changed.');
            }
        }
        // Reuse the native source/provenance reader; a missing ledger row is not
        // adoption or permission to execute either predecessor here.
        $plan = $this->moduleMigrationPlan($document, 'official.document', $manifest, false);
        if (($plan['may_apply'] ?? null) !== true || count($plan['migrations']) !== 2) {
            throw new PluginLifecycleException('MODULE_MIGRATION_PREDECESSOR_REQUIRED', 'Document native migrations are unresolved.');
        }
        foreach ($plan['migrations'] as $stage) {
            if (($stage['disposition'] ?? null) !== 'already_applied' || ($stage['ledger_status'] ?? null) !== 'applied'
                || (self::DOCUMENT_STAGES[$stage['migration_key']] ?? null) !== $stage['sha256']) {
                throw new PluginLifecycleException('MODULE_MIGRATION_PREDECESSOR_REQUIRED', 'Both Document stages must genuinely be applied.');
            }
        }
        $query = $pdo->prepare('SELECT * FROM pa_module_migration WHERE module_key=? ORDER BY migration_key');
        $query->execute(['official.document']); $rows = $query->fetchAll(\PDO::FETCH_ASSOC); $query->closeCursor();
        if (array_column($rows, 'migration_key') !== array_keys(self::DOCUMENT_STAGES)
            || Db::connect()->getPdo() !== $pdo || $pdo->inTransaction()) {
            throw new PluginLifecycleException('MODULE_MIGRATION_PREDECESSOR_REQUIRED', 'Document predecessor ledger changed.');
        }
        return ['source' => $document->publicIdentity(), 'manifest_sha256' => $document->manifestDigest,
            'module_manifest_digest' => $manifest->digest, 'ledger' => $rows,
            'installation' => array_intersect_key($installation, array_flip(['status', 'installed_version', 'artifact_sha256', 'lock_digest']))];
    }

    private static function captureArticlePreservation(\PDO $pdo, string $key, string $sha): array
    {
        if ((self::ARTICLE_STAGES[$key] ?? null) !== $sha || !class_exists(ArticleMigrationPreservation::class)) {
            throw new PluginLifecycleException('MODULE_MIGRATION_PRESERVATION_REQUIRED', 'The fixed Article source contract is unavailable.');
        }
        $before = app()->make(ArticleMigrationPreservation::class)->before($pdo, $key);
        $safe = $before['safe_summary'] ?? null;
        if (Db::connect()->getPdo() !== $pdo || $pdo->inTransaction() || !is_array($safe)
            || ($safe['module_key'] ?? null) !== 'official.article' || ($safe['migration_key'] ?? null) !== $key
            || ($safe['sql_sha256'] ?? null) !== $sha || ($safe['readonly'] ?? null) !== true
            || ($safe['preservation_verified'] ?? null) !== false || !is_array($before['private_state'] ?? null)) {
            throw new PluginLifecycleException('MODULE_MIGRATION_PRESERVATION_REQUIRED', 'Article private facts do not bind the current native stage.');
        }
        return $before['private_state'];
    }

    private static function assertArticlePreserved(\PDO $pdo, string $key, string $sha, array $privateBefore): void
    {
        $after = app()->make(ArticleMigrationPreservation::class)->after($pdo, $privateBefore);
        if (Db::connect()->getPdo() !== $pdo || $pdo->inTransaction() || ($after['module_key'] ?? null) !== 'official.article'
            || ($after['migration_key'] ?? null) !== $key || ($after['sql_sha256'] ?? null) !== $sha
            || ($after['readonly'] ?? null) !== true || ($after['preservation_verified'] ?? null) !== true
            || ($after['proof_kind'] ?? null) !== 'fixed_native_additive_ddl'
            || ($after['delta_kind'] ?? null) !== 'article_document_binding_and_empty_save_receipt' || ($after['table_count'] ?? null) !== 2) {
            throw new PluginLifecycleException('MODULE_MIGRATION_POSTSTATE_REJECTED', 'Article did not prove its fixed native DDL postconditions.');
        }
    }

    private static function fileMigrationProof(string $key, ?\PDO $pdo = null): array
    {
        // The fixed application owner reader executes inside the File proof's actual snapshot,
        // not on another Runner connection or from caller-supplied history/provenance data.
        return app()->make(FileMigrationAdoptionProof::class)->report($key, self::fileApplicationMigrationHistory(...), $pdo);
    }

    /** Application history is owned here; it never substitutes for the File Module's complete proof. */
    private static function fileApplicationMigrationHistory(\PDO $pdo, string $moduleKey): array
    {
        $hashes = self::fileApplicationMigrationHashes();
        if (!isset($hashes[$moduleKey])) { throw new \RuntimeException('APPLICATION_MIGRATION_LEDGER_INVALID'); }
        $read = static function (string $sql, array $parameters) use ($pdo): array {
            if (Db::connect()->getPdo() !== $pdo || !$pdo->inTransaction()) {
                throw new \RuntimeException('APPLICATION_MIGRATION_HISTORY_PDO_MISMATCH');
            }
            $statement = $pdo->prepare($sql); $statement->execute($parameters);
            $rows = $statement->fetchAll(\PDO::FETCH_ASSOC); $statement->closeCursor();
            if (Db::connect()->getPdo() !== $pdo || !$pdo->inTransaction()) {
                throw new \RuntimeException('APPLICATION_MIGRATION_HISTORY_PDO_MISMATCH');
            }
            return $rows;
        };
        $history = ['historical_application_migration' => null,
            'historical_application_migration_observation' => 'unknown_ledger_absent'];
        // This literal is the native ApplicationMigrationRunner ledger, not a Module-owned table.
        $tables = $read('SELECT ENGINE,TABLE_TYPE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?', ['pa_schema_migration']);
        if ($tables === []) { return $history; }
        if (count($tables) !== 1 || $tables[0]['ENGINE'] !== 'InnoDB' || $tables[0]['TABLE_TYPE'] !== 'BASE TABLE') {
            throw new \RuntimeException('APPLICATION_MIGRATION_LEDGER_INVALID');
        }
        $columns = $read('SELECT COLUMN_NAME,COLUMN_TYPE,IS_NULLABLE,COLUMN_DEFAULT,EXTRA,GENERATION_EXPRESSION FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?', ['pa_schema_migration']);
        $expected = ['migration_id' => ['varchar(190)', null], 'release_version' => ['varchar(32)', null],
            'checksum' => ['char(64)', null], 'status' => ["enum('applying','applied','failed')", 'applying']];
        foreach ($columns as $column) {
            $name = $column['COLUMN_NAME'];
            if (!isset($expected[$name])) { continue; }
            if (strtolower($column['COLUMN_TYPE']) !== $expected[$name][0] || $column['IS_NULLABLE'] !== 'NO'
                || $column['COLUMN_DEFAULT'] !== $expected[$name][1] || $column['EXTRA'] !== '' || $column['GENERATION_EXPRESSION'] !== '') {
                throw new \RuntimeException('APPLICATION_MIGRATION_LEDGER_INVALID');
            }
            unset($expected[$name]);
        }
        $primary = $read("SELECT COLUMN_NAME,NON_UNIQUE,SUB_PART,INDEX_TYPE,IS_VISIBLE FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='pa_schema_migration' AND INDEX_NAME='PRIMARY' ORDER BY SEQ_IN_INDEX", []);
        if ($expected !== [] || count($primary) !== 1 || $primary[0]['COLUMN_NAME'] !== 'migration_id'
            || (int) $primary[0]['NON_UNIQUE'] !== 0 || $primary[0]['SUB_PART'] !== null
            || $primary[0]['INDEX_TYPE'] !== 'BTREE' || $primary[0]['IS_VISIBLE'] !== 'YES') {
            throw new \RuntimeException('APPLICATION_MIGRATION_LEDGER_INVALID');
        }
        $id = substr($moduleKey, strlen('official.file:'));
        $records = $read('SELECT migration_id,release_version,checksum,status FROM pa_schema_migration WHERE migration_id=?', [$id]);
        $history['historical_application_migration_observation'] = 'unknown_record_absent';
        if ($records === []) { return $history; }
        $row = $records[0];
        if (count($records) !== 1 || $row['migration_id'] !== $id || !is_string($row['release_version'])
            || preg_match('/^[0-9]+\.[0-9]+\.[0-9]+(?:[-+][0-9A-Za-z.+-]+)?$/D', $row['release_version']) !== 1
            || !is_string($row['checksum']) || !hash_equals($hashes[$moduleKey], $row['checksum'])
            || $row['status'] !== 'applied') {
            throw new \RuntimeException('APPLICATION_MIGRATION_RECORD_CONFLICT');
        }
        $history['historical_application_migration'] = ['source' => 'application-migration-ledger',
            'migration_id' => $row['migration_id'], 'release_version' => $row['release_version'],
            'checksum' => $row['checksum'], 'status' => 'applied', 'claim' => 'recorded_applied'];
        $history['historical_application_migration_observation'] = 'recorded_applied';
        return $history;
    }

    /** Existing application-to-File history contract, not a general extra-ledger allowlist. */
    private static function fileApplicationMigrationHashes(): array
    {
        return [
            'official.file:20260823-unify-storage-service' => 'f79c35b2fe8414dc172a81a45183d2a52eadb05833a704149780b36e400ca2d4',
            'official.file:20260921020000_add_file_image_assets' => '826fa9d8fd841b6834bc5f23cabced30b7ca111de4bb1f629ccbdb8501200cd4',
            'official.file:20261012030000_add_controlled_file_references' => '005edad54fc0cf06a4eeb6afa7c0e5f374a0bc352eaa088282b2f12932251591',
            'official.file:20261012051000_add_immutable_document_snapshots' => '7b73c0c24f14f4c7f4fc1f75321e009b1a645d7df3f7d653dbb3d018e3b5bdbe',
        ];
    }

    /**
     * Read canonical source ownership of known transferred application migrations.
     * It neither proves current data postconditions nor adopts/writes any history.
     * @return array<string,array<string,string>>
     */
    public static function applicationMigrationTransfers(PluginLockResolver $resolver): array
    {
        $plugin = $resolver->all()['official.file'] ?? null;
        if ($plugin === null) { return []; }
        $root = $plugin->moduleRoots['official.file'] ?? null;
        if (!is_string($root)) { throw new PluginLifecycleException('MODULE_MIGRATION_ADOPTION_INVALID', 'File ownership source is missing.'); }
        $manifest = (new \PeanutAdmin\Kernel\Module\ManifestLoader())->load($root);
        $files = self::migrationFiles($root, $manifest, true);
        $adoptions = self::migrationAdoptions('official.file', $files, self::migrationRepairMap('official.file', $files));
        $known = self::fileApplicationMigrationHashes();
        $transfers = [];
        foreach ($adoptions['targets'] as $key => $declaration) {
            if (($known[$key] ?? null) !== $declaration['sha256']) {
                throw new PluginLifecycleException('MODULE_MIGRATION_ADOPTION_INVALID', 'Transferred application source differs from its immutable history contract.');
            }
            $id = substr($key, strlen('official.file:'));
            $transfers[$id] = ['migration_id' => $id, 'module_key' => 'official.file',
                'module_migration_key' => $key, 'sha256' => $declaration['sha256'],
                'declaration_key' => $declaration['declaration_key'], 'declaration_sha256' => $declaration['declaration_sha256'],
                'proof_contract' => $declaration['proof_contract'], 'plugin_manifest_sha256' => $plugin->manifestDigest];
        }
        ksort($transfers, SORT_STRING);
        return $transfers;
    }

    private static function assertAdopted(array $row, array $declaration, \PDO $pdo, string $pluginKey): array
    {
        try { $proof = json_decode((string) ($row['provenance_json'] ?? ''), true, 64, JSON_THROW_ON_ERROR); }
        catch (\JsonException) { throw new PluginLifecycleException('MODULE_MIGRATION_ADOPTION_PROVENANCE_INVALID', 'Adopted migration has no valid provenance.'); }
        if (($row['resolution_kind'] ?? null) !== 'adopted' || ($proof['protocol'] ?? null) !== 'peanut.module-migration-adoption.v1'
            || self::json($proof['declaration'] ?? null) !== self::json($declaration) || ($proof['package']['key'] ?? null) !== $pluginKey
            || !is_array($proof['instance'] ?? null)
            || self::json($proof['instance']) !== self::json(self::migrationInstance($pdo, $proof['instance']))
            || !is_array($proof['apply_admission'] ?? null)
            || !self::recordedAdoptionAdmission($proof)
            || !is_array($proof['evidence'] ?? null) || ($proof['evidence']['disposition'] ?? null) !== 'adopt'
            || ($proof['evidence']['may_apply'] ?? null) !== true
            || ($proof['evidence']['migration_key'] ?? null) !== $declaration['migration_key']
            || ($proof['evidence']['proof_contract'] ?? null) !== $declaration['proof_contract']
            || self::json($proof['historical_application_migration'] ?? null) !== self::json($proof['evidence']['checks']['historical_application_migration'] ?? null)
            || ($proof['evidence_sha256'] ?? null) !== hash('sha256', self::json($proof['evidence']))
            || !is_string($proof['operation_id'] ?? null) || trim($proof['operation_id']) === ''
            || !is_array($proof['actor'] ?? null) || !is_string($proof['actor']['actor_key'] ?? null)) {
            throw new PluginLifecycleException('MODULE_MIGRATION_ADOPTION_PROVENANCE_INVALID', 'Adoption evidence no longer binds this declaration and instance.');
        }
        $evidence = $proof['evidence']; $recordedEvidence = $evidence['proof_sha256'] ?? null; unset($evidence['proof_sha256']);
        if ($recordedEvidence !== hash('sha256', json_encode(self::canonicalJsonValue($evidence),
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE))) {
            throw new PluginLifecycleException('MODULE_MIGRATION_ADOPTION_PROVENANCE_INVALID', 'Adoption proof digest changed.');
        }
        foreach (['artifact_sha256', 'lock_digest', 'manifest_sha256', 'module_manifest_sha256'] as $field) {
            if (preg_match('/^[a-f0-9]{64}$/D', (string) ($proof['package'][$field] ?? '')) !== 1) {
                throw new PluginLifecycleException('MODULE_MIGRATION_ADOPTION_PROVENANCE_INVALID', 'Adoption source identity is incomplete.');
            }
        }
        $current = self::fileMigrationProof($declaration['migration_key'], $pdo->inTransaction() ? $pdo : null);
        if (($current['disposition'] ?? null) !== 'adopt' || ($current['may_apply'] ?? null) !== true || Db::connect()->getPdo() !== $pdo) {
            throw new PluginLifecycleException('MODULE_MIGRATION_ADOPTION_DRIFT', 'Adopted migration postconditions no longer hold.');
        }
        if (($proof['historical_application_migration'] ?? null) !== null
            && self::json($proof['historical_application_migration']) !== self::json($current['checks']['historical_application_migration'] ?? null)) {
            throw new PluginLifecycleException('MODULE_MIGRATION_ADOPTION_DRIFT', 'Recorded application migration provenance changed.');
        }
        return $current;
    }

    private static function recordedAdoptionAdmission(array $proof): bool
    {
        $admission = $proof['apply_admission'];
        if (self::json($admission['database_identity'] ?? null) !== self::json($proof['instance']['database_identity'] ?? null)) { return false; }
        if (($admission['scope'] ?? null) === 'server-release') {
            if (($admission['schema_version'] ?? null) !== 1
                || !in_array(realpath(root_path()), [$admission['instance_root'] ?? null, $admission['source_instance_root'] ?? null], true)) { return false; }
            foreach (['plan_sha256', 'target_identity_sha256', 'backup_sha256', 'admission_digest'] as $field) {
                if (preg_match('/^[a-f0-9]{64}$/D', (string) ($admission[$field] ?? '')) !== 1) { return false; }
            }
            if (!is_string($admission['workspace'] ?? null) || $admission['workspace'] === ''
                || !is_string($admission['update_id'] ?? null) || $admission['update_id'] === ''
                || !isset($admission['maintenance'])) { return false; }
            $digest = $admission['admission_digest']; unset($admission['admission_digest']);
            return hash_equals($digest, hash('sha256', json_encode(self::canonicalJsonValue($admission),
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)));
        }
        return ($admission['instance_root'] ?? null) === realpath(root_path())
            && ($admission['protocol'] ?? null) === 'peanut.installation-module-admission.v1'
            && ($admission['request_id'] ?? null) === ($proof['operation_id'] ?? null)
            && ($admission['operation'] ?? null) === ($proof['operation'] ?? null)
            && ($admission['progress_sha256'] ?? null) === ($proof['instance']['installation_execution']['marker_sha256'] ?? null)
            && ($admission['fresh_schema_sha256'] ?? null) === ($proof['instance']['fresh_schema_sha256'] ?? null)
            && ($admission['external_writer_exclusion'] ?? null) === 'ops_protocol_gate_required_not_attested';
    }

    /** Fixed current-stage reader; no caller-supplied SQL, tables, module or ledger authority. */
    private function stageLedgerReader(\PDO $pdo, object $connection, PluginDescriptor $plugin, ManifestDocument $manifest,
        array $files, array $adoptions, string $moduleKey, string $key, string $sha, int $batch, string $started, array $admission): \Closure
    {
        $context = app()->make(CurrentExecutionContext::class)->current();
        $read = static function () use ($pdo, $moduleKey): array {
            $query = $pdo->prepare('SELECT * FROM pa_module_migration WHERE module_key=? ORDER BY id');
            try { $query->execute([$moduleKey]); return $query->fetchAll(\PDO::FETCH_ASSOC); }
            finally { $query->closeCursor(); }
        };
        $original = $read();
        $reader = static function (\PDO $actual) use ($pdo, $connection, $plugin, $manifest, $files, $adoptions,
            $moduleKey, $key, $sha, $batch, $started, $admission, $context, $read, $original): array {
            if ($actual !== $pdo || Db::connect() !== $connection || $connection->getPdo() !== $pdo || !$pdo->inTransaction()
                || app()->make(CurrentExecutionContext::class)->current() !== $context
                || self::json(self::adoptionAdmission($pdo)) !== self::json($admission)) {
                throw new PluginLifecycleException('MODULE_MIGRATION_STAGE_CHANGED', 'The held native stage, request or master changed.');
            }
            $rows = $read(); $seen = []; $current = 0;
            if ($rows !== $original) { throw new PluginLifecycleException('MODULE_MIGRATION_STAGE_CHANGED', 'The complete stage ledger changed.'); }
            foreach ($rows as $row) {
                $rowKey = $row['migration_key'];
                if ($row['module_key'] !== $moduleKey || !isset($files[$rowKey]) || isset($adoptions['declarations'][$rowKey])
                    || isset($seen[$rowKey])) { throw new PluginLifecycleException('MODULE_MIGRATION_STAGE_INVALID', 'Unexpected module history.'); }
                $seen[$rowKey] = true;
                if ($rowKey === $key) {
                    if ($row['status'] !== 'applying' || $row['checksum'] !== $sha || $row['module_version'] !== $manifest->data['version']
                        || (string) $row['batch_no'] !== (string) $batch || $row['started_at'] !== $started
                        || $row['finished_at'] !== null || $row['error_code'] !== null
                        || $row['resolution_kind'] !== null || $row['provenance_json'] !== null) {
                        throw new PluginLifecycleException('MODULE_MIGRATION_STAGE_INVALID', 'The actual committed applying marker must match this invocation.');
                    }
                    ++$current;
                } else {
                    if ($row['status'] !== 'applied' || strcmp($rowKey, $key) >= 0) {
                        throw new PluginLifecycleException('MODULE_MIGRATION_STAGE_INVALID', 'Only genuine earlier completed stages may precede this stage.');
                    }
                    // Keep native historical checksums and repair/adoption provenance;
                    // do not normalize history to today's source hashes.
                    self::assertResolution($row, $adoptions['targets'][$rowKey] ?? null, $pdo, $plugin->key);
                }
            }
            if ($current !== 1 || array_diff(self::PRESERVATION_STAGES[$key][2], array_keys($seen)) !== []) {
                throw new PluginLifecycleException('MODULE_MIGRATION_STAGE_INVALID', 'The exact current stage and real predecessors are required.');
            }
            return $rows;
        };
        return $reader;
    }

    private static function assertStageProofIdentity(\PDO $pdo, string $moduleKey, string $key, string $sha, array $proof, bool $after): void
    {
        if (Db::connect()->getPdo() !== $pdo || $pdo->inTransaction() || ($proof['module_key'] ?? null) !== $moduleKey
            || ($proof['migration_key'] ?? null) !== $key || ($proof['checksum'] ?? null) !== $sha || ($proof['readonly'] ?? null) !== true
            || (!$after && (!is_array($proof['safe_summary'] ?? null) || !($proof['stageLedger'] ?? null) instanceof \Closure))
            || ($after && (($proof['preservation_verified'] ?? null) !== true
                || preg_match('/\A[a-f0-9]{64}\z/D', (string) ($proof['metadata_sha256'] ?? '')) !== 1
                || preg_match('/\A[a-f0-9]{64}\z/D', (string) ($proof['ledger_sha256'] ?? '')) !== 1
                || !is_int($proof['created_empty_tables'] ?? null) || $proof['created_empty_tables'] < 0))) {
            throw new PluginLifecycleException('MODULE_MIGRATION_POSTSTATE_REJECTED', 'The fixed business stage proof is incomplete.');
        }
    }

    /** Preservation readers use the same declaration/provenance rules; no historical execution claim is inferred. */
    public static function assertResolvedMigrationRows(string $moduleKey, array $files, \PDO $pdo): void
    {
        self::assertMigrationSchema();
        $adoptions = self::migrationAdoptions($moduleKey, $files, self::migrationRepairMap($moduleKey, $files));
        $pluginKey = Db::name('plugin_module')->where('module_key', $moduleKey)->value('plugin_key');
        foreach ($files as $key => $path) {
            if (isset($adoptions['declarations'][$key])) { continue; }
            $row = Db::name('module_migration')->where('module_key', $moduleKey)->where('migration_key', $key)->find();
            if (!is_array($row) || $row['status'] !== 'applied' || $row['checksum'] !== hash_file('sha256', $path)) {
                throw new PluginLifecycleException('MODULE_MIGRATION_REPAIR_REQUIRED', 'Module migration requirements are unresolved.');
            }
            self::assertResolution($row, $adoptions['targets'][$key] ?? null, $pdo, (string) $pluginKey);
        }
        if (Db::connect()->getPdo() !== $pdo || !$pdo->inTransaction()) {
            throw new PluginLifecycleException('MODULE_MIGRATION_PDO_MISMATCH', 'Preservation snapshot changed.');
        }
    }

    private static function assertResolution(array $row, ?array $declaration, \PDO $pdo, string $pluginKey): ?array
    {
        $kind = $row['resolution_kind'] ?? null;
        if ($kind === 'adopted') {
            if ($declaration === null) { throw new PluginLifecycleException('MODULE_MIGRATION_ADOPTION_PROVENANCE_INVALID', 'Adoption declaration is missing.'); }
            return self::assertAdopted($row, $declaration, $pdo, $pluginKey);
        } elseif (!in_array($kind, [null, 'executed'], true) || ($row['provenance_json'] ?? null) !== null) {
            throw new PluginLifecycleException('MODULE_MIGRATION_ADOPTION_PROVENANCE_INVALID', 'Migration resolution source is invalid.');
        }
        return null;
    }

    /** Only an actual installed forward transition uses the legacy contract; fresh installation is unchanged. */
    private function notificationForwardRequired(PluginDescriptor $plugin): bool
    {
        if ($plugin->key !== 'official.notification') { return false; }
        $current = $this->pluginInstallation($plugin->key, false);
        if (!is_array($current)) { return false; }
        if ($current['status'] === 'active') { return !$this->sameIdentity($plugin, $current); }
        if (!in_array($current['status'], ['installing', 'failed'], true)) { return false; }
        if (is_file($this->notificationReceiptPath($plugin))) { return true; }
        // Only the actual fresh-installation host may resume without a prior forward binding.
        return !app()->make(CurrentExecutionContext::class)->current() instanceof InstallationExecutionContext;
    }

    private function notificationTarget(PluginDescriptor $plugin): array
    {
        $this->resolver->reload(); $actual = $this->resolver->require($plugin->key);
        if (self::json($actual->publicIdentity()) !== self::json($plugin->publicIdentity()) || $actual->manifestDigest !== $plugin->manifestDigest) {
            throw new PluginLifecycleException('NOTIFICATION_FORWARD_SOURCE_CHANGED', 'Canonical package changed.');
        }
        return ['package' => $actual->publicIdentity(), 'manifest_sha256' => $actual->manifestDigest,
            'module_manifest_digest' => $this->pluginManifests($actual)['official.notification']->digest,
            'module_version' => $this->pluginManifests($actual)['official.notification']->data['version'],
            'module_manifest_sha256' => hash_file('sha256', $actual->moduleRoots['official.notification'] . '/module.json')];
    }

    /** Platform-owned prerequisites run only inside the Module proof's actual read-only snapshot. */
    private static function notificationForwardSchemaReport(): array
    {
        return app()->make(\PeanutAdmin\Modules\Notification\Contract\NotificationUpgradePreflight::class)->forwardSchemaReport(
            static function (\PDO $pdo, string $operation): array {
                $read = static function (string $sql, array $parameters = []) use ($pdo): array {
                    if (Db::connect()->getPdo() !== $pdo || !$pdo->inTransaction()) {
                        throw new PluginLifecycleException('NOTIFICATION_FORWARD_SNAPSHOT_CHANGED', 'The native proof snapshot is required for platform reads.');
                    }
                    $statement = $pdo->prepare($sql); $statement->execute($parameters);
                    $rows = $statement->fetchAll(\PDO::FETCH_ASSOC); $statement->closeCursor();
                    if (Db::connect()->getPdo() !== $pdo || !$pdo->inTransaction()) {
                        throw new PluginLifecycleException('NOTIFICATION_FORWARD_SNAPSHOT_CHANGED', 'The native proof snapshot changed during platform reads.');
                    }
                    return $rows;
                };
                switch ($operation) {
                    case 'ledger':
                        return $read('SELECT migration_key,checksum,status FROM pa_module_migration WHERE module_key=? ORDER BY migration_key', ['official.notification']);
                    case 'parent_keys':
                        self::notificationForwardParentKeys($read);
                        return [];
                    case 'catalog_schema':
                        return self::notificationForwardCatalogSchema($read);
                    default:
                        throw new PluginLifecycleException('NOTIFICATION_FORWARD_OPERATION_UNKNOWN', 'The fixed native prerequisite operation is unknown.');
                }
            },
        );
    }

    private static function notificationForwardParentKeys(\Closure $read): void
    {
        foreach (['pa_tenant' => ['id'], 'pa_tenant_member' => ['tenant_id', 'id']] as $table => $columns) {
            $tableRows = $read('SELECT TABLE_TYPE,ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?', [$table]);
            if (count($tableRows) !== 1 || $tableRows[0]['TABLE_TYPE'] !== 'BASE TABLE' || $tableRows[0]['ENGINE'] !== 'InnoDB') { throw new \RuntimeException('NOTIFICATION_FORWARD_PARENT_UNKNOWN'); }
            $actual = array_column($read('SELECT COLUMN_NAME,COLUMN_TYPE,IS_NULLABLE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?', [$table]), null, 'COLUMN_NAME');
            foreach ($columns as $column) {
                if (($actual[$column]['COLUMN_TYPE'] ?? null) !== 'bigint unsigned' || ($actual[$column]['IS_NULLABLE'] ?? null) !== 'NO') { throw new \RuntimeException('NOTIFICATION_FORWARD_PARENT_KEY_UNKNOWN'); }
            }
            $keys = [];
            foreach ($read('SELECT INDEX_NAME,COLUMN_NAME,SUB_PART FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND NON_UNIQUE=0 ORDER BY INDEX_NAME,SEQ_IN_INDEX', [$table]) as $row) {
                if ($row['SUB_PART'] !== null) { continue; }
                $keys[$row['INDEX_NAME']][] = $row['COLUMN_NAME'];
            }
            if (!in_array($columns, $keys, true)) { throw new \RuntimeException('NOTIFICATION_FORWARD_PARENT_KEY_UNKNOWN'); }
        }
    }

    /** Catalog schema prerequisites, not proof of catalog business acceptance or backup readiness. */
    private static function notificationForwardCatalogSchema(\Closure $read): array
    {
        $columnNames = static function (string $list): array {
            $result = [];
            foreach (explode(',', $list) as $column) {
                if (!preg_match('/^`([a-z_]+)`$/D', trim($column), $m)) { throw new \RuntimeException('NOTIFICATION_FORWARD_INDEX_UNKNOWN'); }
                $result[] = $m[1];
            }
            return $result;
        };
        $required = [
            'pa_permission' => 'id,key,module_key,type,name,risk_level,status,manifest_version,retired_at,created_at,updated_at',
            'pa_data_condition_definition' => 'id,key,module_key,category,target_mode,config_schema_json,status,manifest_version,manifest_digest,created_at,updated_at',
            'pa_target_type' => 'module_key,status,updated_at',
            'pa_protected_resource' => 'id,key,module_key,status,retired_at,updated_at',
            'pa_resource_operation' => 'id,protected_resource_id,operation,status,updated_at',
            'pa_resource_operation_permission' => 'resource_operation_id',
            'pa_resource_operation_target_type' => 'id,resource_operation_id,status',
            'pa_resource_operation_condition' => 'id,resource_operation_id,status',
            'pa_menu_definition' => 'id,key,module_key,scope,parent_key,type,name,route_name,route_path,component_key,icon,sort_order,required_permission_id,client_keys_json,status,is_show,is_cache,is_disable,manifest_digest,upstream_defaults_json,menu_conflict_json,created_at,updated_at',
            'pa_setting_definition' => 'id,module_key,setting_key,status,revision,definition_digest,updated_at',
            'pa_module_installation' => 'module_key,installed_version,manifest_schema_version,manifest_digest,status,revision,installed_at,activated_at,upgraded_at,last_error_code,created_at,updated_at',
            'pa_plugin_installation' => 'plugin_key,installed_version,source,artifact_sha256,lock_digest,composer_identity_json,npm_identity_json,frontend_identity_json,status,revision,installed_at,activated_at,upgraded_at,uninstalled_at,last_error_code,created_at,updated_at',
            'pa_plugin_module' => 'plugin_key,module_key,module_version,manifest_digest,created_at,updated_at',
            'pa_module_migration' => 'module_key,migration_key,module_version,checksum,batch_no,status,resolution_kind,provenance_json,started_at,finished_at,error_code',
        ];
        $optional = $read('SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?', ['pa_reference_code_set']);
        if ($optional !== []) { $required['pa_reference_code_set'] = 'id,module_key,set_key,lifecycle,revision,definition_digest,updated_at'; }
        $observed = [];
        $serverRoot = dirname(__DIR__, 4);
        $canonicalSql = (string) file_get_contents($serverRoot . '/database/init.sql');
        $canonicalSql .= "\n" . \PeanutAdmin\Kernel\Persistence\Schema\KernelSchema::createSql('pa_permission');
        if ($optional !== []) { $canonicalSql .= "\n" . file_get_contents($serverRoot . '/app/modules/official/reference_codes/database/migrations/20260921-adopt-reference-codes-schema.sql'); }
        foreach ($required as $table => $columns) {
            $rows = $read('SELECT COLUMN_NAME,COLUMN_TYPE,IS_NULLABLE,COLUMN_DEFAULT,EXTRA,COLLATION_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? ORDER BY ORDINAL_POSITION', [$table]);
            $missing = array_values(array_diff(explode(',', $columns), array_column($rows, 'COLUMN_NAME')));
            if ($table === 'pa_module_migration' && $missing === ['resolution_kind', 'provenance_json']
                && self::pendingProvenancePrerequisite() !== null) {
                // The plan still checks every existing column/key; execution requires the real DDL first.
                $columns = implode(',', array_diff(explode(',', $columns), $missing));
                $missing = [];
            }
            if ($missing !== []) {
                return ['may_apply' => false, 'code' => 'NOTIFICATION_FORWARD_CATALOG_SCHEMA_UNAVAILABLE'];
            }
            if (!preg_match('/CREATE TABLE(?: IF NOT EXISTS)? `' . $table . '`\s*\((.*?)\)\s*ENGINE/s', $canonicalSql, $definition)) {
                return ['may_apply' => false, 'code' => 'NOTIFICATION_FORWARD_CATALOG_CONTRACT_UNKNOWN'];
            }
            $actual = array_column($rows, null, 'COLUMN_NAME');
            foreach (explode(',', $columns) as $column) {
                if (!preg_match('/^\s*`' . $column . '`\s+([A-Z]+(?:\([^)]*\))?(?: UNSIGNED)?)([^\n]*)/m', $definition[1], $expected)) {
                    return ['may_apply' => false, 'code' => 'NOTIFICATION_FORWARD_CATALOG_CONTRACT_UNKNOWN'];
                }
                preg_match('/DEFAULT (?:\x27([^\x27]*)\x27|([0-9]+))/', $expected[2], $default);
                preg_match('/COLLATE[= ]+([a-z0-9_]+)/', $expected[2], $collation);
                $nullable = !str_contains($expected[2], 'NOT NULL');
                $value = isset($default[1]) && $default[1] !== '' ? $default[1] : ($default[2] ?? null);
                if (strtolower($expected[1]) !== strtolower($actual[$column]['COLUMN_TYPE'])
                    || $nullable !== ($actual[$column]['IS_NULLABLE'] === 'YES') || $value !== $actual[$column]['COLUMN_DEFAULT']
                    || strtolower(trim($actual[$column]['EXTRA'])) !== (str_contains($expected[2], 'AUTO_INCREMENT') ? 'auto_increment' : '')
                    || (isset($collation[1]) && $collation[1] !== $actual[$column]['COLLATION_NAME'])) {
                    return ['may_apply' => false, 'code' => 'NOTIFICATION_FORWARD_CATALOG_SCHEMA_MISMATCH'];
                }
            }
            $engine = $read('SELECT TABLE_TYPE,ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?', [$table]);
            if (count($engine) !== 1 || $engine[0]['TABLE_TYPE'] !== 'BASE TABLE' || $engine[0]['ENGINE'] !== 'InnoDB') {
                return ['may_apply' => false, 'code' => 'NOTIFICATION_FORWARD_CATALOG_ENGINE_UNSUPPORTED'];
            }
            $unique = [];
            foreach ($read('SELECT INDEX_NAME,COLUMN_NAME,SUB_PART FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND NON_UNIQUE=0 ORDER BY INDEX_NAME,SEQ_IN_INDEX', [$table]) as $index) {
                $unique[$index['INDEX_NAME']][] = $index['SUB_PART'] === null ? $index['COLUMN_NAME'] : null;
            }
            preg_match_all('/(?:PRIMARY KEY|UNIQUE KEY `[^`]+`) \(([^)]+)\)/', $definition[1], $keys);
            foreach ($keys[1] as $key) {
                $names = $columnNames($key);
                if (array_diff($names, explode(',', $columns)) === [] && !in_array($names, $unique, true)) {
                    return ['may_apply' => false, 'code' => 'NOTIFICATION_FORWARD_CATALOG_KEY_MISMATCH'];
                }
            }
            $observed[$table] = ['columns' => $rows, 'unique_keys' => $unique];
        }
        return ['may_apply' => true, 'required_schema_sha256' => hash('sha256', json_encode(self::canonicalJsonValue($observed), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)),
            'scope' => 'required_column_types_and_keys; catalog data conflicts and runtime admission remain independent'];
    }

    /** Called under the original package lock, before beginLifecycle can replace the old installation. */
    private function prepareNotificationForward(PluginDescriptor $plugin, array $manifests, bool $upgrade): bool
    {
        if (!$this->notificationForwardRequired($plugin)) { return false; }
        $report = self::notificationForwardSchemaReport();
        if (($report['may_apply'] ?? null) !== true || ($report['catalog_schema']['may_apply'] ?? null) !== true) {
            throw new PluginLifecycleException('NOTIFICATION_FORWARD_PRECONDITION_UNKNOWN', 'Complete schema, ledger and catalog prerequisites are required before lifecycle writes.');
        }
        $pdo = Db::connect()->getPdo();
        if (!$pdo instanceof \PDO || $pdo->inTransaction()) { throw new PluginLifecycleException('NOTIFICATION_FORWARD_CONNECTION_UNKNOWN', 'A stable native connection is required.'); }
        // This preserves T18's existing-instance closed gate. Neither this receipt nor the read-only
        // report is an admission for external callbacks, DDL, backup or catalog recovery.
        $admission = self::adoptionAdmission($pdo);
        $target = $this->notificationTarget($plugin);
        $instance = self::migrationInstance($pdo);
        $path = $this->notificationReceiptPath($plugin);
        Db::transaction(function () use ($plugin, $manifests, $report, $pdo, $admission, $target, $instance, $path, $upgrade): void {
            $this->tenantStates->lockLifecycleRows(array_keys($manifests), [$plugin->key]);
            if (Db::connect()->getPdo() !== $pdo || self::json(self::adoptionAdmission($pdo)) !== self::json($admission)) {
                throw new PluginLifecycleException('NOTIFICATION_FORWARD_ADMISSION_CHANGED', 'Native admission changed.');
            }
            $old = $this->notificationInstalledState($plugin, true);
            if (file_exists($path) || is_link($path)) {
                $binding = self::readNotificationReceipt($path);
                $this->assertNotificationBinding($plugin, $binding, $report, $instance, $old);
                $attempts = $binding['attempts'];
                if (self::json($attempts[array_key_last($attempts)]['before']) !== self::json($old)
                    || $attempts[array_key_last($attempts)]['module_status'] !== ($upgrade ? 'upgrading' : 'installing')) {
                    $previous = $binding;
                    $binding['attempts'][] = ['before' => $old, 'module_status' => $upgrade ? 'upgrading' : 'installing', 'request_id' => app()->make(CurrentExecutionContext::class)->current()->requestId(), 'created_at' => $this->now()];
                    self::writeNotificationReceipt($path, $binding, $previous);
                }
                return;
            }
            if (!is_array($old['plugin']) || $old['plugin']['status'] !== 'active' || count($old['modules']) !== 1
                || $old['modules'][0]['status'] !== 'active' || count($old['ownership']) !== 1
                || $old['ownership'][0]['module_key'] !== 'official.notification') {
                throw new PluginLifecycleException('NOTIFICATION_FORWARD_OLD_IDENTITY_UNKNOWN', 'A new forward operation requires the real active old installation.');
            }
            $context = app()->make(CurrentExecutionContext::class)->current();
            if (!$context instanceof InstanceExecutionContext && !$context instanceof InstallationExecutionContext) {
                throw new PluginLifecycleException('NOTIFICATION_FORWARD_CONTEXT_UNKNOWN', 'Trusted native lifecycle context is required.');
            }
            $binding = ['protocol' => 'peanut.notification-forward-binding.v1', 'instance' => $instance,
                'old' => $old, 'target' => $target, 'initial_schema_report' => $report,
                'attempts' => [['before' => $old, 'module_status' => $upgrade ? 'upgrading' : 'installing', 'request_id' => $context->requestId(), 'created_at' => $this->now()]],
                'admission' => $admission, 'request_id' => $context->requestId(), 'created_at' => $this->now()];
            self::writeNotificationReceipt($path, $binding);
            $persisted = self::readNotificationReceipt($path);
            if (self::json($persisted) !== self::json($binding) || $this->notificationTarget($plugin) !== $target
                || self::json(self::adoptionAdmission($pdo)) !== self::json($admission)) {
                throw new PluginLifecycleException('NOTIFICATION_FORWARD_BINDING_CHANGED', 'Old/new binding was not durably preserved.');
            }
        });
        return true;
    }

    /** Revalidation before each original SQL, after predecessor completion, and before catalog writes. */
    private function assertNotificationForward(PluginDescriptor $plugin): void
    {
        $report = self::notificationForwardSchemaReport();
        $pdo = Db::connect()->getPdo();
        if (!$pdo instanceof \PDO || ($report['may_apply'] ?? null) !== true || ($report['catalog_schema']['may_apply'] ?? null) !== true) {
            throw new PluginLifecycleException('NOTIFICATION_FORWARD_STATE_CHANGED', 'Complete forward schema and catalog state must remain valid.');
        }
        self::adoptionAdmission($pdo);
        $binding = self::readNotificationReceipt($this->notificationReceiptPath($plugin));
        $current = $this->notificationInstalledState($plugin, false);
        $this->assertNotificationBinding($plugin, $binding, $report, self::migrationInstance($pdo), $current);
    }

    /** Only lifecycle identity columns are persisted; arbitrary extension columns are not copied. */
    private function notificationInstalledState(PluginDescriptor $plugin, bool $lock): array
    {
        $pluginRow = Db::name('plugin_installation')->where('plugin_key', $plugin->key)
            ->field('plugin_key,installed_version,source,artifact_sha256,lock_digest,composer_identity_json,npm_identity_json,frontend_identity_json,status,revision,installed_at,activated_at,upgraded_at,uninstalled_at,last_error_code,created_at,updated_at');
        $modules = Db::name('module_installation')->where('module_key', 'official.notification')
            ->field('module_key,installed_version,manifest_schema_version,manifest_digest,status,revision,installed_at,activated_at,upgraded_at,last_error_code,created_at,updated_at');
        $ownership = Db::name('plugin_module')->where('plugin_key', $plugin->key)
            ->field('plugin_key,module_key,module_version,manifest_digest,created_at,updated_at')->order('module_key');
        $ledger = Db::name('module_migration')->where('module_key', 'official.notification')
            ->field('migration_key,module_version,checksum,batch_no,status,resolution_kind,started_at,finished_at,error_code')->order('migration_key');
        if ($lock) { foreach ([$pluginRow, $modules, $ownership, $ledger] as $query) { $query->lock(true); } }
        return ['plugin' => $pluginRow->find(), 'modules' => $modules->select()->toArray(),
            'ownership' => $ownership->select()->toArray(), 'ledger' => $ledger->select()->toArray()];
    }

    private function assertNotificationBinding(PluginDescriptor $plugin, array $binding, array $report, array $instance, array $current): void
    {
        if (($binding['protocol'] ?? null) !== 'peanut.notification-forward-binding.v1'
            || self::json($binding['target'] ?? null) !== self::json($this->notificationTarget($plugin))
            || self::json($binding['instance'] ?? null) !== self::json($instance)
            || self::json($binding['initial_schema_report']['legacy'] ?? null) !== self::json($report['legacy'] ?? null)
            || self::json($binding['initial_schema_report']['catalog_schema'] ?? null) !== self::json($report['catalog_schema'] ?? null)
            || !is_array($binding['old']['plugin'] ?? null) || count($binding['old']['modules'] ?? []) !== 1
            || !is_array($binding['attempts'] ?? null) || $binding['attempts'] === []) {
            throw new PluginLifecycleException('NOTIFICATION_FORWARD_BINDING_MISMATCH', 'Persistent source, instance or preservation binding changed.');
        }
        $old = $binding['old']; $lastAttempt = $binding['attempts'][array_key_last($binding['attempts'])];
        $attempt = $lastAttempt['before'] ?? null; $moduleStatus = $lastAttempt['module_status'] ?? null; $row = $current['plugin'];
        if (!is_array($attempt) || !in_array($moduleStatus, ['upgrading', 'installing'], true)) {
            throw new PluginLifecycleException('NOTIFICATION_FORWARD_RECOVERY_IDENTITY_UNKNOWN', 'Bound native transition operation is unavailable.');
        }
        if (self::json($current) === self::json($attempt)) { return; }
        if (!is_array($row) || count($current['modules']) !== 1 || self::json($current['ownership']) !== self::json($old['ownership'])) {
            throw new PluginLifecycleException('NOTIFICATION_FORWARD_OLD_IDENTITY_CHANGED', 'Installed ownership changed.');
        }
        if ($row['status'] === 'active' && !$this->sameIdentity($plugin, $row)) {
            if (self::json($current) !== self::json($old)) { throw new PluginLifecycleException('NOTIFICATION_FORWARD_OLD_IDENTITY_CHANGED', 'Old installation changed before lifecycle entry.'); }
            return;
        }
        $module = $current['modules'][0]; $prior = $attempt['modules'][0];
        $steps = $row['status'] === 'installing' ? 1 : ($row['status'] === 'failed' ? 2 : 0);
        if ($steps === 0 || !$this->sameIdentity($plugin, $row) || (int) $row['revision'] !== (int) $attempt['plugin']['revision'] + $steps
            || $module['status'] !== ($row['status'] === 'installing' ? $moduleStatus : 'failed') || (int) $module['revision'] !== (int) $prior['revision'] + $steps
            || $module['installed_version'] !== ($binding['target']['module_version'] ?? null)
            || $module['manifest_digest'] !== ($binding['target']['module_manifest_digest'] ?? null)) {
            throw new PluginLifecycleException('NOTIFICATION_FORWARD_RECOVERY_IDENTITY_UNKNOWN', 'Only the bound native transition may resume; unknown or partial states require their original repair path.');
        }
    }

    private function notificationReceiptPath(PluginDescriptor $plugin): string
    {
        $root = rtrim(root_path(), DIRECTORY_SEPARATOR);
        foreach ([$root, $root . '/private', $root . '/private/installation'] as $directory) {
            if (!is_dir($directory) || is_link($directory)) { throw new PluginLifecycleException('NOTIFICATION_FORWARD_RECEIPT_UNAVAILABLE', 'Protected installation directory is unavailable.'); }
        }
        $digest = hash('sha256', self::json([$plugin->publicIdentity(), $plugin->manifestDigest]));
        return $root . '/private/installation/notification-forward/' . $digest . '.json';
    }

    private static function readNotificationReceipt(string $path): array
    {
        $snapshot = self::notificationReceiptSnapshot($path);
        if (!$snapshot['exists']) { throw new PluginLifecycleException('NOTIFICATION_FORWARD_RECEIPT_UNTRUSTED', 'Protected binding receipt is missing.'); }
        return json_decode($snapshot['contents'], true, 64, JSON_THROW_ON_ERROR)['binding'];
    }

    /** Fixed native receipt only. contents are private backup bytes, never a public plan/result. */
    public static function notificationReceiptSnapshot(string $path): array
    {
        $root = rtrim(root_path(), DIRECTORY_SEPARATOR);
        $directory = $root . '/private/installation/notification-forward';
        if (dirname($path) !== $directory || preg_match('/\A[a-f0-9]{64}\.json\z/D', basename($path)) !== 1
            || !function_exists('posix_geteuid')) {
            throw new PluginLifecycleException('NOTIFICATION_FORWARD_RECEIPT_UNTRUSTED', 'Only the native fixed receipt location is supported.');
        }
        foreach ([$root, $root . '/private', $root . '/private/installation'] as $ancestor) {
            if (!is_dir($ancestor) || is_link($ancestor)) { throw new PluginLifecycleException('NOTIFICATION_FORWARD_RECEIPT_UNTRUSTED', 'Receipt ancestor is unavailable.'); }
        }
        clearstatcache(true, $path); clearstatcache(true, $directory);
        $parent = @lstat($directory); $stat = @lstat($path);
        if (($parent !== false && (!is_dir($directory) || is_link($directory) || ($parent['mode'] & 0777) !== 0700 || $parent['uid'] !== posix_geteuid()))
            || is_link($path)) {
            throw new PluginLifecycleException('NOTIFICATION_FORWARD_RECEIPT_UNTRUSTED', 'Protected binding receipt ownership is invalid.');
        }
        $state = ['path' => $path, 'directory_exists' => $parent !== false, 'exists' => $stat !== false,
            'sha256' => null, 'bytes' => 0, 'mode' => null, 'uid' => null, 'gid' => null];
        if ($stat === false) {
            // A failed stat is not evidence of absence. Prove the exact entry
            // missing in its readable native parent (or the receipt directory missing).
            $entries = @scandir($parent === false ? dirname($directory) : $directory);
            if (!is_array($entries) || in_array($parent === false ? basename($directory) : basename($path), $entries, true)) {
                throw new PluginLifecycleException('NOTIFICATION_FORWARD_RECEIPT_UNTRUSTED', 'Receipt absence is not observable.');
            }
            return $state;
        }
        if ($parent === false || !is_file($path) || ($stat['mode'] & 0777) !== 0600 || $stat['nlink'] !== 1 || $stat['uid'] !== posix_geteuid()) {
            throw new PluginLifecycleException('NOTIFICATION_FORWARD_RECEIPT_UNTRUSTED', 'Protected binding receipt ownership is invalid.');
        }
        $stream = fopen($path, 'rb');
        if ($stream === false) { throw new PluginLifecycleException('NOTIFICATION_FORWARD_RECEIPT_UNTRUSTED', 'Receipt cannot be read.'); }
        try {
            $opened = fstat($stream); $bytes = stream_get_contents($stream); $after = fstat($stream);
            $fields = array_flip(['dev', 'ino', 'mode', 'nlink', 'uid', 'gid', 'size', 'mtime', 'ctime']);
            if (!is_array($opened) || !is_array($after)
                || array_intersect_key($opened, $fields) !== array_intersect_key($stat, $fields)
                || array_intersect_key($opened, $fields) !== array_intersect_key($after, $fields)
                || !is_string($bytes) || strlen($bytes) !== $stat['size']) {
                throw new PluginLifecycleException('NOTIFICATION_FORWARD_RECEIPT_CHANGED', 'Receipt changed while reading its original bytes.');
            }
        } finally { fclose($stream); }
        $data = json_decode($bytes, true, 64, JSON_THROW_ON_ERROR);
        if (!is_array($data) || !is_array($data['binding'] ?? null) || !is_string($data['sha256'] ?? null)
            || !hash_equals($data['sha256'], hash('sha256', self::json($data['binding'])))) {
            throw new PluginLifecycleException('NOTIFICATION_FORWARD_RECEIPT_UNTRUSTED', 'Protected binding receipt is invalid.');
        }
        return array_replace($state, ['sha256' => hash('sha256', $bytes), 'bytes' => strlen($bytes),
            'mode' => 0600, 'uid' => $stat['uid'], 'gid' => $stat['gid'], 'contents' => $bytes]);
    }

    private static function writeNotificationReceipt(string $path, array $binding, ?array $previous = null): void
    {
        $directory = dirname($path);
        if (!file_exists($directory) && !mkdir($directory, 0700)) { throw new PluginLifecycleException('NOTIFICATION_FORWARD_RECEIPT_UNAVAILABLE', 'Cannot create protected receipt directory.'); }
        $parent = lstat($directory);
        if (!is_array($parent) || is_link($directory) || ($parent['mode'] & 0777) !== 0700 || !function_exists('posix_geteuid') || $parent['uid'] !== posix_geteuid()
            || is_link($path)) { throw new PluginLifecycleException('NOTIFICATION_FORWARD_RECEIPT_UNTRUSTED', 'Receipt location is not exclusively owned.'); }
        if (($previous === null && file_exists($path)) || ($previous !== null && self::json(self::readNotificationReceipt($path)) !== self::json($previous))) { throw new PluginLifecycleException('NOTIFICATION_FORWARD_RECEIPT_CHANGED', 'Receipt changed before the next bound attempt.'); }
        $temporary = $directory . '/.' . bin2hex(random_bytes(16)) . '.tmp'; $stream = null;
        try {
            $stream = fopen($temporary, 'x+b');
            if ($stream === false || !chmod($temporary, 0600)) { throw new \RuntimeException('RECEIPT_OPEN_FAILED'); }
            $bytes = self::json(['binding' => $binding, 'sha256' => hash('sha256', self::json($binding))]);
            $offset = 0;
            while ($offset < strlen($bytes)) { $n = fwrite($stream, substr($bytes, $offset)); if ($n === false || $n === 0) { throw new \RuntimeException('RECEIPT_WRITE_FAILED'); } $offset += $n; }
            if (!fflush($stream) || !fsync($stream)) { throw new \RuntimeException('RECEIPT_SYNC_FAILED'); }
            fclose($stream); $stream = null;
            if (!rename($temporary, $path)) { throw new \RuntimeException('RECEIPT_RENAME_FAILED'); }
            $handle = fopen($directory, 'r');
            if ($handle === false) { throw new \RuntimeException('RECEIPT_DIRECTORY_SYNC_FAILED'); }
            try { if (!fsync($handle)) { throw new \RuntimeException('RECEIPT_DIRECTORY_SYNC_FAILED'); } } finally { fclose($handle); }
        } catch (\Throwable) {
            throw new PluginLifecycleException('NOTIFICATION_FORWARD_RECEIPT_UNAVAILABLE', 'Durable binding could not be recorded; no lifecycle writes are permitted.');
        } finally {
            if (is_resource($stream)) { fclose($stream); }
            if (is_file($temporary) && !is_link($temporary)) { unlink($temporary); }
        }
    }

    private function moduleMigrationPlan(PluginDescriptor $plugin, string $moduleKey, ManifestDocument $manifest, bool $willMigrate, ?bool $notificationForwardOverride = null): array
    {
        $prerequisite = null;
        try { self::assertMigrationSchema(); }
        catch (PluginLifecycleException $exception) {
            if ($exception->errorCode !== 'MODULE_MIGRATION_PROVENANCE_SCHEMA_REQUIRED'
                || ($prerequisite = self::pendingProvenancePrerequisite()) === null) { throw $exception; }
        }
        $files = self::migrationFiles($plugin->moduleRoots[$moduleKey], $manifest, true);
        $pdo = Db::connect()->getPdo();
        if (!$pdo instanceof \PDO) { throw new PluginLifecycleException('MODULE_MIGRATION_PDO_MISMATCH', 'Master connection is unavailable.'); }
        (new PeanutMigrationAdapter($pdo, $moduleKey))->assertSourceIdentities(array_keys($files));
        $repairs = self::migrationRepairMap($moduleKey, $files);
        $adoptions = self::migrationAdoptions($moduleKey, $files, $repairs);
        $migrations = []; $reports = []; $mayApply = true;
        $notificationForward = $willMigrate && $moduleKey === 'official.notification' && ($notificationForwardOverride ?? $this->notificationForwardRequired($plugin))
            ? self::notificationForwardSchemaReport() : null;
        $notificationAdmission = null;
        if ($notificationForward !== null) {
            $mayApply = ($notificationForward['may_apply'] ?? null) === true && ($notificationForward['catalog_schema']['may_apply'] ?? null) === true;
            try { $notificationAdmission = self::adoptionAdmission(Db::connect()->getPdo()); }
            catch (PluginLifecycleException $exception) {
                if ($prerequisite !== null && $exception->errorCode === 'MODULE_MIGRATION_ADOPTION_WINDOW_REQUIRED') {
                    $notificationAdmission = ['may_apply' => false, 'execution_admitted' => false, 'status' => 'planned',
                        'code' => 'MODULE_MIGRATION_ADMISSION_DEFERRED', 'application_prerequisites' => [$prerequisite]];
                } else {
                    $notificationAdmission = ['may_apply' => false, 'code' => $exception->errorCode, 'external_writer_exclusion' => 'not_attested'];
                    $mayApply = false;
                }
            }
        }
        foreach ($files as $key => $path) {
            if (isset($adoptions['declarations'][$key])) { continue; }
            $checksum = hash_file('sha256', $path);
            $row = Db::name('module_migration')->where('module_key', $moduleKey)->where('migration_key', $key)->find();
            if ($row !== null && $row['checksum'] !== $checksum) {
                throw new PluginLifecycleException('MODULE_MIGRATION_CHECKSUM_MISMATCH', "Migration changed: {$key}");
            }
            $status = $row['status'] ?? 'missing'; $report = null; $admission = null;
            if ($status === 'applied') {
                $pdo = Db::connect()->getPdo();
                if (!$pdo instanceof \PDO) { throw new PluginLifecycleException('MODULE_MIGRATION_PDO_MISMATCH', 'Master connection is unavailable.'); }
                $report = self::assertResolution($row, $adoptions['targets'][$key] ?? null, $pdo, $plugin->key);
                $disposition = 'already_applied';
            } elseif ($row !== null) {
                if (!isset($repairs[$key])) { throw new PluginLifecycleException('MODULE_MIGRATION_REPAIR_REQUIRED', "Append-only repair required: {$key}"); }
                $disposition = 'execute';
                if (!$willMigrate && Db::name('module_migration')->where('module_key', $moduleKey)->where('migration_key', $repairs[$key])->where('status', 'applied')->count() !== 1) {
                    $disposition = 'reject';
                } elseif (!$willMigrate) { $disposition = 'already_applied';
                }
            } else {
                $disposition = $willMigrate ? 'execute' : 'reject';
                if (isset($adoptions['targets'][$key])) {
                    $report = self::fileMigrationProof($key);
                    $disposition = $willMigrate && ($report['may_apply'] ?? null) === true ? ($report['disposition'] ?? 'reject') : 'reject';
                    if (!in_array($disposition, ['execute', 'adopt'], true)) { $disposition = 'reject'; }
                } elseif ($willMigrate && $moduleKey === 'peanut.workflow' && $key === \PeanutAdmin\Modules\Workflow\Application\WorkflowUpgradePreflight::MIGRATION_KEY) {
                    $report = (new \PeanutAdmin\Modules\Workflow\Application\WorkflowUpgradePreflight(new \PeanutAdmin\Modules\Identity\Contract\WorkflowLegacyOrganizationQuery()))->report();
                } elseif ($notificationForward !== null && $key === \PeanutAdmin\Modules\Notification\Contract\NotificationUpgradePreflight::ADOPTION_KEY) {
                    $report = $notificationForward;
                } elseif ($willMigrate && $moduleKey === 'official.notification' && $key === \PeanutAdmin\Modules\Notification\Contract\NotificationUpgradePreflight::MIGRATION_KEY) {
                    if (($notificationForward['status'] ?? null) === 'adoption_required') {
                        $report = ['module_key' => 'official.notification', 'status' => 'pending_predecessor', 'may_apply' => false,
                            'pending_count' => null, 'code' => 'NOTIFICATION_PRODUCER_PREFLIGHT_AFTER_ADOPTION'];
                        $disposition = 'after_predecessor';
                    } else { $report = app()->make(\PeanutAdmin\Modules\Notification\Contract\NotificationUpgradePreflight::class)->report(); }
                }
                if ($report !== null && ($report['may_apply'] ?? null) !== true && $disposition !== 'after_predecessor') { $disposition = 'reject'; }
            }
            if ($disposition === 'adopt') {
                try { $admission = self::adoptionAdmission(Db::connect()->getPdo()); }
                catch (PluginLifecycleException $exception) {
                    if ($prerequisite !== null && $exception->errorCode === 'MODULE_MIGRATION_ADOPTION_WINDOW_REQUIRED') {
                        $admission = ['may_apply' => false, 'execution_admitted' => false, 'status' => 'planned',
                            'code' => 'MODULE_MIGRATION_ADMISSION_DEFERRED', 'application_prerequisites' => [$prerequisite]];
                    } else {
                        $admission = ['may_apply' => false, 'code' => $exception->errorCode,
                            'external_writer_exclusion' => 'ops_protocol_gate_required_not_attested'];
                        $disposition = 'reject';
                    }
                }
            }
            if ($report !== null) { $reports[] = $report; }
            $mayApply = $mayApply && $disposition !== 'reject';
            $migrations[] = ['migration_key' => $key, 'sha256' => $checksum, 'ledger_status' => $status,
                'resolution_kind' => $row['resolution_kind'] ?? null, 'resolution_origin' => ($row['resolution_kind'] ?? null) ?: 'unknown',
                'module_sql_executed' => ($row['resolution_kind'] ?? null) === 'executed' ? true
                    : (($row['resolution_kind'] ?? null) === 'adopted' ? false : null),
                'historical_application_migration' => $report['checks']['historical_application_migration'] ?? null,
                'executes_original_sql' => $row === null && in_array($disposition, ['execute', 'after_predecessor'], true),
                'repair' => $repairs[$key] ?? null, 'adoption' => $adoptions['targets'][$key] ?? null,
                'apply_admission' => $admission, 'disposition' => $disposition];
        }
        foreach (Db::name('module_migration')->where('module_key', $moduleKey)->column('migration_key') as $key) {
            if (!isset($files[$key]) || isset($adoptions['declarations'][$key])) {
                throw new PluginLifecycleException('MODULE_MIGRATION_INVALID', 'Ledger contains an absent SQL or a falsely executed adoption declaration.');
            }
        }
        $receipt = null;
        if ($notificationForward !== null) {
            $receipt = self::notificationReceiptSnapshot($this->notificationReceiptPath($plugin));
            unset($receipt['contents']);
        }
        return ['may_apply' => $mayApply && $prerequisite === null, 'planning_complete' => $mayApply,
            'status' => !$mayApply ? 'blocked' : ($prerequisite === null ? 'ready' : 'planned'), 'execution_admitted' => false,
            'application_prerequisites' => $prerequisite === null ? [] : [$prerequisite],
            'migrations' => $migrations, 'preflight_reports' => $reports,
            'adoption_declarations' => array_values($adoptions['targets']),
            'notification_forward_schema' => $notificationForward, 'notification_forward_admission' => $notificationAdmission,
            'notification_forward_receipt' => $receipt];
    }

    private static function assertAdoptionPrerequisites(array $migrations): void
    {
        foreach ($migrations as $migration) {
            if (($migration['apply_admission']['code'] ?? null) === 'MODULE_MIGRATION_ADOPTION_WINDOW_REQUIRED') {
                throw new PluginLifecycleException('MODULE_MIGRATION_ADOPTION_WINDOW_REQUIRED',
                    'No held native adoption admission is available. Ops writer/DDL exclusion remains a separate prerequisite; no lifecycle state has been written.');
            }
        }
    }

    private static function adoptionAdmission(\PDO $pdo): array
    {
        if (!app()->make(CurrentExecutionContext::class)->current() instanceof InstallationExecutionContext) {
            try {
                if (Db::connect()->getPdo() !== $pdo || !class_exists('PeanutServerUpdatePlan', false)) {
                    throw new \RuntimeException('NATIVE_SERVER_ADMISSION_UNAVAILABLE');
                }
                $admission = \PeanutServerUpdatePlan::databaseMigrationAdmission($pdo);
                if (($admission['schema_version'] ?? null) !== 1 || ($admission['scope'] ?? null) !== 'server-release'
                    || ($admission['instance_root'] ?? null) !== realpath(root_path())
                    || self::json($admission['database_identity'] ?? null) !== self::json(InstallationExecutionHost::migrationDatabaseIdentity($pdo))) {
                    throw new \RuntimeException('NATIVE_SERVER_ADMISSION_INVALID');
                }
                return $admission;
            } catch (\Throwable) {
                throw new PluginLifecycleException('MODULE_MIGRATION_ADOPTION_WINDOW_REQUIRED',
                    'A held native server release admission bound to the actual master database is required.');
            }
        }
        try { return app()->make(InstallationExecutionHost::class)->migrationAdoptionAdmission($pdo); }
        catch (\Throwable) {
            throw new PluginLifecycleException('MODULE_MIGRATION_ADOPTION_WINDOW_REQUIRED', 'A held native installation admission bound to the actual master database is required.');
        }
    }

    private function adoptMigration(PluginDescriptor $plugin, string $moduleKey, ManifestDocument $manifest, array $declaration, int $batch): void
    {
        $admission = self::adoptionAdmission(Db::connect()->getPdo());
        Db::transaction(function ($connection) use ($plugin, $moduleKey, $manifest, $declaration, $batch, $admission): void {
            $pdo = $connection->getPdo();
            if (!$pdo instanceof \PDO || !$pdo->inTransaction() || Db::connect()->getPdo() !== $pdo) {
                throw new PluginLifecycleException('MODULE_MIGRATION_PDO_MISMATCH', 'Adoption requires the native master transaction.');
            }
            if (self::json(self::adoptionAdmission($pdo)) !== self::json($admission)) {
                throw new PluginLifecycleException('MODULE_MIGRATION_ADOPTION_WINDOW_REQUIRED', 'Native installation admission changed.');
            }
            $this->resolver->reload();
            $actual = $this->resolver->require($plugin->key);
            if (self::json($actual->publicIdentity()) !== self::json($plugin->publicIdentity()) || $actual->manifestDigest !== $plugin->manifestDigest) {
                throw new PluginLifecycleException('MODULE_MIGRATION_ADOPTION_SOURCE_CHANGED', 'Canonical package identity changed.');
            }
            $files = self::migrationFiles($actual->moduleRoots[$moduleKey], $manifest, true);
            $map = self::migrationAdoptions($moduleKey, $files, self::migrationRepairMap($moduleKey, $files));
            if (($map['targets'][$declaration['migration_key']] ?? null) !== $declaration) {
                throw new PluginLifecycleException('MODULE_MIGRATION_ADOPTION_SOURCE_CHANGED', 'Canonical adoption declaration changed.');
            }
            $key = $declaration['migration_key'];
            $row = Db::name('module_migration')->where('module_key', $moduleKey)->where('migration_key', $key)->lock(true)->find();
            if ($row !== null) { throw new PluginLifecycleException('MODULE_MIGRATION_ADOPTION_CONFLICT', 'Migration ledger changed after preflight.'); }
            $proof = self::fileMigrationProof($key, $pdo);
            if (($proof['disposition'] ?? null) !== 'adopt' || ($proof['may_apply'] ?? null) !== true) {
                throw new PluginLifecycleException('MODULE_MIGRATION_ADOPTION_REJECTED', 'Complete File postconditions are required.');
            }
            $context = app()->make(CurrentExecutionContext::class)->current();
            if (!$context instanceof InstanceExecutionContext && !$context instanceof InstallationExecutionContext) {
                throw new PluginLifecycleException('MODULE_MIGRATION_ADOPTION_UNAUTHORIZED', 'Only the trusted instance lifecycle can adopt a migration.');
            }
            $filePrivate = self::captureFilePreservation($pdo, $key, $declaration['sha256']);
            $provenance = ['protocol' => 'peanut.module-migration-adoption.v1', 'declaration' => $declaration,
                'package' => ['key' => $plugin->key, 'version' => $plugin->version, 'artifact_sha256' => $plugin->source['sha256'],
                    'lock_digest' => $plugin->lockDigest, 'manifest_sha256' => $plugin->manifestDigest,
                    'module_manifest_sha256' => hash_file('sha256', $plugin->moduleRoots[$moduleKey] . '/module.json')],
                'instance' => self::migrationInstance($pdo), 'evidence' => $proof, 'evidence_sha256' => hash('sha256', self::json($proof)),
                'historical_application_migration' => $proof['checks']['historical_application_migration'] ?? null,
                'apply_admission' => $admission,
                'actor' => $context->actor(), 'operation' => $context->operation(), 'operation_id' => $context->requestId()];
            $now = $this->now();
            Db::name('module_migration')->insert(['module_key' => $moduleKey, 'migration_key' => $key,
                'module_version' => $manifest->data['version'], 'checksum' => $declaration['sha256'], 'batch_no' => $batch,
                'status' => 'applied', 'resolution_kind' => 'adopted', 'provenance_json' => self::json($provenance),
                'started_at' => $now, 'finished_at' => $now, 'error_code' => null]);
            app()->make(AuditService::class)->appendPlatformEvent('module.migration.adopted', 'adopt', 'success', null,
                null, null, 'module_migration', $key, $context->requestId(), $context->requestId(), null, null, null, null, self::json($provenance));
            $after = self::fileMigrationProof($key, $pdo);
            if (self::json($proof) !== self::json($after) || Db::connect()->getPdo() !== $pdo || !$pdo->inTransaction()
                || self::json(self::adoptionAdmission($pdo)) !== self::json($admission)) {
                throw new PluginLifecycleException('MODULE_MIGRATION_ADOPTION_DRIFT', 'Observed File proof, transaction or native admission changed during adoption.');
            }
            // Include the real ledger/audit DML in this observation window. An
            // assertion failure rolls back this adoption transaction, never DDL.
            self::assertFilePreserved($pdo, $key, $declaration['sha256'], $filePrivate);
            unset($filePrivate);
            if (Db::connect()->getPdo() !== $pdo || !$pdo->inTransaction()
                || self::json(self::adoptionAdmission($pdo)) !== self::json($admission)) {
                throw new PluginLifecycleException('MODULE_MIGRATION_ADOPTION_DRIFT', 'Native admission or transaction changed during File preservation.');
            }
        });
    }

    /** @return array<string,string> */
    private static function migrationFiles(string $root, ManifestDocument $manifest, bool $requireDeclared = false): array
    {
        $directories = [];
        $backend = $manifest->data['backend'] ?? null;
        if (is_array($backend) && is_string($backend['migrations'] ?? null)) {
            $directories[] = $root . '/' . ltrim($backend['migrations'], '/');
            if ($requireDeclared && (!is_dir($directories[0]) || !is_readable($directories[0]) || is_link($directories[0]))) {
                throw new PluginLifecycleException('MODULE_MIGRATION_INVALID', 'Declared migrations are unavailable.');
            }
        }
        $directories[] = $root . '/database/migrations';
        $files = [];
        $resolvedDirectories = [];
        foreach ($directories as $directory) {
            if ($requireDeclared && (file_exists($directory) || is_link($directory))
                && (!is_dir($directory) || !is_readable($directory) || is_link($directory))) {
                throw new PluginLifecycleException('MODULE_MIGRATION_INVALID', 'Migration directory is unavailable.');
            }
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
    private static function migrationRepairMap(string $moduleKey, array $files): array
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
            && $this->tenantStates->activeInstallationCount(array_values(array_map('strval', $moduleKeys)))
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

    private static function json(mixed $value): string
    {
        return (string) json_encode(
            self::canonicalJsonValue($value),
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

    private static function canonicalJsonValue(mixed $value): mixed
    {
        if (!is_array($value)) {
            return $value;
        }
        if (array_is_list($value)) {
            return array_map(static fn(mixed $item): mixed => self::canonicalJsonValue($item), $value);
        }
        ksort($value, SORT_STRING);
        foreach ($value as $key => $item) {
            $value[$key] = self::canonicalJsonValue($item);
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

    private function withPackageLock(string $pluginKey, callable $operation): array
    {
        try {
            return (new AdvisoryLockExecution())->run($this->lockName($pluginKey), 0, function () use ($operation): array {
                $this->resolver->reload();
                return $operation();
            });
        } catch (AdvisoryLockUnavailable) {
            throw new PluginLifecycleException('MODULE_LIFECYCLE_BUSY', 'Module lifecycle is busy.');
        }
    }

}
