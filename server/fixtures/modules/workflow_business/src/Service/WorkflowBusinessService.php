<?php

declare(strict_types=1);

namespace PeanutAdmin\Fixtures\WorkflowBusiness\Service;

use PeanutAdmin\Fixtures\WorkflowBusiness\Model\BusinessObject;
use PeanutAdmin\Fixtures\WorkflowBusiness\Model\EffectReceipt;
use PeanutAdmin\Fixtures\WorkflowBusiness\Model\StockBalance;
use PeanutAdmin\Fixtures\WorkflowBusiness\Model\StockLedger;
use PeanutAdmin\Fixtures\WorkflowBusiness\Model\VersionPayload;
use PeanutAdmin\Fixtures\WorkflowBusiness\Package;
use PeanutAdmin\Kernel\Authorization\Application\AdminAccessException;
use PeanutAdmin\Kernel\Authorization\Application\PageRequest;
use PeanutAdmin\Kernel\Context\AuthorizedOperationContext;
use PeanutAdmin\Modules\ArtifactRevision\Application\ArtifactRevisionService;
use PeanutAdmin\Modules\ArtifactRevision\Contract\ArtifactRevisionQuery;
use PeanutAdmin\Modules\Identity\Audit\AuditService;
use PeanutAdmin\Modules\Identity\Contract\WorkflowOrganizationQuery;
use think\facade\Db;

