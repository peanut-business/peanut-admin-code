<?php

declare(strict_types=1);

namespace app\modules\official\ops\domain\Logs;

use InvalidArgumentException;
use app\modules\official\ops\domain\Application\OpsConsoleException;
use app\modules\official\ops\domain\Support\Contract;

final class RuntimeLogProviderRegistry
{
    /** @var array<string, RuntimeLogProvider> */
    private array $providers = [];

    /** @param iterable<RuntimeLogProvider> $providers */
    public function __construct(iterable $providers)
    {
        foreach ($providers as $provider) {
            $key = Contract::qualifiedKey($provider->sourceKey(), 64);
            if (isset($this->providers[$key])) {
                throw new InvalidArgumentException('Duplicate log provider.');
            }
            $this->providers[$key] = $provider;
        }
        if ($this->providers === [] || count($this->providers) > 16) {
            throw new InvalidArgumentException('Missing log provider.');
        }
    }

    public function require(string $key): RuntimeLogProvider
    {
        return $this->providers[$key] ?? throw OpsConsoleException::providerNotFound();
    }
}
