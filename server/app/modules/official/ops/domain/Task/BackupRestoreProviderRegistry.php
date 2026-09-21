<?php

declare(strict_types=1);

namespace app\modules\official\ops\domain\Task;

use InvalidArgumentException;
use app\modules\official\ops\domain\Application\OpsConsoleException;
use app\modules\official\ops\domain\Support\Contract;

final class BackupRestoreProviderRegistry
{
    /** @var array<string, BackupRestoreProviderDescriptor> */
    private array $providers = [];

    /** @param iterable<BackupRestoreProvider> $providers */
    public function __construct(iterable $providers = [])
    {
        foreach ($providers as $provider) {
            $this->register($provider);
        }
    }

    public function register(BackupRestoreProvider $provider): void
    {
        $descriptor = new BackupRestoreProviderDescriptor(
            $provider,
            $provider->key(),
            $provider->backupHandlerKey(),
            $provider->restoreHandlerKey(),
            $provider->restoreTargetKeys(),
            $provider->maximumAttempts(),
        );
        if (isset($this->providers[$descriptor->key])) {
            throw new InvalidArgumentException('Invalid operations provider registration.');
        }
        $this->providers[$descriptor->key] = $descriptor;
    }

    public function require(string $key): BackupRestoreProviderDescriptor
    {
        try {
            Contract::qualifiedKey($key);
        } catch (InvalidArgumentException) {
            throw OpsConsoleException::providerNotFound();
        }
        return $this->providers[$key] ?? throw OpsConsoleException::providerNotFound();
    }
}
