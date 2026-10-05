<?php

declare(strict_types=1);

define('PEANUT_UPGRADE_HOST_FIXTURE_ONLY', true);
require __DIR__ . '/ProductUpgradeHostBackupBindingTest.php';

/** @return array<string,array<string,string>> */
function recoveryVerifyServices(string $status = 'running', bool $changedImage = false): array
{
    $services = [];
    foreach (['mysql' => 'a', 'pc' => 'b', 'php' => 'c', 'nginx' => 'd', 'cron' => 'e'] as $name => $char) {
        $imageChar = $changedImage && $name === 'php' ? 'f' : $char;
        $services[$name] = [
            'container_id' => str_repeat($char, 64),
            'image_id' => 'sha256:' . str_repeat($imageChar, 64),
            'status' => $status,
            'started_at' => '2026-09-28T00:00:00Z',
            'finished_at' => '2026-09-28T00:00:00Z',
            'restart_count' => '0',
        ];
    }

    return $services;
}

/** @return array<string,string> */
function recoveryVerifyFixture(string $directory, string $failure = 'none'): array
{
    $fixture = upgradeHostFixture($directory . '/fixture', 'valid', true);
    $runtime = substr($fixture['state'], 0, -5) . '.runtime';
    $fixture['runtime'] = $runtime;
    copy($runtime . '/previous/DEPLOYMENT_RECEIPT.json', $fixture['instance'] . '/DEPLOYMENT_RECEIPT.json');
    upgradeHostMutateState($fixture, static function (array &$state): void {
        $state['phase'] = 'recovered';
        $state['phase_in_progress'] = 'recovery-verify';
    });
    $services = recoveryVerifyServices();
    upgradeHostJson($runtime . '/recovery.json', [
        'schema_version' => 1,
        'protocol' => 'peanut.product-upgrade-host-recovery.v1',
        'status' => 'data-runtime-restored',
        'candidate' => 'm4-binding',
        'plan_sha256' => 'sha256:' . str_repeat('a', 64),
        'payload' => [
            'recovery' => [
                'kind' => 'database-public-private-storage-installation-and-program',
                'data_runtime_restored' => true,
                'writers_stopped' => true,
                'resume_required' => true,
                'backup_manifest_sha256' => 'sha256:' . hash_file('sha256', $fixture['backup'] . '/manifest.json'),
            ],
            'restored_resources' => [
                'database_name' => 'peanut_m4',
                'database_resource_id' => 'db-resource',
                'storage_volumes' => ['public' => 'fixture_php-storage', 'private' => 'fixture_php-private-storage'],
                'installation_volume' => 'fixture_php-installation',
            ],
            'services' => $services,
        ],
    ]);
    upgradeHostJson($runtime . '/resume-old.json', [
        'schema_version' => 1,
        'protocol' => 'peanut.product-upgrade-host-resume-old.v1',
        'status' => 'completed',
        'candidate' => 'm4-binding',
        'plan_sha256' => 'sha256:' . str_repeat('a', 64),
        'recovery_record_sha256' => 'sha256:' . hash_file('sha256', $runtime . '/recovery.json'),
        'runtime_receipt_sha256' => 'sha256:' . hash_file('sha256', $fixture['instance'] . '/DEPLOYMENT_RECEIPT.json'),
        'payload' => [
            'recovery' => ['resumed_old_runtime' => true, 'verified' => true],
            'compose_project' => 'fixture',
            'services' => $services,
            'installation_health' => [
                'tenant_count' => 2,
                'owner_count' => 2,
                'operator_count' => 1,
            ],
            'probes' => [
                'healthz' => true,
                'database_environment' => true,
                'plugin_lock' => true,
                'installation_status' => true,
                'receipt_binding' => true,
            ],
        ],
    ]);
    $sentinelDirectory = dirname($fixture['docker_log']);
    upgradeHostFile($sentinelDirectory . '/failure', $failure);
    upgradeHostJson($sentinelDirectory . '/services.json', array_fill_keys(['mysql', 'pc', 'php', 'nginx', 'cron'], 'running'));
    upgradeHostFile($fixture['fake_bin'] . '/docker', <<<'PY'
#!/usr/bin/env python3
import json, os, sys
from pathlib import Path
base = Path(os.environ['FAKE_DOCKER_LOG']).parent
args = sys.argv[1:]
mode = (base / 'failure').read_text()
state = json.loads((base / 'services.json').read_text())
ids = {name: char * 64 for name, char in zip(['mysql', 'pc', 'php', 'nginx', 'cron'], 'abcde')}
def log(kind):
    with (base / 'docker.log').open('a') as output: output.write(kind + '\n')
def reject():
    log('UNEXPECTED:' + ' '.join(args)); sys.exit(98)
if args == ['compose', 'version']:
    print('Docker Compose version v2.0.0'); sys.exit(0)
if args and args[0] == 'volume' and args[1:3] == ['inspect', 'fixture_php-storage']:
    if args[1:] != ['inspect', 'fixture_php-storage', 'fixture_php-private-storage', 'fixture_php-installation']: reject()
    sys.exit(74 if mode == 'volume' else 0)
if args and args[0] == 'ps':
    if any('project.working_dir=' in x for x in args): print('fixture')
    else:
        service = next((x.split('com.docker.compose.service=', 1)[1] for x in args if 'com.docker.compose.service=' in x), None)
        if service: print(ids[service])
    sys.exit(0)
if args and args[0] == 'inspect':
    name = next((name for name, value in ids.items() if value == args[-1]), None)
    if name is None: reject()
    status = 'exited' if mode == 'service' and name == 'nginx' else state[name]
    image_char = 'f' if mode == 'image' and name == 'php' else ids[name][0]
    values = {
        '{{.Id}}': ids[name],
        '{{.Image}}': 'sha256:' + image_char * 64,
        '{{.State.Status}}': status,
        '{{.State.StartedAt}}': '2026-09-28T00:00:00Z',
        '{{.State.FinishedAt}}': '2026-09-28T00:00:00Z',
        '{{.RestartCount}}': '0',
    }
    pattern = args[args.index('--format') + 1]
    if pattern not in values: reject()
    print(values[pattern]); sys.exit(0)
if args and args[0] == 'exec':
    if args[1:] != [ids['php'], 'cat', '/var/www/peanut-admin/DEPLOYMENT_RECEIPT.json']: reject()
    log('RECEIPT')
    print('{"mismatch":true}' if mode == 'receipt' else (base / 'instance/DEPLOYMENT_RECEIPT.json').read_text().strip())
    sys.exit(0)
if not args or args[0] != 'compose': reject()
index = next((i for i, arg in enumerate(args) if arg in ['config', 'up', 'stop', 'build', 'run', 'ps', 'exec']), None)
if index is None: reject()
command = args[index]; rest = args[index+1:]
if command in ['config', 'up', 'stop', 'build', 'run', 'ps']:
    reject()
if command == 'exec':
    joined = ' '.join(rest)
    if 'environment-guard.php' in joined: log('ENVIRONMENT'); sys.exit(64 if mode == 'environment' else 0)
    if 'plugin:lock' in joined: log('PLUGIN_LOCK'); sys.exit(65 if mode == 'plugin' else 0)
    if 'install.php --status' in joined:
        log('INSTALL_STATUS')
        if mode == 'install-command': sys.exit(66)
        if mode == 'bad-status': print('{"state":"uninstalled","health":{}}')
        elif mode == 'health-summary': print('{"state":"installed","health":{"tenant_count":3,"owner_count":2,"operator_count":1}}')
        else: print('{"state":"installed","health":{"tenant_count":2,"owner_count":2,"operator_count":1}}')
        sys.exit(0)
    reject()
reject()
PY, 0755);
    upgradeHostFile($fixture['fake_bin'] . '/curl', <<<'PY'
#!/usr/bin/env python3
import os, sys
from pathlib import Path
base = Path(os.environ['FAKE_DOCKER_LOG']).parent
with (base / 'docker.log').open('a') as log: log.write('HEALTH\n')
if sys.argv[-1] != 'http://127.0.0.1:8080/healthz': sys.exit(98)
sys.exit(67 if (base / 'failure').read_text() == 'health' else 0)
PY, 0755);

    return $fixture;
}

