<?php

declare(strict_types=1);

namespace app\common\services\installation;

use Closure;
use RuntimeException;
use Throwable;

final class InstallationPreflightHost
{
    /** @var list<string> */
    private const REQUIRED_EXTENSIONS = ['curl', 'mbstring', 'pdo_mysql', 'zip'];

    /** @var array<string,string> */
    private const WRITABLE_DIRECTORIES = [
        'runtime' => 'runtime',
        'public-storage' => 'public/storage',
        'private-storage' => 'private/storage',
    ];

    private string $serverRoot;
    private Closure $extensionProbe;
    private Closure $resourceConfigLoader;
    private string $phpVersion;

    public function __construct(
        ?string $serverRoot = null,
        ?Closure $extensionProbe = null,
        ?Closure $resourceConfigLoader = null,
        ?string $phpVersion = null,
    ) {
        $this->serverRoot = rtrim($serverRoot ?? dirname(__DIR__, 4), '/');
        $this->extensionProbe = $extensionProbe
            ?? static fn(string $extension): bool => extension_loaded($extension);
        $this->resourceConfigLoader = $resourceConfigLoader
            ?? fn(): array => $this->loadRegisteredResourceConfig();
        $this->phpVersion = $phpVersion ?? PHP_VERSION;
    }

    /**
     * @return array{
     *   status:string,
     *   code:string,
     *   reason:string,
     *   remediation:string,
     *   checks:list<array{id:string,status:string,code:string,reason:string,remediation:string}>,
     *   resource:array{environment:string,deployment_target:string,resource_id:string,endpoint_id:string,consumer:string}|null
     * }
     */
    public function inspect(): array
    {
        $checks = [
            $this->phpVersionCheck(),
            $this->extensionCheck(),
            $this->installationFilesCheck(),
        ];
        foreach (self::WRITABLE_DIRECTORIES as $id => $relativePath) {
            $checks[] = $this->directoryCheck($id, $relativePath);
        }
        [$resourceCheck, $resource] = $this->resourceCheck();
        $checks[] = $resourceCheck;

        $failed = array_values(array_filter(
            $checks,
            static fn(array $check): bool => $check['status'] === 'failed',
        ));
        if ($failed !== []) {
            return [
                'status' => 'blocked',
                'code' => 'INSTALL_PREFLIGHT_BLOCKED',
                'reason' => sprintf('安装预检有 %d 项未通过', count($failed)),
                'remediation' => '按 checks 中的修复建议处理后重新运行安装预检',
                'checks' => $checks,
                'resource' => $resource,
            ];
        }

        return [
            'status' => 'ready',
            'code' => 'INSTALL_PREFLIGHT_READY',
            'reason' => '安装运行环境和登记资源身份已通过只读预检',
            'remediation' => '可以继续数据库连通、空库和安装锁检查',
            'checks' => $checks,
            'resource' => $resource,
        ];
    }

    /** @return array{id:string,status:string,code:string,reason:string,remediation:string} */
    private function phpVersionCheck(): array
    {
        if (version_compare($this->phpVersion, '8.3.0', '>=')) {
            return $this->passed(
                'php-version',
                'INSTALL_PHP_VERSION_READY',
                'PHP 版本满足 8.3 或更高版本要求',
            );
        }

        return $this->failed(
            'php-version',
            'INSTALL_PHP_VERSION_UNSUPPORTED',
            'PHP 版本低于 8.3',
            '安装 PHP 8.3 或更高版本后重新运行预检',
        );
    }

    /** @return array{id:string,status:string,code:string,reason:string,remediation:string} */
    private function extensionCheck(): array
    {
        $missing = [];
        foreach (self::REQUIRED_EXTENSIONS as $extension) {
            if (!(bool) ($this->extensionProbe)($extension)) {
                $missing[] = $extension;
            }
        }
        if ($missing === []) {
            return $this->passed(
                'php-extensions',
                'INSTALL_PHP_EXTENSIONS_READY',
                '安装所需 PHP 扩展均已加载',
            );
        }

        return $this->failed(
            'php-extensions',
            'INSTALL_PHP_EXTENSIONS_MISSING',
            '缺少 PHP 扩展：' . implode(', ', $missing),
            '安装并启用缺失扩展后重新运行预检',
        );
    }

