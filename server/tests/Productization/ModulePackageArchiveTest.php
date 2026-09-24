<?php
declare(strict_types=1);

use app\platform\exception\plugin\PluginLifecycleException;
use app\platform\exception\plugin\PluginPackageException;
use app\platform\infrastructure\plugin\DeterministicTarArchive;
use app\platform\infrastructure\plugin\PluginArtifactWriter;
use app\platform\infrastructure\plugin\PluginLockResolver;
use app\platform\services\plugin\PluginPackageAdoptionService;
use app\platform\services\plugin\PluginPackageArchiveService;
use app\platform\validation\plugin\ModulePackagePreflight;

require dirname(__DIR__, 2) . '/vendor/autoload.php';

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
function modulePackageRejects(callable $operation, string $errorCode): void
{
    try {
        $operation();
        throw new RuntimeException("Expected package rejection: {$errorCode}");
    } catch (PluginPackageException $exception) {
        $previous = $exception->getPrevious();
        modulePackageExpect(
            $exception->errorCode === $errorCode,
            "Unexpected package rejection: {$exception->errorCode}"
                . ($previous === null ? '' : ' (' . $previous->getMessage() . ')')
        );
    }
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
        $contents = (string)file_get_contents($entry->getPathname());
        file_put_contents($entry->getPathname(), str_replace(array_keys($replacements), array_values($replacements), $contents));
    }
}

$serverRoot = dirname(__DIR__, 2);
$projectRoot = dirname($serverRoot);
$temporary = sys_get_temp_dir() . '/pa-module-package-' . bin2hex(random_bytes(8));
mkdir($temporary, 0700, true);

