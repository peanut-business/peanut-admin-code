<?php
declare(strict_types=1);

use PeanutAdmin\Kernel\Persistence\Schema\KernelSchema;

require_once dirname(__DIR__) . '/bootstrap/environment.php';

$autoload = dirname(__DIR__) . '/vendor/autoload.php';
if (!is_file($autoload)) {
    throw new RuntimeException('缺少 Composer autoload，无法校验 Core Schema');
}
require_once $autoload;

function requiredEnvironment(string $name): string
{
    $value = getenv($name);
    if ($value === false || trim($value) === '') {
        throw new RuntimeException("缺少环境配置：{$name}");
    }
    return trim($value);
}

/** @return array<string,mixed> */
function projectResourceRegistry(): array
{
    $path = dirname(__DIR__, 2) . '/resources/project-resources.json';
    $raw = file_get_contents($path);
    if (!is_string($raw)) {
        throw new RuntimeException('无法读取项目资源登记');
    }
    try {
        $registry = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
    } catch (JsonException $exception) {
        throw new RuntimeException('项目资源登记不是有效 JSON', 0, $exception);
    }
    if (!is_array($registry)
        || ($registry['schema_version'] ?? null) !== 1
        || !is_string($registry['project_id'] ?? null)
        || !is_array($registry['resources']['databases'] ?? null)) {
        throw new RuntimeException('项目资源登记结构无效');
    }
    return $registry;
}

/** @param array<string,mixed> $registry @return array<string,mixed> */
function registeredDatabase(array $registry, string $resourceId): array
{
    $matches = [];
    foreach ($registry['resources']['databases'] as $database) {
        if (is_array($database) && ($database['stable_resource_id'] ?? null) === $resourceId) {
            $matches[] = $database;
        }
    }
    if (count($matches) !== 1) {
        throw new RuntimeException("数据库资源 {$resourceId} 必须在项目登记中唯一存在");
    }
    return $matches[0];
}

/** @return array{app_environment:string,resource_environment:string,default_consumer:string} */
function deploymentTargetContract(string $deploymentTarget): array
{
    return match ($deploymentTarget) {
        'development-test' => [
            'app_environment' => 'development',
            'resource_environment' => 'development-test',
            'default_consumer' => 'host',
        ],
        'local-development' => [
            'app_environment' => 'development',
            'resource_environment' => 'development',
            'default_consumer' => 'host',
        ],
        'local-production-preview' => [
            'app_environment' => 'production',
            'resource_environment' => 'local-production-preview',
            'default_consumer' => 'container',
        ],
        'local-multi-tenant-demo' => [
            'app_environment' => 'development',
            'resource_environment' => 'local-multi-tenant-demo',
            'default_consumer' => 'host',
        ],
        'production' => [
            'app_environment' => 'production',
            'resource_environment' => 'production',
            'default_consumer' => 'container',
        ],
        'production-candidate' => [
            'app_environment' => 'production',
            'resource_environment' => 'production-candidate',
            'default_consumer' => 'container',
        ],
        default => throw new RuntimeException("不支持的部署目标：{$deploymentTarget}"),
    };
}

/** @param array<string,mixed> $database @return array<string,mixed> */
function registeredDatabaseEndpoint(array $database, string $consumer): array
{
    $key = match ($consumer) {
        'host' => 'upstream_endpoint',
        'container' => 'container_endpoint',
        default => throw new RuntimeException("不支持的数据库 consumer：{$consumer}"),
    };
    $endpoint = $database[$key] ?? null;
    if (!is_array($endpoint) || !in_array($consumer, $endpoint['consumers'] ?? [], true)) {
        throw new RuntimeException("数据库资源缺少已登记的 {$consumer} endpoint");
    }
    foreach (['endpoint_id', 'host', 'port'] as $field) {
        if (!is_string($endpoint[$field] ?? null) && !is_int($endpoint[$field] ?? null)) {
            throw new RuntimeException("数据库 endpoint 缺少 {$field}");
        }
    }
    return $endpoint;
}

/** @return array<string,string> */
function activeLeaseMetadata(string $proofPath, int $now, string $expectedGate): array
{
    $metadataPath = $proofPath . '/metadata.tsv';
    if (!is_dir($proofPath) || is_link($proofPath) || !is_file($metadataPath) || is_link($metadataPath)) {
        throw new RuntimeException('active lease metadata 不可用');
    }
    $lines = file($metadataPath, FILE_IGNORE_NEW_LINES);
    if (!is_array($lines)) {
        throw new RuntimeException('active lease metadata 无法读取');
    }
    $metadata = [];
    foreach ($lines as $line) {
        $fields = explode("\t", $line);
        if (count($fields) !== 2 || $fields[0] === '' || $fields[1] === '' || isset($metadata[$fields[0]])) {
            throw new RuntimeException('active lease metadata 格式无效');
        }
        $metadata[$fields[0]] = $fields[1];
    }
    $expectedKeys = ['lease', 'owner', 'thread', 'candidate', 'candidate_repository', 'gate', 'worktree', 'created_at', 'expires_at', 'status'];
    $actualKeys = array_keys($metadata);
    sort($expectedKeys, SORT_STRING);
    sort($actualKeys, SORT_STRING);
    if ($actualKeys !== $expectedKeys
        || preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{0,63}$/D', $metadata['lease']) !== 1
        || preg_match('/^[a-f0-9]{40}$/D', $metadata['candidate']) !== 1
        || $metadata['gate'] !== $expectedGate
        || !str_starts_with($metadata['candidate_repository'], '/')
        || !str_starts_with($metadata['worktree'], '/')
        || preg_match('/^[0-9]+$/D', $metadata['created_at']) !== 1
        || preg_match('/^[0-9]+$/D', $metadata['expires_at']) !== 1
        || $metadata['status'] !== 'ACTIVE'
        || (int)$metadata['created_at'] > $now
        || (int)$metadata['expires_at'] <= $now) {
        throw new RuntimeException('active lease 未激活、已过期或 metadata 合同不匹配');
    }
    return $metadata;
}

