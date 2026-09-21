<?php

declare(strict_types=1);

namespace app\modules\official\notification\delivery\Task;

use PeanutAdmin\Kernel\Context\AuthorizedOperationContext;
use think\facade\Db;
use app\modules\official\notification\delivery\Application\NotificationException;
use app\modules\official\notification\delivery\Package;
use app\modules\official\notification\delivery\Persistence\NotificationRepository;
use app\modules\official\task\contracts\JobExecution;
use app\modules\official\task\contracts\RetryableTaskException;
use app\modules\official\task\contracts\TaskHandler;
use Throwable;

final readonly class InboxTaskHandler implements TaskHandler
{
    public function __construct(
        private NotificationRepository $repository,
    ) {}

    public function key(): string
    {
        return 'notification.inbox';
    }

    public function handle(AuthorizedOperationContext $context, JobExecution $execution): void
    {
        $outboxKey = $this->outboxKey($execution);
        if ($context->tenantContext->tenantId !== $execution->tenantId
            || !hash_equals(Package::RESOURCE_KEY, $context->resourceKey)
            || !hash_equals('manage', $context->operation)
        ) {
            throw NotificationException::denied();
        }
        $execution->checkpoint();
        try {
            Db::transaction(function () use ($execution, $outboxKey): void {
                $execution->assertLeaseOwned();
                $this->repository->deliverInbox($execution->tenantId, $outboxKey, $execution->jobKey);
            });
        } catch (NotificationException $exception) {
            throw $exception;
        } catch (Throwable) {
            throw new RetryableTaskException('NOTIFICATION_INBOX_PERSISTENCE_FAILED');
        }
    }

    private function outboxKey(JobExecution $execution): string
    {
        if (array_keys($execution->payload) !== ['outbox_key'] || !is_string($execution->payload['outbox_key'])
            || preg_match('/^outbox_[0-9a-f]{32}$/D', $execution->payload['outbox_key']) !== 1
        ) {
            throw NotificationException::invalid();
        }
        return $execution->payload['outbox_key'];
    }
}
