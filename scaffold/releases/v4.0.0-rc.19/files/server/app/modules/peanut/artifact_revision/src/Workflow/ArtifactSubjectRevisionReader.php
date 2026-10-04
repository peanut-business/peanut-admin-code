<?php

declare(strict_types=1);

namespace PeanutAdmin\Modules\ArtifactRevision\Workflow;

use PeanutAdmin\Modules\ArtifactRevision\Model\ArtifactRevision;

/** Read-only artifact boundary consumed by Workflow revision pinning. */
interface ArtifactSubjectRevisionReader
{
    public function revision(
        int $tenantId,
        string $artifactType,
        string $artifactKey,
        string $revisionKey,
        bool $forUpdate = false,
    ): ?ArtifactRevision;
}
