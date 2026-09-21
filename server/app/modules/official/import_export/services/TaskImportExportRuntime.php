<?php
declare(strict_types=1);

namespace app\modules\official\import_export\services;

use app\modules\official\import_export\contracts\ImportExportCommands;
use app\modules\official\import_export\contracts\ImportExportQueries;
use app\modules\official\import_export\contracts\ImportExportWorkerRuntime;
use app\modules\official\import_export\contracts\dto\AsyncExportOperation;
use app\modules\official\import_export\infrastructure\file\AppFileMediaGateway;
use app\modules\official\task\contracts\TaskJobRuntime;
use app\modules\official\task\contracts\TaskWorkerDefinition;
use app\modules\official\import_export\engine\Application\ImportExportService;
use PeanutAdmin\Kernel\Context\AuthorizedOperationContext;
use PeanutAdmin\Kernel\Context\authorizationDecision;

final readonly class TaskImportExportRuntime implements ImportExportWorkerRuntime
{
    public function __construct(
        private ImportExportCommands $commands,
        private ImportExportQueries $queries,
        private TaskJobRuntime $tasks,
        private AppFileMediaGateway $files,
        private ImportExportTaskWorkerDefinition $worker,
    ) {
    }

    public function commands(): ImportExportCommands
    {
        return $this->commands;
    }

    public function queries(): ImportExportQueries
    {
        return $this->queries;
    }

    public function operation(AuthorizedOperationContext $context, string $operationKey): AsyncExportOperation
    {
        return $this->queries()->operation($this->asOperation($context, 'read'), $operationKey);
    }

    /** @return array{url:string,filename:string} */
    public function download(AuthorizedOperationContext $context, string $fileKey): array
    {
        $read = $this->asOperation($context, 'read');
        $this->queries()->resultFile($read, $fileKey);
        return $this->files->download($read, $fileKey);
    }

    public function runTenant(int $tenantId, string $workerId): int
    {
        return $this->tasks->runTenant(
            $tenantId,
            $workerId,
            $this->workerDefinition(),
        );
    }

    public function workerDefinition(): TaskWorkerDefinition
    {
        return $this->worker;
    }

    private function asOperation(AuthorizedOperationContext $source, string $operation): AuthorizedOperationContext
    {
        return AuthorizedOperationContext::fromDecision(AuthorizationDecision::allow(
            $source->tenantContext,
            ImportExportService::RESOURCE_KEY,
            $operation,
            [],
            hash('sha256', $source->authorizationBasisDigest . '|async-export|' . $operation),
        ));
    }

}
