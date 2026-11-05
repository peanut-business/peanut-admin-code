<?php

declare(strict_types=1);

use app\adminapi\http\middleware\OperationLogMiddleware;
use app\adminapi\services\log\OperationLogApplicationService;
use app\adminapi\services\OperationLogService;
use app\common\execution\ExecutionContextStore;
use app\common\execution\CurrentExecutionContext;
use PeanutAdmin\Modules\Identity\Contract\TenantAuditDiagnosticQuery;
use PeanutAdmin\Kernel\Audit\AuditOutcome;
use PeanutAdmin\Kernel\Auth\TenantContext;
use PeanutAdmin\Modules\Ops\Domain\Logs\TenantDiagnosticAttributes;

require dirname(__DIR__, 2) . '/vendor/autoload.php';
require_once __DIR__ . '/../Support/CiBootstrap.php';
require_once __DIR__ . '/../Support/RegisteredMysqlTestResource.php';
require_once __DIR__ . '/../Support/ThinkPhpTestConnection.php';
require_once __DIR__ . '/../Support/IdentityMysqlFixture.php';

function expectOperationTenant(bool $condition, string $message): void
{
    $GLOBALS['operationTenantChecks'] = ($GLOBALS['operationTenantChecks'] ?? 0) + 1;
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function operationTenantContext(int $tenantId, int $memberId, string $requestId): TenantContext
{
    return app(\PeanutAdmin\Modules\Identity\Contract\TenantSessionAccess::class)->revalidateSession(
        $tenantId, $memberId + 10000, $memberId, str_pad((string) $memberId, 26, '0', STR_PAD_LEFT), 'admin-web', $requestId,
    );
}

function createOperationTenantSchema(PDO $pdo): void
{
    $serverRoot = dirname(__DIR__, 2);
    IdentityMysqlFixture::install($pdo, [
        $serverRoot . '/app/modules/peanut/file/database/migrations',
        $serverRoot . '/app/modules/peanut/settings/database/migrations',
        $serverRoot . '/app/modules/peanut/reference_codes/database/migrations',
        $serverRoot . '/database/installation',
    ]);
    require_once $serverRoot . '/app/modules/peanut/file/database/seeds/PeanutFileDefaults.php';
    $seed = new PeanutFileDefaults();
    $seed->setAdapter(new \Phinx\Db\Adapter\MysqlAdapter(['name' => $pdo->query('SELECT DATABASE()')->fetchColumn(), 'connection' => $pdo]));
    $seed->run();
}

$serverRoot = dirname(__DIR__, 2);
$runId = strtolower(bin2hex(random_bytes(5)));
$database = RegisteredMysqlTestResource::configuredDatabaseName();
[$pdo, $created] = RegisteredMysqlTestResource::openEmptyDatabase($database);

$exportedPath = null;
try {
    createOperationTenantSchema($pdo);
    $pdo->exec("INSERT INTO pa_tenant(id,code,name,display_name,status,activated_at,created_at,updated_at) VALUES(101,'alpha','Alpha','Alpha','active',UTC_TIMESTAMP(3),UTC_TIMESTAMP(3),UTC_TIMESTAMP(3)),(202,'beta','Beta','Beta','active',UTC_TIMESTAMP(3),UTC_TIMESTAMP(3),UTC_TIMESTAMP(3))");
    $pdo->exec("INSERT INTO pa_account(id,display_name,created_at,updated_at) VALUES(10501,'Alpha',UTC_TIMESTAMP(3),UTC_TIMESTAMP(3)),(10502,'Beta',UTC_TIMESTAMP(3),UTC_TIMESTAMP(3)),(10503,'Projection failure',UTC_TIMESTAMP(3),UTC_TIMESTAMP(3))");
    $pdo->exec("INSERT INTO pa_tenant_member(id,tenant_id,account_id,display_name,status,joined_at,created_at,updated_at) VALUES(501,101,10501,'Alpha','active',UTC_TIMESTAMP(3),UTC_TIMESTAMP(3),UTC_TIMESTAMP(3)),(502,202,10502,'Beta','active',UTC_TIMESTAMP(3),UTC_TIMESTAMP(3),UTC_TIMESTAMP(3)),(503,101,10503,'Projection failure','active',UTC_TIMESTAMP(3),UTC_TIMESTAMP(3),UTC_TIMESTAMP(3))");
    $pdo->exec("INSERT INTO pa_operation_log (tenant_id, username, uri, method) VALUES (101, 'alpha-seed', 'seed/read', 'GET')");
    $app = think\Container::getInstance();
    $app->env->set(['DEPLOYMENT_MODE' => 'multi-tenant', 'APP_ENV' => 'development', 'APP_DEBUG' => true, 'JWT_SECRET' => str_repeat('f', 32)]);
    $app->initialize();
    $app->cache->clear();
    ThinkPhpTestConnection::fromPdo($pdo);
    $pdo->exec("INSERT INTO pa_credential(account_id,kind,identifier_type,identifier_normalized,secret_hash,status,verified_at,secret_changed_at,created_at,updated_at) SELECT id,'email_password','email',CONCAT('fixture-',id,'@example.test'),'fixture','active',UTC_TIMESTAMP(3),UTC_TIMESTAMP(3),UTC_TIMESTAMP(3),UTC_TIMESTAMP(3) FROM pa_account");
    $registry = $app->make(\PeanutAdmin\Kernel\Module\CompiledModuleRegistry::class);
    foreach (['peanut.identity', 'peanut.file', 'peanut.content-tools'] as $moduleKey) {
        $manifest = $registry->requireManifest($moduleKey);
        $pdo->prepare('INSERT INTO pa_module_installation(module_key,installed_version,manifest_schema_version,manifest_digest,status,installed_at,activated_at,created_at,updated_at) VALUES(?,?,?,?,"active",UTC_TIMESTAMP(3),UTC_TIMESTAMP(3),UTC_TIMESTAMP(3),UTC_TIMESTAMP(3))')
            ->execute([$moduleKey, $manifest->data['version'], $manifest->data['schema_version'], $manifest->digest]);
    }
    $app->make(\app\platform\infrastructure\module\ModuleCatalogApplier::class)->apply($registry, ['peanut.identity', 'peanut.file', 'peanut.content-tools']);
    $pdo->exec("INSERT INTO pa_plan(id,code,name,status,created_at,updated_at) VALUES(1,'fixture-file','File fixture','active',UTC_TIMESTAMP(3),UTC_TIMESTAMP(3))");
    $pdo->exec("INSERT INTO pa_plan_module(plan_id,module_key) VALUES(1,'peanut.file')");
    $pdo->exec("INSERT INTO pa_plan_permission(plan_id,permission_key) SELECT 1,`key` FROM pa_permission WHERE module_key='peanut.file'");
    $pdo->exec("INSERT INTO pa_tenant_license(tenant_id,plan_id,status,effective_at,created_at,updated_at) SELECT id,1,'active','2020-01-01 00:00:00.000',UTC_TIMESTAMP(3),UTC_TIMESTAMP(3) FROM pa_tenant");
    $pdo->exec("INSERT INTO pa_role(id,tenant_id,`key`,name,is_builtin,status,created_at,updated_at) VALUES(601,101,'core.tenant-owner','Alpha owner',1,'active',UTC_TIMESTAMP(3),UTC_TIMESTAMP(3)),(602,202,'core.tenant-owner','Beta owner',1,'active',UTC_TIMESTAMP(3),UTC_TIMESTAMP(3))");
    $pdo->exec("INSERT INTO pa_member_role(tenant_id,tenant_member_id,role_id,assigned_at) VALUES(101,501,601,UTC_TIMESTAMP(3)),(202,502,602,UTC_TIMESTAMP(3)),(101,503,601,UTC_TIMESTAMP(3))");
    $pdo->exec("INSERT INTO pa_tenant_session(session_key,tenant_id,account_id,tenant_member_id,client_key,status,account_security_revision,tenant_security_revision,member_security_revision,issued_at,last_seen_at,idle_expires_at,absolute_expires_at,created_at,updated_at) SELECT LPAD(m.id,26,'0'),m.tenant_id,m.account_id,m.id,'admin-web','active',a.security_revision,t.security_revision,m.security_revision,UTC_TIMESTAMP(3),UTC_TIMESTAMP(3),DATE_ADD(UTC_TIMESTAMP(3),INTERVAL 1 HOUR),DATE_ADD(UTC_TIMESTAMP(3),INTERVAL 2 HOUR),UTC_TIMESTAMP(3),UTC_TIMESTAMP(3) FROM pa_tenant_member m JOIN pa_account a ON a.id=m.account_id JOIN pa_tenant t ON t.id=m.tenant_id");

    $pdo->exec("INSERT INTO pa_role_permission(tenant_id,role_id,permission_id,granted_at) SELECT r.tenant_id,r.id,p.id,UTC_TIMESTAMP(3) FROM pa_role r JOIN pa_permission p ON p.module_key='peanut.file' WHERE r.`key`='core.tenant-owner'");
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
    $pdo->exec("ALTER TABLE pa_tenant_audit_event ADD CONSTRAINT chk_reject_tenant_audit_projection CHECK (request_id <> 'mt03-audit-rollback-{$runId}')");
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
    } catch (PDOException|\think\db\exception\PDOException $exception) {
        expectOperationTenant(
            str_contains($exception->getMessage(), 'chk_reject_tenant_audit_projection'),
            'atomic projection failure returned another database error',
        );
    } finally {
        $pdo->exec('ALTER TABLE pa_tenant_audit_event DROP CHECK chk_reject_tenant_audit_projection');
    }
    expectOperationTenant(
        (int) $pdo->query('SELECT COUNT(*) FROM pa_operation_log')->fetchColumn() === $operationCountBeforeFailure
            && (int) $pdo->query("SELECT COUNT(*) FROM pa_tenant_audit_event WHERE request_id='mt03-audit-rollback-{$runId}'")->fetchColumn() === 0,
        'projection failure left a half-persisted Operation Log fact',
    );

    $evidence = $app->make(TenantAuditDiagnosticQuery::class)->recentOperationEvidence(
        new DateTimeImmutable('2000-01-01T00:00:00Z'),
        100,
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
        $alphaList->total === 1
            && $alphaList->items[0]['username'] === 'alpha',
        'Alpha list leaked or lost audit rows',
    );
    expectOperationTenant(
        $betaList->total === 1
            && $betaList->items[0]['username'] === 'beta',
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
    parse_str((string) parse_url($exportUrl, PHP_URL_QUERY), $delivery);
    expectOperationTenant(isset($delivery['file_key'], $delivery['token']), 'Alpha export URL lost its authorized file reference');
    $objectQuery = $pdo->prepare('SELECT tenant_id,object_key,access_type FROM pa_file_object WHERE file_key=?');
    $objectQuery->execute([$delivery['file_key']]);
    $object = $objectQuery->fetch(PDO::FETCH_ASSOC);
    expectOperationTenant(is_array($object) && (int) $object['tenant_id'] === 101
        && $object['access_type'] === 'private' && str_starts_with($object['object_key'], 'tenants/v1/101/export/xlsx/'),
        'Alpha export escaped its private Tenant namespace');
    $exportedPath = $serverRoot . '/private/storage/' . $object['object_key'];
    $opened = app(\PeanutAdmin\Modules\File\Service\Storage\StorageService::class)->authorizedDownload(101, $delivery['file_key'], $delivery['token']);
    expectOperationTenant($opened['path'] === $exportedPath, 'Alpha export delivery did not use the scoped file ledger');
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

    echo 'MT03-OPERATION-LOG-TENANT-001 passed checks=' . $GLOBALS['operationTenantChecks'] . PHP_EOL;
} finally {
    if (is_string($exportedPath) && is_file($exportedPath)) {
        unlink($exportedPath);
    }
    RegisteredMysqlTestResource::cleanup($pdo, $database, $created);
}
