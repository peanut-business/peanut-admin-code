<?php

declare(strict_types=1);

use PeanutAdmin\Kernel\Persistence\Schema\KernelSchema;

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

$path = dirname(__DIR__, 2) . '/database/init.sql';
$sql = (string) file_get_contents($path);
pluginMigrationExpect($sql !== '', 'Plugin lifecycle migration is unavailable');

$requiredTables = [
    'pa_plugin_installation',
    'pa_plugin_module',
    'pa_module_migration',
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
    !str_contains($sql, 'CREATE TABLE `pa_permission`'),
    'canonical application schema must leave pa_permission to Core KernelSchema',
);
foreach (['pa_resource_operation_target_type', 'pa_resource_operation_permission', 'pa_menu_definition'] as $table) {
    pluginMigrationExpect(
        str_contains(pluginMigrationTableSql($sql, $table), 'REFERENCES `pa_permission` (`id`)'),
        "{$table} must reference native Core pa_permission",
    );
}
foreach (['deployment', 'tenant', 'target'] as $scope) {
    pluginMigrationExpect(
        str_contains($sql, "CONSTRAINT `chk_setting_{$scope}_storage`"),
        "settings {$scope} storage constraint is missing",
    );
}

echo "PLUGIN-LIFECYCLE-MIGRATION-CONTRACT-001 passed\n";
