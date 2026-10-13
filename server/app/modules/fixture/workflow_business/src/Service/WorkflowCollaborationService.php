<?php

declare(strict_types=1);

namespace PeanutAdmin\Fixtures\WorkflowBusiness\Service;

use PeanutAdmin\Fixtures\WorkflowBusiness\Model\CollaborationEntry;
use PeanutAdmin\Fixtures\WorkflowBusiness\Model\EffectReceipt;
use PeanutAdmin\Fixtures\WorkflowBusiness\Package;
use PeanutAdmin\Kernel\Authorization\Application\AdminAccessException;
use PeanutAdmin\Kernel\Context\AuthorizedOperationContext;
use PeanutAdmin\Modules\Identity\Audit\AuditService;
use PeanutAdmin\Modules\Workflow\Application\WorkflowQueries;
use PeanutAdmin\Modules\Workflow\Application\WorkflowLifecycleQueries;
use think\facade\Db;

/** Owning business collaboration, separate from the registered snapshot/action provider. */
final readonly class WorkflowCollaborationService
{
    public function __construct(private WorkflowBusinessAccess $access, private WorkflowQueries $workflow,
        private WorkflowLifecycleQueries $workflowLifecycle, private AuditService $audit) {}

    /** Append-only current-object collaboration, independent of frozen materials and voting. */
    public function collaboration(AuthorizedOperationContext $basis, string $scene, string $key, string $instanceKey, string $workItemKey): array
    {
        $this->object($basis, Package::resource($scene), $key, 'read', false);
        $access = $this->workflow->collaborationAccess($basis, $instanceKey, $workItemKey);
        $this->collaborationSubject($access, $scene, $key);
        try {
            $this->access->authorize($basis, Package::resource($scene), 'collaborate', [Package::MODULE_KEY . '.collaborate'], $key);
        } catch (AdminAccessException|\PeanutAdmin\DataPermission\Exception\DataAuthorizationException) {
            $access['can_collaborate'] = false;
        }
        $entries = CollaborationEntry::where('tenant_id', $basis->tenantContext->tenantId)->where('object_key', $key)
            ->where('instance_key', $instanceKey)->order('id', 'desc')->limit(100)
            ->field('entry_key,work_item_key,kind,note,reference,actor_member_id,created_at')->select()->toArray();
        foreach ($entries as &$entry) { $entry['actor_member_id'] = (string) $entry['actor_member_id']; }
        unset($entry);
        return ['access' => $access, 'entries' => $entries];
    }

    public function appendCollaboration(AuthorizedOperationContext $basis, string $scene, string $key, array $input, string $idempotencyKey): array
    {
        self::idempotencyKey($idempotencyKey);
        if (array_diff(array_keys($input), ['instance_key', 'work_item_key', 'instance_revision', 'node_revision', 'task_revision', 'kind', 'note', 'reference']) !== []
            || !is_string($input['instance_key'] ?? null) || !is_string($input['work_item_key'] ?? null)
            || !in_array($input['kind'] ?? null, ['comment', 'supplement'], true)
            || !is_string($input['note'] ?? null) || trim($input['note']) === '' || mb_strlen($input['note']) > 1000
            || !is_string($input['reference'] ?? null) || strlen($input['reference']) > 128
            || ($input['kind'] === 'supplement' && trim($input['reference']) === '')) {
            throw AdminAccessException::invalid('WORKFLOW_BUSINESS_COLLABORATION_INVALID', 'A bounded comment or supplement reference and revision tuple are required.');
        }
        foreach (['instance_revision', 'node_revision', 'task_revision'] as $revision) {
            if (!is_int($input[$revision] ?? null) || $input[$revision] < 1) { throw AdminAccessException::revisionMismatch(); }
        }
        $hash = hash('sha256', self::json([$scene, $key, $input]));
        $effect = 'collaboration:' . hash('sha256', $basis->tenantContext->memberId . ':' . $idempotencyKey);
        return Db::transaction(function () use ($basis, $scene, $key, $input, $effect, $hash): array {
            $this->workflowLifecycle->assertAvailable($basis->tenantContext->tenantId, true);
            $context = $this->access->authorize($basis, Package::resource($scene), 'collaborate', [Package::MODULE_KEY . '.collaborate'], $key);
            $this->object($context, Package::resource($scene), $key, 'collaborate', true);
            $access = $this->workflow->collaborationAccess($context, $input['instance_key'], $input['work_item_key'], true);
            $this->collaborationSubject($access, $scene, $key);
            if (($receipt = $this->effect($context, $effect, $hash)) !== null) { return $receipt; }
            if (!$access['can_collaborate']) { throw new AdminAccessException('WORKFLOW_BUSINESS_COLLABORATION_DENIED', 403, 'A current eligible participant or assignee is required.'); }
            foreach (['instance_revision', 'node_revision', 'task_revision'] as $revision) {
                if ($input[$revision] !== $access[$revision]) { throw AdminAccessException::revisionMismatch(); }
            }
            $receipt = ['entry_key' => 'collaboration_' . bin2hex(random_bytes(16))];
            CollaborationEntry::insert(['tenant_id' => $context->tenantContext->tenantId, 'object_key' => $key,
                'instance_key' => $input['instance_key'], 'work_item_key' => $input['work_item_key'],
                ...$receipt, 'kind' => $input['kind'], 'note' => trim($input['note']), 'reference' => $input['reference'],
                'actor_member_id' => $context->tenantContext->memberId, 'created_at' => self::now()]);
            $this->saveEffect($context, $effect, $hash, 'collaborate', $key, $receipt);
            $this->record($context, 'fixture.workflow-business.collaborated', $key);
            return $receipt;
        });
    }

    private function collaborationSubject(array $access, string $scene, string $key): void
    {
        if ($access['subject_type'] !== Package::resource($scene) || $access['subject_key'] !== $key) { throw AdminAccessException::notFound(); }
    }

    private function object(AuthorizedOperationContext $basis, string $resource, string $key, string $operation, bool $lock): array
    {
        if (preg_match('/^object_[0-9a-f]{32}$/D', $key) !== 1) {
            throw AdminAccessException::notFound();
        }
        $query = $this->access->query($basis, $resource, $operation)->where('object_key', $key);
        if ($lock) {
            $query->lock(true);
        }
        return $query->find()?->toArray() ?? throw AdminAccessException::notFound();
    }

    private function effect(AuthorizedOperationContext $basis, string $effectKey, string $hash): ?array
    {
        $row = EffectReceipt::where('tenant_id', $basis->tenantContext->tenantId)->where('effect_key', $effectKey)->lock(true)->find()?->toArray();
        if ($row === null) {
            return null;
        }
        if (!hash_equals((string) $row['request_hash'], $hash)) {
            throw AdminAccessException::conflict('WORKFLOW_BUSINESS_EFFECT_CONFLICT', 'The effect key is already bound to another action.');
        }
        return json_decode((string) $row['receipt_json'], true, 32, JSON_THROW_ON_ERROR);
    }

    private function saveEffect(AuthorizedOperationContext $basis, string $effectKey, string $hash, string $action, string $objectKey, array $receipt): void
    {
        EffectReceipt::insert(['tenant_id' => $basis->tenantContext->tenantId, 'effect_key' => $effectKey, 'request_hash' => $hash,
            'action_key' => $action, 'object_key' => $objectKey, 'receipt_json' => self::json($receipt), 'created_at' => self::now()]);
    }

    private function record(AuthorizedOperationContext $basis, string $event, string $key): void
    {
        $this->audit->tenantMember($basis->tenantContext, $event, $basis->operation, 'fixture.workflow-business', $key);
    }

    private static function json(array $value): string
    {
        return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    private static function idempotencyKey(string $key): void
    {
        if (strlen($key) < 8 || strlen($key) > 128 || preg_match('/^[\x21-\x7E]+$/D', $key) !== 1) {
            throw AdminAccessException::invalid('WORKFLOW_BUSINESS_IDEMPOTENCY_INVALID', 'A bounded idempotency key is required.');
        }
    }

    private static function now(): string
    {
        return (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s.v');
    }
}
