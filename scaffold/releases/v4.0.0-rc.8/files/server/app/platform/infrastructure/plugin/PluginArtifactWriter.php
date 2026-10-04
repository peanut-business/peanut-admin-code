<?php

declare(strict_types=1);

namespace app\platform\infrastructure\plugin;

use app\common\infrastructure\module\ModuleHostLayoutFactory;
use app\platform\exception\plugin\PluginArtifactToolException;
use app\platform\value\plugin\ModuleFrontendLayout;
use Opis\JsonSchema\Errors\ErrorFormatter;
use Opis\JsonSchema\Validator;
use PeanutAdmin\Kernel\Module\ModuleKey;

/** Builds deterministic Plugin manifests and the repository Plugin lock without runtime services. */
final readonly class PluginArtifactWriter
{
    public function __construct(
        private string $serverRoot,
        private bool $validateJsonSchema = true,
    ) {}

    /** @param list<string> $moduleSpecs @return array<string,mixed> */
    public function build(string $key, string $version, array $moduleSpecs): array
    {
        $key = $this->key($key);
        $version = $this->version($version);
        if ($moduleSpecs === []) {
            throw new PluginArtifactToolException('At least one --module key=relative/root is required.');
        }

        $modules = [];
        foreach ($moduleSpecs as $spec) {
            [$moduleKey, $root] = $this->moduleSpec($spec);
            if (isset($modules[$moduleKey])) {
                throw new PluginArtifactToolException("Duplicate Module key: {$moduleKey}");
            }
            $modules[$moduleKey] = $root;
        }
        ksort($modules, SORT_STRING);

        return $this->manifest($key, $version, $modules);
    }

    /** @param list<string> $moduleSpecs @return array<string,mixed> */
    public function make(string $key, string $version, array $moduleSpecs): array
    {
        $manifest = $this->build($key, $version, $moduleSpecs);
        $key = (string) $manifest['key'];

        $path = $this->projectRoot() . '/plugins/' . $key . '/plugin.json';
        $this->writeJson($path, $manifest);
        return ['path' => $this->relative($path), 'manifest' => $manifest];
    }

    /** @return array{plugins:list<array<string,mixed>>,lock:array<string,mixed>,contents:string} */
    public function lock(): array
    {
        $pluginDirectory = $this->projectRoot() . '/plugins';
        if (!is_dir($pluginDirectory)) {
            throw new PluginArtifactToolException('Plugin directory is unavailable.');
        }
        $manifests = glob($pluginDirectory . '/*/plugin.json') ?: [];
        sort($manifests, SORT_STRING);
        $plugins = [];
        foreach ($manifests as $manifestPath) {
            $manifest = $this->readJson($manifestPath);
            if ($this->validateJsonSchema) {
                $this->assertSchema($manifest);
            }
            $key = $this->key($manifest['key'] ?? '');
            $moduleRoots = [];
            foreach ((array) ($manifest['modules'] ?? []) as $module) {
                if (!is_array($module)) {
                    throw new PluginArtifactToolException("Plugin manifest modules are invalid: {$key}");
                }
                $moduleKey = $this->key($module['key'] ?? '');
                $moduleRoots[$moduleKey] = (string) ($module['root'] ?? '');
            }
            $expectedManifest = $this->manifest(
                $key,
                $this->version($manifest['version'] ?? ''),
                $moduleRoots,
            );
            if ($this->encode($manifest) !== $this->encode($expectedManifest)) {
                throw new PluginArtifactToolException("Plugin manifest is not canonical: {$key}; run plugin:make.");
            }
            if (isset($plugins[$key])) {
                throw new PluginArtifactToolException("Duplicate Plugin manifest: {$key}");
            }
            $plugins[$key] = [
                'key' => $key,
                'version' => $this->version($manifest['version'] ?? ''),
                'source' => $manifest['source'],
                'trust' => $manifest['trust'],
                'composer' => $manifest['composer'],
                'npm' => $manifest['npm'],
                'frontend' => $manifest['frontend'],
                'modules' => $manifest['modules'],
                'manifest' => $this->relative($manifestPath),
                'manifest_sha256' => $this->digest($manifestPath),
            ];
        }
        ksort($plugins, SORT_STRING);
        $lock = ['schema_version' => 1, 'plugins' => array_values($plugins)];
        return ['plugins' => array_values($plugins), 'lock' => $lock, 'contents' => $this->encode($lock)];
    }

    /** @return array{path:string,plugins:int} */
    public function writeLock(): array
    {
        $built = $this->lock();
        $path = $this->projectRoot() . '/plugins.lock';
        $this->writeContents($path, $built['contents']);
        return ['path' => $this->relative($path), 'plugins' => count($built['plugins'])];
    }

    /** @return array{path:string,plugins:int} */
    public function checkLock(): array
    {
        $built = $this->lock();
        $path = $this->projectRoot() . '/plugins.lock';
        if (!is_file($path) || !hash_equals($built['contents'], (string) file_get_contents($path))) {
            throw new PluginArtifactToolException('plugins.lock is not canonical; run plugin:lock --write.');
        }
        return ['path' => $this->relative($path), 'plugins' => count($built['plugins'])];
    }

    /** @param array<string,string> $moduleRoots @return array<string,mixed> */
    private function manifest(string $key, string $version, array $moduleRoots): array
    {
        $modules = [];
        $composer = [];
        $npm = [];
        $frontend = [];
        $contentRoots = [];
        $layout = ModuleHostLayoutFactory::pathLayout();
        $moduleSources = [];
        foreach ($moduleRoots as $moduleKey => $root) {
            $expectedRoot = rtrim($layout->backendRelativePath(ModuleKey::fromString($moduleKey)), '/');
            if ($root !== $expectedRoot) {
                throw new PluginArtifactToolException("Module root is not key-derived: {$moduleKey}");
            }
            $absoluteRoot = $this->withinProject($root);
            if (!is_dir($absoluteRoot) || !is_file($absoluteRoot . '/module.json') || !is_file($absoluteRoot . '/composer.json')) {
                throw new PluginArtifactToolException("Module root is incomplete: {$root}");
            }
            $modules[] = ['key' => $moduleKey, 'root' => $root];
            $package = $this->readJson($absoluteRoot . '/composer.json');
            $composer[] = [
                'name' => $this->packageName($package['name'] ?? '', 'Composer'),
                'version' => $this->version($package['version'] ?? ''),
                'sha256' => $this->digest($absoluteRoot . '/composer.json'),
            ];
            $contentRoots[] = $absoluteRoot;
            $moduleSources[$moduleKey] = $absoluteRoot;

            $moduleManifest = $this->readJson($absoluteRoot . '/module.json');
            try {
                $contributions = ModuleFrontendLayout::contributions(
                    is_array($moduleManifest['frontend'] ?? null) ? $moduleManifest['frontend'] : [],
                    $moduleKey,
                );
            } catch (\InvalidArgumentException $exception) {
                throw new PluginArtifactToolException($exception->getMessage(), 0, $exception);
            }
            foreach ($contributions as $contribution) {
                $frontendRoot = $contribution['root'];
                $absoluteFrontendRoot = $this->withinProject($frontendRoot);
                $packagePath = $absoluteFrontendRoot . '/package.json';
                $entryPath = $this->withinProject($contribution['entry']);
                if (!is_dir($absoluteFrontendRoot) || !is_file($packagePath) || !is_file($entryPath)) {
                    throw new PluginArtifactToolException("Frontend contribution is incomplete: {$frontendRoot}");
                }
                $package = $this->readJson($packagePath);
                $packageName = $this->packageName($package['name'] ?? '', 'npm');
                $packageVersion = $this->version($package['version'] ?? '');
                $npm[] = [
                    'name' => $packageName,
                    'version' => $packageVersion,
                    'integrity' => 'sha256-' . base64_encode(hash_file('sha256', $packagePath, true) ?: ''),
                ];
                $frontend[] = [
                    'client_key' => $contribution['client_key'],
                    'package' => $packageName,
                    'version' => $packageVersion,
                    'entry' => $contribution['entry'],
                    'sha256' => $this->digest($entryPath),
                ];
                $contentRoots[] = $absoluteFrontendRoot;
            }
        }
        try {
            ModuleHostLayoutFactory::fromModuleRoots($moduleSources);
        } catch (\InvalidArgumentException $exception) {
            throw new PluginArtifactToolException('Module PHP namespaces overlap or claim a reserved prefix.', 0, $exception);
        }
        $this->sortIdentities($composer, 'name');
        $this->sortIdentities($npm, 'name');
        $this->sortIdentities($frontend, 'entry');
        $manifest = [
            'schema_version' => 1,
            'key' => $key,
            'version' => $version,
            'source' => [
                'type' => 'canonical-contents',
                'reference' => 'canonical-plugin-contents-v1',
                'sha256' => $this->canonicalContentsDigest($contentRoots),
            ],
            'trust' => $this->trust($moduleRoots),
            'composer' => $composer,
            'npm' => $npm,
            'frontend' => $frontend,
            'modules' => $modules,
        ];
        if ($this->validateJsonSchema) {
            $this->assertSchema($manifest);
        }
        return $manifest;
    }

    /** @param array<string,string> $moduleRoots @return array<string,mixed> */
    private function trust(array $moduleRoots): array
    {
        $modules = [];
        $licenses = [];
        foreach ($moduleRoots as $moduleKey => $root) {
            $absoluteRoot = $this->withinProject($root);
            $module = $this->readJson($absoluteRoot . '/module.json');
            $backend = is_array($module['backend'] ?? null) ? $module['backend'] : [];
            $dependencies = [];
            foreach ((array) ($module['dependencies'] ?? []) as $dependency) {
                if (!is_array($dependency)) {
                    throw new PluginArtifactToolException("Module dependency is invalid: {$moduleKey}");
                }
                $dependencies[] = [
                    'module_key' => $this->key($dependency['module_key'] ?? ''),
                    'version' => $this->versionConstraint($dependency['version'] ?? ''),
                ];
            }
            $this->sortIdentities($dependencies, 'module_key');
            $permissionPath = is_string($backend['permissions'] ?? null) ? $backend['permissions'] : null;
            $permissions = ['path' => $permissionPath, 'sha256' => null];
            if ($permissionPath !== null) {
                $permissions = [
                    'path' => $permissionPath,
                    'sha256' => $this->digest($this->withinModule($absoluteRoot, $permissionPath, $moduleKey)),
                ];
            }
            $modules[] = [
                'key' => $moduleKey,
                'version' => $this->version($module['version'] ?? ''),
                'kernel_constraint' => $this->versionConstraint($module['kernel_constraint'] ?? ''),
                'dependencies' => $dependencies,
                'permissions' => $permissions,
                'migrations' => [
                    'reversibility' => 'verified-backup-required',
                    'files' => $this->migrationFingerprints($absoluteRoot, $moduleKey, $backend),
                ],
            ];
            $licenses[] = trim((string) ($module['license'] ?? ''));
        }
        $this->sortIdentities($modules, 'key');
        $licenses = array_values(array_unique($licenses));
        sort($licenses, SORT_STRING);
        if (count($licenses) !== 1 || $licenses[0] === '') {
            throw new PluginArtifactToolException('Plugin Modules must declare one shared license.');
        }
        return [
            'channel' => 'bundled',
            'origin' => ['type' => 'repository-contents', 'reference' => 'canonical-plugin-contents-v1'],
            'archive' => ['status' => 'not-issued', 'sha256' => null],
            'signature' => ['status' => 'not-issued', 'key_id' => null],
            'sbom' => ['status' => 'not-issued', 'sha256' => null],
            'license' => ['identifier' => $licenses[0], 'status' => 'declared'],
            'qualification' => [
                'status' => 'bundled-locked',
                'review' => 'not-reviewed',
                'vulnerability_response' => 'not-configured',
                'marketplace' => 'blocked',
            ],
            'compatibility' => ['modules' => $modules],
        ];
    }

    /** @param array<string,mixed> $backend @return list<array{key:string,sha256:string}> */
    private function migrationFingerprints(string $root, string $moduleKey, array $backend): array
    {
        if (!array_key_exists('migrations', $backend)) {
            return [];
        }
        if ($backend['migrations'] !== 'database/migrations') {
            throw new PluginArtifactToolException("Module migration path is not canonical: {$moduleKey}");
        }
        $directories = ['database/migrations'];
        $files = [];
        $resolvedDirectories = [];
        foreach (array_values(array_unique($directories)) as $directory) {
            $resolved = realpath($root . '/' . ltrim($directory, '/'));
            if ($resolved === false || !is_dir($resolved)) {
                throw new PluginArtifactToolException("Module migration directory is unavailable: {$moduleKey}");
            }
            $stat = stat($resolved);
            if (!is_array($stat) || !isset($stat['dev'], $stat['ino'])) {
                throw new PluginArtifactToolException("Module migration directory is unavailable: {$moduleKey}");
            }
            $resolvedDirectories[$stat['dev'] . ':' . $stat['ino']] = $resolved;
        }
        foreach (array_values($resolvedDirectories) as $resolved) {
            foreach (glob($resolved . '/*.sql') ?: [] as $path) {
                $key = $moduleKey . ':' . basename($path, '.sql');
                if (isset($files[$key])) {
                    throw new PluginArtifactToolException("Duplicate Module migration: {$key}");
                }
                $files[$key] = ['key' => $key, 'sha256' => $this->digest($path)];
            }
        }
        ksort($files, SORT_STRING);
        return array_values($files);
    }

    private function withinModule(string $root, string $relative, string $moduleKey): string
    {
        if ($relative === '' || str_starts_with($relative, '/') || str_contains($relative, '..')) {
            throw new PluginArtifactToolException("Module permission path is invalid: {$moduleKey}");
        }
        $path = realpath($root . '/' . $relative);
        if ($path === false || !str_starts_with($path, $root . DIRECTORY_SEPARATOR) || !is_file($path)) {
            throw new PluginArtifactToolException("Module permission file is unavailable: {$moduleKey}");
        }
        return $path;
    }

    /** @param list<string> $roots */
    private function canonicalContentsDigest(array $roots): string
    {
        $files = [];
        foreach ($roots as $root) {
            $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS));
            foreach ($iterator as $file) {
                if (!$file->isFile() || $file->isLink()) {
                    continue;
                }
                $relative = $this->relative($file->getPathname());
                if (isset($files[$relative])) {
                    throw new PluginArtifactToolException('Duplicate canonical Plugin file.');
                }
                $files[$relative] = $this->digest($file->getPathname());
            }
        }
        ksort($files, SORT_STRING);
        $canonical = '';
        foreach ($files as $relative => $digest) {
            $canonical .= $relative . "\0" . $digest . "\n";
        }
        return hash('sha256', $canonical);
    }

    /** @param array<string,mixed> $document */
    private function assertSchema(array $document): void
    {
        $schemaPath = $this->serverRoot . '/resources/schemas/plugin.schema.json';
        try {
            $schema = json_decode((string) file_get_contents($schemaPath), false, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new PluginArtifactToolException('Plugin schema is invalid.');
        }
        // Opis distinguishes JSON objects from arrays recursively. Casting only
        // the root PHP array leaves nested objects (for example `source`) as
        // JSON arrays, so round-trip through JSON to preserve the full shape.
        try {
            $documentObject = json_decode(
                json_encode($document, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
                false,
                512,
                JSON_THROW_ON_ERROR,
            );
        } catch (\JsonException $exception) {
            throw new PluginArtifactToolException('Plugin manifest cannot be encoded for schema validation.', 0, $exception);
        }
        $result = (new Validator())->validate($documentObject, $schema);
        if ($result->isValid()) {
            return;
        }
        $details = $result->error() === null ? [] : (new ErrorFormatter())->formatKeyed($result->error());
        throw new PluginArtifactToolException('Plugin manifest schema validation failed: ' . json_encode($details, JSON_UNESCAPED_SLASHES));
    }

    /** @return array<string,mixed> */
    private function readJson(string $path): array
    {
        try {
            $value = json_decode((string) file_get_contents($path), true, 128, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new PluginArtifactToolException("JSON file is invalid: {$path}");
        }
        if (!is_array($value) || array_is_list($value)) {
            throw new PluginArtifactToolException("JSON object is invalid: {$path}");
        }
        return $value;
    }

    private function withinProject(string $relative): string
    {
        if ($relative === '' || str_starts_with($relative, '/') || str_contains($relative, '..')) {
            throw new PluginArtifactToolException('Plugin path must be a project-relative path.');
        }
        $path = realpath($this->projectRoot() . '/' . $relative);
        $root = realpath($this->projectRoot());
        if ($path === false || $root === false || !str_starts_with($path, $root . DIRECTORY_SEPARATOR)) {
            throw new PluginArtifactToolException("Plugin path is unavailable: {$relative}");
        }
        return $path;
    }

    /** @return array{string,string} */
    private function moduleSpec(string $spec): array
    {
        [$key, $root] = array_pad(explode('=', trim($spec), 2), 2, '');
        return [$this->key($key), trim($root)];
    }

    private function key(mixed $value): string
    {
        $value = trim((string) $value);
        if (preg_match('/^[a-z][a-z0-9]*(?:[.-][a-z0-9]+)*$/D', $value) !== 1 || strlen($value) > 96) {
            throw new PluginArtifactToolException('Plugin key is invalid.');
        }
        return $value;
    }

    private function version(mixed $value): string
    {
        $value = trim((string) $value);
        if (preg_match('/^[0-9]+\.[0-9]+\.[0-9]+(?:[-+][0-9A-Za-z.-]+)?$/D', $value) !== 1 || strlen($value) > 32) {
            throw new PluginArtifactToolException('Plugin version is invalid.');
        }
        return $value;
    }

    private function versionConstraint(mixed $value): string
    {
        $value = trim((string) $value);
        if ($value === '' || strlen($value) > 64) {
            throw new PluginArtifactToolException('Module version constraint is invalid.');
        }
        return $value;
    }

    private function packageName(mixed $value, string $kind): string
    {
        $value = trim((string) $value);
        if ($value === '') {
            throw new PluginArtifactToolException("{$kind} package name is invalid.");
        }
        return $value;
    }

    private function digest(string $path): string
    {
        $digest = hash_file('sha256', $path);
        if (!is_string($digest)) {
            throw new PluginArtifactToolException("Cannot digest Plugin file: {$path}");
        }
        return $digest;
    }

    private function projectRoot(): string
    {
        return realpath(dirname($this->serverRoot)) ?: dirname($this->serverRoot);
    }
    private function relative(string $path): string
    {
        $path = realpath($path) ?: $path;
        return ltrim(substr($path, strlen($this->projectRoot())), '/');
    }
    /** @param list<array<string,mixed>> $items */
    private function sortIdentities(array &$items, string $field): void
    {
        usort($items, static fn(array $a, array $b): int => strcmp((string) $a[$field], (string) $b[$field]));
    }
    /** @param array<string,mixed> $value */
    private function encode(array $value): string
    {
        return json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
    }
    /** @param array<string,mixed> $value */
    private function writeJson(string $path, array $value): void
    {
        $this->writeContents($path, $this->encode($value));
    }
    private function writeContents(string $path, string $contents): void
    {
        $directory = dirname($path);
        if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
            throw new PluginArtifactToolException("Cannot create Plugin directory: {$directory}");
        }
        $temporary = $path . '.tmp-' . bin2hex(random_bytes(8));
        if (file_put_contents($temporary, $contents, LOCK_EX) === false) {
            throw new PluginArtifactToolException("Cannot write Plugin file: {$path}");
        }
        if (!rename($temporary, $path)) {
            unlink($temporary);
            throw new PluginArtifactToolException("Cannot promote Plugin file: {$path}");
        }
    }
}
