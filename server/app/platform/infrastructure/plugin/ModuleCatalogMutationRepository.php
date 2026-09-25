<?php

declare(strict_types=1);

namespace app\platform\infrastructure\plugin;

use PeanutAdmin\Kernel\Module\ManifestDocument;
use think\facade\Db;

/** Computes and applies the catalog/RBAC part of retire and purge through ThinkPHP. */
final readonly class ModuleCatalogMutationRepository
{
    /** @param array<string,ManifestDocument> $manifests */
    public function retireMissing(array $manifests): void
    {
        $moduleKeys = array_keys($manifests);
        if ($moduleKeys === []) {
            return;
        }
        $declared = [
            'pa_permission' => [],
            'pa_protected_resource' => [],
            'pa_target_type' => [],
            'pa_data_condition_definition' => [],
        ];
        $operations = [];
        foreach ($manifests as $moduleKey => $manifest) {
            $catalog = is_array($manifest->data['catalog'] ?? null) ? $manifest->data['catalog'] : [];
            foreach ([
                'permissions' => 'pa_permission',
                'protected_resources' => 'pa_protected_resource',
                'target_types' => 'pa_target_type',
                'data_conditions' => 'pa_data_condition_definition',
            ] as $manifestKey => $table) {
                foreach ((array) ($catalog[$manifestKey] ?? []) as $entry) {
                    if (is_array($entry) && is_string($entry['key'] ?? null)) {
                        $declared[$table][$moduleKey][] = $entry['key'];
                    }
                }
            }
            foreach ((array) ($catalog['protected_resources'] ?? []) as $resource) {
                if (!is_array($resource) || !is_string($resource['key'] ?? null)) {
                    continue;
                }
                foreach ((array) ($resource['operations'] ?? []) as $operation) {
                    if (is_array($operation) && is_string($operation['key'] ?? null)) {
                        $operations[$resource['key'] . "\0" . $operation['key']] = true;
                    }
                }
            }
        }

        $now = $this->now();
        foreach ($declared as $table => $byModule) {
            foreach ($moduleKeys as $moduleKey) {
                $this->retireMissingKeys($table, $moduleKey, array_values(array_unique($byModule[$moduleKey] ?? [])), $now);
            }
        }

        $rows = Db::table('pa_resource_operation')->alias('operation')
            ->join('pa_protected_resource resource', 'resource.id=operation.protected_resource_id')
            ->whereIn('resource.module_key', $moduleKeys)->where('operation.status', 'active')
            ->field('operation.id,resource.key AS resource_key,operation.operation')->order('operation.id')->select()->toArray();
        $missingOperationIds = [];
        foreach ($rows as $row) {
            if (!isset($operations[(string) $row['resource_key'] . "\0" . (string) $row['operation']])) {
                $missingOperationIds[] = (string) $row['id'];
            }
        }
        if ($missingOperationIds === []) {
            return;
        }
        $this->deleteByForeignIds('pa_resource_operation_permission', 'resource_operation_id', $missingOperationIds);
        foreach (['pa_resource_operation_target_type', 'pa_resource_operation_condition'] as $table) {
            $this->updateByIds($table, $this->operationRelationIds($table, $missingOperationIds), ['status' => 'retired']);
        }
        $this->updateByIds('pa_resource_operation', $missingOperationIds, ['status' => 'retired', 'updated_at' => $now]);
    }

    /** @return list<string> */
    public function activeModuleKeys(): array
    {
        $keys = [];
        foreach (['pa_permission', 'pa_protected_resource', 'pa_target_type', 'pa_data_condition_definition', 'pa_menu_definition', 'pa_setting_definition'] as $table) {
            foreach (Db::table($table)->where('status', 'active')->distinct(true)->order('module_key')->column('module_key') as $key) {
                $key = (string) $key;
                if (!in_array($key, ['core', 'platform'], true)) {
                    $keys[$key] = true;
                }
            }
        }
        $result = array_keys($keys);
        sort($result, SORT_STRING);
        return $result;
    }

    /** @param list<string> $moduleKeys @return array{removed:list<array<string,mixed>>,preserved:list<array<string,mixed>>,blockers:list<array<string,mixed>>} */
    public function plan(array $moduleKeys, bool $purge): array
    {
        $permissions = $this->ids('pa_permission', $moduleKeys);
        $resources = $this->ids('pa_protected_resource', $moduleKeys);
        $targets = $this->ids('pa_target_type', $moduleKeys);
        $conditions = $this->ids('pa_data_condition_definition', $moduleKeys);
        $operations = $this->operationIds($resources);
        $menus = $this->ids('pa_menu_definition', $moduleKeys);
        $settings = $this->ids('pa_setting_definition', $moduleKeys);
        $removed = [];
        $preserved = [];
        $blockers = $this->crossModuleBlockers($moduleKeys, $permissions, $targets, $conditions, $operations);

        if ($purge) {
            foreach ([
                ['pa_setting_target_value', $this->foreignIds('pa_setting_target_value', 'definition_id', $settings)],
                ['pa_setting_tenant_value', $this->foreignIds('pa_setting_tenant_value', 'definition_id', $settings)],
                ['pa_setting_deployment_value', $this->foreignIds('pa_setting_deployment_value', 'definition_id', $settings)],
                ['pa_setting_definition', $settings],
                ['pa_role_permission', $this->roleBindingIds($permissions, false)],
                ['pa_platform_role_permission', $this->roleBindingIds($permissions, true)],
                ['pa_menu_definition', $menus],
                ['pa_resource_operation_permission', $this->operationRelationIds('pa_resource_operation_permission', $operations)],
                ['pa_resource_operation_target_type', $this->operationRelationIds('pa_resource_operation_target_type', $operations)],
                ['pa_resource_operation_condition', $this->operationRelationIds('pa_resource_operation_condition', $operations)],
                ['pa_resource_operation', $operations],
                ['pa_protected_resource', $resources],
                ['pa_target_type', $targets],
                ['pa_data_condition_definition', $conditions],
                ['pa_permission', $permissions],
            ] as [$table, $identifiers]) {
                $this->append($removed, 'catalog', $table, 'delete', $identifiers);
            }
        } else {
            foreach ([
                ['pa_menu_definition', $this->activeIds('pa_menu_definition', $moduleKeys)],
                ['pa_setting_definition', $this->activeIds('pa_setting_definition', $moduleKeys)],
                ['pa_resource_operation_target_type', $this->activeOperationRelationIds('pa_resource_operation_target_type', $operations)],
                ['pa_resource_operation_condition', $this->activeOperationRelationIds('pa_resource_operation_condition', $operations)],
                ['pa_resource_operation', $this->activeOperationIds($resources)],
                ['pa_protected_resource', $this->activeIds('pa_protected_resource', $moduleKeys)],
                ['pa_target_type', $this->activeIds('pa_target_type', $moduleKeys)],
                ['pa_data_condition_definition', $this->activeIds('pa_data_condition_definition', $moduleKeys)],
                ['pa_permission', $this->activeIds('pa_permission', $moduleKeys)],
            ] as [$table, $identifiers]) {
                $this->append($removed, 'catalog', $table, 'soft_retire', $identifiers);
            }
            foreach ([
                ['pa_setting_target_value', $this->foreignIds('pa_setting_target_value', 'definition_id', $settings)],
                ['pa_setting_tenant_value', $this->foreignIds('pa_setting_tenant_value', 'definition_id', $settings)],
                ['pa_setting_deployment_value', $this->foreignIds('pa_setting_deployment_value', 'definition_id', $settings)],
                ['pa_role_permission', $this->roleBindingIds($permissions, false)],
                ['pa_platform_role_permission', $this->roleBindingIds($permissions, true)],
            ] as [$table, $identifiers]) {
                $this->append($preserved, 'catalog', $table, 'preserve', $identifiers, true);
            }
        }
        $this->sortEntries($removed);
        $this->sortEntries($preserved);
        usort($blockers, static fn(array $a, array $b): int => strcmp((string) $a['code'], (string) $b['code']));
        return ['removed' => $removed, 'preserved' => $preserved, 'blockers' => $blockers];
    }

    /** @param list<string> $moduleKeys */
    public function retire(array $moduleKeys): void
    {
        Db::transaction(function () use ($moduleKeys): void {
            $now = $this->now();
            $permissions = $this->ids('pa_permission', $moduleKeys);
            $resources = $this->ids('pa_protected_resource', $moduleKeys);
            $operations = $this->operationIds($resources);
            $this->updateByIds('pa_permission', $permissions, ['status' => 'retired', 'retired_at' => $now, 'updated_at' => $now]);
            $this->updateByIds('pa_protected_resource', $resources, ['status' => 'retired', 'retired_at' => $now, 'updated_at' => $now]);
            foreach (['pa_target_type', 'pa_data_condition_definition', 'pa_menu_definition'] as $table) {
                $this->updateByIds($table, $this->ids($table, $moduleKeys), ['status' => 'retired', 'updated_at' => $now]);
            }
            $this->updateByIds('pa_setting_definition', $this->ids('pa_setting_definition', $moduleKeys), ['status' => 'retired', 'revision' => Db::raw('revision+1'), 'updated_at' => $now]);
            $this->updateByIds('pa_resource_operation', $operations, ['status' => 'retired', 'updated_at' => $now]);
            foreach (['pa_resource_operation_target_type', 'pa_resource_operation_condition'] as $table) {
                $this->updateByIds($table, $this->operationRelationIds($table, $operations), ['status' => 'retired']);
            }
        });
    }

    /** @param list<string> $moduleKeys */
    public function purge(array $moduleKeys): void
    {
        Db::transaction(function () use ($moduleKeys): void {
            $permissions = $this->ids('pa_permission', $moduleKeys);
            $resources = $this->ids('pa_protected_resource', $moduleKeys);
            $targets = $this->ids('pa_target_type', $moduleKeys);
            $conditions = $this->ids('pa_data_condition_definition', $moduleKeys);
            $operations = $this->operationIds($resources);
            $settings = $this->ids('pa_setting_definition', $moduleKeys);
            foreach (['pa_setting_target_value', 'pa_setting_tenant_value', 'pa_setting_deployment_value'] as $table) {
                $this->deleteByForeignIds($table, 'definition_id', $settings);
            }
            $this->deleteByForeignIds('pa_role_permission', 'permission_id', $permissions);
            $this->deleteByForeignIds('pa_platform_role_permission', 'permission_id', $permissions);
            $this->deleteByIds('pa_menu_definition', $this->ids('pa_menu_definition', $moduleKeys));
            foreach (['pa_resource_operation_permission', 'pa_resource_operation_target_type', 'pa_resource_operation_condition'] as $table) {
                $this->deleteByForeignIds($table, 'resource_operation_id', $operations);
            }
            $this->deleteByIds('pa_resource_operation', $operations);
            $this->deleteByIds('pa_protected_resource', $resources);
            $this->deleteByIds('pa_target_type', $targets);
            $this->deleteByIds('pa_data_condition_definition', $conditions);
            $this->deleteByIds('pa_setting_definition', $settings);
            $this->deleteByIds('pa_permission', $permissions);
        });
    }

    /** @return list<string> */
    private function ids(string $table, array $moduleKeys): array
    {
        return $moduleKeys === [] ? [] : array_map('strval', Db::table($table)->whereIn('module_key', $moduleKeys)->order('id')->column('id'));
    }

    /** @return list<string> */
    private function activeIds(string $table, array $moduleKeys): array
    {
        return $moduleKeys === [] ? [] : array_map('strval', Db::table($table)->whereIn('module_key', $moduleKeys)->where('status', 'active')->order('id')->column('id'));
    }

    /** @return list<string> */
    private function operationIds(array $resourceIds): array
    {
        return $this->foreignIds('pa_resource_operation', 'protected_resource_id', $resourceIds);
    }

    /** @return list<string> */
    private function activeOperationIds(array $resourceIds): array
    {
        return $resourceIds === [] ? [] : array_map('strval', Db::table('pa_resource_operation')->whereIn('protected_resource_id', $resourceIds)->where('status', 'active')->order('id')->column('id'));
    }

    /** @return list<string> */
    private function operationRelationIds(string $table, array $operationIds): array
    {
        return $this->foreignIds($table, 'resource_operation_id', $operationIds);
    }

    /** @return list<string> */
    private function activeOperationRelationIds(string $table, array $operationIds): array
    {
        return $operationIds === [] ? [] : array_map('strval', Db::table($table)->whereIn('resource_operation_id', $operationIds)->where('status', 'active')->order('id')->column('id'));
    }

    /** @return list<string> */
    private function foreignIds(string $table, string $column, array $foreignIds): array
    {
        return $foreignIds === [] ? [] : array_map('strval', Db::table($table)->whereIn($column, $foreignIds)->order('id')->column('id'));
    }

    /** @return list<string> */
    private function roleBindingIds(array $permissionIds, bool $platform): array
    {
        if ($permissionIds === []) {
            return [];
        }
        $table = $platform ? 'pa_platform_role_permission' : 'pa_role_permission';
        $fields = $platform ? 'platform_role_id,permission_id' : 'tenant_id,role_id,permission_id';
        $rows = Db::table($table)->whereIn('permission_id', $permissionIds)->field($fields)->select()->toArray();
        $identifiers = array_map(static function (array $row) use ($platform): string {
            return $platform
                ? 'platform_role_id=' . $row['platform_role_id'] . ';permission_id=' . $row['permission_id']
                : 'tenant_id=' . $row['tenant_id'] . ';role_id=' . $row['role_id'] . ';permission_id=' . $row['permission_id'];
        }, $rows);
        sort($identifiers, SORT_STRING);
        return $identifiers;
    }

    /** @return list<array<string,mixed>> */
    private function crossModuleBlockers(array $moduleKeys, array $permissionIds, array $targetIds, array $conditionIds, array $operationIds): array
    {
        $checks = [];
        if ($moduleKeys !== [] && $permissionIds !== []) {
            $checks['MODULE_CATALOG_EXTERNAL_MENU_REFERENCE'] = Db::table('pa_menu_definition')->whereNotIn('module_key', $moduleKeys)->whereIn('required_permission_id', $permissionIds)->order('id')->column('id');
        }
        if ($permissionIds !== [] && $operationIds !== []) {
            $checks['MODULE_CATALOG_EXTERNAL_PERMISSION_REFERENCE'] = Db::table('pa_resource_operation_permission')->whereIn('permission_id', $permissionIds)->whereNotIn('resource_operation_id', $operationIds)->order('id')->column('id');
        }
        if ($targetIds !== [] && $operationIds !== []) {
            $checks['MODULE_CATALOG_EXTERNAL_TARGET_REFERENCE'] = Db::table('pa_resource_operation_target_type')->whereIn('target_type_id', $targetIds)->whereNotIn('resource_operation_id', $operationIds)->order('id')->column('id');
        }
        if ($conditionIds !== [] && $operationIds !== []) {
            $checks['MODULE_CATALOG_EXTERNAL_CONDITION_REFERENCE'] = Db::table('pa_resource_operation_condition')->whereIn('condition_definition_id', $conditionIds)->whereNotIn('resource_operation_id', $operationIds)->order('id')->column('id');
        }
        $blockers = [];
        foreach ($checks as $code => $ids) {
            $ids = array_map('strval', $ids);
            if ($ids !== []) {
                $blockers[] = ['code' => $code, 'identifiers' => $ids];
            }
        }
        return $blockers;
    }

    private function append(array &$entries, string $scope, string $table, string $action, array $identifiers, bool $includeEmpty = false): void
    {
        sort($identifiers, SORT_STRING);
        if ($identifiers === [] && !$includeEmpty) {
            return;
        }
        $entries[] = ['scope' => $scope, 'table' => $table, 'action' => $action, 'count' => count($identifiers), 'identifiers' => $identifiers];
    }

    private function sortEntries(array &$entries): void
    {
        usort($entries, static fn(array $a, array $b): int => strcmp($a['scope'] . "\0" . $a['table'] . "\0" . $a['action'], $b['scope'] . "\0" . $b['table'] . "\0" . $b['action']));
    }

    private function updateByIds(string $table, array $ids, array $values): void
    {
        if ($ids !== []) {
            Db::table($table)->whereIn('id', $ids)->update($values);
        }
    }

    private function deleteByIds(string $table, array $ids): void
    {
        if ($ids !== []) {
            Db::table($table)->whereIn('id', $ids)->delete();
        }
    }

    private function deleteByForeignIds(string $table, string $column, array $ids): void
    {
        if ($ids !== []) {
            Db::table($table)->whereIn($column, $ids)->delete();
        }
    }

    /** @param list<string> $activeKeys */
    private function retireMissingKeys(string $table, string $moduleKey, array $activeKeys, string $now): void
    {
        $query = Db::table($table)->where('module_key', $moduleKey)->where('status', 'active');
        if ($activeKeys !== []) {
            $query->whereNotIn('key', $activeKeys);
        }
        $values = ['status' => 'retired', 'updated_at' => $now];
        if (in_array($table, ['pa_permission', 'pa_protected_resource'], true)) {
            $values['retired_at'] = $now;
        } elseif ($table === 'pa_setting_definition') {
            $values['revision'] = Db::raw('revision+1');
        }
        $query->update($values);
    }

    private function now(): string
    {
        return gmdate('Y-m-d H:i:s.v');
    }
}
