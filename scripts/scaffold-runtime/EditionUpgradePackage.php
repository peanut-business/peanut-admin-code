<?php

declare(strict_types=1);

namespace app\common\infrastructure\scaffold;

use app\common\validation\scaffold\ScaffoldPathGuard;
use app\common\value\scaffold\ScaffoldManifest;
use app\common\value\scaffold\ScaffoldSourceIdentity;
use RuntimeException;

final class EditionUpgradePackage
{
    private const VERSION = '/^(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)(?:-(?:0|[1-9][0-9]*|[0-9]*[A-Za-z-][0-9A-Za-z-]*)(?:\.(?:0|[1-9][0-9]*|[0-9]*[A-Za-z-][0-9A-Za-z-]*))*)?(?:\+[0-9A-Za-z-]+(?:\.[0-9A-Za-z-]+)*)?$/D';
    public const OWNERSHIP_ADOPTION_PATHS = [
        'server/app/AppService.php',
        'server/app/BaseController.php',
        'server/app/middleware.php',
        'server/app/adminapi/middleware.php',
        'server/app/api/middleware.php',
        'server/app/installation/middleware.php',
        'server/app/platform/middleware.php',
        'server/app/adminapi/http/middleware/AuthMiddleware.php',
        'server/app/adminapi/http/middleware/LoginMiddleware.php',
        'server/app/adminapi/http/middleware/OperationLogMiddleware.php',
        'server/app/api/middleware/CheckTokenMiddleware.php',
        'server/app/api/middleware/PublicTenantModuleMiddleware.php',
        'server/app/common/http/middleware/InstallationStateMiddleware.php',
        'server/app/common/http/middleware/MaintenanceWriteGateMiddleware.php',
        'server/app/common/services/installation/InstallationExecutionHost.php',
        'server/app/common/services/installation/InstallationPreflightHost.php',
        'server/app/common/traits/ApiResponseTrait.php',
        'server/app/common/traits/CrudTrait.php',
        'server/app/common/validate/InputValidator.php',
        'server/app/common/validate/ListsValidate.php',
        'server/app/common/validate/MemberProfileSelfFieldValidate.php',
        'server/app/common/validate/PageSizeRule.php',
        'server/app/common/validate/TenantContextValidate.php',
        'server/app/common/validate/ValidatedInput.php',
        'server/app/installation/http/middleware/InstallationExecutionMiddleware.php',
        'server/app/platform/http/middleware/PlatformHostMiddleware.php',
        'server/app/platform/http/middleware/PlatformInstanceToolMiddleware.php',
        'server/app/platform/http/middleware/PlatformLoginMiddleware.php',
        'server/app/platform/http/middleware/PlatformPermissionMiddleware.php',
        'server/app/common/contract/module/ModuleGovernanceProvider.php',
        'server/app/common/contract/module/PluginLifecycleCommands.php',
        'server/app/common/contract/module/ModuleQualificationQuery.php',
        'server/app/common/contract/module/ModuleQualification.php',
        'server/app/common/contract/module/TenantModuleState.php',
        'server/app/common/persistence/AdvisoryLockExecution.php',
        'server/app/common/persistence/AdvisoryLockUnavailable.php',
        'server/app/common/persistence/TenantPersistenceConfiguration.php',
        'server/app/platform/infrastructure/module/ThinkPhpModuleGovernanceProvider.php',
        'server/app/platform/infrastructure/module/DeployedTenantModuleRegistry.php',
        'server/app/platform/services/module/ModuleQualificationQueryService.php',
        'server/app/platform/validation/module/ModulePublicSurfacePolicy.php',
        'server/app/platform/validation/module/OpisManifestSchemaValidator.php',
        'server/app/platform/validation/module/ReflectionContractInspector.php',
        'server/app/platform/validation/module/StrictVersionConstraintMatcher.php',
        'server/app/platform/services/plugin/PluginLifecycleService.php',
        'server/app/platform/exception/plugin/PluginLifecycleException.php',
        'server/app/platform/value/plugin/PluginDescriptor.php',
        'server/app/platform/infrastructure/plugin/PluginLockResolver.php',
        'server/app/platform/exception/plugin/PluginArtifactToolException.php',
        'server/app/platform/exception/plugin/PluginPackageException.php',
        'server/app/platform/value/plugin/ModuleFrontendLayout.php',
        'server/app/platform/infrastructure/plugin/PluginArtifactWriter.php',
        'server/app/platform/validation/plugin/ModulePackagePreflight.php',
        'server/app/platform/composition/plugin/PluginModuleRegistryFactory.php',
        'server/app/platform/composition/plugin/ModuleDefinitionRegistryFactory.php',
        'server/app/platform/policy/plugin/ModuleLifecyclePolicy.php',
        'server/app/platform/infrastructure/plugin/ModuleCatalogApplier.php',
        'server/app/platform/infrastructure/plugin/ScopedMenuCatalogRepository.php',
        'server/app/platform/infrastructure/plugin/ModuleCatalogMutationRepository.php',
        'server/database/install.php',
    ];

