<?php

declare(strict_types=1);

use app\command\ModuleInstallPackage;
use app\common\execution\CurrentExecutionContext;
use app\common\execution\ExecutionContextStore;
use app\platform\exception\module\ModulePackageException;
use app\platform\infrastructure\module\ModuleCatalogApplier;
use app\platform\infrastructure\module\ModulePackageInstaller;
use app\platform\services\module\ModulePackageArchiveService;
use app\platform\services\module\PlatformModuleRuntimeService;
use think\console\Input;
use think\console\Output;

require dirname(__DIR__, 2) . '/vendor/autoload.php';
require_once dirname(__DIR__, 2) . '/vendor/topthink/framework/src/helper.php';

$temporary = sys_get_temp_dir() . '/peanut-install-plan-' . bin2hex(random_bytes(8));
mkdir($temporary, 0700);
$checks = 0;
$expect = static function (bool $condition, string $message) use (&$checks): void {
    ++$checks;
    if (!$condition) {
        throw new RuntimeException($message);
    }
};
$copy = static function (string $source, string $target, array $replace = []): void {
    mkdir($target, 0700, true);
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($source, FilesystemIterator::SKIP_DOTS)) as $file) {
        if (!$file->isFile()) {
            continue;
        }
        $path = $target . '/' . substr($file->getPathname(), strlen($source) + 1);
        if (!is_dir(dirname($path))) {
            mkdir(dirname($path), 0700, true);
        }
        file_put_contents($path, str_replace(array_keys($replace), array_values($replace), (string) file_get_contents($file->getPathname())));
    }
};
$snapshot = static function (string $root): array {
    $files = [];
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::SELF_FIRST) as $entry) {
        $files[substr($entry->getPathname(), strlen($root) + 1)] = [$entry->getPerms(), $entry->isFile() ? hash_file('sha256', $entry->getPathname()) : null];
    }
    ksort($files);
    return $files;
};