/** @return array<string,list<string>> */
function activeLeaseResources(string $proofPath): array
{
    $resourcesPath = $proofPath . '/resources.tsv';
    if (!is_file($resourcesPath) || is_link($resourcesPath)) {
        throw new RuntimeException('active lease resources 不可用');
    }
    $lines = file($resourcesPath, FILE_IGNORE_NEW_LINES);
    if (!is_array($lines)) {
        throw new RuntimeException('active lease resources 无法读取');
    }
    $resources = [];
    $pairs = [];
    foreach ($lines as $line) {
        $fields = explode("\t", $line);
        if (count($fields) !== 3 || $fields[0] === '' || $fields[1] === '' || $fields[2] === '') {
            throw new RuntimeException('P0-E active lease resources 格式无效');
        }
        [$digest, $type, $value] = $fields;
        if (preg_match('/^[a-f0-9]{64}$/D', $digest) !== 1
            || !hash_equals(hash('sha256', $type . "\t" . $value), $digest)
            || preg_match('/^[a-z][a-z0-9_-]{0,31}$/D', $type) !== 1
            || isset($pairs[$type . "\t" . $value])) {
            throw new RuntimeException('active lease resource identity 无效');
        }
        $pairs[$type . "\t" . $value] = true;
        $resources[$type] ??= [];
        $resources[$type][] = $value;
    }
    foreach ($resources as &$values) {
        sort($values, SORT_STRING);
    }
    unset($values);
    ksort($resources, SORT_STRING);
    return $resources;
}

/** @param array<string,mixed> $database @return array{run_id:string,scenario:string} */
function templatedDatabaseIdentity(array $database, string $name): array
{
    $template = $database['database'] ?? null;
    $allowedScenarios = $database['allowed_scenarios'] ?? null;
    if (!is_string($template)
        || substr_count($template, '<run_id>') !== 1
        || substr_count($template, '<scenario>') !== 1
        || !is_array($allowedScenarios)
        || $allowedScenarios === []) {
        throw new RuntimeException('P0-E database template 登记无效');
    }
    $matcher = preg_quote($template, '/');
    $matcher = str_replace(
        [preg_quote('<run_id>', '/'), preg_quote('<scenario>', '/')],
        ['(?P<run_id>.+?)', '(?P<scenario>.+?)'],
        $matcher
    );
    if (preg_match('/^' . $matcher . '$/D', $name, $matches) !== 1
        || !in_array($matches['scenario'], $allowedScenarios, true)) {
        throw new RuntimeException('P0-E database 不在登记的 template/scenario 集合内');
    }
    $runIdPattern = $database['run_id_pattern'] ?? null;
    if (!is_string($runIdPattern)
        || preg_match('/' . str_replace('/', '\\/', $runIdPattern) . '/D', $matches['run_id']) !== 1) {
        throw new RuntimeException('P0-E database run_id 不符合登记规则');
    }
    $maxLength = $database['database_name_max_length'] ?? null;
    if (!is_int($maxLength) || $maxLength < 1 || strlen($name) > $maxLength) {
        throw new RuntimeException('P0-E database 名称超过登记上限');
    }
    return ['run_id' => $matches['run_id'], 'scenario' => $matches['scenario']];
}

/** @param array<string,list<string>> $resources @param list<string> $expected */
function assertLeaseResourceValues(array $resources, string $type, array $expected): void
{
    sort($expected, SORT_STRING);
    if (($resources[$type] ?? null) !== $expected) {
        throw new RuntimeException("P0-E lease resource {$type} 不匹配精确合同");
    }
}

function isLexicallyAbsolutePath(string $path): bool
{
    return str_starts_with($path, '/')
        && !str_contains($path, "\0")
        && !str_contains($path, '/./')
        && !str_contains($path, '/../')
        && !str_ends_with($path, '/.')
        && !str_ends_with($path, '/..');
}

