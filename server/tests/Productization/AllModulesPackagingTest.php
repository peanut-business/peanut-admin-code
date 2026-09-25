<?php

declare(strict_types=1);

use app\platform\service\plugin\ModulePackagePreflight;
use app\platform\service\plugin\PluginPackageArchiveService;
use PeanutAdmin\Kernel\Module\ModuleKey;

require dirname(__DIR__, 2) . '/vendor/autoload.php';

function allModulesPackagingExpect(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

/** @param list<string> $command */
function allModulesPackagingRun(array $command, ?string $cwd = null): string
{
    $pipes = [];
    $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $cwd);
    if (!is_resource($process)) {
        throw new RuntimeException('unable to start packaging evidence identity command');
    }
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $code = proc_close($process);
    if ($code !== 0) {
        throw new RuntimeException('packaging evidence identity command failed: ' . trim((string) $stderr));
    }
    return trim((string) $stdout);
}

/** @return array{files:int,sha256:string} */
function allModulesPackagingDistFacts(string $root): array
{
    if (!is_dir($root)) {
        throw new RuntimeException('Production Web build output is missing: ' . $root);
    }
    $rows = [];
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
    );
    foreach ($iterator as $file) {
        if ($file->isLink() || !$file->isFile()) {
            throw new RuntimeException('Production Web build output contains a non-regular entry');
        }
        $relative = str_replace('\\', '/', substr($file->getPathname(), strlen($root) + 1));
        $digest = hash_file('sha256', $file->getPathname());
        if (!is_string($digest)) {
            throw new RuntimeException('Production Web build output digest is unavailable: ' . $relative);
        }
        $rows[] = $relative . "\0" . $digest . "\0" . ($file->getPerms() & 0777);
    }
    sort($rows, SORT_STRING);
    return ['files' => count($rows), 'sha256' => hash('sha256', implode("\n", $rows))];
}

$serverRoot = dirname(__DIR__, 2);
$projectRoot = dirname($serverRoot);
$resultsPath = $argv[1] ?? '/tmp/module-packages-test/packaging-results.json';
$currentCandidate = allModulesPackagingRun(['git', '-C', $projectRoot, 'rev-parse', 'HEAD^{commit}']);
$currentTree = allModulesPackagingRun(['git', '-C', $projectRoot, 'rev-parse', 'HEAD^{tree}']);
$pluginLock = json_decode(
    (string) file_get_contents($projectRoot . '/plugins.lock'),
    true,
    64,
    JSON_THROW_ON_ERROR,
);
allModulesPackagingExpect(
    is_array($pluginLock['plugins'] ?? null) && array_is_list($pluginLock['plugins']),
    'Bundled Plugin lock is invalid',
);
$expectedModules = [];
foreach ($pluginLock['plugins'] as $plugin) {
    allModulesPackagingExpect(is_array($plugin), 'Bundled Plugin lock entry is invalid');
    foreach ($plugin['modules'] ?? [] as $module) {
        $moduleKey = is_array($module) ? ($module['key'] ?? null) : null;
        if (!is_string($moduleKey) || !str_starts_with($moduleKey, 'official.')) {
            continue;
        }
        allModulesPackagingExpect(!isset($expectedModules[$moduleKey]), "Bundled official Module is duplicated: {$moduleKey}");
        $expectedModules[$moduleKey] = true;
    }
}
$expectedModules = array_keys($expectedModules);
sort($expectedModules, SORT_STRING);
allModulesPackagingExpect($expectedModules !== [], 'Bundled official Module inventory is empty');

allModulesPackagingExpect(is_file($resultsPath), "Packaging result evidence is missing: {$resultsPath}");
$evidence = json_decode((string) file_get_contents($resultsPath), true, 64, JSON_THROW_ON_ERROR);
allModulesPackagingExpect(is_array($evidence) && !array_is_list($evidence), 'Packaging result evidence root is invalid');
allModulesPackagingExpect(
    is_string($evidence['candidate'] ?? null)
        && preg_match('/^[a-f0-9]{40}$/D', $evidence['candidate']) === 1,
    'Packaging candidate identity is invalid',
);
allModulesPackagingExpect(
    hash_equals($currentCandidate, (string) $evidence['candidate'])
        && hash_equals($currentTree, (string) ($evidence['source_tree'] ?? '')),
    'Packaging evidence does not describe the current source commit/tree',
);

$results = $evidence['results'] ?? null;
allModulesPackagingExpect(is_array($results) && array_is_list($results), 'Packaging results are invalid');
$resultsByModule = [];
foreach ($results as $result) {
    allModulesPackagingExpect(is_array($result), 'Packaging result row is invalid');
    $moduleKey = $result['module_key'] ?? null;
    allModulesPackagingExpect(is_string($moduleKey) && !isset($resultsByModule[$moduleKey]), 'Packaging result identity is invalid');
    $resultsByModule[$moduleKey] = $result;
}
$resultModules = array_keys($resultsByModule);
sort($resultModules, SORT_STRING);
allModulesPackagingExpect($resultModules === $expectedModules, 'Official Module packaging evidence is incomplete or contains an unknown Module');

