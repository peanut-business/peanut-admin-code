<?php
declare(strict_types=1);

namespace PeanutAdmin\Modules\Task\Service;

use PeanutAdmin\Modules\Task\Contract\TaskJobRuntime;
use PeanutAdmin\Modules\Task\Contract\TaskScheduler;
use PeanutAdmin\Modules\Task\Contract\TaskWorkerDefinition;
use PeanutAdmin\Kernel\Tenancy\TenantScope;

final readonly class TaskSchedulerService implements TaskScheduler
{
    /** @var list<TaskWorkerDefinition> */
    private array $definitions;

    public function __construct(
        private TaskJobRuntime $tasks,
        private CrontabSchedulerService $crontabs,
        TaskWorkerDefinition ...$definitions,
    ) {
        $this->definitions = $definitions;
    }

    public function runDue(int $now): void
    {
        $tenantIds = $this->crontabs->runDue(
            $now,
            fn(TenantScope $scope, array $item) => $this->tasks->enqueueCrontab(
                $scope,
                (int)$item['id'],
                $scope->contextIdentity(),
            ),
        );
        foreach ($tenantIds as $tenantId) {
            $this->tasks->runTenant($tenantId, $this->workerId(), ...$this->definitions);
        }
    }

    public function start(TenantScope $scope, array $item): void
    {
        $this->tasks->enqueueCrontab($scope, (int)($item['id'] ?? 0), $scope->contextIdentity());
        $this->tasks->runTenant($scope->tenantId(), $this->workerId(), ...$this->definitions);
    }

    private function workerId(): string
    {
        return 'crontab-' . getmypid() . '-' . bin2hex(random_bytes(6));
    }
}
