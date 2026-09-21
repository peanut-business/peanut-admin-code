<?php
declare(strict_types=1);

namespace app\common\infrastructure\async;

use app\common\execution\ExecutionContextStore;
use app\common\execution\SystemExecutionContext;
use app\common\execution\SystemExecutionMetadata;
use app\common\infrastructure\module\ModuleExecutionBoundary;
use PeanutAdmin\Kernel\Context\AuthorizedOperationContext;
use PeanutAdmin\Kernel\Context\TenantSystemContext;
use app\modules\official\task\contracts\JobExecution;
use app\modules\official\task\contracts\TaskHandler;

/** Rechecks the owning Module immediately before a background handler runs. */
final readonly class ModuleAwareTaskHandler implements TaskHandler
{
    public function __construct(
        private ModuleExecutionBoundary $modules,
        private ExecutionContextStore $executionContexts,
        private string $moduleKey,
        private TaskHandler $inner,
    ) {
        if (preg_match('/^[a-z0-9][a-z0-9._-]{1,127}$/D', trim($moduleKey)) !== 1) {
            throw new \InvalidArgumentException('MODULE_CONTEXT_INVALID');
        }
    }

    public function key(): string
    {
        return $this->inner->key();
    }

    public function handle(AuthorizedOperationContext $context, JobExecution $execution): void
    {
        $execution->checkpoint();
        $this->executionContexts->run(
            new SystemExecutionContext(
                new TenantSystemContext(
                    $context->tenantContext->tenantId,
                    'task-worker',
                    'async.worker',
                    $execution->jobKey,
                ),
                new SystemExecutionMetadata($execution->jobKey, $execution->attemptNumber, $this->key()),
            ),
            function () use ($context, $execution): void {
                $this->modules->assertWorker('official.task');
                $this->modules->assertWorker($this->moduleKey);
                $this->inner->handle($context, $execution);
            },
        );
    }
}
