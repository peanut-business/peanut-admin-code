<?php
declare(strict_types=1);

namespace PeanutAdmin\Modules\Task\Infrastructure\Runtime;

use PeanutAdmin\Modules\Task\Service\CrontabTaskDefinition;
use PeanutAdmin\Modules\Task\Service\TaskAuthorizationRouter;
use PeanutAdmin\Modules\Task\Contract\TaskJobRuntime;
use PeanutAdmin\Modules\Task\Contract\TaskWorkerDefinition;
use app\common\infrastructure\async\ModuleAwareTaskHandler;
use app\common\execution\CurrentExecutionContext;
use app\common\execution\ExecutionContextStore;
use app\common\infrastructure\module\ModuleExecutionBoundary;
use PeanutAdmin\Modules\Identity\Contract\AdminDirectoryQuery;
use app\common\services\CrontabCommandService;
use Closure;
use PeanutAdmin\Kernel\Async\JobHandlerAdapter;
use PeanutAdmin\Kernel\Async\TrustedEnvelopeCodec;
use PeanutAdmin\Modules\Task\Contract\TaskJobService;
use PeanutAdmin\Modules\Task\Job\Execution\LocalWorker;
use PeanutAdmin\Modules\Task\Job\Execution\TaskHandlerRegistry;
use PeanutAdmin\Modules\Task\Job\Persistence\TaskJobStore;
use PeanutAdmin\Modules\Task\Contract\TaskSubmissionProvider;
use PeanutAdmin\Modules\Task\Job\Submission\TaskSubmissionRegistry;
use PeanutAdmin\Modules\Task\Contract\TrustedJobPublisher;
use PeanutAdmin\Kernel\Tenancy\TenantScope;

final readonly class ThinkPhpTaskJobRuntime implements TaskJobRuntime
{
    public function __construct(
        private TaskJobStore $repository,
        private string $signingKey,
        private ExecutionContextStore $executionContexts,
        private CurrentExecutionContext $currentExecution,
        private AdminDirectoryQuery $adminDirectory,
        private ModuleExecutionBoundary $modules,
        private CrontabCommandService $commands,
        private Closure $dispatch,
        private int $workerLimit,
    ) {
        if (strlen($this->signingKey) < 32) {
            throw new \runtimeException('ASYNC_SIGNING_KEY_INVALID');
        }
    }

    public function publisher(TaskSubmissionProvider ...$providers): TrustedJobPublisher
    {
        return new TrustedJobPublisher(
            $this->repository,
            new TaskSubmissionRegistry($providers),
            $this->envelopes(),
        );
    }

    public function jobs(): TaskJobService
    {
        return new TaskJobService($this->repository);
    }

    public function enqueueCrontab(TenantScope $scope, int $scheduleId, string $contextIdentity): void
    {
        $definition = $this->crontabs();
        $this->publisher($definition)->publish(
            $definition->submissionContext($scope, $contextIdentity),
            CrontabTaskDefinition::TASK_TYPE,
            ['schedule_id' => $scheduleId, 'context_identity' => $contextIdentity],
            'crontab:' . hash('sha256', $contextIdentity),
        );
    }

    public function runTenant(int $tenantId, string $workerId, TaskWorkerDefinition ...$definitions): int
    {
        if ($tenantId < 1) {
            throw new \runtimeException('ASYNC_TENANT_INVALID');
        }

        $definitions[] = $this->crontabs();
        $handlers = [];
        foreach ($definitions as $definition) {
            $handlers[] = new ModuleAwareTaskHandler(
                $this->modules,
                $this->executionContexts,
                $definition->ownerModuleKey(),
                $definition->handler(),
            );
        }
        $worker = new LocalWorker(
            $tenantId,
            $workerId,
            $this->repository,
            new TaskHandlerRegistry($handlers),
            new JobHandlerAdapter($this->envelopes(), new TaskAuthorizationRouter($definitions)),
        );

        $processed = 0;
        $limit = min(1000, max(1, $this->workerLimit));
        while ($processed < $limit && $worker->runOnce() !== null) {
            ++$processed;
        }
        return $processed;
    }

    private function envelopes(): TrustedEnvelopeCodec
    {
        return new TrustedEnvelopeCodec($this->signingKey);
    }

    private function crontabs(): CrontabTaskDefinition
    {
        return new CrontabTaskDefinition(
            $this->adminDirectory,
            $this->modules,
            $this->executionContexts,
            $this->currentExecution,
            $this->commands,
            $this->dispatch,
        );
    }
}