/** @param list<string> $arguments */
function leaseGit(array $arguments): string
{
    $pipes = [];
    $process = proc_open(
        array_merge(['/usr/bin/git'], $arguments),
        [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes
    );
    if (!is_resource($process)) {
        throw new RuntimeException('consumer-upgrade candidate Git identity 不可用');
    }
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    if (proc_close($process) !== 0 || !is_string($stdout)) {
        throw new RuntimeException('consumer-upgrade candidate Git identity 无法解析: ' . trim((string)$stderr));
    }
    return trim($stdout);
}

/**
 * The Host-only consumer-upgrade guard accepts only the live lease directory
 * owned by the candidate repository's Git common directory.
 *
 * @param array<string,string> $metadata
 * @param array<string,list<string>> $resources
 */
function assertConsumerUpgradeCandidateLeaseIdentity(
    string $proofPath,
    array $metadata,
    array $resources
): void {
    $candidateRepository = realpath($metadata['candidate_repository']);
    $worktree = realpath($metadata['worktree']);
    $actualProofPath = realpath($proofPath);
    if (!is_string($candidateRepository)
        || !is_dir($candidateRepository)
        || $candidateRepository !== $metadata['candidate_repository']
        || !is_string($worktree)
        || $worktree !== $metadata['worktree']
        || $worktree !== $candidateRepository
        || !is_string($actualProofPath)
        || $actualProofPath !== $proofPath) {
        throw new RuntimeException('consumer-upgrade candidate/proof 路径不是可信实体');
    }

    $commonDirectory = leaseGit(['-C', $candidateRepository, 'rev-parse', '--git-common-dir']);
    if (!str_starts_with($commonDirectory, '/')) {
        $commonDirectory = $candidateRepository . '/' . $commonDirectory;
    }
    $commonDirectory = realpath($commonDirectory);
    if (!is_string($commonDirectory)) {
        throw new RuntimeException('consumer-upgrade candidate Git common-dir 不可用');
    }
    $expectedProofPath = $commonDirectory . '/peanut-admin-resource-leases/leases/' . $metadata['lease'];
    if (!hash_equals($expectedProofPath, $actualProofPath)
        || ($resources['lease-proof-dir'] ?? null) !== [$actualProofPath]) {
        throw new RuntimeException('consumer-upgrade proof 不是 candidate repository 的 active lease 坐标');
    }

    $candidate = leaseGit(['-C', $candidateRepository, 'rev-parse', $metadata['candidate'] . '^{commit}']);
    $head = leaseGit(['-C', $candidateRepository, 'rev-parse', 'HEAD^{commit}']);
    $tree = leaseGit(['-C', $candidateRepository, 'rev-parse', $candidate . '^{tree}']);
    if (!hash_equals($metadata['candidate'], $candidate)
        || !hash_equals($candidate, $head)
        || ($resources['candidate-tree'] ?? null) !== [$tree]) {
        throw new RuntimeException('consumer-upgrade candidate commit/tree 与实际 Git identity 不一致');
    }
}

/**
 * @param array<string,string> $metadata
 * @param array<string,list<string>> $resources
 * @param array<string,mixed> $database
 * @param array<string,mixed> $endpoint
 * @param array{run_id:string,scenario:string} $identity
 */
function assertP0eLeaseContract(
    array $metadata,
    array $resources,
    array $database,
    array $endpoint,
    array $identity,
    string $resourceId,
    string $deploymentTarget,
    string $deploymentMode
): void {
    $expectedCounts = [
        'browser-host' => 2,
        'browser-session' => 1,
        'cache-dir' => 1,
        'candidate-tree' => 1,
        'compose-project' => 1,
        'consumer' => 2,
        'database-tunnel' => 1,
        'deployment-mode' => 2,
        'deployment-target' => 1,
        'docs-port' => 1,
        'endpoint' => 2,
        'environment' => 1,
        'gate' => 1,
        'http-port' => 1,
        'lease-proof-dir' => 1,
        'mysql-db' => count($database['allowed_scenarios']),
        'output-dir' => 1,
        'port' => 3,
        'resource-id' => 1,
        'run-id' => 1,
        'worktree' => 1,
    ];
    $actualCounts = [];
    foreach ($resources as $type => $values) {
        $actualCounts[$type] = count($values);
    }
    ksort($actualCounts, SORT_STRING);
    if ($actualCounts !== $expectedCounts || array_sum($actualCounts) !== array_sum($expectedCounts)) {
        throw new RuntimeException('P0-E lease resource set 存在缺失、额外项或 cardinality 冲突');
    }

    $runId = $identity['run_id'];
    $allowedScenarios = $database['allowed_scenarios'];
    $expectedDatabases = array_map(
        static fn(string $scenario): string => str_replace(
            ['<run_id>', '<scenario>'],
            [$runId, $scenario],
            (string)$database['database']
        ),
        $allowedScenarios
    );
    assertLeaseResourceValues($resources, 'resource-id', [$resourceId]);
    assertLeaseResourceValues($resources, 'environment', ['development']);
    assertLeaseResourceValues($resources, 'deployment-target', [$deploymentTarget]);
    assertLeaseResourceValues($resources, 'consumer', ['container', 'host']);
    $registeredEndpoints = [];
    foreach (['upstream_endpoint', 'container_endpoint'] as $endpointKey) {
        $registered = $database[$endpointKey] ?? null;
        if (!is_array($registered)) {
            throw new RuntimeException("P0-E database {$endpointKey} 登记缺失");
        }
        $registeredEndpoints[] = (string)$registered['host'] . ':' . (string)$registered['port'];
    }
    assertLeaseResourceValues($resources, 'endpoint', $registeredEndpoints);
    assertLeaseResourceValues($resources, 'run-id', [$runId]);
    assertLeaseResourceValues($resources, 'mysql-db', $expectedDatabases);
    assertLeaseResourceValues($resources, 'deployment-mode', ['multi-tenant', 'standalone']);
    assertLeaseResourceValues($resources, 'port', ['20186', '20189', '20190']);
    assertLeaseResourceValues($resources, 'http-port', ['20190']);
    assertLeaseResourceValues($resources, 'docs-port', ['20186']);
    assertLeaseResourceValues(
        $resources,
        'database-tunnel',
        ['peanut-admin-p0e-mysql84-container-tunnel']
    );
    assertLeaseResourceValues($resources, 'compose-project', ['peanut-p0e-' . $runId]);
    assertLeaseResourceValues($resources, 'browser-session', ['p0e-' . $runId]);
    assertLeaseResourceValues($resources, 'browser-host', ['admin.p0e.localhost', 'platform.p0e.localhost']);
    assertLeaseResourceValues($resources, 'gate', [$metadata['gate']]);
    assertLeaseResourceValues($resources, 'worktree', [$metadata['worktree']]);
    if ($metadata['lease'] !== 'p0e-runtime-' . $runId
        || $metadata['candidate_repository'] !== $metadata['worktree']
        || !isLexicallyAbsolutePath($metadata['worktree'])
        || preg_match('/^[a-f0-9]{40}$/D', $resources['candidate-tree'][0]) !== 1) {
        throw new RuntimeException('P0-E lease candidate/run_id/worktree identity 不匹配');
    }

    $outputDir = $resources['output-dir'][0];
    $cacheDir = $resources['cache-dir'][0];
    $proofDir = $resources['lease-proof-dir'][0];
    if (!isLexicallyAbsolutePath($outputDir)
        || !isLexicallyAbsolutePath($cacheDir)
        || !isLexicallyAbsolutePath($proofDir)
        || $outputDir !== rtrim($metadata['worktree'], '/') . '/output/p0e-' . $runId
        || basename($cacheDir) !== 'p0e-' . $runId
        || !str_ends_with($cacheDir, '/.cache/peanut-admin/p0e-' . $runId)
        || !str_ends_with($proofDir, '/peanut-admin-resource-leases/leases/' . $metadata['lease'])) {
        throw new RuntimeException('P0-E lease path identity 不匹配精确 run_id 合同');
    }

    $multiTenantScenarios = ['multi_tenant_fresh', 'plugin_lifecycle', 'multi_tenant_browser'];
    $expectedMode = in_array($identity['scenario'], $multiTenantScenarios, true)
        ? 'multi-tenant'
        : 'standalone';
    if (!hash_equals($expectedMode, $deploymentMode)) {
        throw new RuntimeException('P0-E deployment mode 与 database scenario 不匹配');
    }
}

/**
 * @param array<string,string> $metadata
 * @param array<string,list<string>> $resources
 * @param array<string,mixed> $database
 * @param array{run_id:string,scenario:string} $identity
 */
function assertConsumerUpgradeLeaseContract(
    string $proofPath,
    array $metadata,
    array $resources,
    array $database,
    array $identity,
    string $resourceId,
    string $deploymentTarget,
    string $deploymentMode
): void {
    $expectedCounts = [
        'backup-root' => 2,
        'cache-dir' => 1,
        'candidate-tree' => 1,
        'consumer' => 1,
        'deployment-target' => 1,
        'endpoint' => 1,
        'environment' => 1,
        'gate' => 1,
        'http-port' => 1,
        'instance-root' => 2,
        'lease-proof-dir' => 1,
        'listener-resource-id' => 1,
        'mysql-db' => count($database['allowed_scenarios']),
        'object-prefix' => count($database['allowed_scenarios']),
        'object-storage-resource-id' => 1,
        'output-dir' => 1,
        'port' => 1,
        'resource-id' => 1,
        'run-id' => 1,
        'tooling-resource-id' => 1,
        'worktree' => 1,
    ];
    $actualCounts = [];
    foreach ($resources as $type => $values) $actualCounts[$type] = count($values);
    ksort($actualCounts, SORT_STRING);
    if ($actualCounts !== $expectedCounts || array_sum($actualCounts) !== array_sum($expectedCounts)) {
        throw new RuntimeException('consumer-upgrade lease resource set 存在缺失、额外项或 cardinality 冲突');
    }

    $runId = $identity['run_id'];
    $scenarios = $database['allowed_scenarios'];
    $expectedDatabases = array_map(
        static fn(string $scenario): string => str_replace(['<run_id>', '<scenario>'], [$runId, $scenario], (string)$database['database']),
        $scenarios
    );
    assertLeaseResourceValues($resources, 'resource-id', [$resourceId]);
    assertLeaseResourceValues($resources, 'environment', ['development']);
    assertLeaseResourceValues($resources, 'deployment-target', [$deploymentTarget]);
    assertLeaseResourceValues($resources, 'consumer', ['host']);
    assertLeaseResourceValues($resources, 'endpoint', [(string)$database['upstream_endpoint']['host'] . ':' . (string)$database['upstream_endpoint']['port']]);
    assertLeaseResourceValues($resources, 'run-id', [$runId]);
    assertLeaseResourceValues($resources, 'mysql-db', $expectedDatabases);
    assertLeaseResourceValues($resources, 'tooling-resource-id', [(string)$database['administrative_tooling_resource_id']]);
    assertLeaseResourceValues($resources, 'listener-resource-id', [(string)$database['http_listener_resource_id']]);
    assertLeaseResourceValues($resources, 'object-storage-resource-id', [(string)$database['local_object_storage_resource_id']]);
    assertLeaseResourceValues($resources, 'port', ['20190']);
    assertLeaseResourceValues($resources, 'http-port', ['20190']);
    assertLeaseResourceValues(
        $resources,
        'object-prefix',
        array_map(static fn(string $scenario): string => 'cr03/' . $runId . '/' . $scenario . '/', $scenarios)
    );
    assertLeaseResourceValues($resources, 'gate', [$metadata['gate']]);
    assertLeaseResourceValues($resources, 'worktree', [$metadata['worktree']]);
    if ($metadata['lease'] !== 'consumer-upgrade-' . $runId
        || $metadata['candidate_repository'] !== $metadata['worktree']
        || !isLexicallyAbsolutePath($metadata['worktree'])
        || preg_match('/^[a-f0-9]{40}$/D', $resources['candidate-tree'][0]) !== 1) {
        throw new RuntimeException('consumer-upgrade lease candidate/run_id/worktree identity 不匹配');
    }
    assertConsumerUpgradeCandidateLeaseIdentity($proofPath, $metadata, $resources);

    $cacheDir = $resources['cache-dir'][0];
    $outputDir = $resources['output-dir'][0];
    $proofDir = $resources['lease-proof-dir'][0];
    $expectedRoots = array_map(static fn(string $scenario): string => $cacheDir . '/instances/' . $scenario, $scenarios);
    $expectedBackups = array_map(static fn(string $scenario): string => $cacheDir . '/backups/' . $scenario, $scenarios);
    if (!isLexicallyAbsolutePath($cacheDir)
        || !isLexicallyAbsolutePath($outputDir)
        || !isLexicallyAbsolutePath($proofDir)
        || $outputDir !== rtrim($metadata['worktree'], '/') . '/output/cr03-upgrade-' . $runId
        || !str_ends_with($cacheDir, '/.cache/peanut-admin/cr03-upgrade-' . $runId)
        || !str_ends_with($proofDir, '/peanut-admin-resource-leases/leases/' . $metadata['lease'])) {
        throw new RuntimeException('consumer-upgrade lease path identity 不匹配精确 run_id 合同');
    }
    assertLeaseResourceValues($resources, 'instance-root', $expectedRoots);
    assertLeaseResourceValues($resources, 'backup-root', $expectedBackups);
    $expectedMode = $identity['scenario'] === 'multi_tenant_upgrade' ? 'multi-tenant' : 'standalone';
    if (!hash_equals($expectedMode, $deploymentMode)) {
        throw new RuntimeException('consumer-upgrade deployment mode 与 database scenario 不匹配');
    }
}

/** @return array{environment:string,deployment_target:string,resource_id:string,endpoint_id:string,consumer:string,host:string,port:string,database:string,user:string,password:string} */
function guardedDatabaseConfig(?string $leaseProofPath = null, ?int $now = null): array {
    $registry = projectResourceRegistry();
    $environment = requiredEnvironment('APP_ENV');
    $deploymentTarget = requiredEnvironment('PEANUT_DEPLOYMENT_TARGET');
    $target = deploymentTargetContract($deploymentTarget);
    if (!hash_equals($target['app_environment'], $environment)) {
        throw new RuntimeException("APP_ENV={$environment} 与部署目标 {$deploymentTarget} 不匹配");
    }

    $resourceId = requiredEnvironment('PEANUT_DATABASE_RESOURCE_ID');
    $database = registeredDatabase($registry, $resourceId);
    $registeredName = $database['database'] ?? null;
    $isTemplated = is_string($registeredName)
        && (str_contains($registeredName, '<run_id>') || str_contains($registeredName, '<scenario>'));
    $environments = $database['environments'] ?? [$database['environment'] ?? null];
    $resourceEnvironment = $isTemplated ? 'development' : $target['resource_environment'];
    if (!is_array($environments) || !in_array($resourceEnvironment, $environments, true)) {
        throw new RuntimeException("数据库资源 {$resourceId} 未登记为 {$target['resource_environment']} 环境");
    }
    if (($database['service_type'] ?? null) !== 'mysql' || ($database['fallback'] ?? null) !== 'none') {
        throw new RuntimeException("数据库资源 {$resourceId} 的服务类型或 fallback 不符合门禁");
    }

    $configuredConsumer = getenv('PEANUT_DATABASE_CONSUMER');
    $consumer = $configuredConsumer === false || trim($configuredConsumer) === ''
        ? $target['default_consumer']
        : trim($configuredConsumer);
    $endpoint = registeredDatabaseEndpoint($database, $consumer);
    $configuredEndpointId = getenv('PEANUT_DATABASE_ENDPOINT_ID');
    if ($configuredEndpointId !== false && trim($configuredEndpointId) !== ''
        && !hash_equals((string)$endpoint['endpoint_id'], trim($configuredEndpointId))) {
        throw new RuntimeException("数据库资源 {$resourceId} 的 endpoint identity 不匹配登记值");
    }

    $actual = [
        'host' => requiredEnvironment('DB_HOST'),
        'port' => requiredEnvironment('DB_PORT'),
        'database' => requiredEnvironment('DB_NAME'),
    ];
    if (!hash_equals((string)$endpoint['host'], $actual['host'])
        || !hash_equals((string)$endpoint['port'], $actual['port'])) {
        throw new RuntimeException("数据库资源 {$resourceId} 的地址不匹配登记值");
    }
    $deploymentMode = requiredEnvironment('DEPLOYMENT_MODE');
    if ($isTemplated) {
        if (($database['application_runtime'] ?? null) !== false
            || ($database['lifecycle'] ?? null) !== 'ephemeral') {
            throw new RuntimeException('templated database 必须是登记的 ephemeral qualification resource');
        }
        $identity = templatedDatabaseIdentity($database, $actual['database']);
        if ($resourceId === 'peanut-admin-p0e-mysql84-gate') {
            if ($deploymentTarget !== 'local-production-preview' || !in_array($consumer, ['host', 'container'], true)) {
                throw new RuntimeException('P0-E 只允许固定 production-preview 容器 lease proof');
            }
            if ($leaseProofPath === null) {
                $leaseProofPath = requiredEnvironment('PEANUT_RESOURCE_LEASE_PROOF');
                if ($leaseProofPath !== '/run/peanut-admin/resource-lease') {
                    throw new RuntimeException('P0-E 容器必须使用固定只读 active-lease proof mount');
                }
            }
            $metadata = activeLeaseMetadata($leaseProofPath, $now ?? time(), 'p0e-runtime-qualification');
            $resources = activeLeaseResources($leaseProofPath);
            assertP0eLeaseContract($metadata, $resources, $database, $endpoint, $identity, $resourceId, $deploymentTarget, $deploymentMode);
        } elseif ($resourceId === 'peanut-admin-consumer-upgrade-mysql84-gate') {
            if ($deploymentTarget !== 'local-development' || $consumer !== 'host') {
                throw new RuntimeException('consumer-upgrade 只允许 lease-bound local-development Host execution');
            }
            $leaseProofPath ??= requiredEnvironment('PEANUT_RESOURCE_LEASE_PROOF');
            $metadata = activeLeaseMetadata($leaseProofPath, $now ?? time(), 'consumer-upgrade-qualification');
            $resources = activeLeaseResources($leaseProofPath);
                assertConsumerUpgradeLeaseContract($leaseProofPath, $metadata, $resources, $database, $identity, $resourceId, $deploymentTarget, $deploymentMode);
        } else {
            throw new RuntimeException('templated database resource 未获 qualification guard 授权');
        }
    } elseif ($deploymentTarget === 'development-test') {
        if ($consumer !== 'host' || ($database['application_runtime'] ?? null) !== false
            || ($database['lifecycle'] ?? null) !== 'ephemeral'
            || !is_string($database['lease_gate'] ?? null)) {
            throw new RuntimeException('development-test 必须使用已登记的临时 Host 资源');
        }
        $names = [$registeredName];
        foreach (($database['synthetic_databases'] ?? []) as $group) {
            if (!is_array($group) || !array_is_list($group)) {
                throw new RuntimeException('synthetic database 登记格式无效');
            }
            $names = [...$names, ...$group];
        }
        foreach ($names as $name) {
            if (!is_string($name) || preg_match('/^[a-z0-9_]{1,64}$/D', $name) !== 1) {
                throw new RuntimeException('synthetic database 必须登记精确名称');
            }
        }
        if (!in_array($actual['database'], $names, true)
            || !in_array($deploymentMode, $database['deployment_modes'] ?? [], true)) {
            throw new RuntimeException('development-test database 或 deployment mode 未登记');
        }
        $leaseProofPath ??= requiredEnvironment('PEANUT_RESOURCE_LEASE_PROOF');
        $metadata = activeLeaseMetadata($leaseProofPath, $now ?? time(), $database['lease_gate']);
        $resources = activeLeaseResources($leaseProofPath);
        $root = realpath(dirname(__DIR__, 2));
        $common = leaseGit(['-C', (string)$root, 'rev-parse', '--path-format=absolute', '--git-common-dir']);
        $expectedProof = $common . '/peanut-admin-resource-leases/leases/' . $metadata['lease'];
        if ($metadata['owner'] !== ($database['owner'] ?? null)
            || realpath($metadata['worktree']) !== $root
            || realpath($metadata['candidate_repository']) !== $root
            || realpath($leaseProofPath) !== realpath($expectedProof)
            || !in_array($resourceId, $resources['database-resource'] ?? [], true)
            || !in_array($actual['database'], $resources['mysql-db'] ?? [], true)
            || !in_array($actual['port'], $resources['port'] ?? [], true)) {
            throw new RuntimeException('development-test active lease 与当前资源/工作树不匹配');
        }
    } else {
        if (!is_string($registeredName) || !hash_equals($registeredName, $actual['database'])) {
            throw new RuntimeException("数据库资源 {$resourceId} 的 database 不匹配固定登记值");
        }
        $deploymentModes = $database['deployment_modes'] ?? ['standalone'];
        if (!is_array($deploymentModes)
            || $deploymentModes === []
            || array_filter($deploymentModes, static fn(mixed $mode): bool => !is_string($mode) || $mode === '') !== []) {
            throw new RuntimeException("数据库资源 {$resourceId} 的 deployment_modes 登记无效");
        }
        if (!in_array($deploymentMode, $deploymentModes, true)) {
            throw new RuntimeException("数据库资源 {$resourceId} 不允许 DEPLOYMENT_MODE={$deploymentMode}");
        }
    }

    return [
        'environment' => $environment,
        'deployment_target' => $deploymentTarget,
        'resource_id' => $resourceId,
        'endpoint_id' => (string)$endpoint['endpoint_id'],
        'consumer' => $consumer,
        ...$actual,
        'user' => requiredEnvironment('DB_USER'),
        'password' => requiredEnvironment('DB_PASS'),
    ];
}

function guardedConnection(array $config): PDO
{
    return new PDO(
        sprintf(
            'mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
            $config['host'],
            $config['port'],
            $config['database']
        ),
        $config['user'],
        $config['password'],
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]
    );
}

