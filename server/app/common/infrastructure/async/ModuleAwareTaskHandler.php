<?php

declare(strict_types=1);

namespace app\common\infrastructure\async;

use app\common\execution\ExecutionContextStore;
use app\common\execution\SystemExecutionContext;
use app\common\infrastructure\module\ModuleExecutionBoundary;
use PeanutAdmin\Kernel\Context\AuthorizedOperationContext;
use PeanutAdmin\Modules\Task\Contract\JobExecution;
use PeanutAdmin\Modules\Task\Contract\TaskHandler;
use PeanutAdmin\Modules\Task\Job\Application\TaskJobException;
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
        $execution->addAuthorizationCheck(function () use ($context, $execution): void {
            try {
                $ambient = $this->executionContexts->require();
                if (!$ambient instanceof SystemExecutionContext || $ambient->tenantId() !== $context->tenantContext->tenantId
                    || $ambient->metadata->jobKey !== $execution->jobKey || $ambient->metadata->attemptNumber !== $execution->attemptNumber
                    || $ambient->metadata->handlerKey !== $this->key() || $this->executionContexts->rootIdentity() === null) {
                    throw TaskJobException::denied();
                }
                $this->modules->assertWorker('official.task');
                $this->modules->assertWorker($this->moduleKey);
            } catch (AuthException|ModuleException|\DomainException) {
                throw TaskJobException::denied();
            }
        });
        $execution->checkpoint();
        $this->inner->handle($context, $execution);
        $execution->assertLeaseOwned();
    }
}
