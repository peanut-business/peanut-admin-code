<?php

declare(strict_types=1);

namespace app\common\infrastructure\authorization;

use app\common\model\auth\SystemMenu;
use app\platform\infrastructure\module\ThinkPhpModuleGovernanceProvider;
use PeanutAdmin\Kernel\Auth\TenantContext;
use PeanutAdmin\Kernel\Authorization\TenantAuthorizationRepository;
use PeanutAdmin\Kernel\Menu\MenuDefinition;
use PeanutAdmin\Kernel\Menu\MenuCatalogRepository;
use PeanutAdmin\Kernel\Menu\MenuRegistry;
use PeanutAdmin\Modules\Identity\Contract\TenantAuthorizationQuery;

/**
 * Adapts the Core Module/TenantModule catalog to the Admin Shell menu payload.
 *
 * The bridge is read-only: Plugin installation owns deployment state, the platform
 * owns TenantModule enablement, and Core RBAC owns member Permission grants.
 */
final readonly class CoreTenantModuleAdminBridge
{
    private const APPLICATION_PERMISSION_OWNER = 'peanut.admin';

    /** @return list<string> */
    public static function officialModuleMenuPaths(): array
    {
        return [
            '/system/file',
            '/system/crontab',
            '/notice/channel',
            '/notice/template',
            '/notice/log',
            '/app-setting/channel',
            '/app-setting/pay',
            '/member/list',
            '/member/tag',
            '/finance/account-log',
            '/finance/recharge',
            '/finance/refund',
            '/article/cate',
            '/article/list',
            '/system/configuration-transfer',
            '/system/integration-security',
            '/system/reference-codes',
            '/rich-text/documents',
            '/system/settings',
        ];
    }

    public function __construct(
        private ThinkPhpModuleGovernanceProvider $moduleGovernance,
        private TenantAuthorizationRepository $authorization,
        private MenuCatalogRepository $menuCatalog,
        private TenantAuthorizationQuery $identityAuthorization,
    ) {}

    /** @return array{menu:list<array<string,mixed>>,permissions:list<string>} */
    public function accessData(mixed $tenantContext): array
    {
        if (!$tenantContext instanceof TenantContext
            || $tenantContext->tenantId < 1
            || $tenantContext->memberId < 1) {
            return ['menu' => [], 'permissions' => []];
        }

        $permissions = array_values(array_unique([
            ...$this->authorization->permissions(
                $tenantContext->tenantId,
                $tenantContext->memberId,
            )->keys(),
            ...$this->applicationPermissions($tenantContext),
        ]));
        if ($this->isTenantOwner($tenantContext)) {
            $permissions = array_values(array_unique([
                ...$permissions,
                ...$this->registeredPermissions($tenantContext->tenantId),
            ]));
        }
        $definitions = $this->menuCatalog->activeDefinitions('tenant');
        $qualification = $this->moduleGovernance->qualification();
        $deploymentModules = array_map(
            static fn($module): string => $module->moduleKey,
            $qualification->installedModules(),
        );
        $tenantModules = $qualification->activeTenantModuleKeys($tenantContext->tenantId);
        $visible = (new MenuRegistry($definitions))->visible(
            'admin-web',
            static fn(string $moduleKey): bool => in_array($moduleKey, $deploymentModules, true),
            static fn(string $moduleKey): bool => in_array($moduleKey, $tenantModules, true),
            static fn(string $permission): bool => in_array($permission, $permissions, true),
        );

        return [
            'menu' => $this->serverMenuRecords($visible, $permissions),
            'permissions' => array_values(array_unique($permissions)),
        ];
    }

    /** @return list<array<string,mixed>> */
    public function assignableMenuRecords(int $tenantId): array
    {
        if ($tenantId < 1) {
            return [];
        }
        $qualification = $this->moduleGovernance->qualification();
        $deploymentModules = array_map(
            static fn($module): string => $module->moduleKey,
            $qualification->installedModules(),
        );
        $tenantModules = $qualification->activeTenantModuleKeys($tenantId);
        $visible = (new MenuRegistry($this->menuCatalog->activeDefinitions('tenant')))->visible(
            'admin-web',
            static fn(string $moduleKey): bool => in_array($moduleKey, $deploymentModules, true),
            static fn(string $moduleKey): bool => in_array($moduleKey, $tenantModules, true),
            static fn(string $_permission): bool => true,
        );

        return $this->serverMenuRecords($visible);
    }

    /** @return list<string> */
    public function registeredPermissions(int $tenantId): array
    {
        if ($tenantId < 1) {
            return [];
        }
        $qualification = $this->moduleGovernance->qualification();
        $installed = array_fill_keys(array_map(
            static fn($module): string => $module->moduleKey,
            $qualification->installedModules(),
        ), true);
        $active = array_values(array_unique([
            self::APPLICATION_PERMISSION_OWNER,
            ...array_filter(
                $qualification->activeTenantModuleKeys($tenantId),
                static fn(string $moduleKey): bool => isset($installed[$moduleKey]),
            ),
        ]));
        return $this->identityAuthorization->registeredPermissionKeys($active);
    }

    /** @return list<string> */
    public function registeredSystemMenuPermissions(int $tenantId): array
    {
        if ($tenantId < 1) {
            return [];
        }
        $qualification = $this->moduleGovernance->qualification();
        $installed = array_fill_keys(array_map(
            static fn($module): string => $module->moduleKey,
            $qualification->installedModules(),
        ), true);
        $active = array_fill_keys(array_values(array_unique([
            self::APPLICATION_PERMISSION_OWNER,
            ...array_filter(
                $qualification->activeTenantModuleKeys($tenantId),
                static fn(string $moduleKey): bool => isset($installed[$moduleKey]),
            ),
        ])), true);
        $permissions = [];
        $menuPermissions = array_map('strval', SystemMenu::where('is_disable', 0)
            ->where('perms', '<>', '')->distinct(true)->column('perms'));
        $states = $this->identityAuthorization->permissionStates($menuPermissions);
        foreach ($menuPermissions as $permission) {
            $state = $states[$permission] ?? null;
            $moduleKey = $state['module_key'] ?? null;
            if ($moduleKey !== null && $moduleKey !== '') {
                if (($state['status'] ?? null) !== 'active' || !isset($active[$moduleKey])) {
                    continue;
                }
            }
            $permissions[] = $permission;
        }

        return array_values(array_unique($permissions));
    }

    /** @return list<string> */
    private function applicationPermissions(TenantContext $context): array
    {
        return $this->identityAuthorization->applicationPermissionKeys($context);
    }

    /**
     * @param list<MenuDefinition> $definitions
     * @return list<array<string,mixed>>
     */
    private function serverMenuRecords(array $definitions, ?array $permissions = null): array
    {
        $legacy = SystemMenu::order('id')->select()->toArray();
        $groupPaths = [
            'core.organization' => '/system', 'core.business' => '/member',
            'core.content' => '/article', 'core.applications' => '/app-setting',
            'core.system' => '/system-settings', 'core.operations' => '/operations',
        ];
        $presentations = [];
        $ids = [];
        foreach ($definitions as $definition) {
            $ids[$definition->key] = self::virtualMenuId($definition->key);
            $matches = array_values(array_filter($legacy, static function (array $row) use ($definition, $groupPaths): bool {
                if ($definition->type === 'group') {
                    return $row['type'] === 'M' && $row['paths'] === ($groupPaths[$definition->key] ?? null);
                }
                $baseline = $row['upstream_defaults_json'] ?? null;
                if (is_string($baseline)) {
                    $baseline = json_decode($baseline, true, 512, JSON_THROW_ON_ERROR);
                }
                return $row['type'] === 'C' && ($row['paths'] === $definition->routePath
                    || (is_array($baseline) && ($baseline['paths'] ?? null) === $definition->routePath));
            }));
            // Ambiguous legacy identities are preserved, never guessed or merged.
            if (count($matches) === 1) {
                $presentations[$definition->key] = $matches[0];
                if ($definition->type === 'group') {
                    $ids[$definition->key] = (int) $matches[0]['id'];
                }
            }
        }

        $records = [];
        $byKey = [];
        foreach ($definitions as $definition) {
            $byKey[$definition->key] = $definition;
        }
        foreach ($definitions as $definition) {
            // A denied legacy directory must also suppress newly contributed
            // pages which have no legacy row of their own.
            $ancestor = $definition;
            $seen = [];
            while ($ancestor !== null) {
                if (isset($seen[$ancestor->key])) {
                    continue 2;
                }
                $seen[$ancestor->key] = true;
                $directory = $presentations[$ancestor->key] ?? null;
                if ($directory !== null && !$this->legacyPresentationVisible($directory, $legacy, $permissions)) {
                    continue 2;
                }
                $ancestor = $byKey[$ancestor->parentKey] ?? null;
            }
            $presentation = $presentations[$definition->key] ?? null;
            if ($presentation !== null && !$this->legacyPresentationVisible($presentation, $legacy, $permissions)) {
                continue;
            }
            $legacyId = $presentation['id'] ?? null;
            if ($presentation !== null && !$this->legacyCustomized($presentation, $legacy)) {
                $presentation = null;
            }
            $records[] = [
                'id' => $ids[$definition->key],
                'pid' => $presentation === null
                    ? ($ids[$definition->parentKey] ?? 0) : (int) $presentation['pid'],
                'type' => $definition->type === 'group' ? 'M' : 'C',
                'name' => $presentation['name'] ?? $definition->name,
                'icon' => $presentation['icon'] ?? $definition->icon ?? '',
                'sort' => $presentation['sort'] ?? -$definition->sortOrder,
                'perms' => $definition->requiredPermission ?? '',
                'paths' => $presentation['paths'] ?? $definition->routePath ?? '',
                'component' => $definition->componentKey ?? '',
                'is_cache' => $presentation['is_cache'] ?? 0,
                'is_show' => $presentation['is_show'] ?? 1,
                'is_disable' => 0,
                'module_key' => $definition->moduleKey,
                'required_permission' => $definition->requiredPermission,
                'menu_key' => $definition->key,
                'legacy_menu_id' => $legacyId,
                'children' => [],
            ];
        }

        return $records;
    }

    private function legacyCustomized(array $row, array $rows): bool
    {
        $byId = array_column($rows, null, 'id');
        $seen = [];
        while (true) {
            $id = (int) $row['id'];
            if (isset($seen[$id])) {
                return true;
            }
            $seen[$id] = true;
            $baseline = $row['upstream_defaults_json'] ?? null;
            if (is_string($baseline)) {
                $baseline = json_decode($baseline, true, 512, JSON_THROW_ON_ERROR);
            }
            if (!is_array($baseline)) {
                return true;
            }
            foreach ($baseline as $field => $expected) {
                $current = $row[$field] ?? null;
                if (is_int($expected)) {
                    $current = (int) $current;
                }
                if ($current !== $expected) {
                    return true;
                }
            }
            $parent = (int) $row['pid'];
            if ($parent === 0 || !isset($byId[$parent])) {
                return false;
            }
            $row = $byId[$parent];
        }
    }

    /** Legacy presentation never grants a permission; changed restrictions remain effective. */
    private function legacyPresentationVisible(array $row, array $rows, ?array $permissions): bool
    {
        $byId = array_column($rows, null, 'id');
        $seen = [];
        while (true) {
            $id = (int) $row['id'];
            if (isset($seen[$id]) || (int) $row['is_disable'] !== 0) {
                return false;
            }
            $seen[$id] = true;
            $baseline = $row['upstream_defaults_json'] ?? null;
            if (is_string($baseline)) {
                $baseline = json_decode($baseline, true, 512, JSON_THROW_ON_ERROR);
            }
            $customPermission = (string) $row['perms'];
            if ($permissions !== null && $customPermission !== ''
                && ($baseline['perms'] ?? null) !== $customPermission
                && !in_array($customPermission, $permissions, true)) {
                return false;
            }
            $parent = (int) $row['pid'];
            if ($parent === 0 || !isset($byId[$parent])) {
                return true;
            }
            $row = $byId[$parent];
        }
    }

    public static function virtualMenuId(string $menuKey): int
    {
        return 2_000_000_000 + (int) sprintf('%u', crc32($menuKey)) % 100_000_000;
    }

    private function isTenantOwner(TenantContext $context): bool
    {
        return $this->identityAuthorization->isTenantOwner($context);
    }
}
