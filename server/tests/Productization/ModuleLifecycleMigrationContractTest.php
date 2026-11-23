<?php

declare(strict_types=1);

use app\platform\infrastructure\module\ModuleLockResolver;

$root = realpath($argv[1] ?? (getenv('PEANUT_TEST_APPLICATION_ROOT') ?: dirname(__DIR__, 3)));
if ($root === false) { throw new RuntimeException('Prepared application root is unavailable'); }
require $root . '/server/vendor/autoload.php';

function pluginMigrationExpect(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function pluginMigrationTableSql(string $sql, string $table): string
{
    $matched = preg_match(
        '/CREATE TABLE `?' . preg_quote($table, '/') . '`? \(\r?\n.*?\r?\n\) ENGINE=[^\r\n;]+;?/s',
        $sql,
        $matches,
    );
    pluginMigrationExpect($matched === 1, "missing lifecycle table: {$table}");
    return $matches[0];
}

function pluginMigrationReferences(string $sql, string $table, string $target): bool
{
    return preg_match(
        '/REFERENCES\s+`?' . preg_quote($target, '/') . '`?\s*\(\s*`?id`?\s*\)/',
        pluginMigrationTableSql($sql, $table),
    ) === 1;
}

$resolver = new ModuleLockResolver($root . '/server', '../modules.lock');
$sql = '';
$declaredTables = [];
foreach ([
    'peanut.identity' => [
        '20261101010001_initial_identity_schema.php',
        '20261104000000_api_permission_bundles.php',
    ],
    'peanut.settings' => ['20261101010004_initial_settings_schema.php'],
] as $key => $files) {
    $moduleRoot = $resolver->require($key)->moduleRoots[$key];
    $manifest = json_decode((string) file_get_contents($moduleRoot . '/module.json'), true, 512, JSON_THROW_ON_ERROR);
    $declaredTables = [...$declaredTables, ...$manifest['database']['owned_tables']];
    foreach ($files as $file) {
        $sql .= (string) file_get_contents($moduleRoot . '/database/migrations/' . $file);
    }
}
pluginMigrationExpect($sql !== '', 'Module lifecycle migration source is unavailable');

$requiredTables = [
    'pa_module_installation',
    'pa_permission',
    'pa_system_api',
    'pa_permission_api',
    'pa_job',
    'pa_data_permission',
    'pa_role',
    'pa_role_permission',
    'pa_tenant',
    'pa_tenant_member',
    'pa_member_role',
    'pa_system_menu',
    'pa_setting_definition',
    'pa_setting_deployment_value',
    'pa_setting_tenant_value',
    'pa_setting_target_value',
];
foreach ($requiredTables as $table) {
    pluginMigrationExpect(in_array($table, $declaredTables, true), "lifecycle table must have a locked Module owner: {$table}");
    pluginMigrationTableSql($sql, $table);
}

// Fresh catalogs use canonical APIs, menus and native data policies; legacy resource
// metadata is no longer a database-owned lifecycle catalog. Upgrade preservation is
// verified separately against the real pre-upgrade database.
foreach ([
    'pa_plugin_installation', 'pa_plugin_module', 'pa_module_migration',
    'pa_protected_resource', 'pa_target_type', 'pa_resource_operation',
    'pa_resource_operation_target_type', 'pa_resource_operation_permission',
    'pa_data_condition_definition', 'pa_resource_operation_condition',
] as $table) {
    pluginMigrationExpect(
        preg_match('/CREATE TABLE `?' . preg_quote($table, '/') . '`?\s*\(/', $sql) !== 1,
        "fresh Module-owned schema must not recreate retired lifecycle catalog: {$table}",
    );
}
foreach (['pa_permission_api', 'pa_role_permission', 'pa_system_menu'] as $table) {
    pluginMigrationExpect(
        pluginMigrationReferences($sql, $table, 'pa_permission'),
        "{$table} must reference Identity-owned pa_permission",
    );
}
pluginMigrationExpect(
    pluginMigrationReferences($sql, 'pa_permission_api', 'pa_system_api'),
    'permission bindings must reference the canonical Identity-owned API catalog',
);
foreach (['deployment', 'tenant', 'target'] as $scope) {
    pluginMigrationExpect(
        str_contains($sql, "CONSTRAINT `chk_setting_{$scope}_storage`"),
        "settings {$scope} storage constraint is missing",
    );
}

echo "PLUGIN-LIFECYCLE-MIGRATION-CONTRACT-001 passed\n";
