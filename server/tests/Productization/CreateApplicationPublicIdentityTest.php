<?php

declare(strict_types=1);

function publicIdentityExpect(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

/** @param list<string> $command */
function publicIdentityRun(array $command, string $cwd): string
{
    $pipes = [];
    $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $cwd);
    if (!is_resource($process)) {
        throw new RuntimeException('cannot start public create-app fixture command');
    }
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $code = proc_close($process);
    if ($code !== 0) {
        throw new RuntimeException('public create-app fixture command failed: ' . trim((string) $stderr));
    }
    return (string) $stdout;
}

function publicIdentityDelete(string $path): void
{
    if (is_dir($path) && !is_link($path)) {
        foreach (array_diff(scandir($path) ?: [], ['.', '..']) as $entry) {
            publicIdentityDelete($path . '/' . $entry);
        }
        rmdir($path);
    } elseif (file_exists($path) || is_link($path)) {
        unlink($path);
    }
}

/** @param array<string,mixed> $data */
function publicIdentityWriteJson(string $path, array $data): void
{
    if (!is_dir(dirname($path))) {
        mkdir(dirname($path), 0775, true);
    }
    file_put_contents($path, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . "\n");
}

/** @param array<string,mixed> $inventory */
function publicIdentitySource(string $root, string $target, array $inventory): void
{
    mkdir($target, 0775, true);
    // The focused fixture is rebuilt from files that actually exist in this worktree.
    // Production inventory completeness remains enforced by CreateApplicationTest/main-control regeneration.
    $inventory['files'] = array_values(array_filter(
        $inventory['files'],
        static fn(array $entry): bool => is_file($root . '/' . (string) $entry['path']),
    ));
    foreach ($inventory['files'] as &$entry) {
        $relative = (string) $entry['path'];
        $source = $root . '/' . $relative;
        $destination = $target . '/' . $relative;
        publicIdentityExpect(is_file($source) && !is_link($source), 'candidate fixture source unavailable: ' . $relative);
        if (!is_dir(dirname($destination))) {
            mkdir(dirname($destination), 0775, true);
        }
        copy($source, $destination);
        chmod($destination, fileperms($source) & 0777);
        if (($entry['classification'] ?? null) === 'excluded') {
            continue;
        }
        $transform = (string) $entry['transform'];
        $entry['source_sha256'] = !str_starts_with($relative, 'server/resources/scaffold-application/') && in_array(
            $transform,
            ['changelog', 'release-metadata', 'resources', 'readme', 'docs-page', 'version-contract'],
            true,
        ) ? hash('sha256', "peanut.create-app-semantic-source.v1\0{$relative}\0{$transform}") : hash_file('sha256', $destination);
    }
    unset($entry);
    // Edition configuration is a source-tool input, deliberately outside the generated-app inventory.
    $editionSource = $root . '/scaffold/edition-profiles.json';
    publicIdentityExpect(is_file($editionSource) && !is_link($editionSource), 'edition profile source unavailable');
    if (!is_dir($target . '/scaffold')) {
        mkdir($target . '/scaffold', 0775, true);
    }
    copy($editionSource, $target . '/scaffold/edition-profiles.json');
    publicIdentityWriteJson($target . '/scaffold/application-template-inventory.json', $inventory);
    symlink($root . '/server/vendor', $target . '/server/vendor');
    publicIdentityRun(['git', 'init', '--quiet', '--initial-branch=fixture'], $target);
    // VersionContract verifies the ignored, prebuilt Core Web archives too; the
    // sealed fixture commit must therefore contain every inventory input.
    publicIdentityRun(['git', 'add', '--force', '--all'], $target);
    publicIdentityRun(['git', '-c', 'user.name=Peanut Fixture', '-c', 'user.email=fixture@example.test', 'commit', '--quiet', '-m', 'sealed source fixture'], $target);
}

$root = dirname(__DIR__, 3);
$temporaryBase = realpath(sys_get_temp_dir());
publicIdentityExpect(is_string($temporaryBase), 'temporary root unavailable');
$temporary = $temporaryBase . '/peanut-create-app-public-identity-' . bin2hex(random_bytes(6));
mkdir($temporary, 0700, true);

