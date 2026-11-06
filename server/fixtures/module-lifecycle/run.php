<?php

declare(strict_types=1);

/** Specific isolated-APP Member/Task qualification consumer; no installation or identity synthesis. */
if (in_array('--help', $argv ?? [], true)) {
    echo "Usage: php run.php --action=member-upload|task-import-export|task-inflight-revocation --input=FILE --input-sha256=SHA --plan=FILE --plan-sha256=SHA\n";
    exit(0);
}
umask(0077);
ini_set('zend.exception_ignore_args', '1');
set_exception_handler(static function (Throwable $error): void {
    fwrite(STDERR, json_encode(['status' => 'failed-preserved', 'exception' => get_class($error),
        'source' => basename($error->getFile()), 'line' => $error->getLine()], JSON_THROW_ON_ERROR) . "\n");
    exit(1);
});
$options = getopt('', ['action:', 'input:', 'input-sha256:', 'plan:', 'plan-sha256:', 'consumer:', 'context-output:', 'identity-context:', 'identity-context-sha256:']);
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
if (!in_array($action, ['member-upload', 'task-import-export', 'task-inflight-revocation', 'identity-context'], true)
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
$moduleSourceRoot = $config['reviewed_module_source']['root'];
$runtime = $config['installed_application'];
$root = realpath((string) $runtime['root']);
if ($root === false || $root === $toolRoot || $root === realpath((string) $config['reviewed_module_source']['root'])) {
    throw new RuntimeException('MODULE_QUALIFICATION_ISOLATED_APP_REQUIRED');
}
require $root . '/server/vendor/autoload.php';
$app = (new think\App($root . '/server'))->initialize();
$connection = think\facade\Db::connect();
$connection->query('SELECT 1', [], true);
$pdo = $connection->getPdo();
$identity = app\common\services\installation\InstallationExecutionHost::migrationDatabaseIdentity($pdo);
if ($identity !== $runtime['database_identity']) {
    throw new RuntimeException('MODULE_QUALIFICATION_DATABASE_IDENTITY_MISMATCH');
}

$requestId = static fn(string $audience): string => 'module-qualification-' . $audience . '-' . bin2hex(random_bytes(8));
$authentication = $app->make(PeanutAdmin\Modules\Identity\Auth\TenantAuthService::class);
if (isset($config['identity_credentials'])) {
    $credentials = $config['identity_credentials'];
    $phase = $action === 'identity-context' ? (string) ($options['consumer'] ?? '') : $action;
    if ($phase === 'task-inflight-revocation') { $phase = 'task-import-export'; }
    if (!in_array($phase, ['preflight', 'member-upload', 'task-import-export'], true)) {
        throw new RuntimeException('MODULE_QUALIFICATION_IDENTITY_PHASE_INVALID');
    }
    $sourceDigest = (string) hash_file('sha256', __FILE__);
    if (($plan['qualification_tool_binding']['tool_files']['server/fixtures/module-lifecycle/run.php'] ?? null) !== $sourceDigest) {
        throw new RuntimeException('MODULE_QUALIFICATION_IDENTITY_PRODUCER_UNBOUND');
    }
    $privateRef = static function (array $reference) use ($readPinned): array {
        $path = (string) ($reference['path'] ?? '');
        $stat = lstat($path);
        if ($stat === false || $stat['nlink'] !== 1 || $stat['uid'] !== posix_geteuid()) {
            throw new RuntimeException('MODULE_QUALIFICATION_IDENTITY_PRIVATE_REFERENCE_INVALID');
        }
        return $readPinned($path, (string) ($reference['sha256'] ?? ''), true);
    };
    $actors = $privateRef($credentials['actors']);
    $export = $privateRef($credentials['export_actor']);
    $facts = $privateRef($credentials['actor_facts'])['facts'];
    $reference = $privateRef($credentials['reference_facts'])['facts'];
    $initialRef = $credentials['initial_admin'];
    $initialStat = lstat($initialRef['path']);
    if ($initialStat === false || $initialStat['nlink'] !== 1 || $initialStat['uid'] !== posix_geteuid()
        || is_link($initialRef['path']) || realpath($initialRef['path']) !== $initialRef['path']
        || ($initialStat['mode'] & 0777) !== 0600
        || !hash_equals($initialRef['sha256'], (string) hash_file('sha256', $initialRef['path']))) {
        throw new RuntimeException('MODULE_QUALIFICATION_INITIAL_CREDENTIAL_REFERENCE_INVALID');
    }
    $app->env->load($initialRef['path']);
    $initial = [];
    foreach (['ADMIN_INITIAL_EMAIL', 'ADMIN_INITIAL_PASSWORD', 'PLATFORM_INITIAL_EMAIL', 'PLATFORM_INITIAL_PASSWORD'] as $name) {
        $initial[$name] = $app->env->get($name);
        if (!is_string($initial[$name]) || $initial[$name] === '') {
            throw new RuntimeException('MODULE_QUALIFICATION_INITIAL_CREDENTIAL_REQUIRED');
        }
    }
    $specs = [
        'alpha_manager_access_token' => [$initial['ADMIN_INITIAL_EMAIL'], $initial['ADMIN_INITIAL_PASSWORD'], (int) $facts['alpha_tenant_id'], (int) $facts['manager_original_member_id']],
        'alpha_access_token' => [$export['export_email'], $export['export_password'], (int) $facts['alpha_tenant_id'], (int) $facts['alpha_member_id']],
        'beta_access_token' => [$actors['beta_email'], $actors['beta_password'], (int) $facts['beta_tenant_id'], (int) $facts['beta_member_id']],
        'no_export_access_token' => [$actors['no_export_email'], $actors['no_export_password'], (int) $facts['alpha_tenant_id'], (int) $facts['no_export_member_id']],
        'audit_failure_access_token' => [$export['export_email'], $export['export_password'], (int) $facts['alpha_tenant_id'], (int) $facts['alpha_member_id']],
    ];
    $platformSessions = $app->make(app\platform\services\PlatformOperatorSessionService::class);
    $nativeFacts = static function (string $name, string $raw) use ($app, $authentication, $platformSessions, $requestId, $specs, $reference, $facts): array {
        if ($name === 'member_access_token') {
            $member = $app->make(PeanutAdmin\Modules\Member\Service\UserTokenService::class)->parseSessionToken($raw);
            if ($member['member_id'] !== (int) $reference['member_id'] || $member['tenant_id'] !== (int) $facts['alpha_tenant_id']) {
                throw new RuntimeException('MODULE_QUALIFICATION_MEMBER_PRINCIPAL_CHANGED');
            }
            $member['session_key_sha256'] = hash('sha256', $member['session_key']);
            unset($member['session_key']);
            return $member;
        }
        if ($name === 'platform_access_token') {
            $context = $platformSessions->context($raw, $requestId('platform-context'));
            $record = $app->make(PeanutAdmin\Modules\Identity\Auth\PlatformAuthRepository::class)
                ->sessionByTokenHash(hash('sha256', $raw), 'access', false);
            if ($context->operatorId !== (int) $reference['platform_operator_id'] || $record === null) {
                throw new RuntimeException('MODULE_QUALIFICATION_PLATFORM_PRINCIPAL_CHANGED');
            }
            $actor = ['account_id' => $context->accountId, 'operator_id' => $context->operatorId];
        } else {
            if (!isset($specs[$name])) { throw new RuntimeException('MODULE_QUALIFICATION_UNKNOWN_TOKEN_SLOT'); }
            $context = $authentication->context($raw, $requestId('tenant-context'));
            $record = $app->make(PeanutAdmin\Modules\Identity\Auth\TenantAuthRepository::class)
                ->sessionByTokenHash(hash('sha256', $raw), 'access', false);
            if ($context->tenantId !== $specs[$name][2] || $context->memberId !== $specs[$name][3] || $record === null) {
                throw new RuntimeException('MODULE_QUALIFICATION_TENANT_PRINCIPAL_CHANGED');
            }
            $actor = ['tenant_id' => $context->tenantId, 'member_id' => $context->memberId, 'account_id' => $context->accountId];
        }
        return $actor + ['token_id' => $record->tokenId, 'session_id' => $record->sessionId,
            'session_key_sha256' => hash('sha256', $record->sessionKey), 'issued_at' => $record->issuedAt->getTimestamp(),
            'expires_at' => $record->tokenExpiresAt->getTimestamp()];
    };
    $binding = ['plan_sha256' => $planDigest, 'input_sha256' => (string) $options['input-sha256'],
        'owner' => $plan['owner'], 'thread' => $plan['thread'], 'runtime_lease' => $runtime['lease'],
        'application_root' => $root, 'application_head' => $runtime['head'], 'resource_id' => $runtime['resource_id'],
        'database_identity' => $identity, 'producer_sha256' => $sourceDigest, 'credentials' => $credentials, 'consumer' => $phase];
    $expectedPath = $plan['paths']['output-dir'] . '/identity-contexts/' . $phase . '.json';
    if ($action === 'identity-context') {
        $path = (string) ($options['context-output'] ?? '');
        $parent = dirname($path);
        if ($path !== $expectedPath || realpath($parent) !== $parent || is_link($parent)
            || (fileperms($parent) & 0777) !== 0700 || fileowner($parent) !== posix_geteuid()
            || file_exists($path) || is_link($path)) {
            throw new RuntimeException('MODULE_QUALIFICATION_CONTEXT_OUTPUT_INVALID_OR_RETAINED');
        }
        $value = ['protocol' => 'peanut.module-native-identity-context.v1', 'status' => 'issuing',
            'binding' => $binding, 'tokens' => [], 'facts' => []];
        $save = static function () use (&$value, $path): void {
            $temporary = $path . '.temporary';
            $stream = fopen($temporary, 'x');
            if ($stream === false) { throw new RuntimeException('MODULE_QUALIFICATION_CONTEXT_WRITE_FAILED'); }
            chmod($temporary, 0600);
            fwrite($stream, json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n");
            fflush($stream);
            fclose($stream);
            if (file_exists($path) && (is_link($path) || fileowner($path) !== posix_geteuid()
                || (fileperms($path) & 0777) !== 0600 || lstat($path)['nlink'] !== 1)) {
                throw new RuntimeException('MODULE_QUALIFICATION_CONTEXT_DESTINATION_CHANGED');
            }
            if (!rename($temporary, $path)) { throw new RuntimeException('MODULE_QUALIFICATION_CONTEXT_COMMIT_FAILED'); }
        };
        $save();
        $tenantCodes = $pdo->query('SELECT id,code FROM pa_tenant ORDER BY id')->fetchAll(PDO::FETCH_KEY_PAIR);
        foreach ($specs as $name => [$email, $password, $tenantId, $memberId]) {
            $guard();
            $result = $authentication->login($email, $password, $tenantCodes[$tenantId], '127.0.0.1',
                '058-existing-qualification', $requestId('native-login'));
            if (!$result instanceof PeanutAdmin\Modules\Identity\Auth\TenantAuthentication
                || $result->context->tenantId !== $tenantId || $result->context->memberId !== $memberId) {
                throw new RuntimeException('MODULE_QUALIFICATION_TENANT_LOGIN_PRINCIPAL_CHANGED');
            }
            $raw = $result->tokens->access->expose();
            $value['facts'][$name] = $nativeFacts($name, $raw);
            if ($value['facts'][$name]['expires_at'] !== $result->tokens->accessExpiresAt->getTimestamp()) {
                throw new RuntimeException('MODULE_QUALIFICATION_NATIVE_TOKEN_EXPIRY_CHANGED');
            }
            $value['tokens'][$name] = $raw;
            $save();
        }
        $guard();
        $result = $platformSessions->login($initial['PLATFORM_INITIAL_EMAIL'], $initial['PLATFORM_INITIAL_PASSWORD'],
            '127.0.0.1', '058-existing-qualification', $requestId('platform-login'));
        $raw = $result->tokens->access->expose();
        $value['facts']['platform_access_token'] = $nativeFacts('platform_access_token', $raw);
        if ($value['facts']['platform_access_token']['expires_at'] !== $result->tokens->accessExpiresAt->getTimestamp()) {
            throw new RuntimeException('MODULE_QUALIFICATION_NATIVE_PLATFORM_EXPIRY_CHANGED');
        }
        $value['tokens']['platform_access_token'] = $raw;
        $save();
        $guard();
        $app->request->withHeader(['host' => $reference['member_host']]);
        $app->request->withServer(['HTTP_HOST' => $reference['member_host'], 'SERVER_NAME' => $reference['member_host'], 'REQUEST_METHOD' => 'POST']);
        $memberResult = null;
        $app->make(app\api\middleware\PublicTenantModuleMiddleware::class)->handle($app->request,
            static function () use ($app, $actors, &$memberResult): void {
                $public = $app->make(app\common\execution\CurrentExecutionContext::class)->consumer()->publicTenant;
                $memberResult = $app->make(PeanutAdmin\Modules\Member\Service\LoginApplicationService::class)
                    ->login($public, ['account' => $actors['member_account'], 'password' => $actors['member_password'], 'terminal' => 1], '127.0.0.1');
            }, 'peanut.member.public-auth', 'peanut.member', 'member.login');
        if (!is_array($memberResult) || (int) $memberResult['id'] !== (int) $reference['member_id']) {
            throw new RuntimeException('MODULE_QUALIFICATION_MEMBER_LOGIN_PRINCIPAL_CHANGED');
        }
        $value['facts']['member_access_token'] = $nativeFacts('member_access_token', $memberResult['token']);
        $value['tokens']['member_access_token'] = $memberResult['token'];
        $guard();
        $value['status'] = 'issued';
        $save();
        echo json_encode(['protocol' => 'peanut.module-native-identity-delivery.v1', 'status' => 'issued',
            'context' => ['path' => $path, 'sha256' => hash_file('sha256', $path)],
            'binding' => $binding, 'facts' => $value['facts']], JSON_THROW_ON_ERROR), "\n";
        exit(0);
    }
    $path = (string) ($options['identity-context'] ?? '');
    if ($path !== $expectedPath) { throw new RuntimeException('MODULE_QUALIFICATION_IDENTITY_CONTEXT_LOCATION_MISMATCH'); }
    $context = $privateRef(['path' => $path, 'sha256' => (string) ($options['identity-context-sha256'] ?? '')]);
    if (($context['protocol'] ?? null) !== 'peanut.module-native-identity-context.v1'
        || ($context['status'] ?? null) !== 'issued' || ($context['binding'] ?? null) !== $binding) {
        throw new RuntimeException('MODULE_QUALIFICATION_IDENTITY_CONTEXT_PROVENANCE_MISMATCH');
    }
    $tokens = $context['tokens'];
    $names = [...array_keys($specs), 'platform_access_token', 'member_access_token'];
    if (array_keys($tokens) !== $names || array_keys($context['facts']) !== $names) {
        throw new RuntimeException('MODULE_QUALIFICATION_IDENTITY_CONTEXT_COVERAGE_MISMATCH');
    }
    foreach ($tokens as $name => $raw) {
        if (!is_string($raw) || $raw === '' || $nativeFacts($name, $raw) !== $context['facts'][$name]) {
            throw new RuntimeException('MODULE_QUALIFICATION_NATIVE_SESSION_FACTS_CHANGED');
        }
    }
} else {
    if ($action === 'identity-context') { throw new RuntimeException('MODULE_QUALIFICATION_EXISTING_CREDENTIALS_REQUIRED'); }
    $tokens = $readPinned($config['identity_context']['path'], $config['identity_context']['sha256'], true);
}
$token = static function (string $key) use ($tokens): string {
    $value = $tokens[$key] ?? null;
    if (!is_string($value) || trim($value) === '') {
        throw new RuntimeException('MODULE_QUALIFICATION_NATIVE_SESSION_REQUIRED:' . $key);
    }
    return $value;
};
$tenant = static fn(string $key): PeanutAdmin\Kernel\Auth\TenantContext =>
    $authentication->context($token($key), $requestId($key));

ob_start();
try {
    if ($action === 'member-upload') {
        require_once $moduleSourceRoot . '/server/tests/Multitenancy/MemberUploadTenantWiringTest.php';
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
    } elseif ($action === 'task-inflight-revocation') {
        require_once $moduleSourceRoot . '/server/tests/Multitenancy/TaskImportExportTenantIsolationTest.php';
        $GLOBALS['taskImportExportChecks'] = 0;
        $status = $app->make(app\common\services\installation\InstallationExecutionHost::class)->status();
        expectAsyncTenant(($status['state'] ?? null) === 'installed' && ($status['deployment_mode'] ?? null) === 'multi-tenant',
            'healthy installed multi-tenant Code is required');
        $alpha = $tenant('alpha_access_token');
        $authorization = $app->make(app\common\services\authorization\AdminAuthorizationService::class);
        $principal = $authorization->principal($alpha);
        expectAsyncTenant(!$principal->root, 'revocation assertion requires a non-Root installed principal');
        $roleIds = array_column($principal->roles, 'id');
        expectAsyncTenant($roleIds !== [], 'native principal has no active Roles');
        $permission = (int) $pdo->query("SELECT id FROM pa_permission WHERE `key`='peanut.import-export.operation-log.export' AND status='active'")->fetchColumn();
        expectAsyncTenant($permission > 0, 'existing native export Permission is required');
        $sources = $pdo->prepare('SELECT role_id FROM pa_role_permission WHERE tenant_id=? AND permission_id=? AND role_id IN ('
            . implode(',', array_fill(0, count($roleIds), '?')) . ')');
        $sources->execute([$alpha->tenantId, $permission, ...$roleIds]);
        expectAsyncTenant(array_map('intval', $sources->fetchAll(PDO::FETCH_COLUMN)) === [(int) $config['alpha_role_id']],
            'specified Role must be the sole effective export grant source');
        $pending = $pdo->prepare("SELECT COUNT(*) FROM pa_task_job WHERE tenant_id IN (?,?) AND status IN ('queued','running')");
        $pending->execute([$alpha->tenantId, (int) $config['other_tenant_id']]);
        expectAsyncTenant((int) $pending->fetchColumn() === 0, 'existing Tenant work must not be claimed by qualification workers');
        assertInFlightExportRevocation($app, $pdo, $app->make(PeanutAdmin\Modules\ImportExport\Service\TaskImportExportRuntime::class),
            $alpha, $tenant('alpha_manager_access_token'), (int) $config['alpha_role_id'], $guard,
            static fn(): PeanutAdmin\Kernel\Auth\TenantContext => $tenant('alpha_access_token'), bin2hex(random_bytes(6)), dirname($planPath));
        $checks = $GLOBALS['taskImportExportChecks'];
        if (!is_int($checks) || $checks < 1) {
            throw new RuntimeException('MODULE_QUALIFICATION_NATIVE_ASSERTIONS_REQUIRED');
        }
        echo 'MT03-TASK-IMPORT-EXPORT-INFLIGHT-REVOCATION-001 passed (' . $checks . " checks)\n";
    } else {
        require_once $moduleSourceRoot . '/server/tests/Multitenancy/TaskImportExportTenantIsolationTest.php';
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
            (int) $config['alpha_role_id'], $identity, $guard, $moduleSelection, $tenant('alpha_manager_access_token'),
            static fn(): PeanutAdmin\Kernel\Auth\TenantContext => $tenant('alpha_access_token'), dirname($planPath));
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
