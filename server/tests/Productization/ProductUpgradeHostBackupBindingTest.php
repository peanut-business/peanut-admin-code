<?php

declare(strict_types=1);

$root = dirname(__DIR__, 3);

function upgradeHostExpect(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function upgradeHostRemove(string $path): void
{
    if (is_dir($path) && !is_link($path)) {
        foreach (array_diff(scandir($path) ?: [], ['.', '..']) as $entry) {
            upgradeHostRemove($path . '/' . $entry);
        }
        rmdir($path);
        return;
    }
    if (file_exists($path) || is_link($path)) {
        unlink($path);
    }
}

function upgradeHostFile(string $path, string $contents, int $mode = 0600): void
{
    if (!is_dir(dirname($path))) {
        mkdir(dirname($path), 0700, true);
    }
    file_put_contents($path, $contents);
    chmod($path, $mode);
}

/** @param array<string,mixed> $data */
function upgradeHostJson(string $path, array $data, int $mode = 0600): void
{
    upgradeHostFile(
        $path,
        json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n",
        $mode,
    );
}

/** @param list<string> $command */
function upgradeHostCommand(array $command, ?array $environment = null, ?string $cwd = null): array
{
    $pipes = [];
    $process = proc_open(
        $command,
        [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['redirect', 1]],
        $pipes,
        $cwd,
        $environment,
    );
    upgradeHostExpect(is_resource($process), 'cannot start command: ' . implode(' ', $command));
    $output = stream_get_contents($pipes[1]);
    fclose($pipes[1]);

    return ['exit' => proc_close($process), 'output' => is_string($output) ? $output : ''];
}

function upgradeHostTar(string $source, string $archive): void
{
    $result = upgradeHostCommand(['tar', '-C', $source, '-czf', $archive, '.']);
    upgradeHostExpect($result['exit'] === 0, 'tar fixture failed: ' . $result['output']);
    chmod($archive, 0600);
}

/** @return array<string,string> */
function upgradeHostFixture(string $temporary, string $archiveKind, bool $withBackupEvidence): array
{
    $instance = $temporary . '/instance';
    $package = $temporary . '/package';
    $candidate = 'm4-binding';
    $planSha = 'sha256:' . str_repeat('a', 64);
    $sourceCommit = str_repeat('b', 40);
    $sourceTree = str_repeat('c', 40);
    $state = $instance . '/.peanut/upgrades/product-state/' . $candidate . '.json';
    $plan = $instance . '/.peanut/upgrades/product-plans/' . $candidate . '.json';
    $runtime = substr($state, 0, -5) . '.runtime';
    $backup = $instance . '/backups/product-upgrade-' . $candidate;
    $publicVolume = $temporary . '/volumes/public';
    $privateVolume = $temporary . '/volumes/private';
    $fakeBin = $temporary . '/bin';
    $dockerLog = $temporary . '/docker.log';

    mkdir($backup, 0700, true);
    mkdir($package . '/META-INF', 0700, true);
    mkdir($runtime . '/previous', 0700, true);
    mkdir($publicVolume, 0700, true);
    mkdir($privateVolume, 0700, true);
    mkdir($fakeBin, 0700, true);
    upgradeHostFile($publicVolume . '/before.txt', 'original-public');
    upgradeHostFile($privateVolume . '/before.txt', 'original-private');

    upgradeHostFile($instance . '/.env', "DB_NAME=peanut_m4\nPEANUT_DATABASE_RESOURCE_ID=db-resource\nHTTP_PORT=8080\n", 0600);
    upgradeHostFile($runtime . '/previous/root.env', (string) file_get_contents($instance . '/.env'), 0600);
    upgradeHostJson($runtime . '/previous/DEPLOYMENT_RECEIPT.json', ['receipt' => 'previous'], 0600);
    upgradeHostJson($package . '/upgrade-manifest.json', [
        'schema_version' => 1,
        'protocol' => 'peanut.edition-upgrade-package.v1',
        'target' => ['version' => '4.0.0-dev.1'],
        'build_source' => ['commit' => $sourceCommit, 'tree' => $sourceTree],
        'edition' => ['name' => 'standalone'],
    ]);
    upgradeHostFile($package . '/META-INF/files.sha256', "fixture\0sha256\n", 0600);
    $packageManifestSha = hash_file('sha256', $package . '/upgrade-manifest.json');
    $inventorySha = hash_file('sha256', $package . '/META-INF/files.sha256');
    upgradeHostJson($plan, [
        'schema_version' => 2,
        'protocol' => 'peanut.product-upgrade-plan.v2',
        'candidate' => $candidate,
        'instance_root' => $instance,
        'package' => [
            'root' => $package,
            'inventory_sha256' => 'sha256:' . $inventorySha,
            'manifest_sha256' => 'sha256:' . $packageManifestSha,
            'target_version' => '4.0.0-dev.1',
            'source_commit' => $sourceCommit,
            'source_tree' => $sourceTree,
        ],
        'edition' => ['name' => 'standalone'],
        'plan_sha256' => $planSha,
        'state_path' => $state,
    ], 0600);

    upgradeHostFile($backup . '/database.sql.gz', gzencode("CREATE TABLE restored(id int);\n"), 0600);
    $publicSource = $temporary . '/storage-public-source';
    $privateSource = $temporary . '/storage-private-source';
    mkdir($publicSource, 0700);
    mkdir($privateSource, 0700);
    upgradeHostFile($publicSource . '/file.txt', "public\n", 0600);
    upgradeHostFile($publicSource . '/nested/name with spaces.txt', "nested-public\n", 0600);
    upgradeHostFile($privateSource . '/secret.txt', "private\n", 0600);
    if ($archiveKind === 'symlink') {
        symlink('/tmp/peanut-m4-should-not-be-linked', $publicSource . '/linked');
    }
    upgradeHostTar($publicSource, $backup . '/php-storage.tar.gz');
    upgradeHostTar($privateSource, $backup . '/php-private-storage.tar.gz');
    $databaseSha = hash_file('sha256', $backup . '/database.sql.gz');
    $publicSha = hash_file('sha256', $backup . '/php-storage.tar.gz');
    $privateSha = hash_file('sha256', $backup . '/php-private-storage.tar.gz');
    upgradeHostJson($backup . '/manifest.json', [
        'schema_version' => 1,
        'protocol' => 'peanut.product-upgrade-backup.v1',
        'candidate' => $candidate,
        'plan_sha256' => $planSha,
        'runtime' => [
            'database_name' => 'peanut_m4',
            'storage_volumes' => ['public' => 'fixture_php-storage', 'private' => 'fixture_php-private-storage'],
            'quiesced_services' => new stdClass(),
        ],
        'artifacts' => [
            'database' => ['filename' => 'database.sql.gz', 'sha256' => 'sha256:' . $databaseSha],
            'storage' => [
                'public' => ['filename' => 'php-storage.tar.gz', 'sha256' => 'sha256:' . $publicSha],
                'private' => ['filename' => 'php-private-storage.tar.gz', 'sha256' => 'sha256:' . $privateSha],
            ],
        ],
        'created_at' => '2026-09-28T00:00:00Z',
    ], 0600);
    $manifestSha = hash_file('sha256', $backup . '/manifest.json');
    upgradeHostFile($backup . '/SHA256SUMS', implode('', [
        $databaseSha . "  database.sql.gz\n",
        $publicSha . "  php-storage.tar.gz\n",
        $privateSha . "  php-private-storage.tar.gz\n",
        $manifestSha . "  manifest.json\n",
    ]), 0600);

    $evidence = [];
    if ($withBackupEvidence) {
        $evidence['backup'] = [
            'sha256' => 'sha256:' . str_repeat('d', 64),
            'payload' => [
                'protocol' => 'peanut.product-upgrade-host-evidence.v1',
                'status' => 'completed',
                'phase' => 'backup',
                'candidate' => $candidate,
                'plan_sha256' => $planSha,
                'package_manifest_sha256' => 'sha256:' . $packageManifestSha,
                'payload' => [
                    'backup' => [
                        'kind' => 'database-and-storage-volumes',
                        'manifest_path' => $backup . '/manifest.json',
                        'manifest_sha256' => 'sha256:' . $manifestSha,
                        'database' => ['path' => $backup . '/database.sql.gz', 'sha256' => 'sha256:' . $databaseSha],
                        'storage' => [
                            'public' => ['path' => $backup . '/php-storage.tar.gz', 'sha256' => 'sha256:' . $publicSha],
                            'private' => ['path' => $backup . '/php-private-storage.tar.gz', 'sha256' => 'sha256:' . $privateSha],
                        ],
                    ],
                    'resources' => ['database_resource_id' => 'db-resource', 'compose_project' => 'fixture'],
                ],
            ],
        ];
    }
    upgradeHostJson($state, [
        'schema_version' => 1,
        'protocol' => 'peanut.product-upgrade-state.v1',
        'candidate' => $candidate,
        'plan_sha256' => $planSha,
        'phase' => 'recovering',
        'phase_in_progress' => 'recover',
        'database_recovery_required' => true,
        'migration_completed' => false,
        'activation_started' => false,
        'evidence' => (object) $evidence,
    ], 0600);

    upgradeHostFile($fakeBin . '/curl', "#!/usr/bin/env bash\nexit 0\n", 0755);
    upgradeHostFile($fakeBin . '/stat', <<<'PHP'
#!/usr/bin/env php
<?php
if (($argv[1] ?? null) === '-c' && (($argv[2] ?? null) === '%a %h' || ($argv[2] ?? null) === '%h') && isset($argv[3])) {
    $stat = lstat($argv[3]);
    if ($stat === false) {
        exit(1);
    }
    if ($argv[2] === '%h') {
        printf("%d\n", $stat['nlink']);
        exit(0);
    }
    printf("%o %d\n", $stat['mode'] & 0777, $stat['nlink']);
    exit(0);
}
exit(1);
PHP, 0755);
    upgradeHostFile($fakeBin . '/docker', <<<'BASH'
#!/usr/bin/env bash
set -euo pipefail
if [[ "${1:-}" == "compose" && "${2:-}" == "version" ]]; then
  echo "Docker Compose version v2.0.0"
  exit 0
fi
if [[ "${1:-}" == "ps" ]]; then
  if printf '%s\n' "$@" | grep -q 'com.docker.compose.project.working_dir'; then
    echo fixture
  fi
  exit 0
fi
if [[ "${1:-}" == "volume" && "${2:-}" == "inspect" ]]; then
  last="${@: -1}"
  case "$last" in
    fixture_php-storage) echo "$VOL_PUBLIC" ;;
    fixture_php-private-storage) echo "$VOL_PRIVATE" ;;
    *) exit 1 ;;
  esac
  exit 0
