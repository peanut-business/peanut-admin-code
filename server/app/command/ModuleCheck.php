<?php

declare(strict_types=1);

namespace app\command;

use app\platform\services\plugin\ModuleAuthorCheckHost;
use app\common\execution\ContextualCommand;
use think\console\Input;
use think\console\input\Argument;
use think\console\input\Option;
use think\console\Output;
use think\facade\Config;

final class ModuleCheck extends ContextualCommand
{
    protected function configure()
    {
        $this->setName('module:check')->setDescription('Run the read-only Module author preflight')
            ->addArgument('module_key', Argument::REQUIRED, 'Namespaced Module key')
            ->addOption('kernel-version', null, Option::VALUE_REQUIRED, 'Selected Module Kernel version', '')
            ->addOption('package', null, Option::VALUE_REQUIRED, 'Optional Module package tar path', '')
            ->addOption('sha256', null, Option::VALUE_REQUIRED, 'Optional expected package SHA-256', '');
    }

    protected function handle(Input $input, Output $output): int
    {
        try {
            $kernelVersion = trim((string) $input->getOption('kernel-version'));
            if ($kernelVersion === '') {
                $configured = trim((string) Config::get('modules.kernel_version', '1.0.0'));
                $kernelVersion = $configured === '' ? '1.0.0' : $configured;
            }
            $result = (new ModuleAuthorCheckHost(dirname(__DIR__, 3), $kernelVersion))->inspect(
                (string) $input->getArgument('module_key'),
                (string) $input->getOption('package'),
                (string) $input->getOption('sha256'),
            );
            $output->writeln((string) json_encode(
                $result,
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
            ));
            return $result['status'] === 'ready' ? 0 : 1;
        } catch (\Throwable) {
            $output->writeln((string) json_encode([
                'status' => 'blocked',
                'code' => 'MODULE_CHECK_FAILED',
                'reason' => 'Module 作者检查无法完成',
                'remediation' => '确认项目源码与 Composer 依赖完整后重新运行 module:check',
                'checks' => [],
            ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
            return 1;
        }
    }
}
