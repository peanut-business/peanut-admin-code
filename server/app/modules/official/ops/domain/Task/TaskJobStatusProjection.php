<?php

declare(strict_types=1);

namespace app\modules\official\ops\domain\Task;

use app\modules\official\ops\domain\Application\OpsConsoleException;
use app\modules\official\task\contracts\TaskJobRecordView;
use Throwable;

final class TaskJobStatusProjection
{
    public static function fromRecord(TaskJobRecordView $record): OpsTask
    {
        $task = $record->toPublicArray();
        if (!OpsTask::supportsTaskType($task['task_type'])) {
            throw OpsConsoleException::taskNotFound();
        }
        try {
            return new OpsTask(
                $task['job_key'],
                $task['task_type'],
                $task['status'],
                $task['attempt_count'],
                $task['max_attempts'],
                $task['revision'],
                $task['last_error_code'],
                $task['available_at'],
                $task['created_at'],
                $task['updated_at'],
                $task['completed_at'],
            );
        } catch (Throwable) {
            throw OpsConsoleException::taskUnavailable();
        }
    }

    private function __construct() {}
}
