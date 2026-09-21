<?php
declare(strict_types=1);

use app\platform\service\ops\PlatformUpgradeReadinessService;
use app\platform\service\ops\PlatformUpgradeTarget;
use app\platform\service\ops\PairedBackupProvider;
use app\platform\service\ops\ThinkPhpMaintenanceWindowStore;
use app\platform\service\ops\ThinkPhpOpsTaskDispatcher;
use app\platform\service\ops\PlatformBackupCenterService;
use app\platform\service\ops\PlatformOpsPermissionChecker;
use app\common\service\audit\AuditContractHost;
use app\platform\service\module\ThinkPhpModuleGovernanceProvider;
use app\platform\service\plugin\PluginLockResolver;
use PeanutAdmin\Kernel\Module\ManifestLoader;
use PeanutAdmin\Kernel\Authorization\RevisionPermissionCache;
use PeanutAdmin\Modules\Identity\Platform\Authorization\ThinkPhpPlatformAuthorizationRepository;
use PeanutAdmin\Kernel\Platform\Authorization\PlatformAuthorizationEvaluator;
use PeanutAdmin\Modules\Ops\Domain\Maintenance\MaintenanceReasonRegistry;
use PeanutAdmin\Modules\Ops\Domain\Maintenance\MaintenanceService;
use PeanutAdmin\Modules\Ops\Domain\Task\BackupRestoreProviderRegistry;
use PeanutAdmin\Modules\Ops\Domain\Task\OpsTaskService;
use think\Config as ThinkConfig;
use think\Container;
use think\facade\Config;

require dirname(__DIR__, 2) . '/vendor/autoload.php';
require_once dirname(__DIR__) . '/Support/ThinkPhpTestConnection.php';
require_once dirname(__DIR__, 2) . '/app/platform/service/plugin/PluginLifecycleException.php';
require_once dirname(__DIR__, 2) . '/app/platform/service/plugin/PluginDescriptor.php';
require_once dirname(__DIR__, 2) . '/app/platform/service/plugin/PluginLockResolver.php';
require_once dirname(__DIR__, 2) . '/app/platform/service/ops/PlatformUpgradeTarget.php';
require_once dirname(__DIR__, 2) . '/app/platform/service/ops/PlatformUpgradeReadinessService.php';

function upgradeTargetExpect(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function upgradeTargetCopyTree(string $source, string $target): void
{
    if (!is_dir($target) && !mkdir($target, 0700, true) && !is_dir($target)) {
        throw new RuntimeException('unable to create target fixture directory');
    }
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($source, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST,
    );
    foreach ($iterator as $entry) {
        $relative = substr($entry->getPathname(), strlen($source) + 1);
        $destination = $target . '/' . $relative;
        if ($entry->isDir()) {
            if (!is_dir($destination) && !mkdir($destination, 0700, true) && !is_dir($destination)) {
                throw new RuntimeException('unable to create target fixture directory');
            }
            continue;
        }
        if (!copy($entry->getPathname(), $destination)) {
            throw new RuntimeException('unable to copy target fixture file');
        }
    }
}

function upgradeTargetRemoveTree(string $path): void
{
    if (is_link($path) || is_file($path)) {
        unlink($path);
        return;
    }
    if (!is_dir($path)) {
        return;
    }
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST,
    );
    foreach ($iterator as $entry) {
        $entry->isDir() && !$entry->isLink()
            ? rmdir($entry->getPathname())
            : unlink($entry->getPathname());
    }
    rmdir($path);
}

/** @param array<string,mixed> $document */
function upgradeTargetWriteJson(string $path, array $document): void
{
    $directory = dirname($path);
    if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
        throw new RuntimeException('unable to create JSON fixture directory');
    }
    file_put_contents(
        $path,
        json_encode($document, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n",
    );
}

/** @param list<string> $command */
function upgradeTargetRun(array $command): string
{
    $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    if (!is_resource($process)) {
        throw new RuntimeException('unable to start Git fixture command');
    }
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $status = proc_close($process);
    if ($status !== 0 || !is_string($stdout)) {
        throw new RuntimeException('Git fixture command failed: ' . trim((string)$stderr));
    }
    return trim($stdout);
}

