<?php

declare(strict_types=1);

use PeanutAdmin\Modules\Task\Service\CrontabApplicationService;
use PeanutAdmin\Modules\Task\Service\CrontabTaskDefinition;
use app\command\Crontab as CrontabCommand;
use app\common\enum\CrontabEnum;
use app\common\execution\ExecutionContextStore;
use PeanutAdmin\Modules\Task\Model\Crontab;
use app\common\services\XlsxExportService;
use PeanutAdmin\Modules\File\Service\Storage\StorageService;
use PeanutAdmin\Modules\ImportExport\Service\TaskImportExportRuntime;
use PeanutAdmin\Modules\ImportExport\Infrastructure\File\AppFileMediaGateway;
use app\common\infrastructure\export\OperationLogExportProvider;
use app\command\TenantTaskWorker;
use PeanutAdmin\Modules\ImportExport\Engine\Application\ImportExportService;
use PeanutAdmin\Kernel\Auth\TenantContext;
use PeanutAdmin\Kernel\Auth\ValidatedTenantSession;
use PeanutAdmin\Kernel\Tenancy\TenantScope;
use PeanutAdmin\Modules\Task\Contract\TrustedJobPublisher;
use think\facade\Db;

require dirname(__DIR__, 2) . '/bootstrap/environment.php';
require dirname(__DIR__, 2) . '/vendor/autoload.php';

