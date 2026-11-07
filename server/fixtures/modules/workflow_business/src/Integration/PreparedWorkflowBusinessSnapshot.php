<?php

declare(strict_types=1);

namespace PeanutAdmin\Fixtures\WorkflowBusiness\Integration;

/** Request-local immutable material observation; never an authorization credential. */
final readonly class PreparedWorkflowBusinessSnapshot
{
    public function __construct(public int $tenantId, public string $resourceKey, public string $subjectKey,
        public string $revisionKey, public string $sha256) {}
}
