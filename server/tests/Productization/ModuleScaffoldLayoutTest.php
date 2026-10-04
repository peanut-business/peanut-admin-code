<?php

declare(strict_types=1);

/** Actual source-only generator + canonical manifest preflight + native Composer validation; no framework/DB boot. */
$root = dirname(__DIR__, 3);
if (!class_exists(Composer\Autoload\ClassLoader::class, false)) {
    require $root . '/server/vendor/autoload.php';
}
require_once $root . '/server/app/common/infrastructure/module/ModuleScaffoldGenerator.php';

use app\common\infrastructure\module\ModuleScaffoldGenerator;
use app\common\exception\module\ModuleScaffoldException;

$base = realpath(sys_get_temp_dir());
if (!is_string($base) || !str_starts_with($base, $root . '/.local/tmp/')) {
    throw new RuntimeException('LAYOUT_TEST_OWN_CHECKOUT_TMPDIR_REQUIRED');
}
$temp = $base . '/module-layout-' . bin2hex(random_bytes(6));
mkdir($temp, 0700);
foreach (['server/app/modules', 'web/src/modules', 'server/tests'] as $directory) {
    mkdir($temp . '/' . $directory, 0700, true);
}
$checks = 0;
$expect = static function (bool $value, string $message) use (&$checks): void {
    $checks++;
    if (!$value) {
        throw new RuntimeException($message);
    }
};
$composer = getenv('PEANUT_TEST_COMPOSER') ?: $root . '/scripts/project-composer';
$generator = new ModuleScaffoldGenerator($temp, $root . '/server/resources/module-scaffold', $composer);
$generated = [];
$keep = getenv('PEANUT_LAYOUT_RESULT') ?: null;
if ($keep !== null && !str_starts_with($keep, $base . '/')) {
    throw new RuntimeException('LAYOUT_RESULT_OUTSIDE_TASK');
}
$success = false;
try {
    foreach ([
        ['dcs.product', 'dcs', 'server/app/modules/dcs/product', 'Dcs\\Modules\\Product\\', 'server/tests/Modules/Dcs/Product'],
        ['acme.customer-record', 'acme', 'server/app/modules/acme/customer_record', 'Acme\\Modules\\CustomerRecord\\', 'server/tests/Modules/Acme/CustomerRecord'],
        ['official.sample', 'official', 'server/app/modules/official/sample', 'PeanutAdmin\\Modules\\Sample\\', 'server/tests/Modules/Official/Sample'],
    ] as [$key, $vendor, $backend, $namespace, $tests]) {
        $result = $generator->create($key, $vendor, 'admin-web');
        $expect($result['backend_path'] === $backend && $result['test_path'] === $tests, 'KEY_DERIVED_CASE_CHANGED');
        $manifest = json_decode((string) file_get_contents($temp . '/' . $backend . '/module.json'), true, 512, JSON_THROW_ON_ERROR);
        $package = json_decode((string) file_get_contents($temp . '/' . $backend . '/composer.json'), true, 512, JSON_THROW_ON_ERROR);
        $expect(($package['autoload']['psr-4'][$namespace] ?? null) === 'src/', 'PSR4_PREFIX_OR_SOURCE_ROOT_CHANGED');
        $expect($manifest['backend']['provider'] === $namespace . 'ModuleProvider', 'PROVIDER_NAMESPACE_DRIFT');
        $expect($manifest['frontend']['entry'] === $result['frontend_entry'], 'MANIFEST_FRONTEND_PATH_DRIFT');
        $cursor = $temp;
        foreach (explode('/', $backend . '/src/ModuleProvider.php') as $segment) {
            $expect(in_array($segment, scandir($cursor), true), 'FILESYSTEM_CASE_MISMATCH:' . $segment);
            $cursor .= '/' . $segment;
        }
        foreach (['Controller', 'Validation', 'Service', 'Infrastructure', 'Model'] as $layer) {
            $expect(!file_exists($temp . '/' . $backend . '/src/' . $layer), 'UNUSED_LAYER_GENERATED:' . $layer);
        }
        $loader = new Composer\Autoload\ClassLoader();
        $loader->setPsr4($namespace, [$temp . '/' . $backend . '/src']);
        $loader->register(true);
        try {
            $provider = $namespace . 'ModuleProvider';
            $expect(class_exists($provider) && (new $provider())->moduleKey() === $key, 'GENERATED_PROVIDER_COLD_LOAD_FAILED');
            $expect($manifest['contracts']['exports'] === [], 'EMPTY_BUSINESS_CONTRACT_MUST_NOT_BE_PUBLISHED');
        } finally {
            $loader->unregister();
        }
        $frontend = (string) file_get_contents($temp . '/' . $result['frontend_entry']);
        $expect(str_contains($frontend, "from '@peanut-admin/vue'") && !str_contains($frontend, '@peanut-admin/admin'), 'RETIRED_FRONTEND_PACKAGE_GENERATED');
        $generated[] = $result + ['namespace' => $namespace, 'absolute_frontend' => $temp . '/' . $result['frontend_entry']];
    }
    $before = hash_file('sha256', $temp . '/server/app/modules/dcs/product/module.json');
    try {
        $generator->create('dcs.product', 'dcs');
        throw new RuntimeException('DUPLICATE_ACCEPTED');
    } catch (ModuleScaffoldException $error) {
        $expect(str_contains($error->getMessage(), 'already exists'), 'DUPLICATE_FAILURE_CHANGED');
    }
    $expect(hash_file('sha256', $temp . '/server/app/modules/dcs/product/module.json') === $before, 'DUPLICATE_CHANGED_EXISTING_OUTPUT');
    try {
        $generator->create('dcs.other', 'Dcs');
        throw new RuntimeException('UPPERCASE_VENDOR_ACCEPTED');
    } catch (ModuleScaffoldException $error) {
        $expect(str_contains($error->getMessage(), 'vendor is invalid'), 'VENDOR_FAILURE_CHANGED');
    }
    $doc = (string) file_get_contents($root . '/docs/module-layout.md');
    foreach ($generated as $result) {
        $row = '| `' . $result['module_key'] . '` | `' . $result['backend_path'] . '` | `' . rtrim($result['namespace'], '\\') . '` | `' . $result['frontend_path'] . '` | `' . $result['test_path'] . '` |';
        $expect(str_contains($doc, $row), 'PUBLIC_LAYOUT_DOCUMENT_DRIFT:' . $result['module_key']);
    }
    $result = ['status' => 'passed', 'checks' => $checks, 'fixture' => $temp, 'generated' => $generated, 'database_executed' => false];
    if ($keep !== null) {
        file_put_contents($keep, json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n");
    }
    echo 'MODULE-SCAFFOLD-LAYOUT-001 ' . json_encode($result, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
    $success = true;
} finally {
    if ($success && $keep === null) {
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($temp, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($it as $entry) {
            $entry->isDir() && !$entry->isLink() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
        }
        rmdir($temp);
    } elseif (!$success) {
        fwrite(STDERR, 'Failed fixture retained: ' . $temp . "\n");
    }
}
