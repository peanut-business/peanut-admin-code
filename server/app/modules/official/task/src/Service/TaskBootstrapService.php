<?php
declare(strict_types=1);

namespace PeanutAdmin\Modules\Task\Service;

use PeanutAdmin\Modules\Task\Contract\TaskBootstrapCommands;
use PeanutAdmin\Modules\Task\Model\Crontab;

final class TaskBootstrapService implements TaskBootstrapCommands
{
    public function seedDefaults(array $defaults): void
    {
        $existing = array_fill_keys(array_map(
            'strval',
            Crontab::whereIn('command', array_column($defaults, 'command'))->column('command'),
        ), true);
        $missing = array_values(array_filter(
            $defaults,
            static fn(array $row): bool => !isset($existing[$row['command']]),
        ));
        if ($missing !== []) {
            (new Crontab())->saveAll($missing);
        }
    }
}