function waitForDatabase(array $config, int $seconds): PDO
{
    $deadline = time() + $seconds;
    do {
        try {
            return guardedConnection($config);
        } catch (PDOException $exception) {
            if (time() >= $deadline) {
                throw new RuntimeException('数据库资源在等待窗口内不可连接', 0, $exception);
            }
            sleep(2);
        }
    } while (true);
}

/** @return list<string> */
function canonicalBaselineTables(): array
{
    $tables = array_fill_keys(KernelSchema::tableNames(), true);
    $schema = file_get_contents(__DIR__ . '/init.sql');
    if (!is_string($schema)) {
        throw new RuntimeException('无法读取完整基线 init.sql');
    }
    preg_match_all('/CREATE\s+TABLE(?:\s+IF\s+NOT\s+EXISTS)?\s+`([^`]+)`/i', $schema, $matches);
    foreach ($matches[1] ?? [] as $table) {
        $tables[(string)$table] = true;
    }
    $names = array_keys($tables);
    sort($names, SORT_STRING);
    return $names;
}

/** @param array<string,list<string>> $requirements */
function assertRequiredColumns(PDO $pdo, array $requirements): void
{
    $statement = $pdo->prepare(
        'SELECT COLUMN_NAME FROM information_schema.COLUMNS '
        . 'WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?'
    );
    foreach ($requirements as $table => $expected) {
        $statement->execute([$table]);
        $actual = array_map('strval', $statement->fetchAll(PDO::FETCH_COLUMN));
        $missing = array_values(array_diff($expected, $actual));
        if ($missing !== []) {
            throw new RuntimeException("数据库关键列缺失：{$table}." . implode(', ', $missing));
        }
    }
}

