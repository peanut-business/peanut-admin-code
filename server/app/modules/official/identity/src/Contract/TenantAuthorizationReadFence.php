<?php

declare(strict_types=1);

namespace PeanutAdmin\Modules\Identity\Contract;

use app\common\execution\AdminExecutionContext;
use app\common\execution\CurrentExecutionContext;
use PeanutAdmin\Kernel\Auth\TenantContext;
use PeanutAdmin\Modules\Identity\Authorization\ThinkPhpTenantAuthorizationRepository;
use think\facade\Db;

/** Detects changed native authorization inputs; a digest never grants permission. */
final readonly class TenantAuthorizationReadFence
{
    public function __construct(
        private ThinkPhpTenantAuthorizationRepository $repository,
        private CurrentExecutionContext $execution,
        private TenantModuleStateQueries $moduleStates,
    ) {}

    /** Capture outside a transaction around the caller's existing permission decision. */
    public function snapshot(TenantContext $context, array $permissionKeys, array $moduleKeys): string
    {
        $this->assertScope($context);
        if (Db::connect()->getPdo()->inTransaction()) {
            throw new \DomainException('IDENTITY_AUTHORIZATION_SNAPSHOT_TRANSACTION_DENIED');
        }
        return $this->repository->readFence($context, $permissionKeys, $moduleKeys, false);
    }

    /** Locks remain in the caller's native transaction until its commit or rollback. */
    public function lockAndAssert(TenantContext $context, array $permissionKeys, array $moduleKeys, string $expected): void
    {
        $this->assertScope($context);
        if (!Db::connect()->getPdo()->inTransaction() || preg_match('/^[a-f0-9]{64}$/D', $expected) !== 1) {
            throw new \DomainException('IDENTITY_AUTHORIZATION_PROOF_STALE');
        }
        $this->moduleStates->lockLifecycleRows($moduleKeys);
        if (!hash_equals($expected, $this->repository->readFence($context, $permissionKeys, $moduleKeys, true))) {
            throw new \DomainException('IDENTITY_AUTHORIZATION_PROOF_STALE');
        }
    }

    private function assertScope(TenantContext $context): void
    {
        $root = $this->execution->current();
        if (!$root instanceof AdminExecutionContext || $root->tenant->tenantId !== $context->tenantId
            || $root->tenant->accountId !== $context->accountId || $root->tenant->memberId !== $context->memberId
            || $root->tenant->clientKey !== $context->clientKey || !hash_equals($root->tenant->sessionKey, $context->sessionKey)) {
            throw new \DomainException('IDENTITY_AUTHORIZATION_READ_SCOPE_DENIED');
        }
    }
}
