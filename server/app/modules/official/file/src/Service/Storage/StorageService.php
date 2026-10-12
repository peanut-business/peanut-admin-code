<?php

declare(strict_types=1);

namespace PeanutAdmin\Modules\File\Service\Storage;

use PeanutAdmin\Modules\File\Contract\FileStorage;
use app\common\exception\BusinessException;
use PeanutAdmin\Modules\File\Composition\Storage\StorageDriverFactory;
use PeanutAdmin\Modules\File\Infrastructure\Storage\StorageAccess;
use app\common\tenancy\DataScopePolicy;
use PeanutAdmin\Modules\File\Value\Storage\StoragePath;
use PeanutAdmin\Modules\File\Value\Storage\StoragePurpose;
use PeanutAdmin\Modules\File\Model\Storage\StorageAccount;
use PeanutAdmin\Modules\File\Model\Storage\FileObject;
use PeanutAdmin\Modules\File\Model\Storage\StorageRoute;
use PeanutAdmin\Modules\File\Model\Storage\StorageSpace;
use PeanutAdmin\Modules\File\Infrastructure\Storage\DatabaseReplayGuard;
use DateTimeImmutable;
use DateTimeZone;
use PeanutAdmin\FileMedia\Delivery\DeliveryVisibility;
use PeanutAdmin\FileMedia\Delivery\ReplayMode;
use PeanutAdmin\FileMedia\Delivery\SignedDeliveryTokenService;
use PeanutAdmin\FileMedia\Storage\StorageObjectKey;
use PeanutAdmin\Modules\Identity\Tenancy\DefaultTenantContextResolver;
use PeanutAdmin\Modules\Identity\Contract\AdminDirectoryQuery;
use think\db\BaseQuery;
use think\db\Raw;
use think\facade\Db;
use PeanutAdmin\Kernel\Auth\TenantContext;
use app\common\execution\CurrentExecutionContext;
use PeanutAdmin\Modules\File\Service\FileAuthorizationService;
use PeanutAdmin\Modules\File\Model\Storage\FileReference;
use app\common\services\audit\AuditContractHost;

