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
        foreach (['scope', 'owner', 'thread', 'session', 'gate', 'backup-tool', 'backup-output', 'metadata-tool', 'source-plan', 'maintenance', 'recovery', 'verify-receipt', 'snapshot-operation', 'restore-operation', 'notification-predecessor', 'application-recovery'] as $name) {
            $this->addOption($name, null, \think\console\input\Option::VALUE_REQUIRED, 'Native source maintenance ' . $name, '');
        }
    }

    private function sourceDevelopmentPluginPlan(\think\console\Input $input, Output $output, string $operation, string $key): int
    {
        return $this->writePluginResult($output, function () use ($input, $operation, $key): array {
            $this->assertDevelopmentInstanceMaintenanceAccess();
            if (!in_array($input->getOption('scope'), ['source-development', 'source-application'], true)) {
                throw new PluginLifecycleException('PLUGIN_SOURCE_SCOPE_REQUIRED', 'Explicit source-development or source-application scope is required.');
            }
            $dryRun = (bool) $input->getOption('dry-run');
            $actor = ['scope' => $input->getOption('scope')];
            foreach (['owner', 'thread', 'session', 'gate'] as $name) { $actor[$name] = $input->getOption($name); }
            if ($input->getOption('application-recovery') !== '') {
                $mode = (string) $input->getOption('application-recovery');
                if ($actor['scope'] !== 'source-application' || !in_array($mode, ['verify', 'rehearse', 'restore'], true)
                    || $dryRun !== ($mode === 'verify')) { throw new PluginLifecycleException('PLUGIN_SOURCE_ARGUMENTS_INVALID', 'Whole APP recovery requires its explicit native operation.'); }
                foreach (['backup-tool', 'backup-output', 'metadata-tool', 'maintenance', 'verify-receipt', 'snapshot-operation', 'restore-operation', 'notification-predecessor'] as $name) {
                    if ($input->getOption($name) !== '') { throw new PluginLifecycleException('PLUGIN_SOURCE_ARGUMENTS_INVALID', 'Recovery uses its original fixed native provider.'); }
                }
                return \app\common\services\upgrade\SourceApplicationBackup::sourceApplicationRecovery(
                    rtrim($this->getApp()->getRootPath(), DIRECTORY_SEPARATOR), $actor, $mode,
                    (string) $input->getOption('source-plan'), (string) $input->getOption('recovery'));
            }
            if ($actor['scope'] === 'source-application' && ($input->getOption('snapshot-operation') !== '' || $input->getOption('restore-operation') !== '')) {
                throw new PluginLifecycleException('PLUGIN_SOURCE_ARGUMENTS_INVALID', 'Generated source APP recovery uses its whole native provider.');
            }
            $snapshot = (string) $input->getOption('snapshot-operation');
            $restore = (string) $input->getOption('restore-operation');
            if ($input->getOption('notification-predecessor') !== '' && ($snapshot !== '' || $restore !== '' || $input->getOption('verify-receipt') !== '')) {
                throw new PluginLifecycleException('PLUGIN_SOURCE_ARGUMENTS_INVALID', 'Notification predecessor belongs only to its normal source plan, backup and apply.');
            }
            if ($snapshot !== '' && (($operation === 'upgrade' && in_array($key, ['official.task', 'official.notification', 'official.file'], true))
                || ($operation === 'install' && in_array($key, ['peanut.workflow', 'official.collaboration'], true)))) {
                if (!$dryRun || $restore !== '') { throw new PluginLifecycleException('PLUGIN_SOURCE_ARGUMENTS_INVALID', 'Failure forensic snapshot is read-only.'); }
                foreach (['source-plan', 'maintenance', 'recovery', 'verify-receipt'] as $name) {
                    if ($input->getOption($name) !== '') { throw new PluginLifecycleException('PLUGIN_SOURCE_ARGUMENTS_INVALID', 'Failure forensic snapshot grants no lifecycle or restore admission.'); }
                }
                return $this->getApp()->make(\app\common\services\upgrade\PluginSourceDevelopmentHost::class)->snapshotFailure(
                    $key, $actor, $snapshot, (string) $input->getOption('backup-tool'), (string) $input->getOption('backup-output'),
                    (string) $input->getOption('metadata-tool'),
                    fn(\PDO $pdo, callable $held): array => $this->pluginLifecycle()->withFailureForensicLock($key, $pdo, $held),
                );
            }
            if ($snapshot !== '' || $restore !== '') {
                if ($operation !== 'install' || $key !== 'official.identity' || ($snapshot !== '' && $restore !== '')
                    || ($snapshot !== '' && !$dryRun)
                    || ($snapshot !== '' ? $input->getOption('maintenance') !== '' : $input->getOption('maintenance') === '')
                    || $input->getOption('backup-tool') === '' || $input->getOption('backup-output') === '' || $input->getOption('metadata-tool') === '') {
                    throw new PluginLifecycleException('PLUGIN_SOURCE_ARGUMENTS_INVALID', 'Explicit Identity snapshot/restore arguments are required.');
                }
                foreach (['source-plan', 'recovery', 'verify-receipt'] as $name) {
                    if ($input->getOption($name) !== '') { throw new PluginLifecycleException('PLUGIN_SOURCE_ARGUMENTS_INVALID', 'Recovery is separate from lifecycle apply/receipt verification.'); }
                }
                return $this->getApp()->make(\app\common\services\upgrade\PluginSourceDevelopmentHost::class)->recoverIdentity(
                    $actor, $snapshot !== '' ? $snapshot : $restore, $restore !== '',
                    (string) $input->getOption('backup-tool'), (string) $input->getOption('backup-output'),
                    (string) $input->getOption('metadata-tool'), (string) $input->getOption('maintenance'),
                    function (\PDO $pdo, callable $held): array {
                        // Resolve the native lifecycle only after the host's source/lease admission.
                        $lifecycle = $this->pluginLifecycle();
                        if (!$lifecycle instanceof \app\platform\services\plugin\PluginLifecycleService) {
                            throw new PluginLifecycleException('PLUGIN_SOURCE_IDENTITY_RECOVERY_NATIVE_HOST_REQUIRED', 'The native lifecycle lock is required.');
                        }
                        return $lifecycle->withIdentityRecoveryLock($pdo, $held);
                    }, $restore !== '' && $dryRun,
                );
            }
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
                    ? $this->pluginLifecycle()->upgrade($key, true, $consume) : $this->pluginLifecycle()->installPlan($key, $consume),
                (string) $input->getOption('backup-tool'), (string) $input->getOption('backup-output'),
                $execution,
                (string) $input->getOption('metadata-tool'),
                (string) $input->getOption('notification-predecessor'),
            );
        }, true);
    }

    private function hasSourceDevelopmentOptions(\think\console\Input $input): bool
    {
        foreach (['scope', 'owner', 'thread', 'session', 'gate', 'backup-tool', 'backup-output', 'metadata-tool', 'source-plan', 'maintenance', 'recovery', 'verify-receipt', 'snapshot-operation', 'restore-operation', 'notification-predecessor', 'application-recovery'] as $name) {
            if ($input->getOption($name) !== '') { return true; }
        }
        return false;
    }

    /** The callable creates the native lifecycle only after any source admission. */
    private function writePluginResult(Output $output, callable $operation, bool $sourceDiagnostics = false): int
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
        } catch (\Throwable $failure) {
            $record = ['error' => 'PLUGIN_LIFECYCLE_FAILED'];
            if ($sourceDiagnostics) {
                $record['diagnostic'] = [];
                for ($error = $failure, $depth = 0; $error !== null && $depth < 8; $error = $error->getPrevious(), ++$depth) {
                    $record['diagnostic'][] = ['class' => $error::class, 'file' => basename($error->getFile()), 'line' => $error->getLine(),
                        'code' => preg_match('/\A[A-Z][A-Z0-9_]{0,127}\z/D', $error->getMessage()) === 1 ? $error->getMessage() : 'UNCLASSIFIED',
                        'message_sha256' => hash('sha256', $error->getMessage())];
                }
            }
            $output->writeln((string) json_encode($record, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
            return 1;
        }
    }
}
