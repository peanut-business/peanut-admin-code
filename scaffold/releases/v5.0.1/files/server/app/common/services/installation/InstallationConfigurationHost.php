<?php

declare(strict_types=1);

namespace app\common\services\installation;

use app\common\exception\installation\InstallationExecutionException;
use app\common\value\installation\ServerReleaseIdentity;
use Closure;
use RuntimeException;

/**
 * Creates the one protected instance configuration for a fresh server release.
 * It never edits release identity or package-owned resource projections.
 */
final class InstallationConfigurationHost
{
    /** @var array<string,array{app_environment:string,resource_environment:string}> */
    private const TARGETS = [
        'local-production-preview' => [
            'app_environment' => 'production',
            'resource_environment' => 'local-production-preview',
        ],
        'production' => [
            'app_environment' => 'production',
            'resource_environment' => 'production',
        ],
        'production-candidate' => [
            'app_environment' => 'production',
            'resource_environment' => 'production-candidate',
        ],
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

        if (!$configured && (file_exists($this->pendingPath()) || is_link($this->pendingPath()))) {
            if (is_link($this->pendingPath()) || !is_file($this->pendingPath())) {
                return ['state' => 'blocked', 'code' => 'INSTALL_CONFIGURATION_PARTIAL', 'configured' => false,
                    'application' => $this->publicIdentity($identity)];
            }
            return ['state' => 'pending', 'code' => 'INSTALL_CONFIGURATION_PENDING', 'configured' => false,
                'application' => $this->publicIdentity($identity)];
        }

        if (file_exists($installed) || is_link($installed)) {
            return [
                'state' => 'installed',
                'code' => 'INSTALL_CONFIGURATION_LOCKED',
                'configured' => $configured,
                'application' => $this->publicIdentity($identity),
            ];
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
            'deployment_targets' => array_keys(self::TARGETS),
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

        $allowed = ['deployment_target', 'database_name', 'platform_hosts', 'tenant_admin_hosts'];
        if (array_diff(array_keys($input), $allowed) !== []) {
            throw new InstallationExecutionException(
                'INSTALL_CONFIGURATION_INPUT_INVALID',
                '首次配置包含不支持的字段。',
                422,
            );
        }

        $identity = $this->identity();
        $application = $identity['application'];
        $target = is_string($input['deployment_target'] ?? null)
            ? trim($input['deployment_target'])
            : '';
        if (!isset(self::TARGETS[$target])) {
            throw new InstallationExecutionException(
                'INSTALL_CONFIGURATION_TARGET_INVALID',
                '部署目标无效。',
                422,
            );
        }

        $database = is_string($input['database_name'] ?? null)
            ? trim($input['database_name'])
            : '';
        if ($database === '') {
            $database = $this->defaultDatabaseName((string) $application['slug']);
        }
        if (preg_match('/^[A-Za-z0-9_]{1,64}$/D', $database) !== 1) {
            throw new InstallationExecutionException(
                'INSTALL_CONFIGURATION_DATABASE_INVALID',
                '数据库名只能包含字母、数字和下划线，最长 64 位。',
                422,
            );
        }

        $edition = (string) $application['edition'];
        $platformHosts = $this->hostList($input['platform_hosts'] ?? '');
        $tenantAdminHosts = $this->hostList($input['tenant_admin_hosts'] ?? '');
        if ($edition === 'multi-tenant' && ($platformHosts === [] || $tenantAdminHosts === [])) {
            throw new InstallationExecutionException(
                'INSTALL_CONFIGURATION_HOSTS_REQUIRED',
                '多租户部署必须登记 Platform 与 Tenant Admin Host。',
                422,
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
                    'protocol' => 'peanut.installation-configuration-input.v1',
                    'identity_sha256' => hash('sha256', json_encode($identity, JSON_THROW_ON_ERROR)),
                    'setup_token_sha256' => hash('sha256', $token),
                    'input' => ['deployment_target' => $target, 'database_name' => $database,
                        'platform_hosts' => implode(',', $platformHosts), 'tenant_admin_hosts' => implode(',', $tenantAdminHosts)],
                ]);
            } finally {
                flock($lock, LOCK_UN);
                fclose($lock);
            }
            return ['state' => 'pending', 'code' => 'INSTALL_CONFIGURATION_PENDING', 'restart_required' => true,
                'application' => $this->publicIdentity($identity), 'deployment_target' => $target,
                'database_resource_id' => $application['slug'] . '-bundled-mysql84', 'database_name' => $database];
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
        if (file_exists($lockPath) && !(is_file($this->serverRoot . '/.env')
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
            $resourceId = $slug . '-bundled-mysql84';
            $endpointId = $slug . '-mysql84-container';
            $databaseUser = $this->databaseUser($slug);
            $databasePassword = $this->secret();
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
                        'environments' => [self::TARGETS[$target]['resource_environment']],
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

            $environment = [
                'APP_ENV' => self::TARGETS[$target]['app_environment'],
                'APP_DEBUG' => 'false',
                'DEPLOYMENT_MODE' => $edition,
                'PEANUT_DEPLOYMENT_TARGET' => $target,
                'PEANUT_DATABASE_RESOURCE_ID' => $resourceId,
                'PEANUT_DATABASE_ENDPOINT_ID' => $endpointId,
                'PEANUT_DATABASE_CONSUMER' => 'container',
                'DB_DRIVER' => 'mysql',
                'DB_TYPE' => 'mysql',
                'DB_HOST' => 'mysql',
                'DB_PORT' => '3306',
                'DB_NAME' => $database,
                'DB_USER' => $databaseUser,
                'DB_PASS' => $databasePassword,
                'DB_CHARSET' => 'utf8mb4',
                'DB_PREFIX' => 'pa_',
                'PEANUT_INSTALLATION_MODE' => 'guided',
                'PEANUT_DEMO_MODE' => 'disabled',
                'PUBLIC_DEFAULT_TENANT_FALLBACK' => $edition === 'standalone' ? 'true' : 'false',
                'PLATFORM_HOSTS' => implode(',', $platformHosts),
                'TENANT_ADMIN_HOSTS' => implode(',', $tenantAdminHosts),
                'DEFAULT_LANG' => 'zh-cn',
                'PROJECT_VERSION' => (string) $application['version'],
                'ASYNC_WORKER_LIMIT' => '25',
                ...$secrets,
            ];

            $this->writeJsonAtomic($this->registryPath(), $registry);
            $this->writeEnvironmentAtomic($this->serverRoot . '/.env', $environment);
            $record = [
                'schema_version' => 1,
                'protocol' => 'peanut.installation-configuration.v1',
                'state' => 'configured',
                'application' => $this->publicIdentity($identity),
                'deployment_target' => $target,
                'database_resource_id' => $resourceId,
                'database_name' => $database,
                'configured_at' => gmdate(DATE_ATOM),
            ];
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
        $owner = fileowner($this->serverRoot);
        if ($this->deferPublication || PHP_SAPI !== 'cli' || !is_int($owner)
            || !in_array(posix_geteuid(), [0, $owner], true)
            || file_exists($this->serverRoot . '/runtime/upgrade/.traffic-ready')
            || is_link($this->serverRoot . '/runtime/upgrade/.traffic-ready')) {
            throw new RuntimeException('INSTALL_CONFIGURATION_OWNER_REQUIRED');
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
        if (!is_array($pending) || array_keys($pending) !== ['protocol', 'identity_sha256', 'setup_token_sha256', 'input']
            || $pending['protocol'] !== 'peanut.installation-configuration-input.v1'
            || $pending['identity_sha256'] !== hash('sha256', json_encode($this->identity(), JSON_THROW_ON_ERROR))
            || $pending['setup_token_sha256'] !== hash('sha256', $expectedToken)
            || !is_array($pending['input'])) {
            throw new RuntimeException('INSTALL_CONFIGURATION_PENDING_BINDING_INVALID');
        }
        // Reuse the canonical validator and generate credentials only in the owner boundary.
        $result = $this->configure($expectedToken, $expectedToken, $pending['input']);
        if (!unlink($path)) {
            throw new RuntimeException('INSTALL_CONFIGURATION_PENDING_REMOVE_FAILED');
        }
        return $result;
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

        $release = ServerReleaseIdentity::resolve($this->serverRoot);
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
    private function writeEnvironmentAtomic(string $path, array $environment): void
    {
        $lines = [];
        foreach ($environment as $name => $value) {
            if (preg_match('/^[A-Z][A-Z0-9_]*$/D', $name) !== 1
                || preg_match('/[\r\n\x00]/', $value) === 1) {
                throw new RuntimeException('INSTALL_CONFIGURATION_ENV_INVALID');
            }
            $lines[] = $name . '=' . $value;
        }
        $this->writeAtomic($path, implode("\n", $lines) . "\n");
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
