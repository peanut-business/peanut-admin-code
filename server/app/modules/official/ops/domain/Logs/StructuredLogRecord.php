<?php

declare(strict_types=1);

namespace app\modules\official\ops\domain\Logs;

use InvalidArgumentException;
use app\modules\official\ops\domain\Support\Contract;

final readonly class StructuredLogRecord
{
    public function __construct(
        public string $eventKey,
        public string $severity,
        public string $componentKey,
        public string $occurredAt,
        public ?string $requestId,
        public int $occurrences,
    ) {
        Contract::qualifiedKey($eventKey, 96);
        Contract::qualifiedKey($componentKey, 64);
        Contract::instant($occurredAt);
        if ($requestId !== null) {
            Contract::opaqueKey($requestId, 'req_', 128);
        }
        if (!in_array($severity, LogSeverity::VALUES, true)
            || $occurrences < 1 || $occurrences > 1000000
        ) {
            throw new InvalidArgumentException('Invalid structured log record.');
        }
    }
}
