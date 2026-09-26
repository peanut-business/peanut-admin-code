<?php

declare(strict_types=1);

namespace tests\Unit;

use app\common\contract\module\ModuleQualificationQuery;
use app\platform\context\PlatformOperatorContext;
use app\platform\query\PlatformControlPlaneQueryService;
use app\platform\services\PlatformOperatorSessionService;
use PDO;
use PDOStatement;
use PeanutAdmin\Kernel\Auth\ValidatedPlatformSession;
use PeanutAdmin\Kernel\Authorization\Application\PageRequest;
use PeanutAdmin\Kernel\Authorization\AuthorizationException;
use PeanutAdmin\Kernel\Authorization\EffectivePermissionSet;
use PeanutAdmin\Kernel\Authorization\RevisionPermissionCache;
use PeanutAdmin\Kernel\Context\PlatformContext;
use PeanutAdmin\Kernel\Platform\Authorization\PlatformAuthorizationEvaluator;
use PeanutAdmin\Kernel\Platform\Authorization\PlatformAuthorizationRepository;
use PeanutAdmin\Modules\Identity\Auth\PlatformAuthService;
use PeanutAdmin\Modules\Identity\Contract\PlatformDirectoryQueries;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;
use think\App;
use think\DbManager;

/** Actual query/host/authorization with fixture permissions; SQLite adapts only MySQL's sorted aggregate. */
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class PlatformDirectoryBoundaryTest extends TestCase
{
    private PDO $database;
    private PlatformDirectoryQueries $queries;
    private PlatformControlPlaneQueryService $host;
    private PlatformDirectoryPermissions $permissions;
    private PlatformDirectoryFixtureConnection $connection;
    private PlatformContext $context;

    protected function setUp(): void
    {
        $root = dirname(__DIR__, 3);
        $parent = $root . '/.local/tmp/platform-directory-tests';
        if (!is_dir($parent) && !mkdir($parent, 0700, true) && !is_dir($parent)) {
            throw new \RuntimeException('PLATFORM_DIRECTORY_TEST_DIRECTORY_UNAVAILABLE');
        }
        self::assertStringStartsWith($root . '/.local/tmp/', (string) realpath($parent));
        $temporary = $parent . '/case-' . bin2hex(random_bytes(5));
        require_once $root . '/server/vendor/topthink/framework/src/helper.php';
        $app = new App($temporary);
        $app->config->set(['default' => 'file', 'stores' => ['file' => ['type' => 'File', 'path' => $temporary . '/cache/']]], 'cache');
        $this->database = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $this->database->sqliteCreateAggregate(
            'fixture_sorted_keys',
            static function (?array $values, int $row, mixed $value): array {
                $values ??= [];
                if ($value !== null) {
                    $values[(string) $value] = true;
                }
                return $values;
            },
            static function (?array $values, int $rows): ?string {
                if ($values === null || $values === []) {
                    return null;
                }
                $keys = array_keys($values);
                sort($keys, SORT_STRING);
                return implode(',', $keys);
            },
            1,
        );
        $this->connection = new PlatformDirectoryFixtureConnection($this->database);
        $manager = new \SharedPdoDbManager($this->connection);
        $this->connection->setDb($manager);
        $app->instance(DbManager::class, $manager);
        $this->database->exec(<<<'SQL'
            CREATE TABLE pa_account (id INTEGER PRIMARY KEY, display_name TEXT, status TEXT);
            CREATE TABLE pa_credential (id INTEGER PRIMARY KEY, account_id INTEGER, identifier_type TEXT, identifier_normalized TEXT, status TEXT, secret_hash TEXT);
            CREATE TABLE pa_platform_operator (id INTEGER PRIMARY KEY, account_id INTEGER, display_name TEXT, status TEXT, security_revision INTEGER DEFAULT 1, created_at TEXT, updated_at TEXT);
            CREATE TABLE pa_platform_operator_role (platform_operator_id INTEGER, platform_role_id INTEGER);
            CREATE TABLE pa_platform_role (id INTEGER PRIMARY KEY, "key" TEXT, name TEXT, description TEXT, is_builtin INTEGER DEFAULT 0, status TEXT, revision INTEGER DEFAULT 1, created_at TEXT, updated_at TEXT);
            CREATE TABLE pa_platform_role_permission (platform_role_id INTEGER, permission_id INTEGER);
            CREATE TABLE pa_permission (id INTEGER PRIMARY KEY, "key" TEXT, module_key TEXT, type TEXT, name TEXT, description TEXT, risk_level TEXT, status TEXT, manifest_version TEXT, created_at TEXT, updated_at TEXT, retired_at TEXT);
            CREATE TABLE pa_platform_audit_event (id INTEGER PRIMARY KEY, event_type TEXT, action TEXT, outcome TEXT, reason_code TEXT, operator_id INTEGER, account_id INTEGER, target_type TEXT, target_id TEXT, request_id TEXT, operation_id TEXT, ip_address TEXT, user_agent_hash TEXT, before_json TEXT, after_json TEXT, metadata_json TEXT, occurred_at TEXT);
            CREATE TABLE pa_tenant_member (id INTEGER PRIMARY KEY, tenant_id INTEGER, account_id INTEGER, display_name TEXT, status TEXT, security_revision INTEGER DEFAULT 1, authorization_revision INTEGER DEFAULT 1, joined_at TEXT, created_at TEXT, updated_at TEXT);
            CREATE TABLE pa_member_role (tenant_id INTEGER, tenant_member_id INTEGER, role_id INTEGER);
            CREATE TABLE pa_role (id INTEGER PRIMARY KEY, tenant_id INTEGER, "key" TEXT, is_builtin INTEGER, status TEXT);
            INSERT INTO pa_account VALUES (101,'Account A','active'),(202,'Account B','inactive');
            INSERT INTO pa_credential VALUES (1,101,'email','a@example.test','active','must-not-leak'),(2,202,'email','b@example.test','active','must-not-leak');
            INSERT INTO pa_platform_operator (id,account_id,display_name,status) VALUES (11,101,'Operator A','active'),(22,202,'Operator B','inactive');
            INSERT INTO pa_platform_role (id,"key",name,description,status) VALUES (31,'platform.zulu','Zulu','','active'),(32,'platform.alpha','Alpha','','active');
            INSERT INTO pa_platform_operator_role VALUES (11,31),(11,32),(11,31);
            INSERT INTO pa_permission (id,"key",module_key,status) VALUES (41,'platform.visible','platform','active'),(42,'platform.retired','platform','retired'),(43,'tenant.unrelated','peanut.admin','active');
            INSERT INTO pa_platform_role_permission VALUES (31,41),(31,42),(31,41);
            INSERT INTO pa_platform_audit_event (id,event_type,before_json,after_json,metadata_json) VALUES (1,'fixture.first','{"count":1}','[]','{"ok":true}'),(2,'fixture.second','invalid','"scalar"',NULL);
            INSERT INTO pa_tenant_member (id,tenant_id,account_id,display_name,status) VALUES (51,1,101,'Owner A','inactive'),(52,2,202,'Owner B','active');
            INSERT INTO pa_role VALUES (61,1,'core.tenant-owner',1,'inactive'),(62,2,'core.tenant-owner',1,'active');
            INSERT INTO pa_member_role VALUES (1,51,61),(2,52,62);
            SQL);
        $this->permissions = new PlatformDirectoryPermissions();
        $authorization = new PlatformAuthorizationEvaluator($this->permissions, new RevisionPermissionCache());
        $app->instance(PlatformAuthorizationEvaluator::class, $authorization);
        $this->queries = $app->make(PlatformDirectoryQueries::class);
        $this->context = PlatformContext::fromValidatedSession(new ValidatedPlatformSession(1, 'fixture-platform-session', 101, 11, 'platform-web', new \DateTimeImmutable('2031-01-01T00:00:00Z')), 'platform-directory-fixture');
        $sessions = new PlatformOperatorSessionService((new \ReflectionClass(PlatformAuthService::class))->newInstanceWithoutConstructor(), $authorization, $this->permissions);
        $qualification = $this->createStub(ModuleQualificationQuery::class);
        $qualification->method('tenantModuleStates')->willReturn([]);
        $qualification->method('installedModules')->willReturn([]);
        $this->host = new PlatformControlPlaneQueryService($sessions, $qualification, $this->queries);
    }

    public function testHostIsAnAdapterAndOwnerIsExplicitlyDeclared(): void
    {
        $source = file_get_contents((new \ReflectionClass(PlatformControlPlaneQueryService::class))->getFileName());
        self::assertStringNotContainsString('Db::', $source);
        self::assertStringContainsString('PlatformDirectoryQueries $directory', $source);
        $manifest = json_decode(file_get_contents(dirname(__DIR__, 2) . '/app/modules/official/identity/module.json'), true, 512, JSON_THROW_ON_ERROR);
        self::assertContains(PlatformDirectoryQueries::class, $manifest['contracts']['exports']);
        self::assertSame(['items' => [], 'total' => 0], $this->host->moduleStates($this->hostContext(), 1, new PageRequest()));
    }

    public function testOperatorCountDatabasePageOrderAndSortedRolesArePreserved(): void
    {
        $first = $this->queries->operators($this->context, new PageRequest(1, 1));
        $second = $this->host->operators($this->hostContext(), new PageRequest(2, 1));
        self::assertSame(2, $first['total']);
        self::assertSame(22, $first['items'][0]['id']);
        self::assertSame([], $first['items'][0]['role_keys']);
        self::assertSame(2, $second['total']);
        self::assertSame(11, $second['items'][0]['id']);
        self::assertSame(['platform.alpha', 'platform.zulu'], $second['items'][0]['role_keys']);
        self::assertStringNotContainsString('must-not-leak', json_encode([$first, $second], JSON_THROW_ON_ERROR));
        self::assertStringContainsString("GROUP_CONCAT(DISTINCT role.`key` ORDER BY role.`key` SEPARATOR ',')", implode("\n", $this->connection->observedSql));
    }

    public function testRoleBindingCountAndActiveKeyProjectionKeepTheirDifferentMeanings(): void
    {
        $result = $this->host->roles($this->hostContext(), new PageRequest(2, 1));
        self::assertSame(2, $result['total']);
        self::assertSame(31, $result['items'][0]['id']);
        self::assertSame(2, $result['items'][0]['permission_count']);
        self::assertSame(['platform.visible'], $result['items'][0]['permission_keys']);
    }

    public function testPermissionScopeAndAuditJsonProjectionStayFixed(): void
    {
        $permissions = $this->host->permissions($this->hostContext(), new PageRequest());
        self::assertSame(2, $permissions['total']);
        self::assertSame([41, 42], array_column($permissions['items'], 'id'));
        $audit = $this->host->audit($this->hostContext(), new PageRequest(1, 1));
        self::assertSame(2, $audit['total']);
        self::assertSame(2, $audit['items'][0]['id']);
        self::assertNull($audit['items'][0]['before_json']);
        self::assertNull($audit['items'][0]['after_json']);
        self::assertNull($audit['items'][0]['metadata_json']);
        $older = $this->queries->audit($this->context, new PageRequest(2, 1));
        self::assertSame(['count' => 1], $older['items'][0]['before_json']);
        self::assertSame([], $older['items'][0]['after_json']);
    }

    public function testOwnerViewIsTenantBoundButDoesNotHideInactiveAdministrativeRecords(): void
    {
        $owner = $this->host->owner($this->hostContext(), 1);
        self::assertSame(51, $owner['member_id']);
        self::assertSame('inactive', $owner['member_status']);
        self::assertSame('a@example.test', $owner['email']);
        self::assertArrayNotHasKey('secret_hash', $owner);
        $this->expectException(\PeanutAdmin\Kernel\Authorization\Application\AdminAccessException::class);
        $this->queries->owner($this->context, 999);
    }

    public static function readMethods(): array
    {
        return [
            ['operators', 'platform.operator.read'], ['roles', 'platform.role.read'],
            ['permissions', 'platform.permission.read'], ['audit', 'platform.audit.read'],
            ['owner', 'platform.tenant.read'],
        ];
    }

    #[DataProvider('readMethods')]
    public function testEachReadChecksTheCurrentPermissionBeforeAnyDataQuery(string $method, string $permission): void
    {
        $this->permissions->keys = array_values(array_diff($this->permissions->keys, [$permission]));
        ++$this->permissions->currentRevision;
        $this->connection->observedSql = [];
        try {
            $this->queries->$method($this->context, $method === 'owner' ? 1 : new PageRequest());
            self::fail('Unauthorized directory access was accepted.');
        } catch (AuthorizationException) {
            self::assertSame([], $this->connection->observedSql);
        }
    }

    public function testPermissionRevisionRevocationInvalidatesTheNativeCache(): void
    {
        self::assertSame(2, $this->queries->permissions($this->context, new PageRequest())['total']);
        $this->permissions->keys = [];
        ++$this->permissions->currentRevision;
        $this->expectException(AuthorizationException::class);
        $this->host->permissions($this->hostContext(), new PageRequest());
    }

    private function hostContext(): PlatformOperatorContext
    {
        return PlatformOperatorContext::fromValidatedPlatformSession($this->context);
    }
}

