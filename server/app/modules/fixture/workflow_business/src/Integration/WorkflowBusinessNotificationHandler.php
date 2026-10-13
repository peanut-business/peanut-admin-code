<?php

declare(strict_types=1);

namespace PeanutAdmin\Fixtures\WorkflowBusiness\Integration;

use PeanutAdmin\Fixtures\WorkflowBusiness\Package;
use PeanutAdmin\Kernel\Context\AuthorizedOperationContext;
use PeanutAdmin\Modules\Task\Contract\JobExecution;
use PeanutAdmin\Modules\Task\Contract\TaskHandler;

final readonly class WorkflowBusinessNotificationHandler implements TaskHandler
{
    public function __construct(private string $scene, private \think\App $app) {}
    public function key(): string { return Package::resource($this->scene) . '.notification'; }
    public function handle(AuthorizedOperationContext $context, JobExecution $execution): void
    {
        if ($context->resourceKey !== Package::resource($this->scene) || $context->operation !== 'read') { throw \PeanutAdmin\Modules\Notification\Delivery\Application\NotificationException::denied(); }
        $this->app->make(\PeanutAdmin\Modules\Notification\Delivery\Application\NotificationService::class)->deliverProduced($context, $execution);
    }
}
