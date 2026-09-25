<?php

declare(strict_types=1);

use app\adminapi\application\setting\TransactionSettingsApplicationService;
use app\common\execution\ExecutionContextStore;
use app\common\model\setting\TransactionSetting;
use PeanutAdmin\Kernel\Auth\TenantContext;
use PeanutAdmin\Kernel\Auth\ValidatedTenantSession;
use PeanutAdmin\Kernel\Persistence\Schema\KernelSchema;
use PeanutAdmin\Kernel\Context\TenantContextRequirement;

require dirname(__DIR__, 2) . '/vendor/autoload.php';
require __DIR__ . '/../Support/IsolatedBackendEnvironment.php';
spl_autoload_register(static function (string $class): void {
    if (!str_starts_with($class, 'app\\')) {
        return;
    }
    $path = dirname(__DIR__, 2) . '/app/' . str_replace('\\', '/', substr($class, 4)) . '.php';
    if (is_file($path)) {
        require_once $path;
    }
}, true, true);

function expectTransactionTenant(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function transactionTenantContext(int $tenantId, int $memberId, string $requestId): TenantContext
{
    return TenantContext::fromValidatedSession(new ValidatedTenantSession(
        $memberId,
        '01JMT03TRANSACTION' . str_pad((string) $memberId, 10, '0', STR_PAD_LEFT),
        $tenantId,
        $memberId + 10000,
        $memberId,
        'admin-web',
        new DateTimeImmutable('2031-01-01T00:00:00Z'),
        1,
    ), $requestId);
}

function transactionPdo(string $host, int $port, string $user, string $password, string $database): PDO
{
    return new PDO(
        "mysql:host={$host};port={$port};dbname={$database};charset=utf8mb4",
        $user,
        $password,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::MYSQL_ATTR_MULTI_STATEMENTS => true],
    );
}

function transactionDatabase(PDO $admin): string
{
    $name = 'peanut_admin_fresh_transaction_' . strtolower(bin2hex(random_bytes(5)));
    $admin->exec("CREATE DATABASE `{$name}` CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci");
    return $name;
}

function transactionFreshSchema(PDO $pdo, string $serverRoot): void
{
    foreach (KernelSchema::tableNames() as $table) {
        $pdo->exec(KernelSchema::createSql($table));
    }
    $pdo->exec(KernelSchema::addTenantMemberDepartmentForeignKeySql());
    $pdo->exec(<<<'SQL'
INSERT INTO pa_tenant
  (id, code, name, display_name, status, activated_at, created_at, updated_at)
VALUES
  (101, 'default', 'Alpha', 'Alpha', 'active', UTC_TIMESTAMP(3), UTC_TIMESTAMP(3), UTC_TIMESTAMP(3));
SQL);
    $schema = (string) file_get_contents($serverRoot . '/database/init.sql');
    expectTransactionTenant($schema !== '', 'canonical application schema is missing');
    $pdo->exec($schema);
}

$serverRoot = dirname(__DIR__, 2);
$host = IsolatedBackendEnvironment::required('DB_HOST');
$port = (int) IsolatedBackendEnvironment::required('DB_PORT');
$user = IsolatedBackendEnvironment::required('DB_USER');
$password = IsolatedBackendEnvironment::required('DB_PASS');
$admin = new PDO(
    "mysql:host={$host};port={$port};charset=utf8mb4",
    $user,
    $password,
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
);
$database = transactionDatabase($admin);

