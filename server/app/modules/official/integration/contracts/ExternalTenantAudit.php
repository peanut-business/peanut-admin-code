<?php

declare(strict_types=1);

namespace app\modules\official\integration\contracts;

interface ExternalTenantAudit
{
    /** @param array<string, int|string> $attributes */
    public function record(string $outcome, array $attributes): void;
}