function recoveryVerifyRebindResume(array $fixture): void
{
    $runtime = $fixture['runtime'];
    $record = json_decode((string) file_get_contents($runtime . '/resume-old.json'), true, 512, JSON_THROW_ON_ERROR);
    $record['recovery_record_sha256'] = 'sha256:' . hash_file('sha256', $runtime . '/recovery.json');
    upgradeHostJson($runtime . '/resume-old.json', $record);
}

/** @return array<string,string> */
function recoveryVerifyProtectedDigests(array $fixture): array
{
    $paths = [
        $fixture['state'],
        $fixture['env'],
        $fixture['runtime'] . '/recovery.json',
        $fixture['runtime'] . '/resume-old.json',
        $fixture['instance'] . '/DEPLOYMENT_RECEIPT.json',
    ];
    $hashes = [];
    foreach ($paths as $path) {
        $hashes[$path] = is_file($path) ? hash_file('sha256', $path) : 'missing';
    }

    return $hashes;
}

function recoveryVerifyReject(string $root, array $fixture, string $label, string $message): void
{
    $before = recoveryVerifyProtectedDigests($fixture);
    $result = upgradeHostRunRecover($root, $fixture, 'recovery-verify');
    echo 'CASE ' . $label . ' exit=' . $result['exit'] . PHP_EOL;
    echo $result['output'];
    upgradeHostExpect(
        $result['exit'] !== 0 && str_contains($result['output'], $message),
        'wrong recovery-verify rejection for ' . $label . ': ' . $result['output'],
    );
    upgradeHostExpect(recoveryVerifyProtectedDigests($fixture) === $before, 'recovery-verify changed records for ' . $label);
    $calls = is_file($fixture['docker_log']) ? (string) file_get_contents($fixture['docker_log']) : '';
    upgradeHostExpect(!str_contains($calls, 'UNEXPECTED:'), 'refusal attempted an unapproved command: ' . $label);
}

