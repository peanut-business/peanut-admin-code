<?php
declare(strict_types=1);

namespace app\common\infrastructure\scaffold;

use app\common\value\scaffold\EditionProfile;
use app\common\value\scaffold\VersionContract;
use app\platform\infrastructure\plugin\PluginArtifactWriter;
use app\platform\infrastructure\plugin\PluginLockResolver;
use RuntimeException;

require_once __DIR__ . '/VersionContract.php';
require_once __DIR__ . '/EditionProfile.php';
require_once __DIR__ . '/EditionProjector.php';
require_once dirname(__DIR__, 3) . '/platform/service/plugin/PluginArtifactToolException.php';
require_once dirname(__DIR__, 3) . '/platform/service/plugin/PluginArtifactWriter.php';
require_once dirname(__DIR__, 3) . '/platform/service/plugin/PluginLifecycleException.php';
require_once dirname(__DIR__, 3) . '/platform/service/plugin/PluginDescriptor.php';
require_once dirname(__DIR__, 3) . '/platform/service/plugin/PluginLockResolver.php';

final class ApplicationCreator
{
    private const CLASSIFICATIONS = ['managed', 'generated-managed', 'app-owned', 'excluded'];
    private const TRANSFORMS = ['copy', 'text', 'brand', 'brand-asset', 'changelog', 'ci', 'docs-page', 'environment-guard', 'release-metadata', 'resources', 'readme', 'license', 'modules-config', 'package', 'plugins-lock', 'sbom', 'third-party-notices', 'version-contract', 'composer-lock'];
    private const VARIABLES = ['APPLICATION_VERSION', 'PACKAGE_IDENTITY', 'PRODUCT_NAME', 'SLUG'];
    private const PROFILES = ['minimal', 'standard', 'full'];
    private const WRITABLE_DIRECTORIES = [
        'server/runtime',
        'server/public/storage',
        'server/private/storage',
    ];

    /** @param array{commit:string,tree:string}|null $sourceIdentity */
    public function __construct(
        private readonly string $sourceRoot,
        private readonly string $inventoryPath,
        private readonly ?array $sourceIdentity = null,
        private readonly ?string $adoptionManifestPath = null,
        private readonly bool $projectEdition = true,
    ) {
    }

