<?php
declare(strict_types=1);

namespace app\common\services\installation;

use app\common\exception\installation\InstallationExecutionException;
use app\platform\infrastructure\module\ThinkPhpModuleGovernanceProvider;
use app\platform\services\module\ProductTenantModuleProfileService;
use app\platform\infrastructure\plugin\PluginLockResolver;
use app\platform\infrastructure\plugin\ModuleCatalogApplier;
use app\platform\composition\plugin\ModuleDefinitionRegistryFactory;
use PeanutAdmin\Kernel\Module\CompiledModuleRegistry;
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

    public function __construct(
        private readonly string $serverRoot,
        private readonly ModuleCatalogApplier $catalogs,
    )
    {
        require_once $serverRoot . '/database/install.php';
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
        if (($preflight['status'] ?? null) !== 'ready') {
            return [
                ...$base,
                'state' => 'blocked',
                'code' => 'INSTALL_PREFLIGHT_BLOCKED',
                'retryable' => true,
                'health' => null,
            ];
        }

        try {
            $database = \installationDatabaseState($this->serverRoot);
        } catch (Throwable) {
            return [
                ...$base,
                'state' => 'blocked',
                'code' => 'INSTALL_DATABASE_UNAVAILABLE',
                'retryable' => true,
                'health' => null,
            ];
        }

        $progress = is_file($this->progressMarker());
        $complete = is_file($this->completionMarker());
        if ($progress && !$complete && $database['state'] !== 'uninstalled') {
            return [
                ...$base,
                'state' => 'blocked',
                'code' => 'INSTALL_PARTIAL_STATE_REQUIRES_REBUILD',
                'retryable' => false,
                'health' => null,
            ];
        }
        if ($database['state'] === 'uninstalled') {
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
                'state' => 'installed',
                'code' => 'INSTALL_ALREADY_COMPLETED',
                'retryable' => false,
                'health' => $database['health'],
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
        $expected = (string)Config::get('peanut.installation.setup_token', '');
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
                    (string)$status['code'],
                    $status['retryable']
                        ? '安装前置条件暂未满足。'
                        : '目标包含未完成安装残留，必须由资源 owner 重建后再安装。',
                    409,
                );
            }

            [$credentials, $moduleKeys] = $this->normalizeInput($input);
            $this->writeProgressMarker($moduleKeys);
            try {
                $baseline = \installFreshDatabase($this->serverRoot, $credentials);
                $migration = \migrateDatabase(
                    $this->serverRoot,
                    $this->migrationTargetVersion(),
                );
                $modules = $this->installModules(
                    $moduleKeys,
                    \installationTenantBootstrapContract($this->serverRoot),
                );
                $health = $this->health($moduleKeys);
                $this->writeCompletionMarker($moduleKeys);
                @unlink($this->progressMarker());

                return [
                    'state' => 'installed',
                    'code' => 'INSTALL_COMPLETED',
                    'deployment_mode' => $this->deploymentMode(),
                    'baseline' => $baseline,
                    'migration' => $migration,
                    'modules' => $modules,
                    'health' => $health,
                ];
            } catch (Throwable $exception) {
                try {
                    $database = \installationDatabaseState($this->serverRoot);
                    if ($database['state'] === 'uninstalled') {
                        @unlink($this->progressMarker());
                    }
                } catch (Throwable) {
                }
                throw new InstallationExecutionException(
                    'INSTALL_EXECUTION_FAILED',
                    '安装执行失败；若目标已产生表，请由资源 owner 重建目标后重试。',
                    409,
                    $exception,
                );
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
        sort($modules, SORT_STRING);
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
                    'label' => (string)($manifest->data['name'] ?? substr($key, strlen('official.'))),
                    'description' => (string)($manifest->data['description'] ?? ''),
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
        $lifecycle = (new ThinkPhpModuleGovernanceProvider(
            $this->serverRoot,
            $config,
            $this->catalogs,
        ))->pluginLifecycle();
        $operations = [];
        foreach ($moduleKeys as $moduleKey) {
            $result = $lifecycle->reconcile($moduleKey);
            $operations[] = [
                'key' => $moduleKey,
                'operation' => (string)($result['operation'] ?? ''),
            ];
        }
        $profile = (new ProductTenantModuleProfileService(
            new \PeanutAdmin\Kernel\Module\Persistence\ThinkPhpModuleRuntimeRepository(
                $this->definitionRegistry(),
                true,
            ),
            new ThinkPhpModuleGovernanceProvider($this->serverRoot, $config, $this->catalogs),
            app(\app\common\services\audit\AuditContractHost::class),
        ))->applyInstallationSelection($moduleKeys, $tenantBootstrap['code']);
        return ['operations' => $operations, 'profile' => $profile];
    }

    /** @param list<string> $moduleKeys @return array<string,int> */
    private function health(array $moduleKeys): array
    {
        $pdo = $this->pdo();
        $tenantBootstrap = \installationTenantBootstrapContract($this->serverRoot);
        $health = \assertCurrentDatabase($pdo);
        if ($moduleKeys !== []) {
            $placeholders = implode(',', array_fill(0, count($moduleKeys), '?'));
            $statement = $pdo->prepare(
                "SELECT COUNT(*) FROM pa_module_installation WHERE status='active' AND module_key IN ({$placeholders})"
            );
            $statement->execute($moduleKeys);
            if ((int)$statement->fetchColumn() !== count($moduleKeys)) {
                throw new RuntimeException('Official Module installation is incomplete.');
            }
            $definitions = $this->definitionRegistry();
            $tenantManaged = array_values(array_filter(
                $moduleKeys,
                fn(string $moduleKey): bool => !$definitions->isRequiredTenantFoundation($moduleKey),
            ));
            if ($tenantManaged !== []) {
                $tenantPlaceholders = implode(',', array_fill(0, count($tenantManaged), '?'));
                $statement = $pdo->prepare(
                    "SELECT COUNT(*) FROM pa_tenant_module tm JOIN pa_tenant t ON t.id=tm.tenant_id "
                    . "WHERE t.code=? AND tm.status='enabled' AND tm.module_key IN ({$tenantPlaceholders})"
                );
                $statement->execute([$tenantBootstrap['code'], ...$tenantManaged]);
                if ((int)$statement->fetchColumn() !== count($tenantManaged)) {
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

    private function lockResolver(): PluginLockResolver
    {
        return new PluginLockResolver(
            $this->serverRoot,
            (string)Config::get('modules.plugin_lock', '../plugins.lock'),
        );
    }

    private function definitionRegistry(): CompiledModuleRegistry
    {
        return (new ModuleDefinitionRegistryFactory($this->serverRoot))->fromPluginLock(
            $this->lockResolver(),
            $this->moduleConfig(),
        );
    }

    /** Select Peanut-owned SQL by the adopted scaffold and optional verified overlay identity. */
    private function migrationTargetVersion(): string
    {
        $versions = \applicationReleaseVersions($this->serverRoot);
        return \applicationMigrationTargetVersion($this->serverRoot, $versions);
    }

    private function mode(): string
    {
        $mode = trim((string)Config::get('peanut.installation.mode', 'automatic'));
        if (!in_array($mode, self::MODES, true)) {
            throw new RuntimeException('PEANUT_INSTALLATION_MODE must be guided or automatic.');
        }
        return $mode;
    }

    private function deploymentMode(): string
    {
        $mode = trim((string)Config::get('deployment.mode', ''));
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

    /** @param list<string> $moduleKeys */
    private function writeCompletionMarker(array $moduleKeys): void
    {
        $this->writeMarker($this->completionMarker(), [
            'schema_version' => 1,
            'state' => 'installed',
            'deployment_mode' => $this->deploymentMode(),
            'official_modules' => $moduleKeys,
            'completed_at' => gmdate(DATE_ATOM),
        ]);
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
            || !chmod($temporary, 0660)
            || !rename($temporary, $path)) {
            @unlink($temporary);
            throw new RuntimeException('Installation state marker cannot be written.');
        }
    }

    private function progressMarker(): string
    {
        return $this->serverRoot . '/runtime/installation/executing.json';
    }

    private function completionMarker(): string
    {
        return $this->serverRoot . '/runtime/installation/installed.json';
    }
}
