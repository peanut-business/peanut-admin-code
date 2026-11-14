<?php

declare(strict_types=1);

// Narrow pure policy contract; does not load credentials or open a connection.
if (in_array('--schema-contract', $argv ?? [], true)) {
    $guardEnvironmentPath = dirname(__DIR__, 2) . '/.env.schema-resource-contract-' . getmypid();
    if (file_exists($guardEnvironmentPath) || is_link($guardEnvironmentPath)) {
        throw new RuntimeException('isolated backend environment fixture already exists');
    }
    file_put_contents($guardEnvironmentPath, '');
    chmod($guardEnvironmentPath, 0600);
    register_shutdown_function(static function () use ($guardEnvironmentPath): void {
        if (is_file($guardEnvironmentPath)) {
            unlink($guardEnvironmentPath);
        }
    });
    putenv('PEANUT_SERVER_ENV_FILE=' . $guardEnvironmentPath);
    require_once dirname(__DIR__, 2) . '/database/environment-guard.php';
    unlink($guardEnvironmentPath);
    $endpoint = ['host' => '127.0.0.1', 'port' => 23306];
    $actual = ['database' => 'exact_schema'];
    $resource = ['lease_gate' => 'schema-test', 'lease_policy' => ['mode' => 'schema', 'physical_endpoint' => '127.0.0.1:23306', 'physical_resource_id' => 'fixture', 'max_executors' => 2, 'server_resources' => ['mysql-resource=fixture']]];
    if (mysqlSchemaLeaseResource($resource, $endpoint, $actual) !== '127.0.0.1:23306/exact_schema'
        || mysqlSchemaLeaseResource([], $endpoint, $actual) !== null) {
        throw new RuntimeException('schema/exclusive lease contract failed');
    }
    foreach (['physical_endpoint' => '127.0.0.2:23306', 'max_executors' => 9, 'server_resources' => []] as $field => $value) {
        $bad = $resource; $bad['lease_policy'][$field] = $value;
        try { mysqlSchemaLeaseResource($bad, $endpoint, $actual); }
        catch (RuntimeException) { continue; }
        throw new RuntimeException('invalid schema policy accepted: ' . $field);
    }
    $bad = $resource; $bad['lease_gate'] = 'recovery-qualification';
    try { mysqlSchemaLeaseResource($bad, $endpoint, $actual); }
    catch (RuntimeException) { fwrite(STDOUT, "MYSQL-SCHEMA-GUARD-CONTRACT passed (6 assertions)\n"); exit(0); }
    throw new RuntimeException('qualification schema policy accepted');
}

const P0E_FRESH_SCENARIO_MODES = [
    'standalone_fresh' => 'standalone',
    'multi_tenant_fresh' => 'multi-tenant',
    'module_lifecycle' => 'multi-tenant',
    'consumer_module_cycle' => 'standalone',
    'standalone_browser' => 'standalone',
    'multi_tenant_browser' => 'multi-tenant',
];
const P0E_FRESH_GROUPS = [
    'generated-application',
    'standalone-fresh',
    'multi-tenant-fresh',
    'module-lifecycle',
    'consumer-module-lifecycle',
    'production-compose',
    'standalone-browser',
    'multi-tenant-browser',
];
const CONSUMER_UPGRADE_SCENARIO_MODES = [
    'standalone_upgrade' => 'standalone',
    'multi_tenant_upgrade' => 'multi-tenant',
];

$root = dirname(__DIR__, 3);
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
$p0eMatrixPath = $root . '/server/tests/fixtures/p0e-runtime-qualification/matrix.json';
$consumerUpgradeMatrixPath = $root . '/server/tests/fixtures/consumer-upgrade-qualification/matrix.json';
$registryJson = (string) file_get_contents($registryPath);
$registry = json_decode($registryJson, true, 512, JSON_THROW_ON_ERROR);
$p0eRegistry = json_decode((string) file_get_contents($p0eRegistryPath), true, 512, JSON_THROW_ON_ERROR);
$p0eMatrix = json_decode((string) file_get_contents($p0eMatrixPath), true, 512, JSON_THROW_ON_ERROR);
$consumerUpgradeMatrix = json_decode((string) file_get_contents($consumerUpgradeMatrixPath), true, 512, JSON_THROW_ON_ERROR);

$expect = static function (bool $condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
};

$expect(($registry['schema_version'] ?? null) === 1, 'resource registry schema version is invalid');
$expect(($registry['project_id'] ?? null) === 'peanut-admin', 'resource registry project id is invalid');
$expect(($registry['authority']['source'] ?? null) === 'this versioned file', 'project registry is not authoritative');
$expect(!array_key_exists('company_allocation_evidence', $registry['authority'] ?? []), 'project registry still depends on CompanyOS allocation evidence');
$expect(!str_contains($registryJson, 'CompanyOS') && !str_contains($registryJson, 'company-os'), 'project registry still references CompanyOS');

$docsDomains = array_values(array_filter(
    $registry['resources']['external_services'] ?? [],
    static fn(array $item): bool => ($item['stable_resource_id'] ?? '') === 'peanut-admin-production-docs-domain',
));
$expect(count($docsDomains) === 1, 'production documentation domain must be registered exactly once as an external service');
$docsDomain = $docsDomains[0];
$expect(($docsDomain['environments'] ?? null) === ['production'], 'documentation domain must remain production-only');
$expect(($docsDomain['host'] ?? null) === 'peanut-admin-doc.007345.xyz', 'documentation domain host changed unexpectedly');
$expect(($docsDomain['port'] ?? null) === 443, 'documentation domain port changed unexpectedly');
$expect(($docsDomain['service_type'] ?? null) === 'Cloudflare Pages documentation site', 'documentation service type changed unexpectedly');
$expect(($docsDomain['fallback'] ?? null) === 'none', 'documentation domain must fail closed');
$expect(!array_key_exists('deployment_resource_id', $docsDomain), 'documentation domain must not claim the application Docker deployment');
$docsBackupMatches = array_values(array_filter(
    $registry['resources']['backups'] ?? [],
    static fn(array $item): bool => ($item['stable_resource_id'] ?? '') === 'peanut-admin-production-docs-domain',
));
$expect($docsBackupMatches === [], 'documentation domain must not be registered as a backup resource');