try {
    $inventory = json_decode((string) file_get_contents($root . '/scaffold/application-template-inventory.json'), true, 512, JSON_THROW_ON_ERROR);
    $version = (string) $inventory['template_version'];
    $source = $temporary . '/source';
    publicIdentitySource($root, $source, $inventory);

    $releaseRoot = $temporary . '/release';
    $releasePath = $releaseRoot . '/scaffold-manifest.json';
    $templateCommit = trim(publicIdentityRun(['git', 'rev-parse', 'HEAD'], $source));
    $templateTree = trim(publicIdentityRun(['git', 'rev-parse', 'HEAD^{tree}'], $source));
    publicIdentityRun([
        'php', $source . '/scripts/build-scaffold-release', '--version=' . $version,
        '--source-commit=' . $templateCommit, '--output=' . $releaseRoot,
    ], $source);

    $appOwnedPath = $source . '/server/config/peanut.php';
    file_put_contents($appOwnedPath, (string) file_get_contents($appOwnedPath) . "\n// later app-owned source\n");
    $inventoryPath = $source . '/scaffold/application-template-inventory.json';
    $changedInventory = json_decode((string) file_get_contents($inventoryPath), true, 512, JSON_THROW_ON_ERROR);
    foreach ($changedInventory['files'] as &$entry) {
        if (($entry['path'] ?? null) === 'server/config/peanut.php') {
            $entry['source_sha256'] = hash_file('sha256', $appOwnedPath);
            break;
        }
    }
    unset($entry);
    publicIdentityWriteJson($inventoryPath, $changedInventory);
    publicIdentityRun(['git', 'add', 'server/config/peanut.php', 'scaffold/application-template-inventory.json'], $source);
    publicIdentityRun(['git', '-c', 'user.name=Peanut Fixture', '-c', 'user.email=fixture@example.test', 'commit', '--quiet', '-m', 'later app-owned source'], $source);
    $generationCommit = trim(publicIdentityRun(['git', 'rev-parse', 'HEAD'], $source));
    $generationTree = trim(publicIdentityRun(['git', 'rev-parse', 'HEAD^{tree}'], $source));

    $target = $temporary . '/application';
    $output = json_decode(publicIdentityRun([
        'php', $source . '/scripts/create-app', '--name=Public Candidate', '--slug=public-candidate',
        '--package=fixture/public-candidate', '--target=' . $target, '--edition=multi-tenant', '--profile=full',
        '--scaffold-manifest=' . $releasePath,
    ], $source), true, 512, JSON_THROW_ON_ERROR);
    $manifest = json_decode((string) file_get_contents($target . '/.peanut/application-manifest.json'), true, 512, JSON_THROW_ON_ERROR);
    publicIdentityExpect(
        ($output['generation_source_commit'] ?? null) === $generationCommit
            && ($manifest['generation_source']['commit'] ?? null) === $generationCommit
            && ($manifest['generation_source']['tree'] ?? null) === $generationTree,
        'public create-app did not record its actual clean source identity',
    );
    publicIdentityExpect(
        ($manifest['template']['source_commit'] ?? null) === $templateCommit
            && ($manifest['template']['source_tree'] ?? null) === $templateTree
            && $templateCommit !== $generationCommit,
        'public create-app conflated template release and generation source identity',
    );
    foreach (['standalone', 'multi-tenant'] as $edition) {
        $editionRelease = $temporary . '/release-' . $edition;
        publicIdentityRun([
            'php', $source . '/scripts/build-scaffold-release', '--version=' . $version,
            '--source-commit=' . $templateCommit, '--edition=' . $edition, '--output=' . $editionRelease,
        ], $source);
        $editionTarget = $temporary . '/application-' . $edition;
        $arguments = [
            'php', $source . '/scripts/create-app', '--name=Edition Consumer', '--slug=edition-consumer',
            '--package=fixture/edition-consumer', '--target=' . $editionTarget, '--edition=' . $edition,
            '--profile=full', '--scaffold-manifest=' . $editionRelease . '/scaffold-manifest.json',
        ];
        $created = json_decode(publicIdentityRun($arguments, $source), true, 512, JSON_THROW_ON_ERROR);
        publicIdentityExpect(($created['edition'] ?? null) === $edition, 'sealed edition was not adopted');
        $selector = (string) file_get_contents($editionTarget . '/deploy/docker/nginx-select-admin.sh');
        publicIdentityExpect(
            str_contains($selector, 'this artifact requires DEPLOYMENT_MODE=' . $edition),
            'sealed application did not retain its exact edition projection',
        );
        $arguments[5] = '--target=' . $temporary . '/wrong-' . $edition;
        $arguments[6] = '--edition=' . ($edition === 'standalone' ? 'multi-tenant' : 'standalone');
        try {
            publicIdentityRun($arguments, $source);
            throw new RuntimeException('cross-edition adoption unexpectedly succeeded');
        } catch (RuntimeException $exception) {
            publicIdentityExpect(
                str_contains($exception->getMessage(), 'CREATE_APP_ADOPTION_EDITION_MISMATCH'),
                'cross-edition adoption must fail explicitly before target writes',
            );
        }
    }
} finally {
    publicIdentityDelete($temporary);
}

echo "CREATE-APP-PUBLIC-IDENTITY-001 passed\n";
