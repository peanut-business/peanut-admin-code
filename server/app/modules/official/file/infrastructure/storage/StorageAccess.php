<?php
declare(strict_types=1);

namespace app\modules\official\file\infrastructure\storage;

final class StorageAccess
{
    public const PUBLIC = 'public';
    public const PRIVATE = 'private';
    public const INLINE = 'inline';
    public const ATTACHMENT = 'attachment';

    public static function assertType(string $value): string
    {
        if (!in_array($value, [self::PUBLIC, self::PRIVATE], true)) {
            throw new \InvalidArgumentException('文件访问类型无效');
        }
        return $value;
    }
}
