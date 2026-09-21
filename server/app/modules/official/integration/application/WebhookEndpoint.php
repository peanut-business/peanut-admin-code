<?php

declare(strict_types=1);

namespace app\modules\official\integration\application;

final readonly class WebhookEndpoint
{
    /** @param list<string> $events */
    public function __construct(
        public string $endpointKey,
        public string $name,
        public string $url,
        public array $events,
        public string $status,
        public int $revision,
        public string $createdAt,
    ) {}
}
