<?php

declare(strict_types=1);

namespace app\modules\official\notification\delivery\Task;

use PeanutAdmin\Kernel\Context\AuthorizedOperationContext;
use app\modules\official\notification\delivery\Application\NotificationException;
use app\modules\official\notification\delivery\Package;
use app\modules\official\task\contracts\TaskSubmission;
use app\modules\official\task\contracts\TaskSubmissionProvider;

final readonly class OutboxTaskSubmissionProvider implements TaskSubmissionProvider
{
    public function __construct(private string $channel)
    {
        if (!in_array($channel, ['inbox', 'sms'], true)) {
            throw NotificationException::invalid();
        }
    }

    public function taskType(): string
    {
        return 'notification.' . $this->channel . '.dispatch';
    }

    public function resourceKey(): string
    {
        return Package::RESOURCE_KEY;
    }

    public function operation(): string
    {
        return 'manage';
    }

    public function build(AuthorizedOperationContext $context, array $input): TaskSubmission
    {
        if (array_keys($input) !== ['outbox_key'] || !is_string($input['outbox_key'])
            || preg_match('/^outbox_[0-9a-f]{32}$/D', $input['outbox_key']) !== 1
        ) {
            throw NotificationException::invalid();
        }

        return new TaskSubmission(
            'notification.' . $this->channel,
            ['outbox_key' => $input['outbox_key']],
            $this->channel === 'sms' ? 6 : 3,
        );
    }
}