$candidateDatabases = array_values(array_filter(
    $registry['resources']['databases'] ?? [],
    static fn(array $item): bool => ($item['stable_resource_id'] ?? '') === 'peanut-admin-production-candidate-mysql84',
));
$expect(count($candidateDatabases) === 1, 'production candidate database must be registered exactly once');
$candidateDatabase = $candidateDatabases[0];
$expect(($candidateDatabase['environments'] ?? null) === ['production-candidate'], 'candidate database environment changed');
$expect(($candidateDatabase['database'] ?? null) === 'peanut_admin_candidate', 'candidate database name changed');
$expect(($candidateDatabase['fresh_install_only'] ?? null) === true, 'candidate database lost the fresh-only boundary');
$expect(($candidateDatabase['container_endpoint']['host'] ?? null) === 'mysql'
    && ($candidateDatabase['container_endpoint']['port'] ?? null) === 3306, 'candidate database endpoint changed');

$candidateDomains = array_values(array_filter(
    $registry['resources']['external_services'] ?? [],
    static fn(array $item): bool => ($item['stable_resource_id'] ?? '') === 'peanut-admin-production-candidate-domains',
));
$expect(count($candidateDomains) === 1, 'production candidate domain group must be registered exactly once');
$expect(($candidateDomains[0]['hosts'] ?? null) === [
    'pa-platform.007345.xyz',
    'pa-admin.007345.xyz',
    'pa-tenant-a.007345.xyz',
    'pa-tenant-b.007345.xyz',
], 'candidate domain list changed unexpectedly');
$expect(($candidateDomains[0]['origin_endpoint']['port'] ?? null) === 18093, 'candidate origin port changed');

$localDemoDomains = array_values(array_filter(
    $registry['resources']['external_services'] ?? [],
    static fn(array $item): bool => ($item['stable_resource_id'] ?? '') === 'peanut-admin-local-multi-tenant-demo-domains',
));
$expect(count($localDemoDomains) === 1, 'local multi-tenant demo domains must be registered exactly once');
$expect(($localDemoDomains[0]['environments'] ?? null) === ['local-multi-tenant-demo'], 'local demo domains use the wrong environment');
$expect(($localDemoDomains[0]['hosts'] ?? null) === [
    'platform.peanut-admin.test',
    'platform.peanut-admin.localhost',
    'admin.peanut-admin.test',
    'tenant-a.peanut-admin.test',
    'tenant-b.peanut-admin.test',
    'tenant-a.peanut-admin.localhost',
    'tenant-b.peanut-admin.localhost',
], 'local demo domain list changed unexpectedly');
$expect(($localDemoDomains[0]['tenant_entry_bindings'] ?? null) === [
    'tenant-a.peanut-admin.test' => 'tenant-a/admin-web',
    'tenant-b.peanut-admin.test' => 'tenant-b/admin-web',
    'tenant-a.peanut-admin.localhost' => 'tenant-a/admin-web',
    'tenant-b.peanut-admin.localhost' => 'tenant-b/admin-web',
], 'local demo Tenant host bindings changed unexpectedly');
$productionDeployments = array_values(array_filter(
    $registry['resources']['external_services'] ?? [],
    static fn(array $item): bool => ($item['stable_resource_id'] ?? '') === 'peanut-admin-production-deployment',
));
$expect(count($productionDeployments) === 1, 'published production deployment must be registered exactly once');
$expect(($productionDeployments[0]['environments'] ?? null) === ['production'], 'published deployment must remain production-only');
$expect(($productionDeployments[0]['deployment_root'] ?? null) === '/www/docker/peanut-admin', 'published deployment root changed');
$expect(($productionDeployments[0]['required_non_secret_environment']['PEANUT_DEPLOYMENT_TARGET'] ?? null) === 'production', 'published deployment target changed');

$candidateDeployments = array_values(array_filter(
    $registry['resources']['external_services'] ?? [],
    static fn(array $item): bool => ($item['stable_resource_id'] ?? '') === 'peanut-admin-production-candidate-deployment',
));
$expect(count($candidateDeployments) === 1, 'production candidate deployment must be registered exactly once');
$expect(($candidateDeployments[0]['environments'] ?? null) === ['production-candidate'], 'candidate deployment environment changed');
$expect(($candidateDeployments[0]['deployment_root'] ?? null) === '/www/docker/peanut-admin-candidate', 'candidate deployment root changed');
$expect(($candidateDeployments[0]['required_non_secret_environment']['PEANUT_DEPLOYMENT_TARGET'] ?? null) === 'production-candidate', 'candidate deployment target changed');
$expect(($candidateDeployments[0]['database_resource_id'] ?? null) === 'peanut-admin-production-candidate-mysql84', 'candidate deployment database changed');
$expect(($candidateDeployments[0]['required_non_secret_environment']['PLATFORM_HOSTS'] ?? null) === 'pa-platform.007345.xyz', 'candidate Platform Host boundary changed');
$expect(($candidateDeployments[0]['required_non_secret_environment']['TENANT_ADMIN_HOSTS'] ?? null) === 'pa-admin.007345.xyz', 'candidate shared Admin Host boundary changed');
$expect(($candidateDeployments[0]['required_non_secret_environment']['OWNER_INVITATION_DELIVERY_MODE'] ?? null) === 'manual', 'candidate invitation handoff mode changed');

$databases = array_values(array_filter(
    $registry['resources']['databases'] ?? [],
    static fn(array $item): bool => ($item['stable_resource_id'] ?? '') === 'peanut-admin-scaffold-mt-development-mysql84'
        && in_array('development', $item['environments'] ?? [], true)
        && ($item['application_runtime'] ?? true),
));
$expect(count($databases) === 1, 'scaffold development requires its registered MT database');
$database = $databases[0];
$expect($database['deployment_modes'] === ['multi-tenant'], 'scaffold development database must be MT only');
$expect($database['version'] === '8.4.10', 'development database version changed unexpectedly');
$expect($database['database'] === 'peanut_dev_mt', 'development database name changed unexpectedly');
$expect($database['fallback'] === 'none', 'development database must fail closed');

foreach (['upstream_endpoint' => 'host', 'container_endpoint' => 'container'] as $key => $consumer) {
    $endpoint = $database[$key] ?? null;
    $expect(is_array($endpoint), "{$key} is missing");
    $expect(in_array($consumer, $endpoint['consumers'] ?? [], true), "{$key} consumer is invalid");
    $expect($endpoint['host'] === '192.168.192.2', "{$key} host changed unexpectedly");
    $expect($endpoint['port'] === 20183, "{$key} port changed unexpectedly");
}