    /** @return array{from_manifest:string,to_manifest:string,package:array<string,mixed>} */
    public function prepare(string $projectRoot, string $packageRoot, ?string $modeManifestPath = null): array
    {
        $prepared = $this->authenticate($projectRoot, $packageRoot, true);
        $prepared['from_manifest'] = ScaffoldManifest::writeRecordedBaseline($prepared['project_root'], $modeManifestPath);
        unset($prepared['project_root'], $prepared['application']);
        return $prepared;
    }

    /** Reuse the authenticated predecessor's sealed target snapshot; do not rebuild a different baseline. */
    public function preparePartialSourceSuccessor(string $projectRoot, string $packageRoot, string $predecessor): array
    {
        $root = ScaffoldPathGuard::projectRoot($projectRoot);
        if (!class_exists(\PeanutServerUpdatePlan::class, false)) { require_once dirname(__DIR__, 2) . '/server/docker/scripts/update-plan.php'; }
        return ScaffoldUpgradeRunner::sourceProductLock($root, fn(): array => \PeanutServerUpdatePlan::withSourceInstanceLock($root . '/server',
            function () use ($root, $packageRoot, $predecessor): array {
                $previous = \app\common\services\upgrade\SourceApplicationBackup::protectedPluginJson($predecessor, $root . '/server');
                $plan = \app\common\services\upgrade\SourceApplicationBackup::protectedPluginJson($previous['binding']['plan_path'], $root . '/server');
                $from = ScaffoldManifest::load($plan['manifest_paths']['to']);
                $toPath = ScaffoldPathGuard::existingFileWithin($packageRoot, $packageRoot . '/scaffold-manifest.json', 'SOURCE_SUCCESSOR_TARGET_UNSAFE');
                $to = ScaffoldManifest::load($toPath);
                $context = \PeanutServerUpdatePlan::sourceSuccessorContext($root . '/server', $from->release(), $to->release());
                if ($context['predecessor']['checkpoint']['path'] !== $predecessor
                    || $context['source_manifest']['path'] !== $from->path || 'sha256:' . $context['source_manifest']['sha256'] !== $from->digest()) {
                    throw new RuntimeException('SOURCE_SUCCESSOR_BASELINE_CHANGED');
                }
                return ['from_manifest' => $from->path, 'to_manifest' => $to->path];
            }));
    }

    /** Authenticate a formal package without writing source-baseline metadata. */
    public function prepareAdoption(string $projectRoot, string $packageRoot): array
    {
        $prepared = $this->authenticate($projectRoot, $packageRoot, true);
        unset($prepared['project_root'], $prepared['application']);
        return $prepared;
    }

    /** Reauthenticate a plan-bound package after its target application manifest may already be active. */
    public function reauthenticate(string $projectRoot, string $packageRoot): array
    {
        $prepared = $this->authenticate($projectRoot, $packageRoot, false);
        unset($prepared['project_root'], $prepared['application']);
        return $prepared;
    }

