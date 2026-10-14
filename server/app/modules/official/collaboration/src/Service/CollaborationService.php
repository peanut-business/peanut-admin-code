<?php

declare(strict_types=1);

namespace PeanutAdmin\Modules\Collaboration\Service;

use app\common\contract\authorization\AdminAuthorizationQuery;
use app\common\exception\BusinessException;
use app\common\execution\AdminExecutionContext;
use app\common\execution\ExecutionContextStore;
use app\common\execution\SystemExecutionContext;
use app\common\http\ApiProblem;
use app\common\services\audit\AuditContractHost;
use PeanutAdmin\Kernel\Auth\TenantContext;
use PeanutAdmin\Kernel\Context\TenantSystemContext;
use PeanutAdmin\Modules\Collaboration\Model\CollaborationTicket;
use PeanutAdmin\Modules\Collaboration\Model\CollaborationRoom;
use PeanutAdmin\Modules\Collaboration\Model\CollaborationConnection;
use PeanutAdmin\Modules\Collaboration\Model\CollaborationGrant;
use PeanutAdmin\Modules\Collaboration\Model\CollaborationBarrier;
use PeanutAdmin\Modules\Collaboration\Protocol\TicketIdentity;
use PeanutAdmin\Modules\Document\Contract\DocumentCollaborativeWrites;
use PeanutAdmin\Modules\Document\Contract\DocumentOwnerReference;
use PeanutAdmin\Modules\Document\Contract\AcceptedStoreInput;
use PeanutAdmin\Modules\File\Contract\CollaborationReferenceInput;
use PeanutAdmin\Modules\Document\Contract\ExplicitTargetInput;
use PeanutAdmin\Modules\Document\Contract\ExplicitTargetPreparationInput;
use PeanutAdmin\Modules\File\Contract\FileReferenceCommands;
use PeanutAdmin\Modules\Identity\Contract\TenantSessionAccess;
use PeanutAdmin\Modules\Identity\Contract\TenantMemberDirectory;
use PeanutAdmin\Modules\RichText\Contract\RichTextProjection;
use think\facade\Db;

