<?php
declare(strict_types=1);

// Direct real Dependencies::run / scoped doctor regression. Every tool command is
// replaced at the executable boundary by a temporary synthetic project-env.
// No package manager, real APP, database, shared service, Git or HTTP is invoked.
$tooling = getenv('PEANUT_TEST_TOOLING_ROOT') ?: dirname(__DIR__, 3);
$phpAutoload = $tooling . '/server/vendor/autoload.php';
if (!is_file($phpAutoload)) {
    throw new RuntimeException('MODULE_ENV_TEST_TOOLING_AUTOLOAD_REQUIRED');
}
require_once $phpAutoload;
require_once dirname(__DIR__, 2) . '/app/common/module/Dependencies.php';

function paEnvNeed(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException('MODULE_ENV_TEST_FAILED: ' . $message);
    }
}

function paEnvRemove(string $root): void
{
    if (!is_dir($root) || is_link($root)) {
        return;
    }
    foreach (new DirectoryIterator($root) as $item) {
        if ($item->isDot()) {
            continue;
        }
        $path = $item->getPathname();
        if ($item->isDir() && !$item->isLink()) {
            paEnvRemove($path);
        } else {
            unlink($path);
        }
    }
    rmdir($root);
}

function paEnvFixture(string $base, string $name, bool $v2, string $python): array
{
    $project = $base . '/' . $name;
    foreach ([$project, $project . '/server', $project . '/scripts', $project . '/tools'] as $path) {
        if (!mkdir($path, 0700) && !is_dir($path)) {
            throw new RuntimeException('MODULE_ENV_FIXTURE_CREATE_FAILED');
        }
    }
    if ($v2) {
        file_put_contents($project . '/tools/toolchain.json', json_encode([
            'schema_version' => 1, 'execution' => ['protocol' => 'peanut.project-env.v2'],
        ], JSON_THROW_ON_ERROR));
    } else {
        mkdir($project . '/.local/toolchain', 0700, true);
        file_put_contents($project . '/.local/toolchain/host.json', json_encode([
            'schema_version' => 1, 'commands' => ['python3' => [$python]],
        ], JSON_THROW_ON_ERROR));
    }
    $script = <<<'PY'
import json
import sys
from pathlib import Path

here = Path(__file__).resolve().parent
argv = sys.argv[1:]
with (here / 'calls.jsonl').open('a', encoding='utf-8') as output:
    output.write(json.dumps(argv) + '\n')
if 'doctor' in argv:
    if (here / 'reject-doctor').exists():
        print('simulated diagnostic failure', file=sys.stderr)
        raise SystemExit(7)
    print(json.dumps({'status': 'ok', 'writes': False, 'tools': {'php': {'version': 'synthetic'}}}))
elif 'run' in argv or 'exec' in argv:
    print('synthetic-native-ok')
elif 'prepare' in argv:
    print(json.dumps({'status': 'prepared'}))
else:
    print('unexpected project-env action', file=sys.stderr)
    raise SystemExit(11)
PY;
    file_put_contents($project . '/scripts/project-env', $script);
    return [$project, $project . '/server', $project . '/scripts/calls.jsonl'];
}

function paEnvCalls(string $trace): array
{
    if (!is_file($trace)) {
        return [];
    }
    return array_map(static fn(string $line): array => json_decode($line, true, 512, JSON_THROW_ON_ERROR),
        file($trace, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES));
}

$python = getenv('PEANUT_TEST_PYTHON3_PATH');
paEnvNeed(is_string($python) && str_starts_with($python, '/') && is_file($python),
    'explicitly selected test Python is required');
