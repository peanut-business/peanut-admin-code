<?php

declare(strict_types=1);

$source = dirname(__DIR__, 2);
$temporary = realpath(sys_get_temp_dir());
if (!is_string($temporary) || !str_contains($temporary, '/.local/tmp/')) {
    throw new RuntimeException('Use a checkout-owned temporary directory.');
}
$fixture = $temporary . '/source-path-isolation-' . bin2hex(random_bytes(6));
$consumer = $fixture . '/consumer';
mkdir($consumer . '/server/app/modules', 0700, true);
copy($source . '/app/platform/exception/plugin/PluginPackageException.php', $consumer . '/exception.php');
copy($source . '/app/platform/infrastructure/plugin/PluginPackageSourcePromoter.php', $consumer . '/promoter.php');
$probe = <<<'PHP'
<?php
declare(strict_types=1);
require __DIR__ . '/exception.php';
require __DIR__ . '/promoter.php';
set_error_handler(static function (int $severity, string $message): never {
    throw new ErrorException($message, 0, $severity);
});
$root = __DIR__;
$case = $argv[1];
try {
    $server = $case === 'root-alias' ? $root . '/root-alias/server' : $root . '/server';
    $promoter = new app\platform\infrastructure\plugin\PluginPackageSourcePromoter($server);
    if ($case === 'escape') {
        $method = new ReflectionMethod($promoter, 'assertPath');
        $method->invoke($promoter, $root . '/../outside');
    } else {
        $result = $promoter->run(static fn() => $promoter->recover());
        if ($result !== ['status' => 'clean']) throw new RuntimeException('Unexpected recovery state.');
    }
    if ($case !== 'normal') throw new RuntimeException('Unsafe path accepted.');
} catch (app\platform\exception\plugin\PluginPackageException $error) {
    if ($case === 'normal' || $error->errorCode !== 'MODULE_PACKAGE_PATH_INVALID') throw $error;
}
echo json_encode(['status' => 'passed', 'case' => $case], JSON_THROW_ON_ERROR) . PHP_EOL;
PHP;
file_put_contents($consumer . '/probe.php', $probe);
$passed = [];
try {
    foreach (['normal', 'root-alias', 'nested-link', 'escape'] as $case) {
        $link = null;
        if ($case === 'root-alias') {
            $link = $consumer . '/root-alias';
            symlink($consumer, $link);
        } elseif ($case === 'nested-link') {
            mkdir($consumer . '/link-target', 0700);
            $link = $consumer . '/server/app/modules/linked';
            symlink($consumer . '/link-target', $link);
        }
        $process = proc_open(
            [PHP_BINARY, '-d', 'open_basedir=' . $consumer, $consumer . '/probe.php', $case],
            [1 => ['pipe', 'w'], 2 => ['redirect', 1]],
            $pipes,
            $consumer,
        );
        if (!is_resource($process)) {
            throw new RuntimeException('Cannot start isolated path probe.');
        }
        $output = stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        $exit = proc_close($process);
        if ($link !== null) {
            unlink($link);
        }
        if ($exit !== 0 || trim((string) $output) !== json_encode(['status' => 'passed', 'case' => $case], JSON_THROW_ON_ERROR)) {
            throw new RuntimeException($case . ': ' . $output);
        }
        $passed[] = $case;
    }
    echo 'MODULE-SOURCE-PATH-ISOLATION-001 passed: ' . count($passed) . ' cases; warnings are errors; database-not-executed' . PHP_EOL;
} finally {
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($fixture, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($iterator as $entry) {
        $entry->isDir() && !$entry->isLink() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
    }
    rmdir($fixture);
}
