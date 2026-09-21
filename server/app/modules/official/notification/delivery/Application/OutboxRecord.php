<?php

declare(strict_types=1);

namespace app\modules\official\notification\delivery\Application;

final readonly class OutboxRecord
{
    public function __construct(
        public string $outboxKey,
        public int $tenantId,
        public string $channel,
        public string $status,
        public ?string $jobKey,
    ) {}
}
