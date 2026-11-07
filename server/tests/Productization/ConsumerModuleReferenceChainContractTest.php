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
    str_contains($createApp, "\$specifiedSourceIdentity = isset(\$options['source-commit'], \$options['source-tree'])")
        && str_contains($createApp, "['kind' => 'local', 'snapshot_sha256' => \$options['source-snapshot-sha256']]")
        && preg_match('/new ApplicationCreator\(\s*\$root,\s*\$inventoryPath,\s*\$specifiedSourceIdentity,\s*\$adoptionPath,/s', $createApp) === 1,
    'public create-app must derive generation source identity independently from the adoption manifest',
);

$path = $root . '/scripts/consumer-module-reference-chain';
$source = file_get_contents($path);
referenceChainContractExpect(is_string($source), 'reference-chain source is unavailable');

// The two installed APPs must be bound to the same native P0-E source and protected configuration.
$execution = referenceChainContractSection($source, 'def run_chain(', 'def parse_arguments(');
foreach ([
    'native["lifecycle_inputs"](args, candidate_tree)',
    'qualification.get("lifecycle_inputs") == inputs',
    'qualification.get("template_artifacts") == inputs["template_artifacts"]',
    'native["verify_lease"](native_args, qualification)',
    'author_app, consumer_app = apps["standalone"], apps["multi-tenant"]',
    'args.author_app != args.consumer_app',
    'native["verify_runtime_admission"](admission, qualification["owner"], qualification["thread"])',
    'status.get("state") == "installed"',
    'seed.stat().st_mode & 0o777 == 0o600',
    'owned_after == owned_before',
] as $required) {
    referenceChainContractExpect(str_contains($execution, $required), 'installed APP admission is missing: ' . $required);
}
foreach (['extract_installer(', 'scripts/create-app', 'ApplicationCreator', 'source_builder'] as $retired) {
    referenceChainContractExpect(!str_contains($execution, $retired), 'existing APP qualification replaced its source or installation');
}
$arguments = referenceChainContractSection($source, 'def parse_arguments(', 'def main(');
foreach (['--lifecycle-input', '--lifecycle-input-sha256', '--qualification-plan', '--author-app', '--consumer-app', '--identity-seed-file'] as $option) {
    referenceChainContractExpect(str_contains($arguments, 'parser.add_argument("' . $option . '", required=True'), 'fixed APP input missing: ' . $option);
}

$helper = referenceChainContractSection($source, 'def lifecycle_helper()', 'def helper_action(');
foreach ([
    'ModulePackageInstaller',
    'PlatformModuleRuntimeService',
    'ModuleRuntimeGovernanceService',
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
    'PlatformOperatorSessionService::class',
    'native_platform_login',
    'TenantAuthService::class',
    'TenantOwnerInvitationAdminService::class',
    'TenantOwnerInvitationPublicService::class',
] as $service) {
    referenceChainContractExpect(
        str_contains($helper, $service),
        'the reference chain does not exercise the formal service: ' . $service,
    );
}
foreach ([
    'PlatformContext::fromTrustedAutomation',
    'new PeanutAdmin\\Kernel\\Auth\\ValidatedTenantSession',
    "INSERT INTO pa_tenant(code,name,display_name,status",
] as $forbidden) {
    referenceChainContractExpect(
        !str_contains($helper, $forbidden),
        'the lifecycle helper retained a self-made identity or Tenant fixture: ' . $forbidden,
    );
}
foreach ([
    'issue-identities',
    'native_auth_services_existing_consumer',
    'native_invitation_accept_login',
    'CR21_IDENTITY_SEED',
    '_FILE_INVALID',
    'CR21_IDENTITY_CONTEXT_OUTPUT_REQUIRED',
    'CR21_IDENTITY_CONTEXT_REQUIRED',
    'CR21_IDENTITY_CONTEXT_FILE_INVALID',
    'CR21_IDENTITY_CONTEXT_TOKEN_MISSING',
    'CR21_IDENTITY_CONTEXT_TENANT_MISMATCH',
    'native_auth_service_access_tokens_from_private_file',
    'native_provision_accept_activate',
    'native_non_root_member_grant_revoke',
    'native_non_root_member_cross_tenant_denied',
    'native_non_root_member_grant_revoke_cross_tenant_denied',
    'CR21_REFERENCE_MEMBER_NOT_ORDINARY',
    'CR21_REFERENCE_MEMBER_GRANT_NOT_VISIBLE',
    'CR21_REFERENCE_MEMBER_REVOKE_NOT_VISIBLE',
    'CR21_REFERENCE_MEMBER_B_CROSSTENANT_AUTHORIZATION_LEAK',
    'REFERENCE_CHAIN_PERMISSION_DENIED',
] as $required) {
    referenceChainContractExpect(
        str_contains($source, $required),
        'the reference chain does not fail closed around native identity context: ' . $required,
    );
}
referenceChainContractExpect(
    !str_contains($source, '--identity-context-file')
        && str_contains($source, 'issued_identity = helper_action(')
        && str_contains($source, 'identity_seed_path.unlink(missing_ok=True)')
        && strpos($source, 'issued_identity = helper_action(') < strpos($source, 'install_v1 = package_install('),
    'the reference chain still requires a pre-existing B token or issues identity after package actions',
);
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
    '20260828010101_create_reference_chain.php',
    'CREATE TABLE `pa_acme_reference_chain_record`',
    'FOREIGN KEY (`tenant_id`) REFERENCES `pa_tenant` (`id`)',
    '20260828010201_add_revision_note.php',
    'ALTER TABLE `pa_acme_reference_chain_record` ADD COLUMN `revision_note`',
    'seeded.get("owned_rows") == 1 and seeded.get("migration_count") == 1',
    'len(seeded.get("service_read", {}).get("items", [])) == 1',
    'after_update.get("migration_count") == 2 and after_update.get("v2_column") is True',
    'len(after_update.get("service_read", {}).get("items", [])) == 1',
    'after_retire.get("owned_table") is True and after_retire.get("owned_rows") == 1',
    'after_purge.get("owned_table") is False and after_purge.get("migration_count") == 2',
] as $required) {
    referenceChainContractExpect(
        str_contains($source, $required),
        'the reference-chain migration or lifecycle protection is not locked: ' . $required,
    );
}

