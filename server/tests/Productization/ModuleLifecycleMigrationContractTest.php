<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

function pluginMigrationExpect(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function pluginMigrationTableSql(string $sql, string $table): string
{
    $matched = preg_match(
        '/CREATE TABLE `' . preg_quote($table, '/') . '` \(.*?\n\) ENGINE=.*?;/s',
        $sql,
        $matches,
    );
    pluginMigrationExpect($matched === 1, "missing lifecycle table: {$table}");
    return $matches[0];
}

function pluginMigrationNormalize(string $sql): string
{
    return (string) preg_replace('/\s+/', ' ', trim(rtrim($sql, ';')));
}

$serverRoot = dirname(__DIR__, 2);
$sql = '';
foreach ([
    '/app/modules/peanut/identity/database/migrations/20261101010001_initial_identity_schema.php',
    '/app/modules/peanut/settings/database/migrations/20261101010004_initial_settings_schema.php',
] as $path) {
    $sql .= (string) file_get_contents($serverRoot . $path);
}
pluginMigrationExpect($sql !== '', 'Module lifecycle migration source is unavailable');

$requiredTables = [
    'pa_module_installation',
    'pa_permission',
    'pa_protected_resource',
    'pa_target_type',
    'pa_resource_operation',
    'pa_resource_operation_target_type',
    'pa_resource_operation_permission',
    'pa_data_condition_definition',
    'pa_resource_operation_condition',
    'pa_menu_definition',
    'pa_setting_definition',
    'pa_setting_deployment_value',
    'pa_setting_tenant_value',
    'pa_setting_target_value',
];
foreach ($requiredTables as $table) {
    pluginMigrationTableSql($sql, $table);
}

pluginMigrationExpect(
    !str_contains($sql, 'CREATE TABLE `pa_plugin_installation`')
        && !str_contains($sql, 'CREATE TABLE `pa_plugin_module`')
        && !str_contains($sql, 'CREATE TABLE `pa_module_migration`'),
    'initial Module-owned schema must not recreate retired Plugin or custom migration ledgers',
);
foreach (['pa_resource_operation_target_type', 'pa_resource_operation_permission', 'pa_menu_definition'] as $table) {
    pluginMigrationExpect(
        str_contains(pluginMigrationTableSql($sql, $table), 'REFERENCES `pa_permission` (`id`)'),
        "{$table} must reference Identity-owned pa_permission",
    );
}
foreach (['deployment', 'tenant', 'target'] as $scope) {
    pluginMigrationExpect(
        str_contains($sql, "CONSTRAINT `chk_setting_{$scope}_storage`"),
        "settings {$scope} storage constraint is missing",
    );
}

echo "PLUGIN-LIFECYCLE-MIGRATION-CONTRACT-001 passed\n";