function expectTaskHost(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function failTaskHost(Throwable $exception): never
{
    fwrite(STDERR, 'TaskImportExportHostTest failed: ' . $exception->getMessage() . PHP_EOL);
    exit(1);
}

/** @return array<string,mixed> */
function taskHostJob(int $tenantId, string $identity): array
{
    $job = Db::name('task_job')
        ->where('tenant_id', $tenantId)
        ->where('idempotency_key_hash', hash('sha256', 'crontab:' . hash('sha256', $identity)))
        ->find();
    return is_array($job) ? $job : [];
}

$serverRoot = dirname(__DIR__, 2);
$app = new think\App();
try {
    $app->initialize();
} catch (Throwable $exception) {
    failTaskHost($exception);
}
set_exception_handler('failTaskHost');
if (in_array('--verify-failure-propagation', $_SERVER['argv'] ?? [], true)) {
    throw new RuntimeException('TASK_IMPORT_EXPORT_CONTROLLED_FAILURE');
}
$app->config->set(['mode' => 'multi-tenant'], 'deployment');
$app->config->set(['signing_key' => hash('sha256', 'PB04-TASK-OPS-HOST-001')], 'async');
putenv('DEPLOYMENT_MODE=multi-tenant');
$tenantId = (int) Db::name('tenant')->where('status', 'active')->order('id')->value('id');
expectTaskHost($tenantId > 0, 'active Tenant fixture is unavailable');
$context = TenantContext::fromValidatedSession(new ValidatedTenantSession(
    1,
    'PB04-TASK-IMPORT-EXPORT-HOST',
    $tenantId,
    1,
    1,
    'admin-web',
    new DateTimeImmutable('2031-01-01T00:00:00Z'),
    1,
), 'pb04-task-import-export-host');

expectTaskHost(class_exists(TaskImportExportRuntime::class), 'application async Runtime is missing');
expectTaskHost(!is_file($serverRoot . '/app/common/service/async/TaskImportExportRuntimeFactory.php'), 'retired static async Runtime factory was reintroduced');
expectTaskHost(class_exists(TenantTaskWorker::class), 'Tenant worker command is missing');
expectTaskHost(is_subclass_of(OperationLogExportProvider::class, \PeanutAdmin\Modules\ImportExport\Engine\Contract\DataProvider::class), 'operation-log export provider does not implement the official import/export contract');
expectTaskHost(is_subclass_of(AppFileMediaGateway::class, \PeanutAdmin\Modules\ImportExport\Engine\File\FileMediaGateway::class), 'private file gateway does not implement the official import/export contract');
expectTaskHost(class_exists(TrustedJobPublisher::class) && class_exists(ImportExportService::class), 'official async runtime classes are unavailable');

$migrationSource = (string) file_get_contents($serverRoot . '/database/init.sql');
expectTaskHost(str_contains($migrationSource, 'pa_task_job'), 'Task/Job schema is not owned by the application migration');
expectTaskHost(str_contains($migrationSource, 'pa_import_export_operation'), 'Import/Export schema is not owned by the application migration');
expectTaskHost(str_contains($migrationSource, 'pa_file_object'), 'private file metadata schema is not owned by the application migration');
expectTaskHost(!str_contains($migrationSource, 'public/storage'), 'async migration refers to public storage');

$runtimeSource = (string) file_get_contents($serverRoot . '/app/modules/official/import_export/src/Service/TaskImportExportRuntime.php');
expectTaskHost(str_contains($runtimeSource, 'TaskJobRuntime'), 'Import/Export does not depend on the official Task Runtime contract');
expectTaskHost(!str_contains($runtimeSource, 'PdoTaskJobRepository'), 'Import/Export bypasses the official Task Runtime repository boundary');
expectTaskHost(!str_contains($runtimeSource, 'TrustedJobPublisher'), 'Import/Export bypasses the official Task Runtime publisher boundary');
$moduleProviderSource = (string) file_get_contents($serverRoot . '/app/modules/official/import_export/src/ModuleProvider.php');
expectTaskHost(
    str_contains($moduleProviderSource, 'TaskImportExportRuntime::class')
        && str_contains($moduleProviderSource, 'TaskJobRuntime::class')
        && str_contains($moduleProviderSource, 'AppFileMediaGateway::class')
        && !str_contains($moduleProviderSource, 'StorageService::class'),
    'Import/Export container assembly is incomplete',
);
$taskRuntimeSource = (string) file_get_contents($serverRoot . '/app/modules/official/task/src/Infrastructure/Runtime/ThinkPhpTaskJobRuntime.php');
expectTaskHost(str_contains($taskRuntimeSource, 'TrustedJobPublisher'), 'official.task does not own trusted submission');
expectTaskHost(str_contains($taskRuntimeSource, 'LocalWorker'), 'official.task does not own worker execution');
$jobExecutionSource = (string) file_get_contents($serverRoot . '/app/modules/official/task/src/Contract/JobExecution.php');
$workerSource = (string) file_get_contents($serverRoot . '/app/modules/official/task/src/Job/Execution/LocalWorker.php');
$csvRunnerSource = (string) file_get_contents($serverRoot . '/app/modules/official/import_export/src/Engine/Execution/CsvOperationRunner.php');
expectTaskHost(
    str_contains($jobExecutionSource, 'function checkpoint()')
        && str_contains($jobExecutionSource, 'function assertLeaseOwned()')
        && str_contains($workerSource, '$execution->assertLeaseOwned()')
        && str_contains($csvRunnerSource, '$execution->checkpoint()')
        && str_contains($csvRunnerSource, '$execution->assertLeaseOwned()'),
    'task lease renewal and stale-worker fencing are not exposed to batch handlers',
);
$gatewaySource = (string) file_get_contents($serverRoot . '/app/modules/official/import_export/src/Infrastructure/File/AppFileMediaGateway.php');
expectTaskHost(!str_contains($gatewaySource, "'/public/"), 'private gateway writes below public/');
expectTaskHost(
    str_contains($gatewaySource, 'private FileStorage $storage')
        && !str_contains($gatewaySource, 'new FileStorage(')
        && !str_contains($gatewaySource, 'PDO')
        && !str_contains($gatewaySource, 'pa_import_export_operation'),
    'private gateway does not use the injected Storage Runtime',
);
expectTaskHost(
    str_contains($runtimeSource, 'queries()->resultFile('),
    'result-file authorization does not pass through the ImportExport query contract',
);

$suffix = strtolower(substr(bin2hex(random_bytes(8)), 0, 16));
$taskName = 'PB04任务' . $suffix;
$taskId = 0;
$taskIdentities = [];
$exportPath = '';
$exportFileKey = '';

try {
    expectTaskHost(
        app(ExecutionContextStore::class)->run(
            new \app\common\execution\AdminExecutionContext($context, 'test.task-import-export.crontab.add'),
            fn() => app(CrontabApplicationService::class)->add([
                'name' => $taskName,
                'type' => 1,
                'command' => 'crontab:demo',
                'params' => '',
                'status' => CrontabEnum::START,
                'expression' => '* * * * *',
                'sort' => 0,
                'remark' => 'PB04-TASK-OPS-HOST-001',
            ]),
        ),
        'temporary crontab was not created',
    );
    $taskId = (int) app(ExecutionContextStore::class)->run(
        new \app\common\execution\AdminExecutionContext($context, 'test.task-import-export.crontab.query'),
        fn() => Crontab::where('name', $taskName)->value('id'),
    );
    expectTaskHost($taskId > 0, 'temporary crontab was not created');

    $task = app(ExecutionContextStore::class)->run(
        new \app\common\execution\AdminExecutionContext($context, 'test.task-import-export.crontab.find'),
        fn() => Crontab::findOrEmpty($taskId),
    );
    expectTaskHost(!$task->isEmpty(), 'temporary crontab is missing');
    $taskIdentities[] = CrontabTaskDefinition::contextIdentity($tenantId, $taskId, 1);
    app(CrontabCommand::class)->start(
        TenantScope::fromTrustedContext($tenantId, $taskIdentities[0]),
        $task->getData(),
    );
    $job = taskHostJob($tenantId, $taskIdentities[0]);
    expectTaskHost(($job['status'] ?? null) === 'succeeded', 'allowed task job must succeed');
    $task = app(ExecutionContextStore::class)->run(
        new \app\common\execution\AdminExecutionContext($context, 'test.task-import-export.crontab.find.allowed'),
        fn() => Crontab::findOrEmpty($taskId),
    );
    expectTaskHost((string) $task->error === '', 'allowed task must succeed');
    expectTaskHost((int) $task->status === CrontabEnum::START, 'successful task must remain started');

    app(ExecutionContextStore::class)->run(
        new \app\common\execution\AdminExecutionContext($context, 'test.task-import-export.crontab.seed-denied'),
        fn() => Db::name('crontab')->where('id', $taskId)->update(['command' => 'crontab']),
    );
    $task = app(ExecutionContextStore::class)->run(
        new \app\common\execution\AdminExecutionContext($context, 'test.task-import-export.crontab.find.denied'),
        fn() => Crontab::findOrEmpty($taskId),
    );
    $taskIdentities[] = CrontabTaskDefinition::contextIdentity($tenantId, $taskId, 2);
    app(CrontabCommand::class)->start(
        TenantScope::fromTrustedContext($tenantId, $taskIdentities[1]),
        $task->getData(),
    );
    $job = taskHostJob($tenantId, $taskIdentities[1]);
    expectTaskHost(($job['status'] ?? null) === 'dead', 'disallowed task job must fail closed');
    expectTaskHost(($job['last_error_code'] ?? null) === 'TASK_HANDLER_FAILED', 'disallowed task job lost its stable failure code');
    $task = app(ExecutionContextStore::class)->run(
        new \app\common\execution\AdminExecutionContext($context, 'test.task-import-export.crontab.find.denied-result'),
        fn() => Crontab::findOrEmpty($taskId),
    );
    expectTaskHost((int) $task->status === CrontabEnum::START, 'Task Runtime failure must not create a second Crontab state');
    expectTaskHost((string) $task->error === '', 'Task Runtime failure must not write the retired Crontab error channel');

    app(ExecutionContextStore::class)->run(
        new \app\common\execution\AdminExecutionContext($context, 'test.task-import-export.crontab.seed-retry'),
        fn() => Db::name('crontab')->where('id', $taskId)->update(['command' => 'crontab:demo']),
    );
    expectTaskHost(
        app(ExecutionContextStore::class)->run(
            new \app\common\execution\AdminExecutionContext($context, 'test.task-import-export.crontab.operate'),
            fn() => app(CrontabApplicationService::class)->operate($taskId, 'start'),
        ),
        'manual retry failed',
    );
    $task = app(ExecutionContextStore::class)->run(
        new \app\common\execution\AdminExecutionContext($context, 'test.task-import-export.crontab.find.retry'),
        fn() => Crontab::findOrEmpty($taskId),
    );
    expectTaskHost((int) $task->status === CrontabEnum::START, 'manual retry must restore started state');
    expectTaskHost((string) $task->error === '', 'manual retry must clear the previous error');
    $taskIdentities[] = CrontabTaskDefinition::contextIdentity($tenantId, $taskId, 3);
    app(CrontabCommand::class)->start(
        TenantScope::fromTrustedContext($tenantId, $taskIdentities[2]),
        $task->getData(),
    );
    $job = taskHostJob($tenantId, $taskIdentities[2]);
    expectTaskHost(($job['status'] ?? null) === 'succeeded', 'retried task job must succeed');
    $task = app(ExecutionContextStore::class)->run(
        new \app\common\execution\AdminExecutionContext($context, 'test.task-import-export.crontab.find.retried'),
        fn() => Crontab::findOrEmpty($taskId),
    );
    expectTaskHost((int) $task->status === CrontabEnum::START, 'retried task must succeed');
    expectTaskHost((string) $task->error === '', 'retried task must finish without an error');

    $file = app(ExecutionContextStore::class)->run(
        new \app\common\execution\AdminExecutionContext($context, 'test.task-import-export.xlsx.export'),
        fn() => app(XlsxExportService::class)->create(
            'PB04-task-export-' . $suffix,
            ['任务', '次数', '公式文本'],
            [['crontab:demo', 2, '=1+1']],
        ),
    );
    $exportFileKey = (string) ($file['file_key'] ?? '');
    $exportPath = $serverRoot . '/private/storage/' . (string) ($file['object_key'] ?? '');
    expectTaskHost($exportFileKey !== '', 'XLSX export file key is missing');
    expectTaskHost(is_file($exportPath), 'XLSX export file was not created');
    $zip = new ZipArchive();
    expectTaskHost($zip->open($exportPath) === true, 'XLSX export is not a readable ZIP');
    $sheet = $zip->getFromName('xl/worksheets/sheet1.xml');
    expectTaskHost(is_string($sheet), 'XLSX worksheet is missing');
    expectTaskHost(str_contains($sheet, 'crontab:demo'), 'XLSX text cell is missing');
    expectTaskHost(str_contains($sheet, '<v>2</v>'), 'XLSX numeric cell is missing');
    expectTaskHost(str_contains($sheet, '=1+1'), 'formula-shaped input must remain inline text');
    $zip->close();

    $exportCallers = [
        'app/adminapi/application/auth/AdminApplicationService.php',
        'app/adminapi/application/dept/JobsApplicationService.php',
        'app/modules/official/member/src/Service/MemberAdministrationService.php',
        'app/modules/official/payment/src/Service/RechargeAdministrationService.php',
        'app/adminapi/application/log/OperationLogApplicationService.php',
    ];
    foreach ($exportCallers as $relativePath) {
        $source = (string) file_get_contents($serverRoot . '/' . $relativePath);
        expectTaskHost(
            str_contains($source, 'private readonly XlsxExportService $xlsxExport')
                && str_contains($source, '$this->xlsxExport->create('),
            'export caller must use the injected application XLSX owner: ' . $relativePath,
        );
        expectTaskHost(
            !str_contains($source, 'XlsxExportService::'),
            'export caller retained a static XLSX service lookup: ' . $relativePath,
        );
        expectTaskHost(!str_contains($source, 'new ZipArchive'), 'duplicate XLSX writer: ' . $relativePath);
        expectTaskHost(!str_contains($source, 'function createXlsx'), 'duplicate XLSX helper: ' . $relativePath);
        expectTaskHost(!str_contains($source, 'PeanutAdmin\\Modules\\Task\\Job'), 'official.task internal deep import: ' . $relativePath);
        expectTaskHost(!str_contains($source, 'PeanutAdmin\\Modules\\ImportExport\\Engine'), 'official.import-export internal deep import: ' . $relativePath);
    }

    foreach ([
        'app/command/Crontab.php',
        'app/modules/official/task/src/Service/CrontabApplicationService.php',
        'app/adminapi/services/generator/GeneratorService.php',
        'app/adminapi/service/generator/GeneratorArchiveService.php',
    ] as $relativePath) {
        $source = (string) file_get_contents($serverRoot . '/' . $relativePath);
        expectTaskHost(!str_contains($source, 'PeanutAdmin\\Modules\\Task\\Job'), 'official.task internal deep import: ' . $relativePath);
        expectTaskHost(!str_contains($source, 'PeanutAdmin\\Modules\\ImportExport\\Engine'), 'official.import-export internal deep import: ' . $relativePath);
    }
} finally {
    if ($exportFileKey !== '') {
        app(StorageService::class)->delete($tenantId, $exportFileKey);
    }
    if ($taskIdentities !== []) {
        $jobIds = Db::name('task_job')
            ->where('tenant_id', $tenantId)
            ->whereIn('idempotency_key_hash', array_map(
                static fn(string $identity): string => hash('sha256', 'crontab:' . hash('sha256', $identity)),
                $taskIdentities,
            ))
            ->column('id');
        if ($jobIds !== []) {
            Db::name('task_job_event')->where('tenant_id', $tenantId)->whereIn('job_id', $jobIds)->delete();
            Db::name('task_job_attempt')->where('tenant_id', $tenantId)->whereIn('job_id', $jobIds)->delete();
            Db::name('task_job')->where('tenant_id', $tenantId)->whereIn('id', $jobIds)->delete();
        }
    }
    if ($taskId > 0) {
        app(ExecutionContextStore::class)->run(
            new \app\common\execution\AdminExecutionContext($context, 'test.task-import-export.crontab.cleanup'),
            fn() => Db::name('crontab')->where('id', $taskId)->delete(),
        );
    }
}

expectTaskHost(!is_file($exportPath), 'temporary XLSX was not cleaned');
expectTaskHost(
    app(ExecutionContextStore::class)->run(
        new \app\common\execution\AdminExecutionContext($context, 'test.task-import-export.crontab.cleanup.verify'),
        fn() => Db::name('crontab')->where('name', $taskName)->count(),
    ) === 0,
    'temporary crontab was not cleaned',
);

echo "PB04-TASK-OPS-HOST-001 passed\n";
