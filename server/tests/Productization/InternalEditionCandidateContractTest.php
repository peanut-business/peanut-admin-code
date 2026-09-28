<?php

declare(strict_types=1);

// This entry verifies internal artifact boundaries only; no database or accepted
// installation/member runtime tests are repeated by this focused test.
$root = dirname(__DIR__, 3);
$process = proc_open(
    ['python3', $root . '/scripts/tests/internal-candidate-contract-test.py'],
    [1 => ['pipe', 'w'], 2 => ['redirect', 1]],
    $pipes,
    $root,
);
if (!is_resource($process)) {
    throw new RuntimeException('INTERNAL_CANDIDATE_TEST_PROCESS_UNAVAILABLE');
}
$output = stream_get_contents($pipes[1]);
fclose($pipes[1]);
$status = proc_close($process);
if ($status !== 0
    || !is_string($output)
    || !str_contains($output, 'INTERNAL-CANDIDATE-PROJECTION-001 passed (14 cases)')) {
    throw new RuntimeException('INTERNAL_CANDIDATE_CONTRACT_FAILED: ' . (string) $output);
}
echo $output;
