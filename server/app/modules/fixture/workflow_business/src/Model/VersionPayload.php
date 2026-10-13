<?php

declare(strict_types=1);

namespace PeanutAdmin\Fixtures\WorkflowBusiness\Model;

/** Business payload bytes only; revision lineage and canonical envelope belong to ArtifactRevision. */
final class VersionPayload extends \PeanutAdmin\Kernel\Persistence\Model\TenantModel
{
    protected $name = 'fixture_workflow_payload';
}
