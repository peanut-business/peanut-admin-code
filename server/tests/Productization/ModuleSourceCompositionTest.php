<?php

declare(strict_types=1);

use app\platform\services\module\ModulePackageAdoptionService;
use app\platform\services\module\ModulePackageArchiveService;
use app\platform\infrastructure\module\ModuleLockResolver;
use app\platform\infrastructure\module\ModulePackageSourcePromoter;
use app\platform\exception\module\ModulePackageException;

require dirname(__DIR__, 2) . '/vendor/autoload.php';
require_once dirname(__DIR__, 2) . '/vendor/topthink/framework/src/helper.php';

// Local canonical packages exercise native composition, not public Release provenance or real installation.
$server = dirname(__DIR__, 2);
$temporary = sys_get_temp_dir() . '/pa-composition-' . bin2hex(random_bytes(8));
mkdir($temporary, 0700, true);
$temporary = realpath($temporary);
$host = $temporary . '/host';
$assertions = 0;
function compositionExpect(bool $condition, string $message): void
{
    global $assertions;
    ++$assertions;
    if (!$condition) { throw new RuntimeException($message); }
}
function compositionJson(string $path, array $value): void
{
    file_put_contents($path, json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n");
}
function compositionHostSources(string $server, string $target): void
{
    mkdir($target . '/.git', 0700, true);
    mkdir($target . '/scaffold', 0700, true);
    foreach (['scaffold/application-template-inventory.json', 'scaffold/edition-profiles.json', 'release-versions.json'] as $path) {
        copy(dirname($server) . '/' . $path, $target . '/' . $path);
    }
    foreach (['migrations', 'installation'] as $directory) {
        mkdir($target . '/server/database/' . $directory, 0700, true);
        foreach (glob($server . '/database/' . $directory . '/*.php') as $file) {
            copy($file, $target . '/server/database/' . $directory . '/' . basename($file));
        }
    }
}
function compositionRejected(callable $operation, string $code): void
{
    try { $operation(); } catch (ModulePackageException $exception) {
        compositionExpect($exception->errorCode === $code, 'Unexpected rejection: ' . $exception->errorCode);
        return;
    }
    throw new RuntimeException('Expected rejection: ' . $code);
}
try {
    foreach (['config', 'database', 'resources/schemas'] as $path) { mkdir($host . '/server/' . $path, 0700, true); }
    foreach (['config/module_composition.json', 'config/modules.php', 'database/InitialInstallationSource.php',
        'resources/schemas/module-artifact.schema.json', 'composer.json'] as $path) {
        copy($server . '/' . $path, $host . '/server/' . $path);
    }
    $app = new \think\App($host . '/server');
    $app->config->set(['mode' => 'multi-tenant'], 'deployment');
    $app->env->set('PEANUT_INSTALLATION_SOURCE_MODE', 'development');
    compositionHostSources($server, $host);
    $service = new ModulePackageAdoptionService($host . '/server', [], 'development');
    $archive = new ModulePackageArchiveService($server);
    require_once $host . '/server/database/InitialInstallationSource.php';
    $keys = InitialInstallationSource::modules($server);
    $rows = [];
    $archives = [];
    foreach ($keys as $key) {
        $path = $temporary . '/' . $key . '.tar';
        $packed = $archive->packModule($key, $path);
        $source = $server . '/app/modules/' . str_replace(['.', '-'], ['/', '_'], $key);
        $manifest = json_decode((string) file_get_contents($source . '/module.json'), true, 512, JSON_THROW_ON_ERROR);
        $rows[$key] = ['key' => $key, 'version' => $packed['version'], 'asset' => $key . '-' . $packed['version'] . '.tar',
            'sha256' => $packed['sha256'], 'bytes' => filesize($path), 'license' => $manifest['license'],
            'editions' => \app\common\module\PackageCheck::editions($source), 'kernel_constraint' => $manifest['kernel_constraint'],
            'dependencies' => $manifest['dependencies'] ?? []];
        $archives[$key] = $path;
    }
    $index = ['schema_version' => 1, 'protocol' => 'peanut.module-release-index.v1',
        'repository' => 'peanut-opensource/peanut-module-code', 'tag' => 'local-composition-fixture',
        'commit' => str_repeat('a', 40), 'modules' => array_values($rows)];
    $indexPath = $temporary . '/index.json';
    compositionJson($indexPath, $index);
    $request = ['schema_version' => 1, 'protocol' => 'peanut.module-source-composition-request.v1', 'edition' => 'multi-tenant',
        'preset' => 'minimal', 'optional' => [], 'composition_sha256' => hash_file('sha256', $host . '/server/config/module_composition.json'),
        'index' => ['path' => $indexPath, 'sha256' => hash_file('sha256', $indexPath),
            'repository' => $index['repository'], 'tag' => $index['tag'], 'commit' => $index['commit']], 'archives' => $archives];
    $seal = static function (array $document) use ($indexPath, $request): array {
        compositionJson($indexPath, $document);
        $next = $request;
        $next['index']['sha256'] = hash_file('sha256', $indexPath);
        return $next;
    };
    $plan = $service->compose($request);
    compositionExpect($plan['plan']['status'] === 'ready', json_encode($plan['plan']['diagnostics']));
    $expectedRoots = InitialInstallationSource::composition($host . '/server')['mandatory'];
    sort($expectedRoots, SORT_STRING);
    compositionExpect($plan['plan']['roots'] === $expectedRoots, 'Plan lost selected roots');
    compositionExpect(count($plan['plan']['modules']) === count($keys), 'Closure is incomplete');
    compositionExpect($plan['plan']['dependency_order'] === $keys, 'Native dependency order differs');
    compositionExpect(!file_exists($host . '/.local') && !file_exists($host . '/modules.lock'), 'Planning changed application files');
    compositionExpect($service->compose($request)['plan_sha256'] === $plan['plan_sha256'], 'Plan is not deterministic');
    $missing = $request;
    $missing['archives'] = [];
    compositionExpect(count($service->compose($missing)['plan']['diagnostics']) >= count($keys), 'Missing TARs were not reported together');
    compositionRejected(fn() => $service->compose($request, str_repeat('0', 64)), 'MODULE_COMPOSITION_PLAN_CHANGED');
    compositionExpect(!file_exists($host . '/modules.lock') && !file_exists($host . '/server/app/modules'), 'Rejected plan exposed sources');
    $moduleMigration = array_values(array_filter($plan['plan']['migrations'], static fn(array $source): bool => $source['module_key'] !== null))[0];
    $moduleDirectory = $server . '/app/modules/' . str_replace(['.', '-'], ['/', '_'], $moduleMigration['module_key']);
    $collisionSource = glob($moduleDirectory . '/database/migrations/' . $moduleMigration['migration_key'] . '_*.php')[0];
    $hostMigration = $host . '/server/database/migrations/' . basename($collisionSource);
    copy($collisionSource, $hostMigration);
    compositionExpect(in_array('MODULE_MIGRATION_IDENTITY_CONFLICT', array_column($service->compose($request)['plan']['diagnostics'], 'code'), true),
        'Host migration collision was not admitted before promotion');
    unlink($hostMigration);
    $wrongIndex = $request;
    $wrongIndex['index']['sha256'] = str_repeat('0', 64);
    compositionRejected(fn() => $service->compose($wrongIndex), 'MODULE_COMPOSITION_INDEX_INVALID');
    $optional = $request;
    $optional['optional'] = ['peanut.demo'];
    compositionRejected(fn() => $service->compose($optional), 'MODULE_COMPOSITION_SELECTION_INVALID');

    $bad = $index;
    foreach ($bad['modules'] as &$row) {
        if ($row['key'] === 'peanut.content-tools') {
            $row['dependencies'] = [['module_key' => 'peanut.missing-one', 'version' => '^1'],
                ['module_key' => 'peanut.missing-two', 'version' => '^1']];
        }
    }
    unset($row);
    $blocked = $service->compose($seal($bad));
    compositionExpect(count(array_filter($blocked['plan']['diagnostics'], static fn(array $d): bool => $d['code'] === 'MODULE_PACKAGE_DEPENDENCY_MISSING')) === 2,
        'Missing edges were not aggregated');
    $cycle = $index;
    foreach ($cycle['modules'] as &$row) {
        if ($row['key'] === 'peanut.content-tools') { $row['dependencies'] = [['module_key' => 'peanut.identity', 'version' => '*']]; }
        if ($row['key'] === 'peanut.identity') { $row['dependencies'] = [['module_key' => 'peanut.content-tools', 'version' => '*']]; }
    }
    unset($row);
    compositionExpect(in_array('MODULE_PACKAGE_DEPENDENCY_CYCLE', array_column($service->compose($seal($cycle))['plan']['diagnostics'], 'code'), true), 'Cycle admitted');
    $conflict = $index;
    foreach ($conflict['modules'] as &$row) {
        if ($row['key'] === 'peanut.file') { $row['dependencies'] = [['module_key' => 'peanut.identity', 'version' => '^99.0']]; }
    }
    unset($row);
    compositionExpect(in_array('MODULE_PACKAGE_DEPENDENCY_INCOMPATIBLE', array_column($service->compose($seal($conflict))['plan']['diagnostics'], 'code'), true), 'Incompatible graph admitted');
    $request = $seal($index);

    // Exercise the shipped bridge with no Module sources and traps on runtime/DB bootstrap.
    $bridge = $temporary . '/bridge';
    foreach (['config', 'database', 'resources/schemas', 'app/command', 'vendor/topthink/framework/src'] as $path) {
        mkdir($bridge . '/server/' . $path, 0700, true);
    }
    foreach (['module', 'app/command/ModuleAdoptPackage.php', 'config/peanut.php', 'config/deployment.php', 'config/module_packages.php',
        'config/modules.php', 'config/module_composition.json', 'database/InitialInstallationSource.php',
        'resources/schemas/module-artifact.schema.json', 'composer.json'] as $path) {
        copy($server . '/' . $path, $bridge . '/server/' . $path);
    }
    compositionHostSources($server, $bridge);
    file_put_contents($bridge . '/server/vendor/topthink/framework/src/helper.php', '<?php require_once '
        . var_export($server . '/vendor/topthink/framework/src/helper.php', true) . ';');
    file_put_contents($bridge . '/server/vendor/autoload.php', '<?php $loader = require '
        . var_export($server . '/vendor/autoload.php', true) . '; $loader->addClassMap('
        . var_export(['app\\command\\ModuleAdoptPackage' => $bridge . '/server/app/command/ModuleAdoptPackage.php'], true) . ');'
        . 'spl_autoload_register(static function(string $class): void {'
        . 'if ($class === "app\\\\AppService" || str_starts_with($class, "PeanutAdmin\\\\Modules\\\\")'
        . ' || str_starts_with($class, "think\\\\db\\\\")) { throw new RuntimeException("RUNTIME_OR_DB_BOOTED:" . $class); }'
        . '}, true, true); return $loader;');
    file_put_contents($bridge . '/server/config/database.php', '<?php throw new RuntimeException("DATABASE_CONFIG_READ");');
    file_put_contents($bridge . '/server/.env', "APP_ENV=development\nDEPLOYMENT_MODE=multi-tenant\nPEANUT_INSTALLATION_SOURCE_MODE=development\n");
    chmod($bridge . '/server/.env', 0600);
    $requestPath = $temporary . '/request.json';
    compositionJson($requestPath, $request);
    $invoke = static function(array $arguments) use ($bridge): array {
        $process = proc_open([PHP_BINARY, $bridge . '/server/module'], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes,
            $bridge, ['PATH' => (string) getenv('PATH'), 'TMPDIR' => sys_get_temp_dir()]);
        if (!is_resource($process)) { throw new RuntimeException('Cannot start native bridge'); }
        fwrite($pipes[0], json_encode(['operation' => 'adopt', 'arguments' => $arguments], JSON_THROW_ON_ERROR));
        fclose($pipes[0]);
        $output = stream_get_contents($pipes[1]);
        $error = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exit = proc_close($process);
        compositionExpect($exit === 0, 'Native bridge failed: ' . $output . $error);
        return json_decode($output, true, 512, JSON_THROW_ON_ERROR);
    };
    $nativePlan = $invoke(['--composition', $requestPath]);
    compositionExpect($nativePlan['plan']['status'] === 'ready' && !file_exists($bridge . '/.local'), 'Bridge plan booted runtime or mutated sources');
    $nativeApply = $invoke(['--composition', $requestPath, '--plan-sha256', $nativePlan['plan_sha256']]);
    compositionExpect($nativeApply['status'] === 'source-prepared' && !file_exists($bridge . '/server/private'), 'Bridge crossed first-install boundary');

    $applied = $service->compose($request, $plan['plan_sha256']);
    compositionExpect($applied['status'] === 'source-prepared' && $applied['plan']['runtime_database_mutated'] === false
        && !$applied['plan']['tenant_modules_enabled'] && !$applied['plan']['rbac_granted'], 'Source preparation crossed runtime boundary');
    compositionExpect(count((new ModuleLockResolver($host . '/server', '../modules.lock'))->all()) === count($keys), 'Final lock is incomplete');
    $receipts = glob($host . '/.local/module-source-adoption/*/transaction.json');
    compositionExpect(count($receipts) === 1, 'Graph was split into per-package transactions');
    $receiptPath = $receipts[0];
    $receipt = json_decode((string) file_get_contents($receiptPath), true, 512, JSON_THROW_ON_ERROR);
    compositionExpect(count($receipt['archives']) === count($keys) && $receipt['plan_sha256'] === $plan['plan_sha256'], 'Batch pins lost');
    $again = $service->compose($request);
    compositionExpect($again['plan']['status'] === 'ready' && $again['plan']['writes'] === [], 'Repeat adoption is not a no-op');
    compositionExpect(array_unique(array_column($again['plan']['modules'], 'action')) === ['unchanged'], 'Same-version identity lost');
    $descriptors = (new ModuleLockResolver($host . '/server', '../modules.lock'))->all();
    $previous = $descriptors['peanut.content-tools'];
    // A backend-only previous release cannot grant ownership of an existing client directory.
    $backendOnly = new \app\platform\value\module\ModuleDescriptor($previous->key, $previous->version,
        $previous->source, $previous->lockDigest, $previous->manifestDigest, $previous->composer, [], [], $previous->moduleRoots, $previous->trust);
    $verified = (new ModulePackageArchiveService($host . '/server'))->verify($archives['peanut.content-tools'],
        $rows['peanut.content-tools']['sha256'], [], null, [], true);
    try {
        compositionRejected(fn() => (new ModulePackageSourcePromoter($host . '/server'))->planSources([
            ['package' => $verified, 'previous' => $backendOnly]]), 'MODULE_PACKAGE_TARGET_CONFLICT');
    } finally {
        (new ModulePackageArchiveService($host . '/server'))->cleanup($verified);
    }

    $lower = $index;
    foreach ($lower['modules'] as &$row) {
        if ($row['key'] === 'peanut.content-tools') { $row['version'] = '1.0.1'; $row['asset'] = $row['key'] . '-1.0.1.tar'; }
    }
    unset($row);
    $retained = $service->compose($seal($lower));
    $byKey = array_column($retained['plan']['modules'], null, 'key');
    compositionExpect($retained['plan']['status'] === 'ready' && $byKey['peanut.content-tools']['action'] === 'retain-higher'
        && $byKey['peanut.content-tools']['version'] === $rows['peanut.content-tools']['version'], 'Higher source was not retained');

    // Distinct transport bytes with the same canonical payload must also be rejected.
    $pair = sodium_crypto_sign_keypair();
    $resignedPath = $temporary . '/resigned.tar';
    $resigned = $archive->packModule('peanut.content-tools', $resignedPath,
        ['key_id' => 'test', 'secret_key' => sodium_crypto_sign_secretkey($pair)]);
    $changed = $index;
    foreach ($changed['modules'] as &$row) {
        if ($row['key'] === 'peanut.content-tools') { $row['sha256'] = $resigned['sha256']; $row['bytes'] = filesize($resignedPath); }
    }
    unset($row);
    $changedRequest = $seal($changed);
    $changedRequest['archives']['peanut.content-tools'] = $resignedPath;
    compositionExpect(in_array('PACKAGE_VERSION_IDENTITY_CONFLICT', array_column($service->compose($changedRequest)['plan']['diagnostics'], 'code'), true), 'Same-version transport change admitted');
    $request = $seal($index);

    // Reconcile an interrupted batch through the original journal; do not replay an install.
    $scope = $receipt['entries'][0]['scope'];
    $payload = dirname($receiptPath) . '/payload/' . $scope;
    if (!is_dir(dirname($payload))) { mkdir(dirname($payload), 0700, true); }
    rename($host . '/' . $scope, $payload);
    copy($receiptPath, $host . '/.local/module-source-adoption/journal.json');
    $state = (new ModulePackageSourcePromoter($host . '/server'))->recoveryState();
    compositionExpect($state['status'] === 'pending' && $state['transaction'] === $receipt['transaction'] && $state['completed_writes'] !== [], 'Recovery handle lost');
    compositionRejected(fn() => $service->compose($request), 'MODULE_PACKAGE_RECOVERY_REQUIRED');
    compositionExpect($service->recover()['transaction'] === $receipt['transaction'], 'Recovery changed operation identity');
    compositionExpect($service->compose($request)['plan']['status'] === 'ready', 'Recovered source/lock is invalid');
    mkdir($host . '/server/private/installation', 0700, true);
    compositionJson($host . '/server/private/installation/installed.json', []);
    compositionRejected(fn() => $service->compose($request), 'MODULE_COMPOSITION_UNINSTALLED_REQUIRED');
    echo 'MODULE-SOURCE-COMPOSITION passed ' . $assertions . " assertions (filesystem only; no DB/HTTP)\n";
} finally {
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($temporary, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($iterator as $entry) { $entry->isDir() && !$entry->isLink() ? rmdir($entry->getPathname()) : unlink($entry->getPathname()); }
    rmdir($temporary);
}
