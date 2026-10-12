<?php

declare(strict_types=1);

namespace PeanutAdmin\Modules\Article\Service;

use app\common\contract\authorization\AdminAuthorizationQuery;
use app\common\exception\BusinessException;
use PeanutAdmin\Kernel\Auth\TenantContext;
use PeanutAdmin\Modules\Article\Model\Article;
use PeanutAdmin\Modules\Document\Contract\DocumentOwnerPolicy;
use PeanutAdmin\Modules\Document\Contract\DocumentOwnerReference;
use PeanutAdmin\Modules\Document\Contract\DocumentOwnerState;

/** Article remains the business/approval owner; no creator ACL and no publication changes. */
final readonly class ArticleDocumentOwnerPolicy implements DocumentOwnerPolicy
{
    public function __construct(private AdminAuthorizationQuery $authorization) {}
    public function resolveOwner(TenantContext $context, DocumentOwnerReference $owner, bool $forUpdate = false): DocumentOwnerState
    {
        if ($owner->moduleKey !== 'official.article' || $owner->objectType !== 'article' || $owner->slot !== 'body'
            || preg_match('/^[1-9][0-9]*$/D', $owner->objectId) !== 1 || strlen($owner->objectId) > 18) {
            throw BusinessException::notFound('ARTICLE_DOCUMENT_OWNER_INVALID', '不支持的资讯文档归属或槽位');
        }
        $query = Article::withTrashed()->where('id', (int) $owner->objectId);
        if ($forUpdate) {
            $query->lock(true);
        }
        $article = $query->find();
        if ($article === null) {
            throw BusinessException::notFound('ARTICLE_NOT_FOUND', '资讯不存在');
        }
        return new DocumentOwnerState($context->tenantId, $owner, $article->trashed(), hash('sha256', json_encode([
            $article->id,$article->cid,$article->update_time,$article->delete_time,$article->is_show], JSON_THROW_ON_ERROR)));
    }
    public function authorize(TenantContext $context, string $action, DocumentOwnerState $owner): void
    {
        $permission = match ($action) {
            'view' => 'official.article.detail', 'archive.read' => 'official.article.recycle.detail',
            'create','edit','version.create' => 'official.article.edit', 'archive' => 'official.article.delete',
            'restore' => 'official.article.restore','purge' => 'official.article.force-delete', default => throw BusinessException::forbidden('ARTICLE_DOCUMENT_ACTION_DENIED', '不支持的资讯文档操作'),
        };
        if (!$this->authorization->decide($context, $this->authorization->principal($context), $permission)->allowed) {
            throw BusinessException::forbidden('ARTICLE_DOCUMENT_DENIED', '无权操作资讯文档');
        }
        if (in_array($action, ['view','create','edit','version.create'], true) && $owner->archived
            || in_array($action, ['archive','archive.read','purge'], true) && !$owner->archived
            || $action === 'restore' && $owner->archived) {
            throw BusinessException::forbidden('ARTICLE_DOCUMENT_STATE_DENIED', '资讯生命周期不允许此文档操作');
        }
    }
    public function stateRevision(DocumentOwnerState $owner): string
    {
        return $owner->revision;
    }
}
