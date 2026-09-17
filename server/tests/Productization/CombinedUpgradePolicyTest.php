<?php
declare(strict_types=1);

$root = dirname(__DIR__, 3);
$runner = $root . '/scripts/combined-upgrade-qualification';
$fixturePath = $root . '/server/tests/fixtures/core-upgrade-compatibility/combined-upgrade.json';
$fixture = json_decode((string)file_get_contents($fixturePath), true, 512, JSON_THROW_ON_ERROR);
$temporary = sys_get_temp_dir() . '/pa-combined-upgrade-policy-' . bin2hex(random_bytes(8));
if (!mkdir($temporary, 0700, true) && !is_dir($temporary)) {
    throw new RuntimeException('unable to create policy test directory');
}

function combinedPolicyExpect(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}

/** @return array{exit:int,output:string} */
function combinedPolicyRun(string $runner, string $fixture): array
{
    $pipes = [];
    $process = proc_open([$runner, '--validate-policy', $fixture], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    if (!is_resource($process)) throw new RuntimeException('unable to execute policy validator');
    $output = (string)stream_get_contents($pipes[1]) . (string)stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    return ['exit' => proc_close($process), 'output' => $output];
}

function combinedPolicyWrite(string $path, array $fixture): void
{
    file_put_contents($path, json_encode($fixture, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n");
}

try {
    $workflow = (string)file_get_contents($root . '/.github/workflows/core-upgrade-compatibility.yml');
    foreach ([
        "'server/composer.json'",
        "'server/composer.lock'",
        "'web/package.json'",
        "'web/pnpm-lock.yaml'",
        "'pc/package.json'",
        "'pc/package-lock.json'",
        "'uniapp/package.json'",
        "'uniapp/package-lock.json'",
        "'scaffold/releases/*/scaffold-manifest.json'",
        "'scripts/combined-upgrade-qualification'",
        "'server/tests/Productization/CombinedUpgradePolicyTest.php'",
        "'server/tests/fixtures/core-upgrade-compatibility/**'",
    ] as $requiredTrigger) {
        combinedPolicyExpect(
            str_contains($workflow, $requiredTrigger),
            'combined compatibility workflow trigger is missing: ' . $requiredTrigger
        );
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
        'breaking release without an action must fail closed'
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
        'stable release must not hide a breaking action'
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
    foreach (glob($temporary . '/*') ?: [] as $path) unlink($path);
    rmdir($temporary);
}

echo "COMBINED-UPGRADE-POLICY-TEST-001 passed\n";
