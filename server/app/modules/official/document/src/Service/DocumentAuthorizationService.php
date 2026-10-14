<?php

declare(strict_types=1);

namespace PeanutAdmin\Modules\Document\Service;

use app\common\contract\authorization\AdminAuthorizationQuery;
use app\common\exception\BusinessException;
use app\common\execution\AdminExecutionContext;
use app\common\execution\ExecutionContextStore;
use PeanutAdmin\Kernel\Auth\TenantContext;
use PeanutAdmin\Kernel\Module\ModuleGuard;
use PeanutAdmin\Kernel\Module\ModuleRuntimeRepository;
use PeanutAdmin\Modules\Identity\Contract\TenantSessionAccess;
use PeanutAdmin\Modules\Document\Contract\DocumentOwnerReference;
use PeanutAdmin\Modules\Document\Model\Document;

final readonly class DocumentAuthorizationService
{
    public function __construct(
        private TenantSessionAccess $sessions,
        private AdminAuthorizationQuery $authorization,
        private ExecutionContextStore $execution,
        private ModuleRuntimeRepository $modules,
        private DocumentOwnerRegistry $owners,
    ) {}

    public function run(TenantContext $context, callable $operation): mixed
    {
        $ambient = $this->execution->current();
        if ($ambient !== null && (!$ambient instanceof AdminExecutionContext || $ambient->tenant->tenantId !== $context->tenantId
            || $ambient->tenant->memberId !== $context->memberId || $ambient->tenant->accountId !== $context->accountId
            || $ambient->tenant->clientKey !== $context->clientKey || !hash_equals($ambient->tenant->sessionKey, $context->sessionKey))) {
            throw BusinessException::forbidden('DOCUMENT_CONTEXT_MISMATCH', '文档与当前可信会话不一致');
        }
        try {
            $current = $this->sessions->revalidateSession(
                $context->tenantId,
                $context->accountId,
                $context->memberId,
                $context->sessionKey,
                $context->clientKey,
                $context->requestId,
            );
        } catch (\PeanutAdmin\Modules\Identity\Contract\TenantSessionAccessException) {
            throw BusinessException::forbidden('DOCUMENT_SESSION_DENIED', '原文档会话已失效');
        }
        if ($current->authorizationRevision !== $context->authorizationRevision) {
            throw BusinessException::forbidden('DOCUMENT_AUTHORIZATION_STALE', '文档授权已改变');
        }
        $principal = $this->authorization->principal($current);
        return $this->execution->run(new AdminExecutionContext($current, 'document.operation', $principal->toArray()), function () use ($current, $operation) {
            $this->requireModule($current, 'official.document');
            return $operation($current);
        });
    }

    public function requireModule(TenantContext $context, string $moduleKey): void
    {
        $guard = new ModuleGuard($this->modules);
        $guard->assertDeployment($moduleKey);
        $guard->assertTenant($context->tenantId, $moduleKey, new \DateTimeImmutable('now', new \DateTimeZone('UTC')));
    }

    /** Deployment availability does not confer human or accepted-write authority. */
    public function requireDeploymentModule(string $moduleKey): void
    {
        (new ModuleGuard($this->modules))->assertDeployment($moduleKey);
    }

    public function owner(TenantContext $context, DocumentOwnerReference $reference, string $action, bool $lock): \PeanutAdmin\Modules\Document\Contract\DocumentOwnerState
    {
        $this->requireModule($context, $reference->moduleKey);
        $policy = $this->owners->requirePolicy($reference);
        $owner = $policy->resolveOwner($context, $reference, $lock);
        if ($owner->tenantId !== $context->tenantId || $owner->reference != $reference) {
            throw BusinessException::forbidden('DOCUMENT_OWNER_MISMATCH', '文档业务归属不一致');
        }
        $policy->authorize($context, $action, $owner);
        return $owner;
    }

    /** Owner row is locked before the Document row on all mutation paths. */
    public function document(TenantContext $context, int $id, string $action, bool $lock = false): Document
    {
        if ($id < 1) {
            throw BusinessException::notFound('DOCUMENT_NOT_FOUND', '文档不存在');
        }
        $document = Document::where('id', $id)->find();
        if ($document === null) {
            throw BusinessException::notFound('DOCUMENT_NOT_FOUND', '文档不存在');
        }
        $reference = self::reference($document);
        if ($action === 'view' && $document->status === 'archived') {
            $action = 'archive.read';
        }
        $owner = $this->owner($context, $reference, $action, $lock);
        if ($lock) {
            $document = Document::where('id', $id)->lock(true)->find();
            if ($document === null || self::reference($document) != $reference) {
                throw BusinessException::conflict('DOCUMENT_OWNER_CHANGED', '文档业务归属已改变');
            }
        }
        $valid = match ($action) {
            'view','edit','version.create','create' => $document->status === 'active' && !$owner->archived,
            'archive.read' => $document->status === 'archived' && $owner->archived,
            'archive' => in_array($document->status, ['active','archiving','archived'], true) && $owner->archived,
            'restore' => in_array($document->status, ['active','archived'], true) && !$owner->archived,
            default => false,
        };
        if (!$valid) {
            throw BusinessException::forbidden('DOCUMENT_STATE_DENIED', '当前业务或文档状态不允许此操作');
        }
        return $document;
    }

    public function revision(TenantContext $context, Document $document, string $action = 'view'): string
    {
        $owner = $this->owner($context, self::reference($document), $document->status === 'archived' ? 'archive.read' : $action, false);
        $guard = new ModuleGuard($this->modules);
        $moduleState = [$guard->assertDeployment($owner->reference->moduleKey),$guard->assertTenant($context->tenantId, $owner->reference->moduleKey, new \DateTimeImmutable('now', new \DateTimeZone('UTC')))];
        // Editing progress belongs to state_seq/CAS receipts, never to a connection's authorization grant.
        return hash('sha256', json_encode([$context->authorizationRevision,$document->auth_revision,$document->status,
            $this->owners->requirePolicy($owner->reference)->stateRevision($owner),$moduleState], JSON_THROW_ON_ERROR));
    }

    public static function reference(Document $document): DocumentOwnerReference
    {
        return new DocumentOwnerReference(
            (string) $document->owner_module_key,
            (string) $document->owner_object_type,
            (string) $document->owner_object_id,
            (string) $document->slot,
        );
    }
}
