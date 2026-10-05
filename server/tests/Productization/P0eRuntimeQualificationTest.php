<?php

declare(strict_types=1);

$root = dirname(__DIR__, 3);
$runner = $root . '/scripts/p0e-runtime-qualification';
$fixturePath = $root . '/server/tests/fixtures/p0e-runtime-qualification/matrix.json';
$registryPath = getenv('PEANUT_RESOURCE_REGISTRY');
if (
    !is_string($registryPath)
    || $registryPath === ''
    || !str_starts_with($registryPath, '/')
    || !is_file($registryPath)
    || is_link($registryPath)
) {
    throw new RuntimeException('PEANUT_RESOURCE_REGISTRY must point to the explicit private maintainer registry');
}
$p0eRegistryPath = $root . '/resources/p0e-runtime-qualification.json';
$releaseMetadataPath = $root . '/RELEASE_METADATA.json';

$expect = static function (bool $condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
};
$run = static function (array $arguments) use ($runner): array {
    $command = escapeshellarg($runner);
    foreach ($arguments as $argument) {
        $command .= ' ' . escapeshellarg((string) $argument);
    }
    exec($command . ' 2>&1', $output, $code);
    return [$code, implode("\n", $output)];
};

$fixture = json_decode((string) file_get_contents($fixturePath), true, 512, JSON_THROW_ON_ERROR);
$registry = json_decode((string) file_get_contents($registryPath), true, 512, JSON_THROW_ON_ERROR);
$p0eRegistry = json_decode((string) file_get_contents($p0eRegistryPath), true, 512, JSON_THROW_ON_ERROR);
$releaseMetadata = json_decode((string) file_get_contents($releaseMetadataPath), true, 512, JSON_THROW_ON_ERROR);
$expect(
    ($releaseMetadata['schema_version'] ?? null) === 3
    && ($releaseMetadata['protocol'] ?? null) === 'peanut.release-metadata.v3',
    'release metadata contract changed',
);
$releaseVersion = (string) ($releaseMetadata['source_product_version'] ?? '');
$expect($releaseVersion !== '', 'release metadata source product version is unavailable');
$scaffoldManifestPath = $root . '/scaffold/releases/v' . $releaseVersion . '/scaffold-manifest.json';
$scaffoldManifestText = (string) file_get_contents($scaffoldManifestPath);
$scaffoldManifest = json_decode($scaffoldManifestText, true, 512, JSON_THROW_ON_ERROR);
$candidateInventorySha256 = hash_file('sha256', $root . '/scaffold/application-template-inventory.json');
$expect(is_string($candidateInventorySha256) && $candidateInventorySha256 !== '', 'candidate inventory SHA is unavailable');

$expectedScenarios = [
    'standalone_fresh',
    'multi_tenant_fresh',
    'plugin_lifecycle',
    'consumer_module_cycle',
    'standalone_browser',
    'multi_tenant_browser',
];
$expectedGroups = [
    'generated-application',
    'standalone-fresh',
    'multi-tenant-fresh',
    'plugin-lifecycle',
    'consumer-module-lifecycle',
    'production-compose',
    'standalone-browser',
    'multi-tenant-browser',
];
$expectedTarget = [
    'version' => $releaseVersion,
    'source_commit' => $scaffoldManifest['release']['source_commit'] ?? null,
    'source_tree' => $scaffoldManifest['release']['source_tree'] ?? null,
    'manifest_sha256' => hash('sha256', $scaffoldManifestText),
    'inventory_sha256' => $scaffoldManifest['release']['inventory_sha256'] ?? null,
    'managed_tree_sha256' => $scaffoldManifest['release']['managed_tree_sha256'] ?? null,
    'file_count' => count($scaffoldManifest['files'] ?? []),
    'application_manifest_schema' => 2,
    'default_application_version' => $scaffoldManifest['application']['version'] ?? null,
    'default_uniapp_version_code' => '10',
];

$expect(($fixture['schema_version'] ?? null) === 1, 'P0-E fixture schema changed');
$expect(($fixture['gate'] ?? null) === 'p0e-runtime-qualification', 'P0-E Gate identity changed');
$expect(!array_key_exists('migration_count', $fixture['database_resource'] ?? []), 'P0-E Gate retained an application migration count');
$expect(!array_key_exists('ledger_count', $fixture['database_resource'] ?? []), 'P0-E Gate retained an application migration ledger count');
$expect(!array_key_exists('baselines', $fixture), 'fresh-only P0-E fixture retained 1.x baselines');
$expect(!array_key_exists('legacy_application', $fixture), 'fresh-only P0-E fixture retained a legacy application');
$expect(($fixture['target_release'] ?? null) === $expectedTarget, 'P0-E target scaffold identity changed');
$expect(array_keys($fixture['scenarios'] ?? []) === $expectedScenarios, 'P0-E fresh-only scenario order or closure changed');
$expect(($fixture['groups'] ?? null) === $expectedGroups, 'P0-E fresh-only group order or closure changed');

$expect(!array_key_exists('migrations', $releaseMetadata), 'release metadata retained the retired application migration identity');

$registered = array_values(array_filter(
    $registry['resources']['databases'] ?? [],
    static fn(array $item): bool => ($item['stable_resource_id'] ?? null) === 'peanut-admin-p0e-mysql84-gate',
));
$expect(count($registered) === 1, 'P0-E resource registration is not unique');
$expect(($registered[0]['application_runtime'] ?? null) === false, 'P0-E resource became a default runtime');
$expect(($registered[0]['fallback'] ?? null) === 'none', 'P0-E resource must fail closed');
$expect(($registered[0]['allowed_scenarios'] ?? null) === $expectedScenarios, 'runner and project resource scenarios diverged');

