<?php
declare(strict_types=1);

namespace app\modules\official\file\services;

use app\modules\official\file\contracts\FileReferences;
use PeanutAdmin\Kernel\Context\AuthenticatedMemberContext;
use app\modules\official\file\services\storage\StorageService;
use PeanutAdmin\Kernel\Auth\TenantContext;
use PeanutAdmin\Kernel\Context\TenantSystemContext;

/** Business file references enter the physical storage layer only here. */
final readonly class FileService implements FileReferences
{
    public function __construct(
        private StorageService $storage,
        private string $applicationOrigin,
    ) {}

    public function getFileUrl(string $reference = ''): string
    {
        $reference = trim($reference);
        if ($reference === '') {
            return '';
        }
        if (preg_match('#^https?://#i', $reference) === 1) {
            return $reference;
        }
        $url = $this->storage->publicUrl($reference);
        return $url !== '' ? $url : rtrim($this->applicationOrigin, '/') . '/' . ltrim($reference, '/');
    }

    public function setTenantFileUrl(
        AuthenticatedMemberContext|TenantContext|TenantSystemContext $context,
        string $value = '',
    ): string {
        return $this->storage->normalizePublicReference($context->tenantId, $value);
    }
}
