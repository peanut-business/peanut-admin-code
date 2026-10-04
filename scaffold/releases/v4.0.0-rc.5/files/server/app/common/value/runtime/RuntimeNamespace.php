<?php

declare(strict_types=1);

namespace app\common\value\runtime;

use think\facade\Db;
use think\facade\Config;

/** Stable, non-secret namespace shared by every replica of one application instance. */
final readonly class RuntimeNamespace
{
    private const PROJECT_ID = 'peanut-admin';
    private const ENVIRONMENT_PATTERN = '/^[a-z][a-z0-9._-]{1,31}$/D';
    private const RESOURCE_PATTERN = '/^[a-z0-9][a-z0-9._-]{2,127}$/D';

    private function __construct(
        private string $environment,
        private string $resourceId,
    ) {}

    public static function fromConfiguration(): self
    {
        return self::fromResourceId(
            (string) Config::get('database.resource_id', ''),
            (string) Config::get('peanut.environment', ''),
        );
    }

    /** Config files call this before ThinkPHP's configuration repository is available. */
    public static function fromEnvironment(): self
    {
        return self::fromResourceId(
            (string) (getenv('PEANUT_DATABASE_RESOURCE_ID') ?: ''),
            (string) (getenv('APP_ENV') ?: ''),
        );
    }

    public static function fromResourceId(string $resourceId, string $environment): self
    {
        $environment = strtolower(trim($environment));
        $resourceId = strtolower(trim($resourceId));
        if (preg_match(self::ENVIRONMENT_PATTERN, $environment) !== 1
            || preg_match(self::RESOURCE_PATTERN, $resourceId) !== 1) {
            throw new \RuntimeException('RUNTIME_NAMESPACE_RESOURCE_ID_INVALID');
        }
        return new self($environment, $resourceId);
    }

    public function fingerprint(): string
    {
        return substr(hash('sha256', $this->resourceId), 0, 24);
    }

    public function cachePrefix(): string
    {
        return 'pa:i:' . $this->fingerprint() . ':';
    }

    public function cacheTagPrefix(): string
    {
        return $this->cachePrefix() . 'tag:';
    }

    public function sessionName(): string
    {
        return 'PA_SESSION_' . strtoupper(substr($this->fingerprint(), 0, 16));
    }

    public function sessionPrefix(): string
    {
        return $this->cachePrefix() . 'session:';
    }

    public function advisoryLockName(string $logicalName): string
    {
        $database = Db::query('SELECT DATABASE() AS database_name')[0]['database_name'] ?? null;
        if (!is_string($database) || trim($database) === '' || trim($logicalName) === '') {
            throw new \RuntimeException('RUNTIME_NAMESPACE_DATABASE_INVALID');
        }

        $databaseFingerprint = substr(hash('sha256', $this->identity() . "\0" . $database), 0, 24);
        $logicalFingerprint = substr(hash('sha256', $logicalName), 0, 32);
        return "pa:l:{$databaseFingerprint}:{$logicalFingerprint}";
    }

    private function identity(): string
    {
        return self::PROJECT_ID . "\0" . $this->environment . "\0" . $this->resourceId;
    }
}
