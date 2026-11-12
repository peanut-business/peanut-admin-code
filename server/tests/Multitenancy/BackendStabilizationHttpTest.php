<?php

declare(strict_types=1);

/** Core Admin HTTP routing, authentication and tenant write boundaries. */
require_once __DIR__ . '/../Support/CiBootstrap.php';
require_once __DIR__ . '/../Support/IsolatedBackendEnvironment.php';
require_once __DIR__ . '/../Support/IdentityMysqlFixture.php';
require_once __DIR__ . '/../Support/RegisteredMysqlTestResource.php';

use PeanutAdmin\Modules\Identity\Auth\TenantAuthService;
use PeanutAdmin\Modules\Identity\Authorization\ApiPermissionCatalog;
use PeanutAdmin\Modules\Identity\Authorization\CorePermissionCatalogSynchronizer;
use app\common\services\authorization\GeneratedApiCatalog;

ini_set('zend.exception_ignore_args', '1');
$serverRoot = ((getenv('PEANUT_TEST_APPLICATION_ROOT') ?: dirname(__DIR__, 3)) . '/server');
if (($argv[1] ?? '') === '--request') {
    $input = json_decode(stream_get_contents(STDIN), true, 512, JSON_THROW_ON_ERROR);
    // MultiApp 读取全局入口文件名；CLI 测试文件不能被误认作应用名。
    $_SERVER['SCRIPT_FILENAME'] = $serverRoot . '/public/index.php';
    $app = new think\App($serverRoot);
    $app->setRuntimePath($input['runtime']);
    $headers = ['host' => $input['host'], 'authorization' => $input['token'] === '' ? '' : 'Bearer ' . $input['token'], 'accept' => 'application/json', 'x-request-id' => $input['request_id']];
    if ($input['user_agent'] !== null) {
        $headers['user-agent'] = $input['user_agent'];
    }
    $request = new app\Request();
    $request->setMethod($input['method'])->setPathinfo($input['path'])
        ->setUrl('/' . $input['path'])->setBaseUrl('/' . $input['path'])
        ->withServer(['REMOTE_ADDR' => '127.0.0.1', 'REQUEST_METHOD' => $input['method'], 'HTTP_HOST' => $input['host'], 'SCRIPT_NAME' => '/index.php'])
        ->withHeader($headers)
        ->withGet($input['method'] === 'GET' ? $input['params'] : [])
        ->withPost($input['method'] === 'GET' ? [] : $input['params']);
    $response = $app->http->run($request);
    echo json_encode(['status' => $response->getCode(), 'body' => json_decode($response->getContent(), true)], JSON_THROW_ON_ERROR);
    $app->http->end($response);
    exit;
}

