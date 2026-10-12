<?php

declare(strict_types=1);

namespace PeanutAdmin\Modules\Document\Service;

use app\common\exception\BusinessException;
use app\common\services\audit\AuditContractHost;
use app\common\execution\AdminExecutionContext;
use app\common\execution\ExecutionContextStore;
use PeanutAdmin\Kernel\Auth\TenantContext;
use PeanutAdmin\Modules\Document\Contract\DocumentCommands;
use PeanutAdmin\Modules\Document\Contract\DocumentQueries;
use PeanutAdmin\Modules\Document\Contract\DocumentOwnerReference;
use PeanutAdmin\Modules\Document\Contract\VersionSnapshot;
use PeanutAdmin\Modules\Document\Model\Document;
use PeanutAdmin\Modules\Document\Model\DocumentState;
use PeanutAdmin\Modules\Document\Model\DocumentRevision;
use PeanutAdmin\Modules\Document\Model\DocumentResourceBinding;
use PeanutAdmin\Modules\Document\Model\DocumentFileReference;
use PeanutAdmin\Modules\Document\Model\DocumentOperation;
use PeanutAdmin\Modules\File\Contract\FileDocumentReferencePolicy;
use PeanutAdmin\Modules\File\Contract\FileDocumentSnapshots;
use PeanutAdmin\Modules\File\Contract\FileReferenceCommands;
use PeanutAdmin\Modules\File\Contract\FileStorage;
use PeanutAdmin\Modules\RichText\Contract\RichTextProjection;
use PeanutAdmin\Modules\ContentTools\Contract\AuthorizedContentContext;
use PeanutAdmin\Modules\ContentTools\Service\ContentCapabilitiesService;
use think\facade\Db;

