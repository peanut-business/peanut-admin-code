<?php

declare(strict_types=1);

namespace app\command;

use app\common\execution\ModuleContextualCommand;
use think\console\Input;
use think\console\input\Argument;
use think\console\Output;

final class PluginInstall extends ModuleContextualCommand
{
    use PluginCommandSupport;

    protected function configure()
    {
        $this->setName('plugin:install')->setDescription('Install one immutable locked Plugin')
            ->addArgument('plugin_key', Argument::REQUIRED, 'Plugin key');
    }

    protected function handle(Input $input, Output $output): int
    {
        $key = trim((string) $input->getArgument('plugin_key'));
        return $this->runPluginOperation($output, static fn($service): array => $service->install($key));
    }
}
