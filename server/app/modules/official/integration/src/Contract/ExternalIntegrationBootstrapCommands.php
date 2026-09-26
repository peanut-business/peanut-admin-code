<?php

declare(strict_types=1);

namespace PeanutAdmin\Modules\Integration\Contract;

/** Trusted installation boundary for creating disabled integration bindings. */
final readonly class ExternalIntegrationBootstrapCommands
{
    public function __construct(private ExternalChannelBindingStore $bindings) {}

    public function ensureUnconfiguredBinding(int $tenantId, string $tenantCode, string $provider): void
    {
        $this->bindings->ensureUnconfiguredBinding($tenantId, $tenantCode, $provider);
    }
}
