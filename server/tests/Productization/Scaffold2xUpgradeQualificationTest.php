<?php

declare(strict_types=1);

function scaffold2xExpect(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

/** @param list<string> $command */
function scaffold2xRun(array $command, ?string $cwd = null): string
{
    $pipes = [];
    $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $cwd);
    if (!is_resource($process)) {
        throw new RuntimeException('unable to start command');
    }
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exitCode = proc_close($process);
    if ($exitCode !== 0) {
        throw new RuntimeException('command failed (' . $exitCode . '): ' . trim((string) $stderr));
    }
    return (string) $stdout;
}

function scaffold2xDelete(string $path): void
{
    if (is_dir($path) && !is_link($path)) {
        foreach (array_diff(scandir($path) ?: [], ['.', '..']) as $entry) {
            scaffold2xDelete($path . '/' . $entry);
        }
        rmdir($path);
        return;
    }
    if (file_exists($path) || is_link($path)) {
        unlink($path);
    }
}

function scaffold2xTreeDigest(string $root): string
{
    $rows = [];
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST,
    );
    foreach ($iterator as $entry) {
        $relative = str_replace('\\', '/', substr($entry->getPathname(), strlen($root) + 1));
        if ($relative === '.peanut/upgrades' || str_starts_with($relative, '.peanut/upgrades/')) {
            continue;
        }
        $rows[] = ($entry->isDir() ? 'd' : 'f') . "\0" . $relative . "\0"
            . ($entry->isFile() ? hash_file('sha256', $entry->getPathname()) : '-') . "\0"
            . ($entry->getPerms() & 0777);
    }
    sort($rows, SORT_STRING);
    return hash('sha256', implode("\n", $rows));
}

$root = dirname(__DIR__, 3);
$fromManifestPath = $root . '/scaffold/releases/v2.0.0/scaffold-manifest.json';
$toManifestPath = $root . '/scaffold/releases/v2.0.1/scaffold-manifest.json';
$fromManifest = json_decode((string) file_get_contents($fromManifestPath), true, 512, JSON_THROW_ON_ERROR);
$toManifest = json_decode((string) file_get_contents($toManifestPath), true, 512, JSON_THROW_ON_ERROR);
scaffold2xExpect(($fromManifest['release']['version'] ?? null) === '2.0.0', '2.0.0 release manifest is unavailable');
scaffold2xExpect(($toManifest['release']['version'] ?? null) === '2.0.1', '2.0.1 release manifest is unavailable');

$temporaryRoot = realpath(sys_get_temp_dir());
scaffold2xExpect(is_string($temporaryRoot), 'temporary root must resolve to a real path');
$temporary = $temporaryRoot . '/peanut-scaffold-2x-' . bin2hex(random_bytes(8));
$source = $temporary . '/source';
$application = $temporary . '/application';
mkdir($temporary, 0700, true);

