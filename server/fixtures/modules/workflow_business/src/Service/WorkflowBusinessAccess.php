<?php

declare(strict_types=1);

namespace PeanutAdmin\Fixtures\WorkflowBusiness\Service;

use PeanutAdmin\DataPermission\Constraint\ThinkPhpQueryConstraintApplier;
use PeanutAdmin\DataPermission\Engine\DataPermissionEngine;
use PeanutAdmin\DataPermission\Exception\DataAuthorizationException;
use PeanutAdmin\Fixtures\WorkflowBusiness\Model\BusinessObject;
use PeanutAdmin\Fixtures\WorkflowBusiness\Package;
use PeanutAdmin\Kernel\Authorization\Application\AdminAccessException;
use PeanutAdmin\Kernel\Authorization\TenantAuthorizationEvaluator;
use PeanutAdmin\Kernel\Context\AuthorizationDecision;
use PeanutAdmin\Kernel\Context\AuthorizedOperationContext;
use PeanutAdmin\Kernel\Context\RequestedTargetSet;
use PeanutAdmin\Kernel\Module\ModuleAvailability;
use PeanutAdmin\Modules\Identity\Contract\TenantMemberDirectory;
use PeanutAdmin\Modules\Workflow\Integration\WorkflowScenarioAccess;

/** Native IAM and DataPermission scopes are applied in SQL, before count and pagination. */
final readonly class WorkflowBusinessAccess implements WorkflowScenarioAccess
{
    public function __construct(
        private TenantMemberDirectory $members,
        private TenantAuthorizationEvaluator $permissions,
        private DataPermissionEngine $dataPermissions,
        private ThinkPhpQueryConstraintApplier $constraints,
        private ModuleAvailability $modules,
    ) {}

    public function requirePermission(AuthorizedOperationContext $basis, string $permission): void
    {
        $this->modules->assertAvailable(\PeanutAdmin\Kernel\Tenancy\TenantScope::fromTrustedContext($basis->tenantContext->tenantId, 'workflow-business'), Package::MODULE_KEY, new \DateTimeImmutable('now', new \DateTimeZone('UTC')));
        if ($this->members->current($basis->tenantContext) === null
            || !$this->permissions->allows($basis->tenantContext, $permission)) {
            throw new AdminAccessException('WORKFLOW_BUSINESS_ACCESS_DENIED', 403, 'Current business permission is required.');
        }
    }

    public function currentMember(AuthorizedOperationContext $basis): \PeanutAdmin\Modules\Identity\Contract\Dto\TenantMemberSummary
    {
        return $this->members->current($basis->tenantContext)
            ?? throw new AdminAccessException('WORKFLOW_BUSINESS_ACTOR_INVALID', 403, 'The current tenant member is invalid.');
    }

    public function requireCreate(AuthorizedOperationContext $basis, string $resourceKey): void
    {
        Package::scene($resourceKey);
        $this->requirePermission($basis, Package::MODULE_KEY . '.create');
        if (!$this->dataPermissions->decideCreate($basis->tenantContext, $resourceKey, 'create', new \PeanutAdmin\DataPermission\Target\TypedResourceTargetCollection())->allowed) {
            throw new AdminAccessException('WORKFLOW_BUSINESS_CREATE_DENIED', 403, 'Creating the business object is not authorized.');
        }
    }

    public function query(AuthorizedOperationContext $basis, string $resourceKey, string $operation): \think\db\BaseQuery
    {
        $scene = Package::scene($resourceKey);
        $this->requirePermission($basis, Package::MODULE_KEY . '.read');
        if (!in_array($operation, Package::OPERATIONS, true)) {
            throw new AdminAccessException('WORKFLOW_BUSINESS_OPERATION_INVALID', 403, 'The operation is not registered.');
        }
        $query = BusinessObject::where('tenant_id', $basis->tenantContext->tenantId)->where('scene', $scene);
        $this->constraints->apply($query, $this->dataPermissions->queryConstraint($basis->tenantContext, $resourceKey, $operation));
        return $query;
    }

    public function authorize(AuthorizedOperationContext $basis, string $resourceKey, string $operation, array $permissionKeys, string $subjectKey): AuthorizedOperationContext
    {
        if ($permissionKeys === [] || preg_match('/^object_[0-9a-f]{32}$/D', $subjectKey) !== 1) {
            throw AdminAccessException::notFound();
        }
        foreach ($permissionKeys as $permission) {
            if (!is_string($permission) || $permission === '') {
                throw new AdminAccessException('WORKFLOW_BUSINESS_ACCESS_DENIED', 403, 'A registered permission is required.');
            }
            $this->requirePermission($basis, $permission);
        }
        if ($this->query($basis, $resourceKey, $operation)->where('object_key', $subjectKey)->value('id') === null) {
            throw AdminAccessException::notFound();
        }
        return AuthorizedOperationContext::fromDecision(AuthorizationDecision::allow(
            $basis->tenantContext,
            $resourceKey,
            $operation,
            [new RequestedTargetSet($resourceKey, [$subjectKey])],
            hash('sha256', json_encode([$basis->authorizationBasisDigest, $resourceKey, $operation, $subjectKey, $permissionKeys, $basis->tenantContext->authorizationRevision], JSON_THROW_ON_ERROR)),
        ));
    }

    public function applyVisibleSubjectScope(\PeanutAdmin\Kernel\Auth\TenantContext $ctx, string $resourceKey, \think\db\BaseQuery $query, string $subjectColumn): void
    {
        if ($subjectColumn !== 'i.subject_key') { throw new \LogicException('WORKFLOW_SUBJECT_COLUMN_INVALID'); }
        $scene = Package::scene($resourceKey);
        try {
            $this->modules->assertAvailable(\PeanutAdmin\Kernel\Tenancy\TenantScope::fromTrustedContext($ctx->tenantId, 'workflow-business'), Package::MODULE_KEY, new \DateTimeImmutable('now', new \DateTimeZone('UTC')));
            if ($this->members->current($ctx) === null || !$this->permissions->allows($ctx, Package::MODULE_KEY . '.read')) {
                $query->whereRaw('1=0'); return;
            }
            $constraint = $this->dataPermissions->queryConstraint($ctx, $resourceKey, 'read');
        } catch (DataAuthorizationException|AdminAccessException|\PeanutAdmin\Kernel\Module\ModuleException) {
            $query->whereRaw('1=0'); return;
        }
        $query->whereIn($subjectColumn, function (\think\db\BaseQuery $objects) use ($ctx, $scene, $constraint): void {
            $objects->table((new BusinessObject())->getTable())->where('tenant_id', $ctx->tenantId)->where('scene', $scene)->field('object_key');
            $this->constraints->apply($objects, $constraint);
        });
    }

    public function availableActions(AuthorizedOperationContext $basis, string $resourceKey, string $subjectKey): array
    {
        $actions = [];
        foreach (['update', 'confirm', 'start', 'complete'] as $operation) {
            try {
                $permissions = [Package::MODULE_KEY . '.' . $operation];
                if ($operation === 'start') {
                    $permissions[] = 'peanut.workflow.instance.start';
                }
                $this->authorize($basis, $resourceKey, $operation, $permissions, $subjectKey);
                $actions[] = $operation;
            } catch (DataAuthorizationException|AdminAccessException|\PeanutAdmin\Kernel\Module\ModuleException) {
                // Only explicit current denial hides an action. Infrastructure failures propagate.
            }
        }
        return $actions;
    }
}