/** Produce the release root tree through real Git, then remove its temporary repository metadata. */
function upgradeTargetGitTree(string $releaseRoot): string
{
    upgradeTargetRun(['git', '-C', $releaseRoot, 'init', '--quiet']);
    upgradeTargetRun(['git', '-C', $releaseRoot, 'config', 'core.filemode', 'true']);
    upgradeTargetRun(['git', '-C', $releaseRoot, 'add', '--all']);
    $tree = upgradeTargetRun(['git', '-C', $releaseRoot, 'write-tree']);
    upgradeTargetRemoveTree($releaseRoot . '/.git');
    upgradeTargetExpect(preg_match('/^[a-f0-9]{40}$/D', $tree) === 1, 'Git fixture tree is invalid');
    return $tree;
}

/** @param list<string> $roots */
function upgradeTargetCanonicalDigest(string $projectRoot, array $roots): string
{
    $projectRoot = realpath($projectRoot) ?: $projectRoot;
    $files = [];
    foreach ($roots as $root) {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
        );
        foreach ($iterator as $file) {
            if (!$file->isFile() || $file->isLink()) {
                continue;
            }
            $path = $file->getRealPath() ?: $file->getPathname();
            $relative = str_replace('\\', '/', substr($path, strlen($projectRoot) + 1));
            $files[$relative] = hash_file('sha256', $file->getPathname());
        }
    }
    ksort($files, SORT_STRING);
    $canonical = '';
    foreach ($files as $relative => $digest) {
        $canonical .= $relative . "\0" . $digest . "\n";
    }
    return hash('sha256', $canonical);
}

/** @param callable():void $operation */
function upgradeTargetRejects(callable $operation, string $expected): void
{
    try {
        $operation();
    } catch (RuntimeException $exception) {
        upgradeTargetExpect($exception->getMessage() === $expected, 'unexpected target rejection code');
        return;
    }
    throw new RuntimeException("target fixture accepted invalid input: {$expected}");
}

$sourceRoot = dirname(__DIR__, 3);
$temporary = sys_get_temp_dir() . '/pa-upgrade-target-module-' . bin2hex(random_bytes(8));
$projectRoot = $temporary . '/application';
$targetRoot = $projectRoot . '/.peanut/upgrade-target';
$releaseRoot = $targetRoot . '/release';
$currentModule = $projectRoot . '/server/app/modules/fixture/delivery_record';
$currentFrontend = $projectRoot . '/web/src/modules/fixture-delivery-record';
$targetModule = $releaseRoot . '/server/app/modules/fixture/delivery_record';
$targetFrontend = $releaseRoot . '/web/src/modules/fixture-delivery-record';