$qualificationDatabases = array_values(array_filter(
    $registry['resources']['databases'] ?? [],
    static fn(array $item): bool => ($item['stable_resource_id'] ?? '') === 'peanut-admin-p0e-mysql84-gate',
));
$expect(count($qualificationDatabases) === 1, 'P0-E qualification database registration is missing');
$qualificationDatabase = $qualificationDatabases[0];
$requiredQualificationFields = [
    'purpose', 'owner', 'runtime_resource_id', 'service_type', 'schema', 'allowed_scenarios',
    'upstream_endpoint', 'container_endpoint', 'credential_ref', 'data_source',
    'freshness_requirement', 'health_check', 'claim_precondition', 'creation_policy',
    'backup_responsibility', 'cleanup_responsibility', 'failure_retention_policy',
];
foreach ($requiredQualificationFields as $field) {
    $expect(array_key_exists($field, $qualificationDatabase), "P0-E required field {$field} is missing");
}
$expect(($qualificationDatabase['application_runtime'] ?? null) === false, 'P0-E database must not be selected as an application runtime');
$expect(($qualificationDatabase['environments'] ?? null) === ['development'], 'P0-E database must stay in the development environment');
$expect(($qualificationDatabase['version'] ?? null) === '8.4.10', 'P0-E database version changed unexpectedly');
$expect(($qualificationDatabase['database'] ?? null) === 'peanut_admin_development_p0e_<run_id>_<scenario>', 'P0-E database template is invalid');
$expect(($qualificationDatabase['namespace'] ?? null) === 'peanut_admin_development_p0e_<run_id>_', 'P0-E database namespace is invalid');
$expect(($qualificationDatabase['run_id_pattern'] ?? null) === '^[a-z0-9]{1,11}$', 'P0-E run_id policy is invalid');
$expect(($qualificationDatabase['database_name_max_length'] ?? null) === 64, 'P0-E database name limit is invalid');
$expect(
    ($qualificationDatabase['allowed_scenarios'] ?? null) === array_keys(P0E_FRESH_SCENARIO_MODES),
    'P0-E database scenarios are not the 2.0 fresh-only set',
);
$expect(
    array_keys($p0eMatrix['scenarios'] ?? []) === array_keys(P0E_FRESH_SCENARIO_MODES),
    'P0-E matrix scenarios diverge from the registered 2.0 fresh-only set',
);
$expect(($p0eMatrix['groups'] ?? null) === P0E_FRESH_GROUPS, 'P0-E matrix does not contain the seven fresh-only Gate groups');
$expect(
    !str_contains((string) json_encode($p0eMatrix), 'v1_0_forward')
        && !str_contains((string) json_encode($p0eMatrix), 'v1_1_forward')
        && !str_contains((string) json_encode($p0eMatrix), 'migration_fault')
        && !str_contains((string) json_encode($p0eMatrix), 'recovery'),
    'P0-E matrix retained a 1.x upgrade or recovery fixture',
);
$expect(($qualificationDatabase['credential_ref'] ?? null) === 'mac-14:/Users/xing/.config/peanut-admin/development-db.env', 'P0-E credential reference changed unexpectedly');
$expect(($qualificationDatabase['lifecycle'] ?? null) === 'ephemeral', 'P0-E database lifecycle must be ephemeral');
$expect(($qualificationDatabase['fallback'] ?? null) === 'none', 'P0-E database must fail closed');
$expect(($qualificationDatabase['claim_precondition'] ?? '') !== '', 'P0-E claim precondition is missing');
$expect(($qualificationDatabase['creation_policy'] ?? '') !== '', 'P0-E creation policy is missing');
$expect(($qualificationDatabase['backup_responsibility'] ?? '') !== '', 'P0-E backup responsibility is missing');
$expect(($qualificationDatabase['cleanup_responsibility'] ?? '') !== '', 'P0-E cleanup responsibility is missing');
$expect(($qualificationDatabase['failure_retention_policy'] ?? '') !== '', 'P0-E failure retention policy is missing');
$expect(($qualificationDatabase['data_source'] ?? '') !== '', 'P0-E authoritative data source is missing');
$expect(($qualificationDatabase['freshness_requirement'] ?? '') !== '', 'P0-E freshness requirement is missing');
$expect(is_array($qualificationDatabase['health_check'] ?? null), 'P0-E health check is missing');

