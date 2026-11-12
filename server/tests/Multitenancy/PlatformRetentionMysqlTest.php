<?php

declare(strict_types=1);

require_once __DIR__ . '/../Support/CiBootstrap.php';
require_once ((getenv('PEANUT_TEST_APPLICATION_ROOT') ?: dirname(__DIR__, 3)) . '/server') . '/vendor/autoload.php';
require_once __DIR__ . '/../Support/IdentityMysqlFixture.php';
require_once __DIR__ . '/../Support/ThinkPhpTestConnection.php';
require_once __DIR__ . '/../Support/RegisteredMysqlTestResource.php';
require_once ((getenv('PEANUT_TEST_APPLICATION_ROOT') ?: dirname(__DIR__, 3)) . '/server') . '/vendor/topthink/framework/src/helper.php';

use app\common\execution\AdminExecutionContext;
use app\common\execution\CurrentExecutionContext;
use app\common\execution\ExecutionContextStore;
use app\common\execution\InstanceExecutionContext;
use app\common\services\audit\AuditContractHost;
use PeanutAdmin\Kernel\Auth\TenantContext;
use PeanutAdmin\Kernel\Auth\ValidatedTenantSession;
use PeanutAdmin\Modules\Integration\Infrastructure\Persistence\ThinkPhpIntegrationSecurityRepository;
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

function rejectSuccessAudit(PDO $pdo): void
{
    $pdo->exec("ALTER TABLE pa_platform_audit_event ADD CONSTRAINT chk_retention_success_audit CHECK (outcome <> 'success')");
}

function maintenanceContext(ExecutionContextStore $contexts, string $operation, callable $action): mixed
{
    return $contexts->run(new InstanceExecutionContext('console.' . $operation, 'retention-mysql-' . $operation), $action);
}

function tenantContext(string $operation): AdminExecutionContext
{
    $session = new ValidatedTenantSession(1, 'retention-mysql-tenant', 101, 301, 501, 'admin-web', new DateTimeImmutable('2035-01-01'), 1, 1);
    return new AdminExecutionContext(TenantContext::fromValidatedSession($session, 'retention-mysql-request'), 'console.' . $operation);
}

