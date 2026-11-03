<?php

declare(strict_types=1);

namespace PeanutAdmin\Fixtures\WorkflowBusiness\Integration;

use PeanutAdmin\Fixtures\WorkflowBusiness\Package;
use PeanutAdmin\Kernel\Async\VerifiedJobEnvelope;
use PeanutAdmin\Kernel\Context\AuthorizedOperationContext;
use PeanutAdmin\Modules\Task\Contract\TaskWorkerDefinition;

abstract readonly class WorkflowBusinessNotificationWorkerDefinition implements TaskWorkerDefinition
{
    public function __construct(private \think\App $app, private \PeanutAdmin\Modules\Notification\Infrastructure\Authorization\NotificationAsyncAuthorization $authorization) {}
    abstract protected function scene(): string;
    public function ownerModuleKey(): string { return Package::MODULE_KEY; }
    public function resourceKey(): string { return Package::resource($this->scene()); }
    public function operation(): string { return 'read'; }
    public function handlers(): array { return [new WorkflowBusinessNotificationHandler($this->scene(), $this->app)]; }
    public function reauthorize(VerifiedJobEnvelope $envelope): AuthorizedOperationContext
    {
        if ($envelope->resourceKey !== $this->resourceKey() || $envelope->operation !== 'read') { throw \PeanutAdmin\Modules\Notification\Delivery\Application\NotificationException::denied(); }
        return $this->authorization->reauthorize($envelope);
    }
}