$binding = $p0eRegistry['database_administration_binding'] ?? null;
$expect(is_array($binding), 'P0-E remote administration binding is missing');
$expect(($binding['database_resource_id'] ?? null) === 'peanut-admin-p0e-mysql84-gate', 'P0-E database resource is not fixed');
$expect(($binding['runtime_resource_id'] ?? null) === ($registered[0]['runtime_resource_id'] ?? null), 'P0-E runtime resource diverged');
$expect(($binding['credential_ref'] ?? null) === ($registered[0]['credential_ref'] ?? null), 'P0-E binding credential provenance diverged');
$expect(str_contains((string) ($binding['failure_policy'] ?? ''), 'never stops or restarts Docker Desktop'), 'P0-E binding failure policy may escalate into Docker Desktop recovery');
$browserHosts = $p0eRegistry['browser_host_binding'] ?? null;
$expect(is_array($browserHosts), 'P0-E browser Host binding is missing');
$expect(($browserHosts['platform_host'] ?? null) === 'platform.p0e.localhost', 'P0-E Platform browser Host changed');
$expect(($browserHosts['tenant_admin_host'] ?? null) === 'admin.p0e.localhost', 'P0-E Tenant Admin browser Host changed');
$expect(($browserHosts['tenant_beta_host'] ?? null) === 'beta.p0e.localhost', 'P0-E Tenant Beta browser Host changed');
$expect(($browserHosts['port'] ?? null) === 20190, 'P0-E browser Host port changed');
$expect(($browserHosts['fallback'] ?? null) === 'none', 'P0-E browser Host binding must fail closed');
$tooling = $p0eRegistry['resources']['tooling'][0] ?? null;
$expect(is_array($tooling), 'P0-E remote administration tooling is missing');
$expect(($tooling['mysql_command'] ?? null) === '/usr/bin/mysql', 'P0-E MySQL CLI path changed');
$expect(($tooling['credential_ref'] ?? null) === ($binding['credential_ref'] ?? null), 'P0-E tooling credential provenance diverged');
$expect(($tooling['failure_policy'] ?? null) === ($binding['failure_policy'] ?? null), 'P0-E tooling failure policy diverged');
$expect(!array_key_exists('mysqldump_command', $tooling), 'fresh-only P0-E retained backup tooling');
$expect(($tooling['fallback'] ?? null) === 'none; host mysql commands are forbidden', 'P0-E tooling fallback changed');
$browserTooling = array_values(array_filter(
    $p0eRegistry['resources']['tooling'] ?? [],
    static fn(array $item): bool => ($item['stable_resource_id'] ?? '') === 'peanut-admin-p0e-playwright-cli',
));
$expect(count($browserTooling) === 1, 'P0-E fixed Playwright tooling registration is missing');
$expect(($browserTooling[0]['package'] ?? null) === '@playwright/cli', 'P0-E Playwright package changed');
$expect(($browserTooling[0]['version'] ?? null) === '0.1.18', 'P0-E Playwright version is not pinned');
$expect(($browserTooling[0]['relative_path'] ?? null) === '.local/p0e-browser-cli-0.1.18/playwright-cli', 'P0-E Playwright path is not fixed');
$expect(($browserTooling[0]['fallback'] ?? null) === 'none', 'P0-E Playwright tooling must fail closed');
$databaseTunnel = array_values(array_filter(
    $p0eRegistry['resources']['tooling'] ?? [],
    static fn(array $item): bool => ($item['stable_resource_id'] ?? '') === 'peanut-admin-p0e-mysql84-container-tunnel',
));
$expect(count($databaseTunnel) === 1, 'P0-E database tunnel registration is missing');
$expect(($databaseTunnel[0]['transport'] ?? null) === 'ssh-local-forward', 'P0-E database tunnel transport changed');
$expect(($databaseTunnel[0]['local_host'] ?? null) === '127.0.0.1', 'P0-E database tunnel must remain loopback-bound');
$expect(($databaseTunnel[0]['local_port'] ?? null) === 20189, 'P0-E database tunnel port changed');
$expect(($databaseTunnel[0]['container_host'] ?? null) === 'host.docker.internal', 'P0-E database tunnel container Host changed');
$expect(($databaseTunnel[0]['fallback'] ?? null) === 'none', 'P0-E database tunnel must fail closed');

$candidate = trim((string) shell_exec('git -C ' . escapeshellarg($root) . ' rev-parse HEAD'));
$runId = 'p0e' . bin2hex(random_bytes(4));
$outputPath = $root . '/output/p0e-' . $runId;
$cachePath = rtrim((string) getenv('HOME'), '/') . '/.cache/peanut-admin/p0e-' . $runId;
$arguments = [
    'plan',
    '--candidate', $candidate,
    '--run-id', $runId,
    '--lease', 'p0e-runtime-' . $runId,
    '--http-port', '20190',
    '--docs-port', '20186',
    '--output-dir', $outputPath,
    '--cache-dir', $cachePath,
];
[$code, $output] = $run($arguments);
$expect($code === 0, "P0-E no-resource plan failed: {$output}");
$plan = json_decode($output, true, 512, JSON_THROW_ON_ERROR);
$expect(($plan['candidate'] ?? null) === $candidate, 'plan candidate is not exact HEAD');
$expect(($plan['candidate_inventory_sha256'] ?? null) === $candidateInventorySha256, 'plan did not bind the current candidate inventory');
$expect(($plan['resource_id'] ?? null) === 'peanut-admin-p0e-mysql84-gate', 'plan resource identity changed');
$expect(($plan['environment'] ?? null) === 'development', 'plan environment changed');
$expect(($plan['endpoint'] ?? null) === 'host.docker.internal:20189', 'plan container endpoint changed');
$expect(($plan['host_endpoint'] ?? null) === '192.168.192.2:20183', 'plan Host endpoint changed');
$expect(($plan['database_tunnel']['stable_resource_id'] ?? null) === 'peanut-admin-p0e-mysql84-container-tunnel', 'plan tunnel identity changed');
$expect(($plan['database_admin_tooling']['credential_ref'] ?? null) === ($registered[0]['credential_ref'] ?? null), 'plan lost registered DB root credential provenance');
$expect(($plan['database_admin_tooling']['failure_policy'] ?? null) === ($binding['failure_policy'] ?? null), 'plan lost fail-closed remote administration policy');
$expect(($plan['target_release'] ?? null) === $expectedTarget, 'plan did not bind the 3.0 scaffold release');
$expect(($plan['groups'] ?? null) === $expectedGroups, 'plan did not bind the fresh-only closure');
$expect(array_keys($plan['group_inputs'] ?? []) === $expectedGroups, 'plan did not preflight every selected group source input');
foreach ($plan['group_inputs'] as $group => $inputs) {
    $expect(($inputs['source_files'] ?? []) !== [] && count($inputs['runtime_value_names'] ?? []) === 13,
        "group {$group} lost its explicit source/runtime-value preflight contract");
}
$nativeRuntime = array_values(array_filter(
    $registry['resources']['tooling'] ?? [],
    static fn(array $item): bool => ($item['stable_resource_id'] ?? '') === 'peanut-admin-phase1-image-preparation',
));
$expect(count($nativeRuntime) === 1, 'native server runtime registration is not unique');
$nativeScenarios = [
    'production-compose' => ['standalone_browser', 'multi_tenant_fresh'],
    'standalone-browser' => ['standalone_browser'],
    'multi-tenant-browser' => ['multi_tenant_browser'],
];
$nativeFiles = [
    'server/docker/compose.yaml',
    'server/docker/runtime-configuration.example.json',
    'server/docker/scripts/configure-runtime.py',
    'server/docker/scripts/start.sh',
    'server/docker/scripts/php-entrypoint.sh',
    'server/docker/scripts/vendor-state.py',
    'server/docker/scripts/prepare-vendor.sh',
    'server/docker/scripts/prepare-vendor.php',
    'server/docker/scripts/update-plan.php',
    'server/database/install.php',
    'server/database/environment-guard.php',
    'server/tests/fixtures/mt05/inspect.php',
    'scripts/build-edition-installers',
    'scripts/package-release-files.py',
];
foreach ($nativeScenarios as $group => $scenarios) {
    $contract = $plan['group_inputs'][$group]['runtime_contract'] ?? [];
    $expect($contract === [
        'shape' => 'native-server-only',
        'php_runtime_resource' => $nativeRuntime[0]['stable_resource_id'],
        'built_output' => $nativeRuntime[0]['built_output'],
        'input_images' => $nativeRuntime[0]['input_images'],
        'installation_consumer' => 'host',
        'runtime_consumer' => 'container',
        'deployment_target' => 'local-production-preview',
        'scenarios' => $scenarios,
    ], "{$group} native runtime identity or consumer contract diverged");
    foreach ($nativeFiles as $relative) {
        $expect(($plan['group_inputs'][$group]['source_files'][$relative] ?? null) === hash_file('sha256', $root . '/' . $relative),
            "{$group} did not bind native input {$relative}");
    }
}
$documents = $plan['group_inputs']['generated-application']['documentation'] ?? [];
$expect(($documents['delivery'] ?? null) === 'versioned-markdown-and-openapi'
    && ($documents['api_version'] ?? null) === $releaseVersion
    && count($documents['files'] ?? []) === 7
    && count($documents['browser_documents'] ?? []) === 5,
    'plan did not bind the actual public Markdown and API documents');
