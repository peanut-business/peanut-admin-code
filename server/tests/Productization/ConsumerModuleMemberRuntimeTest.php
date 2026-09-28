<?php

declare(strict_types=1);

use app\common\contract\authorization\AdminAuthorizationQuery;
use app\common\dto\authorization\AdminPrincipal;
use app\common\execution\CurrentExecutionContext;
use app\common\execution\ExecutionContextStore;
use app\common\infrastructure\authorization\CoreTenantModuleAdminBridge;
use app\common\policy\permission\RegisteredAdminPermissionPolicy;
use app\common\services\authorization\AdminAuthorizationService;
use app\platform\infrastructure\module\ThinkPhpModuleGovernanceProvider;
use app\platform\infrastructure\plugin\ModuleCatalogApplier;
use PeanutAdmin\Kernel\Auth\Clock;
use PeanutAdmin\Kernel\Auth\TenantContext;
use PeanutAdmin\Kernel\Auth\TokenIssuer;
use PeanutAdmin\Modules\Identity\Audit\AuditService;
use PeanutAdmin\Modules\Identity\Auth\Persistence\ThinkPhpTenantAuthRepository;
use PeanutAdmin\Modules\Identity\Auth\TenantAuthentication;
use PeanutAdmin\Modules\Identity\Auth\TenantAuthService;
use PeanutAdmin\Modules\Identity\Authorization\Application\RoleAdminService;
use PeanutAdmin\Modules\Identity\Contract\AdminDirectoryQuery;
use PeanutAdmin\Modules\Identity\Contract\TenantAuthorizationQuery;
use PeanutAdmin\Modules\Identity\Membership\Application\MemberAdminService;
use PeanutAdmin\Modules\Identity\Membership\Query\ThinkPhpTenantMemberDirectory;
use PeanutAdmin\Modules\Identity\Menu\ThinkPhpMenuCatalogRepository;
use PeanutAdmin\Modules\Identity\Authorization\ThinkPhpTenantAuthorizationRepository;
use PeanutAdmin\Kernel\Identity\PasswordHasher;
use think\Container;
use think\db\builder\Sqlite as SqliteBuilder;
use think\db\connector\Sqlite;
use think\db\Raw;
use think\DbManager;
use think\facade\Db;

require dirname(__DIR__, 2) . '/bootstrap/environment.php';
require dirname(__DIR__, 2) . '/vendor/autoload.php';
require_once dirname(__DIR__) . '/Support/ThinkPhpTestConnection.php';

function memberRuntimeExpect(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

final class ConsumerModuleMemberRuntimeClock implements Clock
{
    public function now(): DateTimeImmutable
    {
        return new DateTimeImmutable('2026-09-28 00:00:00.000', new DateTimeZone('UTC'));
    }
}

final class ConsumerModuleMemberRuntimeSqliteConnection extends Sqlite
{
    public function __construct(private readonly PDO $sharedPdo)
    {
        parent::__construct([
            'type' => 'sqlite',
            'builder' => SqliteBuilder::class,
            'prefix' => 'pa_',
        ]);
    }

    public function getPDOStatement(string $sql, array $bind = [], bool $master = false, bool $procedure = false): PDOStatement
    {
        return parent::getPDOStatement(str_replace('CURRENT_TIMESTAMP(3)', 'CURRENT_TIMESTAMP', $sql), $bind, $master, $procedure);
    }

    protected function createPdo($dsn, $username, $password, $params): PDO
    {
        return $this->sharedPdo;
    }
}

function memberRuntimeDeny(callable $operation, PDO $pdo): void
{
    $before = (int) $pdo->query('SELECT COUNT(*) FROM pa_acme_reference_chain_record')->fetchColumn();
    try {
        $operation();
    } catch (RuntimeException $error) {
        memberRuntimeExpect($error->getMessage() === 'REFERENCE_CHAIN_PERMISSION_DENIED', 'unexpected denial code');
        $after = (int) $pdo->query('SELECT COUNT(*) FROM pa_acme_reference_chain_record')->fetchColumn();
        memberRuntimeExpect($after === $before, 'permission denial changed business rows');
        return;
    }
    throw new RuntimeException('permission denial was not enforced');
}

function memberRuntimeUseThinkPhpConnection(PDO $pdo): void
{
    if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql') {
        ThinkPhpTestConnection::fromPdo($pdo);
        return;
    }
    $connection = new ConsumerModuleMemberRuntimeSqliteConnection($pdo);
    $manager = new SharedPdoDbManager($connection);
    $connection->setDb($manager);
    Container::getInstance()->instance(DbManager::class, $manager);
}

