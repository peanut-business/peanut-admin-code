<?php

declare(strict_types=1);

namespace app\common\services\installation;

use app\common\composition\ModuleComposition;
use app\common\exception\installation\InstallationExecutionException;
use app\common\services\audit\AuditContractHost;
use app\common\value\installation\ServerReleaseIdentity;
use app\platform\infrastructure\module\ThinkPhpModuleGovernanceProvider;
use app\platform\services\module\ProductTenantModuleProfileService;
use app\platform\infrastructure\plugin\ModuleCatalogApplier;
use PeanutAdmin\Kernel\Module\CompiledModuleRegistry;
use PeanutAdmin\Kernel\Module\ModuleRuntimeRepository;
use PeanutAdmin\Modules\Identity\Contract\TenantModuleStateQueries;
use Closure;
use PDO;
use RuntimeException;
use think\db\PDOConnection;
use think\facade\Config;
use think\facade\Db;
use Throwable;

/** The only application-owned fresh installation execution runtime. */
final class InstallationExecutionHost
{
    private const MODES = ['guided', 'automatic'];

    /** @param Closure(CompiledModuleRegistry):ModuleRuntimeRepository $moduleRuntimeFactory */
    public function __construct(
        private readonly string $serverRoot,
        private readonly ModuleCatalogApplier $catalogs,
        private readonly \PeanutAdmin\Modules\Identity\Contract\AdminDirectoryQuery $tenantDirectory,
        private readonly Closure $moduleRuntimeFactory,
        private readonly AuditContractHost $audit,
        private readonly TenantModuleStateQueries $moduleStates,
        private readonly ModuleComposition $moduleComposition,
        private readonly CompiledModuleRegistry $definitions,
    ) {
        require_once $serverRoot . '/database/install.php';
    }

    /** The normal request gate checks only the physical completion lock. */
    public function isInstalled(): bool
    {
        return $this->completionLockPresent();
    }

    /** @return array{installed:bool,deployment_mode:string} */
    public function entryStatus(): array
    {
        return [
            'installed' => $this->completionLockPresent(),
            'deployment_mode' => $this->deploymentMode(),
        ];
    }

    /** @return array<string,mixed> */
    public function status(): array
    {
        $tenantBootstrap = \installationTenantBootstrapContract($this->serverRoot);
        $preflight = (new InstallationPreflightHost($this->serverRoot))->inspect();
        $base = [
            'mode' => $this->mode(),
            'deployment_mode' => $this->deploymentMode(),
            'tenant_bootstrap' => $tenantBootstrap,
            'preflight' => $preflight,
            'official_modules' => $this->officialModules(),
        ];
        $complete = $this->completionLockPresent();
        if (($preflight['status'] ?? null) !== 'ready') {
            return [
                ...$base,
                'state' => 'blocked',
                'code' => 'INSTALL_PREFLIGHT_BLOCKED',
                'retryable' => !$complete,
                'health' => null,
            ];
        }

        if (!$this->installationMigrationComplete()) {
            return [
                ...$base,
                'state' => 'blocked',
                'code' => 'INSTALL_STATE_MIGRATION_PENDING',
                'retryable' => false,
                'health' => null,
            ];
        }
        if ($this->hasLegacyInstallationState()) {
            return [
                ...$base,
                'state' => 'blocked',
                'code' => 'INSTALL_STATE_MIGRATION_REQUIRED',
                'retryable' => false,
                'health' => null,
            ];
        }

        if ($complete && !$this->completionReceiptValid()) {
            return [
                ...$base,
                'state' => 'blocked',
                'code' => 'INSTALL_COMPLETION_LOCK_INVALID',
                'retryable' => false,
                'health' => null,
            ];
        }

        try {
            $database = \installationDatabaseState($this->serverRoot);
        } catch (Throwable) {
            return [
                ...$base,
                'state' => 'blocked',
                'code' => $complete ? 'INSTALL_LOCKED_DATABASE_UNAVAILABLE' : 'INSTALL_DATABASE_UNAVAILABLE',
                'retryable' => !$complete,
                'health' => null,
            ];
        }

        $progress = is_file($this->progressMarker());
        if ($complete) {
            if ($database['state'] !== 'installed') {
                return [
                    ...$base,
                    'state' => 'blocked',
                    'code' => 'INSTALL_LOCKED_DATABASE_MISMATCH',
                    'retryable' => false,
                    'health' => null,
                ];
            }
            return [
                ...$base,
                'state' => 'installed',
                'code' => 'INSTALL_ALREADY_COMPLETED',
                'retryable' => false,
                'health' => $database['health'],
            ];
        }
        if ($progress && $database['state'] !== 'uninstalled') {
            return [
                ...$base,
                'state' => 'blocked',
                'code' => 'INSTALL_PARTIAL_STATE_REQUIRES_REBUILD',
                'retryable' => false,
                'health' => null,
            ];
        }
        if ($database['state'] === 'uninstalled') {
            if ($this->completionLockPresent()) {
                return [
                    ...$base,
                    'state' => 'blocked',
                    'code' => 'INSTALL_LOCKED_DATABASE_MISMATCH',
                    'retryable' => false,
                    'health' => null,
                ];
            }
            return [
                ...$base,
                'state' => 'uninstalled',
                'code' => $progress ? 'INSTALL_RETRY_READY' : 'INSTALL_READY',
                'retryable' => true,
                'health' => null,
            ];
        }
        if ($database['state'] === 'installed') {
            return [
                ...$base,
                'state' => 'blocked',
                'code' => 'INSTALL_COMPLETION_LOCK_MISSING',
                'retryable' => false,
                'health' => null,
            ];
        }

        return [
            ...$base,
            'state' => 'blocked',
            'code' => 'INSTALL_PARTIAL_STATE_REQUIRES_REBUILD',
            'retryable' => false,
            'health' => null,
        ];
    }

