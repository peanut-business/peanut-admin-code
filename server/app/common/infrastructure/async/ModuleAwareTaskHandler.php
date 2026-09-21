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
use app\modules\official\task\job\Application\TaskJobException;
use PeanutAdmin\Kernel\Auth\AuthException;
use PeanutAdmin\Kernel\Module\ModuleException;

/** Rechecks Task and owning Module authorization at every worker fence. */
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
        $systemContext = new SystemExecutionContext(
            new TenantSystemContext(
                $context->tenantContext->tenantId,
                'task-worker',
                'async.worker',
                $execution->jobKey,
            ),
            new SystemExecutionMetadata($execution->jobKey, $execution->attemptNumber, $this->key()),
        );
        $execution->addAuthorizationCheck(function () use ($systemContext): void {
            $this->executionContexts->run($systemContext, function (): void {
                try {
                    $this->modules->assertWorker('official.task');
                    $this->modules->assertWorker($this->moduleKey);
                } catch (AuthException|ModuleException|\DomainException) {
                    throw TaskJobException::denied();
                }
            });
        });
        $execution->checkpoint();
        $this->executionContexts->run(
            $systemContext,
            function () use ($context, $execution): void {
                $this->inner->handle($context, $execution);
                $execution->assertLeaseOwned();
            },
        );
    }
}
