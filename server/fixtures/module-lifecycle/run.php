<?php

declare(strict_types=1);

/** Specific isolated-APP Member/Task qualification consumer; no installation or identity synthesis. */
if (in_array('--help', $argv ?? [], true)) {
    echo "Usage: php run.php --action=member-upload|task-import-export --input=FILE --input-sha256=SHA --plan=FILE --plan-sha256=SHA\n";
    exit(0);
}
umask(0077);
ini_set('zend.exception_ignore_args', '1');
set_exception_handler(static function (Throwable $error): void {
    fwrite(STDERR, json_encode(['status' => 'failed-preserved', 'exception' => get_class($error),
        'source' => basename($error->getFile()), 'line' => $error->getLine()], JSON_THROW_ON_ERROR) . "\n");
    exit(1);
});
$options = getopt('', ['action:', 'input:', 'input-sha256:', 'plan:', 'plan-sha256:']);
$readPinned = static function (string $path, string $digest, bool $private = false): array {
    if ($path === '' || !is_file($path) || is_link($path) || realpath($path) !== $path
        || preg_match('/^[a-f0-9]{64}$/D', $digest) !== 1 || !hash_equals($digest, (string) hash_file('sha256', $path))
        || ($private && (fileperms($path) & 0777) !== 0600)) {
        throw new RuntimeException('MODULE_QUALIFICATION_PINNED_INPUT_INVALID');
    }
    $value = json_decode((string) file_get_contents($path), true, 128, JSON_THROW_ON_ERROR);
    if (!is_array($value)) {
        throw new RuntimeException('MODULE_QUALIFICATION_INPUT_OBJECT_REQUIRED');
    }
    return $value;
};
$config = $readPinned((string) ($options['input'] ?? ''), (string) ($options['input-sha256'] ?? ''));
$planPath = (string) ($options['plan'] ?? '');
$planDigest = (string) ($options['plan-sha256'] ?? '');
$plan = $readPinned($planPath, $planDigest);
$action = (string) ($options['action'] ?? '');
if (!in_array($action, ['member-upload', 'task-import-export'], true)
    || $config !== ($plan['lifecycle_inputs']['module_qualification'] ?? null)) {
    throw new RuntimeException('MODULE_QUALIFICATION_PLAN_INPUT_MISMATCH');
}
$toolRoot = realpath(dirname(__DIR__, 3));
$guard = static function (bool $transaction = false) use ($toolRoot, $planPath, $planDigest): void {
    $process = proc_open([$toolRoot . '/scripts/p0e-runtime-qualification', 'verify-module-context',
        '--plan=' . $planPath, '--plan-sha256=' . $planDigest], [
            0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w'],
        ], $pipes, $toolRoot);
    if (!is_resource($process)) {
        throw new RuntimeException('MODULE_QUALIFICATION_NATIVE_GUARD_UNAVAILABLE');
    }
    fclose($pipes[0]);
    $output = stream_get_contents($pipes[1]);
    $error = stream_get_contents($pipes[2]);
    fclose($pipes[1]); fclose($pipes[2]);
    if (proc_close($process) !== 0) {
        throw new RuntimeException('MODULE_QUALIFICATION_NATIVE_ADMISSION_LOST');
    }
    $result = json_decode((string) $output, true, 32, JSON_THROW_ON_ERROR);
    if (($result['status'] ?? null) !== 'verified') {
        throw new RuntimeException('MODULE_QUALIFICATION_NATIVE_ADMISSION_UNKNOWN');
    }
};
$guard();
$runtime = $config['installed_application'];
$root = realpath((string) $runtime['root']);
if ($root === false || $root === $toolRoot || $root === realpath((string) $config['reviewed_module_source']['root'])) {
    throw new RuntimeException('MODULE_QUALIFICATION_ISOLATED_APP_REQUIRED');
}
require $root . '/server/vendor/autoload.php';
$app = (new think\App($root . '/server'))->initialize();
$pdo = think\facade\Db::connect()->getPdo();
$identity = app\common\services\installation\InstallationExecutionHost::migrationDatabaseIdentity($pdo);
if ($identity !== $runtime['database_identity']) {
    throw new RuntimeException('MODULE_QUALIFICATION_DATABASE_IDENTITY_MISMATCH');
}
$tokens = $readPinned($config['identity_context']['path'], $config['identity_context']['sha256'], true);
$token = static function (string $key) use ($tokens): string {
    $value = $tokens[$key] ?? null;
    if (!is_string($value) || trim($value) === '') {
        throw new RuntimeException('MODULE_QUALIFICATION_NATIVE_SESSION_REQUIRED:' . $key);
    }
    return $value;
};
$requestId = static fn(string $audience): string => 'module-qualification-' . $audience . '-' . bin2hex(random_bytes(8));
$authentication = $app->make(PeanutAdmin\Modules\Identity\Auth\TenantAuthService::class);
$tenant = static fn(string $key): PeanutAdmin\Kernel\Auth\TenantContext =>
    $authentication->context($token($key), $requestId($key));

