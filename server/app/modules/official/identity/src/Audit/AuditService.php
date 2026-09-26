<?php

declare(strict_types=1);

namespace PeanutAdmin\Modules\Identity\Audit;

use PeanutAdmin\Modules\Identity\Audit\Model\PlatformAuditEventRecord;
use PeanutAdmin\Modules\Identity\Audit\Model\TenantAuditEventRecord;
use PeanutAdmin\Kernel\Auth\TenantContext;
use think\db\Raw;

final readonly class AuditService implements \PeanutAdmin\Kernel\Audit\AuditWriter
{
    /** Persists a fully authorized platform audit projection without exposing its Model. */
    public function appendPlatformEvent(
        string $eventType,
        string $action,
        string $outcome,
        ?string $reasonCode,
        ?int $operatorId,
        ?int $accountId,
        ?string $targetType,
        ?string $targetId,
        string $requestId,
        ?string $operationId,
        ?string $ipAddress,
        ?string $userAgentHash,
        ?string $beforeJson,
        ?string $afterJson,
        ?string $metadataJson,
    ): void {
        self::assertProjection($eventType, $action, $outcome, $requestId);
        if (($operatorId === null) !== ($accountId === null)
            || ($operatorId !== null && ($operatorId < 1 || $accountId < 1))) {
            throw new \DomainException('AUDIT_PLATFORM_ACTOR_INVALID');
        }
        (new PlatformAuditEventRecord())->save([
            'event_type' => $eventType,
            'action' => $action,
            'outcome' => $outcome,
            'reason_code' => $reasonCode,
            'operator_id' => $operatorId,
            'account_id' => $accountId,
            'target_type' => $targetType,
            'target_id' => $targetId,
            'request_id' => $requestId,
            'operation_id' => $operationId,
            'ip_address' => $ipAddress,
            'user_agent_hash' => $userAgentHash,
            'before_json' => self::jsonDocument($beforeJson),
            'after_json' => self::jsonDocument($afterJson),
            'metadata_json' => self::jsonDocument($metadataJson),
            'occurred_at' => new Raw('UTC_TIMESTAMP(3)'),
        ]);
    }

    /** Persists a fully authorized Tenant audit projection without exposing its Model. */
    public function appendTenantEvent(
        int $tenantId,
        string $eventType,
        string $action,
        string $outcome,
        ?string $reasonCode,
        ?int $actorTenantId,
        ?int $actorTenantMemberId,
        ?int $actorAccountId,
        ?int $actorPlatformOperatorId,
        string $actorType,
        ?string $targetResourceType,
        ?string $targetResourceId,
        ?string $boundaryTargetType,
        ?string $boundaryTargetId,
        int $targetCount,
        ?string $targetSetDigest,
        ?string $authorizationBasisJson,
        string $requestId,
        ?string $operationId,
        ?string $ipAddress,
        ?string $userAgentHash,
        ?string $beforeJson,
        ?string $afterJson,
        ?string $metadataJson,
    ): void {
        self::assertProjection($eventType, $action, $outcome, $requestId);
        $actorValid = match ($actorType) {
            'member' => $actorTenantId === $tenantId && ($actorTenantMemberId ?? 0) > 0
                && ($actorAccountId ?? 0) > 0 && $actorPlatformOperatorId === null,
            'tenant_system' => $actorTenantId === $tenantId && $actorTenantMemberId === null
                && $actorAccountId === null && $actorPlatformOperatorId === null,
            'platform_operator' => $actorTenantId === null && $actorTenantMemberId === null
                && ($actorAccountId ?? 0) > 0 && ($actorPlatformOperatorId ?? 0) > 0,
            default => false,
        };
        if ($tenantId < 1 || !$actorValid || $targetCount < 0) {
            throw new \DomainException('AUDIT_TENANT_PROJECTION_INVALID');
        }
        (new TenantAuditEventRecord())->save([
            'tenant_id' => $tenantId,
            'event_type' => $eventType,
            'action' => $action,
            'outcome' => $outcome,
            'reason_code' => $reasonCode,
            'actor_tenant_id' => $actorTenantId,
            'actor_tenant_member_id' => $actorTenantMemberId,
            'actor_account_id' => $actorAccountId,
            'actor_platform_operator_id' => $actorPlatformOperatorId,
            'actor_type' => $actorType,
            'target_resource_type' => $targetResourceType,
            'target_resource_id' => $targetResourceId,
            'boundary_target_type' => $boundaryTargetType,
            'boundary_target_id' => $boundaryTargetId,
            'target_count' => $targetCount,
            'target_set_digest' => $targetSetDigest,
            'authorization_basis_json' => self::jsonDocument($authorizationBasisJson),
            'request_id' => $requestId,
            'operation_id' => $operationId,
            'ip_address' => $ipAddress,
            'user_agent_hash' => $userAgentHash,
            'before_json' => self::jsonDocument($beforeJson),
            'after_json' => self::jsonDocument($afterJson),
            'metadata_json' => self::jsonDocument($metadataJson),
            'occurred_at' => new Raw('UTC_TIMESTAMP(3)'),
        ]);
    }

    private static function assertProjection(string $eventType, string $action, string $outcome, string $requestId): void
    {
        if (trim($eventType) === '' || trim($action) === '' || trim($requestId) === ''
            || \PeanutAdmin\Kernel\Audit\AuditOutcome::tryFrom($outcome) === null) {
            throw new \DomainException('AUDIT_PROJECTION_INVALID');
        }
    }

    /** @return array<array-key, mixed>|null */
    private static function jsonDocument(?string $value): ?array
    {
        if ($value === null) {
            return null;
        }
        try {
            $document = json_decode($value, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            throw new \DomainException('AUDIT_JSON_INVALID', 0, $exception);
        }
        if (!is_array($document)) {
            throw new \DomainException('AUDIT_JSON_DOCUMENT_REQUIRED');
        }
        return $document;
    }

    /** @param array<string, mixed> $metadata */
    public function tenantMember(
        TenantContext $context,
        string $eventType,
        string $action,
        ?string $targetResourceType = null,
        ?string $targetResourceId = null,
        array $metadata = [],
        ?int $targetCount = null,
        ?string $boundaryTargetType = null,
        ?string $boundaryTargetId = null,
        ?string $targetSetDigest = null,
        \PeanutAdmin\Kernel\Audit\AuditOutcome $outcome = \PeanutAdmin\Kernel\Audit\AuditOutcome::Success,
    ): void {
        (new TenantAuditEventRecord())->save([
            'tenant_id' => $context->tenantId,
            'event_type' => $eventType,
            'action' => $action,
            'outcome' => $outcome->value,
            'actor_tenant_id' => $context->tenantId,
            'actor_tenant_member_id' => $context->memberId,
            'actor_account_id' => $context->accountId,
            'actor_type' => 'member',
            'target_resource_type' => $targetResourceType,
            'target_resource_id' => $targetResourceId,
            'boundary_target_type' => $boundaryTargetType,
            'boundary_target_id' => $boundaryTargetId,
            'target_count' => $targetCount ?? ($targetResourceId === null ? 0 : 1),
            'target_set_digest' => $targetSetDigest,
            'request_id' => $context->requestId,
            'metadata_json' => $metadata === [] ? null : $metadata,
            'occurred_at' => new Raw('UTC_TIMESTAMP(3)'),
        ]);
    }

    /** @param array<string, mixed> $metadata */
    public function tenantSystem(
        int $tenantId,
        string $eventType,
        string $action,
        string $requestId,
        array $metadata = [],
        \PeanutAdmin\Kernel\Audit\AuditOutcome $outcome = \PeanutAdmin\Kernel\Audit\AuditOutcome::Success,
    ): void {
        (new TenantAuditEventRecord())->save([
            'tenant_id' => $tenantId,
            'event_type' => $eventType,
            'action' => $action,
            'outcome' => $outcome->value,
            'actor_tenant_id' => $tenantId,
            'actor_type' => 'tenant_system',
            'target_count' => 0,
            'request_id' => $requestId,
            'metadata_json' => $metadata === [] ? null : $metadata,
            'occurred_at' => new Raw('UTC_TIMESTAMP(3)'),
        ]);
    }

    /** @param array<string, mixed> $metadata
     * @param array<string, mixed>|null $before
     * @param array<string, mixed>|null $after
     */
    public function platform(
        int $operatorId,
        int $accountId,
        string $requestId,
        string $eventType,
        string $action,
        array $metadata = [],
        ?string $targetType = null,
        ?string $targetId = null,
        ?array $before = null,
        ?array $after = null,
        \PeanutAdmin\Kernel\Audit\AuditOutcome $outcome = \PeanutAdmin\Kernel\Audit\AuditOutcome::Success,
    ): void {
        (new PlatformAuditEventRecord())->save([
            'event_type' => $eventType,
            'action' => $action,
            'outcome' => $outcome->value,
            'operator_id' => $operatorId,
            'account_id' => $accountId,
            'target_type' => $targetType,
            'target_id' => $targetId,
            'request_id' => $requestId,
            'before_json' => $before,
            'after_json' => $after,
            'metadata_json' => $metadata === [] ? null : $metadata,
            'occurred_at' => new Raw('UTC_TIMESTAMP(3)'),
        ]);
    }

    /** @param array<string, mixed> $metadata
     * @param array<string, mixed>|null $before
     * @param array<string, mixed>|null $after
     */
    public function tenantPlatformOperator(
        int $tenantId,
        int $operatorId,
        int $accountId,
        string $eventType,
        string $action,
        string $requestId,
        array $metadata = [],
        ?string $targetType = null,
        ?string $targetId = null,
        ?array $before = null,
        ?array $after = null,
        \PeanutAdmin\Kernel\Audit\AuditOutcome $outcome = \PeanutAdmin\Kernel\Audit\AuditOutcome::Success,
    ): void {
        (new TenantAuditEventRecord())->save([
            'tenant_id' => $tenantId,
            'event_type' => $eventType,
            'action' => $action,
            'outcome' => $outcome->value,
            'actor_tenant_id' => null,
            'actor_platform_operator_id' => $operatorId,
            'actor_account_id' => $accountId,
            'actor_type' => 'platform_operator',
            'target_resource_type' => $targetType,
            'target_resource_id' => $targetId,
            'target_count' => $targetId === null ? 0 : 1,
            'request_id' => $requestId,
            'before_json' => $before,
            'after_json' => $after,
            'metadata_json' => $metadata === [] ? null : $metadata,
            'occurred_at' => new Raw('UTC_TIMESTAMP(3)'),
        ]);
    }
}
