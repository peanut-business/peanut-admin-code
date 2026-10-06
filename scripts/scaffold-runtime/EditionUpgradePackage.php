<?php

declare(strict_types=1);

namespace app\common\infrastructure\scaffold;

use app\common\validation\scaffold\ScaffoldPathGuard;
use app\common\value\scaffold\ScaffoldManifest;
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
    public function prepare(string $projectRoot, string $packageRoot): array
    {
        $prepared = $this->authenticate($projectRoot, $packageRoot, true);
        $prepared['from_manifest'] = $this->writeBaselineManifest($prepared['project_root'], $prepared['application']);
        unset($prepared['project_root'], $prepared['application']);
        return $prepared;
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
                    || explode('.', $current, 2)[0] !== explode('.', $target, 2)[0]))
            || (($requireSourceCompatibility || $current !== $target)
                && (Semver::lessThan($current, $minimum) || Semver::greaterThanOrEqualTo($current, $target)))) {
            throw new RuntimeException('EDITION_UPGRADE_RELEASE_CHAIN_INVALID');
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
            || ($release['source_commit'] ?? null) !== ($manifest['build_source']['commit'] ?? null)
            || ($release['source_tree'] ?? null) !== ($manifest['build_source']['tree'] ?? null)
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
            || ($projection['source'] ?? null) !== ['commit' => $release['source_commit'], 'tree' => $release['source_tree']]
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
            || ($source['commit'] ?? null) !== ($application['template']['source_commit'] ?? null)
            || ($source['tree'] ?? null) !== ($application['template']['source_tree'] ?? null)) {
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
    }

    /** @param array<string,mixed> $application */
    private function writeBaselineManifest(string $root, array $application): string
    {
        $version = (string) $application['template']['version'];
        $baselineRoot = '.peanut/scaffold-baseline/' . $version;
        $files = [];
        $baselineFiles = [];
        foreach ($application['files'] as $file) {
            if (!is_array($file) || !in_array($file['classification'] ?? null, ['managed', 'generated-managed'], true)) {
                continue;
            }
            $path = (string) ($file['path'] ?? '');
            ScaffoldManifest::path($path);
            $expectedBaseline = $baselineRoot . '/files/' . $path;
            if (($file['baseline_path'] ?? null) !== $expectedBaseline) {
                throw new RuntimeException('EDITION_UPGRADE_BASELINE_PATH_INVALID: ' . $path);
            }
            $absolute = ScaffoldPathGuard::projectPath($root, $expectedBaseline);
            $baselineFiles[$path] = $absolute;
            $digest = hash_file('sha256', $absolute);
            $expectedDigest = (string) ($file['baseline_sha256'] ?? $file['sha256'] ?? '');
            if (!is_string($digest) || preg_match('/^[a-f0-9]{64}$/D', $expectedDigest) !== 1
                || !hash_equals($expectedDigest, $digest)) {
                throw new RuntimeException('EDITION_UPGRADE_BASELINE_DRIFT: ' . $path);
            }
            $files[] = [
                'path' => $path,
                'source' => 'files/' . $path,
                'template_sha256' => $digest,
                'classification' => $file['classification'],
                'transform' => 'tokens',
                'mode' => $file['mode'],
                'policy' => $file['classification'] === 'generated-managed' ? 'generated' : 'managed',
                'owner' => $this->owner($path),
            ];
        }
        usort($files, static fn(array $left, array $right): int => strcmp($left['path'], $right['path']));
        $manifest = [
            'schema_version' => 3,
            'protocol' => 'peanut.scaffold-release.v3',
            'application' => ['version' => (string) $application['application']['version']],
            'edition' => $application['edition'],
            'release' => [
                'version' => $version,
                'source_commit' => $application['template']['source_commit'],
                'source_tree' => $application['template']['source_tree'],
                'inventory_sha256' => $application['template']['inventory_sha256'],
                'inventory_template_version' => $version,
                'managed_tree_sha256' => $application['digests']['managed_tree_sha256'],
                'tokens' => $this->baselineTokens($application, $baselineFiles),
            ],
            'files' => $files,
            'renames' => [],
        ];
        $path = ScaffoldPathGuard::projectPath($root, $baselineRoot . '/edition-scaffold-manifest.json');
        $json = json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . "\n";
        if (is_file($path)) {
            $existingRaw = file_get_contents($path);
            if (is_string($existingRaw) && hash_equals($json, $existingRaw)) {
                return $path;
            }
            try {
                $existing = is_string($existingRaw)
                    ? json_decode($existingRaw, true, 512, JSON_THROW_ON_ERROR)
                    : null;
            } catch (\JsonException) {
                $existing = null;
            }
            $legacyTokens = [
                'product_name' => '__PEANUT_BASELINE_' . 'PRODUCT_NAME__',
                'slug' => '__PEANUT_BASELINE_' . 'SLUG__',
                'package_identity' => '__PEANUT_BASELINE_' . 'PACKAGE_IDENTITY__',
                'application_version' => '__PEANUT_BASELINE_' . 'APPLICATION_VERSION__',
            ];
            if (!is_array($existing) || ($existing['release']['tokens'] ?? null) !== $legacyTokens) {
                throw new RuntimeException('EDITION_UPGRADE_BASELINE_MANIFEST_DRIFT');
            }
            $existing['release']['tokens'] = $manifest['release']['tokens'];
            $migrated = json_encode($existing, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . "\n";
            if (!hash_equals($json, $migrated)) {
                throw new RuntimeException('EDITION_UPGRADE_BASELINE_MANIFEST_DRIFT');
            }
        }
        ScaffoldPathGuard::ensureDirectory(dirname($path));
        $temporary = $path . '.stage-' . bin2hex(random_bytes(6));
        if (file_put_contents($temporary, $json, LOCK_EX) === false || !chmod($temporary, 0600) || !rename($temporary, $path)) {
            @unlink($temporary);
            throw new RuntimeException('EDITION_UPGRADE_BASELINE_MANIFEST_WRITE_FAILED');
        }
        return $path;
    }

    /** @param array<string,mixed> $application @param array<string,string> $baselineFiles @return array<string,string> */
    private function baselineTokens(array $application, array $baselineFiles): array
    {
        $seed = hash('sha256', json_encode([
            'version' => $application['template']['version'],
            'source_commit' => $application['template']['source_commit'],
            'source_tree' => $application['template']['source_tree'],
            'inventory_sha256' => $application['template']['inventory_sha256'],
            'managed_tree_sha256' => $application['digests']['managed_tree_sha256'],
        ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
        for ($nonce = 0; $nonce < 1024; $nonce++) {
            $prefix = '__PEANUT_SYNTHETIC_BASELINE_' . hash('sha256', $seed . "\0" . $nonce) . '_';
            $tokens = [
                'product_name' => $prefix . 'PRODUCT_NAME__',
                'slug' => $prefix . 'SLUG__',
                'package_identity' => $prefix . 'PACKAGE_IDENTITY__',
                'application_version' => $prefix . 'APPLICATION_VERSION__',
            ];
            $collision = false;
            foreach ($baselineFiles as $path => $absolute) {
                $contents = file_get_contents($absolute);
                if (!is_string($contents)) {
                    throw new RuntimeException('EDITION_UPGRADE_BASELINE_DRIFT: ' . $path);
                }
                foreach ($tokens as $token) {
                    if (str_contains($contents, $token)) {
                        $collision = true;
                        break 2;
                    }
                }
            }
            if (!$collision) {
                return $tokens;
            }
        }
        throw new RuntimeException('EDITION_UPGRADE_BASELINE_TOKEN_COLLISION');
    }

    private function owner(string $path): string
    {
        if (str_starts_with($path, 'server/')) {
            return 'backend';
        }
        if (str_starts_with($path, 'web/') || str_starts_with($path, 'platform/')
            || str_starts_with($path, 'pc/') || str_starts_with($path, 'uniapp/')
            || str_starts_with($path, 'docs-site/')) {
            return 'frontend';
        }
        return 'host';
    }
}