try {
    scaffold2xRun(['git', 'clone', '--quiet', '--no-local', '--no-checkout', $root, $source]);
    scaffold2xRun(
        ['git', 'checkout', '--quiet', '--detach', (string) $fromManifest['release']['source_commit']],
        $source,
    );
    $creatorCode = <<<'PHP'
require $argv[1] . '/server/app/common/service/scaffold/ScaffoldPathGuard.php';
require $argv[1] . '/server/app/common/service/scaffold/ScaffoldManifest.php';
require $argv[1] . '/server/app/common/service/scaffold/ApplicationCreator.php';
$release = json_decode((string)file_get_contents($argv[2]), true, 512, JSON_THROW_ON_ERROR)['release'];
$creator = new app\common\service\scaffold\ApplicationCreator(
    $argv[1],
    $argv[1] . '/scaffold/application-template-inventory.json',
    ['commit' => $release['source_commit'], 'tree' => $release['source_tree']],
    $argv[2]
);
$creator->create('Upgrade Qualification', 'upgrade-qualification', 'qualification/upgrade-qualification', $argv[3]);
PHP;
    scaffold2xRun(['php', '-r', $creatorCode, $source, $fromManifestPath, $application]);

    $applicationManifestPath = $application . '/.peanut/application-manifest.json';
    $applicationManifest = json_decode(
        (string) file_get_contents($applicationManifestPath),
        true,
        512,
        JSON_THROW_ON_ERROR,
    );
    scaffold2xExpect(
        ($applicationManifest['template']['version'] ?? null) === '2.0.0',
        'create-app did not adopt the immutable 2.0.0 scaffold release',
    );

    $appOwnedPath = $application . '/server/config/peanut.php';
    file_put_contents($appOwnedPath, (string) file_get_contents($appOwnedPath) . "\n// downstream app-owned proof\n");
    $appOwnedDigest = hash_file('sha256', $appOwnedPath);
    $before = scaffold2xTreeDigest($application);

    $preflight = json_decode(scaffold2xRun([
        PHP_BINARY,
        $root . '/scripts/scaffold-upgrade',
        'preflight',
        '--project-root=' . $application,
        '--from-manifest=' . $fromManifestPath,
        '--to-manifest=' . $toManifestPath,
    ]), true, 512, JSON_THROW_ON_ERROR);
    scaffold2xExpect(
        ($preflight['status'] ?? null) === 'ready' && ($preflight['summary']['conflicts'] ?? null) === 0,
        '2.0.0 to 2.0.1 preflight is not ready',
    );
    $automatic = array_values(array_filter(
        $preflight['actions'] ?? [],
        static fn(array $action): bool => in_array(
            $action['action'] ?? null,
            ['create', 'delete', 'replace', 'regenerate'],
            true,
        ),
    ));
    scaffold2xExpect($automatic !== [], '2.0.0 to 2.0.1 qualification did not exercise managed changes');

    $planPath = $application . '/' . $preflight['plan_path'];
    $apply = json_decode(scaffold2xRun([
        PHP_BINARY,
        $root . '/scripts/scaffold-upgrade',
        'apply',
        '--project-root=' . $application,
        '--plan=' . $planPath,
    ]), true, 512, JSON_THROW_ON_ERROR);
    $verify = json_decode(scaffold2xRun([
        PHP_BINARY,
        $root . '/scripts/scaffold-upgrade',
        'verify',
        '--project-root=' . $application,
        '--plan=' . $planPath,
    ]), true, 512, JSON_THROW_ON_ERROR);
    scaffold2xExpect(
        ($apply['status'] ?? null) === 'applied' && ($verify['status'] ?? null) === 'verified',
        '2.x apply and verify did not complete',
    );
    scaffold2xExpect(
        hash_equals((string) $appOwnedDigest, (string) hash_file('sha256', $appOwnedPath)),
        '2.x upgrade changed app-owned bytes',
    );
    $upgradedManifest = json_decode(
        (string) file_get_contents($applicationManifestPath),
        true,
        512,
        JSON_THROW_ON_ERROR,
    );
    scaffold2xExpect(
        ($upgradedManifest['template']['version'] ?? null) === '2.0.1'
            && ($upgradedManifest['last_scaffold_upgrade']['from'] ?? null) === '2.0.0'
            && ($upgradedManifest['last_scaffold_upgrade']['to'] ?? null) === '2.0.1',
        '2.x application manifest did not record the target release',
    );

    $recover = json_decode(scaffold2xRun([
        PHP_BINARY,
        $root . '/scripts/scaffold-upgrade',
        'recover',
        '--project-root=' . $application,
        '--plan=' . $planPath,
    ]), true, 512, JSON_THROW_ON_ERROR);
    scaffold2xExpect(
        ($recover['status'] ?? null) === 'recovered' && hash_equals($before, scaffold2xTreeDigest($application)),
        '2.x recover did not restore the exact pre-upgrade application tree',
    );
} finally {
    scaffold2xDelete($temporary);
}

echo "SCAFFOLD-2X-UPGRADE-QUALIFICATION-001 passed\n";
