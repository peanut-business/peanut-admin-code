<?php

declare(strict_types=1);

namespace PeanutAdmin\Modules\Settings\Service;

use DateTimeImmutable;
use PeanutAdmin\Kernel\Module\ManifestDocument;
use PeanutAdmin\Kernel\Module\ModuleException;
use PeanutAdmin\Kernel\Module\ModuleKey;
use PeanutAdmin\Modules\Settings\Definition\SettingDefinitionLoader;
use PeanutAdmin\Modules\Settings\Definition\SettingDefinitionRegistry;
use PeanutAdmin\Modules\Settings\Definition\SettingDefinitionSynchronizer;
use think\facade\Db;

/**
 * Applies already selected deployment contributions using the existing definition rules.
 * The lifecycle caller retains authorization, dependency checks and its outer transaction.
 * Catalog metadata never includes stored deployment, Tenant or object setting values.
 */
final readonly class SettingCatalogService
{
    public function __construct(private SettingDefinitionSynchronizer $synchronizer) {}

    /** @param array<string, ManifestDocument> $manifests */
    public function synchronize(array $manifests, DateTimeImmutable $now): void
    {
        $registry = new SettingDefinitionRegistry();
        $loader = new SettingDefinitionLoader();
        foreach ($manifests as $key => $manifest) {
            if (!$manifest instanceof ManifestDocument || !is_string($key)
                || ($manifest->data['key'] ?? null) !== $key) {
                throw new ModuleException('MODULE_SETTING_OWNER_MISMATCH', 'A selected Module identity is inconsistent.');
            }
            ModuleKey::fromString($key);
            $backend = is_array($manifest->data['backend'] ?? null) ? $manifest->data['backend'] : [];
            $resource = $backend['setting_definitions'] ?? null;
            $definitions = is_string($resource)
                ? $loader->load($key, $this->resourcePath($manifest, $resource))
                : [];
            $registry->registerModule($key, $definitions);
        }
        $this->synchronizer->synchronize($registry, $now);
    }

    /** Fixed catalog fingerprint fields, not runtime setting values or an ORM object.
     * @return list<array<string, mixed>>
     */
    public function revisionRows(): array
    {
        return Db::name('setting_definition')
            ->field('id,module_key,setting_key,status,revision,definition_digest')->order('id')->select()->toArray();
    }

    /** @param list<string> $moduleKeys */
    public function activeCount(array $moduleKeys): int
    {
        if ($moduleKeys === []) {
            return 0;
        }
        foreach ($moduleKeys as $key) {
            ModuleKey::fromString($key);
        }
        return (int) Db::table('pa_setting_definition')
            ->whereIn('module_key', $moduleKeys)->where('status', 'active')->count();
    }

    private function resourcePath(ManifestDocument $manifest, string $resource): string
    {
        if ($resource === '' || str_starts_with($resource, '/') || str_contains($resource, '\\')
            || preg_match('#(?:^|/)\.\.(?:/|$)#', $resource) === 1) {
            throw new ModuleException('MODULE_SETTING_RESOURCE_INVALID', 'A setting resource must stay inside its selected Module.');
        }
        $root = realpath($manifest->root);
        $path = $manifest->root . '/' . $resource;
        $resolved = realpath($path);
        if ($root === false || is_link($path)
            || ($resolved !== false && !str_starts_with($resolved, $root . DIRECTORY_SEPARATOR))) {
            throw new ModuleException('MODULE_SETTING_RESOURCE_INVALID', 'A setting resource is outside its selected Module.');
        }
        return $path;
    }
}
