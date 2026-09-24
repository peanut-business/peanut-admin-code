<?php
declare(strict_types=1);

/** 三个真实消费者读取同一固定依赖合同；不下载包，不声称渠道或安装验收。 */
$root = dirname(__DIR__, 3);
if (!class_exists(\Composer\Semver\VersionParser::class)) {
    require_once $root . '/server/vendor/autoload.php';
}
require_once $root . '/server/app/common/value/scaffold/VersionContract.php';
require_once $root . '/server/app/common/value/installation/ApplicationReleaseVersions.php';
require_once $root . '/scripts/scaffold-runtime/ScaffoldUpgradeRunner.php';

use app\common\value\scaffold\VersionContract;
use app\common\value\installation\ApplicationReleaseVersions;
use app\common\infrastructure\scaffold\ScaffoldUpgradeRunner;

$checks = 0;
function portableExpect(bool $condition, string $message): void
{
    $GLOBALS['checks']++;
    if (!$condition) throw new RuntimeException($message);
}
function portableDocument(string $path, array $document): void
{
    file_put_contents($path, json_encode($document, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n");
}
function portableRejects(callable $operation, string $message): void
{
    try { $operation(); } catch (RuntimeException) { $GLOBALS['checks']++; return; }
    throw new RuntimeException('Accepted invalid dependency contract: ' . $message);
}

$base = realpath(sys_get_temp_dir());
if (!is_string($base) || !str_contains($base, '/.local/tmp/')) throw new RuntimeException('TEST_REQUIRES_CHECKOUT_TMPDIR');
$temporary = $base . '/portable-release-' . bin2hex(random_bytes(6));
mkdir($temporary, 0700);
$path = $temporary . '/release-versions.json';
$document = json_decode((string)file_get_contents($root . '/release-versions.json'), true, 512, JSON_THROW_ON_ERROR);
$document['source_product_version'] = $document['scaffold_template'] = '9.8.7-rc.1';
$document['core_php']['constraint'] = '9.2.1';
$document['core_php']['resolved_version'] = 'v9.2.1';
foreach ($document['core_web']['packages'] as $name => &$identity) {
    $identity = [
        'version' => '8.1.0-rc.2',
        'resolved' => 'https://registry.example.invalid/' . $name . '/-/fixture-8.1.0-rc.2.tgz',
        'integrity' => 'sha512-' . base64_encode(hash('sha512', 'synthetic-package:' . $name, true)),
    ];
}
unset($identity);
$runner = new ScaffoldUpgradeRunner();
$normalize = new ReflectionMethod($runner, 'normalizeVersionContractDocument');
$digests = new ReflectionMethod($runner, 'assertV3ArchiveDigests');
$readers = [
    'source-builder' => static fn() => VersionContract::load($path)->toArray(),
    'installer' => static fn() => ApplicationReleaseVersions::load($path)->toArray(),
    'installed-upgrader' => static fn() => $normalize->invoke($runner, file_get_contents($path), 'PORTABLE_TEST_INVALID'),
];
try {
    portableDocument($path, $document);
    foreach ($readers as $name => $read) {
        portableExpect($read() === $document, $name . ' changed the fixed native identities');
    }
    $digests->invoke($runner, $temporary, $document['core_web']);
    portableExpect(!is_dir($temporary . '/packages'), 'portable dependency requires a maintainer tgz directory');
    $instance = $document;
    $instance['instance_version'] = '0.2.0';
    portableDocument($path, $instance);
    portableExpect(ApplicationReleaseVersions::load($path)->instanceVersion() === '0.2.0', 'instance and source versions were conflated');
    portableExpect($normalize->invoke($runner, file_get_contents($path), 'PORTABLE_TEST_INVALID') === $instance, 'upgrader lost instance identity');
    portableRejects(static fn() => VersionContract::load($path), 'source contract must not adopt an instance version');

    // 真实独立子进程只得到发行读取器和合同；open_basedir 禁止读取维护者源码和 vendor。
    portableDocument($path, $document);
    $isolatedFiles = [
        'scripts/scaffold-runtime/ReleaseDependencyIdentity.php',
        'scripts/scaffold-runtime/ScaffoldUpgradeRunner.php',
        'server/app/common/value/installation/ApplicationReleaseVersions.php',
    ];
    foreach ($isolatedFiles as $relative) {
        $target = $temporary . '/' . $relative;
        if (!is_dir(dirname($target))) mkdir(dirname($target), 0700, true);
        copy($root . '/' . $relative, $target);
    }
    $code = 'require $argv[1]."/server/app/common/value/installation/ApplicationReleaseVersions.php";'
        . 'require $argv[1]."/scripts/scaffold-runtime/ScaffoldUpgradeRunner.php";'
        . '$d=app\\common\\value\\installation\\ApplicationReleaseVersions::load($argv[1]."/release-versions.json")->toArray();'
        . '$r=new app\\common\\infrastructure\\scaffold\\ScaffoldUpgradeRunner();'
        . '$m=new ReflectionMethod($r,"normalizeVersionContractDocument");'
        . 'if($m->invoke($r,json_encode($d),"INVALID")!==$d)exit(1);echo "ISOLATED-NO-VENDOR-NO-TGZ";';
    $process = proc_open([PHP_BINARY, '-d', 'open_basedir=' . $temporary, '-r', $code, $temporary],
        [1 => ['pipe', 'w'], 2 => ['redirect', 1]], $pipes, $temporary);
    portableExpect(is_resource($process), 'cannot start isolated consumer');
    $output = stream_get_contents($pipes[1]);
    fclose($pipes[1]);
    portableExpect(proc_close($process) === 0 && $output === 'ISOLATED-NO-VENDOR-NO-TGZ', 'isolated consumer: ' . $output);
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($temporary, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($iterator as $entry) {
        if ($entry->getPathname() === $path) continue;
        $entry->isDir() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
    }

    $mutations = [
        'php-range' => static function (&$d) { $d['core_php']['constraint'] = '^9.2.1'; },
        'php-alias' => static function (&$d) { $d['core_php']['constraint'] = '9.2.1 as 8.0.0'; },
        'php-version-mismatch' => static function (&$d) { $d['core_php']['resolved_version'] = '9.2.2'; },
        'php-dev-with-registry' => static function (&$d) { $d['core_php']['constraint'] = $d['core_php']['resolved_version'] = 'dev-dev'; },
        'php-source-credentials' => static function (&$d) { $d['core_php']['source_url'] = 'https://user:secret@example.invalid/core.git'; },
        'web-range' => static function (&$d) { $d['core_web']['packages']['@peanut-admin/client']['version'] = '^8.1.0'; },
        'web-dev' => static function (&$d) { $d['core_web']['packages']['@peanut-admin/client']['version'] = '8.1.0-dev.1'; },
        'web-http' => static function (&$d) { $d['core_web']['packages']['@peanut-admin/client']['resolved'] = 'http://registry.example.invalid/core.tgz'; },
        'web-credentials' => static function (&$d) { $d['core_web']['packages']['@peanut-admin/client']['resolved'] = 'https://user:secret@registry.example.invalid/core.tgz'; },
        'web-query-secret' => static function (&$d) { $d['core_web']['packages']['@peanut-admin/client']['resolved'] .= '?token=secret'; },
        'web-short-integrity' => static function (&$d) { $d['core_web']['packages']['@peanut-admin/client']['integrity'] = 'sha512-AAAA'; },
        'web-missing-package' => static function (&$d) { unset($d['core_web']['packages']['@peanut-admin/testing']); },
        'web-ambiguous-local-and-registry' => static function (&$d) { $d['core_web']['packages']['@peanut-admin/client']['archive'] = 'packages/core-web/untrusted.tgz'; },
    ];
    foreach ($mutations as $label => $mutate) {
        $changed = $document;
        $mutate($changed);
        portableDocument($path, $changed);
        foreach ($readers as $name => $read) portableRejects($read, $name . ':' . $label);
    }
    // 既有开发合同仍按本地归档的实际字节校验，不能以新格式跳过旧摘要。
    $legacy = VersionContract::load($root . '/release-versions.json')->toArray();
    portableExpect(ApplicationReleaseVersions::load($root . '/release-versions.json')->toArray() === $legacy, 'development identity regressed');
    portableExpect($normalize->invoke($runner, json_encode($legacy, JSON_THROW_ON_ERROR), 'PORTABLE_TEST_INVALID') === $legacy, 'upgrader development identity regressed');
    $digests->invoke($runner, $root, $legacy['core_web']);
    portableRejects(static fn() => $digests->invoke($runner, $temporary, $legacy['core_web']), 'missing local archive must remain rejected');
    echo 'PORTABLE-RELEASE-DEPENDENCY-001 passed: ' . $checks . " checks; source/installer/upgrader identity only, no registry/install qualification\n";
} finally {
    if (is_file($path)) unlink($path);
    rmdir($temporary);
}
