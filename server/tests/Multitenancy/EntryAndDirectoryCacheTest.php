<?php

declare(strict_types=1);

use app\common\execution\CurrentExecutionContext;
use app\common\execution\ExecutionContextStore;
use PeanutAdmin\Kernel\Auth\TenantContext;
use PeanutAdmin\Kernel\Auth\ValidatedTenantSession;
use PeanutAdmin\Modules\Identity\Auth\SessionAuthenticationRecord;
use PeanutAdmin\Modules\Identity\Auth\TenantAuthService;
use PeanutAdmin\Modules\Identity\Contract\AdminDirectoryQuery;
use PeanutAdmin\Modules\Identity\Tenancy\Infrastructure\ThinkPhpTenantEntryBindingLookup;
use think\facade\Cache;
use think\facade\Db;

// Executable native contract: no application credentials, external database, or test dependency install.
$server = ((getenv('PEANUT_TEST_APPLICATION_ROOT') ?: dirname(__DIR__, 3)) . '/server');
$loader = require $server . '/vendor/autoload.php';
$loader->addPsr4('PeanutAdmin\\Modules\\Identity\\', $server . '/app/modules/peanut/identity/src/');
require_once $server . '/vendor/topthink/framework/src/helper.php';
$app = new class($server) extends think\App {
    public function runningInConsole(): bool { return false; }
};
think\Container::setInstance($app);
$request = (new think\Request())->withServer(['REQUEST_METHOD' => 'GET']);
$app->instance('request', $request);
$db = new think\DbManager();
$db->setConfig(['default' => 'cache-contract', 'connections' => ['cache-contract' => [
    'type' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'trigger_sql' => true,
]]]);
$app->instance(think\DbManager::class, $db);
think\Model::setDb($db);
$cachePath = $server . '/runtime/temp/entry-directory-test-' . bin2hex(random_bytes(8)) . '/';
$app->config->set(['default' => 'file', 'stores' => ['file' => [
    'type' => 'File', 'path' => $cachePath, 'cache_subdir' => false,
]]], 'cache');
$checks = 0;
$equal = static function (mixed $expected, mixed $actual, string $label) use (&$checks): void {
    if ($expected !== $actual) throw new RuntimeException($label);
    $checks++;
};
$reject = static function (callable $read, string $label) use ($equal): void {
    try { $read(); } catch (DomainException $error) {
        $equal('TENANT_ENTRY_BINDING_UNAVAILABLE', $error->getMessage(), $label);
        return;
    }
    throw new RuntimeException($label);
};
$selects = 0;
$db->listen(static function (string $sql) use (&$selects): void {
    if (str_starts_with(strtoupper(ltrim($sql)), 'SELECT')) $selects++;
});
try {
    Db::execute('CREATE TABLE tenant (id INTEGER PRIMARY KEY, code TEXT, status TEXT)');
    Db::execute('CREATE TABLE tenant_entry_binding (id INTEGER PRIMARY KEY, tenant_id INTEGER, host TEXT, client_key TEXT, status TEXT)');
    Db::execute('CREATE TABLE tenant_member (id INTEGER PRIMARY KEY, tenant_id INTEGER, display_name TEXT)');
    Db::name('tenant')->insertAll([
        ['id'=>1,'code'=>'a','status'=>'active'], ['id'=>2,'code'=>'b','status'=>'active'],
    ]);
    Db::name('tenant_entry_binding')->insertAll([
        ['id'=>1,'tenant_id'=>1,'host'=>'a.test','client_key'=>'admin-web','status'=>'active'],
        ['id'=>2,'tenant_id'=>2,'host'=>'a.test','client_key'=>'member-api','status'=>'active'],
    ]);
    $lookup = new ThinkPhpTenantEntryBindingLookup();
    $equal(['tenant_id'=>1,'tenant_code'=>'a'], $lookup->binding('a.test','admin-web'), 'binding miss');
    $before = $selects;
    $equal(true, $before > 0, 'SQL listener must observe a cache miss');
    $equal(1, $lookup->binding('a.test','admin-web')['tenant_id'], 'binding hit');
    $equal($before, $selects, 'binding hit queried database');
    $equal(2, $lookup->binding('a.test','member-api')['tenant_id'], 'client isolation');
    $equal(null, $lookup->binding('unknown.test','admin-web'), 'unknown host');
    Db::startTrans();
    try {
        Db::name('tenant_entry_binding')->where('id',1)->update(['status'=>'disabled']);
        $reject(fn() => $lookup->binding('a.test','admin-web'), 'transaction must read current binding');
    } finally { Db::rollback(); }
    $equal(1, $lookup->binding('a.test','admin-web')['tenant_id'], 'rollback must retain committed cache');
    Db::name('tenant_entry_binding')->where('id',1)->update(['tenant_id'=>2]);
    $oldEntryRevision = Cache::get('identity:tenant-entry:v2:revision');
    ThinkPhpTenantEntryBindingLookup::invalidate();
    Cache::set('identity:tenant-entry:v2:' . $oldEntryRevision . ':' . hash('sha256', "a.test\0admin-web"), ['tenant_id'=>1,'tenant_code'=>'a'], 300);
    $equal(2, $lookup->binding('a.test','admin-web')['tenant_id'], 'rebind invalidation');
    Db::name('tenant')->where('id',2)->update(['status'=>'suspended']);
    ThinkPhpTenantEntryBindingLookup::invalidate();
    $reject(fn() => $lookup->binding('a.test','admin-web'), 'tenant suspension invalidation');
    Db::name('tenant')->where('id',2)->update(['status'=>'active']);
    $equal(2, $lookup->binding('a.test','admin-web')['tenant_id'], 'inactive binding must not be cached');
    Db::name('tenant_member')->insertAll([
        ['id'=>1,'tenant_id'=>1,'display_name'=>'A'], ['id'=>2,'tenant_id'=>2,'display_name'=>'B'],
    ]);
    $admit = static function (int $tenant, int $revision) use ($request): TenantContext {
        $context = TenantContext::fromValidatedSession(new ValidatedTenantSession(
            1, 'synthetic', $tenant, 1, 1, 'admin-web', new DateTimeImmutable(), 1, $revision,
        ), 'synthetic');
        $record = (new ReflectionClass(SessionAuthenticationRecord::class))->newInstanceWithoutConstructor();
        $request->withMiddleware([TenantAuthService::class => ['context'=>$context,'record'=>$record]]);
        return $context;
    };
    $directory = new AdminDirectoryQuery(new CurrentExecutionContext(new ExecutionContextStore()));
    $actor = $admit(1,1);
    $equal([1=>'A'], $directory->memberDisplayNames($actor,[1,2]), 'directory tenant isolation');
    $before = $selects;
    $equal([1=>'A'], $directory->memberDisplayNames($actor,[2,1,1]), 'directory normalized key');
    $equal($before, $selects, 'directory hit queried database');
    Db::name('tenant_member')->where('id',1)->update(['display_name'=>'Renamed']);
    $actor = $admit(1,2);
    $equal([1=>'Renamed'], $directory->memberDisplayNames($actor,[1,2]), 'self-profile invalidation');
    Cache::set('identity:member-names:v1:1:1:' . hash('sha256', '1,2'), [1=>'A'], 300);
    $equal([1=>'Renamed'], $directory->memberDisplayNames($actor,[1,2]), 'old revision refill must not revive stale names');
    Db::name('tenant_member')->where('id',1)->update(['display_name'=>'Admin rename']);
    $actor = $admit(1,3);
    $equal([1=>'Admin rename'], $directory->memberDisplayNames($actor,[1,2]), 'admin revision invalidation');
    Db::startTrans();
    try {
        Db::name('tenant_member')->where('id',1)->update(['display_name'=>'Pending']);
        $equal([1=>'Pending'], $directory->memberDisplayNames($actor,[1,2]), 'directory transaction read');
    } finally { Db::rollback(); }
    $equal([1=>'Admin rename'], $directory->memberDisplayNames($actor,[1,2]), 'directory rollback cache');
    $request->setMethod('POST');
    Db::name('tenant_member')->where('id',1)->update(['display_name'=>'Live write']);
    $equal([1=>'Live write'], $directory->memberDisplayNames($actor,[1,2]), 'write request must not use cache');
    echo json_encode(['status'=>'PASS','assertions'=>$checks,'scope'=>'synthetic SQLite and native TP cache; no live authentication'], JSON_THROW_ON_ERROR) . "\n";
} finally {
    Cache::clear();
    foreach (glob($cachePath . '*') ?: [] as $file) unlink($file);
    if (is_dir($cachePath)) rmdir($cachePath);
}
