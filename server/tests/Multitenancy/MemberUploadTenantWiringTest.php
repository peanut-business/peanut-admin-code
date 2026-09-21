<?php
declare(strict_types=1);

use app\api\controller\UploadController;
use app\common\enum\FileEnum;
use app\common\execution\ConsumerExecutionContext;
use app\common\execution\ExecutionContextStore;
use PeanutAdmin\Kernel\Context\AuthenticatedMemberContext;
use PeanutAdmin\Kernel\Persistence\Schema\KernelSchema;

require dirname(__DIR__, 2) . '/vendor/autoload.php';
require __DIR__ . '/../Support/IsolatedBackendEnvironment.php';

function expectMemberUpload(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function memberUploadContext(int $tenantId, int $memberId, string $requestId): AuthenticatedMemberContext
{
    return new AuthenticatedMemberContext(
        $tenantId,
        $memberId,
        hash('sha256', 'member-upload-' . $tenantId . '-' . $memberId),
        $requestId,
    );
}

function memberUploadSchema(PDO $pdo, string $serverRoot): void
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
    $schema = (string)file_get_contents($serverRoot . '/database/init.sql');
    expectMemberUpload($schema !== '', 'canonical application schema is missing');
    $pdo->exec($schema);
    $storageMigration = (string)file_get_contents(
        $serverRoot . '/database/migrations/20260823-unify-storage-service.sql'
    );
    expectMemberUpload($storageMigration !== '', 'canonical storage migration is missing');
    $pdo->exec($storageMigration);
    $pdo->exec(<<<'SQL'
INSERT INTO pa_account
  (id, display_name, status, created_at, updated_at)
VALUES
  (1001, 'Alpha member', 'active', UTC_TIMESTAMP(3), UTC_TIMESTAMP(3));
INSERT INTO pa_tenant_member
  (id, tenant_id, account_id, member_no, display_name, status, joined_at, created_at, updated_at)
VALUES
  (501, 101, 1001, 'member-upload-501', 'Alpha member', 'active', UTC_TIMESTAMP(3), UTC_TIMESTAMP(3), UTC_TIMESTAMP(3));
SQL);
}

$serverRoot = dirname(__DIR__, 2);
$routeSource = (string)file_get_contents($serverRoot . '/app/modules/official/file/route/app.php');
$uploadRoute = "Route::post('upload/image', [ApiUploadController::class, 'image'])";
expectMemberUpload(substr_count($routeSource, $uploadRoute) === 1, 'member upload route is missing or duplicated');
expectMemberUpload(
    str_contains(
        $routeSource,
        $uploadRoute . "\n    ->middleware(CheckTokenMiddleware::class)\n    ->middleware(OfficialModuleMiddleware::class",
    ),
    'member upload route is not protected by identity and Module middleware'
);

$host = IsolatedBackendEnvironment::required('DB_HOST');
$port = (int)IsolatedBackendEnvironment::required('DB_PORT');
$database = IsolatedBackendEnvironment::required('DB_NAME');
$user = IsolatedBackendEnvironment::required('DB_USER');
$password = IsolatedBackendEnvironment::required('DB_PASS');
$runId = strtolower(bin2hex(random_bytes(5)));
expectMemberUpload(
    preg_match('/^peanut_admin_development_p0e_[a-z0-9]{1,11}_plugin_lifecycle$/D', $database) === 1,
    'member upload Gate requires its exact registered P0-E plugin_lifecycle database'
);
$storedObject = null;
$temporaryUpload = tempnam(sys_get_temp_dir(), 'peanut-member-upload-');

