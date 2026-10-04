<?php

declare(strict_types=1);

use app\adminapi\http\middleware\OperationLogMiddleware;
use app\adminapi\application\log\OperationLogApplicationService;
use app\adminapi\service\OperationLogService;
use app\common\execution\ExecutionContextStore;
use app\common\execution\CurrentExecutionContext;
use app\platform\service\ops\PlatformDiagnosticBundleService;
use PeanutAdmin\Kernel\Audit\AuditOutcome;
use PeanutAdmin\Kernel\Auth\TenantContext;
use PeanutAdmin\Kernel\Auth\ValidatedTenantSession;
use PeanutAdmin\Modules\Ops\Domain\Logs\TenantDiagnosticAttributes;

require dirname(__DIR__, 2) . '/vendor/autoload.php';
require __DIR__ . '/../Support/IsolatedBackendEnvironment.php';

function expectOperationTenant(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function operationTenantContext(int $tenantId, int $memberId, string $requestId): TenantContext
{
    return TenantContext::fromValidatedSession(new ValidatedTenantSession(
        $memberId,
        '01JMT03AUDITLOG' . str_pad((string) $memberId, 11, '0', STR_PAD_LEFT),
        $tenantId,
        $memberId + 10000,
        $memberId,
        'admin-web',
        new DateTimeImmutable('2031-01-01T00:00:00Z'),
        1,
    ), $requestId);
}

function createOperationTenantSchema(PDO $pdo): void
{
    $pdo->exec(<<<'SQL'
CREATE TABLE pa_tenant (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  status VARCHAR(32) NOT NULL,
  PRIMARY KEY (id)
) ENGINE=InnoDB;
CREATE TABLE pa_operation_log (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  admin_id INT UNSIGNED NOT NULL DEFAULT 0,
  username VARCHAR(50) NOT NULL DEFAULT '',
  ip VARCHAR(50) NOT NULL DEFAULT '',
  uri VARCHAR(200) NOT NULL DEFAULT '',
  method VARCHAR(10) NOT NULL DEFAULT '',
  request_id VARCHAR(128) NOT NULL DEFAULT '',
  params TEXT,
  create_time INT UNSIGNED NOT NULL DEFAULT 0,
  tenant_id BIGINT UNSIGNED NOT NULL,
  PRIMARY KEY (id), UNIQUE KEY uk_operation_log_tenant_id (tenant_id, id),
  KEY idx_operation_log_tenant_created (tenant_id, create_time, id),
  CONSTRAINT fk_operation_log_tenant FOREIGN KEY (tenant_id) REFERENCES pa_tenant (id) ON DELETE RESTRICT
) ENGINE=InnoDB;
CREATE TABLE pa_tenant_audit_event (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  tenant_id BIGINT UNSIGNED NOT NULL,
  event_type VARCHAR(128) NOT NULL,
  action VARCHAR(255) NOT NULL,
  outcome VARCHAR(32) NOT NULL,
  reason_code VARCHAR(128) NULL,
  actor_tenant_id BIGINT UNSIGNED NULL,
  actor_tenant_member_id BIGINT UNSIGNED NULL,
  actor_account_id BIGINT UNSIGNED NULL,
  actor_platform_operator_id BIGINT UNSIGNED NULL,
  actor_type VARCHAR(64) NOT NULL,
  target_resource_type VARCHAR(128) NULL,
  target_resource_id VARCHAR(255) NULL,
  boundary_target_type VARCHAR(128) NULL,
  boundary_target_id VARCHAR(255) NULL,
  target_count INT UNSIGNED NOT NULL DEFAULT 0,
  target_set_digest VARCHAR(255) NULL,
  authorization_basis_json TEXT NULL,
  request_id VARCHAR(128) NOT NULL,
  operation_id VARCHAR(128) NULL,
  ip_address VARCHAR(50) NULL,
  user_agent_hash VARCHAR(255) NULL,
  before_json TEXT NULL,
  after_json TEXT NULL,
  metadata_json TEXT NULL,
  occurred_at DATETIME(3) NOT NULL,
  PRIMARY KEY (id)
) ENGINE=InnoDB;
SQL);
}

$serverRoot = dirname(__DIR__, 2);
$host = IsolatedBackendEnvironment::required('DB_HOST');
$port = (int) IsolatedBackendEnvironment::required('DB_PORT');
$user = IsolatedBackendEnvironment::required('DB_USER');
$password = IsolatedBackendEnvironment::required('DB_PASS');
$runId = strtolower(bin2hex(random_bytes(5)));
$database = IsolatedBackendEnvironment::required('DB_NAME');

$admin = new PDO(
    "mysql:host={$host};port={$port};charset=utf8mb4",
    $user,
    $password,
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::MYSQL_ATTR_MULTI_STATEMENTS => true],
);
$admin->exec("CREATE DATABASE `{$database}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");