/** @param array<string,list<string>> $requirements */
function assertRequiredIndexes(PDO $pdo, array $requirements): void
{
    $statement = $pdo->prepare(
        'SELECT DISTINCT INDEX_NAME FROM information_schema.STATISTICS '
        . 'WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?'
    );
    foreach ($requirements as $table => $expected) {
        $statement->execute([$table]);
        $actual = array_map('strval', $statement->fetchAll(PDO::FETCH_COLUMN));
        $missing = array_values(array_diff($expected, $actual));
        if ($missing !== []) {
            throw new RuntimeException("数据库关键索引缺失：{$table}." . implode(', ', $missing));
        }
    }
}

/** @return array{baseline_table_count:int,installed_table_count:int,application_migration_count:int,module_migration_count:int,management_member_count:int,menu_count:int,config_count:int,permission_count:int,tenant_count:int,owner_count:int,operator_count:int} */
function assertCurrentDatabase(PDO $pdo): array
{
    $expected = canonicalBaselineTables();
    $actual = array_map('strval', $pdo->query(
        'SELECT TABLE_NAME FROM information_schema.TABLES '
        . 'WHERE TABLE_SCHEMA = DATABASE() ORDER BY TABLE_NAME'
    )->fetchAll(PDO::FETCH_COLUMN));
    $missing = array_values(array_diff($expected, $actual));
    if ($missing !== []) {
        throw new RuntimeException('数据库缺少 v3.0 基线表：' . implode(', ', $missing));
    }

    assertRequiredColumns($pdo, [
        'pa_account' => ['id', 'status', 'security_revision'],
        'pa_credential' => ['account_id', 'identifier_normalized', 'secret_hash', 'status'],
        'pa_tenant' => ['id', 'code', 'status', 'authorization_revision'],
        'pa_tenant_member' => ['tenant_id', 'account_id', 'status', 'authorization_revision'],
        'pa_permission' => ['key', 'module_key', 'status'],
        'pa_system_menu' => ['id', 'type', 'perms', 'component'],
        'pa_config' => ['type', 'name', 'value'],
        'pa_module_migration' => ['module_key', 'migration_key', 'checksum', 'status'],
        'pa_schema_migration' => ['migration_id', 'release_version', 'checksum', 'status'],
    ]);
    assertRequiredIndexes($pdo, [
        'pa_tenant' => ['PRIMARY', 'uk_tenant_code'],
        'pa_tenant_member' => ['PRIMARY', 'uk_tenant_member_account'],
        'pa_permission' => ['PRIMARY', 'uk_permission_key'],
        'pa_system_menu' => ['PRIMARY'],
        'pa_config' => ['PRIMARY', 'uk_type_name'],
        'pa_module_migration' => ['PRIMARY', 'uk_module_migration'],
        'pa_schema_migration' => ['PRIMARY', 'idx_schema_migration_status'],
    ]);

    $menuCount = (int)$pdo->query('SELECT COUNT(*) FROM pa_system_menu')->fetchColumn();
    $configCount = (int)$pdo->query('SELECT COUNT(*) FROM pa_config')->fetchColumn();
    $permissionCount = (int)$pdo->query(
        "SELECT COUNT(*) FROM pa_permission WHERE status = 'active'"
    )->fetchColumn();
    $moduleMigrationCount = (int)$pdo->query('SELECT COUNT(*) FROM pa_module_migration')->fetchColumn();
    $applicationMigrationCount = (int)$pdo->query("SELECT COUNT(*) FROM pa_schema_migration WHERE status = 'applied'")->fetchColumn();
    $tenantCount = (int)$pdo->query(
        "SELECT COUNT(*) FROM pa_tenant WHERE code = 'default' AND status = 'active'"
    )->fetchColumn();
    $ownerCount = (int)$pdo->query(<<<'SQL'
SELECT COUNT(DISTINCT tm.id)
FROM pa_tenant t
JOIN pa_tenant_member tm ON tm.tenant_id = t.id AND tm.status = 'active'
JOIN pa_account a ON a.id = tm.account_id AND a.status = 'active'
JOIN pa_credential c ON c.account_id = a.id AND c.status = 'active'
JOIN pa_member_role mr ON mr.tenant_id = tm.tenant_id AND mr.tenant_member_id = tm.id
JOIN pa_role r ON r.tenant_id = mr.tenant_id AND r.id = mr.role_id
WHERE t.code = 'default' AND t.status = 'active' AND r.`key` = 'core.tenant-owner'
SQL)->fetchColumn();
    $operatorCount = (int)$pdo->query(<<<'SQL'
SELECT COUNT(DISTINCT po.id)
FROM pa_platform_operator po
JOIN pa_account a ON a.id = po.account_id AND a.status = 'active'
JOIN pa_credential c ON c.account_id = a.id AND c.status = 'active'
JOIN pa_platform_operator_role por ON por.platform_operator_id = po.id
JOIN pa_platform_role pr ON pr.id = por.platform_role_id
WHERE po.status = 'active'
  AND pr.`key` = 'platform.bootstrap-owner'
  AND pr.is_builtin = 1
  AND pr.status = 'active'
SQL)->fetchColumn();
    $deploymentMode = requiredEnvironment('DEPLOYMENT_MODE');
    if (!in_array($deploymentMode, ['standalone', 'multi-tenant'], true)) {
        throw new RuntimeException('DEPLOYMENT_MODE 必须是 standalone 或 multi-tenant');
    }
    if ($menuCount < 1
        || $configCount < 1
        || $permissionCount < 1
        || $tenantCount !== 1
        || $ownerCount < 1
        || ($deploymentMode === 'multi-tenant' && $operatorCount < 1)) {
        throw new RuntimeException('数据库基线数据不完整');
    }

    return [
        'baseline_table_count' => count($expected),
        'installed_table_count' => count($actual),
        'application_migration_count' => $applicationMigrationCount,
        'module_migration_count' => $moduleMigrationCount,
        'management_member_count' => $ownerCount,
        'menu_count' => $menuCount,
        'config_count' => $configCount,
        'permission_count' => $permissionCount,
        'tenant_count' => $tenantCount,
        'owner_count' => $ownerCount,
        'operator_count' => $operatorCount,
    ];
}