function memberRuntimeCreateSchema(PDO $pdo): void
{
    if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql') {
        // Native schema and its CHECK/FK constraints remain intact in real MySQL.
        foreach (PeanutAdmin\Kernel\Persistence\Schema\KernelSchema::tableNames() as $table) {
            $pdo->exec(PeanutAdmin\Kernel\Persistence\Schema\KernelSchema::createSql($table));
        }
        $pdo->exec(PeanutAdmin\Kernel\Persistence\Schema\KernelSchema::addTenantMemberDepartmentForeignKeySql());
        foreach (PeanutAdmin\Kernel\Migration\ModuleSchema::tableNames() as $table) {
            $pdo->exec(PeanutAdmin\Kernel\Migration\ModuleSchema::createSql($table));
        }
        $pdo->exec('CREATE TABLE pa_plugin_module (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, plugin_key VARCHAR(96) NOT NULL, module_key VARCHAR(96) NOT NULL UNIQUE, module_version VARCHAR(64) NOT NULL, manifest_digest CHAR(64) NOT NULL, created_at DATETIME(3) NOT NULL, updated_at DATETIME(3) NOT NULL) ENGINE=InnoDB');
        $pdo->exec('CREATE TABLE pa_acme_reference_chain_record (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, tenant_id BIGINT UNSIGNED NOT NULL, reference VARCHAR(120) NOT NULL, created_at DATETIME(3) NOT NULL, FOREIGN KEY (tenant_id) REFERENCES pa_tenant(id)) ENGINE=InnoDB');
        return;
    }
    foreach ([
        'CREATE TABLE pa_account (id INTEGER PRIMARY KEY AUTOINCREMENT, display_name TEXT NOT NULL, avatar_uri TEXT NULL, status TEXT NOT NULL DEFAULT "active", security_revision INTEGER NOT NULL DEFAULT 1, last_login_at TEXT NULL, created_at TEXT NOT NULL, updated_at TEXT NOT NULL)',
        'CREATE TABLE pa_credential (id INTEGER PRIMARY KEY AUTOINCREMENT, account_id INTEGER NOT NULL, kind TEXT NOT NULL, identifier_type TEXT NOT NULL, identifier_normalized TEXT NOT NULL UNIQUE, secret_hash TEXT NOT NULL, status TEXT NOT NULL DEFAULT "active", failed_attempts INTEGER NOT NULL DEFAULT 0, locked_until TEXT NULL, verified_at TEXT NOT NULL, last_used_at TEXT NULL, secret_changed_at TEXT NOT NULL, expires_at TEXT NULL, revision INTEGER NOT NULL DEFAULT 1, revoked_at TEXT NULL, created_at TEXT NOT NULL, updated_at TEXT NOT NULL)',
        'CREATE TABLE pa_tenant (id INTEGER PRIMARY KEY AUTOINCREMENT, code TEXT NOT NULL UNIQUE, name TEXT NOT NULL, display_name TEXT NOT NULL, status TEXT NOT NULL DEFAULT "active", security_revision INTEGER NOT NULL DEFAULT 1, authorization_revision INTEGER NOT NULL DEFAULT 1, revision INTEGER NOT NULL DEFAULT 1, created_at TEXT NOT NULL, updated_at TEXT NOT NULL)',
        'CREATE TABLE pa_tenant_member (id INTEGER PRIMARY KEY AUTOINCREMENT, tenant_id INTEGER NOT NULL, account_id INTEGER NOT NULL, display_name TEXT NOT NULL DEFAULT "", member_no TEXT NULL, member_type TEXT NOT NULL DEFAULT "administrator", primary_department_id INTEGER NULL, status TEXT NOT NULL DEFAULT "active", security_revision INTEGER NOT NULL DEFAULT 1, authorization_revision INTEGER NOT NULL DEFAULT 1, joined_at TEXT NULL, suspended_at TEXT NULL, left_at TEXT NULL, created_at TEXT NOT NULL, updated_at TEXT NOT NULL)',
        'CREATE TABLE pa_role (id INTEGER PRIMARY KEY AUTOINCREMENT, tenant_id INTEGER NOT NULL, key TEXT NOT NULL, name TEXT NOT NULL, description TEXT NULL, is_builtin INTEGER NOT NULL DEFAULT 0, status TEXT NOT NULL DEFAULT "active", authorization_revision INTEGER NOT NULL DEFAULT 1, archived_at TEXT NULL, created_at TEXT NOT NULL, updated_at TEXT NOT NULL, UNIQUE (tenant_id, key))',
        'CREATE TABLE pa_member_role (id INTEGER PRIMARY KEY AUTOINCREMENT, tenant_id INTEGER NOT NULL, tenant_member_id INTEGER NOT NULL, role_id INTEGER NOT NULL, assigned_by_member_id INTEGER NULL, assigned_at TEXT NULL, UNIQUE (tenant_id, tenant_member_id, role_id))',
        'CREATE TABLE pa_permission (id INTEGER PRIMARY KEY AUTOINCREMENT, key TEXT NOT NULL UNIQUE, module_key TEXT NOT NULL, type TEXT NOT NULL, name TEXT NOT NULL, description TEXT NULL, risk_level TEXT NOT NULL DEFAULT "normal", status TEXT NOT NULL DEFAULT "active", manifest_version TEXT NOT NULL, created_at TEXT NOT NULL, updated_at TEXT NOT NULL, retired_at TEXT NULL)',
        'CREATE TABLE pa_role_permission (id INTEGER PRIMARY KEY AUTOINCREMENT, tenant_id INTEGER NOT NULL, role_id INTEGER NOT NULL, permission_id INTEGER NOT NULL, granted_by_member_id INTEGER NULL, granted_at TEXT NULL, UNIQUE (tenant_id, role_id, permission_id))',
        'CREATE TABLE pa_module_installation (module_key TEXT PRIMARY KEY, installed_version TEXT NOT NULL, manifest_schema_version INTEGER NOT NULL, manifest_digest TEXT NOT NULL, status TEXT NOT NULL, revision INTEGER NOT NULL DEFAULT 1, activated_at TEXT NULL, created_at TEXT NOT NULL, updated_at TEXT NOT NULL)',
        'CREATE TABLE pa_plugin_module (id INTEGER PRIMARY KEY AUTOINCREMENT, plugin_key TEXT NOT NULL, module_key TEXT NOT NULL UNIQUE, module_version TEXT NOT NULL, manifest_digest TEXT NOT NULL, created_at TEXT NOT NULL, updated_at TEXT NOT NULL)',
        'CREATE TABLE pa_tenant_module (id INTEGER PRIMARY KEY AUTOINCREMENT, tenant_id INTEGER NOT NULL, module_key TEXT NOT NULL, status TEXT NOT NULL, source TEXT NOT NULL DEFAULT "manual", config_revision INTEGER NOT NULL DEFAULT 1, effective_at TEXT NULL, expires_at TEXT NULL, enabled_at TEXT NULL, disabled_at TEXT NULL, disabled_reason TEXT NULL, authorization_revision INTEGER NOT NULL DEFAULT 1, created_at TEXT NOT NULL, updated_at TEXT NOT NULL, UNIQUE (tenant_id, module_key))',
        'CREATE TABLE pa_menu_definition (id INTEGER PRIMARY KEY AUTOINCREMENT, key TEXT NOT NULL, module_key TEXT NOT NULL, scope TEXT NOT NULL, parent_key TEXT NULL, type TEXT NOT NULL, name TEXT NOT NULL, route_name TEXT NULL, route_path TEXT NULL, component_key TEXT NULL, icon TEXT NULL, sort_order INTEGER NOT NULL DEFAULT 0, required_permission_id INTEGER NULL, client_keys_json TEXT NOT NULL DEFAULT "[]", status TEXT NOT NULL DEFAULT "active", manifest_digest TEXT NOT NULL DEFAULT "", created_at TEXT NOT NULL, updated_at TEXT NOT NULL)',
        'CREATE TABLE pa_auth_security_event (id INTEGER PRIMARY KEY AUTOINCREMENT, audience TEXT NOT NULL, event_type TEXT NOT NULL, outcome TEXT NOT NULL, reason_code TEXT NULL, account_id INTEGER NULL, credential_id INTEGER NULL, session_key TEXT NULL, identifier_hmac TEXT NULL, request_id TEXT NOT NULL, ip_address TEXT NULL, user_agent_hash TEXT NULL, metadata_json TEXT NULL, occurred_at TEXT NOT NULL)',
        'CREATE TABLE pa_tenant_session (id INTEGER PRIMARY KEY AUTOINCREMENT, session_key TEXT NOT NULL UNIQUE, tenant_id INTEGER NOT NULL, account_id INTEGER NOT NULL, tenant_member_id INTEGER NOT NULL, client_key TEXT NOT NULL, status TEXT NOT NULL DEFAULT "active", account_security_revision INTEGER NOT NULL, tenant_security_revision INTEGER NOT NULL, member_security_revision INTEGER NOT NULL, authorization_revision INTEGER NOT NULL DEFAULT 1, issued_at TEXT NOT NULL, last_seen_at TEXT NOT NULL, idle_expires_at TEXT NOT NULL, absolute_expires_at TEXT NOT NULL, ip_address TEXT NULL, user_agent_hash TEXT NULL, revoked_at TEXT NULL, revoke_reason TEXT NULL, created_at TEXT NOT NULL, updated_at TEXT NOT NULL)',
        'CREATE TABLE pa_tenant_session_token (id INTEGER PRIMARY KEY AUTOINCREMENT, session_id INTEGER NOT NULL, token_hash TEXT NOT NULL UNIQUE, token_type TEXT NOT NULL, status TEXT NOT NULL DEFAULT "active", parent_token_id INTEGER NULL, replaced_by_token_id INTEGER NULL, expires_at TEXT NOT NULL, used_at TEXT NULL, revoked_at TEXT NULL, created_at TEXT NOT NULL)',
        'CREATE TABLE pa_tenant_audit_event (id INTEGER PRIMARY KEY AUTOINCREMENT, tenant_id INTEGER NOT NULL, actor_account_id INTEGER NULL, actor_member_id INTEGER NULL, actor_tenant_id INTEGER NULL, actor_tenant_member_id INTEGER NULL, actor_platform_operator_id INTEGER NULL, actor_type TEXT, outcome TEXT, boundary_target_type TEXT, boundary_target_id TEXT, target_count INTEGER, target_set_digest TEXT, event_type TEXT NOT NULL, action TEXT NOT NULL, target_resource_type TEXT NOT NULL, target_resource_id TEXT NOT NULL, request_id TEXT NULL, metadata_json TEXT NULL, occurred_at TEXT NOT NULL)',
        'CREATE TABLE pa_acme_reference_chain_record (id INTEGER PRIMARY KEY AUTOINCREMENT, tenant_id INTEGER NOT NULL, reference TEXT NOT NULL, created_at TEXT NOT NULL)',
    ] as $sql) {
        $pdo->exec($sql);
    }
}

