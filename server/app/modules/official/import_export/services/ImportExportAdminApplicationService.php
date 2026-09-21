<?php
declare(strict_types=1);

namespace app\modules\official\import_export\services;

use app\common\contract\authorization\AdminAuthorizationQuery;
use app\common\dto\authorization\AdminPrincipal;
use app\modules\official\import_export\contracts\dto\AsyncExportOperation;
use app\modules\official\import_export\contracts\ImportExportCommands;
use app\modules\official\import_export\contracts\ImportExportQueries;
use app\modules\official\import_export\engine\Application\ImportExportException;
use app\modules\official\import_export\engine\Application\ImportExportService;
use app\modules\official\import_export\infrastructure\file\AppFileMediaGateway;
use PeanutAdmin\Kernel\Auth\TenantContext;
use PeanutAdmin\Kernel\Context\AuthorizationDecision;
use PeanutAdmin\Kernel\Context\AuthorizedOperationContext;

/** 租户后台导入导出管理用例；HTTP、CLI 或其他调用者都必须提供可信租户与人员。 */
final readonly class ImportExportAdminApplicationService
{
    public function __construct(
        private AdminAuthorizationQuery $authorization,
        private ImportExportCommands $commands,
        private ImportExportQueries $queries,
        private AppFileMediaGateway $files,
    ) {}

    /** @return array{items:list<AsyncExportOperation>,page:int,page_size:int,total:int} */
    public function operations(
        TenantContext $tenant,
        AdminPrincipal $actor,
        string $status,
        int $page,
        int $pageSize,
    ): array {
        return $this->queries->operations(
            $this->authorized($tenant, $actor, 'official.import-export.operations.read', 'read'),
            $status,
            $page,
            $pageSize,
        );
    }

    /** @param array<string,string> $mapping */
    public function submitImport(
        TenantContext $tenant,
        AdminPrincipal $actor,
        string $providerKey,
        string $fileKey,
        array $mapping,
        string $idempotencyKey,
    ): AsyncExportOperation {
        return $this->commands->submitImport(
            $this->authorized($tenant, $actor, 'official.import-export.operations.create', 'create'),
            $providerKey,
            $fileKey,
            $mapping,
            $idempotencyKey,
        );
    }

    public function submitExport(
        TenantContext $tenant,
        AdminPrincipal $actor,
        string $providerKey,
        string $idempotencyKey,
    ): AsyncExportOperation {
        return $this->commands->submitExport(
            $this->authorized($tenant, $actor, 'official.import-export.operations.create', 'create'),
            $providerKey,
            $idempotencyKey,
        );
    }

    public function cancel(
        TenantContext $tenant,
        AdminPrincipal $actor,
        string $operationKey,
        int $revision,
    ): AsyncExportOperation {
        return $this->commands->cancel(
            $this->authorized($tenant, $actor, 'official.import-export.operations.cancel', 'cancel'),
            $operationKey,
            $revision,
        );
    }

    /** @return array{url:string,filename:string} */
    public function download(
        TenantContext $tenant,
        AdminPrincipal $actor,
        string $fileKey,
    ): array {
        $context = $this->authorized($tenant, $actor, 'official.import-export.operations.read', 'read');
        $this->queries->resultFile($context, $fileKey);
        return $this->files->download($context, $fileKey);
    }

    /** 权限执行点：permission/resource/operation 由具体管理用例固定，Controller 不能传入通行证。 */
    private function authorized(
        TenantContext $tenant,
        AdminPrincipal $actor,
        string $permission,
        string $operation,
    ): AuthorizedOperationContext {
        if (!$this->authorization->decide($tenant, $actor, $permission)->allowed) {
            throw ImportExportException::denied();
        }
        return AuthorizedOperationContext::fromDecision(AuthorizationDecision::allow(
            $tenant,
            ImportExportService::RESOURCE_KEY,
            $operation,
            [],
            hash('sha256', implode("\0", [
                (string)$tenant->tenantId,
                (string)$tenant->memberId,
                (string)$tenant->authorizationRevision,
                $tenant->requestId,
                $permission,
                $operation,
            ])),
        ));
    }
}