$lifecycle = referenceChainContractSection($source, 'def package_install(', 'def run_chain(');
foreach ([
    'run_module(consumer, env, "install"',
    'run_module(consumer, env, "upgrade"',
    'run_module(consumer, env, "disable"',
    'run_module(consumer, env, "uninstall"',
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
    str_contains($source, '"tenant_fixture_entry": "native_provision_accept_activate"')
        && str_contains($source, '"identity_context_entry": "native_auth_service_access_tokens_from_private_file"')
        && str_contains($source, '"member_authorization_entry": "native_non_root_member_grant_revoke_cross_tenant_denied"')
        && str_contains($source, '"business_data_entry": "module_contract_service"'),
    'the summary does not distinguish fixture state from product/service acceptance',
);
referenceChainContractExpect(
    !str_contains($source, 'tenant_owner_session_only_non_root_member_not_proven'),
    'the reference chain still reports ordinary non-root member authorization as unproven',
);

// Exercise the installed APP boundaries with synthetic files or SQLite, without touching a running APP.
foreach ([
    ['scripts/tests/consumer-module-installer-test.py', 'CONSUMER-INSTALLED-FILES-001 passed (4 tests)'],
    ['scripts/tests/consumer-module-resource-test.py', 'CONSUMER-RESOURCE-CONTRACT-001 passed (3 tests)'],
    ['scripts/tests/consumer-module-sample-test.py', 'CONSUMER-MODULE-SAMPLE-001 passed (2 cases)'],
    ['scripts/tests/consumer-module-identity-test.py', 'CONSUMER-IDENTITY-NATIVE-001 passed (23 checks)'],
    ['server/tests/Productization/ConsumerModuleMemberRuntimeTest.php', 'CONSUMER-MEMBER-RUNTIME-001 passed (7 checks)'],
] as [$fixture, $marker]) {
    $binary = str_ends_with($fixture, '.php') ? PHP_BINARY : 'python3';
    $process = proc_open([$binary, $root . '/' . $fixture], [1 => ['pipe', 'w'], 2 => ['redirect', 1]], $pipes, $root);
    referenceChainContractExpect(is_resource($process), 'fixture runner unavailable: ' . $fixture);
    $output = stream_get_contents($pipes[1]);
    fclose($pipes[1]);
    $exit = proc_close($process);
    referenceChainContractExpect($exit === 0 && str_contains((string) $output, $marker), $fixture . ' failed: ' . $output);
    echo $marker . "\n";
}

echo "CONSUMER-MODULE-REFERENCE-CHAIN-CONTRACT-001 passed\n";
