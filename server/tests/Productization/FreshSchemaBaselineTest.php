<?php
declare(strict_types=1);

function freshSchemaExpect(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$serverRoot = dirname(__DIR__, 2);
$schema = (string)file_get_contents($serverRoot . '/database/init.sql');
$installer = (string)file_get_contents($serverRoot . '/database/install.php');
$installationHost = (string)file_get_contents(
    $serverRoot . '/app/common/services/installation/InstallationExecutionHost.php'
);
$migrationRunner = (string)file_get_contents(
    $serverRoot . '/app/common/services/upgrade/ApplicationMigrationRunner.php'
);
$upgradeEntry = (string)file_get_contents(dirname($serverRoot) . '/scripts/upgrade');
$guard = (string)file_get_contents($serverRoot . '/database/environment-guard.php');
preg_match_all('/CREATE\s+TABLE(?:\s+IF\s+NOT\s+EXISTS)?\s+`([^`]+)`/i', $schema, $matches);
$applicationTables = array_values(array_unique($matches[1] ?? []));

foreach ([
    'pa_legacy_admin_tenant_map',
    'pa_legacy_role_tenant_map',
    'pa_legacy_dept_tenant_map',
    'pa_default_tenant_bootstrap',
    'pa_admin',
    'pa_admin_role',
    'pa_admin_dept',
    'pa_admin_jobs',
    'pa_admin_session',
    'pa_system_role',
    'pa_system_role_menu',
    'pa_dept',
] as $retiredTable) {
    $identifier = '/(?<![A-Za-z0-9_])' . preg_quote($retiredTable, '/') . '(?![A-Za-z0-9_])/';
    freshSchemaExpect(!in_array($retiredTable, $applicationTables, true), "retired table remains in canonical schema: {$retiredTable}");
    freshSchemaExpect(preg_match($identifier, $installer) !== 1, "installer still depends on retired table: {$retiredTable}");
    freshSchemaExpect(preg_match($identifier, $guard) !== 1, "environment guard still depends on retired table: {$retiredTable}");
}

freshSchemaExpect(str_contains($installer, 'KernelSchema::tableNames()'), 'installer does not create native Core schema');
freshSchemaExpect(str_contains($installer, 'BootstrapService'), 'installer does not use the native Core bootstrap service');
freshSchemaExpect(str_contains($installer, "'default'"), 'installer does not create the formal default Tenant');
freshSchemaExpect(str_contains($installer, "'core.tenant-owner'"), 'installer health contract does not verify the native owner role');
freshSchemaExpect(!str_contains($installer, "'--migrate'"), 'fresh installer still exposes the retired upgrade mode');
freshSchemaExpect(
    str_contains($upgradeEntry, "['plan', 'apply', 'verify', 'recover']")
        && str_contains($migrationRunner, 'final class ApplicationMigrationRunner')
        && str_contains($upgradeEntry, 'peanut.product-upgrade-state.v1')
        && str_contains($upgradeEntry, "['prepare', 'backup', 'quiesce', 'switch', 'reload', 'health', 'recover']")
        && str_contains($upgradeEntry, 'PRODUCT_UPGRADE_ALREADY_RUNNING')
        && str_contains($upgradeEntry, "\$state['migration_started'] = \$migrationIds !== []")
        && str_contains($upgradeEntry, "\$state['phase'] = 'completed'"),
    'standalone application upgrade entry or shared migration runner is unavailable'
);
freshSchemaExpect(
    str_contains($installationHost, 'peanut.installation-baseline.v1')
        && str_contains($installationHost, "'kind' => 'product-source'")
        && str_contains($installationHost, "'kind' => 'generated-application'")
        && str_contains($installationHost, 'peanut.installation-receipt.v1'),
    'fresh installation does not create a source-independent baseline manifest and receipt'
);
freshSchemaExpect(
    str_contains($installer, 'applicationReleaseVersions($serverDir)')
        && str_contains($installer, "'source_product_version' => \$contract->sourceProductVersion()")
        && str_contains($installer, "'release_sequence_version' => \$contract->releaseSequenceVersion()")
        && str_contains($installer, "\$versions['release_sequence_version']")
        && str_contains($migrationRunner, "\$identity['peanut_release']")
        && str_contains($installationHost, '$this->migrationTargetVersion()'),
    'fresh install and migration selection do not preserve source, instance and scaffold version axes'
);
$migrations = glob($serverRoot . '/database/migrations/*.sql') ?: [];
// These migrations reached the shared ledger before the release marker became mandatory.
// Their raw bytes are immutable because the application ledger hashes the complete SQL file.
$immutableMissingReleaseIdentity = [
    '20260827-create-ops-backup-evidence.sql' => '33058d141518a42a481e10ee386804c37b98efc3a34ea6e60df977eda8221be2',
    '20260827-create-ops-restore-evidence.sql' => '22be5e7932f82d23ba43d90f437da97095a9407cc17faf94f9832e7f3f4efdca',
    '20260827-first-run-readiness.sql' => '83b410aa0715b313ad52179f92c60619472ef61ed989d9753104285b3aee09d7',
];
$observedImmutableMissingReleaseIdentity = [];
foreach ($migrations as $migration) {
    $migrationSql = (string)file_get_contents($migration);
    $migrationName = basename($migration);
    $hasReleaseIdentity = preg_match('/^--\s+peanut-release:\s+\d+\.\d+\.\d+\s*$/m', $migrationSql) === 1;
    if (!$hasReleaseIdentity && isset($immutableMissingReleaseIdentity[$migrationName])) {
        freshSchemaExpect(
            hash_equals($immutableMissingReleaseIdentity[$migrationName], hash('sha256', $migrationSql)),
            'immutable migration without release identity changed: ' . $migrationName
        );
        $observedImmutableMissingReleaseIdentity[$migrationName] = true;
        continue;
    }
    freshSchemaExpect(
        $hasReleaseIdentity,
        'post-baseline migration is missing a release identity: ' . $migrationName
    );
}
freshSchemaExpect(
    array_keys($observedImmutableMissingReleaseIdentity) === array_keys($immutableMissingReleaseIdentity),
    'immutable migration release identity exceptions are stale'
);

foreach (['information_schema', 'ALTER TABLE', 'PREPARE ', 'EXECUTE ', 'DEALLOCATE PREPARE'] as $transitionSql) {
    freshSchemaExpect(!str_contains($schema, $transitionSql), "transition SQL remains in canonical schema: {$transitionSql}");
}

freshSchemaExpect(in_array('pa_schema_migration', $applicationTables, true), 'application migration ledger is missing from the canonical schema');
foreach ([
    'pa_jobs',
    'pa_plugin_installation',
    'pa_module_migration',
    'pa_task_job',
    'pa_external_channel_binding',
    'pa_tenant_setting',
    'pa_tenant_entry_binding',
    'pa_tenant_owner_invitation',
    'pa_tenant_idempotency_record',
    'pa_system_dict_type',
    'pa_system_dict_data',
] as $table) {
    freshSchemaExpect(in_array($table, $applicationTables, true), "canonical business table missing: {$table}");
}
foreach ([
    'uk_tenant_setting_namespace',
    'uk_tenant_entry_binding',
    'uk_owner_invitation_pending_tenant',
    'uk_tenant_idempotency',
    'idx_refund_record_tenant_order_amount',
    'uk_system_dict_type_code',
    'uk_system_dict_data_type_value',
] as $index) {
    freshSchemaExpect(str_contains($schema, '`' . $index . '`'), "canonical schema index missing: {$index}");
}
foreach (['member_sex', 'member_status', 'member_channel', 'payment_status', 'refund_status'] as $dictionary) {
    freshSchemaExpect(str_contains($schema, "'{$dictionary}'"), "canonical system dictionary seed missing: {$dictionary}");
}

echo "FRESH-SCHEMA-BASELINE-001 passed\n";
