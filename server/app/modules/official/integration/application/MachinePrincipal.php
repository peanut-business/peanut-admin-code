<?php

declare(strict_types=1);

namespace app\modules\official\integration\application;

final readonly class MachinePrincipal
{
    /** @param list<string> $scopes */
    public function __construct(
        public int $tenantId,
        public string $identityKey,
        public array $scopes,
        public string $audience = 'machine',
    ) {}
}
