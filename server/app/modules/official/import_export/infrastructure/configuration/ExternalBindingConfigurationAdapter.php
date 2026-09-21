<?php
declare(strict_types=1);

namespace app\modules\official\import_export\infrastructure\configuration;
use app\modules\official\integration\contracts\ExternalTenantResolutionException;
use PeanutAdmin\Kernel\Auth\TenantContext;
use PeanutAdmin\Kernel\Context\PlatformContext;
use think\facade\Db;

/** Transfers Tenant-owned external provider bindings without callback secrets. */
final class ExternalBindingConfigurationAdapter implements ConfigurationTransferAdapter
{
    public function key(): string
    {
        return ConfigurationPackageCodec::ADAPTER_EXTERNAL_BINDINGS;
    }

    public function supportsCreate(): bool
    {
        return true;
    }

    public function export(TenantContext|PlatformContext $context): array
    {
        $tenantId = $this->tenantId($context);
        $entries = [];
        foreach (Db::name('external_channel_binding')->where('tenant_id', $tenantId)
            ->field('provider,identity_hash,identity_hint,config_json,status')->order('provider')->select()->toArray() as $row) {
            $provider = (string)($row['provider'] ?? '');
            $this->assertProvider($provider);
            $config = $this->decodeConfig($row['config_json'] ?? null);
            $identityHash = $this->identityHash($row['identity_hash'] ?? null, (int)($row['status'] ?? 0) === 1);
            $value = [
                'identity_hash' => $identityHash,
                'identity_hint' => (string)($row['identity_hint'] ?? ''),
                'config' => $config,
                'status' => (int)($row['status'] ?? 0) === 1,
            ];
            $entries[] = ConfigurationTransferValue::entry($this->key(), $provider, $value);
        }

        return $entries;
    }

