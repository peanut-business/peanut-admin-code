<?php

declare(strict_types=1);

namespace app\modules\official\task\contracts;

use Closure;
use Throwable;

final readonly class JobExecution
{
    /** @param array<string, mixed> $payload */
    public function __construct(
        public string $jobKey,
        public int $tenantId,
        public int $attemptNumber,
        public array $payload,
        private Closure $renewLease,
        private Closure $assertLeaseOwned,
    ) {}

    /**
     * Renew the current claim before the next bounded batch or side effect.
     * A lost or expired lease throws and the handler must stop immediately.
     */
    public function checkpoint(): void
    {
        try {
            ($this->renewLease)();
        } catch (Throwable $exception) {
            throw new LeaseLostException(previous: $exception);
        }
    }

    /**
     * Fence a result commit after work that may have crossed the lease limit.
     */
    public function assertLeaseOwned(): void
    {
        try {
            ($this->assertLeaseOwned)();
        } catch (Throwable $exception) {
            throw new LeaseLostException(previous: $exception);
        }
    }
}