$expect(($plan['full_gate_groups'] ?? null) === $expectedGroups, 'plan lost the full P0-E group definition');
$expect(($plan['through_group'] ?? null) === null, 'full plan unexpectedly became a bounded run');
$expect(!array_key_exists('legacy_application', $plan), 'plan retained a legacy application');
$expect(!array_key_exists('backup-dir', $plan['paths'] ?? []), 'plan retained a recovery backup path');
$expect(!file_exists($outputPath) && !file_exists($cachePath), 'no-resource plan created a path');

$resourceCounts = [];
$resourceValues = [];
foreach ($plan['lease_resources'] ?? [] as $resource) {
    $type = (string) ($resource['type'] ?? '');
    $resourceCounts[$type] = ($resourceCounts[$type] ?? 0) + 1;
    $resourceValues[$type][] = (string) ($resource['value'] ?? '');
}
$expect(count($plan['lease_resources'] ?? []) === 43, 'manual lease resources must have 43 exact rows');
$expect(($resourceCounts['mysql-db'] ?? null) === 6, 'claim must bind six exact fresh-only databases');
$expect(($resourceValues['database'] ?? null) === ($resourceValues['mysql-db'] ?? null), 'claim database locks diverge from exact MySQL namespaces');
$expect(($resourceValues['registry-sha256'] ?? null) === [hash_file('sha256', $registryPath)], 'claim did not bind the actual private registry bytes');
$expect(($resourceValues['resource-scope'] ?? null) === [str_replace('<run_id>', $runId, $registered[0]['namespace'])], 'claim lost its registered database scope');
$expect(($resourceValues['qualification-group'] ?? null) === ['multi-tenant-browser'], 'full claim lost its exact qualification cutoff');
$expect(($resourceCounts['deployment-mode'] ?? null) === 2, 'claim must bind both deployment modes');
$expect(($resourceCounts['port'] ?? null) === 4, 'claim must bind database, HTTP, tunnel and docs port conflicts');
$expect(($resourceCounts['database-tunnel'] ?? null) === 1, 'claim must bind the database tunnel');
$expect(($resourceCounts['browser-host'] ?? null) === 3, 'claim must bind all three separate browser Host boundaries');
$expect(($resourceValues['browser-host'] ?? null) === ['admin.p0e.localhost', 'beta.p0e.localhost', 'platform.p0e.localhost'], 'claim lost an exact browser Host');
$expect(($resourceCounts['endpoint'] ?? null) === 4, 'claim must bind registered endpoint IDs and exact Host/container endpoint values');

$boundedArguments = $arguments;
$boundedArguments[] = '--through-group';
$boundedArguments[] = 'multi-tenant-fresh';
[$boundedCode, $boundedOutput] = $run($boundedArguments);
$expect($boundedCode === 0, "P0-E bounded no-resource plan failed: {$boundedOutput}");
$boundedPlan = json_decode($boundedOutput, true, 512, JSON_THROW_ON_ERROR);
$expect(
    ($boundedPlan['groups'] ?? null) === array_slice($expectedGroups, 0, 3),
    'bounded P0-E plan did not select the exact dependency-preserving prefix',
);
$expect(($boundedPlan['full_gate_groups'] ?? null) === $expectedGroups, 'bounded plan changed the full Gate definition');
$expect(($boundedPlan['through_group'] ?? null) === 'multi-tenant-fresh', 'bounded plan lost its exact cutoff group');
$boundedResourceCounts = [];
$boundedResourceValues = [];
foreach ($boundedPlan['lease_resources'] ?? [] as $resource) {
    $type = (string) ($resource['type'] ?? '');
    $boundedResourceCounts[$type] = ($boundedResourceCounts[$type] ?? 0) + 1;
    $boundedResourceValues[$type][] = (string) ($resource['value'] ?? '');
}
$expect(($boundedResourceCounts['mysql-db'] ?? null) === 2, 'bounded plan must reserve only the two fresh databases');
$expect(($boundedResourceValues['qualification-group'] ?? null) === ['multi-tenant-fresh'], 'bounded plan lost its exact qualification cutoff');
$expect(!array_key_exists('browser-host', $boundedResourceCounts), 'bounded plan unexpectedly reserved browser Hosts');
$expect(!array_key_exists('browser-session', $boundedResourceCounts), 'bounded plan unexpectedly reserved a browser session');
$expect(($boundedResourceValues['port'] ?? null) === ['20183'], 'bounded fresh-only plan must bind only its registered MySQL port');
$expect(($boundedPlan['listener_ports'] ?? null) === [], 'bounded fresh-only plan retained listener port dependencies');
$expect(($boundedPlan['compose_required'] ?? null) === false, 'bounded fresh-only plan retained Compose execution');
$expect(($boundedPlan['browser_required'] ?? null) === false, 'bounded fresh-only plan retained browser execution');

