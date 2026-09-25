<?php

declare(strict_types=1);

$root = dirname(__DIR__, 3);
$runner = $root . '/scripts/p0e-runtime-qualification';
$fixturePath = $root . '/server/tests/fixtures/p0e-runtime-qualification/matrix.json';
$registryPath = $root . '/resources/project-resources.json';
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
$releaseVersion = ($releaseMetadata['schema_version'] ?? null) === 2
    && ($releaseMetadata['protocol'] ?? null) === 'peanut.release-metadata.v2'
    ? (string) ($releaseMetadata['instance_version'] ?? $releaseMetadata['source_product_version'] ?? '')
    : (string) ($releaseMetadata['version'] ?? '');
$scaffoldManifestPath = $root . '/scaffold/releases/v' . $releaseVersion . '/scaffold-manifest.json';
$scaffoldManifestText = (string) file_get_contents($scaffoldManifestPath);
$scaffoldManifest = json_decode($scaffoldManifestText, true, 512, JSON_THROW_ON_ERROR);

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
$browserHosts = $p0eRegistry['browser_host_binding'] ?? null;
$expect(is_array($browserHosts), 'P0-E browser Host binding is missing');
$expect(($browserHosts['platform_host'] ?? null) === 'platform.p0e.localhost', 'P0-E Platform browser Host changed');
$expect(($browserHosts['tenant_admin_host'] ?? null) === 'admin.p0e.localhost', 'P0-E Tenant Admin browser Host changed');
$expect(($browserHosts['port'] ?? null) === 20190, 'P0-E browser Host port changed');
$expect(($browserHosts['fallback'] ?? null) === 'none', 'P0-E browser Host binding must fail closed');
$tooling = $p0eRegistry['resources']['tooling'][0] ?? null;
$expect(is_array($tooling), 'P0-E remote administration tooling is missing');
$expect(($tooling['mysql_command'] ?? null) === '/usr/bin/mysql', 'P0-E MySQL CLI path changed');
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
$expect(($plan['resource_id'] ?? null) === 'peanut-admin-p0e-mysql84-gate', 'plan resource identity changed');
$expect(($plan['environment'] ?? null) === 'development', 'plan environment changed');
$expect(($plan['endpoint'] ?? null) === 'host.docker.internal:20189', 'plan container endpoint changed');
$expect(($plan['host_endpoint'] ?? null) === '192.168.192.2:20183', 'plan Host endpoint changed');
$expect(($plan['database_tunnel']['stable_resource_id'] ?? null) === 'peanut-admin-p0e-mysql84-container-tunnel', 'plan tunnel identity changed');
$expect(($plan['target_release'] ?? null) === $expectedTarget, 'plan did not bind the 3.0 scaffold release');
$expect(($plan['groups'] ?? null) === $expectedGroups, 'plan did not bind the fresh-only closure');
$expect(!array_key_exists('legacy_application', $plan), 'plan retained a legacy application');
$expect(!array_key_exists('backup-dir', $plan['paths'] ?? []), 'plan retained a recovery backup path');
$expect(!file_exists($outputPath) && !file_exists($cachePath), 'no-resource plan created a path');

$resourceCounts = [];
foreach ($plan['lease_resources'] ?? [] as $resource) {
    $type = (string) ($resource['type'] ?? '');
    $resourceCounts[$type] = ($resourceCounts[$type] ?? 0) + 1;
}
$expect(count($plan['lease_resources'] ?? []) === 30, 'manual lease resources must have 30 exact rows');
$expect(($resourceCounts['mysql-db'] ?? null) === 6, 'claim must bind six exact fresh-only databases');
$expect(($resourceCounts['deployment-mode'] ?? null) === 2, 'claim must bind both deployment modes');
$expect(($resourceCounts['port'] ?? null) === 3, 'claim must bind all generic port conflicts');
$expect(($resourceCounts['database-tunnel'] ?? null) === 1, 'claim must bind the database tunnel');
$expect(($resourceCounts['browser-host'] ?? null) === 2, 'claim must bind the separate browser Host boundaries');
$expect(($resourceCounts['endpoint'] ?? null) === 2, 'claim must bind Host and container database endpoints');

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
$expect(str_contains($runnerSource, '--formal-release-adoption'), 'consumer Module lifecycle does not require the sealed scaffold adoption path');
$expect(str_contains($runnerSource, 'consumer_module_cycle'), 'consumer Module lifecycle does not own a length-safe isolated database scenario');
$expect(str_contains($runnerSource, 'passed != required'), 'Gate completion closure is not enforced');
$expect(str_contains($runnerSource, 'preflight_database_admin_tooling'), 'remote database administration does not fail fast');
$expect(str_contains($runnerSource, 'start_database_tunnel'), 'container database tunnel is not lifecycle-managed');
$expect(str_contains($runnerSource, 'stop_database_tunnel'), 'container database tunnel cleanup is missing');
$expect(str_contains($runnerSource, 'preflight_browser_tooling'), 'browser tooling does not fail before resource claim');
$expect(str_contains($runnerSource, 'registered_browser_cli_path'), 'browser tooling does not use the fixed registered path');
$expect(!str_contains($runnerSource, 'pwcli-cache-*'), 'browser tooling retained temporary cache glob discovery');
$expect(str_contains($runnerSource, 'prepare_database_credentials()'), 'P0-E runner does not synchronize the registered database credential source');
$expect(str_contains($runnerSource, 'runtime-credentials.env'), 'P0-E runner does not retain per-run browser credentials for resume');
$expect(str_contains($runnerSource, 'resources != expected_resources'), 'lease verification is not an exact-set comparison');
$expect(str_contains($runnerSource, '"candidate_repository": plan_data["worktree"]'), 'lease verification lost the candidate repository boundary');
$expect(str_contains($runnerSource, 'read_only: true'), 'container lease proof is not read-only');
$expect(str_contains($runnerSource, 'PERSISTENT_DATABASE'), 'persistent database refusal is missing');
$expect(!str_contains($runnerSource, '["mysql"'), 'runner reintroduced a bare host MySQL client');
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
$expect(str_contains($browserFixture, "await page.locator('input').nth(0).fill(adminEmail);"), 'browser smoke must submit an email in both deployment modes');
$expect(str_contains($browserFixture, 'P0E_BROWSER_TENANT_ADMIN_URL') && str_contains($browserFixture, 'P0E_BROWSER_PLATFORM_URL'), 'browser smoke must use separate Tenant Admin and Platform Hostnames');
$expect(str_contains($browserFixture, '${platformUrl}/platform/'), 'browser smoke must enter the standalone Platform frontend');
$expect(str_contains($browserFixture, "getByText('概览', { exact: true }).first()"), 'browser smoke must wait for the visible Platform overview label');
$expect(str_contains($browserFixture, "page.locator('.login-form .el-select').waitFor"), 'multi-tenant browser smoke must not mistake the navbar selector for the login selector');
$expect(str_contains($browserFixture, ".el-select-dropdown:visible .el-select-dropdown__item').first().click()"), 'multi-tenant browser smoke must select a tenant before its second login submission');

echo "P0E-RUNTIME-QUALIFICATION-CONTRACT-001 passed\n";