/** @param list<string> $arguments */
function environmentGuardMain(array $arguments): int
{
    try {
        $config = guardedDatabaseConfig();
        $validateConfigOnly = in_array('--validate-config', $arguments, true);
        $wait = 0;
        foreach ($arguments as $argument) {
            if (preg_match('/^--wait=(\d+)$/D', (string)$argument, $matches) === 1) {
                $wait = min(300, max(0, (int)$matches[1]));
            }
        }
        $result = [
            'environment' => $config['environment'],
            'deployment_target' => $config['deployment_target'],
            'resource_id' => $config['resource_id'],
            'endpoint_id' => $config['endpoint_id'],
            'consumer' => $config['consumer'],
            'host' => $config['host'],
            'port' => (int)$config['port'],
            'database' => $config['database'],
            'status' => 'validated',
        ];
        if (!$validateConfigOnly) {
            $pdo = $wait > 0 ? waitForDatabase($config, $wait) : guardedConnection($config);
            $result['status'] = 'connected';
            if (in_array('--current', $arguments, true)) {
                $result += assertCurrentDatabase($pdo);
                $result['status'] = 'current';
            }
        }
        echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT), PHP_EOL;
        return 0;
    } catch (Throwable $exception) {
        fwrite(STDERR, '数据库环境门禁失败：' . $exception->getMessage() . PHP_EOL);
        return 1;
    }
}

if (realpath((string)($_SERVER['SCRIPT_FILENAME'] ?? '')) === __FILE__) {
    exit(environmentGuardMain($_SERVER['argv'] ?? []));
}
