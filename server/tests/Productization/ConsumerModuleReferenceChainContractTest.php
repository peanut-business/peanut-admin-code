<?php

declare(strict_types=1);

function referenceChainContractExpect(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function referenceChainContractSection(string $source, string $start, string $end): string
{
    $offset = strpos($source, $start);
    $limit = $offset === false ? false : strpos($source, $end, $offset + strlen($start));
    if ($offset === false || $limit === false) {
        throw new RuntimeException('reference-chain section is unavailable: ' . $start);
    }
    return substr($source, $offset, $limit - $offset);
}

$root = dirname(__DIR__, 3);
$createApp = file_get_contents($root . '/scripts/create-app');
referenceChainContractExpect(is_string($createApp), 'public create-app source is unavailable');
referenceChainContractExpect(
    preg_match(
        '/new ApplicationCreator\(\s*\$root,\s*\$inventoryPath,\s*null,\s*\$adoption->path,/s',
        $createApp,
    ) === 1,
    'public create-app must derive generation source identity independently from the adoption manifest',
);

$path = $root . '/scripts/consumer-module-reference-chain';
$source = file_get_contents($path);
referenceChainContractExpect(is_string($source), 'reference-chain source is unavailable');

// CR21 now consumes one fixed Edition installer for A and B. Source generation
// is verified by that artifact's provenance, not a removed create_application helper.
$execution = referenceChainContractSection($source, 'def run_chain(', 'def parse_arguments(');
foreach ([
    'extract_installer(installer_package, installer_artifact, author)',
    'extract_installer(installer_package, installer_artifact, consumer)',
    'sha256(installer_package) != args.installer_sha256',
    'sha256(installer_manifest) != args.installer_manifest_sha256',
    'installer_artifact.get("protocol") != "peanut.edition-installer.v1"',
    'installer_artifact.get("source", {}).get("commit") != args.candidate',
    'installer_artifact.get("source", {}).get("tree") != candidate_tree',
    'installer_artifact.get("edition", {}).get("name") != args.edition',
    '("installer-archive-sha256", args.installer_sha256)',
    '("installer-manifest-sha256", args.installer_manifest_sha256)',
] as $required) {
    referenceChainContractExpect(
        str_contains($execution, $required),
        'fixed installer identity or shared A/B input is not checked: ' . $required,
    );
}
referenceChainContractExpect(
    !str_contains($execution, 'ApplicationCreator')
        && !str_contains($execution, 'source_builder')
        && !str_contains($execution, 'scripts/create-app'),
    'the fixed installer consumer retained a source-generation substitute',
);
$arguments = referenceChainContractSection($source, 'def parse_arguments(', 'def main(');
foreach (['--installer-package', '--installer-manifest', '--installer-sha256', '--installer-manifest-sha256'] as $option) {
    referenceChainContractExpect(
        str_contains($arguments, 'parser.add_argument("' . $option . '", required=True'),
        'a fixed installer input is not required: ' . $option,
    );
}

$helper = referenceChainContractSection($source, 'def lifecycle_helper()', 'def helper_action(');
foreach ([
    'PluginPackageInstaller',
    'PlatformModuleRuntimeService',
    'PluginRuntimeGovernanceService',
] as $forbidden) {
    referenceChainContractExpect(
        !str_contains($helper, $forbidden),
        'the read/service helper retained a privileged Package lifecycle bypass: ' . $forbidden,
    );
}
foreach ([
    'PlatformTenantAdminService::class',
    'RoleAdministrationRuntime::class',
    'Acme\\Modules\\ReferenceChain\\Contract\\ReferenceChainCommands::class',
] as $service) {
    referenceChainContractExpect(
        str_contains($helper, $service),
        'the reference chain does not exercise the formal service: ' . $service,
    );
}
referenceChainContractExpect(
    str_contains($source, 'AdminAuthorizationQuery')
        && str_contains($source, "decide(\$context, \$principal, 'acme.reference-chain.write')"),
    'the generated Module service does not enforce its formal RBAC permission',
);
referenceChainContractExpect(
    str_contains($source, 'ModuleQualificationQuery')
        && str_contains($source, "activeTenantModuleKeys(\$tenantId)")
        && str_contains($source, 'identity_manifest_path')
        && str_contains($source, 'identity_version')
        && str_contains($source, "'items' => \$items")
        && str_contains($source, "'has_more' => count(\$rows) > \$limit")
        && str_contains($source, "'next_cursor'")
        && !str_contains($source, "Db::name('tenant_module')"),
    'the generated Module service does not use the public Module qualification contract',
);
foreach ([
    '20260828010101_create_reference_chain.sql',
    'CREATE TABLE `pa_acme_reference_chain_record`',
    'FOREIGN KEY (`tenant_id`) REFERENCES `pa_tenant` (`id`)',
    '20260828010201_add_revision_note.sql',
    'ALTER TABLE `pa_acme_reference_chain_record` ADD COLUMN `revision_note`',
    'seeded.get("owned_rows") == 1 and seeded.get("migration_count") == 1',
    'len(seeded.get("service_read", {}).get("items", [])) == 1',
    'after_update.get("migration_count") == 2 and after_update.get("v2_column") is True',
    'len(after_update.get("service_read", {}).get("items", [])) == 1',
    'after_retire.get("owned_table") is True and after_retire.get("owned_rows") == 1',
    'after_purge.get("owned_table") is False and after_purge.get("migration_count") == 0',
] as $required) {
    referenceChainContractExpect(
        str_contains($source, $required),
        'the reference-chain migration or lifecycle protection is not locked: ' . $required,
    );
}

$lifecycle = referenceChainContractSection($source, 'def package_install(', 'def run_chain(');
foreach ([
    'module:install-package',
    'module:update-package',
    'module:disable-package',
    'module:uninstall-package',
] as $command) {
    referenceChainContractExpect(
        str_contains($lifecycle, $command),
        'the generated application public lifecycle command is missing: ' . $command,
    );
}
referenceChainContractExpect(
    !str_contains($lifecycle, 'helper_action(') && !str_contains($lifecycle, 'edition =='),
    'Package lifecycle still branches around the public Module CLI',
);
referenceChainContractExpect(
    str_contains($source, '"tenant_fixture_entry": "synthetic_sql_fixture_not_product_entry"')
        && str_contains($source, '"business_data_entry": "module_contract_service"'),
    'the summary does not distinguish fixture state from product/service acceptance',
);

// Execute the real archive extractor on owned positive/negative fixture archives.
$archiveProcess = proc_open(
    ['python3', $root . '/scripts/tests/consumer-module-installer-test.py'],
    [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
    $archivePipes,
    $root,
);
referenceChainContractExpect(is_resource($archiveProcess), 'installer archive runner is unavailable');
$archiveOutput = stream_get_contents($archivePipes[1]);
$archiveError = stream_get_contents($archivePipes[2]);
fclose($archivePipes[1]);
fclose($archivePipes[2]);
referenceChainContractExpect(
    proc_close($archiveProcess) === 0
        && str_contains((string) $archiveOutput, 'CONSUMER-INSTALLER-ARCHIVE-001 passed (9 cases)'),
    'fixed installer archive contract failed: ' . $archiveError,
);
echo $archiveOutput;

// 真实 Python 资源选择器的正反例；静态源码检查不能代替资源边界行为。
$resourceProcess = proc_open(
    ['python3', $root . '/scripts/tests/consumer-module-resource-test.py'],
    [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
    $resourcePipes,
    $root,
);
referenceChainContractExpect(is_resource($resourceProcess), 'resource contract runner is unavailable');
$resourceOutput = stream_get_contents($resourcePipes[1]);
$resourceError = stream_get_contents($resourcePipes[2]);
fclose($resourcePipes[1]);
fclose($resourcePipes[2]);
referenceChainContractExpect(
    proc_close($resourceProcess) === 0
    && str_contains((string) $resourceOutput, 'CONSUMER-RESOURCE-CONTRACT-001 passed (9 cases)'),
    'reference-chain resource contract failed: ' . $resourceError,
);
echo $resourceOutput;

// Run the actual generated PHP sample service against controlled fixtures.
$sampleProcess = proc_open(
    ['python3', $root . '/scripts/tests/consumer-module-sample-test.py'],
    [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
    $samplePipes,
    $root,
);
referenceChainContractExpect(is_resource($sampleProcess), 'sample service runner is unavailable');
$sampleOutput = stream_get_contents($samplePipes[1]);
$sampleError = stream_get_contents($samplePipes[2]);
fclose($samplePipes[1]);
fclose($samplePipes[2]);
referenceChainContractExpect(
    proc_close($sampleProcess) === 0
    && str_contains((string) $sampleOutput, 'CONSUMER-MODULE-SAMPLE-001 passed (2 cases)'),
    'reference-chain generated sample service contract failed: ' . $sampleError,
);
echo $sampleOutput;

echo "CONSUMER-MODULE-REFERENCE-CHAIN-CONTRACT-001 passed\n";