$consumerUpgradeDatabases = array_values(array_filter(
    $registry['resources']['databases'] ?? [],
    static fn(array $item): bool => ($item['stable_resource_id'] ?? '') === 'peanut-admin-consumer-upgrade-mysql84-gate',
));
$expect(count($consumerUpgradeDatabases) === 1, 'consumer-upgrade database registration is missing');
$consumerUpgradeDatabase = $consumerUpgradeDatabases[0];
foreach ($requiredQualificationFields as $field) {
    $expect(array_key_exists($field, $consumerUpgradeDatabase), "consumer-upgrade required field {$field} is missing");
}
$expect(($consumerUpgradeDatabase['application_runtime'] ?? null) === false, 'consumer-upgrade database must not be selected as an application runtime');
$expect(($consumerUpgradeDatabase['environments'] ?? null) === ['development'], 'consumer-upgrade database must stay in development');
$expect(($consumerUpgradeDatabase['database'] ?? null) === 'peanut_admin_development_cr03_<run_id>_<scenario>', 'consumer-upgrade database template is invalid');
$expect(($consumerUpgradeDatabase['namespace'] ?? null) === 'peanut_admin_development_cr03_<run_id>_', 'consumer-upgrade database namespace is invalid');
$expect(($consumerUpgradeDatabase['run_id_pattern'] ?? null) === '^[a-z0-9]{1,11}$', 'consumer-upgrade run_id policy is invalid');
$expect(($consumerUpgradeDatabase['database_name_max_length'] ?? null) === 64, 'consumer-upgrade database name limit is invalid');
$expect(($consumerUpgradeDatabase['allowed_scenarios'] ?? null) === array_keys(CONSUMER_UPGRADE_SCENARIO_MODES), 'consumer-upgrade scenarios are invalid');
$expect(($consumerUpgradeDatabase['lifecycle'] ?? null) === 'ephemeral', 'consumer-upgrade database lifecycle must be ephemeral');
$expect(($consumerUpgradeDatabase['qualification_gate'] ?? null) === 'consumer-upgrade-qualification', 'consumer-upgrade Gate is invalid');
$expect(($consumerUpgradeDatabase['deployment_target'] ?? null) === 'local-development', 'consumer-upgrade deployment target is invalid');
$expect(($consumerUpgradeDatabase['fallback'] ?? null) === 'none', 'consumer-upgrade database must fail closed');
$expect(($consumerUpgradeDatabase['container_endpoint'] ?? null) === null, 'consumer-upgrade must not expose a container endpoint');
$expect(($consumerUpgradeDatabase['upstream_endpoint']['endpoint_id'] ?? null) === 'peanut-admin-consumer-upgrade-mysql84-gate-host-direct', 'consumer-upgrade Host endpoint is invalid');
$expect(($consumerUpgradeDatabase['upstream_endpoint']['host'] ?? null) === '192.168.192.2' && ($consumerUpgradeDatabase['upstream_endpoint']['port'] ?? null) === 20183, 'consumer-upgrade Host address is invalid');
$expect(($consumerUpgradeDatabase['upstream_endpoint']['consumers'] ?? null) === ['host'], 'consumer-upgrade must permit only Host execution');
$expect(($consumerUpgradeDatabase['administrative_tooling_resource_id'] ?? null) === 'peanut-admin-consumer-upgrade-mysql84-remote-admin-cli', 'consumer-upgrade administrative tooling binding is invalid');
$expect(($consumerUpgradeDatabase['http_listener_resource_id'] ?? null) === 'peanut-admin-local-production-preview-gateway', 'consumer-upgrade HTTP listener binding is invalid');
$expect(($consumerUpgradeDatabase['local_object_storage_resource_id'] ?? null) === 'peanut-admin-consumer-upgrade-local-storage', 'consumer-upgrade Local storage binding is invalid');
$consumerAdminTools = array_values(array_filter(
    $registry['resources']['tooling'] ?? [],
    static fn(array $item): bool => ($item['stable_resource_id'] ?? null) === 'peanut-admin-consumer-upgrade-mysql84-remote-admin-cli',
));
$expect(count($consumerAdminTools) === 1, 'consumer-upgrade administrative tooling is missing');
$consumerAdminTool = $consumerAdminTools[0];
$expect(($consumerAdminTool['mysql_command'] ?? null) === '/usr/bin/mysql' && ($consumerAdminTool['mysqldump_command'] ?? null) === '/usr/bin/mysqldump', 'consumer-upgrade exact mysql/mysqldump tools are invalid');
$expect(($consumerAdminTool['database_name_pattern'] ?? null) === '^peanut_admin_development_cr03_[a-z0-9]{1,11}_(standalone_upgrade|multi_tenant_upgrade)$', 'consumer-upgrade administrative naming range is invalid');
$expect(str_contains((string) ($consumerAdminTool['fallback'] ?? ''), 'generic database runners are forbidden'), 'consumer-upgrade administrative tooling allowed a generic fallback');
$expect(($consumerUpgradeMatrix['gate'] ?? null) === 'consumer-upgrade-qualification', 'consumer-upgrade fixture Gate is invalid');
$expect(($consumerUpgradeMatrix['database_resource']['stable_resource_id'] ?? null) === 'peanut-admin-consumer-upgrade-mysql84-gate', 'consumer-upgrade fixture resource is invalid');
$expect(array_keys($consumerUpgradeMatrix['scenarios'] ?? []) === array_keys(CONSUMER_UPGRADE_SCENARIO_MODES), 'consumer-upgrade fixture scenarios diverge from the registry');

$expect(($p0eRegistry['schema_version'] ?? null) === 1, 'P0-E resource registry schema version is invalid');
$expect(($p0eRegistry['project_id'] ?? null) === 'peanut-admin', 'P0-E resource registry project id is invalid');
$expect(($p0eRegistry['gate'] ?? null) === 'p0e-runtime-qualification', 'P0-E resource registry Gate changed');
$expect(!array_key_exists('company_allocation_evidence', $p0eRegistry['authority'] ?? []), 'P0-E registry still depends on CompanyOS allocation evidence');
$expect(!array_key_exists('company_allocation_resource_id', $p0eRegistry['authority'] ?? []), 'P0-E registry still has a CompanyOS allocation identity');
$expect(!str_contains((string) json_encode($p0eRegistry), 'CompanyOS') && !str_contains((string) json_encode($p0eRegistry), 'company-os'), 'P0-E registry still references CompanyOS');
$binding = $p0eRegistry['database_administration_binding'] ?? null;
$expect(is_array($binding), 'P0-E database administration binding is missing');
$expect(($binding['database_resource_id'] ?? null) === 'peanut-admin-p0e-mysql84-gate', 'P0-E binding database resource changed');
$expect(($binding['runtime_resource_id'] ?? null) === ($qualificationDatabase['runtime_resource_id'] ?? null), 'P0-E binding and project runtime resource diverged');
$expect(($binding['administrative_tooling_resource_id'] ?? null) === 'peanut-admin-mysql84-remote-admin-cli', 'P0-E binding tooling resource changed');
$expect(($binding['database'] ?? null) === ($qualificationDatabase['database'] ?? null), 'P0-E binding database template diverged');
$expect(($binding['namespace'] ?? null) === ($qualificationDatabase['namespace'] ?? null), 'P0-E binding namespace diverged');
$expect(($binding['version'] ?? null) === ($qualificationDatabase['version'] ?? null), 'P0-E binding version diverged');
$expect(($binding['port'] ?? null) === ($qualificationDatabase['upstream_endpoint']['port'] ?? null), 'P0-E binding port diverged');
$expect(($binding['credential_ref'] ?? null) === ($qualificationDatabase['credential_ref'] ?? null), 'P0-E binding credential provenance diverged');
$expect(str_contains((string) ($binding['failure_policy'] ?? ''), 'never stops or restarts Docker Desktop'), 'P0-E binding failure policy is not fail-closed');
$expect(($binding['fallback'] ?? null) === 'none', 'P0-E database administration binding must fail closed');