final readonly class StorageService implements FileStorage
{
    public const DELIVERY_URL_TTL = 600;
    private SignedDeliveryTokenService $deliveryTokens;
    /** In-process receipts for objects created by this exact operation; never serialized or exported. */
    private \stdClass $newUploads;

    public function __construct(
        private StorageDriverFactory $drivers,
        private DataScopePolicy $dataScopePolicy,
        private DefaultTenantContextResolver $defaultTenant,
        private string $signingSecret,
        private string $applicationOrigin,
        private AdminDirectoryQuery $directory,
        private FileAuthorizationService $authorization,
        private CurrentExecutionContext $execution,
        private AuditContractHost $audit,
        private \PeanutAdmin\Modules\ContentTools\Service\ContentCapabilitiesService $capabilities,
    ) {
        if (strlen($this->signingSecret) < 32) {
            throw new \RuntimeException('文件签名配置无效');
        }
        $this->deliveryTokens = new SignedDeliveryTokenService(
            $this->signingSecret,
            new DatabaseReplayGuard(),
            self::DELIVERY_URL_TTL,
        );
        $this->newUploads = new \stdClass();
    }

    public function storePath(
        int $tenantId,
        ?int $memberId,
        string $purpose,
        string $sourcePath,
        string $originalName,
        string $mediaType,
    ): array {
        if ($tenantId < 1 || !is_file($sourcePath) || !is_readable($sourcePath)) {
            throw new \InvalidArgumentException('待存储文件无效');
        }
        $access = StoragePurpose::accessType($purpose);
        $current = $this->execution->current();
        $uploadActor = $current instanceof \app\common\execution\AdminExecutionContext ? $current->tenant
            : ($current instanceof \app\common\execution\ConsumerExecutionContext ? $current->member : null);
        if (($access === 'public' || $purpose === 'export.xlsx')
            && ($uploadActor === null || $uploadActor->tenantId !== $tenantId || $uploadActor->memberId !== $memberId)) {
            throw BusinessException::forbidden('FILE_UPLOAD_ACTOR_REQUIRED', '文件交付需要当前可信上传身份');
        }
        $originalName = self::filename($originalName);
        $fileKey = 'file_' . bin2hex(random_bytes(16));
        $objectKey = StoragePath::objectKey(
            $tenantId,
            $purpose,
            $fileKey,
            (string) pathinfo($originalName, PATHINFO_EXTENSION),
        );
        $route = $this->route($purpose, $access);
        $driver = $this->drivers->make($route, $route);
        $size = filesize($sourcePath);
        $sha256 = hash_file('sha256', $sourcePath);
        if (!is_int($size) || !is_string($sha256)) {
            throw new \RuntimeException('文件信息读取失败');
        }
        $detectedType = (new \finfo(FILEINFO_MIME_TYPE))->file($sourcePath);
        if (!is_string($detectedType) || $detectedType === '') {
            throw new \RuntimeException('文件真实类型无法识别');
        }
        $mediaType = $detectedType;
        $this->reserveObject($tenantId, [
            'file_key' => $fileKey,
            'purpose' => $purpose,
            'access_type' => $access,
            'storage_space_id' => (int) $route['space_id'],
            'object_key' => $objectKey,
            'disposition' => StoragePurpose::disposition($purpose),
            'original_name' => $originalName,
            'media_type' => $mediaType !== '' ? $mediaType : 'application/octet-stream',
            'size_bytes' => $size,
            'sha256' => $sha256,
            'created_by_member_id' => $memberId && $memberId > 0 ? $memberId : null,
        ]);
        try {
            $driver->put($objectKey, $sourcePath);
            if (!$this->markObjectReady($tenantId, $fileKey)) {
                throw new \RuntimeException('文件对象账本未能切换到 ready');
            }
        } catch (\Throwable $error) {
            $deleteFailure = null;
            try {
                $driver->delete($objectKey);
            } catch (\Throwable $exception) {
                $deleteFailure = $exception;
            }
            if (!$this->markObjectWriteFailed($tenantId, $fileKey)) {
                throw new \RuntimeException('文件对象账本未能记录 write_failed', 0, $error);
            }
            if ($deleteFailure !== null) {
                throw new \RuntimeException('文件对象写入失败且补偿删除失败，需按 file_key 清理', 0, $deleteFailure);
            }
            throw $error;
        }

        $this->newUploads->{$fileKey} = ['tenant_id' => $tenantId, 'member_id' => $memberId,
            'request_id' => $this->execution->requestId(), 'object_key' => $objectKey];
        $stored = [
            'file_key' => $fileKey,
            'object_key' => $objectKey,
            'access_type' => $access,
            'url' => '',
            'original_name' => $originalName,
        ];
        if ($access === 'private' && $purpose !== 'export.xlsx') {
            return $stored;
        }
        try {
            $object = $this->deliverableObjectForTenant($tenantId, $fileKey);
            if ($object === null) {
                throw new \RuntimeException('文件对象当前不可交付');
            }
            $stored['url'] = $purpose === 'export.xlsx' ? $this->accessUrlForTenant($tenantId, $fileKey)
                : ($access === 'public' ? $this->url($object) : '');
            return $stored;
        } catch (\Throwable $error) {
            try {
                $this->discardFailedUpload($uploadActor, $stored);
            } catch (\Throwable $compensation) {
                \app\common\infrastructure\runtime\OperationalLog::warning($this->execution, 'file_upload_compensation_failed', [
                    'file_key' => $fileKey, 'exception' => $compensation::class,
                ]);
            }
            throw $error;
        }
    }

    public function publicUrl(string $reference): string
    {
        $reference = trim($reference);
        if ($reference === '') {
            return '';
        }
        if (preg_match('/^file_[0-9a-f]{32}$/D', $reference) !== 1) {
            return '';
        }
        $object = $this->publicObject($reference);
        return $object === null ? '' : $this->url($object);
    }

    public function normalizePublicReference(int $tenantId, string $reference): string
    {
        $reference = trim($reference);
        if ($reference === '') {
            return '';
        }
        if (preg_match('/^file_[0-9a-f]{32}$/D', $reference) === 1) {
            $object = $this->deliverableObjectForTenant($tenantId, $reference);
            if ($object === null || $object['access_type'] !== 'public' || $object['purpose'] === 'attachment.sensitive') {
                throw new \RuntimeException('素材对象不属于当前租户');
            }
            return $reference;
        }
        throw BusinessException::invalid('FILE_REFERENCE_INVALID', '仅允许稳定资源引用，不允许交付链接或外部地址');
    }

    /** File-internal metadata rollback only; absent from FileStorage and all HTTP actions. */
    public function discardFailedUpload(TenantContext|\PeanutAdmin\Kernel\Context\AuthenticatedMemberContext $context, array $stored): void
    {
        $key = $stored['file_key'] ?? '';
        $receipt = is_string($key) ? ($this->newUploads->{$key} ?? null) : null;
        $actor = $context instanceof TenantContext ? $this->execution->tenantAdmin() : $this->execution->member();
        if (!is_array($receipt) || $actor->tenantId !== $context->tenantId || $actor->memberId !== $context->memberId
            || $receipt['tenant_id'] !== $context->tenantId || $receipt['member_id'] !== $context->memberId
            || $receipt['request_id'] !== $this->execution->requestId()
            || $receipt['object_key'] !== ($stored['object_key'] ?? null)) {
            throw BusinessException::forbidden('FILE_UPLOAD_COMPENSATION_DENIED', '仅可补偿本次新上传');
        }
        unset($this->newUploads->{$key});
        $object = $this->objectForTenant($context->tenantId, $key);
        if ($object === null) {
            throw BusinessException::notFound('FILE_UPLOAD_COMPENSATION_NOT_FOUND', '上传资源不存在');
        }
        Db::transaction(function () use ($context, $key): void {
            $locked = $this->ownedObjects($context->tenantId)->where('file_key', $key)->lock(true)->find();
            $locked = is_array($locked) ? $locked : $locked?->toArray();
            if ($locked === null || (int) $locked['created_by_member_id'] !== $context->memberId || $locked['status'] !== 'ready'
                || \PeanutAdmin\Modules\File\Model\File::withTrashed()->where('file_key', $key)->find() !== null
                || FileReference::where('file_key', $key)->find() !== null
                || Db::name('file_derivative')->where('source_file_key|derivative_file_key', $key)->find() !== null) {
                throw BusinessException::conflict('FILE_UPLOAD_COMPENSATION_CONFLICT', '上传已被持久引用或状态改变');
            }
            if (!$this->archive($context->tenantId, $key)) {
                throw BusinessException::conflict('FILE_REVISION_CONFLICT', '上传状态已改变');
            }
        });
        try {
            $receipt = $this->drivers->make($object, $object)->delete((string) $object['object_key']);
            $metadata = ['provider_delete_acknowledged' => true, 'provider_outcome' => $receipt->outcome];
            $event = 'file.upload.compensated';
        } catch (\Throwable) {
            // Remain archived and non-deliverable; the original metadata error must survive.
            $metadata = ['provider_delete_acknowledged' => false, 'cleanup_pending' => true];
            $event = 'file.upload.compensation_pending';
        }
        if ($context instanceof TenantContext) {
            $this->audit->appendTenantMember($context, $event, 'file.upload.compensate', 'file', $key, null, null, 0, null, $metadata);
        } else {
            $this->audit->appendTenantSystem($context->tenantId, $event, 'file.upload.compensate', $context->requestId,
                [...$metadata, 'file_key' => $key, 'initiating_member_id' => $context->memberId]);
        }
    }

    public function delete(int $tenantId, string $fileKey): void
    {
        $object = $this->objectForTenant($tenantId, $fileKey);
        if ($object === null) {
            throw new \RuntimeException('文件对象不存在');
        }
        $context = $this->execution->tenantAdmin();
        $this->authorization->requireManagementPermission($context, 'official.file.delete');
        $this->authorization->authorize($context, $object, 'manage');
        Db::transaction(function () use ($fileKey, $context): void {
            $this->ownedObjects($context->tenantId)->where('file_key', $fileKey)->lock(true)->find();
            if (FileReference::where('file_key', $fileKey)->lock(true)->find() !== null
                || \PeanutAdmin\Modules\File\Model\Storage\FileDerivative::where('source_file_key', $fileKey)->find() !== null
                || \PeanutAdmin\Modules\File\Model\Storage\FileDerivative::where('derivative_file_key', $fileKey)->find() !== null) {
                throw BusinessException::conflict('FILE_REFERENCED', '资源仍被引用，不能删除');
            }
            $this->audit->appendTenantMember($context, 'file.delete.intent', 'file.manage', 'file', $fileKey);
            if (!$this->archive($context->tenantId, $fileKey)) {
                throw BusinessException::conflict('FILE_REVISION_CONFLICT', '资源状态已改变');
            }
        });
        try {
            $this->drivers->make($object, $object)->delete((string) $object['object_key']);
        } catch (\Throwable $error) {
            $this->restore($tenantId, $fileKey);
            $this->audit->appendTenantMember($context, 'file.delete.failed', 'file.manage', 'file', $fileKey,
                null, null, 0, null, ['provider_delete_failed' => true]);
            throw $error;
        }
        $this->audit->appendTenantMember($context, 'file.delete.completed', 'file.manage', 'file', $fileKey);
    }

    public function accessUrlForTenant(int $tenantId, string $fileKey): string
    {
        $object = $this->deliverableObjectForTenant($tenantId, $fileKey);
        if ($object === null) {
            throw new \RuntimeException('文件对象不存在或不可用');
        }
        $context = $this->execution->tenantAdmin();
        $grant = $this->authorization->authorize($context, $object, 'download_original');
        return $this->url($object, $grant, 'download_original');
    }

    public function requireOriginalDownloadPermission(int $tenantId): void
    {
        $context = $this->execution->tenantAdmin();
        if ($context->tenantId !== $tenantId) {
            throw BusinessException::forbidden('FILE_ACCESS_DENIED', '无权访问其他租户资源');
        }
        $this->authorization->requireOriginalDownloadPermission($context);
    }

    /** @return array{path:string,filename:string,media_type:string,temporary:bool} */
    public function openForTenant(int $tenantId, string $fileKey): array
    {
        $object = $this->deliverableObjectForTenant($tenantId, $fileKey);
        if ($object === null) {
            throw BusinessException::notFound('STORAGE_INPUT_NOT_FOUND', '文件不存在或不可用');
        }
        $this->authorization->authorize($this->execution->tenantAdmin(), $object, 'download_original');
        return [
            ...$this->materialize($object),
            'filename' => (string) $object['original_name'],
            'media_type' => (string) $object['media_type'],
        ];
    }

    /** @return array{path:string,filename:string,media_type:string,disposition:string,temporary:bool} */
    public function authorizedDownload(int $tenantId, string $fileKey, string $token): array
    {
        $object = $this->deliverableObjectForTenant($tenantId, $fileKey);
        if ($object === null) {
            throw BusinessException::notFound('STORAGE_DELIVERY_NOT_FOUND', '文件不存在或不可用');
        }
        try {
            $claims = $this->deliveryTokens->verifyAndConsume(
                $token,
                $tenantId,
                $fileKey,
                new DateTimeImmutable('now', new DateTimeZone('UTC')),
            );
        } catch (\Throwable) {
            throw new BusinessException('STORAGE_DELIVERY_INPUT_INVALID', 422, '文件链接无效或已过期');
        }
        $expectedVisibility = $object['access_type'] === 'private'
            ? DeliveryVisibility::Private
            : DeliveryVisibility::Public;
        if ($claims['visibility'] !== $expectedVisibility) {
            throw new BusinessException('STORAGE_DELIVERY_INPUT_INVALID', 422, '文件链接无效或已过期');
        }
        $grant = Db::name('file_delivery_grant')->where('token_id_hash', hash('sha256', $claims['token_id']))->find();
        if (!is_array($grant) || (int) $grant['subject_tenant_id'] !== $tenantId
            || $grant['file_key'] !== $fileKey || (int) $grant['file_revision'] !== (int) $object['revision']
            || $grant['visibility'] !== $object['access_type']
            || strtotime((string) $grant['expires_at'] . ' UTC') <= time()) {
            throw BusinessException::forbidden('FILE_DELIVERY_REVOKED', '资源链接已失效');
        }
        $source = $this->deliverableObjectForTenant($tenantId, (string) $grant['source_file_key']);
        if ($source === null || (int) $source['revision'] !== (int) $grant['source_revision']
            || $source['access_type'] !== $grant['source_visibility']) {
            throw BusinessException::forbidden('FILE_DELIVERY_REVOKED', '资源链接已失效');
        }
        if ($grant['operation'] === 'public') {
            if (!hash_equals((string) $grant['authorization_revision'], $this->authorization->authorizePublic($source))) {
                throw BusinessException::forbidden('FILE_DELIVERY_REVOKED', '资源授权已改变');
            }
            $this->assertPublicDerivative($source);
        } else {
            $subject = json_decode((string) $grant['subject_json'], true, 16, JSON_THROW_ON_ERROR);
            $context = $this->authorization->grantContext($subject);
            if ($context->authorizationRevision !== (int) $subject['authorization_revision']) {
                throw BusinessException::forbidden('FILE_DELIVERY_REVOKED', '资源授权已改变');
            }
            $live = $this->authorization->authorize($context, $source, (string) $grant['operation'], $grant['document_id'] === null ? null : (int) $grant['document_id']);
            if (!hash_equals((string) $grant['authorization_revision'], $live['authorization_revision'])
                || $grant['reference_digest'] !== $live['reference_digest']) {
                throw BusinessException::forbidden('FILE_DELIVERY_REVOKED', '资源授权已改变');
            }
        }
        return [
            ...$this->materialize($object),
            'filename' => (string) $object['original_name'],
            'media_type' => (string) $object['media_type'],
            'disposition' => $grant['operation'] !== 'download_original'
                && in_array(strtolower((string) $object['media_type']), ['image/png', 'image/jpeg', 'image/webp', 'image/gif', 'audio/mpeg', 'audio/ogg', 'audio/wav', 'video/mp4', 'video/webm', 'video/ogg'], true)
                ? 'inline' : 'attachment',
        ];
    }

    /** The File-owned facade returns no storage account, credential or physical address. */
    public function resolveAuthorized(TenantContext $context, string $fileKey, string $operation, ?int $documentId = null, ?string $variantKey = null, ?string $viewerAdapterId = null): array
    {
        $source = $this->deliverableObjectForTenant($context->tenantId, $fileKey);
        if ($source === null) {
            throw BusinessException::notFound('FILE_NOT_FOUND', '资源不存在');
        }
        $grant = $this->authorization->authorize($context, $source, $operation, $documentId);
        $canDownload = false;
        try {
            $this->authorization->authorize($context, $source, 'download_original');
            $canDownload = true;
        } catch (BusinessException) {
            // Preview never inherits an independent original-download right.
        }
        if ($operation === 'manage') {
            return ['file_key' => $fileKey, 'revision' => (int) $source['revision'], 'can_manage' => true];
        }
        $target = $source;
        if ($operation === 'preview' && (!$canDownload || $variantKey !== null)) {
            $query = Db::name('file_derivative')->where('source_file_key', $fileKey);
            if ($this->dataScopePolicy->usesTenantColumn()) {
                $query->where('tenant_id', $context->tenantId);
            }
            if ($variantKey !== null) {
                $query->where('variant_key', $variantKey);
            }
            $variant = $query->order('variant_key')->find();
            $target = is_array($variant) ? $this->deliverableObjectForTenant($context->tenantId, (string) $variant['derivative_file_key']) : null;
            if ($target === null || $target['file_key'] === $fileKey || $target['sha256'] === $source['sha256']
                || !in_array($target['media_type'], ['image/png', 'image/jpeg', 'image/webp', 'application/pdf'], true)) {
                return ['file_key' => $fileKey, 'state' => 'preview_unavailable', 'can_download_original' => $canDownload, 'url' => null];
            }
        }
        $capability = $this->capabilities->resolve(new \PeanutAdmin\Modules\ContentTools\Contract\AuthorizedContentContext(
            'file_resource', (string) $target['media_type'], true, false, $canDownload,
            $grant['authorization_revision'], 'browser', $this->authorization->contentProviderModules($context),
        ), 'view', $viewerAdapterId, null);
        if ($operation === 'preview' && !$capability['canView']) {
            return ['file_key' => $fileKey, 'state' => $capability['state'], 'capabilities' => $capability, 'can_download_original' => $canDownload, 'url' => null];
        }
        return [
            'file_key' => $fileKey, 'delivery_file_key' => (string) $target['file_key'], 'source_file_key' => $fileKey,
            'source_sha256' => (string) $source['sha256'], 'media_type' => (string) $target['media_type'],
            'original_name' => (string) $source['original_name'], 'revision' => (int) $source['revision'],
            'state' => 'available', 'purpose' => $operation === 'preview' ? 'preview' : 'download_original',
            'bytes' => $target['file_key'] === $fileKey ? 'original' : 'derivative',
            'can_download_original' => $canDownload, 'authorization_revision' => $grant['authorization_revision'],
            'capabilities' => $capability,
            'expires_at' => (time() + 300) * 1000,
            'url' => $this->url($target, $grant, $operation, $source, $documentId),
        ];
    }

    /** Server-side derivative binding inspects actual bytes under current management authorization. */
    public function verifyImageDerivative(TenantContext $context, string $fileKey, int $width, int $height, string $mediaType): void
    {
        $object = $this->deliverableObjectForTenant($context->tenantId, $fileKey);
        if ($object === null) {
            throw BusinessException::notFound('FILE_DERIVATIVE_NOT_FOUND', '衍生资源不存在');
        }
        $this->authorization->authorize($context, $object, 'manage');
        $materialized = $this->materialize($object);
        try {
            $metadata = (new \PeanutAdmin\FileMedia\Media\ImageMetadataInspector())->inspect($materialized['path']);
            if ($metadata->width !== $width || $metadata->height !== $height || $metadata->mediaType !== $mediaType) {
                throw BusinessException::invalid('FILE_DERIVATIVE_CONTENT_MISMATCH', '衍生图内容与声明不一致');
            }
        } finally {
            if ($materialized['temporary']) {
                @unlink($materialized['path']);
            }
        }
    }

    /** @return array{path:string,temporary:bool} */
    private function materialize(array $object): array
    {
        $driver = $this->drivers->make($object, $object);
        $path = $driver->localPath((string) $object['object_key']);
        $temporary = false;
        if ($path === null) {
            $path = tempnam(sys_get_temp_dir(), 'peanut-storage-open-');
            if (!is_string($path)) {
                throw new \RuntimeException('文件临时空间不可用');
            }
            $temporary = true;
            try {
                $driver->downloadTo((string) $object['object_key'], $path);
            } catch (\Throwable $error) {
                if (is_file($path)) {
                    unlink($path);
                }
                throw $error;
            }
        }
        if (!is_file($path) || !is_readable($path)) {
            if ($temporary && is_file($path)) {
                unlink($path);
            }
            throw BusinessException::notFound('STORAGE_DELIVERY_NOT_FOUND', '文件不存在或不可用');
        }
        if (!hash_equals((string) $object['sha256'], (string) hash_file('sha256', $path))) {
            if ($temporary) { unlink($path); }
            throw BusinessException::conflict('FILE_BYTES_CHANGED', '资源内容与登记不一致');
        }
        return ['path' => $path, 'temporary' => $temporary];
    }

    private function route(string $purpose, string $access): array
    {
        $access = StorageAccess::assertType($access);
        $row = $this->routeRow($purpose, $access) ?? $this->routeRow('default.' . $access, $access);
        if ($row === null) {
            throw new \RuntimeException('文件用途没有可用的存储路由');
        }
        return $row;
    }

    private function objectForTenant(int $tenantId, string $fileKey, bool $readyOnly = true): ?array
    {
        $query = $this->objectQuery($this->logicalTenantId($tenantId))->where('f.file_key', $fileKey);
        if ($readyOnly) {
            $query->where('f.status', 'ready');
        }
        return $this->findOwnedObject($query, false);
    }

    private function deliverableObjectForTenant(int $tenantId, string $fileKey): ?array
    {
        return $this->findOwnedObject(
            $this->objectQuery($this->logicalTenantId($tenantId))
                ->where('f.file_key', $fileKey)
                ->where('f.status', 'ready'),
            true,
        );
    }

    private function publicObject(string $reference): ?array
    {
        $reference = trim($reference);
        $field = 'f.file_key';
        $tenantId = $this->dataScopePolicy->usesTenantColumn() ? null : $this->standaloneTenantId();
        if (preg_match('/^file_[0-9a-f]{32}$/D', $reference) !== 1) {
            $reference = ltrim($reference, '/');
            if (str_starts_with($reference, 'storage/')) {
                $reference = substr($reference, 8);
            }
            if (preg_match('#^tenants/v1/([1-9][0-9]*)/#D', $reference, $matches) !== 1) {
                return null;
            }
            $reference = StorageObjectKey::assert($reference);
            $field = 'f.object_key';
            $referenceTenantId = (int) $matches[1];
            if ($tenantId !== null && $referenceTenantId !== $tenantId) {
                return null;
            }
            $tenantId = $referenceTenantId;
        }
        $object = $this->findOwnedObject(
            $this->objectQuery($tenantId)
                ->where($field, $reference)
                ->where('f.access_type', 'public')
                ->where('f.status', 'ready'),
            true,
        );
        if ($object !== null) {
            $this->authorization->authorizePublic($object);
            $this->assertPublicDerivative($object);
        }
        return $object;
    }

    private function assertPublicDerivative(array $object): void
    {
        $query = Db::name('file_derivative')->where('derivative_file_key', $object['file_key']);
        if ($this->dataScopePolicy->usesTenantColumn()) { $query->where('tenant_id', (int) $object['tenant_id']); }
        $derivative = $query->find();
        if (is_array($derivative)) {
            $source = $this->deliverableObjectForTenant((int) $object['tenant_id'], (string) $derivative['source_file_key']);
            if ($source === null || $source['access_type'] !== 'public') {
                throw BusinessException::forbidden('FILE_PUBLIC_ACCESS_DENIED', '资源不能公开交付');
            }
            $this->authorization->authorizePublic($source);
        }
    }

    private function reserveObject(int $tenantId, array $data): void
    {
        $tenantId = $this->logicalTenantId($tenantId);
        if (!str_starts_with((string) ($data['object_key'] ?? ''), $this->ownerPrefix($tenantId))) {
            throw new \DomainException('STORAGE_OBJECT_OWNER_MISMATCH');
        }
        FileObject::create([
            ...$data,
            ...($this->dataScopePolicy->usesTenantColumn() ? ['tenant_id' => $tenantId] : []),
            'status' => 'pending_write',
            'revision' => 1,
            'created_at' => new Raw('UTC_TIMESTAMP(3)'),
            'updated_at' => new Raw('UTC_TIMESTAMP(3)'),
            'archived_at' => null,
        ]);
    }

    private function markObjectReady(int $tenantId, string $fileKey): bool
    {
        return $this->changeStatus($tenantId, $fileKey, 'pending_write', 'ready');
    }

    private function markObjectWriteFailed(int $tenantId, string $fileKey): bool
    {
        return $this->changeStatus($tenantId, $fileKey, 'pending_write', 'write_failed');
    }

    private function archive(int $tenantId, string $fileKey): bool
    {
        return $this->ownedObjects($this->logicalTenantId($tenantId))
            ->where('file_key', $fileKey)->where('status', 'ready')
            ->update([
                'status' => 'archived',
                'archived_at' => new Raw('UTC_TIMESTAMP(3)'),
                'updated_at' => new Raw('UTC_TIMESTAMP(3)'),
                'revision' => new Raw('revision+1'),
            ]) === 1;
    }

    private function restore(int $tenantId, string $fileKey): void
    {
        $this->ownedObjects($this->logicalTenantId($tenantId))
            ->where('file_key', $fileKey)->where('status', 'archived')
            ->update([
                'status' => 'ready',
                'archived_at' => null,
                'updated_at' => new Raw('UTC_TIMESTAMP(3)'),
                'revision' => new Raw('revision+1'),
            ]);
    }

    private function routeRow(string $routeKey, string $access): ?array
    {
        return $this->find(
            StorageRoute::alias('r')
                ->join('storage_space s', 's.id=r.space_id')
                ->join('storage_account a', 'a.id=s.account_id')
                ->where('r.route_key', $routeKey)->where('r.access_type', $access)
                ->where('s.access_type', $access)->where('s.status', 'active')->where('a.status', 'active')
                ->field('a.id AS account_id,a.account_key,a.driver,a.name AS account_name,a.credential_ciphertext,a.credential_key_version,a.status AS account_status')
                ->field('s.id AS space_id,s.space_key,s.name AS space_name,s.access_type,s.bucket,s.region,s.endpoint,s.access_domain,s.local_path,s.status AS space_status'),
        );
    }

    private function changeStatus(int $tenantId, string $fileKey, string $from, string $to): bool
    {
        return $this->ownedObjects($this->logicalTenantId($tenantId))
            ->where('file_key', $fileKey)->where('status', $from)
            ->update([
                'status' => $to,
                'updated_at' => new Raw('UTC_TIMESTAMP(3)'),
                'revision' => new Raw('revision+1'),
            ]) === 1;
    }

    private function ownedObjects(int $tenantId): BaseQuery
    {
        return FileObject::where([])
            ->whereLike('object_key', $this->ownerPrefix($tenantId) . '%');
    }

    private function logicalTenantId(int $tenantId): int
    {
        if ($tenantId < 1) {
            throw new \DomainException('STORAGE_TENANT_INVALID');
        }
        if ($this->dataScopePolicy->usesTenantColumn()) {
            return $tenantId;
        }
        $defaultTenantId = $this->standaloneTenantId();
        if ($tenantId !== $defaultTenantId) {
            throw new \DomainException('STORAGE_OBJECT_OWNER_MISMATCH');
        }
        return $defaultTenantId;
    }

    private function standaloneTenantId(): int
    {
        return $this->defaultTenant->system(
            'storage-service',
            'storage.resolve-default-tenant',
            'storage-service-default-tenant',
        )->tenantId;
    }

    private function ownerPrefix(int $tenantId): string
    {
        return 'tenants/v1/' . $tenantId . '/';
    }

    /**
     * 文件模块自己的受控读取边界：私有读取固定租户，公开查询固定 public。
     * 签名交付没有员工会话，不从全局登录上下文推导范围，也不提供通用过滤开关。
     */
    private function objectQuery(?int $tenantId): BaseQuery
    {
        if ($tenantId !== null && $tenantId < 1) {
            throw new \DomainException('STORAGE_TENANT_INVALID');
        }
        $query = Db::name('file_object')->alias('f')
            ->join('storage_space s', 's.id=f.storage_space_id')
            ->join('storage_account a', 'a.id=s.account_id')
            ->field('f.*,a.id AS account_id,a.account_key,a.driver,a.name AS account_name,a.credential_ciphertext,a.credential_key_version,a.status AS account_status')
            ->field('s.space_key,s.name AS space_name,s.bucket,s.region,s.endpoint,s.access_domain,s.local_path,s.status AS space_status');
        if ($tenantId !== null) {
            $query->whereLike('f.object_key', $this->ownerPrefix($tenantId) . '%');
            if ($this->dataScopePolicy->usesTenantColumn()) {
                $query->where('f.tenant_id', $tenantId);
            }
        } else {
            // 无指定租户仅用于公共资源查找，不能返回私有对象。
            $query->where('f.access_type', 'public');
        }
        if (!$this->dataScopePolicy->usesTenantColumn()) {
            if (!is_int($tenantId) || $tenantId < 1) {
                throw new \LogicException('STORAGE_STANDALONE_TENANT_UNAVAILABLE');
            }
            // Identity resolved and validated the default owner before this query.
            $query->fieldRaw($tenantId . ' AS tenant_id');
        }
        return $query;
    }

    private function findOwnedObject(BaseQuery $query, bool $requireActive): ?array
    {
        $row = $this->find($query);
        if ($row === null) {
            return null;
        }
        $status = $this->directory->tenantStatus((int) ($row['tenant_id'] ?? 0));
        if ($status === null || ($requireActive && $status !== 'active')) {
            return null;
        }
        return $row;
    }

    private function find(BaseQuery $query): ?array
    {
        $row = $query->find();
        return $row === null ? null : (is_array($row) ? $row : $row->toArray());
    }

    private function url(array $object, ?array $grant = null, string $operation = 'public', ?array $source = null, ?int $documentId = null): string
    {
        $tenantId = (int) $object['tenant_id'];
        $fileKey = (string) $object['file_key'];
        $private = $object['access_type'] === 'private';
        if ($private && $grant === null) {
            $grant = $this->authorization->authorize($this->execution->tenantAdmin(), $object, 'download_original');
            $operation = 'download_original';
        }
        $source ??= $object;
        $tokenId = bin2hex(random_bytes(16));
        $context = $grant['context'] ?? null;
        Db::name('file_delivery_grant')->insert([
            'token_id_hash' => hash('sha256', $tokenId), 'subject_tenant_id' => $tenantId,
            'file_key' => $fileKey, 'file_revision' => (int) $object['revision'], 'visibility' => $object['access_type'],
            'source_file_key' => $source['file_key'], 'source_revision' => (int) $source['revision'],
            'source_visibility' => $source['access_type'],
            'operation' => $operation, 'document_id' => $documentId,
            'authorization_revision' => $grant['authorization_revision'] ?? $this->authorization->authorizePublic($source),
            'reference_digest' => $grant['reference_digest'] ?? null,
            'subject_json' => $context instanceof TenantContext ? json_encode([
                'tenant_id' => $context->tenantId, 'account_id' => $context->accountId, 'member_id' => $context->memberId,
                'session_key' => $context->sessionKey, 'client_key' => $context->clientKey,
                'authorization_revision' => $context->authorizationRevision,
            ], JSON_THROW_ON_ERROR) : null,
            'expires_at' => gmdate('Y-m-d H:i:s', time() + ($private || $grant !== null ? 300 : self::DELIVERY_URL_TTL)),
        ]);
        $token = $this->deliveryTokens->issue(
            $tenantId,
            $fileKey,
            $private ? DeliveryVisibility::Private : DeliveryVisibility::Public,
            $private ? ReplayMode::SingleUse : ReplayMode::Bounded,
            new DateTimeImmutable('now', new DateTimeZone('UTC')),
            $private ? 300 : self::DELIVERY_URL_TTL,
            $tokenId,
        );
        return rtrim($this->applicationOrigin, '/') . '/api/storage/delivery?' . http_build_query([
            'tenant_id' => $tenantId,
            'file_key' => $fileKey,
            'token' => $token,
        ]);
    }

    private static function filename(string $value): string
    {
        $value = trim(str_replace(["\0", '/', "\\"], '_', $value));
        if ($value === '') {
            throw new \InvalidArgumentException('文件名无效');
        }
        return mb_substr($value, 0, 255);
    }
}
