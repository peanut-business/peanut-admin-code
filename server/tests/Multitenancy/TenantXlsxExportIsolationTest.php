<?php

declare(strict_types=1);

use app\common\services\XlsxExportService;
use app\common\execution\ExecutionContextStore;
use PeanutAdmin\Modules\File\Service\Storage\StorageService;
use PeanutAdmin\Kernel\Auth\TenantContext;
use PeanutAdmin\Kernel\Auth\ValidatedTenantSession;

require ((getenv('PEANUT_TEST_APPLICATION_ROOT') ?: dirname(__DIR__, 3)) . '/server') . '/vendor/autoload.php';
require_once __DIR__ . '/../Support/CiBootstrap.php';
require_once __DIR__ . '/../Support/RegisteredMysqlTestResource.php';
require_once __DIR__ . '/../Support/ThinkPhpTestConnection.php';
require_once __DIR__ . '/../Support/IdentityMysqlFixture.php';

function expectTenantXlsx(bool $condition, string $message): void
{
    $GLOBALS['tenantXlsxChecks'] = ($GLOBALS['tenantXlsxChecks'] ?? 0) + 1;
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function tenantXlsxContext(int $tenantId, int $memberId, string $requestId): TenantContext
{
    if ($memberId > 0) {
        return app(\PeanutAdmin\Modules\Identity\Contract\TenantSessionAccess::class)->revalidateSession(
            $tenantId, $memberId + 10000, $memberId, str_pad((string) $memberId, 26, '0', STR_PAD_LEFT), 'admin-web', $requestId,
        );
    }
    return TenantContext::fromValidatedSession(new ValidatedTenantSession(
        $memberId + 10000,
        '01JMT03XLSX' . str_pad((string) $memberId, 13, '0', STR_PAD_LEFT),
        $tenantId,
        $memberId + 10000,
        $memberId,
        'admin-web',
        new DateTimeImmutable('2031-01-01T00:00:00Z'),
        1,
        1,
    ), $requestId);
}

function tenantXlsxSheet(string $path): string
{
    $zip = new ZipArchive();
    expectTenantXlsx($zip->open($path) === true, 'Tenant export is not a readable XLSX');
    $sheet = $zip->getFromName('xl/worksheets/sheet1.xml');
    $zip->close();
    expectTenantXlsx(is_string($sheet), 'Tenant export worksheet is missing');
    return $sheet;
}

function tenantXlsxRun(TenantContext $context, callable $operation): mixed
{
    return app(ExecutionContextStore::class)->run(
        new \app\common\execution\AdminExecutionContext($context, 'test.xlsx.export'),
        $operation,
    );
}

$serverRoot = ((getenv('PEANUT_TEST_APPLICATION_ROOT') ?: dirname(__DIR__, 3)) . '/server');
$runId = strtolower(bin2hex(random_bytes(6)));
$database = RegisteredMysqlTestResource::configuredDatabaseName();
[$pdo, $databaseCreated] = RegisteredMysqlTestResource::openEmptyDatabase($database);
$alphaPath = '';
$betaPath = '';
try {
IdentityMysqlFixture::install($pdo, [
    $serverRoot . '/app/modules/peanut/file/database/migrations',
    $serverRoot . '/app/modules/peanut/settings/database/migrations',
    $serverRoot . '/app/modules/peanut/reference_codes/database/migrations',
    $serverRoot . '/database/installation',
]);
require_once $serverRoot . '/app/modules/peanut/file/database/seeds/PeanutFileDefaults.php';
$seed = new PeanutFileDefaults();
$seed->setAdapter(new \Phinx\Db\Adapter\MysqlAdapter(['name' => $database, 'connection' => $pdo]));
$seed->run();
$pdo->exec("INSERT INTO pa_account (id,display_name,status,security_revision,created_at,updated_at) VALUES (10501,'Alpha','active',1,UTC_TIMESTAMP(3),UTC_TIMESTAMP(3)),(10502,'Beta','active',1,UTC_TIMESTAMP(3),UTC_TIMESTAMP(3))");
$pdo->exec("INSERT INTO pa_tenant (id,code,name,display_name,status,locale,timezone,security_revision,authorization_revision,revision,activated_at,created_at,updated_at) VALUES (101,'alpha','Alpha','Alpha','active','zh-CN','Asia/Shanghai',1,1,1,UTC_TIMESTAMP(3),UTC_TIMESTAMP(3),UTC_TIMESTAMP(3)),(202,'beta','Beta','Beta','active','zh-CN','Asia/Shanghai',1,1,1,UTC_TIMESTAMP(3),UTC_TIMESTAMP(3),UTC_TIMESTAMP(3))");
$pdo->exec("INSERT INTO pa_tenant_member (id,tenant_id,account_id,member_no,display_name,member_type,status,security_revision,authorization_revision,joined_at,created_at,updated_at) VALUES (501,101,10501,'alpha-501','Alpha','internal','active',1,1,UTC_TIMESTAMP(3),UTC_TIMESTAMP(3),UTC_TIMESTAMP(3)),(502,202,10502,'beta-502','Beta','internal','active',1,1,UTC_TIMESTAMP(3),UTC_TIMESTAMP(3),UTC_TIMESTAMP(3))");
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
$pdo->exec("INSERT INTO pa_member_role(tenant_id,tenant_member_id,role_id,assigned_at) VALUES(101,501,601,UTC_TIMESTAMP(3)),(202,502,602,UTC_TIMESTAMP(3))");
$pdo->exec("INSERT INTO pa_tenant_session(session_key,tenant_id,account_id,tenant_member_id,client_key,status,account_security_revision,tenant_security_revision,member_security_revision,issued_at,last_seen_at,idle_expires_at,absolute_expires_at,created_at,updated_at) SELECT LPAD(m.id,26,'0'),m.tenant_id,m.account_id,m.id,'admin-web','active',a.security_revision,t.security_revision,m.security_revision,UTC_TIMESTAMP(3),UTC_TIMESTAMP(3),DATE_ADD(UTC_TIMESTAMP(3),INTERVAL 1 HOUR),DATE_ADD(UTC_TIMESTAMP(3),INTERVAL 2 HOUR),UTC_TIMESTAMP(3),UTC_TIMESTAMP(3) FROM pa_tenant_member m JOIN pa_account a ON a.id=m.account_id JOIN pa_tenant t ON t.id=m.tenant_id");
$pdo->exec("INSERT INTO pa_role_permission(tenant_id,role_id,permission_id,granted_at) SELECT r.tenant_id,r.id,p.id,UTC_TIMESTAMP(3) FROM pa_role r JOIN pa_permission p ON p.module_key='peanut.file' WHERE r.`key`='core.tenant-owner'");
$exports = app(XlsxExportService::class);
$storage = app(StorageService::class);
$alpha = tenantXlsxContext(101, 501, 'mt03-xlsx-alpha-' . $runId);
$beta = tenantXlsxContext(202, 502, 'mt03-xlsx-beta-' . $runId);
$invalid = tenantXlsxContext(303, 0, 'mt03-xlsx-invalid-' . $runId);
$invalidDirectory = $serverRoot . '/private/storage/tenants/v1/303/export/xlsx';
$alphaUri = '';
$betaUri = '';
$alphaPath = '';
$betaPath = '';

    $before = (int) $pdo->query('SELECT COUNT(*) FROM pa_file_object')->fetchColumn();
    try {
        $exports->create(
            'same-' . $runId,
            ['marker'],
            [['missing-context']],
        );
        throw new RuntimeException('Missing TenantContext unexpectedly created an export');
    } catch (Throwable) {
        expectTenantXlsx(
            (int) $pdo->query('SELECT COUNT(*) FROM pa_file_object')->fetchColumn() === $before,
            'Missing TenantContext produced a storage-ledger side effect',
        );
    }
    try {
        tenantXlsxRun($invalid, fn() => $exports->create(
            'same-' . $runId,
            ['marker'],
            [['invalid-context']],
        ));
        throw new RuntimeException('Untrusted TenantContext unexpectedly created an export');
    } catch (InvalidArgumentException) {
        expectTenantXlsx(!is_dir($invalidDirectory), 'Untrusted TenantContext created its target directory');
    }

    $alphaExport = tenantXlsxRun($alpha, fn() => $exports->create(
        'same-' . $runId,
        ['marker'],
        [['alpha-only-' . $runId]],
    ));
    $betaExport = tenantXlsxRun($beta, fn() => $exports->create(
        'same-' . $runId,
        ['marker'],
        [['beta-only-' . $runId]],
    ));
    $alphaUri = (string) $alphaExport['object_key'];
    $betaUri = (string) $betaExport['object_key'];
    expectTenantXlsx(
        str_starts_with($alphaUri, 'tenants/v1/101/export/xlsx/'),
        'Alpha export escaped its Tenant namespace',
    );
    expectTenantXlsx(
        str_starts_with($betaUri, 'tenants/v1/202/export/xlsx/'),
        'Beta export escaped its Tenant namespace',
    );
    expectTenantXlsx(dirname($alphaUri) !== dirname($betaUri), 'Tenant exports share a physical directory');

    $alphaPath = $serverRoot . '/private/storage/' . $alphaUri;
    $betaPath = $serverRoot . '/private/storage/' . $betaUri;
    $alphaSheet = tenantXlsxSheet($alphaPath);
    $betaSheet = tenantXlsxSheet($betaPath);
    expectTenantXlsx(str_contains($alphaSheet, 'alpha-only-' . $runId), 'Alpha export lost its content');
    expectTenantXlsx(!str_contains($alphaSheet, 'beta-only-' . $runId), 'Alpha export leaked Beta content');
    expectTenantXlsx(str_contains($betaSheet, 'beta-only-' . $runId), 'Beta export lost its content');
    expectTenantXlsx(!str_contains($betaSheet, 'alpha-only-' . $runId), 'Beta export leaked Alpha content');

    try {
        tenantXlsxRun($alpha, fn() => $storage->delete($alpha->tenantId, (string) $betaExport['file_key']));
        throw new RuntimeException('Alpha deleted a Beta export');
    } catch (RuntimeException $error) {
        expectTenantXlsx($error->getMessage() === '文件对象不存在', 'Cross-Tenant cleanup denial changed');
        expectTenantXlsx(is_file($betaPath), 'Cross-Tenant cleanup touched the Beta export');
    }
    tenantXlsxRun($alpha, fn() => $storage->delete($alpha->tenantId, (string) $alphaExport['file_key']));
    expectTenantXlsx(!is_file($alphaPath), 'Alpha export survived its own cleanup');
    expectTenantXlsx(is_file($betaPath), 'Alpha cleanup deleted Beta export');

    foreach ([
        'app/adminapi/services/log/OperationLogApplicationService.php',
    ] as $relativePath) {
        $source = (string) file_get_contents($serverRoot . '/' . $relativePath);
        expectTenantXlsx(str_contains($source, '$this->xlsxExport->create('), 'Tenant caller did not adopt the injected export API: ' . $relativePath);
    }

    $tenantCreate = new ReflectionMethod(XlsxExportService::class, '__construct');
    expectTenantXlsx(
        $tenantCreate->getParameters()[0]->getType()?->getName() === \app\common\execution\CurrentExecutionContext::class,
        'Tenant export boundary does not consume CurrentExecutionContext',
    );

    echo 'MT03-TENANT-XLSX-EXPORT-001 passed checks=' . $GLOBALS['tenantXlsxChecks'] . PHP_EOL;
} finally {
    foreach ([$alphaPath, $betaPath] as $path) {
        if ($path !== '' && is_file($path)) {
            unlink($path);
        }
    }
    foreach ([dirname($alphaPath), dirname($betaPath)] as $directory) {
        if ($directory !== '.' && is_dir($directory)) {
            @rmdir($directory);
            @rmdir(dirname($directory));
            @rmdir(dirname($directory, 2));
        }
    }
    RegisteredMysqlTestResource::cleanup($pdo, $database, $databaseCreated);
}
