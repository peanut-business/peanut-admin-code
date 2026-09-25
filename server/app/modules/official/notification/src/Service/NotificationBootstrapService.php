<?php

declare(strict_types=1);

namespace PeanutAdmin\Modules\Notification\Service;

use PeanutAdmin\Modules\Notification\Model\NoticeScene;
use app\common\execution\SystemExecutionContext;
use PeanutAdmin\Modules\Notification\Contract\NotificationBootstrapCommands;

final class NotificationBootstrapService implements NotificationBootstrapCommands
{
    public function provisionTenantDefaults(SystemExecutionContext $context): void
    {
        $system = $context->system;
        if ($system->tenantId < 1
            || $system->actorKey !== 'platform.tenant-bootstrap'
            || $system->operation !== 'notification.provision-tenant-defaults'
            || $system->operationId === '') {
            throw new \DomainException('NOTIFICATION_PROVISION_CONTEXT_INVALID');
        }
        NoticeScene::provisionDefaults(NotificationBootstrapDefaults::scenes());
    }
}