$selectedArguments = $arguments;
$selectedArguments[] = '--groups';
$selectedArguments[] = 'generated-application,standalone-fresh,multi-tenant-fresh,production-compose,standalone-browser';
[$selectedCode, $selectedOutput] = $run($selectedArguments);
$expect($selectedCode === 0, "P0-E selected-group no-resource plan failed: {$selectedOutput}");
$selectedPlan = json_decode($selectedOutput, true, 512, JSON_THROW_ON_ERROR);
$expectedPrereleaseGroups = [
    'generated-application',
    'standalone-fresh',
    'multi-tenant-fresh',
    'production-compose',
    'standalone-browser',
];
$expect(($selectedPlan['groups'] ?? null) === $expectedPrereleaseGroups, 'selected P0-E plan did not preserve the exact canonical group set');
$expect(($selectedPlan['selection_scope'] ?? null) === 'selected-groups', 'selected P0-E plan lost its selection scope');
$expect(($selectedPlan['through_group'] ?? null) === null, 'selected P0-E plan unexpectedly retained a prefix cutoff');
$selectedQualificationResources = array_values(array_map(
    static fn (array $resource): string => (string) ($resource['value'] ?? ''),
    array_filter(
        $selectedPlan['lease_resources'] ?? [],
        static fn (array $resource): bool => ($resource['type'] ?? null) === 'qualification-group',
    ),
));
$expect($selectedQualificationResources === ['standalone-browser'], 'selected P0-E lease did not bind its canonical resource cutoff');
$expect(($selectedPlan['compose_required'] ?? null) === true, 'selected prerelease plan lost Compose execution');
$expect(($selectedPlan['browser_required'] ?? null) === true, 'selected prerelease plan lost browser execution');
foreach (['production-compose', 'standalone-browser'] as $group) {
    $expect(($selectedPlan['group_inputs'][$group] ?? null) === $plan['group_inputs'][$group],
        "{$group} changed native inputs or runtime shape with Gate selection");
}

$invalidSelectedArguments = $arguments;
$invalidSelectedArguments[] = '--groups';
$invalidSelectedArguments[] = 'generated-application,standalone-browser';
[$invalidSelectedCode, $invalidSelectedOutput] = $run($invalidSelectedArguments);
$expect($invalidSelectedCode !== 0, 'selected P0-E accepted standalone-browser without production-compose');
$expect(str_contains($invalidSelectedOutput, 'standalone-browser requires production-compose'), 'selected P0-E dependency refusal changed');

$runnerSource = (string) file_get_contents($runner);
$unsupportedRunnerFragments = [
    'ensure_legacy_source',
    'create_legacy_application',
    'legacy_application_upgrade',
    'legacy_application_recovery',
    'def forward(',
    'migration_fault_restore',
    '--adopt-existing',
    'SCAFFOLD_UPGRADE',
    'upgraded_plugin_lifecycle',
    'upgraded_production_compose',
    'upgraded_browser',
    'mysqldump',
    'remote_dump',
    'remote_restore',
];
foreach ($unsupportedRunnerFragments as $fragment) {
    $expect(!str_contains($runnerSource, $fragment), "fresh-only runner retained unsupported code: {$fragment}");
}
$runStart = strpos($runnerSource, '    def run(self) -> None:');
$runEnd = strpos($runnerSource, '    def preserve_failure', $runStart === false ? 0 : $runStart);
$expect($runStart !== false && $runEnd !== false, 'runner group closure is unavailable');
$runClosure = substr($runnerSource, $runStart, $runEnd - $runStart);
foreach ($expectedGroups as $group) {
    $expect(str_contains($runClosure, 'run_group("' . $group . '"'), "runner omitted group {$group}");
}
$expect(!str_contains($runClosure, 'forward') && !str_contains($runClosure, 'legacy') && !str_contains($runClosure, 'recovery'), 'runner closure retained a legacy qualification group');
$expect(
    str_contains($runnerSource, '"php", "scripts/build-edition-installers"')
    && str_contains($runnerSource, 'self.generated["multi-tenant"]')
    && str_contains($runnerSource, 'artifact_manifest.get("application", {}).get("managed_tree_sha256") != manifest.get("digests", {}).get("managed_tree_sha256")')
    && str_contains($runnerSource, 'plugin_lock_restored_sha256'),
    'formal Edition installer qualification lost its projected application identity or Plugin lifecycle',
);
$expect(str_contains($runnerSource, 'consumer-module-reference-chain'), 'consumer Module lifecycle does not use the independent application driver');
$expect(!str_contains($runnerSource, '--formal-release-adoption'), 'consumer Module lifecycle retained an exited CLI option');
$consumerSource = (string) file_get_contents($root . '/scripts/consumer-module-reference-chain');
foreach (['sodium_crypto_sign_keypair', 'author-signing-key.base64', 'PEANUT_MODULE_TRUSTED_KEYS_JSON', '--signing-key-id', '--signing-secret-key-file', '--signature-key-id', 'signed_pack_v1_v2'] as $exitedInput) {
    $expect(!str_contains($consumerSource, $exitedInput), "consumer fixture retained mandatory signing input {$exitedInput}");
}
$expect(str_contains($consumerSource, '"canonical_pack_v1_v2": "passed"'), 'consumer package evidence does not describe canonical integrity');
foreach (['--database-resource', '--endpoint', '--author-database', '--installer-package', '--installer-manifest', '--installer-sha256', '--installer-manifest-sha256', '--qualification-plan'] as $option) {
    $expect(str_contains($runnerSource, $option) && str_contains($consumerSource, $option), "consumer CLI contract omitted {$option}");
}
$expect(str_contains($runnerSource, '"consumer-module-lifecycle": ["consumer_module_cycle", "multi_tenant_fresh"]'), 'consumer retry does not clean both owned application databases');
foreach (['installer-archive', 'installer-archive-sha256', 'installer-manifest', 'installer-manifest-sha256'] as $resource) {
    $expect(str_contains($consumerSource, '("' . $resource . '",'), "independent consumer lost direct lease protection {$resource}");
}
$expect(str_contains($runnerSource, 'consumer_module_cycle'), 'consumer Module lifecycle does not own a length-safe isolated database scenario');
$expect(str_contains($runnerSource, 'passed != required'), 'Gate completion closure is not enforced');
$expect(str_contains($runnerSource, 'required = set(self.plan["groups"])'), 'runner does not close against the explicitly planned group prefix');
$expect(str_contains($runnerSource, '"partial-passed"'), 'bounded qualification is not distinguished from a full Gate pass');
$expect(str_contains($runnerSource, '"selected-groups"'), 'runner does not distinguish selected-group qualification from prefix qualification');
$expect(str_contains($runnerSource, 'preflight_database_admin_tooling'), 'remote database administration does not fail fast');
$expect(str_contains($runnerSource, 'self.preflight_group_environments()')
    && strpos($runnerSource, 'self.preflight_group_environments()') < strpos($runnerSource, 'self.run_group("generated-application"')
    && str_contains($runnerSource, 'guardedDatabaseConfig($argv[1])')
    && str_contains($runnerSource, 'container_runtime=consumer == "container"'),
    'actual PHP resource guard does not preflight all groups before generated builds');
$expect(str_contains($runnerSource, 'self.prepare_registry_proof()') && str_contains($runnerSource, 'self.prepare_registry_proof(resume=True)')
    && str_contains($runnerSource, 'path.read_bytes() != raw'), 'native lease proof lost its fixed original registry bytes');
$expect(str_contains($runnerSource, 'if "generated-application" in self.plan["groups"]:')
    && str_contains($runnerSource, 'shutil.copytree(self.edition_artifacts, preserved)')
    && str_contains($runnerSource, 'preserved edition artifact bytes differ from qualified originals'),
    'full qualification cleanup does not retain its original generated artifacts');
