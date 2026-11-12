<?php

declare(strict_types=1);

use app\platform\exception\module\ModuleLifecycleException;
use app\platform\exception\module\ModulePackageException;
use app\platform\infrastructure\module\DeterministicTarArchive;
use app\platform\infrastructure\module\ModuleArtifactWriter;
use app\platform\infrastructure\module\ModuleLockResolver;
use app\platform\services\module\ModulePackageAdoptionService;
use app\platform\services\module\ModulePackageArchiveService;
use app\common\module\PackageCheck as ModulePackagePreflight;

require ((getenv('PEANUT_TEST_APPLICATION_ROOT') ?: dirname(__DIR__, 3)) . '/server') . '/vendor/autoload.php';

// 默认保留完整测试。只读源码联调可显式只跑归档/独立接收/文件恢复，绝不触碰宿主运行日志。
$arguments = realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__
    ? array_slice($argv ?? [], 1) : [];
if ($arguments !== [] && $arguments !== ['--archive-only']) {
    throw new InvalidArgumentException('Usage: ModulePackageArchiveTest.php [--archive-only]');
}
$archiveOnly = $arguments === ['--archive-only'];

function modulePackageExpect(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

/** @param callable():void $operation */
function modulePackageRejects(callable $operation, string $errorCode): ModulePackageException
{
    try {
        $operation();
        throw new RuntimeException("Expected package rejection: {$errorCode}");
    } catch (ModulePackageException $exception) {
        $previous = $exception->getPrevious();
        modulePackageExpect(
            $exception->errorCode === $errorCode,
            "Unexpected package rejection: {$exception->errorCode}"
                . ($previous === null ? '' : ' (' . $previous->getMessage() . ')'),
        );
        return $exception;
    }
}

/** The native bridge returns structured failure and recovery identity, never a bare error object. */
function modulePackageNativeFailure(string $output, string $code): bool
{
    $payload = json_decode($output, true);
    return is_array($payload) && ($payload['schema_version'] ?? null) === 1
        && ($payload['error'] ?? null) === $code
        && is_string($payload['message'] ?? null)
        && is_array($payload['recovery'] ?? null);
}

function modulePackageCopyTree(string $source, string $target): void
{
    mkdir($target, 0777, true);
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($source, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST,
    );
    foreach ($iterator as $entry) {
        $relative = substr($entry->getPathname(), strlen($source) + 1);
        $destination = $target . '/' . $relative;
        if ($entry->isDir()) {
            if (!is_dir($destination)) {
                mkdir($destination, 0777, true);
            }
        } else {
            copy($entry->getPathname(), $destination);
        }
    }
}

function modulePackageRemoveTree(string $path): void
{
    if (!is_dir($path)) {
        if (is_file($path)) {
            unlink($path);
        }
        return;
    }
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST,
    );
    foreach ($iterator as $entry) {
        $entry->isDir() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
    }
    rmdir($path);
}

function modulePackageRewriteTree(string $root, array $replacements): void
{
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
    );
    foreach ($iterator as $entry) {
        if (!$entry->isFile()) {
            continue;
        }
        $contents = (string) file_get_contents($entry->getPathname());
        file_put_contents($entry->getPathname(), str_replace(array_keys($replacements), array_values($replacements), $contents));
    }
}

$serverRoot = ((getenv('PEANUT_TEST_APPLICATION_ROOT') ?: dirname(__DIR__, 3)) . '/server');
$projectRoot = dirname($serverRoot);
$temporary = sys_get_temp_dir() . '/pa-module-package-' . bin2hex(random_bytes(8));
mkdir($temporary, 0700, true);
// Source-only adoption still uses the native App configuration for Edition selection.
$application = new \think\App($temporary . '/test-host/');
$application->config->set(['mode' => 'multi-tenant'], 'deployment');