function integrationMysql(PDO $pdo, ExecutionContextStore $contexts, CurrentExecutionContext $current, AuditContractHost $audit): void
{
    $pdo->exec("INSERT INTO pa_integration_webhook_endpoint (id,tenant_id,endpoint_key,name,url,events_json,secret_ciphertext,secret_key_id,created_by_member_id,created_at,updated_at) VALUES
        (1,101,CONCAT('whk_',REPEAT('a',36)),'Alpha','https://example.test/a','[]','fixture','fixture',501,'2000-01-01','2000-01-01'),
        (2,202,CONCAT('whk_',REPEAT('b',36)),'Beta','https://example.test/b','[]','fixture','fixture',502,'2000-01-01','2000-01-01')");
    $insert = $pdo->prepare('INSERT INTO pa_integration_webhook_delivery (id,tenant_id,endpoint_id,delivery_key,event_key,event_type,payload_json,payload_sha256,status,available_at,delivered_at,payload_expires_at,created_at,updated_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)');
    foreach ([
        [11, 101, 'delivered', 'private-body-one', '2000-01-01 00:00:00.000'],
        [12, 202, 'permanent_failed', 'private-body-two', '2000-01-01 00:00:00.000'],
        [13, 101, 'pending', 'pending-body', '2000-01-01 00:00:00.000'],
        [14, 202, 'delivered', 'future-body', '2099-01-01 00:00:00.000'],
        [15, 101, 'delivered', 'millisecond-body', '2031-01-02 00:00:00.501'],
    ] as [$id, $tenant, $status, $body, $cutoff]) {
        $payload = json_encode(['body' => $body], JSON_THROW_ON_ERROR);
        $insert->execute([$id, $tenant, $tenant === 101 ? 1 : 2, sprintf('delivery_%032x', $id),
            'retention-' . $id, 'fixture.retention', $payload, hash('sha256', $payload), $status,
            $cutoff, $status === 'delivered' ? $cutoff : null, $cutoff, $cutoff, $cutoff]);
    }
    $attempt = $pdo->prepare("INSERT INTO pa_integration_webhook_attempt (id,tenant_id,delivery_id,attempt_number,outcome,duration_ms,attempted_at) VALUES (?,?,?,?,'delivered',0,'2000-01-01')");
    foreach ([[1,101,11],[2,101,11],[3,101,11],[4,101,11],[5,101,11],[6,202,12],[7,202,12],[8,101,13]] as $row) {
        $attempt->execute([...$row, $row[0] <= 5 ? $row[0] : ($row[0] <= 7 ? $row[0] - 5 : 1)]);
    }
    $repository = new ThinkPhpIntegrationSecurityRepository($current, $audit);
    $payloadCutoff = new DateTimeImmutable('2031-01-02T00:00:00.500Z');
    $earlyCutoff = new DateTimeImmutable('1999-01-01T00:00:00Z');
    $purge = fn(DateTimeImmutable $payload, DateTimeImmutable $evidence, int $limit) => $repository->purgeExpiredDeliveryData($payload, $evidence, $limit);
    $before = [rowsMysql($pdo, 'pa_integration_webhook_delivery'), rowsMysql($pdo, 'pa_integration_webhook_attempt')];
    rejectedMysql(fn() => $purge($payloadCutoff, $payloadCutoff, 2), 'PLATFORM_MAINTENANCE_CONTEXT_REQUIRED');
    $contexts->run(new InstanceExecutionContext('console.ordinary:task', 'wrong-instance'),
        fn() => rejectedMysql(fn() => $purge($payloadCutoff, $payloadCutoff, 2), 'PLATFORM_MAINTENANCE_CONTEXT_REQUIRED'));
    $contexts->run(tenantContext('integration:purge-expired'),
        fn() => rejectedMysql(fn() => $purge($payloadCutoff, $payloadCutoff, 2), 'PLATFORM_MAINTENANCE_CONTEXT_REQUIRED'));
    maintenanceContext($contexts, 'integration:purge-expired',
        fn() => rejectedMysql(fn() => Db::transaction(fn() => $purge($payloadCutoff, $payloadCutoff, 2)), 'MAINTENANCE_OUTER_TRANSACTION_FORBIDDEN'));
    checkMysql([rowsMysql($pdo, 'pa_integration_webhook_delivery'), rowsMysql($pdo, 'pa_integration_webhook_attempt')] === $before, 'rejected purge mutated rows');
    rejectSuccessAudit($pdo);
    try {
        maintenanceContext($contexts, 'integration:purge-expired', fn() => $purge($payloadCutoff, $payloadCutoff, 2));
        throw new RuntimeException('integration audit failure did not roll back');
    } catch (Throwable $failure) {
        checkMysql(str_contains($failure->getMessage(), 'chk_retention_success_audit'), 'integration audit failure missing');
    }
    checkMysql([rowsMysql($pdo, 'pa_integration_webhook_delivery'), rowsMysql($pdo, 'pa_integration_webhook_attempt')] === $before, 'integration audit failure committed rows');
    checkMysql((int) $pdo->query("SELECT COUNT(*) FROM pa_platform_audit_event WHERE outcome='error'")->fetchColumn() === 1, 'integration failure audit missing');
    $pdo->exec('ALTER TABLE pa_platform_audit_event DROP CHECK chk_retention_success_audit');
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
[$pdo, $created] = RegisteredMysqlTestResource::openEmptyDatabase($database);
try {
    IdentityMysqlFixture::install($pdo, [((getenv('PEANUT_TEST_APPLICATION_ROOT') ?: dirname(__DIR__, 3)) . '/server') . '/app/modules/peanut/integration/database/migrations']);
    $pdo->exec("INSERT INTO pa_tenant (id,code,name,display_name,status,created_at,updated_at) VALUES
        (101,'alpha','Alpha','Alpha','active','2000-01-01','2000-01-01'),(202,'beta','Beta','Beta','active','2000-01-01','2000-01-01')");
    $pdo->exec("INSERT INTO pa_account (id,display_name,status,created_at,updated_at) VALUES
        (301,'Alpha','active','2000-01-01','2000-01-01'),(302,'Beta','active','2000-01-01','2000-01-01')");
    $pdo->exec("INSERT INTO pa_tenant_member (id,tenant_id,account_id,status,created_at,updated_at) VALUES
        (501,101,301,'active','2000-01-01','2000-01-01'),(502,202,302,'active','2000-01-01','2000-01-01')");
    ThinkPhpTestConnection::fromPdo($pdo);
    $contexts = new ExecutionContextStore();
    $current = new CurrentExecutionContext($contexts);
    $audit = new AuditContractHost($current);
    integrationMysql($pdo, $contexts, $current, $audit);
    echo 'Integration MySQL retention PASS checks=' . $checks . PHP_EOL;
} finally {
    RegisteredMysqlTestResource::cleanup($pdo, $database, $created);
}
