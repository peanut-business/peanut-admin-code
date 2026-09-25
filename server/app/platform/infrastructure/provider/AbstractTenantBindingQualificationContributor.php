<?php

declare(strict_types=1);

namespace app\platform\infrastructure\provider;

use app\platform\contract\provider\ProviderQualificationContributor;
use app\platform\value\provider\ProviderQualificationSubject;
use think\facade\Db;

abstract class AbstractTenantBindingQualificationContributor implements ProviderQualificationContributor
{
    public function __construct(
        private readonly string $digestKey,
    ) {
        if (strlen($digestKey) < 32) {
            throw new \InvalidArgumentException('PROVIDER_QUALIFICATION_DIGEST_KEY_INVALID');
        }
    }

    /**
     * @return list<array{provider_key:string,binding_provider:string,category:string,callback_required:bool}>
     */
    abstract protected function definitions(): array;

    /** @param array<string,mixed> $config */
    abstract protected function configured(string $providerKey, array $config, int $bindingStatus): bool;

    public function subjects(): array
    {
        $tenants = Db::name('tenant')->where('status', 'active')->order('id')->column('id');
        $definitions = $this->definitions();
        $bindingProviders = array_values(array_unique(array_column($definitions, 'binding_provider')));
        $bindings = $this->bindings($bindingProviders);
        $subjects = [];
        foreach ($tenants as $tenantValue) {
            $tenantId = (int) $tenantValue;
            foreach ($definitions as $definition) {
                $bindingProvider = $definition['binding_provider'];
                $row = $bindings[$tenantId][$bindingProvider] ?? null;
                $config = is_array($row) ? json_decode((string) $row['config_json'], true) : [];
                $config = is_array($config) ? $config : [];
                $status = is_array($row) ? (int) $row['status'] : 0;
                $providerKey = $definition['provider_key'];
                $subjects[] = new ProviderQualificationSubject(
                    $providerKey,
                    $definition['category'],
                    'tenant',
                    $tenantId,
                    $providerKey,
                    $this->configured($providerKey, $config, $status),
                    $definition['callback_required'],
                    null,
                    $this->digest($tenantId, $providerKey, $row),
                );
            }
        }
        return $subjects;
    }

    /** @param list<string> $providers @return array<int,array<string,array<string,mixed>>> */
    private function bindings(array $providers): array
    {
        if ($providers === []) {
            return [];
        }
        $indexed = [];
        $rows = Db::name('external_channel_binding')->whereIn('provider', $providers)
            ->field('id,tenant_id,provider,identity_hash,config_json,status,update_time')
            ->order('tenant_id')->order('provider')->select()->toArray();
        foreach ($rows as $row) {
            $indexed[(int) $row['tenant_id']][(string) $row['provider']] = $row;
        }
        return $indexed;
    }

    /** @param array<string,mixed>|null $row */
    private function digest(int $tenantId, string $providerKey, ?array $row): string
    {
        $payload = is_array($row)
            ? implode("\0", [
                $providerKey,
                (string) $row['id'],
                (string) $row['identity_hash'],
                (string) $row['status'],
                (string) $row['update_time'],
                (string) $row['config_json'],
            ])
            : implode("\0", [$providerKey, (string) $tenantId, 'missing']);
        return hash_hmac('sha256', $payload, $this->digestKey);
    }

    /** @param array<string,mixed> $config @param list<string> $fields */
    protected function complete(array $config, array $fields): bool
    {
        foreach ($fields as $field) {
            if (trim((string) ($config[$field] ?? '')) === '') {
                return false;
            }
        }
        return true;
    }
}
