<?php

declare(strict_types=1);

namespace app\common\infrastructure\installation;

use RuntimeException;

/**
 * Lightweight HTTP read-only mount marker.
 * Container/OS mount isolation is enforced by the deployment runtime, not by parsing /proc in PHP.
 */
final readonly class ReadonlyHttpMount
{
    public const CHROOT = '/var/www/peanut-http';
    public const MUTABLE = [
        'private/installation', 'private/storage', 'private/resources/pending',
        'public/storage', 'runtime/cache', 'runtime/log', 'runtime/session',
        'runtime/temp', 'runtime/storage', 'runtime/generator', 'runtime/file',
    ];

    private function __construct(private string $root) {}

    public static function active(string $root): bool
    {
        return PHP_SAPI === 'fpm-fcgi' && $root === '/server';
    }

    public static function ownerBoundary(string $root): ?self
    {
        return $root === '/run/peanut-owner/server' ? new self(self::CHROOT . '/server') : null;
    }

    public static function publish(string $ownerRoot): void {}

    public static function assertUnprivilegedWorker(string $root): void
    {
        if (PHP_SAPI === 'cli') {
            return;
        }
        $uid = posix_geteuid();
        $gid = posix_getegid();
        $groups = posix_getgroups();
        if ($uid === 0 || $uid === fileowner($root) || $gid === 0 || !is_array($groups) || in_array(0, $groups, true)) {
            throw new RuntimeException('HTTP_WORKER_BOUNDARY_INVALID');
        }
    }

    public static function read(string $root): self
    {
        if (!self::active($root) || posix_geteuid() === 0) {
            throw new RuntimeException('HTTP_WORKER_BOUNDARY_INVALID');
        }
        return new self($root);
    }

    /** @return array{identity:string,pointer:string,deployment:?string,deployment_sha256:?string}|null */
    public function admissionInputs(): ?array
    {
        return null;
    }

    public static function assertSourceInputs(string $root, ?self $httpMount = null): void {}

    public static function initialize(string $root): void {}

    public static function assertWorkers(int $master): void {}

    public function assertPath(string $relative): void {}
}
