<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/bootstrap/environment.php';

/** Creates one temporary server/.env.<run-id> for an isolated PHP test process. */
final class IsolatedBackendEnvironment
{
    /** @var list<string> */
    private static array $paths = [];
    private static bool $cleanupRegistered = false;

    public static function activateDatabase(
        string $host,
        int|string $port,
        string $database,
        string $user,
        string $password,
        string $deploymentMode,
        string $prefix = 'pa_',
    ): string {
        if (!in_array($deploymentMode, ['standalone', 'multi-tenant'], true)) {
            throw new RuntimeException('ISOLATED_BACKEND_DEPLOYMENT_MODE_INVALID');
        }
        return self::activate([
            'DB_HOST' => $host,
            'DB_PORT' => $port,
            'DB_NAME' => $database,
            'DB_USER' => $user,
            'DB_PASS' => $password,
            'DB_PREFIX' => $prefix,
            'DEPLOYMENT_MODE' => $deploymentMode,
        ]);
    }

    /** @param array<string,string|int|bool> $values */
    public static function activate(array $values): string
    {
        $serverRoot = dirname(__DIR__, 2);
        $basePath = peanutBackendEnvironmentPath();
        $baseValues = parse_ini_file($basePath, false, INI_SCANNER_RAW);
        if (!is_array($baseValues)) {
            throw new RuntimeException('ISOLATED_BACKEND_ENVIRONMENT_BASE_INVALID');
        }
        $runId = 'test-' . bin2hex(random_bytes(8));
        $path = $serverRoot . '/.env.' . $runId;
        $values = array_replace($baseValues, [
            'APP_ENV' => 'development',
            'APP_DEBUG' => 'true',
            'DEPLOYMENT_MODE' => 'standalone',
            'DB_PREFIX' => 'pa_',
        ], $values);

        $lines = [];
        foreach ($values as $key => $value) {
            if (preg_match('/^[A-Z][A-Z0-9_]*$/D', $key) !== 1) {
                throw new RuntimeException('ISOLATED_BACKEND_ENVIRONMENT_KEY_INVALID');
            }
            $value = is_bool($value) ? ($value ? 'true' : 'false') : (string)$value;
            if (str_contains($value, "\0") || str_contains($value, "\n") || str_contains($value, "\r")) {
                throw new RuntimeException("ISOLATED_BACKEND_ENVIRONMENT_VALUE_INVALID:{$key}");
            }
            $lines[] = $key . '="' . str_replace(['\\', '"'], ['\\\\', '\\"'], $value) . '"';
        }
        $contents = implode("\n", $lines) . "\n";
        $handle = fopen($path, 'x');
        if ($handle === false) {
            throw new RuntimeException('ISOLATED_BACKEND_ENVIRONMENT_CREATE_FAILED');
        }
        try {
            if (!chmod($path, 0600) || fwrite($handle, $contents) !== strlen($contents)) {
                throw new RuntimeException('ISOLATED_BACKEND_ENVIRONMENT_WRITE_FAILED');
            }
        } finally {
            fclose($handle);
        }

        self::$paths[] = $path;
        self::registerCleanup();
        putenv('PEANUT_SERVER_ENV_FILE=' . $path);
        $_ENV['PEANUT_SERVER_ENV_FILE'] = $path;
        $_SERVER['PEANUT_SERVER_ENV_FILE'] = $path;

        $managedKeys = array_keys($values);
        if (function_exists('peanutBackendEnvironmentKeys')) {
            $managedKeys = array_values(array_unique([...$managedKeys, ...peanutBackendEnvironmentKeys()]));
        }
        foreach ($managedKeys as $key) {
            putenv($key);
            putenv('PHP_' . $key);
            unset($_ENV[$key], $_ENV['PHP_' . $key], $_SERVER[$key], $_SERVER['PHP_' . $key]);
        }

        // 生产 bootstrap 每个进程只加载一次；测试切换到刚写入的独立文件时，
        // 复用同一键名／值校验器重新应用配置，不能清空后再调用其一次性入口。
        $isolatedValues = parse_ini_file($path, false, INI_SCANNER_RAW);
        if (!is_array($isolatedValues)) {
            throw new RuntimeException('ISOLATED_BACKEND_ENVIRONMENT_PARSE_FAILED');
        }
        peanutApplyEnvironmentFile($isolatedValues, peanutBackendEnvironmentKeys(), 'BACKEND');
        $_ENV['ENV_NAME'] = 'test-' . substr(basename($path), strlen('.env.test-'));
        $_SERVER['ENV_NAME'] = $_ENV['ENV_NAME'];

        return $path;
    }

