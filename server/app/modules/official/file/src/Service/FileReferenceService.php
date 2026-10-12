<?php

declare(strict_types=1);

namespace PeanutAdmin\Modules\File\Service;

use app\common\exception\BusinessException;
use app\common\execution\CurrentExecutionContext;
use app\common\services\audit\AuditContractHost;
use PeanutAdmin\Kernel\Auth\TenantContext;
use PeanutAdmin\Modules\File\Contract\FileReferenceCommands;
use PeanutAdmin\Modules\File\Model\Storage\FileObject;
use PeanutAdmin\Modules\File\Model\Storage\FileReference;
use think\facade\Db;

/** Owner and File changes share the caller's ThinkPHP transaction. */
final readonly class FileReferenceService implements FileReferenceCommands
{
    public function __construct(private FileDocumentReferenceRegistry $documents, private FileAuthorizationService $authorization, private AuditContractHost $audit, private CurrentExecutionContext $execution) {}

    public function replaceDocumentReferences(TenantContext $context, int $documentId, array $fileKeys): void
    {
        $actor = $this->execution->tenantAdmin();
        if ($actor->tenantId !== $context->tenantId || $actor->memberId !== $context->memberId
            || !hash_equals($actor->sessionKey, $context->sessionKey)) {
            throw BusinessException::forbidden('FILE_REFERENCE_CONTEXT_MISMATCH', '引用与当前可信会话不一致');
        }
        if ($documentId < 1 || count($fileKeys) > 1000 || $fileKeys !== array_values($fileKeys)) {
            throw BusinessException::invalid('FILE_REFERENCE_SET_INVALID', '资源引用无效');
        }
        foreach ($fileKeys as $key) {
            if (!is_string($key) || preg_match('/^file_[0-9a-f]{32}$/D', $key) !== 1) {
                throw BusinessException::invalid('FILE_REFERENCE_SET_INVALID', '仅允许稳定资源引用');
            }
        }
        $fileKeys = array_values(array_unique($fileKeys));
        sort($fileKeys);
        $policy = $this->documents->requirePolicy();
        Db::transaction(function () use ($context, $documentId, $fileKeys, $policy): void {
            // The owner locks and authorizes its persisted document in this same transaction.
            $owner = $policy->referencesForUpdate($context, $documentId);
            $expected = array_values(array_unique($owner['file_keys']));
            sort($expected);
            if ($fileKeys !== $expected || $owner['authorization_revision'] === '' || $owner['reference_digest'] === '') {
                throw BusinessException::forbidden('FILE_REFERENCE_OWNER_MISMATCH', '引用与文档当前内容不一致');
            }
            // Same object lock as deletion prevents reference/delete races. Scope is supplied by owner boundary.
            foreach ($fileKeys as $key) {
                $object = FileObject::where('file_key', $key)->lock(true)->find()?->toArray();
                if ($object === null) {
                    throw BusinessException::notFound('FILE_REFERENCE_NOT_FOUND', '引用资源不存在');
                }
                $this->authorization->authorize($context, $object, 'preview', $object['purpose'] === 'attachment.sensitive' ? null : $documentId);
            }
            FileReference::where('owner_module_key', 'official.document')->where('owner_type', 'document')
                ->where('owner_id', (string) $documentId)->delete();
            foreach ($fileKeys as $key) {
                FileReference::create(['tenant_id' => $context->tenantId, 'file_key' => $key,
                    'owner_module_key' => 'official.document', 'owner_type' => 'document', 'owner_id' => (string) $documentId,
                    'reference_digest' => $owner['reference_digest'], 'updated_at' => gmdate('Y-m-d H:i:s')]);
            }
            $this->audit->appendTenantMember($context, 'file.references.replaced', 'file.references.replace', 'document', (string) $documentId,
                null, null, count($fileKeys), hash('sha256', implode("\0", $fileKeys)));
        });
    }
}
