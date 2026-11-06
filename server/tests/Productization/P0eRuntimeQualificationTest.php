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
$expectedScenarios = [
    'standalone_fresh',
    'multi_tenant_fresh',
    'module_lifecycle',
    'consumer_module_cycle',
    'standalone_browser',
    'multi_tenant_browser',
];
$expectedGroups = [
    'generated-application',
    'standalone-fresh',
    'multi-tenant-fresh',
    'module-lifecycle',
    'consumer-module-lifecycle',
    'production-compose',
    'standalone-browser',
    'multi-tenant-browser',
];
$expect(($fixture['schema_version'] ?? null) === 1, 'P0-E fixture schema changed');
$expect(($fixture['gate'] ?? null) === 'p0e-runtime-qualification', 'P0-E Gate identity changed');
$expect(!array_key_exists('migration_count', $fixture['database_resource'] ?? []), 'P0-E Gate retained an application migration count');
$expect(!array_key_exists('ledger_count', $fixture['database_resource'] ?? []), 'P0-E Gate retained an application migration ledger count');
$expect(!array_key_exists('baselines', $fixture), 'fresh-only P0-E fixture retained 1.x baselines');
$expect(!array_key_exists('legacy_application', $fixture), 'fresh-only P0-E fixture retained a legacy application');
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

// A plan without the current lifecycle manifest cannot be used for qualification.
[$missingStatus, $missingOutput] = $run(['plan']);
$expect($missingStatus !== 0
    && str_contains($missingOutput, '--lifecycle-input')
    && str_contains($missingOutput, '--lifecycle-input-sha256'), 'planning accepted missing fixed lifecycle inputs');

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
    'upgraded_module_lifecycle',
    'upgraded_production_compose',
    'upgraded_browser',
    'mysqldump',
    'remote_dump',
    'remote_restore',
];
foreach ($unsupportedRunnerFragments as $fragment) {
    $expect(!str_contains($runnerSource, $fragment), "fresh-only runner retained unsupported code: {$fragment}");
}
$consumerSource = (string) file_get_contents($root . '/scripts/consumer-module-reference-chain');
foreach (['sodium_crypto_sign_keypair', 'author-signing-key.base64', 'PEANUT_MODULE_TRUSTED_KEYS_JSON', '--signing-key-id', '--signing-secret-key-file', '--signature-key-id', 'signed_pack_v1_v2'] as $exitedInput) {
    $expect(!str_contains($consumerSource, $exitedInput), "consumer fixture retained mandatory signing input {$exitedInput}");
}
$expect(str_contains($consumerSource, '"canonical_pack_v1_v2": "passed"'), 'consumer package evidence does not describe canonical integrity');
$moduleFixture = (string) file_get_contents($root . '/server/fixtures/module-lifecycle/run.php');
$nativeLifecycle = (string) file_get_contents($root . '/server/tests/Productization/ModuleLifecycleMySqlTest.php');
$nativeFailure = (string) file_get_contents($root . '/server/tests/Productization/ModuleFailureRecoveryMySqlTest.php');
$expect(str_contains($nativeLifecycle, "upgrade('acme.lifecycle', true)"), 'Module upgrade dry-run left native qualification');
$expect(str_contains($nativeLifecycle, "rollbackPlan('acme.lifecycle')"), 'Module rollback-plan left native qualification');
$expect(str_contains($nativeLifecycle, "uninstall('acme.lifecycle')"), 'Module preserve-data uninstall left native qualification');
$expect(str_contains($nativeFailure, 'ACCEPTANCE_CONTROLLED_INSTALL_FAILURE')
    && str_contains($nativeFailure, 'ACCEPTANCE_CONTROLLED_UPGRADE_FAILURE'), 'native lifecycle failures left qualification');
$expect(str_contains($moduleFixture, 'memberUploadInstalledAssertions(')
    && str_contains($moduleFixture, 'taskImportExportInstalledAssertions('), 'isolated Member/Task assertions left qualification');
$expect(!str_contains($moduleFixture, 'SourceApplicationBackup')
    && !str_contains($moduleFixture, 'pa_tenant_module'), 'Module producer retained retired execution contracts');

$browserFixture = (string) file_get_contents($root . '/server/tests/fixtures/p0e-runtime-qualification/browser-smoke.js');
$expect(str_contains($browserFixture, "loginTenant(page, tenantAdminUrl, adminEmail, adminPassword, 'admin')"), 'baseline must use the real Tenant email login in both deployment modes');
$expect(str_contains($browserFixture, 'P0E_BROWSER_TENANT_ADMIN_URL') && str_contains($browserFixture, 'P0E_BROWSER_PLATFORM_URL'), 'browser smoke must use separate Tenant Admin and Platform Hostnames');
$expect(str_contains($browserFixture, '${platformUrl}/platform/'), 'browser smoke must enter the standalone Platform frontend');
$expect(str_contains($browserFixture, "getByText('概览', { exact: true }).first()"), 'browser smoke must wait for the visible Platform overview label');
$expect(str_contains($browserFixture, "form.locator('.el-select').waitFor"), 'multi-tenant browser smoke must not mistake the navbar selector for the login selector');
$expect(str_contains($browserFixture, ".el-select-dropdown:visible .el-select-dropdown__item').first().click()"), 'multi-tenant browser smoke must select a tenant before its second login submission');

$consumerContractCode = <<<'PY'
import ast, copy, runpy, shlex, tempfile, textwrap
from pathlib import Path
from unittest.mock import patch

root = Path.cwd()
consumer_path = root / 'scripts/consumer-module-reference-chain'
consumer = runpy.run_path(str(consumer_path))
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

def reject(operation):
    try: operation()
    except consumer['ChainError']: return
    raise AssertionError('invalid derived proof accepted')

with tempfile.TemporaryDirectory(prefix='p0e-consumer-contract-') as temporary:
    base = Path(temporary).resolve()
    archive = base / 'reviewed.tar'
    package_calls = []
    def package_command(project, environment, operation, arguments, log):
        package_calls.append([operation, *arguments])
        return {'status': 'verified'}
    with patch.dict(consumer['package_install'].__globals__, {'run_module': package_command}):
        consumer['package_install'](base, {}, archive, 'f' * 64, base / 'package.log')
        consumer['package_update'](base, {}, archive, 'f' * 64, True, base / 'package.log')
        consumer['package_update'](base, {}, archive, 'f' * 64, False, base / 'package.log')
    assert package_calls == [
        ['install', str(archive), '--sha256=' + 'f' * 64],
        ['upgrade', str(archive), '--sha256=' + 'f' * 64, '--dry-run'],
        ['upgrade', str(archive), '--sha256=' + 'f' * 64],
    ], 'canonical install/update lost its pinned archive digest or acquired signing input'
    source = consumer_path.read_text()
    preview = textwrap.dedent(source[source.index('        before_update = helper_action'):source.index('        updated = package_update')])
    baseline = dict(module_installation={'installed_version': '1.0.0', 'status': 'active'},
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
print('consumer archive digest and zero-write preview contracts passed')
PY;
$consumerOutput = [];
$consumerCode = 0;
exec('python3 -c ' . escapeshellarg($consumerContractCode) . ' 2>&1', $consumerOutput, $consumerCode);
$expect($consumerCode === 0, 'consumer CLI/derived input contract failed: ' . implode("\n", $consumerOutput));
echo implode("\n", $consumerOutput), "\n";

echo "P0E-RUNTIME-QUALIFICATION-CONTRACT-001 passed (maintainer contracts; real eight-group qualification not run)\n";
