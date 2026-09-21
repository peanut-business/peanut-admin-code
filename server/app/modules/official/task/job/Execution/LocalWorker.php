<?php

declare(strict_types=1);

namespace app\modules\official\task\job\Execution;

use PeanutAdmin\Kernel\Async\JobHandlerAdapter;
use think\facade\Db;
use app\modules\official\task\contracts\JobExecution;
use app\modules\official\task\contracts\LeaseLostException;
use app\modules\official\task\contracts\RetryableTaskException;
use app\modules\official\task\job\Application\TaskJobException;
use app\modules\official\task\job\Persistence\TaskJobStore;
use Throwable;

final readonly class LocalWorker
{
    public function __construct(
        private int $tenantId,
        private string $workerId,
        private TaskJobStore $repository,
        private TaskHandlerRegistry $handlers,
        private JobHandlerAdapter $authorization,
        private int $leaseSeconds = 60,
    ) {
        if ($tenantId < 1
            || preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]{2,127}$/D', $workerId) !== 1
            || $leaseSeconds < 5 || $leaseSeconds > 3600
        ) {
            throw TaskJobException::invalid();
        }
    }

    public function runOnce(): ?string
    {
        $claim = Db::transaction(
            fn(): ?JobClaim => $this->repository->claim($this->tenantId, $this->workerId, $this->leaseSeconds),
        );
        if ($claim === null) {
            return null;
        }
        try {
            $this->repository->assertExecutable($claim);
            $handler = $this->handlers->require($claim->handlerKey);
            $authorization = new JobAuthorizationFence($this->authorization, $claim);
            $authorization->handle(
                function ($context, $envelope) use ($claim, $handler, $authorization): void {
                    $execution = new JobExecution(
                        $claim->jobKey,
                        $claim->tenantId,
                        $claim->attemptNumber,
                        $claim->payload,
                        function () use ($claim): void {
                            Db::transaction(function () use ($claim): void {
                                $this->renew($claim);
                                $this->repository->assertExecutable($claim);
                            });
                        },
                        fn() => $this->repository->assertExecutable($claim),
                        fn() => $authorization->assertAuthorized(),
                    );
                    $handler->handle($context, $execution);
                    $execution->assertLeaseOwned();
                },
            );
        } catch (LeaseLostException) {
            return 'lease_lost';
        } catch (RetryableTaskException $exception) {
            return $this->failClaim($claim, $exception->safeCode, true, $this->backoff($claim->attemptNumber));
        } catch (Throwable $exception) {
            $code = $exception instanceof TaskJobException ? $exception->problemCode : 'TASK_HANDLER_FAILED';
            return $this->failClaim($claim, $code, false, 0);
        }
        try {
            Db::transaction(function () use ($claim): void {
                $this->repository->succeed($claim);
            });
        } catch (TaskJobException $exception) {
            if ($exception->problemCode === 'TASK_STATE_CONFLICT') {
                return 'lease_lost';
            }
            throw $exception;
        }
        return 'succeeded';
    }

    private function failClaim(JobClaim $claim, string $code, bool $retryable, int $backoff): string
    {
        try {
            return Db::transaction(
                fn(): string => $this->repository->fail($claim, $code, $retryable, $backoff),
            );
        } catch (TaskJobException $exception) {
            if ($exception->problemCode === 'TASK_STATE_CONFLICT') {
                return 'lease_lost';
            }
            throw $exception;
        }
    }

    public function renew(JobClaim $claim): void
    {
        if ($claim->tenantId !== $this->tenantId) {
            throw TaskJobException::denied();
        }
        $this->repository->renew($claim, $this->leaseSeconds);
    }

    private function backoff(int $attempt): int
    {
        return min(300, 5 * (2 ** max(0, $attempt - 1)));
    }

}
