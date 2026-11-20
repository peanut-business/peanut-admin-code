<?php

declare(strict_types=1);

use app\common\http\ApiProblem;
use app\common\infrastructure\concurrency\ThinkCacheLimiterStorage;
use app\common\services\RequestGuardService;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\FlockStore;
use think\App;
use think\cache\driver\File;

final class RequestGuardServiceTest extends TestCase
{
    public function testRealFileCacheAndLockEnforceScopedLimits(): void
    {
        $dir = sys_get_temp_dir() . '/peanut-guard-' . bin2hex(random_bytes(8));
        mkdir($dir);
        try {
            $cache = new File(new App($dir), ['path' => $dir . '/cache']);
            $guard = new RequestGuardService(new ThinkCacheLimiterStorage($cache), new LockFactory(new FlockStore($dir . '/locks')));
            $guard->limit('tenant-a', 'member-1', 'update', 1, '1 minute');
            $guard->limit('tenant-b', 'member-1', 'update', 1, '1 minute');
            try {
                $guard->limit('tenant-a', 'member-1', 'update', 1, '1 minute');
                self::fail('Expected scoped rate limit');
            } catch (ApiProblem $error) {
                self::assertSame(429, $error->httpStatus);
                self::assertArrayHasKey('Retry-After', $error->headers);
            }
            $guard->preventRepeat('tenant-a', 'member-1', 'update', 'command-1', '1 minute');
            $this->expectException(ApiProblem::class);
            $guard->preventRepeat('tenant-a', 'member-1', 'update', 'command-1', '1 minute');
        } finally {
            $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
            foreach ($files as $file) $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
            rmdir($dir);
        }
    }
}