fi
subcommand=""
for argument in "$@"; do
  case "$argument" in
    stop|ps|exec|up) subcommand="$argument"; break ;;
  esac
done
case "$subcommand" in
  stop)
    echo STOP "$@" >>"$FAKE_DOCKER_LOG"
    exit 0
    ;;
  ps)
    echo mysql
    exit 0
    ;;
  exec)
    if printf '%s\n' "$@" | grep -q 'DROP DATABASE'; then
      echo DROP_DATABASE >>"$FAKE_DOCKER_LOG"
    else
      echo EXEC "$@" >>"$FAKE_DOCKER_LOG"
    fi
    cat >/dev/null || true
    exit 0
    ;;
  up)
    echo UP "$@" >>"$FAKE_DOCKER_LOG"
    exit 0
    ;;
esac
exit 0
BASH, 0755);

    return [
        'backup' => $backup,
        'instance' => $instance,
        'package' => $package,
        'plan' => $plan,
        'state' => $state,
        'env' => $instance . '/.env',
        'fake_bin' => $fakeBin,
        'docker_log' => $dockerLog,
        'public_volume' => $publicVolume,
        'private_volume' => $privateVolume,
    ];
}

function upgradeHostRunRecover(string $root, array $fixture, string $phase = 'recover'): array
{
    $path = $fixture['fake_bin'] . PATH_SEPARATOR . (getenv('PATH') ?: '');

    return upgradeHostCommand(
        [
            $root . '/scripts/product-upgrade-host',
            $phase,
            '--instance-root=' . $fixture['instance'],
            '--package=' . $fixture['package'],
            '--plan=' . $fixture['plan'],
            '--state=' . $fixture['state'],
        ],
        [
            'PATH' => $path,
            'PEANUT_SERVER_ENV_FILE' => $fixture['env'],
            'FAKE_DOCKER_LOG' => $fixture['docker_log'],
            'VOL_PUBLIC' => $fixture['public_volume'],
            'VOL_PRIVATE' => $fixture['private_volume'],
            'TMPDIR' => getenv('TMPDIR') ?: sys_get_temp_dir(),
        ],
        $root,
    );
}