try {
    $service = new PluginPackageArchiveService($serverRoot);
    $first = $temporary . '/fixture-a.tar';
    $second = $temporary . '/fixture-b.tar';
    $packedA = $service->packModule('fixture.delivery-record', $first);
    $packedB = $service->packModule('fixture.delivery-record', $second);
    modulePackageExpect($packedA['sha256'] === $packedB['sha256'], 'same Module tree did not produce a deterministic tar');

    $tar = new DeterministicTarArchive();
    $entries = $tar->scan($first);
    modulePackageExpect(isset($entries['META-INF/files.sha256']), 'package inventory is missing');
    $inventory = $tar->read($first, $entries['META-INF/files.sha256']);
    modulePackageExpect(str_contains($inventory, "\0"), 'package inventory does not use path+NUL+sha256 rows');
    modulePackageExpect(
        count(array_filter(array_keys($entries), static fn(string $path): bool => str_ends_with($path, '/module.json'))) === 1,
        'single-Module package contains a second manifest'
    );

    $verified = $service->verify($first, $packedA['sha256'], [], null, []);
    modulePackageExpect($verified->packageKey === 'fixture.delivery-record', 'verified package identity changed');
    modulePackageExpect($verified->dependencyOrder === ['fixture.delivery-record'], 'single-Module dependency order changed');
    $service->cleanup($verified);

    modulePackageRejects(
        static fn() => $service->verify($first, str_repeat('0', 64), [], null, []),
        'MODULE_PACKAGE_ARCHIVE_DIGEST_MISMATCH',
    );
    modulePackageRejects(
        static fn() => $service->verify($first, null, [], null, []),
        'MODULE_PACKAGE_SOURCE_UNTRUSTED',
    );

    $keypair = sodium_crypto_sign_keypair();
    $secret = sodium_crypto_sign_secretkey($keypair);
    $public = sodium_crypto_sign_publickey($keypair);
    $signedPath = $temporary . '/fixture-signed.tar';
    $service->packModule('fixture.delivery-record', $signedPath, ['key_id' => 'fixture-release', 'secret_key' => $secret]);
    $signed = $service->verify($signedPath, null, ['fixture-release' => $public], 'fixture-release', []);
    modulePackageExpect($signed->packageKey === 'fixture.delivery-record', 'signed package did not verify');
    $service->cleanup($signed);

    $tamperedEntries = [];
    foreach ($entries as $path => $entry) {
        $contents = $tar->read($first, $entry);
        if ($path === 'web/src/modules/fixture-delivery-record/contribution.ts') {
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

    $fixtureBackend = $projectRoot . '/server/app/modules/fixture/delivery_record';
    $fixtureFrontend = $projectRoot . '/web/src/modules/fixture-delivery-record';
    $badRoot = $temporary . '/bad-project';
    modulePackageCopyTree($fixtureBackend, $badRoot . '/server/app/modules/fixture/delivery_record');
    modulePackageCopyTree($fixtureFrontend, $badRoot . '/web/src/modules/fixture-delivery-record');
    $badManifestPath = $badRoot . '/server/app/modules/fixture/delivery_record/module.json';
    $badManifest = json_decode((string)file_get_contents($badManifestPath), true, 64, JSON_THROW_ON_ERROR);
    $badManifest['frontend']['entry'] = 'web/src/modules/fixture-delivery-record/index.ts';
    file_put_contents($badManifestPath, json_encode($badManifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n");
    modulePackageRejects(
        static fn() => (new ModulePackagePreflight($badRoot))->inspect('fixture.delivery-record'),
        'MODULE_PACKAGE_FRONTEND_ENTRY_MISMATCH',
    );

    $bundleRoot = $temporary . '/bundle-project';
    mkdir($bundleRoot . '/server/resources/schemas', 0777, true);
    copy($serverRoot . '/resources/schemas/plugin.schema.json', $bundleRoot . '/server/resources/schemas/plugin.schema.json');
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
            'fixture.delivery-record' => $key,
            'fixture-delivery-record' => str_replace('.', '-', $key),
            'PeanutAdmin\\\\Fixtures\\\\DeliveryRecord' => 'Acme\\\\Modules\\\\' . $class,
            'PeanutAdmin\\Fixtures\\DeliveryRecord' => 'Acme\\Modules\\' . $class,
            'peanut-business/fixture-delivery-record' => 'acme/' . strtolower($class),
            'pa_fixture_delivery_record' => $table,
        ]);
        modulePackageRewriteTree($frontend, [
            'fixture.delivery-record' => $key,
            'fixture-delivery-record' => str_replace('.', '-', $key),
            '@peanut-admin/fixture-delivery-record' => '@acme/' . strtolower($class),
        ]);
    }
    $firstManifestPath = $bundleRoot . '/server/app/modules/acme/first/module.json';
    $firstManifest = json_decode((string)file_get_contents($firstManifestPath), true, 64, JSON_THROW_ON_ERROR);
    $firstManifest['dependencies'] = [['module_key' => 'acme.second', 'version' => '^1.0']];
    file_put_contents($firstManifestPath, json_encode($firstManifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n");
    $bundleService = new PluginPackageArchiveService($bundleRoot . '/server');
    $bundlePath = $temporary . '/acme-bundle.tar';
    foreach (['.env.production', 'id_ed25519', 'node_modules/vendor-runtime.js'] as $forbiddenRelative) {
        $forbiddenSource = $bundleRoot . '/server/app/modules/acme/first/' . $forbiddenRelative;
        if (!is_dir(dirname($forbiddenSource))) mkdir(dirname($forbiddenSource), 0700, true);
        file_put_contents($forbiddenSource, "VENDOR_SECRET=must-not-be-packed\n");
        modulePackageRejects(
            static fn() => $bundleService->packBundle('acme.bundle', '1.0.0', ['acme.first', 'acme.second'], $bundlePath),
            'MODULE_PACKAGE_SOURCE_FORBIDDEN',
        );
        unlink($forbiddenSource);
        if ($forbiddenRelative === 'node_modules/vendor-runtime.js') rmdir(dirname($forbiddenSource));
    }
    $bundleResult = $bundleService->packBundle('acme.bundle', '1.0.0', ['acme.first', 'acme.second'], $bundlePath);
    $bundleEntries = $tar->scan($bundlePath);
    $bundleExtracted = $temporary . '/bundle-extracted';
    $tar->extract($bundlePath, $bundleEntries, $bundleExtracted);
    $bundleFiles = [];
    foreach (['server/app/modules/acme/first', 'server/app/modules/acme/second', 'web/src/modules/acme-first', 'web/src/modules/acme-second'] as $root) {
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
    foreach (['server/app/modules/acme/first', 'server/app/modules/acme/second', 'web/src/modules/acme-first', 'web/src/modules/acme-second'] as $root) {
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
        'bundle payload differs from source: ' . json_encode(array_diff_assoc($bundleSourceFiles, $bundleFiles), JSON_UNESCAPED_SLASHES)
    );
    $bundleCanonical = '';
    foreach ($bundleFiles as $relative => $digest) {
        $bundleCanonical .= $relative . "\0" . $digest . "\n";
    }
    $bundleManifest = json_decode((string)file_get_contents($bundleExtracted . '/plugins/acme.bundle/plugin.json'), true, 64, JSON_THROW_ON_ERROR);
    $bundleActualSourceDigest = hash('sha256', $bundleCanonical);
    modulePackageExpect(
        $bundleActualSourceDigest === $bundleManifest['source']['sha256'],
        'bundle source digest changed during archive round trip'
    );
    $bundle = $bundleService->verify($bundlePath, $bundleResult['sha256'], [], null, []);
    modulePackageExpect(
        $bundle->dependencyOrder === ['acme.second', 'acme.first'],
        'bundle dependency order is not topological'
    );
    modulePackageExpect(count($bundle->modules) === 2, 'bundle Module count changed');
    $bundleService->cleanup($bundle);

    // Adoption has no database dependency and applies equally to the source tree of both Editions.
    $adoptRoot = realpath($temporary) . '/adopted';
    mkdir($adoptRoot . '/server/resources/schemas', 0700, true);
    copy($serverRoot . '/resources/schemas/plugin.schema.json', $adoptRoot . '/server/resources/schemas/plugin.schema.json');
    $adopter = new PluginPackageAdoptionService($adoptRoot . '/server', ['fixture-release' => $public], 'development');
    $pin = hash_file('sha256', $signedPath);
    modulePackageRejects(fn() => (new PluginPackageAdoptionService($adoptRoot . '/server', [], 'production'))->adopt($signedPath, $pin, 'fixture-release'), 'MODULE_SOURCE_ADOPTION_DISABLED');
    modulePackageRejects(fn() => $adopter->adopt($signedPath, null, 'fixture-release'), 'MODULE_PACKAGE_SOURCE_UNTRUSTED');
    modulePackageRejects(fn() => $adopter->adopt($first, $packedA['sha256'], 'fixture-release'), 'MODULE_PACKAGE_SOURCE_UNTRUSTED');
    modulePackageRejects(fn() => $adopter->adopt($signedPath, str_repeat('0', 64), 'fixture-release'), 'MODULE_PACKAGE_ARCHIVE_DIGEST_MISMATCH');
    $adopted = $adopter->adopt($signedPath, $pin, 'fixture-release');
    modulePackageExpect($adopted['status'] === 'development-adopted' && !$adopted['runtime_database_mutated'] && !$adopted['tenant_modules_enabled'] && !$adopted['rbac_granted'], 'adoption crossed Runtime activation boundary');
    modulePackageExpect($adopter->adopt($signedPath, $pin, 'fixture-release')['operation'] === 'unchanged', 'same identity adoption is not idempotent');
    (new PluginArtifactWriter($adoptRoot . '/server'))->checkLock();
    modulePackageExpect(count((new PluginLockResolver($adoptRoot . '/server', '../plugins.lock'))->all()) === 1, 'adopted source and lock diverge');

    // Replay real persisted transaction inputs in fresh processes at each process-crash boundary.
    // The child is killed without catch/finally cleanup after exposing the chosen durable intermediate state.
    modulePackageExpect(function_exists('pcntl_fork') && function_exists('posix_kill'), 'crash recovery qualification requires pcntl and posix');
    $receiptPath = (glob($adoptRoot . '/.local/module-source-adoption/*/transaction.json') ?: [])[0];
    $receipt = (string)file_get_contents($receiptPath);
    $journal = json_decode($receipt, true, 512, JSON_THROW_ON_ERROR);
    $transactionRoot = dirname($receiptPath);
    foreach (['prepared', 'first-source-promoted', 'lock-committed'] as $cut) {
        $pid = pcntl_fork();
        modulePackageExpect($pid !== -1, 'cannot fork crash fixture');
        if ($pid === 0) {
            foreach ($journal['entries'] as $entry) {
                $payload = $transactionRoot . '/payload/' . $entry['scope'];
                if (!is_dir(dirname($payload))) mkdir(dirname($payload), 0700, true);
                rename($adoptRoot . '/' . $entry['scope'], $payload);
            }
            unlink($adoptRoot . '/plugins.lock');
            file_put_contents($adoptRoot . '/.local/module-source-adoption/journal.json', $receipt);
            if ($cut !== 'prepared') {
                foreach ($journal['entries'] as $index => $entry) {
                    if ($cut === 'first-source-promoted' && $index !== 0) break;
                    rename($transactionRoot . '/payload/' . $entry['scope'], $adoptRoot . '/' . $entry['scope']);
                }
            }
            if ($cut === 'lock-committed') copy($transactionRoot . '/next.lock', $adoptRoot . '/plugins.lock');
            posix_kill(getmypid(), SIGKILL);
            exit(99);
        }
        pcntl_waitpid($pid, $status);
        modulePackageExpect(pcntl_wifsignaled($status) && pcntl_wtermsig($status) === SIGKILL, 'crash fixture did not terminate abruptly');
        try {
            (new PluginLockResolver($adoptRoot . '/server', '../plugins.lock'))->all();
            throw new RuntimeException('pending adoption was consumed');
        } catch (PluginLifecycleException $exception) {
            modulePackageExpect($exception->errorCode === 'MODULE_PACKAGE_RECOVERY_REQUIRED', 'pending adoption did not fail closed');
        }
        modulePackageExpect($adopter->recover()['status'] === 'recovered', 'persistent recovery failed at ' . $cut);
        (new PluginArtifactWriter($adoptRoot . '/server'))->checkLock();
        modulePackageExpect($adopter->recover()['status'] === 'clean', 'recovery is not idempotent');
    }
    $alias = realpath($temporary) . '/adoption-alias';
    symlink($adoptRoot, $alias);
    modulePackageRejects(fn() => (new PluginPackageAdoptionService($alias . '/server', ['fixture-release' => $public], 'development'))->adopt($signedPath, $pin, 'fixture-release'), 'MODULE_PACKAGE_PATH_INVALID');
    unlink($alias);
    $identities = (new ReflectionClass(\app\platform\validation\plugin\PluginReleaseCompositionGuard::class))->newInstanceWithoutConstructor();
    $identityColumn = new ReflectionMethod($identities, 'jsonColumn');
    foreach (['{}', '{"0":{"name":"identity"}}'] as $objectIdentity) {
        try {
            $identityColumn->invoke($identities, $objectIdentity);
            throw new RuntimeException('object-shaped identity was accepted');
        } catch (PluginLifecycleException $exception) {
            modulePackageExpect($exception->errorCode === 'PLUGIN_RELEASE_CURRENT_IDENTITY_INVALID', 'object identity rejection changed');
        }
    }
    $backendParent = $adoptRoot . '/server/app/modules/fixture';
    rename($backendParent, $backendParent . '-real');
    symlink($backendParent . '-real', $backendParent);
    modulePackageRejects(fn() => $adopter->recover(), 'MODULE_PACKAGE_PATH_INVALID');
    unlink($backendParent);
    rename($backendParent . '-real', $backendParent);

    // Upgrade recovery includes a crash after the old root is backed up, before the new root arrives.
    $upgradeRoot = realpath($temporary) . '/upgrade-source';
    mkdir($upgradeRoot . '/server/resources/schemas', 0700, true);
    copy($serverRoot . '/resources/schemas/plugin.schema.json', $upgradeRoot . '/server/resources/schemas/plugin.schema.json');
    modulePackageCopyTree($fixtureBackend, $upgradeRoot . '/server/app/modules/fixture/delivery_record');
    modulePackageCopyTree($fixtureFrontend, $upgradeRoot . '/web/src/modules/fixture-delivery-record');
    modulePackageRewriteTree($upgradeRoot, ['"version": "1.0.0"' => '"version": "1.1.0"']);
    $routeDirectory = $upgradeRoot . '/server/app/modules/fixture/delivery_record/Http';
    if (!is_dir($routeDirectory)) mkdir($routeDirectory, 0700, true);
    file_put_contents($routeDirectory . '/routes.php', "<?php\n// Explicit application route composition fixture.\n");
    $upgradePath = $temporary . '/upgrade.tar';
    $upgrade = (new PluginPackageArchiveService($upgradeRoot . '/server'))->packModule('fixture.delivery-record', $upgradePath, ['key_id' => 'fixture-release', 'secret_key' => $secret]);
    $oldLock = (string)file_get_contents($adoptRoot . '/plugins.lock');
    $updated = $adopter->adopt($upgradePath, $upgrade['sha256'], 'fixture-release');
    modulePackageExpect($updated['route_contributions'] === ['server/app/modules/fixture/delivery_record/route/app.php'], 'conventional route contribution was not reported');
    $receipts = glob($adoptRoot . '/.local/module-source-adoption/*/transaction.json') ?: [];
    $upgradeReceiptPath = array_values(array_diff($receipts, [$receiptPath]))[0];
    $upgradeReceipt = (string)file_get_contents($upgradeReceiptPath);
    $upgradeJournal = json_decode($upgradeReceipt, true, 512, JSON_THROW_ON_ERROR);
    $upgradeTransaction = dirname($upgradeReceiptPath);
    $pid = pcntl_fork();
    modulePackageExpect($pid !== -1, 'cannot fork upgrade crash fixture');
    if ($pid === 0) {
        foreach ($upgradeJournal['entries'] as $index => $entry) {
            $payload = $upgradeTransaction . '/payload/' . $entry['scope'];
            if (!is_dir(dirname($payload))) mkdir(dirname($payload), 0700, true);
            rename($adoptRoot . '/' . $entry['scope'], $payload);
            if ($index !== 0) rename($upgradeTransaction . '/before/' . $entry['scope'], $adoptRoot . '/' . $entry['scope']);
        }
        file_put_contents($adoptRoot . '/plugins.lock', $oldLock);
        file_put_contents($adoptRoot . '/.local/module-source-adoption/journal.json', $upgradeReceipt);
        posix_kill(getmypid(), SIGKILL);
        exit(99);
    }
    pcntl_waitpid($pid, $status);
    modulePackageExpect(pcntl_wifsignaled($status), 'upgrade fixture did not terminate abruptly');
    file_put_contents($adoptRoot . '/plugins.lock', $oldLock . "\n");
    modulePackageRejects(fn() => $adopter->recover(), 'MODULE_PACKAGE_RECOVERY_REQUIRED');
    file_put_contents($adoptRoot . '/plugins.lock', $oldLock);
    modulePackageExpect($adopter->recover()['status'] === 'recovered', 'upgrade backup gap did not recover');
    (new PluginArtifactWriter($adoptRoot . '/server'))->checkLock();
    modulePackageRejects(fn() => $adopter->adopt($signedPath, $pin, 'fixture-release'), 'PLUGIN_DOWNGRADE_REJECTED');
    // The same edition generator must retain private manifests when composing the next bundled source lock.
    $creator = new \app\common\infrastructure\scaffold\ApplicationCreator($projectRoot, $projectRoot . '/scaffold/application-template-inventory.json');
    $rebuild = new ReflectionMethod($creator, 'rebuildBundledPluginArtifacts');
    $artifactFiles = [];
    foreach (['plugins/fixture.delivery-record/plugin.json', 'plugins.lock'] as $relative) {
        chmod($adoptRoot . '/' . $relative, 0644);
        $artifactFiles[] = ['path' => $relative, 'mode' => 0644];
    }
    $rebuild->invoke($creator, $adoptRoot, $artifactFiles);
    modulePackageExpect(isset((new PluginLockResolver($adoptRoot . '/server', '../plugins.lock'))->all()['fixture.delivery-record']), 'edition composition discarded private package identity');

    $officialPath = $temporary . '/official-signed.tar';
    $official = $service->packModule('official.rich-text', $officialPath, ['key_id' => 'fixture-release', 'secret_key' => $secret]);
    modulePackageRejects(fn() => $adopter->adopt($officialPath, $official['sha256'], 'fixture-release'), 'MODULE_PACKAGE_PRIVATE_REQUIRED');
    if (!$archiveOnly) {
    // An incomplete source must still expose the development recovery CLI while ordinary boot fails closed.
    $bootJournal = $projectRoot . '/.local/module-source-adoption/journal.json';
    $bootEnvironment = $serverRoot . '/.env.module-package-' . bin2hex(random_bytes(4));
    modulePackageExpect(!file_exists($bootJournal), 'source worktree already has pending adoption');
    if (!is_dir(dirname($bootJournal))) mkdir(dirname($bootJournal), 0700, true);
    file_put_contents($bootJournal, '{"schema_version":0}');
    file_put_contents(
        $bootEnvironment,
        "APP_ENV=development\nAPP_DEBUG=true\nDEPLOYMENT_MODE=multi-tenant\n"
            . "PEANUT_DATABASE_RESOURCE_ID=p2-module-package-fixture\n"
            . "DB_HOST=127.0.0.1\nDB_PORT=1\nDB_NAME=p2_module_package_fixture\n"
            . "DB_USER=p2\nDB_PASS=p2\nDB_PREFIX=pa_\n"
            . "PEANUT_PLUGIN_LOCK=../plugins.lock\nPEANUT_MODULE_KERNEL_VERSION=1.0.0\n"
            . "PEANUT_MODULE_TRUSTED_KEYS_JSON={}\n",
    );
    chmod($bootEnvironment, 0600);
    try {
        foreach ([['module:adopt-package', '--recover'], ['list']] as $arguments) {
            $process = proc_open(
                [PHP_BINARY, $serverRoot . '/think', ...$arguments],
                [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
                $pipes,
                $serverRoot,
                ['PATH' => (string)getenv('PATH'), 'PEANUT_SERVER_ENV_FILE' => $bootEnvironment],
            );
            modulePackageExpect(is_resource($process), 'cannot start recovery boot check');
            $output = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
            fclose($pipes[1]); fclose($pipes[2]);
            modulePackageExpect(proc_close($process) !== 0, 'invalid journal boot unexpectedly succeeded');
            if ($arguments[0] === 'module:adopt-package') {
                modulePackageExpect(
                    trim($output) === '{"error":"MODULE_PACKAGE_RECOVERY_REQUIRED"}',
                    'recovery CLI could not boot without Module composition: ' . trim($output),
                );
            } else {
                modulePackageExpect(str_contains($output, 'Run module:adopt-package --recover'), 'ordinary Runtime consumed incomplete source');
            }
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
