<?php

declare(strict_types=1);

namespace app\command;

use app\platform\infrastructure\module\ThinkPhpModuleGovernanceProvider;
use app\platform\exception\plugin\PluginLifecycleException;
use think\console\Output;
use think\facade\Config;

trait PluginCommandSupport
{
    private function pluginLifecycle(): \app\common\contract\module\PluginLifecycleCommands
    {
        $serverRoot = dirname(__DIR__, 2);
        $config = Config::get('modules', []);
        if (!is_array($config)) {
            throw new PluginLifecycleException('MODULE_REGISTRY_UNAVAILABLE', 'Module deployment config is invalid.');
        }
        return (new ThinkPhpModuleGovernanceProvider(
            $serverRoot,
            $config,
            $this->moduleCatalogs(),
        ))->pluginLifecycle();
    }

    /** @param callable(\app\common\contract\module\PluginLifecycleCommands):array<string,mixed> $operation */
    private function runPluginOperation(Output $output, callable $operation): int
    {
        return $this->writePluginResult($output, fn(): array => $operation($this->pluginLifecycle()));
    }

    private function configureSourceDevelopmentOptions(): void
    {
        foreach (['scope', 'owner', 'thread', 'session', 'gate', 'backup-tool', 'backup-output', 'metadata-tool', 'source-plan', 'maintenance', 'recovery', 'verify-receipt'] as $name) {
            $this->addOption($name, null, \think\console\input\Option::VALUE_REQUIRED, 'Native source-development plan ' . $name, '');
        }
    }

    private function sourceDevelopmentPluginPlan(\think\console\Input $input, Output $output, string $operation, string $key): int
    {
        return $this->writePluginResult($output, function () use ($input, $operation, $key): array {
            $this->assertDevelopmentInstanceMaintenanceAccess();
            if ($input->getOption('scope') !== 'source-development') {
                throw new PluginLifecycleException('PLUGIN_SOURCE_SCOPE_REQUIRED', 'Explicit source-development scope is required.');
            }
            $dryRun = (bool) $input->getOption('dry-run');
            $actor = [];
            foreach (['owner', 'thread', 'session', 'gate'] as $name) { $actor[$name] = $input->getOption($name); }
            $receipt = (string) $input->getOption('verify-receipt');
            if ($receipt !== '') {
                if (!$dryRun) { throw new PluginLifecycleException('PLUGIN_SOURCE_ARGUMENTS_INVALID', 'Receipt verification is read-only.'); }
                foreach (['source-plan', 'maintenance', 'recovery', 'backup-tool', 'backup-output', 'metadata-tool'] as $name) {
                    if ($input->getOption($name) !== '') { throw new PluginLifecycleException('PLUGIN_SOURCE_ARGUMENTS_INVALID', 'Receipt verification cannot capture or mutate.'); }
                }
                return $this->getApp()->make(\app\common\services\upgrade\PluginSourceDevelopmentHost::class)->verifyReceipt($operation, $key, $actor, $receipt);
            }
            $execution = null;
            foreach (['source-plan', 'maintenance', 'recovery'] as $name) {
                if ($dryRun ? $input->getOption($name) !== '' : $input->getOption($name) === '') {
                    throw new PluginLifecycleException('PLUGIN_SOURCE_ARGUMENTS_INVALID', 'Apply needs the original plan, current maintenance and native recovery; dry-run accepts none.');
                }
            }
            if (!$dryRun) {
                $execution = ['plan' => (string) $input->getOption('source-plan'), 'maintenance' => (string) $input->getOption('maintenance'),
                    'recovery' => (string) $input->getOption('recovery'),
                    'mutate' => fn(): array => $operation === 'upgrade' ? $this->pluginLifecycle()->upgrade($key, false) : $this->pluginLifecycle()->install($key)];
            }
            return $this->getApp()->make(\app\common\services\upgrade\PluginSourceDevelopmentHost::class)->plan(
                $operation, $key, $actor,
                fn(callable $consume): array => $operation === 'upgrade'
                    ? $this->pluginLifecycle()->upgrade($key, true, $consume) : $this->pluginLifecycle()->reconcilePlan($key, false, $consume),
                (string) $input->getOption('backup-tool'), (string) $input->getOption('backup-output'),
                $execution,
                (string) $input->getOption('metadata-tool'),
            );
        });
    }

    private function hasSourceDevelopmentOptions(\think\console\Input $input): bool
    {
        foreach (['scope', 'owner', 'thread', 'session', 'gate', 'backup-tool', 'backup-output', 'metadata-tool', 'source-plan', 'maintenance', 'recovery', 'verify-receipt'] as $name) {
            if ($input->getOption($name) !== '') { return true; }
        }
        return false;
    }

    /** The callable creates the native lifecycle only after any source admission. */
    private function writePluginResult(Output $output, callable $operation): int
    {
        try {
            $output->writeln((string) json_encode(
                $operation(),
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES,
            ));
            return 0;
        } catch (PluginLifecycleException $exception) {
            $output->writeln((string) json_encode(
                ['error' => $exception->errorCode],
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES,
            ));
            return 1;
        } catch (\Throwable) {
            $output->writeln('{"error":"PLUGIN_LIFECYCLE_FAILED"}');
            return 1;
        }
    }
}
