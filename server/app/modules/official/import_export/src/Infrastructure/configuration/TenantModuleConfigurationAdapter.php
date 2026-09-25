<?php

declare(strict_types=1);

namespace PeanutAdmin\Modules\ImportExport\Infrastructure\configuration;

use PeanutAdmin\Kernel\Auth\TenantContext;
use PeanutAdmin\Kernel\Context\PlatformContext;
use PeanutAdmin\Modules\Identity\Module\TenantModuleConfigurationService;
use think\facade\Db;

/** Transfers only the configuration of currently effective Tenant Modules. */
final readonly class TenantModuleConfigurationAdapter implements ConfigurationTransferAdapter
{
    private const TABLE = 'p' . 'a_tenant_module';

    public function __construct(
        private TenantModuleConfigurationService $modules,
    ) {}

    public function key(): string
    {
        return ConfigurationPackageCodec::ADAPTER_TENANT_MODULES;
    }

    public function supportsCreate(): bool
    {
        return false;
    }

    public function export(TenantContext|PlatformContext $context): array
    {
        $tenantId = $this->tenantId($context);
        $entries = [];
        foreach (Db::name('tenant_module')->where('tenant_id', $tenantId)->where('status', 'enabled')
            ->where(function ($query): void {
                $query->whereNull('effective_at')->whereOr('effective_at', '<=', Db::raw('CURRENT_TIMESTAMP(3)'));
            })
            ->where(function ($query): void {
                $query->whereNull('expires_at')->whereOr('expires_at', '>', Db::raw('CURRENT_TIMESTAMP(3)'));
            })
            ->field('module_key,config_json')->order('module_key')->select()->toArray() as $row) {
            $moduleKey = (string) ($row['module_key'] ?? '');
            $this->assertModuleKey($moduleKey);
            $entries[] = ConfigurationTransferValue::entry(
                $this->key(),
                $moduleKey,
                $this->decodeConfig($row['config_json'] ?? null),
            );
        }
        return $entries;
    }

    public function current(TenantContext|PlatformContext $context, string $key): array
    {
        $tenantId = $this->tenantId($context);
        $this->assertModuleKey($key);
        $row = Db::name('tenant_module')->where('tenant_id', $tenantId)->where('module_key', $key)
            ->field('status,config_json,config_revision,effective_at,expires_at')->find();
        if (!is_array($row)) {
            return ['exists' => false, 'value' => null, 'revision' => null];
        }
        $effective = $this->effective($row);
        return [
            'exists' => $effective,
            'value' => ConfigurationTransferValue::entry(
                $this->key(),
                $key,
                $this->decodeConfig($row['config_json'] ?? null),
            )['value'],
            'revision' => (int) ($row['config_revision'] ?? 0),
        ];
    }

    public function apply(
        TenantContext|PlatformContext $context,
        string $key,
        mixed $value,
        array $entry,
        ?int $revision,
    ): void {
        $tenant = $this->tenantContext($context);
        $this->assertModuleKey($key);
        if (!is_array($value) || $revision === null) {
            throw new \runtimeException('TRANSFER_TENANT_MODULE_NOT_ENABLED');
        }

        $current = $this->current($tenant, $key);
        if (!$current['exists'] || !is_int($current['revision'])) {
            throw new \runtimeException('TRANSFER_TENANT_MODULE_NOT_ENABLED');
        }

        $this->modules->update(
            $tenant,
            $key,
            $value,
            $revision,
        );
    }

    private function tenantContext(TenantContext|PlatformContext $context): TenantContext
    {
        if (!$context instanceof TenantContext
            || $context->tenantId < 1
            || $context->accountId < 1
            || $context->memberId < 1
            || $context->authorizationRevision < 1
            || $context->sessionKey === ''
            || $context->clientKey === ''
            || $context->requestId === '') {
            throw new \runtimeException('TRANSFER_TENANT_CONTEXT_INVALID');
        }
        return $context;
    }

    private function tenantId(TenantContext|PlatformContext $context): int
    {
        return $this->tenantContext($context)->tenantId;
    }

    private function effective(array $row): bool
    {
        if ((string) ($row['status'] ?? '') !== 'enabled') {
            return false;
        }
        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        foreach (['effective_at' => true, 'expires_at' => false] as $column => $lowerBound) {
            $value = $row[$column] ?? null;
            if ($value === null || $value === '') {
                continue;
            }
            try {
                $date = new \DateTimeImmutable((string) $value, new \DateTimeZone('UTC'));
            } catch (\Throwable) {
                return false;
            }
            if (($lowerBound && $date > $now) || (!$lowerBound && $date <= $now)) {
                return false;
            }
        }
        return true;
    }

    /** @return array<string, mixed> */
    private function decodeConfig(mixed $encoded): array
    {
        if ($encoded === null || $encoded === '') {
            return [];
        }
        try {
            $config = json_decode((string) $encoded, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new \runtimeException('TRANSFER_TENANT_MODULE_INVALID');
        }
        if (!is_array($config)) {
            throw new \runtimeException('TRANSFER_TENANT_MODULE_INVALID');
        }
        return $config;
    }

    private function assertModuleKey(string $moduleKey): void
    {
        if (preg_match('/^[a-z][a-z0-9]*(?:-[a-z0-9]+)*(?:\.[a-z][a-z0-9]*(?:-[a-z0-9]+)*)*$/D', $moduleKey) !== 1) {
            throw new \runtimeException('TRANSFER_TENANT_MODULE_INVALID');
        }
    }
}