    public function current(TenantContext|PlatformContext $context, string $key): array
    {
        $tenantId = $this->tenantId($context);
        $this->assertProvider($key);
        $row = Db::name('external_channel_binding')->where('tenant_id', $tenantId)->where('provider', $key)
            ->field('identity_hash,identity_hint,config_json,status,update_time')->find();
        if (!is_array($row)) {
            return ['exists' => false, 'value' => null, 'revision' => null];
        }

        $value = [
            'identity_hash' => $this->identityHash($row['identity_hash'] ?? null, (int)($row['status'] ?? 0) === 1),
            'identity_hint' => (string)($row['identity_hint'] ?? ''),
            'config' => $this->decodeConfig($row['config_json'] ?? null),
            'status' => (int)($row['status'] ?? 0) === 1,
        ];

        return [
            'exists' => true,
            'value' => ConfigurationTransferValue::entry($this->key(), $key, $value)['value'],
            // The table predates a numeric revision column. Bind concurrency
            // to the complete persisted state so same-second writes cannot
            // evade the import plan's optimistic check.
            'revision' => $this->configurationRevision($row),
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
        $this->assertProvider($key);
        if (!is_array($value) || array_is_list($value)) {
            throw new \runtimeException('TRANSFER_EXTERNAL_BINDING_INVALID');
        }
        $expected = ['config', 'identity_hash', 'identity_hint', 'status'];
        $actual = array_keys($value);
        sort($actual, SORT_STRING);
        sort($expected, SORT_STRING);
        if ($actual !== $expected
            || !is_array($value['config'])
            || !is_bool($value['status'])
            || !is_string($value['identity_hint'])
            || strlen($value['identity_hint']) > 32
            || ($value['identity_hash'] !== null && !is_string($value['identity_hash']))) {
            throw new \runtimeException('TRANSFER_EXTERNAL_BINDING_INVALID');
        }
        $identityHash = $value['identity_hash'];
        if ($identityHash !== null && preg_match('/^[a-f0-9]{64}$/D', $identityHash) !== 1) {
            throw new \runtimeException('TRANSFER_EXTERNAL_BINDING_INVALID');
        }
        if ($value['status'] && $identityHash === null) {
            throw new \runtimeException('TRANSFER_EXTERNAL_BINDING_INVALID');
        }

        $this->importConfiguration(
            $tenant,
            $key,
            $value['config'],
            $identityHash,
            $value['identity_hint'],
            $value['status'],
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

    /** @return array<string, mixed> */
    private function decodeConfig(mixed $encoded): array
    {
        try {
            $config = json_decode((string)$encoded, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new \runtimeException('TRANSFER_EXTERNAL_BINDING_INVALID');
        }
        if (!is_array($config)) {
            throw new \runtimeException('TRANSFER_EXTERNAL_BINDING_INVALID');
        }
        return $config;
    }

    private function identityHash(mixed $value, bool $required): ?string
    {
        $identityHash = trim((string)$value);
        if ($identityHash === '') {
            if ($required) {
                throw new \runtimeException('TRANSFER_EXTERNAL_BINDING_INVALID');
            }
            return null;
        }
        if (preg_match('/^[a-f0-9]{64}$/D', $identityHash) !== 1) {
            throw new \runtimeException('TRANSFER_EXTERNAL_BINDING_INVALID');
        }
        return $identityHash;
    }

    private function assertProvider(string $provider): void
    {
        if (preg_match('/^[a-z][a-z0-9.-]{0,63}$/D', $provider) !== 1) {
            throw new \runtimeException('TRANSFER_EXTERNAL_BINDING_INVALID');
        }
    }

    /** @param array<string, mixed> $binding */
    private function configurationRevision(array $binding): int
    {
        $state = [];
        foreach (['identity_hash', 'identity_hint', 'config_json', 'status', 'update_time'] as $key) {
            $state[$key] = $binding[$key] ?? null;
        }
        try {
            $encoded = json_encode(
                $state,
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
            );
        } catch (\JsonException) {
            throw new \runtimeException('TRANSFER_EXTERNAL_BINDING_INVALID');
        }
        $revision = hexdec(substr(hash('sha256', $encoded), 0, 15));
        if (!is_int($revision) || $revision < 1) {
            throw new \runtimeException('TRANSFER_EXTERNAL_BINDING_INVALID');
        }
        return $revision;
    }

    /** @param array<string, mixed> $config */
    private function importConfiguration(
        TenantContext $context,
        string $provider,
        array $config,
        ?string $identityHash,
        string $identityHint,
        bool $enabled,
        ?int $expectedRevision,
    ): void {
        $tenantId = $context->tenantId;
        $provider = trim($provider);
        $identityHint = trim($identityHint);
        if (preg_match('/^[a-z][a-z0-9.-]{0,63}$/D', $provider) !== 1
            || strlen($identityHint) > 32
            || ($identityHash !== null && preg_match('/^[a-f0-9]{64}$/D', $identityHash) !== 1)
            || ($enabled && $identityHash === null)) {
            throw new \runtimeException('TRANSFER_EXTERNAL_BINDING_INVALID');
        }

        $this->assertActiveTenant($tenantId);
        $binding = Db::name('external_channel_binding')->where('tenant_id', $tenantId)->where('provider', $provider)
            ->field('id,identity_hash,identity_hint,config_json,status,update_time')->lock(true)->find();
        $binding = is_array($binding) ? $binding : null;
        $currentRevision = $binding === null ? null : $this->configurationRevision($binding);
        if (($expectedRevision === null && $binding !== null)
            || ($expectedRevision !== null && $currentRevision !== $expectedRevision)) {
            throw new \runtimeException('TRANSFER_CONFLICT');
        }
        $this->persistImportedLocked(
            $tenantId,
            $provider,
            $config,
            $identityHash,
            $identityHint,
            $enabled,
            $binding,
        );
    }

    private function assertActiveTenant(int $tenantId): void
    {
        if (Db::name('tenant')->where('id', $tenantId)->lock(true)->value('status') !== 'active') {
            throw new ExternalTenantResolutionException();
        }
    }

    /** @param array<string, mixed>|null $binding @param array<string, mixed> $config */
    private function persistImportedLocked(
        int $tenantId,
        string $provider,
        array $config,
        ?string $identityHash,
        string $identityHint,
        bool $enabled,
        ?array $binding,
    ): void {
        $encoded = json_encode($config, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $now = time();
        if ($binding === null) {
            Db::name('external_channel_binding')->insert([
                'tenant_id' => $tenantId,
                'provider' => $provider,
                'callback_key' => bin2hex(random_bytes(32)),
                'identity_hash' => $identityHash
                    ?? hash('sha256', 'unconfigured:' . $provider . ':' . $tenantId),
                'identity_hint' => $identityHint,
                'config_json' => $encoded,
                'status' => $enabled ? 1 : 0,
                'create_time' => $now,
                'update_time' => $now,
            ]);
            return;
        }

        $changes = [
            'config_json' => $encoded,
            'status' => $enabled ? 1 : 0,
            'update_time' => $now,
        ];
        if ($identityHash !== null) {
            $changes['identity_hash'] = $identityHash;
            $changes['identity_hint'] = $identityHint;
        }
        Db::name('external_channel_binding')->where('id', (int)$binding['id'])
            ->where('tenant_id', $tenantId)->where('provider', $provider)->update($changes);
    }
}
