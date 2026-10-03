<?php

declare(strict_types=1);

namespace app\common\contract\module;

interface PluginLifecycleCommands
{
    /** @return array<string,mixed> */
    public function install(string $pluginKey): array;

    /** @return array<string,mixed> */
    public function reconcile(string $pluginKey): array;

    /** @return array<string,mixed> */
    public function upgrade(string $pluginKey, bool $dryRun): array;

    /** @return array<string,mixed> */
    public function rollbackPlan(string $pluginKey): array;

    /** @return array<string,mixed> */
    public function uninstall(string $pluginKey): array;
}
