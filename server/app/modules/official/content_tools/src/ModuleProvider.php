<?php

declare(strict_types=1);

namespace PeanutAdmin\Modules\ContentTools;

use PeanutAdmin\Kernel\Module\ModuleProvider as ModuleProviderContract;
use PeanutAdmin\Modules\ContentTools\Service\ContentToolRegistry;
use PeanutAdmin\Modules\ContentTools\Service\ContentCapabilitiesService;
use think\App;

final class ModuleProvider implements ModuleProviderContract
{
    public function moduleKey(): string
    {
        return 'official.content-tools';
    }

    public function bindings(): array
    {
        return [
            ContentToolRegistry::class => static function (App $app): ContentToolRegistry {
                $registry = ContentToolRegistry::withBuiltins();
                $app->instance(ContentToolRegistry::class, $registry);
                return $registry;
            },
            ContentCapabilitiesService::class => ContentCapabilitiesService::class,
        ];
    }
}
