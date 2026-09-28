<?php

declare(strict_types=1);

define('PEANUT_UPGRADE_HOST_FIXTURE_ONLY', true);
require __DIR__ . '/ProductUpgradeHostBackupBindingTest.php';

/** @return array<string,string> */
function resumeFailureFixture(string $directory, string $failure): array
{
    $fixture = upgradeHostFixture($directory, 'valid', true);
    $runtime = substr($fixture['state'], 0, -5) . '.runtime';
    $fixture['runtime'] = $runtime;
    upgradeHostMutateState($fixture, static function (array &$state): void {
        $state['phase_in_progress'] = 'resume-old';
    });
    upgradeHostJson($runtime . '/recovery.json', [
        'schema_version' => 1,
        'protocol' => 'peanut.product-upgrade-host-recovery.v1',
        'status' => 'data-runtime-restored',
        'candidate' => 'm4-binding',
        'plan_sha256' => 'sha256:' . str_repeat('a', 64),
        'payload' => [
            'recovery' => ['writers_stopped' => true, 'resume_required' => true],
        ],
    ]);
    copy($runtime . '/previous/DEPLOYMENT_RECEIPT.json', $fixture['instance'] . '/DEPLOYMENT_RECEIPT.json');
    upgradeHostJson($directory . '/services.json', ['mysql' => 'running', 'pc' => 'exited', 'php' => 'exited', 'nginx' => 'exited', 'cron' => 'exited']);
    upgradeHostFile($directory . '/failure', $failure);
    // Explicit command model. It cannot fall back to Docker, a database or HTTP.
    upgradeHostFile($fixture['fake_bin'] . '/docker', <<<'PY'
#!/usr/bin/env python3
import json, os, signal, sys
from pathlib import Path
base = Path(os.environ['FAKE_DOCKER_LOG']).parent
args = sys.argv[1:]
state = json.loads((base / 'services.json').read_text())
mode = (base / 'failure').read_text()
ids = {name: char * 64 for name, char in zip(state, 'abcde')}
def log(kind):
    with (base / 'docker.log').open('a') as output: output.write(kind + '\n')
def save(): (base / 'services.json').write_text(json.dumps(state))
def reject():
    log('UNEXPECTED:' + ' '.join(args)); sys.exit(98)
if args == ['compose', 'version']:
    print('Docker Compose version v2.0.0'); sys.exit(0)
if args and args[0] == 'ps':
    if any('project.working_dir=' in x for x in args): print('fixture')
    else:
        service = next((x.split('com.docker.compose.service=', 1)[1] for x in args if 'com.docker.compose.service=' in x), None)
        if service: print(ids[service])
        else:
            for name, status in state.items():
                if status == 'running': print(name)
    sys.exit(0)
if args and args[0] == 'inspect':
    name = next((name for name, value in ids.items() if value == args[-1]), None)
    if name is None: reject()
    values = {'{{.Id}}': ids[name], '{{.Image}}': 'sha256:' + ids[name], '{{.State.Status}}': state[name], '{{.State.StartedAt}}': '2026-09-28T00:00:00Z', '{{.State.FinishedAt}}': '2026-09-28T00:00:00Z', '{{.RestartCount}}': '0'}
    pattern = args[args.index('--format') + 1]
    if pattern not in values: reject()
    print(values[pattern]); sys.exit(0)
if args and args[0] == 'exec':
    if args[1:] != [ids['php'], 'cat', '/var/www/peanut-admin/DEPLOYMENT_RECEIPT.json']: reject()
    log('RECEIPT')
    print('{"mismatch":true}' if mode == 'receipt' else (base / 'instance/DEPLOYMENT_RECEIPT.json').read_text().strip())
    sys.exit(0)
if not args or args[0] != 'compose': reject()
index = next((i for i, arg in enumerate(args) if arg in ['config', 'up', 'stop', 'ps', 'exec']), None)
if index is None: reject()
command = args[index]; rest = args[index+1:]
if command == 'config':
    log('CONFIG'); sys.exit(61 if mode == 'config' else 0)
if command == 'up':
    targets = [x for x in rest if x in state]
    log('UP:' + ','.join(targets))
    for name in targets: state[name] = 'running'
    save()
    if targets == ['mysql']: sys.exit(62 if mode == 'mysql-start' else 0)
    if targets != ['pc', 'php', 'nginx', 'cron']: reject()
    if mode == 'signal': os.kill(os.getppid(), signal.SIGTERM); sys.exit(143)
    sys.exit(63 if mode == 'partial-start' else 0)
if command == 'stop':
    log('STOP')
    if mode == 'health-stop-fails': sys.exit(73)
    for name in ['pc', 'php', 'nginx', 'cron']: state[name] = 'exited'
    save(); sys.exit(0)
if command == 'ps':
    for name, status in state.items():
        if status == 'running': print(name)
    sys.exit(0)
if command == 'exec':
    joined = ' '.join(rest)
    if 'environment-guard.php' in joined: log('ENVIRONMENT'); sys.exit(64 if mode == 'environment' else 0)
    if 'plugin:lock' in joined: log('PLUGIN_LOCK'); sys.exit(65 if mode == 'plugin-lock' else 0)
    if 'install.php --status' in joined:
        log('INSTALL_STATUS')
        if mode == 'status-command': sys.exit(66)
        if mode == 'bad-status': print('{"state":"uninstalled","health":{}}')
        else: print('{"state":"installed","health":{"tenant_count":2,"owner_count":2,"operator_count":1}}')
        sys.exit(0)
    reject()
reject()
PY, 0755);
    upgradeHostFile($fixture['fake_bin'] . '/curl', <<<'PY'
#!/usr/bin/env python3
import os,sys
from pathlib import Path
base=Path(os.environ['FAKE_DOCKER_LOG']).parent
with (base/'docker.log').open('a') as log: log.write('HEALTH\n')
if sys.argv[-1] != 'http://127.0.0.1:8080/healthz': sys.exit(98)
sys.exit(67 if (base/'failure').read_text() in ['health','health-stop-fails'] else 0)
PY, 0755);
    upgradeHostFile($fixture['fake_bin'] . '/mv', <<<'PY'
#!/usr/bin/env python3
import os,sys
from pathlib import Path
base=Path(os.environ['FAKE_DOCKER_LOG']).parent
if (base/'failure').read_text() == 'record-write' and sys.argv[-1].endswith('/resume-old.json'):
    with (base/'docker.log').open('a') as log: log.write('RECORD_WRITE_FAILED\n')
    sys.exit(68)
os.execv('/bin/mv', ['/bin/mv', *sys.argv[1:]])
PY, 0755);
    return $fixture;
}

