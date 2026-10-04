<?php

declare(strict_types=1);

use app\common\execution\CurrentExecutionContext;
use app\common\execution\ExecutionContextStore;
use app\platform\services\module\ProductTenantModuleProfileService;
use PeanutAdmin\Kernel\Module\ModuleException;
use PeanutAdmin\Modules\Identity\Contract\AdminDirectoryQuery;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;
use think\App;

/** Exact source helper and native owner query, not a product install or module mutation. */
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class ProductProfileTenantBoundaryTest extends TestCase
{
    private PDO $database;
    private AdminDirectoryQuery $directory;
    private ProductTenantModuleProfileService $service;

    protected function setUp(): void
    {
        require_once dirname(__DIR__, 2) . '/vendor/topthink/framework/src/helper.php';
        new App(dirname(__DIR__, 3) . '/.local/tmp/profile-tenant-boundary-' . bin2hex(random_bytes(5)));
        $this->database = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        ThinkPhpTestConnection::fromPdo($this->database);
        $this->database->exec("CREATE TABLE pa_tenant(id INTEGER PRIMARY KEY, code TEXT COLLATE NOCASE, name TEXT, status TEXT); INSERT INTO pa_tenant VALUES(1,'default','Private name','active'),(2,'tenant-b','Other private name','active'),(3,'tenant-a','Private A','active'),(4,'suspended','Private S','suspended')");
        $this->directory = new AdminDirectoryQuery(new CurrentExecutionContext(new ExecutionContextStore()));
        $this->service = (new ReflectionClass(ProductTenantModuleProfileService::class))->newInstanceWithoutConstructor();
        (new ReflectionProperty($this->service, 'tenantDirectory'))->setValue($this->service, $this->directory);
    }

    private function select(array $codes): array
    {
        return (new ReflectionMethod($this->service, 'tenants'))->invoke($this->service, $codes);
    }

    public function testExactSetDatabaseOrderAndFixedProjectionAreRetained(): void
    {
        self::assertSame([['id' => 1, 'code' => 'default'], ['id' => 3, 'code' => 'tenant-a'], ['id' => 2, 'code' => 'tenant-b']], $this->select(['tenant-b', 'default', 'tenant-a']));
        self::assertSame([], $this->select([]));
        $source = file_get_contents((new ReflectionClass($this->service))->getFileName());
        self::assertStringNotContainsString("Db::name('tenant')", $source);
        self::assertSame(AdminDirectoryQuery::class, (new ReflectionProperty($this->service, 'tenantDirectory'))->getType()->getName());
    }

    public function testUnknownInactiveDuplicateOrDatabaseEquivalentButNonExactCodesAreRejected(): void
    {
        foreach ([['default', 'missing'], ['suspended'], ['default', 'default'], ['DEFAULT']] as $codes) {
            try {
                $this->select($codes);
                self::fail('The exact requested tenant set was not enforced.');
            } catch (ModuleException $exception) {
                self::assertSame('PRODUCT_PROFILE_TENANT_SET_INVALID', $exception->errorCode);
            }
        }
        self::assertSame(4, (int) $this->database->query('SELECT COUNT(*) FROM pa_tenant')->fetchColumn());
    }

    public function testQueryUsesTheExistingCallerTransactionAndDoesNotCommitIt(): void
    {
        try {
            think\facade\Db::transaction(function (): void {
                think\facade\Db::name('tenant')->where('id', 1)->update(['name' => 'fixture-change']);
                self::assertSame([['id' => 1, 'code' => 'default']], $this->select(['default']));
                throw new DomainException('FIXTURE_OUTER_FAILURE');
            });
        } catch (DomainException $exception) {
            self::assertSame('FIXTURE_OUTER_FAILURE', $exception->getMessage());
        }
        self::assertSame('Private name', $this->database->query('SELECT name FROM pa_tenant WHERE id=1')->fetchColumn());
    }

    public function testStorageFailureDoesNotBecomeAnEmptyTenantSet(): void
    {
        $this->database->exec('DROP TABLE pa_tenant');
        $this->expectException(think\db\exception\PDOException::class);
        $this->select(['default']);
    }
}
