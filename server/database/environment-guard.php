<?php

declare(strict_types=1);

$autoload = dirname(__DIR__) . '/vendor/autoload.php';
if (!is_file($autoload)) {
    throw new RuntimeException('缺少 Composer autoload，无法校验应用数据库');
}
require_once $autoload;

// Standalone database commands need native Env before any application services.
$application = \think\Container::getInstance();
if (!$application instanceof \think\App) {
    $application = new \think\App(dirname(__DIR__));
}
require_once $application->getThinkPath() . 'helper.php';
if (!$application->initialized()) {
    $application->loadEnv((string) $application->env->get('env_name', ''));
}
require_once __DIR__ . '/resource-registry.php';

function requiredEnvironment(string $name, string|false $default = false): string
{
    $value = env($name, $default);
    if ((!is_string($value) && !is_int($value)) || trim((string) $value) === '') {
        throw new RuntimeException("缺少环境配置：{$name}");
    }
    return trim((string) $value);
}

/** Maintenance selections and lease handles are process inputs, never APP .env fields. */
function requiredMaintenanceEnvironment(string $name): string
{
    $value = getenv($name);
    if ($value === false || trim($value) === '') {
        throw new RuntimeException("缺少维护进程输入：{$name}");
    }
    return trim($value);
}

/** Only an explicitly registered schema policy replaces a port lock. */
function mysqlSchemaLeaseResource(array $database, array $endpoint, array $actual): ?string
{
    $policy = $database['lease_policy'] ?? null;
    if ($policy === null || ($policy['mode'] ?? null) === 'exclusive') { return null; }
    if (!is_array($policy) || ($policy['mode'] ?? null) !== 'schema'
        || !is_int($policy['max_executors'] ?? null)
        || $policy['max_executors'] < 1 || $policy['max_executors'] > 8
        || ($policy['physical_endpoint'] ?? null) !== (string) $endpoint['host'] . ':' . (int) $endpoint['port']
        || !is_string($policy['physical_resource_id'] ?? null) || $policy['physical_resource_id'] === ''
        || !is_array($policy['server_resources'] ?? null) || $policy['server_resources'] === []
        || preg_match('/qualification|performance|recovery/', (string) ($database['lease_gate'] ?? '')) === 1
        || preg_match('/^[A-Za-z0-9_]{1,64}$/D', $actual['database']) !== 1) {
        throw new RuntimeException('MySQL schema lease policy does not match registered physical endpoint');
    }
    return $policy['physical_endpoint'] . '/' . $actual['database'];
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
        $matcher,
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
function isLexicallyAbsolutePath(string $path): bool
{
    return str_starts_with($path, '/')
        && !str_contains($path, "\0")
        && !str_contains($path, '/./')
        && !str_contains($path, '/../')
        && !str_ends_with($path, '/.')
        && !str_ends_with($path, '/..');
}

/** @return array{environment:string,deployment_target:string,resource_id:string,endpoint_id:string,consumer:string,host:string,port:string,database:string,user:string,password:string} */
/** 源码与生成 APP 共用此门禁；资源路径只由严格的项目／实例身份读取器选择。 */
function guardedDatabaseConfig(?string $leaseProofPath = null, ?int $now = null): array
{
    $registry = projectResourceRegistry($registrySha256);
    $environment = requiredEnvironment('APP_ENV');
    $deploymentTarget = requiredMaintenanceEnvironment('PEANUT_DEPLOYMENT_TARGET');
    $target = deploymentTargetContract($deploymentTarget);
    if (!hash_equals($target['app_environment'], $environment)) {
        throw new RuntimeException("APP_ENV={$environment} 与部署目标 {$deploymentTarget} 不匹配");
    }

    $resourceId = requiredMaintenanceEnvironment('PEANUT_DATABASE_RESOURCE_ID');
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

    $consumer = requiredMaintenanceEnvironment('PEANUT_DATABASE_CONSUMER');
    $endpoint = registeredDatabaseEndpoint($database, $consumer);
    $configuredEndpointId = getenv('PEANUT_DATABASE_ENDPOINT_ID');
    if ($configuredEndpointId !== false && trim($configuredEndpointId) !== ''
        && !hash_equals((string) $endpoint['endpoint_id'], trim($configuredEndpointId))) {
        throw new RuntimeException("数据库资源 {$resourceId} 的 endpoint identity 不匹配登记值");
    }

    $actual = [
        'host' => requiredEnvironment('DB_HOST', '127.0.0.1'),
        'port' => requiredEnvironment('DB_PORT', '3306'),
        'database' => requiredEnvironment('DB_NAME'),
    ];
    if (!hash_equals((string) $endpoint['host'], $actual['host'])
        || !hash_equals((string) $endpoint['port'], $actual['port'])) {
        throw new RuntimeException("数据库资源 {$resourceId} 的地址不匹配登记值");
    }
    $deploymentMode = requiredEnvironment('DEPLOYMENT_MODE');
    if ($isTemplated) {
        if (($database['application_runtime'] ?? null) !== false
            || ($database['lifecycle'] ?? null) !== 'ephemeral') {
            throw new RuntimeException('templated database 必须是登记的 ephemeral qualification resource');
        }
        $identity = templatedDatabaseIdentity($database, $actual['database']);
        if (!in_array($resourceId, ['peanut-admin-p0e-mysql84-gate', 'peanut-admin-consumer-upgrade-mysql84-gate'], true)) {
            throw new RuntimeException('templated database resource 未获 qualification guard 授权');
        }
        if (isset($database['deployment_modes'])) {
            $deploymentModes = $database['deployment_modes'];
            if (!is_array($deploymentModes) || !in_array($deploymentMode, $deploymentModes, true)) {
                throw new RuntimeException("templated database resource {$resourceId} 不允许 DEPLOYMENT_MODE={$deploymentMode}");
            }
        }
        if ($resourceId === 'peanut-admin-p0e-mysql84-gate') {
            if (!hash_equals('local-production-preview', $deploymentTarget)) {
                throw new RuntimeException("templated database resource {$resourceId} 不允许部署目标 {$deploymentTarget}");
            }
            $multiTenantScenarios = ['multi_tenant_fresh', 'module_lifecycle', 'multi_tenant_browser'];
            $expectedMode = in_array($identity['scenario'], $multiTenantScenarios, true)
                ? 'multi-tenant'
                : 'standalone';
            if (!hash_equals($expectedMode, $deploymentMode)) {
                throw new RuntimeException('P0-E deployment mode 与 database scenario 不匹配');
            }
        } else {
            if (!hash_equals('local-development', $deploymentTarget) || !hash_equals('host', $consumer)) {
                throw new RuntimeException("templated database resource {$resourceId} 不允许部署目标 {$deploymentTarget} 或 consumer {$consumer}");
            }
            $expectedMode = $identity['scenario'] === 'multi_tenant_upgrade' ? 'multi-tenant' : 'standalone';
            if (!hash_equals($expectedMode, $deploymentMode)) {
                throw new RuntimeException('consumer-upgrade deployment mode 与 database scenario 不匹配');
            }
        }
    } elseif ($deploymentTarget === 'development-test') {
        if ($consumer !== 'host' || ($database['application_runtime'] ?? null) !== false
            || ($database['lifecycle'] ?? null) !== 'ephemeral') {
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
        if (isset($database['lease_policy']['shared_access'])) {
            if ($deploymentTarget !== 'local-development' || $consumer !== 'host') {
                throw new RuntimeException('shared schema runtime 只允许登记的 local-development Host');
            }
        }
    }

    return [
        'environment' => $environment,
        'deployment_target' => $deploymentTarget,
        'resource_id' => $resourceId,
        'endpoint_id' => (string) $endpoint['endpoint_id'],
        'consumer' => $consumer,
        ...$actual,
        'user' => requiredEnvironment('DB_USER'),
        'password' => requiredEnvironment('DB_PASS'),
    ];
}

/**
 * Closed acceptance observation of persisted collaboration metadata, not a runtime
 * deployment target or a general SQL interface. The caller must bind independently
 * known identities in its own native observation role; a discovered instance cannot
 * authorize room queries. The real same-subject runtime parent retains its original
 * candidate and physical locks; the child separately owns the reviewed source.
 * No connection, SQL callback or transaction escapes.
 *
 * @param array{owner:string,thread:string,candidate:string,instance_id:?string,service_instance_id:?string,receipt_path:string} $actor
 * @return array<string,mixed>
 */
function observeRegisteredCollaborationRuntime(array $actor): array
{
    $keys = array_keys($actor);
    sort($keys, SORT_STRING);
    if (PHP_SAPI !== 'cli'
        || $keys !== ['candidate', 'instance_id', 'owner', 'receipt_path', 'service_instance_id', 'thread']
        || !is_string($actor['owner']) || $actor['owner'] === ''
        || !is_string($actor['thread']) || $actor['thread'] === ''
        || !is_string($actor['candidate']) || preg_match('/^[a-f0-9]{40}$/D', $actor['candidate']) !== 1
        || !is_string($actor['receipt_path']) || !isLexicallyAbsolutePath($actor['receipt_path'])
        || realpath(dirname($actor['receipt_path'])) !== dirname($actor['receipt_path'])
        || preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{0,127}\.json$/D', basename($actor['receipt_path'])) !== 1
        || file_exists($actor['receipt_path']) || is_link($actor['receipt_path'])) {
        throw new RuntimeException('COLLABORATION_OBSERVATION_ACTOR_INVALID');
    }
    foreach (['pcntl_alarm','pcntl_signal','pcntl_signal_get_handler','pcntl_async_signals','posix_geteuid','fsync'] as $capability) {
        if (!function_exists($capability)) {
            throw new RuntimeException('COLLABORATION_OBSERVATION_WINDOW_CAPABILITY_REQUIRED');
        }
    }
    $confirmed = $actor['instance_id'] !== null && $actor['service_instance_id'] !== null;
    foreach (['instance_id', 'service_instance_id'] as $key) {
        if (($actor[$key] !== null && (!is_string($actor[$key])
                || preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]{0,127}$/D', $actor[$key]) !== 1))
            || (($actor[$key] !== null) !== $confirmed)) {
            throw new RuntimeException('COLLABORATION_OBSERVATION_INSTANCE_BINDING_INVALID');
        }
    }
    $root = (string) realpath(dirname(__DIR__, 2));
    $registry = projectResourceRegistry($registrySha256);
    $resourceId = requiredMaintenanceEnvironment('PEANUT_DATABASE_RESOURCE_ID');
    $database = registeredDatabase($registry, $resourceId);
    $endpoint = registeredDatabaseEndpoint($database, 'host');
    $name = requiredEnvironment('DB_NAME');
    if (($registry['authority']['role'] ?? null) !== 'maintainer'
        || !in_array('acceptance', $database['environments'] ?? [], true)
        || ($database['application_runtime'] ?? null) !== true
        || ($database['service_type'] ?? null) !== 'mysql' || ($database['fallback'] ?? null) !== 'none'
        || ($database['version'] ?? null) !== '8.4.10'
        || !is_string($database['credential_ref'] ?? null) || $database['credential_ref'] === ''
        || !in_array($name, $database['synthetic_databases']['standalone'] ?? [], true)
        || preg_match('/^[A-Za-z0-9_]{1,64}$/D', $name) !== 1
        || !is_string($database['resource_scope'] ?? null) || $database['resource_scope'] === ''
        || !is_string($database['lease_gate'] ?? null) || $database['lease_gate'] === ''
        || isset($database['lease_policy']['shared_access'])
        || requiredEnvironment('APP_ENV') !== 'development'
        || requiredMaintenanceEnvironment('PEANUT_DEPLOYMENT_TARGET') !== 'local-development'
        || requiredEnvironment('DEPLOYMENT_MODE') !== 'standalone'
        || requiredMaintenanceEnvironment('PEANUT_DATABASE_CONSUMER') !== 'host'
        || requiredMaintenanceEnvironment('PEANUT_DATABASE_ENDPOINT_ID') !== (string) $endpoint['endpoint_id']
        || requiredEnvironment('DB_HOST', '127.0.0.1') !== (string) $endpoint['host']
        || requiredEnvironment('DB_PORT', '3306') !== (string) $endpoint['port']) {
        throw new RuntimeException('COLLABORATION_OBSERVATION_REGISTERED_TARGET_INVALID');
    }
    $config = ['host' => (string) $endpoint['host'], 'port' => (string) $endpoint['port'],
        'database' => $name, 'user' => requiredEnvironment('DB_USER'), 'password' => requiredEnvironment('DB_PASS')];
    $schema = mysqlSchemaLeaseResource($database, $endpoint, $config);
    if ($schema === null) {
        throw new RuntimeException('COLLABORATION_OBSERVATION_SCHEMA_POLICY_REQUIRED');
    }
    $windowHandle = null;
    $windowDeadline = null;
    $authorize = static function () use ($root, $actor, &$windowDeadline): array {
        return [
            'lease' => 'native',
            'owner' => $actor['owner'],
            'thread' => $actor['thread'],
            'candidate' => $actor['candidate'],
            'expires_at' => (string) (int) (($windowDeadline ?? microtime(true) + 45) + 60),
            'runtime_candidate' => $actor['candidate'],
            'runtime_worktree' => $root,
        ];
    };
    $metadata = $authorize(); // Complete admission before opening any database connection.
    $mutex = sys_get_temp_dir() . '/peanut-admin-collaboration-observation.lock';
    $previousAlarm = pcntl_alarm(0);
    if ($previousAlarm > 0) {
        pcntl_alarm($previousAlarm);
        throw new RuntimeException('COLLABORATION_OBSERVATION_EXISTING_ALARM');
    }
    $pdo = null;
    $mutexIdentity = null;
    $receiptHandle = null;
    $receiptDirectoryHandle = null;
    $receiptIdentity = null;
    $signalHandlers = [];
    $previousAsync = pcntl_async_signals();
    $previousReadTimeout = null;
    $observation = null;
    $failure = null;
    $stage = 'acquire_mutex';
    $selectAttempts = 0;
    $selectCompleted = 0;
    $connected = false;
    $receiptHash = null;
    $closing = false;
    $interruptDelivered = false;
    $ownsMutex = static function () use ($mutex, &$windowHandle, &$mutexIdentity): bool {
        clearstatcache(true, $mutex); clearstatcache(true, $mutex . '/pid');
        $directory = @lstat($mutex); $named = @lstat($mutex . '/pid');
        $open = is_resource($windowHandle) ? fstat($windowHandle) : false;
        return is_array($mutexIdentity) && is_array($directory) && is_array($named) && is_array($open)
            && !is_link($mutex) && !is_link($mutex . '/pid') && $open['nlink'] === 1
            && $directory['dev'] === $mutexIdentity['dev'] && $directory['ino'] === $mutexIdentity['ino']
            && $open['dev'] === $named['dev'] && $open['ino'] === $named['ino']
            && $open['uid'] === posix_geteuid() && ($open['mode'] & 0777) === 0600
            && trim((string) @file_get_contents($mutex . '/pid')) === (string) getmypid();
    };
    $checkWindow = static function () use (&$windowDeadline, $ownsMutex): void {
        if ($windowDeadline === null || microtime(true) >= $windowDeadline || !$ownsMutex()) {
            throw new RuntimeException('COLLABORATION_OBSERVATION_WINDOW_EXPIRED_OR_CHANGED');
        }
    };
    try {
        // Same mkdir/PID protocol and namespace as every historical producer.
        // Only this PHP process executes SQL; there is no surviving worker child.
        for ($attempt = 0; $attempt < 100; $attempt++) {
            $mask = umask(0077);
            try { $created = @mkdir($mutex, 0700); }
            finally { umask($mask); }
            if ($created) {
                $mutexIdentity = lstat($mutex);
                $mask = umask(0077);
                try { $windowHandle = @fopen($mutex . '/pid', 'x+b'); }
                finally { umask($mask); }
                if (!is_resource($windowHandle)) {
                    throw new RuntimeException('COLLABORATION_OBSERVATION_MUTEX_PID_OPEN_FAILED');
                }
                $pid = getmypid() . "\n";
                if (fwrite($windowHandle, $pid) !== strlen($pid) || !fflush($windowHandle) || !$ownsMutex()) {
                    throw new RuntimeException('COLLABORATION_OBSERVATION_MUTEX_PID_INVALID');
                }
                break;
            }
            // Do not remove an unknown/stale holder here. Normal native preflight
            // owns stale-lock recovery; a failed acquisition never cleans others.
            usleep(50000);
        }
        if (!is_resource($windowHandle)) {
            throw new RuntimeException('COLLABORATION_OBSERVATION_MUTEX_BUSY_OR_UNAVAILABLE');
        }
        $windowDeadline = microtime(true) + 30;
        foreach ([SIGALRM, SIGTERM, SIGINT, SIGHUP] as $signal) {
            $signalHandlers[$signal] = pcntl_signal_get_handler($signal);
            pcntl_signal($signal, static function (int $received) use (&$closing, &$interruptDelivered, &$failure): void {
                if ($closing || $interruptDelivered) {
                    $failure ??= new RuntimeException('COLLABORATION_OBSERVATION_INTERRUPTED_DURING_CLOSE');
                    return;
                }
                $interruptDelivered = true;
                throw new RuntimeException($received === SIGALRM
                    ? 'COLLABORATION_OBSERVATION_WINDOW_TIMEOUT' : 'COLLABORATION_OBSERVATION_INTERRUPTED');
            }, false);
        }
        pcntl_async_signals(true);
        pcntl_alarm(30);
        $stage = 'locked_authorization';
        $metadata = $authorize();
        $checkWindow();
        $stage = 'open_receipt';
        $receiptDirectoryHandle = @fopen(dirname($actor['receipt_path']), 'r');
        if (!is_resource($receiptDirectoryHandle)) {
            throw new RuntimeException('COLLABORATION_OBSERVATION_RECEIPT_DIRECTORY_UNAVAILABLE');
        }
        $mask = umask(0077);
        try { $receiptHandle = @fopen($actor['receipt_path'], 'x+b'); }
        finally { umask($mask); }
        if (!is_resource($receiptHandle)) {
            throw new RuntimeException('COLLABORATION_OBSERVATION_RECEIPT_OPEN_FAILED');
        }
        $receiptIdentity = fstat($receiptHandle);
        $namedReceipt = lstat($actor['receipt_path']);
        if (is_link($actor['receipt_path']) || $receiptIdentity['nlink'] !== 1
            || $receiptIdentity['dev'] !== $namedReceipt['dev'] || $receiptIdentity['ino'] !== $namedReceipt['ino']
            || $receiptIdentity['uid'] !== posix_geteuid() || ($receiptIdentity['mode'] & 0777) !== 0600) {
            throw new RuntimeException('COLLABORATION_OBSERVATION_RECEIPT_ENTITY_INVALID');
        }
        $receiptDirectory = fstat($receiptDirectoryHandle);
        $namedDirectory = lstat(dirname($actor['receipt_path']));
        if ($receiptDirectory['dev'] !== $namedDirectory['dev'] || $receiptDirectory['ino'] !== $namedDirectory['ino']
            || realpath(dirname($actor['receipt_path'])) !== dirname($actor['receipt_path'])
            || !fflush($receiptHandle) || !fsync($receiptHandle) || !fsync($receiptDirectoryHandle)) {
            throw new RuntimeException('COLLABORATION_OBSERVATION_RECEIPT_DIRECTORY_CHANGED');
        }
        // These bound connection/read/query limits complement the process alarm;
        // alarm availability alone is not a hard bound on blocking PDO I/O.
        $previousReadTimeout = ini_set('mysqlnd.net_read_timeout', '3');
        if ($previousReadTimeout === false) {
            throw new RuntimeException('COLLABORATION_OBSERVATION_READ_TIMEOUT_UNAVAILABLE');
        }
        $stage = 'connect';
        $checkWindow();
        $pdo = guardedConnection($config, 3);
        $connected = true;
        $checkWindow();
        $pdo->exec('SET SESSION MAX_EXECUTION_TIME=2000');
        $pdo->exec('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
        $pdo->exec('START TRANSACTION WITH CONSISTENT SNAPSHOT, READ ONLY');
        $stage = 'database_identity';
        $checkWindow(); $selectAttempts++;
        $identity = $pdo->query('SELECT DATABASE() AS database_name,@@server_uuid AS server_uuid,@@version AS version,UTC_TIMESTAMP(3) AS observed_at')->fetch(PDO::FETCH_ASSOC);
        $selectCompleted++; $checkWindow();
        if (!is_array($identity) || $identity['database_name'] !== $name
            || !is_string($identity['server_uuid'])
            || preg_match('/^[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12}$/D', $identity['server_uuid']) !== 1
            || $identity['version'] !== $database['version']) {
            throw new RuntimeException('COLLABORATION_OBSERVATION_DATABASE_IDENTITY_MISMATCH');
        }
        // All identifiers and expressions below are source constants; only values bind.
        $columns = [
            'pa_collaboration_runtime' => ['instance_id','edition','phase','config_revision','config_sha256','drain_id','updated_at','plan_json','receipt_json'],
            'pa_collaboration_room' => ['id','tenant_id','document_id','instance_id','edition','protocol_version','room_key','service_instance_id','holder_key','lease_epoch','lease_expires_at','status','accepted_through','persisted_through','pending_update_count','persisted_state_seq','baseline_sha256','last_target_progress','last_store_id','last_store_sha256','updated_at'],
            'pa_collaboration_barrier' => ['room_id','tenant_id','barrier_key','lease_epoch','status','created_at','expires_at','released_at','committed_state_seq','business_sha256','business_plan_json','drained_receipt_json','business_result_json'],
            'pa_document_operation' => ['tenant_id','document_id','operation_id','action','phase','input_sha256','business_sha256','created_at','released_at','result_json'],
        ];
        $schemaEvidence = [];
        $checkColumns = static function (string $table) use ($pdo, $columns, $name, &$schemaEvidence, $checkWindow, &$stage, &$selectAttempts, &$selectCompleted): void {
            $stage = 'columns_' . $table;
            $checkWindow();
            $wanted = $columns[$table];
            $query = $pdo->prepare('SELECT COLUMN_NAME,DATA_TYPE FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=? AND TABLE_NAME=? AND COLUMN_NAME IN (' . implode(',', array_fill(0, count($wanted), '?')) . ') ORDER BY ORDINAL_POSITION');
            $checkWindow(); $selectAttempts++;
            $query->execute([$name, $table, ...$wanted]);
            $actual = $query->fetchAll(PDO::FETCH_ASSOC);
            $query->closeCursor();
            $selectCompleted++; $checkWindow();
            if (array_diff($wanted, array_column($actual, 'COLUMN_NAME')) !== []) {
                throw new RuntimeException('COLLABORATION_OBSERVATION_SCHEMA_GAP: ' . $table);
            }
            foreach ($actual as $column) {
                if (str_ends_with($column['COLUMN_NAME'], '_json') && $column['DATA_TYPE'] !== 'json') {
                    throw new RuntimeException('COLLABORATION_OBSERVATION_SCHEMA_TYPE_GAP: ' . $table);
                }
            }
            $schemaEvidence[$table] = $actual;
        };
        $checkColumns('pa_collaboration_runtime');
        $runtimeSql = <<<'SQL'
SELECT instance_id,edition,phase,config_revision,config_sha256,drain_id,updated_at,
 JSON_VALUE(plan_json,'$.candidate.service_instance_id' ERROR ON ERROR) AS service_instance_id,
 JSON_VALUE(plan_json,'$.candidate.holder_key' ERROR ON ERROR) AS holder_key,
 JSON_VALUE(plan_json,'$.candidate.probe_id' ERROR ON ERROR) AS probe_id,
 JSON_VALUE(plan_json,'$.activation.activation_id' ERROR ON ERROR) AS activation_id,
 JSON_VALUE(plan_json,'$.process_lease_expires_at_ms' RETURNING UNSIGNED ERROR ON ERROR) AS process_lease_expires_at_ms,
 JSON_VALUE(receipt_json,'$.protocol' ERROR ON ERROR) AS receipt_protocol,
 JSON_VALUE(receipt_json,'$.drain_id' ERROR ON ERROR) AS receipt_drain_id,
 JSON_VALUE(receipt_json,'$.plan_sha256' ERROR ON ERROR) AS receipt_plan_sha256,
 JSON_VALUE(receipt_json,'$.targets_sha256' ERROR ON ERROR) AS receipt_targets_sha256,
 JSON_VALUE(receipt_json,'$.durably_drained' ERROR ON ERROR) AS durably_drained,
 JSON_VALUE(receipt_json,'$.room_count' RETURNING UNSIGNED ERROR ON ERROR) AS receipt_room_count
FROM pa_collaboration_runtime WHERE edition='standalone'
SQL;
        $runtimeQuery = $pdo->prepare($runtimeSql . ($confirmed ? ' AND instance_id=?' : '') . ' ORDER BY instance_id LIMIT 2');
        $stage = 'runtime'; $checkWindow(); $selectAttempts++;
        $runtimeQuery->execute($confirmed ? [$actor['instance_id']] : []);
        $runtime = $runtimeQuery->fetchAll(PDO::FETCH_ASSOC);
        $runtimeQuery->closeCursor();
        $selectCompleted++; $checkWindow();
        $result = ['protocol' => 'peanut.collaboration-runtime-observation.v1', 'operation_environment' => 'acceptance',
            'resource_id' => $resourceId, 'endpoint_id' => $endpoint['endpoint_id'], 'scope' => $database['resource_scope'],
            'candidate' => $actor['candidate'], 'lease' => $metadata['lease'], 'owner' => $actor['owner'], 'thread' => $actor['thread'],
            'runtime_candidate' => $metadata['runtime_candidate'], 'runtime_worktree' => $metadata['runtime_worktree'],
            'registry_sha256' => $registrySha256, 'identity' => $identity, 'runtime' => $runtime,
            'expected_server_uuid' => null, 'server_uuid_verification' => 'observed_only_no_expected_identity_asserted',
            'credential_permissions' => 'Not asserted SELECT-only; this closed operation executes fixed reads only.',
            'runtime_observed_count' => count($runtime), 'runtime_limit' => 2,
            'status' => 'identity_confirmation_required', 'associated_queries' => 'not_run',
            'proof_limit' => 'Persisted metadata only; Node memory, loaded code, captures and original failure/operation budgets remain unknown.'];
        if ($confirmed) {
            if (count($runtime) !== 1 || $runtime[0]['instance_id'] !== $actor['instance_id']
                || $runtime[0]['service_instance_id'] !== $actor['service_instance_id']) {
                throw new RuntimeException('COLLABORATION_OBSERVATION_EXPECTED_INSTANCE_MISMATCH');
            }
            foreach (['pa_collaboration_room','pa_collaboration_barrier','pa_document_operation'] as $table) {
                $checkColumns($table);
            }
            $queries = [
                'rooms' => <<<'SQL'
SELECT id,tenant_id,document_id,room_key,service_instance_id,holder_key,lease_epoch,lease_expires_at,status,
 accepted_through,persisted_through,pending_update_count,persisted_state_seq,baseline_sha256,last_target_progress,
 last_store_id,last_store_sha256,updated_at
FROM pa_collaboration_room WHERE instance_id=? AND edition='standalone' AND protocol_version=2 ORDER BY id LIMIT 10001
SQL,
                'unreleased_barriers' => <<<'SQL'
SELECT B.barrier_key,B.lease_epoch,B.status,B.created_at,B.expires_at,B.released_at,B.committed_state_seq,B.business_sha256,
 JSON_VALUE(B.business_plan_json,'$.operation_id' ERROR ON ERROR) AS operation_id,
 JSON_VALUE(B.business_plan_json,'$.input_sha256' ERROR ON ERROR) AS input_sha256,
 JSON_VALUE(B.business_plan_json,'$.holder_key' ERROR ON ERROR) AS holder_key,
 JSON_VALUE(B.business_plan_json,'$.lease_epoch' RETURNING UNSIGNED ERROR ON ERROR) AS business_lease_epoch,
 JSON_VALUE(B.business_plan_json,'$.deadline' ERROR ON ERROR) AS deadline,
 SHA2(CAST(B.drained_receipt_json AS CHAR),256) AS drained_receipt_sql_sha256,
 SHA2(CAST(B.business_result_json AS CHAR),256) AS business_result_sql_sha256
FROM pa_collaboration_barrier B JOIN pa_collaboration_room R ON B.room_id=R.id AND B.tenant_id=R.tenant_id
WHERE R.instance_id=? AND R.edition='standalone' AND R.protocol_version=2
 AND B.business_plan_json IS NOT NULL AND B.released_at IS NULL ORDER BY B.tenant_id,B.room_id,B.barrier_key LIMIT 10001
SQL,
                'pending_operations' => <<<'SQL'
SELECT O.operation_id,O.action,O.phase,O.input_sha256,O.business_sha256,O.created_at,O.released_at,
 SHA2(CAST(O.result_json AS CHAR),256) AS result_sql_sha256,
 CASE WHEN O.action='collaboration.store' THEN JSON_VALUE(O.result_json,'$.store_id' ERROR ON ERROR) END AS store_id,
 CASE WHEN O.action='collaboration.store' THEN JSON_VALUE(O.result_json,'$.lease_epoch' RETURNING UNSIGNED ERROR ON ERROR) END AS lease_epoch,
 CASE WHEN O.action='collaboration.store' THEN JSON_VALUE(O.result_json,'$.state_seq' RETURNING UNSIGNED ERROR ON ERROR) END AS state_seq,
 CASE WHEN O.action='collaboration.store' THEN JSON_VALUE(O.result_json,'$.target_progress' RETURNING UNSIGNED ERROR ON ERROR) END AS target_progress,
 CASE WHEN O.action='collaboration.store' THEN JSON_VALUE(O.result_json,'$.binary_sha256' ERROR ON ERROR) END AS binary_sha256
FROM pa_document_operation O WHERE O.action<>'collaboration.target' AND O.phase<>'completed'
 AND EXISTS (SELECT 1 FROM pa_collaboration_room R WHERE R.tenant_id=O.tenant_id AND R.document_id=O.document_id
 AND R.instance_id=? AND R.edition='standalone' AND R.protocol_version=2)
ORDER BY O.tenant_id,O.document_id,O.operation_id LIMIT 10001
SQL,
            ];
            foreach ($queries as $key => $sql) {
                $stage = $key; $checkWindow();
                $query = $pdo->prepare($sql);
                $checkWindow(); $selectAttempts++;
                $query->execute([$actor['instance_id']]);
                $rows = $query->fetchAll(PDO::FETCH_ASSOC);
                $query->closeCursor();
                $selectCompleted++; $checkWindow();
                if (count($rows) >= 10001) {
                    throw new RuntimeException('COLLABORATION_OBSERVATION_RESULT_LIMIT: ' . $key);
                }
                $result[$key] = ['count' => count($rows), 'rows_sha256' => hash('sha256', json_encode($rows, JSON_THROW_ON_ERROR)), 'rows' => $rows];
            }
            $result['room_service_mismatch_count'] = count(array_filter($result['rooms']['rows'],
                static fn(array $row): bool => $row['service_instance_id'] !== $actor['service_instance_id']));
            $result['status'] = 'persisted_metadata_observed';
            $result['associated_queries'] = 'fixed_projection_only';
        }
        $result['schema_columns'] = $schemaEvidence;
        $stage = 'final_authorization'; $checkWindow();
        $authorize(); // Expiry or source/lease drift invalidates the observation.
        $checkWindow();
        $observation = $result;
        $closing = true;
    } catch (Throwable $error) {
        $closing = true;
        $failure = $error;
    } finally {
        // During cleanup, signals mark failure without escaping the close/receipt/
        // unlock sequence. A SIGKILL leaves the native PID lock for stale recovery
        // only after this actual SQL client process has terminated.
        pcntl_async_signals(false);
        pcntl_alarm(0);
        foreach (array_keys($signalHandlers) as $signal) {
            pcntl_signal($signal, static function () use (&$failure): void {
                $failure ??= new RuntimeException('COLLABORATION_OBSERVATION_INTERRUPTED_DURING_CLOSE');
            }, false);
        }
        pcntl_async_signals(true);
        foreach ([$runtimeQuery ?? null, $query ?? null] as $statement) {
            try { if ($statement instanceof PDOStatement) { $statement->closeCursor(); } }
            catch (Throwable $closeError) { $failure ??= $closeError; }
        }
        unset($runtimeQuery, $query, $statement, $checkColumns);
        try {
            if ($pdo instanceof PDO && $pdo->inTransaction()) { $pdo->rollBack(); }
        } catch (Throwable $closeError) { $failure ??= $closeError; }
        finally { $pdo = null; }
        if ($previousReadTimeout !== null && $previousReadTimeout !== false) {
            ini_set('mysqlnd.net_read_timeout', $previousReadTimeout);
        }
        if (is_resource($windowHandle)) {
            try { $checkWindow(); } catch (Throwable $deadlineError) { $failure ??= $deadlineError; }
        }
        try {
            if (is_resource($receiptHandle)) {
                $payload = ['protocol' => 'peanut.collaboration-observation-receipt.v1',
                    'status' => $failure === null ? 'metadata_recorded' : 'failed', 'candidate' => $actor['candidate'],
                    'runtime_candidate' => $metadata['runtime_candidate'], 'owner' => $actor['owner'], 'thread' => $actor['thread'],
                    'lease' => $metadata['lease'], 'SQL_client_pid' => getmypid(), 'database_connected' => $connected,
                    'window_release' => 'not_yet_released_at_durable_record',
                    'this_invocation_select_attempts' => $selectAttempts, 'this_invocation_select_completed' => $selectCompleted,
                    'failed_stage' => $failure === null ? null : $stage,
                    'error_class' => $failure === null ? null : get_class($failure),
                    'error_code' => $failure === null ? null : (string) $failure->getCode(),
                    'error_reason' => $failure instanceof RuntimeException && str_starts_with($failure->getMessage(), 'COLLABORATION_OBSERVATION_')
                        ? $failure->getMessage() : null,
                    'observation' => $failure === null ? $observation : null];
                $bytes = json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";
                $offset = 0;
                while ($offset < strlen($bytes)) {
                    $written = fwrite($receiptHandle, substr($bytes, $offset));
                    if ($written === false || $written === 0) { throw new RuntimeException('COLLABORATION_OBSERVATION_RECEIPT_WRITE_FAILED'); }
                    $offset += $written;
                }
                if (!fflush($receiptHandle) || !fsync($receiptHandle)) {
                    throw new RuntimeException('COLLABORATION_OBSERVATION_RECEIPT_SYNC_FAILED');
                }
                clearstatcache(true, $actor['receipt_path']);
                $named = lstat($actor['receipt_path']); $open = fstat($receiptHandle);
                if (is_link($actor['receipt_path']) || $open['nlink'] !== 1
                    || $open['dev'] !== $receiptIdentity['dev'] || $open['ino'] !== $receiptIdentity['ino']
                    || $open['dev'] !== $named['dev'] || $open['ino'] !== $named['ino'] || $open['size'] !== strlen($bytes)
                    || $open['uid'] !== posix_geteuid() || ($open['mode'] & 0777) !== 0600) {
                    throw new RuntimeException('COLLABORATION_OBSERVATION_RECEIPT_CHANGED');
                }
                rewind($receiptHandle);
                $receiptHash = hash('sha256', $bytes);
                if (!hash_equals($receiptHash, hash('sha256', (string) stream_get_contents($receiptHandle)))) {
                    throw new RuntimeException('COLLABORATION_OBSERVATION_RECEIPT_READBACK_FAILED');
                }
                clearstatcache(true, dirname($actor['receipt_path']));
                $namedDirectory = lstat(dirname($actor['receipt_path']));
                $openDirectory = fstat($receiptDirectoryHandle);
                if ($openDirectory['dev'] !== $namedDirectory['dev'] || $openDirectory['ino'] !== $namedDirectory['ino']
                    || $openDirectory['dev'] !== $receiptDirectory['dev'] || $openDirectory['ino'] !== $receiptDirectory['ino']
                    || realpath(dirname($actor['receipt_path'])) !== dirname($actor['receipt_path'])
                    || !fsync($receiptDirectoryHandle)) {
                    throw new RuntimeException('COLLABORATION_OBSERVATION_RECEIPT_DIRECTORY_SYNC_FAILED');
                }
            }
        } catch (Throwable $receiptError) { $failure = $receiptError; $receiptHash = null; }
        finally {
            if (is_resource($windowHandle)) {
                try { $checkWindow(); } catch (Throwable $deadlineError) { $failure ??= $deadlineError; }
            }
            if (is_resource($receiptHandle)) {
                try { fclose($receiptHandle); } catch (Throwable $closeError) { $failure ??= $closeError; }
            }
            if (is_resource($receiptDirectoryHandle)) {
                try { fclose($receiptDirectoryHandle); } catch (Throwable $closeError) { $failure ??= $closeError; }
            }
            if (is_resource($windowHandle)) {
                if ($ownsMutex()) {
                    $releasedPid = false;
                    try {
                        $releasedPid = unlink($mutex . '/pid');
                        if (!$releasedPid) { throw new RuntimeException('COLLABORATION_OBSERVATION_MUTEX_PID_RELEASE_FAILED'); }
                    } catch (Throwable $releaseError) { $failure ??= $releaseError; }
                    try { fclose($windowHandle); } catch (Throwable $closeError) { $failure ??= $closeError; }
                    $windowHandle = null;
                    clearstatcache(true, $mutex);
                    $directory = @lstat($mutex);
                    if ($releasedPid && is_array($directory) && !is_link($mutex)
                        && $directory['dev'] === $mutexIdentity['dev'] && $directory['ino'] === $mutexIdentity['ino']
                        && !file_exists($mutex . '/pid')) {
                        try {
                            if (!rmdir($mutex)) { throw new RuntimeException('COLLABORATION_OBSERVATION_MUTEX_RELEASE_FAILED'); }
                        } catch (Throwable $releaseError) { $failure ??= $releaseError; }
                    } else { $failure ??= new RuntimeException('COLLABORATION_OBSERVATION_MUTEX_ENTITY_CHANGED'); }
                } else {
                    try { fclose($windowHandle); } catch (Throwable $closeError) { $failure ??= $closeError; }
                    $windowHandle = null;
                    $failure ??= new RuntimeException('COLLABORATION_OBSERVATION_MUTEX_ENTITY_CHANGED');
                }
            } elseif ($mutexIdentity !== null) {
                // Only an empty directory just created by this process may be
                // removed after PID-file creation failed.
                clearstatcache(true, $mutex); $directory = @lstat($mutex);
                if (is_array($directory) && !is_link($mutex) && $directory['dev'] === $mutexIdentity['dev']
                    && $directory['ino'] === $mutexIdentity['ino'] && !file_exists($mutex . '/pid')) { @rmdir($mutex); }
            }
            foreach ($signalHandlers as $signal => $handler) { pcntl_signal($signal, $handler, false); }
            pcntl_async_signals($previousAsync);
        }
    }
    if ($failure !== null || $observation === null || $receiptHash === null) {
        throw new RuntimeException('COLLABORATION_OBSERVATION_FAILED; receipt is not a successful observation', 0, $failure);
    }
    return $observation + ['receipt_path' => $actor['receipt_path'], 'receipt_sha256' => $receiptHash];
}

function guardedConnection(array $config, ?int $connectTimeout = null): PDO
{
    $options = [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES => false];
    if ($connectTimeout !== null) {
        if ($connectTimeout < 1 || $connectTimeout > 5) { throw new RuntimeException('Invalid bounded connection timeout'); }
        $options[PDO::ATTR_TIMEOUT] = $connectTimeout;
    }
    return new PDO(
        sprintf(
            'mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
            $config['host'],
            $config['port'],
            $config['database'],
        ),
        $config['user'],
        $config['password'],
        $options,
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
    require_once __DIR__ . '/InitialInstallationSource.php';
    $serverRoot = dirname(__DIR__);
    $names = InitialInstallationSource::tables($serverRoot, InitialInstallationSource::installedModules($serverRoot));
    sort($names, SORT_STRING);
    return $names;
}

/** @param array<string,list<string>> $requirements */
function assertRequiredColumns(PDO $pdo, array $requirements): void
{
    $statement = $pdo->prepare(
        'SELECT COLUMN_NAME FROM information_schema.COLUMNS '
        . 'WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?',
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
        . 'WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?',
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

/** @return array{baseline_table_count:int,installed_table_count:int,application_migration_count:int,management_member_count:int,menu_count:int,config_count:int,permission_count:int,tenant_count:int,owner_count:int,operator_count:int} */
function assertCurrentDatabase(PDO $pdo): array
{
    $expected = canonicalBaselineTables();
    // The native command defaults to "migrations"; this application's database
    // configuration sets only DB_PREFIX and does not override migration_table.
    $migrationTable = requiredEnvironment('DB_PREFIX', 'pa_') . 'migrations';
    $expected[] = $migrationTable;
    $actual = array_map('strval', $pdo->query(
        'SELECT TABLE_NAME FROM information_schema.TABLES '
        . 'WHERE TABLE_SCHEMA = DATABASE() ORDER BY TABLE_NAME',
    )->fetchAll(PDO::FETCH_COLUMN));
    $missing = array_values(array_diff($expected, $actual));
    if ($missing !== []) {
        throw new RuntimeException('数据库缺少初始应用表：' . implode(', ', $missing));
    }

    assertRequiredColumns($pdo, [
        'pa_account' => ['id', 'status', 'security_revision'],
        'pa_credential' => ['account_id', 'identifier_normalized', 'secret_hash', 'status'],
        'pa_tenant' => ['id', 'code', 'status', 'authorization_revision'],
        'pa_tenant_member' => ['tenant_id', 'account_id', 'status', 'authorization_revision'],
        'pa_permission' => ['key', 'module_key', 'status'],
        'pa_system_menu' => ['id', 'type', 'perms', 'component'],
        'pa_config' => ['type', 'name', 'value'],
        $migrationTable => ['version', 'migration_name', 'start_time', 'end_time', 'breakpoint'],
    ]);
    assertRequiredIndexes($pdo, [
        'pa_tenant' => ['PRIMARY', 'uk_tenant_code'],
        'pa_tenant_member' => ['PRIMARY', 'uk_tenant_member_account'],
        'pa_permission' => ['PRIMARY', 'uk_permission_key'],
        'pa_system_menu' => ['PRIMARY'],
        'pa_config' => ['PRIMARY', 'uk_type_name'],
        $migrationTable => ['PRIMARY'],
    ]);

    $menuCount = (int) $pdo->query('SELECT COUNT(*) FROM pa_system_menu')->fetchColumn();
    $configCount = (int) $pdo->query('SELECT COUNT(*) FROM pa_config')->fetchColumn();
    $permissionCount = (int) $pdo->query(
        "SELECT COUNT(*) FROM pa_permission WHERE status = 'active'",
    )->fetchColumn();
    $quotedMigrationTable = '`' . str_replace('`', '``', $migrationTable) . '`';
    $appliedVersions = array_map('strval', $pdo->query('SELECT version FROM ' . $quotedMigrationTable)->fetchAll(PDO::FETCH_COLUMN));
    $expectedVersions = [];
    foreach (\app\common\module\ModuleMigrationRun::sources([], dirname(__DIR__), true) as $source) {
        $expectedVersions[] = $source['migration_key'];
    }
    if ($expectedVersions === [] || array_diff($expectedVersions, $appliedVersions) !== []) {
        throw new RuntimeException('数据库原生迁移尚未完成');
    }
    $applicationMigrationCount = count($appliedVersions);
    $tenantCount = (int) $pdo->query(
        "SELECT COUNT(*) FROM pa_tenant WHERE code = 'default' AND status = 'active'",
    )->fetchColumn();
    $ownerCount = (int) $pdo->query(<<<'SQL'
SELECT COUNT(DISTINCT tm.id)
FROM pa_tenant t
JOIN pa_tenant_member tm ON tm.tenant_id = t.id AND tm.status = 'active'
JOIN pa_account a ON a.id = tm.account_id AND a.status = 'active'
JOIN pa_credential c ON c.account_id = a.id AND c.status = 'active'
JOIN pa_member_role mr ON mr.tenant_id = tm.tenant_id AND mr.tenant_member_id = tm.id
JOIN pa_role r ON r.tenant_id = mr.tenant_id AND r.id = mr.role_id
WHERE t.code = 'default' AND t.status = 'active' AND r.`key` = 'core.tenant-owner'
SQL)->fetchColumn();
    $operatorCount = (int) $pdo->query(<<<'SQL'
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
            if (preg_match('/^--wait=(\d+)$/D', (string) $argument, $matches) === 1) {
                $wait = min(300, max(0, (int) $matches[1]));
            }
        }
        $result = [
            'environment' => $config['environment'],
            'deployment_target' => $config['deployment_target'],
            'resource_id' => $config['resource_id'],
            'endpoint_id' => $config['endpoint_id'],
            'consumer' => $config['consumer'],
            'host' => $config['host'],
            'port' => (int) $config['port'],
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

if (realpath((string) ($_SERVER['SCRIPT_FILENAME'] ?? '')) === __FILE__) {
    exit(environmentGuardMain($_SERVER['argv'] ?? []));
}
