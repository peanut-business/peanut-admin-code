<?php
declare(strict_types=1);

use app\platform\services\developer\DeveloperCenterCatalogService;
use PHPUnit\Framework\TestCase;

/** 验证展示事实的数量，不用路由存在冒充完整接口文档；只操作本例临时文件。 */
final class DeveloperCenterDocumentationCountTest extends TestCase
{
    public function testRoutesAndCompleteOperationsHaveSeparateCounts(): void
    {
        $root = sys_get_temp_dir() . '/peanut-catalog-count-' . bin2hex(random_bytes(8));
        mkdir($root . '/server/config', 0700, true);
        mkdir($root . '/server/generated', 0700, true);
        $input = $root . '/server/config/console.php';
        file_put_contents($input, '<?php return ["module_commands" => []];');
        file_put_contents($root . '/server/generated/api-catalog.json', json_encode([
            'inputs' => [['path' => 'server/config/console.php', 'sha256' => hash_file('sha256', $input)]],
            'endpoints' => [
                ['method' => 'GET', 'path' => '/complete', 'documented' => true, 'owner' => 'application:test'],
                ['method' => 'GET', 'path' => '/route-only', 'documented' => false, 'owner' => 'application:test'],
                ['method' => 'GET', 'path' => '/missing-contract', 'owner' => 'application:test'],
            ],
        ], JSON_THROW_ON_ERROR));
        try {
            $snapshot = (new DeveloperCenterCatalogService($root . '/server', []))->snapshot();
            self::assertSame(3, $snapshot['summary']['routes']);
            self::assertSame(1, $snapshot['summary']['generated_api_operations']);
            self::assertSame(0, $snapshot['summary']['complete_api_operations']);
            self::assertSame(1, $snapshot['summary']['partial_api_operations']);
            self::assertSame(2, $snapshot['summary']['undocumented_routes']);
            self::assertSame('not_checked', $snapshot['status']['runtime_effective']['status']);
            self::assertSame('not_recorded', $snapshot['status']['tests']['status']);
            // 目录过期时拒绝展示旧结果，不能继续用旧数量说明当前源码。
            file_put_contents($input, '<?php return ["module_commands" => [], "changed" => true];');
            $stale = (new DeveloperCenterCatalogService($root . '/server', []))->snapshot();
            self::assertSame('stale', $stale['status']['api_catalog']['status']);
            self::assertSame(0, $stale['summary']['routes']);
            self::assertSame(0, $stale['summary']['generated_api_operations']);
        } finally {
            $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
            foreach ($iterator as $item) {
                $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
            }
            rmdir($root);
        }
    }
}
