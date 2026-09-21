<?php
declare(strict_types=1);

namespace PeanutAdmin\Fixtures\DeliveryRecord\Service;

interface DeliveryRecordAccess
{
    public function requirePermission(string $permission): void;
}
