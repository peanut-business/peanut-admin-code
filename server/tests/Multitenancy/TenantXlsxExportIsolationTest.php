<?php
declare(strict_types=1);

use app\common\services\XlsxExportService;
use app\common\execution\ExecutionContextStore;
use PeanutAdmin\Modules\File\Service\Storage\StorageService;
use PeanutAdmin\Kernel\Auth\TenantContext;
use PeanutAdmin\Kernel\Auth\ValidatedTenantSession;
use PeanutAdmin\Kernel\Persistence\Schema\KernelSchema;

require dirname(__DIR__, 2) . '/bootstrap/environment.php';
require dirname(__DIR__, 2) . '/vendor/autoload.php';
require_once dirname(__DIR__, 2) . '/database/install.php';
require __DIR__ . '/../Support/IsolatedBackendEnvironment.php';

function expectTenantXlsx(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function tenantXlsxContext(int $tenantId, int $memberId, string $requestId): TenantContext
{
    return TenantContext::fromValidatedSession(new ValidatedTenantSession(
        $memberId + 1000,
        '01JMT03XLSX' . str_pad((string)$memberId, 13, '0', STR_PAD_LEFT),
        $tenantId,
        $memberId + 1000,
        $memberId,
        'admin-web',
        new DateTimeImmutable('2031-01-01T00:00:00Z'),
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

$serverRoot = dirname(__DIR__, 2);
$host = IsolatedBackendEnvironment::required('DB_HOST');
$port = (int)IsolatedBackendEnvironment::required('DB_PORT');
$user = IsolatedBackendEnvironment::required('DB_USER');
$password = IsolatedBackendEnvironment::required('DB_PASS');
$runId = strtolower(bin2hex(random_bytes(6)));
$database = 'peanut_admin_mt03_xlsx_' . $runId;
$admin = new PDO(
    "mysql:host={$host};port={$port};charset=utf8mb4",
    $user,
    $password,
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::MYSQL_ATTR_MULTI_STATEMENTS => true],
);
$admin->exec("CREATE DATABASE `{$database}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
$pdo = new PDO(
    "mysql:host={$host};port={$port};dbname={$database};charset=utf8mb4",
    $user,
    $password,
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES => false, PDO::MYSQL_ATTR_MULTI_STATEMENTS => true],
);
foreach (KernelSchema::tableNames() as $table) {
    $pdo->exec(KernelSchema::createSql($table));
}
executeSqlFiles($pdo, [$serverRoot . '/database/init.sql']);
executeSqlFiles($pdo, applicationMigrationFiles($serverRoot . '/database'));
$pdo->exec("INSERT INTO pa_account (id,display_name,status,security_revision,created_at,updated_at) VALUES (10501,'Alpha','active',1,UTC_TIMESTAMP(3),UTC_TIMESTAMP(3)),(10502,'Beta','active',1,UTC_TIMESTAMP(3),UTC_TIMESTAMP(3))");
$pdo->exec("INSERT INTO pa_tenant (id,code,name,display_name,status,locale,timezone,security_revision,authorization_revision,revision,activated_at,created_at,updated_at) VALUES (101,'alpha','Alpha','Alpha','active','zh-CN','Asia/Shanghai',1,1,1,UTC_TIMESTAMP(3),UTC_TIMESTAMP(3),UTC_TIMESTAMP(3)),(202,'beta','Beta','Beta','active','zh-CN','Asia/Shanghai',1,1,1,UTC_TIMESTAMP(3),UTC_TIMESTAMP(3),UTC_TIMESTAMP(3))");
$pdo->exec("INSERT INTO pa_tenant_member (id,tenant_id,account_id,member_no,display_name,member_type,status,security_revision,authorization_revision,joined_at,created_at,updated_at) VALUES (501,101,10501,'alpha-501','Alpha','internal','active',1,1,UTC_TIMESTAMP(3),UTC_TIMESTAMP(3),UTC_TIMESTAMP(3)),(502,202,10502,'beta-502','Beta','internal','active',1,1,UTC_TIMESTAMP(3),UTC_TIMESTAMP(3),UTC_TIMESTAMP(3))");
IsolatedBackendEnvironment::activateDatabase($host, $port, $database, $user, $password, 'multi-tenant');
$app = new think\App();
$app->initialize();
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

try {
    $before = (int)$pdo->query('SELECT COUNT(*) FROM pa_file_object')->fetchColumn();
    try {
        $exports->create(
            'same-' . $runId,
            ['marker'],
            [['missing-context']]
        );
        throw new RuntimeException('Missing TenantContext unexpectedly created an export');
    } catch (Throwable) {
        expectTenantXlsx(
            (int)$pdo->query('SELECT COUNT(*) FROM pa_file_object')->fetchColumn() === $before,
            'Missing TenantContext produced a storage-ledger side effect'
        );
    }
    try {
        tenantXlsxRun($invalid, fn() => $exports->create(
            'same-' . $runId, ['marker'], [['invalid-context']]
        ));
        throw new RuntimeException('Untrusted TenantContext unexpectedly created an export');
    } catch (InvalidArgumentException) {
        expectTenantXlsx(!is_dir($invalidDirectory), 'Untrusted TenantContext created its target directory');
    }

    $alphaExport = tenantXlsxRun($alpha, fn() => $exports->create(
        'same-' . $runId, ['marker'], [['alpha-only-' . $runId]]
    ));
    $betaExport = tenantXlsxRun($beta, fn() => $exports->create(
        'same-' . $runId, ['marker'], [['beta-only-' . $runId]]
    ));
    $alphaUri = (string)$alphaExport['object_key'];
    $betaUri = (string)$betaExport['object_key'];
    expectTenantXlsx(
        str_starts_with($alphaUri, 'tenants/v1/101/export/xlsx/'),
        'Alpha export escaped its Tenant namespace'
    );
    expectTenantXlsx(
        str_starts_with($betaUri, 'tenants/v1/202/export/xlsx/'),
        'Beta export escaped its Tenant namespace'
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
        $storage->delete($alpha->tenantId, (string)$betaExport['file_key']);
        throw new RuntimeException('Alpha deleted a Beta export');
    } catch (InvalidArgumentException) {
        expectTenantXlsx(is_file($betaPath), 'Cross-Tenant cleanup touched the Beta export');
    }
    $storage->delete($alpha->tenantId, (string)$alphaExport['file_key']);
    expectTenantXlsx(!is_file($alphaPath), 'Alpha export survived its own cleanup');
    expectTenantXlsx(is_file($betaPath), 'Alpha cleanup deleted Beta export');

    foreach ([
        'app/modules/official/member/src/Service/MemberAdministrationService.php',
        'app/modules/official/payment/src/Service/RechargeAdministrationService.php',
        'app/adminapi/application/log/OperationLogApplicationService.php',
    ] as $relativePath) {
        $source = (string)file_get_contents($serverRoot . '/' . $relativePath);
        expectTenantXlsx(str_contains($source, '$this->xlsxExport->create('), 'Tenant caller did not adopt the injected export API: ' . $relativePath);
    }

    $tenantCreate = new ReflectionMethod(XlsxExportService::class, '__construct');
    expectTenantXlsx(
        $tenantCreate->getParameters()[0]->getType()?->getName() === \app\common\execution\CurrentExecutionContext::class,
        'Tenant export boundary does not consume CurrentExecutionContext'
    );

    echo "MT03-TENANT-XLSX-EXPORT-001 passed\n";
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
    $admin->exec("DROP DATABASE IF EXISTS `{$database}`");
}