/** One native owner/Document transaction per mutation; versions and command receipts are append-only. */
final readonly class DocumentService implements DocumentCommands, DocumentQueries, FileDocumentReferencePolicy
{
    private \WeakMap $preparedVersions;
    private const PREPARED_LIFETIME_NANOSECONDS = 120_000_000_000;

    public function __construct(
        private DocumentAuthorizationService $authorization,
        private FileReferenceCommands $references,
        private FileDocumentSnapshots $snapshots,
        private FileStorage $files,
        private AuditContractHost $audit,
        private ContentCapabilitiesService $tools,
        private ExecutionContextStore $execution,
    ) {
        $this->preparedVersions = new \WeakMap();
    }

    public function create(TenantContext $context, DocumentOwnerReference $owner, string $contentKind, string $requestId, ?string $fileKey = null): array
    {
        if (!in_array($contentKind, ['rich_text','file_resource'], true) || ($contentKind === 'file_resource') !== ($fileKey !== null)) {
            throw BusinessException::invalid('DOCUMENT_KIND_INVALID', '文档类型或主资源无效');
        }
        return $this->authorization->run($context, fn(TenantContext $current) => Db::transaction(function () use ($current, $owner, $contentKind, $requestId, $fileKey): array {
            $this->authorization->owner($current, $owner, 'create', true);
            $input = [$owner->moduleKey,$owner->objectType,$owner->objectId,$owner->slot,$contentKind,$fileKey];
            $document = Document::where('owner_module_key', $owner->moduleKey)->where('owner_object_type', $owner->objectType)
                ->where('owner_object_id', $owner->objectId)->where('slot', $owner->slot)->lock(true)->find();
            if ($document !== null) {
                $document = $this->authorization->document($current, (int) $document->id, 'edit', true);
                if ($document->content_kind !== $contentKind) {
                    throw BusinessException::conflict('DOCUMENT_SLOT_OCCUPIED', '业务槽位已有其他类型文档');
                }
                $replay = $this->replay($document, 'create', $requestId, $input);
                if ($replay !== null) {
                    return $replay;
                }
                return $this->receipt($current, $document, 'create', $requestId, $input, $this->summary($document) + ['created' => false]);
            }
            $resource = $fileKey === null ? null : $this->snapshots->describeForDocument($current, $fileKey);
            $document = Document::create(['tenant_id' => $current->tenantId,'owner_module_key' => $owner->moduleKey,'owner_object_type' => $owner->objectType,
                'owner_object_id' => $owner->objectId,'slot' => $owner->slot,'content_kind' => $contentKind,'body_type' => $contentKind === 'rich_text' ? 'rich_text' : null,
                'schema_version' => $contentKind === 'rich_text' ? RichTextProjection::SCHEMA : 'peanut.file-resource/1','status' => 'active','auth_revision' => 1,
                'state_seq' => $resource === null ? 0 : 1,'collaboration_enabled' => 0,'lease_epoch' => 0,'created_by_member_id' => $current->memberId,
                'updated_by_member_id' => $current->memberId,'create_time' => time(),'update_time' => time()]);
            if ($resource === null) {
                $projection = RichTextProjection::empty();
                DocumentState::create(['tenant_id' => $current->tenantId,'document_id' => $document->id,'yjs_state_binary' => null,'state_seq' => 0,
                    'state_sha256' => hash('sha256', $projection->json),'projected_json' => $projection->json,'projection_schema_version' => RichTextProjection::SCHEMA,
                    'persisted_at' => self::now(),'last_store_id' => $requestId]);
                $this->writeReferences($current, $document, []);
            } else {
                $this->writeBinding($current, $document, $resource);
                $this->writeReferences($current, $document, [['file_key' => $fileKey,'variant_key' => null]]);
            }
            return $this->receipt($current, $document, 'create', $requestId, $input, $this->summary($document) + ['created' => true]);
        }));
    }

    public function assertOwnerDeletionAllowed(TenantContext $context, DocumentOwnerReference $owner): void
    {
        $this->authorization->run($context, function (TenantContext $current) use ($owner): void {
            $this->authorization->owner($current, $owner, 'purge', true);
            if (Document::where('owner_module_key', $owner->moduleKey)->where('owner_object_type', $owner->objectType)->where('owner_object_id', $owner->objectId)->lock(true)->find() !== null) {
                throw BusinessException::conflict('DOCUMENT_OWNER_RETAINED', '业务仍有受保护文档，不可永久删除');
            }
        });
    }

    public function saveSolo(TenantContext $context, int $documentId, int $expectedStateSeq, array $structuredContent, string $requestId): array
    {
        $projection = RichTextProjection::fromStructured($structuredContent);
        return $this->authorization->run($context, fn(TenantContext $current) => Db::transaction(function () use ($current, $documentId, $expectedStateSeq, $projection, $requestId): array {
            $document = $this->authorization->document($current, $documentId, 'edit', true);
            $this->solo($document, 'rich_text');
            $input = [$expectedStateSeq,hash('sha256', $projection->json)];
            $replay = $this->replay($document, 'solo.save', $requestId, $input);
            if ($replay !== null) {
                return $replay;
            }
            $this->cas($document, $expectedStateSeq);
            $next = $expectedStateSeq + 1;
            $state = DocumentState::where('document_id', $documentId)->lock(true)->find();
            if ($state === null || $state->yjs_state_binary !== null) {
                throw BusinessException::conflict('DOCUMENT_SOLO_STATE_INVALID', '文档持久模式不允许单人保存');
            }
            $state->save(['state_seq' => $next,'state_sha256' => hash('sha256', $projection->json),'projected_json' => $projection->json,
                'persisted_at' => self::now(),'last_store_id' => $requestId]);
            $document->save(['state_seq' => $next,'updated_by_member_id' => $current->memberId,'update_time' => time()]);
            $this->writeReferences($current, $document, $projection->fileReferences);
            return $this->receipt($current, $document, 'solo.save', $requestId, $input, ['document_id' => $documentId,'state_seq' => $next,'persisted_at' => self::now(),'digest' => hash('sha256', $projection->json)]);
        }));
    }

    public function bindResource(TenantContext $context, int $documentId, string $fileKey, int $expectedStateSeq, string $requestId): array
    {
        return $this->authorization->run($context, fn(TenantContext $current) => Db::transaction(function () use ($current, $documentId, $fileKey, $expectedStateSeq, $requestId): array {
            $document = $this->authorization->document($current, $documentId, 'edit', true);
            $this->solo($document, 'file_resource');
            $input = [$expectedStateSeq,$fileKey];
            $replay = $this->replay($document, 'resource.bind', $requestId, $input);
            if ($replay !== null) {
                return $replay;
            }
            $this->cas($document, $expectedStateSeq);
            $resource = $this->snapshots->describeForDocument($current, $fileKey);
            $document->save(['state_seq' => $expectedStateSeq + 1,'updated_by_member_id' => $current->memberId,'update_time' => time()]);
            $this->writeBinding($current, $document, $resource);
            $this->writeReferences($current, $document, [['file_key' => $fileKey,'variant_key' => null]]);
            return $this->receipt($current, $document, 'resource.bind', $requestId, $input, ['document_id' => $documentId,'state_seq' => $expectedStateSeq + 1,'file_key' => $fileKey,'digest' => $resource['sha256']]);
        }));
    }

    public function freeze(TenantContext $context, int $documentId, int $expectedStateSeq, string $reason, string $requestId): array
    {
        if (trim($reason) === '' || mb_strlen($reason) > 255) {
            throw BusinessException::invalid('DOCUMENT_REVISION_REASON_INVALID', '版本原因无效');
        }
        return $this->authorization->run($context, function (TenantContext $current) use ($documentId, $expectedStateSeq, $reason, $requestId): array {
            $input = [$expectedStateSeq,$reason];
            $plan = Db::transaction(function () use ($current, $documentId, $expectedStateSeq, $requestId, $input): array {
                $document = $this->authorization->document($current, $documentId, 'version.create', true);
                $this->solo($document);
                $replay = $this->replay($document, 'version.create', $requestId, $input);
                if ($replay !== null) {
                    return ['replay' => $replay];
                }
                $this->cas($document, $expectedStateSeq);
                return ['binding' => $document->content_kind === 'file_resource' ? $this->binding($document) : null];
            });
            if (isset($plan['replay'])) {
                return $plan['replay'];
            }
            $snapshot = null;
            if ($plan['binding'] !== null) {
                $snapshot = $this->snapshots->captureForDocument($current, $plan['binding']['primary_file_key'], $plan['binding']['source_sha256'], (int) $plan['binding']['source_file_revision'], 'document:' . $documentId . ':' . $requestId);
            }
            try {
                return Db::transaction(function () use ($current, $documentId, $expectedStateSeq, $reason, $requestId, $input, $snapshot, $plan): array {
                    $document = $this->authorization->document($current, $documentId, 'version.create', true);
                    $this->solo($document);
                    $replay = $this->replay($document, 'version.create', $requestId, $input);
                    if ($replay !== null) {
                        return $replay;
                    }
                    $this->cas($document, $expectedStateSeq);
                    if ($snapshot !== null && $this->binding($document) !== $plan['binding']) {
                        throw BusinessException::conflict('DOCUMENT_BINDING_CHANGED', '主资源绑定已改变');
                    }
                    $revision = $this->appendRevision($current, $document, $reason, $snapshot);
                    if ($snapshot !== null) {
                        $this->revisionReference($current, $document, $snapshot['file_key']);
                        $this->syncReferences($current, $document);
                        $this->snapshots->claimDocumentSnapshot($current, $documentId, $snapshot);
                    }
                    return $this->receipt($current, $document, 'version.create', $requestId, $input, $revision);
                });
            } catch (\Throwable $error) {
                if ($snapshot !== null) {
                    try {
                        $this->snapshots->abandonDocumentSnapshot($current, $snapshot);
                    } catch (\Throwable) { /* The durable File recovery ledger survives; preserve the owner/CAS error. */
                    }
                }
                throw $error;
            }
        });
    }

    public function restoreRevision(TenantContext $context, int $documentId, int $revisionId, int $expectedStateSeq, string $requestId): array
    {
        return $this->authorization->run($context, fn(TenantContext $current) => Db::transaction(function () use ($current, $documentId, $revisionId, $expectedStateSeq, $requestId): array {
            $document = $this->authorization->document($current, $documentId, 'edit', true);
            $this->solo($document);
            $this->authorization->document($current, $documentId, 'version.create', true);
            $input = [$revisionId,$expectedStateSeq];
            $replay = $this->replay($document, 'version.restore', $requestId, $input);
            if ($replay !== null) {
                return $replay;
            }
            $this->cas($document, $expectedStateSeq);
            $revision = $this->revision($document, $revisionId);
            if ($revision->content_kind !== $document->content_kind) {
                throw BusinessException::conflict('DOCUMENT_REVISION_KIND_MISMATCH', '版本类型不一致');
            }
            $document->save(['state_seq' => $expectedStateSeq + 1,'updated_by_member_id' => $current->memberId,'update_time' => time()]);
            $snapshot = null;
            if ($document->content_kind === 'rich_text') {
                $projection = RichTextProjection::fromStructured(self::json($revision->content_snapshot_json));
                $state = DocumentState::where('document_id', $documentId)->lock(true)->find();
                if ($state === null || $revision->yjs_snapshot !== null) {
                    throw BusinessException::conflict('DOCUMENT_COLLABORATION_BARRIER_REQUIRED', '协作版本恢复需要真实服务屏障');
                }
                $state->save(['projected_json' => $projection->json,'state_sha256' => hash('sha256', $projection->json),'yjs_state_binary' => null,
                    'state_seq' => $expectedStateSeq + 1,'persisted_at' => self::now(),'last_store_id' => $requestId]);
                $this->writeReferences($current, $document, $projection->fileReferences);
            } else {
                $snapshot = $this->snapshots->describeForDocument($current, (string) $revision->immutable_file_key);
                if (!hash_equals((string) $revision->immutable_file_sha256, $snapshot['sha256'])) {
                    throw BusinessException::conflict('DOCUMENT_REVISION_BYTES_CHANGED', '历史字节校验失败');
                }
                $this->writeBinding($current, $document, $snapshot);
                $this->writeReferences($current, $document, [['file_key' => $snapshot['file_key'],'variant_key' => null]]);
            }
            return $this->receipt($current, $document, 'version.restore', $requestId, $input, $this->appendRevision($current, $document, 'restore:' . $revisionId, $snapshot));
        }));
    }

    public function archive(TenantContext $context, int $documentId, int $expectedAuthRevision, string $requestId): array
    {
        return $this->lifecycle($context, $documentId, $expectedAuthRevision, $requestId, 'archive');
    }
    public function restore(TenantContext $context, int $documentId, int $expectedAuthRevision, string $requestId): array
    {
        return $this->lifecycle($context, $documentId, $expectedAuthRevision, $requestId, 'restore');
    }
    private function lifecycle(TenantContext $context, int $id, int $expected, string $requestId, string $action): array
    {
        return $this->authorization->run($context, fn(TenantContext $current) => Db::transaction(function () use ($current, $id, $expected, $requestId, $action): array {
            $document = $this->authorization->document($current, $id, $action, true);
            $this->solo($document);
            $input = [$expected];
            $replay = $this->replay($document, $action, $requestId, $input);
            if ($replay !== null) {
                return $replay;
            }
            if ((int) $document->auth_revision !== $expected) {
                throw BusinessException::conflict('DOCUMENT_AUTH_REVISION_CONFLICT', '文档授权状态已改变');
            }
            $status = $action === 'archive' ? 'archived' : 'active';
            if ($document->status !== $status) {
                $document->save(['status' => $status,'auth_revision' => $expected + 1,'archived_at' => $action === 'archive' ? self::now() : null,'updated_by_member_id' => $current->memberId,'update_time' => time()]);
            }
            return $this->receipt($current, $document, $action, $requestId, $input, $this->summary($document));
        }));
    }
    public function archiveOwner(TenantContext $context, string $moduleKey, string $objectType, string $objectId): void
    {
        $this->ownerLifecycle($context, $moduleKey, $objectType, $objectId, 'archive');
    }
    public function restoreOwner(TenantContext $context, string $moduleKey, string $objectType, string $objectId): void
    {
        $this->ownerLifecycle($context, $moduleKey, $objectType, $objectId, 'restore');
    }
    private function ownerLifecycle(TenantContext $context, string $module, string $type, string $id, string $action): void
    {
        $this->authorization->run($context, function (TenantContext $current) use ($module, $type, $id, $action): void {
            $documents = Document::where('owner_module_key', $module)->where('owner_object_type', $type)->where('owner_object_id', $id)->order('id')->select();
            foreach ($documents as $document) {
                $this->lifecycle($current, (int) $document->id, (int) $document->auth_revision, 'owner-' . $action . '-' . $document->auth_revision, $action);
            }
        });
    }

    public function detail(TenantContext $context, int $documentId, ?int $revisionId = null): array
    {
        return $this->authorization->run($context, function (TenantContext $current) use ($documentId, $revisionId): array {
            $document = $this->authorization->document($current, $documentId, 'view');
            $result = $this->summary($document);
            $result['owner'] = ['module_key' => $document->owner_module_key,'object_type' => $document->owner_object_type,'object_id' => $document->owner_object_id,'slot' => $document->slot];
            $result['authorization_revision'] = $this->authorization->revision($current, $document);
            if ($revisionId !== null) {
                return $result + $this->versionSnapshot($current, $documentId, $revisionId)->toArray();
            }
            if ($document->content_kind === 'rich_text') {
                $state = DocumentState::where('document_id', $documentId)->find();
                if ($state === null) {
                    throw BusinessException::conflict('DOCUMENT_STATE_MISSING', '文档正文不可用');
                }
                return $result + ['structured_content' => self::json($state->projected_json),'digest' => $state->state_sha256,'persisted_at' => $state->persisted_at];
            }
            $binding = $this->binding($document);
            return $result + ['file_key' => $binding['primary_file_key'],'verified_mime_type' => $binding['verified_mime_type'],'digest' => $binding['source_sha256']];
        });
    }

    public function versionSnapshot(TenantContext $context, int $documentId, int $revisionId): \PeanutAdmin\Modules\Document\Contract\VersionSnapshot
    {
        // Capture the external lifetime before DocAuth creates its transient nested run.
        $root = $this->execution->current() instanceof AdminExecutionContext ? $this->execution->rootIdentity() : null;
        if (($pdo = Db::connect()->getPdo()) instanceof \PDO && $pdo->inTransaction()) {
            throw BusinessException::conflict('DOCUMENT_VERSION_NOT_COMMITTED', '不可把外层事务中的版本声明为已提交');
        }
        return $this->authorization->run($context, function (TenantContext $current) use ($documentId, $revisionId, $root) {
            $document = $this->authorization->document($current, $documentId, 'view');
            $revision = $this->revision($document, $revisionId);
            $snapshot = $this->snapshotFromRevision($current, $document, $revision);
            if ($root !== null && $root === $this->execution->rootIdentity()) {
                $this->preparedVersions[$snapshot] = [
                    'root' => $root, 'actor' => self::preparedActor($current), 'owner' => DocumentAuthorizationService::reference($document),
                    'canonical' => self::snapshotHash($snapshot), 'expires_at' => hrtime(true) + self::PREPARED_LIFETIME_NANOSECONDS,
                ];
            }
            return $snapshot;
        });
    }

    public function verifyPreparedVersion(TenantContext $context, VersionSnapshot $snapshot, DocumentOwnerReference $expectedOwner): void
    {
        $proof = $this->preparedVersions[$snapshot] ?? null;
        if ($proof === null || !$this->execution->current() instanceof AdminExecutionContext
            || $proof['root'] !== $this->execution->rootIdentity() || hrtime(true) >= $proof['expires_at']
            || $proof['actor'] !== self::preparedActor($context) || $proof['owner'] != $expectedOwner
            || !hash_equals($proof['canonical'], self::snapshotHash($snapshot))) {
            throw BusinessException::forbidden('DOCUMENT_PREPARED_VERSION_DENIED', '文档版本准备证明无效或已过期');
        }
        if (!(($pdo = Db::connect()->getPdo()) instanceof \PDO) || !$pdo->inTransaction()) {
            throw BusinessException::conflict('DOCUMENT_PREPARED_TRANSACTION_REQUIRED', '版本复核必须在业务外层事务内');
        }
        $this->authorization->run($context, function (TenantContext $current) use ($snapshot, $expectedOwner, $proof): void {
            $document = $this->authorization->document($current, $snapshot->documentId, 'view', true);
            if (DocumentAuthorizationService::reference($document) != $expectedOwner || $proof['actor'] !== self::preparedActor($current)) {
                throw BusinessException::forbidden('DOCUMENT_PREPARED_VERSION_DENIED', '文档版本业务归属或身份已改变');
            }
            $revision = DocumentRevision::where('document_id', $document->id)->where('id', $snapshot->revisionId)->lock(true)->find();
            if ($revision === null || !hash_equals($proof['canonical'], self::snapshotHash($this->snapshotFromRevision($current, $document, $revision)))) {
                throw BusinessException::conflict('DOCUMENT_PREPARED_VERSION_CHANGED', '不可变文档版本与准备结果不一致');
            }
            $keys = array_values(array_unique(array_column($snapshot->references, 'file_key')));
            $this->references->assertDocumentReferencesReadable($current, $snapshot->documentId, $keys);
            if ($snapshot->contentKind === 'file_resource') {
                $this->snapshots->assertDocumentSnapshotReadable($current, $snapshot->documentId, $snapshot->fileKey, $snapshot->fileSha256, $snapshot->verifiedMimeType);
            }
        });
    }

    private function snapshotFromRevision(TenantContext $context, Document $document, DocumentRevision $revision): VersionSnapshot
    {
        $projection = $revision->content_snapshot_json === null ? null : RichTextProjection::fromStructured(self::json($revision->content_snapshot_json));
        $content = $projection?->json;
        if (!in_array($revision->content_kind, ['rich_text', 'file_resource'], true)
            || ($revision->content_kind === 'rich_text' && ($content === null || $revision->immutable_file_key !== null || $revision->immutable_file_sha256 !== null || $revision->immutable_file_mime_type !== null))
            || ($revision->content_kind === 'file_resource' && ($content !== null || !is_string($revision->immutable_file_key)
                || preg_match('/^file_[0-9a-f]{32}$/D', $revision->immutable_file_key) !== 1 || !is_string($revision->immutable_file_sha256)
                || preg_match('/^[0-9a-f]{64}$/D', $revision->immutable_file_sha256) !== 1 || !is_string($revision->immutable_file_mime_type) || $revision->immutable_file_mime_type === ''))) {
            throw BusinessException::conflict('DOCUMENT_VERSION_DIGEST_INVALID', '不可变文档版本字段不一致');
        }
        $digest = hash('sha256', json_encode([(string) $revision->content_kind, (int) $revision->source_state_seq, $content,
            $revision->yjs_snapshot === null ? null : hash('sha256', $revision->yjs_snapshot), $revision->immutable_file_key, $revision->immutable_file_sha256], JSON_THROW_ON_ERROR));
        if (!hash_equals((string) $revision->snapshot_sha256, $digest)) {
            throw BusinessException::conflict('DOCUMENT_VERSION_DIGEST_INVALID', '不可变文档版本摘要不一致');
        }
        return new VersionSnapshot(
            $context->tenantId,
            (int) $document->id,
            (int) $revision->id,
            (int) $revision->revision_no,
            (int) $revision->source_state_seq,
            (string) $revision->content_kind,
            $digest,
            $projection === null ? null : self::json($projection->json),
            $revision->immutable_file_key,
            $revision->immutable_file_sha256,
            $revision->immutable_file_mime_type,
            $projection?->fileReferences ?? [['file_key' => (string) $revision->immutable_file_key, 'variant_key' => null]],
        );
    }

    private static function snapshotHash(VersionSnapshot $snapshot): string
    {
        return hash('sha256', json_encode(RichTextProjection::canonical($snapshot->toArray()), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    private static function preparedActor(TenantContext $context): array
    {
        return [$context->tenantId, $context->accountId, $context->memberId, $context->sessionKey,
            $context->clientKey, $context->requestId, $context->authorizationRevision];
    }

    public function capabilities(TenantContext $context, int $documentId, string $intent, ?int $revisionId = null): array
    {
        if (!in_array($intent, ['view','edit'], true)) {
            throw BusinessException::invalid('DOCUMENT_INTENT_INVALID', '内容意图无效');
        }
        return $this->authorization->run($context, function (TenantContext $current) use ($documentId, $intent, $revisionId): array {
            $data = $this->detail($current, $documentId, $revisionId);
            $document = $this->authorization->document($current, $documentId, 'view');
            $canEdit = false;
            $canDownload = false;
            if ($revisionId === null && !(bool) $document->collaboration_enabled) {
                try {
                    $this->authorization->document($current, $documentId, 'edit');
                    $canEdit = true;
                } catch (BusinessException) {
                }
            }
            if ($document->content_kind === 'file_resource') {
                try {
                    $this->files->requireOriginalDownloadPermission($current->tenantId);
                    $canDownload = true;
                } catch (BusinessException) {
                }
            }
            $modules = [];
            foreach (['official.content-tools','official.rich-text'] as $key) {
                try {
                    $this->authorization->requireModule($current, $key);
                    $modules[] = $key;
                } catch (\PeanutAdmin\Kernel\Module\ModuleException) {
                }
            }
            $authorized = new AuthorizedContentContext(
                (string) $document->content_kind,
                $data['verified_mime_type'] ?? 'application/vnd.peanut.richtext+json',
                true,
                $canEdit,
                $canDownload,
                $data['authorization_revision'],
                'browser',
                $modules,
                [],
            );
            return ['document_id' => $documentId,'can_view' => true,'can_edit' => $canEdit,'can_download_original' => $canDownload,
                'collaboration_enabled' => (bool) $document->collaboration_enabled,'capabilities' => $this->tools->resolve($authorized, $intent, null, null)];
        });
    }

    public function authorizePreview(TenantContext $context, int $documentId, string $fileKey): array
    {
        return $this->authorization->run($context, function (TenantContext $current) use ($documentId, $fileKey): array {
            $document = $this->authorization->document($current, $documentId, 'view');
            if (DocumentFileReference::where('document_id', $documentId)->where('file_key', $fileKey)->find() === null) {
                throw BusinessException::forbidden('DOCUMENT_FILE_REFERENCE_DENIED', '资源不在当前获准文档引用中');
            }
            return ['authorization_revision' => $this->authorization->revision($current, $document),'reference_digest' => $this->referenceDigest($document),'inherit_preview' => true];
        });
    }
    public function referencesForUpdate(TenantContext $context, int $documentId): array
    {
        return $this->authorization->run($context, function (TenantContext $current) use ($documentId): array {
            $document = $this->authorization->document($current, $documentId, 'edit', true);
            $keys = DocumentFileReference::where('document_id', $documentId)->lock(true)->order('file_key')->column('file_key');
            $keys = array_values(array_unique($keys));
            return ['authorization_revision' => $this->authorization->revision($current, $document, 'edit'),'file_keys' => $keys,'reference_digest' => $this->referenceDigest($document)];
        });
    }

    private function writeBinding(TenantContext $context, Document $document, array $resource): void
    {
        $data = ['tenant_id' => $context->tenantId,'document_id' => $document->id,'primary_file_key' => $resource['file_key'],'verified_mime_type' => $resource['verified_mime_type'],
            'source_sha256' => $resource['sha256'],'source_file_revision' => $resource['revision'],'state_seq' => $document->state_seq,'bound_at' => self::now()];
        $existing = DocumentResourceBinding::where('document_id', $document->id)->lock(true)->find();
        if ($existing === null) {
            DocumentResourceBinding::create($data);
        } else {
            $existing->save($data);
        }
    }
    private function writeReferences(TenantContext $context, Document $document, array $refs): void
    {
        DocumentFileReference::where('document_id', $document->id)->where('usage', 'current')->delete();
        foreach ($refs as $ref) {
            try {
                DocumentFileReference::create(['tenant_id' => $context->tenantId,'document_id' => $document->id,'file_key' => $ref['file_key'],
                    'variant_key' => $ref['variant_key'],'usage' => 'current','derived_state_seq' => $document->state_seq,'updated_at' => self::now()]);
            } catch (\think\db\exception\PDOException $error) {
                $info = $error->getData()['PDO Error Info'] ?? [];
                // Only the declared File-target FK becomes a stable missing-reference rejection.
                // Owner/member constraints and unknown database failures retain their original cause.
                if (($info['SQLSTATE'] ?? null) === '23000' && (int) ($info['Driver Error Code'] ?? 0) === 1452
                    && str_contains((string) ($info['Driver Error Message'] ?? ''), '`fk_document_file_ref_object`')) {
                    throw BusinessException::notFound('DOCUMENT_FILE_REFERENCE_NOT_FOUND', '引用资源不存在');
                }
                throw $error;
            }
        }
        $this->syncReferences($context, $document);
    }
    private function revisionReference(TenantContext $context, Document $document, string $key): void
    {
        if (DocumentFileReference::where('document_id', $document->id)->where('file_key', $key)->where('usage', 'revision')->find() === null) {
            DocumentFileReference::create(['tenant_id' => $context->tenantId,'document_id' => $document->id,'file_key' => $key,'variant_key' => null,'usage' => 'revision','derived_state_seq' => $document->state_seq,'updated_at' => self::now()]);
        }
    }
    private function syncReferences(TenantContext $context, Document $document): void
    {
        $keys = array_values(array_unique(DocumentFileReference::where('document_id', $document->id)->order('file_key')->column('file_key')));
        $this->references->replaceDocumentReferences($context, (int) $document->id, $keys);
    }
    private function referenceDigest(Document $document): string
    {
        $rows = DocumentFileReference::where('document_id', $document->id)->field(['file_key','variant_key','usage','derived_state_seq'])->order(['file_key' => 'asc','usage' => 'asc'])->select()->toArray();
        return hash('sha256', json_encode(RichTextProjection::canonical($rows), JSON_THROW_ON_ERROR));
    }
    private function appendRevision(TenantContext $context, Document $document, string $reason, ?array $snapshot): array
    {
        $content = null;
        $binary = null;
        if ($document->content_kind === 'rich_text') {
            $state = DocumentState::where('document_id', $document->id)->lock(true)->find();
            if ($state === null || (int) $state->state_seq !== (int) $document->state_seq) {
                throw BusinessException::conflict('DOCUMENT_STATE_MISSING', '文档持久水位不一致');
            }
            $projection = RichTextProjection::fromStructured(self::json($state->projected_json));
            $content = $projection->json;
            $binary = $state->yjs_state_binary;
            foreach ($projection->fileReferences as $ref) {
                $this->revisionReference($context, $document, $ref['file_key']);
            }
            $this->syncReferences($context, $document);
        }
        $number = (int) DocumentRevision::where('document_id', $document->id)->max('revision_no') + 1;
        $digest = hash('sha256', json_encode([(string) $document->content_kind,(int) $document->state_seq,$content,$binary === null ? null : hash('sha256', $binary),$snapshot['file_key'] ?? null,$snapshot['sha256'] ?? null], JSON_THROW_ON_ERROR));
        $revision = DocumentRevision::create(['tenant_id' => $context->tenantId,'document_id' => $document->id,'revision_no' => $number,'source_state_seq' => $document->state_seq,
            'content_kind' => $document->content_kind,'yjs_snapshot' => $binary,'content_snapshot_json' => $content,'immutable_file_key' => $snapshot['file_key'] ?? null,'immutable_file_sha256' => $snapshot['sha256'] ?? null,
            'immutable_file_mime_type' => $snapshot['verified_mime_type'] ?? null,
            'created_at' => self::now(),'created_by_member_id' => $context->memberId,'reason' => $reason,'snapshot_sha256' => $digest]);
        return ['document_id' => (int) $document->id,'revision_id' => (int) $revision->id,'revision_no' => $number,'source_state_seq' => (int) $document->state_seq,'digest' => $digest];
    }
    private function replay(Document $document, string $action, string $requestId, array $input): ?array
    {
        self::requestKey($requestId);
        $row = DocumentOperation::where('document_id', $document->id)->where('operation_id', $requestId)->lock(true)->find();
        if ($row === null) {
            return null;
        }
        if ($row->action !== $action || !hash_equals((string) $row->input_sha256, self::inputHash($input))) {
            throw BusinessException::conflict('DOCUMENT_IDEMPOTENCY_CONFLICT', '同一请求标识不能改变动作或内容');
        }
        return self::json($row->result_json);
    }
    private function receipt(TenantContext $context, Document $document, string $action, string $requestId, array $input, array $result): array
    {
        self::requestKey($requestId);
        DocumentOperation::create(['tenant_id' => $context->tenantId,'document_id' => $document->id,'operation_id' => $requestId,'action' => $action,'input_sha256' => self::inputHash($input),
            'result_json' => json_encode($result, JSON_THROW_ON_ERROR),'created_at' => self::now()]);
        $this->audit->appendTenantMember($context, 'document.' . $action, 'document.' . $action, 'document', (string) $document->id, null, null, 1, $result['digest'] ?? null, ['state_seq' => (int) $document->state_seq]);
        return $result;
    }
    private function binding(Document $document): array
    {
        $binding = DocumentResourceBinding::where('document_id', $document->id)->find();
        if ($binding === null || (int) $binding->state_seq !== (int) $document->state_seq) {
            throw BusinessException::conflict('DOCUMENT_BINDING_MISSING', '文档主资源绑定不可用');
        }
        return $binding->toArray();
    }
    private function revision(Document $document, int $id): DocumentRevision
    {
        $revision = DocumentRevision::where('document_id', $document->id)->where('id', $id)->find();
        if ($revision === null) {
            throw BusinessException::notFound('DOCUMENT_REVISION_NOT_FOUND', '文档版本不存在');
        }return $revision;
    }
    private function solo(Document $document, ?string $kind = null): void
    {
        if ((bool) $document->collaboration_enabled) {
            throw BusinessException::conflict('DOCUMENT_COLLABORATION_BARRIER_REQUIRED', '协作已启用，必须使用真实保存屏障');
        }
        if ($kind !== null && $document->content_kind !== $kind) {
            throw BusinessException::invalid('DOCUMENT_KIND_MISMATCH', '文档类型不允许此操作');
        }
    }
    private function cas(Document $document, int $expected): void
    {
        if ($expected < 0 || (int) $document->state_seq !== $expected) {
            throw BusinessException::conflict('DOCUMENT_STATE_SEQ_CONFLICT', '文档已被其他请求更新');
        }
    }
    private function summary(Document $document): array
    {
        return ['document_id' => (int) $document->id,'content_kind' => $document->content_kind,'schema_version' => $document->schema_version,'status' => $document->status,'state_seq' => (int) $document->state_seq,'auth_revision' => (int) $document->auth_revision];
    }
    private static function requestKey(string $key): void
    {
        if (preg_match('/^[A-Za-z0-9:_-]{1,128}$/D', $key) !== 1) {
            throw BusinessException::invalid('DOCUMENT_REQUEST_ID_INVALID', '文档请求标识无效');
        }
    }
    private static function inputHash(array $input): string
    {
        return hash('sha256', json_encode(RichTextProjection::canonical($input), JSON_THROW_ON_ERROR));
    }
    private static function now(): string
    {
        return gmdate('Y-m-d H:i:s');
    }
    private static function json(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }return json_decode((string) $value, true, 64, JSON_THROW_ON_ERROR);
    }
}