try {
    // A real private Module namespace participates in the deployment lock; fixture.* is intentionally excluded.
    $authorRoot = $temporary . '/author';
    modulePackageCopyTree($projectRoot . '/server/fixtures/modules/delivery_record', $authorRoot . '/server/app/modules/acme/delivery_record');
    modulePackageCopyTree($projectRoot . '/web/tests/fixtures/modules/fixture-delivery-record', $authorRoot . '/web/src/modules/acme-delivery-record');
    $fromNamespace = implode(chr(92), ['PeanutAdmin', 'Fixtures', 'DeliveryRecord']);
    $toNamespace = implode(chr(92), ['Acme', 'Modules', 'DeliveryRecord']);
    modulePackageRewriteTree($authorRoot, [
        'fixture.delivery-record' => 'acme.delivery-record',
        'fixture-delivery-record' => 'acme-delivery-record',
        trim(json_encode($fromNamespace), '"') => trim(json_encode($toNamespace), '"'),
        $fromNamespace => $toNamespace,
    ]);
    mkdir($authorRoot . '/server/resources/schemas', 0700, true);
    copy($serverRoot . '/resources/schemas/module-artifact.schema.json', $authorRoot . '/server/resources/schemas/module-artifact.schema.json');
    $service = new ModulePackageArchiveService($authorRoot . '/server');
    $first = $temporary . '/fixture-a.tar';
    $second = $temporary . '/fixture-b.tar';
    $packedA = $service->packModule('acme.delivery-record', $first);
    $packedB = $service->packModule('acme.delivery-record', $second);
    modulePackageExpect($packedA['sha256'] === $packedB['sha256'], 'same Module tree did not produce a deterministic tar');

    $tar = new DeterministicTarArchive();
    $repeated = $service->packModule('acme.delivery-record', $first);
    modulePackageExpect($repeated['sha256'] === $packedA['sha256'], 'identical output was not reusable');
    modulePackageRejects(
        static fn() => $tar->write($first, ['other.txt' => ['contents' => 'different payload']]),
        'PACKAGE_VERSION_IDENTITY_CONFLICT',
    );
    modulePackageExpect(hash_file('sha256', $first) === $packedA['sha256'], 'existing archive was replaced');
    $entries = $tar->scan($first);
    modulePackageExpect(isset($entries['META-INF/files.sha256']), 'package inventory is missing');
    $inventory = $tar->read($first, $entries['META-INF/files.sha256']);
    modulePackageExpect(str_contains($inventory, "\0"), 'package inventory does not use path+NUL+sha256 rows');
    modulePackageExpect(
        count(array_filter(array_keys($entries), static fn(string $path): bool => str_ends_with($path, '/module.json'))) === 1,
        'single-Module package contains a second manifest',
    );

    $verified = $service->verify($first, $packedA['sha256'], [], null, []);
    modulePackageExpect($verified->packageKey === 'acme.delivery-record', 'verified package identity changed');
    modulePackageExpect($verified->dependencyOrder === ['acme.delivery-record'], 'single-Module dependency order changed');
    $service->cleanup($verified);

    modulePackageRejects(
        static fn() => $service->verify($first, str_repeat('0', 64), [], null, []),
        'MODULE_PACKAGE_ARCHIVE_DIGEST_MISMATCH',
    );
    modulePackageRejects(
        static fn() => $service->verify($first, null, [], null, []),
        'MODULE_PACKAGE_SOURCE_UNTRUSTED',
    );

    // Negative fixture: recompute only this synthetic envelope/inventory, retaining valid Module bytes.
    $splitVersionEntries = [];
    foreach ($entries as $path => $entry) {
        if ($path === 'META-INF/files.sha256' || str_starts_with($path, 'META-INF/signatures/')) { continue; }
        $contents = $tar->read($first, $entry);
        if ($path === 'server/app/modules/acme/delivery_record/module.json') {
            $document = json_decode($contents, true, 128, JSON_THROW_ON_ERROR);
            $document['key'] = 'acme.wrong';
            $contents = json_encode($document, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
        }
        $splitVersionEntries[$path] = ['contents' => $contents];
    }
    ksort($splitVersionEntries, SORT_STRING);
    $splitInventory = '';
    foreach ($splitVersionEntries as $path => $entry) { $splitInventory .= $path . "\0" . hash('sha256', $entry['contents']) . "\n"; }
    $splitVersionEntries['META-INF/files.sha256'] = ['contents' => $splitInventory];
    $splitArchive = $temporary . '/split-version.tar';
    $tar->write($splitArchive, $splitVersionEntries);
    modulePackageRejects(
        static fn() => $service->verify($splitArchive, hash_file('sha256', $splitArchive), [], null, []),
        'MODULE_PACKAGE_IDENTITY_MISMATCH',
    );

    $keypair = sodium_crypto_sign_keypair();
    $secret = sodium_crypto_sign_secretkey($keypair);
    $public = sodium_crypto_sign_publickey($keypair);
    $signedPath = $temporary . '/fixture-signed.tar';
    $service->packModule('acme.delivery-record', $signedPath, ['key_id' => 'fixture-release', 'secret_key' => $secret]);
    $signed = $service->verify($signedPath, null, ['fixture-release' => $public], 'fixture-release', []);
    modulePackageExpect($signed->packageKey === 'acme.delivery-record', 'signed package did not verify');
    $service->cleanup($signed);

    $tamperedEntries = [];
    foreach ($entries as $path => $entry) {
        $contents = $tar->read($first, $entry);
        if ($path === 'web/src/modules/acme-delivery-record/contribution.ts') {
            $contents .= "\n// tampered\n";
        }
        $tamperedEntries[$path] = ['contents' => $contents];
    }
    $tamperedPath = $temporary . '/fixture-tampered.tar';
    $tar->write($tamperedPath, $tamperedEntries);
    modulePackageRejects(
        static fn() => $service->verify($tamperedPath, hash_file('sha256', $tamperedPath), [], null, []),
        'MODULE_PACKAGE_FILE_DIGEST_MISMATCH',
    );
    modulePackageRejects(
        static fn() => $tar->write($temporary . '/unsafe.tar', ['../escape' => ['contents' => 'x']]),
        'MODULE_PACKAGE_PATH_INVALID',
    );

    $fixtureBackend = $authorRoot . '/server/app/modules/acme/delivery_record';
    $fixtureFrontend = $authorRoot . '/web/src/modules/acme-delivery-record';
    $badRoot = $temporary . '/bad-project';
    modulePackageCopyTree($fixtureBackend, $badRoot . '/server/app/modules/acme/delivery_record');
    modulePackageCopyTree($fixtureFrontend, $badRoot . '/web/src/modules/acme-delivery-record');
    $badManifestPath = $badRoot . '/server/app/modules/acme/delivery_record/module.json';
    $badManifest = json_decode((string) file_get_contents($badManifestPath), true, 64, JSON_THROW_ON_ERROR);
    $badManifest['frontend']['entry'] = 'web/src/modules/acme-delivery-record/index.ts';
    file_put_contents($badManifestPath, json_encode($badManifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n");
    modulePackageRejects(
        static fn() => (new ModulePackagePreflight($badRoot))->inspect('acme.delivery-record'),
        'MODULE_PACKAGE_FRONTEND_ENTRY_MISMATCH',
    );

    $bundleRoot = $temporary . '/bundle-project';
    mkdir($bundleRoot . '/server/resources/schemas', 0777, true);
    copy($serverRoot . '/resources/schemas/module-artifact.schema.json', $bundleRoot . '/server/resources/schemas/module-artifact.schema.json');
    foreach ([
        'acme.first' => ['First', 'pa_acme_first'],
        'acme.second' => ['Second', 'pa_acme_second'],
    ] as $key => [$class, $table]) {
        $directory = strtolower($class);
        $backend = $bundleRoot . '/server/app/modules/acme/' . $directory;
        $frontend = $bundleRoot . '/web/src/modules/' . str_replace('.', '-', $key);
        modulePackageCopyTree($fixtureBackend, $backend);
        modulePackageCopyTree($fixtureFrontend, $frontend);
        modulePackageRewriteTree($backend, [
            'acme.delivery-record' => $key,
            'acme-delivery-record' => str_replace('.', '-', $key),
            'Acme\\\\Modules\\\\DeliveryRecord' => 'Acme\\\\Modules\\\\' . $class,
            'Acme\\Modules\\DeliveryRecord' => 'Acme\\Modules\\' . $class,
            'peanut-business/acme-delivery-record' => 'acme/' . strtolower($class),
            'pa_fixture_delivery_record' => $table,
        ]);
        modulePackageRewriteTree($frontend, [
            'acme.delivery-record' => $key,
            'acme-delivery-record' => str_replace('.', '-', $key),
            '@peanut-admin/acme-delivery-record' => '@acme/' . strtolower($class),
        ]);
    }
    $firstManifestPath = $bundleRoot . '/server/app/modules/acme/first/module.json';
    $firstManifest = json_decode((string) file_get_contents($firstManifestPath), true, 64, JSON_THROW_ON_ERROR);
    $firstManifest['dependencies'] = [['module_key' => 'acme.second', 'version' => '^1.0']];
    file_put_contents($firstManifestPath, json_encode($firstManifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n");
    $bundleService = new ModulePackageArchiveService($bundleRoot . '/server');
    $bundlePath = $temporary . '/acme-bundle.tar';
    foreach (['.env.production', 'id_ed25519', 'node_modules/vendor-runtime.js'] as $forbiddenRelative) {
        $forbiddenSource = $bundleRoot . '/server/app/modules/acme/first/' . $forbiddenRelative;
        if (!is_dir(dirname($forbiddenSource))) {
            mkdir(dirname($forbiddenSource), 0700, true);
        }
        file_put_contents($forbiddenSource, "VENDOR_SECRET=must-not-be-packed\n");
        if ($forbiddenRelative === 'node_modules/vendor-runtime.js') {
            // Dependencies/caches are intentionally excluded; credentials in source are rejected.
            $cacheArchive = $temporary . '/acme-first-with-cache.tar';
            $cacheResult = $bundleService->packModule('acme.first', $cacheArchive);
            modulePackageExpect(
                !isset($tar->scan($cacheArchive)['server/app/modules/acme/first/' . $forbiddenRelative]),
                'Module archive included a dependency cache',
            );
            unlink($cacheArchive);
        } else {
            modulePackageRejects(
                static fn() => $bundleService->packModule('acme.first', $bundlePath),
                'MODULE_PACKAGE_SOURCE_FORBIDDEN',
            );
        }
        unlink($forbiddenSource);
        if ($forbiddenRelative === 'node_modules/vendor-runtime.js') {
            rmdir(dirname($forbiddenSource));
        }
    }
    $bundleResult = $bundleService->packModule('acme.first', $bundlePath);
    modulePackageExpect($cacheResult['sha256'] === $bundleResult['sha256'], 'ignored dependency cache changed the Module artifact identity');
    $bundleEntries = $tar->scan($bundlePath);
    $bundleExtracted = $temporary . '/bundle-extracted';
    $tar->extract($bundlePath, $bundleEntries, $bundleExtracted);
    $bundleFiles = [];
    foreach (['server/app/modules/acme/first', 'web/src/modules/acme-first'] as $root) {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($bundleExtracted . '/' . $root, FilesystemIterator::SKIP_DOTS),
        );
        foreach ($iterator as $entry) {
            if ($entry->isFile() && !$entry->isLink()) {
                $relative = substr($entry->getPathname(), strlen($bundleExtracted) + 1);
                $bundleFiles[$relative] = hash_file('sha256', $entry->getPathname());
            }
        }
    }
    ksort($bundleFiles, SORT_STRING);
    $bundleSourceFiles = [];
    foreach (['server/app/modules/acme/first', 'web/src/modules/acme-first'] as $root) {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($bundleRoot . '/' . $root, FilesystemIterator::SKIP_DOTS),
        );
        foreach ($iterator as $entry) {
            if ($entry->isFile() && !$entry->isLink()) {
                $relative = substr($entry->getPathname(), strlen($bundleRoot) + 1);
                $bundleSourceFiles[$relative] = hash_file('sha256', $entry->getPathname());
            }
        }
    }
    ksort($bundleSourceFiles, SORT_STRING);
    modulePackageExpect(
        $bundleFiles === $bundleSourceFiles,
        'bundle payload differs from source: ' . json_encode(array_diff_assoc($bundleSourceFiles, $bundleFiles), JSON_UNESCAPED_SLASHES),
    );
    $bundleCanonical = '';
    foreach ($bundleFiles as $relative => $digest) {
        $bundleCanonical .= $relative . "\0" . $digest . "\n";
    }
    $bundleDeclaration = json_decode((string) file_get_contents($bundleExtracted . '/server/app/modules/acme/first/module.json'), true, 64, JSON_THROW_ON_ERROR);
    $bundleManifest = (new ModuleArtifactWriter($bundleExtracted . '/server', false))->build('acme.first', $bundleDeclaration['version'], ['acme.first=server/app/modules/acme/first']);
    $bundleActualSourceDigest = hash('sha256', $bundleCanonical);
    modulePackageExpect(
        $bundleActualSourceDigest === $bundleManifest['source']['sha256'],
        'bundle source digest changed during archive round trip',
    );
    modulePackageRejects(
        static fn() => $bundleService->verify($bundlePath, $bundleResult['sha256'], [], null, []),
        'MODULE_PACKAGE_DEPENDENCY_MISSING',
    );
    $incompatible = modulePackageRejects(
        static fn() => $bundleService->verify($bundlePath, $bundleResult['sha256'], [], null, ['acme.second' => '2.0.0']),
        'MODULE_PACKAGE_DEPENDENCY_INCOMPATIBLE',
    );
    modulePackageExpect(
        $incompatible->getMessage() === 'acme.first requires acme.second ^1.0, but the available version is 2.0.0.',
        'dependency rejection must identify the module, dependency, constraint and available version',
    );
    $bundle = $bundleService->verify($bundlePath, $bundleResult['sha256'], [], null, ['acme.second' => '1.0.0']);
    modulePackageExpect(
        $bundle->dependencyOrder === ['acme.first'],
        'an external dependency must not become a second payload Module',
    );
    modulePackageExpect(count($bundle->modules) === 1, 'single Module artifact contains more than one Module');
    $bundleService->cleanup($bundle);

    // Adoption has no database dependency and applies equally to the source tree of both Editions.
    $adoptRoot = realpath($temporary) . '/adopted';
    mkdir($adoptRoot . '/server/resources/schemas', 0700, true);
    copy($serverRoot . '/resources/schemas/module-artifact.schema.json', $adoptRoot . '/server/resources/schemas/module-artifact.schema.json');
    $adopter = new ModulePackageAdoptionService($adoptRoot . '/server', ['fixture-release' => $public], 'development');
    $pin = hash_file('sha256', $signedPath);
    modulePackageRejects(fn() => (new ModulePackageAdoptionService($adoptRoot . '/server', [], 'production'))->adopt($signedPath, $pin, 'fixture-release'), 'MODULE_SOURCE_ADOPTION_DISABLED');
    modulePackageRejects(fn() => $adopter->adopt($signedPath, null, 'fixture-release'), 'MODULE_PACKAGE_SOURCE_UNTRUSTED');
    modulePackageRejects(fn() => $adopter->adopt($first, $packedA['sha256'], 'fixture-release'), 'MODULE_PACKAGE_SOURCE_UNTRUSTED');
    modulePackageRejects(fn() => $adopter->adopt($signedPath, str_repeat('0', 64), 'fixture-release'), 'MODULE_PACKAGE_ARCHIVE_DIGEST_MISMATCH');
    $adopted = $adopter->adopt($signedPath, $pin, 'fixture-release');
    modulePackageExpect($adopted['status'] === 'development-adopted' && !$adopted['runtime_database_mutated'] && !$adopted['tenant_modules_enabled'] && !$adopted['rbac_granted'], 'adoption crossed Runtime activation boundary');
    modulePackageExpect($adopter->adopt($signedPath, $pin, 'fixture-release')['operation'] === 'unchanged', 'same identity adoption is not idempotent');
    (new ModuleArtifactWriter($adoptRoot . '/server'))->checkLock();
    modulePackageExpect(count((new ModuleLockResolver($adoptRoot . '/server', '../modules.lock'))->all()) === 1, 'adopted source and lock diverge');

    // Replay real persisted transaction inputs in fresh processes at each process-crash boundary.
    // The child is killed without catch/finally cleanup after exposing the chosen durable intermediate state.
    modulePackageExpect(function_exists('pcntl_fork') && function_exists('posix_kill'), 'crash recovery qualification requires pcntl and posix');
    $receiptPath = (glob($adoptRoot . '/.local/module-source-adoption/*/transaction.json') ?: [])[0];
    $receipt = (string) file_get_contents($receiptPath);
    $journal = json_decode($receipt, true, 512, JSON_THROW_ON_ERROR);
    $transactionRoot = dirname($receiptPath);
    foreach (['prepared', 'first-source-promoted', 'lock-committed'] as $cut) {
        $pid = pcntl_fork();
        modulePackageExpect($pid !== -1, 'cannot fork crash fixture');
        if ($pid === 0) {
            foreach ($journal['entries'] as $entry) {
                $payload = $transactionRoot . '/payload/' . $entry['scope'];
                if (!is_dir(dirname($payload))) {
                    mkdir(dirname($payload), 0700, true);
                }
                rename($adoptRoot . '/' . $entry['scope'], $payload);
            }
            unlink($adoptRoot . '/modules.lock');
            file_put_contents($adoptRoot . '/.local/module-source-adoption/journal.json', $receipt);
            if ($cut !== 'prepared') {
                foreach ($journal['entries'] as $index => $entry) {
                    if ($cut === 'first-source-promoted' && $index !== 0) {
                        break;
                    }
                    rename($transactionRoot . '/payload/' . $entry['scope'], $adoptRoot . '/' . $entry['scope']);
                }
            }
            if ($cut === 'lock-committed') {
                copy($transactionRoot . '/next.lock', $adoptRoot . '/modules.lock');
            }
            posix_kill(getmypid(), SIGKILL);
            exit(99);
        }
        pcntl_waitpid($pid, $status);
        modulePackageExpect(pcntl_wifsignaled($status) && pcntl_wtermsig($status) === SIGKILL, 'crash fixture did not terminate abruptly');
        try {
            (new ModuleLockResolver($adoptRoot . '/server', '../modules.lock'))->all();
            throw new RuntimeException('pending adoption was consumed');
        } catch (ModuleLifecycleException $exception) {
            modulePackageExpect($exception->errorCode === 'MODULE_PACKAGE_RECOVERY_REQUIRED', 'pending adoption did not fail closed');
        }
        modulePackageExpect($adopter->recover()['status'] === 'recovered', 'persistent recovery failed at ' . $cut);
        (new ModuleArtifactWriter($adoptRoot . '/server'))->checkLock();
        modulePackageExpect($adopter->recover()['status'] === 'clean', 'recovery is not idempotent');
    }
    $alias = realpath($temporary) . '/adoption-alias';
    symlink($adoptRoot, $alias);
    modulePackageRejects(fn() => (new ModulePackageAdoptionService($alias . '/server', ['fixture-release' => $public], 'development'))->adopt($signedPath, $pin, 'fixture-release'), 'MODULE_PACKAGE_PATH_INVALID');
    unlink($alias);
    $identities = (new ReflectionClass(\app\platform\validation\module\ModuleReleaseCompositionGuard::class))->newInstanceWithoutConstructor();
    $identityColumn = new ReflectionMethod($identities, 'jsonColumn');
    foreach (['{}', '{"0":{"name":"identity"}}'] as $objectIdentity) {
        try {
            $identityColumn->invoke($identities, $objectIdentity);
            throw new RuntimeException('object-shaped identity was accepted');
        } catch (ModuleLifecycleException $exception) {
            modulePackageExpect($exception->errorCode === 'PLUGIN_RELEASE_CURRENT_IDENTITY_INVALID', 'object identity rejection changed');
        }
    }
    $backendParent = $adoptRoot . '/server/app/modules/acme';
    rename($backendParent, $backendParent . '-real');
    symlink($backendParent . '-real', $backendParent);
    modulePackageRejects(fn() => $adopter->recover(), 'MODULE_PACKAGE_PATH_INVALID');
    unlink($backendParent);
    rename($backendParent . '-real', $backendParent);

    // Upgrade recovery includes a crash after the old root is backed up, before the new root arrives.
    $upgradeRoot = realpath($temporary) . '/upgrade-source';
    mkdir($upgradeRoot . '/server/resources/schemas', 0700, true);
    copy($serverRoot . '/resources/schemas/module-artifact.schema.json', $upgradeRoot . '/server/resources/schemas/module-artifact.schema.json');
    modulePackageCopyTree($fixtureBackend, $upgradeRoot . '/server/app/modules/acme/delivery_record');
    modulePackageCopyTree($fixtureFrontend, $upgradeRoot . '/web/src/modules/acme-delivery-record');
    $changedEntry = '/web/src/modules/acme-delivery-record/contribution.ts';
    $originalEntry = (string) file_get_contents($upgradeRoot . $changedEntry);
    file_put_contents($upgradeRoot . $changedEntry, $originalEntry . "\n// same-version source change\n");
    $changedArchive = $temporary . '/same-version-changed.tar';
    $changed = (new ModulePackageArchiveService($upgradeRoot . '/server'))->packModule('acme.delivery-record', $changedArchive);
    $installedLock = hash_file('sha256', $adoptRoot . '/modules.lock');
    modulePackageRejects(fn() => $adopter->adopt($changedArchive, $changed['sha256'], null), 'PACKAGE_VERSION_IDENTITY_CONFLICT');
    modulePackageExpect(hash_file('sha256', $adoptRoot . '/modules.lock') === $installedLock
        && file_get_contents($adoptRoot . $changedEntry) === $originalEntry, 'Rejected same-version source changed the adopted graph.');
    file_put_contents($upgradeRoot . $changedEntry, $originalEntry);
    modulePackageRewriteTree($upgradeRoot, ['"version": "1.0.0"' => '"version": "1.1.0"']);
    $routeDirectory = $upgradeRoot . '/server/app/modules/acme/delivery_record/Http';
    if (!is_dir($routeDirectory)) {
        mkdir($routeDirectory, 0700, true);
    }
    file_put_contents($routeDirectory . '/routes.php', "<?php\n// Explicit application route composition fixture.\n");
    $upgradePath = $temporary . '/upgrade.tar';
    $upgrade = (new ModulePackageArchiveService($upgradeRoot . '/server'))->packModule('acme.delivery-record', $upgradePath, ['key_id' => 'fixture-release', 'secret_key' => $secret]);
    $oldLock = (string) file_get_contents($adoptRoot . '/modules.lock');
    $customerWip = $adoptRoot . '/server/app/modules/acme/delivery_record/customer-wip.php';
    $customerWipContents = "<?php\n// Uncommitted application customization.\n";
    file_put_contents($customerWip, $customerWipContents);
    try {
        $adopter->adopt($upgradePath, $upgrade['sha256'], 'fixture-release');
        throw new RuntimeException('Adoption overwrote unlocked customer WIP');
    } catch (ModuleLifecycleException $exception) {
        modulePackageExpect($exception->errorCode === 'PLUGIN_ARTIFACT_MISMATCH', 'Customer WIP did not fail source admission');
        modulePackageExpect(file_get_contents($customerWip) === $customerWipContents
            && file_get_contents($adoptRoot . '/modules.lock') === $oldLock,
            'Rejected customer WIP changed source or lock');
    }
    unlink($customerWip);
    $updated = $adopter->adopt($upgradePath, $upgrade['sha256'], 'fixture-release');
    modulePackageExpect($updated['route_contributions'] === ['server/app/modules/acme/delivery_record/route/app.php'], 'conventional route contribution was not reported');
    $receipts = glob($adoptRoot . '/.local/module-source-adoption/*/transaction.json') ?: [];
    $upgradeReceiptPath = array_values(array_diff($receipts, [$receiptPath]))[0];
    $upgradeReceipt = (string) file_get_contents($upgradeReceiptPath);
    $upgradeJournal = json_decode($upgradeReceipt, true, 512, JSON_THROW_ON_ERROR);
    $upgradeTransaction = dirname($upgradeReceiptPath);
    $pid = pcntl_fork();
    modulePackageExpect($pid !== -1, 'cannot fork upgrade crash fixture');
    if ($pid === 0) {
        foreach ($upgradeJournal['entries'] as $index => $entry) {
            $payload = $upgradeTransaction . '/payload/' . $entry['scope'];
            if (!is_dir(dirname($payload))) {
                mkdir(dirname($payload), 0700, true);
            }
            rename($adoptRoot . '/' . $entry['scope'], $payload);
            if ($index !== 0) {
                rename($upgradeTransaction . '/before/' . $entry['scope'], $adoptRoot . '/' . $entry['scope']);
            }
        }
        file_put_contents($adoptRoot . '/modules.lock', $oldLock);
        file_put_contents($adoptRoot . '/.local/module-source-adoption/journal.json', $upgradeReceipt);
        posix_kill(getmypid(), SIGKILL);
        exit(99);
    }
    pcntl_waitpid($pid, $status);
    modulePackageExpect(pcntl_wifsignaled($status), 'upgrade fixture did not terminate abruptly');
    file_put_contents($adoptRoot . '/modules.lock', $oldLock . "\n");
    modulePackageRejects(fn() => $adopter->recover(), 'MODULE_PACKAGE_RECOVERY_REQUIRED');
    file_put_contents($adoptRoot . '/modules.lock', $oldLock);
    modulePackageExpect($adopter->recover()['status'] === 'recovered', 'upgrade backup gap did not recover');
    (new ModuleArtifactWriter($adoptRoot . '/server'))->checkLock();
    modulePackageRejects(fn() => $adopter->adopt($signedPath, $pin, 'fixture-release'), 'PLUGIN_DOWNGRADE_REJECTED');
    // The same edition generator must retain private manifests when composing the next bundled source lock.
    $creator = new \app\common\infrastructure\scaffold\ApplicationCreator($projectRoot, $projectRoot . '/scaffold/application-template-inventory.json');
    $rebuild = new ReflectionMethod($creator, 'rebuildBundledModuleArtifacts');
    $artifactFiles = [];
    foreach (['server/app/modules/acme/delivery_record/module.json', 'modules.lock'] as $relative) {
        chmod($adoptRoot . '/' . $relative, 0644);
        $artifactFiles[] = ['path' => $relative, 'mode' => 0644];
    }
    $rebuild->invoke($creator, $adoptRoot, $artifactFiles);
    modulePackageExpect(isset((new ModuleLockResolver($adoptRoot . '/server', '../modules.lock'))->all()['acme.delivery-record']), 'edition composition discarded private package identity');

    $officialPath = $temporary . '/official-signed.tar';
    $official = (new ModulePackageArchiveService($serverRoot))->packModule('peanut.content-tools', $officialPath, ['key_id' => 'fixture-release', 'secret_key' => $secret]);
    modulePackageRejects(fn() => $adopter->adopt($officialPath, $official['sha256'], 'fixture-release'), 'MODULE_PACKAGE_PRIVATE_REQUIRED');
    modulePackageRejects(fn() => $adopter->adopt($officialPath, $official['sha256'], 'fixture-release', 'peanut.identity'), 'MODULE_PACKAGE_OFFICIAL_SOURCE_MISMATCH');
    modulePackageRejects(fn() => $adopter->adopt($signedPath, $pin, 'fixture-release', 'peanut.content-tools'), 'MODULE_PACKAGE_OFFICIAL_SOURCE_MISMATCH');
    modulePackageRejects(fn() => $adopter->adopt($officialPath, str_repeat('0', 64), 'fixture-release', 'peanut.content-tools'), 'MODULE_PACKAGE_ARCHIVE_DIGEST_MISMATCH');
    $officialRoot = realpath($temporary) . '/official-source-only';
    mkdir($officialRoot . '/server/resources/schemas', 0700, true);
    copy($serverRoot . '/resources/schemas/module-artifact.schema.json', $officialRoot . '/server/resources/schemas/module-artifact.schema.json');
    $officialAdopter = new ModulePackageAdoptionService($officialRoot . '/server', ['fixture-release' => $public], 'development');
    $officialAdopted = $officialAdopter->adopt($officialPath, $official['sha256'], 'fixture-release', 'peanut.content-tools');
    modulePackageExpect($officialAdopted['operation'] === 'adopted'
        && $officialAdopted['adoption_scope'] === 'official'
        && $officialAdopted['runtime_activation'] === 'not-verified'
        && $officialAdopted['reconciliation_status'] === 'pending'
        && $officialAdopted['runtime_database_mutated'] === false,
        'verified official source adoption crossed the Runtime activation boundary');
    modulePackageExpect($officialAdopter->adopt($officialPath, $official['sha256'], 'fixture-release', 'peanut.content-tools')['operation'] === 'unchanged', 'official source adoption is not idempotent');
    $officialResignedPath = $temporary . '/official-resigned.tar';
    $officialResigned = (new ModulePackageArchiveService($serverRoot))->packModule(
        'peanut.content-tools', $officialResignedPath, ['key_id' => 'fixture-release-2', 'secret_key' => $secret],
    );
    modulePackageExpect($officialResigned['sha256'] !== $official['sha256'], 're-signed fixture did not change official archive digest');
    $officialAdopterWithBothKeys = new ModulePackageAdoptionService(
        $officialRoot . '/server', ['fixture-release' => $public, 'fixture-release-2' => $public], 'development',
    );
    modulePackageRejects(
        fn() => $officialAdopterWithBothKeys->adopt($officialResignedPath, $officialResigned['sha256'], 'fixture-release-2', 'peanut.content-tools'),
        'PACKAGE_VERSION_IDENTITY_CONFLICT',
    );
    (new ModuleArtifactWriter($officialRoot . '/server'))->checkLock();
    modulePackageExpect(isset((new ModuleLockResolver($officialRoot . '/server', '../modules.lock'))->all()['peanut.content-tools']), 'official source and lock are not consistent');

    // Real canonical official source can advance repeatedly while Runtime adoption remains pending.
    // These locally produced archives exercise source adoption, not published-channel provenance or MySQL.
    $officialAuthor = $temporary . '/official-upgrade-source';
    $officialBackend = '/server/app/modules/peanut/content_tools';
    modulePackageCopyTree($officialRoot . $officialBackend, $officialAuthor . $officialBackend);
    $officialManifest = json_decode((string) file_get_contents($officialRoot . $officialBackend . '/module.json'), true, 64, JSON_THROW_ON_ERROR);
    foreach (\app\platform\value\module\ModuleFrontendLayout::contributions($officialManifest['frontend'], 'peanut.content-tools') as $contribution) {
        modulePackageCopyTree($officialRoot . '/' . $contribution['root'], $officialAuthor . '/' . $contribution['root']);
    }
    mkdir($officialAuthor . '/server/resources/schemas', 0700, true);
    copy($serverRoot . '/resources/schemas/module-artifact.schema.json', $officialAuthor . '/server/resources/schemas/module-artifact.schema.json');
    $customization = $officialRoot . '/application-owned.txt';
    file_put_contents($customization, 'customer-owned contents');
    $previousVersion = $officialAdopted['version'];
    foreach (['2.0.0', '4.0.0'] as $version) {
        modulePackageRewriteTree($officialAuthor, ['"version": "' . $previousVersion . '"' => '"version": "' . $version . '"']);
        $path = $temporary . '/official-' . $version . '.tar';
        $packed = (new ModulePackageArchiveService($officialAuthor . '/server'))->packModule('peanut.content-tools', $path);
        $adoption = $officialAdopter->adopt($path, $packed['sha256'], null, 'peanut.content-tools');
        modulePackageExpect($adoption['version'] === $version && $adoption['operation'] === 'adopted'
            && $adoption['reconciliation_status'] === 'pending' && $adoption['runtime_database_mutated'] === false,
            'Repeated official source adoption crossed the Runtime boundary');
        modulePackageExpect($officialAdopter->adopt($path, $packed['sha256'], null, 'peanut.content-tools')['operation'] === 'unchanged',
            'Repeated official source adoption lost its archive pin');
        (new ModuleArtifactWriter($officialRoot . '/server'))->checkLock();
        modulePackageExpect(file_get_contents($customization) === 'customer-owned contents', 'Official adoption changed application-owned source');
        $previousVersion = $version;
    }
    modulePackageRejects(fn() => $officialAdopter->adopt($officialPath, $official['sha256'], 'fixture-release', 'peanut.content-tools'), 'PLUGIN_DOWNGRADE_REJECTED');

    // BUG-01: a real consumer import must fail against the locked old official API.
    // Reuse the delivery fixture, native archive/lock producer, Core compiler and PHP sources.
    $boundaryRoot = realpath($temporary) . '/contract-consumer';
    $boundaryAuthor = $temporary . '/contract-author';
    $upstreamBackend = '/server/app/modules/official/delivery_record';
    modulePackageCopyTree($authorRoot, $boundaryAuthor);
    rename($boundaryAuthor . '/server/app/modules/acme', $boundaryAuthor . '/server/app/modules/official');
    rename($boundaryAuthor . '/web/src/modules/acme-delivery-record', $boundaryAuthor . '/web/src/modules/official-delivery-record');
    $upstreamNamespace = 'Official\\Modules\\DeliveryRecord';
    modulePackageRewriteTree($boundaryAuthor, [
        'acme.delivery-record' => 'official.delivery-record',
        'acme-delivery-record' => 'official-delivery-record',
        $toNamespace => $upstreamNamespace,
        trim(json_encode($toNamespace), '"') => trim(json_encode($upstreamNamespace), '"'),
        'pa_fixture_delivery_record' => 'pa_official_delivery_record',
    ]);
    modulePackageCopyTree($boundaryAuthor, $boundaryRoot);
    modulePackageCopyTree($fixtureBackend, $boundaryRoot . '/server/app/modules/acme/delivery_record');
    modulePackageCopyTree($fixtureFrontend, $boundaryRoot . '/web/src/modules/acme-delivery-record');
    $consumerManifestPath = $boundaryRoot . '/server/app/modules/acme/delivery_record/module.json';
    $consumerManifest = json_decode((string) file_get_contents($consumerManifestPath), true, 64, JSON_THROW_ON_ERROR);
    $consumerManifest['dependencies'] = [['module_key' => 'official.delivery-record', 'version' => '^1.0']];
    file_put_contents($consumerManifestPath, json_encode($consumerManifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n");
    file_put_contents($boundaryRoot . '/server/app/modules/acme/delivery_record/src/Service/UpstreamConsumer.php', <<<'PHP'
<?php
namespace Acme\Modules\DeliveryRecord\Service;
use Official\Modules\DeliveryRecord\Contract\DeviceCredentialBindings;
final class UpstreamConsumer
{
    public function __construct(private DeviceCredentialBindings $bindings) {}
    public function revision(): string { return $this->bindings->revision(); }
}
PHP);
    (new ModuleArtifactWriter($boundaryRoot . '/server'))->writeLock();
    $beforeContractLock = (string) file_get_contents($boundaryRoot . '/modules.lock');

    // Copy the actual native bridge/command and AppService unchanged: their __DIR__ owns this app.
    // All other classes and locked third-party dependencies use the existing Composer loader.
    mkdir($boundaryRoot . '/server/app/command', 0700, true);
    mkdir($boundaryRoot . '/server/vendor', 0700, true);
    mkdir($boundaryRoot . '/server/vendor/topthink/framework/src', 0700, true);
    file_put_contents($boundaryRoot . '/server/vendor/topthink/framework/src/helper.php', '<?php require_once '
        . var_export($serverRoot . '/vendor/topthink/framework/src/helper.php', true) . ";\n");
    mkdir($boundaryRoot . '/server/config', 0700, true);
    foreach (['module', 'app/AppService.php', 'app/service.php', 'app/provider.php', 'app/command/ModuleAdoptPackage.php',
        'config/app.php', 'config/cache.php', 'config/database.php', 'config/peanut.php', 'config/deployment.php',
        'config/module_packages.php'] as $relative) {
        copy($serverRoot . '/' . $relative, $boundaryRoot . '/server/' . $relative);
    }
    file_put_contents($boundaryRoot . '/server/vendor/autoload.php', "<?php\n\$loader = require "
        . var_export($serverRoot . '/vendor/autoload.php', true) . ";\n"
        . '$loader->addClassMap(' . var_export([
            'app\\AppService' => $boundaryRoot . '/server/app/AppService.php',
            'app\\command\\ModuleAdoptPackage' => $boundaryRoot . '/server/app/command/ModuleAdoptPackage.php',
        ], true) . ");\nspl_autoload_register(static function (string \$class): void {\n"
        . "    if (\$class === 'app\\\\AppService') { throw new \\RuntimeException('SOURCE_ADOPTION_RUNTIME_BOOTED'); }\n"
        . "}, true, true);\nreturn \$loader;\n");
    file_put_contents($boundaryRoot . '/server/.env', "APP_ENV=development\nAPP_DEBUG=true\nDEPLOYMENT_MODE=multi-tenant\n");
    chmod($boundaryRoot . '/server/.env', 0600);
    $compilePath = $boundaryRoot . '/compile.php';
    file_put_contents($compilePath, <<<'PHP'
<?php
use PeanutAdmin\Kernel\Module\{ManifestLoader, ModuleRegistryCompiler, ModuleBoundaryChecker, ModuleProvider, ModuleException};
require __DIR__ . '/server/vendor/autoload.php';
$app = new \think\App(__DIR__ . '/server');
$app->config->set(['mode' => 'multi-tenant'], 'deployment');
$roots = [];
foreach ((new \app\platform\infrastructure\module\ModuleLockResolver(__DIR__ . '/server', '../modules.lock'))->moduleRoots() as $root) {
    $document = (new ManifestLoader())->load($root);
    $roots[$document->data['key']] = $root;
}
$layout = \app\common\infrastructure\module\ModuleHostLayoutFactory::fromModuleRoots($roots);
foreach (\app\common\value\module\ModulePhpNamespace::map($roots) as $key => $namespace) {
    $loader->setPsr4($namespace, [$roots[$key] . '/src']);
}
try {
    $documents = array_map(static fn(string $root) => (new ManifestLoader())->load($root), array_values($roots));
    $components = [];
    foreach ($documents as $document) {
        foreach ($document->data['catalog']['menus'] as $menu) {
            $components[] = $menu['component_key'];
        }
    }
    $compiler = new ModuleRegistryCompiler(
        new \app\platform\validation\module\OpisManifestSchemaValidator(
            dirname((new ReflectionClass(ModuleProvider::class))->getFileName(), 3) . '/resources/schemas/module-manifest.schema.json'),
        '1.0.0', $components, $layout, [], ['admin-web'], [],
    );
    $registry = $compiler->compile($documents);
    (new ModuleBoundaryChecker($registry, $layout, ['pa_']))->check();
    (new \app\common\composition\ModuleComposition($app))->register($registry);
    $consumer = $app->make(\Acme\Modules\DeliveryRecord\Service\UpstreamConsumer::class);
    if ($consumer->revision() !== 'new-official-contract') {
        throw new RuntimeException('Consumer did not execute the adopted official implementation.');
    }
    echo json_encode(['modules' => $registry->moduleKeys(), 'revision' => $consumer->revision()], JSON_THROW_ON_ERROR);
} catch (ModuleException $exception) {
    fwrite(STDERR, json_encode(['error' => $exception->errorCode, 'message' => $exception->getMessage()], JSON_THROW_ON_ERROR));
    exit(1);
}
PHP);
    $runBoundary = static function (bool $adopt = false, array $arguments = []) use ($boundaryRoot, $compilePath): array {
        $process = proc_open([PHP_BINARY, $adopt ? $boundaryRoot . '/server/module' : $compilePath],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $boundaryRoot,
            ['PATH' => (string) getenv('PATH')]);
        modulePackageExpect(is_resource($process), 'Cannot start native contract fixture');
        if ($adopt) {
            fwrite($pipes[0], json_encode(['operation' => 'adopt', 'arguments' => $arguments], JSON_THROW_ON_ERROR));
        }
        fclose($pipes[0]);
        $output = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        return [proc_close($process), trim($output)];
    };
    $expectBoundaryFailure = static function (string $code) use ($runBoundary): void {
        [$exit, $output] = $runBoundary();
        modulePackageExpect($exit === 1, 'Contract compile unexpectedly succeeded: ' . $output);
        $error = json_decode($output, true, 32, JSON_THROW_ON_ERROR);
        modulePackageExpect($error['error'] === $code
            && str_contains($error['message'], 'UpstreamConsumer.php')
            && str_contains($error['message'], 'Official\\Modules\\DeliveryRecord\\Contract\\DeviceCredentialBindings'),
            'Unexpected contract compile rejection: ' . $output);
        echo "BUG-01 compile exit={$exit} {$output}\n";
    };
    $expectBoundaryFailure('MODULE_CONTRACT_MISSING');
    modulePackageExpect(file_get_contents($boundaryRoot . '/modules.lock') === $beforeContractLock,
        'Failed old-contract compile changed the locked graph');

    modulePackageRewriteTree($boundaryAuthor, ['"version": "1.0.0"' => '"version": "1.1.0"']);
    file_put_contents($boundaryAuthor . $upstreamBackend . '/src/Contract/DeviceCredentialBindings.php', <<<'PHP'
<?php
namespace Official\Modules\DeliveryRecord\Contract;
interface DeviceCredentialBindings { public function revision(): string; }
PHP);
    file_put_contents($boundaryAuthor . $upstreamBackend . '/src/Service/DeviceCredentialBindingsService.php', <<<'PHP'
<?php
namespace Official\Modules\DeliveryRecord\Service;
use Official\Modules\DeliveryRecord\Contract\DeviceCredentialBindings;
final class DeviceCredentialBindingsService implements DeviceCredentialBindings
{
    public function revision(): string { return 'new-official-contract'; }
}
PHP);
    $providerPath = $boundaryAuthor . $upstreamBackend . '/src/ModuleProvider.php';
    file_put_contents($providerPath, str_replace('return [', 'return [' . "\n"
        . '            \\Official\\Modules\\DeliveryRecord\\Contract\\DeviceCredentialBindings::class => \\Official\\Modules\\DeliveryRecord\\Service\\DeviceCredentialBindingsService::class,',
        (string) file_get_contents($providerPath)));
    $upstreamManifestPath = $boundaryAuthor . $upstreamBackend . '/module.json';
    $upstreamManifest = json_decode((string) file_get_contents($upstreamManifestPath), true, 64, JSON_THROW_ON_ERROR);
    $upstreamManifest['contracts']['exports'][] = $upstreamNamespace . '\\Contract\\DeviceCredentialBindings';
    file_put_contents($upstreamManifestPath, json_encode($upstreamManifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n");
    $contractArchive = $temporary . '/official-contract.tar';
    $contractPackage = (new ModulePackageArchiveService($boundaryAuthor . '/server'))->packModule('official.delivery-record', $contractArchive);
    [$exit, $output] = $runBoundary(true, [$contractArchive, '--sha256=' . $contractPackage['sha256'], '--official-key=official.delivery-record']);
    modulePackageExpect($exit === 0, 'Native official contract adoption failed: ' . $output);
    $contractAdopted = json_decode($output, true, 64, JSON_THROW_ON_ERROR);
    modulePackageExpect($contractAdopted['operation'] === 'adopted' && $contractAdopted['version'] === '1.1.0'
        && $contractAdopted['adoption_scope'] === 'official' && $contractAdopted['reconciliation_status'] === 'pending'
        && $contractAdopted['runtime_activation'] === 'not-verified' && $contractAdopted['runtime_database_mutated'] === false,
        'Native contract adoption crossed the source-only boundary: ' . $output);
    echo "BUG-01 native adopt exit={$exit} sha256={$contractPackage['sha256']} version=1.1.0 reconciliation=pending\n";
    (new ModuleArtifactWriter($boundaryRoot . '/server'))->checkLock();
    [$exit, $output] = $runBoundary();
    modulePackageExpect($exit === 0, 'Fresh process could not compile/assemble the adopted contract: ' . $output);
    $compiled = json_decode($output, true, 32, JSON_THROW_ON_ERROR);
    modulePackageExpect($compiled['modules'] === ['official.delivery-record', 'acme.delivery-record']
        && $compiled['revision'] === 'new-official-contract', 'Adopted consumer did not execute: ' . $output);
    echo "BUG-01 fresh compile+composition exit={$exit} {$output}\n";

    $consumerBytes = (string) file_get_contents($consumerManifestPath);
    $consumerLock = (string) file_get_contents($boundaryRoot . '/modules.lock');
    try {
        $consumerManifest['dependencies'] = [];
        file_put_contents($consumerManifestPath, json_encode($consumerManifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n");
        (new ModuleArtifactWriter($boundaryRoot . '/server'))->writeLock();
        $expectBoundaryFailure('MODULE_DEPENDENCY_MISSING');
    } finally {
        file_put_contents($consumerManifestPath, $consumerBytes);
        file_put_contents($boundaryRoot . '/modules.lock', $consumerLock);
    }
    // Remove only the new export in a new package; its real PHP interface remains present.
    array_pop($upstreamManifest['contracts']['exports']);
    file_put_contents($upstreamManifestPath, json_encode($upstreamManifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n");
    modulePackageRewriteTree($boundaryAuthor, ['"version": "1.1.0"' => '"version": "1.2.0"']);
    $unexportedArchive = $temporary . '/official-unexported-contract.tar';
    $unexportedPackage = (new ModulePackageArchiveService($boundaryAuthor . '/server'))->packModule('official.delivery-record', $unexportedArchive);
    [$exit, $output] = $runBoundary(true, [$unexportedArchive, '--sha256=' . $unexportedPackage['sha256'], '--official-key=official.delivery-record']);
    modulePackageExpect($exit === 0 && json_decode($output, true, 64, JSON_THROW_ON_ERROR)['reconciliation_status'] === 'pending',
        'Native unexported contract adoption failed: ' . $output);
    echo "BUG-01 native unexported adopt exit={$exit} sha256={$unexportedPackage['sha256']} version=1.2.0 reconciliation=pending\n";
    $expectBoundaryFailure('MODULE_CONTRACT_MISSING');
    $bridgeEnvironment = $boundaryRoot . '/server/.env';
    $bridgeEnvironmentBytes = (string) file_get_contents($bridgeEnvironment);
    $bridgeLock = (string) file_get_contents($boundaryRoot . '/modules.lock');
    $bridgeArguments = [$unexportedArchive, '--sha256=' . $unexportedPackage['sha256'], '--official-key=official.delivery-record'];
    try {
        chmod($bridgeEnvironment, 0644);
        [$exit, $output] = $runBoundary(true, $bridgeArguments);
        modulePackageExpect($exit !== 0 && str_contains($output, 'MODULE_RECOVERY_BACKEND_ENVIRONMENT_INVALID'),
            'Source adoption accepted an insecure environment file: ' . $output);
        chmod($bridgeEnvironment, 0600);
        file_put_contents($bridgeEnvironment, "APP_ENV=production\nDEPLOYMENT_MODE=multi-tenant\n");
        [$exit, $output] = $runBoundary(true, $bridgeArguments);
        modulePackageExpect($exit !== 0 && str_contains($output, 'MODULE_SOURCE_ADOPTION_DISABLED'),
            'Source adoption accepted a production instance: ' . $output);
        modulePackageExpect(file_get_contents($boundaryRoot . '/modules.lock') === $bridgeLock,
            'Rejected source adoption changed the Module lock');
    } finally {
        chmod($bridgeEnvironment, 0600);
        file_put_contents($bridgeEnvironment, $bridgeEnvironmentBytes);
    }
    if (!$archiveOnly) {
        // An incomplete source must still expose the development recovery CLI while ordinary boot fails closed.
        $bootJournal = $projectRoot . '/.local/module-source-adoption/journal.json';
        $envName = 'module-package-' . bin2hex(random_bytes(4));
        $bootEnvironment = $serverRoot . '/.env.' . $envName;
        modulePackageExpect(!file_exists($bootJournal), 'source worktree already has pending adoption');
        if (!is_dir(dirname($bootJournal))) {
            mkdir(dirname($bootJournal), 0700, true);
        }
        file_put_contents($bootJournal, '{"schema_version":0}');
        file_put_contents(
            $bootEnvironment,
            "APP_ENV=development\nAPP_DEBUG=true\nDEPLOYMENT_MODE=multi-tenant\n"
                . "PEANUT_DATABASE_RESOURCE_ID=p2-module-package-fixture\n"
                . "DB_HOST=127.0.0.1\nDB_PORT=1\nDB_NAME=p2_module_package_fixture\n"
                . "DB_USER=p2\nDB_PASS=p2\nDB_PREFIX=pa_\n"
                . "PEANUT_MODULE_LOCK=../modules.lock\nPEANUT_MODULE_KERNEL_VERSION=1.0.0\n"
                . "PEANUT_MODULE_TRUSTED_KEYS_JSON={}\n",
        );
        chmod($bootEnvironment, 0600);
        $invoke = static function (bool $bridge, array $environment, string $operation = 'adopt', array $arguments = ['--recover']) use ($serverRoot): array {
            $process = proc_open(
                $bridge ? [PHP_BINARY, $serverRoot . '/module'] : [PHP_BINARY, $serverRoot . '/think', 'list'],
                [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
                $pipes, $serverRoot, ['PATH' => (string) getenv('PATH')] + $environment,
            );
            modulePackageExpect(is_resource($process), 'cannot start recovery boot check');
            if ($bridge) {
                fwrite($pipes[0], json_encode(['operation' => $operation, 'arguments' => $arguments], JSON_THROW_ON_ERROR));
            }
            fclose($pipes[0]);
            $output = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);

            return [proc_close($process), trim($output)];
        };
        $outside = $temporary . '/external-development.env';
        file_put_contents($outside, "APP_ENV=development\n");
        chmod($outside, 0600);
        try {
            $environment = ['PHP_ENV_NAME' => $envName];
            [$exit, $output] = $invoke(false, $environment);
            modulePackageExpect($exit !== 0 && str_contains($output, 'Run module:adopt-package --recover'), 'ordinary Runtime consumed incomplete source');
            [$exit, $output] = $invoke(true, $environment);
            modulePackageExpect(
                $exit !== 0 && modulePackageNativeFailure($output, 'MODULE_PACKAGE_RECOVERY_REQUIRED')
                    && (json_decode($output, true)['recovery']['status'] ?? null) === 'invalid'
                    && !isset(json_decode($output, true)['recovery']['resume']),
                'development recovery CLI did not fail closed on a corrupt journal: ' . $output,
            );
            [$exit, $output] = $invoke(true, $environment, 'upgrade', [$officialPath, '--sha256=' . $official['sha256'], '--dry-run']);
            modulePackageExpect($exit !== 0 && str_contains($output, 'Run module:adopt-package --recover'),
                'Runtime package upgrade consumed incomplete source');
            [$exit, $output] = $invoke(true, $environment, 'adopt', [$officialPath, '--sha256=' . $official['sha256'], '--official-key=peanut.content-tools']);
            modulePackageExpect($exit !== 0
                    && modulePackageNativeFailure($output, 'MODULE_PACKAGE_RECOVERY_REQUIRED'),
                'Initial official adoption could not reach its source transaction: ' . $output);
            unlink($bootJournal);
            $sourceLock = (string) file_get_contents($projectRoot . '/modules.lock');
            try {
                foreach ([
                    [str_repeat('0', 64), 'peanut.content-tools', 'MODULE_PACKAGE_ARCHIVE_DIGEST_MISMATCH'],
                    [$official['sha256'], 'peanut.identity', 'MODULE_PACKAGE_OFFICIAL_SOURCE_MISMATCH'],
                    [$official['sha256'], 'peanut.content-tools', 'PACKAGE_VERSION_IDENTITY_CONFLICT'],
                ] as [$digest, $key, $error]) {
                    [$exit, $output] = $invoke(true, $environment, 'adopt', [$officialPath, '--sha256=' . $digest, '--official-key=' . $key]);
                    modulePackageExpect($exit !== 0 && modulePackageNativeFailure($output, $error),
                        'Native official adoption did not reach package admission without a database: ' . $output);
                    modulePackageExpect(file_get_contents($projectRoot . '/modules.lock') === $sourceLock,
                        'Rejected native official adoption changed the deployment lock');
                }
            } finally {
                file_put_contents($bootJournal, '{"schema_version":0}');
            }
            $state = static function () use ($projectRoot, $bootJournal): array {
                $paths = [$bootJournal, $projectRoot . '/modules.lock', dirname($bootJournal) . '/writer.lock'];
                return array_map(static fn(string $path) => is_file($path) ? hash_file('sha256', $path) : null, $paths);
            };
            $before = $state();
            $untrusted = ['PEANUT_SERVER_ENV_FILE' => $outside, 'PHP_APP_ENV' => 'development'];
            foreach (["APP_ENV=production\n", "APP_DEBUG=false\n"] as $contents) {
                file_put_contents($bootEnvironment, $contents);
                [$exit, $output] = $invoke(true, $environment + $untrusted);
                modulePackageExpect(
                    $exit !== 0 && modulePackageNativeFailure($output, 'MODULE_SOURCE_ADOPTION_DISABLED')
                        && $state() === $before,
                    'recovery accepted an external development environment or modified source state',
                );
            }
            unlink($bootEnvironment);
            symlink($outside, $bootEnvironment);
            foreach (['0', $envName, $envName . '/../' . basename($outside)] as $invalidName) {
                [$exit, $output] = $invoke(true, ['PHP_ENV_NAME' => $invalidName] + $untrusted);
                modulePackageExpect(
                    $exit !== 0 && str_contains($output, 'MODULE_RECOVERY_BACKEND_ENVIRONMENT_INVALID') && $state() === $before,
                    'recovery accepted a linked or unconfined environment',
                );
            }
        } finally {
            unlink($bootJournal);
            unlink($bootEnvironment);
        }
    }
    echo $archiveOnly
        ? "MODULE-PACKAGE-ARCHIVE-SOURCE-001 passed sha256={$packedA['sha256']} adoption+crash-recovery; runtime-boot=not-executed\n"
        : "MODULE-PACKAGE-ARCHIVE-001 passed sha256={$packedA['sha256']} adoption+crash-recovery\n";
} finally {
    modulePackageRemoveTree($temporary);
}
