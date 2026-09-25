<?php

declare(strict_types=1);

namespace app\platform\infrastructure\module;

use app\platform\infrastructure\plugin\ModuleCatalogApplier;
use PeanutAdmin\Kernel\Module\ManifestDocument;
use PeanutAdmin\Kernel\Module\ModuleException;
use think\facade\Db;

/** Registers one explicitly deployed Module manifest in the deployment ledger. */
final readonly class DeploymentModuleInstaller
{
    public function __construct(
        private string $serverRoot,
        private ModuleCatalogApplier $catalogs,
    ) {}

    /**
     * @param array<string,mixed> $deploymentConfig
     * @return array{key:string,version:string,schema:int,digest:string,status:string}
     */
    public function install(string $moduleKey, array $deploymentConfig): array
    {
        $registry = $this->registry($deploymentConfig);
        $manifest = null;
        foreach ($registry->compiled()->modules as $candidate) {
            if (($candidate->data['key'] ?? null) === $moduleKey) {
                $manifest = $candidate;
                break;
            }
        }
        if (!$manifest instanceof ManifestDocument) {
            throw new ModuleException('MODULE_NOT_REGISTERED', "Unknown deployed Module: {$moduleKey}");
        }

        $identity = $this->identity($manifest);
        $now = gmdate('Y-m-d H:i:s.v');
        return Db::transaction(function () use ($identity, $registry, $moduleKey, $now): array {
            Db::name('module_installation')->duplicate(['module_key'])->insert([
                'module_key' => $identity['key'],
                'installed_version' => $identity['version'],
                'manifest_schema_version' => $identity['schema'],
                'manifest_digest' => $identity['digest'],
                'installed_at' => $now,
                'activated_at' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            $current = Db::name('module_installation')->where('module_key', $identity['key'])
                ->field('installed_version,manifest_schema_version,manifest_digest,status')->lock(true)->find();
            if ($current === null) {
                throw new ModuleException(
                    'MODULE_INSTALLATION_FAILED',
                    "Module installation record was not created: {$identity['key']}",
                );
            }
            $this->assertSameIdentity($identity, $current);
            $this->catalogs->apply($registry->compiled(), [$moduleKey]);
            return $identity;
        });
    }

    /** @param array<string,mixed> $deploymentConfig */
    private function registry(array $deploymentConfig): DeployedTenantModuleRegistry
    {
        return (new ThinkPhpModuleGovernanceProvider(
            $this->serverRoot,
            $deploymentConfig,
            $this->catalogs,
        ))->registry();
    }

    /** @return array{key:string,version:string,schema:int,digest:string,status:string} */
    private function identity(ManifestDocument $manifest): array
    {
        return [
            'key' => (string) $manifest->data['key'],
            'version' => (string) $manifest->data['version'],
            'schema' => (int) $manifest->data['schema_version'],
            'digest' => $manifest->digest,
            'status' => 'active',
        ];
    }

    /**
     * @param array{key:string,version:string,schema:int,digest:string,status:string} $identity
     * @param array<string,mixed> $current
     */
    private function assertSameIdentity(array $identity, array $current): void
    {
        if ((string) ($current['installed_version'] ?? '') !== $identity['version']
            || (int) ($current['manifest_schema_version'] ?? 0) !== $identity['schema']
            || !hash_equals($identity['digest'], (string) ($current['manifest_digest'] ?? ''))
            || (string) ($current['status'] ?? '') !== $identity['status']) {
            throw new ModuleException(
                'MODULE_INSTALLATION_MISMATCH',
                "Installed Module identity differs from the deployment registry: {$identity['key']}",
            );
        }
    }
}
