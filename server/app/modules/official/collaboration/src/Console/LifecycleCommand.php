<?php

declare(strict_types=1);

namespace PeanutAdmin\Modules\Collaboration\Console;

use think\console\Input;
use think\console\Output;
use think\console\input\Argument;
use think\console\input\Option;
use think\facade\Log;
use PeanutAdmin\Modules\Collaboration\Service\RuntimeLifecycle;

final class LifecycleCommand extends \app\common\execution\ContextualCommand
{
    protected function configure()
    {
        $this->setName('collaboration:lifecycle')->setDescription('Native owner collaboration lifecycle')
            ->addArgument('action', Argument::REQUIRED, 'status/prepare/activate/begin/drain/stopped')
            ->addOption('drain-id', null, Option::VALUE_REQUIRED, 'Original stable drain identity', 'runtime-start')
            ->addOption('owner-container', null, Option::VALUE_REQUIRED, 'Base64 canonical immutable owner container identity for begin')
            ->addOption('stop-proof', null, Option::VALUE_REQUIRED, 'Base64 canonical signed original container stop proof');
    }
    protected function establishesInstanceContext(): bool
    {
        return false;
    }
    protected function handle(Input $input, Output $output): int
    {
        if (PHP_SAPI !== 'cli' || $this->executionContext()->current() !== null) {
            throw new \DomainException('COLLABORATION_TOP_LEVEL_OWNER_REQUIRED');
        }
        try {
            $owner = $input->getOption('owner-container');
            $proof = $input->getOption('stop-proof');
            $result = app()->make(RuntimeLifecycle::class)->native((string) $input->getArgument('action'), (string) $input->getOption('drain-id'),
                is_string($proof) ? RuntimeLifecycle::decodeOwnerInput($proof) : null,
                is_string($owner) ? RuntimeLifecycle::decodeOwnerInput($owner) : null);
            $output->writeln(json_encode(['protocol' => 'peanut.collaboration.lifecycle.v1','schema_version' => 1,'ok' => true,'data' => $result], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
            return 0;
        } catch (\Throwable $error) {
            try {
                Log::write('COLLABORATION_LIFECYCLE_FAILED ' . json_encode(
                    self::failureContext((string) $input->getArgument('action'), $error),
                    JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES,
                ), 'error');
            } catch (\Throwable) {
                // A local logging failure must preserve the public failure contract.
            }
            $output->writeln(json_encode(['protocol' => 'peanut.collaboration.lifecycle.v1','schema_version' => 1,'ok' => false,'error_code' => 'COLLABORATION_LIFECYCLE_UNCONFIRMED'], JSON_THROW_ON_ERROR));
            return 4;
        }
    }

    private static function failureContext(string $action, \Throwable $error): array
    {
        $state = null;
        $driver = null;
        if ($error instanceof \PDOException) {
            $state = $error->errorInfo[0] ?? null;
            $driver = $error->errorInfo[1] ?? null;
        } elseif ($error instanceof \think\db\exception\PDOException) {
            $info = $error->getData()['PDO Error Info'] ?? [];
            $state = $info['SQLSTATE'] ?? null;
            $driver = $info['Driver Error Code'] ?? null;
        }
        $code = filter_var($error->getCode(), FILTER_VALIDATE_INT);
        $driver = filter_var($driver, FILTER_VALIDATE_INT);
        $class = get_class($error);
        return [
            'action' => in_array($action, ['status', 'prepare', 'activate', 'begin', 'drain', 'stopped'], true) ? $action : 'unknown',
            'exception_class' => str_contains($class, "\0") ? 'anonymous-class' : $class,
            'exception_code' => $code === false ? null : $code,
            'business_reason' => $error instanceof \DomainException ? match ($error->getMessage()) {
                'COLLABORATION_LIFECYCLE_OWNER_REQUIRED' => 'COLLABORATION_LIFECYCLE_OWNER_REQUIRED',
                'COLLABORATION_CONFIGURATION_INCOMPLETE' => 'COLLABORATION_CONFIGURATION_INCOMPLETE',
                'COLLABORATION_CONFIGURATION_STALE' => 'COLLABORATION_CONFIGURATION_STALE',
                default => null,
            } : null,
            'sqlstate' => is_string($state) && preg_match('/^[A-Z0-9]{5}$/D', $state) === 1 ? $state : null,
            'driver_code' => $driver === false ? null : $driver,
        ];
    }
}