$temporaryRoot = $root . '/.local/tmp/m4-terminal-verify-20260928';
if (!is_dir($temporaryRoot)) {
    mkdir($temporaryRoot, 0700, true);
}
upgradeHostExpect(!is_link($temporaryRoot) && realpath($temporaryRoot) === $temporaryRoot, 'unsafe temporary root');
$temporary = $temporaryRoot . '/run-' . bin2hex(random_bytes(5));
mkdir($temporary, 0700);
$cases = 0;
try {
    $valid = recoveryVerifyFixture($temporary . '/valid');
    $before = recoveryVerifyProtectedDigests($valid);
    $result = upgradeHostRunRecover($root, $valid, 'recovery-verify');
    echo 'CASE valid exit=' . $result['exit'] . PHP_EOL;
    echo $result['output'];
    upgradeHostExpect($result['exit'] === 0, 'valid recovery-verify failed: ' . $result['output']);
    $receipt = json_decode($result['output'], true, 512, JSON_THROW_ON_ERROR);
    upgradeHostExpect($receipt['phase'] === 'recovery-verify', 'wrong phase in recovery-verify receipt');
    upgradeHostExpect($receipt['payload']['recovery']['read_only'] === true, 'recovery-verify did not mark read-only');
    upgradeHostExpect(recoveryVerifyProtectedDigests($valid) === $before, 'valid recovery-verify rewrote records');
    $log = (string) file_get_contents($valid['docker_log']);
    upgradeHostExpect(!str_contains($log, 'UNEXPECTED:'), 'unexpected command during recovery-verify');
    $replay = upgradeHostRunRecover($root, $valid, 'recovery-verify');
    upgradeHostExpect($replay['exit'] === 0, 'repeat recovery-verify failed');
    upgradeHostExpect(recoveryVerifyProtectedDigests($valid) === $before, 'repeat recovery-verify rewrote records');
    $cases += 2;

    // Healthy business growth after writers resume is legitimate, not corruption.
    $growth = recoveryVerifyFixture($temporary . '/healthy-growth', 'health-summary');
    $growthBefore = recoveryVerifyProtectedDigests($growth);
    $growthResult = upgradeHostRunRecover($root, $growth, 'recovery-verify');
    upgradeHostExpect($growthResult['exit'] === 0, 'healthy tenant growth was rejected: ' . $growthResult['output']);
    upgradeHostExpect(recoveryVerifyProtectedDigests($growth) === $growthBefore, 'growth probe rewrote records');
    $cases++;
    echo "CASE healthy-growth accepted-read-only\n";

    $programOnly = recoveryVerifyFixture($temporary . '/program-only');
    upgradeHostMutateState($programOnly, static function (array &$state): void {
        $state['database_recovery_required'] = false;
        $state['evidence'] = new stdClass();
    });
    $programRecord = json_decode((string) file_get_contents($programOnly['runtime'] . '/recovery.json'), true, 512, JSON_THROW_ON_ERROR);
    $programRecord['payload']['recovery']['kind'] = 'program-only';
    $programRecord['payload']['recovery']['backup_manifest_sha256'] = null;
    upgradeHostJson($programOnly['runtime'] . '/recovery.json', $programRecord);
    recoveryVerifyRebindResume($programOnly);
    $programBefore = recoveryVerifyProtectedDigests($programOnly);
    $programResult = upgradeHostRunRecover($root, $programOnly, 'recovery-verify');
    upgradeHostExpect($programResult['exit'] === 0, 'program-only verification needs an unrelated data backup: ' . $programResult['output']);
    upgradeHostExpect(recoveryVerifyProtectedDigests($programOnly) === $programBefore, 'program-only probe rewrote records');
    $cases++;
    echo "CASE program-only accepted-read-only\n";

    foreach ([
        'wrong-terminal-phase' => ['terminal state', static function (array $fixture): void {
            upgradeHostMutateState($fixture, static function (array &$state): void {
                $state['phase'] = 'recovering';
            });
        }],
        'missing-recovery-record' => ['record is unavailable', static function (array $fixture): void {
            unlink($fixture['runtime'] . '/recovery.json');
        }],
        'linked-resume-record' => ['record is unavailable', static function (array $fixture): void {
            rename($fixture['runtime'] . '/resume-old.json', $fixture['runtime'] . '/resume-old.real.json');
            symlink($fixture['runtime'] . '/resume-old.real.json', $fixture['runtime'] . '/resume-old.json');
        }],
        'wide-recovery-record' => ['record is unavailable', static function (array $fixture): void {
            chmod($fixture['runtime'] . '/recovery.json', 0644);
        }],
        'wrong-recovery-candidate' => ['recovery record is invalid', static function (array $fixture): void {
            $record = json_decode((string) file_get_contents($fixture['runtime'] . '/recovery.json'), true, 512, JSON_THROW_ON_ERROR);
            $record['candidate'] = 'other-candidate';
            upgradeHostJson($fixture['runtime'] . '/recovery.json', $record);
            recoveryVerifyRebindResume($fixture);
        }],
        'wrong-recovery-protocol' => ['recovery record is invalid', static function (array $fixture): void {
            $record = json_decode((string) file_get_contents($fixture['runtime'] . '/recovery.json'), true, 512, JSON_THROW_ON_ERROR);
            $record['protocol'] = 'wrong';
            upgradeHostJson($fixture['runtime'] . '/recovery.json', $record);
            recoveryVerifyRebindResume($fixture);
        }],
        'wrong-recovery-summary' => ['recovery record is invalid', static function (array $fixture): void {
            $record = json_decode((string) file_get_contents($fixture['runtime'] . '/recovery.json'), true, 512, JSON_THROW_ON_ERROR);
            $record['payload']['recovery']['backup_manifest_sha256'] = 'sha256:' . str_repeat('0', 64);
            upgradeHostJson($fixture['runtime'] . '/recovery.json', $record);
            recoveryVerifyRebindResume($fixture);
        }],
        'wrong-resume-plan' => ['old runtime receipt is invalid', static function (array $fixture): void {
            $record = json_decode((string) file_get_contents($fixture['runtime'] . '/resume-old.json'), true, 512, JSON_THROW_ON_ERROR);
            $record['plan_sha256'] = 'sha256:' . str_repeat('f', 64);
            upgradeHostJson($fixture['runtime'] . '/resume-old.json', $record);
        }],
        'changed-env-database' => ['recovery record is invalid', static function (array $fixture): void {
            upgradeHostFile($fixture['env'], "DB_NAME=other_database\nPEANUT_DATABASE_RESOURCE_ID=db-resource\nHTTP_PORT=8080\n", 0600);
        }],
        'service-not-running' => ['services are not running', static function (array $fixture): void {
            upgradeHostFile(dirname($fixture['docker_log']) . '/failure', 'service');
        }],
        'volume-unavailable' => ['storage volumes are unavailable', static function (array $fixture): void {
            upgradeHostFile(dirname($fixture['docker_log']) . '/failure', 'volume');
        }],
        'image-changed' => ['runtime image identity changed', static function (array $fixture): void {
            upgradeHostFile(dirname($fixture['docker_log']) . '/failure', 'image');
        }],
        'environment-check-failed' => ['database_environment', static function (array $fixture): void {
            upgradeHostFile(dirname($fixture['docker_log']) . '/failure', 'environment');
        }],
        'plugin-check-failed' => ['plugin_lock', static function (array $fixture): void {
            upgradeHostFile(dirname($fixture['docker_log']) . '/failure', 'plugin');
        }],
        'installation-check-failed' => ['business state verification', static function (array $fixture): void {
            upgradeHostFile(dirname($fixture['docker_log']) . '/failure', 'bad-status');
        }],
        'receipt-mismatch' => ['receipt identity changed', static function (array $fixture): void {
            upgradeHostFile(dirname($fixture['docker_log']) . '/failure', 'receipt');
        }],
        'health-check-failed' => ['healthz', static function (array $fixture): void {
            upgradeHostFile(dirname($fixture['docker_log']) . '/failure', 'health');
        }],
    ] as $label => [$message, $mutate]) {
        $fixture = recoveryVerifyFixture($temporary . '/' . $label);
        $mutate($fixture);
        recoveryVerifyReject($root, $fixture, $label, $message);
        $cases++;
    }
} finally {
    upgradeHostRemove($temporary);
}

echo "PRODUCT-UPGRADE-HOST-RECOVERY-VERIFY passed ({$cases} cases; read-only terminal verification, service sentinels, no database)\n";
