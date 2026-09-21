<?php
declare(strict_types=1);

namespace app\platform\infrastructure\plugin;

use app\platform\exception\plugin\PluginLifecycleException;
use DateTimeImmutable;
use DateTimeZone;
use PeanutAdmin\Modules\Identity\Authorization\ModuleAuthorizationCatalogSynchronizer;
use PeanutAdmin\Modules\Identity\Authorization\Persistence\ThinkPhpAuthorizationCatalogRepository;
use PeanutAdmin\Modules\Identity\Menu\MenuCatalogSynchronizer;
use PeanutAdmin\Modules\Identity\Menu\ThinkPhpMenuCatalogRepository;
use PeanutAdmin\Kernel\Module\CompiledModuleRegistry;
use PeanutAdmin\Kernel\Module\ManifestDocument;
use PeanutAdmin\Modules\Settings\Definition\SettingDefinitionLoader;
use PeanutAdmin\Modules\Settings\Definition\SettingDefinitionRegistry;
use PeanutAdmin\Modules\Settings\Definition\SettingDefinitionSynchronizer;
use PeanutAdmin\Modules\ReferenceCodes\Versioned\Definition\ReferenceCodeSetLoader;
use PeanutAdmin\Modules\ReferenceCodes\Versioned\Definition\ReferenceCodeSetRegistry;
use PeanutAdmin\Modules\ReferenceCodes\Versioned\Persistence\ReferenceCodeStore;
use think\facade\Db;

