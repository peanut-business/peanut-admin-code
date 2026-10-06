<?php

declare(strict_types=1);

use app\common\value\installation\ServerReleaseIdentity;

require_once dirname(__DIR__, 2) . '/app/common/value/installation/ServerReleaseIdentity.php';
require_once dirname(__DIR__, 2) . '/vendor/autoload.php';

function serverReleaseIdentityExpect(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function serverReleaseIdentityDelete(string $path): void
{
    if (is_dir($path) && !is_link($path)) {
        foreach (array_diff(scandir($path) ?: [], ['.', '..']) as $entry) {
            serverReleaseIdentityDelete($path . '/' . $entry);
        }
        rmdir($path);
        return;
    }
    if (file_exists($path) || is_link($path)) {
        unlink($path);
    }
}

/** @return list<array{path:string,sha256:string,mode:int}> */
function serverReleaseIdentityRows(string $serverRoot, array $paths): array
{
    $rows = [];
    sort($paths, SORT_STRING);
    foreach ($paths as $path) {
        $relative = substr($path, strlen('server/'));
        $absolute = $serverRoot . '/' . $relative;
        $digest = hash_file('sha256', $absolute);
        if (!is_string($digest)) {
            throw new RuntimeException('test fixture digest unavailable');
        }
        $rows[] = ['path' => $path, 'sha256' => $digest, 'mode' => 0644];
    }
    return $rows;
}

function serverReleaseIdentityFixture(string $root, string $kind): ServerReleaseIdentity
{
    $server = $root . '/server';
    foreach (['database', '.peanut', 'resources'] as $directory) {
        if (!is_dir($server . '/' . $directory) && !mkdir($server . '/' . $directory, 0700, true)) {
            throw new RuntimeException('cannot create server release fixture');
        }
    }

    file_put_contents($server . '/database/install.php', "<?php\n");
    $plugins = ['schema_version' => 1, 'protocol' => 'peanut.server-plugin-lock.v1', 'plugins' => []];
    file_put_contents($server . '/plugins.lock', json_encode($plugins, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES) . "\n");
    file_put_contents($server . '/resources/project-resources.json', json_encode([
        'schema_version' => 1,
        'project_id' => 'runtime-fixture',
        'authority' => ['role' => 'application'],
        'resources' => ['databases' => []],
    ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES) . "\n");
    file_put_contents($server . '/.peanut/RELEASE_METADATA.json', json_encode([
        'source_product_version' => '4.0.0-rc.10',
        'instance_version' => '1.2.3',
        'application_identity' => 'runtime-fixture/application',
    ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES) . "\n");

    $files = serverReleaseIdentityRows($server, [
        'server/.peanut/RELEASE_METADATA.json',
        'server/database/install.php',
        'server/plugins.lock',
        'server/resources/project-resources.json',
    ]);
    $appCommit = $kind === 'application' ? str_repeat('a', 40) : null;
    $appTree = $kind === 'application' ? str_repeat('b', 40) : null;
    $identity = [
        'schema_version' => 1,
        'protocol' => 'peanut.server-release.v1',
        'application' => [
            'kind' => $kind,
            'manifest_sha256' => str_repeat('1', 64),
            'commit' => $appCommit,
            'tree' => $appTree,
            'slug' => 'runtime-fixture',
            'edition' => 'standalone',
            'version' => '1.2.3',
            'profile' => 'full',
            'package_identity' => 'runtime-fixture/application',
            'name' => 'Runtime Fixture',
            'source_files_sha256' => str_repeat('2', 64),
        ],
        'edition_contract' => [
            'name' => 'standalone',
            'deployment_mode' => 'standalone',
            'tenant_bootstrap' => [],
            'source_sha256' => str_repeat('3', 64),
        ],
        'edition_profile_sha256' => str_repeat('3', 64),
        'upstream' => [
            'commit' => str_repeat('c', 40),
            'tree' => str_repeat('d', 40),
            'inventory_sha256' => str_repeat('4', 64),
        ],
        'template' => [
            'source_commit' => str_repeat('e', 40),
            'source_tree' => str_repeat('f', 40),
            'inventory_sha256' => str_repeat('5', 64),
            'version' => '4.0.0-rc.10',
        ],
        'versions' => [
            'source_product_version' => '4.0.0-rc.10',
            'release_sequence_version' => '1.2.3',
            'scaffold_template' => '4.0.0-rc.10',
        ],
        'plugin_projection' => [
            'source_lock_sha256' => str_repeat('6', 64),
            'projected_lock_sha256' => hash_file('sha256', $server . '/plugins.lock'),
            'plugins' => [],
        ],
        'files' => $files,
        'files_sha256' => hash('sha256', json_encode($files, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)),
    ];
    file_put_contents(
        $server . '/.peanut/release-identity.json',
        json_encode($identity, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . "\n",
    );

    return ServerReleaseIdentity::load($server);
}

$projectRoot = dirname(__DIR__, 3);
$temporaryRoot = $projectRoot . '/.local/tmp';
if (!is_dir($temporaryRoot) && !mkdir($temporaryRoot, 0700, true) && !is_dir($temporaryRoot)) {
    throw new RuntimeException('cannot create local fixture root');
}
$fixture = $temporaryRoot . '/server-release-identity-' . bin2hex(random_bytes(6));
if (!mkdir($fixture, 0700)) {
    throw new RuntimeException('cannot create fixture');
}

try {
    $application = serverReleaseIdentityFixture($fixture . '/application', 'application');
    serverReleaseIdentityExpect(
        $application->runtimeSourceIdentity() === ['commit' => str_repeat('a', 40), 'tree' => str_repeat('b', 40)],
        'APP server release must expose its own committed source identity',
    );
    serverReleaseIdentityExpect(
        ($application->applicationIdentity()['package_identity'] ?? null) === 'runtime-fixture/application',
        'application identity must remain available to server-only consumers',
    );
    serverReleaseIdentityExpect(
        ($application->templateIdentity()['version'] ?? null) === '4.0.0-rc.10'
            && ($application->versions()['release_sequence_version'] ?? null) === '1.2.3',
        'template and release versions must remain available to server-only consumers',
    );

    $generated = serverReleaseIdentityFixture($fixture . '/generated', 'generated-template');
    serverReleaseIdentityExpect(
        $generated->runtimeSourceIdentity() === ['commit' => str_repeat('c', 40), 'tree' => str_repeat('d', 40)],
        'generated-template server release must fall back to its upstream generation identity',
    );

    $server = $fixture . '/application/server';
    mkdir($server . '/runtime/upgrade', 0700, true);
    $currentUpdate = $server . '/runtime/upgrade/current-update.json';
    foreach (['file', 'dangling-link'] as $kind) {
        if ($kind === 'file') {
            file_put_contents($currentUpdate, '{}');
        } else {
            symlink('missing-update-state', $currentUpdate);
        }
        try {
            \app\common\infrastructure\installation\VerifiedServerDeployment::read(new \think\App($server));
            throw new RuntimeException('current update guard was accepted: ' . $kind);
        } catch (RuntimeException $exception) {
            serverReleaseIdentityExpect(
                $exception->getMessage() === 'SERVER_DEPLOYMENT_TRAFFIC_CLOSED',
                'an existing or linked current update pointer must close direct PHP admission',
            );
        } finally {
            unlink($currentUpdate);
        }
    }

    $cli = new \think\App($server);
    $cli->instance(ServerReleaseIdentity::class, $application);
    serverReleaseIdentityExpect(
        $cli->runningInConsole() && $cli->exists(ServerReleaseIdentity::class)
        && ServerReleaseIdentity::resolve($server) === $application,
        'CLI admission must reuse its explicitly verified same-root native App instance',
    );
    $otherProgram = $fixture . '/generated/server/database/install.php';
    file_put_contents($otherProgram, "<?php // changed external root\n");
    try {
        ServerReleaseIdentity::resolve($fixture . '/generated/server');
        throw new RuntimeException('another root borrowed current App admission');
    } catch (RuntimeException $exception) {
        serverReleaseIdentityExpect(
            $exception->getMessage() === 'SERVER_RELEASE_IDENTITY_INVALID',
            'another server root must retain full fresh content verification',
        );
    } finally {
        file_put_contents($otherProgram, "<?php\n");
    }

    // A release declaration alone is never HTTP admission, even after successful CLI verification.
    try {
        \app\common\infrastructure\installation\VerifiedServerDeployment::read(new \think\App($fixture . '/application/server'));
        throw new RuntimeException('missing deployment-owner admission was accepted');
    } catch (RuntimeException $exception) {
        serverReleaseIdentityExpect(
            str_starts_with($exception->getMessage(), 'SERVER_DEPLOYMENT_'),
            'missing protected admission must fail closed without compiling or scanning source',
        );
    }
    $program = $fixture . '/application/server/database/install.php';
    file_put_contents($program, "<?php // modified after verification\n");
    $cli = new \think\App($server);
    // A closure binding is not an existing verified instance and may not bypass fresh CLI verification.
    $cli->bind(ServerReleaseIdentity::class, static fn(): ServerReleaseIdentity => $application);
    try {
        ServerReleaseIdentity::resolve($server);
        throw new RuntimeException('unverified CLI closure bypassed content verification');
    } catch (RuntimeException $exception) {
        serverReleaseIdentityExpect(
            !$cli->exists(ServerReleaseIdentity::class)
            && $exception->getMessage() === 'SERVER_RELEASE_IDENTITY_INVALID',
            'CLI without an explicit verified identity instance must perform fresh full verification',
        );
    }
    try {
        ServerReleaseIdentity::load($fixture . '/application/server');
        throw new RuntimeException('modified source was accepted by fresh verification');
    } catch (RuntimeException $exception) {
        serverReleaseIdentityExpect(
            $exception->getMessage() === 'SERVER_RELEASE_IDENTITY_INVALID',
            'CLI and lifecycle full verification must reject changed program bytes',
        );
    }
    unlink($program);
    symlink($fixture . '/generated/server/database/install.php', $program);
    try {
        ServerReleaseIdentity::load($fixture . '/application/server');
        throw new RuntimeException('linked source was accepted by fresh verification');
    } catch (RuntimeException $exception) {
        serverReleaseIdentityExpect(
            $exception->getMessage() === 'SERVER_RELEASE_IDENTITY_INVALID',
            'fresh verification must reject links even when target bytes match the inventory',
        );
    }

    echo "SERVER-RELEASE-RUNTIME-IDENTITY passed assertions=12\n";
} finally {
    serverReleaseIdentityDelete($fixture);
}