$expect(str_contains($runnerSource, 'registered database credential is missing or ambiguous: MYSQL_ROOT_PASSWORD'), 'remote administration does not fail closed on a missing registered root credential');
$expect(str_contains($runnerSource, '--defaults-extra-file="$path"'), 'remote administration does not use a container-private MySQL option file');
$expect(str_contains($runnerSource, "trap 'rm -f -- ") && str_contains($runnerSource, 'EXIT HUP INT TERM'), 'remote administration does not clean its container-private credential file');
$expect(!str_contains($runnerSource, 'MYSQL_PWD="$MYSQL_ROOT_PASSWORD"'), 'remote administration still depends on the container MYSQL_ROOT_PASSWORD environment');
$expect(!str_contains($runnerSource, 'docker desktop restart') && !str_contains($runnerSource, 'docker desktop stop'), 'P0-E runner contains Docker Desktop recovery escalation');
$expect(str_contains($runnerSource, 'start_database_tunnel'), 'container database tunnel is not lifecycle-managed');
$expect(str_contains($runnerSource, 'stop_database_tunnel'), 'container database tunnel cleanup is missing');
$expect(str_contains($runnerSource, 'preflight_browser_tooling'), 'browser tooling does not fail before resource claim');
$expect(str_contains($runnerSource, 'preflight_group_inputs(selected_groups)')
    && !str_contains($runnerSource, 'docs-site') && !str_contains($runnerSource, 'vitepress')
    && str_contains($runnerSource, '"-m", "http.server"')
    && str_contains($runnerSource, 'documentation_contract(generated, generated=True)'),
    'qualification retained an exited document site instead of authenticating released public documents');
$expect(str_contains($runnerSource, 'registered_browser_cli_path'), 'browser tooling does not use the fixed registered path');
$expect(!str_contains($runnerSource, 'pwcli-cache-*'), 'browser tooling retained temporary cache glob discovery');
$expect(str_contains($runnerSource, 'prepare_database_credentials()'), 'P0-E runner does not synchronize the registered database credential source');
$expect(str_contains($runnerSource, 'runtime-credentials.env'), 'P0-E runner does not retain per-run browser credentials for resume');
$expect(str_contains($runnerSource, 'resources != expected_resources'), 'lease verification is not an exact-set comparison');
$expect(str_contains($runnerSource, '"candidate_repository": plan_data["worktree"]'), 'lease verification lost the candidate repository boundary');
$expect(str_contains($runnerSource, 'input_text=self.native_compose_overlay()')
    && str_contains($runnerSource, 'self.compose_overlay.write_text(self.native_compose_overlay())'),
    'native Preflight and startup do not consume the same lease-proof overlay');
$expect(str_contains($runnerSource, 'PERSISTENT_DATABASE'), 'persistent database refusal is missing');
$expect(
    str_contains($runnerSource, '["php", "server/database/environment-guard.php", "--current"]'),
    'P0-E install does not qualify the complete fresh schema through the environment guard',
);
$expect(!str_contains($runnerSource, 'server/database/migrate.php'), 'P0-E runner retained the application migration runner');

$pluginFixture = (string) file_get_contents($root . '/server/fixtures/plugin-module-lifecycle/run.php');
$expect(str_contains($pluginFixture, "upgrade('fixture.delivery-record', true)"), 'Plugin upgrade dry-run capability left the Gate fixture');
$expect(str_contains($pluginFixture, "rollbackPlan('fixture.delivery-record')"), 'Plugin rollback-plan capability left the Gate fixture');
$expect(str_contains($pluginFixture, "uninstall('fixture.delivery-record')"), 'Plugin preserve-data uninstall capability left the Gate fixture');

$browserFixture = (string) file_get_contents($root . '/server/tests/fixtures/p0e-runtime-qualification/browser-smoke.js');
$expect(str_contains($browserFixture, "loginTenant(page, tenantAdminUrl, adminEmail, adminPassword, 'admin')"), 'baseline must use the real Tenant email login in both deployment modes');
$expect(str_contains($browserFixture, 'P0E_BROWSER_TENANT_ADMIN_URL') && str_contains($browserFixture, 'P0E_BROWSER_PLATFORM_URL'), 'browser smoke must use separate Tenant Admin and Platform Hostnames');
$expect(str_contains($browserFixture, '${platformUrl}/platform/'), 'browser smoke must enter the standalone Platform frontend');
$expect(str_contains($browserFixture, "getByText('概览', { exact: true }).first()"), 'browser smoke must wait for the visible Platform overview label');
$expect(str_contains($browserFixture, "form.locator('.el-select').waitFor"), 'multi-tenant browser smoke must not mistake the navbar selector for the login selector');
$expect(str_contains($browserFixture, ".el-select-dropdown:visible .el-select-dropdown__item').first().click()"), 'multi-tenant browser smoke must select a tenant before its second login submission');

$nativeProofCode = <<<'PY'
import hashlib, pathlib, runpy, tempfile, types
namespace = runpy.run_path("scripts/p0e-runtime-qualification")
Runner = namespace["Runner"]
registry = namespace["REGISTRY_PATH"]
with tempfile.TemporaryDirectory(prefix="p0e-registry-proof-", dir=".local/tmp") as directory:
    runner = Runner.__new__(Runner)
    runner.plan = {"lease_proof_dir": directory, "registry_sha256": hashlib.sha256(registry.read_bytes()).hexdigest()}
    proof = pathlib.Path(directory) / "registry.json"
    runner.prepare_registry_proof()
    assert proof.read_bytes() == registry.read_bytes()
    runner.prepare_registry_proof(resume=True)
    def reject(operation):
        try:
            operation()
        except (namespace["GateError"], FileExistsError):
            return
        raise AssertionError("native registry proof accepted invalid input")
    reject(lambda: runner.prepare_registry_proof())
    proof.write_bytes(proof.read_bytes() + b"\n")
    reject(lambda: runner.prepare_registry_proof(resume=True))
    proof.unlink()
    reject(lambda: runner.prepare_registry_proof(resume=True))
    proof.symlink_to(registry)
    reject(lambda: runner.prepare_registry_proof(resume=True))
    proof.unlink()
    runner.plan["registry_sha256"] = "f" * 64
    reject(lambda: runner.prepare_registry_proof())
    assert not proof.exists()
    runner.plan["lease_proof_container_path"] = "/run/peanut-admin/resource-lease"
    runner.args = types.SimpleNamespace(parent_lease="")
    overlay = runner.native_compose_overlay()
    assert f"      - {directory}:/run/peanut-admin/resource-lease:ro\n" in overlay
    assert "depends_on: !reset {}" in overlay and "profiles: [bundled-db]" in overlay
    assert "resource-lease-parent" not in overlay
    with tempfile.TemporaryDirectory(prefix="p0e-parent-proof-", dir=pathlib.Path(directory).parent) as parent:
        runner.args.parent_lease = pathlib.Path(parent).name
        overlay = runner.native_compose_overlay()
        assert f"      - {directory}:/run/peanut-admin/resource-lease:ro\n" in overlay
        assert f"      - {parent}:/run/peanut-admin/resource-lease-parent:ro\n" in overlay
        assert overlay.count(":ro\n") == 2
    reject(lambda: runner.native_compose_overlay())