try {
    $pdo = new PDO(
        "mysql:host={$host};port={$port};dbname={$database};charset=utf8mb4",
        $user,
        $password,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
    memberUploadSchema($pdo, $serverRoot);

    IsolatedBackendEnvironment::activateDatabase($host, $port, $database, $user, $password, 'multi-tenant');

    $app = new think\App($serverRoot);
    $app->initialize();
    set_exception_handler(static function (Throwable $exception): never {
        fwrite(STDERR, sprintf(
            "MT03-MEMBER-UPLOAD-TENANT-WIRING-001 failed: %s: %s\n",
            $exception::class,
            $exception->getMessage(),
        ));
        exit(1);
    });
    $request = $app->request;
    $request->withPost([
        'cid' => 0,
        'tenant_id' => 202,
        'source_id' => 999,
        'source' => FileEnum::SOURCE_ADMIN,
        'member_id' => 999,
    ]);

    expectMemberUpload($temporaryUpload !== false, 'could not allocate upload fixture');
    file_put_contents(
        $temporaryUpload,
        base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=', true)
    );
    $request->withFiles([
        'file' => [
            'name' => 'member-avatar.png',
            'type' => 'image/png',
            'tmp_name' => $temporaryUpload,
            'error' => UPLOAD_ERR_OK,
            'size' => filesize($temporaryUpload),
        ],
    ]);
    $app->instance('request', $request);

    $member = memberUploadContext(101, 501, 'mt03-member-upload-' . $runId);
    $contexts = $app->make(ExecutionContextStore::class);
    $response = $contexts->run(
        ConsumerExecutionContext::member($member, 'member.file.upload'),
        static function () use ($app): object {
            $controller = $app->make(UploadController::class);
            $controller->initialize();
            return $controller->image();
        },
    );
    expectMemberUpload($contexts->isEmpty(), 'member upload leaked its execution context');
    $body = json_decode($response->getContent(), true, 512, JSON_THROW_ON_ERROR);
    expectMemberUpload(($body['code'] ?? null) === 20000, 'member upload failed: ' . ($body['msg'] ?? 'unknown error'));

    $row = $pdo->query(<<<'SQL'
SELECT f.tenant_id, f.source_id, f.source, f.type, f.name, f.file_key,
       o.object_key, o.status, o.created_by_member_id, a.driver, s.local_path
FROM pa_file f
JOIN pa_file_object o ON o.file_key = f.file_key
JOIN pa_storage_space s ON s.id = o.storage_space_id
JOIN pa_storage_account a ON a.id = s.account_id
LIMIT 1
SQL)->fetch(PDO::FETCH_ASSOC);
    expectMemberUpload(is_array($row), 'member upload did not create a file row');
    expectMemberUpload((int)$row['tenant_id'] === 101, 'payload forged uploaded file Tenant ownership');
    expectMemberUpload((int)$row['source_id'] === 501, 'payload forged uploaded file member owner');
    expectMemberUpload((int)$row['source'] === FileEnum::SOURCE_USER, 'member upload was stored as an admin upload');
    expectMemberUpload((int)$row['type'] === FileEnum::IMAGE, 'member upload file type changed');
    expectMemberUpload($row['name'] === 'member-avatar.png', 'member upload original name changed');
    expectMemberUpload(preg_match('/^file_[0-9a-f]{32}$/D', (string)$row['file_key']) === 1, 'member upload file identity changed');
    expectMemberUpload(str_starts_with((string)$row['object_key'], 'tenants/v1/101/material/image/'), 'member upload escaped its Tenant object namespace');
    expectMemberUpload(!str_contains((string)$row['object_key'], '/202/'), 'payload Tenant appeared in stored object namespace');
    expectMemberUpload($row['driver'] === 'local' && $row['local_path'] === 'public/storage', 'member upload did not use the registered local public storage route');
    expectMemberUpload($row['status'] === 'ready', 'member upload object was not made ready');
    expectMemberUpload((int)$row['created_by_member_id'] === 501, 'payload forged the storage object member owner');
    expectMemberUpload(($body['data']['file_key'] ?? null) === $row['file_key'], 'upload response did not return the canonical file identity');

    $storedObject = $serverRoot . '/public/storage/' . $row['object_key'];
    expectMemberUpload(is_file($storedObject), 'member upload object was not written to storage');
    expectMemberUpload((int)$pdo->query('SELECT COUNT(*) FROM pa_file')->fetchColumn() === 1, 'member upload created an unexpected number of rows');
    expectMemberUpload((int)$pdo->query('SELECT COUNT(*) FROM pa_file_object')->fetchColumn() === 1, 'member upload created an unexpected number of object rows');

    echo "MT03-MEMBER-UPLOAD-TENANT-WIRING-001 passed\n";
} finally {
    if (is_string($storedObject) && is_file($storedObject)) {
        unlink($storedObject);
    }
    if (is_string($temporaryUpload) && is_file($temporaryUpload)) {
        unlink($temporaryUpload);
    }
    if (isset($row['object_key'])) {
        $directory = dirname($serverRoot . '/public/storage/' . $row['object_key']);
        @rmdir($directory);
        @rmdir(dirname($directory));
        @rmdir(dirname($directory, 2));
        @rmdir(dirname($directory, 3));
        @rmdir(dirname($directory, 4));
        @rmdir(dirname($directory, 5));
    }
}
