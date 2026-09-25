<?php
declare(strict_types=1);

use app\platform\infrastructure\plugin\DevelopmentModuleDiscovery;
use PeanutAdmin\Kernel\Module\ModuleHostLayout;
use PeanutAdmin\Kernel\Module\ModuleKey;

require dirname(__DIR__, 2) . '/vendor/autoload.php';

function moduleCreateExpect(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}

/** @param list<string> $arguments @return array{code:int,output:string,json:array<string,mixed>} */
function moduleCreateRun(string $serverRoot, array $arguments): array
{
    $pipes = [];
    $process = proc_open(
        [PHP_BINARY, $serverRoot . '/think', 'module:create', ...$arguments],
        [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes,
        $serverRoot,
    );
    if (!is_resource($process)) throw new RuntimeException('module:create process could not start');
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $code = proc_close($process);
    $output = trim((string)$stdout . "\n" . (string)$stderr);
    $lines = array_values(array_filter(array_map('trim', explode("\n", $output)), 'strlen'));
    $json = json_decode((string)($lines[array_key_last($lines)] ?? ''), true);
    return ['code' => $code, 'output' => $output, 'json' => is_array($json) ? $json : []];
}

function moduleCreateTreeDigest(string $root): string
{
    $files = [];
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
    );
    foreach ($iterator as $entry) {
        if (!$entry->isFile() || $entry->isLink()) continue;
        $relative = substr($entry->getPathname(), strlen($root) + 1);
        $files[$relative] = hash_file('sha256', $entry->getPathname());
    }
    ksort($files, SORT_STRING);
    return hash('sha256', json_encode($files, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
}

function moduleCreateRemoveTree(string $path): void
{
    if (!is_dir($path) || is_link($path)) return;
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST,
    );
    foreach ($iterator as $entry) {
        $entry->isDir() && !$entry->isLink() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
    }
    rmdir($path);
}

/** @return array{code:int,output:string} */
function moduleCreateRunViteDiscovery(string $projectRoot): array
{
    $pipes = [];
    $process = proc_open(
        ['node', $projectRoot . '/web/tests/Productization/module-dev-discovery.test.mjs'],
        [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes,
        $projectRoot . '/web',
    );
    if (!is_resource($process)) throw new RuntimeException('Vite discovery process could not start');
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    return ['code' => proc_close($process), 'output' => trim((string)$stdout . "\n" . (string)$stderr)];
}

$serverRoot = dirname(__DIR__, 2);
$projectRoot = dirname($serverRoot);
$layout = new ModuleHostLayout('server/app/modules', 'app\\modules', 'web/src/modules');
$suffix = bin2hex(random_bytes(6));
$officialKey = 'official.generated-' . $suffix;
$customKey = 'acme.custom-generated-' . $suffix;
$officialModuleKey = ModuleKey::fromString($officialKey);
$customModuleKey = ModuleKey::fromString($customKey);
$officialBackend = $projectRoot . '/' . rtrim($layout->backendRelativePath($officialModuleKey), '/');
$officialFrontend = $projectRoot . '/' . rtrim($layout->frontendRelativePath($officialModuleKey), '/');
$customBackend = $projectRoot . '/' . rtrim($layout->backendRelativePath($customModuleKey), '/');
$customFrontend = $projectRoot . '/' . rtrim($layout->frontendRelativePath($customModuleKey), '/');
$officialTests = $projectRoot . '/server/tests/Modules/' . implode('/', $officialModuleKey->pascalSegments());
$customTests = $projectRoot . '/server/tests/Modules/' . implode('/', $customModuleKey->pascalSegments());
$customVendorRoot = $projectRoot . '/server/app/modules/acme';
$customVendorRootExisted = is_dir($customVendorRoot);

try {
    $official = moduleCreateRun($serverRoot, [$officialKey]);
    moduleCreateExpect($official['code'] === 0, 'default Official Module creation failed: ' . $official['output']);
    moduleCreateExpect(($official['json']['module_key'] ?? null) === $officialKey, 'Official creation returned another key');
    moduleCreateExpect(($official['json']['vendor'] ?? null) === 'official', 'Official vendor was not derived');

    $custom = moduleCreateRun($serverRoot, [$customKey, '--vendor=acme']);
    moduleCreateExpect($custom['code'] === 0, 'custom vendor Module creation failed: ' . $custom['output']);
    moduleCreateExpect(($custom['json']['module_key'] ?? null) === $customKey, 'custom creation returned another key');
    moduleCreateExpect(($custom['json']['vendor'] ?? null) === 'acme', 'custom vendor changed');

    $expectedFrontendFiles = ['contribution.ts', 'views/.gitkeep', 'api.ts', 'package.json'];
    $expectedTestFiles = ['TenantSecurityDriver.php', 'TenantSecurityTest.php'];
    foreach ([
        [$officialBackend, 'Generated' . ucfirst($suffix)],
        [$customBackend, 'CustomGenerated' . ucfirst($suffix)],
    ] as [$root, $moduleName]) {
        $expectedBackendFiles = [
            'module.json', 'src/ModuleProvider.php', 'src/Contract/' . $moduleName . 'Commands.php',
            'route/app.php',
            'resources/permissions.json', 'resources/menus.json',
            'resources/setting-definitions.json', 'database/migrations/README.md', 'composer.json',
        ];
        foreach ($expectedBackendFiles as $relative) {
            moduleCreateExpect(is_file($root . '/' . $relative), "generated backend file is missing: {$relative}");
        }
        moduleCreateExpect(!file_exists($root . '/Application/.gitkeep'), 'legacy Application scaffold path must be absent');
        foreach (['Controller', 'Validation', 'Service', 'Infrastructure', 'Model'] as $unusedLayer) {
            moduleCreateExpect(!file_exists($root . '/src/' . $unusedLayer), 'unused backend layer must not be generated: ' . $unusedLayer);
        }
    }
    foreach ([$officialFrontend, $customFrontend] as $root) {
        foreach ($expectedFrontendFiles as $relative) {
            moduleCreateExpect(is_file($root . '/' . $relative), "generated frontend file is missing: {$relative}");
        }
    }
    foreach ([$officialTests, $customTests] as $root) {
        foreach ($expectedTestFiles as $relative) {
            moduleCreateExpect(is_file($root . '/' . $relative), "generated test file is missing: {$relative}");
        }
        $securityTest = (string)file_get_contents($root . '/TenantSecurityTest.php');
        foreach (['tenant-a', 'tenant-b', 'forged Tenant ID', 'AUTHORIZATION_PERMISSION_DENIED',
            'MODULE_TENANT_DISABLED', 'failed migration', 'replayed'] as $scenario) {
            moduleCreateExpect(str_contains($securityTest, $scenario), "Tenant security scenario is missing: {$scenario}");
        }
    }

    $officialManifest = json_decode((string)file_get_contents($officialBackend . '/module.json'), true, 64, JSON_THROW_ON_ERROR);
    moduleCreateExpect(($officialManifest['frontend']['entry'] ?? null) === 'web/src/modules/' . $officialModuleKey->slug() . '/contribution.ts', 'frontend.entry is not key-derived');
    moduleCreateExpect(($officialManifest['backend']['migrations'] ?? null) === 'database/migrations', 'generated migrations declaration is missing');
    moduleCreateExpect(($officialManifest['backend']['setting_definitions'] ?? null) === 'resources/setting-definitions.json', 'generated setting definitions declaration is missing');
    moduleCreateExpect(($officialManifest['contracts']['exports'][0] ?? null) === 'PeanutAdmin\\Modules\\Generated' . ucfirst($suffix) . '\\Contract\\Generated' . ucfirst($suffix) . 'Commands', 'generated command contract export is missing');
    moduleCreateExpect(($officialManifest['lifecycle']['protected'] ?? null) === false, 'generated Module must be removable by default');
    $customComposer = json_decode((string)file_get_contents($customBackend . '/composer.json'), true, 32, JSON_THROW_ON_ERROR);
    moduleCreateExpect(($customComposer['autoload']['psr-4']['Acme\\Modules\\CustomGenerated' . ucfirst($suffix) . '\\'] ?? null) === 'src/', 'custom Composer namespace is not key-derived');
    $customProviderSource = (string)file_get_contents($customBackend . '/src/ModuleProvider.php');
    moduleCreateExpect(!str_contains($customProviderSource, '${'), 'backend template placeholder remains');
    require_once $customBackend . '/src/ModuleProvider.php';
    $customProviderClass = 'Acme\\Modules\\CustomGenerated' . ucfirst($suffix) . '\\ModuleProvider';
    moduleCreateExpect((new $customProviderClass())->bindings() === [], 'generated Module bindings must default to empty');
    moduleCreateExpect(!str_contains((string)file_get_contents($customFrontend . '/contribution.ts'), '${'), 'frontend template placeholder remains');
    moduleCreateExpect(!str_contains((string)file_get_contents($customTests . '/TenantSecurityTest.php'), '${'), 'test template placeholder remains');
    moduleCreateExpect(($custom['json']['test_path'] ?? null) === 'server/tests/Modules/Acme/CustomGenerated' . ucfirst($suffix), 'test path is not key-derived');

    $roots = (new DevelopmentModuleDiscovery($projectRoot))->moduleRoots();
    moduleCreateExpect(($roots[$officialKey] ?? null) === realpath($officialBackend), 'generated Official Module was not discovered');
    moduleCreateExpect(($roots[$customKey] ?? null) === realpath($customBackend), 'generated custom Module was not discovered');

    $vite = moduleCreateRunViteDiscovery($projectRoot);
    moduleCreateExpect($vite['code'] === 0, 'Vite dev did not discover generated contributions: ' . $vite['output']);

    $beforeDuplicate = moduleCreateTreeDigest($officialBackend) . moduleCreateTreeDigest($officialFrontend);
    $duplicate = moduleCreateRun($serverRoot, [$officialKey]);
    $afterDuplicate = moduleCreateTreeDigest($officialBackend) . moduleCreateTreeDigest($officialFrontend);
    moduleCreateExpect($duplicate['code'] === 1, 'duplicate Module creation was accepted');
    moduleCreateExpect(($duplicate['json']['error'] ?? null) === 'MODULE_CREATE_TARGET_EXISTS', 'duplicate failure code changed');
    moduleCreateExpect($beforeDuplicate === $afterDuplicate, 'duplicate creation changed the existing Module');

    // Official and peanut share PeanutAdmin\\Modules; custom vendors own separate prefixes.
    $namespaceCollision = moduleCreateRun($serverRoot, ['peanut.generated-' . $suffix, '--vendor=peanut']);
    moduleCreateExpect($namespaceCollision['code'] === 1, 'cross-vendor PHP namespace collision was accepted');
    moduleCreateExpect(($namespaceCollision['json']['error'] ?? null) === 'MODULE_CREATE_NAMESPACE_CONFLICT', 'namespace collision failure code changed');

    $invalid = moduleCreateRun($serverRoot, ['Invalid/../key']);
    moduleCreateExpect($invalid['code'] === 1, 'invalid Module key was accepted');
    moduleCreateExpect(($invalid['json']['error'] ?? null) === 'MODULE_CREATE_KEY_INVALID', 'invalid key failure code changed');

    echo "MODULE-CREATE-001 passed official={$officialKey} custom={$customKey}\n";
} finally {
    moduleCreateRemoveTree($officialBackend);
    moduleCreateRemoveTree($officialFrontend);
    moduleCreateRemoveTree($customBackend);
    moduleCreateRemoveTree($customFrontend);
    moduleCreateRemoveTree($officialTests);
    moduleCreateRemoveTree($customTests);
    if (!$customVendorRootExisted && is_dir($customVendorRoot) && (scandir($customVendorRoot) ?: []) === ['.', '..']) {
        rmdir($customVendorRoot);
    }
}
