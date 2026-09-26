<?php

declare(strict_types=1);

namespace tests\Unit;

use app\common\contract\AdminPermissionPolicy;
use app\common\contract\module\ModuleQualificationQuery;
use app\common\execution\CurrentExecutionContext;
use app\common\execution\ExecutionContextStore;
use app\common\infrastructure\authorization\CoreTenantModuleAdminBridge;
use app\common\runtime\authorization\RoleAdministrationRuntime;
use app\common\services\authorization\AdminAuthorizationService;
use app\platform\infrastructure\module\ThinkPhpModuleGovernanceProvider;
use PDO;
use PeanutAdmin\Kernel\Authorization\TenantAuthorizationRepository;
use PeanutAdmin\Kernel\Menu\MenuCatalogRepository;
use PeanutAdmin\Modules\Identity\Authorization\Application\RoleAdminService;
use PeanutAdmin\Modules\Identity\Contract\AdminDirectoryQuery;
use PeanutAdmin\Modules\Identity\Contract\TenantAuthorizationQuery;
use PeanutAdmin\Modules\Identity\Contract\TenantMemberDirectory;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionProperty;
use think\App;

/** Real menu/Identity reads in in-memory SQLite; module qualification is a controlled fixture. */
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class SystemMenuPermissionBoundaryTest extends TestCase
{
    private PDO $database;
    private TenantAuthorizationQuery $identity;
    private CoreTenantModuleAdminBridge $bridge;
    private RoleAdministrationRuntime $roles;

    protected function setUp(): void
    {
        $root = dirname(__DIR__, 3);
        $temporary = $root . '/.local/tmp/system-menu-permission-tests';
        if (!is_dir($temporary) && !mkdir($temporary, 0700, true) && !is_dir($temporary)) {
            throw new \RuntimeException('MENU_PERMISSION_TEST_DIRECTORY_UNAVAILABLE');
        }
        self::assertStringStartsWith($root . '/.local/tmp/', (string) realpath($temporary));
        require_once $root . '/server/vendor/topthink/framework/src/helper.php';
        new App($temporary . '/case-' . bin2hex(random_bytes(5)));
        $this->database = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        \ThinkPhpTestConnection::fromPdo($this->database);
        $this->database->exec(<<<'SQL'
            CREATE TABLE pa_permission (id INTEGER PRIMARY KEY, "key" TEXT UNIQUE, module_key TEXT, status TEXT);
            CREATE TABLE pa_system_menu (id INTEGER PRIMARY KEY, perms TEXT, is_disable INTEGER);
            INSERT INTO pa_permission VALUES (1,'app.active','peanut.admin','active'),(2,'app.retired','peanut.admin','retired'),(3,'module.unavailable','official.unavailable','active'),(4,'unowned.active',NULL,'active'),(5,'hidden.active','peanut.admin','active');
            INSERT INTO pa_system_menu VALUES (1,'app.active',0),(2,'app.retired',0),(3,'module.unavailable',0),(4,'legacy.unregistered',0),(5,'unowned.active',0),(6,'hidden.active',1),(7,'',0),(8,'app.active',0);
            SQL);
        $members = $this->createStub(TenantMemberDirectory::class);
        $this->identity = new TenantAuthorizationQuery($members);
        $qualification = $this->createStub(ModuleQualificationQuery::class);
        $qualification->method('installedModules')->willReturn([]);
        $qualification->method('activeTenantModuleKeys')->willReturn(['official.unavailable']);
        $provider = (new ReflectionClass(ThinkPhpModuleGovernanceProvider::class))->newInstanceWithoutConstructor();
        (new ReflectionProperty($provider, 'qualificationInstance'))->setValue($provider, $qualification);
        $menus = $this->createStub(MenuCatalogRepository::class);
        $menus->method('activeDefinitions')->willReturn([]);
        $this->bridge = new CoreTenantModuleAdminBridge(
            $provider,
            $this->createStub(TenantAuthorizationRepository::class),
            $menus,
            $this->identity,
        );
        $authorization = new AdminAuthorizationService(
            $this->bridge,
            $this->createStub(AdminPermissionPolicy::class),
            $members,
            new AdminDirectoryQuery(new CurrentExecutionContext(new ExecutionContextStore())),
            $this->identity,
        );
        $this->roles = new RoleAdministrationRuntime(
            (new ReflectionClass(RoleAdminService::class))->newInstanceWithoutConstructor(),
            $authorization,
            $this->identity,
        );
    }

    public function testHostsDoNotJoinTheIdentityPrivatePermissionTable(): void
    {
        foreach ([CoreTenantModuleAdminBridge::class, RoleAdministrationRuntime::class] as $type) {
            $source = file_get_contents((new ReflectionClass($type))->getFileName());
            self::assertStringNotContainsString("->join('permission", $source);
            self::assertStringContainsString('->permissionStates(', $source);
        }
    }

    public function testOwnerReturnsOnlyRequestedDefinitionMetadata(): void
    {
        self::assertSame([
            'app.active' => ['module_key' => 'peanut.admin', 'status' => 'active'],
            'app.retired' => ['module_key' => 'peanut.admin', 'status' => 'retired'],
            'unowned.active' => ['module_key' => null, 'status' => 'active'],
        ], $this->identity->permissionStates(['unowned.active', 'missing', 'app.retired', 'app.active', 'app.active']));
        self::assertSame([], $this->identity->permissionStates([]));
    }

    public function testMenuBridgeKeepsLegacyUnknownButRejectsRetiredAndUninstalledDefinitions(): void
    {
        $actual = $this->bridge->registeredSystemMenuPermissions(101);
        sort($actual, SORT_STRING);
        self::assertSame(['app.active', 'legacy.unregistered', 'unowned.active'], $actual);
        self::assertSame([], $this->bridge->registeredSystemMenuPermissions(0));
    }

    public function testRoleSelectionRequiresAnActiveDefinitionAndKeepsTheOriginalSelectedMenuScope(): void
    {
        self::assertSame(['app.active', 'module.unavailable', 'unowned.active'], $this->roles->permissionKeys(101, [1, 2, 3, 4, 5, 6, 7, 8, 999]));
        self::assertSame([], $this->roles->permissionKeys(101, [2, 4, 6, 999]));
        self::assertSame([], $this->roles->permissionKeys(101, []));
        // This projection never grants the role: the existing assignment use case still enforces module/actor policy.
    }

    public function testLookupUsesBoundedBatchesWithoutTruncatingOrReturningUnrequestedKeys(): void
    {
        $insert = $this->database->prepare('INSERT INTO pa_permission (id,"key",module_key,status) VALUES (?, ?, ?, ?)');
        $keys = [];
        for ($index = 0; $index < 601; ++$index) {
            $key = sprintf('batch.%04d', $index);
            $insert->execute([100 + $index, $key, 'peanut.admin', 'active']);
            $keys[] = $key;
        }
        $states = $this->identity->permissionStates(array_reverse($keys));
        self::assertCount(601, $states);
        self::assertSame($keys, array_keys($states));
        self::assertArrayNotHasKey('app.active', $states);
    }

    public function testInvalidLookupInputIsNotCoerced(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->identity->permissionStates(['app.active', ['not-a-key']]);
    }

    public function testOwnerReadFailureRemainsAFailureRatherThanAnUnregisteredPermission(): void
    {
        $this->database->exec('DROP TABLE pa_permission');
        $this->expectException(\Throwable::class);
        $this->bridge->registeredSystemMenuPermissions(101);
    }
}
