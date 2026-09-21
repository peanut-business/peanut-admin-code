<?php
declare(strict_types=1);

namespace PeanutAdmin\Modules\Task\Contract;

interface TaskBootstrapCommands
{
    /** @param list<array<string,mixed>> $defaults */
    public function seedDefaults(array $defaults): void;
}