    public static function required(string $key): string
    {
        if (preg_match('/^[A-Z][A-Z0-9_]*$/D', $key) !== 1) {
            throw new RuntimeException('ISOLATED_BACKEND_ENVIRONMENT_KEY_INVALID');
        }
        $value = getenv($key);
        if ($value === false || trim($value) === '') {
            throw new RuntimeException("ISOLATED_BACKEND_ENVIRONMENT_REQUIRED:{$key}");
        }
        return $value;
    }

    /** @return array<string,mixed> */
    public static function requireRegisteredDatabase(string $registryPath, string $stableResourceId): array
    {
        if (!is_file($registryPath) || is_link($registryPath)) {
            throw new RuntimeException('ISOLATED_BACKEND_RESOURCE_REGISTRY_INVALID');
        }
        $registry = json_decode((string)file_get_contents($registryPath), true, 512, JSON_THROW_ON_ERROR);
        $databases = $registry['resources']['databases'] ?? null;
        if (!is_array($databases)) {
            throw new RuntimeException('ISOLATED_BACKEND_DATABASE_REGISTRY_INVALID');
        }
        $matches = array_values(array_filter(
            $databases,
            static fn (mixed $resource): bool => is_array($resource)
                && ($resource['stable_resource_id'] ?? null) === $stableResourceId,
        ));
        if (count($matches) !== 1) {
            throw new RuntimeException('ISOLATED_BACKEND_DATABASE_RESOURCE_INVALID');
        }
        $resource = $matches[0];
        $host = $resource['host'] ?? null;
        $port = $resource['port'] ?? null;
        $database = $resource['database'] ?? null;
        if (!is_string($host) || $host === ''
            || !is_int($port) || $port < 1 || $port > 65535
            || !is_string($database) || preg_match('/^[a-z0-9_]+$/D', $database) !== 1) {
            throw new RuntimeException('ISOLATED_BACKEND_DATABASE_RESOURCE_ENDPOINT_INVALID');
        }
        if (!hash_equals($stableResourceId, self::required('PEANUT_DATABASE_RESOURCE_ID'))
            || !hash_equals($host, self::required('DB_HOST'))
            || (int)self::required('DB_PORT') !== $port
            || !hash_equals($database, self::required('DB_NAME'))) {
            throw new RuntimeException('ISOLATED_BACKEND_DATABASE_RESOURCE_MISMATCH');
        }
        return $resource;
    }

    public static function cleanup(): void
    {
        foreach (array_reverse(self::$paths) as $path) {
            $serverRoot = dirname(__DIR__, 2);
            if (dirname($path) === $serverRoot
                && preg_match('/^\.env\.test-[a-f0-9]{16}$/D', basename($path)) === 1
                && is_file($path)
                && !is_link($path)) {
                unlink($path);
            }
        }
        self::$paths = [];
        putenv('PEANUT_SERVER_ENV_FILE');
        unset($_ENV['PEANUT_SERVER_ENV_FILE'], $_SERVER['PEANUT_SERVER_ENV_FILE']);
    }

    private static function registerCleanup(): void
    {
        if (self::$cleanupRegistered) {
            return;
        }
        self::$cleanupRegistered = true;
        register_shutdown_function([self::class, 'cleanup']);
    }
}
