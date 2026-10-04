<?php

declare(strict_types=1);

use PeanutAdmin\Modules\Task\Service\CrontabApplicationService;
use PeanutAdmin\Modules\Task\Service\CrontabTaskDefinition;
use PeanutAdmin\Modules\Task\ModuleProvider as TaskModuleProvider;
use app\common\enum\CrontabEnum;
use app\common\execution\CurrentExecutionContext;
use app\common\execution\ExecutionContextStore;
use app\common\execution\SystemExecutionContext;
use PeanutAdmin\Modules\Task\Model\Crontab;
use PeanutAdmin\Kernel\Auth\TenantContext;
use PeanutAdmin\Kernel\Auth\ValidatedTenantSession;
use PeanutAdmin\Kernel\Module\ManifestLoader;
use PeanutAdmin\Kernel\Persistence\Schema\KernelSchema;
use PeanutAdmin\Kernel\Tenancy\ScheduledTenantContext;
use PeanutAdmin\Kernel\Tenancy\TenantScope;

require dirname(__DIR__, 2) . '/vendor/autoload.php';
require __DIR__ . '/../Support/IsolatedBackendEnvironment.php';

function expectCrontabTenant(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function expectCrontabTenantThrows(callable $operation, string $message): void
{
    try {
        $operation();
    } catch (Throwable) {
        return;
    }
    throw new RuntimeException($message);
}

function crontabTenantContext(int $tenantId, int $accountId, int $memberId, string $requestId): TenantContext
{
    return TenantContext::fromValidatedSession(new ValidatedTenantSession(
        $memberId,
        'crontab-session-' . $tenantId . '-' . $memberId,
        $tenantId,
        $accountId,
        $memberId,
        'admin-web',
        new DateTimeImmutable('2031-01-01T00:00:00Z'),
        1,
    ), $requestId);
}

function createCrontabTenantSchema(PDO $pdo, string $serverRoot): void
{
    foreach (KernelSchema::tableNames() as $table) {
        $pdo->exec(KernelSchema::createSql($table));
    }
    $pdo->exec(KernelSchema::addTenantMemberDepartmentForeignKeySql());
    $pdo->exec(<<<'SQL'
INSERT INTO pa_tenant
  (id, code, name, display_name, status, activated_at, created_at, updated_at)
VALUES
  (101, 'default', 'Alpha', 'Alpha', 'active', UTC_TIMESTAMP(3), UTC_TIMESTAMP(3), UTC_TIMESTAMP(3));
SQL);
    $schema = (string) file_get_contents($serverRoot . '/database/init.sql');
    expectCrontabTenant($schema !== '', 'canonical application schema is missing');
    $pdo->exec($schema);
}

$serverRoot = dirname(__DIR__, 2);
$manifest = (new ManifestLoader())->load($serverRoot . '/app/modules/official/task');
$taskVersion = (string) ($manifest->data['version'] ?? '');
$taskDigest = $manifest->digest;
expectCrontabTenant($taskVersion !== '' && $taskDigest !== '', 'official.task manifest is unavailable');

$host = IsolatedBackendEnvironment::required('DB_HOST');
$port = (int) IsolatedBackendEnvironment::required('DB_PORT');
$database = IsolatedBackendEnvironment::required('DB_NAME');
$user = IsolatedBackendEnvironment::required('DB_USER');
$password = IsolatedBackendEnvironment::required('DB_PASS');
$runId = strtolower(bin2hex(random_bytes(5)));
$signingKey = hash('sha256', 'crontab-task-' . $runId) . hash('sha256', 'retry-' . $runId);
expectCrontabTenant(
    preg_match('/^peanut_admin_development_p0e_[a-z0-9]{1,11}_plugin_lifecycle$/D', $database) === 1,
    'Crontab Task Gate requires its exact registered P0-E database',
);

$pdo = new PDO(
    "mysql:host={$host};port={$port};dbname={$database};charset=utf8mb4",
    $user,
    $password,
    [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
        PDO::MYSQL_ATTR_MULTI_STATEMENTS => true,
    ],
);

createCrontabTenantSchema($pdo, $serverRoot);
$now = '2030-01-01 00:00:00.000';
$pdo->exec(<<<SQL
INSERT INTO pa_tenant
  (id, code, name, display_name, status, activated_at, created_at, updated_at)
VALUES
  (202, 'beta', 'Beta', 'Beta', 'active', '{$now}', '{$now}', '{$now}');
INSERT INTO pa_account (id, display_name, status, created_at, updated_at) VALUES
  (1001, 'Alpha', 'active', '{$now}', '{$now}'),
  (1002, 'Beta', 'active', '{$now}', '{$now}');
INSERT INTO pa_tenant_member
  (id, tenant_id, account_id, display_name, status, joined_at, created_at, updated_at)
VALUES
  (501, 101, 1001, 'Alpha', 'active', '{$now}', '{$now}', '{$now}'),
  (502, 202, 1002, 'Beta', 'active', '{$now}', '{$now}', '{$now}');
INSERT INTO pa_role
  (id, tenant_id, `key`, name, is_builtin, status, authorization_revision, created_at, updated_at)
VALUES
  (1201, 101, 'core.tenant-owner', 'Alpha owner', 1, 'active', 1, '{$now}', '{$now}'),
  (2202, 202, 'core.tenant-owner', 'Beta owner', 1, 'active', 1, '{$now}', '{$now}');
INSERT INTO pa_member_role (tenant_id, tenant_member_id, role_id, assigned_at)
VALUES
  (101, 501, 1201, '{$now}'),
  (202, 502, 2202, '{$now}');
INSERT INTO pa_module_installation
  (module_key, installed_version, manifest_schema_version, manifest_digest, status, installed_at, activated_at, created_at, updated_at)
VALUES
  ('official.task', '{$taskVersion}', 1, '{$taskDigest}', 'active', '{$now}', '{$now}', '{$now}', '{$now}');
INSERT INTO pa_tenant_module
  (tenant_id, module_key, status, source, enabled_at, created_at, updated_at)
VALUES
  (101, 'official.task', 'enabled', 'manual', '{$now}', '{$now}', '{$now}'),
  (202, 'official.task', 'enabled', 'manual', '{$now}', '{$now}', '{$now}');
SQL);

IsolatedBackendEnvironment::activateDatabase($host, $port, $database, $user, $password, 'multi-tenant');
$app = new think\App($serverRoot);
$app->initialize();
$alpha = crontabTenantContext(101, 1001, 501, 'crontab-alpha-' . $runId);
$beta = crontabTenantContext(202, 1002, 502, 'crontab-beta-' . $runId);
$task = [
    'tenant_id' => 999,
    'name' => 'Same task',
    'type' => 1,
    'command' => 'crontab:demo',
    'params' => '',
    'status' => CrontabEnum::START,
    'expression' => '* * * * *',
    'sort' => 0,
    'remark' => 'MT03-CRONTAB-TENANT-ISOLATION-001',
];
foreach ([[$alpha, 'alpha'], [$beta, 'beta']] as [$context, $suffix]) {
    expectCrontabTenant(
        app(ExecutionContextStore::class)->run(
            new \app\common\execution\AdminExecutionContext($context, 'test.crontab.add.' . $suffix),
            fn() => app(CrontabApplicationService::class)->add($task),
        ),
        'Tenant schedule creation failed',
    );
}
$alphaId = (int) app(ExecutionContextStore::class)->run(
    new \app\common\execution\AdminExecutionContext($alpha, 'test.crontab.query.alpha'),
    fn() => Crontab::where([])->where('name', 'Same task')->value('id'),
);
$betaId = (int) app(ExecutionContextStore::class)->run(
    new \app\common\execution\AdminExecutionContext($beta, 'test.crontab.query.beta'),
    fn() => Crontab::where([])->where('name', 'Same task')->value('id'),
);
expectCrontabTenant($alphaId > 0 && $betaId > 0 && $alphaId !== $betaId, 'Tenant schedules were not independently created');
expectCrontabTenantThrows(
    fn() => app(ExecutionContextStore::class)->run(
        new \app\common\execution\AdminExecutionContext($alpha, 'test.crontab.detail.cross-tenant'),
        fn() => app(CrontabApplicationService::class)->detail($betaId),
    ),
    'cross-Tenant schedule detail leaked',
);
expectCrontabTenantThrows(
    fn() => app(ExecutionContextStore::class)->run(
        new \app\common\execution\AdminExecutionContext($alpha, 'test.crontab.delete.cross-tenant'),
        fn() => app(CrontabApplicationService::class)->delete($betaId),
    ),
    'cross-Tenant schedule delete succeeded',
);

$windowNow = time();
$previousWindow = $windowNow - 60;
$setWindow = $pdo->prepare('UPDATE pa_crontab SET last_time=? WHERE id=?');
$setWindow->execute([$previousWindow, $alphaId]);
$setWindow->execute([$previousWindow, $betaId]);

$dispatches = [];
$taskTraces = [];
$dispatch = static function (string $command, array $params) use (&$dispatches, &$taskTraces): void {
    $scope = ScheduledTenantContext::require();
    $dispatches[] = [$command, $scope->tenantId(), $scope->contextIdentity(), $params];
    $current = app(CurrentExecutionContext::class)->current();
    $metadata = $current instanceof SystemExecutionContext ? $current->metadata : null;
    $taskTraces[] = [
        'job_key' => $metadata?->jobKey,
        'attempt_number' => $metadata?->attemptNumber,
        'handler_key' => $metadata?->handlerKey,
        'tenant_id' => $current?->tenantId(),
        'request_id' => $current?->requestId(),
    ];
    if ($scope->tenantId() === 202) {
        throw new RuntimeException('fixture retry');
    }
};
$taskProvider = new TaskModuleProvider();
$tasks = $taskProvider->jobs(
    app(\app\common\persistence\TenantPersistenceConfiguration::class),
    $signingKey,
    app(\app\common\execution\ExecutionContextStore::class),
    app(\app\common\execution\CurrentExecutionContext::class),
    app(\app\common\service\org\AdminDirectoryQuery::class),
    app(\app\common\service\module\ModuleExecutionBoundary::class),
    app(\app\common\services\CrontabCommandService::class),
    $dispatch,
    [],
    25,
);
$scheduler = $taskProvider->scheduler(
    $tasks,
    app(\PeanutAdmin\Modules\Task\Service\CrontabSchedulerService::class),
);
$scheduler->runDue($windowNow);

$jobs = $pdo->query(<<<'SQL'
SELECT id, job_key, handler_key, tenant_id, task_type, status, attempt_count, max_attempts, last_error_code
FROM pa_task_job ORDER BY tenant_id
SQL)->fetchAll();
expectCrontabTenant(count($jobs) === 2, 'due schedules did not create exactly one Task Job per Tenant');
expectCrontabTenant(
    $jobs[0]['tenant_id'] === 101
        && $jobs[0]['task_type'] === CrontabTaskDefinition::TASK_TYPE
        && $jobs[0]['status'] === 'succeeded'
        && (int) $jobs[0]['attempt_count'] === 1,
    'successful scheduled trigger did not complete through Task Runtime',
);
expectCrontabTenant(
    $jobs[1]['tenant_id'] === 202
        && $jobs[1]['status'] === 'queued'
        && (int) $jobs[1]['attempt_count'] === 1
        && (int) $jobs[1]['max_attempts'] === 3
        && $jobs[1]['last_error_code'] === 'CRONTAB_EXECUTION_FAILED',
    'failed scheduled trigger did not create a retryable Task attempt',
);
expectCrontabTenant(
    count($dispatches) === 2
        && $dispatches[0][1] !== $dispatches[1][1]
        && str_contains($dispatches[0][2], 'tenant=' . $dispatches[0][1])
        && str_contains($dispatches[1][2], 'tenant=' . $dispatches[1][1]),
    'scheduled handlers did not preserve two isolated Tenant contexts',
);
expectCrontabTenant(ScheduledTenantContext::current() === null, 'scheduled Tenant context leaked after Task handler');
expectCrontabTenant(app(ExecutionContextStore::class)->isEmpty(), 'Task execution context leaked after handler completion');
expectCrontabTenant(
    (int) $pdo->query('SELECT COUNT(*) FROM pa_task_job_attempt WHERE tenant_id=101') ->fetchColumn() === 1
        && (int) $pdo->query('SELECT COUNT(*) FROM pa_task_job_attempt WHERE tenant_id=202')->fetchColumn() === 1,
    'Task attempts crossed Tenant ownership',
);

$betaJobId = (int) $jobs[1]['id'];
for ($attempt = 2; $attempt <= 3; ++$attempt) {
    $pdo->prepare('UPDATE pa_task_job SET available_at=UTC_TIMESTAMP(3) WHERE tenant_id=202 AND id=?')
        ->execute([$betaJobId]);
    expectCrontabTenant(
        $tasks->runTenant(202, 'crontab-retry-' . $attempt . '-' . $runId) === 1,
        'Task retry attempt was not processed',
    );
}
$betaJob = $pdo->query("SELECT status,attempt_count,max_attempts,last_error_code FROM pa_task_job WHERE id={$betaJobId}")
    ->fetch();
expectCrontabTenant(
    $betaJob['status'] === 'dead'
        && (int) $betaJob['attempt_count'] === 3
        && (int) $betaJob['max_attempts'] === 3
        && $betaJob['last_error_code'] === 'CRONTAB_EXECUTION_FAILED',
    'existing Task retry policy did not reach its dead terminal state',
);
$attempts = $pdo->query(
    "SELECT attempt_number,status,error_code FROM pa_task_job_attempt WHERE tenant_id=202 AND job_id={$betaJobId} ORDER BY attempt_number",
)->fetchAll();
expectCrontabTenant(
    array_column($attempts, 'status') === ['retry', 'retry', 'dead']
        && array_column($attempts, 'error_code') === array_fill(0, 3, 'CRONTAB_EXECUTION_FAILED'),
    'Task attempt ledger did not own retry and terminal failure states',
);
$backoffs = array_map(
    static fn(string $json): int => (int) (json_decode($json, true, 16, JSON_THROW_ON_ERROR)['backoff_seconds'] ?? 0),
    $pdo->query(
        "SELECT metadata_json FROM pa_task_job_event WHERE tenant_id=202 AND job_id={$betaJobId} AND event_key='tenant.task.retry_scheduled' ORDER BY id",
    )->fetchAll(PDO::FETCH_COLUMN),
);
expectCrontabTenant($backoffs === [5, 10], 'Task Runtime exponential backoff policy changed');
$betaTrace = array_values(array_filter($taskTraces, static fn(array $trace): bool => $trace['tenant_id'] === 202));
expectCrontabTenant(
    count($betaTrace) === 3
        && array_values(array_unique(array_column($betaTrace, 'job_key'))) === [$jobs[1]['job_key']]
        && array_column($betaTrace, 'attempt_number') === [1, 2, 3]
        && array_values(array_unique(array_column($betaTrace, 'handler_key'))) === [$jobs[1]['handler_key']]
        && array_values(array_unique(array_column($betaTrace, 'request_id'))) === [$dispatches[1][2]],
    'Task trace lost stable job identity, distinct attempts, handler, Tenant or request trace',
);

$dispatchCount = count($dispatches);
expectCrontabTenant(
    $tasks->runTenant(202, 'crontab-terminal-' . $runId) === 0 && count($dispatches) === $dispatchCount,
    'terminal Crontab Task executed again',
);
$alphaScope = TenantScope::fromTrustedContext(
    101,
    CrontabTaskDefinition::contextIdentity(101, $alphaId, $previousWindow),
);
$scheduler->start($alphaScope, ['id' => $alphaId]);
expectCrontabTenant(
    count($dispatches) === $dispatchCount && (int) $pdo->query('SELECT COUNT(*) FROM pa_task_job')->fetchColumn() === 2,
    'same schedule window bypassed Task idempotency or repeated a terminal job',
);
expectCrontabTenant(
    (int) $pdo->query("SELECT status FROM pa_crontab WHERE id={$betaId}")->fetchColumn() === CrontabEnum::START
        && (string) $pdo->query("SELECT error FROM pa_crontab WHERE id={$betaId}")->fetchColumn() === '',
    'Crontab retained a second execution status/error path outside Task Runtime',
);

$diagnostics = (string) file_get_contents($serverRoot . '/app/platform/service/ops/PlatformDiagnosticBundleService.php');
expectCrontabTenant(
    str_contains($diagnostics, "'tenant_aggregate' => \$this->failedTaskGroups('pa_task_job', \$since)")
        && $betaJob['status'] === 'dead'
        && $betaJob['last_error_code'] === 'CRONTAB_EXECUTION_FAILED',
    'terminal Crontab failure is absent from the existing diagnostics projection',
);
$schedulerSource = (string) file_get_contents($serverRoot . '/app/modules/official/task/src/Service/CrontabSchedulerService.php');
$commandSource = (string) file_get_contents($serverRoot . '/app/command/Crontab.php');
$runtimeSource = (string) file_get_contents($serverRoot . '/app/modules/official/task/src/Infrastructure/Runtime/ThinkPhpTaskJobRuntime.php');
expectCrontabTenant(
    !str_contains($schedulerSource, 'Console::call')
        && !str_contains($commandSource, 'Console::call')
        && str_contains($runtimeSource, 'enqueueCrontab(')
        && str_contains($runtimeSource, '$definitions[] = $this->crontabs()'),
    'production Crontab still bypasses the Task Runtime execution path',
);
$workerSource = (string) file_get_contents($serverRoot . '/app/command/TenantTaskWorker.php');
expectCrontabTenant(
    str_contains($workerSource, "OperationalLog::error('tenant_task_worker_startup_failed'")
        && str_contains($workerSource, "'ASYNC_SIGNING_KEY_INVALID'")
        && !str_contains($workerSource, 'getTraceAsString'),
    'Task worker startup failures are not allowlisted operational diagnostics',
);
echo "MT03-CRONTAB-TENANT-ISOLATION-001 passed\n";