print("native registry proof export passed; cases=7")
print("native shared overlay contract passed; cases=3")
PY;
$nativeOutput = [];
$nativeCode = 0;
exec('python3 -c ' . escapeshellarg($nativeProofCode) . ' 2>&1', $nativeOutput, $nativeCode);
$expect($nativeCode === 0, 'native registry proof export failed: ' . implode("\n", $nativeOutput));
echo implode("\n", $nativeOutput), "\n";

$consumerContractCode = <<<'PY'
import argparse, ast, copy, json, os, runpy, shlex, shutil, subprocess, tempfile, textwrap
from pathlib import Path
from unittest.mock import patch

root = Path.cwd()
consumer_path = root / 'scripts/consumer-module-reference-chain'
consumer = runpy.run_path(str(consumer_path))
tree = ast.parse(consumer_path.read_text())
function = next(n for n in tree.body if isinstance(n, ast.FunctionDef) and n.name == 'parse_arguments')
body = []
for statement in function.body:
    if isinstance(statement, ast.Assign) and any(isinstance(t, ast.Name) and t.id == 'args' for t in statement.targets): break
    body.append(statement)
namespace = {'argparse': argparse, '__doc__': 'actual consumer parser'}
exec(compile(ast.fix_missing_locations(ast.Module(body=body, type_ignores=[])), str(consumer_path), 'exec'), namespace)
parser = namespace['parser']
runner_tree = ast.parse((root / 'scripts/p0e-runtime-qualification').read_text())

def bare_host_mysql_calls(tree):
    def executable(argv):
        if isinstance(argv, (ast.List, ast.Tuple)) and argv.elts:
            first = argv.elts[0]
            return first.value if isinstance(first, ast.Constant) else None
        if isinstance(argv, ast.Constant) and isinstance(argv.value, str):
            words = shlex.split(argv.value)
            return words[0] if words else None
        if isinstance(argv, ast.BinOp) and isinstance(argv.op, ast.Add):
            return executable(argv.left)
        return None
    failures = []
    for node in ast.walk(tree):
        if not isinstance(node, ast.Call):
            continue
        is_command = isinstance(node.func, ast.Name) and node.func.id == 'command'
        is_subprocess = (isinstance(node.func, ast.Attribute) and isinstance(node.func.value, ast.Name)
            and node.func.value.id == 'subprocess'
            and node.func.attr in {'run', 'Popen', 'call', 'check_call', 'check_output'})
        if not (is_command or is_subprocess):
            continue
        argv = node.args[0] if node.args else next((item.value for item in node.keywords if item.arg == 'args'), None)
        if executable(argv) == 'mysql':
            failures.append(node.lineno)
    return failures

assert bare_host_mysql_calls(ast.parse('value = contract["input_images"]["mysql"]')) == []
assert bare_host_mysql_calls(ast.parse('command(["mysql", "--version"])')) == [1]
assert bare_host_mysql_calls(ast.parse('subprocess.run(args=["mysql", "--version"])')) == [1]
assert bare_host_mysql_calls(ast.parse('subprocess.Popen("mysql --version", shell=True)')) == [1]
assert bare_host_mysql_calls(ast.parse('command(["docker", "exec", "allocation", "/usr/bin/mysql"])')) == []
assert not bare_host_mysql_calls(runner_tree), 'runner reintroduced bare host MySQL calls at lines: ' + str(bare_host_mysql_calls(runner_tree))
method = next(n for n in ast.walk(runner_tree) if isinstance(n, ast.FunctionDef) and n.name == 'consumer_module_lifecycle')
options = {n.value for n in ast.walk(method) if isinstance(n, ast.Constant) and isinstance(n.value, str) and n.value.startswith('--')}
assert options <= set(parser._option_string_actions), options - set(parser._option_string_actions)
assert {a.option_strings[0] for a in parser._actions if a.required} <= options

def reject(operation):
    try: operation()
    except consumer['ChainError']: return
    raise AssertionError('invalid derived proof accepted')

