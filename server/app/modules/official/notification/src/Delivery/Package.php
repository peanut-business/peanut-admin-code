<?php

declare(strict_types=1);

namespace PeanutAdmin\Modules\Notification\Delivery;

final class Package
{
    public const MODULE_KEY = 'official.notification';
    public const RESOURCE_KEY = 'official.notification.delivery';
    public const READ_PERMISSION = 'official.notification.inbox.read';
    public const MANAGE_PERMISSION = 'official.notification.delivery.manage';

    private function __construct() {}
}
