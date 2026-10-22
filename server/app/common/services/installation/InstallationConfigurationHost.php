<?php

declare(strict_types=1);

namespace app\common\services\installation;

use app\common\exception\installation\InstallationExecutionException;
use app\common\value\installation\ApplicationSourceIdentity;
use app\common\value\installation\ServerReleaseIdentity;
use Closure;
use RuntimeException;

/**
 * Creates the one protected instance configuration for a fresh server release.
 * It never edits release identity or package-owned resource projections.
 */
final class InstallationConfigurationHost
{
    /** Native deploymentTargetContract() owns each target's environment. */
    private const TARGETS = [
        'local-development', 'local-production-preview', 'local-multi-tenant-demo',
        'production', 'production-candidate',
    ];

    /** @param Closure(string):array<string,mixed>|null $identityLoader */
    public function __construct(
        private readonly string $serverRoot,
        private readonly ?Closure $identityLoader = null,
        private readonly ?Closure $secretGenerator = null,
        private readonly bool $deferPublication = false,
    ) {}

    /** @return array<string,mixed> */
    public function status(): array
    {
        $identity = $this->identity();
        $env = $this->serverRoot . '/.env';
        $registry = $this->registryPath();
        $record = $this->serverRoot . '/private/resources/configuration.json';
        $lock = $this->serverRoot . '/private/resources/configuration.lock';
        $installed = $this->serverRoot . '/private/installation/installed.json';
        $configured = is_file($env) && !is_link($env)
            && is_file($registry) && !is_link($registry)
            && is_file($record) && !is_link($record);
        if ($configured && !file_exists($installed) && !is_link($installed)
            && ($identity['application']['kind'] ?? null) === 'generated-application') {
            $stat = lstat($record);
            $configuration = json_decode((string) file_get_contents($record), true, 512, JSON_THROW_ON_ERROR);
            $source = $configuration['application_source'] ?? null;
            if ($stat['nlink'] !== 1 || ($stat['mode'] & 0777) !== 0600 || $stat['uid'] !== fileowner($this->serverRoot)
                || !is_array($source) || !is_string($source['manifest_sha256'] ?? null)
                || !hash_equals($source['manifest_sha256'], (string) hash_file('sha256', dirname($this->serverRoot) . '/.peanut/application-manifest.json'))) {
                throw new RuntimeException('INSTALL_CONFIGURATION_SOURCE_BINDING_CHANGED');
            }
        }

        if (file_exists($installed) || is_link($installed)) {
            return [
                'state' => 'installed',
                'code' => 'INSTALL_CONFIGURATION_LOCKED',
                'configured' => $configured,
                'application' => $this->publicIdentity($identity),
            ];
        }
        // This receipt constrains unfinished first configuration. A supported
        // native update retains installed identity without rewriting its history.
        $binding = $this->deploymentBinding($identity);
        if (!$configured && (file_exists($this->pendingPath()) || is_link($this->pendingPath()))) {
            if (is_link($this->pendingPath()) || !is_file($this->pendingPath())) {
                return ['state' => 'blocked', 'code' => 'INSTALL_CONFIGURATION_PARTIAL', 'configured' => false,
                    'application' => $this->publicIdentity($identity)];
            }
            return ['state' => 'pending', 'code' => 'INSTALL_CONFIGURATION_PENDING', 'configured' => false,
                'application' => $this->publicIdentity($identity)];
        }
        if (!$configured && (file_exists($env) || is_link($env)
            || file_exists($registry) || is_link($registry)
            || file_exists($record) || is_link($record)
            || file_exists($lock) || is_link($lock))) {
            return [
                'state' => 'blocked',
                'code' => 'INSTALL_CONFIGURATION_PARTIAL',
                'configured' => false,
                'application' => $this->publicIdentity($identity),
            ];
        }
        return [
            'state' => $configured ? 'configured' : 'unconfigured',
            'code' => $configured ? 'INSTALL_CONFIGURATION_READY' : 'INSTALL_CONFIGURATION_REQUIRED',
            'configured' => $configured,
            'application' => $this->publicIdentity($identity),
            'deployment_targets' => (ApplicationSourceIdentity::hasRuntime($this->serverRoot)
                || in_array($identity['application']['kind'] ?? null, ['internal-candidate', 'generated-application'], true))
                ? ['local-development', 'local-multi-tenant-demo'] : self::TARGETS,
            'database_resources' => $configured ? [] : $this->databaseOptions(),
            'deployment_binding' => $binding === null ? null : [
                'deployment_target' => $binding['target'], 'database_source' => $binding['database_source'],
                'database_resource_id' => $binding['database_resource_id'], 'database_consumer' => 'container',
                'database_name' => $binding['database_name'],
            ],
        ];
    }

    /** @param array<string,mixed> $input @return array<string,mixed> */
    public function configure(string $token, string $expectedToken, array $input): array
    {
        if (preg_match('/^[A-Za-z0-9_-]{32,128}$/D', $expectedToken) !== 1
            || $token === ''
            || !hash_equals($expectedToken, $token)) {
            throw new InstallationExecutionException(
                'INSTALL_SETUP_TOKEN_INVALID',
                'Setup token 无效。',
                403,
            );
        }

        // A guided HTTP token is not the mutable source owner's publication
        // authority. Status may read that identity; this writer may not use it.
        if (($this->identity()['application']['kind'] ?? null) === 'generated-application') {
            throw new RuntimeException('INSTALL_CONFIGURATION_SOURCE_OWNER_REQUIRED');
        }

        return $this->configureInput($token, $input);
    }