mkdir($temporary, 0700, true);
try {
    foreach ([
        [$sourceRoot . '/server/app/modules/fixture/delivery_record', $currentModule],
        [$sourceRoot . '/web/src/modules/fixture-delivery-record', $currentFrontend],
        [$sourceRoot . '/server/app/modules/fixture/delivery_record', $targetModule],
        [$sourceRoot . '/web/src/modules/fixture-delivery-record', $targetFrontend],
    ] as [$source, $target]) {
        upgradeTargetCopyTree($source, $target);
    }
    upgradeTargetCopyTree(
        $sourceRoot . '/plugins/fixture.delivery-record',
        $releaseRoot . '/plugins/fixture.delivery-record',
    );

    $targetMarker = $targetModule . '/Application/target-release-proof.txt';
    file_put_contents($targetMarker, "target release module bytes\n");
    upgradeTargetExpect(!is_file($currentModule . '/Application/target-release-proof.txt'), 'target marker leaked into current source');

    $sourceLock = json_decode(
        (string)file_get_contents($sourceRoot . '/plugins.lock'),
        true,
        64,
        JSON_THROW_ON_ERROR,
    );
    $plugin = array_values(array_filter(
        $sourceLock['plugins'],
        static fn(array $entry): bool => ($entry['key'] ?? null) === 'fixture.delivery-record',
    ))[0] ?? null;
    upgradeTargetExpect(is_array($plugin), 'fixture Plugin lock entry is unavailable');

    $plugin['source']['sha256'] = upgradeTargetCanonicalDigest(
        $releaseRoot,
        [$targetModule, $targetFrontend],
    );
    $plugin['trust']['compatibility']['modules'][0]['kernel_constraint'] = '^2.0';
    $pluginManifestPath = $releaseRoot . '/plugins/fixture.delivery-record/plugin.json';
    $pluginManifest = json_decode((string)file_get_contents($pluginManifestPath), true, 64, JSON_THROW_ON_ERROR);
    $pluginManifest['source'] = $plugin['source'];
    $pluginManifest['trust'] = $plugin['trust'];
    upgradeTargetWriteJson($pluginManifestPath, $pluginManifest);
    $plugin['manifest_sha256'] = hash_file('sha256', $pluginManifestPath);
    $targetLockPath = $releaseRoot . '/plugins.lock';
    upgradeTargetWriteJson($targetLockPath, ['schema_version' => 1, 'plugins' => [$plugin]]);
    file_put_contents($releaseRoot . '/sort.txt', "regular sort fixture\n");
    mkdir($releaseRoot . '/sort', 0700, true);
    file_put_contents($releaseRoot . '/sort/child.txt', "directory sort fixture\n");
    $executablePath = $releaseRoot . '/ops-executable';
    file_put_contents($executablePath, "#!/usr/bin/env bash\nexit 0\n");
    chmod($executablePath, 0755);
    $groupExecutablePath = $releaseRoot . '/group-executable';
    file_put_contents($groupExecutablePath, "group executable bit only\n");
    chmod($groupExecutablePath, 0645);

    $targetCommit = str_repeat('a', 40);
    $scaffoldSourceCommit = str_repeat('b', 40);
    $scaffoldSourceTree = str_repeat('c', 40);
    $inventoryDigest = str_repeat('c', 64);
    $fromManifest = [
        'release' => [
            'version' => '3.0.8',
            'source_commit' => str_repeat('d', 40),
            'source_tree' => str_repeat('e', 40),
            'inventory_sha256' => str_repeat('f', 64),
        ],
    ];
    $toManifest = [
        'release' => [
            'version' => '3.0.9',
            'source_commit' => $scaffoldSourceCommit,
            'source_tree' => $scaffoldSourceTree,
            'inventory_sha256' => $inventoryDigest,
        ],
    ];
    $fromManifestPath = $targetRoot . '/from/scaffold-manifest.json';
    $toManifestPath = $targetRoot . '/to/scaffold-manifest.json';
    upgradeTargetWriteJson($fromManifestPath, $fromManifest);
    upgradeTargetWriteJson($toManifestPath, $toManifest);
    $sourceApplicationManifestPath = $projectRoot . '/.peanut/application-manifest.json';
    $sourceApplicationManifest = [
        'schema_version' => 2,
        'protocol' => 'peanut.application-scaffold.v2',
        'application' => [
            'name' => 'Original Acme Console',
            'slug' => 'acme-console',
            'package_identity' => 'acme/console',
            'version' => '1.4.0',
        ],
        'template' => [
            'version' => '3.0.8',
            'source_commit' => str_repeat('d', 40),
            'source_tree' => str_repeat('e', 40),
            'inventory_sha256' => str_repeat('f', 64),
        ],
    ];
    upgradeTargetWriteJson($sourceApplicationManifestPath, $sourceApplicationManifest);
    $sourceReleaseMetadataPath = $projectRoot . '/RELEASE_METADATA.json';
    $sourceReleaseMetadata = [
        'schema_version' => 1,
        'product' => 'Original Acme Console',
        'application_identity' => 'acme/console',
        'version' => '1.4.0',
        'expected_tag' => 'v1.4.0',
    ];
    upgradeTargetWriteJson($sourceReleaseMetadataPath, $sourceReleaseMetadata);
    $sourceReleaseVersionsPath = $projectRoot . '/release-versions.json';
    $sourceReleaseVersions = [
        'schema_version' => 1,
        'protocol' => 'peanut.release-versions.v1',
        'product_release' => '1.4.0',
        'scaffold_template' => '3.0.8',
        'generated_application_default' => '0.1.0',
        'core_php' => '0.1.0-alpha.12',
        'core_web' => '0.1.0-alpha.12',
    ];
    upgradeTargetWriteJson($sourceReleaseVersionsPath, $sourceReleaseVersions);
    $targetApplicationManifestPath = $releaseRoot . '/.peanut/application-manifest.json';
    $targetApplicationManifest = [
        'schema_version' => 2,
        'protocol' => 'peanut.application-scaffold.v2',
        'application' => [
            'name' => 'Acme Console',
            'slug' => 'acme-console',
            'package_identity' => 'acme/console',
            'version' => '0.1.0-alpha.1',
        ],
        'template' => [
            'version' => '3.0.9',
            'source_commit' => $scaffoldSourceCommit,
            'source_tree' => $scaffoldSourceTree,
            'inventory_sha256' => $inventoryDigest,
        ],
    ];
    upgradeTargetWriteJson($targetApplicationManifestPath, $targetApplicationManifest);
    upgradeTargetWriteJson($releaseRoot . '/RELEASE_METADATA.json', [
        'schema_version' => 1,
        'product' => 'Renamed Acme Console',
        'application_identity' => 'acme/console',
        'version' => '1.4.1',
        'expected_tag' => 'v1.4.1',
    ]);
    upgradeTargetWriteJson($releaseRoot . '/release-versions.json', [
        'schema_version' => 1,
        'protocol' => 'peanut.release-versions.v1',
        'product_release' => '1.4.1',
        'scaffold_template' => '3.0.9',
        'generated_application_default' => '0.1.0',
        'core_php' => '0.1.0-alpha.12',
        'core_web' => '0.1.0-alpha.12',
    ]);
    $targetTree = upgradeTargetGitTree($releaseRoot);
    $emptyMigrationDigest = hash('sha256', '[]');
    $descriptor = [
        'schema_version' => 1,
        'protocol' => 'peanut.application-upgrade-target.v1',
        'release' => [
            'key' => 'v1.4.1',
            'commit' => $targetCommit,
            'tree' => $targetTree,
            'qualification' => [
                'status' => 'passed',
                'candidate_commit' => $targetCommit,
                'candidate_tree' => $targetTree,
                'groups_passed' => 7,
                'cleanup_residual_count' => 0,
                'lease_released' => true,
            ],
        ],
        'scaffold' => [
            'from_version' => '3.0.8',
            'from_manifest_sha256' => hash_file('sha256', $fromManifestPath),
            'to_version' => '3.0.9',
            'to_manifest_sha256' => hash_file('sha256', $toManifestPath),
        ],
        'migrations' => [
            'from' => ['inventory_sha256' => $emptyMigrationDigest, 'files' => []],
            'to' => ['inventory_sha256' => $emptyMigrationDigest, 'files' => []],
        ],
        'modules' => [
            'lock_sha256' => hash_file('sha256', $targetLockPath),
            'kernel_version' => '2.0.0',
        ],
    ];
    $descriptorPath = $targetRoot . '/target.json';
    upgradeTargetWriteJson($descriptorPath, $descriptor);

    $target = PlatformUpgradeTarget::load($projectRoot);
    upgradeTargetExpect($target->releaseRoot === realpath($releaseRoot), 'target release root changed');
    upgradeTargetExpect($target->releaseServerRoot === realpath($releaseRoot . '/server'), 'target server root changed');
    upgradeTargetExpect($target->targetLockPath === realpath($targetLockPath), 'target lock path changed');
    $resolved = (new PluginLockResolver($target->releaseServerRoot, $target->targetLockPath))
        ->require('fixture.delivery-record');
    upgradeTargetExpect(
        $resolved->moduleRoots['fixture.delivery-record'] === realpath($targetModule),
        'target lock resolved Module bytes from the current application',
    );

    $container = new Container();
    Container::setInstance($container);
    $container->instance('config', new ThinkConfig());
    Config::set([
        'roots' => ['app/modules/fixture/delivery_record'],
        'kernel_version' => '1.0.0',
        'registered_client_keys' => ['admin-web', 'platform-web'],
    ], 'modules');

    $pdo = new PDO('sqlite::memory:', null, null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    $pdo->exec(<<<'SQL'
CREATE TABLE pa_module_installation (
  module_key TEXT PRIMARY KEY,
  installed_version TEXT NOT NULL,
  manifest_schema_version INTEGER NOT NULL,
  manifest_digest TEXT NOT NULL,
  status TEXT NOT NULL
);
CREATE TABLE pa_plugin_module (
  plugin_key TEXT NOT NULL,
  module_key TEXT NOT NULL
);
SQL);
    $currentManifest = (new ManifestLoader())->load(
        $sourceRoot . '/server/app/modules/fixture/delivery_record',
    );
    $statement = $pdo->prepare(
        'INSERT INTO pa_module_installation '
        . '(module_key,installed_version,manifest_schema_version,manifest_digest,status) VALUES (?,?,?,?,?)',
    );
    $statement->execute([
        'fixture.delivery-record',
        '1.0.0',
        1,
        $currentManifest->digest,
        'active',
    ]);

    $moduleConfig = Config::get('modules', []);
    upgradeTargetExpect(is_array($moduleConfig), 'Module fixture configuration is unavailable');
    $audit = new AuditContractHost(null);
    $permissions = new PlatformOpsPermissionChecker(new PlatformAuthorizationEvaluator(
        new ThinkPhpPlatformAuthorizationRepository(),
        new RevisionPermissionCache(),
    ));
    $providers = new BackupRestoreProviderRegistry([new PairedBackupProvider()]);
    $tasks = new OpsTaskService($permissions, $providers, new ThinkPhpOpsTaskDispatcher($audit));
    $maintenance = new MaintenanceService(
        $permissions,
        new MaintenanceReasonRegistry(['planned-upgrade']),
        new ThinkPhpMaintenanceWindowStore($audit),
    );
    $service = new PlatformUpgradeReadinessService(
        $projectRoot,
        new ThinkPhpModuleGovernanceProvider(
            $projectRoot . '/server',
            $moduleConfig,
            ThinkPhpTestConnection::moduleCatalogs($pdo),
        ),
        new PlatformBackupCenterService($providers, $tasks, $permissions),
        $maintenance,
        $permissions,
    );
    $moduleProjection = Closure::bind(
        fn(PlatformUpgradeTarget $value): array => $this->moduleProjection($value),
        $service,
        PlatformUpgradeReadinessService::class,
    );
    upgradeTargetExpect(is_callable($moduleProjection), 'Module projection fixture cannot access the focused method');
    $projection = $moduleProjection($target);
    upgradeTargetExpect(
        ($projection['status'] ?? null) === 'ready'
            && ($projection['compatible_count'] ?? null) === 1
            && ($projection['target_kernel_version'] ?? null) === '2.0.0',
        'target Module source or target Kernel constraint was not used',
    );

    $sourceIdentity = Closure::bind(
        fn(): ?array => $this->sourceIdentity(),
        $service,
        PlatformUpgradeReadinessService::class,
    );
    $directionCode = Closure::bind(
        fn(array $source, PlatformUpgradeTarget $value): ?string => $this->directionCode($source, $value),
        $service,
        PlatformUpgradeReadinessService::class,
    );
    $scaffoldProjection = Closure::bind(
        fn(PlatformUpgradeTarget $value): array => $this->scaffoldProjection($value),
        $service,
        PlatformUpgradeReadinessService::class,
    );
    upgradeTargetExpect(is_callable($sourceIdentity) && is_callable($directionCode) && is_callable($scaffoldProjection), 'readiness fixture cannot access focused identity methods');
    $source = $sourceIdentity();
    upgradeTargetExpect(
        is_array($source)
            && ($source['product_release'] ?? null) === '1.4.0'
            && $directionCode($source, $target) === null,
        'independent application and scaffold directions were not accepted',
    );
    $provenance = $scaffoldProjection($target);
    upgradeTargetExpect(
        ($provenance['status'] ?? null) === 'ready'
            && ($provenance['code'] ?? null) === 'UPGRADE_SCAFFOLD_PROVENANCE_READY'
            && ($provenance['automatic'] ?? null) === 0
            && ($provenance['conflicts'] ?? null) === 0,
        'deployment readiness did not report verified target scaffold provenance',
    );

    $rewrittenSourceManifest = $sourceApplicationManifest;
    $rewrittenSourceManifest['template']['inventory_sha256'] = str_repeat('0', 64);
    upgradeTargetWriteJson($sourceApplicationManifestPath, $rewrittenSourceManifest);
    $rewrittenSource = $sourceIdentity();
    upgradeTargetExpect(
        is_array($rewrittenSource)
            && $directionCode($rewrittenSource, $target) === 'UPGRADE_SOURCE_RELEASE_MISMATCH',
        'current template provenance drift was accepted',
    );
    upgradeTargetWriteJson($sourceApplicationManifestPath, $sourceApplicationManifest);

    $sameProductVersions = $sourceReleaseVersions;
    $sameProductVersions['product_release'] = '1.4.1';
    $sameProductMetadata = $sourceReleaseMetadata;
    $sameProductMetadata['version'] = '1.4.1';
    $sameProductMetadata['expected_tag'] = 'v1.4.1';
    upgradeTargetWriteJson($sourceReleaseVersionsPath, $sameProductVersions);
    upgradeTargetWriteJson($sourceReleaseMetadataPath, $sameProductMetadata);
    $sameProductSource = $sourceIdentity();
    upgradeTargetExpect(
        is_array($sameProductSource)
            && $directionCode($sameProductSource, $target) === 'UPGRADE_TARGET_NOT_NEWER',
        'non-increasing application release was accepted',
    );
    upgradeTargetWriteJson($sourceReleaseVersionsPath, $sourceReleaseVersions);
    upgradeTargetWriteJson($sourceReleaseMetadataPath, $sourceReleaseMetadata);

    $otherIdentityManifest = $sourceApplicationManifest;
    $otherIdentityManifest['application']['slug'] = 'other-console';
    upgradeTargetWriteJson($sourceApplicationManifestPath, $otherIdentityManifest);
    $otherIdentitySource = $sourceIdentity();
    upgradeTargetExpect(
        is_array($otherIdentitySource)
            && $directionCode($otherIdentitySource, $target) === 'UPGRADE_APPLICATION_IDENTITY_MISMATCH',
        'different stable application identity was accepted',
    );
    upgradeTargetWriteJson($sourceApplicationManifestPath, $sourceApplicationManifest);

    upgradeTargetWriteJson($fromManifestPath, $toManifest);
    $sameScaffoldDescriptor = $descriptor;
    $sameScaffoldDescriptor['scaffold']['from_version'] = '3.0.9';
    $sameScaffoldDescriptor['scaffold']['from_manifest_sha256'] = hash_file('sha256', $fromManifestPath);
    upgradeTargetWriteJson($descriptorPath, $sameScaffoldDescriptor);
    $sameScaffoldTarget = PlatformUpgradeTarget::load($projectRoot);
    $sameScaffoldSourceManifest = $sourceApplicationManifest;
    $sameScaffoldSourceManifest['template'] = $toManifest['release'];
    $sameScaffoldSourceVersions = $sourceReleaseVersions;
    $sameScaffoldSourceVersions['scaffold_template'] = '3.0.9';
    upgradeTargetWriteJson($sourceApplicationManifestPath, $sameScaffoldSourceManifest);
    upgradeTargetWriteJson($sourceReleaseVersionsPath, $sameScaffoldSourceVersions);
    $sameScaffoldSource = $sourceIdentity();
    upgradeTargetExpect(
        is_array($sameScaffoldSource) && $directionCode($sameScaffoldSource, $sameScaffoldTarget) === null,
        'same-version scaffold provenance was rejected despite identical manifests',
    );
    upgradeTargetWriteJson($sourceApplicationManifestPath, $sourceApplicationManifest);
    upgradeTargetWriteJson($sourceReleaseVersionsPath, $sourceReleaseVersions);
    upgradeTargetWriteJson($fromManifestPath, $fromManifest);
    upgradeTargetWriteJson($descriptorPath, $descriptor);

    $invalidKernel = $descriptor;
    $invalidKernel['modules']['kernel_version'] = '^2.0';
    upgradeTargetWriteJson($descriptorPath, $invalidKernel);
    upgradeTargetRejects(
        static fn() => PlatformUpgradeTarget::load($projectRoot),
        'UPGRADE_TARGET_MODULE_LOCK_INVALID',
    );
    upgradeTargetWriteJson($descriptorPath, $descriptor);

    $invalidApplicationVersion = $targetApplicationManifest;
    $invalidApplicationVersion['application']['version'] = 'recent-adoption';
    upgradeTargetWriteJson($targetApplicationManifestPath, $invalidApplicationVersion);
    $invalidApplicationDescriptor = $descriptor;
    $invalidApplicationTree = upgradeTargetGitTree($releaseRoot);
    $invalidApplicationDescriptor['release']['tree'] = $invalidApplicationTree;
    $invalidApplicationDescriptor['release']['qualification']['candidate_tree'] = $invalidApplicationTree;
    upgradeTargetWriteJson($descriptorPath, $invalidApplicationDescriptor);
    upgradeTargetRejects(
        static fn() => PlatformUpgradeTarget::load($projectRoot),
        'UPGRADE_TARGET_APPLICATION_MANIFEST_INVALID',
    );
    upgradeTargetWriteJson($targetApplicationManifestPath, $targetApplicationManifest);
    upgradeTargetWriteJson($descriptorPath, $descriptor);

    $sameVersionFromManifest = $fromManifest;
    $sameVersionFromManifest['release']['version'] = '3.0.9';
    upgradeTargetWriteJson($fromManifestPath, $sameVersionFromManifest);
    $sameVersionDescriptor = $descriptor;
    $sameVersionDescriptor['scaffold']['from_version'] = '3.0.9';
    $sameVersionDescriptor['scaffold']['from_manifest_sha256'] = hash_file('sha256', $fromManifestPath);
    upgradeTargetWriteJson($descriptorPath, $sameVersionDescriptor);
    upgradeTargetRejects(
        static fn() => PlatformUpgradeTarget::load($projectRoot),
        'UPGRADE_TARGET_SCAFFOLD_INVALID',
    );
    upgradeTargetWriteJson($fromManifestPath, $fromManifest);
    upgradeTargetWriteJson($descriptorPath, $descriptor);

    file_put_contents($targetMarker, "tampered target release bytes\n");
    upgradeTargetRejects(
        static fn() => PlatformUpgradeTarget::load($projectRoot),
        'UPGRADE_TARGET_RELEASE_TREE_INVALID',
    );
    file_put_contents($targetMarker, "target release module bytes\n");

    chmod($executablePath, 0644);
    upgradeTargetRejects(
        static fn() => PlatformUpgradeTarget::load($projectRoot),
        'UPGRADE_TARGET_RELEASE_TREE_INVALID',
    );
    chmod($executablePath, 0755);

    $outside = $temporary . '/outside.txt';
    file_put_contents($outside, "outside\n");
    $symlink = $releaseRoot . '/target-escape';
    symlink($outside, $symlink);
    upgradeTargetRejects(
        static fn() => PlatformUpgradeTarget::load($projectRoot),
        'UPGRADE_TARGET_RELEASE_TREE_INVALID',
    );
    unlink($symlink);
} finally {
    upgradeTargetRemoveTree($temporary);
}

echo "PLATFORM-UPGRADE-TARGET-MODULE-001 passed\n";
