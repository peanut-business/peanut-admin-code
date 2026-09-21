<?php
declare(strict_types=1);

namespace app\common\services\audit;

use app\common\infrastructure\audit\OperationLogProjection;
use app\common\policy\audit\RedactionPolicy;
use app\common\contract\audit\AuditActor;
use app\common\contract\audit\AuditEvent;
use app\common\contract\audit\AuditResource;
use app\common\execution\CurrentExecutionContext;
use PeanutAdmin\Kernel\Audit\AuditOutcome;
use PeanutAdmin\Modules\Identity\Audit\Model\PlatformAuditEventRecord;
use PeanutAdmin\Modules\Identity\Audit\Model\TenantAuditEventRecord;
use PeanutAdmin\Kernel\Auth\TenantContext;
use think\facade\Db;

final class AuditContractHost
{
    private OperationLogProjection $operationLogs;

    public function __construct(?CurrentExecutionContext $execution)
    {
        $this->operationLogs = new OperationLogProjection($execution);
    }

    public function record(AuditEvent $event): void
    {
        if ($event->projection === AuditEvent::OPERATION_LOG) {
            Db::transaction(function () use ($event): void {
                $this->operationLogs->append($event);
                $this->appendTenantEvent($event);
            });
            return;
        }
        if ($event->projection === AuditEvent::PLATFORM) {
            $this->appendPlatformEvent($event);
            return;
        }
        $this->appendTenantEvent($event);
    }

    public function recordOperationLog(
        TenantContext $context,
        int $adminId,
        string $username,
        string $ip,
        string $uri,
        string $method,
        mixed $params,
        AuditOutcome $outcome = AuditOutcome::Success,
        ?string $reasonCode = null,
        int $httpStatus = 200,
    ): void {
        $this->record(AuditEvent::operationLog(
            $context,
            $adminId,
            $username,
            $ip,
            $uri,
            $method,
            $params,
            $outcome,
            $reasonCode,
            $httpStatus,
        ));
    }

    public function appendPlatform(
        string $eventType,
        string $action,
        string $requestId,
        ?int $operatorId,
        ?int $accountId,
        array $metadata = [],
    ): void {
        $this->recordPlatform($eventType, $action, $requestId, $operatorId, $accountId, $metadata);
    }

    public function appendTenantSystem(
        int $tenantId,
        string $eventType,
        string $action,
        string $requestId,
        array $metadata = [],
    ): void {
        $this->recordTenantSystem($tenantId, $eventType, $action, $requestId, $metadata);
    }

    public function recordPlatform(
        string $eventType,
        string $operation,
        string $requestId,
        ?int $operatorId,
        ?int $accountId,
        array $metadata = [],
        AuditOutcome $outcome = AuditOutcome::Success,
        ?string $reasonCode = null,
        ?AuditResource $resource = null,
    ): void {
        $this->record(AuditEvent::platform(
            $eventType,
            $operation,
            $requestId,
            $operatorId,
            $accountId,
            $metadata,
            $outcome,
            $reasonCode,
            $resource,
        ));
    }

    public function recordTenantSystem(
        int $tenantId,
        string $eventType,
        string $operation,
        string $requestId,
        array $metadata = [],
        AuditOutcome $outcome = AuditOutcome::Success,
        ?string $reasonCode = null,
        ?AuditResource $resource = null,
    ): void {
        $this->record(AuditEvent::tenantSystem(
            $tenantId,
            $eventType,
            $operation,
            $requestId,
            $metadata,
            $outcome,
            $reasonCode,
            $resource,
        ));
    }

    public function appendTenantMember(
        TenantContext $context,
        string $eventType,
        string $action,
        ?string $targetResourceType = null,
        ?string $targetResourceId = null,
        ?string $boundaryTargetType = null,
        ?string $boundaryTargetId = null,
        int $targetCount = 0,
        ?string $targetSetDigest = null,
        array $metadata = [],
    ): void {
        $this->record(AuditEvent::tenantMember(
            $context,
            $eventType,
            $action,
            $targetResourceType === null || $targetResourceId === null
                ? null
                : new AuditResource($targetResourceType, $targetResourceId),
            $boundaryTargetType === null || $boundaryTargetId === null
                ? null
                : new AuditResource($boundaryTargetType, $boundaryTargetId),
            $targetCount,
            $targetSetDigest,
            $metadata,
        ));
    }

    public function appendTenantPlatformOperator(
        int $tenantId,
        int $operatorId,
        int $accountId,
        string $eventType,
        string $action,
        string $requestId,
        array $metadata = [],
    ): void {
        $this->record(AuditEvent::tenantPlatformOperator(
            $tenantId,
            $operatorId,
            $accountId,
            $eventType,
            $action,
            $requestId,
            $metadata,
        ));
    }

    private function appendPlatformEvent(AuditEvent $event): void
    {
        $actor = $event->actor;
        (new PlatformAuditEventRecord())->save([
            'event_type' => $event->eventType,
            'action' => $event->operation,
            'outcome' => $event->outcome->value,
            'reason_code' => $event->reasonCode,
            'operator_id' => $actor->platformOperatorId,
            'account_id' => $actor->accountId,
            'target_type' => $event->resource?->type,
            'target_id' => $event->resource?->id,
            'request_id' => $event->trace->requestId,
            'operation_id' => $event->trace->operationId,
            'ip_address' => $event->trace->ipAddress,
            'user_agent_hash' => $event->trace->userAgentHash,
            'before_json' => RedactionPolicy::nullableJson($event->before),
            'after_json' => RedactionPolicy::nullableJson($event->after),
            'metadata_json' => RedactionPolicy::nullableJson($event->metadata),
            'occurred_at' => Db::raw('UTC_TIMESTAMP(3)'),
        ]);
    }

    private function appendTenantEvent(AuditEvent $event): void
    {
        if ($event->tenantId === null) {
            throw new \InvalidArgumentException('AUDIT_TENANT_REQUIRED');
        }
        $actor = $event->actor;
        (new TenantAuditEventRecord())->save([
            'tenant_id' => $event->tenantId,
            'event_type' => $event->eventType,
            'action' => $event->operation,
            'outcome' => $event->outcome->value,
            'reason_code' => $event->reasonCode,
            'actor_tenant_id' => $actor->type === AuditActor::TENANT_MEMBER || $actor->type === AuditActor::TENANT_SYSTEM
                ? $actor->tenantId
                : null,
            'actor_tenant_member_id' => $actor->tenantMemberId,
            'actor_account_id' => $actor->accountId,
            'actor_platform_operator_id' => $actor->platformOperatorId,
            'actor_type' => $actor->type,
            'target_resource_type' => $event->resource?->type,
            'target_resource_id' => $event->resource?->id,
            'boundary_target_type' => $event->boundaryTarget?->type,
            'boundary_target_id' => $event->boundaryTarget?->id,
            'target_count' => $event->targetCount,
            'target_set_digest' => $event->targetSetDigest,
            'authorization_basis_json' => RedactionPolicy::nullableJson($event->authorizationBasis),
            'request_id' => $event->trace->requestId,
            'operation_id' => $event->trace->operationId,
            'ip_address' => $event->trace->ipAddress,
            'user_agent_hash' => $event->trace->userAgentHash,
            'before_json' => RedactionPolicy::nullableJson($event->before),
            'after_json' => RedactionPolicy::nullableJson($event->after),
            'metadata_json' => RedactionPolicy::nullableJson($event->metadata),
            'occurred_at' => Db::raw('UTC_TIMESTAMP(3)'),
        ]);
    }
}