function memberRuntimeInsert(PDO $pdo, string $table, array $values): int
{
    $columns = array_keys($values);
    $quoted = array_map(static fn(string $column): string => '`' . $column . '`', $columns);
    $params = array_map(static fn(string $column): string => ':' . $column, $columns);
    $statement = $pdo->prepare(sprintf('INSERT INTO %s (%s) VALUES (%s)', $table, implode(',', $quoted), implode(',', $params)));
    $statement->execute($values);
    return (int) $pdo->lastInsertId();
}

function memberRuntimeModuleRoot(string $root): string
{
    $moduleRoot = $root . '/module/acme/reference_chain';
    $sourceRoot = $moduleRoot . '/src';
    if (!is_dir($sourceRoot) && !mkdir($sourceRoot, 0700, true) && !is_dir($sourceRoot)) {
        throw new RuntimeException('runtime module root unavailable');
    }
    file_put_contents($moduleRoot . '/composer.json', json_encode([
        'autoload' => ['psr-4' => ['Acme\\Modules\\ReferenceChain\\' => 'src/']],
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
    file_put_contents($moduleRoot . '/module.json', json_encode([
        'schema_version' => 1,
        'key' => 'acme.reference-chain',
        'name' => 'Reference Chain',
        'description' => 'M3 ordinary member runtime fixture',
        'version' => '1.0.0',
        'kernel_constraint' => '^1.0',
        'license' => 'Apache-2.0',
        'dependencies' => [],
        'backend' => ['provider' => 'Acme\\Modules\\ReferenceChain\\ModuleProvider'],
        'frontend' => (object) [],
        'database' => ['owned_tables' => ['pa_acme_reference_chain_record']],
        'contracts' => ['exports' => [], 'events' => []],
        'tenant' => [
            'enableable' => true,
            'disable_behavior' => 'reject_new_operations',
            'requires' => [],
        ],
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
    file_put_contents($sourceRoot . '/ModuleProvider.php', <<<'PHP'
<?php
declare(strict_types=1);

namespace Acme\Modules\ReferenceChain;

use PeanutAdmin\Kernel\Module\ModuleProvider as ModuleProviderContract;

final class ModuleProvider implements ModuleProviderContract
{
    public function moduleKey(): string
    {
        return 'acme.reference-chain';
    }

    public function bindings(): array
    {
        return [];
    }
}
PHP);
    return $moduleRoot;
}

$mode = $argv[1] ?? '--sqlite';
memberRuntimeExpect(in_array($mode, ['--sqlite', '--mysql'], true) && count($argv) <= 2, 'explicit sqlite/mysql mode required');
$root = dirname(__DIR__, 3);
$serverRoot = $root . '/server';
$temporaryParent = $root . '/.local/tmp/m3-runtime-member-20260928';
if (!is_dir($temporaryParent)) {
    mkdir($temporaryParent, 0700, true);
}
$temporaryRoot = $temporaryParent . '/member-runtime-' . bin2hex(random_bytes(8));
memberRuntimeExpect(mkdir($temporaryRoot, 0700), 'owned temporary root unavailable');
register_shutdown_function(static function () use ($temporaryRoot): void {
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($temporaryRoot, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($iterator as $entry) {
        if ($entry->isDir() && !$entry->isLink()) {
            rmdir($entry->getPathname());
        } else {
            unlink($entry->getPathname());
        }
    }
    rmdir($temporaryRoot);
});
// Reuse the exact sample generator. No competing business implementation lives here.
$generator = <<<'PY'
import importlib.machinery, importlib.util, pathlib, shutil, sys
root=pathlib.Path(sys.argv[1]); target=pathlib.Path(sys.argv[2])
loader=importlib.machinery.SourceFileLoader('sample_runtime_source', str(root/'scripts/tests/consumer-module-sample-test.py'))
spec=importlib.util.spec_from_loader(loader.name,loader); module=importlib.util.module_from_spec(spec); loader.exec_module(module)
case=module.ConsumerModuleSampleTest('test_generated_php_service_enforces_public_contracts'); case.setUp()
try:
    for name in ['Contract/ReferenceChainCommands.php','Service/ReferenceChainService.php']:
        out=target/name; out.parent.mkdir(parents=True,exist_ok=True); shutil.copy2(case.backend/'src'/name,out)
finally:
    case.doCleanups()
PY;
$process = proc_open(['python3', '-c', $generator, $root, $temporaryRoot], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $root);
memberRuntimeExpect(is_resource($process), 'native sample generation process unavailable');
$generatedOutput = stream_get_contents($pipes[1]);
$generatedError = stream_get_contents($pipes[2]);
fclose($pipes[1]);
fclose($pipes[2]);
memberRuntimeExpect(proc_close($process) === 0 && $generatedError === '', 'native sample generator failed: ' . $generatedError);
require $temporaryRoot . '/Contract/ReferenceChainCommands.php';
require $temporaryRoot . '/Service/ReferenceChainService.php';
if ($mode === '--mysql') {
    require_once dirname(__DIR__) . '/Support/RegisteredMysqlTestResource.php';
    $databaseName = RegisteredMysqlTestResource::configuredDatabaseName();
    [$pdo, $databaseCreated] = RegisteredMysqlTestResource::openEmptyDatabase($databaseName);
} else {
    $pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
    $pdo->sqliteCreateFunction('UTC_TIMESTAMP', static fn(): string => '2026-09-28 00:00:00');
}
$completed = false;
try {
    memberRuntimeUseThinkPhpConnection($pdo);
    memberRuntimeCreateSchema($pdo);
    $moduleRoot = memberRuntimeModuleRoot($temporaryRoot);

    $now = '2026-09-28 00:00:00.000';
    $passwords = new PasswordHasher();
    $ownerPassword = 'Owner-member-runtime-123!';
    $ownerAccount = memberRuntimeInsert($pdo, 'pa_account', ['display_name' => 'Tenant Owner', 'created_at' => $now, 'updated_at' => $now]);
    memberRuntimeInsert($pdo, 'pa_credential', [
        'account_id' => $ownerAccount,
        'kind' => 'email_password',
        'identifier_type' => 'email',
        'identifier_normalized' => 'owner@example.test',
        'secret_hash' => $passwords->hash($ownerPassword),
        'verified_at' => $now,
        'secret_changed_at' => $now,
        'created_at' => $now,
        'updated_at' => $now,
    ]);
    $tenantA = memberRuntimeInsert($pdo, 'pa_tenant', ['code' => 'default', 'name' => 'Default', 'display_name' => 'Default', 'status' => 'active', 'created_at' => $now, 'updated_at' => $now]);
    $tenantB = memberRuntimeInsert($pdo, 'pa_tenant', ['code' => 'tenant-b', 'name' => 'Tenant B', 'display_name' => 'Tenant B', 'status' => 'active', 'created_at' => $now, 'updated_at' => $now]);
    $ownerMember = memberRuntimeInsert($pdo, 'pa_tenant_member', ['tenant_id' => $tenantA, 'account_id' => $ownerAccount, 'display_name' => 'Tenant Owner', 'status' => 'active', 'joined_at' => $now, 'created_at' => $now, 'updated_at' => $now]);
    $ownerRole = memberRuntimeInsert($pdo, 'pa_role', ['tenant_id' => $tenantA, 'key' => 'core.tenant-owner', 'name' => 'Tenant Owner', 'is_builtin' => 1, 'created_at' => $now, 'updated_at' => $now]);
    memberRuntimeInsert($pdo, 'pa_member_role', ['tenant_id' => $tenantA, 'tenant_member_id' => $ownerMember, 'role_id' => $ownerRole, 'assigned_at' => $now]);

    $provider = new ThinkPhpModuleGovernanceProvider(
        $serverRoot,
        [
            'roots' => [$moduleRoot],
            'kernel_version' => '1.0.0',
            'registered_client_keys' => ['admin-web', 'platform-web'],
        ],
        ThinkPhpTestConnection::moduleCatalogs($pdo),
    );
    memberRuntimeUseThinkPhpConnection($pdo);
    $manifest = $provider->registry()->compiled()->requireManifest('acme.reference-chain');
    memberRuntimeInsert($pdo, 'pa_module_installation', [
        'module_key' => 'acme.reference-chain',
        'installed_version' => '1.0.0',
        'manifest_schema_version' => 1,
        'manifest_digest' => $manifest->digest,
        'status' => 'active',
        'activated_at' => $now,
        'created_at' => $now,
        'updated_at' => $now,
    ]);
    memberRuntimeInsert($pdo, 'pa_plugin_module', [
        'plugin_key' => 'deployment',
        'module_key' => 'acme.reference-chain',
        'module_version' => '1.0.0',
        'manifest_digest' => $manifest->digest,
        'created_at' => $now,
        'updated_at' => $now,
    ]);
    foreach ([$tenantA, $tenantB] as $tenantId) {
        memberRuntimeInsert($pdo, 'pa_tenant_module', [
            'tenant_id' => $tenantId,
            'module_key' => 'acme.reference-chain',
            'status' => 'enabled',
            'source' => 'manual',
            'enabled_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }
    memberRuntimeInsert($pdo, 'pa_permission', [
        'key' => 'acme.reference-chain.write',
        'module_key' => 'acme.reference-chain',
        'type' => 'api',
        'name' => 'Write reference records',
        'risk_level' => 'normal',
        'status' => 'active',
        'manifest_version' => '1.0.0',
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    $members = new ThinkPhpTenantMemberDirectory();
    $tenantAuthorization = new TenantAuthorizationQuery($members);
    $authorization = new AdminAuthorizationService(
        new CoreTenantModuleAdminBridge(
            $provider,
            new ThinkPhpTenantAuthorizationRepository(),
            new ThinkPhpMenuCatalogRepository(),
            $tenantAuthorization,
        ),
        new RegisteredAdminPermissionPolicy(),
        $members,
        new AdminDirectoryQuery(new CurrentExecutionContext(new ExecutionContextStore())),
        $tenantAuthorization,
    );
    $auth = new TenantAuthService(
        new ThinkPhpTenantAuthRepository(),
        $passwords,
        new ConsumerModuleMemberRuntimeClock(),
        new TokenIssuer(),
        'member-runtime-identifier-hmac-key-32bytes',
    );
    $ownerLogin = $auth->login('owner@example.test', $ownerPassword, 'default', '127.0.0.1', 'runtime member', 'runtime-owner-login');
    memberRuntimeExpect($ownerLogin instanceof TenantAuthentication, 'owner login did not authenticate');
    $ownerContext = $ownerLogin->context;

    $roleService = new RoleAdminService(new AuditService());
    $memberService = new MemberAdminService(new AuditService(), $passwords);
    $business = new Acme\Modules\ReferenceChain\Service\ReferenceChainService($authorization, $provider->qualification());

    $role = $roleService->create($ownerContext, 'reference-member', 'Reference Member', null);
    memberRuntimeExpect(isset($role['revision']) && !array_key_exists('authorization_revision', $role), 'public role revision contract changed');
    $memberPassword = 'Runtime-member-123!';
    $member = $memberService->createAdministrator(
        $ownerContext,
        'member@example.test',
        'Runtime Member',
        $memberPassword,
        null,
        [(int) $role['id']],
        true,
    );
    $memberLogin = $auth->login('member@example.test', $memberPassword, 'default', '127.0.0.1', 'runtime member', 'runtime-member-login');
    memberRuntimeExpect($memberLogin instanceof TenantAuthentication, 'ordinary member login did not authenticate');
    $memberContext = $memberLogin->context;
    $principal = $authorization->principal($memberContext);
    memberRuntimeExpect($principal instanceof AdminPrincipal && !$principal->root, 'ordinary member was treated as root');
    memberRuntimeDeny(fn() => $business->create($memberContext, 'before-grant'), $pdo);
    memberRuntimeDeny(fn() => $business->list($memberContext), $pdo);

    $role = $roleService->replacePermissions($ownerContext, (int) $role['id'], ['acme.reference-chain.write'], (int) $role['revision']);
    memberRuntimeExpect($role['permission_keys'] === ['acme.reference-chain.write'], 'role grant was not visible');
    $created = $business->create($memberContext, 'after-grant');
    memberRuntimeExpect($created['tenant_id'] === $tenantA, 'business write used the wrong tenant');
    memberRuntimeExpect(count($business->list($memberContext)['items']) === 1, 'business read did not return granted member row');

    $role = $roleService->replacePermissions($ownerContext, (int) $role['id'], [], (int) $role['revision']);
    memberRuntimeExpect($role['permission_keys'] === [], 'role revoke was not visible');
    memberRuntimeDeny(fn() => $business->create($memberContext, 'after-revoke'), $pdo);
    memberRuntimeDeny(fn() => $business->list($memberContext), $pdo);
    $roleService->replacePermissions($ownerContext, (int) $role['id'], ['acme.reference-chain.write'], (int) $role['revision']);

    $tenantBAccount = memberRuntimeInsert($pdo, 'pa_account', ['display_name' => 'Tenant B Member', 'created_at' => $now, 'updated_at' => $now]);
    $tenantBPassword = 'Runtime-member-b-123!';
    memberRuntimeInsert($pdo, 'pa_credential', [
        'account_id' => $tenantBAccount,
        'kind' => 'email_password',
        'identifier_type' => 'email',
        'identifier_normalized' => 'member-b@example.test',
        'secret_hash' => $passwords->hash($tenantBPassword),
        'verified_at' => $now,
        'secret_changed_at' => $now,
        'created_at' => $now,
        'updated_at' => $now,
    ]);
    $tenantBMember = memberRuntimeInsert($pdo, 'pa_tenant_member', [
        'tenant_id' => $tenantB,
        'account_id' => $tenantBAccount,
        'display_name' => 'Tenant B Member',
        'status' => 'active',
        'joined_at' => $now,
        'created_at' => $now,
        'updated_at' => $now,
    ]);
    $tenantBRole = memberRuntimeInsert($pdo, 'pa_role', ['tenant_id' => $tenantB, 'key' => 'reference-member-b', 'name' => 'Reference Member B', 'created_at' => $now, 'updated_at' => $now]);
    memberRuntimeInsert($pdo, 'pa_member_role', ['tenant_id' => $tenantB, 'tenant_member_id' => $tenantBMember, 'role_id' => $tenantBRole, 'assigned_at' => $now]);
    $tenantBLogin = $auth->login('member-b@example.test', $tenantBPassword, 'tenant-b', '127.0.0.1', 'runtime member', 'runtime-member-b-login');
    memberRuntimeExpect($tenantBLogin instanceof TenantAuthentication, 'tenant B ordinary member login did not authenticate');
    $tenantBPrincipal = $authorization->principal($tenantBLogin->context);
    memberRuntimeExpect(!$tenantBPrincipal->root, 'tenant B member was treated as root');
    memberRuntimeDeny(fn() => $business->create($tenantBLogin->context, 'tenant-b-denied'), $pdo);
    memberRuntimeDeny(fn() => $business->list($tenantBLogin->context), $pdo);
    memberRuntimeExpect((int) $pdo->query("SELECT COUNT(*) FROM pa_tenant_audit_event WHERE actor_type='member' AND outcome='success' AND actor_tenant_member_id=" . $ownerMember)->fetchColumn() > 0, 'native audit actor/outcome fields missing');
    $completed = true;

    echo json_encode([
        'status' => 'passed',
        'database' => $mode === '--mysql' ? $databaseName : 'sqlite::memory:',
        'driver' => $pdo->getAttribute(PDO::ATTR_DRIVER_NAME),
        'server_version' => $pdo->getAttribute(PDO::ATTR_SERVER_VERSION),
        'schema_scope' => 'seeded integration, not installed A/B or migration qualification',
        'service_source' => 'actual author_module_v1 generated ReferenceChainService',
        'checks' => [
            'native_tenant_auth_login',
            'non_root_principal',
            'deny_before_grant_zero_write',
            'role_grant_visible',
            'business_write_read',
            'revoke_denied_zero_write',
            'cross_tenant_member_denied_zero_write',
        ],
        'assertions' => 7,
    ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES), PHP_EOL;
    echo "CONSUMER-MEMBER-RUNTIME-001 passed (7 checks)\n";

} finally {
    if ($mode === '--mysql' && $completed) {
        RegisteredMysqlTestResource::cleanup($pdo, $databaseName, $databaseCreated);
    }
}