    /** @return array{id:string,status:string,code:string,reason:string,remediation:string} */
    private function installationFilesCheck(): array
    {
        $required = [
            'Composer autoload' => $this->serverRoot . '/vendor/autoload.php',
            '数据库基线' => $this->serverRoot . '/database/init.sql',
            '品牌配置' => $this->serverRoot . '/config/brand.json',
            '发布身份' => dirname($this->serverRoot) . '/RELEASE_METADATA.json',
            'Plugin lock' => dirname($this->serverRoot) . '/plugins.lock',
            'Plugin schema' => $this->serverRoot . '/resources/schemas/plugin.schema.json',
        ];
        $missing = [];
        foreach ($required as $label => $path) {
            if (!is_file($path) || !is_readable($path)) {
                $missing[] = $label;
            }
        }
        if ($missing === []) {
            return $this->passed(
                'installation-files',
                'INSTALL_FILES_READY',
                '安装器所需文件均存在且可读',
            );
        }

        return $this->failed(
            'installation-files',
            'INSTALL_FILES_MISSING',
            '缺少或无法读取：' . implode(', ', $missing),
            '恢复完整发布制品并安装锁定的 Composer 依赖后重新运行预检',
        );
    }

    /** @return array{id:string,status:string,code:string,reason:string,remediation:string} */
    private function directoryCheck(string $id, string $relativePath): array
    {
        $path = $this->serverRoot . '/' . $relativePath;
        if (is_dir($path) && is_readable($path) && is_writable($path)) {
            return $this->passed(
                'directory.' . $id,
                'INSTALL_DIRECTORY_READY',
                $relativePath . ' 目录存在且可读写',
            );
        }

        return $this->failed(
            'directory.' . $id,
            'INSTALL_DIRECTORY_NOT_WRITABLE',
            $relativePath . ' 目录不存在或不可读写',
            '由部署 owner 创建该目录并授予应用进程最小读写权限',
        );
    }

    /**
     * @return array{
     *   0:array{id:string,status:string,code:string,reason:string,remediation:string},
     *   1:array{environment:string,deployment_target:string,resource_id:string,endpoint_id:string,consumer:string}|null
     * }
     */
    private function resourceCheck(): array
    {
        try {
            $config = ($this->resourceConfigLoader)();
            if (!is_array($config)) {
                throw new RuntimeException('resource config loader must return an array');
            }
            $identity = [];
            foreach (['environment', 'deployment_target', 'resource_id', 'endpoint_id', 'consumer'] as $field) {
                $value = $config[$field] ?? null;
                if (!is_string($value) || trim($value) === '') {
                    throw new RuntimeException('resource identity is incomplete');
                }
                $identity[$field] = $value;
            }

            return [
                $this->passed(
                    'database-resource',
                    'INSTALL_DATABASE_RESOURCE_READY',
                    '数据库环境、资源、consumer 和 endpoint 身份与项目登记一致',
                ),
                $identity,
            ];
        } catch (Throwable) {
            return [
                $this->failed(
                    'database-resource',
                    'INSTALL_DATABASE_RESOURCE_INVALID',
                    '数据库资源身份未通过项目登记校验',
                    '按 resources/project-resources.json 选择环境、资源 ID、consumer 和 endpoint，禁止猜测地址或凭据',
                ),
                null,
            ];
        }
    }

    /** @return array<string,mixed> */
    private function loadRegisteredResourceConfig(): array
    {
        $guard = $this->serverRoot . '/database/environment-guard.php';
        if (!is_file($guard)) {
            throw new RuntimeException('database environment guard is missing');
        }
        require_once $guard;
        if (!function_exists('guardedDatabaseConfig')) {
            throw new RuntimeException('database environment guard is unavailable');
        }
        $hostLeaseProof = getenv('P0E_HOST_LEASE_PROOF');
        return guardedDatabaseConfig(
            $hostLeaseProof === false || trim($hostLeaseProof) === '' ? null : $hostLeaseProof,
        );
    }

    /** @return array{id:string,status:string,code:string,reason:string,remediation:string} */
    private function passed(string $id, string $code, string $reason): array
    {
        return [
            'id' => $id,
            'status' => 'passed',
            'code' => $code,
            'reason' => $reason,
            'remediation' => '',
        ];
    }

    /** @return array{id:string,status:string,code:string,reason:string,remediation:string} */
    private function failed(string $id, string $code, string $reason, string $remediation): array
    {
        return [
            'id' => $id,
            'status' => 'failed',
            'code' => $code,
            'reason' => $reason,
            'remediation' => $remediation,
        ];
    }
}
