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

/** Stage actual sealed fixture bytes under the public channel's canonical path. */
function publicIdentityStageRelease(string $source, string $destination): void
{
    publicIdentityDelete($destination);
    if (!is_dir(dirname($destination))) {
        mkdir(dirname($destination), 0775, true);
    }
    mkdir($destination, 0775, true);
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($source, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST,
    );
    foreach ($iterator as $entry) {
        $relative = substr($entry->getPathname(), strlen($source) + 1);
        $target = $destination . '/' . $relative;
        publicIdentityExpect(!$entry->isLink(), 'synthetic release may not contain links');
        if ($entry->isDir()) {
            if (!is_dir($target)) mkdir($target, 0775, true);
        } else {
            publicIdentityExpect(copy($entry->getPathname(), $target), 'sealed fixture copy failed');
            chmod($target, fileperms($entry->getPathname()) & 0777);
        }
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

function publicIdentityCatalog(string $target): void
{
    $versions = json_decode((string) file_get_contents($target . '/release-versions.json'), true, 512, JSON_THROW_ON_ERROR);
    $openapi = json_decode((string) file_get_contents($target . '/server/generated/openapi.json'), true, 512, JSON_THROW_ON_ERROR);
    $catalog = json_decode((string) file_get_contents($target . '/server/generated/api-catalog.json'), true, 512, JSON_THROW_ON_ERROR);
    publicIdentityExpect(($openapi['info']['version'] ?? null) === $versions['source_product_version'], 'generated APP API version lost its source-product identity');
    publicIdentityExpect(
        count(array_filter($catalog['inputs'] ?? [], static fn(array $input): bool =>
            ($input['path'] ?? null) === 'release-versions.json')) === 1,
        'generated APP catalog omitted or duplicated its version authority',
    );
    foreach ($catalog['inputs'] as $input) {
        publicIdentityExpect(hash_file('sha256', $target . '/' . $input['path']) === $input['sha256'], 'generated APP catalog retained a source-template hash: ' . $input['path']);
    }
    $manifest = json_decode((string) file_get_contents($target . '/.peanut/application-manifest.json'), true, 512, JSON_THROW_ON_ERROR);
    $catalogRow = array_values(array_filter($manifest['files'], static fn(array $entry): bool => $entry['path'] === 'server/generated/api-catalog.json'))[0] ?? [];
    publicIdentityExpect(($catalogRow['sha256'] ?? null) === hash_file('sha256', $target . '/server/generated/api-catalog.json'), 'generated APP manifest omitted the materialized catalog bytes');
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
    $origin = trim(publicIdentityRun(['git', 'remote', 'get-url', 'origin'], $root));
    publicIdentityExpect($origin !== '', 'candidate source origin unavailable');
    publicIdentityRun(['git', 'remote', 'add', 'origin', $origin], $target);
    // VersionContract verifies the ignored, prebuilt Core Web archives too; the
    // sealed fixture commit must therefore contain every inventory input.
    publicIdentityRun(['git', 'add', '--force', '--all'], $target);
    publicIdentityRun(['git', '-c', 'user.name=Peanut Fixture', '-c', 'user.email=fixture@example.test', 'commit', '--quiet', '-m', 'sealed source fixture'], $target);
    // The synthetic source is immutable, but create-app requires its original
    // exact-lock UniApp generator to resolve Vite. Keep dependencies ignored
    // and outside both the fixed source commit and generated APP inventory.
    publicIdentityExpect(
        is_file($root . '/uniapp/node_modules/vite/package.json')
            && is_dir($target . '/uniapp')
            && symlink($root . '/uniapp/node_modules', $target . '/uniapp/node_modules'),
        'original locked UniApp fixture toolchain is unavailable',
    );
    // The API generator's source/path guard forbids out-of-repository and
    // hard-linked packages. Materialize the fixture Web toolchain with the
    // native frozen lock and independent package files, never copy node_modules.
    publicIdentityRun([
        'pnpm', '--dir', $target . '/web', 'install', '--frozen-lockfile',
        '--ignore-scripts', '--package-import-method=copy',
    ], $target);
    publicIdentityExpect(
        trim(publicIdentityRun(['git', 'status', '--porcelain=v1', '--untracked-files=all'], $target)) === '',
        'ignored UniApp toolchain must not alter the immutable source identity',
    );
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

    $templateCommit = trim(publicIdentityRun(['git', 'rev-parse', 'HEAD'], $source));
    $templateTree = trim(publicIdentityRun(['git', 'rev-parse', 'HEAD^{tree}'], $source));
    $releaseRoot = $temporary . '/release';
    publicIdentityRun([
        'php', $source . '/scripts/build-scaffold-release', '--version=' . $version,
        '--source-commit=' . $templateCommit, '--output=' . $releaseRoot,
        '--api-tool-root=' . $root,
    ], $source);
    $editionReleases = [];
    foreach (['standalone', 'multi-tenant'] as $edition) {
        $editionReleases[$edition] = $temporary . '/release-' . $edition;
        publicIdentityRun([
            'php', $source . '/scripts/build-scaffold-release', '--version=' . $version,
            '--source-commit=' . $templateCommit, '--edition=' . $edition,
            '--output=' . $editionReleases[$edition], '--api-tool-root=' . $root,
        ], $source);
    }
    // This is a local protocol fixture, not a claim of a GitHub publication.
    // The real published-adoption contract only accepts canonical Edition
    // release bytes, never an arbitrary local manifest as 'stable'.
    $canonicalRelease = $source . '/scaffold/releases/v' . $version;
    publicIdentityStageRelease($releaseRoot, $canonicalRelease);
    file_put_contents($source . '/source-only-generation-note.txt', "source-only generation commit\n");
    publicIdentityRun(['git', 'add', '--force', '--all', 'scaffold/releases/v' . $version,
        'source-only-generation-note.txt'], $source);
    publicIdentityRun(['git', '-c', 'user.name=Peanut Fixture', '-c', 'user.email=fixture@example.test',
        'commit', '--quiet', '-m', 'later source and fixture release'], $source);
    $generationCommit = trim(publicIdentityRun(['git', 'rev-parse', 'HEAD'], $source));
    $generationTree = trim(publicIdentityRun(['git', 'rev-parse', 'HEAD^{tree}'], $source));
    $releasePath = $canonicalRelease . '/scaffold-manifest.json';

    $target = $temporary . '/application';
    $output = json_decode(publicIdentityRun([
        'php', $source . '/scripts/create-app', '--name=Public Candidate', '--slug=public-candidate',
        '--package=fixture/public-candidate', '--target=' . $target, '--edition=multi-tenant', '--profile=full',
        '--scaffold-manifest=' . $releasePath, '--application-version=1.2.3',
        '--source-channel=stable', '--release-version=' . $version,
    ], $source), true, 512, JSON_THROW_ON_ERROR);
    $manifest = json_decode((string) file_get_contents($target . '/.peanut/application-manifest.json'), true, 512, JSON_THROW_ON_ERROR);
    publicIdentityCatalog($target);
    $applicationVersions = json_decode((string) file_get_contents($target . '/release-versions.json'), true, 512, JSON_THROW_ON_ERROR);
    publicIdentityExpect(($applicationVersions['instance_version'] ?? null) === '1.2.3', 'APP instance version was conflated with the Peanut source-product version');
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
        publicIdentityStageRelease($editionReleases[$edition], $canonicalRelease);
        publicIdentityRun(['git', 'add', '--force', '--all', 'scaffold/releases/v' . $version], $source);
        publicIdentityRun(['git', '-c', 'user.name=Peanut Fixture', '-c', 'user.email=fixture@example.test',
            'commit', '--quiet', '-m', 'selected sealed edition ' . $edition], $source);
        $editionTarget = $temporary . '/application-' . $edition;
        $arguments = [
            'php', $source . '/scripts/create-app', '--name=Edition Consumer', '--slug=edition-consumer',
            '--package=fixture/edition-consumer', '--target=' . $editionTarget, '--edition=' . $edition,
            '--profile=full', '--scaffold-manifest=' . $releasePath,
            '--source-channel=stable', '--release-version=' . $version,
        ];
        $created = json_decode(publicIdentityRun($arguments, $source), true, 512, JSON_THROW_ON_ERROR);
        publicIdentityCatalog($editionTarget);
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
