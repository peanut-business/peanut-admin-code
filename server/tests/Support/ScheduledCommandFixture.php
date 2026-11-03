<?php

declare(strict_types=1);

namespace Tests\Support;

use app\common\execution\ContextualCommand;
use app\common\execution\CurrentExecutionContext;
use app\common\execution\ExecutionContextStore;
use app\common\services\CrontabCommandService;
use PeanutAdmin\Kernel\Tenancy\ScheduledTenantContext;
use think\App;
use think\console\Input;
use think\console\Output;

/** Test-only handler: exercises native scheduling without invoking payment providers. */
class ScheduledCommandFixture extends ContextualCommand
{
    public static function __make(App $app): static
    {
        return new static($app->make(ExecutionContextStore::class), $app->make(CurrentExecutionContext::class));
    }

    public static function register(App $app, string $handler = self::class): void
    {
        $console = $app->config->get('console', []);
        $console['commands']['refund:reconcile'] = $handler;
        // This fixture belongs only to Task; it never claims Payment behavior.
        $console['module_commands']['refund:reconcile'] = 'peanut.task';
        $app->config->set($console, 'console');
        $app->instance(CrontabCommandService::class, new CrontabCommandService(
            $console['commands'], $console['module_commands'],
        ));
    }

    protected function configure()
    {
        $this->setName('refund:reconcile')->setDescription('Scheduled command qualification fixture');
    }

    protected function handle(Input $input, Output $output): int
    {
        ScheduledTenantContext::require();
        return 0;
    }
}
