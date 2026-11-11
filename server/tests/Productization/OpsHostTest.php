<?php

declare(strict_types=1);

use app\adminapi\services\system\SystemApplicationService;
use app\adminapi\services\OperationLogService;
use app\common\policy\permission\RegisteredAdminPermissionPolicy;

require dirname(__DIR__, 2) . '/vendor/autoload.php';

function expectOpsHost(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$serverRoot = dirname(__DIR__, 2);
$repositoryRoot = dirname($serverRoot);
$app = new think\App($serverRoot);
require_once $app->getThinkPath() . 'helper.php';

$permissions = ['core.audit.read', 'core.audit.clear', 'core.system.read', 'core.system.cache.clear'];
$policy = new RegisteredAdminPermissionPolicy();
foreach ($permissions as $permission) {
    expectOpsHost(
        $policy->canAccess(false, $permission, $permissions, []) === false,
        'registered unowned operation must fail closed: ' . $permission,
    );
}

$routes = (string) file_get_contents(
    $serverRoot . '/route/admin.php',
);
foreach ($permissions as $permission) {
    expectOpsHost(
        str_contains($routes, "'peanut_permission' => '" . $permission . "'"),
        'operation permission is not registered: ' . $permission,
    );
}

$safeValue = 'visible-value';
$sensitiveValue = 'must-not-appear';
$redacted = OperationLogService::redactSensitive([
    'safe' => $safeValue,
    'jobs_code' => 'ops_job',
    'password' => $sensitiveValue,
    'mch_key' => $sensitiveValue,
    'api_v3_key' => $sensitiveValue,
    'wx_pay_cert_path' => $sensitiveValue,
    'verification_code' => $sensitiveValue,
    'nested' => [
        'Authorization' => $sensitiveValue,
        'public_key' => $safeValue,
    ],
]);
expectOpsHost(($redacted['safe'] ?? null) === $safeValue, 'safe value must remain visible');
expectOpsHost(($redacted['jobs_code'] ?? null) === 'ops_job', 'business code must not be over-redacted');
expectOpsHost(($redacted['nested']['public_key'] ?? null) === '******', 'public key must be redacted from audit logs');
foreach (['password', 'mch_key', 'api_v3_key', 'wx_pay_cert_path', 'verification_code'] as $key) {
    expectOpsHost(($redacted[$key] ?? null) === '******', 'sensitive field was not redacted: ' . $key);
}
expectOpsHost(
    ($redacted['nested']['Authorization'] ?? null) === '******',
    'nested authorization was not redacted',
);

$serialized = OperationLogService::serializeParams($redacted);
expectOpsHost(!str_contains($serialized, $sensitiveValue), 'serialized log leaked a sensitive value');
$oversized = OperationLogService::serializeParams(['payload' => str_repeat('x', 70000)]);
expectOpsHost(
    $oversized === '{"_redacted":"payload_unavailable"}',
    'oversized payload must fail closed to bounded metadata',
);

$info = (new SystemApplicationService())->getInfo('test-server');
expectOpsHost(array_keys($info) === ['server', 'env', 'auth'], 'maintenance probe shape changed');
expectOpsHost(($info['env'][0]['require'] ?? null) === '8.3版本以上', 'PHP requirement must match Composer');
foreach ($info['auth'] as $directory) {
    expectOpsHost(
        in_array((int) ($directory['status'] ?? -1), [0, 1], true),
        'directory probe must return a boolean status',
    );
}
$encodedInfo = json_encode($info, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
expectOpsHost(!str_contains($encodedInfo, root_path()), 'maintenance probe must not expose absolute paths');

$systemSource = (string) file_get_contents($serverRoot . '/app/adminapi/services/system/SystemApplicationService.php');
$probeStart = strpos($systemSource, 'public function getInfo');
$probeEnd = strpos($systemSource, 'public function clearCache');
expectOpsHost($probeStart !== false && $probeEnd !== false, 'maintenance probe source was not found');
$probeSource = substr($systemSource, $probeStart, $probeEnd - $probeStart);
foreach (['check_dir_write', 'file_put_contents', 'touch(', 'mkdir(', 'unlink(', 'del_target_dir', 'Cache::'] as $mutation) {
    expectOpsHost(!str_contains($probeSource, $mutation), 'maintenance probe must stay read-only: ' . $mutation);
}

$middlewareSource = (string) file_get_contents(
    $serverRoot . '/app/adminapi/http/middleware/OperationLogMiddleware.php',
);
$logicSource = (string) file_get_contents($serverRoot . '/app/adminapi/services/log/OperationLogApplicationService.php');
$serviceSource = (string) file_get_contents($serverRoot . '/app/adminapi/services/OperationLogService.php');
$auditHostSource = (string) file_get_contents($serverRoot . '/app/common/services/audit/AuditContractHost.php');
$projectionSource = (string) file_get_contents($serverRoot . '/app/common/infrastructure/audit/OperationLogProjection.php');
$diagnosticSource = (string) file_get_contents($serverRoot . '/app/modules/peanut/ops/src/Service/PlatformDiagnosticBundleService.php');
$diagnosticQuerySource = (string) file_get_contents($serverRoot . '/app/modules/peanut/identity/src/Audit/Diagnostic/ThinkPhpTenantAuditDiagnosticQuery.php');
expectOpsHost(!str_contains($middlewareSource, 'OperationLog::create'), 'middleware must use the unique log service');
expectOpsHost(!str_contains($logicSource, 'OperationLog::create'), 'clear must use the unique log service');
expectOpsHost(str_contains($serviceSource, 'AuditContractHost'), 'log service must use the unified audit host');
expectOpsHost(str_contains($auditHostSource, 'OperationLogProjection'), 'audit host must use the operation log projection');
expectOpsHost(
    substr_count($projectionSource, 'OperationLog::create') === 1,
    'operation log projection must be the unique OperationLog writer',
);
expectOpsHost(
    str_contains($auditHostSource, 'Db::transaction(')
        && !str_contains($auditHostSource, 'beginTransaction()'),
    'Operation Log projections must use the native ThinkPHP transaction boundary',
);
expectOpsHost(
    !str_contains($serviceSource, 'OperationLog::create')
        && !str_contains($auditHostSource, 'OperationLog::create'),
    'Operation Log owner contract must keep one production writer',
);
expectOpsHost(
    str_contains($diagnosticSource, 'Package::READ_PERMISSION')
        && str_contains($diagnosticSource, 'Package::LOGS_PERMISSION'),
    'diagnostic Operation Log evidence bypassed existing Platform permissions',
);
$evidenceStart = strpos($diagnosticQuerySource, 'public function recentOperationEvidence');
$evidenceEnd = strpos($diagnosticQuerySource, 'private static function instant', (int) $evidenceStart);
expectOpsHost($evidenceStart !== false && $evidenceEnd !== false, 'diagnostic Operation Log evidence source was not found');
$evidenceSource = substr($diagnosticQuerySource, $evidenceStart, $evidenceEnd - $evidenceStart);
foreach (['tenant_id', 'request_id', 'operation_id', 'action', 'outcome', 'reason_code', 'target_resource_id', 'occurred_at'] as $field) {
    expectOpsHost(str_contains($evidenceSource, $field), 'diagnostic Operation Log evidence lost: ' . $field);
}
foreach (['metadata_json', 'params', 'username', 'admin_id', 'ip_address', 'actor_'] as $field) {
    expectOpsHost(!str_contains($evidenceSource, $field), 'diagnostic Operation Log evidence exposes: ' . $field);
}
expectOpsHost(
    str_contains($diagnosticSource, "'operation_logs' => [")
        && str_contains($diagnosticSource, "'items' => \$this->tenantAuditDiagnostics->recentOperationEvidence(\$since, 100)"),
    'diagnostic bundle does not include Operation Log evidence',
);
expectOpsHost(str_contains($logicSource, 'Db::transaction('), 'log clear must use a ThinkPHP transaction');
expectOpsHost(str_contains($logicSource, "'log/clear'"), 'log clear must retain an audit tombstone');
expectOpsHost(!str_contains($serviceSource, 'PeanutAdmin\\Modules\\Ops\\Domain'), 'application log owner must not deep import official.ops internals');

echo "PB04-OPS-HOST-001 passed\n";
