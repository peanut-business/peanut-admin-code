<?php

declare(strict_types=1);

use app\common\execution\CurrentExecutionContext;
use app\common\execution\ExecutionContextStore;
use PeanutAdmin\Kernel\Auth\TenantContext;
use PeanutAdmin\Kernel\Auth\ValidatedTenantSession;
use PeanutAdmin\Modules\Identity\Auth\SessionAuthenticationRecord;
use PeanutAdmin\Modules\Identity\Auth\TenantAuthService;
use PeanutAdmin\Modules\Identity\Contract\AdminDirectoryQuery;
use PeanutAdmin\Modules\Identity\Contract\TenantModuleStateQueries;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use think\facade\Cache;
use think\facade\Db;

/** Native cache/ORM behavior with synthetic in-memory data; live Token validation is tested over HTTP. */
final class AdmittedReadCacheTest extends TestCase
{
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testVersionedTenantAndInstallationReadsStayIsolatedAndTransactionsStayLive(): void
    {
        require_once dirname(__DIR__, 2) . '/vendor/topthink/framework/src/helper.php';
        $app = new class(dirname(__DIR__, 2)) extends think\App {
            public function runningInConsole(): bool { return false; }
        };
        think\Container::setInstance($app);
        $request = (new think\Request())->withServer(['REQUEST_METHOD' => 'GET']);
        $app->instance('request', $request);
        $db = new think\DbManager();
        $db->setConfig(['default' => 'cache-test', 'connections' => [
            'cache-test' => ['type' => 'sqlite', 'database' => ':memory:', 'prefix' => ''],
        ]]);
        $app->instance(think\DbManager::class, $db);
        think\Model::setDb($db);
        $cachePath = sys_get_temp_dir() . '/sales-admitted-cache-' . bin2hex(random_bytes(8)) . '/';
        $app->config->set(['default' => 'file', 'stores' => ['file' => [
            'type' => 'File', 'path' => $cachePath, 'cache_subdir' => false,
        ]]], 'cache');
        Db::execute('CREATE TABLE tenant (id INTEGER PRIMARY KEY, code TEXT, name TEXT, display_name TEXT,
            locale TEXT, timezone TEXT, status TEXT)');
        Db::execute('CREATE TABLE module_installation (id INTEGER PRIMARY KEY, module_key TEXT,
            installed_version TEXT, manifest_schema_version INTEGER, manifest_digest TEXT, status TEXT, revision INTEGER)');
        Db::name('tenant')->insertAll([
            ['id'=>1,'code'=>'a','name'=>'A','display_name'=>'A','locale'=>'zh-CN','timezone'=>'Asia/Shanghai','status'=>'active'],
            ['id'=>2,'code'=>'b','name'=>'B','display_name'=>'B','locale'=>'en-US','timezone'=>'UTC','status'=>'active'],
        ]);
        Db::name('module_installation')->insert(['id'=>1,'module_key'=>'sales.crm','installed_version'=>'1',
            'manifest_schema_version'=>1,'manifest_digest'=>'fixture','status'=>'active','revision'=>1]);
        $directory = new AdminDirectoryQuery(new CurrentExecutionContext(new ExecutionContextStore()));
        $modules = new TenantModuleStateQueries();
        $admit = static function (int $tenant, int $revision) use ($request): TenantContext {
            $context = TenantContext::fromValidatedSession(new ValidatedTenantSession(
                1, 'cache-fixture', $tenant, 1, 1, 'admin-web', new DateTimeImmutable(), 1, $revision,
            ), 'cache-fixture');
            // Only the version fields are consumed by these projections; no authentication is simulated.
            $record = (new ReflectionClass(SessionAuthenticationRecord::class))->newInstanceWithoutConstructor();
            (new ReflectionProperty($record, 'tenantRevision'))->setValue($record, $revision);
            $request->withMiddleware([TenantAuthService::class => ['context'=>$context,'record'=>$record],
                TenantModuleStateQueries::class => []]);
            return $context;
        };
        try {
            $a = $admit(1, 1);
            self::assertSame('Asia/Shanghai', $directory->tenantProfile($a)['timezone']);
            self::assertSame('active', $modules->installationRecord('sales.crm')['status']);
            Db::name('tenant')->where('id', 1)->update(['timezone'=>'Europe/London']);
            Db::name('module_installation')->where('id', 1)->update(['status'=>'maintenance','revision'=>2]);
            $a = $admit(1, 1);
            self::assertSame('Asia/Shanghai', $directory->tenantProfile($a)['timezone']);
            self::assertSame('active', $modules->installationRecord('sales.crm')['status']);
            $a = $admit(1, 2);
            self::assertSame('Europe/London', $directory->tenantProfile($a)['timezone']);
            self::assertSame('maintenance', $modules->installationRecord('sales.crm')['status']);
            self::assertNull($modules->installationRecord('absent'));
            $b = $admit(2, 2);
            self::assertSame('UTC', $directory->tenantProfile($b)['timezone']);
            self::assertNull(TenantAuthService::authenticatedRead($a));
            $a = $admit(1, 2);
            Db::startTrans();
            try {
                Db::name('tenant')->where('id', 1)->update(['timezone'=>'UTC']);
                self::assertNull(TenantAuthService::authenticatedRead($a));
                self::assertSame('UTC', $directory->tenantProfile($a)['timezone']);
                Db::name('module_installation')->where('id', 1)->update(['status'=>'active']);
                self::assertSame('active', $modules->installationRecord('sales.crm')['status']);
            } finally {
                Db::rollback();
            }
            self::assertSame('Europe/London', $directory->tenantProfile($a)['timezone']);
            $request->setMethod('POST');
            self::assertNull(TenantAuthService::authenticatedRead($a));
            $request->setMethod('GET');
            $request->withMiddleware([TenantAuthService::class => null]);
            self::assertNull(TenantAuthService::authenticatedRead($a));
        } finally {
            Cache::clear();
            foreach (glob($cachePath . '*') ?: [] as $file) { unlink($file); }
            if (is_dir($cachePath)) { rmdir($cachePath); }
        }
    }
}
