<?php
declare(strict_types=1);

use app\Modules\Official\File\Contracts\FileAdministration;
use app\Modules\Official\File\Contracts\FileUploads;
use app\Modules\Official\File\Contracts\Dto\UploadFile;
use app\common\execution\CurrentExecutionContext;
use app\common\execution\ExecutionContextStore;
use app\modules\official\file\value\storage\StoragePath;
use app\Modules\Official\File\Model\File;
use app\Modules\Official\File\Model\FileCate;
use PeanutAdmin\Kernel\Auth\TenantContext;
use PeanutAdmin\Kernel\Auth\ValidatedTenantSession;
use PeanutAdmin\Kernel\Persistence\Schema\KernelSchema;

require dirname(__DIR__, 2) . '/vendor/autoload.php';
require_once dirname(__DIR__, 2) . '/database/install.php';
require __DIR__ . '/../Support/IsolatedBackendEnvironment.php';

function expectFileTenant(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function fileTenantContext(int $tenantId, int $memberId, string $requestId): TenantContext
{
    return TenantContext::fromValidatedSession(new ValidatedTenantSession(
        $memberId,
        '01JMT03FILEOBJ' . str_pad((string)$memberId, 13, '0', STR_PAD_LEFT),
        $tenantId,
        $memberId + 10000,
        $memberId,
        'admin-web',
        new DateTimeImmutable('2031-01-01T00:00:00Z'),
        1,
    ), $requestId);
}

function createFileTenantSchema(PDO $pdo, string $serverRoot): void
{
    foreach (KernelSchema::tableNames() as $table) {
        $pdo->exec(KernelSchema::createSql($table));
    }
    executeSqlFiles($pdo, [$serverRoot . '/database/init.sql']);
    executeSqlFiles($pdo, applicationMigrationFiles($serverRoot . '/database'));
}

$serverRoot = dirname(__DIR__, 2);
$host = IsolatedBackendEnvironment::required('DB_HOST');
$port = (int)IsolatedBackendEnvironment::required('DB_PORT');
$user = IsolatedBackendEnvironment::required('DB_USER');
$password = IsolatedBackendEnvironment::required('DB_PASS');
$runId = 'a1-file';
$database = IsolatedBackendEnvironment::required('DB_NAME');
expectFileTenant($database === 'peanut_admin_a1_file_test', 'registered File test database is required');
$admin = new PDO(
    "mysql:host={$host};port={$port};charset=utf8mb4",
    $user,
    $password,
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::MYSQL_ATTR_MULTI_STATEMENTS => true]
);
expectFileTenant(
    $admin->query("SELECT SCHEMA_NAME FROM information_schema.SCHEMATA WHERE SCHEMA_NAME = " . $admin->quote($database))->fetchColumn() === false,
    'registered File test database must not pre-exist',
);
$admin->exec("CREATE DATABASE `{$database}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");

$alphaObject = '';
$betaObject = '';
$alphaSource = tempnam(sys_get_temp_dir(), 'pa-file-alpha-');
$betaSource = tempnam(sys_get_temp_dir(), 'pa-file-beta-');
if (!is_string($alphaSource) || !is_string($betaSource)) {
    throw new RuntimeException('file upload fixtures could not be created');
}
file_put_contents($alphaSource, 'alpha');
file_put_contents($betaSource, 'beta');

