<?php

declare(strict_types=1);

namespace app\common\security;

use PeanutAdmin\Kernel\Identity\PasswordHasher;

/** Application-owned password contract layered over the reusable Core default. */
final class ApplicationPasswordPolicy
{
    public const MINIMUM_LENGTH = 12;
    public const MAXIMUM_LENGTH = 128;

    public static function hasher(): PasswordHasher
    {
        return new PasswordHasher(self::MINIMUM_LENGTH, self::MAXIMUM_LENGTH);
    }

    private function __construct() {}
}