$administrativeTools = array_values(array_filter(
    $p0eRegistry['resources']['tooling'] ?? [],
    static fn(array $item): bool => ($item['stable_resource_id'] ?? '') === 'peanut-admin-mysql84-remote-admin-cli',
));
$expect(count($administrativeTools) === 1, 'P0-E remote MySQL administration tooling registration is missing');
$administrativeTool = $administrativeTools[0];
$expect(($administrativeTool['host'] ?? null) === 'mac-14', 'P0-E administration moved off the database resource host');
$expect(($administrativeTool['version'] ?? null) === '8.4.10', 'P0-E administration client version changed');
$expect(($administrativeTool['transport'] ?? null) === 'ssh-docker-exec', 'P0-E administration transport changed');
$expect(($administrativeTool['ssh_command'] ?? null) === '/usr/bin/ssh', 'P0-E SSH command is not absolute');
$expect(($administrativeTool['docker_command'] ?? null) === '/usr/local/bin/docker', 'P0-E remote Docker command is not absolute');
$expect(($administrativeTool['container_name'] ?? null) === 'peanut-admin-mysql84-development', 'P0-E administration container changed');
$expect(($administrativeTool['mysql_command'] ?? null) === '/usr/bin/mysql', 'P0-E MySQL command is not absolute');
$expect(($administrativeTool['credential_ref'] ?? null) === ($qualificationDatabase['credential_ref'] ?? null), 'P0-E administration credential provenance diverged');
$expect(($administrativeTool['failure_policy'] ?? null) === ($binding['failure_policy'] ?? null), 'P0-E administration failure policy diverged');
$expect(!array_key_exists('mysqldump_command', $administrativeTool), 'fresh-only P0-E retained backup tooling');
$expect(str_starts_with((string) ($administrativeTool['container_image'] ?? ''), 'mysql:8.4.10@sha256:'), 'P0-E administration image is not immutable');
$expect(($administrativeTool['fallback'] ?? null) === 'none; host mysql commands are forbidden', 'P0-E administration allowed a host CLI fallback');

$upstreamEndpoint = $qualificationDatabase['upstream_endpoint'] ?? null;
$expect(is_array($upstreamEndpoint), 'P0-E upstream endpoint is missing');
$expect(in_array('host', $upstreamEndpoint['consumers'] ?? [], true), 'P0-E upstream endpoint consumer is invalid');
$expect(($upstreamEndpoint['host'] ?? null) === '192.168.192.2', 'P0-E upstream Host changed unexpectedly');
$expect(($upstreamEndpoint['port'] ?? null) === 20183, 'P0-E upstream port changed unexpectedly');
$containerEndpoint = $qualificationDatabase['container_endpoint'] ?? null;
$expect(is_array($containerEndpoint), 'P0-E container endpoint is missing');
$expect(in_array('container', $containerEndpoint['consumers'] ?? [], true), 'P0-E container endpoint consumer is invalid');
$expect(($containerEndpoint['host'] ?? null) === 'host.docker.internal', 'P0-E container endpoint must use the Docker Desktop Host gateway');
$expect(($containerEndpoint['port'] ?? null) === 20189, 'P0-E container endpoint must use the registered tunnel port');

$databaseTunnels = array_values(array_filter(
    $p0eRegistry['resources']['tooling'] ?? [],
    static fn(array $item): bool => ($item['stable_resource_id'] ?? '') === 'peanut-admin-p0e-mysql84-container-tunnel',
));
$expect(count($databaseTunnels) === 1, 'P0-E container database tunnel tooling is missing');
$expect(($databaseTunnels[0]['transport'] ?? null) === 'ssh-local-forward', 'P0-E database tunnel transport changed');
$expect(($databaseTunnels[0]['local_host'] ?? null) === '127.0.0.1', 'P0-E database tunnel must remain loopback-bound');
$expect(($databaseTunnels[0]['local_port'] ?? null) === 20189, 'P0-E database tunnel local port changed');
$expect(($databaseTunnels[0]['container_host'] ?? null) === 'host.docker.internal', 'P0-E database tunnel container Host changed');
$expect(($databaseTunnels[0]['upstream_host'] ?? null) === ($upstreamEndpoint['host'] ?? null), 'P0-E database tunnel upstream Host diverged');
$expect(($databaseTunnels[0]['upstream_port'] ?? null) === ($upstreamEndpoint['port'] ?? null), 'P0-E database tunnel upstream port diverged');
$expect(($databaseTunnels[0]['fallback'] ?? null) === 'none', 'P0-E database tunnel must fail closed');

$runSelector = static function (array $arguments) use ($root): array {
    $command = escapeshellarg($root . '/scripts/project-resource-registry');
    foreach ($arguments as $argument) {
        $command .= ' ' . escapeshellarg($argument);
    }
    $output = [];
    $exitCode = 0;
    exec($command . ' 2>&1', $output, $exitCode);
    return [$exitCode, implode("\n", $output)];
};

[$exitCode, $output] = $runSelector([
    'database-env', '--deployment-target', 'local-development', '--consumer', 'host',
]);
$expect($exitCode !== 0 && str_contains($output, 'expected exactly one database'), 'ambiguous development database selection must fail closed');

[$exitCode, $output] = $runSelector([
    'database-env', '--deployment-target', 'local-development', '--consumer', 'host',
    '--resource-id', $database['stable_resource_id'],
]);
$expect($exitCode === 0 && str_contains($output, 'DB_NAME=peanut_dev_mt'), 'explicit fixed database resource selection failed');

[$exitCode, $output] = $runSelector([
    'database-env', '--deployment-target', 'local-development', '--consumer', 'host',
    '--resource-id', $database['stable_resource_id'], '--database-name', 'override_forbidden',
]);
$expect($exitCode !== 0, 'fixed database resource unexpectedly allowed a database name override');

$qualificationName = 'peanut_admin_development_p0e_run123_standalone_fresh';
[$exitCode, $output] = $runSelector([
    'database-env', '--deployment-target', 'local-development', '--consumer', 'host',
    '--resource-id', 'peanut-admin-p0e-mysql84-gate', '--database-name', $qualificationName,
]);
$expect($exitCode === 0, "explicit P0-E database selection failed: {$output}");
$expect(str_contains($output, 'PEANUT_DATABASE_RESOURCE_ID=peanut-admin-p0e-mysql84-gate'), 'explicit P0-E resource identity is missing');
$expect(str_contains($output, "DB_NAME={$qualificationName}"), 'explicit P0-E database name is missing');

