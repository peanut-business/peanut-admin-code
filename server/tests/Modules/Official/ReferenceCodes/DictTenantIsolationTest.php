<?php

declare(strict_types=1);

use PeanutAdmin\Modules\ReferenceCodes\Service\DictDataApplicationService;
use PeanutAdmin\Modules\ReferenceCodes\Service\DictTypeApplicationService;
use app\common\execution\CurrentExecutionContext;
use app\common\execution\ExecutionContextStore;
use PeanutAdmin\Kernel\Auth\TenantContext;
use PeanutAdmin\Kernel\Auth\ValidatedTenantSession;

require dirname(__DIR__, 4) . '/vendor/autoload.php';
require dirname(__DIR__, 3) . '/Support/IsolatedBackendEnvironment.php';

function expectDictTenant(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function dictFailure(callable $operation): array
{
    try {
        $operation();
    } catch (Throwable $exception) {
        return [property_exists($exception, 'errorCode') ? $exception->errorCode : null, $exception->getMessage()];
    }
    throw new RuntimeException('expected dictionary operation to fail');
}

function dictTenantContext(int $tenantId, int $memberId, string $requestId): TenantContext
{
    return TenantContext::fromValidatedSession(new ValidatedTenantSession(
        $memberId,
        '01JMT02DICT' . str_pad((string) $memberId, 16, '0', STR_PAD_LEFT),
        $tenantId,
        $memberId + 10000,
        $memberId,
        'admin-web',
        new DateTimeImmutable('2031-01-01T00:00:00Z'),
        1,
    ), $requestId);
}

function dictTenantDatabase(PDO $admin, string $database): string
{
    $admin->exec("CREATE DATABASE `{$database}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    return $database;
}

function dictTenantEnv(string $name): string
{
    $value = getenv($name);
    if ($value === false || $value === '') {
        throw new RuntimeException("missing required environment variable: {$name}");
    }
    return $value;
}

function dictTenantPdo(string $host, int $port, string $password, string $database): PDO
{
    return new PDO(
        "mysql:host={$host};port={$port};dbname={$database};charset=utf8mb4",
        dictTenantEnv('DB_USER'),
        $password,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_EMULATE_PREPARES => false,
            PDO::MYSQL_ATTR_MULTI_STATEMENTS => true,
        ],
    );
}

function createDictTenantSchema(PDO $pdo): void
{
    $pdo->exec(<<<'SQL'
CREATE TABLE pa_tenant (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  status VARCHAR(32) NOT NULL,
  PRIMARY KEY (id)
) ENGINE=InnoDB;
CREATE TABLE pa_dict_type (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  name VARCHAR(100) NOT NULL DEFAULT '', type VARCHAR(100) NOT NULL DEFAULT '',
  is_disable TINYINT NOT NULL DEFAULT 0, remark VARCHAR(255) NOT NULL DEFAULT '',
  create_time INT UNSIGNED NOT NULL DEFAULT 0, update_time INT UNSIGNED NOT NULL DEFAULT 0,
  delete_time INT UNSIGNED NULL DEFAULT NULL, tenant_id BIGINT UNSIGNED NOT NULL,
  active_type VARCHAR(100) GENERATED ALWAYS AS (CASE WHEN delete_time IS NULL THEN type ELSE NULL END) STORED,
  PRIMARY KEY (id), KEY idx_type (type),
  UNIQUE KEY uk_dict_type_tenant_id (tenant_id, id),
  UNIQUE KEY uk_dict_type_tenant_active_type (tenant_id, active_type),
  KEY idx_dict_type_tenant_status_name (tenant_id, is_disable, name, id),
  CONSTRAINT fk_dict_type_tenant FOREIGN KEY (tenant_id) REFERENCES pa_tenant (id) ON DELETE RESTRICT
) ENGINE=InnoDB;
CREATE TABLE pa_dict_data (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  name VARCHAR(100) NOT NULL DEFAULT '', value VARCHAR(255) NOT NULL DEFAULT '',
  type_id INT UNSIGNED NOT NULL DEFAULT 0, type_value VARCHAR(100) NOT NULL DEFAULT '',
  sort SMALLINT NOT NULL DEFAULT 0, is_disable TINYINT NOT NULL DEFAULT 0,
  remark VARCHAR(255) NOT NULL DEFAULT '', create_time INT UNSIGNED NOT NULL DEFAULT 0,
  update_time INT UNSIGNED NOT NULL DEFAULT 0, delete_time INT UNSIGNED NULL DEFAULT NULL,
  tenant_id BIGINT UNSIGNED NOT NULL,
  PRIMARY KEY (id), KEY idx_type_id (type_id),
  UNIQUE KEY uk_dict_data_tenant_id (tenant_id, id),
  KEY idx_dict_data_tenant_type_status_sort (tenant_id, type_id, is_disable, sort, id),
  CONSTRAINT fk_dict_data_tenant FOREIGN KEY (tenant_id) REFERENCES pa_tenant (id) ON DELETE RESTRICT,
  CONSTRAINT fk_dict_data_tenant_type FOREIGN KEY (tenant_id, type_id) REFERENCES pa_dict_type (tenant_id, id) ON DELETE RESTRICT
) ENGINE=InnoDB;
CREATE TABLE pa_system_dict_type (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  code VARCHAR(100) NOT NULL,
  name VARCHAR(100) NOT NULL DEFAULT '',
  is_disable TINYINT NOT NULL DEFAULT 0,
  remark VARCHAR(255) NOT NULL DEFAULT '',
  create_time INT UNSIGNED NOT NULL DEFAULT 0,
  update_time INT UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (id), UNIQUE KEY uk_system_dict_type_code (code)
) ENGINE=InnoDB;
CREATE TABLE pa_system_dict_data (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  type_code VARCHAR(100) NOT NULL,
  name VARCHAR(100) NOT NULL DEFAULT '',
  value VARCHAR(255) NOT NULL DEFAULT '',
  sort SMALLINT NOT NULL DEFAULT 0,
  is_disable TINYINT NOT NULL DEFAULT 0,
  remark VARCHAR(255) NOT NULL DEFAULT '',
  create_time INT UNSIGNED NOT NULL DEFAULT 0,
  update_time INT UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (id), UNIQUE KEY uk_system_dict_data_type_value (type_code, value),
  CONSTRAINT fk_system_dict_data_type FOREIGN KEY (type_code) REFERENCES pa_system_dict_type (code)
) ENGINE=InnoDB;
SQL);
}

function seedDictTenantSchema(PDO $pdo): void
{
    $pdo->exec(<<<'SQL'
INSERT INTO pa_tenant (id, status) VALUES (101, 'active'), (202, 'active');
INSERT INTO pa_dict_type (id, tenant_id, name, type) VALUES
  (11, 101, 'Alpha', 'shared_key'),
  (12, 202, 'Beta', 'shared_key');
INSERT INTO pa_dict_data (id, tenant_id, name, value, type_id, type_value, sort) VALUES
  (21, 101, 'Alpha item', 'alpha', 11, 'shared_key', 10),
  (22, 202, 'Beta item', 'beta', 12, 'shared_key', 20);
SQL);
}

$host = dictTenantEnv('DB_HOST');
$port = (int) dictTenantEnv('DB_PORT');
$user = dictTenantEnv('DB_USER');
$password = dictTenantEnv('DB_PASS');
$database = dictTenantEnv('DB_NAME');
$admin = new PDO(
    "mysql:host={$host};port={$port};charset=utf8mb4",
    $user,
    $password,
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
);
$database = dictTenantDatabase($admin, $database);

try {
    $pdo = dictTenantPdo($host, $port, $password, $database);
    createDictTenantSchema($pdo);
    seedDictTenantSchema($pdo);
    try {
        $pdo->exec("INSERT INTO pa_dict_type (tenant_id, name, type) VALUES (202, 'Duplicate Beta', 'shared_key')");
        throw new RuntimeException('same-Tenant active dictionary type duplicate unexpectedly succeeded');
    } catch (PDOException $exception) {
        expectDictTenant($exception->getCode() === '23000', 'dictionary uniqueness failed with an unexpected shape');
    }

    IsolatedBackendEnvironment::activateDatabase($host, $port, $database, $user, $password, 'multi-tenant');
    $app = new think\App();
    $app->initialize();

    $alpha = dictTenantContext(101, 501, 'mt02-dict-alpha');
    $beta = dictTenantContext(202, 502, 'mt02-dict-beta');
    try {
        app(CurrentExecutionContext::class)->tenantAdmin();
        throw new RuntimeException('missing TenantContext unexpectedly reached dictionary Runtime');
    } catch (Throwable $exception) {
        expectDictTenant($exception->getMessage() !== '', 'missing context denial lost its shape');
    }

    expectDictTenant(
        app(ExecutionContextStore::class)->run(
            new \app\common\execution\AdminExecutionContext($alpha, 'test.dict.type.list.alpha'),
            fn() => app(DictTypeApplicationService::class)->lists($alpha, []),
        )->total() === 1,
        'Alpha type list crossed Tenant boundary',
    );
    expectDictTenant(
        app(ExecutionContextStore::class)->run(
            new \app\common\execution\AdminExecutionContext($beta, 'test.dict.type.list.beta'),
            fn() => app(DictTypeApplicationService::class)->lists($beta, []),
        )->total() === 1,
        'Beta type list crossed Tenant boundary',
    );
    expectDictTenant(
        app(ExecutionContextStore::class)->run(
            new \app\common\execution\AdminExecutionContext($alpha, 'test.dict.data.list.alpha'),
            fn() => app(DictDataApplicationService::class)->lists($alpha, []),
        )->total() === 1,
        'Alpha data list crossed Tenant boundary',
    );
    expectDictTenant(
        app(ExecutionContextStore::class)->run(
            new \app\common\execution\AdminExecutionContext($beta, 'test.dict.data.list.beta'),
            fn() => app(DictDataApplicationService::class)->lists($beta, []),
        )->total() === 1,
        'Beta data list crossed Tenant boundary',
    );
    expectDictTenant(
        app(ExecutionContextStore::class)->run(
            new \app\common\execution\AdminExecutionContext($alpha, 'test.dict.type.detail.cross-tenant'),
            fn() => app(DictTypeApplicationService::class)->detail($alpha, 12),
        ) === [],
        'cross-Tenant type detail was visible',
    );
    expectDictTenant(
        app(ExecutionContextStore::class)->run(
            new \app\common\execution\AdminExecutionContext($alpha, 'test.dict.data.detail.cross-tenant'),
            fn() => app(DictDataApplicationService::class)->detail($alpha, 22),
        ) === [],
        'cross-Tenant data detail was visible',
    );
    expectDictTenant(
        app(ExecutionContextStore::class)->run(
            new \app\common\execution\AdminExecutionContext($alpha, 'test.dict.type.detail.missing'),
            fn() => app(DictTypeApplicationService::class)->detail($alpha, 999999),
        ) === [],
        'missing type detail shape changed',
    );
    expectDictTenant(
        app(ExecutionContextStore::class)->run(
            new \app\common\execution\AdminExecutionContext($alpha, 'test.dict.data.detail.missing'),
            fn() => app(DictDataApplicationService::class)->detail($alpha, 999999),
        ) === [],
        'missing data detail shape changed',
    );

    expectDictTenant(
        app(ExecutionContextStore::class)->run(
            new \app\common\execution\AdminExecutionContext($alpha, 'test.dict.type.add'),
            fn() => app(DictTypeApplicationService::class)->add($alpha, [
                'tenant_id' => 202,
                'name' => 'Alpha status',
                'type' => 'status',
            ]),
        ),
        'Alpha dictionary type add failed',
    );
    $alphaTypeId = (int) $pdo->query("SELECT id FROM pa_dict_type WHERE tenant_id = 101 AND type = 'status' LIMIT 1")->fetchColumn();
    expectDictTenant($alphaTypeId > 0, 'Alpha type was not created');
    expectDictTenant(
        (int) $pdo->query("SELECT tenant_id FROM pa_dict_type WHERE id = {$alphaTypeId}")->fetchColumn() === 101,
        'payload tenant_id overrode trusted dictionary owner',
    );
    $crossParentDenied = dictFailure(fn() => app(ExecutionContextStore::class)->run(
        new \app\common\execution\AdminExecutionContext($alpha, 'test.dict.data.add.cross-tenant'),
        fn() => app(DictDataApplicationService::class)->add($alpha, [
            'tenant_id' => 202,
            'type_id' => 12,
            'name' => 'Cross Tenant',
            'value' => 'forbidden',
        ]),
    ));
    expectDictTenant($crossParentDenied[1] === '字典类型不存在', 'cross-Tenant dictionary parent denial changed');

    expectDictTenant(
        app(ExecutionContextStore::class)->run(
            new \app\common\execution\AdminExecutionContext($alpha, 'test.dict.data.add'),
            fn() => app(DictDataApplicationService::class)->add($alpha, [
                'tenant_id' => 202,
                'type_id' => $alphaTypeId,
                'name' => 'Enabled',
                'value' => '1',
                'sort' => 30,
            ]),
        ),
        'Alpha dictionary data add failed',
    );
    $alphaDataId = (int) $pdo->query("SELECT id FROM pa_dict_data WHERE tenant_id = 101 AND type_id = {$alphaTypeId} LIMIT 1")->fetchColumn();
    expectDictTenant($alphaDataId > 0, 'Alpha dictionary data was not created');
    expectDictTenant(
        array_column(app(ExecutionContextStore::class)->run(
            new \app\common\execution\AdminExecutionContext($alpha, 'test.dict.data.by-type.alpha'),
            fn() => app(DictDataApplicationService::class)->byType($alpha, 'status'),
        ), 'value') === ['1'],
        'Alpha byType lost owned data',
    );
    expectDictTenant(
        app(ExecutionContextStore::class)->run(
            new \app\common\execution\AdminExecutionContext($beta, 'test.dict.data.by-type.beta'),
            fn() => app(DictDataApplicationService::class)->byType($beta, 'status'),
        ) === [],
        'Beta byType leaked Alpha data',
    );

    $betaBefore = $pdo->query('SELECT name, type, is_disable FROM pa_dict_type WHERE id = 12')->fetch(PDO::FETCH_ASSOC);
    $betaDataBefore = $pdo->query('SELECT name, value, type_value, is_disable FROM pa_dict_data WHERE id = 22')->fetch(PDO::FETCH_ASSOC);
    $typeDenied = dictFailure(fn() => app(ExecutionContextStore::class)->run(
        new \app\common\execution\AdminExecutionContext($alpha, 'test.dict.type.edit.cross-tenant'),
        fn() => app(DictTypeApplicationService::class)->edit($alpha, [
            'id' => 12, 'name' => 'Cross Tenant', 'type' => 'cross_tenant',
        ]),
    ));
    $missingTypeDenied = dictFailure(fn() => app(ExecutionContextStore::class)->run(
        new \app\common\execution\AdminExecutionContext($alpha, 'test.dict.type.edit.missing'),
        fn() => app(DictTypeApplicationService::class)->edit($alpha, [
            'id' => 999999, 'name' => 'Missing', 'type' => 'missing',
        ]),
    ));
    expectDictTenant($missingTypeDenied === $typeDenied, 'type edit enumerated Tenant ownership');

    $dataDenied = dictFailure(fn() => app(ExecutionContextStore::class)->run(
        new \app\common\execution\AdminExecutionContext($alpha, 'test.dict.data.edit.cross-tenant'),
        fn() => app(DictDataApplicationService::class)->edit($alpha, [
            'id' => 22, 'name' => 'Cross Tenant', 'value' => 'forbidden',
        ]),
    ));
    $missingDataDenied = dictFailure(fn() => app(ExecutionContextStore::class)->run(
        new \app\common\execution\AdminExecutionContext($alpha, 'test.dict.data.edit.missing'),
        fn() => app(DictDataApplicationService::class)->edit($alpha, [
            'id' => 999999, 'name' => 'Missing', 'value' => 'missing',
        ]),
    ));
    expectDictTenant($missingDataDenied === $dataDenied, 'data edit enumerated Tenant ownership');

    expectDictTenant(
        app(ExecutionContextStore::class)->run(
            new \app\common\execution\AdminExecutionContext($alpha, 'test.dict.type.edit'),
            fn() => app(DictTypeApplicationService::class)->edit($alpha, [
                'id' => $alphaTypeId, 'name' => 'Alpha state', 'type' => 'state',
            ]),
        ),
        'Alpha dictionary type edit failed',
    );
    expectDictTenant(
        (string) $pdo->query("SELECT type_value FROM pa_dict_data WHERE id = {$alphaDataId}")->fetchColumn() === 'state',
        'Alpha type rename did not synchronize owned data',
    );
    expectDictTenant(
        (string) $pdo->query('SELECT type_value FROM pa_dict_data WHERE id = 22')->fetchColumn() === 'shared_key',
        'Alpha type rename changed Beta data',
    );
    $occupiedTypeDenied = dictFailure(fn() => app(ExecutionContextStore::class)->run(
        new \app\common\execution\AdminExecutionContext($alpha, 'test.dict.type.delete.occupied'),
        fn() => app(DictTypeApplicationService::class)->delete($alpha, $alphaTypeId),
    ));
    expectDictTenant($occupiedTypeDenied[1] === '字典类型已被数据项使用，请先删除数据项', 'occupied delete lost its failure shape');
    $typeStatusDenied = dictFailure(fn() => app(ExecutionContextStore::class)->run(
        new \app\common\execution\AdminExecutionContext($alpha, 'test.dict.type.status.cross-tenant'),
        fn() => app(DictTypeApplicationService::class)->updateStatus($alpha, 12, 1),
    ));
    $missingTypeStatusDenied = dictFailure(fn() => app(ExecutionContextStore::class)->run(
        new \app\common\execution\AdminExecutionContext($alpha, 'test.dict.type.status.missing'),
        fn() => app(DictTypeApplicationService::class)->updateStatus($alpha, 999999, 1),
    ));
    expectDictTenant($missingTypeStatusDenied === $typeStatusDenied, 'type status enumerated Tenant ownership');
    $dataStatusDenied = dictFailure(fn() => app(ExecutionContextStore::class)->run(
        new \app\common\execution\AdminExecutionContext($alpha, 'test.dict.data.status.cross-tenant'),
        fn() => app(DictDataApplicationService::class)->updateStatus($alpha, 22, 1),
    ));
    $missingDataStatusDenied = dictFailure(fn() => app(ExecutionContextStore::class)->run(
        new \app\common\execution\AdminExecutionContext($alpha, 'test.dict.data.status.missing'),
        fn() => app(DictDataApplicationService::class)->updateStatus($alpha, 999999, 1),
    ));
    expectDictTenant($missingDataStatusDenied === $dataStatusDenied, 'data status enumerated Tenant ownership');
    dictFailure(fn() => app(ExecutionContextStore::class)->run(
        new \app\common\execution\AdminExecutionContext($alpha, 'test.dict.type.delete.cross-tenant'),
        fn() => app(DictTypeApplicationService::class)->delete($alpha, 12),
    ));
    dictFailure(fn() => app(ExecutionContextStore::class)->run(
        new \app\common\execution\AdminExecutionContext($alpha, 'test.dict.data.delete.cross-tenant'),
        fn() => app(DictDataApplicationService::class)->delete($alpha, 22),
    ));

    expectDictTenant(
        $pdo->query('SELECT name, type, is_disable FROM pa_dict_type WHERE id = 12')->fetch(PDO::FETCH_ASSOC) === $betaBefore,
        'cross-Tenant denial changed Beta type',
    );
    expectDictTenant(
        $pdo->query('SELECT name, value, type_value, is_disable FROM pa_dict_data WHERE id = 22')->fetch(PDO::FETCH_ASSOC) === $betaDataBefore,
        'cross-Tenant denial changed Beta data',
    );
} finally {
    $admin->exec("DROP DATABASE IF EXISTS `{$database}`");
}

echo "MT02-DICT-TENANT-ISOLATION-001 passed\n";