$exportedPath = null;
try {
    $pdo = new PDO(
        "mysql:host={$host};port={$port};dbname={$database};charset=utf8mb4",
        $user,
        $password,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES => false, PDO::MYSQL_ATTR_MULTI_STATEMENTS => true],
    );
    createOperationTenantSchema($pdo);
    $pdo->exec("INSERT INTO pa_tenant (id, status) VALUES (101, 'active'), (202, 'active')");
    $pdo->exec("INSERT INTO pa_operation_log (tenant_id, username, uri, method) VALUES (101, 'alpha-seed', 'seed/read', 'GET')");
    IsolatedBackendEnvironment::activateDatabase($host, $port, $database, $user, $password, 'multi-tenant');
    $app = new think\App();
    $app->initialize();

    $alpha = operationTenantContext(101, 501, 'mt03-audit-alpha-' . $runId);
    $beta = operationTenantContext(202, 502, 'mt03-audit-beta-' . $runId);
    try {
        app(CurrentExecutionContext::class)->tenantAdmin();
        throw new RuntimeException('missing TenantContext unexpectedly succeeded');
    } catch (Throwable $exception) {
        expectOperationTenant($exception->getMessage() !== '', 'missing context denial lost its shape');
    }
    expectOperationTenant(
        TenantDiagnosticAttributes::fromTenantContext(null) === [
            'scope' => 'unavailable', 'tenant_id' => null, 'request_id' => '',
        ],
        'unavailable diagnostics were attributed to a default Tenant',
    );
    expectOperationTenant(
        TenantDiagnosticAttributes::fromTenantContext($alpha)['tenant_id'] === 101,
        'trusted diagnostics lost Tenant attribution',
    );

    $handlerCalled = false;
    $missingRequest = new class {
        public function method(): string
        {
            return 'POST';
        }
    };
    try {
        $app->make(OperationLogMiddleware::class)->handle($missingRequest, function () use (&$handlerCalled): void {
            $handlerCalled = true;
        });
        throw new RuntimeException('middleware accepted a write without TenantContext');
    } catch (Throwable $exception) {
        expectOperationTenant(!$handlerCalled, 'missing context reached the business handler');
        expectOperationTenant(
            (int) $pdo->query('SELECT COUNT(*) FROM pa_operation_log')->fetchColumn() === 1,
            'missing context produced an audit database side effect',
        );
    }

    app(ExecutionContextStore::class)->run(
        new \app\common\execution\AdminExecutionContext($alpha, 'test.operation-log.record.alpha'),
        fn() => app(OperationLogService::class)->record(
            $alpha,
            1,
            'alpha',
            '127.0.0.1',
            'same/write',
            'POST',
            [
                'tenant_id' => 202,
                'marker' => 'alpha-only-' . $runId,
            ],
            AuditOutcome::Success,
            null,
            200,
        ),
    );
    app(ExecutionContextStore::class)->run(
        new \app\common\execution\AdminExecutionContext($beta, 'test.operation-log.record.beta'),
        fn() => app(OperationLogService::class)->record(
            $beta,
            2,
            'beta',
            '127.0.0.2',
            'same/write',
            'POST',
            [
                'tenant_id' => 101,
                'marker' => 'beta-only-' . $runId,
            ],
            AuditOutcome::Denied,
            'HTTP_403',
            403,
        ),
    );
    expectOperationTenant(
        (int) $pdo->query("SELECT tenant_id FROM pa_operation_log WHERE username = 'alpha'")->fetchColumn() === 101,
        'payload forged Alpha audit ownership',
    );
    expectOperationTenant(
        (int) $pdo->query("SELECT tenant_id FROM pa_operation_log WHERE username = 'beta'")->fetchColumn() === 202,
        'payload forged Beta audit ownership',
    );
    $betaAudit = $pdo->query("SELECT tenant_id,request_id,outcome,reason_code,target_resource_id,metadata_json FROM pa_tenant_audit_event WHERE request_id='mt03-audit-beta-{$runId}'")->fetch(PDO::FETCH_ASSOC);
    expectOperationTenant(
        $betaAudit !== false
            && (int) $betaAudit['tenant_id'] === 202
            && $betaAudit['outcome'] === 'denied'
            && $betaAudit['reason_code'] === 'HTTP_403'
            && $betaAudit['target_resource_id'] === 'same/write',
        'Operation Log projections lost Tenant, request, outcome, reason, or route correlation',
    );
    expectOperationTenant(
        (int) $pdo->query("SELECT COUNT(*) FROM pa_operation_log WHERE request_id='mt03-audit-beta-{$runId}'")->fetchColumn() === 1
            && (int) $pdo->query("SELECT COUNT(*) FROM pa_tenant_audit_event WHERE request_id='mt03-audit-beta-{$runId}'")->fetchColumn() === 1,
        'one Operation Log fact was not projected exactly once to both stores',
    );

    $projectionFailure = operationTenantContext(101, 503, 'mt03-audit-rollback-' . $runId);
    $operationCountBeforeFailure = (int) $pdo->query('SELECT COUNT(*) FROM pa_operation_log')->fetchColumn();
    $pdo->exec("CREATE TRIGGER reject_tenant_audit_projection BEFORE INSERT ON pa_tenant_audit_event FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'injected projection failure'");
    try {
        app(ExecutionContextStore::class)->run(
            new \app\common\execution\AdminExecutionContext($projectionFailure, 'test.operation-log.rollback'),
            fn() => app(OperationLogService::class)->record(
                $projectionFailure,
                3,
                'rollback-user',
                '127.0.0.3',
                'rollback/write',
                'POST',
                ['password' => 'must-not-survive'],
            ),
        );
        throw new RuntimeException('injected audit projection failure unexpectedly committed');
    } catch (PDOException $exception) {
        expectOperationTenant(
            str_contains($exception->getMessage(), 'injected projection failure'),
            'atomic projection failure returned another database error',
        );
    } finally {
        $pdo->exec('DROP TRIGGER reject_tenant_audit_projection');
    }
    expectOperationTenant(
        (int) $pdo->query('SELECT COUNT(*) FROM pa_operation_log')->fetchColumn() === $operationCountBeforeFailure
            && (int) $pdo->query("SELECT COUNT(*) FROM pa_tenant_audit_event WHERE request_id='mt03-audit-rollback-{$runId}'")->fetchColumn() === 0,
        'projection failure left a half-persisted Operation Log fact',
    );

    $evidenceMethod = new ReflectionMethod(PlatformDiagnosticBundleService::class, 'operationLogEvidence');
    $evidence = $evidenceMethod->invoke(
        $app->make(PlatformDiagnosticBundleService::class),
        new DateTimeImmutable('2000-01-01T00:00:00Z'),
    );
    $evidenceByRequest = array_column($evidence, null, 'request_id');
    expectOperationTenant(
        isset($evidenceByRequest['mt03-audit-alpha-' . $runId], $evidenceByRequest['mt03-audit-beta-' . $runId])
            && $evidenceByRequest['mt03-audit-alpha-' . $runId]['tenant_id'] === 101
            && $evidenceByRequest['mt03-audit-beta-' . $runId]['tenant_id'] === 202,
        'diagnostic Operation Log evidence lost request correlation or Tenant isolation',
    );
    $encodedEvidence = json_encode($evidence, JSON_THROW_ON_ERROR);
    foreach (['alpha-only-' . $runId, 'beta-only-' . $runId, '127.0.0.1', '127.0.0.2', 'metadata_json'] as $prohibited) {
        expectOperationTenant(
            !str_contains($encodedEvidence, $prohibited),
            'diagnostic Operation Log evidence leaked payload, identity, IP, or metadata: ' . $prohibited,
        );
    }
    expectOperationTenant(
        $evidenceByRequest['mt03-audit-beta-' . $runId] === [
            'tenant_id' => 202,
            'request_id' => 'mt03-audit-beta-' . $runId,
            'operation_id' => null,
            'operation' => 'POST same/write',
            'outcome' => 'denied',
            'reason_code' => 'HTTP_403',
            'route' => 'same/write',
            'occurred_at' => $evidenceByRequest['mt03-audit-beta-' . $runId]['occurred_at'],
        ],
        'diagnostic Operation Log evidence changed its minimal redacted shape',
    );

    $alphaList = app(ExecutionContextStore::class)->run(
        new \app\common\execution\AdminExecutionContext($alpha, 'test.operation-log.list.alpha'),
        fn() => app(OperationLogApplicationService::class)->lists($alpha, ['tenant_id' => 202, 'uri' => 'same/write']),
    );
    $betaList = app(ExecutionContextStore::class)->run(
        new \app\common\execution\AdminExecutionContext($beta, 'test.operation-log.list.beta'),
        fn() => app(OperationLogApplicationService::class)->lists($beta, ['tenant_id' => 101, 'uri' => 'same/write']),
    );
    expectOperationTenant(
        $alphaList->total() === 1
            && $alphaList->getCollection()->toArray()[0]['username'] === 'alpha',
        'Alpha list leaked or lost audit rows',
    );
    expectOperationTenant(
        $betaList->total() === 1
            && $betaList->getCollection()->toArray()[0]['username'] === 'beta',
        'Beta list leaked or lost audit rows',
    );

    $betaId = (int) $pdo->query("SELECT id FROM pa_operation_log WHERE username = 'beta'")->fetchColumn();
    foreach ([$betaId, 999999] as $target) {
        try {
            app(ExecutionContextStore::class)->run(
                new \app\common\execution\AdminExecutionContext($alpha, 'test.operation-log.detail.denied'),
                fn() => app(OperationLogApplicationService::class)->detail($alpha, $target),
            );
            throw new RuntimeException('cross/missing audit detail unexpectedly succeeded');
        } catch (InvalidArgumentException $exception) {
            expectOperationTenant($exception->getMessage() === '操作日志不存在', 'audit detail denial enumerated Tenant ownership');
        }
    }

    $export = app(ExecutionContextStore::class)->run(
        new \app\common\execution\AdminExecutionContext($alpha, 'test.operation-log.export.alpha'),
        fn() => app(OperationLogApplicationService::class)->lists($alpha, [
            'tenant_id' => 202,
            'uri' => 'same/write',
            'export' => 2,
            'file_name' => 'tenant-audit-' . $runId,
        ]),
    );
    $exportUrl = (string) $export['url'];
    $storageOffset = strpos($exportUrl, 'storage/exports/');
    expectOperationTenant($storageOffset !== false, 'Alpha export URL lost its storage path');
    $exportUri = substr($exportUrl, $storageOffset);
    expectOperationTenant(
        str_starts_with($exportUri, 'storage/exports/tenants/v1/101/operation-logs/'),
        'Alpha export escaped its Tenant namespace',
    );
    $exportedPath = $serverRoot . '/public/' . $exportUri;
    expectOperationTenant(is_file($exportedPath), 'Alpha export file was not created');
    $zip = new ZipArchive();
    expectOperationTenant($zip->open($exportedPath) === true, 'Alpha export is not a readable XLSX');
    $sheet = (string) $zip->getFromName('xl/worksheets/sheet1.xml');
    $zip->close();
    expectOperationTenant(str_contains($sheet, 'alpha-only-' . $runId), 'Alpha export lost its own audit row');
    expectOperationTenant(!str_contains($sheet, 'beta-only-' . $runId), 'Alpha export leaked Beta audit content');

    $cleared = app(ExecutionContextStore::class)->run(
        new \app\common\execution\AdminExecutionContext($alpha, 'test.operation-log.clear.alpha'),
        fn() => app(OperationLogApplicationService::class)->clear($alpha, 1, 'alpha', '127.0.0.1'),
    );
    expectOperationTenant($cleared === 2, 'Alpha clear did not count only Alpha rows');
    expectOperationTenant(
        (int) $pdo->query('SELECT COUNT(*) FROM pa_operation_log WHERE tenant_id = 101')->fetchColumn() === 1,
        'Alpha clear did not preserve its tenant-scoped tombstone',
    );
    expectOperationTenant(
        (int) $pdo->query("SELECT COUNT(*) FROM pa_operation_log WHERE tenant_id = 202 AND username = 'beta'")->fetchColumn() === 1,
        'Alpha clear touched Beta audit rows',
    );

    $recordSignature = new ReflectionMethod(OperationLogService::class, 'record');
    expectOperationTenant(
        $recordSignature->getParameters()[0]->getType()?->getName() === TenantContext::class,
        'operation log write boundary does not require TenantContext',
    );

    echo "MT03-OPERATION-LOG-TENANT-001 passed\n";
} finally {
    if (is_string($exportedPath) && is_file($exportedPath)) {
        unlink($exportedPath);
    }
    $admin->exec("DROP DATABASE IF EXISTS `{$database}`");
}