    /** @param array<string,mixed> $input @return array<string,mixed> */
    public function executeGuided(string $token, array $input): array
    {
        if ($this->mode() !== 'guided') {
            throw new InstallationExecutionException(
                'INSTALL_GUIDED_MODE_DISABLED',
                '当前部署未启用 guided 安装模式。',
                403,
            );
        }
        $expected = (string) Config::get('peanut.installation.setup_token', '');
        if (preg_match('/^[A-Za-z0-9_-]{32,128}$/D', $expected) !== 1
            || $token === ''
            || !hash_equals($expected, $token)) {
            throw new InstallationExecutionException(
                'INSTALL_SETUP_TOKEN_INVALID',
                'Setup token 无效。',
                403,
            );
        }
        return $this->execute($input);
    }

    /** @param array<string,mixed> $input @return array<string,mixed> */
    public function executeAutomatic(array $input): array
    {
        if ($this->mode() !== 'automatic') {
            throw new InstallationExecutionException(
                'INSTALL_AUTOMATIC_MODE_DISABLED',
                '当前部署未启用 automatic 安装模式。',
                409,
            );
        }
        return $this->execute($input);
    }

    /** @param array<string,mixed> $input @return array<string,mixed> */
    private function execute(array $input): array
    {
        $lock = $this->acquireExecutionLock();
        try {
            if ($this->completionLockPresent()) {
                throw new InstallationExecutionException(
                    'INSTALL_ALREADY_COMPLETED',
                    '安装完成锁已经存在，重复执行已被拒绝。',
                    409,
                );
            }
            $status = $this->status();
            if ($status['state'] === 'installed') {
                throw new InstallationExecutionException(
                    'INSTALL_ALREADY_COMPLETED',
                    '系统已经完成安装，重复执行已被拒绝。',
                    409,
                );
            }
            if ($status['state'] !== 'uninstalled') {
                throw new InstallationExecutionException(
                    (string) $status['code'],
                    $status['retryable']
                        ? '安装前置条件暂未满足。'
                        : '目标包含未完成安装残留，必须由资源 owner 重建后再安装。',
                    409,
                );
            }

            [$credentials, $moduleKeys] = $this->normalizeInput($input);
            $this->writeProgressMarker($moduleKeys);
            $stage = 'fresh_database';
            try {
                $baseline = \installFreshDatabase($this->serverRoot, $credentials);
                $stage = 'migrate_database';
                $migration = \migrateDatabase(
                    $this->serverRoot,
                    $this->migrationTargetVersion(),
                );
                $stage = 'install_modules';
                $modules = $this->installModules(
                    $moduleKeys,
                    \installationTenantBootstrapContract($this->serverRoot),
                );
                $stage = 'health';
                $health = $this->health($moduleKeys);
                $stage = 'completion_marker';
                $receipt = $this->writeCompletionMarker($moduleKeys);
                @unlink($this->progressMarker());

                return [
                    'state' => 'installed',
                    'code' => 'INSTALL_COMPLETED',
                    'deployment_mode' => $this->deploymentMode(),
                    'baseline' => $baseline,
                    'migration' => $migration,
                    'modules' => $modules,
                    'health' => $health,
                    'installation_receipt' => $receipt,
                ];
            } catch (Throwable $exception) {
                $failure = new InstallationExecutionException(
                    'INSTALL_EXECUTION_FAILED',
                    '安装执行失败；若目标已产生表，请由资源 owner 重建目标后重试。',
                    409,
                    $exception,
                );
                \logInstallationFailure($this->serverRoot, $stage, $failure->errorCode, $failure);
                try {
                    $database = \installationDatabaseState($this->serverRoot);
                    if ($database['state'] === 'uninstalled') {
                        @unlink($this->progressMarker());
                    }
                } catch (Throwable) {
                }
                throw $failure;
            }
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    /** @return resource */
    private function acquireExecutionLock()
    {
        $directory = dirname($this->progressMarker());
        if (!is_dir($directory) && !mkdir($directory, 0770, true) && !is_dir($directory)) {
            throw new InstallationExecutionException(
                'INSTALL_STATE_UNAVAILABLE',
                '安装状态目录不可用。',
                503,
            );
        }
        $handle = fopen($directory . '/execution.lock', 'c+');
        if ($handle === false || !flock($handle, LOCK_EX | LOCK_NB)) {
            if (is_resource($handle)) {
                fclose($handle);
            }
            throw new InstallationExecutionException(
                'INSTALL_EXECUTION_IN_PROGRESS',
                '已有安装执行正在进行。',
                409,
            );
        }
        return $handle;
    }

    /**
     * @param array<string,mixed> $input
     * @return array{0:array<string,mixed>,1:list<string>}
     */
    private function normalizeInput(array $input): array
    {
        $allowed = [
            'admin_email',
            'admin_password',
            'platform_email',
            'platform_password',
            'official_modules',
        ];
        if (array_diff(array_keys($input), $allowed) !== []) {
            throw new InstallationExecutionException(
                'INSTALL_INPUT_INVALID',
                '安装请求包含不支持的字段。',
                422,
            );
        }
        $modules = $input['official_modules'] ?? array_column($this->officialModules(), 'key');
        if (!is_array($modules)
            || !array_is_list($modules)
            || array_filter($modules, static fn(mixed $key): bool => !is_string($key)) !== []) {
            throw new InstallationExecutionException('INSTALL_MODULE_SELECTION_INVALID', 'Module 选择无效。', 422);
        }
        $modules = array_values(array_unique($modules));
        $options = $this->officialModules();
        $available = array_column($options, 'key');
        if (array_diff($modules, $available) !== []) {
            throw new InstallationExecutionException('INSTALL_MODULE_SELECTION_INVALID', 'Module 选择无效。', 422);
        }
        foreach ($options as $option) {
            if ($option['required']) {
                $modules[] = $option['key'];
            }
        }
        $modules = array_values(array_unique($modules));
        $selected = array_fill_keys($modules, true);
        $definitions = [];
        foreach ($this->definitionRegistry()->modules as $manifest) {
            $key = (string) $manifest->data['key'];
            if (isset($selected[$key])) {
                $definitions[$key] = [
                    'version' => (string) $manifest->data['version'],
                    'dependencies' => $manifest->data['dependencies'] ?? [],
                ];
            }
        }
        // 与独立模块包使用同一依赖/版本规则，且在创建数据库之前完成校验。
        $serverIdentity = $this->serverRoot . '/.peanut/release-identity.json';
        $projectRoot = dirname($this->serverRoot);
        $applicationManifest = $projectRoot . '/.peanut/application-manifest.json';
        if (file_exists($serverIdentity) || is_link($serverIdentity)) {
            $packageRoot = $this->serverRoot;
        } elseif (\installationSourceDevelopmentMode($this->serverRoot)) {
            $packageRoot = $projectRoot;
        } elseif (file_exists($applicationManifest) || is_link($applicationManifest)) {
            if (!is_file($applicationManifest) || is_link($applicationManifest)) {
                throw new RuntimeException('INSTALL_RELEASE_IDENTITY_UNAVAILABLE');
            }
            $packageRoot = $projectRoot;
        } else {
            throw new RuntimeException('INSTALL_RELEASE_IDENTITY_UNAVAILABLE');
        }
        $modules = (new \app\platform\validation\plugin\ModulePackagePreflight($packageRoot))
            ->dependencyOrder($definitions, []);
        $credentials = array_intersect_key($input, array_flip([
            'admin_email', 'admin_password', 'platform_email', 'platform_password',
        ]));
        try {
            \normalizeInstallationCredentials($credentials);
        } catch (Throwable) {
            throw new InstallationExecutionException('INSTALL_IDENTITY_INVALID', '初始身份不符合安装要求。', 422);
        }
        return [$credentials, $modules];
    }

    /** @return list<array{key:string,label:string,description:string,required:bool,default:bool}> */
    private function officialModules(): array
    {
        $modules = [];
        $registry = $this->definitionRegistry();
        foreach ($registry->modules as $manifest) {
            $key = $manifest->data['key'] ?? null;
            if (is_string($key) && str_starts_with($key, 'official.')) {
                $required = $registry->isRequiredTenantFoundation($key);
                $modules[] = [
                    'key' => $key,
                    'label' => (string) ($manifest->data['name'] ?? substr($key, strlen('official.'))),
                    'description' => (string) ($manifest->data['description'] ?? ''),
                    'required' => $required,
                    'default' => true,
                ];
            }
        }
        usort($modules, static fn(array $left, array $right): int => $left['key'] <=> $right['key']);
        return $modules;
    }

    /**
     * @param list<string> $moduleKeys
     * @param array{kind:string,code:string,tenant_identity:string,rbac:string,execution_context:string,module_lifecycle:string} $tenantBootstrap
     * @return array<string,mixed>
     */
    private function installModules(array $moduleKeys, array $tenantBootstrap): array
    {
        $config = $this->moduleConfig();
        $registry = $this->definitionRegistry();
        $governance = new ThinkPhpModuleGovernanceProvider(
            $this->serverRoot,
            $config,
            $this->catalogs,
            $registry,
        );
        $lifecycle = $governance->pluginLifecycle();
        $operations = [];
        foreach ($moduleKeys as $moduleKey) {
            $result = $lifecycle->reconcile($moduleKey);
            $operations[] = [
                'key' => $moduleKey,
                'operation' => (string) ($result['operation'] ?? ''),
            ];
        }
        $profile = (new ProductTenantModuleProfileService(
            $this->runtimeForProfile($registry),
            $governance,
            $this->audit,
            $this->tenantDirectory,
            $this->moduleComposition,
        ))->applyInstallationSelection($moduleKeys, $tenantBootstrap['code']);
        return ['operations' => $operations, 'profile' => $profile];
    }

    /** Construct persistence after reconciliation over the same immutable deployment declaration. */
    private function runtimeForProfile(CompiledModuleRegistry $registry): ModuleRuntimeRepository
    {
        return ($this->moduleRuntimeFactory)($registry);
    }

    /** @param list<string> $moduleKeys @return array<string,int> */
    private function health(array $moduleKeys): array
    {
        $pdo = $this->pdo();
        $tenantBootstrap = \installationTenantBootstrapContract($this->serverRoot);
        $health = \assertCurrentDatabase($pdo);
        if ($moduleKeys !== []) {
            if ($this->moduleStates->activeInstallationCount($moduleKeys) !== count($moduleKeys)) {
                throw new RuntimeException('Official Module installation is incomplete.');
            }
            $definitions = $this->definitionRegistry();
            $tenantManaged = array_values(array_filter(
                $moduleKeys,
                fn(string $moduleKey): bool => !$definitions->isRequiredTenantFoundation($moduleKey),
            ));
            if ($tenantManaged !== []) {
                if ($this->moduleStates->enabledTenantSelectionCount($tenantBootstrap['code'], $tenantManaged) !== count($tenantManaged)) {
                    throw new RuntimeException('Default Tenant Module selection is incomplete.');
                }
            }
        }
        $health['selected_module_count'] = count($moduleKeys);
        return $health;
    }

    private function pdo(): PDO
    {
        $connection = Db::connect();
        if (!$connection instanceof PDOConnection) {
            throw new RuntimeException('INSTALL_DATABASE_DRIVER_UNAVAILABLE');
        }
        $pdo = $connection->getPdo();
        if (!$pdo instanceof PDO) {
            throw new RuntimeException('INSTALL_DATABASE_CONNECTION_UNAVAILABLE');
        }

        return $pdo;
    }

    /** @return array<string,mixed> */
    private function moduleConfig(): array
    {
        $config = Config::get('modules', []);
        if (!is_array($config)) {
            throw new RuntimeException('MODULE_REGISTRY_UNAVAILABLE');
        }

        return $config;
    }

    private function definitionRegistry(): CompiledModuleRegistry
    {
        return $this->definitions;
    }

    /** Select Peanut-owned SQL by the adopted scaffold and optional verified overlay identity. */
    private function migrationTargetVersion(): string
    {
        $versions = \applicationReleaseVersions($this->serverRoot);
        return \applicationMigrationTargetVersion($this->serverRoot, $versions);
    }

    private function mode(): string
    {
        $mode = trim((string) Config::get('peanut.installation.mode', 'automatic'));
        if (!in_array($mode, self::MODES, true)) {
            throw new RuntimeException('PEANUT_INSTALLATION_MODE must be guided or automatic.');
        }
        return $mode;
    }

    private function deploymentMode(): string
    {
        $mode = trim((string) Config::get('deployment.mode', ''));
        if ($mode !== 'standalone' && $mode !== 'multi-tenant') {
            throw new RuntimeException('DEPLOYMENT_MODE must be standalone or multi-tenant.');
        }
        return $mode;
    }

    /** @param list<string> $moduleKeys */
    private function writeProgressMarker(array $moduleKeys): void
    {
        $this->writeMarker($this->progressMarker(), [
            'schema_version' => 1,
            'state' => 'executing',
            'deployment_mode' => $this->deploymentMode(),
            'official_modules' => $moduleKeys,
            'started_at' => gmdate(DATE_ATOM),
        ]);
    }

    /** @param list<string> $moduleKeys @return array<string,mixed> */
    private function writeCompletionMarker(array $moduleKeys): array
    {
        $versions = \applicationReleaseVersions($this->serverRoot);
        $baseline = $this->installationBaseline($moduleKeys, $versions);
        $this->writeMarker($this->baselineMarker(), $baseline);
        $baselineSha256 = hash_file('sha256', $this->baselineMarker());
        if (!is_string($baselineSha256)) {
            throw new RuntimeException('INSTALL_RELEASE_IDENTITY_UNAVAILABLE');
        }
        $payload = [
            'schema_version' => 1,
            'protocol' => 'peanut.installation-receipt.v1',
            'state' => 'installed',
            'deployment_mode' => $this->deploymentMode(),
            'official_modules' => $moduleKeys,
            'release' => $versions,
            'baseline_manifest' => [
                'path' => 'server/private/installation/baseline.json',
                'sha256' => $baselineSha256,
            ],
            'completed_at' => gmdate(DATE_ATOM),
        ];
        if (isset($baseline['source']['application_manifest_sha256'])) {
            $payload['application_manifest_sha256'] = $baseline['source']['application_manifest_sha256'];
        }
        $this->writeMarker($this->completionMarker(), $payload);
        $receiptSha256 = hash_file('sha256', $this->completionMarker());
        if (!is_string($receiptSha256)) {
            throw new RuntimeException('INSTALL_RELEASE_IDENTITY_UNAVAILABLE');
        }
        return [
            'path' => 'server/private/installation/installed.json',
            'sha256' => $receiptSha256,
            'baseline_manifest' => $payload['baseline_manifest'],
        ];
    }

    /** @param list<string> $moduleKeys @param array<string,string> $versions @return array<string,mixed> */
    private function installationBaseline(array $moduleKeys, array $versions): array
    {
        $projectRoot = dirname($this->serverRoot);
        $serverIdentity = $this->serverRoot . '/.peanut/release-identity.json';
        $applicationManifest = $projectRoot . '/.peanut/application-manifest.json';
        if (file_exists($serverIdentity) || is_link($serverIdentity)) {
            $identity = ServerReleaseIdentity::resolve($this->serverRoot);
            $source = [
                'kind' => 'server-release',
                'server_release_identity_sha256' => $identity->identitySha256(),
                'application_manifest_sha256' => $identity->manifestSha256(),
            ];
        } elseif (\installationSourceDevelopmentMode($this->serverRoot)) {
            $source = [
                'kind' => 'product-source',
                'inventory_sha256' => $this->fileDigest($projectRoot . '/scaffold/application-template-inventory.json'),
                'edition_profiles_sha256' => $this->fileDigest($projectRoot . '/scaffold/edition-profiles.json'),
                'release_versions_sha256' => $this->fileDigest($projectRoot . '/release-versions.json'),
            ];
        } elseif (file_exists($applicationManifest) || is_link($applicationManifest)) {
            if (!is_file($applicationManifest) || is_link($applicationManifest)) {
                throw new RuntimeException('INSTALL_RELEASE_IDENTITY_UNAVAILABLE');
            }
            $source = [
                'kind' => 'generated-application',
                'application_manifest_sha256' => $this->fileDigest($applicationManifest),
            ];
        } else {
            throw new RuntimeException('INSTALL_RELEASE_IDENTITY_UNAVAILABLE');
        }

        $migrations = [];
        foreach (glob($this->serverRoot . '/database/migrations/*.sql') ?: [] as $path) {
            $migrations[] = [
                'id' => basename($path, '.sql'),
                'sha256' => $this->fileDigest($path),
            ];
        }
        usort($migrations, static fn(array $left, array $right): int => strcmp($left['id'], $right['id']));

        return [
            'schema_version' => 1,
            'protocol' => 'peanut.installation-baseline.v1',
            'deployment_mode' => $this->deploymentMode(),
            'release' => $versions,
            'source' => $source,
            'database' => [
                'fresh_schema_sha256' => $this->fileDigest($this->serverRoot . '/database/init.sql'),
                'migration_chain' => $migrations,
            ],
            'official_modules' => $moduleKeys,
        ];
    }

    private function fileDigest(string $path): string
    {
        if (!is_file($path) || is_link($path)) {
            throw new RuntimeException('INSTALL_RELEASE_IDENTITY_UNAVAILABLE');
        }
        $digest = hash_file('sha256', $path);
        if (!is_string($digest)) {
            throw new RuntimeException('INSTALL_RELEASE_IDENTITY_UNAVAILABLE');
        }
        return $digest;
    }

    /** @param array<string,mixed> $payload */
    private function writeMarker(string $path, array $payload): void
    {
        $directory = dirname($path);
        if (!is_dir($directory) && !mkdir($directory, 0770, true) && !is_dir($directory)) {
            throw new RuntimeException('Installation state directory is unavailable.');
        }
        $temporary = $path . '.tmp-' . bin2hex(random_bytes(6));
        $json = json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        if (file_put_contents($temporary, $json . "\n", LOCK_EX) === false
            || !chmod($temporary, 0600)
            || !rename($temporary, $path)) {
            @unlink($temporary);
            throw new RuntimeException('Installation state marker cannot be written.');
        }
    }

    private function progressMarker(): string
    {
        return $this->serverRoot . '/private/installation/executing.json';
    }

    private function completionMarker(): string
    {
        return $this->serverRoot . '/private/installation/installed.json';
    }

    private function completionLockPresent(): bool
    {
        $path = $this->completionMarker();
        return file_exists($path) || is_link($path);
    }

    private function completionReceiptValid(): bool
    {
        $path = $this->completionMarker();
        if (!is_file($path) || is_link($path)) {
            return false;
        }
        try {
            $receipt = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            return false;
        }
        if (!is_array($receipt)
            || ($receipt['schema_version'] ?? null) !== 1
            || ($receipt['protocol'] ?? null) !== 'peanut.installation-receipt.v1'
            || ($receipt['state'] ?? null) !== 'installed'
            || !in_array($receipt['deployment_mode'] ?? null, ['standalone', 'multi-tenant'], true)
            || !is_array($receipt['official_modules'] ?? null)
            || !is_array($receipt['release'] ?? null)
            || !is_array($receipt['baseline_manifest'] ?? null)
            || !is_string($receipt['completed_at'] ?? null)
            || trim($receipt['completed_at']) === '') {
            return false;
        }
        foreach ($receipt['official_modules'] as $module) {
            if (!is_string($module) || $module === '') {
                return false;
            }
        }
        foreach (['source_product_version', 'release_sequence_version', 'scaffold_template'] as $name) {
            if (!is_string($receipt['release'][$name] ?? null) || $receipt['release'][$name] === '') {
                return false;
            }
        }
        $baseline = $receipt['baseline_manifest'];
        $baselinePath = $baseline['path'] ?? null;
        $baselineSha256 = $baseline['sha256'] ?? null;
        if (!in_array($baselinePath, [
            'server/runtime/installation/baseline.json',
            'server/private/installation/baseline.json',
        ], true) || !is_string($baselineSha256)
            || preg_match('/^[a-f0-9]{64}$/D', $baselineSha256) !== 1) {
            return false;
        }
        $file = $this->baselineMarker();
        return is_file($file) && !is_link($file)
            && hash_file('sha256', $file) === $baselineSha256;
    }

    private function baselineMarker(): string
    {
        return $this->serverRoot . '/private/installation/baseline.json';
    }

    private function hasLegacyInstallationState(): bool
    {
        $legacy = $this->serverRoot . '/runtime/installation';
        foreach (['executing.json', 'installed.json', 'baseline.json'] as $name) {
            if (file_exists($legacy . '/' . $name) || is_link($legacy . '/' . $name)) {
                return true;
            }
        }
        return false;
    }

    private function installationMigrationComplete(): bool
    {
        $path = $this->serverRoot . '/private/installation/migration.json';
        if (!file_exists($path) && !is_link($path)) {
            return true;
        }
        if (!is_file($path) || is_link($path)) {
            return false;
        }
        try {
            $record = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            return false;
        }
        if (!is_array($record)
            || ($record['protocol'] ?? null) !== 'peanut.installation-state-migration.v1'
            || ($record['phase'] ?? null) !== 'complete'
            || !is_array($record['files'] ?? null)
            || $record['files'] === []) {
            return false;
        }
        foreach ($record['files'] as $name => $identity) {
            if (!in_array($name, ['executing.json', 'installed.json', 'baseline.json'], true)
                || !is_array($identity)
                || !is_string($identity['sha256'] ?? null)
                || !is_int($identity['bytes'] ?? null)) {
                return false;
            }
            $file = dirname($path) . '/' . $name;
            if (!is_file($file) || is_link($file)
                || filesize($file) !== $identity['bytes']
                || hash_file('sha256', $file) !== $identity['sha256']) {
                return false;
            }
        }
        return true;
    }
}
