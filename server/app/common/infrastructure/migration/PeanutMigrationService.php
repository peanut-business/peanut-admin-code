<?php

declare(strict_types=1);

namespace app\common\infrastructure\migration;

use app\command\NativeMigrationEntry;

/** Registered before vendor discovery: App::getService recognizes this official Service subtype. */
final class PeanutMigrationService extends \think\migration\Service
{
    public function boot()
    {
        // Do not register generic directory execution, rollback, breakpoint or seed
        // commands: they have no Peanut lifecycle/preflight/recovery admission.
        \think\Console::starting(static function (\think\Console $console): void {
            $console->addCommand(new NativeMigrationEntry('migrate:run'));
            $console->addCommand(new NativeMigrationEntry('migrate:status'));
        });
    }
}
