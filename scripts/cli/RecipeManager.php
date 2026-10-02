<?php

declare(strict_types=1);

namespace Peanut\Cli;

use app\common\infrastructure\scaffold\Semver;
use app\common\validation\scaffold\ScaffoldPathGuard;
use app\common\value\scaffold\ScaffoldManifest;
use RuntimeException;
use Throwable;

/** Local, additive Recipe installation. Upgrades remain with the scaffold engine. */
final class RecipeManager
{
    public function __construct(private readonly string $toolRoot) {}

    public static function json(string $path): array
    {
        $data = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($data)) {
            throw new RuntimeException('PEANUT_JSON_OBJECT_REQUIRED');
        }
        return $data;
    }

    public function catalog(): array
    {
        $catalog = self::json($this->toolRoot . '/recipes/catalog.json');
        if (($catalog['protocol'] ?? null) !== 'peanut.recipe-catalog.v1' || ($catalog['schema_version'] ?? null) !== 1
            || !is_array($catalog['recipes'] ?? null)) {
            throw new RuntimeException('RECIPE_CATALOG_INVALID');
        }
        return $catalog['recipes'];
    }

    public function application(string $root): array
    {
        $path = ScaffoldPathGuard::projectPath($root, '.peanut/application-manifest.json');
        ScaffoldPathGuard::existingFileWithin($root, $path, 'APPLICATION_MANIFEST_REQUIRED');
        $app = self::json($path);
        if (($app['protocol'] ?? null) !== 'peanut.application-scaffold.v2' || ($app['schema_version'] ?? null) !== 2
            || !is_array($app['application'] ?? null) || !is_array($app['template'] ?? null) || !is_array($app['files'] ?? null)) {
            throw new RuntimeException('APPLICATION_MANIFEST_INVALID');
        }
        foreach (['name', 'slug', 'package_identity', 'version', 'edition', 'profile'] as $key) {
            if (!is_string($app['application'][$key] ?? null) || $app['application'][$key] === '') {
                throw new RuntimeException('APPLICATION_IDENTITY_INVALID');
            }
        }
        Semver::compare($app['application']['version'], $app['application']['version']);
        return $app;
    }

    public function status(string $root, string $id): array
    {
        $this->id($id);
        $base = '.peanut/recipes/' . $id;
        $pending = ScaffoldPathGuard::projectPath($root, '.peanut/recipes/.' . $id . '.installing');
        if (file_exists($pending)) {
            return ['recipe' => $id, 'status' => 'recovery_required'];
        }
        $directory = ScaffoldPathGuard::projectPath($root, $base);
        if (!file_exists($directory)) {
            return ['recipe' => $id, 'status' => 'not_installed'];
        }
        $manifestPath = ScaffoldPathGuard::projectPath($root, $base . '/manifest.json');
        ScaffoldPathGuard::existingFileWithin($root, $manifestPath, 'RECIPE_STATE_INVALID');
        $state = self::json($manifestPath);
        if (($state['protocol'] ?? null) !== 'peanut.recipe-installation.v1' || ($state['schema_version'] ?? null) !== 1
            || ($state['recipe'] ?? null) !== $id || !is_array($state['manifest'] ?? null)) {
            throw new RuntimeException('RECIPE_STATE_INVALID');
        }
        $manifest = $state['manifest'];
        $this->validateManifest($manifest, $id);
        $sourceManifest = ScaffoldPathGuard::projectPath($root, $base . '/source-manifest.json');
        ScaffoldPathGuard::existingFileWithin($root, $sourceManifest, 'RECIPE_STATE_INVALID');
        if (!is_string($state['manifest_sha256'] ?? null)
            || !hash_equals($state['manifest_sha256'], hash_file('sha256', $sourceManifest))
            || self::json($sourceManifest) !== $manifest) {
            throw new RuntimeException('RECIPE_MANIFEST_DIGEST_MISMATCH');
        }
        $files = [];
        foreach ($manifest['files'] as $entry) {
            $path = $entry['path'];
            $baseline = ScaffoldPathGuard::projectPath($root, $base . '/baseline/files/' . $path);
            ScaffoldPathGuard::existingFileWithin($root, $baseline, 'RECIPE_BASELINE_INVALID');
            if (!hash_equals($entry['sha256'], hash_file('sha256', $baseline))) {
                throw new RuntimeException('RECIPE_BASELINE_DIGEST_MISMATCH: ' . $path);
            }
            $target = ScaffoldPathGuard::projectPath($root, $path);
            $status = !file_exists($target) ? 'missing' : 'modified';
            if (is_file($target)) {
                ScaffoldPathGuard::existingFileWithin($root, $target, 'RECIPE_TARGET_INVALID');
                if (hash_equals($entry['sha256'], hash_file('sha256', $target)) && (fileperms($target) & 0777) === $entry['mode']) {
                    $status = 'unchanged';
                }
            }
            $files[] = ['path' => $path, 'owner' => $entry['owner'], 'status' => $status];
        }
        return ['recipe' => $id, 'version' => $manifest['version'], 'status' => 'installed',
            'manifest_sha256' => $state['manifest_sha256'] ?? null, 'files' => $files];
    }

    public function add(string $root, string $id): array
    {
        $this->id($id);
        $app = $this->application($root);
        $existing = $this->status($root, $id);
        if ($existing['status'] === 'installed') {
            // Re-running add never rewrites a baseline or downstream customization.
            return $existing;
        }
        if ($existing['status'] !== 'not_installed') {
            throw new RuntimeException('RECIPE_RECOVERY_REQUIRED');
        }
        $catalog = $this->catalog();
        $version = $catalog[$id] ?? null;
        if (!is_string($version)) {
            throw new RuntimeException('RECIPE_UNKNOWN: ' . $id);
        }
        Semver::compare($version, $version);
        $bundle = ScaffoldPathGuard::projectPath($this->toolRoot, 'recipes/' . $id . '/' . $version);
        $sourceManifest = ScaffoldPathGuard::projectPath($bundle, 'manifest.json');
        ScaffoldPathGuard::existingFileWithin($bundle, $sourceManifest, 'RECIPE_MANIFEST_INVALID');
        $manifest = self::json($sourceManifest);
        $this->validateManifest($manifest, $id);
        if ($manifest['version'] !== $version) {
            throw new RuntimeException('RECIPE_VERSION_MISMATCH');
        }
        $contents = [];
        foreach ($manifest['files'] as $entry) {
            $source = ScaffoldPathGuard::projectPath($bundle, 'files/' . $entry['path']);
            ScaffoldPathGuard::existingFileWithin($bundle, $source, 'RECIPE_SOURCE_INVALID');
            $raw = (string) file_get_contents($source);
            if (!hash_equals($entry['sha256'], hash('sha256', $raw))) {
                throw new RuntimeException('RECIPE_SOURCE_DIGEST_MISMATCH: ' . $entry['path']);
            }
            $contents[$entry['path']] = $raw;
        }
        $this->preflight($root, $app, $manifest);
        $recipes = ScaffoldPathGuard::projectPath($root, '.peanut/recipes');
        ScaffoldPathGuard::ensureDirectory($recipes);
        $stage = ScaffoldPathGuard::projectPath($root, '.peanut/recipes/.' . $id . '.installing');
        $destination = ScaffoldPathGuard::projectPath($root, '.peanut/recipes/' . $id);
        if (!mkdir($stage, 0775)) {
            throw new RuntimeException('RECIPE_INSTALL_LOCKED');
        }
        $created = [];
        try {
            $this->preflight($root, $app, $manifest);
            $state = ['schema_version' => 1, 'protocol' => 'peanut.recipe-installation.v1', 'recipe' => $id,
                'manifest_sha256' => hash_file('sha256', $sourceManifest), 'manifest' => $manifest];
            $this->write($stage, 'manifest.json', json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n", 0644);
            $this->write($stage, 'source-manifest.json', (string) file_get_contents($sourceManifest), 0644);
            foreach ($manifest['files'] as $entry) {
                $this->write($stage, 'baseline/files/' . $entry['path'], $contents[$entry['path']], $entry['mode']);
            }
            foreach ($manifest['files'] as $entry) {
                $this->write($root, $entry['path'], $contents[$entry['path']], $entry['mode']);
                $created[] = $entry;
            }
            if (file_exists($destination) || !rename($stage, $destination)) {
                throw new RuntimeException('RECIPE_STATE_COMMIT_FAILED');
            }
        } catch (Throwable $error) {
            // Only undo files created here, and only while their bytes still match.
            foreach ($created as $entry) {
                $path = ScaffoldPathGuard::projectPath($root, $entry['path']);
                if (!is_file($path) || !hash_equals($entry['sha256'], hash_file('sha256', $path)) || !unlink($path)) {
                    throw new RuntimeException('RECIPE_RECOVERY_REQUIRED', 0, $error);
                }
            }
            if ($error->getMessage() !== 'RECIPE_RECOVERY_REQUIRED') {
                $this->removeStage($stage);
            }
            throw $error;
        }
        return $this->status($root, $id);
    }

    private function id(string $id): void
    {
        if (preg_match('/^[a-z][a-z0-9]*(?:-[a-z0-9]+)*$/D', $id) !== 1) {
            throw new RuntimeException('RECIPE_ID_INVALID');
        }
    }

    private function validateManifest(array $manifest, string $id): void
    {
        if (($manifest['protocol'] ?? null) !== 'peanut.recipe.v1' || ($manifest['schema_version'] ?? null) !== 1
            || ($manifest['recipe'] ?? null) !== $id || !is_string($manifest['version'] ?? null)
            || !is_array($manifest['files'] ?? null) || $manifest['files'] === []) {
            throw new RuntimeException('RECIPE_MANIFEST_INVALID');
        }
        Semver::compare($manifest['version'], $manifest['version']);
        $seen = [];
        foreach ($manifest['files'] as $entry) {
            if (!is_array($entry) || !is_string($entry['path'] ?? null)) {
                throw new RuntimeException('RECIPE_FILE_INVALID');
            }
            ScaffoldManifest::path($entry['path']);
            if (!str_starts_with($entry['path'], '.github/workflows/') || !str_ends_with($entry['path'], '.yml')
                || ($entry['owner'] ?? null) !== 'recipe:' . $id || ($entry['classification'] ?? null) !== 'managed'
                || ($entry['mode'] ?? null) !== 0644 || preg_match('/^[a-f0-9]{64}$/D', (string) ($entry['sha256'] ?? '')) !== 1
                || isset($seen[$entry['path']])) {
                throw new RuntimeException('RECIPE_FILE_INVALID');
            }
            $seen[$entry['path']] = true;
        }
    }

    private function preflight(string $root, array $app, array $manifest): void
    {
        foreach ($manifest['files'] as $entry) {
            foreach ($app['files'] as $owned) {
                if (($owned['path'] ?? null) === $entry['path']) {
                    throw new RuntimeException('RECIPE_OWNERSHIP_CONFLICT: ' . $entry['path']);
                }
            }
            $path = ScaffoldPathGuard::projectPath($root, $entry['path']);
            $decision = ScaffoldManifest::additionDecision(file_exists($path), false);
            if ($decision['conflict']) {
                throw new RuntimeException('RECIPE_PATH_CONFLICT: ' . $entry['path']);
            }
        }
    }

    private function write(string $root, string $relative, string $content, int $mode): void
    {
        $path = ScaffoldPathGuard::projectPath($root, $relative);
        ScaffoldPathGuard::ensureDirectory(dirname($path));
        $path = ScaffoldPathGuard::projectPath($root, $relative);
        $handle = fopen($path, 'x+b');
        if ($handle === false) {
            throw new RuntimeException('RECIPE_EXCLUSIVE_WRITE_FAILED: ' . $relative);
        }
        try {
            if (fwrite($handle, $content) !== strlen($content) || !fflush($handle) || !chmod($path, $mode)) {
                throw new RuntimeException('RECIPE_WRITE_FAILED: ' . $relative);
            }
        } catch (Throwable $error) {
            if (!unlink($path)) {
                throw new RuntimeException('RECIPE_RECOVERY_REQUIRED', 0, $error);
            }
            throw $error;
        } finally {
            fclose($handle);
        }
    }

    private function removeStage(string $path): void
    {
        foreach (scandir($path) ?: [] as $name) {
            if ($name === '.' || $name === '..') {
                continue;
            }
            $child = ScaffoldPathGuard::projectPath($path, $name);
            if (is_dir($child)) {
                $this->removeStage($child);
            } elseif (!unlink($child)) {
                throw new RuntimeException('RECIPE_RECOVERY_REQUIRED');
            }
        }
        if (!rmdir($path)) {
            throw new RuntimeException('RECIPE_RECOVERY_REQUIRED');
        }
    }
}