$assertions = 0;
$requests = 0;
$failures = [];
function httpExpect(bool $condition, string $message): void
{
    global $assertions, $failures;
    $assertions++;
    if (!$condition) {
        $failures[] = $message;
        echo 'FAIL ' . $message . "\n";
    }
}
function stabilizationHttp(
    string $method,
    string $path,
    array $params = [],
    string $token = '',
    string $host = 'alpha.d03.test',
    ?string $userAgent = 'Peanut Backend Stabilization HTTP Fixture',
): array {
    global $requests, $runtime;
    $requests++;
    $process = proc_open([PHP_BINARY, __FILE__, '--request'], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    if (!is_resource($process)) {
        throw new RuntimeException('HTTP worker could not start');
    }
    fwrite($pipes[0], json_encode(['method' => $method, 'path' => $path, 'params' => $params, 'token' => $token, 'host' => $host, 'user_agent' => $userAgent, 'request_id' => 'stabilization-http-' . $requests, 'runtime' => $runtime], JSON_THROW_ON_ERROR));
    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $code = proc_close($process);
    $result = json_decode($stdout, true);
    if ($code !== 0 || !is_array($result)) {
        throw new RuntimeException('HTTP worker failed for ' . $path . ': ' . substr($stderr, 0, 1200));
    }
    return $result;
}
function httpSuccess(array $response, string $label): void
{
    httpExpect($response['status'] === 200 && ($response['body']['code'] ?? null) === 20000, $label . ': ' . json_encode($response, JSON_UNESCAPED_UNICODE));
}

$database = RegisteredMysqlTestResource::configuredDatabaseName();
[$pdo, $createdDatabase] = RegisteredMysqlTestResource::openEmptyDatabase($database);
$runtime = sys_get_temp_dir() . '/peanut-stabilization-http-' . bin2hex(random_bytes(8)) . '/';
try {
    if (!mkdir($runtime, 0700)) {
        throw new RuntimeException('Cannot prepare isolated HTTP runtime');
    }
    IdentityMysqlFixture::install($pdo, [$serverRoot . '/app/modules/peanut/ops/database/migrations']);
    IsolatedBackendEnvironment::activate([
        'DB_NAME' => $database,
        'DEPLOYMENT_MODE' => 'multi-tenant',
        'TENANT_IDENTIFIER_HMAC_KEY' => bin2hex(random_bytes(32)),
        'JWT_SECRET' => bin2hex(random_bytes(32)),
    ]);
    $app = new think\App($serverRoot);
    $app->setRuntimePath($runtime);
    $app->initialize();
    $app->make(CorePermissionCatalogSynchronizer::class)->synchronize();
    (new ApiPermissionCatalog())->synchronize(GeneratedApiCatalog::load($serverRoot), [], false);
    $pdo->exec("INSERT INTO pa_tenant (id,code,name,display_name,status,activated_at,created_at,updated_at) VALUES (101,'alpha','Alpha','Alpha','active',UTC_TIMESTAMP(3),UTC_TIMESTAMP(3),UTC_TIMESTAMP(3)),(202,'beta','Beta','Beta','active',UTC_TIMESTAMP(3),UTC_TIMESTAMP(3),UTC_TIMESTAMP(3))");
    $pdo->exec("INSERT INTO pa_tenant_entry_binding (tenant_id,host,client_key) VALUES (101,'alpha.d03.test','admin-web'),(202,'beta.d03.test','admin-web')");
    $hash = app(PeanutAdmin\Kernel\Identity\PasswordHasher::class)->hash('D03HttpPassword2026');
    foreach ([[1501,501,101,'alpha'], [1502,502,202,'beta']] as [$account, $member, $tenant, $code]) {
        $pdo->exec("INSERT INTO pa_account (id,display_name,status,created_at,updated_at) VALUES ({$account},'HTTP Operator','active',UTC_TIMESTAMP(3),UTC_TIMESTAMP(3))");
        $insert = $pdo->prepare("INSERT INTO pa_credential (account_id,kind,identifier_type,identifier_normalized,secret_hash,status,verified_at,secret_changed_at,created_at,updated_at) VALUES (?,'email_password','email',?,?,'active',UTC_TIMESTAMP(3),UTC_TIMESTAMP(3),UTC_TIMESTAMP(3),UTC_TIMESTAMP(3))");
        $insert->execute([$account, $code . '-http@example.test', $hash]);
        $pdo->exec("INSERT INTO pa_tenant_member (id,tenant_id,account_id,member_no,display_name,status,joined_at,created_at,updated_at) VALUES ({$member},{$tenant},{$account},'http-{$code}','HTTP Operator','active',UTC_TIMESTAMP(3),UTC_TIMESTAMP(3),UTC_TIMESTAMP(3))");
        $pdo->exec("INSERT INTO pa_role (id,tenant_id,`key`,name,is_builtin,status,created_at,updated_at) VALUES ({$member},{$tenant},'http.operator','HTTP Operator',0,'active',UTC_TIMESTAMP(3),UTC_TIMESTAMP(3))");
        $pdo->exec("INSERT INTO pa_member_role (tenant_id,tenant_member_id,role_id,assigned_at) VALUES ({$tenant},{$member},{$member},UTC_TIMESTAMP(3))");
    }
    $permissions = [
        'core.role.read', 'core.role.create', 'core.role.update', 'core.role.archive',
        'core.department.read', 'core.department.create', 'core.department.update', 'core.department.archive',
        'core.member.create', 'core.member.update', 'core.member.role.assign', 'core.member.suspend', 'core.member.activate', 'core.member.leave',
    ];
    $lookup = $pdo->prepare("SELECT id FROM pa_permission WHERE `key`=? AND status='active'");
    $grant = $pdo->prepare('INSERT INTO pa_role_permission (tenant_id,role_id,permission_id,granted_at) VALUES (101,501,?,UTC_TIMESTAMP(3)),(202,502,?,UTC_TIMESTAMP(3))');
    foreach ($permissions as $key) {
        $lookup->execute([$key]);
        $permission = (int) $lookup->fetchColumn();
        if ($permission < 1) {
            throw new RuntimeException('Core permission missing: ' . $key);
        }
        $grant->execute([$permission, $permission]);
    }
    $auth = app(TenantAuthService::class);
    $alpha = $auth->login('alpha-http@example.test', 'D03HttpPassword2026', 'alpha', '127.0.0.1', 'D03 HTTP', 'd03-http-login-a')->tokens->access->expose();
    $beta = $auth->login('beta-http@example.test', 'D03HttpPassword2026', 'beta', '127.0.0.1', 'D03 HTTP', 'd03-http-login-b')->tokens->access->expose();
    httpExpect(stabilizationHttp('GET', 'adminapi/role/lists')['status'] === 401, 'D03 missing token did not fail in login middleware');
    httpExpect(stabilizationHttp('POST', 'adminapi/role/add', ['name' => 'forged'], 'pa_tat_forged')['status'] === 401, 'D03 forged session did not fail');
    httpExpect((int) $pdo->query("SELECT COUNT(*) FROM pa_role WHERE name='forged'")->fetchColumn() === 0, 'D03 forged request wrote a role');
    httpSuccess(stabilizationHttp('GET', 'adminapi/role/lists', [], $alpha), 'D03 original role query');
    $forgedRole = stabilizationHttp('POST', 'adminapi/role/add', ['name' => 'HTTP Forged Tenant', 'tenant_id' => 202], $alpha);
    httpExpect($forgedRole['status'] === 422 && ($forgedRole['body']['data']['error_code'] ?? null) === 'ROLE_INPUT_INVALID', 'D03 role create accepted a caller-supplied tenant');
    httpExpect((int) $pdo->query("SELECT COUNT(*) FROM pa_role WHERE name='HTTP Forged Tenant'")->fetchColumn() === 0, 'D03 forged tenant wrote a role');
    httpSuccess(stabilizationHttp('POST', 'adminapi/role/add', ['name' => 'HTTP Alpha'], $alpha), 'D03 role create');
    httpSuccess(stabilizationHttp('POST', 'adminapi/role/add', ['name' => 'HTTP Beta'], $beta, 'beta.d03.test'), 'D03 same permissions beta create');
    $roleA = (int) $pdo->query("SELECT id FROM pa_role WHERE tenant_id=101 AND name='HTTP Alpha'")->fetchColumn();
    $roleB = (int) $pdo->query("SELECT id FROM pa_role WHERE tenant_id=202 AND name='HTTP Beta'")->fetchColumn();
    httpExpect($roleA > 0 && $roleB > 0, 'D03 trusted session tenant not used for role writes');
    if ($roleA > 0 && $roleB > 0) {
        httpSuccess(stabilizationHttp('POST', 'adminapi/role/edit', ['id' => $roleA, 'name' => 'HTTP Alpha Edited'], $alpha), 'D03 role edit');
        httpExpect(stabilizationHttp('POST', 'adminapi/role/edit', ['id' => $roleB,'name' => 'Cross tenant'], $alpha)['status'] === 404, 'D03 same-permission cross-tenant role write accepted');
        httpExpect($pdo->query("SELECT name FROM pa_role WHERE id={$roleB}")->fetchColumn() === 'HTTP Beta', 'D03 cross-tenant role mutated');
        httpSuccess(stabilizationHttp('POST', 'adminapi/role/delete', ['id' => $roleA], $alpha), 'D03 role archive');
    }
    httpSuccess(stabilizationHttp('POST', 'adminapi/dept/add', ['name' => 'HTTP Dept','pid' => 0,'status' => 1], $alpha), 'D03 department create');
    $dept = (int) $pdo->query("SELECT id FROM pa_department WHERE tenant_id=101 AND name='HTTP Dept'")->fetchColumn();
    if ($dept > 0) {
        httpSuccess(stabilizationHttp('POST', 'adminapi/dept/edit', ['id' => $dept,'name' => 'HTTP Dept Edited','pid' => 0,'status' => 1], $alpha), 'D03 department edit');
        httpSuccess(stabilizationHttp('POST', 'adminapi/dept/status', ['id' => $dept,'status' => 0], $alpha), 'D03 department status');
        httpSuccess(stabilizationHttp('POST', 'adminapi/dept/delete', ['id' => $dept], $alpha), 'D03 department archive');
    }
    $memberParams = ['account' => 'http-created@example.test','name' => 'HTTP Created','password' => 'D03MemberPassword2026','password_confirm' => 'D03MemberPassword2026','role_id' => [501],'disable' => 0,'multipoint_login' => 1];
    httpSuccess(stabilizationHttp('POST', 'adminapi/admin/add', $memberParams, $alpha), 'D03 member create');
    $member = (int) $pdo->query("SELECT id FROM pa_tenant_member WHERE tenant_id=101 AND display_name='HTTP Created'")->fetchColumn();
    if ($member > 0) {
        unset($memberParams['password'],$memberParams['password_confirm']);
        httpSuccess(stabilizationHttp('POST', 'adminapi/admin/edit', $memberParams + ['id' => $member], $alpha), 'D03 member edit');
        httpSuccess(stabilizationHttp('POST', 'adminapi/admin/status', ['id' => $member,'disable' => 1], $alpha), 'D03 member suspend');
        httpSuccess(stabilizationHttp('POST', 'adminapi/admin/status', ['id' => $member,'disable' => 0], $alpha), 'D03 member activate');
        httpSuccess(stabilizationHttp('POST', 'adminapi/admin/delete', ['id' => $member], $alpha), 'D03 member leave');
    }
    httpSuccess(stabilizationHttp('GET', 'adminapi/admin/self', [], $alpha), 'D03 self query');
    httpSuccess(stabilizationHttp('POST', 'adminapi/admin/editSelf', ['nickname' => 'HTTP Self No UA','password_old' => 'D03HttpPassword2026','password' => 'D03HttpChangedNoUa2026','password_confirm' => 'D03HttpChangedNoUa2026'], $alpha, 'alpha.d03.test', null), 'D03 self profile and password without User-Agent');
    httpExpect(stabilizationHttp('GET', 'adminapi/role/lists', [], $alpha)['status'] === 401, 'D03 HTTP password change without User-Agent did not invalidate session');
    $alphaWithUserAgent = $auth->login('alpha-http@example.test', 'D03HttpChangedNoUa2026', 'alpha', '127.0.0.1', 'D03 HTTP', 'd03-http-login-a-with-ua')->tokens->access->expose();
    httpSuccess(stabilizationHttp('POST', 'adminapi/admin/editSelf', ['nickname' => 'HTTP Self','password_old' => 'D03HttpChangedNoUa2026','password' => 'D03HttpChanged2026','password_confirm' => 'D03HttpChanged2026'], $alphaWithUserAgent), 'D03 self profile and password with User-Agent');
    httpExpect(stabilizationHttp('GET', 'adminapi/role/lists', [], $alphaWithUserAgent)['status'] === 401, 'D03 HTTP password change with User-Agent did not invalidate session');
    httpExpect((int) $pdo->query("SELECT COUNT(*) FROM pa_tenant_audit_event WHERE actor_tenant_member_id=501 AND actor_account_id=1501 AND event_type='tenant.role.created'")->fetchColumn() > 0, 'D03 HTTP write lost trusted audit identity');
    // 取消 beta 的单项权限，原会话必须经授权修订重新判定，不可绕过权限中间件。
    $pdo->exec("DELETE rp FROM pa_role_permission rp JOIN pa_permission p ON p.id=rp.permission_id WHERE rp.tenant_id=202 AND p.`key`='core.role.create'");
    $pdo->exec('UPDATE pa_tenant SET authorization_revision=authorization_revision+1 WHERE id=202');
    httpExpect(stabilizationHttp('POST', 'adminapi/role/add', ['name' => 'Permission denied'], $beta, 'beta.d03.test')['status'] === 403, 'D03 permission middleware did not deny removed grant');
    httpExpect((int) $pdo->query("SELECT COUNT(*) FROM pa_role WHERE name='Permission denied'")->fetchColumn() === 0, 'D03 revoked request wrote a role');
} catch (Throwable $error) {
    $failures[] = $error::class . ': ' . $error->getMessage();
    fwrite(STDERR, end($failures) . "\n");
} finally {
    try {
        RegisteredMysqlTestResource::cleanup($pdo, $database, $createdDatabase);
    } finally {
        IsolatedBackendEnvironment::cleanup();
        if (is_dir($runtime)) {
            $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($runtime, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
            foreach ($files as $file) {
                $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
            }
            rmdir($runtime);
        }
    }
}
echo 'BACKEND-STABILIZATION-HTTP-001 slice=d03; requests=' . $requests . '; assertions=' . $assertions . '; failures=' . count($failures) . "\n";
exit($failures === [] ? 0 : 1);