try {
    $pdo = new PDO(
        "mysql:host={$host};port={$port};dbname={$database};charset=utf8mb4",
        $user,
        $password,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES => false, PDO::MYSQL_ATTR_MULTI_STATEMENTS => true]
    );
    createFileTenantSchema($pdo, $serverRoot);
    $pdo->exec("INSERT INTO pa_account (id,display_name,status,security_revision,created_at,updated_at) VALUES (10501,'Alpha','active',1,UTC_TIMESTAMP(3),UTC_TIMESTAMP(3)),(10502,'Beta','active',1,UTC_TIMESTAMP(3),UTC_TIMESTAMP(3))");
    $pdo->exec("INSERT INTO pa_tenant (id,code,name,display_name,status,locale,timezone,security_revision,authorization_revision,revision,activated_at,created_at,updated_at) VALUES (101,'alpha','Alpha','Alpha','active','zh-CN','Asia/Shanghai',1,1,1,UTC_TIMESTAMP(3),UTC_TIMESTAMP(3),UTC_TIMESTAMP(3)),(202,'beta','Beta','Beta','active','zh-CN','Asia/Shanghai',1,1,1,UTC_TIMESTAMP(3),UTC_TIMESTAMP(3),UTC_TIMESTAMP(3))");
    $pdo->exec("INSERT INTO pa_tenant_member (id,tenant_id,account_id,member_no,display_name,member_type,status,security_revision,authorization_revision,joined_at,created_at,updated_at) VALUES (501,101,10501,'alpha-501','Alpha','internal','active',1,1,UTC_TIMESTAMP(3),UTC_TIMESTAMP(3),UTC_TIMESTAMP(3)),(502,202,10502,'beta-502','Beta','internal','active',1,1,UTC_TIMESTAMP(3),UTC_TIMESTAMP(3),UTC_TIMESTAMP(3))");
    $pdo->exec("INSERT INTO pa_file_cate (id, tenant_id, pid, type, name) VALUES (11, 101, 0, 10, 'Alpha seed')");
    IsolatedBackendEnvironment::activateDatabase($host, $port, $database, $user, $password, 'multi-tenant');
    $app = new think\App();
    $app->initialize();
    $files = app(FileAdministration::class);
    $uploads = app(FileUploads::class);

    $alpha = fileTenantContext(101, 501, 'mt03-file-alpha-' . $runId);
    $beta = fileTenantContext(202, 502, 'mt03-file-beta-' . $runId);
    try {
        app(CurrentExecutionContext::class)->tenantAdmin();
        throw new RuntimeException('missing TenantContext unexpectedly succeeded');
    } catch (Throwable $exception) {
        expectFileTenant($exception->getMessage() !== '', 'missing context denial lost its shape');
    }

    expectFileTenant(
        StoragePath::objectKey($alpha->tenantId, 'image.upload', 'file_' . str_repeat('a', 32), 'png')
            !== StoragePath::objectKey($beta->tenantId, 'image.upload', 'file_' . str_repeat('a', 32), 'png'),
        'two Tenants share the same object namespace'
    );
    expectFileTenant(
        app(ExecutionContextStore::class)->run(
            new \app\common\execution\AdminExecutionContext($alpha, 'test.file.category.add.alpha'),
            fn() => $files->addCategory(['tenant_id' => 202, 'pid' => 0, 'type' => 10, 'name' => 'Same category']),
        ),
        'Alpha category was not created',
    );
    expectFileTenant(
        app(ExecutionContextStore::class)->run(
            new \app\common\execution\AdminExecutionContext($beta, 'test.file.category.add.beta'),
            fn() => $files->addCategory(['tenant_id' => 101, 'pid' => 0, 'type' => 10, 'name' => 'Same category']),
        ),
        'Beta category was not created',
    );
    $alphaCategory = (int)app(ExecutionContextStore::class)->run(
        new \app\common\execution\AdminExecutionContext($alpha, 'test.file.category.query.alpha'),
        fn() => FileCate::where([])->where('name', 'Same category')->value('id'),
    );
    $betaCategory = (int)app(ExecutionContextStore::class)->run(
        new \app\common\execution\AdminExecutionContext($beta, 'test.file.category.query.beta'),
        fn() => FileCate::where([])->where('name', 'Same category')->value('id'),
    );
    expectFileTenant($alphaCategory > 0 && $betaCategory > 0, 'same-name Tenant categories were not created');
    expectFileTenant(
        (int)$pdo->query("SELECT tenant_id FROM pa_file_cate WHERE id = {$alphaCategory}")->fetchColumn() === 101,
        'request payload forged category Tenant ownership'
    );

    $alphaUpload = app(ExecutionContextStore::class)->run(
        new \app\common\execution\AdminExecutionContext($alpha, 'test.file.create.alpha'),
        fn() => $uploads->image(
            $alpha,
            new UploadFile($alphaSource, 'same.png', 5, 'image/png', 'png'),
            $alphaCategory,
            501,
        ),
    );
    $betaUpload = app(ExecutionContextStore::class)->run(
        new \app\common\execution\AdminExecutionContext($beta, 'test.file.create.beta'),
        fn() => $uploads->image(
            $beta,
            new UploadFile($betaSource, 'same.png', 4, 'image/png', 'png'),
            $betaCategory,
            502,
        ),
    );
    $alphaFile = (int)$alphaUpload['id'];
    $betaFile = (int)$betaUpload['id'];
    $alphaObjectKey = (string)$pdo->query("SELECT object_key FROM pa_file_object WHERE file_key=" . $pdo->quote((string)$alphaUpload['file_key']))->fetchColumn();
    $betaObjectKey = (string)$pdo->query("SELECT object_key FROM pa_file_object WHERE file_key=" . $pdo->quote((string)$betaUpload['file_key']))->fetchColumn();
    $alphaObject = $serverRoot . '/public/storage/' . $alphaObjectKey;
    $betaObject = $serverRoot . '/public/storage/' . $betaObjectKey;
    expectFileTenant($alphaFile > 0 && $betaFile > 0, 'same-name Tenant files were not created');
    expectFileTenant(is_file($alphaObject) && is_file($betaObject), 'canonical storage driver did not persist both Tenant objects');

    expectFileTenant(
        count(app(ExecutionContextStore::class)->run(
            new \app\common\execution\AdminExecutionContext($alpha, 'test.file.list.alpha'),
            fn() => $files->lists(['type' => 10, 'name' => 'same.png']),
        )->items) === 1,
        'Alpha file list leaked or lost same-name files',
    );
    expectFileTenant(
        count(app(ExecutionContextStore::class)->run(
            new \app\common\execution\AdminExecutionContext($beta, 'test.file.list.beta'),
            fn() => $files->lists(['type' => 10, 'name' => 'same.png']),
        )->items) === 1,
        'Beta file list leaked or lost same-name files',
    );
    expectFileTenant(
        count(app(ExecutionContextStore::class)->run(
            new \app\common\execution\AdminExecutionContext($alpha, 'test.file.category.list.alpha'),
            fn() => $files->categoryLists(10),
        )) === 2,
        'Alpha category tree leaked or lost categories',
    );
    expectFileTenant(
        count(app(ExecutionContextStore::class)->run(
            new \app\common\execution\AdminExecutionContext($beta, 'test.file.category.list.beta'),
            fn() => $files->categoryLists(10),
        )) === 1,
        'Beta category tree leaked or lost categories',
    );

    foreach ([$betaCategory, 999999] as $target) {
        try {
            app(ExecutionContextStore::class)->run(
                new \app\common\execution\AdminExecutionContext($alpha, 'test.file.category.delete.denied'),
                fn() => $files->deleteCategory($target),
            );
            throw new RuntimeException('cross/missing category delete unexpectedly succeeded');
        } catch (InvalidArgumentException $exception) {
            expectFileTenant($exception->getMessage() === '分类不存在', 'category denial enumerated Tenant ownership');
        }
    }
    foreach ([$betaFile, 999999] as $target) {
        try {
            app(ExecutionContextStore::class)->run(
                new \app\common\execution\AdminExecutionContext($alpha, 'test.file.delete.denied'),
                fn() => $files->delete([$target]),
            );
            throw new RuntimeException('cross/missing file delete unexpectedly succeeded');
        } catch (InvalidArgumentException $exception) {
            expectFileTenant($exception->getMessage() === '包含不存在的素材', 'file denial enumerated Tenant ownership');
        }
    }
    try {
        StoragePath::objectKey(101, 'image.upload', 'forged', 'png');
        throw new RuntimeException('invalid file identity unexpectedly produced an object key');
    } catch (InvalidArgumentException $exception) {
        expectFileTenant($exception->getMessage() === '文件身份无效', 'object identity denial changed');
    }

    $result = app(ExecutionContextStore::class)->run(
        new \app\common\execution\AdminExecutionContext($alpha, 'test.file.category.delete.alpha'),
        fn() => $files->deleteCategory($alphaCategory),
    );
    expectFileTenant($result === ['categories_deleted' => 1, 'files_deleted' => 1, 'storage_deleted' => 1], 'Alpha category cleanup result changed');
    expectFileTenant(!file_exists($alphaObject), 'Alpha object survived Tenant cleanup');
    expectFileTenant(file_exists($betaObject) && file_get_contents($betaObject) === 'beta', 'Alpha cleanup touched Beta object');
    expectFileTenant(
        app(ExecutionContextStore::class)->run(
            new \app\common\execution\AdminExecutionContext($beta, 'test.file.category.query.beta'),
            fn() => FileCate::where([])->where('id', $betaCategory)->find() !== null,
        ),
        'Alpha cleanup deleted Beta category',
    );
    expectFileTenant(
        app(ExecutionContextStore::class)->run(
            new \app\common\execution\AdminExecutionContext($beta, 'test.file.query.beta'),
            fn() => File::where([])->where('id', $betaFile)->find() !== null,
        ),
        'Alpha cleanup deleted Beta file row',
    );
    expectFileTenant((int)$pdo->query("SELECT COUNT(*) FROM pa_file WHERE tenant_id = 202 AND delete_time IS NULL")->fetchColumn() === 1, 'Beta active file count changed');

    echo "MT03-FILE-TENANT-OWNERSHIP-001 passed\n";
} finally {
    if ($alphaObject !== '' && file_exists($alphaObject)) {
        unlink($alphaObject);
    }
    if ($betaObject !== '' && file_exists($betaObject)) {
        unlink($betaObject);
    }
    foreach ([$alphaObject, $betaObject] as $path) {
        if ($path !== '') {
            @rmdir(dirname($path));
            @rmdir(dirname($path, 2));
            @rmdir(dirname($path, 3));
        }
    }
    foreach ([$alphaSource, $betaSource] as $source) {
        if (is_file($source)) {
            unlink($source);
        }
    }
    $admin->exec("DROP DATABASE IF EXISTS `{$database}`");
}