ob_start();
try {
    if ($action === 'member-upload') {
        require_once $toolRoot . '/server/tests/Multitenancy/MemberUploadTenantWiringTest.php';
        $memberToken = $token('member_access_token');
        $member = null;
        $request = $app->request;
        $request->withHeader(['authorization' => 'Bearer ' . $memberToken]);
        $app->make(app\api\middleware\CheckTokenMiddleware::class)->handle($request,
            static function () use ($app, &$member): void {
                $member = $app->make(app\common\execution\CurrentExecutionContext::class)->member();
            },
        );
        if (!$member instanceof PeanutAdmin\Kernel\Context\AuthenticatedMemberContext) {
            throw new RuntimeException('MODULE_QUALIFICATION_ACTIVE_MEMBER_REQUIRED');
        }
        memberUploadInstalledAssertions($app, $pdo, $member, (int) $config['other_tenant_id'], $identity, $guard);
    } else {
        require_once $toolRoot . '/server/tests/Multitenancy/TaskImportExportTenantIsolationTest.php';
        $alpha = $tenant('alpha_access_token');
        $beta = $tenant('beta_access_token');
        $noExport = $tenant('no_export_access_token');
        $auditFailure = $tenant('audit_failure_access_token');
        $platformSessions = $app->make(app\platform\services\PlatformOperatorSessionService::class);
        $platform = $platformSessions->context($token('platform_access_token'), $requestId('platform'));
        $platformSessions->assertAllowed($platform, 'platform.plan.manage');
        $plans = $app->make(PeanutAdmin\Modules\Identity\Platform\Application\PlanLicenseAdminService::class);
        $license = $plans->tenantLicense($alpha->tenantId)['license'] ?? null;
        if (!is_array($license)) {
            throw new RuntimeException('MODULE_QUALIFICATION_ALPHA_LICENSE_REQUIRED');
        }
        $planId = (int) $license['plan_id'];
        $references = $pdo->prepare('SELECT tenant_id FROM pa_tenant_license WHERE plan_id=? ORDER BY tenant_id');
        $references->execute([$planId]);
        if (array_map('intval', $references->fetchAll(PDO::FETCH_COLUMN)) !== [$alpha->tenantId]) {
            throw new RuntimeException('MODULE_QUALIFICATION_ALPHA_PLAN_MUST_BE_EXCLUSIVE');
        }
        $readPlan = static function () use ($pdo, $planId): array {
            $statement = $pdo->prepare('SELECT id,name,status,revision FROM pa_plan WHERE id=?');
            $statement->execute([$planId]);
            $current = $statement->fetch(PDO::FETCH_ASSOC);
            if (!is_array($current)) { throw new RuntimeException('MODULE_QUALIFICATION_PLAN_DISAPPEARED'); }
            $modules = $pdo->prepare('SELECT module_key FROM pa_plan_module WHERE plan_id=? ORDER BY module_key');
            $modules->execute([$planId]);
            $permissions = $pdo->prepare('SELECT permission_key FROM pa_plan_permission WHERE plan_id=? ORDER BY permission_key');
            $permissions->execute([$planId]);
            return $current + ['module_keys' => $modules->fetchAll(PDO::FETCH_COLUMN),
                'permission_keys' => $permissions->fetchAll(PDO::FETCH_COLUMN)];
        };
        $originalPlan = $readPlan();
        $registry = $app->make(PeanutAdmin\Kernel\Module\CompiledModuleRegistry::class);
        $moduleSelection = static function (string $key, bool $enabled) use ($guard, $plans, $platformSessions, $requestId, $originalPlan, $planId, $readPlan, $registry, $pdo, $authentication, $token, $alpha, $auditFailure): array {
            $guard();
            $current = $readPlan();
            $removed = $enabled ? [] : [$key];
            do {
                $previous = $removed;
                foreach ($originalPlan['module_keys'] as $module) {
                    $manifest = $registry->requireManifest($module)->data;
                    foreach ([...($manifest['dependencies'] ?? []), ...($manifest['tenant']['requires'] ?? [])] as $dependency) {
                        $dependency = is_array($dependency) ? ($dependency['module_key'] ?? '') : $dependency;
                        if (in_array($dependency, $removed, true) && !in_array($module, $removed, true)) {
                            $removed[] = $module;
                        }
                    }
                }
            } while ($previous !== $removed);
            $moduleKeys = array_values(array_diff($originalPlan['module_keys'], $removed));
            $permissionOwners = $pdo->query('SELECT p.`key`,p.module_key FROM pa_permission p')->fetchAll(PDO::FETCH_KEY_PAIR);
            $permissionKeys = array_values(array_filter($originalPlan['permission_keys'],
                static fn(string $permission): bool => !in_array($permissionOwners[$permission] ?? '', $removed, true)));
            $bindings = $pdo->query("SELECT p.`key`,api.module_key,api.prerequisite_permission_keys
                FROM pa_permission_api binding JOIN pa_permission p ON p.id=binding.permission_id
                JOIN pa_api_endpoint api ON api.id=binding.api_id WHERE api.status='active'")->fetchAll(PDO::FETCH_ASSOC);
            do {
                $previousPermissions = $permissionKeys;
                foreach ($bindings as $binding) {
                    $prerequisites = json_decode($binding['prerequisite_permission_keys'], true, 32, JSON_THROW_ON_ERROR);
                    if (in_array($binding['module_key'], $removed, true)
                        || array_diff($prerequisites, [...$permissionKeys, ...PeanutAdmin\Modules\Identity\Authorization\CorePermissionCatalog::TENANT]) !== []) {
                        $permissionKeys = array_values(array_diff($permissionKeys, [$binding['key']]));
                    }
                }
            } while ($previousPermissions !== $permissionKeys);
            $platform = $platformSessions->context($token('platform_access_token'), $requestId('platform-plan'));
            $platformSessions->assertAllowed($platform, 'platform.plan.manage');
            $plans->savePlan($platform->core, [
                'id' => $planId, 'expected_revision' => (int) $current['revision'],
                'name' => $originalPlan['name'], 'status' => $originalPlan['status'],
                'module_keys' => $moduleKeys, 'permission_keys' => $permissionKeys,
                'change_reason' => 'Isolated Module qualification: ' . ($enabled ? 'restore ' : 'revoke ') . $key,
            ], false);
            $guard();
            return [
                $authentication->context($token('alpha_access_token'), $alpha->requestId),
                $authentication->context($token('audit_failure_access_token'), $auditFailure->requestId),
            ];
        };
        taskImportExportInstalledAssertions($app, $pdo, $alpha, $beta, $noExport, $auditFailure,
            (int) $config['alpha_role_id'], $identity, $guard, $moduleSelection);
    }
    $assertionOutput = (string) ob_get_clean();
} catch (Throwable $error) {
    ob_end_clean();
    throw $error;
}
$guard();
echo json_encode(['protocol' => 'peanut.module-qualification-result.v1', 'status' => 'passed',
    'producer' => $action, 'source' => $plan['lifecycle_inputs']['source'],
    'input_sha256' => $options['input-sha256'], 'plan_sha256' => $planDigest,
    'application_root' => $root, 'resource_id' => $runtime['resource_id'],
    'database_identity' => $identity, 'assertion_output_sha256' => hash('sha256', $assertionOutput),
    'native_identity' => 'verified-current-session', 'writes_retained' => true], JSON_THROW_ON_ERROR) . "\n";
