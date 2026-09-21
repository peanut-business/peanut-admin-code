<?php
declare(strict_types=1);

namespace app\modules\official\import_export\services;

use app\modules\official\task\contracts\TaskWorkerDefinition;
use app\modules\official\import_export\infrastructure\authorization\AdminAsyncAuthorization;
use app\modules\official\import_export\engine\Application\ImportExportService;
use app\modules\official\import_export\engine\Execution\ImportExportTaskHandler;
use PeanutAdmin\Kernel\Async\VerifiedJobEnvelope;
use PeanutAdmin\Kernel\Context\AuthorizedOperationContext;
use app\modules\official\task\contracts\TaskHandler;

/** Import/Export owns its handler and authorization semantics; Task owns execution. */
final readonly class ImportExportTaskWorkerDefinition implements TaskWorkerDefinition
{
    public function __construct(
        private ImportExportTaskHandler $handler,
        private AdminAsyncAuthorization $authorization,
    ) {
    }

    public function ownerModuleKey(): string
    {
        return 'official.import-export';
    }

    public function resourceKey(): string
    {
        return ImportExportService::RESOURCE_KEY;
    }

    public function operation(): string
    {
        return 'create';
    }

    public function handler(): TaskHandler
    {
        return $this->handler;
    }

    public function reauthorize(VerifiedJobEnvelope $envelope): AuthorizedOperationContext
    {
        return $this->authorization->reauthorize($envelope);
    }
}
