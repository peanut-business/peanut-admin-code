<?php

declare(strict_types=1);

namespace PeanutAdmin\Fixtures\WorkflowBusiness\Integration;

final readonly class PurchaseNotificationWorkerDefinition extends WorkflowBusinessNotificationWorkerDefinition
{
    protected function scene(): string
    {
        return 'purchase';
    }
}
