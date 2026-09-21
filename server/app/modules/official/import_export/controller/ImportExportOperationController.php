<?php
declare(strict_types=1);

namespace app\modules\official\import_export\controller;

use app\adminapi\controller\BaseAdminController;
use app\common\dto\authorization\AdminPrincipal;
use app\common\execution\CurrentExecutionContext;
use app\common\http\ApiProblem;
use app\common\services\authorization\AdminAuthorizationService;
use app\modules\official\import_export\contracts\dto\AsyncExportOperation;
use app\modules\official\import_export\engine\Application\ImportExportException;
use app\modules\official\import_export\engine\Application\ImportExportService;
use app\modules\official\import_export\infrastructure\file\AppFileMediaGateway;
use app\modules\official\import_export\services\ImportExportApplicationService;
use PeanutAdmin\Kernel\Context\AuthorizationDecision;
use PeanutAdmin\Kernel\Context\AuthorizedOperationContext;
use think\App;
use think\Response;
use think\response\Json;

final class ImportExportOperationController extends BaseAdminController
{
    public function __construct(
        App $app,
        CurrentExecutionContext $executionContext,
        private readonly ImportExportApplicationService $operations,
        private readonly AppFileMediaGateway $files,
        private readonly AdminAuthorizationService $authorization,
    ) {
        parent::__construct($app, $executionContext);
    }

    public function index(): Json
    {
        try {
            $status = trim((string)$this->request->get('status', 'queued'));
            $page = $this->positiveInteger($this->request->get('page', 1));
            $pageSize = $this->positiveInteger($this->request->get('page_size', 20));
            $result = $this->operations->operations(
                $this->context('official.import-export.operations.read', 'read'),
                $status,
                $page,
                $pageSize,
            );
            return json([
                'data' => ['items' => array_map(
                    static fn(AsyncExportOperation $operation): array => $operation->toPublicArray(),
                    $result['items'],
                )],
                'meta' => [
                    'request_id' => $this->executionContext()->requestId(),
                    'page' => $result['page'],
                    'page_size' => $result['page_size'],
                    'total' => $result['total'],
                ],
            ]);
        } catch (ImportExportException $exception) {
            throw $this->problem($exception);
        }
    }

    public function submitImport(): Json
    {
        try {
            $mapping = $this->request->post('mapping', []);
            if (!is_array($mapping) || array_is_list($mapping)) {
                throw ImportExportException::invalid();
            }
            $operation = $this->operations->submitImport(
                $this->context('official.import-export.operations.create', 'create'),
                trim((string)$this->request->post('provider_key', '')),
                trim((string)$this->request->post('file_key', '')),
                $mapping,
                $this->idempotencyKey(),
            );
            return $this->operationResponse($operation, 201);
        } catch (ImportExportException $exception) {
            throw $this->problem($exception);
        }
    }

    public function submitExport(): Json
    {
        try {
            $operation = $this->operations->submitExport(
                $this->context('official.import-export.operations.create', 'create'),
                trim((string)$this->request->post('provider_key', '')),
                $this->idempotencyKey(),
            );
            return $this->operationResponse($operation, 201);
        } catch (ImportExportException $exception) {
            throw $this->problem($exception);
        }
    }

    public function cancel(string $operationKey): Json
    {
        try {
            $operation = $this->operations->cancel(
                $this->context('official.import-export.operations.cancel', 'cancel'),
                $operationKey,
                $this->positiveInteger($this->request->post('revision')),
            );
            return $this->operationResponse($operation);
        } catch (ImportExportException $exception) {
            throw $this->problem($exception);
        }
    }

    public function download(string $fileKey): Response
    {
        try {
            $context = $this->context('official.import-export.operations.read', 'read');
            $this->operations->resultFile($context, $fileKey);
            $file = $this->files->download($context, $fileKey);
            return redirect($file['url'])->header(['Cache-Control' => 'no-store']);
        } catch (ImportExportException $exception) {
            throw $this->problem($exception);
        }
    }

    private function operationResponse(AsyncExportOperation $operation, int $status = 200): Json
    {
        return json([
            'data' => $operation->toPublicArray(),
            'meta' => ['request_id' => $this->executionContext()->requestId()],
        ], $status);
    }

    private function context(string $permission, string $operation): AuthorizedOperationContext
    {
        $tenant = $this->tenantAdminContext();
        $principal = AdminPrincipal::fromArray($this->executionContext()->tenantAdminPrincipal());
        if (!$this->authorization->decide($tenant, $principal, $permission)->allowed) {
            throw new ApiProblem('IMPORT_EXPORT_PERMISSION_DENIED', 403, 'Import/export access was denied.');
        }
        return AuthorizedOperationContext::fromDecision(AuthorizationDecision::allow(
            $tenant,
            ImportExportService::RESOURCE_KEY,
            $operation,
            [],
            hash('sha256', $tenant->requestId . '|' . $permission . '|' . $operation),
        ));
    }

    private function idempotencyKey(): string
    {
        $value = trim((string)$this->request->header('Idempotency-Key', ''));
        if (strlen($value) < 8 || strlen($value) > 160 || preg_match('/^[\x21-\x7e]+$/D', $value) !== 1) {
            throw ImportExportException::invalid();
        }
        return $value;
    }

    private function positiveInteger(mixed $value): int
    {
        if ((!is_int($value) && !(is_string($value) && preg_match('/^[1-9][0-9]*$/D', $value) === 1))
            || (int)$value < 1
        ) {
            throw ImportExportException::invalid();
        }
        return (int)$value;
    }

    private function problem(ImportExportException $exception): ApiProblem
    {
        $status = match ($exception->problemCode) {
            'IMPORT_EXPORT_PERMISSION_DENIED' => 403,
            'IMPORT_EXPORT_NOT_FOUND', 'IMPORT_EXPORT_FILE_UNAVAILABLE' => 404,
            'IMPORT_EXPORT_IDEMPOTENCY_CONFLICT', 'IMPORT_EXPORT_STATE_CONFLICT' => 409,
            'IMPORT_EXPORT_PROVIDER_UNAVAILABLE' => 503,
            default => 422,
        };
        return new ApiProblem($exception->problemCode, $status, 'Import/export request was rejected.');
    }
}