[$exitCode, $output] = $runSelector([
    'database-env', '--deployment-target', 'local-development', '--consumer', 'host',
    '--resource-id', 'peanut-admin-p0e-mysql84-gate',
]);
$expect($exitCode !== 0, 'templated database resource unexpectedly allowed a missing database name');

[$exitCode, $output] = $runSelector([
    'database-env', '--deployment-target', 'production', '--consumer', 'container',
    '--resource-id', 'peanut-admin-p0e-mysql84-gate', '--database-name', $qualificationName,
]);
$expect($exitCode !== 0, 'P0-E database selection unexpectedly allowed production');

[$exitCode, $output] = $runSelector([
    'database-env', '--deployment-target', 'local-development', '--consumer', 'host',
    '--resource-id', 'peanut-admin-p0e-mysql84-gate',
    '--database-name', 'peanut_admin_development_intruder_run123_standalone_fresh',
]);
$expect($exitCode !== 0, 'P0-E database selection unexpectedly allowed an out-of-namespace name');

[$exitCode, $output] = $runSelector([
    'database-env', '--deployment-target', 'local-development', '--consumer', 'host',
    '--resource-id', 'peanut-admin-p0e-mysql84-gate',
    '--database-name', 'peanut_admin_development_p0e_run123_unknown_scenario',
]);
$expect($exitCode !== 0, 'templated database selection unexpectedly allowed an unknown scenario');

[$exitCode, $output] = $runSelector([
    'database-env', '--deployment-target', 'local-development', '--consumer', 'host',
    '--resource-id', 'peanut-admin-p0e-mysql84-gate',
    '--database-name', 'peanut_admin_development_p0e_UPPER_standalone_fresh',
]);
$expect($exitCode !== 0, 'templated database selection unexpectedly allowed an invalid run_id');

$consumerUpgradeName = 'peanut_admin_development_cr03_run123_standalone_upgrade';
[$exitCode, $output] = $runSelector([
    'database-env', '--deployment-target', 'local-development', '--consumer', 'host',
    '--resource-id', 'peanut-admin-consumer-upgrade-mysql84-gate', '--database-name', $consumerUpgradeName,
]);
$expect($exitCode === 0, "explicit consumer-upgrade database selection failed: {$output}");
$expect(str_contains($output, 'PEANUT_DATABASE_RESOURCE_ID=peanut-admin-consumer-upgrade-mysql84-gate'), 'consumer-upgrade resource identity is missing');
$expect(str_contains($output, "DB_NAME={$consumerUpgradeName}"), 'consumer-upgrade database name is missing');

foreach ([
    ['local-development', 'host', null, 'missing database name'],
    ['production', 'host', $consumerUpgradeName, 'production target'],
    ['local-development', 'container', $consumerUpgradeName, 'container consumer'],
    ['local-development', 'host', 'peanut_admin_development_cr03_run123_unknown', 'unknown scenario'],
    ['local-development', 'host', 'peanut_admin_development_p0e_run123_standalone_upgrade', 'foreign namespace'],
] as [$target, $consumer, $name, $case]) {
    $arguments = [
        'database-env', '--deployment-target', $target, '--consumer', $consumer,
        '--resource-id', 'peanut-admin-consumer-upgrade-mysql84-gate',
    ];
    if ($name !== null) {
        $arguments[] = '--database-name';
    }
    if ($name !== null) {
        $arguments[] = $name;
    }
    [$exitCode, $output] = $runSelector($arguments);
    $expect($exitCode !== 0, "consumer-upgrade selection unexpectedly allowed {$case}");
}

$selectorSource = (string) file_get_contents($root . '/scripts/project-resource-registry');
$expect(!str_contains($selectorSource, 'P0E_RESOURCE_ID'), 'resource selector still hard-codes a project resource identity');
$expect(!str_contains($selectorSource, 'P0-E database'), 'resource selector still exposes project-specific database semantics');

$rootInstructions = (string) file_get_contents($root . '/AGENTS.md');
$qualificationRunner = (string) file_get_contents($root . '/scripts/p0e-runtime-qualification');
$guardSource = (string) file_get_contents($root . '/server/database/environment-guard.php');
$devCompose = (string) file_get_contents($root . '/deploy/docker-compose.dev.yml');
$hostRuntime = (string) file_get_contents($root . '/scripts/project-env');
$developmentDatabase = (string) file_get_contents($root . '/scripts/project-development-database.sh');

$expect(str_contains($rootInstructions, 'resources/project-resources.json'), 'root AGENTS.md does not reference the registry');
$expect(str_contains($qualificationRunner, 'resources/p0e-runtime-qualification.json')
    && str_contains($qualificationRunner, 'PEANUT_RESOURCE_REGISTRY'), 'qualification runner lost its two registry inputs');
$expect(str_contains($guardSource, 'guardedDatabaseConfig')
    && str_contains($guardSource, 'templatedDatabaseIdentity'), 'environment guard lost templated qualification validation');
$expect(str_contains($hostRuntime, 'tools/toolchain.json') && str_contains($hostRuntime, 'host.json')
    && str_contains($hostRuntime, 'project-composer'), 'project tool environment lost its registered PHP/Composer inputs');