final class PlatformDirectoryPermissions implements PlatformAuthorizationRepository
{
    public int $currentRevision = 1;
    public array $keys = ['platform.operator.read', 'platform.role.read', 'platform.permission.read', 'platform.audit.read', 'platform.tenant.read'];

    public function revision(int $operatorId): string
    {
        return $operatorId . ':' . $this->currentRevision;
    }

    public function permissions(int $operatorId): EffectivePermissionSet
    {
        return new EffectivePermissionSet($this->keys);
    }
}

final class PlatformDirectoryFixtureConnection extends \think\db\connector\Sqlite
{
    public array $observedSql = [];

    public function __construct(private readonly PDO $fixture)
    {
        parent::__construct(['type' => 'sqlite', 'builder' => \think\db\builder\Sqlite::class, 'prefix' => 'pa_']);
    }

    protected function createPdo($dsn, $username, $password, $params): PDO
    {
        return $this->fixture;
    }

    public function getPDOStatement(string $sql, array $bind = [], bool $master = false, bool $procedure = false): PDOStatement
    {
        $this->observedSql[] = $sql;
        foreach (['role', 'permission'] as $alias) {
            $sql = str_replace("GROUP_CONCAT(DISTINCT {$alias}.`key` ORDER BY {$alias}.`key` SEPARATOR ',')", "fixture_sorted_keys({$alias}.`key`)", $sql);
        }
        return parent::getPDOStatement($sql, $bind, $master, $procedure);
    }
}
