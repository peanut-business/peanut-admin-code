<?php

declare(strict_types=1);

use app\adminapi\application\setting\HotSearchApplicationService as AdminHotSearchLogic;
use app\api\application\SearchApplicationService as ApiSearchLogic;
use app\common\execution\CurrentExecutionContext;
use app\common\execution\ExecutionContextStore;
use PeanutAdmin\Kernel\Auth\TenantContext;
use PeanutAdmin\Kernel\Auth\ValidatedTenantSession;
use PeanutAdmin\Kernel\Context\TenantSystemContext;

require dirname(__DIR__, 2) . '/vendor/autoload.php';
require __DIR__ . '/../Support/IsolatedBackendEnvironment.php';

function expectHotSearchTenant(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function hotSearchTenantContext(int $tenantId, int $memberId, string $requestId): TenantContext
{
    return TenantContext::fromValidatedSession(new ValidatedTenantSession(
        $memberId,
        '01JMT03HOTSEARCH' . str_pad((string) $memberId, 11, '0', STR_PAD_LEFT),
        $tenantId,
        $memberId + 10000,
        $memberId,
        'admin-web',
        new DateTimeImmutable('2031-01-01T00:00:00Z'),
        1,
    ), $requestId);
}

function createHotSearchTenantSchema(PDO $pdo): void
{
    $pdo->exec(<<<'SQL'
CREATE TABLE pa_tenant (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  status VARCHAR(32) NOT NULL,
  PRIMARY KEY (id)
) ENGINE=InnoDB;
CREATE TABLE pa_hot_search (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  name VARCHAR(200) NOT NULL DEFAULT '',
  sort SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  create_time INT UNSIGNED NOT NULL DEFAULT 0,
  tenant_id BIGINT UNSIGNED NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uk_hot_search_tenant_id (tenant_id, id),
  KEY idx_hot_search_tenant_sort (tenant_id, sort, id),
  CONSTRAINT fk_hot_search_tenant FOREIGN KEY (tenant_id) REFERENCES pa_tenant (id) ON DELETE RESTRICT
) ENGINE=InnoDB;
CREATE TABLE pa_tenant_setting (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  tenant_id BIGINT UNSIGNED NOT NULL,
  namespace VARCHAR(64) NOT NULL,
  config_json JSON NOT NULL,
  revision BIGINT UNSIGNED NOT NULL DEFAULT 1,
  create_time INT UNSIGNED NOT NULL DEFAULT 0,
  update_time INT UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (id),
  UNIQUE KEY uk_tenant_setting_namespace (tenant_id, namespace),
  CONSTRAINT fk_tenant_setting_tenant FOREIGN KEY (tenant_id) REFERENCES pa_tenant (id) ON DELETE CASCADE
) ENGINE=InnoDB;
SQL);
}

function seedHotSearchTenantSchema(PDO $pdo): void
{
    $pdo->exec(<<<'SQL'
INSERT INTO pa_tenant (id, status) VALUES (101, 'active'), (202, 'active');
INSERT INTO pa_hot_search (id, tenant_id, name, sort) VALUES
  (11, 101, 'Alpha seed', 10),
  (21, 202, 'Same term', 30),
  (22, 202, 'Beta only', 20);
INSERT INTO pa_tenant_setting (tenant_id, namespace, config_json) VALUES
  (101, 'hot-search', JSON_OBJECT('status', 1)),
  (202, 'hot-search', JSON_OBJECT('status', 1));
SQL);
}

$host = IsolatedBackendEnvironment::required('DB_HOST');
$port = (int) IsolatedBackendEnvironment::required('DB_PORT');
$user = IsolatedBackendEnvironment::required('DB_USER');
$password = IsolatedBackendEnvironment::required('DB_PASS');
$runId = strtolower(bin2hex(random_bytes(5)));
$database = 'peanut_admin_mt03_hot_search_' . $runId;
$admin = new PDO(
    "mysql:host={$host};port={$port};charset=utf8mb4",
    $user,
    $password,
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::MYSQL_ATTR_MULTI_STATEMENTS => true],
);
$admin->exec("CREATE DATABASE `{$database}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");