/** Optional synthetic business. Workflow interacts only through this owning module. */
final readonly class WorkflowBusinessService
{
    public function __construct(
        private WorkflowBusinessAccess $access,
        private WorkflowOrganizationQuery $organization,
        private ArtifactRevisionService $revisions,
        private ArtifactRevisionQuery $revisionQuery,
        private AuditService $audit,
    ) {}

    public function lists(AuthorizedOperationContext $basis, string $scene, PageRequest $page): array
    {
        $query = $this->access->query($basis, Package::resource($scene), 'read');
        $total = (int) (clone $query)->count();
        $rows = $query->field('object_key,scene,title,owner_member_id,department_id,state,revision,confirmed_revision_key,updated_at')
            ->order('id', 'desc')->limit($page->offset(), $page->pageSize)->select()->toArray();
        return ['items' => array_map($this->project(...), $rows), 'total' => $total];
    }

    public function detail(AuthorizedOperationContext $basis, string $scene, string $key): array
    {
        return $this->project($this->object($basis, Package::resource($scene), $key, 'read', false))
            + ['available_actions' => $this->access->availableActions($basis, Package::resource($scene), $key)];
    }

    public function create(AuthorizedOperationContext $basis, string $scene, array $input): array
    {
        $resource = Package::resource($scene);
        $this->access->requireCreate($basis, $resource);
        $fields = self::fields($input);
        $departmentKey = $this->organization->businessDepartment($basis->tenantContext, $input['business_department_key'] ?? null);
        $member = $this->access->currentMember($basis);
        $key = 'object_' . bin2hex(random_bytes(16));
        return Db::transaction(function () use ($basis, $scene, $fields, $departmentKey, $member, $key): array {
            $this->access->requireCreate($basis, Package::resource($scene));
            $now = self::now();
            $object = new BusinessObject();
            $object->save([
                'tenant_id' => $basis->tenantContext->tenantId, 'object_key' => $key, 'scene' => $scene,
                'owner_member_id' => $basis->tenantContext->memberId, 'department_id' => $member->primaryDepartmentId,
                'business_department_key' => $departmentKey, ...$fields, 'state' => 'draft', 'revision' => 1,
                'completion_ready' => false, 'completion_form_json' => '{}', 'created_at' => $now, 'updated_at' => $now,
            ]);
            $this->record($basis, 'fixture.workflow-business.created', $key);
            return $this->project($object->toArray());
        });
    }

    public function save(AuthorizedOperationContext $basis, string $scene, string $key, int $expectedRevision, array $input): array
    {
        $fields = self::fields($input);
        return Db::transaction(function () use ($basis, $scene, $key, $expectedRevision, $fields): array {
            $row = $this->object($basis, Package::resource($scene), $key, 'update', true);
            $this->expect($row, $expectedRevision);
            if (in_array($row['state'], ['received', 'decided'], true)) {
                throw AdminAccessException::conflict('WORKFLOW_BUSINESS_FINALIZED', 'A completed object cannot be edited.');
            }
            BusinessObject::where('tenant_id', $basis->tenantContext->tenantId)->where('id', $row['id'])->update([
                ...$fields, 'state' => 'draft', 'confirmed_revision_key' => null, 'completion_ready' => false,
                'completion_form_json' => '{}', 'revision' => $expectedRevision + 1, 'updated_at' => self::now(),
            ]);
            $this->record($basis, 'fixture.workflow-business.saved', $key);
            return $this->detail($basis, $scene, $key);
        });
    }

    public function confirm(AuthorizedOperationContext $basis, string $scene, string $key, int $expectedRevision, string $idempotencyKey): array
    {
        self::idempotencyKey($idempotencyKey);
        $resource = Package::resource($scene);
        $effect = 'confirm:' . hash('sha256', $idempotencyKey);
        $hash = hash('sha256', self::json([$resource, $key, $expectedRevision]));
        return Db::transaction(function () use ($basis, $scene, $resource, $key, $expectedRevision, $effect, $hash): array {
            $context = $this->access->authorize($basis, $resource, 'confirm', [Package::MODULE_KEY . '.confirm'], $key);
            $row = $this->object($context, $resource, $key, 'confirm', true);
            if (($replay = $this->effect($context, $effect, $hash)) !== null) {
                return $replay;
            }
            $this->expect($row, $expectedRevision);
            if ($row['state'] !== 'draft') {
                throw AdminAccessException::conflict('WORKFLOW_BUSINESS_CONFIRM_STATE', 'Only a saved draft can be confirmed.');
            }
            $payload = self::json(self::payload($row));
            $created = $this->revisions->createRevision(
                $context,
                $resource,
                $key,
                $row['last_revision_key'] ?? null,
                isset($row['artifact_revision']) ? (int) $row['artifact_revision'] : null,
                hash('sha256', $effect . ':create'),
            );
            VersionPayload::insert([
                'tenant_id' => $context->tenantContext->tenantId, 'object_key' => $key, 'revision_key' => $created->revisionKey,
                'payload_json' => $payload, 'payload_sha256' => hash('sha256', $payload), 'created_at' => self::now(),
            ]);
            $finalized = $this->revisions->finalizeRevision(
                $context,
                $resource,
                $key,
                $created->revisionKey,
                $created->artifactRevision,
                $created->revision,
                Package::MODULE_KEY . '.payload',
                '1',
                'fixture-workflow-payload:' . $created->revisionKey,
                hash('sha256', $payload),
                null,
                hash('sha256', $effect . ':finalize'),
            );
            BusinessObject::where('tenant_id', $context->tenantContext->tenantId)->where('id', $row['id'])->update([
                'state' => 'confirmed', 'confirmed_revision_key' => $finalized->revisionKey, 'last_revision_key' => $finalized->revisionKey,
                'artifact_revision' => $finalized->artifactRevision, 'revision' => $expectedRevision + 1, 'updated_at' => self::now(),
            ]);
            $receipt = ['object_key' => $key, 'scene' => $scene, 'revision' => (string) ($expectedRevision + 1),
                'revision_key' => $finalized->revisionKey, 'sha256' => $finalized->canonicalEnvelopeSha256];
            $this->saveEffect($context, $effect, $hash, 'confirm', $key, $receipt);
            $this->record($context, 'fixture.workflow-business.confirmed', $key);
            return $receipt;
        });
    }

    /** Saves the business form only. It neither votes nor completes any workflow task. */
    public function saveCompletionForm(AuthorizedOperationContext $basis, string $scene, string $key, int $expectedRevision, array $input): array
    {
        if (array_diff(array_keys($input), $scene === 'receipt' ? ['note', 'reference', 'result'] : ['note', 'reference']) !== [] || !is_string($input['note'] ?? null)
            || !is_string($input['reference'] ?? null) || trim($input['reference']) === '' || mb_strlen($input['note']) > 1000 || strlen($input['reference']) > 128
            || ($scene === 'receipt' && !in_array($input['result'] ?? null, ['completed', 'rejected'], true))) {
            throw AdminAccessException::invalid('WORKFLOW_BUSINESS_FORM_INVALID', 'Save a reference and bounded note first.');
        }
        return Db::transaction(function () use ($basis, $scene, $key, $expectedRevision, $input): array {
            $row = $this->object($basis, Package::resource($scene), $key, 'complete', true);
            $this->expect($row, $expectedRevision);
            if ($row['state'] !== 'confirmed') {
                throw AdminAccessException::conflict('WORKFLOW_BUSINESS_FORM_STATE', 'The object must have a confirmed version.');
            }
            BusinessObject::where('tenant_id', $basis->tenantContext->tenantId)->where('id', $row['id'])->update([
                'completion_form_json' => self::json($input), 'completion_ready' => true,
                'revision' => $expectedRevision + 1, 'updated_at' => self::now(),
            ]);
            $this->record($basis, 'fixture.workflow-business.form-saved', $key);
            return $this->detail($basis, $scene, $key);
        });
    }

    public function snapshot(AuthorizedOperationContext $basis, string $resource, string $key, string $revisionKey, bool $forUpdate): array
    {
        $this->object($basis, $resource, $key, 'read', $forUpdate);
        $snapshot = $this->revisionQuery->snapshot($basis->tenantContext->tenantId, $resource, $key, $revisionKey, $forUpdate);
        $query = VersionPayload::where('tenant_id', $basis->tenantContext->tenantId)->where('object_key', $key)->where('revision_key', $revisionKey);
        if ($forUpdate) {
            $query->lock(true);
        }
        $payload = $query->find()?->toArray();
        if ($snapshot === null || $payload === null || $snapshot->envelope['payload_ref'] !== 'fixture-workflow-payload:' . $revisionKey
            || !hash_equals((string) $snapshot->envelope['payload_sha256'], hash('sha256', (string) $payload['payload_json']))
            || !hash_equals((string) $payload['payload_sha256'], (string) $snapshot->envelope['payload_sha256'])) {
            throw AdminAccessException::conflict('WORKFLOW_BUSINESS_SNAPSHOT_INVALID', 'The fixed business version is missing or has changed.');
        }
        $fields = json_decode((string) $payload['payload_json'], true, 32, JSON_THROW_ON_ERROR);
        return ['revision_key' => $snapshot->revisionKey, 'sha256' => $snapshot->sha256, 'fields' => $fields,
            'reference' => ['module_key' => Package::MODULE_KEY, 'object_key' => $key, 'form_path' => '/app/fixtures/workflow-business/' . Package::scene($resource) . '/' . $key]];
    }

    public function subjectOwner(AuthorizedOperationContext $basis, string $resource, string $key): ?int
    {
        return (int) $this->object($basis, $resource, $key, 'read', false)['owner_member_id'];
    }

    public function businessDepartment(AuthorizedOperationContext $basis, ?string $requestedKey): ?string
    {
        $target = $basis->targets[0] ?? null;
        if (count($basis->targets) !== 1 || $target === null || $target->targetRole !== 'primary'
            || $target->targetResourceKey !== $basis->resourceKey || count($target->targetIds) !== 1) {
            throw AdminAccessException::notFound();
        }
        $row = $this->object($basis, $basis->resourceKey, $target->targetIds[0], 'read', false);
        $stored = $row['business_department_key'];
        $key = $stored === null ? null : $this->organization->activeDepartmentKey($basis->tenantContext, (string) $stored);
        if ($requestedKey !== null && ($key === null || !hash_equals($key, $requestedKey))) {
            throw AdminAccessException::invalid('WORKFLOW_BUSINESS_DEPARTMENT_INVALID', 'Use the current department of the authorized business object.');
        }
        return $key;
    }

    public function validateCompletion(AuthorizedOperationContext $basis, array $instance, array $input): array
    {
        $resource = (string) ($instance['subject_type'] ?? '');
        $resubmission = ($instance['submission_action'] ?? null) === 'resubmit';
        $row = $this->object($basis, $resource, (string) ($instance['subject_key'] ?? ''), $resubmission ? 'update' : 'complete', false);
        if ($row['state'] !== 'confirmed' || (!$resubmission && !(bool) $row['completion_ready'])
            || $row['confirmed_revision_key'] !== ($instance['subject_revision_key'] ?? null)) {
            throw AdminAccessException::conflict('WORKFLOW_BUSINESS_COMPLETION_BLOCKED', 'Save the business form and use the current confirmed version before completing.');
        }
        if (array_diff(array_keys($input), ['result']) !== []
            || (array_key_exists('result', $input) && !in_array($input['result'], ['approved', 'rejected', 'completed'], true))) {
            throw AdminAccessException::invalid('WORKFLOW_BUSINESS_COMPLETION_INPUT_INVALID', 'Completion accepts registered fields only.');
        }
        if ($resubmission) {
            $previous = $instance['submission_previous_revision_key'] ?? null;
            if ((int) ($instance['cycle_no'] ?? 0) > 1
                && (!is_string($previous) || hash_equals($previous, (string) $row['confirmed_revision_key']))) {
                throw AdminAccessException::conflict('WORKFLOW_BUSINESS_NEW_REVISION_REQUIRED', 'Returned material must be saved and confirmed as a new version.');
            }
            return []; // The confirmed new snapshot is the resubmission; it is not a handling decision.
        }
        $form = json_decode((string) $row['completion_form_json'], true, 32, JSON_THROW_ON_ERROR);
        if (Package::scene($resource) === 'receipt' && !in_array($form['result'] ?? null, ['completed', 'rejected'], true)) {
            throw AdminAccessException::conflict('WORKFLOW_BUSINESS_ACCEPTANCE_REQUIRED', 'Save an explicit acceptance result for this version.');
        }
        if (Package::scene($resource) === 'receipt' && ($instance['current_node_key'] ?? null) === 'exception'
            && $form['result'] !== 'completed') {
            throw AdminAccessException::conflict('WORKFLOW_BUSINESS_EXCEPTION_UNRESOLVED', 'Resolve the acceptance exception before completing supervisor handling.');
        }
        return ['form_saved' => true, 'quantity' => (int) $row['quantity']]
            + (Package::scene($resource) === 'receipt' ? ['result' => $form['result']] : []);
    }

    /** Joined outer transaction owns commit; no external effect or independent transaction. */
    public function executeAction(AuthorizedOperationContext $basis, string $actionKey, int $contractVersion, array $input, string $effectKey): array
    {
        $scene = Package::scene($basis->resourceKey);
        $pdo = Db::connect()->getPdo();
        if ($actionKey !== Package::action($scene) || $contractVersion !== 1 || strlen($effectKey) > 160 || $effectKey === ''
            || !$pdo instanceof \PDO || !$pdo->inTransaction() || array_diff(array_keys($input), ['subject_key', 'revision_key', 'result']) !== []
            || !is_string($input['subject_key'] ?? null) || !is_string($input['revision_key'] ?? null)
            || !in_array($input['result'] ?? null, ['approved', 'rejected', 'completed'], true)) {
            throw AdminAccessException::invalid('WORKFLOW_BUSINESS_ACTION_INVALID', 'The trusted registered action and outer transaction are required.');
        }
        $operation = $scene === 'receipt' ? 'receive' : 'complete';
        $context = $this->access->authorize($basis, $basis->resourceKey, $operation, [Package::MODULE_KEY . '.' . $operation], $input['subject_key']);
        $row = $this->object($context, $context->resourceKey, $input['subject_key'], $operation, true);
        $hash = hash('sha256', self::json([$actionKey, $contractVersion, $input]));
        if (($receipt = $this->effect($context, $effectKey, $hash)) !== null) {
            return $receipt;
        }
        if ($row['state'] !== 'confirmed' || ($scene !== 'application' && $input['result'] !== 'rejected' && !(bool) $row['completion_ready'])
            || $row['confirmed_revision_key'] !== $input['revision_key']) {
            throw AdminAccessException::conflict('WORKFLOW_BUSINESS_ACTION_BLOCKED', 'The current confirmed object and saved form are required.');
        }
        $this->snapshot($context, $context->resourceKey, $input['subject_key'], $input['revision_key'], true);
        if ($scene === 'receipt') {
            $form = json_decode((string) $row['completion_form_json'], true, 32, JSON_THROW_ON_ERROR);
            if ($input['result'] !== 'completed' || ($form['result'] ?? null) !== 'completed') {
                throw AdminAccessException::invalid('WORKFLOW_BUSINESS_RECEIPT_RESULT_INVALID', 'Stock receipt requires completed.');
            }
            StockLedger::insert(['tenant_id' => $context->tenantContext->tenantId, 'effect_key' => $effectKey,
                'object_key' => $input['subject_key'], 'sku' => $row['sku'], 'quantity' => $row['quantity'],
                'actor_member_id' => $context->tenantContext->memberId, 'created_at' => self::now()]);
            $table = (new StockBalance())->getTable();
            if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/D', $table) !== 1) {
                throw new \LogicException('WORKFLOW_BUSINESS_STOCK_TABLE_INVALID');
            }
            Db::execute(
                'INSERT INTO `' . $table . '` (tenant_id,sku,quantity,revision,updated_at) VALUES (?,?,?,1,?) '
                . 'ON DUPLICATE KEY UPDATE quantity=quantity+VALUES(quantity),revision=revision+1,updated_at=VALUES(updated_at)',
                [$context->tenantContext->tenantId, $row['sku'], (int) $row['quantity'], self::now()],
            );
        } elseif (!in_array($input['result'], ['approved', 'rejected'], true)) {
            throw AdminAccessException::invalid('WORKFLOW_BUSINESS_DECISION_INVALID', 'A decision result is required.');
        }
        BusinessObject::where('tenant_id', $context->tenantContext->tenantId)->where('id', $row['id'])->update([
            'state' => $scene === 'receipt' ? 'received' : 'decided', 'result_code' => $input['result'],
            'revision' => (int) $row['revision'] + 1, 'updated_at' => self::now(),
        ]);
        $receipt = ['effect_key' => $effectKey, 'object_key' => $input['subject_key'],
            'output' => ['result' => $input['result'], 'quantity' => $scene === 'receipt' ? (int) $row['quantity'] : 0]];
        $this->saveEffect($context, $effectKey, $hash, $actionKey, $input['subject_key'], $receipt);
        $this->record($context, 'fixture.workflow-business.effect-applied', $input['subject_key']);
        return $receipt;
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

    private function expect(array $row, int $expected): void
    {
        if ($expected < 1 || (int) $row['revision'] !== $expected) {
            throw AdminAccessException::revisionMismatch();
        }
    }

    private function record(AuthorizedOperationContext $basis, string $event, string $key): void
    {
        $this->audit->tenantMember($basis->tenantContext, $event, $basis->operation, 'fixture.workflow-business', $key);
    }

    private function project(array $row): array
    {
        foreach (['id', 'tenant_id', 'owner_member_id', 'department_id', 'revision', 'artifact_revision'] as $field) {
            if (isset($row[$field])) {
                $row[$field] = (string) $row[$field];
            }
        }
        foreach (['completion_form_json'] as $field) {
            if (isset($row[$field])) {
                $row['completion_form'] = json_decode((string) $row[$field], true, 32, JSON_THROW_ON_ERROR);
                unset($row[$field]);
            }
        }
        return $row;
    }

    private static function fields(array $input): array
    {
        if (array_diff(array_keys($input), ['title', 'amount_minor', 'sku', 'quantity', 'business_department_key']) !== []
            || !is_string($input['title'] ?? null) || trim($input['title']) === '' || mb_strlen($input['title']) > 120
            || !is_int($input['amount_minor'] ?? null) || $input['amount_minor'] < 0 || $input['amount_minor'] > 1000000000
            || !is_int($input['quantity'] ?? null) || $input['quantity'] < 1 || $input['quantity'] > 1000000
            || !is_string($input['sku'] ?? null) || preg_match('/^[A-Za-z0-9._-]{1,64}$/D', $input['sku']) !== 1
            || (isset($input['business_department_key']) && !is_string($input['business_department_key']))) {
            throw AdminAccessException::invalid('WORKFLOW_BUSINESS_FIELDS_INVALID', 'The registered business fields are invalid.');
        }
        return ['title' => trim($input['title']), 'amount_minor' => $input['amount_minor'], 'sku' => $input['sku'], 'quantity' => $input['quantity']];
    }

    private static function payload(array $row): array
    {
        $amountMinor = (int) $row['amount_minor'];
        $createdAt = (new \DateTimeImmutable((string) $row['created_at'], new \DateTimeZone('UTC')))
            ->setTimezone(new \DateTimeZone('UTC'));
        return ['title' => (string) $row['title'], 'amount_minor' => $amountMinor,
            'amount_decimal' => intdiv($amountMinor, 100) . '.' . str_pad((string) ($amountMinor % 100), 2, '0', STR_PAD_LEFT),
            'created_at_utc' => $createdAt->format('Y-m-d\TH:i:s.v\Z'),
            'business_department_key' => $row['business_department_key'] === null ? null : (string) $row['business_department_key'],
            'sku' => (string) $row['sku'], 'quantity' => (int) $row['quantity'], 'scene' => (string) $row['scene']];
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
