<?php

declare(strict_types=1);

/**
 * 用既有签名包夹具执行真实 scripts/upgrade 子进程及文件引擎。
 * Host 为明确合成的阶段回执/故障注入，不运行Docker、数据库、网站或真实业务。
 */
function productCoordinatorProbe(
    string $toolRoot,
    string $temporary,
    string $sourceProject,
    string $sourcePackage,
    string $public,
    string $secret,
): void {
    $checks = 0;
    $failures = [];
    $assert = static function (bool $ok, string $message) use (&$checks): void {
        $checks++;
        if (!$ok) {
            throw new RuntimeException($message);
        }
    };
    $host = <<<'PHP'
#!/usr/bin/env php
<?php
declare(strict_types=1);
$phase = $argv[1]; $args = [];
foreach (array_slice($argv, 2) as $arg) { [$key, $value] = explode('=', $arg, 2); $args[$key] = $value; }
$root = $args['--instance-root'];
$plan = json_decode(file_get_contents($args['--plan']), true, 512, JSON_THROW_ON_ERROR);
$state = json_decode(file_get_contents($args['--state']), true, 512, JSON_THROW_ON_ERROR);
$directory = $root . '/.fixture-host';
if (!is_dir($directory)) mkdir($directory, 0700);
file_put_contents($directory . '/calls.jsonl', json_encode([
    'phase' => $phase, 'state_phase' => $state['phase'],
    'database_recovery_required' => $state['database_recovery_required'],
    'activation_started' => $state['activation_started'],
]) . "\n", FILE_APPEND);
if ($state['phase_in_progress'] !== $phase) exit(71);
if ($phase === 'migrate' && !$state['database_recovery_required']) exit(72);
if ($phase === 'activate' && !$state['activation_started']) exit(73);
$control = is_file($directory . '/control.json')
    ? json_decode(file_get_contents($directory . '/control.json'), true, 512, JSON_THROW_ON_ERROR) : [];
if (($control['fail'] ?? null) === $phase) { fwrite(STDERR, 'synthetic-host-failure:' . $phase); exit(23); }
echo json_encode([
    'protocol' => 'peanut.product-upgrade-host-evidence.v1', 'status' => 'completed',
    'phase' => $phase,
    'candidate' => ($control['wrong_evidence'] ?? null) === $phase ? 'wrong-candidate' : $plan['candidate'],
    'plan_sha256' => $plan['plan_sha256'], 'package_manifest_sha256' => $plan['package']['manifest_sha256'],
    'payload' => ['proof' => 'synthetic-host-phase-only', 'database_executed' => false],
], JSON_THROW_ON_ERROR);
PHP;
    // 缺失安装依赖时应给出明确错误，不加载目标包或退回维护者的其他目录。
    $missing = $temporary . '/coordinator-missing-dependencies';
    mkdir($missing . '/scripts', 0700, true);
    copy($toolRoot . '/scripts/upgrade', $missing . '/scripts/upgrade');
    $pipes = [];
    $process = proc_open(
        [PHP_BINARY, $missing . '/scripts/upgrade', 'plan'],
        [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['redirect', 1]],
        $pipes,
        $missing,
    );
    $assert(is_resource($process), 'cannot run missing dependency check');
    $output = stream_get_contents($pipes[1]);
    fclose($pipes[1]);
    $assert(proc_close($process) === 1 && str_contains($output, 'PRODUCT_UPGRADE_DEPENDENCIES_MISSING')
        && !str_contains($output, 'Fatal error'), 'missing dependencies must fail before package access');

    $cases = ['normal', 'managed-drift', 'app-owned-drift', 'migration-recovery',
        'recovery-resume-rejected', 'activation-failure', 'untrusted-package', 'wrong-evidence', 'lock-contention'];
    foreach ($cases as $case) {
        $base = $temporary . '/coordinator-' . $case;
        $project = $base . '/instance';
        $package = $base . '/package';
        editionUpgradeCopyTree($sourceProject, $project);
        editionUpgradeCopyTree($sourcePackage, $package);
        $hostPath = $package . '/scripts/upgrade-runtime/product-upgrade-host';
        editionUpgradeFile($hostPath, $host, 0755);
        // 使用夹具专用临时密钥重新签名，绝不复用正式签名身份。
        $inventory = [];
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($package, FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            if (!$file->isFile()) {
                continue;
            }
            $relative = substr($file->getPathname(), strlen($package) + 1);
            if (!str_starts_with($relative, 'META-INF/')) {
                $inventory[$relative] = hash_file('sha256', $file->getPathname());
            }
        }
        ksort($inventory, SORT_STRING);
        $bytes = '';
        foreach ($inventory as $path => $hash) {
            $bytes .= $path . "\0" . $hash . "\n";
        }
        editionUpgradeFile($package . '/META-INF/files.sha256', $bytes);
        editionUpgradeJson($package . '/META-INF/signatures/test-release.json', [
            'schema_version' => 1, 'algorithm' => 'ed25519', 'key_id' => 'test-release',
            'inventory_sha256' => hash('sha256', $bytes),
            'signature_base64' => base64_encode(sodium_crypto_sign_detached(hash('sha256', $bytes, true), $secret)),
        ]);
        $keys = $base . '/trusted.ini';
        editionUpgradeFile($keys, "PEANUT_UPGRADE_TRUSTED_KEYS_JSON=" . json_encode([
            'test-release' => base64_encode($public),
        ], JSON_THROW_ON_ERROR) . "\n", 0600);
        mkdir($project . '/.fixture-host', 0700);
        $calls = static function () use ($project): array {
            $path = $project . '/.fixture-host/calls.jsonl';
            return is_file($path) ? array_map(static fn(string $line): array => json_decode($line, true, 512, JSON_THROW_ON_ERROR), file($path, FILE_IGNORE_NEW_LINES)) : [];
        };
        $control = static function (array $value) use ($project): void {
            editionUpgradeJson($project . '/.fixture-host/control.json', $value);
        };
        $run = static function (string $operation, ?string $plan = null) use ($toolRoot, $project, $package, $keys, $base): array {
            $arguments = [PHP_BINARY, $toolRoot . '/scripts/upgrade', $operation,
                '--instance-root=' . $project, '--package=' . $package,
                '--signature-key-id=test-release', '--env-file=' . $keys];
            if ($plan !== null) {
                $arguments[] = '--plan=' . $plan;
            }
            $env = [];
            foreach (['PATH', 'HOME', 'TMPDIR', 'SystemRoot'] as $key) {
                if (($value = getenv($key)) !== false) {
                    $env[$key] = $value;
                }
            }
            // 只把当前真实PHP解释器目录加入该子进程，Host脚本不依赖全局PHP版本。
            $env['PATH'] = dirname(PHP_BINARY) . PATH_SEPARATOR . ($env['PATH'] ?? '');
            $pipes = [];
            $process = proc_open($arguments, [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['redirect', 1]], $pipes, $toolRoot, $env);
            if (!is_resource($process)) {
                throw new RuntimeException('cannot start coordinator');
            }
            $output = stream_get_contents($pipes[1]);
            fclose($pipes[1]);
            $exit = proc_close($process);
            file_put_contents($base . '/commands.log', json_encode($arguments) . "\nEXIT {$exit}\n" . $output . "\n", FILE_APPEND);
            $json = json_decode(trim($output), true);
            return ['exit' => $exit, 'output' => $output, 'json' => is_array($json) ? $json : []];
        };
        $ok = static function (array $result, string $message) use ($assert): array {
            $assert($result['exit'] === 0, $message . ': ' . $result['output']);
            return $result['json'];
        };
        $reject = static function (array $result, string $error) use ($assert): void {
            $assert(
                $result['exit'] !== 0 && str_contains($result['output'], $error),
                'expected ' . $error . ', got: ' . $result['output'],
            );
        };
        try {
            if ($case === 'untrusted-package') {
                file_put_contents($hostPath, "\n# untrusted mutation\n", FILE_APPEND);
                $reject($run('plan'), 'EDITION_UPGRADE_FILE_DIGEST_MISMATCH');
                $assert($calls() === [], 'untrusted target executed before authentication');
                continue;
            }
            $plan = $ok($run('plan'), 'plan');
            $assert(($plan['status'] ?? null) === 'ready' && $calls() === [], 'planning must not execute target Host');
            $planPath = $plan['plan_path'];
            $initialState = file_get_contents($plan['state_path']);
            $again = $ok($run('plan'), 'repeat plan');
            $assert(($again['idempotent'] ?? false) && $initialState === file_get_contents($plan['state_path']), 'repeat plan reset state');
            if ($case === 'lock-contention') {
                $lock = fopen($project . '/.peanut/upgrades/product.lock', 'c+');
                if ($lock === false || !flock($lock, LOCK_EX | LOCK_NB)) {
                    throw new RuntimeException('cannot acquire fixture lock');
                }
                try {
                    $reject($run('apply', $planPath), 'PRODUCT_UPGRADE_ALREADY_RUNNING');
                } finally {
                    flock($lock, LOCK_UN);
                    fclose($lock);
                }
                $assert($calls() === [], 'contending process invoked Host');
                continue;
            }
            if ($case === 'wrong-evidence') {
                $control(['wrong_evidence' => 'prepare']);
                $reject($run('apply', $planPath), 'PRODUCT_UPGRADE_HOST_EVIDENCE_INVALID:prepare');
                $assert(file_get_contents($project . '/managed.txt') === "old managed\n", 'invalid Host evidence changed managed files');
                continue;
            }
            if (in_array($case, ['migration-recovery', 'recovery-resume-rejected'], true)) {
                $control(['fail' => 'migrate']);
                $reject($run('apply', $planPath), 'PRODUCT_UPGRADE_HOST_PHASE_FAILED:migrate:exit-23');
                $state = json_decode(file_get_contents($plan['state_path']), true, 512, JSON_THROW_ON_ERROR);
                $assert($state['database_recovery_required'] && !$state['activation_started'], 'migration recovery boundary not persisted');
                if ($case === 'recovery-resume-rejected') {
                    $control(['fail' => 'recover']);
                    $reject($run('recover', $planPath), 'PRODUCT_UPGRADE_HOST_PHASE_FAILED:recover:exit-23');
                    $control([]);
                    $beforeCalls = $calls();
                    $beforeState = file_get_contents($plan['state_path']);
                    $reject($run('apply', $planPath), 'PRODUCT_UPGRADE_RECOVERY_IN_PROGRESS');
                    $assert($beforeCalls === $calls() && $beforeState === file_get_contents($plan['state_path']), 'apply resumed during recovery');
                }
                $control([]);
                $recovered = $ok($run('recover', $planPath), 'recover');
                $assert(($recovered['status'] ?? null) === 'recovered' && file_get_contents($project . '/managed.txt') === "old managed\n", 'file recovery failed');
                $count = count(array_filter($calls(), static fn(array $call): bool => $call['phase'] === 'recover'));
                $repeated = $ok($run('recover', $planPath), 'repeat recover');
                $assert(($repeated['idempotent'] ?? false) && count(array_filter($calls(), static fn(array $call): bool => $call['phase'] === 'recover')) === $count, 'repeat recovery replayed restore');
                $reject($run('apply', $planPath), 'PRODUCT_UPGRADE_RECOVERED_PLAN_FINAL');
                continue;
            }
            if ($case === 'activation-failure') {
                $control(['fail' => 'activate']);
                $reject($run('apply', $planPath), 'PRODUCT_UPGRADE_HOST_PHASE_FAILED:activate:exit-23');
                $state = json_decode(file_get_contents($plan['state_path']), true, 512, JSON_THROW_ON_ERROR);
                $assert($state['activation_started'], 'activation boundary not persisted');
                $before = $calls();
                $reject($run('recover', $planPath), 'PRODUCT_UPGRADE_ACTIVATION_STARTED_FORWARD_REPAIR_REQUIRED');
                $assert($calls() === $before, 'activation failure invoked destructive recovery');
                continue;
            }
            $completed = $ok($run('apply', $planPath), 'initial apply');
            $assert(($completed['status'] ?? null) === 'completed' && file_get_contents($project . '/managed.txt') === "new managed\n", 'apply did not finish');
            if ($case === 'normal') {
                $phases = array_column($calls(), 'phase');
                $assert($phases === ['prepare', 'migration-preflight', 'backup', 'quiesce', 'migrate', 'switch', 'reload-safe', 'migration-verify', 'health', 'activate'], 'unexpected phase order');
                $repeated = $ok($run('apply', $planPath), 'repeat apply');
                $assert(($repeated['idempotent'] ?? false) && array_slice(array_column($calls(), 'phase'), count($phases)) === ['migration-verify', 'health'], 'repeat apply replayed writes');
                $ok($run('verify', $planPath), 'verify');
                $reject($run('recover', $planPath), 'PRODUCT_UPGRADE_COMPLETED_RECOVERY_REQUIRES_NEW_CHANGE_PLAN');
            } else {
                $relative = $case === 'managed-drift' ? 'managed.txt' : 'business.php';
                $error = $case === 'managed-drift' ? 'SCAFFOLD_VERIFY_MANAGED_MISMATCH' : 'SCAFFOLD_VERIFY_APP_OWNED_CHANGED';
                file_put_contents($project . '/' . $relative, "changed after upgrade\n");
                $beforeState = file_get_contents($plan['state_path']);
                $beforeCalls = $calls();
                $reject($run('apply', $planPath), $error);
                $assert($beforeState === file_get_contents($plan['state_path']) && $beforeCalls === $calls(), 'drift rejection had Host/state side effects');
                $reject($run('verify', $planPath), $error);
            }
        } catch (Throwable $error) {
            $failures[] = $case . ': ' . $error->getMessage();
            echo "COORDINATOR CASE FAILED {$case}: " . $error->getMessage() . "\n";
        }
    }
    echo 'PRODUCT-UPGRADE-COORDINATOR-001 cases=' . (count($cases) + 1) . ' checks=' . $checks
        . ' failures=' . count($failures) . "; host=synthetic; database-not-executed\n";
    if ($failures !== []) {
        throw new RuntimeException(implode("\n", $failures));
    }
}
