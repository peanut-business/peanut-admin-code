<?php

declare(strict_types=1);

$root = dirname(__DIR__, 3);
$process = proc_open(
    ['python3', $root . '/scripts/tests/release-public-assets-test.py'],
    [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
    $pipes,
    $root,
);
if (!is_resource($process)) {
    throw new RuntimeException('Release public asset test process is unavailable.');
}
$output = stream_get_contents($pipes[1]);
$error = stream_get_contents($pipes[2]);
fclose($pipes[1]);
fclose($pipes[2]);
$exit = proc_close($process);
fwrite(STDOUT, (string) $output);
fwrite(STDERR, (string) $error);
if ($exit !== 0 || !preg_match('/Ran [1-9][0-9]* tests?/', (string) $error)
    || !preg_match('/^OK$/m', (string) $error)) {
    throw new RuntimeException('Release public asset boundary checks did not pass.');
}
echo "RELEASE-PUBLIC-ASSETS-BOUNDARY passed; filesystem-only, not publication\n";
