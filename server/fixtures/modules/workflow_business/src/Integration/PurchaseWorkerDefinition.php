<?php

declare(strict_types=1);

namespace PeanutAdmin\Fixtures\WorkflowBusiness\Integration;

final readonly class PurchaseWorkerDefinition extends WorkflowBusinessWorkerDefinition
{
    protected function scene(): string
    {
        return 'purchase';
    }
}
