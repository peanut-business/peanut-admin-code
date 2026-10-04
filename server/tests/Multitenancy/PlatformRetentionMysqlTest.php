<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/vendor/autoload.php';
require_once __DIR__ . '/../Support/ThinkPhpTestConnection.php';
require_once __DIR__ . '/../Support/RegisteredMysqlTestResource.php';
require_once dirname(__DIR__, 2) . '/vendor/topthink/framework/src/helper.php';

use app\common\execution\AdminExecutionContext;
use app\common\execution\CurrentExecutionContext;
use app\common\execution\ExecutionContextStore;
use app\common\execution\InstanceExecutionContext;
use app\common\services\audit\AuditContractHost;
use PeanutAdmin\Kernel\Auth\TenantContext;
use PeanutAdmin\Kernel\Auth\ValidatedTenantSession;
use PeanutAdmin\Kernel\Persistence\Tenancy\TenantPersistenceMode;
use PeanutAdmin\Modules\ImportExport\Engine\Database\Schema as ImportSchema;
use PeanutAdmin\Modules\ImportExport\Engine\Persistence\ImportExportStore;
use PeanutAdmin\Modules\Integration\Infrastructure\Persistence\ThinkPhpIntegrationSecurityRepository;
use think\App;
use think\facade\Db;

$checks = 0;
function checkMysql(bool $condition, string $message): void
{
    global $checks;
    ++$checks;
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function rejectedMysql(callable $action, string $message): void
{
    try {
        $action();
    } catch (Throwable $failure) {
        checkMysql(str_contains($failure->getMessage(), $message), 'wrong rejection: ' . $failure->getMessage());
        return;
    }
    throw new RuntimeException('maintenance call was accepted: ' . $message);
}

function rowsMysql(PDO $pdo, string $table): array
{
    return $pdo->query('SELECT * FROM `' . $table . '` ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
}

function auditFailureTrigger(PDO $pdo): void
{
    $pdo->exec("CREATE TRIGGER reject_success_audit BEFORE INSERT ON pa_platform_audit_event FOR EACH ROW BEGIN IF NEW.outcome = 'success' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'synthetic audit failure'; END IF; END");
}

function maintenanceContext(ExecutionContextStore $contexts, string $operation, callable $action): mixed
{
    return $contexts->run(new InstanceExecutionContext('console.' . $operation, 'retention-mysql-' . $operation), $action);
}

function tenantContext(string $operation): AdminExecutionContext
{
    $session = new ValidatedTenantSession(1, 'retention-mysql-tenant', 101, 301, 501, 'admin-web', new DateTimeImmutable('2035-01-01'), 1);
    return new AdminExecutionContext(TenantContext::fromValidatedSession($session, 'retention-mysql-request'), 'console.' . $operation);
}

function importExportMysql(PDO $pdo, ExecutionContextStore $contexts, CurrentExecutionContext $current, AuditContractHost $audit): void
{
    $pdo->exec('CREATE TABLE pa_tenant (id BIGINT UNSIGNED NOT NULL PRIMARY KEY) ENGINE=InnoDB');
    $pdo->exec('CREATE TABLE pa_tenant_member (id BIGINT UNSIGNED NOT NULL PRIMARY KEY, tenant_id BIGINT UNSIGNED NOT NULL, UNIQUE KEY tenant_member (tenant_id,id), CONSTRAINT fk_retention_member_tenant FOREIGN KEY (tenant_id) REFERENCES pa_tenant(id)) ENGINE=InnoDB');
    $pdo->exec('INSERT INTO pa_tenant VALUES (101),(202)');
    $pdo->exec('INSERT INTO pa_tenant_member VALUES (501,101),(502,202)');
    foreach (ImportSchema::tableNames() as $table) {
        $pdo->exec(ImportSchema::createSql($table));
    }
    $pdo->exec(\PeanutAdmin\Kernel\Persistence\Schema\KernelSchema::createSql('pa_platform_audit_event'));
    $insert = $pdo->prepare('INSERT INTO pa_import_export_operation (id,operation_key,tenant_id,created_by_member_id,provider_key,direction,status,input_file_key,result_file_key,error_file_key,schema_revision,mapping_json,idempotency_key_hash,request_hash,retention_until,created_at,updated_at,completed_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)');
    foreach ([
        [1, 101, 501, 'succeeded', '2000-01-01 00:00:00.000'],
        [2, 202, 502, 'failed', '2000-01-01 00:00:00.000'],
        [3, 101, 501, 'cancelled', '2000-01-01 00:00:00.000'],
        [4, 101, 501, 'running', '2000-01-01 00:00:00.000'],
        [5, 202, 502, 'succeeded', '2099-01-01 00:00:00.000'],
    ] as [$id, $tenant, $member, $status, $retention]) {
        $insert->execute([
            $id, sprintf('iox_%032x', $id), $tenant, $member, 'retention', 'export', $status,
            null, sprintf('file_%032x', $id), sprintf('file_%032x', $id + 100),
            'v1', '{}', hash('sha256', 'idem-' . $id), hash('sha256', 'request-' . $id),
            $retention, '2000-01-01 00:00:00.000', '2000-01-01 00:00:00.000',
            $status === 'running' ? null : '2000-01-01 00:00:00.000',
        ]);
    }
    $store = new ImportExportStore(TenantPersistenceMode::TenantScoped, null, $current, $audit);
    $before = rowsMysql($pdo, 'pa_import_export_operation');
    rejectedMysql(fn() => $store->expireDue(), 'MAINTENANCE_CONTEXT_REQUIRED');
    $contexts->run(new InstanceExecutionContext('console.ordinary:task', 'wrong-instance'),
        fn() => rejectedMysql(fn() => $store->expireDue(), 'MAINTENANCE_CONTEXT_REQUIRED'));
    $contexts->run(tenantContext('import-export:expire'), fn() => rejectedMysql(fn() => $store->expireDue(), 'MAINTENANCE_CONTEXT_REQUIRED'));
    checkMysql(rowsMysql($pdo, 'pa_import_export_operation') === $before, 'rejected import calls mutated rows');
    $wrongMode = new ImportExportStore(TenantPersistenceMode::InstanceScoped, 101, $current, $audit);
    maintenanceContext($contexts, 'import-export:expire',
        fn() => rejectedMysql(fn() => $wrongMode->expireDue(1), 'TENANT_PERSISTENCE_SCHEMA_MODE_MISMATCH'));
    auditFailureTrigger($pdo);
    try {
        maintenanceContext($contexts, 'import-export:expire', fn() => $store->expireDue(1));
        throw new RuntimeException('import audit failure did not roll back');
    } catch (Throwable $failure) {
        checkMysql(str_contains($failure->getMessage(), 'synthetic audit failure'), 'import audit failure missing');
    }
    checkMysql(rowsMysql($pdo, 'pa_import_export_operation') === $before, 'import audit failure committed rows');
    checkMysql((int) $pdo->query("SELECT COUNT(*) FROM pa_platform_audit_event WHERE outcome='error'")->fetchColumn() === 1, 'import failure audit missing');
    $pdo->exec('DROP TRIGGER reject_success_audit');
    checkMysql(maintenanceContext($contexts, 'import-export:expire', fn() => $store->expireDue(1)) === 1, 'import batch one');
    checkMysql(maintenanceContext($contexts, 'import-export:expire', fn() => $store->expireDue(2)) === 2, 'import batch two');
    checkMysql(maintenanceContext($contexts, 'import-export:expire', fn() => $store->expireDue(2)) === 0, 'import repeat');
    $rows = rowsMysql($pdo, 'pa_import_export_operation');
    checkMysql(array_column($rows, 'status') === ['expired', 'expired', 'expired', 'running', 'succeeded'], 'import status/retention');
    checkMysql(array_slice(array_column($rows, 'result_file_key'), 0, 3) === [null, null, null], 'import result references');
    checkMysql(array_slice(array_column($rows, 'error_file_key'), 0, 3) === [null, null, null], 'import error references');
    checkMysql(array_map('intval', array_column($rows, 'revision')) === [2, 2, 2, 1, 1], 'import revisions');
    checkMysql($rows[3]['result_file_key'] !== null && $rows[4]['result_file_key'] !== null, 'import noneligible references');
    checkMysql((int) $pdo->query("SELECT COUNT(*) FROM pa_platform_audit_event WHERE outcome='success'")->fetchColumn() === 3, 'import success audits');
    checkMysql(!$pdo->inTransaction() && $contexts->isEmpty(), 'import transaction/context leaked');
}

function integrationMysql(PDO $pdo, ExecutionContextStore $contexts, CurrentExecutionContext $current, AuditContractHost $audit): void
{
    $pdo->exec('CREATE TABLE pa_integration_webhook_delivery (id BIGINT UNSIGNED NOT NULL PRIMARY KEY, tenant_id BIGINT UNSIGNED NOT NULL, status VARCHAR(24) NOT NULL, payload_json JSON NULL, payload_expires_at DATETIME(3) NOT NULL, updated_at DATETIME(3) NOT NULL, UNIQUE KEY tenant_delivery (tenant_id,id)) ENGINE=InnoDB');
    $pdo->exec('CREATE TABLE pa_integration_webhook_attempt (id BIGINT UNSIGNED NOT NULL PRIMARY KEY, tenant_id BIGINT UNSIGNED NOT NULL, delivery_id BIGINT UNSIGNED NOT NULL, CONSTRAINT fk_retention_attempt_delivery FOREIGN KEY (tenant_id,delivery_id) REFERENCES pa_integration_webhook_delivery(tenant_id,id) ON DELETE RESTRICT) ENGINE=InnoDB');
    $pdo->exec(\PeanutAdmin\Kernel\Persistence\Schema\KernelSchema::createSql('pa_platform_audit_event'));
    $insert = $pdo->prepare('INSERT INTO pa_integration_webhook_delivery VALUES (?,?,?,?,?,?)');
    foreach ([
        [11, 101, 'delivered', 'private-body-one', '2000-01-01 00:00:00.000'],
        [12, 202, 'permanent_failed', 'private-body-two', '2000-01-01 00:00:00.000'],
        [13, 101, 'pending', 'pending-body', '2000-01-01 00:00:00.000'],
        [14, 202, 'delivered', 'future-body', '2099-01-01 00:00:00.000'],
        [15, 101, 'delivered', 'millisecond-body', '2031-01-02 00:00:00.501'],
    ] as [$id, $tenant, $status, $body, $cutoff]) {
        $insert->execute([$id, $tenant, $status, json_encode(['body' => $body], JSON_THROW_ON_ERROR), $cutoff, $cutoff]);
    }
    $attempt = $pdo->prepare('INSERT INTO pa_integration_webhook_attempt VALUES (?,?,?)');
    foreach ([[1,101,11],[2,101,11],[3,101,11],[4,101,11],[5,101,11],[6,202,12],[7,202,12],[8,101,13]] as $row) {
        $attempt->execute($row);
    }
    $repository = new ThinkPhpIntegrationSecurityRepository($current, $audit);
    $payloadCutoff = new DateTimeImmutable('2031-01-02T00:00:00.500Z');
    $earlyCutoff = new DateTimeImmutable('1999-01-01T00:00:00Z');
    $purge = fn(DateTimeImmutable $payload, DateTimeImmutable $evidence, int $limit) => $repository->purgeExpiredDeliveryData($payload, $evidence, $limit);
    $before = [rowsMysql($pdo, 'pa_integration_webhook_delivery'), rowsMysql($pdo, 'pa_integration_webhook_attempt')];
    rejectedMysql(fn() => $purge($payloadCutoff, $payloadCutoff, 2), 'MAINTENANCE_CONTEXT_REQUIRED');
    $contexts->run(new InstanceExecutionContext('console.ordinary:task', 'wrong-instance'),
        fn() => rejectedMysql(fn() => $purge($payloadCutoff, $payloadCutoff, 2), 'MAINTENANCE_CONTEXT_REQUIRED'));
    $contexts->run(tenantContext('integration:purge-expired'),
        fn() => rejectedMysql(fn() => $purge($payloadCutoff, $payloadCutoff, 2), 'MAINTENANCE_CONTEXT_REQUIRED'));
    maintenanceContext($contexts, 'integration:purge-expired',
        fn() => rejectedMysql(fn() => Db::transaction(fn() => $purge($payloadCutoff, $payloadCutoff, 2)), 'MAINTENANCE_OUTER_TRANSACTION_FORBIDDEN'));
    checkMysql([rowsMysql($pdo, 'pa_integration_webhook_delivery'), rowsMysql($pdo, 'pa_integration_webhook_attempt')] === $before, 'rejected purge mutated rows');
    auditFailureTrigger($pdo);
    try {
        maintenanceContext($contexts, 'integration:purge-expired', fn() => $purge($payloadCutoff, $payloadCutoff, 2));
        throw new RuntimeException('integration audit failure did not roll back');
    } catch (Throwable $failure) {
        checkMysql(str_contains($failure->getMessage(), 'synthetic audit failure'), 'integration audit failure missing');
    }
    checkMysql([rowsMysql($pdo, 'pa_integration_webhook_delivery'), rowsMysql($pdo, 'pa_integration_webhook_attempt')] === $before, 'integration audit failure committed rows');
    checkMysql((int) $pdo->query("SELECT COUNT(*) FROM pa_platform_audit_event WHERE outcome='error'")->fetchColumn() === 1, 'integration failure audit missing');
    $pdo->exec('DROP TRIGGER reject_success_audit');
    foreach ([1, 1] as $batch) {
        $result = maintenanceContext($contexts, 'integration:purge-expired', fn() => $purge($payloadCutoff, $earlyCutoff, $batch));
        checkMysql($result === ['payloads_cleared' => 1, 'attempts_deleted' => 0, 'deliveries_deleted' => 0], 'independent payload batch');
        checkMysql(!$pdo->inTransaction(), 'payload transaction leaked');
    }
    $result = maintenanceContext($contexts, 'integration:purge-expired', fn() => $purge($payloadCutoff, $earlyCutoff, 2));
    checkMysql($result === ['payloads_cleared' => 0, 'attempts_deleted' => 0, 'deliveries_deleted' => 0], 'payload repeat');
    checkMysql(count(rowsMysql($pdo, 'pa_integration_webhook_attempt')) === 8, 'evidence cutoff ignored');
    $totals = ['payloads_cleared' => 0, 'attempts_deleted' => 0, 'deliveries_deleted' => 0];
    for ($batch = 0; $batch < 8; ++$batch) {
        $result = maintenanceContext($contexts, 'integration:purge-expired', fn() => $purge($earlyCutoff, $payloadCutoff, 2));
        foreach ($result as $key => $value) {
            checkMysql($value <= 2, 'integration batch exceeded limit: ' . $key);
            $totals[$key] += $value;
        }
        checkMysql(!$pdo->inTransaction(), 'integration transaction leaked');
        if ($batch === 0) {
            checkMysql((int) $pdo->query('SELECT COUNT(*) FROM pa_integration_webhook_delivery WHERE id=11')->fetchColumn() === 1, 'parent deleted with remaining children');
        }
        if (array_sum($result) === 0) {
            break;
        }
    }
    checkMysql($totals === ['payloads_cleared' => 0, 'attempts_deleted' => 7, 'deliveries_deleted' => 2], 'integration totals/resume');
    checkMysql(array_map('intval', array_column(rowsMysql($pdo, 'pa_integration_webhook_delivery'), 'id')) === [13,14,15], 'integration terminal/millisecond cutoff');
    checkMysql(array_map('intval', array_column(rowsMysql($pdo, 'pa_integration_webhook_attempt'), 'id')) === [8], 'integration child evidence');
    $auditRows = rowsMysql($pdo, 'pa_platform_audit_event');
    $auditJson = json_encode($auditRows, JSON_THROW_ON_ERROR);
    checkMysql(!str_contains($auditJson, 'private-body') && !str_contains($auditJson, 'millisecond-body'), 'integration audit leaked payload');
    checkMysql(str_contains($auditJson, 'payload_cutoff') && str_contains($auditJson, 'attempts_deleted'), 'integration audit lacks metadata/counts');
    checkMysql($contexts->isEmpty(), 'integration context leaked');
}

$database = RegisteredMysqlTestResource::configuredDatabaseName();
if (!in_array($database, ['peanut_admin_mt_notifications_20260922', 'peanut_admin_mt_integration_20260922'], true)) {
    throw new RuntimeException('retention test database is not one of the two registered targets');
}
new App(dirname(__DIR__, 2) . '/.local/tmp/retention-mysql-tests');
[$pdo, $created] = RegisteredMysqlTestResource::openEmptyDatabase($database);
try {
    ThinkPhpTestConnection::fromPdo($pdo);
    $contexts = new ExecutionContextStore();
    $current = new CurrentExecutionContext($contexts);
    $audit = new AuditContractHost($current);
    if ($database === 'peanut_admin_mt_notifications_20260922') {
        importExportMysql($pdo, $contexts, $current, $audit);
        $area = 'ImportExport';
    } else {
        integrationMysql($pdo, $contexts, $current, $audit);
        $area = 'Integration';
    }
    echo $area . ' MySQL retention PASS checks=' . $checks . PHP_EOL;
} finally {
    RegisteredMysqlTestResource::cleanup($pdo, $database, $created);
}
