<?php

declare(strict_types=1);

use app\common\services\audit\AuditContractHost;
use PeanutAdmin\Kernel\Auth\ValidatedPlatformSession;
use PeanutAdmin\Kernel\Authorization\Application\PageRequest;
use PeanutAdmin\Kernel\Authorization\AuthorizationException;
use PeanutAdmin\Kernel\Authorization\EffectivePermissionSet;
use PeanutAdmin\Kernel\Authorization\RevisionPermissionCache;
use PeanutAdmin\Kernel\Context\PlatformContext;
use PeanutAdmin\Kernel\Identity\PasswordHasher;
use PeanutAdmin\Kernel\Platform\Authorization\PlatformAuthorizationEvaluator;
use PeanutAdmin\Kernel\Platform\Authorization\PlatformAuthorizationRepository;
use PeanutAdmin\Modules\Identity\Contract\TenantOwnerAdminProvisioner;
use PeanutAdmin\Modules\Identity\Invitation\OneTimeInvitationToken;
use PeanutAdmin\Modules\Identity\Invitation\OwnerInvitationDelivery;
use PeanutAdmin\Modules\Identity\Invitation\OwnerInvitationDeliveryPort;
use PeanutAdmin\Modules\Identity\Invitation\OwnerInvitationDeliveryResult;
use PeanutAdmin\Modules\Identity\Invitation\OwnerInvitationRuntimePolicy;
use PeanutAdmin\Modules\Identity\Invitation\TenantOwnerInvitationAdminService;
use PeanutAdmin\Modules\Identity\Invitation\TenantOwnerInvitationException;
use PeanutAdmin\Modules\Identity\Invitation\TenantOwnerInvitationPublicService;
use PeanutAdmin\Modules\Identity\Invitation\UnavailableOwnerInvitationDeliveryPort;
use PeanutAdmin\Modules\Identity\Platform\Application\PlatformTenantAdminService;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;
use think\App;

