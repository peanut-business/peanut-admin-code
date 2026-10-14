<?php

declare(strict_types=1);

namespace PeanutAdmin\Modules\Collaboration\Console;

use PeanutAdmin\Modules\Collaboration\Service\RuntimeLifecycle;
use think\console\Input;
use think\console\Output;
use think\console\input\Option;

/** The native owner supplies stopped evidence; each tenant receives a separate root execution scope. */
final class NativeMigrationCommand extends \app\common\execution\ContextualCommand
{
    protected function configure()
    {
        $this->setName('collaboration:migrate-native')->setDescription('Explicit drained protocol 1 room cutover to native protocol 2')
            ->addOption('drain-id', null, Option::VALUE_REQUIRED, 'Original confirmed stopped drain identity');
    }

    protected function establishesInstanceContext(): bool
    {
        return false;
    }

    protected function handle(Input $input, Output $output): int
    {
        try {
            $result = app()->make(RuntimeLifecycle::class)->migrateNative((string) $input->getOption('drain-id'));
            $output->writeln(json_encode(['ok' => true,'data' => $result], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
            return 0;
        } catch (\Throwable) {
            // No SQL/bindings, credentials, session references or filesystem configuration in CLI output.
            $output->writeln(json_encode(['ok' => false,'error_code' => 'COLLABORATION_NATIVE_MIGRATION_UNCONFIRMED'], JSON_THROW_ON_ERROR));
            return 4;
        }
    }
}
