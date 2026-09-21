<?php

declare(strict_types=1);

namespace app\modules\official\import_export\engine\Execution;

use app\modules\official\import_export\engine\Application\ImportExportException;
use app\modules\official\import_export\engine\Application\ImportExportService;
use PeanutAdmin\Kernel\Context\AuthorizedOperationContext;
use app\modules\official\task\contracts\TaskSubmission;
use app\modules\official\task\contracts\TaskSubmissionProvider;

final class ImportExportTaskSubmissionProvider implements TaskSubmissionProvider
{
    public function taskType(): string
    {
        return ImportExportService::TASK_TYPE;
    }
    public function resourceKey(): string
    {
        return ImportExportService::RESOURCE_KEY;
    }
    public function operation(): string
    {
        return 'create';
    }

    public function build(AuthorizedOperationContext $context, array $input): TaskSubmission
    {
        if (array_keys($input) !== ['operation_key'] || !is_string($input['operation_key'])) {
            throw ImportExportException::invalid();
        }
        ImportExportService::assertOperationKey($input['operation_key']);
        return new TaskSubmission('peanut.import-export.execute', ['operation_key' => $input['operation_key']], 3);
    }
}