/** Actual invitation/token/authorization/audit code over synthetic SQLite; delivery and application provisioning are explicit ports. */
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class OwnerInvitationBoundaryTest extends TestCase
{
    private PDO $database;
    private TenantOwnerInvitationAdminService $admin;
    private TenantOwnerInvitationPublicService $public;
    private PlatformContext $context;
    private PlatformAuthorizationEvaluator $authorization;
    private AuditContractHost $audit;
    private array $permissions = ['platform.tenant.create', 'platform.tenant.provision-owner'];
    private array $provisioned = [];
    private bool $bootstrapFails = false;

    protected function setUp(): void
    {
        require_once dirname(__DIR__, 2) . '/vendor/topthink/framework/src/helper.php';
        $app = new App(dirname(__DIR__, 3) . '/.local/tmp/owner-invitation-' . bin2hex(random_bytes(6)));
        $app->config->set(['default' => 'file', 'stores' => ['file' => ['type' => 'File', 'path' => $app->getRuntimePath() . 'cache/']]], 'cache');
        $this->database = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $this->database->sqliteCreateFunction('UTC_TIMESTAMP', static fn(int $precision): string => gmdate('Y-m-d H:i:s') . '.000', 1);
        ThinkPhpTestConnection::fromPdo($this->database);
        $this->database->exec(<<<'SQL'
            CREATE TABLE pa_tenant(id INTEGER PRIMARY KEY,code TEXT,name TEXT,status TEXT,authorization_revision INTEGER DEFAULT 1,updated_at TEXT);
            INSERT INTO pa_tenant(id,code,name,status) VALUES(1,'alpha','Alpha','provisioning'),(2,'beta','Beta','active'),(3,'inactive','Inactive','suspended');
            CREATE TABLE pa_account(id INTEGER PRIMARY KEY AUTOINCREMENT,display_name TEXT,status TEXT DEFAULT 'active',created_at TEXT,updated_at TEXT);
            CREATE TABLE pa_credential(id INTEGER PRIMARY KEY AUTOINCREMENT,account_id INTEGER,kind TEXT,identifier_type TEXT,identifier_normalized TEXT,status TEXT DEFAULT 'active',secret_hash TEXT,verified_at TEXT,secret_changed_at TEXT,created_at TEXT,updated_at TEXT,UNIQUE(identifier_type,identifier_normalized));
            CREATE TABLE pa_tenant_member(id INTEGER PRIMARY KEY AUTOINCREMENT,tenant_id INTEGER,account_id INTEGER,display_name TEXT,status TEXT,joined_at TEXT,security_revision INTEGER DEFAULT 1,authorization_revision INTEGER DEFAULT 1,created_at TEXT,updated_at TEXT,UNIQUE(tenant_id,account_id));
            CREATE TABLE pa_role(id INTEGER PRIMARY KEY AUTOINCREMENT,tenant_id INTEGER,"key" TEXT,name TEXT,description TEXT,is_builtin INTEGER,status TEXT,created_at TEXT,updated_at TEXT,UNIQUE(tenant_id,"key"));
            CREATE TABLE pa_member_role(tenant_id INTEGER,tenant_member_id INTEGER,role_id INTEGER,assigned_at TEXT,UNIQUE(tenant_id,tenant_member_id,role_id));
            INSERT INTO pa_account(id,display_name) VALUES(20,'Existing owner');
            INSERT INTO pa_tenant_member(id,tenant_id,account_id,display_name,status) VALUES(20,2,20,'Existing owner','active');
            INSERT INTO pa_role(id,tenant_id,"key",name,is_builtin,status) VALUES(20,2,'core.tenant-owner','Tenant Owner',1,'active');
            INSERT INTO pa_member_role VALUES(2,20,20,NULL);
            CREATE TABLE pa_tenant_owner_invitation(id INTEGER PRIMARY KEY AUTOINCREMENT,tenant_id INTEGER,email_normalized TEXT,display_name TEXT,token_hash TEXT UNIQUE,status TEXT DEFAULT 'pending',delivery_status TEXT DEFAULT 'pending_delivery',delivery_provider TEXT,delivery_message_id TEXT,delivery_attempts INTEGER DEFAULT 0,delivery_error_code TEXT,last_delivery_at TEXT,generation INTEGER DEFAULT 1,expires_at TEXT,accepted_at TEXT,revoked_at TEXT,accepted_account_id INTEGER,accepted_member_id INTEGER,invited_by_operator_id INTEGER,revoked_by_operator_id INTEGER,created_at TEXT,updated_at TEXT,pending_tenant_id INTEGER GENERATED ALWAYS AS (CASE WHEN status='pending' THEN tenant_id ELSE NULL END) STORED,UNIQUE(pending_tenant_id));
            CREATE TABLE pa_platform_audit_event(id INTEGER PRIMARY KEY AUTOINCREMENT,event_type TEXT,action TEXT,outcome TEXT,reason_code TEXT,operator_id INTEGER,account_id INTEGER,target_type TEXT,target_id TEXT,request_id TEXT,operation_id TEXT,ip_address TEXT,user_agent_hash TEXT,before_json TEXT,after_json TEXT,metadata_json TEXT,occurred_at TEXT);
            CREATE TABLE pa_tenant_audit_event(id INTEGER PRIMARY KEY AUTOINCREMENT,tenant_id INTEGER,event_type TEXT,action TEXT,outcome TEXT,reason_code TEXT,actor_tenant_id INTEGER,actor_tenant_member_id INTEGER,actor_account_id INTEGER,actor_platform_operator_id INTEGER,actor_type TEXT,target_resource_type TEXT,target_resource_id TEXT,boundary_target_type TEXT,boundary_target_id TEXT,target_count INTEGER,target_set_digest TEXT,authorization_basis_json TEXT,request_id TEXT,operation_id TEXT,ip_address TEXT,user_agent_hash TEXT,before_json TEXT,after_json TEXT,metadata_json TEXT,occurred_at TEXT);
            SQL);
        $repository = $this->createStub(PlatformAuthorizationRepository::class);
        $repository->method('revision')->willReturnCallback(fn(int $id): string => hash('sha256', implode(',', $this->permissions)));
        $repository->method('permissions')->willReturnCallback(fn(int $id): EffectivePermissionSet => new EffectivePermissionSet($this->permissions));
        $this->authorization = new PlatformAuthorizationEvaluator($repository, new RevisionPermissionCache());
        $this->context = PlatformContext::fromValidatedSession(new ValidatedPlatformSession(1, 'fixture-session', 101, 11, 'platform-web', new DateTimeImmutable('2031-01-01T00:00:00Z')), 'owner-invitation-test');
        $this->audit = new AuditContractHost(null);
        $this->admin = $this->adminWith(new UnavailableOwnerInvitationDeliveryPort(), OwnerInvitationRuntimePolicy::fromEnvironment('development'));
        $bootstrap = $this->createStub(TenantOwnerAdminProvisioner::class);
        $bootstrap->method('provision')->willReturnCallback(function (int $tenant, int $account, int $member, int $role, string $code, string $name): int {
            if ($this->bootstrapFails) {
                throw new DomainException('FIXTURE_BOOTSTRAP_FAILED');
            }
            $this->provisioned[] = [$tenant,$account,$member,$role,$code,$name];
            return $member;
        });
        $this->public = new TenantOwnerInvitationPublicService($bootstrap, $this->audit, new PasswordHasher());
    }

    private function adminWith(OwnerInvitationDeliveryPort $delivery, OwnerInvitationRuntimePolicy $policy): TenantOwnerInvitationAdminService
    {
        return new TenantOwnerInvitationAdminService((new ReflectionClass(PlatformTenantAdminService::class))->newInstanceWithoutConstructor(), $this->authorization, $delivery, $policy, $this->audit);
    }

    private function invite(int $tenant = 1, string $email = 'new@example.test'): array
    {
        return $this->admin->invite($this->context, $tenant, $email, 'Invited Owner', 24);
    }

    private function rejected(string $code, callable $operation): void
    {
        try {
            $operation();
            self::fail('Expected rejection: ' . $code);
        } catch (TenantOwnerInvitationException $exception) {
            self::assertSame($code, $exception->errorCode);
        }
    }

    public function testManifestOwnsTheWholeInvitationAndPublishesOnlyItsUseAndDeliveryBoundary(): void
    {
        $manifest = json_decode(file_get_contents(dirname(__DIR__, 2) . '/app/modules/official/identity/module.json'), true, 512, JSON_THROW_ON_ERROR);
        self::assertContains('pa_tenant_owner_invitation', $manifest['database']['owned_tables']);
        foreach ([TenantOwnerInvitationAdminService::class,TenantOwnerInvitationPublicService::class,TenantOwnerInvitationException::class,OwnerInvitationDeliveryPort::class,OwnerInvitationDelivery::class,OwnerInvitationDeliveryResult::class,TenantOwnerAdminProvisioner::class] as $type) {
            self::assertContains($type, $manifest['contracts']['exports']);
        }
        self::assertFileDoesNotExist(dirname(__DIR__, 2) . '/app/platform/invitation/TenantOwnerInvitationAdminService.php');
    }

    public function testAllInvitationActionsRequireTheCoreContextRatherThanTheHttpWrapper(): void
    {
        $class = new ReflectionClass($this->admin);
        foreach (['provision', 'invite', 'invitations', 'resend', 'revoke'] as $method) {
            self::assertSame(PlatformContext::class, $class->getMethod($method)->getParameters()[0]->getType()->getName());
        }
        self::assertSame($this->authorization, $class->getProperty('authorization')->getValue($this->admin));
        self::assertStringNotContainsString('PlatformOperatorSessionService', file_get_contents($class->getFileName()));
        $controller = file_get_contents(dirname(__DIR__, 2) . '/app/platform/controller/PlatformTenantInvitationController.php');
        self::assertSame(5, substr_count($controller, '$this->platformContext->core,'));
    }

    public function testProvisionRequiresBothOriginalPermissionsBeforeTenantCreation(): void
    {
        foreach ([[], ['platform.tenant.create'], ['platform.tenant.provision-owner']] as $allowed) {
            $this->permissions = $allowed;
            try {
                $this->admin->provision($this->context, 'new-tenant', 'New tenant', 'new@example.test', 'Owner', 24);
                self::fail('An incomplete permission set issued an owner invitation.');
            } catch (AuthorizationException) {
                self::assertSame(3, (int) $this->database->query('SELECT COUNT(*) FROM pa_tenant')->fetchColumn());
                self::assertSame(0, (int) $this->database->query('SELECT COUNT(*) FROM pa_tenant_owner_invitation')->fetchColumn());
            }
        }
    }

    public function testIssuanceStoresOnlyHashAndInspectionDoesNotExposeEmailOrToken(): void
    {
        $issued = $this->invite();
        self::assertSame('pending_delivery', $issued['delivery_status']);
        $row = $this->database->query('SELECT * FROM pa_tenant_owner_invitation')->fetch(PDO::FETCH_ASSOC);
        self::assertSame(hash('sha256', $issued['accept_token']), $row['token_hash']);
        self::assertStringNotContainsString($issued['accept_token'], json_encode($row));
        $view = $this->public->inspect($issued['accept_token']);
        self::assertSame('pending', $view['status']);
        self::assertTrue($view['requires_password']);
        self::assertStringNotContainsString('new@example.test', json_encode($view));
        self::assertArrayNotHasKey('accept_token', $view);
        self::assertSame('provisioning', $this->database->query('SELECT status FROM pa_tenant WHERE id=1')->fetchColumn());
        $this->rejected('TENANT_OWNER_INVITATION_PENDING', fn() => $this->invite());
    }

    public function testAcceptCreatesNativeOwnerAndConsumesTokenWithoutActivatingTenant(): void
    {
        $issued = $this->invite();
        $result = $this->public->accept($issued['accept_token'], 'Fixture!Password2026');
        self::assertSame('accepted', $result['status']);
        self::assertSame('provisioning', $result['tenant_status']);
        $secret = $this->database->query("SELECT secret_hash FROM pa_credential WHERE identifier_normalized='new@example.test'")->fetchColumn();
        self::assertTrue(password_verify('Fixture!Password2026', $secret));
        self::assertSame('active', $this->database->query('SELECT status FROM pa_tenant_member WHERE id=' . $result['member_id'])->fetchColumn());
        self::assertCount(1, $this->provisioned);
        self::assertSame([$result['tenant_id'],$result['account_id'],$result['member_id'],$result['role_id'],'alpha','Invited Owner'], $this->provisioned[0]);
        self::assertSame(1, (int) $this->database->query('SELECT COUNT(*) FROM pa_tenant_audit_event')->fetchColumn());
        $this->rejected('INVITATION_NOT_FOUND', fn() => $this->public->accept($issued['accept_token'], null));
    }

    public function testExistingAccountCannotHaveItsPasswordOverwrittenAndReceivesSeparateMembership(): void
    {
        $secret = password_hash('Original!Password2026', PASSWORD_DEFAULT);
        $insert = $this->database->prepare("INSERT INTO pa_credential(account_id,kind,identifier_type,identifier_normalized,secret_hash) VALUES(20,'email_password','email','existing@example.test',?)");
        $insert->execute([$secret]);
        $issued = $this->invite(1, 'existing@example.test');
        self::assertFalse($this->public->inspect($issued['accept_token'])['requires_password']);
        $this->rejected('EXISTING_ACCOUNT_PASSWORD_FORBIDDEN', fn() => $this->public->accept($issued['accept_token'], 'Replacement!Password2026'));
        $accepted = $this->public->accept($issued['accept_token'], null);
        self::assertSame(20, $accepted['account_id']);
        self::assertNotSame(20, $accepted['member_id']);
        self::assertSame($secret, $this->database->query('SELECT secret_hash FROM pa_credential WHERE account_id=20')->fetchColumn());
        self::assertSame(2, (int) $this->database->query('SELECT COUNT(*) FROM pa_tenant_member WHERE account_id=20')->fetchColumn());
    }

    /** 原生 MySQL BIGINT 自增值可为字符串；SQLite 仍执行真实 SQL，仅替换返回表示。 */
    private function stringGeneratedIdentityIds(): object
    {
        $connection = new class ($this->database) extends \think\db\connector\Sqlite {
            public array $stringIdTables = [];

            public function __construct(private readonly PDO $pdo)
            {
                parent::__construct(['type' => 'sqlite', 'prefix' => 'pa_']);
            }

            protected function createPdo($dsn, $username, $password, $params): PDO
            {
                return $this->pdo;
            }

            public function getLastInsID(\think\db\BaseQuery $query, ?string $sequence = null)
            {
                $id = parent::getLastInsID($query, $sequence);
                if (in_array($query->getTable(), ['pa_account', 'pa_tenant_member'], true)) {
                    $this->stringIdTables[] = $query->getTable();
                    return (string) $id;
                }
                return $id;
            }
        };
        $manager = new SharedPdoDbManager($connection);
        $connection->setDb($manager);
        \think\Container::getInstance()->instance(\think\DbManager::class, $manager);
        return $connection;
    }

    public function testNewAccountAndMemberStringInsertIdsReachTypedProvisionerAsIntegers(): void
    {
        $connection = $this->stringGeneratedIdentityIds();
        $issued = $this->invite();
        $accepted = $this->public->accept($issued['accept_token'], 'Fixture!Password2026');
        self::assertContains('pa_account', $connection->stringIdTables);
        self::assertContains('pa_tenant_member', $connection->stringIdTables);
        self::assertIsInt($accepted['account_id']);
        self::assertIsInt($accepted['member_id']);
        self::assertSame('accepted', $accepted['status']);
        self::assertCount(1, $this->provisioned);
        self::assertSame($accepted['account_id'], $this->provisioned[0][1]);
        self::assertSame($accepted['member_id'], $this->provisioned[0][2]);
        self::assertSame(1, (int) $this->database->query('SELECT COUNT(*) FROM pa_member_role WHERE tenant_id=1')->fetchColumn());
        $this->rejected('INVITATION_NOT_FOUND', fn() => $this->public->accept($issued['accept_token'], null));
    }

    public function testExistingAccountStringMemberIdPreservesCredentialAndRollback(): void
    {
        $connection = $this->stringGeneratedIdentityIds();
        $secret = password_hash('Original!Password2026', PASSWORD_DEFAULT);
        $statement = $this->database->prepare("INSERT INTO pa_credential(account_id,kind,identifier_type,identifier_normalized,secret_hash) VALUES(20,'email_password','email','existing@example.test',?)");
        $statement->execute([$secret]);
        $issued = $this->invite(1, 'existing@example.test');
        $this->bootstrapFails = true;
        try {
            $this->public->accept($issued['accept_token'], null);
            self::fail('Bootstrap failure was ignored for a string-generated member ID.');
        } catch (DomainException $error) {
            self::assertSame('FIXTURE_BOOTSTRAP_FAILED', $error->getMessage());
        }
        self::assertSame(0, (int) $this->database->query('SELECT COUNT(*) FROM pa_tenant_member WHERE tenant_id=1')->fetchColumn());
        self::assertSame('pending', $this->public->inspect($issued['accept_token'])['status']);
        self::assertSame(0, (int) $this->database->query('SELECT COUNT(*) FROM pa_tenant_audit_event')->fetchColumn());
        $this->bootstrapFails = false;
        $accepted = $this->public->accept($issued['accept_token'], null);
        self::assertContains('pa_tenant_member', $connection->stringIdTables);
        self::assertNotContains('pa_account', $connection->stringIdTables);
        self::assertSame(20, $accepted['account_id']);
        self::assertIsInt($accepted['member_id']);
        self::assertSame('accepted', $accepted['status']);
        self::assertSame($accepted['member_id'], $this->provisioned[0][2]);
        self::assertSame($secret, $this->database->query('SELECT secret_hash FROM pa_credential WHERE account_id=20')->fetchColumn());
        self::assertSame(2, (int) $this->database->query('SELECT COUNT(*) FROM pa_tenant_member WHERE account_id=20')->fetchColumn());
    }

    public function testResendInvalidatesOldTokenAndRevokePreventsAcceptance(): void
    {
        $first = $this->invite();
        $second = $this->admin->resend($this->context, $first['id'], 24);
        self::assertSame(2, $second['generation']);
        self::assertNotSame($first['accept_token'], $second['accept_token']);
        $this->rejected('INVITATION_NOT_FOUND', fn() => $this->public->inspect($first['accept_token']));
        $this->admin->revoke($this->context, $first['id']);
        self::assertSame('revoked', $this->public->inspect($second['accept_token'])['status']);
        $this->rejected('INVITATION_REVOKED', fn() => $this->public->accept($second['accept_token'], 'Fixture!Password2026'));
        self::assertSame(0, (int) $this->database->query('SELECT COUNT(*) FROM pa_credential')->fetchColumn());
    }

    public function testExpiryIsPersistedButNeverCreatesAnOwner(): void
    {
        $issued = $this->invite();
        $this->database->exec("UPDATE pa_tenant_owner_invitation SET expires_at='2000-01-01 00:00:00.000'");
        $this->rejected('INVITATION_EXPIRED', fn() => $this->public->accept($issued['accept_token'], 'Fixture!Password2026'));
        self::assertSame('expired', $this->database->query('SELECT status FROM pa_tenant_owner_invitation')->fetchColumn());
        self::assertSame(1, (int) $this->database->query('SELECT COUNT(*) FROM pa_tenant_member')->fetchColumn());
    }

    public function testAdditionalOwnerRetainsExistingOwnerAndInactiveTenantIsDenied(): void
    {
        $issued = $this->invite(2);
        $accepted = $this->public->accept($issued['accept_token'], 'Fixture!Password2026');
        self::assertSame('active', $accepted['tenant_status']);
        self::assertSame(2, (int) $this->database->query('SELECT COUNT(*) FROM pa_member_role WHERE tenant_id=2')->fetchColumn());
        $this->rejected('TENANT_OWNER_INVITATION_NOT_ALLOWED', fn() => $this->invite(3));
        $this->database->exec("UPDATE pa_tenant_member SET status='inactive' WHERE tenant_id=2");
        $this->rejected('TENANT_ACTIVE_OWNER_REQUIRED', fn() => $this->invite(2, 'next@example.test'));
    }

    public function testProductionDeliveryFailureDoesNotLeakTokenOrReportSent(): void
    {
        $provider = $this->createStub(OwnerInvitationDeliveryPort::class);
        $provider->method('isConfigured')->willReturn(true);
        $provider->method('deliver')->willThrowException(new RuntimeException('fixture-private-delivery-detail'));
        $admin = $this->adminWith($provider, OwnerInvitationRuntimePolicy::fromEnvironment('production'));
        $issued = $admin->invite($this->context, 1, 'failed@example.test', 'Owner', 24);
        self::assertSame('failed', $issued['delivery_status']);
        self::assertArrayNotHasKey('accept_token', $issued);
        self::assertSame('DELIVERY_PROVIDER_ERROR', $this->database->query('SELECT delivery_error_code FROM pa_tenant_owner_invitation')->fetchColumn());
        self::assertStringNotContainsString('fixture-private', json_encode($issued));
    }

    public function testMissingDeliveryAndRevokedPermissionRejectBeforeIssuance(): void
    {
        $admin = $this->adminWith(new UnavailableOwnerInvitationDeliveryPort(), OwnerInvitationRuntimePolicy::fromEnvironment('production'));
        $this->rejected('OWNER_INVITATION_DELIVERY_UNAVAILABLE', fn() => $admin->invite($this->context, 1, 'new@example.test', 'Owner', 24));
        $this->permissions = [];
        $this->database->exec('DROP TABLE pa_tenant_owner_invitation');
        $this->expectException(AuthorizationException::class);
        $this->invite();
    }

    public function testBootstrapFailureRollsBackNewIdentityRoleAndTokenConsumption(): void
    {
        $issued = $this->invite();
        $this->bootstrapFails = true;
        try {
            $this->public->accept($issued['accept_token'], 'Fixture!Password2026');
            self::fail('Bootstrap failure was ignored.');
        } catch (DomainException $error) {
            self::assertSame('FIXTURE_BOOTSTRAP_FAILED', $error->getMessage());
        }
        self::assertSame(1, (int) $this->database->query('SELECT COUNT(*) FROM pa_account')->fetchColumn());
        self::assertSame(0, (int) $this->database->query('SELECT COUNT(*) FROM pa_credential')->fetchColumn());
        self::assertSame(1, (int) $this->database->query('SELECT COUNT(*) FROM pa_tenant_member')->fetchColumn());
        self::assertSame('pending', $this->public->inspect($issued['accept_token'])['status']);
        self::assertSame(0, (int) $this->database->query('SELECT COUNT(*) FROM pa_tenant_audit_event')->fetchColumn());
    }

    public function testAuditFailureRollsBackIdentityAndInvitationTogether(): void
    {
        $issued = $this->invite();
        $this->database->exec("CREATE TRIGGER reject_fixture_audit BEFORE INSERT ON pa_tenant_audit_event BEGIN SELECT RAISE(ABORT,'fixture audit failure'); END");
        try {
            $this->public->accept($issued['accept_token'], 'Fixture!Password2026');
            self::fail('Audit failure was ignored.');
        } catch (think\db\exception\PDOException) {
            self::assertSame(0, (int) $this->database->query('SELECT COUNT(*) FROM pa_credential')->fetchColumn());
            self::assertSame('pending', $this->public->inspect($issued['accept_token'])['status']);
        }
    }

    public function testHistoryPaginationAndExpiredProjectionRemainDatabaseScoped(): void
    {
        $first = $this->invite();
        $this->admin->revoke($this->context, $first['id']);
        $second = $this->invite();
        $this->database->exec("UPDATE pa_tenant_owner_invitation SET expires_at='2000-01-01 00:00:00.000' WHERE id=" . $second['id']);
        $firstPage = $this->admin->invitations($this->context, 1, new PageRequest(1, 1));
        $secondPage = $this->admin->invitations($this->context, 1, new PageRequest(2, 1));
        self::assertSame(2, $firstPage['total']);
        self::assertSame('expired', $firstPage['items'][0]['status']);
        self::assertSame('revoked', $secondPage['items'][0]['status']);
        self::assertArrayNotHasKey('token_hash', $firstPage['items'][0]);
        self::assertSame(0, $this->admin->invitations($this->context, 2, new PageRequest())['total']);
    }
}