$expect(!preg_match('/(?m)^\s{2}php:\s*$/', $devCompose), 'development Compose still defines a PHP service');
$expect(str_contains($devCompose, 'host.docker.internal'), 'development containers do not target host PHP');
$expect(str_contains($devCompose, 'NO_PROXY'), 'development containers do not bypass proxies for host PHP');
$expect(!str_contains($devCompose, 'DB_HOST=192.168.192.2'), 'development Compose overrides the registered database host');
$expect(str_contains($developmentDatabase, 'repair-root-credential'), 'development database tooling lacks a bounded root credential repair entry');
$expect(str_contains($developmentDatabase, 'StrictHostKeyChecking=yes'), 'development database credential repair does not preserve SSH host-key verification');
$expect(str_contains($developmentDatabase, 'before-root-credential-repair'), 'development database credential repair lacks its backup artifact');
$expect(str_contains($developmentDatabase, 'MYSQL_ROOT_PASSWORD') && str_contains($developmentDatabase, 'SELECT 1'), 'development database credential repair does not verify the live root credential');
$expect(
    str_contains($developmentDatabase, '$1 != "DB_ROOT_PASS" && $1 != "MYSQL_ROOT_PASSWORD"'),
    'normal development credential synchronization no longer strips root-only credentials',
);
$expect(!str_contains($developmentDatabase, 'docker desktop stop') && !str_contains($developmentDatabase, 'docker desktop restart'), 'development database repair may escalate into Docker Desktop lifecycle control');
$registeredPorts = [];
foreach ($registry['resources']['local_listeners'] ?? [] as $listener) {
    if (!isset($listener['port_env'], $listener['port'])) {
        continue;
    }
    $registeredPorts[$listener['port_env']] = $listener['port'];
}
$requiredPorts = [
    'DEV_HTTP_PORT' => 20187,
    'PHP_PORT' => 20180,
    'VITE_PORT' => 20181,
    'RICH_TEXT_COLLABORATION_PORT' => 20282,
    'PLATFORM_PORT' => 20177,
    'MT_DEMO_PHP_PORT' => 20178,
    'MT_DEMO_VITE_PORT' => 20179,
    'MT_DEMO_PLATFORM_PORT' => 20176,
    'PC_PORT' => 20185,
    'MOBILE_PORT' => 20182,
    'DOCS_PORT' => 20186,
    'PEANUT_GENERATED_APPLICATION_UPGRADE_PORT' => 20283,
    'HTTP_PORT' => 20190,
    'P0E_DB_TUNNEL_PORT' => 20189,
    'MYSQL_PORT' => 20276,
    'CACHE_PORT' => 20277,
    'PEANUT_BROWSER_BACKEND_PORT' => 20278,
    'PEANUT_BROWSER_FRONTEND_PORT' => 20279,
    'PEANUT_STARTER_BACKEND_PORT' => 20280,
    'PEANUT_STARTER_FRONTEND_PORT' => 20281,
];
$expect(array_intersect_key($registeredPorts, $requiredPorts) === $requiredPorts, 'registered local listener ports do not match the Peanut Admin project block');
$redis = array_values(array_filter(
    $registry['resources']['optional_services'] ?? [],
    static fn(array $item): bool => ($item['stable_resource_id'] ?? '') === 'peanut-admin-local-redis-experiment',
));
$expect(count($redis) === 1 && $redis[0]['port_env'] === 'REDIS_PORT' && $redis[0]['port'] === 20184, 'registered Redis port is invalid');

require_once $root . '/server/database/environment-guard.php';

/** @param array<string,string> $values */
function resourceGuardSetEnvironment(array $values): void
{
    foreach ([
        'APP_ENV', 'PEANUT_DEPLOYMENT_TARGET', 'PEANUT_DATABASE_RESOURCE_ID',
        'PEANUT_DATABASE_CONSUMER', 'PEANUT_DATABASE_ENDPOINT_ID',
        'PEANUT_RESOURCE_LEASE_PROOF', 'DB_HOST', 'DB_PORT', 'DB_NAME',
        'DB_USER', 'DB_PASS', 'DEPLOYMENT_MODE',
    ] as $name) {
        putenv($name);
    }
    $environment = new \think\Env();
    foreach ($values as $name => $value) {
        if (str_starts_with($name, 'PEANUT_')) {
            putenv($name . '=' . $value);
        } else {
            $environment->set($name, $value);
        }
    }
    \think\Container::getInstance()->instance('env', $environment);
}

/** @param array<string,string> $overrides @return array<string,string> */
function resourceGuardP0eEnvironment(string $runId, string $scenario, string $mode, array $overrides = []): array
{
    return array_replace([
        'APP_ENV' => 'production',
        'PEANUT_DEPLOYMENT_TARGET' => 'local-production-preview',
        'PEANUT_DATABASE_RESOURCE_ID' => 'peanut-admin-p0e-mysql84-gate',
        'PEANUT_DATABASE_CONSUMER' => 'container',
        'DB_HOST' => 'host.docker.internal',
        'DB_PORT' => '20189',
        'DB_NAME' => 'peanut_admin_development_p0e_' . $runId . '_' . $scenario,
        'DB_USER' => 'guard-test',
        'DB_PASS' => 'guard-test',
        'DEPLOYMENT_MODE' => $mode,
    ], $overrides);
}

/** @param array<string,string> $overrides @return array<string,string> */
function resourceGuardConsumerUpgradeEnvironment(string $runId, string $scenario, string $mode, array $overrides = []): array
{
    return array_replace([
        'APP_ENV' => 'development',
        'PEANUT_DEPLOYMENT_TARGET' => 'local-development',
        'PEANUT_DATABASE_RESOURCE_ID' => 'peanut-admin-consumer-upgrade-mysql84-gate',
        'PEANUT_DATABASE_CONSUMER' => 'host',
        'PEANUT_DATABASE_ENDPOINT_ID' => 'peanut-admin-consumer-upgrade-mysql84-gate-host-direct',
        'DB_HOST' => '192.168.192.2',
        'DB_PORT' => '20183',
        'DB_NAME' => 'peanut_admin_development_cr03_' . $runId . '_' . $scenario,
        'DB_USER' => 'guard-test',
        'DB_PASS' => 'guard-test',
        'DEPLOYMENT_MODE' => $mode,
    ], $overrides);
}

function resourceGuardMustFail(callable $operation, string $case): void
{
    try {
        $operation();
    } catch (RuntimeException) {
        return;
    }
    throw new RuntimeException("environment guard unexpectedly allowed {$case}");
}

