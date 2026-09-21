<?php
declare(strict_types=1);

namespace PeanutAdmin\Fixtures\DeliveryRecord\Controller;

use PeanutAdmin\Fixtures\DeliveryRecord\Contract\DeliveryRecordCommands;
use app\common\execution\CurrentExecutionContext;
use PeanutAdmin\Kernel\Module\ModuleException;

/** Tenant-member HTTP boundary; root and system actors never bypass Module commands. */
final readonly class DeliveryRecordHttpHandler
{
    public function __construct(
        private DeliveryRecordCommands $commands,
        private CurrentExecutionContext $executionContext,
    ) {}

    /** @return list<array<string,mixed>> */
    public function lists(): array
    {
        $this->assertMemberContext();
        return $this->commands->list();
    }

    /** @return array<string,mixed> */
    public function record(string $reference): array
    {
        $this->assertMemberContext();
        return $this->commands->record($reference);
    }

    private function assertMemberContext(): void
    {
        try {
            $this->executionContext->tenantAdmin();
        } catch (\DomainException) {
            throw new ModuleException(
                'CONTEXT_TENANT_REQUIRED',
                'A validated Tenant member context is required.',
            );
        }
    }
}