/** Rebind fixture bytes only; preserve old state unless explicitly requested. */
function upgradeHostFixtureChecksums(array $fixture, bool $bindState = false): void
{
    $backup = $fixture['backup'];
    $lines = '';
    foreach (['database.sql.gz', 'php-storage.tar.gz', 'php-private-storage.tar.gz', 'manifest.json'] as $name) {
        $lines .= hash_file('sha256', $backup . '/' . $name) . '  ' . $name . "\n";
    }
    upgradeHostFile($backup . '/SHA256SUMS', $lines);
    if ($bindState) {
        $state = json_decode((string) file_get_contents($fixture['state']), true, 512, JSON_THROW_ON_ERROR);
        $binding = &$state['evidence']['backup']['payload']['payload']['backup'];
        $binding['manifest_sha256'] = 'sha256:' . hash_file('sha256', $backup . '/manifest.json');
        $binding['database']['sha256'] = 'sha256:' . hash_file('sha256', $backup . '/database.sql.gz');
        $binding['storage']['public']['sha256'] = 'sha256:' . hash_file('sha256', $backup . '/php-storage.tar.gz');
        $binding['storage']['private']['sha256'] = 'sha256:' . hash_file('sha256', $backup . '/php-private-storage.tar.gz');
        upgradeHostJson($fixture['state'], $state);
    }
}