$ordinaryEnvironments = [
    'development' => [
        'APP_ENV' => 'development', 'PEANUT_DEPLOYMENT_TARGET' => 'local-development',
        'PEANUT_DATABASE_RESOURCE_ID' => 'peanut-admin-scaffold-mt-development-mysql84',
        'PEANUT_DATABASE_CONSUMER' => 'host',
        'PEANUT_DATABASE_ENDPOINT_ID' => 'peanut-admin-scaffold-mt-development-host-direct',
        'DB_HOST' => '192.168.192.2', 'DB_PORT' => '20183',
        'DB_NAME' => 'peanut_dev_mt', 'DB_USER' => 'test', 'DB_PASS' => 'test',
        'DEPLOYMENT_MODE' => 'multi-tenant',
    ],
    'local-multi-tenant-demo' => [
        'APP_ENV' => 'development', 'PEANUT_DEPLOYMENT_TARGET' => 'local-multi-tenant-demo',
        'PEANUT_DATABASE_RESOURCE_ID' => 'peanut-admin-mysql84-local-multi-tenant-demo',
        'PEANUT_DATABASE_CONSUMER' => 'host',
        'PEANUT_DATABASE_ENDPOINT_ID' => 'peanut-admin-mysql84-local-multi-tenant-demo-host-direct',
        'DB_HOST' => '192.168.192.2', 'DB_PORT' => '20183',
        'DB_NAME' => 'peanut_admin_development_mtlocal01', 'DB_USER' => 'test', 'DB_PASS' => 'test',
        'DEPLOYMENT_MODE' => 'multi-tenant',
    ],
    'production' => [
        'APP_ENV' => 'production', 'PEANUT_DEPLOYMENT_TARGET' => 'production',
        'PEANUT_DATABASE_CONSUMER' => 'container',
        'PEANUT_DATABASE_RESOURCE_ID' => 'peanut-admin-production-bundled-mysql84',
        'DB_HOST' => 'mysql', 'DB_PORT' => '3306', 'DB_NAME' => 'peanut_admin',
        'DB_USER' => 'test', 'DB_PASS' => 'test', 'DEPLOYMENT_MODE' => 'standalone',
    ],
    'production-candidate' => [
        'APP_ENV' => 'production', 'PEANUT_DEPLOYMENT_TARGET' => 'production-candidate',
        'PEANUT_DATABASE_CONSUMER' => 'container',
        'PEANUT_DATABASE_RESOURCE_ID' => 'peanut-admin-production-candidate-mysql84',
        'DB_HOST' => 'mysql', 'DB_PORT' => '3306', 'DB_NAME' => 'peanut_admin_candidate',
        'DB_USER' => 'test', 'DB_PASS' => 'test', 'DEPLOYMENT_MODE' => 'multi-tenant',
    ],
];
foreach ($ordinaryEnvironments as $case => $environment) {
    resourceGuardSetEnvironment($environment);
    $config = guardedDatabaseConfig();
    $expect($config['database'] === $environment['DB_NAME'], "ordinary guard regression: {$case}");
}
resourceGuardSetEnvironment(array_replace($ordinaryEnvironments['development'], [
    'APP_ENV' => 'production', 'PEANUT_DEPLOYMENT_TARGET' => 'local-production-preview',
]));
resourceGuardMustFail(static fn(): array => guardedDatabaseConfig(), 'shared development schema in preview');


$guardRunId = 'run123';
foreach (P0E_FRESH_SCENARIO_MODES as $scenario => $mode) {
    resourceGuardSetEnvironment(resourceGuardP0eEnvironment($guardRunId, $scenario, $mode));
    $config = guardedDatabaseConfig();
    $expect($config['consumer'] === 'container', "P0-E guard did not allow exact scenario {$scenario}");
}
resourceGuardSetEnvironment(resourceGuardP0eEnvironment($guardRunId, 'module_lifecycle', 'standalone'));
resourceGuardMustFail(static fn(): array => guardedDatabaseConfig(), 'Module lifecycle with standalone mode');
resourceGuardSetEnvironment(resourceGuardP0eEnvironment($guardRunId, 'standalone_fresh', 'multi-tenant'));
resourceGuardMustFail(static fn(): array => guardedDatabaseConfig(), 'Standalone fresh with multi-tenant mode');
resourceGuardSetEnvironment(resourceGuardP0eEnvironment(
    $guardRunId,
    'standalone_fresh',
    'standalone',
    [
        'PEANUT_DATABASE_CONSUMER' => 'host',
        'PEANUT_DATABASE_ENDPOINT_ID' => 'peanut-admin-p0e-mysql84-gate-host-direct',
        'DB_HOST' => '192.168.192.2',
        'DB_PORT' => '20183',
    ],
));
$hostConfig = guardedDatabaseConfig();
$expect($hostConfig['consumer'] === 'host', 'P0-E guard did not allow registered Host endpoint');

$p0eRejections = [
    'persistent-db' => ['DB_NAME' => 'peanut_admin_development'],
    'unknown-scenario' => ['DB_NAME' => 'peanut_admin_development_p0e_run123_unknown'],
    'invalid-run-id' => ['DB_NAME' => 'peanut_admin_development_p0e_INVALID_standalone_fresh'],
    'host-with-container-endpoint' => ['PEANUT_DATABASE_CONSUMER' => 'host'],
    'fallback-address' => ['DB_HOST' => '127.0.0.1', 'DB_PORT' => '3306'],
    'production-target' => ['PEANUT_DEPLOYMENT_TARGET' => 'production'],
];
foreach ($p0eRejections as $case => $overrides) {
    resourceGuardSetEnvironment(resourceGuardP0eEnvironment($guardRunId, 'standalone_fresh', 'standalone', $overrides));
    resourceGuardMustFail(static fn(): array => guardedDatabaseConfig(), 'P0-E ' . $case);
}

foreach (CONSUMER_UPGRADE_SCENARIO_MODES as $scenario => $mode) {
    resourceGuardSetEnvironment(resourceGuardConsumerUpgradeEnvironment($guardRunId, $scenario, $mode));
    $config = guardedDatabaseConfig();
    $expect($config['consumer'] === 'host', "consumer-upgrade guard did not allow exact scenario {$scenario}");
}
resourceGuardSetEnvironment(resourceGuardConsumerUpgradeEnvironment($guardRunId, 'multi_tenant_upgrade', 'standalone'));
resourceGuardMustFail(static fn(): array => guardedDatabaseConfig(), 'Consumer upgrade multi-tenant scenario with standalone mode');
$consumerRejections = [
    'production-target' => ['APP_ENV' => 'production', 'PEANUT_DEPLOYMENT_TARGET' => 'production'],
    'container-consumer' => ['PEANUT_DATABASE_CONSUMER' => 'container'],
    'wrong-endpoint-id' => ['PEANUT_DATABASE_ENDPOINT_ID' => 'peanut-admin-p0e-mysql84-gate-host-direct'],
    'wrong-mode' => ['DEPLOYMENT_MODE' => 'multi-tenant'],
    'unknown-scenario' => ['DB_NAME' => 'peanut_admin_development_cr03_run123_unknown'],
];
foreach ($consumerRejections as $case => $overrides) {
    resourceGuardSetEnvironment(resourceGuardConsumerUpgradeEnvironment($guardRunId, 'standalone_upgrade', 'standalone', $overrides));
    resourceGuardMustFail(static fn(): array => guardedDatabaseConfig(), 'consumer-upgrade ' . $case);
}

echo "database resource registry contract passed\n";