try {
    $pdo = transactionPdo($host, $port, $user, $password, $database);
    transactionFreshSchema($pdo, $serverRoot);
    $pdo->exec(<<<'SQL'
INSERT INTO pa_tenant
  (id, code, name, display_name, status, activated_at, created_at, updated_at)
VALUES
  (202, 'beta', 'Beta', 'Beta', 'active', UTC_TIMESTAMP(3), UTC_TIMESTAMP(3), UTC_TIMESTAMP(3));
INSERT INTO pa_transaction_setting
  (tenant_id, cancel_unpaid_orders, cancel_unpaid_orders_times, verification_orders, verification_orders_times, create_time, update_time)
VALUES
  (202, 1, 30, 1, 24, 0, 0);
SQL);

    expectTransactionTenant(
        (int) $pdo->query('SELECT COUNT(*) FROM pa_transaction_setting')->fetchColumn() === 2,
        'fresh Tenants did not receive explicit transaction policies',
    );
    foreach ([101, 202] as $tenantId) {
        $row = $pdo->query("SELECT * FROM pa_transaction_setting WHERE tenant_id = {$tenantId}")->fetch(PDO::FETCH_ASSOC);
        expectTransactionTenant((int) $row['cancel_unpaid_orders'] === 1, "Tenant {$tenantId} lost the fresh cancel mode");
        expectTransactionTenant((int) $row['cancel_unpaid_orders_times'] === 30, "Tenant {$tenantId} lost the fresh cancel threshold");
        expectTransactionTenant((int) $row['verification_orders'] === 1, "Tenant {$tenantId} lost the fresh verification mode");
        expectTransactionTenant((int) $row['verification_orders_times'] === 24, "Tenant {$tenantId} lost the fresh verification threshold");
    }
    try {
        $pdo->exec('INSERT INTO pa_transaction_setting (tenant_id, cancel_unpaid_orders_times, verification_orders_times) VALUES (202, 60, 48)');
        throw new RuntimeException('same-Tenant transaction policy duplicate unexpectedly succeeded');
    } catch (PDOException $exception) {
        expectTransactionTenant($exception->getCode() === '23000', 'one-policy-per-Tenant constraint failed unexpectedly');
    }
    try {
        $pdo->exec('INSERT INTO pa_transaction_setting (tenant_id, cancel_unpaid_orders_times, verification_orders_times) VALUES (999, 60, 48)');
        throw new RuntimeException('orphan transaction policy unexpectedly succeeded');
    } catch (PDOException $exception) {
        expectTransactionTenant($exception->getCode() === '23000', 'transaction policy Tenant foreign key failed unexpectedly');
    }

    IsolatedBackendEnvironment::activateDatabase($host, $port, $database, $user, $password, 'multi-tenant');
    $app = new think\App($serverRoot);
    $app->initialize();
    $alpha = transactionTenantContext(101, 11, 'fresh-transaction-alpha');
    $beta = transactionTenantContext(202, 22, 'fresh-transaction-beta');

    app(ExecutionContextStore::class)->run(
        new \app\common\execution\AdminExecutionContext($beta, 'test.transaction-settings.set.beta'),
        fn() => app(TransactionSettingsApplicationService::class)->setConfig($beta, [
            'tenant_id' => 101,
            'cancel_unpaid_orders' => 1,
            'cancel_unpaid_orders_times' => 60,
            'verification_orders' => 0,
            'verification_orders_times' => 48,
        ]),
    );
    expectTransactionTenant(
        app(ExecutionContextStore::class)->run(
            new \app\common\execution\AdminExecutionContext($alpha, 'test.transaction-settings.get.alpha'),
            fn() => app(TransactionSettingsApplicationService::class)->getConfig($alpha),
        )['cancel_unpaid_orders_times'] === 30,
        'Beta changed Alpha transaction policy',
    );
    expectTransactionTenant(
        app(ExecutionContextStore::class)->run(
            new \app\common\execution\AdminExecutionContext($beta, 'test.transaction-settings.get.beta'),
            fn() => app(TransactionSettingsApplicationService::class)->getConfig($beta),
        )['cancel_unpaid_orders_times'] === 60,
        'Beta transaction policy was not updated',
    );
    expectTransactionTenant(
        (int) app(ExecutionContextStore::class)->run(
            new \app\common\execution\AdminExecutionContext($alpha, 'test.transaction-settings.query.alpha'),
            fn() => TransactionSetting::where([])->count(),
        ) === 1,
        'Alpha query crossed Tenant boundary',
    );
    expectTransactionTenant(
        (int) app(ExecutionContextStore::class)->run(
            new \app\common\execution\AdminExecutionContext($beta, 'test.transaction-settings.query.beta'),
            fn() => TransactionSetting::where([])->count(),
        ) === 1,
        'Beta query crossed Tenant boundary',
    );

    $pdo->exec(<<<'SQL'
INSERT INTO pa_tenant
  (id, code, name, display_name, status, activated_at, created_at, updated_at)
VALUES
  (303, 'gamma', 'Gamma', 'Gamma', 'active', UTC_TIMESTAMP(3), UTC_TIMESTAMP(3), UTC_TIMESTAMP(3));
SQL);
    $gamma = transactionTenantContext(303, 33, 'fresh-transaction-gamma');
    expectTransactionTenant(
        app(ExecutionContextStore::class)->run(
            new \app\common\execution\AdminExecutionContext($gamma, 'test.transaction-settings.get.gamma'),
            fn() => app(TransactionSettingsApplicationService::class)->getConfig($gamma),
        ) === [
            'cancel_unpaid_orders' => 1,
            'cancel_unpaid_orders_times' => 30,
            'verification_orders' => 1,
            'verification_orders_times' => 24,
        ],
        'new Tenant did not receive stable Runtime defaults',
    );
    app(ExecutionContextStore::class)->run(
        new \app\common\execution\AdminExecutionContext($gamma, 'test.transaction-settings.set.gamma'),
        fn() => app(TransactionSettingsApplicationService::class)->setConfig($gamma, [
            'tenant_id' => 101,
            'cancel_unpaid_orders' => 0,
            'verification_orders' => 0,
        ]),
    );
    expectTransactionTenant(
        (int) $pdo->query('SELECT tenant_id FROM pa_transaction_setting WHERE tenant_id = 303')->fetchColumn() === 303,
        'payload forged new policy Tenant ownership',
    );

    try {
        TenantContextRequirement::tenantId([]);
        throw new RuntimeException('invalid Tenant context was accepted');
    } catch (Throwable $exception) {
        expectTransactionTenant($exception->getMessage() !== '', 'invalid Tenant context denial lost shape');
    }
} finally {
    $admin->exec("DROP DATABASE IF EXISTS `{$database}`");
}

echo "MT03-TRANSACTION-SETTING-TENANT-ISOLATION-001 passed\n";