    /** Same native source coordinator, with the public template archive/cache protocol. */
    public function authenticateTemplate(string $projectRoot, string $packageRoot): array
    {
        $root = ScaffoldPathGuard::projectRoot($projectRoot);
        $package = ScaffoldPathGuard::projectRoot($packageRoot);
        $metadataPath = ScaffoldPathGuard::existingFileWithin($root, $package . '/template-package.json', 'TEMPLATE_UPGRADE_METADATA_UNSAFE');
        $metadata = json_decode((string) file_get_contents($metadataPath), true, 512, JSON_THROW_ON_ERROR);
        if ($package !== $root . '/.peanut/upgrades/template-packages/' . ($metadata['archive']['sha256'] ?? '')) {
            throw new RuntimeException('TEMPLATE_UPGRADE_CACHE_IDENTITY_INVALID');
        }
        $pipes = [];
        $process = proc_open(['python3', '-B', dirname(__DIR__) . '/product-upgrade-host', 'source-template-check', '--cache=' . $package],
            [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        if (!is_resource($process)) { throw new RuntimeException('TEMPLATE_UPGRADE_VALIDATOR_UNAVAILABLE'); }
        $output = stream_get_contents($pipes[1]); $diagnostic = stream_get_contents($pipes[2]);
        fclose($pipes[1]); fclose($pipes[2]);
        $code = proc_close($process);
        $checked = json_decode($output, true);
        if ($code !== 0 || $diagnostic !== '' || ($checked['status'] ?? null) !== 'verified'
            || ($checked['metadata_sha256'] ?? null) !== hash_file('sha256', $metadataPath)) {
            throw new RuntimeException('TEMPLATE_UPGRADE_ARCHIVE_REJECTED:' . hash('sha256', $diagnostic));
        }
        $application = $this->applicationManifest($root);
        $target = ScaffoldManifest::load($package . '/scaffold-manifest.json');
        $currentVersion = $application['template']['version'] ?? '';
        if (preg_match(self::VERSION, $currentVersion) !== 1 || preg_match(self::VERSION, $target->version()) !== 1
            || Semver::compare($currentVersion, $target->version()) > 0) {
            throw new RuntimeException('TEMPLATE_UPGRADE_SOURCE_RANGE_UNSUPPORTED');
        }
        if (($metadata['edition'] ?? null) !== ($application['application']['edition'] ?? null)
            || ($target->data['edition']['name'] ?? null) !== ($application['edition']['name'] ?? null)
            || ($checked['manifest_sha256'] ?? null) !== substr($target->digest(), 7)) {
            throw new RuntimeException('TEMPLATE_UPGRADE_EDITION_MISMATCH');
        }
        $selection = ['repository' => 'peanut-business/peanut-admin-code',
            'requested_ref' => 'local:' . $metadata['archive']['sha256'], 'channel' => 'development',
            'commit' => $metadata['source']['commit'], 'tree' => $metadata['source']['tree'],
            'template_package_path' => $metadataPath, 'template_package_sha256' => 'sha256:' . hash_file('sha256', $metadataPath)];
        return ['project_root' => $root, 'application' => $application,
            'to_manifest' => $target->path, 'source_selection' => $selection,
            'package' => ['protocol' => $metadata['protocol'], 'target' => ['version' => $target->version()],
                'edition' => $target->data['edition'], 'inventory_sha256' => 'sha256:' . $metadata['archive']['sha256'],
                'manifest_sha256' => $selection['template_package_sha256']]];
    }

    /** @return array<string,mixed> */
    private function authenticate(
        string $projectRoot,
        string $packageRoot,
        bool $requireSourceCompatibility,
    ): array {
        $project = ScaffoldPathGuard::projectRoot($projectRoot);
        $package = realpath($packageRoot);
        if (!is_string($package) || !is_dir($package) || is_link($package)) {
            throw new RuntimeException('EDITION_UPGRADE_PACKAGE_NOT_FOUND');
        }
        if (file_exists($package . '/template-package.json') || is_link($package . '/template-package.json')) {
            return $this->authenticateTemplate($project, $package);
        }
        $inventoryPath = $package . '/META-INF/files.sha256';
        if (!is_file($inventoryPath) || is_link($inventoryPath)) {
            throw new RuntimeException('EDITION_UPGRADE_INVENTORY_MISSING');
        }
        $inventory = (string) file_get_contents($inventoryPath);
        $files = $this->verifyInventory($package, $inventory);

        $manifestPath = $package . '/upgrade-manifest.json';
        if (!isset($files['upgrade-manifest.json'])) {
            throw new RuntimeException('EDITION_UPGRADE_MANIFEST_MISSING');
        }
        try {
            $manifest = json_decode((string) file_get_contents($manifestPath), true, 128, JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            throw new RuntimeException('EDITION_UPGRADE_MANIFEST_INVALID', 0, $exception);
        }
        if (!is_array($manifest)
            || ($manifest['schema_version'] ?? null) !== 1
            || ($manifest['protocol'] ?? null) !== 'peanut.edition-upgrade-package.v1'
            || ($manifest['upgrader']['scope'] ?? null) !== 'source'
            || ($manifest['upgrader']['installed_entrypoint'] ?? null) !== 'scripts/upgrade'
            || ($manifest['upgrader']['internal_scaffold_engine'] ?? null) !== 'scripts/scaffold-upgrade'
            || isset($manifest['upgrader']['host_driver'])
            || isset($manifest['upgrader']['database_driver'])
            || !isset(
                $files['scripts/scaffold-upgrade'],
                $files['scripts/scaffold-runtime/ScaffoldPathGuard.php'],
                $files['scripts/scaffold-runtime/ScaffoldManifest.php'],
                $files['scripts/scaffold-runtime/ScaffoldUpgradeLedger.php'],
                $files['scripts/scaffold-runtime/Semver.php'],
                $files['scripts/scaffold-runtime/ScaffoldUpgradeRunner.php'],
                $files['scripts/scaffold-runtime/EditionUpgradePackage.php'],
            )) {
            throw new RuntimeException('EDITION_UPGRADE_MANIFEST_INVALID');
        }

        $application = $this->applicationManifest($project);
        $packageEdition = $this->packageEdition($manifest['edition'] ?? null);
        $edition = $packageEdition['name'];
        if (!in_array($edition, ['standalone', 'multi-tenant'], true)
            || ($application['application']['edition'] ?? null) !== $edition
            || ($application['edition']['name'] ?? null) !== $edition
            || (isset($application['edition']['tenant_bootstrap'])
                && $application['edition']['tenant_bootstrap'] !== $packageEdition['tenant_bootstrap'])) {
            throw new RuntimeException('EDITION_UPGRADE_EDITION_MISMATCH');
        }
        $current = (string) ($application['template']['version'] ?? '');
        $minimum = (string) ($manifest['compatibility']['source']['minimum_inclusive'] ?? '');
        $maximum = (string) ($manifest['compatibility']['source']['maximum_exclusive'] ?? '');
        $target = (string) ($manifest['target']['version'] ?? '');
        $majorPolicy = $manifest['compatibility']['major_policy'] ?? null;
        if (preg_match(self::VERSION, $current) !== 1
            || preg_match(self::VERSION, $minimum) !== 1
            || preg_match(self::VERSION, $maximum) !== 1
            || preg_match(self::VERSION, $target) !== 1
            || $maximum !== $target
            || !in_array($majorPolicy, ['same-major', 'source-range'], true)
            || Semver::greaterThanOrEqualTo($minimum, $target)
            || ($majorPolicy === 'same-major'
                && (explode('.', $minimum, 2)[0] !== explode('.', $target, 2)[0]
                    || explode('.', $current, 2)[0] !== explode('.', $target, 2)[0]))) {
            throw new RuntimeException('EDITION_UPGRADE_RELEASE_CHAIN_INVALID');
        }
        if (($requireSourceCompatibility || $current !== $target)
            && (Semver::lessThan($current, $minimum) || Semver::greaterThanOrEqualTo($current, $target))) {
            throw new RuntimeException(($application['generation_source']['channel'] ?? null) === 'development'
                ? 'SOURCE_RANGE_UNSUPPORTED' : 'EDITION_UPGRADE_RELEASE_CHAIN_INVALID');
        }

        $targetRelative = (string) ($manifest['target']['scaffold_manifest'] ?? '');
        ScaffoldManifest::path($targetRelative);
        if (!isset($files[$targetRelative])) {
            throw new RuntimeException('EDITION_UPGRADE_TARGET_MANIFEST_MISSING');
        }
        $targetPath = $package . '/' . $targetRelative;
        $targetManifest = ScaffoldManifest::load($targetPath);
        $targetDigest = hash_file('sha256', $targetPath);
        $release = $targetManifest->release();
        $targetEdition = $targetManifest->data['edition'] ?? null;
        if (!is_array($targetEdition)
            || !is_string($targetDigest)
            || !hash_equals((string) ($manifest['target']['scaffold_manifest_sha256'] ?? ''), $targetDigest)
            || $targetManifest->version() !== $target
            || ($targetEdition['name'] ?? null) !== $packageEdition['name']
            || ($targetEdition['deployment_mode'] ?? null) !== $packageEdition['deployment_mode']
            || ($targetEdition['source_sha256'] ?? null) !== $packageEdition['profile_sha256']
            || ($targetEdition['generator_version'] ?? null) !== $packageEdition['generator_version']
            || ($targetEdition['module_profile'] ?? null) !== $packageEdition['module_profile']
            || ($targetEdition['tenant_bootstrap'] ?? null) !== $packageEdition['tenant_bootstrap']
            || ($targetEdition['schema']['projection'] ?? null) !== $packageEdition['schema_projection']
            || $targetManifest->sourceIdentity() !== ScaffoldSourceIdentity::from($manifest['build_source'] ?? [], true)
            || ($release['inventory_sha256'] ?? null) !== ($manifest['build_source']['inventory_sha256'] ?? null)
            || ($release['managed_tree_sha256'] ?? null) !== ($manifest['target']['managed_tree_sha256'] ?? null)) {
            throw new RuntimeException('EDITION_UPGRADE_TARGET_IDENTITY_MISMATCH');
        }
        $this->assertInternalCoreProjection($targetManifest, $manifest);

        $this->assertOwnership($manifest);
        $this->assertMigrationChain($manifest, $targetManifest);
        $adoption = $requireSourceCompatibility
            ? $this->assertAdoption($package, $files, $manifest, $application, $targetManifest)
            : null;

        return [
            'to_manifest' => $targetManifest->path,
            'adoption' => $adoption,
            'project_root' => $project,
            'application' => $application,
            'package' => $manifest + [
                'inventory_sha256' => 'sha256:' . hash('sha256', $inventory),
                'manifest_sha256' => 'sha256:' . hash_file('sha256', $manifestPath),
            ],
        ];
    }

    /** The identified target and upgrade manifest must identify the same projected Core bytes. */
    private function assertInternalCoreProjection(ScaffoldManifest $target, array $upgrade): void
    {
        $projection = $target->data['internal_core_projection'] ?? null;
        $declared = $upgrade['target']['internal_core_projection'] ?? null;
        if ($projection === null && $declared === null) {
            return;
        }
        $release = $target->release();
        $core = is_array($projection) ? ($projection['core'] ?? null) : null;
        $path = is_array($core) ? ($core['archive_path'] ?? null) : null;
        if (!is_array($projection) || $projection !== $declared || !is_array($core)
            || ($upgrade['scope'] ?? null) !== 'internal-upgrade-candidate'
            || ($upgrade['formal_release_eligible'] ?? null) !== false
            || ($projection['protocol'] ?? null) !== 'peanut.internal-core-scaffold-projection.v1'
            || ($projection['scope'] ?? null) !== 'internal-upgrade-target-not-formal-release'
            || ($target->sourceIdentity()['kind'] === 'local'
                ? (($projection['source_identity'] ?? null) !== $target->sourceIdentity() || array_key_exists('source', $projection))
                : (($projection['source'] ?? null) !== ['commit' => $target->sourceIdentity()['commit'], 'tree' => $target->sourceIdentity()['tree']]))
            || ($projection['managed_tree_sha256']['projected'] ?? null) !== ($release['managed_tree_sha256'] ?? null)
            || preg_match('/^[a-f0-9]{64}$/D', (string) ($projection['core_inputs_sha256'] ?? '')) !== 1
            || !is_string($path) || !str_starts_with($path, 'packages/core-php/') || !str_ends_with($path, '.zip')) {
            throw new RuntimeException('EDITION_UPGRADE_INTERNAL_PROJECTION_INVALID');
        }
        ScaffoldManifest::path($path);
        $files = $target->files();
        $coreFile = $files[$path] ?? null;
        $versionFile = $files['release-versions.json'] ?? null;
        $lockFile = $files['server/composer.lock'] ?? null;
        if (!is_array($coreFile) || !is_array($versionFile) || !is_array($lockFile)
            || ($coreFile['template_sha256'] ?? null) !== ($core['archive_sha256'] ?? null)) {
            throw new RuntimeException('EDITION_UPGRADE_INTERNAL_PROJECTION_FILES_INVALID');
        }
        $rows = [];
        foreach ($files as $name => $file) {
            $artifact = $target->artifactPath($file);
            $digest = hash_file('sha256', $artifact);
            if (!is_string($digest) || !hash_equals((string) $file['template_sha256'], $digest)) {
                throw new RuntimeException('EDITION_UPGRADE_INTERNAL_TARGET_DIGEST_INVALID: ' . $name);
            }
            $rows[] = $name . "\0" . $digest;
        }
        sort($rows, SORT_STRING);
        if (!hash_equals((string) $release['managed_tree_sha256'], hash('sha256', implode("\n", $rows)))) {
            throw new RuntimeException('EDITION_UPGRADE_INTERNAL_TARGET_TREE_INVALID');
        }
        try {
            $version = json_decode((string) file_get_contents($target->artifactPath($versionFile)), true, 512, JSON_THROW_ON_ERROR);
            $lock = json_decode((string) file_get_contents($target->artifactPath($lockFile)), true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            throw new RuntimeException('EDITION_UPGRADE_INTERNAL_DEPENDENCY_INVALID', 0, $exception);
        }
        $locked = array_values(array_filter($lock['packages'] ?? [], static fn(mixed $package): bool => is_array($package) && ($package['name'] ?? null) === 'peanut-admin/core'));
        if (!is_array($version)
            || ($version['source_product_version'] ?? null) !== ($release['version'] ?? null)
            || ($version['core_php'] ?? null) !== ($projection['release_versions']['after'] ?? null)
            || count($locked) !== 1
            || ($locked[0]['version'] ?? null) !== ($core['resolved_version'] ?? null)
            || ($locked[0]['source']['reference'] ?? null) !== ($core['source_reference'] ?? null)
            || ($locked[0]['dist']['reference'] ?? null) !== ($core['source_reference'] ?? null)
            || ($locked[0]['dist']['url'] ?? null) !== '../' . $path
            || ($locked[0]['dist']['shasum'] ?? null) !== ($core['archive_sha1'] ?? null)) {
            throw new RuntimeException('EDITION_UPGRADE_INTERNAL_DEPENDENCY_INVALID');
        }
    }

    /** @return array{name:string,deployment_mode:string,profile_sha256:string,generator_version:int,module_profile:string,tenant_bootstrap:array<string,string>,schema_projection:string} */
    private function packageEdition(mixed $edition): array
    {
        $expectedBootstrap = [
            'kind' => 'real-default-tenant',
            'code' => 'default',
            'tenant_identity' => 'required',
            'rbac' => 'required',
            'execution_context' => 'PeanutAdmin\\Kernel\\Context\\TenantSystemContext',
            'module_lifecycle' => 'required',
        ];
        if (!is_array($edition)
            || array_keys($edition) !== [
                'name', 'deployment_mode', 'profile_sha256', 'generator_version',
                'module_profile', 'tenant_bootstrap', 'schema_projection',
            ]
            || !in_array($edition['name'] ?? null, ['standalone', 'multi-tenant'], true)
            || ($edition['deployment_mode'] ?? null) !== $edition['name']
            || preg_match('/^[a-f0-9]{64}$/D', (string) ($edition['profile_sha256'] ?? '')) !== 1
            || !is_int($edition['generator_version'] ?? null)
            || ($edition['module_profile'] ?? null) !== 'official-default'
            || ($edition['tenant_bootstrap'] ?? null) !== $expectedBootstrap
            || ($edition['schema_projection'] ?? null) !== 'tenant-owned-v1') {
            throw new RuntimeException('EDITION_UPGRADE_EDITION_CONTRACT_INVALID');
        }
        return $edition;
    }

    /** @return array<string,mixed>|null */
    private function assertAdoption(
        string $package,
        array $inventory,
        array $manifest,
        array $application,
        ScaffoldManifest $target,
    ): ?array {
        $adoption = $manifest['ownership']['adoption'] ?? null;
        if ($adoption === null) {
            return null;
        }
        $source = $adoption['source'] ?? null;
        $entries = $adoption['files'] ?? null;
        if (!is_array($source) || !is_array($entries)
            || ($adoption['protocol'] ?? null) !== 'peanut.ownership-adoption.v1'
            || ($source['version'] ?? null) !== ($application['template']['version'] ?? null)
            || ScaffoldSourceIdentity::from($source, true) !== ScaffoldSourceIdentity::from($application['template'] ?? [])) {
            throw new RuntimeException('EDITION_UPGRADE_ADOPTION_SOURCE_INVALID');
        }
        $targetFiles = $target->files();
        $actualPaths = [];
        foreach ($entries as $entry) {
            $path = is_array($entry) ? (string) ($entry['path'] ?? '') : '';
            ScaffoldManifest::path($path);
            $relative = is_array($entry) ? (string) ($entry['source'] ?? '') : '';
            ScaffoldManifest::path($relative);
            $digest = is_array($entry) ? (string) ($entry['sha256'] ?? '') : '';
            if (isset($actualPaths[$path]) || !isset($inventory[$relative])
                || preg_match('/^[a-f0-9]{64}$/D', $digest) !== 1
                || !hash_equals($digest, $inventory[$relative])
                || !in_array($entry['mode'] ?? null, [0644, 0755], true)
                || ($entry['classification'] ?? null) !== 'managed'
                || ($entry['owner'] ?? null) !== 'scaffold'
                || !isset($targetFiles[$path])
                || ($targetFiles[$path]['classification'] ?? null) !== 'managed') {
                throw new RuntimeException('EDITION_UPGRADE_ADOPTION_FILE_INVALID: ' . $path);
            }
            $absolute = ScaffoldPathGuard::existingFileWithin($package, $package . '/' . $relative, 'EDITION_UPGRADE_ADOPTION_SOURCE_INVALID');
            $actualPaths[$path] = $entry + ['absolute_source' => $absolute];
        }
        $expected = self::OWNERSHIP_ADOPTION_PATHS;
        sort($expected, SORT_STRING);
        $paths = array_keys($actualPaths);
        sort($paths, SORT_STRING);
        if ($paths !== $expected) {
            throw new RuntimeException('EDITION_UPGRADE_ADOPTION_SCOPE_INVALID');
        }
        ksort($actualPaths, SORT_STRING);
        return ['source' => $source, 'files' => $actualPaths];
    }

    /** @return array<string,string> */
    private function verifyInventory(string $root, string $contents): array
    {
        $inventory = [];
        $lines = explode("\n", $contents);
        if (array_pop($lines) !== '') {
            throw new RuntimeException('EDITION_UPGRADE_INVENTORY_INVALID');
        }
        $previous = null;
        foreach ($lines as $line) {
            $separator = strpos($line, "\0");
            if ($separator === false || strpos($line, "\0", $separator + 1) !== false) {
                throw new RuntimeException('EDITION_UPGRADE_INVENTORY_INVALID');
            }
            $path = substr($line, 0, $separator);
            $digest = substr($line, $separator + 1);
            ScaffoldManifest::path($path);
            if (preg_match('/^[a-f0-9]{64}$/D', $digest) !== 1
                || isset($inventory[$path])
                || ($previous !== null && strcmp($previous, $path) >= 0)) {
                throw new RuntimeException('EDITION_UPGRADE_INVENTORY_INVALID');
            }
            $absolute = $root . '/' . $path;
            if (!is_file($absolute) || is_link($absolute)
                || !hash_equals($digest, (string) hash_file('sha256', $absolute))) {
                throw new RuntimeException('EDITION_UPGRADE_FILE_DIGEST_MISMATCH: ' . $path);
            }
            $inventory[$path] = $digest;
            $previous = $path;
        }

        $actual = [];
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            if ($file->isLink() || !$file->isFile()) {
                throw new RuntimeException('EDITION_UPGRADE_FILE_TYPE_INVALID');
            }
            $relative = str_replace('\\', '/', substr($file->getPathname(), strlen($root) + 1));
            if ($relative === 'META-INF/files.sha256') {
                continue;
            }
            $actual[] = $relative;
        }
        sort($actual, SORT_STRING);
        if ($actual !== array_keys($inventory)) {
            throw new RuntimeException('EDITION_UPGRADE_INVENTORY_COVERAGE_MISMATCH');
        }
        return $inventory;
    }

    /** @return array<string,mixed> */
    private function applicationManifest(string $root): array
    {
        $path = ScaffoldPathGuard::projectPath($root, '.peanut/application-manifest.json');
        try {
            $manifest = is_file($path) && !is_link($path)
                ? json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR)
                : null;
        } catch (\JsonException $exception) {
            throw new RuntimeException('EDITION_UPGRADE_APPLICATION_MANIFEST_INVALID', 0, $exception);
        }
        if (!is_array($manifest)
            || ($manifest['protocol'] ?? null) !== 'peanut.application-scaffold.v2'
            || !is_array($manifest['application'] ?? null)
            || !is_array($manifest['edition'] ?? null)
            || !is_array($manifest['template'] ?? null)
            || !is_array($manifest['files'] ?? null)) {
            throw new RuntimeException('EDITION_UPGRADE_APPLICATION_MANIFEST_INVALID');
        }
        return $manifest;
    }

    /** @param array<string,mixed> $manifest */
    private function assertOwnership(array $manifest): void
    {
        if (($manifest['ownership']['automatic'] ?? null) !== ['managed', 'generated-managed']
            || ($manifest['ownership']['preserved'] ?? null) !== ['app-owned', 'third-party-module', 'secret']
            || ($manifest['recovery']['managed_files'] ?? null) !== 'scaffold-recovery-plan'
            || ($manifest['recovery']['database'] ?? null) !== 'not-applicable-source-only') {
            throw new RuntimeException('EDITION_UPGRADE_OWNERSHIP_INVALID');
        }
    }

    /** @param array<string,mixed> $manifest */
    private function assertMigrationChain(array $manifest, ScaffoldManifest $target): void
    {
        $chain = $manifest['migration_chain'] ?? null;
        if (!is_array($chain) || ($chain['strategy'] ?? null) !== 'append-only-ledger' || !is_array($chain['files'] ?? null)) {
            throw new RuntimeException('EDITION_UPGRADE_MIGRATION_CHAIN_INVALID');
        }
        $expected = [];
        foreach ($target->files() as $path => $file) {
            if (str_starts_with($path, 'server/database/migrations/') && str_ends_with($path, '.sql')) {
                $expected[$path] = $file['template_sha256'];
            }
        }
        $actual = [];
        foreach ($chain['files'] as $file) {
            if (!is_array($file) || !is_string($file['path'] ?? null) || !is_string($file['sha256'] ?? null)
                || isset($actual[$file['path']])) {
                throw new RuntimeException('EDITION_UPGRADE_MIGRATION_CHAIN_INVALID');
            }
            $actual[$file['path']] = $file['sha256'];
        }
        if ($actual !== $expected) {
            throw new RuntimeException('EDITION_UPGRADE_MIGRATION_CHAIN_INCOMPLETE');
        }
        // Validate data as package input, without loading executable PHP from the target candidate.
        $files = $target->files();
        $baselines = [
            '20261019-clean-install-5.0.2-r1' => ['directory' => '5.0.2-r1', 'revision' => 1],
            '20261019-clean-install-5.0.2-r2' => ['directory' => '5.0.2-r2', 'revision' => 2],
        ];
        if (!array_key_exists('installation_baseline', $chain)) {
            foreach ($baselines as $baseline) {
                if (isset($files['server/database/baselines/' . $baseline['directory'] . '/manifest.json'])) {
                    throw new RuntimeException('EDITION_UPGRADE_INSTALL_BASELINE_UNEXPECTED');
                }
            }
            return;
        }
        $identityKey = $chain['installation_baseline']['key'] ?? null;
        if (!is_string($identityKey) || !isset($baselines[$identityKey])) { throw new RuntimeException('EDITION_UPGRADE_INSTALL_BASELINE_INVALID'); }
        $source = $baselines[$identityKey];
        $prefix = 'server/database/baselines/' . $source['directory'] . '/';
        $bytes = [];
        foreach (['manifest.json', 'baseline.lock', 'schema.sql', 'data.sql'] as $name) {
            $file = $files[$prefix . $name] ?? null;
            if (!is_array($file)) { throw new RuntimeException('EDITION_UPGRADE_INSTALL_BASELINE_INCOMPLETE'); }
            $content = file_get_contents($target->artifactPath($file));
            if (!is_string($content) || hash('sha256', $content) !== $file['template_sha256']) { throw new RuntimeException('EDITION_UPGRADE_INSTALL_BASELINE_CHANGED'); }
            $bytes[$name] = $content;
        }
        $seed = json_decode($bytes['manifest.json'], true, 64, JSON_THROW_ON_ERROR);
        $lock = json_decode($bytes['baseline.lock'], true, 64, JSON_THROW_ON_ERROR);
        if (($seed['protocol'] ?? null) !== 'peanut.clean-install-baseline.v1' || ($seed['schema_version'] ?? null) !== 1
            || ($seed['key'] ?? null) !== $identityKey || ($seed['revision'] ?? null) !== $source['revision']
            || ($seed['source_product_version'] ?? null) !== '5.0.2' || ($seed['fresh_only'] ?? null) !== true
            || ($lock['protocol'] ?? null) !== 'peanut.clean-install-baseline-lock.v1' || ($lock['schema_version'] ?? null) !== 1
            || ($lock['key'] ?? null) !== $seed['key'] || !is_array($seed['covered_migrations'] ?? null)) {
            throw new RuntimeException('EDITION_UPGRADE_INSTALL_BASELINE_INVALID');
        }
        $locked = array_keys($lock['files'] ?? []); sort($locked, SORT_STRING);
        if ($locked !== ['data.sql', 'manifest.json', 'schema.sql']) { throw new RuntimeException('EDITION_UPGRADE_INSTALL_BASELINE_INVALID'); }
        foreach ($locked as $name) {
            if ($lock['files'][$name] !== hash('sha256', $bytes[$name])
                || ($name !== 'manifest.json' && ($seed['payloads'][$name] ?? null) !== $lock['files'][$name])) {
                throw new RuntimeException('EDITION_UPGRADE_INSTALL_BASELINE_CHANGED');
            }
        }
        foreach ($seed['covered_migrations'] as $covered) {
            if (!is_string($covered['path'] ?? null) || !is_string($covered['sha256'] ?? null)
                || ($actual[$covered['path']] ?? null) !== $covered['sha256']) { throw new RuntimeException('EDITION_UPGRADE_INSTALL_BASELINE_COVERAGE_CHANGED'); }
        }
        $identity = ['key' => $seed['key'], 'sha256' => hash('sha256', $bytes['manifest.json']), 'source_product_version' => $seed['source_product_version'],
            'revision' => $seed['revision'], 'coverage_semantics' => 'covered_by_baseline', 'covered_migrations' => $seed['covered_migrations']];
        if (($chain['installation_baseline'] ?? null) !== $identity) { throw new RuntimeException('EDITION_UPGRADE_INSTALL_BASELINE_IDENTITY_CHANGED'); }
    }

}