    /** @return array<string,mixed> */
    public function create(
        string $productName,
        string $slug,
        string $packageIdentity,
        string $target,
        string $edition,
        ?string $applicationVersion = null,
        string $profile = 'standard'
    ): array
    {
        $journal = $this->sourceRoot . '/.local/module-source-adoption/journal.json';
        if (file_exists($journal) || is_link($journal)) {
            throw new RuntimeException('MODULE_PACKAGE_RECOVERY_REQUIRED');
        }
        $inventory = $this->loadInventory();
        if (!in_array($profile, self::PROFILES, true)) {
            throw new RuntimeException('CREATE_APP_PROFILE_INVALID');
        }
        $editionProfile = EditionProfile::load(
            $this->sourceRoot . '/scaffold/edition-profiles.json',
            $edition,
        );
        $parameters = $this->validateParameters(
            $productName,
            $slug,
            $packageIdentity,
            $applicationVersion ?? (string)$inventory['application']['version']
        );
        $generationIdentity = $this->validateSourceIdentity($this->sourceIdentity ?? $this->gitIdentity());
        $inventoryDigest = hash_file('sha256', $this->inventoryPath);
        if (!is_string($inventoryDigest) || preg_match('/^[a-f0-9]{64}$/D', $inventoryDigest) !== 1) {
            throw new RuntimeException('CREATE_APP_INVENTORY_DIGEST_INVALID');
        }
        $adoption = $this->loadAdoptionManifest($inventory);
        $target = $this->validateTarget($target);
        $parent = dirname($target);
        $stage = $parent . DIRECTORY_SEPARATOR . '.' . basename($target) . '.create-' . bin2hex(random_bytes(6));
        if (!mkdir($stage, 0775, false)) {
            throw new RuntimeException('CREATE_APP_STAGE_FAILED');
        }

        try {
            $files = [];
            foreach ($inventory['files'] as $entry) {
                if ($entry['classification'] === 'excluded' || !in_array($profile, $entry['profiles'], true)) {
                    continue;
                }
                $source = $this->sourcePath((string)$entry['path']);
                $actualSourceDigest = self::sourceDigest($source, (string)$entry['path'], (string)$entry['transform']);
                if (!is_string($actualSourceDigest) || !hash_equals((string)$entry['source_sha256'], $actualSourceDigest)) {
                    throw new RuntimeException('CREATE_APP_SOURCE_DIGEST_MISMATCH: ' . $entry['path']);
                }
                $content = file_get_contents($source);
                if (!is_string($content)) {
                    throw new RuntimeException('CREATE_APP_SOURCE_READ_FAILED: ' . $entry['path']);
                }
                $content = $this->transform($content, $entry, $parameters, $stage);
                $destination = $stage . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, (string)$entry['target']);
                $this->writeFile($destination, $content, (int)$entry['mode']);
                $files[] = [
                    'path' => $entry['target'],
                    'sha256' => hash('sha256', $content),
                    'mode' => $entry['mode'],
                    'classification' => $entry['classification'],
                    'owner' => $entry['owner'],
                    'source' => $entry['path'],
                ];
            }
            $this->prepareWritableDirectories($stage);
            usort($files, static fn(array $a, array $b): int => strcmp((string)$a['path'], (string)$b['path']));
            $this->assertNoUnresolvedVariables($stage);
            if ($adoption !== null) {
                $this->assertAdoptionEquivalent($stage, $adoption, $parameters, $files);
            }
            $files = $this->rebuildBundledPluginArtifacts($stage, $files);
            if ($this->projectEdition) {
                $files = $this->projectEdition($stage, $inventory, $files, $editionProfile);
            }
            $templateIdentity = $adoption === null
                ? [
                    'version' => $inventory['template_version'],
                    'inventory_sha256' => $inventoryDigest,
                    'source_commit' => $generationIdentity['commit'],
                    'source_tree' => $generationIdentity['tree'],
                ]
                : [
                    'version' => $adoption->version(),
                    'inventory_sha256' => $adoption->release()['inventory_sha256'],
                    'source_commit' => $adoption->release()['source_commit'],
                    'source_tree' => $adoption->release()['source_tree'],
                ];
            $manifest = $this->writeApplicationManifest(
                $stage,
                $generationIdentity,
                $inventoryDigest,
                $templateIdentity,
                $parameters,
                $files,
                $profile,
                $editionProfile,
            );

            if (is_dir($target) && !rmdir($target)) {
                throw new RuntimeException('CREATE_APP_TARGET_NOT_EMPTY');
            }
            if (!rename($stage, $target)) {
                throw new RuntimeException('CREATE_APP_TARGET_COMMIT_FAILED');
            }
            return $manifest;
        } catch (\Throwable $exception) {
            $this->deleteTree($stage);
            throw $exception;
        }
    }

    /**
     * @param array<string,mixed> $inventory
     * @param list<array<string,mixed>> $files
     * @return list<array<string,mixed>>
     */
    private function projectEdition(
        string $stage,
        array $inventory,
        array $files,
        EditionProfile $profile,
    ): array {
        $entries = [];
        foreach ($inventory['files'] as $entry) {
            if (is_array($entry) && isset($entry['target'])) {
                $entries[(string)$entry['target']] = $entry;
            }
        }
        $fileIndexes = [];
        foreach ($files as $index => $file) {
            $fileIndexes[(string)$file['path']] = $index;
        }

        $projector = new EditionProjector();
        foreach ($projector->paths($profile) as $path) {
            if (!isset($entries[$path], $fileIndexes[$path])) {
                throw new RuntimeException('CREATE_APP_EDITION_PROJECTED_FILE_MISSING: ' . $path);
            }
            $absolute = $stage . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $path);
            $content = file_get_contents($absolute);
            if (!is_string($content)) {
                throw new RuntimeException('CREATE_APP_EDITION_PROJECTED_FILE_UNREADABLE: ' . $path);
            }
            $files[$fileIndexes[$path]] = $projector->project(
                $stage,
                $entries[$path],
                $content,
                $profile,
            );
        }
        usort($files, static fn(array $a, array $b): int => strcmp((string)$a['path'], (string)$b['path']));
        return $files;
    }

    /** @param array{commit:string,tree:string} $identity @return array{commit:string,tree:string} */
    private function validateSourceIdentity(array $identity): array
    {
        if (array_keys($identity) !== ['commit', 'tree']
            || preg_match('/^[a-f0-9]{40}$/D', (string)$identity['commit']) !== 1
            || preg_match('/^[a-f0-9]{40}$/D', (string)$identity['tree']) !== 1) {
            throw new RuntimeException('CREATE_APP_SOURCE_IDENTITY_INVALID');
        }
        return $identity;
    }

    /** @param array<string,mixed> $inventory */
    private function loadAdoptionManifest(array $inventory): ?ScaffoldManifest
    {
        if ($this->adoptionManifestPath === null) {
            return null;
        }
        try {
            $manifest = ScaffoldManifest::load($this->adoptionManifestPath);
        } catch (\Throwable $exception) {
            throw new RuntimeException('CREATE_APP_ADOPTION_MANIFEST_INVALID: ' . $exception->getMessage(), 0, $exception);
        }
        if ($manifest->version() !== $inventory['template_version']
            || ($manifest->release()['inventory_template_version'] ?? null) !== $inventory['template_version']) {
            throw new RuntimeException('CREATE_APP_ADOPTION_VERSION_MISMATCH');
        }
        if ($manifest->supportsApplicationVersion()
            && $manifest->defaultApplicationVersion() !== $inventory['application']['version']) {
            throw new RuntimeException('CREATE_APP_ADOPTION_APPLICATION_VERSION_MISMATCH');
        }
        return $manifest;
    }

    /**
     * @param array<string,string> $parameters
     * @param list<array<string,mixed>> $files
     */
    private function assertAdoptionEquivalent(
        string $stage,
        ScaffoldManifest $adoption,
        array $parameters,
        array $files
    ): void {
        $release = $adoption->release();
        $tokens = $release['tokens'] ?? null;
        $expectedTokenKeys = $adoption->supportsApplicationVersion()
            ? ['product_name', 'slug', 'package_identity', 'application_version']
            : ['product_name', 'slug', 'package_identity'];
        if (!is_array($tokens) || array_keys($tokens) !== $expectedTokenKeys) {
            throw new RuntimeException('CREATE_APP_ADOPTION_TOKENS_INVALID');
        }
        foreach ($tokens as $token) {
            if (!is_string($token) || $token === '') {
                throw new RuntimeException('CREATE_APP_ADOPTION_TOKENS_INVALID');
            }
        }
        if (count(array_unique(array_values($tokens), SORT_STRING)) !== count($tokens)) {
            throw new RuntimeException('CREATE_APP_ADOPTION_TOKENS_INVALID');
        }
        $renderParameters = [
            'product_name' => $parameters['PRODUCT_NAME'],
            'slug' => $parameters['SLUG'],
            'package_identity' => $parameters['PACKAGE_IDENTITY'],
            'application_version' => $parameters['APPLICATION_VERSION'],
        ];
        if (!$adoption->supportsApplicationVersion()) {
            unset($renderParameters['application_version']);
        }
        $current = [];
        foreach ($files as $file) {
            if (in_array($file['classification'], ['managed', 'generated-managed'], true)) {
                $current[(string)$file['path']] = $file;
            }
        }
        ksort($current, SORT_STRING);
        $released = $adoption->files();
        if (count($current) !== count($released)) {
            throw new RuntimeException('CREATE_APP_ADOPTION_MANAGED_SET_MISMATCH');
        }
        $releaseTree = [];
        foreach ($current as $path => $generated) {
            $artifact = $released[$path] ?? null;
            if (!is_array($artifact)) {
                throw new RuntimeException('CREATE_APP_ADOPTION_MANAGED_SET_MISMATCH: ' . $path);
            }
            if (($artifact['mode'] ?? null) !== ($generated['mode'] ?? null)
                || ($artifact['classification'] ?? null) !== ($generated['classification'] ?? null)) {
                throw new RuntimeException('CREATE_APP_ADOPTION_FILE_METADATA_MISMATCH: ' . $path);
            }
            $generatedPath = ScaffoldPathGuard::existingFileWithin(
                $stage,
                $stage . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $path),
                'CREATE_APP_ADOPTION_GENERATED_PATH_INVALID'
            );
            $generatedContent = file_get_contents($generatedPath);
            $generatedDigest = is_string($generatedContent) ? hash('sha256', $generatedContent) : null;
            if (!is_string($generatedContent) || !is_string($generatedDigest)
                || !hash_equals((string)$generated['sha256'], $generatedDigest)
                || ((fileperms($generatedPath) & 0777) !== $generated['mode'])) {
                throw new RuntimeException('CREATE_APP_ADOPTION_GENERATED_FILE_MISMATCH: ' . $path);
            }
            try {
                $artifactPath = $adoption->artifactPath($artifact);
            } catch (\Throwable $exception) {
                throw new RuntimeException('CREATE_APP_ADOPTION_ARTIFACT_INVALID: ' . $path, 0, $exception);
            }
            $artifactContent = file_get_contents($artifactPath);
            if (!is_string($artifactContent)
                || !hash_equals((string)$artifact['template_sha256'], hash('sha256', $artifactContent))) {
                throw new RuntimeException('CREATE_APP_ADOPTION_ARTIFACT_DIGEST_MISMATCH: ' . $path);
            }
            $rendered = $this->renderAdoptionArtifact($adoption, $artifact, $tokens, $renderParameters);
            if (!$this->isDerivedPluginArtifact($path)
                && !hash_equals($generatedDigest, hash('sha256', $rendered))) {
                throw new RuntimeException('CREATE_APP_ADOPTION_RENDER_MISMATCH: ' . $path);
            }
            $releaseTree[] = [
                'path' => $path,
                'sha256' => hash('sha256', $this->renderAdoptionArtifact($adoption, $artifact, $tokens, $tokens)),
            ];
        }
        $releaseTreeDigest = $this->treeDigest($releaseTree);
        $recordedTreeDigest = $release['managed_tree_sha256'] ?? null;
        if (!is_string($recordedTreeDigest) || preg_match('/^[a-f0-9]{64}$/D', $recordedTreeDigest) !== 1
            || !hash_equals($recordedTreeDigest, $releaseTreeDigest)) {
            throw new RuntimeException('CREATE_APP_ADOPTION_MANAGED_TREE_MISMATCH');
        }
    }

    private function isDerivedPluginArtifact(string $path): bool
    {
        return $path === 'plugins.lock'
            || preg_match('#^plugins/official\.[a-z0-9.-]+/plugin\.json$#D', $path) === 1;
    }

    /** @param array<string,string> $tokens @param array<string,string> $values */
    private function replaceReleaseTokens(string $content, array $tokens, array $values): string
    {
        foreach (array_keys($tokens) as $key) {
            $content = str_replace($tokens[$key], $values[$key], $content);
        }
        return $content;
    }

    /** @param array<string,mixed> $artifact @param array<string,string> $tokens @param array<string,string> $values */
    private function renderAdoptionArtifact(ScaffoldManifest $adoption, array $artifact, array $tokens, array $values): string
    {
        $content = file_get_contents($adoption->artifactPath($artifact));
        if (!is_string($content)) {
            throw new RuntimeException('CREATE_APP_ADOPTION_ARTIFACT_INVALID: ' . ($artifact['path'] ?? ''));
        }
        $rendered = $this->replaceReleaseTokens($content, $tokens, $values);
        if (($artifact['transform'] ?? null) !== 'composer-lock') {
            return $rendered;
        }
        $composer = $adoption->files()['server/composer.json'] ?? null;
        if (!is_array($composer)) {
            throw new RuntimeException('CREATE_APP_ADOPTION_COMPOSER_COMPANION_MISSING');
        }
        $composerContent = file_get_contents($adoption->artifactPath($composer));
        if (!is_string($composerContent)
            || !hash_equals((string)($composer['template_sha256'] ?? ''), hash('sha256', $composerContent))) {
            throw new RuntimeException('CREATE_APP_ADOPTION_COMPOSER_COMPANION_INVALID');
        }
        return ScaffoldManifest::renderComposerLock(
            $rendered,
            $this->replaceReleaseTokens($composerContent, $tokens, $values),
        );
    }

    /** @return array<string,string> */
    private function validateParameters(
        string $productName,
        string $slug,
        string $packageIdentity,
        string $applicationVersion
    ): array
    {
        $productName = trim($productName);
        if ($productName === '' || strlen($productName) > 80 || preg_match('/[\x00-\x1F\x7F{}]/', $productName) === 1) {
            throw new RuntimeException('CREATE_APP_PRODUCT_NAME_INVALID');
        }
        if (preg_match('/^[a-z][a-z0-9]*(?:-[a-z0-9]+)*$/D', $slug) !== 1 || strlen($slug) > 63) {
            throw new RuntimeException('CREATE_APP_SLUG_INVALID');
        }
        if (preg_match('/^[a-z0-9](?:[a-z0-9._-]*[a-z0-9])?\/[a-z0-9](?:[a-z0-9._-]*[a-z0-9])?$/D', $packageIdentity) !== 1
            || strlen($packageIdentity) > 120) {
            throw new RuntimeException('CREATE_APP_PACKAGE_IDENTITY_INVALID');
        }
        $this->versionContract()->assertValid($applicationVersion, 'CREATE_APP_APPLICATION_VERSION_INVALID');
        return [
            'APPLICATION_VERSION' => $applicationVersion,
            'PRODUCT_NAME' => $productName,
            'SLUG' => $slug,
            'PACKAGE_IDENTITY' => $packageIdentity,
        ];
    }

    /** @return array<string,mixed> */
    private function loadInventory(): array
    {
        $raw = file_get_contents($this->inventoryPath);
        try {
            $inventory = is_string($raw) ? json_decode($raw, true, 512, JSON_THROW_ON_ERROR) : null;
        } catch (\JsonException $exception) {
            throw new RuntimeException('CREATE_APP_INVENTORY_INVALID_JSON', 0, $exception);
        }
        if (!is_array($inventory) || ($inventory['schema_version'] ?? null) !== 2
            || ($inventory['protocol'] ?? null) !== 'peanut.create-app-inventory.v2'
            || !is_string($inventory['template_version'] ?? null)
            || !is_array($inventory['application'] ?? null)
            || array_keys($inventory['application']) !== ['version']
            || !is_array($inventory['variables'] ?? null)
            || !is_array($inventory['files'] ?? null)) {
            throw new RuntimeException('CREATE_APP_INVENTORY_SCHEMA_INVALID');
        }
        $versions = $this->versionContract();
        $versions->assertSame((string)$inventory['template_version'], $versions->scaffoldTemplate(), 'CREATE_APP_INVENTORY_TEMPLATE_VERSION_MISMATCH');
        $versions->assertSame((string)$inventory['application']['version'], $versions->generatedInstanceDefault(), 'CREATE_APP_INVENTORY_APPLICATION_VERSION_MISMATCH');
        $variables = $inventory['variables'];
        sort($variables, SORT_STRING);
        if ($variables !== self::VARIABLES) {
            throw new RuntimeException('CREATE_APP_INVENTORY_UNKNOWN_VARIABLE');
        }
        $seenSource = [];
        $seenTarget = [];
        foreach ($inventory['files'] as $index => &$entry) {
            if (!is_array($entry)) {
                throw new RuntimeException('CREATE_APP_INVENTORY_ENTRY_INVALID: ' . $index);
            }
            $path = ScaffoldManifest::path((string)($entry['path'] ?? ''));
            $target = ScaffoldManifest::path((string)($entry['target'] ?? $path));
            $classification = (string)($entry['classification'] ?? '');
            $transform = (string)($entry['transform'] ?? 'copy');
            if (isset($seenSource[$path]) || isset($seenTarget[$target])) {
                throw new RuntimeException('CREATE_APP_INVENTORY_DUPLICATE_PATH: ' . $path);
            }
            if (!in_array($classification, self::CLASSIFICATIONS, true)
                || !in_array($transform, self::TRANSFORMS, true)
                || ($transform === 'composer-lock' && $path !== 'server/composer.lock')
                || !is_string($entry['owner'] ?? null)
                || !is_array($entry['profiles'] ?? null)
                || array_values(array_unique($entry['profiles'])) !== $entry['profiles']
                || array_diff($entry['profiles'], self::PROFILES) !== []
                || $entry['profiles'] === []
                || !in_array($entry['mode'] ?? null, [0644, 0755], true)) {
                throw new RuntimeException('CREATE_APP_INVENTORY_ENTRY_INVALID: ' . $path);
            }
            if ($classification !== 'excluded'
                && (!is_string($entry['source_sha256'] ?? null)
                    || preg_match('/^[a-f0-9]{64}$/D', $entry['source_sha256']) !== 1)) {
                throw new RuntimeException('CREATE_APP_INVENTORY_DIGEST_INVALID: ' . $path);
            }
            $seenSource[$path] = true;
            $seenTarget[$target] = true;
            $entry['path'] = $path;
            $entry['target'] = $target;
        }
        unset($entry);
        return $inventory;
    }

    /** @return array{commit:string,tree:string} */
    private function gitIdentity(): array
    {
        $status = $this->git(['status', '--porcelain=v1', '--untracked-files=all']);
        if ($status !== '') {
            throw new RuntimeException('CREATE_APP_SOURCE_NOT_CLEAN');
        }
        $commit = $this->git(['rev-parse', 'HEAD']);
        $tree = $this->git(['rev-parse', 'HEAD^{tree}']);
        if (preg_match('/^[a-f0-9]{40}$/D', $commit) !== 1 || preg_match('/^[a-f0-9]{40}$/D', $tree) !== 1) {
            throw new RuntimeException('CREATE_APP_SOURCE_IDENTITY_INVALID');
        }
        return ['commit' => $commit, 'tree' => $tree];
    }

    /** @param list<string> $arguments */
    private function git(array $arguments): string
    {
        $command = ['git', '-C', $this->sourceRoot, ...$arguments];
        $pipes = [];
        $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        if (!is_resource($process)) {
            throw new RuntimeException('CREATE_APP_GIT_UNAVAILABLE');
        }
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        if (proc_close($process) !== 0 || !is_string($stdout)) {
            throw new RuntimeException('CREATE_APP_GIT_FAILED: ' . trim((string)$stderr));
        }
        return trim($stdout);
    }

    private function validateTarget(string $target): string
    {
        if ($target === '' || str_contains($target, "\0") || !str_starts_with($target, DIRECTORY_SEPARATOR)
            || in_array('..', explode(DIRECTORY_SEPARATOR, $target), true)) {
            throw new RuntimeException('CREATE_APP_TARGET_PATH_INVALID');
        }
        $cursor = DIRECTORY_SEPARATOR;
        foreach (array_filter(explode(DIRECTORY_SEPARATOR, $target), 'strlen') as $segment) {
            $cursor = rtrim($cursor, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $segment;
            if (is_link($cursor)) {
                throw new RuntimeException('CREATE_APP_TARGET_SYMLINK_REJECTED');
            }
        }
        $parent = realpath(dirname($target));
        if ($parent === false || !is_dir($parent)) {
            throw new RuntimeException('CREATE_APP_TARGET_PARENT_INVALID');
        }
        $resolved = $parent . DIRECTORY_SEPARATOR . basename($target);
        if (is_file($resolved) || (is_dir($resolved) && (scandir($resolved) ?: []) !== ['.', '..'])) {
            throw new RuntimeException('CREATE_APP_TARGET_NOT_EMPTY');
        }
        $source = realpath($this->sourceRoot);
        if ($source === false || $resolved === $source || str_starts_with($resolved . DIRECTORY_SEPARATOR, $source . DIRECTORY_SEPARATOR)) {
            throw new RuntimeException('CREATE_APP_TARGET_INSIDE_SOURCE');
        }
        return $resolved;
    }

    private function sourcePath(string $relative): string
    {
        $path = $this->sourceRoot . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative);
        return ScaffoldPathGuard::existingFileWithin($this->sourceRoot, $path, 'CREATE_APP_SOURCE_PATH_INVALID');
    }

    private static function sourceDigest(string $source, string $path, string $transform): string
    {
        // Generated metadata is rebuilt from parameters, so source prose changes
        // must not invalidate the immutable application template identity.
        if (in_array($transform, ['changelog', 'release-metadata', 'resources', 'readme', 'docs-page', 'version-contract'], true)) {
            return hash('sha256', "peanut.create-app-semantic-source.v1\0{$path}\0{$transform}");
        }
        $digest = hash_file('sha256', $source);
        if (!is_string($digest)) {
            throw new RuntimeException('CREATE_APP_SOURCE_DIGEST_INVALID');
        }
        return $digest;
    }

    /** @param array<string,mixed> $entry @param array<string,string> $parameters */
    private function transform(string $content, array $entry, array $parameters, string $stage): string
    {
        return match ($entry['transform']) {
            'copy' => $content,
            'text' => $this->textTransform($content, $parameters, (string)$entry['path']),
            'brand' => $this->brandManifest($parameters),
            'brand-asset' => $this->brandAsset((string)$entry['path'], $parameters),
            'changelog' => $this->render($this->changelog($parameters['APPLICATION_VERSION']), $parameters),
            'ci' => $this->ciTransform($content),
            'docs-page' => $this->render($this->docsPage((string)$entry['path']), $parameters),
            'environment-guard' => $this->environmentGuard($content),
            'release-metadata' => $this->releaseMetadata($parameters),
            'resources' => $this->resourceRegistry($parameters),
            'readme' => $this->render($this->readme(), $parameters),
            'license' => $this->render($this->license(), $parameters),
            'modules-config' => $this->modulesConfig($content),
            'package' => $this->packageTransform($content, $parameters, (string)$entry['path']),
            'plugins-lock' => $this->pluginsLock($content),
            'sbom' => $this->sbom($content, $parameters),
            'third-party-notices' => $this->thirdPartyNotices($content, $parameters),
            'version-contract' => $this->versionContractDocument($parameters),
            'composer-lock' => $this->composerLock($content, $stage),
            default => throw new RuntimeException('CREATE_APP_INVENTORY_TRANSFORM_UNKNOWN'),
        };
    }

    private function composerLock(string $content, string $stage): string
    {
        $composerPath = $stage . '/server/composer.json';
        if (!is_file($composerPath) || is_link($composerPath)) {
            throw new RuntimeException('CREATE_APP_COMPOSER_COMPANION_MISSING');
        }
        $composer = file_get_contents($composerPath);
        if (!is_string($composer)) {
            throw new RuntimeException('CREATE_APP_COMPOSER_COMPANION_MISSING');
        }
        return ScaffoldManifest::renderComposerLock($content, $composer);
    }

    /** @param array<string,string> $parameters */
    private function textTransform(string $content, array $parameters, string $path): string
    {
        $content = str_replace(
            ['Peanut Admin', 'peanut-business/peanut-admin-code', 'https://peanut-admin.007345.xyz', 'https://peanut-admin-doc.007345.xyz', '花生科技'],
            [$parameters['PRODUCT_NAME'], $parameters['PACKAGE_IDENTITY'], 'https://example.invalid', 'https://docs.example.invalid', 'application owner'],
            $content
        );
        if ($path === 'server/database/init.sql') {
            $content = str_replace(
                ["-- 超级管理员（密码：admin123456）", "MD5(CONCAT(MD5('admin123456'),'abcd1234'))", '系统预置角色（仅菜单管理权限，演示用）'],
                ['-- 超级管理员（密码必须由安装器注入）', "MD5(CONCAT(MD5('__INSTALLER_MUST_REPLACE__'),'abcd1234'))", '系统预置最小权限角色'],
                $content
            );
        }
        if ($path === 'server/database/install.php') {
            $content = str_replace("MD5(CONCAT(MD5('admin123456'),'abcd1234'))", "MD5(CONCAT(MD5('__INSTALLER_MUST_REPLACE__'),'abcd1234'))", $content);
        }
        if ($path === 'server/tests/Productization/InstallerBootstrapTest.php') {
            $content = str_replace(
                ["MD5(CONCAT(MD5('admin123456')", '密码：admin123456', 'known password expression must not reach the database', 'installer must only replace the executable seed'],
                ["MD5(CONCAT(MD5('__INSTALLER_MUST_REPLACE__')", '密码必须由安装器注入', 'placeholder password expression must not reach the database', 'installer must preserve the neutral seed comment'],
                $content
            );
        }
        if ($path === 'scripts/check-local-runtime-contract') {
            $contractIdentity = $parameters['SLUG'] . '-contract';
            $content = str_replace(
                [
                    'peanut-admin-php:contract',
                    'peanut-admin-nginx:contract',
                    'peanut-admin-mysql84-development-host-direct',
                    'peanut-admin-mysql84-development',
                    'DB_HOST=192.168.192.2',
                    'DB_PORT=20183',
                    'DB_NAME=peanut_admin_development',
                    'DB_USER=peanut_admin_development',
                ],
                [
                    $parameters['SLUG'] . '-php:contract',
                    $parameters['SLUG'] . '-nginx:contract',
                    $parameters['SLUG'] . '-mysql84-contract-host',
                    $parameters['SLUG'] . '-mysql84-contract',
                    'DB_HOST=127.0.0.1',
                    'DB_PORT=3306',
                    'DB_NAME=' . $contractIdentity,
                    'DB_USER=' . $contractIdentity,
                ],
                $content
            );
        }
        if ($path === 'server/app/adminapi/application/WorkbenchApplicationService.php') {
            $content = preg_replace("/'(today_sales|total_sales|today_visitor|total_visitor|today_new_user|total_new_user|order_num|order_sum)' => [0-9]+,/", "'$1' => 0,", $content) ?? $content;
        }
        if ($path === 'uniapp/src/pages/as_us/as_us.vue') {
            $content = $this->replaceVersionLiteral($content, $parameters['APPLICATION_VERSION'], $path);
        }
        if ($path === 'uniapp/src/manifest.json') {
            $content = $this->uniappManifest($content, $parameters['APPLICATION_VERSION']);
        }
        return $content;
    }

    /** @param array<string,string> $parameters */
    private function packageTransform(string $content, array $parameters, string $path): string
    {
        $content = str_replace(
            ['peanut-business/peanut-admin-code', 'peanut-admin-web', 'peanut-admin-pc', 'peanut-admin-uniapp', 'peanut-admin-docs'],
            [$parameters['PACKAGE_IDENTITY'], $parameters['SLUG'] . '-web', $parameters['SLUG'] . '-pc', $parameters['SLUG'] . '-uniapp', $parameters['SLUG'] . '-docs'],
            $this->textTransform($content, $parameters, 'package')
        );
        if (!str_ends_with($path, '.json')) {
            return $content;
        }
        try {
            $document = json_decode($content, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            throw new RuntimeException('CREATE_APP_PACKAGE_JSON_INVALID: ' . $path, 0, $exception);
        }
        if (!is_array($document)) {
            throw new RuntimeException('CREATE_APP_PACKAGE_JSON_INVALID: ' . $path);
        }
        if (str_ends_with($path, '/package.json') && array_key_exists('version', $document)) {
            $document['version'] = $parameters['APPLICATION_VERSION'];
        }
        if (in_array($path, ['pc/package-lock.json', 'uniapp/package-lock.json'], true)) {
            if (!array_key_exists('version', $document)
                || !is_array($document['packages'][''] ?? null)
                || !array_key_exists('version', $document['packages'][''])) {
                throw new RuntimeException('CREATE_APP_PACKAGE_LOCK_ROOT_INVALID: ' . $path);
            }
            $document['version'] = $parameters['APPLICATION_VERSION'];
            $document['packages']['']['version'] = $parameters['APPLICATION_VERSION'];
        }
        return json_encode(
            $document,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
        ) . "\n";
    }

    private function replaceVersionLiteral(string $content, string $version, string $path): string
    {
        $patterns = match ($path) {
            'uniapp/src/pages/as_us/as_us.vue' => ["/appStore\.config\?\.version \|\| '[^']+'/"],
            default => [],
        };
        foreach ($patterns as $pattern) {
            if (preg_match_all($pattern, $content) !== 1) {
                throw new RuntimeException('CREATE_APP_VERSION_SURFACE_INVALID: ' . $path);
            }
            $content = preg_replace_callback(
                $pattern,
                static fn(array $matches): string => preg_replace("/'[^']+'(?=\))|'[^']+'$/", "'{$version}'", $matches[0], 1) ?? $matches[0],
                $content,
                1
            ) ?? $content;
        }
        return $content;
    }

    private function uniappManifest(string $content, string $version): string
    {
        foreach ([
            '/("versionName"\s*:\s*)"[^"]+"/' => '$1"' . $version . '"',
            '/("versionCode"\s*:\s*)"[^"]+"/' => '$1"10"',
        ] as $pattern => $replacement) {
            if (preg_match_all($pattern, $content) !== 1) {
                throw new RuntimeException('CREATE_APP_UNIAPP_VERSION_SURFACE_INVALID');
            }
            $content = preg_replace($pattern, $replacement, $content, 1) ?? $content;
        }
        return $content;
    }

    /** @param array<string,string> $parameters */
    private function brandAsset(string $path, array $parameters): string
    {
        if (str_ends_with($path, '/favicon.svg')) {
            return '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 32 32" role="img">'
                . '<title>' . htmlspecialchars($parameters['PRODUCT_NAME'], ENT_XML1) . '</title>'
                . '<rect width="32" height="32" rx="8" fill="#165DFF"/>'
                . '<path d="M8 16 16 8l8 8-8 8Z" fill="#fff"/>'
                . '<circle cx="23.5" cy="23.5" r="3" fill="#34D399"/></svg>' . "\n";
        }
        if (str_ends_with($path, '/logo.svg')) {
            return '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 64 64" role="img">'
                . '<title>' . htmlspecialchars($parameters['PRODUCT_NAME'], ENT_XML1) . '</title>'
                . '<rect width="64" height="64" rx="16" fill="#165DFF"/>'
                . '<path d="M16 32 32 16l16 16-16 16Z" fill="#fff"/>'
                . '<circle cx="47.5" cy="47.5" r="5.5" fill="#34D399"/></svg>' . "\n";
        }
        return $this->textTransform((string)file_get_contents($this->sourcePath($path)), $parameters, $path);
    }

    private function ciTransform(string $content): string
    {
        $content = preg_replace('/  stale-facts:\n.*?(?=  changes:\n)/s', '', $content) ?? $content;
        $content = preg_replace('/^      create_app:.*\n/m', '', $content) ?? $content;
        $content = preg_replace('/^      scaffold_upgrade:.*\n/m', '', $content) ?? $content;
        $content = str_replace('server web pc uniapp docs_site create_app scaffold_upgrade', 'server web pc uniapp docs_site', $content);
        $content = preg_replace('/^          matches .*create_app=true.*\n/m', '', $content) ?? $content;
        $content = preg_replace('/\n  create-app:\n.*?(?=  php:\n)/s', "\n", $content) ?? $content;
        $content = preg_replace('/\n  scaffold-upgrade:\n.*?(?=  php:\n)/s', "\n", $content) ?? $content;
        return $content;
    }

    private function environmentGuard(string $content): string
    {
        $content = preg_replace('/const PEANUT_DATABASE_RESOURCES = \[.*?\n\];\n/s', '', $content, 1) ?? $content;
        $replacement = <<<'PHP'
function guardedDatabaseConfig(): array
{
    $environment = requiredEnvironment('APP_ENV');
    $deploymentTarget = requiredEnvironment('PEANUT_DEPLOYMENT_TARGET');
    $resourceId = requiredEnvironment('PEANUT_DATABASE_RESOURCE_ID');
    $endpointId = requiredEnvironment('PEANUT_DATABASE_ENDPOINT_ID');
    $consumer = requiredEnvironment('PEANUT_DATABASE_CONSUMER');
    $registryPath = dirname(__DIR__, 2) . '/resources/project-resources.json';
    $raw = file_get_contents($registryPath);
    $registry = is_string($raw) ? json_decode($raw, true) : null;
    $databases = is_array($registry) ? ($registry['resources']['databases'] ?? null) : null;
    if (!is_array($databases)) {
        throw new RuntimeException('项目数据库资源登记无效');
    }
    $registered = null;
    foreach ($databases as $database) {
        if (is_array($database) && ($database['stable_resource_id'] ?? null) === $resourceId) {
            $registered = $database;
            break;
        }
    }
    if (!is_array($registered)) {
        throw new RuntimeException("数据库资源 {$resourceId} 未登记");
    }
    $environments = $registered['environments'] ?? [$registered['environment'] ?? null];
    if (!is_array($environments) || (!in_array($environment, $environments, true) && !in_array($deploymentTarget, $environments, true))) {
        throw new RuntimeException("数据库资源 {$resourceId} 未登记为 {$environment}/{$deploymentTarget}");
    }
    $actual = [
        'host' => requiredEnvironment('DB_HOST'),
        'port' => requiredEnvironment('DB_PORT'),
        'database' => requiredEnvironment('DB_NAME'),
    ];
    $endpoint = registeredDatabaseEndpoint($registered, $consumer);
    if (!hash_equals((string)$endpoint['endpoint_id'], $endpointId)
        || !hash_equals((string)$endpoint['host'], $actual['host'])
        || !hash_equals((string)$endpoint['port'], $actual['port'])
        || !hash_equals((string)($registered['database'] ?? ''), $actual['database'])) {
        throw new RuntimeException("数据库资源 {$resourceId} 的地址或 database 不匹配登记值");
    }
    if (!in_array(requiredEnvironment('DEPLOYMENT_MODE'), ['standalone', 'multi-tenant'], true)) {
        throw new RuntimeException('DEPLOYMENT_MODE 只允许 standalone 或 multi-tenant');
    }
    return [
        'environment' => $environment,
        'deployment_target' => $deploymentTarget,
        'resource_id' => $resourceId,
        'endpoint_id' => $endpointId,
        'consumer' => $consumer,
        ...$actual,
        'user' => requiredEnvironment('DB_USER'),
        'password' => requiredEnvironment('DB_PASS'),
    ];
}
PHP;
        $content = preg_replace('/function guardedDatabaseConfig\([^)]*\): array\s*\{.*?\n\}\n\nfunction guardedConnection/s', $replacement . "\n\nfunction guardedConnection", $content, 1) ?? $content;
        return $content;
    }

    /** Render the bounded application-owned documentation pages selected by the scaffold inventory. */
    private function docsPage(string $path): string
    {
        return match ($path) {
            'docs-site/index.md' => "---\nlayout: home\nhero:\n  name: \"{{PRODUCT_NAME}}\"\n  text: \"Application documentation\"\n---\n\nThis site belongs to the generated application.\n",
            'docs-site/getting-started.md' => "# Getting started\n\nCopy root `.env.example` to `.env` for Docker ports, images and build proxies only. Copy `server/.env.example` to permission-0600 `server/.env`; it is the single source for PHP, database and Tenant/Platform runtime settings. `PHP_*` backend aliases are forbidden. Register this application's own database, ports, domains and external services in `resources/project-resources.json` before connecting anything.\n\nInstall the adopted scaffold major only into a confirmed empty database. Write `ADMIN_INITIAL_EMAIL` and a strong `ADMIN_INITIAL_PASSWORD` to a separate permission-0600 one-time file, select it with `PEANUT_INSTALLATION_ENV_FILE` only for `php server/database/install.php`, then delete it. Run `php server/database/environment-guard.php --current` afterward. Later scaffold patch/minor releases use the standard update path and apply append-only files in `server/database/migrations/`; a different scaffold major requires a fresh rebuild. The application's own product version is independent and does not trigger this scaffold-major rule.\n",
            'docs/peanut-admin-release-deployment.md' => "# Deployment\n\nOne deployment is one application instance with its own database, secrets, file storage and lifecycle. Root `.env` is Docker orchestration only; `server/.env` is the only backend configuration source. Invoke Compose with `--env-file .env --env-file server/.env` after registering this application's resources; never inherit the scaffold source environment.\n\nA fresh install creates the Core/Application baseline and then applies the current scaffold's append-only Peanut migrations plus unmarked application-owned migrations before reporting success. Normal updates preserve data, install locked dependencies, and run `php server/database/install.php --migrate --target-version=<scaffold-template>`, where the target is the adopted `release-versions.json.scaffold_template`; the application's own release tag controls deployment order and does not filter Peanut SQL. A scaffold major change must use the explicit, backed-up `--fresh` path. Plugin Module migrations keep their independent lifecycle. Multi-tenant deployments require a separate PlatformOperator identity and the `/platform/` bundle.\n",
            'docs-site/api.md' => "# API and extensions\n\nApplication HTTP adapters and product modules are app-owned. Use `server/config/peanut.php` and `web/src/peanut.overrides.ts` as the stable Core Host extension entries.\n",
            'docs-site/guide/application-module-lifecycle.md' => "# Create an application and deliver a Module\n\nThis guide is for an application owner and Module author. Start from one immutable scaffold release, keep the generated application in its own repository, and use the [reference index](/reference) and [support guide](/support) when a command or failure needs clarification.\n\n## 1. Create an application\n\nRun `create-app` from the selected release and use a new absolute target path:\n\n~~~bash\nphp scripts/create-app --name=<name> --slug=<slug> --package=<vendor/name> --target=<absolute-path> --profile=standard\n~~~\n\nRecord the template version, application version, source commit/tree, and managed/app-owned summaries from the generated manifest. Register the new application's database, ports, domains, and external services separately; do not inherit the source repository environment.\n\n## 2. Create and check a Module\n\nIn the generated application's `server/` directory, run `module:create` and then the read-only `module:check`:\n\n~~~bash\nphp think module:create <module.key> --vendor=<Vendor>\nphp think module:check <module.key>\n~~~\n\nComplete the backend, frontend, manifest, permissions, menus, migrations, and Tenant isolation skeleton. Repeat `module:check` until `status=ready` and every check passes.\n\n## 3. Pack the Module\n\n~~~bash\nphp think module:pack <module.key> --output=<absolute-path>/<module>-<version>.tar --signing-key-id=<key-id> --signing-secret-key-file=<secure-file>\n~~~\n\nDistribute the archive SHA-256, signature key ID, and public-key configuration through a trusted channel. Never commit the signing secret.\n\n## 4. Install the Package\n\nDevelopment or debug Standalone tooling can validate and install a trusted archive:\n\n~~~bash\nphp think module:install-package <archive> --sha256=<64-hex> --signature-key-id=<key-id>\n~~~\n\nPackage installation changes Package and ModuleInstallation state only. It does not enable a TenantModule or grant Tenant/RBAC permissions. The production worker accepts only deployment-owned opaque tasks for `update`, `retire`, and `Purge`; it has no production entry for initial `install`, `disable`, or `reactivate`. Production HTTP must not receive an archive path, URL, command, credential, or arbitrary target.\n\n## 5. Enable the Tenant and grant RBAC\n\nA PlatformOperator explicitly enables the Module for the target Tenant. A Tenant administrator then grants the Module permission and data scope to the appropriate role and member through Tenant/RBAC governance. Verify at least one enabled and one denied Tenant, plus a member without permission. Package installation must never grant Tenant access automatically.\n\n## 6. Update the Package\n\nFix the new immutable archive identity first, then run a zero-write dry-run:\n\n~~~bash\nphp think module:update-package <archive> --sha256=<64-hex> --signature-key-id=<key-id> --dry-run\nphp think module:update-package <archive> --sha256=<64-hex> --signature-key-id=<key-id>\n~~~\n\nOnly a strictly higher version with the same Package key and member scope may proceed. The update must have a paired verified backup, and it must stop before destructive work for a checksum/signature mismatch, dependency conflict, unknown or irreversible migration, or downgrade.\n\n## 7. Disable, reactivate, retire, and Purge\n\nDisable TenantModules and dependants before disabling the Package:\n\n~~~bash\nphp think module:disable-package <module-or-package-key>\n~~~\n\nReactivate by installing the exact same immutable archive again in the authorized development or instance-tool boundary; do not create a second reactivation path. Retire first produces a preview. Execute it only with the unchanged full `confirm_plan` file and digest. Add `--purge` only when the paired backup and double confirmation authorize deletion of Module-owned tables, migration ledger, catalog, and explicit RBAC bindings. Active TenantModules, dependants, protected Modules, or an occupied lifecycle task must stop the plan.\n\n## 8. Upgrade the application scaffold\n\nUse the Release's `scripts/scaffold-upgrade` contract:\n\n~~~bash\nphp scripts/scaffold-upgrade preflight --project-root=<application> --from-manifest=<from> --to-manifest=<to>\nphp scripts/scaffold-upgrade apply --project-root=<application> --plan=<plan>\nphp scripts/scaffold-upgrade verify --project-root=<application> --plan=<plan>\nphp scripts/scaffold-upgrade recover --project-root=<application> --plan=<plan>\n~~~\n\nOnly manifest-declared `managed` and `generated-managed` files are replaced. Module and business `app-owned` files remain under their owners. `recover` uses the same plan after a failed apply; it does not replace database migrations, Package updates, or production authorization.\n\nFor stable error fields and recovery handling, use the [reference error index](/reference#errors-and-recovery). For a versioned, redacted report, use [support](/support).\n",
            'docs-site/reference.md' => "# Reference\n\nThis is the generated application's command, version, configuration, and recovery index. Start with [Create an application and deliver a Module](/guide/application-module-lifecycle), then use [Support and issue reporting](/support) for a redacted diagnostic bundle or a problem report.\n\n## Where to look\n\n| Need | Start here |\n| --- | --- |\n| Application and Module commands | `scripts/`, `server/think`, and each Module's public contract |\n| Version identity | annotated tag or Release, application manifest, source commit/tree, and lock files |\n| HTTP and configuration | `server/route/`, controllers, `server/.env.example`, and the configuration loader |\n| Tenant and RBAC rules | trusted Tenant context, TenantModule governance, roles, permissions, and data scopes |\n| Scaffold upgrade | `scripts/scaffold-upgrade` and the source/target scaffold manifests |\n| Diagnostics and support | [support guide](/support) and the root `SECURITY.md` |\n\n## Command index\n\n| Command | Boundary |\n| --- | --- |\n| `php scripts/create-app --name=<name> --slug=<slug> --package=<vendor/name> --target=<absolute-path> [--application-version=<semver>] [--profile=minimal\|standard\|full]` | Create a fresh application from one immutable scaffold release. |\n| `php think module:create <module.key> [--vendor=<Vendor>]` | Generate a Module skeleton without overwriting an existing target. |\n| `php think module:check <module.key> [--kernel-version=<semver>] [--package=<tar>] [--sha256=<hash>]` | Run the eight read-only author checks; it does not connect to a database. |\n| `php think module:pack <module.key> [--output=<tar>] [--signing-key-id=<id> --signing-secret-key-file=<file>]` | Produce a deterministic archive and optional Ed25519 signature. |\n| `php think module:install-package <tar> [--sha256=<hash>] [--signature-key-id=<id>]` | Development/debug/Standalone install or same-archive reactivation; it does not enable a Tenant or grant RBAC. |\n| `php think module:update-package <tar> [--sha256=<hash>] [--signature-key-id=<id>] [--dry-run]` | Plan or apply a strictly higher immutable Package update. |\n| `php think module:disable-package <module-or-package-key>` | Disable a Package after its TenantModules and dependants are stopped. |\n| `php think module:uninstall-package MODULE_OR_PACKAGE_KEY [--purge] [--confirm-plan-file=PLAN_JSON --confirm-plan-digest=PLAN_SHA256]` | Preview or double-confirm retire/Purge; never edit the plan to bypass a stop. |\n| `php think ops-module:request preview\|prepare --operation=update\|retire\|purge ...` | Deployment owner queues only an opaque update, retire, or Purge task from registered targets. |\n| `scripts/ops-module-worker --once` | Production worker for update, retire, and Purge only; it has no initial install, disable, or reactivate entry. |\n| `php scripts/scaffold-upgrade preflight\|apply\|verify\|recover ...` | Apply or recover managed scaffold files without replacing app-owned Module or business code. |\n\nProduction HTTP cannot upload a Package, choose a local path or URL, issue a command, select a target, or supply credentials.\n\n## Errors and recovery\n\nCommands return structured output with a stable error code or JSON error. Preserve Package state, maintenance state, backup, and recovery pointer after a failure. Fix the indicated precondition, then repeat the corresponding check, dry-run, or preview. Never delete files, tables, locks, or migration ledgers as an ad hoc recovery.\n\n## Validation\n\nUse each command's `--help` where available, verify the exact version identity before an upgrade, and run the affected smoke checks after a successful operation.\n",
            'docs-site/support.md' => "# Support and issue reporting\n\nUse this page to provide a reproducible, privacy-preserving report. The [reference index](/reference) defines command boundaries; the root `SECURITY.md` defines private security contact.\n\n## Choose the channel\n\n- An ordinary defect, documentation error, compatibility question, or feature request belongs in the project's public Issue tracker.\n- Unauthorized access, Tenant boundary bypass, identity or RBAC bypass, sensitive-data exposure, arbitrary file or command execution, and signature/checksum bypass are security issues. Do not publish their details.\n- Real provider credentials, messages, payments, OAuth, or storage operations remain the responsibility of the relevant provider owner; support cannot safely run them against your production account.\n\n## Include version and a minimum reproduction\n\nFor an ordinary issue, include the exact annotated tag or Release, application version, source commit/tree from `.peanut/application-manifest.json`, deployment mode, operating system, PHP/Node versions, affected client or Module key/version, shortest reproduction, expected and actual result, stable error code, and command exit code. State whether it reproduces in a fresh independent application; if not, describe only the minimum app-owned change.\n\nDo not upload a database dump, private source, credentials, Tenant records, cookies, tokens, raw request headers, or absolute paths. Remove them from terminal excerpts.\n\n## Redacted diagnostic bundle\n\nAn authorized operator may attach a bounded diagnostic bundle when the deployment provides that capability. Check its checksum and inspect it before sharing. It may contain version identity, deployment mode, debug state, runtime status, installed Module summary, bounded failed-task codes, and structured audit messages. It must exclude raw logs, secrets, tokens, cookies, absolute paths, personal information, Tenant business records, resource registries, deployment tasks, recovery pointers, database dumps, environment files, and signing keys.\n\n## Private security contact\n\nUse the repository's **Security → Report a vulnerability** private form when available. If it is unavailable, create a public issue titled `Security contact request` containing only the affected release/tag and a private way to contact the maintainers. Do not include the component, attack steps, impact, proof of concept, credentials, diagnostic bundle, or screenshots. Wait for a private channel before sending sensitive details.\n\nOnly test systems, data, accounts, and providers that you own or are explicitly authorized to use.\n",
            'SECURITY.md' => "# {{PRODUCT_NAME}} Security Policy\n\n## Supported versions\n\nSecurity fixes target the latest published release of this application. Before reporting, confirm the affected annotated tag or Release and whether the behavior still reproduces there. Older releases may require an application upgrade before a fix can be applied.\n\n## Reporting a vulnerability\n\nDo not disclose vulnerability details, credentials, personal data, Tenant data, exploit code, or raw logs in a public Issue, Discussion, pull request, or screenshot.\n\nUse the repository's **Security → Report a vulnerability** private form when available. If it is unavailable, open a public Issue containing only the title `Security contact request`, the affected release or tag, and a private way for maintainers to contact you. Do not include the vulnerable component, attack steps, impact details, proof of concept, secrets, or diagnostic bundle in that contact-only Issue. Wait until a maintainer establishes a private channel before sending sensitive material.\n\n## What to include privately\n\nSend the affected release/tag, complete source commit when known, deployment mode, vulnerable component and prerequisites, minimal reproduction, observed impact, whether the issue crosses Account, Tenant, permission, Module, file, or deployment boundaries, and a redacted diagnostic bundle only when necessary.\n\nFor an ordinary issue, follow the [support guide](docs-site/support.md). Only test systems, data, accounts, and providers that you own or have explicit permission to use.\n",
            'docs-site/architecture/identity-and-tenancy.md' => "# Identity and tenancy\n\nAccount/Credential is the login identity; TenantMember is that account's membership in one Tenant; Tenant Role/RBAC grants permissions only in that Tenant. Business customers, suppliers and contacts remain application business records rather than Account fields.\n\nA PlatformOperator governs this application instance but does not become a TenantMember or gain arbitrary Tenant business-data access. Host-bound Tenant entry points restrict the session to the bound Tenant; only an explicitly configured shared Admin Host can offer Tenant switching to an account that has active memberships.\n",
            'docs-site/architecture/official-module-qualification.md' => "# Official module qualification\n\nA module is usable only when its Plugin artifact is installed, the Tenant has the module enabled, and the current TenantMember has the required RBAC/data permission. Each module owns its schema and public contracts; it must not read or write another module's private tables.\n\nBefore declaring a module available, add its Tenant isolation, disabled-module and authorization checks. External providers such as payment, notifications and OAuth require their own production configuration and verification.\n",
            'docs-site/capabilities.md' => "# Capability catalogue\n\nCore defaults are identity, Tenant membership, RBAC, audit, fresh installation, Module lifecycle and the Admin Shell. Files, notifications, OAuth, payment, member CRM, tasks, import/export and content are optional application capabilities, not an excuse to bypass Tenant isolation.\n\nProduct-specific domains such as Party, Store, Warehouse, Supplier relationship, Product, Pricing, Inventory, Procurement and Trade belong in this application's Modules. Add only the domains this product owns, with their data owner, public contract and acceptance tests.\n",
            'docs-site/guide/development.md', 'docs/peanut-admin-development-guide.md' => "# Development guide\n\nCore owns generic identity, tenancy and authorization contracts. This application owns routes, product settings, pages and business Runtime. A Module owns its tables, use cases, permissions, menu contributions and public DTO/command contracts.\n\nDevelop a vertical slice through route, controller, application service and Module contract. Supply TenantContext from trusted middleware; never accept a client-supplied Tenant ID as authorization. Add a normal Tenant A case and a denied Tenant B case before enabling the Module.\n",
            'docs-site/guide/module-development.md', 'docs/plugin-module-development.md' => "# Module development\n\nPlace an application Module under `server/app/modules/<Vendor>/<Module>/` with Domain, Application, Contracts, Infrastructure, Database, Resources and Tests. Put the matching management contribution in `web/src/modules/<module>/`.\n\nExpose commands and read-only DTOs from `Contracts`; callers must not join or mutate another Module's private tables. Plugin install, TenantModule enablement and member RBAC are separate gates. Document the Module's owner Tenant, migrations, menu/permission keys and cross-Tenant denial cases.\n",
            'docs/peanut-admin-user-manual.md' => "# Administrator manual\n\nThis page is the product owner’s operating manual. Document the application's enabled Modules, its roles, approval and data-scope rules, and the support path for tenant owners. Do not document product-only fields as Peanut Core behavior.\n",
            'docs-site/releases.md' => "# Releases\n\nCreate application releases from immutable application commits. Regenerate legal metadata and dependency inventory for each release.\n",
            'docs-site/legal.md' => "# Legal\n\nReview the generated root legal files before redistribution. Dependency changes require a refreshed SBOM and third-party notices.\n",
            'docs-site/404.md' => "# Page not found\n\nReturn to the [documentation home](/).\n",
            default => "# {{PRODUCT_NAME}} documentation\n\nThis page is app-owned. Replace it with product-specific documentation.\n",
        };
    }

    /** @param array<string,string> $parameters */
    private function releaseMetadata(array $parameters): string
    {
        $versions = $this->versionContract();
        if ($versions->isV2()) {
            return json_encode([
                'schema_version' => 2,
                'protocol' => 'peanut.release-metadata.v2',
                'product' => $parameters['PRODUCT_NAME'],
                'application_identity' => $parameters['PACKAGE_IDENTITY'],
                'source_product_version' => $versions->sourceProductVersion(),
                'instance_version' => $parameters['APPLICATION_VERSION'],
                'status' => 'generated-application-baseline',
                'release_policy' => 'replace this metadata from an immutable application release candidate before publishing',
                'public_runtime_dependencies' => [
                    'composer' => 'peanut-admin/core@' . $versions->corePhp(),
                    'frontend' => '@peanut-admin/admin@' . $versions->coreWeb(),
                ],
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . "\n";
        }
        $metadata = [
            'schema_version' => 1,
            'product' => $parameters['PRODUCT_NAME'],
            'application_identity' => $parameters['PACKAGE_IDENTITY'],
            'version' => $parameters['APPLICATION_VERSION'],
            'status' => 'generated-application-baseline',
            'release_policy' => 'replace this metadata from an immutable application release candidate before publishing',
            'public_runtime_dependencies' => [
                'composer' => 'peanut-admin/core@' . $versions->corePhp(),
                'frontend' => '@peanut-admin/admin@' . $versions->coreWeb(),
            ],
        ];
        return json_encode($metadata, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . "\n";
    }

    /** @param array<string,string> $parameters */
    private function versionContractDocument(array $parameters): string
    {
        $versions = $this->versionContract();
        if ($versions->isV2()) {
            return json_encode([
                'schema_version' => 2,
                'protocol' => 'peanut.release-versions.v2',
                'source_product_version' => $versions->sourceProductVersion(),
                'instance_version' => $parameters['APPLICATION_VERSION'],
                'scaffold_template' => $versions->scaffoldTemplate(),
                'generated_instance_default' => $versions->generatedInstanceDefault(),
                'core_php' => $versions->corePhp(),
                'core_web' => $versions->coreWeb(),
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . "\n";
        }
        return json_encode([
            'schema_version' => 1,
            'protocol' => 'peanut.release-versions.v1',
            'product_release' => $parameters['APPLICATION_VERSION'],
            'scaffold_template' => $versions->scaffoldTemplate(),
            'generated_application_default' => $parameters['APPLICATION_VERSION'],
            'core_php' => $versions->corePhp(),
            'core_web' => $versions->coreWeb(),
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . "\n";
    }

    /** @param array<string,string> $parameters */
    private function sbom(string $content, array $parameters): string
    {
        $content = $this->textTransform($content, $parameters, 'RELEASE_SBOM.spdx.json');
        $document = json_decode($content, true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($document) || !is_array($document['packages'] ?? null)) {
            throw new RuntimeException('CREATE_APP_SBOM_INVALID');
        }
        $rootPackages = 0;
        foreach ($document['packages'] as &$package) {
            if (!is_array($package) || ($package['SPDXID'] ?? null) !== 'SPDXRef-Package-Peanut-Admin') {
                continue;
            }
            $package['name'] = $parameters['PRODUCT_NAME'];
            $package['versionInfo'] = $parameters['APPLICATION_VERSION'];
            $rootPackages++;
        }
        unset($package);
        if ($rootPackages !== 1) {
            throw new RuntimeException('CREATE_APP_SBOM_ROOT_PACKAGE_INVALID');
        }
        $document['name'] = $parameters['PRODUCT_NAME'] . ' ' . $parameters['APPLICATION_VERSION'] . ' generated dependency SBOM';
        $document['documentNamespace'] = 'https://example.invalid/' . $parameters['SLUG'] . '/sbom/' . $parameters['APPLICATION_VERSION'];
        $document['creationInfo']['comment'] = 'Generated application baseline; regenerate from application lockfiles before release.';
        return json_encode($document, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . "\n";
    }

    /** @param array<string,string> $parameters */
    private function thirdPartyNotices(string $content, array $parameters): string
    {
        $content = $this->textTransform($content, $parameters, 'THIRD_PARTY_NOTICES.md');
        $content = str_replace(' 1.1.5 on 2026-08-15', ' generated application baseline', $content);
        $content = preg_replace('/Copyright \(c\) 2026 花生科技\. All rights reserved\./', 'Copyright holder: application owner. All rights reserved.', $content) ?? $content;
        return $content;
    }

    /** @param array<string,string> $parameters */
    private function brandManifest(array $parameters): string
    {
        $brand = [
            'schema_version' => 1,
            'website' => [
                'name' => $parameters['PRODUCT_NAME'], 'web_favicon' => 'brand/favicon.svg', 'web_logo' => 'brand/logo.svg',
                'login_image' => 'brand/login-background.svg', 'shop_name' => $parameters['PRODUCT_NAME'], 'shop_logo' => 'brand/logo.svg',
                'pc_logo' => 'brand/logo.svg', 'pc_title' => $parameters['PRODUCT_NAME'], 'pc_ico' => 'brand/favicon.svg',
                'pc_desc' => $parameters['PRODUCT_NAME'] . ' application', 'pc_keywords' => $parameters['PRODUCT_NAME'],
                'h5_favicon' => 'brand/favicon.svg', 'slogan' => 'A maintainable application baseline', 'copyright' => '',
                'official_url' => '', 'github_url' => '',
            ],
            'default_image' => [
                'admin_avatar' => 'brand/avatar-admin.svg', 'user_avatar' => 'brand/avatar-member.svg', 'menu' => 'brand/menu.svg',
                'project_docs' => 'brand/docs.svg', 'technical_support' => 'brand/support.svg',
            ],
        ];
        return json_encode($brand, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . "\n";
    }

    /** @param array<string,string> $parameters */
    private function resourceRegistry(array $parameters): string
    {
        $registry = [
            'schema_version' => 1,
            'project_id' => $parameters['SLUG'],
            'authority' => [
                'owner' => $parameters['PRODUCT_NAME'] . ' maintainers', 'source' => 'this versioned file',
                'credentials_policy' => 'references only; secrets are never stored in Git',
                'allocation_status' => 'unallocated; register environment-specific resources before connection or startup',
            ],
            'resources' => [
                'tooling' => [],
                'databases' => [[
                    'stable_resource_id' => $parameters['SLUG'] . '-mysql84-ci', 'purpose' => 'GitHub Actions server checks',
                    'environments' => ['ci'], 'owner' => 'generated GitHub Actions workflow', 'host' => '127.0.0.1', 'port' => 3306,
                    'database' => null, 'schema' => 'pa_ table prefix', 'namespace' => $parameters['SLUG'] . '-ci',
                    'service_type' => 'mysql:8.4 service container', 'credential_ref' => 'ephemeral workflow environment variables',
                    'data_source' => 'fresh ephemeral CI database', 'freshness_requirement' => 'new service container per job',
                    'health_check' => 'mysqladmin ping', 'fallback' => 'none', 'cleanup_responsibility' => 'GitHub Actions job teardown',
                ]],
                'local_listeners' => [], 'containers' => [], 'optional_services' => [],
                'external_services' => [],
                'queues' => ['status' => 'not_registered'], 'object_storage' => ['status' => 'not_registered'],
            ],
        ];
        return json_encode($registry, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . "\n";
    }

    /** @param array<string,string> $parameters */
    private function readme(): string
    {
        return "# {{PRODUCT_NAME}}\n\nApplication identity: `{{PACKAGE_IDENTITY}}` (`{{SLUG}}`).\n\n"
            . "This repository was generated from a versioned application scaffold. Application business code and the stable Host override files are app-owned; `.peanut/application-manifest.json` records the exact boundary and managed baseline.\n\n"
            . "Before connecting a database or starting a service, register the environment resources in `resources/project-resources.json`. A fresh install requires explicit `ADMIN_INITIAL_PASSWORD`; no shared default password is supplied.\n";
    }

    /** @param array<string,string> $parameters */
    private function license(): string
    {
        return "{{PRODUCT_NAME}}\n\nCopyright holder: application owner. All rights reserved.\n\n"
            . "Third-party components retain their own licenses; review THIRD_PARTY_NOTICES.md and RELEASE_SBOM.spdx.json before redistribution.\n";
    }

    private function changelog(string $version): string
    {
        return "# Changelog\n\n## {$version}\n\n- Initial generated application baseline for {{PRODUCT_NAME}}.\n";
    }

    private function modulesConfig(string $content): string
    {
        if (substr_count($content, "'plugin_lock' => (string)env('PEANUT_PLUGIN_LOCK', '../plugins.lock')") !== 1) {
            throw new RuntimeException('CREATE_APP_MODULES_CONFIG_SOURCE_INVALID');
        }
        return $content;
    }

    private function pluginsLock(string $content): string
    {
        try {
            $source = json_decode($content, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            throw new RuntimeException('CREATE_APP_PLUGIN_LOCK_SOURCE_INVALID', 0, $exception);
        }
        if (!is_array($source)
            || ($source['schema_version'] ?? null) !== 1
            || !is_array($source['plugins'] ?? null)
            || !array_is_list($source['plugins'])
        ) {
            throw new RuntimeException('CREATE_APP_PLUGIN_LOCK_SOURCE_INVALID');
        }
        $bundled = [];
        $keys = [];
        foreach ($source['plugins'] as $plugin) {
            $key = is_array($plugin) ? ($plugin['key'] ?? null) : null;
            if (!is_string($key) || $key === '') {
                throw new RuntimeException('CREATE_APP_PLUGIN_LOCK_SOURCE_INVALID');
            }
            // The source-only qualification fixture is excluded by the template inventory.
            if ($key === 'fixture.delivery-record') {
                continue;
            }
            if (isset($keys[$key])) {
                throw new RuntimeException('CREATE_APP_PLUGIN_LOCK_SOURCE_INVALID');
            }
            $keys[$key] = true;
            $bundled[] = $plugin;
        }
        if ($bundled === []) {
            throw new RuntimeException('CREATE_APP_PLUGIN_SET_EMPTY');
        }
        return json_encode(
            ['schema_version' => 1, 'plugins' => $bundled],
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
        ) . "\n";
    }

    /** @param list<array<string,mixed>> $files @return list<array<string,mixed>> */
    private function rebuildBundledPluginArtifacts(string $stage, array $files): array
    {
        $manifestPaths = [];
        foreach ($files as $file) {
            $path = (string)($file['path'] ?? '');
            if (preg_match('#^plugins/([a-z][a-z0-9.-]+)/plugin\.json$#D', $path, $matches) !== 1) {
                continue;
            }
            $manifestPaths[$matches[1]] = $path;
        }
        ksort($manifestPaths, SORT_STRING);
        if ($manifestPaths === []) {
            throw new RuntimeException('CREATE_APP_PLUGIN_SET_EMPTY');
        }

        // create-app is intentionally PHP/Git-only. The Writer still owns the
        // canonical bytes, while the dependency-free Resolver verifies the
        // completed derived artifacts without requiring Composer installation.
        $writer = new PluginArtifactWriter($stage . '/server', false);
        $rewritten = [];
        foreach ($manifestPaths as $directoryKey => $path) {
            $absolute = $stage . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $path);
            try {
                $manifest = json_decode((string)file_get_contents($absolute), true, 128, JSON_THROW_ON_ERROR);
            } catch (\JsonException $exception) {
                throw new RuntimeException('CREATE_APP_PLUGIN_MANIFEST_INVALID: ' . $path, 0, $exception);
            }
            if (!is_array($manifest) || array_is_list($manifest)
                || ($manifest['key'] ?? null) !== $directoryKey
                || !is_string($manifest['version'] ?? null)
                || !is_array($manifest['modules'] ?? null)
                || !array_is_list($manifest['modules'])
                || $manifest['modules'] === []) {
                throw new RuntimeException('CREATE_APP_PLUGIN_MANIFEST_INVALID: ' . $path);
            }
            $moduleSpecs = [];
            foreach ($manifest['modules'] as $module) {
                if (!is_array($module) || array_is_list($module)
                    || !is_string($module['key'] ?? null) || !is_string($module['root'] ?? null)) {
                    throw new RuntimeException('CREATE_APP_PLUGIN_MANIFEST_INVALID: ' . $path);
                }
                $moduleSpecs[] = $module['key'] . '=' . $module['root'];
            }
            $result = $writer->make($directoryKey, $manifest['version'], $moduleSpecs);
            if (($result['path'] ?? null) !== $path) {
                throw new RuntimeException('CREATE_APP_PLUGIN_MANIFEST_PATH_MISMATCH: ' . $path);
            }
            $rewritten[$path] = true;
        }
        $lock = $writer->writeLock();
        if (($lock['path'] ?? null) !== 'plugins.lock') {
            throw new RuntimeException('CREATE_APP_PLUGIN_LOCK_PATH_MISMATCH');
        }
        $rewritten['plugins.lock'] = true;
        $resolved = (new PluginLockResolver($stage . '/server', '../plugins.lock'))->all();
        if (array_keys($resolved) !== array_keys($manifestPaths)) {
            throw new RuntimeException('CREATE_APP_PLUGIN_LOCK_SET_MISMATCH');
        }

        foreach ($files as &$file) {
            $path = (string)($file['path'] ?? '');
            if (!isset($rewritten[$path])) {
                continue;
            }
            $absolute = $stage . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $path);
            $content = file_get_contents($absolute);
            if (!is_string($content) || (fileperms($absolute) & 0777) !== ($file['mode'] ?? null)) {
                throw new RuntimeException('CREATE_APP_PLUGIN_ARTIFACT_WRITE_MISMATCH: ' . $path);
            }
            $file['sha256'] = hash('sha256', $content);
            unset($rewritten[$path]);
        }
        unset($file);
        if ($rewritten !== []) {
            throw new RuntimeException('CREATE_APP_PLUGIN_ARTIFACT_UNDECLARED: ' . implode(',', array_keys($rewritten)));
        }
        return $files;
    }

    private function writeFile(string $path, string $content, int $mode): void
    {
        $directory = dirname($path);
        if (!is_dir($directory) && !mkdir($directory, 0775, true)) {
            throw new RuntimeException('CREATE_APP_DIRECTORY_FAILED: ' . $directory);
        }
        if (file_put_contents($path, $content) === false || !chmod($path, $mode)) {
            throw new RuntimeException('CREATE_APP_WRITE_FAILED: ' . $path);
        }
    }

    private function prepareWritableDirectories(string $stage): void
    {
        foreach (self::WRITABLE_DIRECTORIES as $relativePath) {
            $directory = $stage . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relativePath);
            if ((!is_dir($directory) && !mkdir($directory, 0775, true)) || !chmod($directory, 0775)) {
                throw new RuntimeException('CREATE_APP_WRITABLE_DIRECTORY_FAILED: ' . $relativePath);
            }
        }
    }

    private function assertNoUnresolvedVariables(string $root): void
    {
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            if (!$file->isFile() || $file->getSize() > 5_000_000) {
                continue;
            }
            $content = file_get_contents($file->getPathname());
            if (is_string($content) && preg_match('/{{[A-Z][A-Z0-9_]*}}/', $content) === 1) {
                throw new RuntimeException('CREATE_APP_UNKNOWN_TEMPLATE_VARIABLE: ' . $file->getPathname());
            }
        }
    }

    /** @param array<string,string> $parameters */
    private function render(string $value, array $parameters): string
    {
        $rendered = strtr($value, array_combine(
            array_map(static fn(string $key): string => '{{' . $key . '}}', array_keys($parameters)),
            array_values($parameters)
        ) ?: []);
        if (preg_match('/{{[A-Z][A-Z0-9_]*}}/', $rendered) === 1) {
            throw new RuntimeException('CREATE_APP_UNKNOWN_TEMPLATE_VARIABLE');
        }
        return $rendered;
    }

    /**
     * @param array{commit:string,tree:string} $generationIdentity
     * @param array{version:string,inventory_sha256:string,source_commit:string,source_tree:string} $templateIdentity
     * @param array<string,string> $parameters
     * @param list<array<string,mixed>> $files
     */
    private function writeApplicationManifest(
        string $stage,
        array $generationIdentity,
        string $inventoryDigest,
        array $templateIdentity,
        array $parameters,
        array $files,
        string $profile,
        EditionProfile $editionProfile,
    ): array {
        $baselineRoot = '.peanut/scaffold-baseline/' . $templateIdentity['version'] . '/files';
        foreach ($files as &$file) {
            if (!in_array($file['classification'], ['managed', 'generated-managed'], true)) {
                continue;
            }
            $source = $stage . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, (string)$file['path']);
            $baseline = $stage . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $baselineRoot . '/' . $file['path']);
            $content = file_get_contents($source);
            if (!is_string($content)) {
                throw new RuntimeException('CREATE_APP_BASELINE_READ_FAILED');
            }
            $this->writeFile($baseline, $content, 0644);
            $file['baseline_path'] = $baselineRoot . '/' . $file['path'];
            $file['baseline_sha256'] = hash('sha256', $content);
        }
        unset($file);
        $managed = array_values(array_filter($files, static fn(array $file): bool => in_array($file['classification'], ['managed', 'generated-managed'], true)));
        $appOwned = array_values(array_filter($files, static fn(array $file): bool => $file['classification'] === 'app-owned'));
        $manifest = [
            'schema_version' => 2,
            'protocol' => 'peanut.application-scaffold.v2',
            'application' => [
                'name' => $parameters['PRODUCT_NAME'],
                'slug' => $parameters['SLUG'],
                'package_identity' => $parameters['PACKAGE_IDENTITY'],
                'version' => $parameters['APPLICATION_VERSION'],
                'profile' => $profile,
                'edition' => $editionProfile->edition,
            ],
            'edition' => $editionProfile->identity(),
            'template' => $templateIdentity,
            'generation_source' => [
                'commit' => $generationIdentity['commit'],
                'tree' => $generationIdentity['tree'],
                'inventory_sha256' => $inventoryDigest,
                'edition_profile_sha256' => $editionProfile->sourceSha256,
            ],
            'ownership' => [
                'managed_default' => 'three-way against the recorded baseline; never overwrite a locally changed file without an explicit later apply decision',
                'app_owned_default' => 'preserve; future scaffold versions do not take ownership by default',
                'metadata' => ['path' => '.peanut/application-manifest.json', 'classification' => 'generated-managed'],
                'baseline_root' => $baselineRoot,
            ],
            'digests' => [
                'managed_tree_sha256' => $this->treeDigest($managed),
                'app_owned_tree_sha256' => $this->treeDigest($appOwned),
            ],
            'files' => $files,
        ];
        $manifestPath = $stage . '/.peanut/application-manifest.json';
        $this->writeFile($manifestPath, json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . "\n", 0644);
        return $manifest;
    }

    private function versionContract(): VersionContract
    {
        return VersionContract::load($this->sourceRoot . '/release-versions.json');
    }

    /** @param list<array<string,mixed>> $files */
    private function treeDigest(array $files): string
    {
        $rows = array_map(static fn(array $file): string => $file['path'] . "\0" . $file['sha256'], $files);
        sort($rows, SORT_STRING);
        return hash('sha256', implode("\n", $rows));
    }

    private function deleteTree(string $path): void
    {
        if (!file_exists($path) && !is_link($path)) {
            return;
        }
        if (is_dir($path) && !is_link($path)) {
            foreach (array_diff(scandir($path) ?: [], ['.', '..']) as $entry) {
                $this->deleteTree($path . DIRECTORY_SEPARATOR . $entry);
            }
            rmdir($path);
            return;
        }
        unlink($path);
    }
}
