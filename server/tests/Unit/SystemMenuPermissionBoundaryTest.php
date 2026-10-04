<?php

declare(strict_types=1);

namespace tests\Unit;

use app\common\contract\AdminPermissionPolicy;
use app\common\contract\module\ModuleQualificationQuery;
use app\common\execution\CurrentExecutionContext;
use app\common\execution\ExecutionContextStore;
use app\common\infrastructure\authorization\CoreTenantModuleAdminBridge;
use app\common\runtime\authorization\RoleAdministrationRuntime;
use app\adminapi\services\auth\RoleApplicationService;
use app\common\services\authorization\AdminAuthorizationService;
use app\platform\infrastructure\module\ThinkPhpModuleGovernanceProvider;
use PDO;
use PeanutAdmin\Kernel\Authorization\TenantAuthorizationRepository;
use PeanutAdmin\Kernel\Menu\MenuCatalogRepository;
use PeanutAdmin\Modules\Identity\Menu\MenuAdministrationService;
use PeanutAdmin\Modules\Identity\Authorization\Application\RoleAdminService;
use PeanutAdmin\Modules\Identity\Audit\AuditService;
use PeanutAdmin\Kernel\Auth\TenantContext;
use PeanutAdmin\Kernel\Auth\ValidatedTenantSession;
use PeanutAdmin\Kernel\Authorization\Application\AdminAccessException;
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
        require_once dirname((new ReflectionClass(App::class))->getFileName(), 2) . '/helper.php';
        new App($temporary . '/case-' . bin2hex(random_bytes(5)));
        $this->database = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        \ThinkPhpTestConnection::fromPdo($this->database);
        $this->database->sqliteCreateFunction('UTC_TIMESTAMP', static fn(int $precision): string => '2031-01-01 00:00:00.000', 1);
        $this->database->exec(<<<'SQL'
            CREATE TABLE pa_permission (id INTEGER PRIMARY KEY, "key" TEXT COLLATE NOCASE UNIQUE, module_key TEXT, status TEXT);
            CREATE TABLE pa_system_menu (id INTEGER PRIMARY KEY, perms TEXT, is_disable INTEGER, menu_key TEXT);
            CREATE TABLE pa_menu_definition (id INTEGER PRIMARY KEY, "key" TEXT, scope TEXT, sort_order INTEGER, required_permission_id INTEGER);
            CREATE TABLE pa_tenant_module (tenant_id INTEGER, module_key TEXT, status TEXT, effective_at TEXT, expires_at TEXT);
            CREATE TABLE pa_tenant (id INTEGER PRIMARY KEY, status TEXT, authorization_revision INTEGER, updated_at TEXT);
            CREATE TABLE pa_role (id INTEGER PRIMARY KEY, tenant_id INTEGER, "key" TEXT, name TEXT, description TEXT, is_builtin INTEGER, status TEXT, authorization_revision INTEGER, updated_at TEXT);
            CREATE TABLE pa_role_permission (id INTEGER PRIMARY KEY, tenant_id INTEGER, role_id INTEGER, permission_id INTEGER, granted_by_member_id INTEGER, granted_at TEXT);
            CREATE TABLE pa_tenant_audit_event (id INTEGER PRIMARY KEY, tenant_id INTEGER, event_type TEXT, action TEXT, outcome TEXT, actor_tenant_id INTEGER, actor_tenant_member_id INTEGER, actor_account_id INTEGER, actor_type TEXT, target_resource_type TEXT, target_resource_id TEXT, boundary_target_type TEXT, boundary_target_id TEXT, target_count INTEGER, target_set_digest TEXT, authorization_basis_json TEXT, request_id TEXT, metadata_json TEXT, occurred_at TEXT);
            INSERT INTO pa_tenant VALUES (101,'active',1,NULL);
            INSERT INTO pa_role VALUES (1,101,'application.reader','Before','Before description',0,'active',1,NULL);
            INSERT INTO pa_permission VALUES (1,'app.active','peanut.admin','active'),(2,'app.retired','peanut.admin','retired'),(3,'module.unavailable','official.unavailable','active'),(4,'unowned.active',NULL,'active'),(5,'hidden.active','peanut.admin','active');
            INSERT INTO pa_system_menu (id,perms,is_disable) VALUES (1,'app.active',0),(2,'app.retired',0),(3,'module.unavailable',0),(4,'legacy.unregistered',0),(5,'unowned.active',0),(6,'hidden.active',1),(7,'',0),(8,'app.active',0);
            UPDATE pa_system_menu SET menu_key='menu.' || id;
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
            new MenuAdministrationService(),
        );
        $authorization = new AdminAuthorizationService(
            $this->bridge,
            $this->createStub(AdminPermissionPolicy::class),
            $members,
            new AdminDirectoryQuery(new CurrentExecutionContext(new ExecutionContextStore())),
            $this->identity,
            new MenuAdministrationService(),
        );
        $this->roles = new RoleAdministrationRuntime(
            new RoleAdminService(new AuditService()),
            $authorization,
            $this->identity,
            new MenuAdministrationService(),
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
            'app.active' => ['key' => 'app.active', 'module_key' => 'peanut.admin', 'status' => 'active'],
            'app.retired' => ['key' => 'app.retired', 'module_key' => 'peanut.admin', 'status' => 'retired'],
            'unowned.active' => ['key' => 'unowned.active', 'module_key' => null, 'status' => 'active'],
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
        self::assertSame(['app.active', 'module.unavailable', 'unowned.active'], $this->roles->permissionKeys(101, ['menu.1', 'menu.2', 'menu.3', 'menu.4', 'menu.5', 'menu.7', 'menu.8']));
        self::assertSame([], $this->roles->permissionKeys(101, ['menu.2', 'menu.4']));
        self::assertSame([], $this->roles->permissionKeys(101, []));
        // This projection never grants the role: the existing assignment use case still enforces module/actor policy.
    }

    public function testUnknownOrDisabledMenuKeyCannotBeAssigned(): void
    {
        $this->expectException(\DomainException::class);
        $this->roles->permissionKeys(101, ['menu.6', 'menu.999']);
    }

    public function testApplicationMenuPermissionCanBeAssignedWithoutATenantModule(): void
    {
        $application = new RoleApplicationService($this->roles);
        self::assertTrue($application->edit($this->context(), ['id' => 1, 'name' => 'After', 'menu_keys' => ['menu.1']]));
        $role = $this->roles->service()->get(101, 1);
        self::assertSame('After', $role['name']);
        self::assertSame(['app.active'], $role['permission_keys']);
        self::assertSame(['menu.1', 'menu.8'], $this->roles->menuKeys($this->context(), $role['permission_keys']));
        self::assertSame('3', $role['revision']);
        self::assertFalse($this->database->inTransaction());
    }

    public function testRejectedPermissionRollsBackRoleMetadataRevisionBindingsAndAudit(): void
    {
        $this->database->exec('INSERT INTO pa_role_permission VALUES (1,101,1,1,201,NULL)');
        $before = $this->roleWriteState();
        try {
            (new RoleApplicationService($this->roles))->edit($this->context(), [
                'id' => 1, 'name' => 'Must roll back', 'desc' => 'Must roll back', 'menu_keys' => ['menu.3'],
            ]);
            self::fail('An unavailable Module permission must be rejected.');
        } catch (AdminAccessException $exception) {
            self::assertSame('PERMISSION_NOT_ASSIGNABLE', $exception->errorCode);
        }
        self::assertSame($before, $this->roleWriteState());
        self::assertFalse($this->database->inTransaction());
    }

    public function testStaleRevisionCannotChangeRoleOrPermissionBindings(): void
    {
        $service = $this->roles->service();
        $service->replacePermissions($this->context(), 1, ['app.active'], 1);
        $before = $this->roleWriteState();
        foreach ([
            fn() => $service->update($this->context(), 1, 'Stale', null, 1),
            fn() => $service->replacePermissions($this->context(), 1, [], 1),
        ] as $operation) {
            try {
                $operation();
                self::fail('A stale role revision must be rejected.');
            } catch (AdminAccessException $exception) {
                self::assertSame(412, $exception->httpStatus);
            }
            self::assertSame($before, $this->roleWriteState());
        }
    }

    private function context(): TenantContext
    {
        return TenantContext::fromValidatedSession(new ValidatedTenantSession(
            1,
            'menu-boundary-session',
            101,
            301,
            201,
            'admin.web',
            new \DateTimeImmutable('2031-01-01'),
            1,
        ), 'menu-role-boundary');
    }

    /** @return array<string,list<array<string,mixed>>> */
    private function roleWriteState(): array
    {
        $state = [];
        foreach (['pa_role', 'pa_role_permission', 'pa_tenant', 'pa_tenant_audit_event'] as $table) {
            $state[$table] = $this->database->query('SELECT * FROM ' . $table . ' ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
        }
        return $state;
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

    public function testDatabaseEquivalentMenuKeysDoNotBecomeUnknownOrBypassRetirement(): void
    {
        $this->database->exec("INSERT INTO pa_system_menu (id,perms,is_disable) VALUES (9,'APP.RETIRED',0),(10,'APP.ACTIVE',0); UPDATE pa_system_menu SET menu_key='menu.' || id WHERE id IN (9,10)");
        self::assertNotContains('APP.RETIRED', $this->bridge->registeredSystemMenuPermissions(101));
        self::assertSame(['app.active'], $this->roles->permissionKeys(101, ['menu.9', 'menu.10']));
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