try {
    $sourceRoot = dirname(__DIR__, 3);
    $author = $temporary . '/author';
    $moduleRoot = $author . '/server/app/modules/acme/delivery_record';
    $namespace = 'PeanutAdmin\\Fixtures\\DeliveryRecord';
    $replacement = 'Acme\\Modules\\DeliveryRecord';
    $replace = ['fixture.delivery-record' => 'acme.delivery-record', 'fixture-delivery-record' => 'acme-delivery-record',
        $namespace => $replacement, str_replace('\\', '\\\\', $namespace) => str_replace('\\', '\\\\', $replacement)];
    $copy($sourceRoot . '/server/fixtures/modules/delivery_record', $moduleRoot, $replace);
    $copy($sourceRoot . '/web/tests/fixtures/modules/fixture-delivery-record', $author . '/web/src/modules/acme-delivery-record', $replace);
    mkdir($author . '/server/resources/schemas', 0700, true);
    copy($sourceRoot . '/server/resources/schemas/module-artifact.schema.json', $author . '/server/resources/schemas/module-artifact.schema.json');
    $composer = json_decode((string) file_get_contents($moduleRoot . '/composer.json'), true, 512, JSON_THROW_ON_ERROR);
    $composer['require']['peanut-admin/core'] = '^5.1';
    $composer['require']['peanut-admin/module-composition-host'] = '^1.0';
    $composer['extra']['peanut']['editions'] = ['standalone', 'multi-tenant'];
    file_put_contents($moduleRoot . '/composer.json', json_encode($composer, JSON_THROW_ON_ERROR));
    $manifest = json_decode((string) file_get_contents($moduleRoot . '/module.json'), true, 512, JSON_THROW_ON_ERROR);
    $manifest['backend']['migrations'] = 'database/migrations';
    mkdir($moduleRoot . '/database/migrations', 0700, true);
    file_put_contents($moduleRoot . '/database/migrations/20261111000001_install_plan_sample.php', "<?php\nclass InstallPlanSample {}\n");
    $archive = new ModulePackageArchiveService($author . '/server');
    $pack = static function (array $data, string $name) use ($moduleRoot, $archive, $temporary): array {
        file_put_contents($moduleRoot . '/module.json', json_encode($data, JSON_THROW_ON_ERROR));
        $path = $temporary . '/' . $name . '.tar';
        return [$path, $archive->packModule('acme.delivery-record', $path)['sha256']];
    };
    [$package, $sha] = $pack($manifest, 'valid');
    $consumer = $temporary . '/consumer';
    foreach (['server/database/migrations', 'server/database/installation', '.git', 'scaffold'] as $directory) {
        mkdir($consumer . '/' . $directory, 0700, true);
    }
    copy($sourceRoot . '/server/database/InitialInstallationSource.php', $consumer . '/server/database/InitialInstallationSource.php');
    mkdir($consumer . '/server/resources/schemas', 0700, true);
    copy($sourceRoot . '/server/resources/schemas/module-artifact.schema.json', $consumer . '/server/resources/schemas/module-artifact.schema.json');
    foreach (['scaffold/application-template-inventory.json', 'scaffold/edition-profiles.json', 'release-versions.json'] as $file) {
        file_put_contents($consumer . '/' . $file, '{}');
    }
    file_put_contents($consumer . '/server/database/installation/20261111000000_install_plan_host.php', "<?php\nclass InstallPlanHost {}\n");
    file_put_contents($consumer . '/server/composer.json', '{"provide":{"peanut-admin/module-composition-host":"1.0.0"}}');
    $app = new think\App($consumer . '/server/');
    $app->debug(true);
    $app->env->set('PEANUT_INSTALLATION_SOURCE_MODE', 'development');
    $app->config->set(['mode' => 'multi-tenant'], 'deployment');
    $app->config->set(['environment' => 'development'], 'peanut');
    $database = new class {
        public int $calls = 0;
        public function __call(string $method, array $arguments): never
        {
            ++$this->calls;
            throw new RuntimeException('UNEXPECTED_DATABASE_ACCESS: ' . $method);
        }
    };
    $app->instance('db', $database);
    $catalogs = (new ReflectionClass(ModuleCatalogApplier::class))->newInstanceWithoutConstructor();
    $config = ['kernel_version' => '1.0.0'];
    $installer = new ModulePackageInstaller($consumer . '/server', $config, [], $catalogs);
    $before = $snapshot($consumer);
    foreach (['standalone', 'multi-tenant'] as $edition) {
        $app->config->set(['mode' => $edition], 'deployment');
        $plan = $installer->install($package, $sha, null, true);
        $expect($plan['operation'] === 'install' && $plan['dry_run'] && $plan['status'] === 'source-preflight-ready', 'Fresh install did not return a source plan');
        $expect($plan['source'] === null && $plan['lock']['before_sha256'] === null, 'Fresh plan invented an installed identity');
        $expect($plan['dependency_order'] === ['acme.delivery-record'] && count($plan['migrations']) === 1, 'Native graph/migration plan missing');
        $expect($plan['target']['archive_sha256'] === $sha && $plan['files'] !== [], 'Plan lost the audited identity');
        $expect($plan['runtime']['status'] === 'not-checked' && $snapshot($consumer) === $before && $database->calls === 0, 'Dry run changed application or accessed DB');
        $expect($installer->install($package, $sha, null, true) === $plan, 'Read-only plan is not repeatable');
    }
    // Exercise the actual command, context and service dispatch. Unused runtime
    // collaborators deliberately remain uninitialized: planning must not touch them.
    $runtimeClass = new ReflectionClass(PlatformModuleRuntimeService::class);
    $runtime = $runtimeClass->newInstanceWithoutConstructor();
    foreach (['serverRoot' => $consumer . '/server', 'moduleConfig' => $config, 'trustedPublicKeys' => [], 'catalogs' => $catalogs] as $name => $value) {
        $runtimeClass->getProperty($name)->setValue($runtime, $value);
    }
    $contexts = new ExecutionContextStore();
    $command = new ModuleInstallPackage($contexts, new CurrentExecutionContext($contexts), $catalogs, $runtime);
    $command->setApp($app);
    $output = new Output('buffer');
    $exit = $command->run(new Input([$package, '--sha256=' . $sha, '--dry-run']), $output);
    $result = json_decode(trim($output->fetch()), true, 512, JSON_THROW_ON_ERROR);
    $expect($exit === 0 && ($result['dry_run'] ?? false) && $contexts->isEmpty(), 'Native command/service did not return the plan');
    $rejects = static function (callable $run, string $error) use ($expect, $snapshot, $consumer, &$before, $database): void {
        try {
            $run();
            throw new RuntimeException('Expected rejection: ' . $error);
        } catch (ModulePackageException $exception) {
            $expect($exception->errorCode === $error, 'Wrong rejection: ' . $exception->errorCode . ' / ' . $exception->getMessage() . ' / ' . $exception->getPrevious()?->getMessage());
        }
        $expect($snapshot($consumer) === $before && $database->calls === 0, 'Rejected dry run changed application or accessed DB');
    };
    $rejects(fn() => $installer->install($package, str_repeat('0', 64), null, true), 'MODULE_PACKAGE_ARCHIVE_DIGEST_MISMATCH');
    $rejects(fn() => $installer->install($package, null, null, true), 'MODULE_PACKAGE_SOURCE_UNTRUSTED');
    $bad = $manifest;
    $bad['kernel_constraint'] = '^2.0';
    [$badPath, $badSha] = $pack($bad, 'kernel');
    $rejects(fn() => $installer->install($badPath, $badSha, null, true), 'MODULE_KERNEL_INCOMPATIBLE');
    $executionRoot = $temporary . '/execution';
    $copy($consumer, $executionRoot);
    try {
        (new ModulePackageInstaller($executionRoot . '/server', $config, [], $catalogs))->install($badPath, $badSha, null);
        throw new RuntimeException('Execution skipped the shared Kernel preflight');
    } catch (ModulePackageException $exception) {
        $expect($exception->errorCode === 'MODULE_KERNEL_INCOMPATIBLE' && $database->calls === 0, 'Execution did not share the source-only rejection');
    }
    $bad = $manifest;
    $bad['dependencies'] = [['module_key' => 'acme.missing', 'version' => '^1.0']];
    mkdir($author . '/server/app/modules/acme/missing', 0700, true);
    file_put_contents($author . '/server/app/modules/acme/missing/module.json', '{"key":"acme.missing","version":"1.0.0"}');
    [$badPath, $badSha] = $pack($bad, 'dependency');
    $rejects(fn() => $installer->install($badPath, $badSha, null, true), 'MODULE_PACKAGE_DEPENDENCY_MISSING');
    $composer['require']['peanut-admin/core'] = '^999.0';
    file_put_contents($moduleRoot . '/composer.json', json_encode($composer, JSON_THROW_ON_ERROR));
    [$badPath, $badSha] = $pack($manifest, 'core');
    $rejects(fn() => $installer->install($badPath, $badSha, null, true), 'MODULE_RELEASE_PREPARATION_REQUIRED');
    $composer['require']['peanut-admin/core'] = '^5.1';
    $composer['require']['peanut-admin/module-composition-host'] = '^2.0';
    file_put_contents($moduleRoot . '/composer.json', json_encode($composer, JSON_THROW_ON_ERROR));
    [$badPath, $badSha] = $pack($manifest, 'host');
    $rejects(fn() => $installer->install($badPath, $badSha, null, true), 'MODULE_HOST_CAPABILITY_INCOMPATIBLE');
    $composer['require']['peanut-admin/module-composition-host'] = '^1.0';
    $composer['extra']['peanut']['editions'] = ['standalone'];
    file_put_contents($moduleRoot . '/composer.json', json_encode($composer, JSON_THROW_ON_ERROR));
    [$badPath, $badSha] = $pack($manifest, 'edition');
    $rejects(fn() => $installer->install($badPath, $badSha, null, true), 'MODULE_EDITION_UNSUPPORTED');
    // Native migration source collision and unowned target are rejected before DB.
    file_put_contents($consumer . '/server/database/migrations/20261111000001_install_plan_collision.php', "<?php\nclass InstallPlanCollision {}\n");
    $before = $snapshot($consumer);
    try {
        $installer->install($package, $sha, null, true);
        throw new RuntimeException('Expected migration identity conflict');
    } catch (app\platform\exception\module\ModuleLifecycleException $exception) {
        $expect($exception->errorCode === 'MODULE_MIGRATION_IDENTITY_CONFLICT', 'Migration conflict was not retained');
    }
    $expect($snapshot($consumer) === $before && $database->calls === 0, 'Migration rejection had side effects');
    unlink($consumer . '/server/database/migrations/20261111000001_install_plan_collision.php');
    mkdir($consumer . '/server/app/modules/acme/delivery_record', 0700, true);
    file_put_contents($consumer . '/server/app/modules/acme/delivery_record/custom.php', '<?php // app-owned');
    $before = $snapshot($consumer);
    // Capture the updated baseline for this independent conflict scenario.
    try {
        $installer->install($package, $sha, null, true);
        throw new RuntimeException('Expected target conflict');
    } catch (ModulePackageException $exception) {
        $expect($exception->errorCode === 'MODULE_PACKAGE_TARGET_CONFLICT', 'Unowned target was accepted');
    }
    $expect($snapshot($consumer) === $before && $database->calls === 0, 'Target rejection had side effects');
    unlink($consumer . '/server/app/modules/acme/delivery_record/custom.php');
    rmdir($consumer . '/server/app/modules/acme/delivery_record');
    (new app\platform\services\module\ModulePackageAdoptionService($consumer . '/server', [], 'development'))->adopt($package, $sha, null);
    $before = $snapshot($consumer);
    $existing = $installer->install($package, $sha, null, true);
    $expect($existing['source']['version'] === '1.0.0'
        && array_unique(array_column($existing['source_scopes'], 'action')) === ['preserve']
        && $existing['runtime']['installation'] === 'not-checked', 'Existing source was mistaken for an active Runtime installation');
    $expect($snapshot($consumer) === $before && $database->calls === 0, 'Existing-source plan changed application or DB');
    $composer['extra']['peanut']['editions'] = ['standalone', 'multi-tenant'];
    file_put_contents($moduleRoot . '/composer.json', json_encode($composer, JSON_THROW_ON_ERROR));
    file_put_contents($moduleRoot . '/changed.txt', 'same version, different contents');
    [$badPath, $badSha] = $pack($manifest, 'immutable');
    $rejects(fn() => $installer->install($badPath, $badSha, null, true), 'MODULE_PACKAGE_TARGET_CONFLICT');
    $composer['version'] = '0.9.0';
    file_put_contents($moduleRoot . '/composer.json', json_encode($composer, JSON_THROW_ON_ERROR));
    $older = $manifest;
    $older['version'] = '0.9.0';
    [$badPath, $badSha] = $pack($older, 'downgrade');
    $rejects(fn() => $installer->install($badPath, $badSha, null, true), 'MODULE_PACKAGE_TARGET_CONFLICT');
    echo "MODULE-INSTALL-PACKAGE-DRY-RUN {$checks} assertions PASS\n";
} finally {
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($temporary, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST) as $entry) {
        $entry->isDir() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
    }
    rmdir($temporary);
}