/** Real PHP protocol use cases. Service identity and the original current human remain separate. */
final readonly class CollaborationService implements \PeanutAdmin\Modules\Collaboration\Contract\CollaborationTickets
{
    public function __construct(
        private DocumentCollaborativeWrites $documents,
        private NativeCollaborationProvider $native,
        private FileReferenceCommands $references,
        private TenantSessionAccess $sessions,
        private TenantMemberDirectory $members,
        private AdminAuthorizationQuery $authorization,
        private ExecutionContextStore $execution,
        private ServiceAuthentication $authentication,
        private RuntimeLifecycle $lifecycle,
        private AuditContractHost $audit,
        private array $configuration,
        private string $edition,
    ) {}

    public function ticket(TenantContext $context, int $documentId, string $mode): array
    {
        return $this->issueTicket($context, $documentId, $mode, null);
    }

    public function ownerTicket(TenantContext $context, int $documentId, DocumentOwnerReference $owner): array
    {
        return $this->issueTicket($context, $documentId, 'write', $owner);
    }

    private function issueTicket(TenantContext $context, int $documentId, string $mode, ?DocumentOwnerReference $expectedOwner): array
    {
        $this->requireConfiguration();
        $publicWssUrl = $this->publicWssUrl();
        $ambient = $this->execution->current();
        if (!$ambient instanceof AdminExecutionContext || $ambient->tenant !== $context) {
            throw BusinessException::forbidden('COLLABORATION_HUMAN_SESSION_REQUIRED', '票据必须由真实当前登录会话签发');
        }
        return Db::transaction(function () use ($context, $documentId, $mode, $expectedOwner, $publicWssUrl): array {
            $this->lifecycle->lockAdmission();
            $doc = $this->documents->authorizeCollaboration($context, $documentId, $mode, $mode === 'write');
            if ($expectedOwner !== null && $doc['owner'] !== ['module_key' => $expectedOwner->moduleKey,'object_type' => $expectedOwner->objectType,'object_id' => $expectedOwner->objectId,'slot' => $expectedOwner->slot]) {
                throw BusinessException::forbidden('COLLABORATION_OWNER_MISMATCH', '协作正文业务归属不一致');
            }
            if (!$doc['collaboration_enabled']) {
                throw new ApiProblem('COLLAB_UNAVAILABLE', 503, '文档协作尚未初始化');
            }
            $now = time();
            $row = ['tenant_id' => $context->tenantId,'instance_id' => $this->configuration['instance_id'],'edition' => $this->edition,
                'document_id' => $documentId,'jti' => bin2hex(random_bytes(32)),'mode' => $mode,
                'issued_identity_json' => json_encode(TicketIdentity::fromCurrentSession($context)->toLedger(), JSON_THROW_ON_ERROR),
                'owner_reference_json' => json_encode($doc['owner'], JSON_THROW_ON_ERROR),
                'authorization_revision' => $doc['authorization_revision'],'document_auth_revision' => $doc['document_auth_revision'],
                'issued_state_seq' => $doc['state_seq'],'created_at' => self::date($now),
                'expires_at' => self::date($now + $this->configuration['ticket_seconds'])];
            $token = 'pa_ct_' . $row['jti'] . '.' . $this->ticketSignature($row);
            $row['ticket_sha256'] = hash('sha256', $token);
            CollaborationTicket::create($row);
            $this->audit->appendTenantMember($context, 'collaboration.ticket.issued', 'collaboration.ticket', 'document', (string) $documentId);
            $answer = ['ticket' => $token,'expires_at' => ($now + $this->configuration['ticket_seconds']) * 1000,
                'document_id' => $documentId,'mode' => $mode,'protocol' => 2,
                'wss_url' => $publicWssUrl, 'config_revision' => $this->configuration['config_revision'], 'config_sha256' => $this->configuration['config_sha256']];
            return $expectedOwner === null ? $answer : $answer + ['owner' => $doc['owner'],'authorization_revision' => $doc['authorization_revision'],
                'document_name' => implode(':', [$this->configuration['instance_id'],$this->edition,$context->tenantId,$documentId])];
        });
    }

    public function authorize(): array
    {
        $body = $this->input(['ticket']);
        $ticket = $this->findTicket(self::text($body, 'ticket'), true);
        $identity = TicketIdentity::fromLedger(NativeCollaborationProvider::json($ticket->issued_identity_json));
        return $this->asActor($identity, function (TenantContext $actor) use ($ticket): array {
            return Db::transaction(function () use ($actor, $ticket): array {
                $this->lifecycle->lockAdmission($this->authentication->current()->holderKey);
                $doc = $this->documents->authorizeCollaboration($actor, (int) $ticket->document_id, $ticket->mode);
                $this->sameTicketDocument($ticket, $doc);
                $request = $this->authentication->current();
                $binding = ['instance_id' => $ticket->instance_id,'edition' => $ticket->edition,'tenant_id' => $actor->tenantId,
                    'document_id' => (int) $ticket->document_id,'owner' => $doc['owner']];
                $room = CollaborationRoom::where('document_id', $ticket->document_id)->where('instance_id', $ticket->instance_id)
                    ->where('edition', $ticket->edition)->lock(true)->find();
                if ($room === null) {
                    $room = CollaborationRoom::create(['tenant_id' => $actor->tenantId,'document_id' => $ticket->document_id,
                        'instance_id' => $ticket->instance_id,'edition' => $ticket->edition,'room_key' => bin2hex(random_bytes(32)),
                        'binding_json' => json_encode($binding, JSON_THROW_ON_ERROR),'service_instance_id' => $request->serviceInstanceId,
                        'holder_key' => $request->holderKey,
                        'protocol_version' => 2,'lease_epoch' => $doc['lease_epoch'],'lease_expires_at' => self::date(time() + $this->configuration['room_lease_seconds']),
                        'status' => 'active','persisted_state_seq' => $doc['state_seq'],'baseline_sha256' => $doc['state_sha256'],
                        'created_at' => self::date(),'updated_at' => self::date()]);
                }
                if ((int) $room->protocol_version !== 2) {
                    throw BusinessException::conflict('COLLABORATION_PROTOCOL_MIGRATION_REQUIRED', '旧房间必须经明确排空及停止后迁入原生协议');
                }
                if ((int) $room->tenant_id !== $actor->tenantId || !$this->lifecycle->ownsRoom($room)
                    || RichTextProjection::canonical(NativeCollaborationProvider::json($room->binding_json)) !== RichTextProjection::canonical($binding)
                    || (int) $room->lease_epoch !== $doc['lease_epoch'] || $room->status !== 'active') {
                    throw BusinessException::conflict('RECOVERY_REQUIRED', '房间正在排空或恢复');
                }
                if ($room->service_instance_id !== $request->serviceInstanceId || $room->holder_key !== $request->holderKey
                    || strtotime($room->lease_expires_at . ' UTC') <= time()) {
                    // Signed details are emitted only after the original ticket and current full actor permission pass.
                    // This transaction has not consumed the ticket; one explicit recovery may reauthorize it.
                    throw new ApiProblem('RECOVERY_REQUIRED', 409, '房间需要显式恢复',
                        ['room_key' => $room->room_key,'lease_epoch' => (int) $room->lease_epoch,'binding' => $binding]);
                }
                $lockedTicket = CollaborationTicket::where('id', $ticket->id)->lock(true)->find();
                if ($lockedTicket === null || $lockedTicket->consumed_at !== null || $lockedTicket->revoked_at !== null
                    || strtotime($lockedTicket->expires_at . ' UTC') <= time()) {
                    throw BusinessException::forbidden('COLLABORATION_TICKET_REPLAY', '票据已使用或过期');
                }
                $lockedTicket->save(['consumed_at' => self::date()]);
                $connection = CollaborationConnection::create(['tenant_id' => $actor->tenantId,'connection_key' => bin2hex(random_bytes(32)),
                    'ticket_id' => $ticket->id,'room_id' => $room->id,'lease_epoch' => $room->lease_epoch,'mode' => $ticket->mode,
                    'status' => 'active','authorization_revision' => $doc['authorization_revision'],
                    'lease_expires_at' => self::date(time() + $this->configuration['connection_lease_seconds']),'created_at' => self::date()]);
                // Joining a nearly expired Room must not issue a grant beyond its holder lease.
                $this->renewConnectionLease($connection, $room, $doc['authorization_revision']);
                $this->recordGrant($connection, $room, $ticket);
                return $this->connectionResult($connection, $room, $actor, $doc);
            });
        });
    }

    public function check(): array
    {
        $body = $this->input(['connection_key','mode']);
        [$connection, $ticket] = $this->connectionTicket(self::text($body, 'connection_key'));
        $mode = self::text($body, 'mode');
        if (!in_array($mode, ['read','write'], true) || $mode === 'write' && $connection->mode !== 'write') {
            throw BusinessException::forbidden('COLLABORATION_CONNECTION_READ_ONLY', '连接不允许写入');
        }
        try {
            return $this->asActor(
                TicketIdentity::fromLedger(NativeCollaborationProvider::json($ticket->issued_identity_json)),
                function (TenantContext $actor) use ($connection, $ticket, $mode): array {
                    return Db::transaction(function () use ($actor, $connection, $ticket, $mode): array {
                        $this->lifecycle->lockAdmission($this->authentication->current()->holderKey);
                        // A read presence check cannot renew a cached write grant without current edit permission.
                        $doc = $this->documents->authorizeCollaboration($actor, (int) $ticket->document_id, $connection->mode === 'write' ? 'write' : $mode);
                        $this->sameTicketDocument($ticket, $doc);
                        $roomKey = CollaborationRoom::where('id', $connection->room_id)->value('room_key');
                        $room = $this->native->lockedRoom($roomKey, (int) $connection->lease_epoch);
                        $current = $this->lockedConnection($connection->connection_key, $room);
                        if ($mode === 'write' && $room->status !== 'active') {
                            throw BusinessException::conflict('COLLABORATION_ROOM_READ_ONLY', '房间当前禁止新写入');
                        }
                        $this->renewConnectionLease($current, $room, $doc['authorization_revision']);
                        $this->recordGrant($current, $room, $ticket);
                        return $this->connectionResult($current, $room, $actor, $doc);
                    });
                },
            );
        } catch (BusinessException|\PeanutAdmin\Modules\Identity\Contract\TenantSessionAccessException $error) {
            if ($error instanceof BusinessException && $error->httpStatus !== 403) {
                throw $error;
            }
            // Rejection is returned after a separate owned-ledger transaction persists revocation.
            $this->markConnectionRevoked($connection->connection_key);
            throw $error;
        }
    }

    public function load(): array
    {
        $body = $this->input(['room_key','lease_epoch','connection_key']);
        [$connection,$ticket] = $this->connectionTicket(self::text($body, 'connection_key'));
        return $this->asActor(TicketIdentity::fromLedger(NativeCollaborationProvider::json($ticket->issued_identity_json)),
            function (TenantContext $actor) use ($body, $connection, $ticket): array {
                return Db::transaction(function () use ($actor, $body, $connection, $ticket): array {
                    $doc = $this->documents->authorizeCollaboration($actor, (int) $ticket->document_id, 'read');
                    $this->sameTicketDocument($ticket, $doc);
                    $data = $this->documents->loadAuthorizedCollaboration($actor, (int) $ticket->document_id,
                        self::owner(NativeCollaborationProvider::json($ticket->owner_reference_json)));
                    $room = $this->native->lockedRoom(self::text($body, 'room_key'), self::positive($body, 'lease_epoch'));
                    $this->lockedConnection($connection->connection_key, $room);
                    if ($room->status !== 'active') {
                        throw BusinessException::conflict('RECOVERY_REQUIRED', '房间正在排空或恢复');
                    }
                    return $data + ['binding' => $this->native->roomBinding($room->room_key),'holder_key' => $room->holder_key];
                });
            });
    }

    public function store(): array
    {
        $input = new AcceptedStoreInput($this->authentication->payload());
        return $this->asRoom($input->tuple['room_key'], fn(array $binding): array =>
            Db::transaction(function () use ($input): array {
                $this->lifecycle->lockStoreCustody($this->authentication->current()->holderKey);
                return $this->documents->commitAccepted($input)->data;
            }));
    }

    public function receipt(): array
    {
        $body = $this->input(['room_key','lease_epoch','store_id','capture_sha256']);
        return $this->asRoom(self::text($body, 'room_key'), function (array $binding) use ($body): array {
            return Db::transaction(function () use ($binding, $body): array {
                $receipt = $this->documents->collaborationReceipt($binding['document_id'], self::owner($binding['owner']),
                    self::key($body, 'store_id'), self::digest($body, 'capture_sha256'));
                return $receipt === null ? ['found' => false] : ['found' => true,'receipt' => $receipt->data];
            });
        });
    }

    public function references(): array
    {
        $body = $this->input(['connection_key','room_key','lease_epoch','file_keys','request_id']);
        $keys = self::array($body, 'file_keys');
        if (!array_is_list($keys) || count($keys) > 1000
            || count(array_filter($keys, static fn($key): bool => !is_string($key) || preg_match('/^file_[0-9a-f]{32}$/D', $key) !== 1)) > 0
            || count(array_unique($keys)) !== count($keys)) {
            throw BusinessException::invalid('COLLABORATION_REFERENCE_KEYS_INVALID', '资源引用无效');
        }
        sort($keys);
        $requestId = self::key($body, 'request_id');
        [$connection,$ticket] = $this->connectionTicket(self::text($body, 'connection_key'));
        return $this->asActor(TicketIdentity::fromLedger(NativeCollaborationProvider::json($ticket->issued_identity_json)),
            function (TenantContext $actor) use ($body, $connection, $ticket, $keys, $requestId): array {
                return Db::transaction(function () use ($actor, $body, $connection, $ticket, $keys, $requestId): array {
                    $this->lifecycle->lockAdmission($this->authentication->current()->holderKey);
                    $doc = $this->documents->authorizeCollaboration($actor, (int) $ticket->document_id, 'write');
                    $this->sameTicketDocument($ticket, $doc);
                    $room = $this->native->lockedRoom(self::text($body, 'room_key'), self::positive($body, 'lease_epoch'));
                    $current = $this->lockedConnection($connection->connection_key, $room);
                    if ($current->mode !== 'write' || $room->status !== 'active') {
                        throw BusinessException::forbidden('COLLABORATION_REFERENCE_READ_ONLY', '当前连接禁止新资源引用');
                    }
                    $grantKey = hash('sha256', $room->room_key . ':' . $current->connection_key . ':' . $requestId);
                    $old = CollaborationGrant::where('grant_key', $grantKey)->lock(true)->find();
                    if ($old !== null && ($old->input_sha256 !== self::hash($body) || $old->authorization_revision !== $doc['authorization_revision'])) {
                        throw BusinessException::conflict('COLLABORATION_REFERENCE_IDEMPOTENCY_CONFLICT', '资源请求编号不能改变');
                    }
                    $now = (int) floor(microtime(true) * 1000);
                    $reservationKey = hash('sha256', 'references:' . $grantKey);
                    if ($old === null) {
                        $this->assertGrantCapacity($room);
                        CollaborationGrant::create(['tenant_id' => $actor->tenantId,'document_id' => $doc['document_id'],
                            'room_id' => $room->id,'connection_id' => $current->id,'ticket_id' => $ticket->id,
                            'grant_key' => $grantKey,'purpose' => 'references','mode' => 'write',
                            'service_instance_id' => $this->authentication->current()->serviceInstanceId,
                            'holder_key' => $this->authentication->current()->holderKey,'lease_epoch' => $room->lease_epoch,
                            'authorization_revision' => $doc['authorization_revision'],
                            'identity_sha256' => self::hash(NativeCollaborationProvider::json($ticket->issued_identity_json)),
                            'issued_at_ms' => $now,'expires_at_ms' => max($now + 1, strtotime($current->lease_expires_at . ' UTC') * 1000),
                            'input_sha256' => self::hash($body),'file_keys_json' => json_encode($keys, JSON_THROW_ON_ERROR),
                            'reservation_key' => $reservationKey,'created_at' => self::date()]);
                        $this->references->reserveCollaborationReferences($actor, new CollaborationReferenceInput(
                            $actor->tenantId, $doc['document_id'], $reservationKey, $keys, $grantKey));
                    } else {
                        $now = (int) $old->issued_at_ms;
                    }
                    return ['document_id' => $doc['document_id'],'room_key' => $room->room_key,'lease_epoch' => (int) $room->lease_epoch,
                        'file_keys' => $keys,'authorization_revision' => $doc['authorization_revision'],'authorized_at_ms' => $now];
                });
            });
    }

    public function prepareTarget(): array
    {
        $input = new ExplicitTargetPreparationInput($this->authentication->payload());
        return $this->targetActor($input->tuple['connection_key'], fn(TenantContext $actor, CollaborationTicket $ticket): array =>
            $this->documents->prepareExplicitTarget($actor, (int) $ticket->document_id,
                self::owner(NativeCollaborationProvider::json($ticket->owner_reference_json)), $input));
    }

    public function target(): array
    {
        $input = new ExplicitTargetInput($this->authentication->payload());
        return $this->targetActor($input->tuple['connection_key'], fn(TenantContext $actor, CollaborationTicket $ticket): array =>
            $this->documents->registerExplicitTarget($actor, (int) $ticket->document_id,
                self::owner(NativeCollaborationProvider::json($ticket->owner_reference_json)), $input));
    }

    private function targetActor(string $key, callable $operation): array
    {
        [$connection,$ticket] = $this->connectionTicket($key);
        return $this->asActor(TicketIdentity::fromLedger(NativeCollaborationProvider::json($ticket->issued_identity_json)),
            fn(TenantContext $actor): array => Db::transaction(function () use ($actor, $ticket, $operation): array {
                $this->lifecycle->lockAdmission($this->authentication->current()->holderKey);
                return $operation($actor, $ticket);
            }));
    }

    public function lifecycle(): array
    {
        $body = $this->authentication->payload();
        return $this->lifecycle->signed($body, $this->authentication->current()->holderKey);
    }

    public function recovery(): array
    {
        $body = $this->input(['protocol','room_key','lease_epoch','request_id']);
        if (($body['protocol'] ?? null) !== 2) {
            throw BusinessException::invalid('COLLABORATION_RECOVERY_PROTOCOL_INVALID', '恢复需要原生协议');
        }
        $requestId = self::key($body, 'request_id');
        $binding = $this->native->recoveryBinding(self::text($body, 'room_key'));
        $request = $this->authentication->current();
        if ($this->execution->current() !== null) {
            throw BusinessException::forbidden('COLLABORATION_INTERNAL_AUDIENCE_INVALID', '恢复不能覆盖现有身份');
        }
        return $this->execution->run(new SystemExecutionContext(new TenantSystemContext($binding['tenant_id'],
            'collaboration:' . $request->serviceInstanceId, 'collaboration.native-recovery', $request->nonce)),
            function () use ($body, $binding, $requestId, $request): array {
                return Db::transaction(function () use ($body, $binding, $requestId, $request): array {
                    $this->lifecycle->lockAdmission($this->authentication->current()->holderKey);
                    $epoch = self::positive($body, 'lease_epoch');
                    $baseline = $this->documents->recoverCollaborationEpoch($binding['document_id'], self::owner($binding['owner']), $epoch, $requestId);
                    $room = CollaborationRoom::where('room_key', $body['room_key'])->lock(true)->find();
                    if ($room !== null && (int) $room->lease_epoch === $epoch + 1 && $room->service_instance_id === $request->serviceInstanceId
                        && $room->holder_key === $request->holderKey) {
                        return ['protocol' => 2,'holder_key' => $request->holderKey,'request_id' => $requestId,'room_key' => $room->room_key,'lease_epoch' => $epoch + 1,
                            'binding' => $binding,'baseline' => $baseline];
                    }
                    $now = (int) floor(microtime(true) * 1000);
                    if ($room === null || (int) $room->protocol_version !== 2 || (int) $room->lease_epoch !== $epoch
                        || !($baseline['status'] === 'archived' ? $room->status === 'closed' : in_array($room->status, ['active','recovery'], true))
                        || strtotime($room->lease_expires_at . ' UTC') * 1000 > $now
                        || CollaborationGrant::where('room_id', $room->id)->where('lease_epoch', $epoch)
                            ->where('purpose', 'connection')->where('mode', 'write')->where('expires_at_ms', '>', $now)->lock(true)->find() !== null
                        || CollaborationBarrier::where('room_id', $room->id)->whereNotNull('business_plan_json')->whereNull('released_at')->lock(true)->find() !== null
                        || (int) $room->persisted_state_seq !== $baseline['state_seq'] || $room->baseline_sha256 !== $baseline['state_sha256']) {
                        throw BusinessException::conflict('COLLABORATION_RECOVERY_NOT_FENCED', '旧房间或授权尚有效，不能恢复抢占或解锁未知业务');
                    }
                    $room->save(['lease_epoch' => $epoch + 1,'service_instance_id' => $request->serviceInstanceId,
                        'holder_key' => $request->holderKey,'status' => $baseline['status'] === 'archived' ? 'closed' : 'active',
                        'lease_expires_at' => $this->lifecycle->roomLeaseExpiresAt(),'last_store_id' => $baseline['store_id'],
                        'last_store_sha256' => null,'last_receipt_json' => null,'last_target_progress' => 0,'updated_at' => self::date()]);
                    CollaborationConnection::where('room_id', $room->id)->where('status', 'active')->update(['status' => 'closed']);
                    $this->documents->releaseFencedCollaborationReferences($binding['document_id'], self::owner($binding['owner']), $epoch);
                    $this->audit->appendTenantSystem($binding['tenant_id'], 'collaboration.room.recovered', 'collaboration.recovery',
                        $requestId, ['document_id' => $binding['document_id'],'lease_epoch' => $epoch + 1,'state_seq' => $baseline['state_seq']]);
                    return ['protocol' => 2,'holder_key' => $request->holderKey,'request_id' => $requestId,'room_key' => $room->room_key,'lease_epoch' => $epoch + 1,
                        'binding' => $binding,'baseline' => $baseline];
                });
            });
    }

    private function assertGrantCapacity(CollaborationRoom $room): void
    {
        // Unknown/pending proof is never removed by a TTL. Stop new grants instead of losing custody evidence.
        if (CollaborationGrant::where('room_id', $room->id)->where('lease_epoch', $room->lease_epoch)->count() >= 10000) {
            throw BusinessException::conflict('COLLABORATION_GRANT_BACKPRESSURE', '授权证明待提交过多，须先保存或显式排空');
        }
    }

    private function recordGrant(CollaborationConnection $connection, CollaborationRoom $room, CollaborationTicket $ticket): void
    {
        $this->assertGrantCapacity($room);
        $now = (int) floor(microtime(true) * 1000);
        $expires = strtotime($connection->lease_expires_at . ' UTC') * 1000;
        if ($expires <= $now) {
            throw BusinessException::forbidden('COLLABORATION_CONNECTION_LEASE_STALE', '授权窗口已失效');
        }
        CollaborationGrant::create(['tenant_id' => $room->tenant_id,'document_id' => $room->document_id,'room_id' => $room->id,
            'connection_id' => $connection->id,'ticket_id' => $ticket->id,'grant_key' => bin2hex(random_bytes(32)),
            'purpose' => 'connection','mode' => $connection->mode,'service_instance_id' => $room->service_instance_id,'holder_key' => $room->holder_key,
            'lease_epoch' => $room->lease_epoch,'authorization_revision' => $connection->authorization_revision,
            'identity_sha256' => self::hash(NativeCollaborationProvider::json($ticket->issued_identity_json)),
            'issued_at_ms' => $now,'expires_at_ms' => $expires,'created_at' => self::date()]);
    }

    public function revoke(): array
    {
        $body = $this->input(['connection_key']);
        $this->markConnectionRevoked(self::text($body, 'connection_key'));
        return ['revoked' => true];
    }

    private function markConnectionRevoked(string $key): void
    {
        [$connection] = $this->connectionTicket($key, false);
        $roomKey = CollaborationRoom::withoutTenantScope()->where('id', $connection->room_id)->value('room_key');
        $this->asRoom($roomKey, function (array $binding) use ($key): void {
            Db::transaction(function () use ($key, $binding): void {
                $room = CollaborationRoom::where('room_key', $this->authenticationRoomKey($key))->lock(true)->find();
                $connection = CollaborationConnection::where('connection_key', $key)->where('room_id', $room->id)->lock(true)->find();
                if ($connection === null) {
                    throw BusinessException::notFound('COLLABORATION_CONNECTION_NOT_FOUND', '连接不存在');
                }
                $connection->save(['status' => 'revoked','revoked_at' => self::date()]);
                $this->audit->appendTenantSystem(
                    $binding['tenant_id'],
                    'collaboration.connection.revoked',
                    'collaboration.revoke',
                    $this->authentication->current()->nonce,
                    ['document_id' => $binding['document_id'],'connection_key' => $key],
                );
            });
        });
    }

    private function authenticationRoomKey(string $connectionKey): string
    {
        $connection = CollaborationConnection::where('connection_key', $connectionKey)->find();
        return (string) CollaborationRoom::where('id', $connection->room_id)->value('room_key');
    }

    private function asActor(TicketIdentity $identity, callable $operation): mixed
    {
        $request = $this->authentication->current();
        if ($this->execution->current() !== null) {
            throw BusinessException::forbidden('COLLABORATION_INTERNAL_AUDIENCE_INVALID', '内部请求不能继承现有身份');
        }
        try {
            $actor = $identity->revalidate($this->sessions, $request->nonce);
        } catch (\PeanutAdmin\Modules\Identity\Contract\TenantSessionAccessException) {
            throw BusinessException::forbidden('STALE_AUTH_REVISION', '原会话已失效');
        }
        if ($actor->authorizationRevision !== $identity->authorizationRevision) {
            throw BusinessException::forbidden('STALE_AUTH_REVISION', '原会话授权修订已改变');
        }
        $principal = $this->authorization->principal($actor);
        return $this->execution->run(new AdminExecutionContext($actor, 'collaboration.current-human', $principal->toArray()), fn() => $operation($actor));
    }

    private function asRoom(string $roomKey, callable $operation): mixed
    {
        $request = $this->authentication->current();
        if ($this->execution->current() !== null) {
            throw BusinessException::forbidden('COLLABORATION_INTERNAL_AUDIENCE_INVALID', '服务不能覆盖现有执行身份');
        }
        $binding = $this->native->roomBinding($roomKey);
        return $this->execution->run(new SystemExecutionContext(new TenantSystemContext(
            $binding['tenant_id'],
            'collaboration:' . $request->serviceInstanceId,
            'collaboration.accepted-runtime',
            $request->nonce,
        )), fn() => $operation($binding));
    }

    private function findTicket(string $token, bool $requireUnused): CollaborationTicket
    {
        if (preg_match('/^pa_ct_[0-9a-f]{64}\.[0-9a-f]{64}$/D', $token) !== 1) {
            throw BusinessException::forbidden('COLLABORATION_TICKET_INVALID', '协作票据无效');
        }
        $ticket = CollaborationTicket::withoutTenantScope()->where('ticket_sha256', hash('sha256', $token))->find();
        if ($ticket === null || $ticket->instance_id !== $this->configuration['instance_id'] || $ticket->edition !== $this->edition
            || $ticket->revoked_at !== null || $requireUnused && ($ticket->consumed_at !== null || strtotime($ticket->expires_at . ' UTC') <= time())
            || !hash_equals('pa_ct_' . $ticket->jti . '.' . $this->ticketSignature($ticket->toArray()), $token)) {
            throw BusinessException::forbidden('COLLABORATION_TICKET_INVALID', '协作票据无效');
        }
        return $ticket;
    }

    private function connectionTicket(string $key, bool $active = true): array
    {
        $this->authentication->current();
        if (preg_match('/^[0-9a-f]{64}$/D', $key) !== 1) {
            throw BusinessException::invalid('COLLABORATION_CONNECTION_KEY_INVALID', '连接编号无效');
        }
        $connection = CollaborationConnection::withoutTenantScope()->where('connection_key', $key)->find();
        $ticket = $connection === null ? null : CollaborationTicket::withoutTenantScope()->where('id', $connection->ticket_id)->find();
        if ($connection === null || $ticket === null || $ticket->instance_id !== $this->configuration['instance_id']
            || $ticket->edition !== $this->edition || $active && ($connection->status !== 'active' || $ticket->revoked_at !== null)) {
            throw BusinessException::forbidden('COLLABORATION_CONNECTION_DENIED', '协作连接不可用');
        }
        return [$connection,$ticket];
    }

    private function lockedConnection(string $key, CollaborationRoom $room): CollaborationConnection
    {
        $connection = CollaborationConnection::where('connection_key', $key)->where('room_id', $room->id)->lock(true)->find();
        if ($connection === null || $connection->status !== 'active' || (int) $connection->lease_epoch !== (int) $room->lease_epoch
            || strtotime($connection->lease_expires_at . ' UTC') <= $this->authentication->receivedAt()
            || strtotime($room->lease_expires_at . ' UTC') <= $this->authentication->receivedAt()) {
            throw BusinessException::forbidden('COLLABORATION_CONNECTION_LEASE_STALE', '连接租约已失效');
        }
        return $connection;
    }

    private function renewConnectionLease(CollaborationConnection $connection, CollaborationRoom $room, string $authorizationRevision): void
    {
        $now = time();
        $connection->save(['authorization_revision' => $authorizationRevision,
            'lease_expires_at' => self::date($now + $this->configuration['connection_lease_seconds'])]);
        $room->save(['lease_expires_at' => self::date($now + $this->configuration['room_lease_seconds']),
            'updated_at' => self::date($now)]);
    }

    private function sameTicketDocument(CollaborationTicket $ticket, array $doc): void
    {
        if ((int) $ticket->document_auth_revision !== $doc['document_auth_revision']
            || RichTextProjection::canonical(NativeCollaborationProvider::json($ticket->owner_reference_json)) !== RichTextProjection::canonical($doc['owner'])) {
            throw BusinessException::forbidden('STALE_AUTH_REVISION', '票据文档归属或授权已改变');
        }
    }

    private function ticketSignature(array $row): string
    {
        return hash_hmac('sha256', json_encode(RichTextProjection::canonical(['aud' => 'peanut.collaboration.browser.v2',
            'iss' => $row['instance_id'],'instance_id' => $row['instance_id'],'edition' => $row['edition'],
            'document_id' => (int) $row['document_id'],'jti' => $row['jti'],'mode' => $row['mode'],
            'identity' => NativeCollaborationProvider::json($row['issued_identity_json']),
            'owner' => NativeCollaborationProvider::json($row['owner_reference_json']),
            'authorization_revision' => $row['authorization_revision'],'document_auth_revision' => (int) $row['document_auth_revision'],
            'issued_state_seq' => (int) $row['issued_state_seq'],'iat' => strtotime($row['created_at'] . ' UTC'),
            'exp' => strtotime($row['expires_at'] . ' UTC')]), JSON_THROW_ON_ERROR), $this->configuration['ticket_secret']);
    }

    private function input(array $fields): array
    {
        $this->requireConfiguration();
        $body = $this->authentication->payload();
        if (array_diff(array_keys($body), $fields) !== []) {
            throw BusinessException::invalid('COLLABORATION_INPUT_FIELDS_INVALID', '协作请求字段无效');
        }
        return $body;
    }

    private function requireConfiguration(): void
    {
        if (($this->configuration['enabled'] ?? null) !== true || !in_array($this->edition, ['engine','standalone','multi-tenant'], true)
            || !is_string($this->configuration['ticket_secret'] ?? null) || strlen($this->configuration['ticket_secret']) < 32
            || preg_match('/^[A-Za-z0-9_-]{1,128}$/D', $this->configuration['instance_id'] ?? '') !== 1
            || !is_int($this->configuration['ticket_seconds'] ?? null) || $this->configuration['ticket_seconds'] < 1 || $this->configuration['ticket_seconds'] > 60
            || !is_int($this->configuration['room_lease_seconds'] ?? null) || $this->configuration['room_lease_seconds'] < 1 || $this->configuration['room_lease_seconds'] > 60
            || !is_int($this->configuration['connection_lease_seconds'] ?? null) || $this->configuration['connection_lease_seconds'] < 1 || $this->configuration['connection_lease_seconds'] > 30
            || $this->configuration['connection_lease_seconds'] > $this->configuration['room_lease_seconds']
            ) {
            throw new ApiProblem('COLLAB_UNAVAILABLE', 503, '协作配置不完整或未启用');
        }
    }

    private function publicWssUrl(): string
    {
        $value = $this->configuration['public_wss_url'] ?? null;
        $url = is_string($value) ? parse_url($value) : false;
        if (!is_array($url) || array_diff(array_keys($url), ['scheme', 'host', 'port', 'path']) !== []
            || ($url['scheme'] ?? null) !== 'wss' || ($url['path'] ?? null) !== '/collaboration'
            || !is_string($url['host'] ?? null) || strlen($url['host']) > 253
            || preg_match('/^[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?(?:\.[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?)*$/D', $url['host']) !== 1
            || !is_int($url['port'] ?? null) || $url['port'] < 1024 || $url['port'] > 65535
            || $value !== 'wss://' . $url['host'] . ':' . $url['port'] . '/collaboration'
            || !is_int($this->configuration['config_revision'] ?? null) || $this->configuration['config_revision'] < 1
            || !is_string($this->configuration['config_sha256'] ?? null)
            || preg_match('/^[a-f0-9]{64}$/D', $this->configuration['config_sha256'] ?? '') !== 1) {
            throw new ApiProblem('COLLAB_UNAVAILABLE', 503, '公开协作传输配置尚未核定');
        }
        return $value;
    }

    private function connectionResult(CollaborationConnection $connection, CollaborationRoom $room, TenantContext $actor, array $document): array
    {
        $member = $this->members->current($actor);
        if ($member === null || $member->tenantId !== $actor->tenantId || $member->memberId !== $actor->memberId || $member->accountId !== $actor->accountId) {
            throw BusinessException::forbidden('COLLABORATION_MEMBER_UNAVAILABLE', '当前协作成员资格已改变');
        }
        // Room is already locked by authorize/check. Return only its server-held
        // binding after comparing it with this request's live authorized Document.
        $binding = $this->native->roomBinding($room->room_key);
        if ($binding['tenant_id'] !== $actor->tenantId || $binding['document_id'] !== (int) $room->document_id
            || $binding['instance_id'] !== $this->configuration['instance_id'] || $binding['edition'] !== $this->edition
            || RichTextProjection::canonical($binding['owner']) !== RichTextProjection::canonical($document['owner'])) {
            throw BusinessException::conflict('RECOVERY_REQUIRED', '房间归属与当前已授权文档不一致');
        }
        return ['protocol' => 2,'holder_key' => $room->holder_key,'connection_key' => $connection->connection_key,'room_key' => $room->room_key,'document_id' => (int) $room->document_id,
            'binding' => $binding,'mode' => $connection->mode,'member_id' => $member->memberId,'nickname' => $member->displayName,'lease_epoch' => (int) $room->lease_epoch,
            'authorization_revision' => $connection->authorization_revision,'lease_expires_at' => strtotime($connection->lease_expires_at . ' UTC') * 1000];
    }

    private static function owner(array $owner): DocumentOwnerReference
    {
        return new DocumentOwnerReference($owner['module_key'], $owner['object_type'], $owner['object_id'], $owner['slot']);
    }
    private static function date(?int $time = null): string
    {
        return $time === null ? (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s.v') : gmdate('Y-m-d H:i:s', $time);
    }
    private static function hash(array $value): string
    {
        return hash('sha256', json_encode(RichTextProjection::canonical($value), JSON_THROW_ON_ERROR));
    }
    private static function text(array $value, string $key): string
    {
        if (!is_string($value[$key] ?? null)) {
            throw BusinessException::invalid('COLLABORATION_INPUT_INVALID', '协作字段无效');
        }
        return $value[$key];
    }
    private static function array(array $value, string $key): array
    {
        if (!is_array($value[$key] ?? null)) {
            throw BusinessException::invalid('COLLABORATION_INPUT_INVALID', '协作字段无效');
        }
        return $value[$key];
    }
    private static function key(array $value, string $key): string
    {
        $text = self::text($value, $key);
        if (preg_match('/^[A-Za-z0-9_.:-]{1,128}$/D', $text) !== 1) {
            throw BusinessException::invalid('COLLABORATION_INPUT_INVALID', '协作编号无效');
        }
        return $text;
    }
    private static function digest(array $value, string $key): string
    {
        $text = self::text($value, $key);
        if (preg_match('/^[0-9a-f]{64}$/D', $text) !== 1) {
            throw BusinessException::invalid('COLLABORATION_INPUT_INVALID', '协作摘要无效');
        }
        return $text;
    }
    private static function positive(array $value, string $key): int
    {
        if (!is_int($value[$key] ?? null) || $value[$key] < 1) {
            throw BusinessException::invalid('COLLABORATION_INPUT_INVALID', '协作序号无效');
        }
        return $value[$key];
    }
}
