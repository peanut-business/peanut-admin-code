<?php

declare(strict_types=1);

$root = dirname(__DIR__, 3);
$runner = $root . '/scripts/combined-upgrade-qualification';
$fixturePath = $root . '/server/tests/fixtures/core-upgrade-compatibility/combined-upgrade.json';
$fixture = json_decode((string) file_get_contents($fixturePath), true, 512, JSON_THROW_ON_ERROR);
$temporary = sys_get_temp_dir() . '/pa-combined-upgrade-policy-' . bin2hex(random_bytes(8));
if (!mkdir($temporary, 0700, true) && !is_dir($temporary)) {
    throw new RuntimeException('unable to create policy test directory');
}

function combinedPolicyExpect(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

/** @return array{exit:int,output:string} */
function combinedPolicyCommand(array $arguments): array
{
    $pipes = [];
    $process = proc_open($arguments, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    if (!is_resource($process)) {
        throw new RuntimeException('unable to execute policy validator');
    }
    $output = (string) stream_get_contents($pipes[1]) . (string) stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    return ['exit' => proc_close($process), 'output' => $output];
}

function combinedPolicyRun(string $runner, string $fixture): array
{
    return combinedPolicyCommand([$runner, '--validate-policy', $fixture]);
}

function combinedPolicyWrite(string $path, array $fixture): void
{
    file_put_contents($path, json_encode($fixture, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n");
}

try {
    $paths = ['server/composer.json' => 'server', 'server/composer.lock' => 'server',
        'web/package.json' => 'web', 'web/pnpm-lock.yaml' => 'web', 'pc/package.json' => 'pc',
        'pc/package-lock.json' => 'pc', 'uniapp/package.json' => 'uniapp', 'uniapp/package-lock.json' => 'uniapp',
        'scripts/combined-upgrade-qualification' => 'integration', 'scripts/scaffold-runtime/ScaffoldUpgradeRunner.php' => 'integration',
        'server/tests/Productization/CombinedUpgradePolicyTest.php' => 'server',
        'server/tests/fixtures/core-upgrade-compatibility/combined-upgrade.json' => 'server'];
    foreach ($paths as $path => $group) {
        file_put_contents($temporary . '/changed-path', $path . "\n");
        $result = combinedPolicyCommand([$root . '/scripts/ci-change-classifier', '--paths-file=' . $temporary . '/changed-path']);
        combinedPolicyExpect($result['exit'] === 0 && in_array($group . '=true', explode("\n", $result['output']), true),
            'current CI selector missed ' . $path);
    }

    // The policy describes the immutable 1.x chain. Only its manifests belong in this isolated consumer.
    $checkout = $temporary . '/policy';
    $clone = combinedPolicyCommand(['git', 'clone', '--quiet', '--shared', '--no-checkout', $root, $checkout]);
    combinedPolicyExpect($clone['exit'] === 0, $clone['output']);
    $runner = $checkout . '/scripts/combined-upgrade-qualification';
    mkdir(dirname($runner), 0700, true);
    copy($root . '/scripts/combined-upgrade-qualification', $runner);
    chmod($runner, 0755);
    foreach (array_merge($fixture['php_probes'], ['server/tests/fixtures/core-upgrade-compatibility/manual-action.txt']) as $file) {
        if (!is_dir(dirname($checkout . '/' . $file))) { mkdir(dirname($checkout . '/' . $file), 0700, true); }
        copy($root . '/' . $file, $checkout . '/' . $file);
    }
    foreach ($fixture['release_chain'] as $release) {
        $file = 'scaffold/releases/v' . $release['version'] . '/scaffold-manifest.json';
        $manifest = combinedPolicyCommand(['git', '-C', $root, 'show', '0075e3ed8dc5e71834335af54eca25761236e9df:' . $file]);
        combinedPolicyExpect($manifest['exit'] === 0 && hash('sha256', $manifest['output']) === $release['manifest_sha256'],
            'historical release manifest identity is unavailable: ' . $file);
        mkdir(dirname($checkout . '/' . $file), 0700, true);
        file_put_contents($checkout . '/' . $file, $manifest['output']);
    }

    $valid = combinedPolicyRun($runner, $fixturePath);
    combinedPolicyExpect($valid['exit'] === 0, 'checked-in combined upgrade policy must pass');

    $missingAction = $fixture;
    $missingAction['transitions'][0]['compatibility'] = 'breaking';
    $missingActionPath = $temporary . '/missing-action.json';
    combinedPolicyWrite($missingActionPath, $missingAction);
    $missingActionResult = combinedPolicyRun($runner, $missingActionPath);
    combinedPolicyExpect(
        $missingActionResult['exit'] !== 0 && str_contains($missingActionResult['output'], 'needs a machine-readable migration'),
        'breaking release without an action must fail closed',
    );

    $stableSmuggling = $fixture;
    $stableSmuggling['transitions'][0]['actions'][] = [
        'id' => 'unexpected-action',
        'kind' => 'manual-action',
        'artifact' => 'server/tests/fixtures/core-upgrade-compatibility/manual-action.txt',
        'sha256' => hash_file('sha256', $root . '/server/tests/fixtures/core-upgrade-compatibility/manual-action.txt'),
    ];
    $stableSmugglingPath = $temporary . '/stable-smuggling.json';
    combinedPolicyWrite($stableSmugglingPath, $stableSmuggling);
    $stableSmugglingResult = combinedPolicyRun($runner, $stableSmugglingPath);
    combinedPolicyExpect(
        $stableSmugglingResult['exit'] !== 0 && str_contains($stableSmugglingResult['output'], 'must not smuggle migration actions'),
        'stable release must not hide a breaking action',
    );

    $machineReadable = $fixture;
    $machineReadable['transitions'][0]['compatibility'] = 'breaking';
    $machineReadable['transitions'][0]['actions'][] = [
        'id' => 'upgrade-instructions',
        'kind' => 'manual-action',
        'artifact' => 'server/tests/fixtures/core-upgrade-compatibility/manual-action.txt',
        'sha256' => hash_file('sha256', $root . '/server/tests/fixtures/core-upgrade-compatibility/manual-action.txt'),
    ];
    $machineReadablePath = $temporary . '/machine-readable.json';
    combinedPolicyWrite($machineReadablePath, $machineReadable);
    $machineReadableResult = combinedPolicyRun($runner, $machineReadablePath);
    combinedPolicyExpect($machineReadableResult['exit'] === 0, 'machine-readable breaking action must be accepted');
} finally {
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($temporary, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($files as $file) {
        $file->isDir() && !$file->isLink() ? rmdir($file->getPathname()) : unlink($file->getPathname());
    }
    rmdir($temporary);
}

echo "COMBINED-UPGRADE-POLICY-TEST-001 passed\n";
