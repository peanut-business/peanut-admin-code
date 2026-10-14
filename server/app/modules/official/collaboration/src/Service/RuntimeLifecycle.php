<?php

declare(strict_types=1);

namespace PeanutAdmin\Modules\Collaboration\Service;

use app\common\execution\ExecutionContextStore;
use app\common\execution\InstanceExecutionContext;
use PeanutAdmin\Kernel\Module\CompiledModuleRegistry;
use PeanutAdmin\Kernel\Module\ModuleRuntimeRepository;
use PeanutAdmin\Modules\Collaboration\Model\CollaborationRuntimeState;
use PeanutAdmin\Modules\Collaboration\Model\CollaborationRoom;
use PeanutAdmin\Modules\Collaboration\Model\CollaborationAdmission;
use PeanutAdmin\Modules\Collaboration\Model\CollaborationConnection;
use PeanutAdmin\Modules\Collaboration\Model\CollaborationBarrier;
use PeanutAdmin\Modules\Collaboration\Model\CollaborationGrant;
use PeanutAdmin\Modules\Document\Service\DocumentAcceptedCommitQuery;
use PeanutAdmin\Modules\Document\Contract\DocumentCollaborativeWrites;
use PeanutAdmin\Modules\Document\Contract\DocumentOwnerReference;
use app\common\execution\SystemExecutionContext;
use PeanutAdmin\Kernel\Context\TenantSystemContext;
use PeanutAdmin\Modules\RichText\Contract\RichTextProjection;
use think\facade\Db;

