<?php
declare(strict_types=1);

namespace app\platform\composition\plugin;

use app\platform\infrastructure\plugin\PluginLockResolver;
use app\platform\validation\module\OpisManifestSchemaValidator;
use app\platform\validation\module\ReflectionContractInspector;
use app\platform\validation\module\StrictVersionConstraintMatcher;
use PeanutAdmin\DataPermission\Persistence\Schema\DataPermissionSchema;
use PeanutAdmin\Kernel\Authorization\Persistence\Schema\AuthorizationSchema;
use PeanutAdmin\Kernel\Idempotency\IdempotencySchema;
use PeanutAdmin\Kernel\Migration\ModuleSchema;
use PeanutAdmin\Kernel\Module\CompiledModuleRegistry;
use PeanutAdmin\Kernel\Module\ManifestLoader;
use PeanutAdmin\Kernel\Module\ModuleBoundaryChecker;
use PeanutAdmin\Kernel\Module\ModuleException;
use PeanutAdmin\Kernel\Module\ModuleHostLayout;
use PeanutAdmin\Kernel\Module\ModuleProvider;
use PeanutAdmin\Kernel\Module\ModuleRegistryCompiler;
use PeanutAdmin\Kernel\Persistence\Schema\KernelSchema;

/** Compiles immutable deployment metadata without opening a database connection. */
final readonly class ModuleDefinitionRegistryFactory
{
    public function __construct(private string $serverRoot)
    {
    }

    /** @param array<string,mixed> $deploymentConfig */
    public function fromDeploymentConfig(array $deploymentConfig): CompiledModuleRegistry
    {
        $roots = is_array($deploymentConfig['roots'] ?? null)
            ? array_values($deploymentConfig['roots'])
            : [];
        return $this->compile($this->resolveRoots($roots, true), $deploymentConfig);
    }

    /** @param array<string,mixed> $deploymentConfig */
    public function fromPluginLock(
        PluginLockResolver $resolver,
        array $deploymentConfig,
        bool $includeDeploymentRoots = true,
    ): CompiledModuleRegistry {
        $configuredRoots = $includeDeploymentRoots && is_array($deploymentConfig['roots'] ?? null)
            ? array_values($deploymentConfig['roots'])
            : [];
        $roots = [
            ...$this->resolveRoots($configuredRoots, false),
            ...$resolver->moduleRoots(),
        ];
        return $this->compile(array_values(array_unique($roots)), $deploymentConfig);
    }

    /**
     * @param list<mixed> $roots
     * @return list<string>
     */
    private function resolveRoots(array $roots, bool $required): array
    {
        if (!array_is_list($roots) || ($required && $roots === [])) {
            throw new ModuleException('MODULE_REGISTRY_UNAVAILABLE', 'No deployed Module roots are configured.');
        }
        $resolvedRoots = [];
        foreach ($roots as $root) {
            if (!is_string($root) || trim($root) === '') {
                throw new ModuleException('MODULE_REGISTRY_UNAVAILABLE', 'A deployed Module root is invalid.');
            }
            $candidate = str_starts_with($root, DIRECTORY_SEPARATOR)
                ? $root
                : $this->serverRoot . '/' . ltrim($root, '/');
            $resolved = realpath($candidate);
            if ($resolved === false || !is_dir($resolved)) {
                throw new ModuleException('MODULE_REGISTRY_UNAVAILABLE', 'A deployed Module root is unavailable.');
            }
            $resolvedRoots[] = $resolved;
        }
        return $resolvedRoots;
    }

    /** @param list<string> $roots @param array<string,mixed> $deploymentConfig */
    private function compile(array $roots, array $deploymentConfig): CompiledModuleRegistry
    {
        if ($roots === []) {
            throw new ModuleException('MODULE_REGISTRY_UNAVAILABLE', 'No locked Module roots are available.');
        }
        $kernelVersion = trim((string)($deploymentConfig['kernel_version'] ?? ''));
        $clients = is_array($deploymentConfig['registered_client_keys'] ?? null)
            ? array_values($deploymentConfig['registered_client_keys'])
            : [];
        if ($kernelVersion === '' || $clients === [] || !array_is_list($clients)) {
            throw new ModuleException('MODULE_REGISTRY_UNAVAILABLE', 'Module deployment metadata is invalid.');
        }

        $kernelRoot = dirname((new \ReflectionClass(ModuleProvider::class))->getFileName(), 3);
        $layout = new ModuleHostLayout('server/app/modules', 'app\\modules', 'web/src/modules');
        $compiler = new ModuleRegistryCompiler(
            new OpisManifestSchemaValidator($kernelRoot . '/resources/schemas/module-manifest.schema.json'),
            new StrictVersionConstraintMatcher(),
            new ReflectionContractInspector(),
            $kernelVersion,
            $this->frontendComponents($roots),
            $layout,
            [
                ...KernelSchema::tableNames(),
                ...AuthorizationSchema::tableNames(),
                ...ModuleSchema::tableNames(),
                ...IdempotencySchema::tableNames(),
                ...DataPermissionSchema::tableNames(),
            ],
            $clients,
            [
                ...\PeanutAdmin\Kernel\Authorization\CorePermissionCatalog::TENANT,
                ...\PeanutAdmin\Kernel\Authorization\CorePermissionCatalog::PLATFORM,
            ],
            $this->historicalBusinessTableOwners(),
        );
        $loader = new ManifestLoader();
        $documents = array_map(
            static fn(string $root) => $loader->load($root),
            $roots,
        );
        $registry = $compiler->compile($documents);
        (new ModuleBoundaryChecker($registry, $layout, ['pa_']))->check();
        return $registry;
    }

    /**
     * The protected, shipped IAM manifest is the ownership source. Arbitrary modules
     * cannot opt themselves into the historical table exception by declaring a table.
     * Technical ModuleSchema/IdempotencySchema tables have no exception.
     * @return array<string,string>
     */
    private function historicalBusinessTableOwners(): array
    {
        $technical = [...ModuleSchema::tableNames(), ...IdempotencySchema::tableNames()];
        $owners = [];
        foreach (['official.identity' => 'identity', 'official.ops' => 'ops'] as $key => $directory) {
            $path = $this->serverRoot . '/app/modules/official/' . $directory . '/module.json';
            $manifest = json_decode((string)file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
            if (!is_array($manifest) || ($manifest['key'] ?? null) !== $key
                || ($manifest['lifecycle']['protected'] ?? false) !== true) {
                throw new ModuleException('MODULE_MANIFEST_INVALID', "The protected {$key} manifest is unavailable.");
            }
            foreach (($manifest['database']['owned_tables'] ?? []) as $table) {
                if (!is_string($table) || in_array($table, $technical, true) || isset($owners[$table])) {
                    throw new ModuleException('MODULE_REGISTRY_CONFLICT', "{$key} historical table ownership is invalid.");
                }
                $owners[$table] = $key;
            }
        }
        return $owners;
    }

    /** @param non-empty-list<string> $roots @return list<string> */
    private function frontendComponents(array $roots): array
    {
        $components = [];
        $loader = new ManifestLoader();
        foreach ($roots as $root) {
            $manifest = $loader->load($root);
            $catalog = is_array($manifest->data['catalog'] ?? null) ? $manifest->data['catalog'] : [];
            foreach ((array)($catalog['menus'] ?? []) as $menu) {
                if (!is_array($menu) || ($menu['type'] ?? null) !== 'page') {
                    continue;
                }
                $component = $menu['component_key'] ?? null;
                if (is_string($component) && $component !== '') {
                    $components[] = $component;
                }
            }
        }
        $components = array_values(array_unique($components));
        sort($components, SORT_STRING);
        return $components;
    }
}
