<?php
declare(strict_types=1);

namespace app\modules\official\task;

use app\modules\official\task\services\CrontabSchedulerService;
use app\modules\official\task\services\TaskSchedulerService;
use app\modules\official\task\services\TaskBootstrapService;
use app\modules\official\task\infrastructure\runtime\ThinkPhpTaskJobRuntime;
use app\modules\official\task\contracts\TaskJobRuntime;
use app\modules\official\task\contracts\TaskScheduler;
use app\modules\official\task\contracts\TaskBootstrapCommands;
use app\modules\official\task\contracts\TaskWorkerDefinition;
use app\modules\official\task\contracts\TaskDiagnosticQuery;
use app\modules\official\task\services\TaskDiagnosticService;
use app\common\execution\CurrentExecutionContext;
use app\common\execution\ExecutionContextStore;
use app\common\infrastructure\module\ModuleExecutionBoundary;
use app\modules\official\identity\contracts\AdminDirectoryQuery;
use app\common\services\CrontabCommandService;
use Closure;
use app\common\persistence\TenantPersistenceConfiguration;
use PeanutAdmin\Kernel\Module\ModuleProvider as ModuleProviderContract;
use think\App;
use app\modules\official\task\job\Persistence\TaskJobStore;

final class ModuleProvider implements ModuleProviderContract
{
    public function moduleKey(): string
    {
        return 'official.task';
    }

    public function scheduler(
        TaskJobRuntime $tasks,
        CrontabSchedulerService $crontabs,
        TaskWorkerDefinition ...$definitions,
    ): TaskScheduler
    {
        return new TaskSchedulerService($tasks, $crontabs, ...$definitions);
    }

    public function jobs(
        TenantPersistenceConfiguration $persistence,
        string $signingKey,
        ExecutionContextStore $executionContexts,
        CurrentExecutionContext $currentExecution,
        AdminDirectoryQuery $adminDirectory,
        ModuleExecutionBoundary $modules,
        CrontabCommandService $commands,
        Closure $dispatch,
        int $workerLimit,
    ): TaskJobRuntime
    {
        return new ThinkPhpTaskJobRuntime(
            new TaskJobStore($persistence->mode, $persistence->instanceTenantId),
            $signingKey,
            $executionContexts,
            $currentExecution,
            $adminDirectory,
            $modules,
            $commands,
            $dispatch,
            $workerLimit,
        );
    }

    public function bindings(): array
    {
        return [
            TaskJobRuntime::class => fn(App $app): TaskJobRuntime => $this->jobs(
                $app->make(TenantPersistenceConfiguration::class),
                (string)$app->config->get('async.signing_key', ''),
                $app->make(ExecutionContextStore::class),
                $app->make(CurrentExecutionContext::class),
                $app->make(AdminDirectoryQuery::class),
                $app->make(ModuleExecutionBoundary::class),
                $app->make(CrontabCommandService::class),
                Closure::fromCallable([$app->make('console'), 'call']),
                (int)$app->config->get('async.worker_limit', 25),
            ),
            TaskBootstrapCommands::class => TaskBootstrapService::class,
            TaskDiagnosticQuery::class => TaskDiagnosticService::class,
            TaskScheduler::class => fn(App $app): TaskScheduler => $this->scheduler(
                $app->make(TaskJobRuntime::class),
                $app->make(CrontabSchedulerService::class),
            ),
        ];
    }
}