    /** The native host owner publishes before starting PHP; no HTTP token is invented. */
    public function configureForOwner(string $setupToken, array $input, ?array $resourceRegistry = null): array
    {
        $this->assertOwnerPublication();
        if (preg_match('/^[A-Za-z0-9_-]{32,128}$/D', $setupToken) !== 1) {
            throw new RuntimeException('INSTALL_CONFIGURATION_SETUP_TOKEN_INVALID');
        }
        if (file_exists($this->pendingPath()) || is_link($this->pendingPath())) {
            throw new RuntimeException('INSTALL_CONFIGURATION_PENDING_REQUIRES_PUBLICATION');
        }
        $registered = ($input['database_source'] ?? 'bundled') === 'registered';
        if ($registered !== ($resourceRegistry !== null)) {
            throw new RuntimeException('INSTALL_CONFIGURATION_OWNER_REGISTRY_REQUIRED');
        }
        $registry = $registered ? $this->ownerDatabaseRegistry($resourceRegistry, $input) : null;
        return $this->configureInput($setupToken, $input, $registry);
    }

    /** Both authenticated callers use the same configuration and publication contract. */
    private function configureInput(string $token, array $input, ?array $ownerRegistry = null, bool $publishingPending = false): array
    {
        require_once $this->serverRoot . '/database/resource-registry.php';

        $allowed = ['deployment_target', 'database_name', 'platform_hosts', 'tenant_admin_hosts',
            'database_source', 'database_resource_id', 'database_consumer', 'database_user', 'database_password'];
        if (array_diff(array_keys($input), $allowed) !== []) {
            throw new InstallationExecutionException(
                'INSTALL_CONFIGURATION_INPUT_INVALID',
                '首次配置包含不支持的字段。',
                422,
            );
        }
        // A finished/private configuration is never a new admission attempt.
        // Keep the later locked recheck for concurrent first writers.
        $this->assertFreshConfigurationState();

        $identity = $this->identity();
        $sourceBinding = null;
        if (($identity['application']['kind'] ?? null) === 'generated-application') {
            if (!class_exists(\PeanutServerUpdatePlan::class, false)) {
                throw new RuntimeException('INSTALL_CONFIGURATION_SOURCE_OWNER_REQUIRED');
            }
            $sourceBinding = \PeanutServerUpdatePlan::sourceConfigurationBinding($this->serverRoot);
        }
        $application = $identity['application'];
        $binding = $this->deploymentBinding($identity, $deploymentSha256);
        $target = is_string($input['deployment_target'] ?? null)
            ? trim($input['deployment_target'])
            : '';
        if (!in_array($target, self::TARGETS, true)) {
            throw new InstallationExecutionException(
                'INSTALL_CONFIGURATION_TARGET_INVALID',
                '部署目标无效。',
                422,
            );
        }
        $targetContract = \deploymentTargetContract($target);
        if ((ApplicationSourceIdentity::hasRuntime($this->serverRoot)
                || in_array($application['kind'] ?? null, ['internal-candidate', 'generated-application'], true))
            && $targetContract['app_environment'] !== 'development') {
            throw new InstallationExecutionException(
                'INSTALL_CONFIGURATION_TARGET_INVALID',
                '内部候选和开发运行布局只能使用开发环境部署目标。',
                422,
            );
        }
        $source = $input['database_source'] ?? 'bundled';
        if (!in_array($source, ['bundled', 'registered'], true)) {
            throw new InstallationExecutionException('INSTALL_CONFIGURATION_DATABASE_SOURCE_INVALID', '数据库来源无效。', 422);
        }
        $registered = $source === 'registered' ? $this->registeredConnection($input, $application, $targetContract, $ownerRegistry) : null;
        if ($source === 'bundled' && array_intersect(array_keys($input),
            ['database_resource_id', 'database_consumer', 'database_user', 'database_password']) !== []) {
            throw new InstallationExecutionException('INSTALL_CONFIGURATION_DATABASE_SOURCE_INVALID', '已有数据库字段不能用于内置数据库。', 422);
        }

        $database = is_string($input['database_name'] ?? null)
            ? trim($input['database_name'])
            : '';
        if ($registered !== null) {
            if ($database !== '' && $database !== $registered['database']) {
                throw new InstallationExecutionException('INSTALL_CONFIGURATION_DATABASE_INVALID', '数据库名必须与所选登记一致。', 422);
            }
            $database = $registered['database'];
        } elseif ($database === '') {
            $database = $this->defaultDatabaseName((string) $application['slug']);
        }
        if (preg_match('/^[A-Za-z0-9_]{1,64}$/D', $database) !== 1) {
            throw new InstallationExecutionException(
                'INSTALL_CONFIGURATION_DATABASE_INVALID',
                '数据库名只能包含字母、数字和下划线，最长 64 位。',
                422,
            );
        }

        $permissionRegistrySha256 = $registered['registry_sha256'] ?? null;
        if ($binding !== null) {
            if ($target !== $binding['target'] || $source !== $binding['database_source'] || $database !== $binding['database_name']) {
                throw new InstallationExecutionException('INSTALL_CONFIGURATION_DEPLOYMENT_BINDING_MISMATCH', '配置必须保留部署已固定的数据库选择。', 422);
            }
            $endpoint = $registered['endpoint'] ?? null;
            if ($source === 'bundled') {
                $permissionRegistry = $this->applicationRegistry($permissionRegistrySha256);
                $permission = \registeredDatabase($permissionRegistry, $binding['database_resource_id']);
                $endpoint = \registeredDatabaseEndpoint($permission, 'container');
                if (($permission['service_type'] ?? null) !== 'mysql' || ($permission['fallback'] ?? null) !== 'none'
                    || ($permission['application_runtime'] ?? null) !== true || ($permission['database'] ?? null) !== $database
                    || ($permission['namespace'] ?? null) !== $binding['root'] . '/server/docker/mysql'
                    || !in_array($targetContract['resource_environment'], $permission['environments'] ?? [], true)
                    || !in_array($application['edition'], $permission['deployment_modes'] ?? [], true)) {
                    throw new InstallationExecutionException('INSTALL_CONFIGURATION_DEPLOYMENT_PERMISSION_INVALID', '内置数据库选择必须具有本APP的登记权限。', 422);
                }
            }
            $expectedEndpoint = $binding['database_endpoint'];
            if (is_array($endpoint)) { ksort($endpoint); }
            ksort($expectedEndpoint);
            if (($registered !== null && ($registered['resource_id'] !== $binding['database_resource_id']
                || $registered['consumer'] !== 'container')) || $endpoint !== $expectedEndpoint) {
                throw new InstallationExecutionException('INSTALL_CONFIGURATION_DEPLOYMENT_BINDING_MISMATCH', '配置必须保留部署已固定的容器端点。', 422);
            }
        }

        $edition = (string) $application['edition'];
        $platformHosts = $this->hostList($input['platform_hosts'] ?? '');
        $tenantAdminHosts = $this->hostList($input['tenant_admin_hosts'] ?? '');
        if ($edition === 'multi-tenant' && ($platformHosts === [] || $tenantAdminHosts === [])) {
            throw new InstallationExecutionException(
                'INSTALL_CONFIGURATION_HOSTS_REQUIRED',
                '多租户部署必须登记 Platform 与 Tenant Admin Host。',
                422,
                diagnostic: [
                    'reason' => '多租户部署缺少 Platform Hosts 或 Tenant Admin Hosts。',
                    'remedy' => '本次 Hosts 校验未写入配置或数据库。补齐已登记的纯主机名后，重新读取配置状态；仅在仍未配置且没有待发布、残留或安装锁时，使用新输入提交。不得清除数据或安装身份。',
                    'writes' => ['outcome' => 'none'],
                    'safe_retry' => true,
                ],
            );
        }

        if ($this->deferPublication) {
            $this->assertFreshConfigurationState();
            if (file_exists($this->pendingPath()) || is_link($this->pendingPath())) {
                throw new InstallationExecutionException('INSTALL_CONFIGURATION_IN_PROGRESS', '已有首次配置等待发布。', 409);
            }
            $directory = dirname($this->pendingPath());
            if (is_link($directory) || !is_dir($directory)) {
                throw new RuntimeException('INSTALL_CONFIGURATION_PENDING_DIRECTORY_INVALID');
            }
            $lockPath = $directory . '/configuration.lock';
            if (is_link($lockPath) || (file_exists($lockPath) && (!is_file($lockPath) || lstat($lockPath)['nlink'] !== 1))) {
                throw new RuntimeException('INSTALL_CONFIGURATION_PENDING_LOCK_INVALID');
            }
            $lock = fopen($lockPath, 'c+');
            if (!is_resource($lock) || !flock($lock, LOCK_EX | LOCK_NB)) {
                throw new RuntimeException('INSTALL_CONFIGURATION_PENDING_BUSY');
            }
            try {
                $this->assertFreshConfigurationState();
                if (file_exists($this->pendingPath()) || is_link($this->pendingPath())) {
                    throw new InstallationExecutionException('INSTALL_CONFIGURATION_IN_PROGRESS', '已有首次配置等待发布。', 409);
                }
                $this->writeJsonAtomic($this->pendingPath(), [
                    'protocol' => 'peanut.installation-configuration-input.v3',
                    'identity_sha256' => hash('sha256', json_encode($identity, JSON_THROW_ON_ERROR)),
                    'setup_token_sha256' => hash('sha256', $token),
                    'registry_sha256' => $permissionRegistrySha256,
                    'deployment_sha256' => $deploymentSha256,
                    'input' => ['deployment_target' => $target, 'database_name' => $database,
                        'platform_hosts' => implode(',', $platformHosts), 'tenant_admin_hosts' => implode(',', $tenantAdminHosts),
                        'database_source' => $source,
                        ...($registered === null ? [] : array_intersect_key($input, array_flip(
                            ['database_resource_id', 'database_consumer', 'database_user', 'database_password'])))],
                ]);
            } finally {
                flock($lock, LOCK_UN);
                fclose($lock);
            }
            return ['state' => 'pending', 'code' => 'INSTALL_CONFIGURATION_PENDING', 'restart_required' => true,
                'application' => $this->publicIdentity($identity), 'deployment_target' => $target,
                'database_resource_id' => $registered['resource_id'] ?? $binding['database_resource_id'] ?? $application['slug'] . '-bundled-mysql84', 'database_name' => $database];
        }

        $stateDirectory = dirname($this->registryPath());
        if ((!is_dir($stateDirectory) && !mkdir($stateDirectory, 0770, true) && !is_dir($stateDirectory))
            || is_link($stateDirectory)) {
            throw new InstallationExecutionException(
                'INSTALL_CONFIGURATION_STATE_UNAVAILABLE',
                '实例资源目录不可用。',
                503,
            );
        }

        $lockPath = $stateDirectory . '/configuration.lock';
        if (is_link($lockPath) || (file_exists($lockPath) && !is_file($lockPath))) {
            throw new InstallationExecutionException(
                'INSTALL_CONFIGURATION_STATE_UNAVAILABLE',
                '首次配置锁不安全。',
                503,
            );
        }
        if (!$publishingPending && file_exists($lockPath) && !(is_file($this->serverRoot . '/.env')
            && is_file($this->registryPath())
            && is_file($stateDirectory . '/configuration.json'))) {
            throw new InstallationExecutionException(
                'INSTALL_CONFIGURATION_PARTIAL',
                '首次配置曾开始但未完成，须先核对实例状态。',
                409,
            );
        }
        $lock = fopen($lockPath, 'c+');
        if ($lock === false || !flock($lock, LOCK_EX | LOCK_NB)) {
            if (is_resource($lock)) {
                fclose($lock);
            }
            throw new InstallationExecutionException(
                'INSTALL_CONFIGURATION_IN_PROGRESS',
                '已有首次配置正在执行。',
                409,
            );
        }
        chmod($lockPath, 0600);

        try {
            $this->assertFreshConfigurationState();

            $slug = (string) $application['slug'];
            $resourceId = $registered['resource_id'] ?? $binding['database_resource_id'] ?? $slug . '-bundled-mysql84';
            $endpointId = $registered['endpoint']['endpoint_id'] ?? $binding['database_endpoint']['endpoint_id'] ?? $slug . '-mysql84-container';
            $databaseUser = $registered['user'] ?? $this->databaseUser($slug);
            $databasePassword = $registered['password'] ?? $this->secret();
            $secrets = [
                'JWT_SECRET' => $this->secret(),
                'TENANT_IDENTIFIER_HMAC_KEY' => $this->secret(),
                'PLATFORM_IDENTIFIER_HMAC_KEY' => $this->secret(),
                'PEANUT_STORAGE_CREDENTIAL_MASTER_KEY' => $this->secret(),
                'ASYNC_SIGNING_KEY' => $this->secret(),
            ];

            $registry = [
                'schema_version' => 1,
                'project_id' => $slug,
                'authority' => [
                    'owner' => ($application['name'] ?? $slug) . ' instance owner',
                    'role' => 'application',
                    'source' => 'server/private/resources/project-resources.json',
                    'credentials_policy' => 'credential references only; secret values remain in server/.env',
                    'resource_observation_policy' => 'validate this instance registry before stateful operations',
                ],
                'resources' => [
                    'tooling' => [],
                    'databases' => [[
                        'stable_resource_id' => $resourceId,
                        'purpose' => 'Bundled application MySQL database',
                        'environments' => [$targetContract['resource_environment']],
                        'owner' => 'application instance owner',
                        'host' => 'mysql',
                        'port' => 3306,
                        'database' => $database,
                        'schema' => 'pa_ table prefix',
                        'namespace' => $slug,
                        'service_type' => 'mysql',
                        'credential_ref' => 'server/.env:DB_USER,DB_PASS',
                        'data_source' => 'persistent server/docker/mysql',
                        'freshness_requirement' => 'same instance and empty database before first installation',
                        'health_check' => 'mysqladmin ping and installation preflight',
                        'fallback' => 'none',
                        'lifecycle' => 'persistent instance data',
                        'application_runtime' => true,
                        'deployment_modes' => [$edition],
                        'container_endpoint' => [
                            'endpoint_id' => $endpointId,
                            'host' => 'mysql',
                            'port' => 3306,
                            'consumers' => ['container'],
                        ],
                    ]],
                    'local_listeners' => [],
                    'containers' => [],
                    'optional_services' => [],
                    'external_services' => [],
                    'backups' => [],
                    'queues' => ['status' => 'unallocated', 'resources' => []],
                    'object_storage' => ['status' => 'unallocated', 'resources' => []],
                ],
            ];

            if ($registered !== null) {
                $registry = $registered['registry'];
                foreach ($registry['resources']['databases'] as &$row) {
                    if ($row['stable_resource_id'] === $resourceId) {
                        $row['credential_ref'] = 'server/.env:DB_USER,DB_PASS';
                    }
                }
                unset($row);
            }

            $environment = [
                'APP_ENV' => $targetContract['app_environment'],
                'APP_DEBUG' => 'false',
                'DEPLOYMENT_MODE' => $edition,
                'PEANUT_DEPLOYMENT_TARGET' => $target,
                'PEANUT_DATABASE_RESOURCE_ID' => $resourceId,
                'PEANUT_DATABASE_ENDPOINT_ID' => $endpointId,
                'PEANUT_DATABASE_CONSUMER' => $registered['consumer'] ?? 'container',
                'PEANUT_DATABASE_SOURCE' => $source,
                'DB_DRIVER' => 'mysql',
                'DB_TYPE' => 'mysql',
                'DB_HOST' => $registered['endpoint']['host'] ?? 'mysql',
                'DB_PORT' => (string) ($registered['endpoint']['port'] ?? 3306),
                'DB_NAME' => $database,
                'DB_USER' => $databaseUser,
                'DB_PASS' => $databasePassword,
                'DB_CHARSET' => 'utf8mb4',
                'DB_PREFIX' => 'pa_',
                'PEANUT_INSTALLATION_MODE' => 'guided',
                'PEANUT_INSTALLATION_SETUP_TOKEN' => $token,
                'PEANUT_DEMO_MODE' => 'disabled',
                'PUBLIC_DEFAULT_TENANT_FALLBACK' => $edition === 'standalone' ? 'true' : 'false',
                'PLATFORM_HOSTS' => implode(',', $platformHosts),
                'TENANT_ADMIN_HOSTS' => implode(',', $tenantAdminHosts),
                'DEFAULT_LANG' => 'zh-cn',
                'PROJECT_VERSION' => (string) $application['version'],
                'ASYNC_WORKER_LIMIT' => '25',
                ...$secrets,
            ];

            // Validate credential serialization before publishing any instance file.
            $environmentBytes = $this->environmentBytes($environment);
            $this->writeJsonAtomic($this->registryPath(), $registry);
            $this->writeAtomic($this->serverRoot . '/.env', $environmentBytes);
            $record = [
                'schema_version' => 1,
                'protocol' => 'peanut.installation-configuration.v1',
                'state' => 'configured',
                'application' => $this->publicIdentity($identity),
                'deployment_target' => $target,
                'database_source' => $source,
                'database_resource_id' => $resourceId,
                'database_name' => $database,
                'configured_at' => gmdate(DATE_ATOM),
            ];
            if ($sourceBinding !== null) { $record['application_source'] = $sourceBinding; }
            $this->writeJsonAtomic($stateDirectory . '/configuration.json', $record);

            return [
                'state' => 'configured',
                'code' => 'INSTALL_CONFIGURATION_COMPLETED',
                'restart_required' => true,
                'application' => $this->publicIdentity($identity),
                'deployment_target' => $target,
                'database_resource_id' => $resourceId,
                'database_name' => $database,
            ];
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    /** The native owner calls this only after revoking traffic and stopping the bootstrap writer. */
    public function publishPending(string $expectedToken): array
    {
        $this->assertOwnerPublication();
        if (preg_match('/^[A-Za-z0-9_-]{32,128}$/D', $expectedToken) !== 1) {
            throw new RuntimeException('INSTALL_CONFIGURATION_SETUP_TOKEN_INVALID');
        }
        foreach (['private', 'private/resources', 'private/resources/pending'] as $relative) {
            if (is_link($this->serverRoot . '/' . $relative) || !is_dir($this->serverRoot . '/' . $relative)) {
                throw new RuntimeException('INSTALL_CONFIGURATION_PENDING_PATH_INVALID');
            }
        }
        $path = $this->pendingPath();
        $stat = @lstat($path);
        if (!is_array($stat) || is_link($path) || !is_file($path) || $stat['nlink'] !== 1
            || ($stat['mode'] & 0777) !== 0600) {
            throw new RuntimeException('INSTALL_CONFIGURATION_PENDING_PATH_INVALID');
        }
        $pending = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($pending) || array_keys($pending) !== ['protocol', 'identity_sha256', 'setup_token_sha256', 'registry_sha256', 'deployment_sha256', 'input']
            || $pending['protocol'] !== 'peanut.installation-configuration-input.v3'
            || $pending['identity_sha256'] !== hash('sha256', json_encode($this->identity(), JSON_THROW_ON_ERROR))
            || $pending['setup_token_sha256'] !== hash('sha256', $expectedToken)
            || !is_array($pending['input'])) {
            throw new RuntimeException('INSTALL_CONFIGURATION_PENDING_BINDING_INVALID');
        }
        $this->deploymentBinding($this->identity(), $deploymentSha256);
        if ($pending['deployment_sha256'] !== $deploymentSha256) {
            throw new RuntimeException('INSTALL_CONFIGURATION_PENDING_DEPLOYMENT_CHANGED');
        }
        if ($pending['registry_sha256'] !== null) {
            $this->applicationRegistry($registrySha256);
            if (!hash_equals($pending['registry_sha256'], $registrySha256)) {
                throw new RuntimeException('INSTALL_CONFIGURATION_PENDING_REGISTRY_CHANGED');
            }
        }
        // Reuse the canonical validator and generate credentials only in the owner boundary.
        $result = $this->configureInput($expectedToken, $pending['input'], publishingPending: true);
        if (!unlink($path)) {
            throw new RuntimeException('INSTALL_CONFIGURATION_PENDING_REMOVE_FAILED');
        }
        return $result;
    }

    private function assertOwnerPublication(): void
    {
        $owner = fileowner($this->serverRoot);
        if ($this->deferPublication || PHP_SAPI !== 'cli' || !is_int($owner)
            || !in_array(posix_geteuid(), [0, $owner], true)
            || file_exists($this->serverRoot . '/runtime/upgrade/.traffic-ready')
            || is_link($this->serverRoot . '/runtime/upgrade/.traffic-ready')) {
            throw new RuntimeException('INSTALL_CONFIGURATION_OWNER_REQUIRED');
        }
    }

    /** Deployment-owned nonsecret selection on the existing readonly HTTP program mount. */
    private function deploymentBinding(array $identity, ?string &$sha256 = null): ?array
    {
        $sha256 = null;
        $path = $this->serverRoot . '/private/mounted-deployment-receipt.json';
        if (!file_exists($path) && !is_link($path)) { return null; }
        $stat = @lstat($path);
        if (!is_array($stat) || !is_file($path) || is_link($path) || realpath($path) !== $path
            || $stat['nlink'] !== 1 || ($stat['mode'] & 0777) !== 0444 || $stat['uid'] !== fileowner($this->serverRoot)) {
            throw new RuntimeException('INSTALL_CONFIGURATION_DEPLOYMENT_BINDING_UNSAFE');
        }
        $bytes = file_get_contents($path);
        $plan = json_decode((string) $bytes, true, 512, JSON_THROW_ON_ERROR);
        $application = $plan['application'] ?? null;
        $expected = $identity['application'];
        if (!is_array($application)) { throw new RuntimeException('INSTALL_CONFIGURATION_DEPLOYMENT_BINDING_INVALID'); }
        ksort($application); ksort($expected);
        $endpoint = $plan['database_endpoint'] ?? null;
        if (($plan['protocol'] ?? null) !== 'peanut.mounted-deployment-plan.v1' || $application !== $expected
            || !is_string($plan['root'] ?? null) || preg_match('~^/(?:[A-Za-z0-9_-][A-Za-z0-9._-]*/)*[A-Za-z0-9_-][A-Za-z0-9._-]*$~D', $plan['root']) !== 1
            || !in_array($plan['target'] ?? null, self::TARGETS, true)
            || !in_array($plan['database_source'] ?? null, ['bundled', 'registered'], true)
            || !is_string($plan['database_resource_id'] ?? null)
            || preg_match('/^[A-Za-z0-9][A-Za-z0-9_.:-]{0,127}$/D', $plan['database_resource_id']) !== 1
            || !is_string($plan['database_name'] ?? null) || preg_match('/^[A-Za-z0-9_]{1,64}$/D', $plan['database_name']) !== 1
            || !is_array($endpoint) || ($endpoint['consumers'] ?? null) !== ['container']
            || !is_string($endpoint['endpoint_id'] ?? null) || preg_match('/^[A-Za-z0-9][A-Za-z0-9_.:-]{0,127}$/D', $endpoint['endpoint_id']) !== 1
            || !is_string($endpoint['host'] ?? null) || preg_match('/^[A-Za-z0-9._:-]{1,253}$/D', $endpoint['host']) !== 1
            || !is_int($endpoint['port'] ?? null) || $endpoint['port'] < 1 || $endpoint['port'] > 65535
            || ($plan['database_source'] === 'bundled' && ($endpoint['host'] !== 'mysql' || $endpoint['port'] !== 3306))) {
            throw new RuntimeException('INSTALL_CONFIGURATION_DEPLOYMENT_BINDING_INVALID');
        }
        $sha256 = hash('sha256', (string) $bytes);
        return $plan;
    }

    /** Read only the APP's own registry through the same native runtime selector. */
    private function applicationRegistry(?string &$sha256 = null): array
    {
        require_once $this->serverRoot . '/database/resource-registry.php';
        $external = getenv('PEANUT_RESOURCE_REGISTRY');
        if ($external !== false && trim($external) !== '') {
            throw new RuntimeException('INSTALL_CONFIGURATION_APP_REGISTRY_REQUIRED');
        }
        $registry = \projectResourceRegistry($sha256);
        if (($registry['authority']['role'] ?? null) !== 'application'
            || $registry['project_id'] !== $this->identity()['application']['slug']) {
            throw new RuntimeException('INSTALL_CONFIGURATION_APP_REGISTRY_REQUIRED');
        }
        return $registry;
    }

    /** Public choices contain registered topology, never credentials or permission claims. */
    private function databaseOptions(): array
    {
        $options = [];
        foreach ($this->applicationRegistry()['resources']['databases'] as $database) {
            if (($database['service_type'] ?? null) !== 'mysql' || ($database['application_runtime'] ?? null) !== true) {
                continue;
            }
            $endpoints = [];
            foreach (['host', 'container'] as $consumer) {
                $key = $consumer === 'host' ? 'upstream_endpoint' : 'container_endpoint';
                if (isset($database[$key])) {
                    $endpoint = \registeredDatabaseEndpoint($database, $consumer);
                    $endpoints[] = ['consumer' => $consumer, 'host' => $endpoint['host'], 'port' => $endpoint['port']];
                }
            }
            $options[] = ['resource_id' => $database['stable_resource_id'], 'database_name' => $database['database'],
                'environments' => $database['environments'], 'endpoints' => $endpoints];
        }
        return $options;
    }

    /** Resolve the exact registered database; publication never opens or provisions it. */
    private function registeredConnection(array $input, array $application, array $target, ?array $ownerRegistry = null): array
    {
        foreach (['database_resource_id', 'database_consumer', 'database_user', 'database_password'] as $key) {
            if (!is_string($input[$key] ?? null) || $input[$key] === '' || preg_match('/[\r\n\x00]/', $input[$key])) {
                throw new InstallationExecutionException('INSTALL_CONFIGURATION_DATABASE_INVALID', '已有数据库的登记选择和账号凭据必须完整。', 422);
            }
        }
        if (!in_array($input['database_consumer'], ['host', 'container'], true)
            || preg_match('/^[A-Za-z0-9_.@-]{1,32}$/D', $input['database_user']) !== 1
            || strcasecmp($input['database_user'], 'root') === 0 || strlen($input['database_password']) > 4096) {
            throw new InstallationExecutionException('INSTALL_CONFIGURATION_DATABASE_INVALID', '请使用登记端点和已有普通数据库账号。', 422);
        }
        // Reject an unrepresentable credential before even staging pending input.
        $this->environmentBytes(['DB_USER' => $input['database_user'], 'DB_PASS' => $input['database_password']]);
        // Only configureForOwner can supply an owner declaration. HTTP keeps the sealed public choices.
        if ($ownerRegistry === null) {
            $registry = $this->applicationRegistry($registrySha256);
        } else {
            $registry = $ownerRegistry;
            $registrySha256 = hash('sha256', json_encode($registry, JSON_THROW_ON_ERROR));
        }
        $database = \registeredDatabase($registry, $input['database_resource_id']);
        $endpoint = \registeredDatabaseEndpoint($database, $input['database_consumer']);
        if (($database['service_type'] ?? null) !== 'mysql' || ($database['fallback'] ?? null) !== 'none'
            || ($database['application_runtime'] ?? null) !== true
            || !in_array($target['resource_environment'], $database['environments'] ?? [], true)
            || !in_array($application['edition'], $database['deployment_modes'] ?? [], true)
            || preg_match('/^[A-Za-z0-9_]{1,64}$/D', $database['database'] ?? '') !== 1
            || preg_match('/^[A-Za-z0-9._:-]{1,253}$/D', $endpoint['host']) !== 1
            || !is_int($endpoint['port']) || $endpoint['port'] < 1 || $endpoint['port'] > 65535) {
            throw new InstallationExecutionException('INSTALL_CONFIGURATION_DATABASE_INVALID', '所选数据库与本实例的环境、版本形态或端点不匹配。', 422);
        }
        return ['registry' => $registry, 'registry_sha256' => $registrySha256,
            'resource_id' => $database['stable_resource_id'], 'database' => $database['database'], 'endpoint' => $endpoint,
            'consumer' => $input['database_consumer'], 'user' => $input['database_user'], 'password' => $input['database_password']];
    }

    /** A deployment declaration, never a second registry selector or an external Project path. */
    private function ownerDatabaseRegistry(array $registry, array $input): array
    {
        require_once $this->serverRoot . '/database/resource-registry.php';
        $external = getenv('PEANUT_RESOURCE_REGISTRY');
        if (($external !== false && trim($external) !== '')
            || count($registry) !== 4 || ($registry['schema_version'] ?? null) !== 1
            || ($registry['project_id'] ?? null) !== $this->identity()['application']['slug']
            || ($registry['authority'] ?? null) !== ['role' => 'application']
            || !is_array($registry['resources'] ?? null) || array_keys($registry['resources']) !== ['databases']
            || !is_array($registry['resources']['databases']) || !array_is_list($registry['resources']['databases'])
            || count($registry['resources']['databases']) !== 1 || !is_array($registry['resources']['databases'][0])
            || !is_string($input['database_resource_id'] ?? null)) {
            throw new RuntimeException('INSTALL_CONFIGURATION_OWNER_REGISTRY_INVALID');
        }
        $database = \registeredDatabase($registry, $input['database_resource_id']);
        $text = ['stable_resource_id', 'purpose', 'owner', 'version', 'database', 'schema', 'namespace',
            'data_source', 'freshness_requirement', 'health_check'];
        $fields = [...$text, 'service_type', 'fallback', 'application_runtime', 'environments', 'deployment_modes',
            'upstream_endpoint', 'container_endpoint'];
        if (array_diff(array_keys($database), $fields) !== []) {
            throw new RuntimeException('INSTALL_CONFIGURATION_OWNER_DATABASE_INVALID');
        }
        foreach ($text as $field) {
            if (!is_string($database[$field] ?? null) || trim($database[$field]) === ''
                || strlen($database[$field]) > 1024 || preg_match('/[\r\n\x00]/', $database[$field])) {
                throw new RuntimeException('INSTALL_CONFIGURATION_OWNER_DATABASE_INVALID');
            }
        }
        if (preg_match('/^[A-Za-z0-9][A-Za-z0-9_.:-]{0,127}$/D', $database['stable_resource_id']) !== 1
            || ($database['service_type'] ?? null) !== 'mysql' || ($database['fallback'] ?? null) !== 'none'
            || ($database['application_runtime'] ?? null) !== true) {
            throw new RuntimeException('INSTALL_CONFIGURATION_OWNER_DATABASE_INVALID');
        }
        foreach (['environments', 'deployment_modes'] as $field) {
            if (!is_array($database[$field] ?? null) || !array_is_list($database[$field]) || $database[$field] === []) {
                throw new RuntimeException('INSTALL_CONFIGURATION_OWNER_DATABASE_INVALID');
            }
            foreach ($database[$field] as $value) {
                if (!is_string($value) || preg_match('/^[a-z][a-z0-9-]{0,63}$/D', $value) !== 1) {
                    throw new RuntimeException('INSTALL_CONFIGURATION_OWNER_DATABASE_INVALID');
                }
            }
        }
        $endpoints = 0;
        foreach (['host' => 'upstream_endpoint', 'container' => 'container_endpoint'] as $consumer => $field) {
            if (!array_key_exists($field, $database)) { continue; }
            $endpoint = \registeredDatabaseEndpoint($database, $consumer);
            if (count($endpoint) !== 4 || array_diff(array_keys($endpoint), ['endpoint_id', 'host', 'port', 'consumers']) !== []
                || !is_string($endpoint['endpoint_id']) || preg_match('/^[A-Za-z0-9][A-Za-z0-9_.:-]{0,127}$/D', $endpoint['endpoint_id']) !== 1
                || !is_string($endpoint['host']) || preg_match('/^[A-Za-z0-9._:-]{1,253}$/D', $endpoint['host']) !== 1
                || !is_int($endpoint['port']) || $endpoint['port'] < 1 || $endpoint['port'] > 65535
                || $endpoint['consumers'] !== [$consumer]) {
                throw new RuntimeException('INSTALL_CONFIGURATION_OWNER_DATABASE_ENDPOINT_INVALID');
            }
            ++$endpoints;
        }
        if ($endpoints === 0) { throw new RuntimeException('INSTALL_CONFIGURATION_OWNER_DATABASE_ENDPOINT_INVALID'); }
        // The private instance record contains this one APP resource and local credential references only.
        $database['credential_ref'] = 'server/.env:DB_USER,DB_PASS';
        return ['schema_version' => 1, 'project_id' => $registry['project_id'],
            'authority' => ['role' => 'application', 'owner' => 'application instance owner',
                'source' => 'owner first configuration', 'credentials_policy' => 'references only; secrets remain in server/.env'],
            'resources' => ['tooling' => [], 'databases' => [$database], 'local_listeners' => [], 'containers' => [],
                'optional_services' => [], 'external_services' => [], 'backups' => [],
                'queues' => ['status' => 'unallocated', 'resources' => []],
                'object_storage' => ['status' => 'unallocated', 'resources' => []]]];
    }

    private function pendingPath(): string
    {
        return $this->serverRoot . '/private/resources/pending/configuration.json';
    }

    private function assertFreshConfigurationState(): void
    {
        foreach ([
            $this->serverRoot . '/.env',
            $this->registryPath(),
            $this->serverRoot . '/private/resources/configuration.json',
            $this->serverRoot . '/private/installation/installed.json',
            $this->serverRoot . '/private/installation/executing.json',
            $this->serverRoot . '/private/installation/baseline.json',
            $this->serverRoot . '/runtime/installation/installed.json',
            $this->serverRoot . '/runtime/installation/executing.json',
        ] as $path) {
            if (file_exists($path) || is_link($path)) {
                throw new InstallationExecutionException(
                    'INSTALL_CONFIGURATION_ALREADY_PRESENT',
                    '实例已存在配置或安装身份，拒绝重复首次配置。',
                    409,
                );
            }
        }
    }

    /** @return array<string,mixed> */
    private function identity(): array
    {
        if ($this->identityLoader !== null) {
            $identity = ($this->identityLoader)($this->serverRoot);
            if (!is_array($identity)) {
                throw new RuntimeException('INSTALL_RELEASE_IDENTITY_UNAVAILABLE');
            }
            return $identity;
        }

        $releasePath = $this->serverRoot . '/.peanut/release-identity.json';
        $sourcePath = dirname($this->serverRoot) . '/.peanut/application-manifest.json';
        if (!ApplicationSourceIdentity::hasRuntime($this->serverRoot)
            && !file_exists($releasePath) && !is_link($releasePath)
            && (file_exists($sourcePath) || is_link($sourcePath))) {
            // The installer already consumes this native generated APP identity.
            // Configuration/status must not require a fabricated runtime first.
            $source = ApplicationSourceIdentity::loadGeneratedApp($this->serverRoot);
            return ['application' => $source->applicationIdentity() + ['kind' => 'generated-application'],
                'versions' => $source->versions()];
        }
        $release = ApplicationSourceIdentity::hasRuntime($this->serverRoot)
            ? ApplicationSourceIdentity::load($this->serverRoot)
            : ServerReleaseIdentity::resolve($this->serverRoot);
        return [
            'application' => $release->applicationIdentity(),
            'versions' => $release->versions(),
        ];
    }

    /** @param array<string,mixed> $identity @return array<string,string> */
    private function publicIdentity(array $identity): array
    {
        $application = $identity['application'] ?? null;
        if (!is_array($application)) {
            throw new RuntimeException('INSTALL_RELEASE_IDENTITY_UNAVAILABLE');
        }
        $result = [];
        foreach (['slug', 'edition', 'version', 'package_identity', 'name'] as $field) {
            $value = $application[$field] ?? null;
            if (!is_string($value) || $value === '') {
                throw new RuntimeException('INSTALL_RELEASE_IDENTITY_UNAVAILABLE');
            }
            $result[$field] = $value;
        }
        return $result;
    }

    /** @return list<string> */
    private function hostList(mixed $value): array
    {
        if ($value === null || $value === '') {
            return [];
        }
        if (!is_string($value)) {
            throw new InstallationExecutionException(
                'INSTALL_CONFIGURATION_HOSTS_INVALID',
                'Host 列表格式无效。',
                422,
            );
        }
        $hosts = array_values(array_unique(array_filter(array_map('trim', explode(',', strtolower($value))))));
        foreach ($hosts as $host) {
            if (strlen($host) > 253
                || preg_match('/^(?:localhost|[a-z0-9](?:[a-z0-9.-]*[a-z0-9])?)$/D', $host) !== 1
                || str_contains($host, '..')) {
                throw new InstallationExecutionException(
                    'INSTALL_CONFIGURATION_HOSTS_INVALID',
                    'Host 列表包含无效值。',
                    422,
                );
            }
        }
        return $hosts;
    }

    private function defaultDatabaseName(string $slug): string
    {
        $value = strtolower(preg_replace('/[^A-Za-z0-9]+/', '_', $slug) ?? '');
        $value = trim($value, '_');
        $value = $value === '' ? 'peanut_app' : $value . '_app';
        return substr($value, 0, 64);
    }

    private function databaseUser(string $slug): string
    {
        $value = strtolower(preg_replace('/[^A-Za-z0-9]+/', '_', $slug) ?? '');
        $value = trim($value, '_');
        $value = $value === '' ? 'peanut' : $value;
        return substr($value . '_app_' . substr(hash('sha256', $slug), 0, 8), 0, 32);
    }

    private function secret(): string
    {
        if ($this->secretGenerator !== null) {
            $secret = ($this->secretGenerator)();
            if (!is_string($secret) || preg_match('/^[a-f0-9]{64}$/D', $secret) !== 1) {
                throw new RuntimeException('INSTALL_CONFIGURATION_SECRET_INVALID');
            }
            return $secret;
        }
        return bin2hex(random_bytes(32));
    }

    private function registryPath(): string
    {
        return $this->serverRoot . '/private/resources/project-resources.json';
    }

    /** @param array<string,mixed> $value */
    private function writeJsonAtomic(string $path, array $value): void
    {
        $directory = dirname($path);
        if ((!is_dir($directory) && !mkdir($directory, 0770, true) && !is_dir($directory))
            || is_link($directory)) {
            throw new RuntimeException('INSTALL_CONFIGURATION_STATE_UNAVAILABLE');
        }
        $bytes = json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . "\n";
        $this->writeAtomic($path, $bytes);
    }

    /** @param array<string,string> $environment */
    private function environmentBytes(array $environment): string
    {
        $lines = [];
        foreach ($environment as $name => $value) {
            if (preg_match('/^[A-Z][A-Z0-9_]*$/D', $name) !== 1
                || preg_match('/[\r\n\x00]/', $value) === 1) {
                throw new RuntimeException('INSTALL_CONFIGURATION_ENV_INVALID');
            }
            $line = $name . '=' . $value;
            if ((@parse_ini_string($line, false, INI_SCANNER_RAW)[$name] ?? null) !== $value) {
                $line = $name . '="' . str_replace(['\\', '"'], ['\\\\', '\\"'], $value) . '"';
                if ((@parse_ini_string($line, false, INI_SCANNER_RAW)[$name] ?? null) !== $value) {
                    throw new RuntimeException('INSTALL_CONFIGURATION_ENV_ENCODING_INVALID');
                }
            }
            $lines[] = $line;
        }
        return implode("\n", $lines) . "\n";
    }

    private function writeAtomic(string $path, string $bytes): void
    {
        if (file_exists($path) || is_link($path)) {
            throw new RuntimeException('INSTALL_CONFIGURATION_TARGET_EXISTS');
        }
        $temporary = dirname($path) . '/.' . basename($path) . '.tmp-' . bin2hex(random_bytes(8));
        $previousUmask = umask(0077);
        try {
            $written = file_put_contents($temporary, $bytes, LOCK_EX);
        } finally {
            umask($previousUmask);
        }
        if ($written !== strlen($bytes)
            || !chmod($temporary, 0600)
            || !rename($temporary, $path)) {
            @unlink($temporary);
            throw new RuntimeException('INSTALL_CONFIGURATION_WRITE_FAILED');
        }
    }
}