$base = sys_get_temp_dir() . '/pa-native-module-env-' . bin2hex(random_bytes(8));
if (!mkdir($base, 0700)) {
    throw new RuntimeException('MODULE_ENV_TEST_TEMP_CREATE_FAILED');
}
$beforePython = getenv('PEANUT_TOOL_PYTHON3');
try {
    [$v2, $server, $trace] = paEnvFixture($base, 'v2', true, $python);
    putenv('PEANUT_TOOL_PYTHON3=' . $python);
    $dependencies = new \app\common\module\Dependencies($server);
    $reflection = new ReflectionClass($dependencies);
    $invoke = $reflection->getMethod('run');
    $doctor = $reflection->getMethod('assertNativeToolchainReady');

    $result = $invoke->invoke($dependencies, ['composer', 'install', '--no-scripts'], $server);
    paEnvNeed($result['tool'] === 'composer'
        && $result['exit_code'] === 0
        && trim($result['output']) === 'synthetic-native-ok', 'v2 native command result');
    $records = paEnvCalls($trace);
    paEnvNeed(count($records) === 1 && in_array('run', $records[0], true)
        && !in_array('prepare', $records[0], true) && !in_array('exec', $records[0], true),
        'v2 must use only one native run instead of prepare plus exec');
    paEnvNeed(in_array('php,composer', $records[0], true)
        && in_array('--state-root', $records[0], true)
        && in_array('--root', $records[0], true)
        && in_array('--', $records[0], true), 'v2 tool scope and fixed input');

    $doctor->invoke($dependencies, ['web','platform'], true);
    $records = paEnvCalls($trace);
    paEnvNeed(count($records) === 2 && in_array('doctor', $records[1], true)
        && in_array('php,composer,node,pnpm,npm', $records[1], true),
        'one read-only scoped preflight for selected graph');
    file_put_contents($v2 . '/scripts/reject-doctor', '');
    try {
        $doctor->invoke($dependencies, ['web'], true);
        throw new RuntimeException('doctor failure was incorrectly accepted');
    } catch (\app\platform\exception\module\ModulePackageException $expected) {
        paEnvNeed($expected->errorCode === 'MODULE_DEPENDENCY_INSTALL_FAILED', 'preflight failure propagation');
    }
    paEnvNeed(!is_dir($v2 . '/.local'), 'read-only doctor must not create APP state');

    $validTraceCount = count(paEnvCalls($trace));
    putenv('PEANUT_TOOL_PYTHON3=/definitely/missing/explicit-python');
    $spawnWarnings = [];
    set_error_handler(static function (int $severity, string $message) use (&$spawnWarnings): bool {
        if (str_contains($message, 'proc_open(): posix_spawn() failed')) {
            $spawnWarnings[] = $message;
            return true;
        }
        return false;
    });
    try {
        try {
            $invoke->invoke($dependencies, ['php', '--version'], $server);
            throw new RuntimeException('invalid explicit Python fell back to ambient tools');
        } catch (\app\platform\exception\module\ModulePackageException $expected) {
            paEnvNeed(in_array($expected->errorCode, ['MODULE_DEPENDENCY_PROCESS_FAILED', 'MODULE_DEPENDENCY_INSTALL_FAILED'], true), 'explicit interpreter fails at spawn or native execution without fallback');
        }
    } finally {
        restore_error_handler();
    }
    paEnvNeed($spawnWarnings !== [] && count(paEnvCalls($trace)) === $validTraceCount,
        'bad explicit interpreter must fail at spawn without hidden fallback');
    putenv('PEANUT_TOOL_PYTHON3=' . $python);

    [$legacy, $legacyServer, $legacyTrace] = paEnvFixture($base, 'legacy', false, $python);
    $legacyResult = $invoke->invoke(new \app\common\module\Dependencies($legacyServer),
        ['composer', 'install', '--no-scripts'], $legacyServer);
    $calls = paEnvCalls($legacyTrace);
    paEnvNeed($legacyResult['exit_code'] === 0 && count($calls) === 2
        && in_array('prepare', $calls[0], true) && in_array('exec', $calls[1], true),
        'legacy explicit host still retains its prepare/exec compatibility');
    $invoke->invoke($dependencies, ['pnpm', '--version'], $server);
    $invoke->invoke($dependencies, ['npm', '--version'], $server);
    $nativeCalls = paEnvCalls($trace);
    paEnvNeed(in_array('node,pnpm', $nativeCalls[count($nativeCalls) - 2], true)
        && in_array('node,npm', $nativeCalls[count($nativeCalls) - 1], true),
        'v2 must request only the selected Node and native package manager scope');

    [$removal, $removalServer, $removalTrace] = paEnvFixture($base, 'removal', true, $python);
    file_put_contents($removalServer . '/composer.json', '{"require":{},"repositories":[]}');
    file_put_contents($removalServer . '/composer.lock', '{"packages":[],"packages-dev":[]}');
    file_put_contents($removal . '/scripts/reject-doctor', '');
    $composerBefore = file_get_contents($removalServer . '/composer.json');
    try {
        (new \app\common\module\Dependencies($removalServer))->remove([]);
        throw new RuntimeException('Module removal passed an explicitly rejected native doctor');
    } catch (\app\platform\exception\module\ModulePackageException $expected) {
        paEnvNeed($expected->errorCode === 'MODULE_DEPENDENCY_INSTALL_FAILED',
            'removal must fail at native doctor before selecting package-manager commands');
    }
    $removalCalls = paEnvCalls($removalTrace);
    paEnvNeed(count($removalCalls) === 1 && in_array('doctor', $removalCalls[0], true)
        && in_array('php,composer', $removalCalls[0], true)
        && file_get_contents($removalServer . '/composer.json') === $composerBefore,
        'removal preflight must run exactly once before changing Composer inputs');
    echo 'MODULE-ENV-V2-CONTRACT passed checks=10 v2-run=3 v2-preflight=1 legacy-prepare-exec=1 removal-prewrite=1 actual-dependency-installs=0' . PHP_EOL;
} finally {
    if ($beforePython === false) {
        putenv('PEANUT_TOOL_PYTHON3');
    } else {
        putenv('PEANUT_TOOL_PYTHON3=' . $beforePython);
    }
    paEnvRemove($base);
}