$availableVersions = [];
$sourcePreflight = new ModulePackagePreflight($projectRoot);
foreach ($expectedModules as $moduleKey) {
    $availableVersions[$moduleKey] = $sourcePreflight->inspect($moduleKey)['version'];
}

$archiveService = new PluginPackageArchiveService($serverRoot);
foreach ($expectedModules as $moduleKey) {
    $result = $resultsByModule[$moduleKey];
    allModulesPackagingExpect(($result['exit_code'] ?? null) === 0, "Module package failed: {$moduleKey}");
    allModulesPackagingExpect(($result['error'] ?? null) === null, "Module package recorded an error: {$moduleKey}");

    $archivePath = $result['tar_path'] ?? null;
    $expectedSize = $result['tar_size'] ?? null;
    $expectedSha256 = $result['sha256'] ?? null;
    allModulesPackagingExpect(is_string($archivePath) && is_file($archivePath), "Module package is missing: {$moduleKey}");
    allModulesPackagingExpect(is_int($expectedSize) && filesize($archivePath) === $expectedSize, "Module package size differs: {$moduleKey}");
    allModulesPackagingExpect(
        is_string($expectedSha256)
            && preg_match('/^[a-f0-9]{64}$/D', $expectedSha256) === 1
            && hash_equals($expectedSha256, (string) hash_file('sha256', $archivePath)),
        "Module package SHA-256 differs: {$moduleKey}",
    );

    $verified = $archiveService->verify($archivePath, $expectedSha256, [], null, $availableVersions);
    try {
        allModulesPackagingExpect($verified->packageKey === $moduleKey, "Plugin identity differs: {$moduleKey}");
        allModulesPackagingExpect($verified->manifestRelative === "plugins/{$moduleKey}/plugin.json", "Plugin manifest path differs: {$moduleKey}");
        allModulesPackagingExpect(isset($verified->inventory[$verified->manifestRelative]), "Plugin manifest is absent from the verified inventory: {$moduleKey}");
        allModulesPackagingExpect(array_keys($verified->modules) === [$moduleKey], "Single-Module package has another Module identity: {$moduleKey}");

        $module = $verified->modules[$moduleKey];
        $manifest = $module['manifest']->data;
        foreach (($manifest['catalog']['permissions'] ?? []) as $permission) {
            $permissionKey = is_array($permission) ? ($permission['key'] ?? null) : null;
            allModulesPackagingExpect(
                is_string($permissionKey) && str_starts_with($permissionKey, $moduleKey . '.'),
                "Permission escaped the Module namespace: {$moduleKey}",
            );
        }

        $frontendEntry = $manifest['frontend']['entry'] ?? null;
        if ($frontendEntry !== null) {
            $derivedEntry = 'web/src/modules/' . ModuleKey::fromString($moduleKey)->slug() . '/contribution.ts';
            allModulesPackagingExpect($frontendEntry === $derivedEntry, "frontend.entry is not key-derived: {$moduleKey}");
            allModulesPackagingExpect(isset($verified->inventory[$derivedEntry]), "Frontend entry is absent from the verified inventory: {$moduleKey}");
        }
    } finally {
        $archiveService->cleanup($verified);
    }
}

$productionBuild = $evidence['production_build'] ?? null;
allModulesPackagingExpect(is_array($productionBuild), 'Production build evidence is missing');
allModulesPackagingExpect(
    ($productionBuild['command'] ?? null) === 'pnpm --dir web build'
        && ($productionBuild['install_command'] ?? null) === 'pnpm --dir web install --frozen-lockfile'
        && ($productionBuild['install_environment'] ?? null) === ['HUSKY' => '0', 'CI' => '1']
        && ($productionBuild['candidate'] ?? null) === $currentCandidate
        && ($productionBuild['source_tree'] ?? null) === $currentTree
        && ($productionBuild['dist_path'] ?? null) === 'web/dist',
    'Production Web build evidence identity is invalid',
);
allModulesPackagingExpect(($productionBuild['exit_code'] ?? null) === 0, 'Production Web build failed');
allModulesPackagingExpect(is_int($productionBuild['files'] ?? null), 'Production Web file count is invalid');
allModulesPackagingExpect(
    is_string($productionBuild['tree_sha256'] ?? null)
        && preg_match('/^[a-f0-9]{64}$/D', $productionBuild['tree_sha256']) === 1,
    'Production Web tree digest is invalid',
);
$distFacts = allModulesPackagingDistFacts($projectRoot . '/web/dist');
allModulesPackagingExpect(
    $productionBuild['files'] === $distFacts['files']
        && hash_equals((string) $productionBuild['tree_sha256'], $distFacts['sha256']),
    'Production Web build evidence does not match web/dist',
);
allModulesPackagingExpect(($productionBuild['filename_dev_tools_hits'] ?? null) === [], 'Production bundle contains a dev-tools filename');
allModulesPackagingExpect(($productionBuild['symbol_hits'] ?? null) === [], 'Production bundle contains a dev-tools symbol');

echo 'ALL-MODULES-PACKAGING-001 passed modules=' . count($expectedModules)
    . ' candidate=' . $evidence['candidate'] . "\n";
