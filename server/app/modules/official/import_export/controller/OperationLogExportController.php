<?php
declare(strict_types=1);

namespace app\modules\official\import_export\controller;

use app\adminapi\controller\BaseAdminController;
use app\modules\official\import_export\services\OperationLogExportApplicationService;
use app\common\execution\CurrentExecutionContext;
use think\App;

final class OperationLogExportController extends BaseAdminController
{
    public function __construct(
        App $app,
        CurrentExecutionContext $executionContext,
        private readonly OperationLogExportApplicationService $exports,
    ) {
        parent::__construct($app, $executionContext);
    }

    public function export()
    {
        $context = $this->tenantAdminContext();
        $operation = $this->exports->submit(
            $context,
            $this->tenantAdminActor(),
            trim((string)$this->request->header('Idempotency-Key', '')),
        );
        return $this->data($operation->toPublicArray());
    }

    public function exportStatus()
    {
        $context = $this->tenantAdminContext();
        $operation = $this->exports->operation(
            $context,
            $this->tenantAdminActor(),
            (string)$this->request->get('operation_key', ''),
        );
        return $this->data($operation->toPublicArray());
    }

    public function exportDownload()
    {
        $context = $this->tenantAdminContext();
        $file = $this->exports->download(
            $context,
            $this->tenantAdminActor(),
            (string)$this->request->get('file_key', ''),
        );
        return redirect($file['url']);
    }
}
