<?php

declare(strict_types=1);

namespace PeanutAdmin\Modules\ReferenceCodes\Service;

use DateTimeImmutable;
use PeanutAdmin\Kernel\Module\ManifestDocument;
use PeanutAdmin\Kernel\Module\ModuleException;
use PeanutAdmin\Kernel\Module\ModuleKey;
use PeanutAdmin\Modules\ReferenceCodes\Versioned\Definition\ReferenceCodeSetLoader;
use PeanutAdmin\Modules\ReferenceCodes\Versioned\Definition\ReferenceCodeSetRegistry;
use PeanutAdmin\Modules\ReferenceCodes\Versioned\Persistence\ReferenceCodeStore;
use think\facade\Db;

/**
 * Deployment catalog collaboration for already selected, compiled Module manifests.
 * This does not authorize installation or expose Tenant reference-code values.
 * The caller keeps its original lifecycle authorization and enclosing transaction.
 */
final class ReferenceCodeCatalogService
{
    /** @param array<string, ManifestDocument> $manifests */
    public function synchronize(array $manifests, DateTimeImmutable $now): void
    {
        $registry = new ReferenceCodeSetRegistry();
        $loader = new ReferenceCodeSetLoader();
        foreach ($manifests as $key => $manifest) {
            if (!$manifest instanceof ManifestDocument || !is_string($key)
                || ($manifest->data['key'] ?? null) !== $key) {
                throw new ModuleException('MODULE_REFERENCE_CODE_OWNER_MISMATCH', 'A selected Module identity is inconsistent.');
            }
            ModuleKey::fromString($key);
            $backend = is_array($manifest->data['backend'] ?? null) ? $manifest->data['backend'] : [];
            $resource = $backend['reference_code_sets'] ?? null;
            $definitions = is_string($resource)
                ? $loader->load($key, $this->resourcePath($manifest, $resource))
                : [];
            $registry->registerModule($key, $definitions);
        }
        if (!$this->storageAvailable()) {
            if ($registry->all() !== []) {
                throw new ModuleException(
                    'MODULE_REFERENCE_CODE_STORAGE_REQUIRED',
                    'Install the declared reference-code dependency before registering its contributions.',
                );
            }
            return;
        }
        (new ReferenceCodeStore())->synchronize($registry, $now);
    }

    /** Fixed definition fields used by the host's existing catalog fingerprint, not business records.
     * @return list<array<string, mixed>>
     */
    public function revisionRows(): array
    {
        return $this->storageAvailable()
            ? Db::name('reference_code_set')
                ->field('id,module_key,set_key,lifecycle,revision,definition_digest')->order('id')->select()->toArray()
            : [];
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
        return $this->storageAvailable()
            ? (int) Db::table('pa_reference_code_set')->whereIn('module_key', $moduleKeys)->where('lifecycle', 'active')->count()
            : 0;
    }

    private function storageAvailable(): bool
    {
        // Native driver metadata, using the same selected connection as catalog writes.
        return in_array('pa_reference_code_set', Db::connect()->getTables(), true);
    }

    private function resourcePath(ManifestDocument $manifest, string $resource): string
    {
        if ($resource === '' || str_starts_with($resource, '/') || str_contains($resource, '\\')
            || preg_match('#(?:^|/)\.\.(?:/|$)#', $resource) === 1) {
            throw new ModuleException('MODULE_REFERENCE_CODE_RESOURCE_INVALID', 'A reference-code resource must stay inside its selected Module.');
        }
        $root = realpath($manifest->root);
        $path = $manifest->root . '/' . $resource;
        $resolved = realpath($path);
        if ($root === false || is_link($path)
            || ($resolved !== false && !str_starts_with($resolved, $root . DIRECTORY_SEPARATOR))) {
            throw new ModuleException('MODULE_REFERENCE_CODE_RESOURCE_INVALID', 'A reference-code resource is outside its selected Module.');
        }
        return $path;
    }
}