/** The single application entry point for applying, retiring, and purging Module catalog contributions. */
final readonly class ModuleCatalogApplier
{
    public function __construct(private SettingDefinitionSynchronizer $settings) {}

    /**
     * @param null|list<string> $moduleKeys Null applies the complete compiled registry; a list applies only that scope.
     * @return array{operation:string,modules:list<string>,catalog_revision:string,changes:array{menus:int,permissions:int,settings:int,reference_codes:int}}
     */
    public function apply(CompiledModuleRegistry $registry, ?array $moduleKeys = null): array
    {
        $manifests = [];
        foreach ($registry->modules as $manifest) {
            $key = (string)($manifest->data['key'] ?? '');
            $manifests[$key] = $manifest;
        }
        $fullRegistry = $moduleKeys === null;
        $selectedKeys = $fullRegistry ? array_keys($manifests) : array_values(array_unique($moduleKeys));
        sort($selectedKeys, SORT_STRING);
        foreach ($selectedKeys as $key) {
            if (!isset($manifests[$key])) {
                throw new PluginLifecycleException('MODULE_NOT_REGISTERED', 'Module is not present in the compiled registry.');
            }
        }

        $selected = array_intersect_key($manifests, array_fill_keys($selectedKeys, true));
        $compiledScope = $this->scope($registry, $selected);
        $before = $this->catalogRevision();
        Db::transaction(function () use ($compiledScope, $fullRegistry, $registry, $selected, $selectedKeys): void {
            (new ModuleAuthorizationCatalogSynchronizer(new ThinkPhpAuthorizationCatalogRepository()))
                ->synchronize($compiledScope);

            $menuRepository = new ThinkPhpMenuCatalogRepository();
            $menus = $fullRegistry
                ? $menuRepository
                : new ScopedMenuCatalogRepository($menuRepository, $selectedKeys);
            (new MenuCatalogSynchronizer($menus))->synchronize($fullRegistry ? $registry : $compiledScope);

            $settings = new SettingDefinitionRegistry();
            $loader = new SettingDefinitionLoader();
            foreach ($selected as $key => $manifest) {
                $backend = is_array($manifest->data['backend'] ?? null) ? $manifest->data['backend'] : [];
                $resource = $backend['setting_definitions'] ?? null;
                $definitions = is_string($resource)
                    ? $loader->load($key, $manifest->root . '/' . ltrim($resource, '/'))
                    : [];
                $settings->registerModule($key, $definitions);
            }
            $now = new DateTimeImmutable(
                (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d H:i:s.v'),
                new DateTimeZone('UTC'),
            );
            $this->settings->synchronize(
                $settings,
                $now,
            );

            $referenceCodes = new ReferenceCodeSetRegistry();
            $referenceCodeLoader = new ReferenceCodeSetLoader();
            foreach ($selected as $key => $manifest) {
                $backend = is_array($manifest->data['backend'] ?? null) ? $manifest->data['backend'] : [];
                $resource = $backend['reference_code_sets'] ?? null;
                $definitions = is_string($resource)
                    ? $referenceCodeLoader->load($key, $manifest->root . '/' . ltrim($resource, '/'))
                    : [];
                $referenceCodes->registerModule($key, $definitions);
            }
            (new ReferenceCodeStore())->synchronize($referenceCodes, $now);

            $mutations = new ModuleCatalogMutationRepository();
            $mutations->retireMissing($selected);
            if ($fullRegistry) {
                $absent = array_values(array_diff($mutations->activeModuleKeys(), $selectedKeys));
                if ($absent !== []) $mutations->retire($absent);
            }
        });

        $after = $this->catalogRevision();
        return [
            'operation' => hash_equals($before, $after) ? 'unchanged' : 'synced',
            'modules' => $selectedKeys,
            'catalog_revision' => $after,
            'changes' => $this->activeCounts($selectedKeys),
        ];
    }

    /** @param list<string> $moduleKeys */
    public function retire(array $moduleKeys): void
    {
        (new ModuleCatalogMutationRepository())->retire($moduleKeys);
    }

    /** @param list<string> $moduleKeys */
    public function purge(array $moduleKeys): void
    {
        (new ModuleCatalogMutationRepository())->purge($moduleKeys);
    }

    /** @param list<string> $moduleKeys @return array{removed:list<array<string,mixed>>,preserved:list<array<string,mixed>>,blockers:list<array<string,mixed>>} */
    public function plan(array $moduleKeys, bool $purge): array
    {
        return (new ModuleCatalogMutationRepository())->plan($moduleKeys, $purge);
    }

    public function catalogRevision(): string
    {
        $rows = [];
        $rows['pa_permission'] = Db::name('permission')
            ->field('id,key,module_key,status')
            ->fieldRaw('COALESCE(DATE_FORMAT(retired_at,"%Y-%m-%d %H:%i:%s.%f"),"") AS retired_at')
            ->order('id')->select()->toArray();
        $rows['pa_menu_definition'] = Db::name('menu_definition')
            ->field('id,key,module_key,status,manifest_digest')->order('id')->select()->toArray();
        $rows['pa_setting_definition'] = Db::name('setting_definition')
            ->field('id,module_key,setting_key,status,revision,definition_digest')->order('id')->select()->toArray();
        $rows['pa_reference_code_set'] = self::referenceCodeTableExists()
            ? Db::name('reference_code_set')
                ->field('id,module_key,set_key,lifecycle,revision,definition_digest')->order('id')->select()->toArray()
            : [];
        return hash('sha256', json_encode($rows, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
    }

    /** @param list<string> $moduleKeys */
    public function invalidateTenantAuthorization(array $moduleKeys): void
    {
        if ($moduleKeys === []) {
            return;
        }
        Db::transaction(function () use ($moduleKeys): void {
            $tenantIds = array_map('intval', Db::name('tenant_module')
                ->whereIn('module_key', $moduleKeys)->lock(true)->distinct(true)->order('tenant_id')->column('tenant_id'));
            Db::name('tenant_module')->whereIn('module_key', $moduleKeys)->update([
                'authorization_revision' => Db::raw('authorization_revision+1'),
                'updated_at' => Db::raw('UTC_TIMESTAMP(3)'),
            ]);
            if ($tenantIds !== []) {
                Db::name('tenant')->whereIn('id', $tenantIds)->update([
                    'authorization_revision' => Db::raw('authorization_revision+1'),
                    'revision' => Db::raw('revision+1'),
                    'updated_at' => Db::raw('UTC_TIMESTAMP(3)'),
                ]);
            }
        });
    }

    /** @param array<string,ManifestDocument> $manifests */
    private function scope(CompiledModuleRegistry $registry, array $manifests): CompiledModuleRegistry
    {
        $keys = array_fill_keys(array_keys($manifests), true);
        $menus = array_filter(
            $registry->menus,
            static fn(array $menu): bool => isset($keys[(string)($menu['module_key'] ?? '')]),
        );
        return new CompiledModuleRegistry(
            array_values($manifests),
            array_filter($registry->targetTypeOwners, static fn(string $owner): bool => isset($keys[$owner])),
            array_filter($registry->ownedTableOwners, static fn(string $owner): bool => isset($keys[$owner])),
            $menus,
            hash('sha256', implode('|', array_map(static fn(ManifestDocument $manifest): string => $manifest->digest, $manifests))),
        );
    }

    /** @param list<string> $moduleKeys @return array{menus:int,permissions:int,settings:int,reference_codes:int} */
    private function activeCounts(array $moduleKeys): array
    {
        if ($moduleKeys === []) return ['menus' => 0, 'permissions' => 0, 'settings' => 0, 'reference_codes' => 0];
        $counts = [];
        foreach (['menus' => 'pa_menu_definition', 'permissions' => 'pa_permission', 'settings' => 'pa_setting_definition'] as $name => $table) {
            $counts[$name] = (int)Db::table($table)->whereIn('module_key', $moduleKeys)->where('status', 'active')->count();
        }
        $counts['reference_codes'] = self::referenceCodeTableExists()
            ? (int)Db::table('pa_reference_code_set')
                ->whereIn('module_key', $moduleKeys)->where('lifecycle', 'active')->count()
            : 0;
        return $counts;
    }

    private static function referenceCodeTableExists(): bool
    {
        return Db::query("SHOW TABLES LIKE 'pa_reference_code_set'") !== [];
    }
}
