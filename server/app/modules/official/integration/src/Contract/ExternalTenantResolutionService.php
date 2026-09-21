<?php
declare(strict_types=1);

namespace PeanutAdmin\Modules\Integration\Contract;

interface ExternalTenantResolutionService
{
    public const SYSTEM_ACTOR = 'peanut.external.callback';

    public function verifiedCallback(string $provider, string $callbackKey, string $operation, string $operationId, callable $verifier): ExternalTenantResolution;

    public function clientIdentity(string $provider, string $clientIdentity, string $operation, string $operationId): ExternalTenantResolution;

    public function onlyActiveBinding(string $provider, string $operation, string $operationId): ExternalTenantResolution;

    /** @param list<ExternalTenantBinding> $bindings */
    public function verifiedCandidates(array $bindings, string $provider, string $candidate, string $operation, string $operationId, bool $requireProviderMatch = true): ExternalTenantResolution;

    public function bindingForTenant(int $tenantId, string $provider, bool $requireActive = true): ExternalTenantBinding;
}