/** Deployment lifecycle owns one instance gate, never an actor ACL or a second task system. */
final readonly class RuntimeLifecycle
{
    public function __construct(
        private ExecutionContextStore $execution,
        private ModuleRuntimeRepository $modules,
        private CompiledModuleRegistry $registry,
        private array $configuration,
        private string $edition,
        private DocumentCollaborativeWrites $documents,
        private BusinessControlClient $control,
        private DocumentAcceptedCommitQuery $commits,
    ) {}

    public function ownsRoom(CollaborationRoom $room): bool
    {
        return $room->instance_id === $this->configuration['instance_id'] && $room->edition === $this->edition;
    }

    public function roomLeaseExpiresAt(): string
    {
        return gmdate('Y-m-d H:i:s', time() + $this->configuration['room_lease_seconds']);
    }

    private function query(): \think\db\BaseQuery
    {
        return CollaborationRuntimeState::where('instance_id', $this->configuration['instance_id'])->where('edition', $this->edition);
    }
    private function bound(CollaborationRuntimeState $row): void
    {
        if ((int) $row->config_revision !== $this->configuration['config_revision']
            || !hash_equals($row->config_sha256, $this->configuration['config_sha256'])) {
            throw new \DomainException('COLLABORATION_CONFIGURATION_STALE');
        }
    }
    /** Serializes new admissions/preparations; instance phase changes never hold Document locks. */
    public function lockAdmission(?string $holder = null): void
    {
        $pdo = Db::connect()->getPdo();
        if (!$pdo instanceof \PDO || !$pdo->inTransaction()) {
            throw new \LogicException('COLLABORATION_GATE_TRANSACTION_REQUIRED');
        }
        $row = $this->query()->lock(true)->find();
        if ($row === null) {
            throw new \DomainException('COLLABORATION_RUNTIME_NOT_PREPARED');
        }
        $this->bound($row);
        if ($row->phase !== 'active') {
            throw new \DomainException('COLLABORATION_RUNTIME_DRAINING');
        }
        $plan = $this->plan($row);
        if (($plan['process_lease_expires_at_ms'] ?? 0) <= (int) floor(microtime(true) * 1000)
            || !isset($plan['activation']) || $holder !== null && ($plan['candidate']['holder_key'] ?? null) !== $holder) {
            throw new \DomainException('COLLABORATION_PROCESS_LEASE_UNCONFIRMED');
        }
    }
    /** Already lawful captures may finish during drain, exclusively under current process custody. */
    public function lockStoreCustody(string $holder): void
    {
        $pdo = Db::connect()->getPdo();
        if (!$pdo instanceof \PDO || !$pdo->inTransaction()) throw new \LogicException('COLLABORATION_GATE_TRANSACTION_REQUIRED');
        $row = $this->query()->lock(true)->find();
        if ($row === null) throw new \DomainException('COLLABORATION_RUNTIME_NOT_PREPARED');
        $this->bound($row);
        $plan = $this->plan($row);
        if (!in_array($row->phase, ['active','draining'], true) || ($plan['candidate']['holder_key'] ?? null) !== $holder
            || !isset($plan['activation'])) throw new \DomainException('COLLABORATION_STORE_CUSTODY_CHANGED');
    }
    public function readiness(): array
    {
        $c = $this->configuration;
        if (($c['enabled'] ?? false) !== true || ($c['config_revision'] ?? 0) < 1
            || preg_match('/^[a-f0-9]{64}$/D', $c['config_sha256'] ?? '') !== 1
            || strlen((string) ($c['secret'] ?? '')) < 32 || strlen((string) ($c['ticket_secret'] ?? '')) < 32
            || !is_file($c['ca_bundle_file'] ?? '') || is_link($c['ca_bundle_file'] ?? '') || !is_readable($c['ca_bundle_file'] ?? '')
            || preg_match('/^[a-f0-9]{64}$/D', $c['ca_sha256'] ?? '') !== 1 || !hash_equals($c['ca_sha256'], hash_file('sha256', $c['ca_bundle_file']))
            || strlen((string) config('peanut.storage_credential_master_key')) < 32) {
            throw new \DomainException('COLLABORATION_CONFIGURATION_INCOMPLETE');
        }
        foreach (['official.collaboration','official.document','official.file','official.rich-text'] as $key) {
            $installed = $this->modules->installation($key);
            $compiled = $this->registry->requireManifest($key);
            if ($installed === null || $installed->status !== 'active'
                || $installed->installedVersion !== $compiled->data['version'] || $installed->manifestDigest !== $compiled->digest) {
                throw new \DomainException('COLLABORATION_MODULE_SCHEMA_STALE');
            }
        }
        // Real same-instance durable schema queries, with fixed non-secret columns. No mock or nonce-only readiness.
        foreach (['document_state' => ['document_id','yjs_state_binary','state_sha256','state_seq'],
            'document_operation' => ['operation_id','phase','barrier_plan_json'],
            'file_reference' => ['tenant_id','file_key','owner_module_key','owner_type','owner_id','reference_digest'],
            'collaboration_room' => ['room_key','lease_epoch','protocol_version','holder_key','last_target_progress','last_receipt_json'],
            'collaboration_grant' => ['room_id','connection_id','holder_key','authorization_revision','issued_at_ms','expires_at_ms']] as $table => $columns) {
            Db::name($table)->field($columns)->limit(1)->select();
        }
        $row = $this->query()->find();
        if ($row === null) {
            throw new \DomainException('COLLABORATION_RUNTIME_NOT_PREPARED');
        }
        $this->bound($row);
        $plan = $this->plan($row);
        return ['protocol' => 'peanut.collaboration.lifecycle.v1','schema_version' => 1,'native_protocol' => 2,
            'instance_id' => $c['instance_id'],'edition' => $this->edition,'service_instance_id' => $c['service_instance_id'],
            'config_revision' => $c['config_revision'],'config_sha256' => $c['config_sha256'],
            'projector_version' => $c['projector_version'],'registry_revision' => $this->registry->revision,
            'durability_dependency' => 'mysql','file_ledger_ready' => true,
            'php_ready' => true,'node_ready' => false,'paired_ready' => false,
            'holder_key' => $plan['candidate']['holder_key'] ?? null,'probe_id' => $plan['candidate']['probe_id'] ?? null,
            'activation_id' => $plan['activation']['activation_id'] ?? null,'process_lease_expires_at_ms' => $plan['process_lease_expires_at_ms'] ?? 0,
            'drain_id' => $row->drain_id,'plan_sha256' => isset($plan['drain']) ? self::hash($plan['drain']) : null,
            'ready' => in_array($row->phase, ['prepared','active'], true),'admission_enabled' => $row->phase === 'active','phase' => $row->phase];
    }
    private function identity(): array
    {
        $c = $this->configuration;
        return ['instance_id' => $c['instance_id'],'edition' => $this->edition,'service_instance_id' => $c['service_instance_id'],
            'config_revision' => $c['config_revision'],'config_sha256' => $c['config_sha256'],'projector_version' => $c['projector_version']];
    }
    private function instance(callable $work, string $id): mixed
    {
        if (!$this->execution->isEmpty()) {
            throw new \DomainException('COLLABORATION_LIFECYCLE_AMBIENT_CONTEXT');
        }
        return $this->execution->run(new InstanceExecutionContext('collaboration.native-lifecycle', $id), $work);
    }
    private function plan(CollaborationRuntimeState $row): array
    {
        return $row->plan_json === null ? [] : NativeCollaborationProvider::json($row->plan_json);
    }
    private function summary(CollaborationRuntimeState $row): array
    {
        $plan = $this->plan($row);
        return $this->identity() + ['protocol' => 'peanut.collaboration.lifecycle.v1','schema_version' => 1,'native_protocol' => 2,
            'registry_revision' => $this->registry->revision,'durability_dependency' => 'mysql','file_ledger_ready' => isset($plan['candidate']),
            'php_ready' => isset($plan['candidate']) && ($plan['registry_revision'] ?? null) === $this->registry->revision,'node_ready' => false,'paired_ready' => false,
            'holder_key' => $plan['candidate']['holder_key'] ?? null,'probe_id' => $plan['candidate']['probe_id'] ?? null,
            'activation_id' => $plan['activation']['activation_id'] ?? null,'process_lease_expires_at_ms' => $plan['process_lease_expires_at_ms'] ?? 0,
            'phase' => $row->phase,'ready' => in_array($row->phase, ['prepared','active'], true),'admission_enabled' => $row->phase === 'active',
            'drain_id' => $row->drain_id,'plan_sha256' => isset($plan['drain']) ? self::hash($plan['drain']) : null];
    }
    /** Called only through the current signed service request, never browser identity. */
    public function signed(array $body, string $holder): array
    {
        if (!$this->execution->isEmpty() || preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/D', $holder) !== 1) {
            throw new \DomainException('COLLABORATION_LIFECYCLE_AUDIENCE_INVALID');
        }
        $stage = $body['stage'] ?? null;
        if ($stage === 'readiness' && $body === ['stage' => 'readiness']) {
            return $this->readiness();
        }
        $keys = array_keys($body); sort($keys);
        $expected = match ($stage) {
            'paired_probe' => ['config_revision','config_sha256','edition','full','instance_id','probe_id','projector_version','service_instance_id','stage'],
            'activation_state' => ['activation_id','probe_id','stage'],
            'drain_state' => ['drain_id','plan_sha256','probe_id','stage'],
            default => [],
        };
        if ($keys !== $expected || preg_match('/^[A-Za-z0-9_-]{1,128}$/D', $body['probe_id'] ?? '') !== 1) {
            throw new \DomainException('COLLABORATION_LIFECYCLE_INPUT_INVALID');
        }
        if ($stage === 'paired_probe') {
            foreach ($this->identity() as $key => $value) {
                if (($body[$key] ?? null) !== $value) throw new \DomainException('COLLABORATION_CONFIGURATION_STALE');
            }
            if (!is_bool($body['full'])) throw new \DomainException('COLLABORATION_LIFECYCLE_INPUT_INVALID');
            $observed = $this->query()->find();
            if ($observed === null) throw new \DomainException('COLLABORATION_RUNTIME_NOT_PREPARED');
            $observedPlan = $this->plan($observed);
            if ($body['full'] || ($observedPlan['registry_revision'] ?? null) !== $this->registry->revision
                || ($observedPlan['candidate']['holder_key'] ?? null) !== $holder) $this->readiness();
        }
        return $this->instance(fn(): array => Db::transaction(function () use ($body, $holder, $stage): array {
            $row = $this->query()->lock(true)->find();
            if ($row === null) throw new \DomainException('COLLABORATION_RUNTIME_NOT_PREPARED');
            $this->bound($row);
            $plan = $this->plan($row);
            $now = (int) floor(microtime(true) * 1000);
            if ($stage === 'paired_probe') {
                $candidate = $this->identity() + ['holder_key' => $holder,'probe_id' => $body['probe_id']];
                if (self::hash($plan['candidate'] ?? []) !== self::hash($candidate)) {
                    if (!in_array($row->phase, ['prepared','active'], true) || isset($plan['drain'])
                        || ($plan['process_lease_expires_at_ms'] ?? 0) > $now) throw new \DomainException('COLLABORATION_PROCESS_LEASE_ACTIVE');
                    $this->assertExpiredCustody();
                    $plan = ['candidate' => $candidate,'activation' => null];
                    $row->phase = 'prepared';
                }
                if (!in_array($row->phase, ['prepared','active','draining','durably_drained'], true)) throw new \DomainException('COLLABORATION_RUNTIME_STOPPED');
                $plan['process_lease_expires_at_ms'] = $now + $this->configuration['room_lease_seconds'] * 1000;
                $plan['registry_revision'] = $this->registry->revision;
                $row->save(['phase' => $row->phase,'plan_json' => json_encode($plan, JSON_THROW_ON_ERROR),'updated_at' => gmdate('Y-m-d H:i:s')]);
            } else {
                if (($plan['candidate']['holder_key'] ?? null) !== $holder || ($plan['candidate']['probe_id'] ?? null) !== $body['probe_id']) {
                    throw new \DomainException('CONTROL_HOLDER_CHANGED');
                }
                if ($stage === 'activation_state' && ($row->phase !== 'active' || ($plan['activation']['activation_id'] ?? null) !== ($body['activation_id'] ?? null))) {
                    throw new \DomainException('COLLABORATION_ACTIVATION_UNCONFIRMED');
                }
                if ($stage === 'drain_state') {
                    if ($row->phase !== 'draining' || $row->drain_id !== $body['drain_id'] || !isset($plan['drain']) || self::hash($plan['drain']) !== $body['plan_sha256']) {
                        throw new \DomainException('COLLABORATION_DRAIN_PLAN_CHANGED');
                    }
                    $plan['process_lease_expires_at_ms'] = $now + $this->configuration['room_lease_seconds'] * 1000;
                    $row->save(['plan_json' => json_encode($plan, JSON_THROW_ON_ERROR)]);
                }
            }
            return $this->summary($row);
        }), $body['probe_id']);
    }
    private function rooms(): array
    {
        $rooms = CollaborationRoom::withoutTenantScope()->where('instance_id', $this->configuration['instance_id'])->where('edition', $this->edition)
            ->where('protocol_version', 2)->order('document_id')->limit(10001)->select();
        if (count($rooms) > 10000) throw new \DomainException('COLLABORATION_INSTANCE_ROOM_LIMIT');
        return iterator_to_array($rooms);
    }
    private function assertExpiredCustody(): void
    {
        $rooms = $this->rooms();
        $now = (int) floor(microtime(true) * 1000);
        foreach ($rooms as $room) {
            if (strtotime($room->lease_expires_at . ' UTC') * 1000 > $now
                || CollaborationGrant::withoutTenantScope()->where('room_id', $room->id)->where('purpose', 'connection')->where('mode', 'write')
                    ->where('expires_at_ms', '>', $now)->find() !== null
                || CollaborationBarrier::withoutTenantScope()->where('room_id', $room->id)->whereNotNull('business_plan_json')->whereNull('released_at')->find() !== null
                || $this->commits->hasPendingRuntimeBusiness((int) $room->tenant_id, (int) $room->document_id)) {
                throw new \DomainException('COLLABORATION_CUSTODY_RECOVERY_BLOCKED');
            }
        }
    }
    public function native(string $action, string $id, ?array $stopProof = null, ?array $ownerContainer = null): array
    {
        if (PHP_SAPI !== 'cli' || !$this->execution->isEmpty() || preg_match('/^[A-Za-z0-9_-]{1,128}$/D', $id) !== 1) {
            throw new \DomainException('COLLABORATION_LIFECYCLE_OWNER_REQUIRED');
        }
        if (!in_array($action, ['status','prepare','activate','begin','drain','stopped'], true)) throw new \InvalidArgumentException('COLLABORATION_LIFECYCLE_ACTION_INVALID');
        if ($stopProof !== null && $action !== 'stopped' || $ownerContainer !== null && $action !== 'begin') throw new \InvalidArgumentException('COLLABORATION_LIFECYCLE_INPUT_INVALID');
        if (($this->configuration['enabled'] ?? false) !== true) throw new \DomainException('COLLABORATION_CONFIGURATION_INCOMPLETE');
        if ($action === 'prepare') {
            return $this->instance(fn(): array => Db::transaction(function (): array {
                $row = $this->query()->lock(true)->find();
                if ($row !== null && in_array($row->phase, ['active','draining','durably_drained'], true)) {
                    $this->bound($row);
                    if ($row->phase !== 'active') {
                        $plan = $this->plan($row);
                        return $this->summary($row) + ['plan' => $plan['drain'] ?? null,'targets_sha256' => isset($plan['drain']) ? self::hash($plan['drain']['rooms']) : null];
                    }
                    return ['phase' => 'active'];
                }
                $values = ['instance_id' => $this->configuration['instance_id'],'edition' => $this->edition,
                    'config_revision' => $this->configuration['config_revision'],'config_sha256' => $this->configuration['config_sha256'],
                    'phase' => 'prepared','drain_id' => null,'plan_json' => null,'receipt_json' => null,'updated_at' => gmdate('Y-m-d H:i:s')];
                if ($row === null) {
                    CollaborationRuntimeState::create($values);
                } else {
                    $row->save($values);
                }
                return ['native_protocol' => 2,'phase' => 'prepared','paired_ready' => false,'admission_enabled' => false];
            }), $id);
        }
        $row = $this->instance(fn() => $this->query()->find(), $id);
        if ($row === null) return ['native_protocol' => 2,'ready' => false,'paired_ready' => false,'phase' => 'unprepared','admission_enabled' => false];
        $this->bound($row);
        if ($action === 'stopped') return $this->stopped($id, $stopProof);
        $plan = $this->plan($row);
        if ($action === 'status' && in_array($row->phase, ['draining','durably_drained','stopped'], true)) {
            return $this->summary($row) + ['receipt' => $row->receipt_json === null ? null : NativeCollaborationProvider::json($row->receipt_json)];
        }
        if (!isset($plan['candidate'])) return $this->summary($row);
        $input = ['protocol' => 2,'action' => 'probe'] + $plan['candidate'] + ['activation_id' => $plan['activation']['activation_id'] ?? null];
        if ($action === 'status' || $action === 'activate') {
            $probe = $this->control->operator($input);
            if (($probe['paired_ready'] ?? null) !== true || ($probe['node_ready'] ?? null) !== true || ($probe['php_ready'] ?? null) !== true) {
                throw new \DomainException('COLLABORATION_PAIRED_NOT_READY');
            }
            if ($action === 'status') return $probe;
            $activation = $this->instance(fn(): array => Db::transaction(function () use ($id, $plan): array {
                $current = $this->query()->lock(true)->find();
                if ($current === null) throw new \DomainException('COLLABORATION_ACTIVATION_UNCONFIRMED');
                $this->bound($current);
                $actual = $this->plan($current);
                if (self::hash($actual['candidate'] ?? []) !== self::hash($plan['candidate']) || ($actual['process_lease_expires_at_ms'] ?? 0) <= (int) floor(microtime(true) * 1000)) {
                    throw new \DomainException('COLLABORATION_ACTIVATION_UNCONFIRMED');
                }
                if ($current->phase === 'active') {
                    if (($actual['activation']['activation_id'] ?? null) !== $id) throw new \DomainException('COLLABORATION_ORIGINAL_ACTIVATION_REQUIRED');
                    return $actual['activation'];
                }
                if ($current->phase !== 'prepared' || isset($actual['drain'])) throw new \DomainException('COLLABORATION_ACTIVATION_UNCONFIRMED');
                $activation = ['activation_id' => $id,'input_sha256' => self::hash($actual['candidate'] + ['activation_id' => $id])];
                $actual['activation'] = $activation;
                $current->save(['phase' => 'active','plan_json' => json_encode($actual, JSON_THROW_ON_ERROR),'updated_at' => gmdate('Y-m-d H:i:s')]);
                return $activation;
            }), $id);
            return $this->control->operator(array_replace($input, ['action' => 'activate','activation_id' => $activation['activation_id']]));
        }
        if ($action === 'begin') return $this->begin($id, $ownerContainer);
        if ($action === 'drain') return $this->finishDrain($id);
        throw new \DomainException('COLLABORATION_LIFECYCLE_ACTION_INVALID');
    }
    private function begin(string $id, ?array $ownerContainer): array
    {
        if ($ownerContainer === null) throw new \DomainException('COLLABORATION_OWNER_CONTAINER_REQUIRED');
        self::assertContainerIdentity($ownerContainer);
        return $this->instance(fn(): array => Db::transaction(function () use ($id, $ownerContainer): array {
            $row = $this->query()->lock(true)->find();
            if ($row === null) throw new \DomainException('COLLABORATION_RUNTIME_NOT_PREPARED');
            $this->bound($row);
            $plan = $this->plan($row);
            if (in_array($row->phase, ['draining','durably_drained','stopped'], true)) {
                if ($row->drain_id !== $id || !isset($plan['drain'])) throw new \DomainException('COLLABORATION_ORIGINAL_DRAIN_REQUIRED');
                if (self::ownerCanonical($plan['drain']['owner_container'] ?? []) !== self::ownerCanonical($ownerContainer)) {
                    throw new \DomainException('COLLABORATION_ORIGINAL_OWNER_CONTAINER_REQUIRED');
                }
                return $this->summary($row) + ['plan' => $plan['drain'],'targets_sha256' => self::hash($plan['drain']['rooms'])];
            }
            if ($row->phase !== 'active' || !isset($plan['candidate'], $plan['activation'])) throw new \DomainException('COLLABORATION_RUNTIME_NOT_ACTIVE');
            $rooms = [];
            foreach ($this->rooms() as $room) {
                if (CollaborationBarrier::withoutTenantScope()->where('room_id', $room->id)->whereNotNull('business_plan_json')->whereNull('released_at')->lock(true)->find() !== null) {
                    throw new \DomainException('COLLABORATION_BUSINESS_UNCONFIRMED');
                }
                $rooms[] = ['room_key' => $room->room_key,'document_id' => (int) $room->document_id,'lease_epoch' => (int) $room->lease_epoch,'holder_key' => $room->holder_key];
            }
            $plan['drain'] = $plan['candidate'] + ['activation_id' => $plan['activation']['activation_id'],'drain_id' => $id,
                'owner_container' => $ownerContainer,'rooms' => $rooms];
            $row->save(['phase' => 'draining','drain_id' => $id,'plan_json' => json_encode($plan, JSON_THROW_ON_ERROR),'receipt_json' => null,'updated_at' => gmdate('Y-m-d H:i:s')]);
            return $this->summary($row) + ['plan' => $plan['drain'],'targets_sha256' => self::hash($rooms)];
        }), $id);
    }
    private function finishDrain(string $id): array
    {
        $row = $this->instance(fn() => $this->query()->find(), $id);
        if ($row === null || $row->drain_id !== $id || !in_array($row->phase, ['draining','durably_drained','stopped'], true)) {
            throw new \DomainException('COLLABORATION_ORIGINAL_DRAIN_REQUIRED');
        }
        $this->bound($row);
        if ($row->receipt_json !== null) {
            $cached = NativeCollaborationProvider::json($row->receipt_json);
            $public = array_intersect_key($cached, array_flip(['protocol','drain_id','plan_sha256','targets_sha256','durably_drained','room_count']));
            return $this->summary($row) + ['receipt' => $public,'room_receipts' => $cached['room_receipts'] ?? []];
        }
        $plan = $this->plan($row)['drain'] ?? throw new \DomainException('COLLABORATION_ORIGINAL_DRAIN_REQUIRED');
        $hash = self::hash($plan);
        $reply = $this->control->operator(['protocol' => 2,'action' => 'drain_instance','plan_sha256' => $hash] + $plan);
        if (($reply['native_protocol'] ?? null) !== 2 || ($reply['native_drained'] ?? null) !== true || ($reply['drain_id'] ?? null) !== $id
            || ($reply['plan_sha256'] ?? null) !== $hash || !is_array($reply['room_receipts'] ?? null)
            || count($reply['room_receipts']) !== count($plan['rooms']) || ($reply['targets_sha256'] ?? null) !== self::hash($reply['room_receipts'])) {
            throw new \DomainException('COLLABORATION_INSTANCE_DRAIN_UNCONFIRMED');
        }
        $verified = [];
        foreach ($plan['rooms'] as $index => $room) {
            $observed = $reply['room_receipts'][$index] ?? [];
            foreach ($room as $key => $value) if (($observed[$key] ?? null) !== $value) throw new \DomainException('COLLABORATION_DRAIN_SCOPE_CHANGED');
            $binding = $this->instance(function () use ($room): array {
                $held = CollaborationRoom::withoutTenantScope()->where('instance_id', $this->configuration['instance_id'])->where('edition', $this->edition)
                    ->where('room_key', $room['room_key'])->find();
                if ($held === null) throw new \DomainException('COLLABORATION_DRAIN_SCOPE_CHANGED');
                return NativeCollaborationProvider::json($held->binding_json);
            }, $id);
            $verified[] = $this->execution->run(new SystemExecutionContext(new TenantSystemContext($binding['tenant_id'],
                'collaboration:' . $this->configuration['service_instance_id'], 'collaboration.native-instance-drain', $id)),
                fn(): array => Db::transaction(fn(): array => $this->verifyRoomDrain($room, $observed, $binding, $plan['holder_key'])));
        }
        $receipt = ['protocol' => 2,'drain_id' => $id,'plan_sha256' => $hash,'targets_sha256' => self::hash($verified),'durably_drained' => true,'room_count' => count($verified)];
        return $this->instance(fn(): array => Db::transaction(function () use ($id, $hash, $receipt, $verified): array {
            $current = $this->query()->lock(true)->find();
            if ($current === null || $current->phase !== 'draining' || $current->drain_id !== $id
                || self::hash($this->plan($current)['drain'] ?? []) !== $hash) throw new \DomainException('COLLABORATION_DRAIN_PLAN_CHANGED');
            $current->save(['phase' => 'durably_drained','receipt_json' => json_encode($receipt + ['room_receipts' => $verified], JSON_THROW_ON_ERROR),'updated_at' => gmdate('Y-m-d H:i:s')]);
            return $this->summary($current) + ['receipt' => $receipt,'room_receipts' => $verified];
        }), $id);
    }
    private function verifyRoomDrain(array $expected, array $observed, array $binding, string $holder): array
    {
        ['document' => $document, 'state' => $state] = $this->commits->collaborationBaseline($expected['document_id'], true);
        $pendingBusiness = $this->commits->hasPendingCollaborationBusiness($expected['document_id'], null, true);
        $room = CollaborationRoom::where('room_key', $expected['room_key'])->lock(true)->find();
        if ($room === null || $document === null || $state === null || !$this->ownsRoom($room) || (int) $room->protocol_version !== 2
            || (int) $room->lease_epoch !== $expected['lease_epoch'] || $room->holder_key !== $expected['holder_key']
            || (int) $document['lease_epoch'] !== $expected['lease_epoch'] || (int) $document['state_seq'] !== (int) $state['state_seq']
            || NativeCollaborationProvider::json($room->binding_json) !== $binding
            || $document['owner_module_key'] !== $binding['owner']['module_key'] || $document['owner_object_type'] !== $binding['owner']['object_type']
            || (string) $document['owner_object_id'] !== $binding['owner']['object_id'] || $document['slot'] !== $binding['owner']['slot']
            || (int) $room->persisted_state_seq !== (int) $state['state_seq'] || $room->baseline_sha256 !== $state['state_sha256']
            || CollaborationBarrier::where('room_id', $room->id)->whereNotNull('business_plan_json')->whereNull('released_at')->lock(true)->find() !== null
            || $pendingBusiness) {
            throw new \DomainException('COLLABORATION_DRAIN_BASELINE_INVALID');
        }
        $result = $expected + ['state_seq' => (int) $state['state_seq']];
        if (($observed['source'] ?? null) === 'offline' && $expected['holder_key'] !== $holder) {
            $now = (int) floor(microtime(true) * 1000);
            if (strtotime($room->lease_expires_at . ' UTC') * 1000 > $now || CollaborationGrant::where('room_id', $room->id)
                ->where('purpose', 'connection')->where('mode', 'write')->where('expires_at_ms', '>', $now)->lock(true)->find() !== null) {
                throw new \DomainException('COLLABORATION_OFFLINE_HOLDER_STILL_ACTIVE');
            }
        }
        if ($state['yjs_state_binary'] === null) {
            if (($observed['source'] ?? null) !== 'offline') throw new \DomainException('COLLABORATION_DRAIN_RECEIPT_MISSING');
            return $result + ['source' => 'persistent_uninitialized','store_id' => null,'binary_sha256' => null,'snapshot' => null,
                'state_sha256' => $state['state_sha256'],'projection_sha256' => self::hash(NativeCollaborationProvider::json($state['projected_json']))];
        }
        if (!is_string($state['yjs_state_binary']) || hash('sha256', $state['yjs_state_binary']) !== $state['state_sha256']) throw new \DomainException('COLLABORATION_DRAIN_BYTES_INVALID');
        $storeId = ($observed['source'] ?? null) === 'native' ? ($observed['store_id'] ?? null) : $room->last_store_id;
        $operation = is_string($storeId) ? $this->commits->collaborationStoreReceipt((int) $room->document_id, $storeId, true) : null;
        $commit = $operation === null ? null : NativeCollaborationProvider::json($operation['result_json']);
        if ($commit === null || !in_array($operation['action'], ['collaboration.store','collaboration.baseline'], true)
            || ($commit['document_id'] ?? null) !== $expected['document_id'] || ($commit['state_seq'] ?? null) !== (int) $state['state_seq']
            || ($commit['binary_sha256'] ?? null) !== $state['state_sha256'] || ($commit['lease_epoch'] ?? null) !== $expected['lease_epoch']) {
            throw new \DomainException('COLLABORATION_DRAIN_RECEIPT_MISSING');
        }
        if (($observed['source'] ?? null) === 'native') {
            $coverage = $observed['coverage'] ?? [];
            if ($expected['holder_key'] !== $holder || ($observed['state_seq'] ?? null) !== (int) $state['state_seq']
                || ($observed['binary_sha256'] ?? null) !== $state['state_sha256'] || ($observed['target_progress'] ?? null) !== ($commit['target_progress'] ?? null)
                || ($coverage['algorithm'] ?? null) !== 'yjs.snapshotContainsUpdate/13.6.32' || ($coverage['covers_target'] ?? null) !== true
                || preg_match('/^[a-f0-9]{64}$/D', $coverage['committed_snapshot_sha256'] ?? '') !== 1
                || preg_match('/^[a-f0-9]{64}$/D', $coverage['captured_snapshot_sha256'] ?? '') !== 1) throw new \DomainException('COLLABORATION_DRAIN_COVERAGE_INVALID');
            return $result + ['source' => 'native','store_id' => $storeId,'binary_sha256' => $state['state_sha256'],'coverage' => $coverage];
        }
        if (($observed['source'] ?? null) !== 'offline') throw new \DomainException('COLLABORATION_DRAIN_SOURCE_INVALID');
        return $result + ['source' => 'persistent_baseline','store_id' => $storeId,'binary_sha256' => $state['state_sha256'],'snapshot' => null];
    }
    /** Owner evidence canonical is UTF-8, unlike the established business tuple hash. */
    public static function ownerCanonical(mixed $value): string
    {
        $sort = static function (mixed $item) use (&$sort): mixed {
            if (is_float($item) || is_object($item) || is_resource($item)) throw new \DomainException('COLLABORATION_OWNER_PROOF_INVALID');
            if (is_array($item)) {
                if (!array_is_list($item)) ksort($item, SORT_STRING);
                foreach ($item as $key => $child) $item[$key] = $sort($child);
            }
            return $item;
        };
        return json_encode($sort($value), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_LINE_TERMINATORS);
    }
    public static function decodeOwnerInput(string $encoded): array
    {
        if (strlen($encoded) > 21848) throw new \DomainException('COLLABORATION_OWNER_PROOF_INVALID');
        $bytes = base64_decode($encoded, true);
        if (!is_string($bytes) || strlen($bytes) > 16384 || base64_encode($bytes) !== $encoded) throw new \DomainException('COLLABORATION_OWNER_PROOF_INVALID');
        $data = json_decode($bytes, true, 16, JSON_THROW_ON_ERROR);
        if (!is_array($data) || array_is_list($data) || self::ownerCanonical($data) !== $bytes) throw new \DomainException('COLLABORATION_OWNER_PROOF_INVALID');
        return $data;
    }
    private static function assertContainerIdentity(array $container): void
    {
        $keys = array_keys($container); sort($keys);
        $mount = $container['service_mount'] ?? null;
        $mountKeys = is_array($mount) ? array_keys($mount) : []; sort($mountKeys);
        $working = $container['compose_working_dir'] ?? null;
        $program = $container['program_mount'] ?? null;
        $programKeys = is_array($program) ? array_keys($program) : []; sort($programKeys);
        if ($keys !== ['compose_project','compose_service','compose_working_dir','id','image_id','program_mount','service_mount']
            || preg_match('/^[a-f0-9]{64}$/D', $container['id'] ?? '') !== 1
            || preg_match('/^sha256:[a-f0-9]{64}$/D', $container['image_id'] ?? '') !== 1
            || preg_match('/^[a-z0-9][a-z0-9_-]{0,62}$/D', $container['compose_project'] ?? '') !== 1
            || ($container['compose_service'] ?? null) !== 'collaboration'
            || !is_string($working) || strlen($working) > 4096 || !str_starts_with($working, '/') || !str_ends_with($working, '/docker')
            || str_contains($working, "\0") || str_contains($working, '//') || preg_match('~/(?:\.|\.\.)(?:/|$)~D', $working)
            || $mountKeys !== ['destination','read_only','source'] || ($mount['destination'] ?? null) !== '/run/peanut-collaboration'
            || ($mount['read_only'] ?? null) !== true || ($mount['source'] ?? null) !== substr($working, 0, -7) . '/private/collaboration/service'
            || $programKeys !== ['destination','read_only','source'] || ($program['destination'] ?? null) !== '/srv/peanut-hocuspocus'
            || ($program['read_only'] ?? null) !== true || ($program['source'] ?? null) !== substr($working, 0, -7)) {
            throw new \DomainException('COLLABORATION_OWNER_CONTAINER_INVALID');
        }
    }
    private function stopped(string $id, ?array $proof): array
    {
        if ($proof === null) throw new \DomainException('COLLABORATION_STOP_PROOF_REQUIRED');
        $keys = array_keys($proof); sort($keys);
        if ($keys !== ['config_revision','config_sha256','container','drain_id','edition','holder_key','instance_id','key_id','plan_sha256','proof_sha256','protocol','service_instance_id','signature','targets_sha256']) {
            throw new \DomainException('COLLABORATION_STOP_PROOF_INVALID');
        }
        foreach ($this->identity() as $key => $value) {
            if ($key !== 'projector_version' && ($proof[$key] ?? null) !== $value) throw new \DomainException('COLLABORATION_STOP_CONFIGURATION_STALE');
        }
        foreach (['plan_sha256','targets_sha256','proof_sha256','signature'] as $key) {
            if (!is_string($proof[$key]) || preg_match('/^[a-f0-9]{64}$/D', $proof[$key]) !== 1) throw new \DomainException('COLLABORATION_STOP_PROOF_INVALID');
        }
        $container = $proof['container'];
        if (!is_array($container) || ($container['state'] ?? null) !== 'exited' || ($container['running'] ?? null) !== false
            || ($proof['protocol'] ?? null) !== 2 || ($proof['drain_id'] ?? null) !== $id || ($proof['key_id'] ?? null) !== $this->configuration['key_id']
            || preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/D', $proof['holder_key'] ?? '') !== 1) {
            throw new \DomainException('COLLABORATION_STOP_PROOF_INVALID');
        }
        unset($container['state'], $container['running']);
        self::assertContainerIdentity($container);
        $unsigned = $proof; unset($unsigned['proof_sha256'], $unsigned['signature']);
        $hash = hash('sha256', self::ownerCanonical($unsigned));
        $canonical = implode("\n", ['peanut.collaboration.native.stop-proof.v2',$proof['instance_id'],$proof['service_instance_id'],$proof['key_id'],$proof['holder_key'],$hash]);
        if (!hash_equals($hash, $proof['proof_sha256']) || strlen($this->configuration['secret'] ?? '') < 32
            || !hash_equals(hash_hmac('sha256', $canonical, $this->configuration['secret']), $proof['signature'])) {
            throw new \DomainException('COLLABORATION_STOP_PROOF_AUTH_INVALID');
        }
        return $this->instance(fn(): array => Db::transaction(function () use ($id, $proof, $container, $hash): array {
            $row = $this->query()->lock(true)->find();
            if ($row === null) throw new \DomainException('COLLABORATION_STOP_UNCONFIRMED');
            $this->bound($row);
            $plan = $this->plan($row);
            $receipt = $row->receipt_json === null ? [] : NativeCollaborationProvider::json($row->receipt_json);
            if (!in_array($row->phase, ['durably_drained','stopped'], true) || $row->drain_id !== $id || ($receipt['durably_drained'] ?? null) !== true
                || ($plan['candidate']['holder_key'] ?? null) !== $proof['holder_key'] || self::hash($plan['drain'] ?? []) !== $proof['plan_sha256']
                || ($receipt['plan_sha256'] ?? null) !== $proof['plan_sha256'] || ($receipt['targets_sha256'] ?? null) !== $proof['targets_sha256']
                || self::ownerCanonical($plan['drain']['owner_container'] ?? []) !== self::ownerCanonical($container)
                || $row->phase === 'stopped' && ($receipt['stop_proof_sha256'] ?? null) !== $hash) {
                throw new \DomainException('COLLABORATION_ORIGINAL_STOP_PROOF_REQUIRED');
            }
            $receipt['stop_proof_sha256'] = $hash;
            $row->save(['phase' => 'stopped','receipt_json' => json_encode($receipt, JSON_THROW_ON_ERROR),'updated_at' => gmdate('Y-m-d H:i:s')]);
            return $this->summary($row) + ['stop_confirmed' => true,'receipt' => array_intersect_key($receipt,
                array_flip(['protocol','drain_id','plan_sha256','targets_sha256','durably_drained','room_count']))];
        }), $id);
    }
    public static function hash(array $value): string
    {
        return hash('sha256', json_encode(RichTextProjection::canonical($value), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
    }

    /** Disabled, stopped instance data cutover under the original bound configuration; never activation. */
    public function migrateNative(string $id): array
    {
        if (PHP_SAPI !== 'cli' || !$this->execution->isEmpty() || preg_match('/^[A-Za-z0-9_-]{1,128}$/D', $id) !== 1
            || ($this->configuration['enabled'] ?? null) !== false) {
            throw new \DomainException('COLLABORATION_TOP_LEVEL_OWNER_REQUIRED');
        }
        return Db::transaction(function () use ($id): array {
            $runtime = $this->query()->lock(true)->find();
            if ($runtime === null || $runtime->phase !== 'stopped' || $runtime->drain_id !== $id || $runtime->receipt_json === null
                || (NativeCollaborationProvider::json($runtime->receipt_json)['durably_drained'] ?? null) !== true) {
                throw new \DomainException('COLLABORATION_OLD_RUNTIME_NOT_STOPPED');
            }
            $this->bound($runtime);
            $rooms = CollaborationRoom::withoutTenantScope()->where('instance_id', $runtime->instance_id)->where('edition', $runtime->edition)
                ->where('protocol_version', 1)->order('document_id')->limit(10001)->select();
            if (count($rooms) > 10000) {
                throw new \DomainException('COLLABORATION_MIGRATION_ROOM_LIMIT');
            }
            $results = [];
            foreach ($rooms as $observed) {
                $binding = NativeCollaborationProvider::json($observed->binding_json);
                if (($binding['tenant_id'] ?? null) !== (int) $observed->tenant_id || ($binding['document_id'] ?? null) !== (int) $observed->document_id
                    || ($binding['instance_id'] ?? null) !== $runtime->instance_id || ($binding['edition'] ?? null) !== $runtime->edition) {
                    throw new \DomainException('COLLABORATION_MIGRATION_BINDING_INVALID');
                }
                $results[] = $this->execution->run(new SystemExecutionContext(new TenantSystemContext($binding['tenant_id'],
                    'collaboration:' . $this->configuration['service_instance_id'], 'collaboration.native-migration', $id)),
                    function () use ($observed, $binding, $id): array {
                        $owner = $binding['owner'];
                        $baseline = $this->documents->migrateCollaborationEpoch($binding['document_id'],
                            new DocumentOwnerReference($owner['module_key'], $owner['object_type'], $owner['object_id'], $owner['slot']),
                            (int) $observed->lease_epoch, 'native-migration:' . hash('sha256', $id . ':' . $observed->room_key));
                        $room = CollaborationRoom::where('id', $observed->id)->lock(true)->find();
                        if ($room === null || (int) $room->protocol_version !== 1 || $room->status !== 'closed'
                            || (int) $room->lease_epoch !== (int) $observed->lease_epoch || strtotime($room->lease_expires_at . ' UTC') >= time()
                            || (int) $room->accepted_through !== (int) $room->persisted_through || (int) $room->pending_update_count !== 0
                            || (int) $room->pending_journal_bytes !== 0
                            || CollaborationAdmission::where('room_id', $room->id)->whereNull('persisted_state_seq')->lock(true)->find() !== null
                            || CollaborationConnection::where('room_id', $room->id)->where('status', 'active')->lock(true)->find() !== null
                            || CollaborationBarrier::where('room_id', $room->id)->whereNotNull('business_plan_json')->whereNull('released_at')->lock(true)->find() !== null
                            || $baseline['state_seq'] !== (int) $room->persisted_state_seq || $baseline['state_sha256'] !== $room->baseline_sha256) {
                            throw new \DomainException('COLLABORATION_OLD_ROOM_NOT_DURABLY_STOPPED');
                        }
                        // Historical journals and their counters remain unchanged, never replayed or erased.
                        $room->save(['protocol_version' => 2,'lease_epoch' => $baseline['lease_epoch'],
                            'status' => $baseline['status'] === 'archived' ? 'closed' : 'active',
                            'service_instance_id' => $this->configuration['service_instance_id'],'holder_key' => null,
                            'last_store_id' => $baseline['store_id'],'last_store_sha256' => null,'last_receipt_json' => null,'last_target_progress' => 0]);
                        return ['room_key' => $room->room_key,'lease_epoch' => $baseline['lease_epoch'],'state_seq' => $baseline['state_seq'],
                            'binary_sha256' => $baseline['state_sha256'],'store_id' => $baseline['store_id']];
                    });
            }
            return ['protocol' => 2,'drain_id' => $id,'migrated' => count($results),'rooms' => $results];
        });
    }
}
