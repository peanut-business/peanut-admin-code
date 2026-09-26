<?php

declare(strict_types=1);

namespace tests\Unit;

use app\common\execution\CurrentExecutionContext;
use app\common\execution\ExecutionContextStore;
use app\platform\services\ApplicationTenantBootstrapService;
use app\platform\services\CoreTenantOwnerAdminProvisioner;
use PDO;
use PeanutAdmin\Modules\Identity\Contract\AdminDirectoryQuery;
use PeanutAdmin\Modules\Identity\Contract\TenantAuthorizationCommands;
use PeanutAdmin\Modules\Integration\Contract\ExternalIntegrationBootstrapCommands;
use PeanutAdmin\Modules\Notification\Contract\NotificationBootstrapCommands;
use PeanutAdmin\Modules\Settings\Contract\TenantSettingsCommands;
use PeanutAdmin\Modules\Settings\Contract\TenantSettingsQuery;
use PeanutAdmin\Modules\Task\Contract\TaskBootstrapCommands;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use think\App;
use think\DbManager;

/** Real Identity read and host, isolated SQLite with no application schema; no real onboarding is performed. */
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class ProvisionedOwnerBoundaryTest extends TestCase
{
    private PDO $database;
    private AdminDirectoryQuery $directory;
    private CoreTenantOwnerAdminProvisioner $host;

    protected function setUp(): void
    {
        $root = dirname(__DIR__, 3);
        $temporary = $root . '/.local/tmp/provisioned-owner-tests';
        if (!is_dir($temporary) && !mkdir($temporary, 0700, true) && !is_dir($temporary)) {
            throw new \RuntimeException('PROVISIONED_OWNER_TEST_DIRECTORY_UNAVAILABLE');
        }
        self::assertStringStartsWith($root . '/.local/tmp/', (string) realpath($temporary));
        require_once $root . '/server/vendor/topthink/framework/src/helper.php';
        $app = new App($temporary . '/case-' . bin2hex(random_bytes(5)));
        $this->database = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        \ThinkPhpTestConnection::fromPdo($this->database);
        $this->database->exec(<<<'SQL'
            CREATE TABLE pa_tenant (id INTEGER PRIMARY KEY, status TEXT);
            CREATE TABLE pa_account (id INTEGER PRIMARY KEY, status TEXT);
            CREATE TABLE pa_tenant_member (id INTEGER PRIMARY KEY, tenant_id INTEGER, account_id INTEGER, status TEXT);
            CREATE TABLE pa_member_role (tenant_id INTEGER, tenant_member_id INTEGER, role_id INTEGER);
            CREATE TABLE pa_role (id INTEGER PRIMARY KEY, tenant_id INTEGER, "key" TEXT, is_builtin INTEGER, status TEXT);
            INSERT INTO pa_tenant VALUES (1,'provisioning'),(2,'active');
            INSERT INTO pa_account VALUES (101,'active'),(202,'active');
            INSERT INTO pa_tenant_member VALUES (11,1,101,'active'),(22,2,202,'active');
            INSERT INTO pa_role VALUES (31,1,'core.tenant-owner',1,'active'),(32,2,'core.tenant-owner',1,'active');
            INSERT INTO pa_member_role VALUES (1,11,31),(2,22,32);
            SQL);
        $contexts = new ExecutionContextStore();
        $this->directory = new AdminDirectoryQuery(new CurrentExecutionContext($contexts));
        $notifications = $this->createMock(NotificationBootstrapCommands::class);
        $tasks = $this->createMock(TaskBootstrapCommands::class);
        // Without the application-owned schema, the existing native bootstrap deliberately returns before seeding.
        $bootstrap = new ApplicationTenantBootstrapService(
            $notifications,
            $tasks,
            $contexts,
            $this->createStub(TenantSettingsQuery::class),
            $this->createStub(TenantSettingsCommands::class),
            (new ReflectionClass(ExternalIntegrationBootstrapCommands::class))->newInstanceWithoutConstructor(),
            (new ReflectionClass(TenantAuthorizationCommands::class))->newInstanceWithoutConstructor(),
            $app->make(DbManager::class),
        );
        $this->host = new CoreTenantOwnerAdminProvisioner($bootstrap, $this->directory);
    }

    public function testHostUsesThePublishedOwnerCheckInsteadOfPrivateIdentityJoins(): void
    {
        $source = file_get_contents((new ReflectionClass(CoreTenantOwnerAdminProvisioner::class))->getFileName());
        self::assertStringContainsString('AdminDirectoryQuery $directory', $source);
        self::assertStringContainsString('->isProvisionedOwner(', $source);
        self::assertStringNotContainsString('Db::', $source);
    }

    public function testProvisioningAndActiveTenantKeepTheExistingFirstOwnerTuple(): void
    {
        self::assertTrue($this->directory->isProvisionedOwner(1, 101, 11, 31));
        self::assertSame(11, $this->host->provision(1, 101, 11, 31, 'alpha', 'Alpha'));
        self::assertSame(22, $this->host->provision(2, 202, 22, 32, 'beta', 'Beta'));
        self::assertSame(2, (int) $this->database->query('SELECT COUNT(*) FROM pa_tenant_member')->fetchColumn());
    }

    public static function brokenRelationships(): array
    {
        return [
            'inactive account' => ["UPDATE pa_account SET status='disabled' WHERE id=101"],
            'inactive member' => ["UPDATE pa_tenant_member SET status='inactive' WHERE id=11"],
            'inactive role' => ["UPDATE pa_role SET status='inactive' WHERE id=31"],
            'ordinary role' => ["UPDATE pa_role SET is_builtin=0 WHERE id=31"],
            'different role name' => ["UPDATE pa_role SET \"key\"='custom.owner' WHERE id=31"],
            'cross tenant role' => ['UPDATE pa_role SET tenant_id=2 WHERE id=31'],
            'cross tenant membership' => ['UPDATE pa_member_role SET tenant_id=2 WHERE tenant_member_id=11'],
            'missing membership' => ['DELETE FROM pa_member_role WHERE tenant_member_id=11'],
            'different account' => ['UPDATE pa_tenant_member SET account_id=202 WHERE id=11'],
        ];
    }

    #[DataProvider('brokenRelationships')]
    public function testInvalidTupleCannotProceedToApplicationInitialization(string $change): void
    {
        $this->database->exec($change);
        self::assertFalse($this->directory->isProvisionedOwner(1, 101, 11, 31));
        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('TENANT_OWNER_ADMIN_PRINCIPAL_INVALID');
        $this->host->provision(1, 101, 11, 31, 'alpha', 'Alpha');
    }

    public function testUnrelatedIdsAndInvalidNumbersAreNotCoercedIntoAnOwner(): void
    {
        foreach ([[1,202,11,31],[1,101,22,31],[1,101,11,32],[2,101,11,31],[0,101,11,31],[1,0,11,31],[1,101,0,31],[1,101,11,0]] as $tuple) {
            self::assertFalse($this->directory->isProvisionedOwner(...$tuple));
        }
    }

    public function testStorageFailureDoesNotBecomeAValidOwnerOrACompletedInitialization(): void
    {
        $this->database->exec('DROP TABLE pa_member_role');
        $this->expectException(\Throwable::class);
        $this->host->provision(1, 101, 11, 31, 'alpha', 'Alpha');
    }
}