try {
    $pdo = new PDO(
        "mysql:host={$host};port={$port};dbname={$database};charset=utf8mb4",
        $user,
        $password,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES => false, PDO::MYSQL_ATTR_MULTI_STATEMENTS => true],
    );
    createHotSearchTenantSchema($pdo);
    seedHotSearchTenantSchema($pdo);
    IsolatedBackendEnvironment::activateDatabase($host, $port, $database, $user, $password, 'multi-tenant');
    $app = new think\App();
    $app->initialize();

    $alpha = hotSearchTenantContext(101, 501, 'mt03-hot-search-alpha-' . $runId);
    $beta = hotSearchTenantContext(202, 502, 'mt03-hot-search-beta-' . $runId);
    $before = $pdo->query("SELECT id, name, sort FROM pa_hot_search WHERE tenant_id = 202 ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);
    $statusBefore = (string) $pdo->query("SELECT config_json FROM pa_tenant_setting WHERE tenant_id = 202 AND namespace = 'hot-search'")->fetchColumn();

    try {
        app(CurrentExecutionContext::class)->tenantAdmin();
        throw new RuntimeException('missing TenantContext unexpectedly reached hot-search write path');
    } catch (Throwable $exception) {
        expectHotSearchTenant($exception->getMessage() !== '', 'missing context denial lost its shape');
    }
    expectHotSearchTenant(
        $pdo->query("SELECT id, name, sort FROM pa_hot_search WHERE tenant_id = 202 ORDER BY id")->fetchAll(PDO::FETCH_ASSOC) === $before,
        'missing context changed Beta terms',
    );
    expectHotSearchTenant(
        (string) $pdo->query("SELECT config_json FROM pa_tenant_setting WHERE tenant_id = 202 AND namespace = 'hot-search'")->fetchColumn() === $statusBefore,
        'missing context changed Beta status',
    );
    try {
        app(ApiSearchLogic::class)->hotLists();
        throw new RuntimeException('missing public TenantContext unexpectedly read hot-search terms');
    } catch (Throwable $exception) {
        expectHotSearchTenant($exception->getMessage() !== '', 'missing public context denial lost its shape');
    }

    expectHotSearchTenant(
        app(ExecutionContextStore::class)->run(
            new \app\common\execution\AdminExecutionContext($alpha, 'test.hot-search.set.alpha'),
            fn() => app(AdminHotSearchLogic::class)->setConfig($alpha, [
                'status' => 0,
                'data' => [
                    ['tenant_id' => 202, 'name' => 'Same term', 'sort' => 90],
                    ['tenant_id' => 202, 'name' => 'Alpha only', 'sort' => 80],
                ],
            ]),
        ),
        'Alpha hot-search replacement failed',
    );
    expectHotSearchTenant(
        $pdo->query("SELECT id, name, sort FROM pa_hot_search WHERE tenant_id = 202 ORDER BY id")->fetchAll(PDO::FETCH_ASSOC) === $before,
        'Alpha full replacement changed Beta terms',
    );
    expectHotSearchTenant((int) $pdo->query("SELECT COUNT(*) FROM pa_hot_search WHERE tenant_id = 101")->fetchColumn() === 2, 'Alpha replacement did not remain Tenant-scoped');
    expectHotSearchTenant((int) $pdo->query("SELECT COUNT(*) FROM pa_hot_search WHERE tenant_id = 202 AND name = 'Same term'")->fetchColumn() === 1, 'Beta same-name term was deleted');
    expectHotSearchTenant((int) $pdo->query("SELECT COUNT(*) FROM pa_hot_search WHERE tenant_id = 202 AND name = 'Alpha only'")->fetchColumn() === 0, 'payload forged hot-search owner');
    expectHotSearchTenant(
        (int) $pdo->query("SELECT JSON_UNQUOTE(JSON_EXTRACT(config_json, '$.status')) FROM pa_tenant_setting WHERE tenant_id = 101 AND namespace = 'hot-search'")->fetchColumn() === 0,
        'Alpha status was not updated',
    );
    expectHotSearchTenant(
        (int) $pdo->query("SELECT JSON_UNQUOTE(JSON_EXTRACT(config_json, '$.status')) FROM pa_tenant_setting WHERE tenant_id = 202 AND namespace = 'hot-search'")->fetchColumn() === 1,
        'Alpha status update changed Beta status',
    );

    $adminAlpha = app(ExecutionContextStore::class)->run(
        new \app\common\execution\AdminExecutionContext($alpha, 'test.hot-search.get.alpha'),
        fn() => app(AdminHotSearchLogic::class)->getConfig($alpha),
    );
    $adminBeta = app(ExecutionContextStore::class)->run(
        new \app\common\execution\AdminExecutionContext($beta, 'test.hot-search.get.beta'),
        fn() => app(AdminHotSearchLogic::class)->getConfig($beta),
    );
    expectHotSearchTenant(array_column($adminAlpha['data'], 'name') === ['Same term', 'Alpha only'], 'admin Alpha list leaked or lost terms');
    expectHotSearchTenant(array_column($adminBeta['data'], 'name') === ['Same term', 'Beta only'], 'admin Beta list leaked or lost terms');
    expectHotSearchTenant($adminAlpha['status'] === 0 && $adminBeta['status'] === 1, 'status is not Tenant-owned');

    $publicAlpha = new TenantSystemContext(101, 'peanut.hot-search.public-read', 'hot-search.lists', 'public-alpha-' . $runId);
    $publicBeta = new TenantSystemContext(202, 'peanut.hot-search.public-read', 'hot-search.lists', 'public-beta-' . $runId);
    $publicAlphaResult = app(ExecutionContextStore::class)->run(
        \app\common\execution\ConsumerExecutionContext::publicTenant($publicAlpha),
        fn() => app(ApiSearchLogic::class)->hotLists($publicAlpha),
    );
    $publicBetaResult = app(ExecutionContextStore::class)->run(
        \app\common\execution\ConsumerExecutionContext::publicTenant($publicBeta),
        fn() => app(ApiSearchLogic::class)->hotLists($publicBeta),
    );
    expectHotSearchTenant(array_column($publicAlphaResult['data'], 'name') === ['Same term', 'Alpha only'], 'public Alpha read crossed Tenant');
    expectHotSearchTenant(array_column($publicBetaResult['data'], 'name') === ['Same term', 'Beta only'], 'public Beta read crossed Tenant');
    expectHotSearchTenant($publicAlphaResult['status'] === 0 && $publicBetaResult['status'] === 1, 'public status crossed Tenant');
    try {
        app(ApiSearchLogic::class)->hotLists(new TenantSystemContext(101, 'untrusted.actor', 'hot-search.lists', 'forged-' . $runId));
        throw new RuntimeException('untrusted public context unexpectedly read hot-search terms');
    } catch (Throwable $exception) {
        expectHotSearchTenant($exception->getMessage() !== '', 'untrusted public denial lost its shape');
    }
} finally {
    $admin->exec("DROP DATABASE IF EXISTS `{$database}`");
}

echo "MT03-HOT-SEARCH-TENANT-ISOLATION-001 passed\n";
