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
            ->addArgument('operation', Argument::REQUIRED, 'preflight, apply or verify')
            ->addOption('workspace', null, Option::VALUE_REQUIRED, 'Existing native server-update workspace')
            ->addOption('plan', null, Option::VALUE_REQUIRED, 'Protected preflight plan path')
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
            $code = preg_match('/\AAPPLICATION_SCHEMA_[A-Z0-9_]+\z/D', $message) === 1
                ? $message : 'APPLICATION_SCHEMA_FAILED';
            $output->writeln(json_encode([
                'scope' => 'application-schema', 'status' => 'blocked', 'code' => $code,
                'diagnostic_sha256' => hash('sha256', $message),
                'plugins' => 'not_run', 'product_database' => 'not_verified',
            ], JSON_THROW_ON_ERROR));
            return 1;
        }
    }
}