with tempfile.TemporaryDirectory(prefix='p0e-consumer-contract-') as temporary:
    base = Path(temporary).resolve()
    cache, output = base / 'cache', base / 'output'
    artifacts = cache / 'edition-artifacts'
    artifacts.mkdir(parents=True)
    (output / 'groups').mkdir(parents=True)
    archive = artifacts / 'peanut-admin-1.0.0-multi-tenant.tar.gz'
    manifest = artifacts / (archive.name + '.manifest.json')
    archive.write_bytes(b'fixed test bytes')
    manifest.write_text(json.dumps({'application': {'manifest_sha256': 'a' * 64}}))
    sha = consumer['sha256']
    value = dict(selection_scope='full', run_id='run', lease='lease', owner='owner', thread='thread', parent_lease='parent',
        paths={'cache-dir': str(cache), 'output-dir': str(output)}, ports={'http': 20190, 'docs': 20186},
        groups=['generated-application', 'consumer-module-lifecycle'], through_group=None, candidate_tree='b' * 40,
        target_release={'version': '1.0.0'}, databases={'multi_tenant_fresh': 'author', 'consumer_module_cycle': 'consumer'})
    plan = output / 'plan.json'
    plan.write_text(json.dumps(value))
    receipt = dict(schema_version=1, status='passed', candidate='c' * 40, candidate_tree='b' * 40, target_release=value['target_release'],
        editions={'multi-tenant': {'archive_sha256': sha(archive), 'installer_manifest_sha256': sha(manifest), 'application_manifest_sha256': 'a' * 64}})
    receipt_path = output / 'groups/generated-application.json'
    receipt_path.write_text(json.dumps(receipt))
    checkpoint = dict(schema_version=1, candidate='c' * 40, candidate_tree='b' * 40, lease='lease', parent_lease='parent', run_id='run',
        planned_groups=value['groups'], groups={'generated-application': {'status': 'passed'}})
    checkpoint_path = output / 'checkpoint.json'
    checkpoint_path.write_text(json.dumps(checkpoint))
    args = argparse.Namespace(qualification_plan=str(plan), candidate='c' * 40, lease='lease', installer_mode='formal', edition='multi-tenant',
        database='consumer', author_database='author', installer_package=str(archive), installer_manifest=str(manifest),
        installer_sha256=sha(archive), installer_manifest_sha256=sha(manifest))
    observed = []
    def native_plan(native_args, require_clean):
        assert require_clean and native_args.groups is None and native_args.through_group is None
        return value
    native = {'plan': native_plan, 'verify_lease': lambda a, p: observed.append((a.lease, p))}
    verify = consumer['p0e_installer_inputs']
    with patch('runpy.run_path', return_value=native):
        assert verify(args, 'b' * 40) == value and observed
        for field, bad in [('author_database', 'consumer'), ('database', 'outside'), ('installer_mode', 'internal-candidate')]:
            altered = copy.copy(args); setattr(altered, field, bad)
            reject(lambda: verify(altered, 'b' * 40))
        for field in ['candidate', 'candidate_tree', 'lease', 'parent_lease', 'run_id', 'planned_groups']:
            altered = dict(checkpoint); altered[field] = 'wrong'
            checkpoint_path.write_text(json.dumps(altered))
            reject(lambda: verify(args, 'b' * 40))
        checkpoint_path.write_text(json.dumps(checkpoint))
        bad = copy.deepcopy(receipt); bad['editions']['multi-tenant']['archive_sha256'] = 'd' * 64
        receipt_path.write_text(json.dumps(bad)); reject(lambda: verify(args, 'b' * 40))
        receipt_path.write_text(json.dumps(receipt))
        archive.write_bytes(b'tampered'); reject(lambda: verify(args, 'b' * 40))
        archive.write_bytes(b'fixed test bytes')
        receipt_path.unlink(); receipt_path.symlink_to(manifest)
        reject(lambda: verify(args, 'b' * 40))
        receipt_path.unlink(); receipt_path.write_text(json.dumps(receipt))
        groups = output / 'groups'; moved = output / 'groups-real'
        groups.rename(moved); groups.symlink_to(moved, target_is_directory=True)
        reject(lambda: verify(args, 'b' * 40))
        groups.unlink(); moved.rename(groups)
    independent = copy.copy(args)
    independent.qualification_plan = None
    independent.database_resource = 'fixture-resource'
    independent.endpoint = 'fixture-host'
    independent.output = str(base / 'independent-output')
    direct = {('database-resource', independent.database_resource), ('endpoint', independent.endpoint),
        ('database', independent.database), ('database', independent.author_database),
        ('output-root', independent.output), ('cache-root', independent.output + '/cache'),
        ('installer-archive', independent.installer_package), ('installer-archive-sha256', independent.installer_sha256),
        ('installer-manifest', independent.installer_manifest), ('installer-manifest-sha256', independent.installer_manifest_sha256)}
    metadata = dict(gate='consumer-reference-chain', candidate=independent.candidate, status='ACTIVE', worktree=str(root))
    def git_command(arguments, **kwargs):
        assert arguments[0] == 'git'
        return ('b' * 40 if arguments[-1] == 'HEAD^{tree}' else 'c' * 40) if arguments[1] == 'rev-parse' else ''
    class ReachedRegistration(Exception): pass
    def registration(*arguments):
        assert arguments[-1] is None, 'independent consumer acquired P0-E projection'
        raise ReachedRegistration()
    chain = consumer['run_chain']
    replacements = dict(command=git_command, validate_installer_artifact=lambda *a: {}, registered_database=registration)
    with patch.dict(chain.__globals__, replacements):
        for kind in ['installer-archive', 'installer-archive-sha256', 'installer-manifest', 'installer-manifest-sha256']:
            with patch.dict(chain.__globals__, {'lease_fields': lambda _: (metadata, [(k, v) for k, v in direct if k != kind])}):
                reject(lambda: chain(independent))
        with patch.dict(chain.__globals__, {'lease_fields': lambda _: (metadata, list(direct))}):
            try: chain(independent)
            except ReachedRegistration: pass
            else: raise AssertionError('valid direct lease did not reach registered preflight')
            altered = copy.copy(independent); altered.qualification_plan = str(plan)
            reject(lambda: chain(altered))
        p0e_metadata = dict(metadata, gate='p0e-runtime-qualification')
        with patch.dict(chain.__globals__, {'lease_fields': lambda _: (p0e_metadata, list(direct))}):
            reject(lambda: chain(independent))
    package_calls = []
    def package_command(project, environment, arguments, log):
        package_calls.append(arguments)
        return {'status': 'verified'}
    with patch.dict(consumer['package_install'].__globals__, {'run_think': package_command}):
        consumer['package_install'](base, {}, archive, 'f' * 64, base / 'package.log')
        consumer['package_update'](base, {}, archive, 'f' * 64, True, base / 'package.log')
        consumer['package_update'](base, {}, archive, 'f' * 64, False, base / 'package.log')
    assert package_calls == [
        ['module:install-package', str(archive), '--sha256=' + 'f' * 64],
        ['module:update-package', str(archive), '--sha256=' + 'f' * 64, '--dry-run'],
        ['module:update-package', str(archive), '--sha256=' + 'f' * 64],
    ], 'canonical install/update lost its pinned archive digest or acquired signing input'
    source = consumer_path.read_text()
    preview = textwrap.dedent(source[source.index('        before_update = helper_action'):source.index('        updated = package_update')])
    baseline = dict(plugin={'installed_version': '1.0.0', 'status': 'active'},
        module_installation={'installed_version': '1.0.0', 'status': 'active'},
        tenant_modules=[{'code': 'default', 'status': 'enabled'}, {'code': 'tenant-b', 'status': 'enabled'}],
        rbac=[{'code': 'default', 'grants': 1}], permission_count=1, migration_count=1,
        owned_table=True, owned_rows=1, v2_column=False)
    def preview_contract(after):
        snapshots = iter([copy.deepcopy(baseline), after])
        scope = dict(helper=None, consumer=base, consumer_env={}, logs=base,
            v2_path=archive, v2_sha='f' * 64, expect=consumer['expect'],
            helper_action=lambda *a: next(snapshots),
            package_update=lambda *a: {'operation': 'update', 'dry_run': True})
        exec(compile(preview, str(consumer_path), 'exec'), scope)
        assert scope['before_update'] == scope['after_dry_run']
    preview_contract(copy.deepcopy(baseline))
    for field in baseline:
        changed = copy.deepcopy(baseline); changed[field] = 'unexpected-state-change'
        reject(lambda: preview_contract(changed))
    consumer['CONFIG_VALUES'].update(DB_HOST='127.0.0.1', DB_PORT='21306', DB_USER='fixture', DB_PASS='fixture')
    for label in ('author', 'consumer'):
        app = base / label
        (app / 'resources').mkdir(parents=True)
        (app / 'server/database').mkdir(parents=True)
        (app / 'server/bootstrap').mkdir()
        (app / 'server/vendor').mkdir()
        (app / '.peanut').mkdir()
        registry = app / 'resources/project-resources.json'
        registry.write_text(json.dumps({'schema_version': 1, 'project_id': label, 'authority': {'role': 'application'}, 'resources': {'databases': []}}))
        (app / '.peanut/application-manifest.json').write_text(json.dumps({'application': {'slug': label}}))
        consumer['configure_generated_database'](app, 'cr21-' + label, 'cr21-' + label + '-host', label)
        resource = json.loads(registry.read_text())['resources']['databases'][0]
        assert resource['database'] == label and resource['deployment_modes'] == ['multi-tenant']
        shutil.copyfile(root / 'server/database/environment-guard.php', app / 'server/database/environment-guard.php')
        shutil.copyfile(root / 'server/bootstrap/environment.php', app / 'server/bootstrap/environment.php')
        (app / 'server/vendor/autoload.php').symlink_to(root / 'server/vendor/autoload.php')
        backend_path = app / 'server/.env.cr21'
        backend = consumer['backend_environment'](backend_path, label, 'cr21-' + label, 'cr21-' + label + '-host', 'multi-tenant')
        assert 'PEANUT_MODULE_TRUSTED_KEYS_JSON' not in backend_path.read_text()
        environment = {key: os.environ[key] for key in ('PATH', 'HOME', 'TMPDIR') if key in os.environ}
        environment.update(backend)
        code = "require $argv[1]; echo json_encode(guardedDatabaseConfig(), JSON_THROW_ON_ERROR);"
        result = subprocess.run(['php', '-r', code, str(app / 'server/database/environment-guard.php')], env=environment, capture_output=True, text=True)
        assert result.returncode == 0, result.stderr
        configured = json.loads(result.stdout)
        assert configured['database'] == label and configured['resource_id'] == 'cr21-' + label and configured['deployment_target'] == 'local-development'
        backend_path.write_text(backend_path.read_text().replace('DB_NAME="' + label + '"', 'DB_NAME="foreign"'))
        result = subprocess.run(['php', '-r', code, str(app / 'server/database/environment-guard.php')], env=environment, capture_output=True, text=True)
        assert result.returncode != 0, 'foreign application database accepted'
