<?php

declare(strict_types=1);

namespace app\modules\official\import_export\engine\Execution;

use app\modules\official\import_export\engine\Application\ImportExportException;
use app\modules\official\import_export\engine\Application\ImportExportService;
use PeanutAdmin\Kernel\Context\AuthorizedOperationContext;
use app\modules\official\task\contracts\JobExecution;
use app\modules\official\task\contracts\TaskHandler;

final readonly class ImportExportTaskHandler implements TaskHandler
{
    public function __construct(private CsvOperationRunner $runner) {}
    public function key(): string
    {
        return 'peanut.import-export.execute';
    }

    public function handle(AuthorizedOperationContext $context, JobExecution $execution): void
    {
        if (!hash_equals(ImportExportService::RESOURCE_KEY, $context->resourceKey)
            || !hash_equals('create', $context->operation)
            || array_keys($execution->payload) !== ['operation_key']
            || !is_string($execution->payload['operation_key'])) {
            throw ImportExportException::denied();
        }
        $execution->checkpoint();
        $this->runner->run($context, $execution->payload['operation_key'], $execution);
    }
}
