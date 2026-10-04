<?php

declare(strict_types=1);

namespace PeanutAdmin\Fixtures\DeliveryRecord;

use PeanutAdmin\Fixtures\DeliveryRecord\Service\DeliveryRecordService;
use PeanutAdmin\Fixtures\DeliveryRecord\Service\DeliveryRecordAccess;
use PeanutAdmin\Fixtures\DeliveryRecord\Contract\DeliveryRecordCommands;
use PeanutAdmin\Fixtures\DeliveryRecord\Infrastructure\Authorization\ThinkPhpDeliveryRecordAccess;
use PeanutAdmin\Kernel\Module\ModuleProvider as ModuleProviderContract;

final class ModuleProvider implements ModuleProviderContract
{
    public function moduleKey(): string
    {
        return 'fixture.delivery-record';
    }

    public function bindings(): array
    {
        return [
            DeliveryRecordAccess::class => ThinkPhpDeliveryRecordAccess::class,
            DeliveryRecordCommands::class => DeliveryRecordService::class,
        ];
    }
}
