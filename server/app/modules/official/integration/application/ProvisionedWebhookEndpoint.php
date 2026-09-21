<?php

declare(strict_types=1);

namespace app\modules\official\integration\application;

final readonly class ProvisionedWebhookEndpoint
{
    public function __construct(public WebhookEndpoint $endpoint, public string $signingSecret) {}
}