/** @return array<string,string> */
function resumeProtectedDigests(array $fixture): array
{
    $paths = [$fixture['env'], $fixture['state'], $fixture['runtime'] . '/recovery.json', $fixture['instance'] . '/DEPLOYMENT_RECEIPT.json'];
    foreach ([$fixture['backup'], $fixture['public_volume'], $fixture['private_volume']] as $directory) {
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $entry) {
            if ($entry->isFile()) {
                $paths[] = $entry->getPathname();
            }
        }
    }
    $hashes = [];
    foreach ($paths as $path) {
        $hashes[$path] = hash_file('sha256', $path);
    }
    return $hashes;
}

$temporaryRoot = $root . '/.local/tmp/m4-resume-failure-20260928';
if (!is_dir($temporaryRoot)) {
    mkdir($temporaryRoot, 0700, true);
}
upgradeHostExpect(!is_link($temporaryRoot) && realpath($temporaryRoot) === $temporaryRoot, 'unsafe temporary root');
$temporary = $temporaryRoot . '/run-' . bin2hex(random_bytes(5));
mkdir($temporary, 0700);
$selected = $argv[1] ?? null;
$cases = 0;
try {
    $failures = ['config' => 61, 'mysql-start' => 62, 'partial-start' => 63, 'environment' => 64, 'plugin-lock' => 65, 'status-command' => 1, 'bad-status' => 1, 'health' => 67, 'receipt' => 1, 'record-write' => 68, 'signal' => 143, 'health-stop-fails' => 67];
    foreach ($failures as $failure => $expectedExit) {
        if ($selected !== null && $selected !== $failure) {
            continue;
        }
        $fixture = resumeFailureFixture($temporary . '/' . $failure, $failure);
        $before = resumeProtectedDigests($fixture);
        $result = upgradeHostRunRecover($root, $fixture, 'resume-old');
        echo 'CASE ' . $failure . ' exit=' . $result['exit'] . PHP_EOL;
        echo $result['output'];
        upgradeHostExpect($result['exit'] === $expectedExit, 'resume failure exit mismatch: ' . $failure);
        upgradeHostExpect(!is_file($fixture['runtime'] . '/resume-old.json'), 'failed resume published completion');
        upgradeHostExpect(glob($fixture['runtime'] . '/resume-old.json.tmp-*') === [], 'failed resume left a partial receipt');
        upgradeHostExpect(!str_contains($result['output'], '"status": "completed"'), 'failure emitted success');
        upgradeHostExpect(resumeProtectedDigests($fixture) === $before, 'resume changed restored data, state or backup');
        $services = json_decode((string) file_get_contents(dirname($fixture['docker_log']) . '/services.json'), true, 512, JSON_THROW_ON_ERROR);
        $running = array_keys(array_filter($services, static fn(string $status): bool => $status === 'running'));
        if ($failure === 'health-stop-fails') {
            upgradeHostExpect(count($running) > 1 && str_contains($result['output'], 'manual intervention'), 'failed isolation was not reported');
        } else {
            upgradeHostExpect($running === ['mysql'], 'FAILED_RESUME_LEFT_WRITERS_ACTIVE:' . $failure);
        }
        $log = (string) file_get_contents($fixture['docker_log']);
        upgradeHostExpect(!str_contains($log, 'UNEXPECTED:'), 'unexpected runtime command');
        $cases++;
        // A failed probe may be corrected without redoing the data restore.
        if ($failure === 'health') {
            upgradeHostFile(dirname($fixture['docker_log']) . '/failure', 'none');
            $retry = upgradeHostRunRecover($root, $fixture, 'resume-old');
            upgradeHostExpect($retry['exit'] === 0, 'retry did not recover: ' . $retry['output']);
            $receipt = json_decode($retry['output'], true, 512, JSON_THROW_ON_ERROR);
            upgradeHostExpect($receipt['phase'] === 'resume-old' && $receipt['status'] === 'completed', 'wrong completion response');
            $saved = json_decode((string) file_get_contents($fixture['runtime'] . '/resume-old.json'), true, 512, JSON_THROW_ON_ERROR);
            upgradeHostExpect($saved['recovery_record_sha256'] === 'sha256:' . hash_file('sha256', $fixture['runtime'] . '/recovery.json'), 'retry binding lost');
            upgradeHostExpect(resumeProtectedDigests($fixture) === $before, 'retry repeated restoration');
            $services = json_decode((string) file_get_contents(dirname($fixture['docker_log']) . '/services.json'), true, 512, JSON_THROW_ON_ERROR);
            upgradeHostExpect(count(array_filter($services, static fn(string $status): bool => $status === 'running')) === 5, 'successful retry left writers stopped');
            $cases++;
            echo "CASE retry-after-health passed\n";
            $completedLog = (string) file_get_contents($fixture['docker_log']);
            $completedSha = hash_file('sha256', $fixture['runtime'] . '/resume-old.json');
            $replay = upgradeHostRunRecover($root, $fixture, 'resume-old');
            // Saved JSON is canonically key-sorted; compare decoded values, not formatting.
            upgradeHostExpect(
                $replay['exit'] === 0
                && json_decode($replay['output'], false, 512, JSON_THROW_ON_ERROR)
                    == json_decode($retry['output'], false, 512, JSON_THROW_ON_ERROR),
                'completed replay changed its response',
            );
            upgradeHostExpect(file_get_contents($fixture['docker_log']) === $completedLog, 'completed replay restarted services');
            upgradeHostExpect(hash_file('sha256', $fixture['runtime'] . '/resume-old.json') === $completedSha, 'completed replay rewrote its receipt');
            $cases++;
            echo "CASE completed-replay-without-writes passed\n";
            $saved['runtime_receipt_sha256'] = 'sha256:' . str_repeat('f', 64);
            upgradeHostJson($fixture['runtime'] . '/resume-old.json', $saved);
            $mismatch = upgradeHostRunRecover($root, $fixture, 'resume-old');
            upgradeHostExpect($mismatch['exit'] !== 0 && str_contains($mismatch['output'], 'bound to another runtime'), 'mismatched replay was accepted');
            upgradeHostExpect(file_get_contents($fixture['docker_log']) === $completedLog, 'mismatched replay affected existing services');
            $cases++;
            echo "CASE mismatched-replay-rejected passed\n";
        }
    }
    if ($selected === null) {
        foreach (['activation', 'wrong-plan', 'missing-recovery', 'linked-recovery', 'unsafe-recovery-mode'] as $failure) {
            $fixture = resumeFailureFixture($temporary . '/' . $failure, 'none');
            if ($failure === 'activation') {
                upgradeHostMutateState($fixture, static function (array &$state): void {
                    $state['activation_started'] = true;
                });
            } elseif ($failure === 'wrong-plan') {
                $record = json_decode((string) file_get_contents($fixture['runtime'] . '/recovery.json'), true, 512, JSON_THROW_ON_ERROR);
                $record['plan_sha256'] = 'sha256:' . str_repeat('f', 64);
                upgradeHostJson($fixture['runtime'] . '/recovery.json', $record);
            } elseif ($failure === 'linked-recovery') {
                link($fixture['runtime'] . '/recovery.json', $fixture['runtime'] . '/recovery-alias.json');
            } elseif ($failure === 'unsafe-recovery-mode') {
                chmod($fixture['runtime'] . '/recovery.json', 0644);
            } else {
                unlink($fixture['runtime'] . '/recovery.json');
            }
            $result = upgradeHostRunRecover($root, $fixture, 'resume-old');
            upgradeHostExpect($result['exit'] !== 0 && !is_file($fixture['docker_log']), 'invalid precondition started services');
            echo 'CASE ' . $failure . ' rejected-before-start' . PHP_EOL;
            $cases++;
        }
    }
} finally {
    upgradeHostRemove($temporary);
}
echo "PRODUCT-UPGRADE-HOST-RESUME-FAILURE passed ({$cases} cases; native host, explicit service sentinels, no database)\n";
