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

$resolver = new ModuleLockResolver($root . '/server', '../modules.lock');
$sql = '';
foreach ([
    'peanut.identity' => '20261101010001_initial_identity_schema.php',
    'peanut.settings' => '20261101010004_initial_settings_schema.php',
] as $key => $file) {
    $moduleRoot = $resolver->require($key)->moduleRoots[$key];
    $sql .= (string) file_get_contents($moduleRoot . '/database/migrations/' . $file);
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