function upgradeHostMutateManifest(array $fixture, callable $mutate, bool $bindState = true): void
{
    $path = $fixture['backup'] . '/manifest.json';
    $data = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
    $mutate($data);
    $data['runtime']['quiesced_services'] = (object) $data['runtime']['quiesced_services'];
    upgradeHostJson($path, $data);
    upgradeHostFixtureChecksums($fixture, $bindState);
}

function upgradeHostMutateState(array $fixture, callable $mutate): void
{
    $data = json_decode((string) file_get_contents($fixture['state']), true, 512, JSON_THROW_ON_ERROR);
    $mutate($data);
    upgradeHostJson($fixture['state'], $data);
}

/** Make real hostile tar headers without extracting any file. */
function upgradeHostArchiveEntries(array $fixture, array $entries): void
{
    $python = <<<'PY'
import io,json,sys,tarfile
with tarfile.open(sys.argv[1], 'w:gz', format=tarfile.USTAR_FORMAT) as archive:
    for entry in json.loads(sys.argv[2]):
        item=tarfile.TarInfo(entry['name']); item.mode=0o600
        kind=entry.get('kind','file')
        if kind=='file':
            content=b'fixture'; item.size=len(content); archive.addfile(item,io.BytesIO(content))
        else:
            item.type={'symlink':tarfile.SYMTYPE,'hardlink':tarfile.LNKTYPE,'fifo':tarfile.FIFOTYPE}[kind]
            item.linkname=entry.get('target',''); archive.addfile(item)
PY;
    $archive = $fixture['backup'] . '/php-storage.tar.gz';
    $result = upgradeHostCommand(['python3', '-c', $python, $archive, json_encode($entries, JSON_THROW_ON_ERROR)]);
    upgradeHostExpect($result['exit'] === 0, 'hostile archive fixture generation failed: ' . $result['output']);
    chmod($archive, 0600);
    upgradeHostMutateManifest($fixture, static function (array &$data) use ($archive): void {
        $data['artifacts']['storage']['public']['sha256'] = 'sha256:' . hash_file('sha256', $archive);
    });
}

function upgradeHostReject(string $root, array $fixture, string $message): void
{
    $result = upgradeHostRunRecover($root, $fixture);
    upgradeHostExpect(
        $result['exit'] !== 0 && str_contains($result['output'], $message),
        'wrong failure for ' . $message . ': ' . $result['output'],
    );
    upgradeHostExpect(!is_file($fixture['docker_log']), 'rejected backup reached a Docker write');
    upgradeHostExpect(
        file_get_contents($fixture['public_volume'] . '/before.txt') === 'original-public',
        'rejected backup altered public files',
    );
    upgradeHostExpect(
        file_get_contents($fixture['private_volume'] . '/before.txt') === 'original-private',
        'rejected backup altered private files',
    );
}

// Reuse these concrete file fixtures without rerunning the accepted backup suite.
if (defined('PEANUT_UPGRADE_HOST_FIXTURE_ONLY') && PEANUT_UPGRADE_HOST_FIXTURE_ONLY) {
    return;
}

