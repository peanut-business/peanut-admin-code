<?php
declare(strict_types=1);

namespace PeanutAdmin\Modules\ImportExport\Infrastructure\configuration;
use PeanutAdmin\Kernel\Auth\TenantContext;
use PeanutAdmin\Kernel\Context\PlatformContext;
use think\facade\Db;

/** Transfers the application-owned tenant_setting documents. */
final class TenantSettingsConfigurationAdapter implements ConfigurationTransferAdapter
{
    public function key(): string
    {
        return ConfigurationPackageCodec::ADAPTER_TENANT_SETTINGS;
    }

    public function supportsCreate(): bool
    {
        return true;
    }

    public function export(TenantContext|PlatformContext $context): array
    {
        $tenantId = $this->tenantId($context);
        $entries = [];
        foreach (Db::name('tenant_setting')->where('tenant_id', $tenantId)
            ->field('namespace,config_json')->order('namespace')->select()->toArray() as $row) {
            $namespace = (string)($row['namespace'] ?? '');
            $document = $this->decodeDocument($row['config_json'] ?? null);
            $entries[] = ConfigurationTransferValue::entry($this->key(), $namespace, $document);
        }
        return $entries;
    }

    public function current(TenantContext|PlatformContext $context, string $key): array
    {
        $tenantId = $this->tenantId($context);
        $this->assertNamespace($key);
        $row = Db::name('tenant_setting')->where('tenant_id', $tenantId)->where('namespace', $key)
            ->field('config_json,revision')->find();
        if (!is_array($row)) {
            return ['exists' => false, 'value' => null, 'revision' => null];
        }

        return [
            'exists' => true,
            'value' => ConfigurationTransferValue::entry($this->key(), $key, $this->decodeDocument($row['config_json'] ?? null))['value'],
            'revision' => (int)($row['revision'] ?? 0),
        ];
    }

    public function apply(
        TenantContext|PlatformContext $context,
        string $key,
        mixed $value,
        array $entry,
        ?int $revision,
    ): void {
        $tenantId = $this->tenantId($context);
        $this->assertNamespace($key);
        if (!is_array($value)) {
            throw new \runtimeException('TRANSFER_TENANT_SETTING_INVALID');
        }
        try {
            $encoded = json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        } catch (\JsonException) {
            throw new \runtimeException('TRANSFER_TENANT_SETTING_INVALID');
        }

        Db::transaction(function () use ($tenantId, $key, $encoded, $revision): void {
            $row = Db::name('tenant_setting')->where('tenant_id', $tenantId)->where('namespace', $key)
                ->field('id,revision,create_time')->lock(true)->find();
            $now = time();
            if (!is_array($row)) {
                if ($revision !== null) {
                    throw new \runtimeException('TRANSFER_CONFLICT');
                }
                Db::name('tenant_setting')->insert([
                    'tenant_id' => $tenantId,
                    'namespace' => $key,
                    'config_json' => $encoded,
                    'revision' => 1,
                    'create_time' => $now,
                    'update_time' => $now,
                ]);
            } else {
                if ($revision === null || (int)$row['revision'] !== $revision) {
                    throw new \runtimeException('TRANSFER_CONFLICT');
                }
                $updated = Db::name('tenant_setting')->where('id', (int)$row['id'])
                    ->where('tenant_id', $tenantId)->where('namespace', $key)->where('revision', $revision)->update([
                    'config_json' => $encoded,
                    'revision' => Db::raw('revision + 1'),
                    'update_time' => $now,
                ]);
                if ($updated !== 1) {
                    throw new \runtimeException('TRANSFER_CONFLICT');
                }
            }
        });
    }

    private function tenantId(TenantContext|PlatformContext $context): int
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
        return $context->tenantId;
    }

    /** @return array<string, mixed> */
    private function decodeDocument(mixed $encoded): array
    {
        try {
            $document = json_decode((string)$encoded, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new \runtimeException('TRANSFER_TENANT_SETTING_INVALID');
        }
        if (!is_array($document)) {
            throw new \runtimeException('TRANSFER_TENANT_SETTING_INVALID');
        }
        return $document;
    }

    private function assertNamespace(string $namespace): void
    {
        if (preg_match('/^[a-z][a-z0-9.-]{0,63}$/D', $namespace) !== 1) {
            throw new \runtimeException('TRANSFER_TENANT_SETTING_INVALID');
        }
    }
}
