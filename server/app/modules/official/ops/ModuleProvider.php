<?php

declare(strict_types=1);

namespace app\modules\official\ops;

use PeanutAdmin\Kernel\Module\ModuleProvider as ModuleProviderContract;

/** Registers the platform operations data owner; runtime composition stays in the platform host. */
final class ModuleProvider implements ModuleProviderContract
{
    public function moduleKey(): string
    {
        return 'official.ops';
    }

    /** @return array<class-string, class-string|callable> */
    public function bindings(): array
    {
        return [];
    }
}
