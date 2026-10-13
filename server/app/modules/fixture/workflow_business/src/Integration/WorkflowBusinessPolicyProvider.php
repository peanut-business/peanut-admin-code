<?php

declare(strict_types=1);

namespace PeanutAdmin\Fixtures\WorkflowBusiness\Integration;

use PeanutAdmin\DataPermission\Catalog\ResourceOperation;
use PeanutAdmin\DataPermission\Constraint\ColumnReference;
use PeanutAdmin\DataPermission\Constraint\QueryConstraint;
use PeanutAdmin\DataPermission\Context\AuthorizationContext;
use PeanutAdmin\DataPermission\Decision\AuthorizationDecision;
use PeanutAdmin\DataPermission\Policy\EffectivePolicySet;
use PeanutAdmin\DataPermission\Provider\DepartmentHierarchyProvider;
use PeanutAdmin\DataPermission\Provider\ProviderColumnMap;
use PeanutAdmin\DataPermission\Provider\ResourceCreatePolicyProvider;
use PeanutAdmin\DataPermission\Provider\ResourceQueryPolicyProvider;
use PeanutAdmin\DataPermission\Provider\ResourceTargetPolicyProvider;
use PeanutAdmin\DataPermission\Provider\StandardResourcePolicyProvider;
use PeanutAdmin\DataPermission\Provider\TargetSetMembershipProvider;
use PeanutAdmin\DataPermission\Target\TypedResourceTargetCollection;
use PeanutAdmin\Fixtures\WorkflowBusiness\Package;

/** Business column mapping only; all policy semantics use the existing Core provider. */
final readonly class WorkflowBusinessPolicyProvider implements ResourceQueryPolicyProvider, ResourceTargetPolicyProvider, ResourceCreatePolicyProvider
{
    private StandardResourcePolicyProvider $native;

    public function __construct(DepartmentHierarchyProvider $departments, TargetSetMembershipProvider $targetSets)
    {
        $targets = [];
        foreach (Package::SCENES as $scene) {
            $targets[Package::resource($scene)] = new ColumnReference('object_key');
        }
        $this->native = new StandardResourcePolicyProvider(new ProviderColumnMap(
            new ColumnReference('tenant_id'),
            new ColumnReference('owner_member_id'),
            new ColumnReference('department_id'),
            $targets,
        ), $departments, $targetSets);
    }

    public function tenantConstraint(AuthorizationContext $context, ResourceOperation $operation): QueryConstraint
    {
        return $this->native->tenantConstraint($context, $operation);
    }

    public function requestedTargetConstraint(AuthorizationContext $context, ResourceOperation $operation, TypedResourceTargetCollection $targets): QueryConstraint
    {
        return $this->native->requestedTargetConstraint($context, $operation, $targets);
    }

    public function compilePredicate(AuthorizationContext $context, ResourceOperation $operation, EffectivePolicySet $policies): QueryConstraint
    {
        return $this->native->compilePredicate($context, $operation, $policies);
    }

    public function assertTargetsAllowed(AuthorizationContext $context, ResourceOperation $operation, TypedResourceTargetCollection $targets, EffectivePolicySet $policies): AuthorizationDecision
    {
        return $this->native->assertTargetsAllowed($context, $operation, $targets, $policies);
    }

    public function assertCreateAllowed(AuthorizationContext $context, ResourceOperation $operation, TypedResourceTargetCollection $targets, EffectivePolicySet $policies): AuthorizationDecision
    {
        return $this->native->assertCreateAllowed($context, $operation, $targets, $policies);
    }
}