$temporaryRoot = $root . '/.local/tmp/m4-backup-binding-20260928';
if (!is_dir($temporaryRoot)) {
    mkdir($temporaryRoot, 0775, true);
}
$temporary = $temporaryRoot . '/host-backup-binding-' . bin2hex(random_bytes(4));
mkdir($temporary, 0700);

$cases = 0;
try {
    foreach ([
        ['recover'],
        ['recover', '--instance-root=/x', '--instance-root=/x', '--plan=/x', '--state=/x'],
        ['recover', '--instance-root=relative', '--package=/x', '--plan=/x', '--state=/x'],
        ['invalid-phase', '--instance-root=/x', '--package=/x', '--plan=/x', '--state=/x'],
    ] as $invalidArguments) {
        $invalid = upgradeHostCommand([$root . '/scripts/product-upgrade-host', ...$invalidArguments]);
        upgradeHostExpect(
            $invalid['exit'] === 64 && str_contains($invalid['output'], 'Usage:'),
            'invalid CLI arguments were not rejected at parsing',
        );
        $cases++;
    }
    $initialBackup = upgradeHostFixture($temporary . '/backup-no-state-receipt', 'valid', false);
    upgradeHostMutateState($initialBackup, static function (array &$state): void {
        $state['phase_in_progress'] = 'backup';
        $state['evidence'] = new stdClass();
    });
    $created = upgradeHostRunRecover($root, $initialBackup, 'backup');
    upgradeHostExpect($created['exit'] === 0, 'fresh backup incorrectly requires its own future receipt: ' . $created['output']);
    $payload = json_decode($created['output'], true, 512, JSON_THROW_ON_ERROR);
    upgradeHostExpect($payload['phase'] === 'backup' && $payload['payload']['backup']['kind'] === 'database-and-storage-volumes', 'backup response contract changed');
    upgradeHostExpect(!is_file($initialBackup['docker_log']), 'backup receipt verification altered resources');
    $cases++;

    $missingReceipt = upgradeHostFixture($temporary . '/missing-receipt', 'valid', false);
    upgradeHostReject($root, $missingReceipt, 'state backup evidence');
    $cases++;

    $unsafeArchive = upgradeHostFixture($temporary . '/unsafe-archive', 'symlink', true);
    upgradeHostReject($root, $unsafeArchive, 'links or special files');
    $cases++;

    $mutations = [
        'checksum-missing' => ['omits a required artifact', static function (array $f): void {
            $p = $f['backup'] . '/SHA256SUMS';
            $lines = file($p);
            array_pop($lines);
            file_put_contents($p, implode('', $lines));
        }],
        'checksum-duplicate' => ['duplicate entry', static function (array $f): void {
            $p = $f['backup'] . '/SHA256SUMS';
            file_put_contents($p, file($p)[0], FILE_APPEND);
        }],
        'checksum-external' => ['unsafe entry', static function (array $f): void {
            file_put_contents($f['backup'] . '/SHA256SUMS', str_repeat('0', 64) . "  ../other\n", FILE_APPEND);
        }],
        'unrecorded-bytes' => ['differs from actual backup bytes', static function (array $f): void {
            file_put_contents($f['backup'] . '/database.sql.gz', gzencode('new different data'));
        }],
        'resealed-with-stale-state' => ['state backup evidence', static function (array $f): void {
            file_put_contents($f['backup'] . '/database.sql.gz', gzencode('new different data'));
            upgradeHostMutateManifest($f, static function (array &$m) use ($f): void {
                $m['artifacts']['database']['sha256'] = 'sha256:' . hash_file('sha256', $f['backup'] . '/database.sql.gz');
            }, false);
        }],
        'wrong-database' => ['runtime identity is invalid', static function (array $f): void {
            upgradeHostMutateManifest($f, static function (array &$m): void {
                $m['runtime']['database_name'] = 'other_database';
            });
        }],
        'wrong-volume' => ['runtime identity is invalid', static function (array $f): void {
            upgradeHostMutateManifest($f, static function (array &$m): void {
                $m['runtime']['storage_volumes']['private'] = 'other_private';
            });
        }],
        'wrong-plan' => ['bound to another upgrade', static function (array $f): void {
            upgradeHostMutateManifest($f, static function (array &$m): void {
                $m['plan_sha256'] = 'sha256:' . str_repeat('0', 64);
            });
        }],
        'manifest-digest-mismatch' => ['bound to another upgrade', static function (array $f): void {
            upgradeHostMutateManifest($f, static function (array &$m): void {
                $m['artifacts']['storage']['private']['sha256'] = 'sha256:' . str_repeat('0', 64);
            });
        }],
        'wrong-package-binding' => ['state backup evidence', static function (array $f): void {
            upgradeHostMutateState($f, static function (array &$s): void {
                $s['evidence']['backup']['payload']['package_manifest_sha256'] = 'sha256:' . str_repeat('0', 64);
            });
        }],
        'wrong-manifest-path' => ['state backup evidence', static function (array $f): void {
            upgradeHostMutateState($f, static function (array &$s): void {
                $s['evidence']['backup']['payload']['payload']['backup']['manifest_path'] = '/wrong/manifest.json';
            });
        }],
        'after-activation' => ['forbidden after activation', static function (array $f): void {
            upgradeHostMutateState($f, static function (array &$s): void {
                $s['activation_started'] = true;
            });
        }],
        'backup-hardlink' => ['linked or non-regular', static function (array $f): void {
            link($f['backup'] . '/database.sql.gz', $f['instance'] . '/linked.sql.gz');
        }],
        'backup-symlink' => ['linked or non-regular', static function (array $f): void {
            $p = $f['backup'] . '/database.sql.gz';
            rename($p, $p . '.real');
            symlink($p . '.real', $p);
        }],
        'unsafe-backup-mode' => ['linked or non-regular', static function (array $f): void {
            chmod($f['backup'] . '/database.sql.gz', 0666);
        }],
    ];
    foreach ($mutations as $label => [$expected, $mutate]) {
        $fixture = upgradeHostFixture($temporary . '/' . $label, 'valid', true);
        $mutate($fixture);
        echo 'CASE ' . $label . PHP_EOL;
        upgradeHostReject($root, $fixture, $expected);
        $cases++;
    }
    foreach ([
        'parent' => [['name' => '../outside']],
        'absolute' => [['name' => $temporary . '/absolute']],
        'duplicate' => [['name' => './same'], ['name' => './same']],
        'alias-duplicate' => [['name' => './same'], ['name' => 'same']],
        'double-slash' => [['name' => 'dir//file']],
        'dot-segment' => [['name' => 'dir/./file']],
        'escaped-name' => [['name' => 'back\\slash']],
        'hardlink' => [['name' => 'link', 'kind' => 'hardlink', 'target' => '../outside']],
        'fifo' => [['name' => 'pipe', 'kind' => 'fifo']],
    ] as $label => $entries) {
        $fixture = upgradeHostFixture($temporary . '/archive-' . $label, 'valid', true);
        upgradeHostArchiveEntries($fixture, $entries);
        echo 'CASE archive-' . $label . PHP_EOL;
        upgradeHostReject($root, $fixture, in_array($label, ['hardlink', 'fifo'], true) ? 'links or special files' : 'unsafe, duplicate or ambiguous paths');
        $cases++;
    }

    $valid = upgradeHostFixture($temporary . '/valid', 'valid', true);
    $result = upgradeHostRunRecover($root, $valid);
    upgradeHostExpect($result['exit'] === 0, 'valid bound backup was rejected: ' . $result['output']);
    $log = (string) file_get_contents($valid['docker_log']);
    upgradeHostExpect(str_contains($log, 'DROP_DATABASE'), 'valid backup did not reach database sentinel');
    upgradeHostExpect(file_get_contents($valid['public_volume'] . '/file.txt') === "public\n", 'public storage was not restored');
    upgradeHostExpect(file_get_contents($valid['public_volume'] . '/nested/name with spaces.txt') === "nested-public\n", 'legitimate nested path was rejected');
    upgradeHostExpect(file_get_contents($valid['private_volume'] . '/secret.txt') === "private\n", 'private storage was not restored');
    upgradeHostExpect(!file_exists($valid['public_volume'] . '/before.txt') && !file_exists($valid['private_volume'] . '/before.txt'), 'pre-restore fixture contents remain');
    $cases++;
    $again = upgradeHostRunRecover($root, $valid);
    upgradeHostExpect($again['exit'] === 0, 'recorded repeat recovery rejected');
    upgradeHostExpect(file_get_contents($valid['docker_log']) === $log, 'repeat recovery re-imported or restarted writers');
    $cases++;
} finally {
    upgradeHostRemove($temporary);
}

echo "PRODUCT-UPGRADE-HOST-BACKUP-BINDING passed ({$cases} cases; real file archives, database/Compose sentinels only)\n";
