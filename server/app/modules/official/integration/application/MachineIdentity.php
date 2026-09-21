<?php

declare(strict_types=1);

namespace app\modules\official\integration\application;

final readonly class MachineIdentity
{
    /** @param list<string> $scopes */
    public function __construct(
        public string $identityKey,
        public string $name,
        public array $scopes,
        public string $status,
        public string $tokenPrefix,
        public string $tokenLastFour,
        public ?string $expiresAt,
        public ?string $lastUsedAt,
        public int $revision,
        public string $createdAt,
    ) {}
}