print('consumer actual CLI parser and derived proof negative contracts passed')
PY;
$consumerOutput = [];
$consumerCode = 0;
exec('python3 -c ' . escapeshellarg($consumerContractCode) . ' 2>&1', $consumerOutput, $consumerCode);
$expect($consumerCode === 0, 'consumer CLI/derived input contract failed: ' . implode("\n", $consumerOutput));
echo implode("\n", $consumerOutput), "\n";

$leaseCleanupCode = <<<'PY'
import argparse, hashlib, json, pathlib, runpy, shutil, tempfile
from unittest.mock import patch
n = runpy.run_path('scripts/p0e-runtime-qualification')
Runner, error = n['Runner'], n['GateError']
with tempfile.TemporaryDirectory(prefix='p0e-lease-cleanup-', dir='.local/tmp') as temporary:
    base = pathlib.Path(temporary).resolve()
    def setup(label):
        root = base / label; root.mkdir()
        proof = root / 'lease'; proof.mkdir()
        output = root / 'output'; output.mkdir()
        contents = {'metadata.tsv': b'owner\tcontroller\n', 'resources.tsv': b'original resource tuples\n', 'registry.json': b'original private registry bytes\n'}
        for name, raw in contents.items(): (proof / name).write_bytes(raw)
        r = Runner.__new__(Runner)
        r.args = argparse.Namespace(candidate='a' * 40, owner='controller', lease='lease', run_id=label)
        r.output = output; r.checkpoint_path = output / 'checkpoint.json'
        r.plan = {'lease_proof_dir': str(proof), 'registry_sha256': hashlib.sha256(contents['registry.json']).hexdigest(), 'candidate_tree': 'b' * 40}
        r.checkpoint = dict(candidate=r.args.candidate, candidate_tree='b' * 40, run_id=label, lease='lease', parent_lease='parent', planned_groups=[])
        r.write_json(output / 'plan.json', r.plan); r.save_checkpoint()
        return r, proof, contents
    def reject(r):
        try: r.release_owned_lease()
        except error: return
        raise AssertionError('unsafe cleanup accepted')
    released = []
    current = {}
    def verify(args, plan):
        assert args.owner == 'controller' and args.lease == 'lease'
        assert (pathlib.Path(plan['lease_proof_dir']) / 'metadata.tsv').is_file()
    def command(arguments):
        assert arguments == [str(n['LEASE_TOOL']), 'release', '--lease', 'lease', '--owner', 'controller']
        proof = current['proof']; assert not (proof / 'registry.json').exists()
        if current.get('refuse'): raise error('native release refused before mutation')
        (proof / 'metadata.tsv').unlink(); (proof / 'resources.tsv').unlink(); proof.rmdir()
        released.append(True)
    with patch.dict(Runner.release_owned_lease.__globals__, {'verify_lease': verify, 'command': command}):
        r, proof, contents = setup('unknown'); current['proof'] = proof
        (proof / 'other-owner-evidence').write_text('must remain')
        reject(r); assert all((proof / name).read_bytes() == raw for name, raw in contents.items()) and not released
        r, proof, contents = setup('linked'); current['proof'] = proof
        (proof / 'registry.json').unlink(); (proof / 'registry.json').symlink_to(proof / 'metadata.tsv')
        reject(r); assert (proof / 'registry.json').is_symlink() and not released
        r, proof, contents = setup('wrong-digest'); current['proof'] = proof
        (proof / 'registry.json').write_bytes(b'tampered')
        reject(r); assert (proof / 'metadata.tsv').is_file() and not released
        r, proof, contents = setup('refused'); current.update(proof=proof, refuse=True)
        reject(r); assert all((proof / name).read_bytes() == raw for name, raw in contents.items())
        assert not (r.output / 'lease-release.json').exists() and not released
        r, proof, contents = setup('success'); current.update(proof=proof, refuse=False)
        r.release_owned_lease(); assert not proof.exists() and len(released) == 1
        receipt = json.loads((r.output / 'lease-release.json').read_text())
        saved = pathlib.Path(receipt['original_proof'])
        assert receipt['status'] == 'released' and {p.name for p in saved.iterdir()} == set(contents)
        for name, raw in contents.items(): assert (saved / name).read_bytes() == raw and (saved / name).stat().st_mode & 0o777 == 0o600
        assert not (r.output / 'summary.json').exists(), 'resource release invented qualification success'
source = pathlib.Path('scripts/p0e-runtime-qualification').read_text()
cleanup = source[source.index('    def cleanup_success'):source.index('    def release_owned_lease')]
assert cleanup.index('self.release_owned_lease()') < cleanup.index('shutil.rmtree(self.cache)') < cleanup.index('self.write_json(self.summary_path')
print('native lease proof preservation/release passed; cases=5; no qualification summary created')
PY;
$leaseCleanupOutput = [];
$leaseCleanupExit = 0;
exec('python3 -c ' . escapeshellarg($leaseCleanupCode) . ' 2>&1', $leaseCleanupOutput, $leaseCleanupExit);
$expect($leaseCleanupExit === 0, 'lease proof preservation/release contract failed: ' . implode("\n", $leaseCleanupOutput));
echo implode("\n", $leaseCleanupOutput), "\n";

echo "P0E-RUNTIME-QUALIFICATION-CONTRACT-001 passed\n";
