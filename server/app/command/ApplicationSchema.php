<?php

declare(strict_types=1);

namespace app\command;

use app\common\execution\ContextualCommand;
use app\common\execution\InstanceExecutionContext;
use app\common\services\upgrade\ApplicationSchemaHost;
use think\console\Input;
use think\console\input\Argument;
use think\console\input\Option;
use think\console\Output;

/** Application SQL only; never enters Plugin or product deployment lifecycle. */
final class ApplicationSchema extends ContextualCommand
{
    protected function configure()
    {
        $this->setName('schema:application')->setDescription('Inspect or execute the bound application SQL stage')
            ->addArgument('operation', Argument::REQUIRED, 'preflight, backup, apply, verify or source-only verify-receipt')
            ->addOption('scope', null, Option::VALUE_REQUIRED, 'Explicit source-development maintenance scope', '')
            ->addOption('owner', null, Option::VALUE_REQUIRED, 'Owner of the current native resource grant')
            ->addOption('thread', null, Option::VALUE_REQUIRED, 'Thread of the current native resource grant')
            ->addOption('session', null, Option::VALUE_REQUIRED, 'Workflow Session of the current native resource grant')
            ->addOption('gate', null, Option::VALUE_REQUIRED, 'Gate of the current native resource grant')
            ->addOption('maintenance', null, Option::VALUE_REQUIRED, 'Protected current stop-writer evidence')
            ->addOption('recovery', null, Option::VALUE_REQUIRED, 'Protected scoped backup references for external recovery acceptance')
            ->addOption('workspace', null, Option::VALUE_REQUIRED, 'Existing native server-update workspace')
            ->addOption('plan', null, Option::VALUE_REQUIRED, 'Protected preflight plan path')
            ->addOption('backup-tool', null, Option::VALUE_REQUIRED, 'Registered selected-table native backup tooling resource')
            ->addOption('backup-output', null, Option::VALUE_REQUIRED, 'New exact native-leased private backup directory')
            ->addOption('result', null, Option::VALUE_REQUIRED, 'Native application-schema result path');
    }

    protected function handle(Input $input, Output $output): int
    {
        try {
            $context = $this->executionContext()->current();
            if (PHP_SAPI !== 'cli' || !$this->establishedInstanceContext()
                || !$context instanceof InstanceExecutionContext
                || $context->operation() !== 'console.schema:application') {
                throw new \RuntimeException('APPLICATION_SCHEMA_INSTANCE_CONTEXT_REQUIRED');
            }
            $operation = (string) $input->getArgument('operation');
            $plan = (string) $input->getOption('plan');
            $result = (string) $input->getOption('result');
            $workspace = (string) $input->getOption('workspace');
            $host = new ApplicationSchemaHost(dirname(__DIR__, 2));
            $scope = (string) $input->getOption('scope');
            if ($scope !== '') {
                $guard = \app\common\validation\instance\InstanceToolAccessGuard::fromConfiguredValue($this->getApp()->config->get('deployment.mode'));
                if ($scope !== 'source-development' || $workspace !== ''
                    || !$guard->allowsCliDevelopmentMaintenance($this->getApp()->config->get('peanut.environment'),
                        $this->getApp()->isDebug(), $this->executionContext(), $this->getName(), $this->establishedInstanceContext())) {
                    throw new \RuntimeException('APPLICATION_SCHEMA_SOURCE_CONTEXT_REQUIRED');
                }
                $authority = [];
                foreach (['owner', 'thread', 'session', 'gate'] as $key) { $authority[$key] = (string) $input->getOption($key); }
                $report = $host->sourceDevelopment($operation, $authority, $plan, $result,
                    (string) $input->getOption('maintenance'), (string) $input->getOption('recovery'),
                    (string) $input->getOption('backup-tool'), (string) $input->getOption('backup-output'));
                $output->writeln(json_encode($report, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
                return ($report['status'] ?? null) === 'blocked' ? 2 : 0;
            }
            foreach (['owner', 'thread', 'session', 'gate', 'maintenance', 'recovery', 'backup-tool', 'backup-output'] as $key) {
                if ((string) $input->getOption($key) !== '') { throw new \RuntimeException('APPLICATION_SCHEMA_ARGUMENTS_INVALID'); }
            }
            $report = match (true) {
                $workspace !== '' && $plan === '' && $result === '' => $host->serverStage($operation, $workspace),
                $workspace === '' && $operation === 'preflight' && $plan === '' && $result === '' => $host->preflight(),
                $workspace === '' && $operation === 'apply' && $plan !== '' && $result === '' => $host->apply($plan),
                $workspace === '' && $operation === 'verify' && $result !== '' && $plan === '' => $host->verify($result),
                default => throw new \RuntimeException('APPLICATION_SCHEMA_ARGUMENTS_INVALID'),
            };
            $output->writeln(json_encode($report, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
            return ($report['status'] ?? null) === 'blocked' ? 2 : 0;
        } catch (\Throwable $exception) {
            $message = $exception->getMessage();
            $prefix = explode(':', $message, 2)[0];
            $code = preg_match('/\AAPPLICATION_SCHEMA_[A-Z0-9_]+\z/D', $prefix) === 1
                ? $prefix : 'APPLICATION_SCHEMA_FAILED';
            $output->writeln(json_encode([
                'scope' => 'application-schema', 'status' => 'blocked', 'code' => $code,
                'diagnostic_sha256' => hash('sha256', $message),
                'diagnostic_origin' => self::diagnosticOrigin($exception),
                'plugins' => 'not_run', 'product_database' => 'not_verified',
            ], JSON_THROW_ON_ERROR));
            return 1;
        }
    }

    /** Locate a CLI failure without exposing SQL, bindings, messages or absolute paths. */
    private static function diagnosticOrigin(\Throwable $exception): array
    {
        $root = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR;
        $chain = [];
        do {
            $file = $exception->getFile();
            $type = get_class($exception);
            // Anonymous class names contain a NUL and their absolute source path.
            if (preg_match('/\A[A-Za-z_][A-Za-z0-9_]*(?:\\\\[A-Za-z_][A-Za-z0-9_]*)*\z/D', $type) !== 1) { $type = 'Throwable'; }
            $source = str_starts_with($file, $root) ? substr($file, strlen($root)) : 'external';
            if (preg_match('/\A[A-Za-z0-9_.\/-]+\z/D', $source) !== 1 || in_array('..', explode('/', $source), true)) { $source = 'external'; }
            $item = ['type' => $type, 'source' => $source,
                'line' => $exception->getLine()];
            if ($exception instanceof \PDOException) {
                $state = $exception->errorInfo[0] ?? $exception->getCode();
                $driver = $exception->errorInfo[1] ?? null;
                if (is_string($state) && preg_match('/\A[0-9A-Z]{5}\z/D', $state) === 1) { $item['sqlstate'] = $state; }
                if (is_int($driver) && $driver >= 0) { $item['driver_code'] = $driver; }
            }
            $chain[] = $item;
            $exception = $exception->getPrevious();
        } while ($exception !== null && count($chain) < 8);
        return $chain;
    }
}
