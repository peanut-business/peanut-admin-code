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
use think\db\BaseQuery;
use think\db\Raw;
use think\facade\Db;

final readonly class StorageService implements FileStorage
{
    public const DELIVERY_URL_TTL = 600;
    private SignedDeliveryTokenService $deliveryTokens;

    public function __construct(
        private StorageDriverFactory $drivers,
        private DataScopePolicy $dataScopePolicy,
        private DefaultTenantContextResolver $defaultTenant,
        private string $signingSecret,
        private string $applicationOrigin,
    ) {
        if (strlen($this->signingSecret) < 32) {
            throw new \RuntimeException('文件签名配置无效');
        }
        $this->deliveryTokens = new SignedDeliveryTokenService(
            $this->signingSecret,
            new DatabaseReplayGuard(),
            self::DELIVERY_URL_TTL,
        );
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

        $object = $this->deliverableObjectForTenant($tenantId, $fileKey);
        if ($object === null) {
            throw new \RuntimeException('文件对象当前不可交付');
        }
        return [
            'file_key' => $fileKey,
            'object_key' => $objectKey,
            'access_type' => $access,
            'url' => $this->url($object),
            'original_name' => $originalName,
        ];
    }

    public function publicUrl(string $reference): string
    {
        $reference = trim($reference);
        if ($reference === '') {
            return '';
        }
        $internal = $this->internalReference($reference);
        if ($internal !== null) {
            $object = $this->publicObject($internal);
            return $object === null ? '' : $this->url($object);
        }
        if (preg_match('#^https?://#i', $reference) === 1) {
            return $reference;
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
        $internal = $this->internalReference($reference) ?? $reference;
        if (preg_match('/^file_[0-9a-f]{32}$/D', $internal) === 1) {
            $object = $this->deliverableObjectForTenant($tenantId, $internal);
            if ($object === null || $object['access_type'] !== 'public') {
                throw new \RuntimeException('素材对象不属于当前租户');
            }
            return $internal;
        }
        $path = preg_match('#^https?://#i', $internal) === 1
            ? ltrim((string) (parse_url($internal, PHP_URL_PATH) ?? ''), '/')
            : ltrim($internal, '/');
        if (str_starts_with($path, 'storage/')) {
            $path = substr($path, 8);
        }
        $object = $this->publicObject($path);
        if ($object !== null) {
            if ((int) $object['tenant_id'] !== $tenantId || $path !== (string) $object['object_key']) {
                throw new \RuntimeException('素材对象不属于当前租户');
            }
            return (string) $object['file_key'];
        }
        if (preg_match('#^https?://#i', $reference) === 1) {
            return $reference;
        }
        throw new \RuntimeException('素材对象不属于当前租户');
    }

    public function delete(int $tenantId, string $fileKey): void
    {
        $object = $this->objectForTenant($tenantId, $fileKey);
        if ($object === null) {
            throw new \RuntimeException('文件对象不存在');
        }
        if (!$this->archive($tenantId, $fileKey)) {
            throw new \RuntimeException('文件对象状态更新失败');
        }
        try {
            $this->drivers->make($object, $object)->delete((string) $object['object_key']);
        } catch (\Throwable $error) {
            $this->restore($tenantId, $fileKey);
            throw $error;
        }
    }

    public function accessUrlForTenant(int $tenantId, string $fileKey): string
    {
        $object = $this->deliverableObjectForTenant($tenantId, $fileKey);
        if ($object === null) {
            throw new \RuntimeException('文件对象不存在或不可用');
        }
        return $this->url($object);
    }

    /** @return array{path:string,filename:string,media_type:string,temporary:bool} */
    public function openForTenant(int $tenantId, string $fileKey): array
    {
        $object = $this->deliverableObjectForTenant($tenantId, $fileKey);
        if ($object === null) {
            throw BusinessException::notFound('STORAGE_INPUT_NOT_FOUND', '文件不存在或不可用');
        }
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
        return [
            ...$this->materialize($object),
            'filename' => (string) $object['original_name'],
            'media_type' => (string) $object['media_type'],
            'disposition' => (string) $object['disposition'],
        ];
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
        return $this->find($query);
    }

    private function deliverableObjectForTenant(int $tenantId, string $fileKey): ?array
    {
        return $this->find(
            $this->objectQuery($this->logicalTenantId($tenantId))
                ->where('f.file_key', $fileKey)
                ->where('f.status', 'ready')
                ->where('t.status', 'active'),
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
        return $this->find(
            $this->objectQuery($tenantId)
                ->where($field, $reference)
                ->where('f.access_type', 'public')
                ->where('f.status', 'ready')
                ->where('t.status', 'active'),
        );
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
            $query->join('tenant t', 't.id=' . $tenantId)
                ->where('t.code', 'default')
                ->fieldRaw($tenantId . ' AS tenant_id');
        } else {
            $query->join('tenant t', 't.id=f.tenant_id');
        }
        return $query;
    }

    private function find(BaseQuery $query): ?array
    {
        $row = $query->find();
        return $row === null ? null : (is_array($row) ? $row : $row->toArray());
    }

    private function url(array $object): string
    {
        $tenantId = (int) $object['tenant_id'];
        $fileKey = (string) $object['file_key'];
        $private = $object['access_type'] === 'private';
        $token = $this->deliveryTokens->issue(
            $tenantId,
            $fileKey,
            $private ? DeliveryVisibility::Private : DeliveryVisibility::Public,
            $private ? ReplayMode::SingleUse : ReplayMode::Bounded,
            new DateTimeImmutable('now', new DateTimeZone('UTC')),
            $private ? 300 : self::DELIVERY_URL_TTL,
        );
        return rtrim($this->applicationOrigin, '/') . '/api/storage/delivery?' . http_build_query([
            'tenant_id' => $tenantId,
            'file_key' => $fileKey,
            'token' => $token,
        ]);
    }

    private function internalReference(string $reference): ?string
    {
        if (preg_match('/^file_[0-9a-f]{32}$/D', $reference) === 1) {
            return $reference;
        }
        $path = (string) (parse_url($reference, PHP_URL_PATH) ?? '');
        if ($path === '/api/storage/delivery') {
            parse_str((string) (parse_url($reference, PHP_URL_QUERY) ?? ''), $query);
            $fileKey = $query['file_key'] ?? null;
            return is_string($fileKey) && preg_match('/^file_[0-9a-f]{32}$/D', $fileKey) === 1
                ? $fileKey
                : null;
        }
        $path = ltrim($path !== '' ? $path : $reference, '/');
        return str_starts_with($path, 'storage/tenants/v1/') ? substr($path, 8) : null;
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
