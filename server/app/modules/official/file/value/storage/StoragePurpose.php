<?php
declare(strict_types=1);

namespace app\modules\official\file\value\storage;

use app\modules\official\file\infrastructure\storage\StorageAccess;

final class StoragePurpose
{
    private const ACCESS = [
        'material.image' => StorageAccess::PUBLIC,
        'material.image-variant' => StorageAccess::PUBLIC,
        'material.video' => StorageAccess::PUBLIC,
        'material.file' => StorageAccess::PUBLIC,
        'member.avatar' => StorageAccess::PUBLIC,
        'article.asset' => StorageAccess::PUBLIC,
        'decoration.asset' => StorageAccess::PUBLIC,
        'website.asset' => StorageAccess::PUBLIC,
        'export.xlsx' => StorageAccess::PRIVATE,
        'export.csv' => StorageAccess::PRIVATE,
        'attachment.sensitive' => StorageAccess::PRIVATE,
    ];

    public static function accessType(string $purpose): string
    {
        $purpose = trim($purpose);
        if (!isset(self::ACCESS[$purpose])) {
            throw new \InvalidArgumentException('文件用途未登记');
        }
        return self::ACCESS[$purpose];
    }

    public static function disposition(string $purpose): string
    {
        return str_starts_with($purpose, 'export.')
            ? StorageAccess::ATTACHMENT
            : StorageAccess::INLINE;
    }

}
