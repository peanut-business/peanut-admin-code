<?php

declare(strict_types=1);

namespace app\common\value\scaffold;

use RuntimeException;

/** One identity grammar for source snapshots, manifests and upgrade seals. */
final class ScaffoldSourceIdentity
{
    private const EXCLUDED_NAMES = ['.git', '.local', '.ops', 'vendor', 'node_modules', '.nuxt', '.cache', 'coverage'];
    private const EXCLUDED_PATHS = ['backups', 'release', 'releases', 'output', 'evidence', 'updates', '.peanut',
        'scaffold/releases', 'server/runtime', 'server/private/installation', 'server/private/resources',
        'server/private/storage', 'server/private/collaboration', 'server/public/uploads', 'server/public/storage',
        'server/public/admin', 'server/public/platform', 'server/public/pc', 'server/public/mobile',
        'server/docker/mysql', 'server/docker/secrets', 'server/docker/tls'];

    /** Legacy Git projections are accepted only when no union was declared. */
    public static function from(array $value, bool $generation = false): array
    {
        $commitKey = $generation ? 'commit' : 'source_commit';
        $treeKey = $generation ? 'tree' : 'source_tree';
        $identity = $value['source_identity'] ?? null;
        if (array_key_exists('source_identity', $value) && !is_array($identity)) {
            throw new RuntimeException('SCAFFOLD_SOURCE_IDENTITY_INVALID');
        }
        if ($identity === null) {
            $identity = ['kind' => 'git', 'commit' => $value[$commitKey] ?? null, 'tree' => $value[$treeKey] ?? null];
        }
        $identity = self::validate($identity);
        if ($identity['kind'] === 'local') {
            if (array_intersect(['source_commit', 'source_tree', 'commit', 'tree'], array_keys($value)) !== []) {
                throw new RuntimeException('SCAFFOLD_SOURCE_IDENTITY_MIXED');
            }
        } elseif (($value[$commitKey] ?? null) !== $identity['commit'] || ($value[$treeKey] ?? null) !== $identity['tree']) {
            throw new RuntimeException('SCAFFOLD_SOURCE_IDENTITY_MISMATCH');
        }
        return $identity;
    }

    public static function validate(array $identity): array
    {
        $keys = array_keys($identity);
        sort($keys, SORT_STRING);
        if (($identity['kind'] ?? null) === 'git' && $keys === ['commit', 'kind', 'tree']
            && preg_match('/^[a-f0-9]{40}$/D', (string) ($identity['commit'] ?? '')) === 1
            && preg_match('/^[a-f0-9]{40}$/D', (string) ($identity['tree'] ?? '')) === 1) {
            return ['kind' => 'git', 'commit' => $identity['commit'], 'tree' => $identity['tree']];
        }
        if (($identity['kind'] ?? null) === 'local' && $keys === ['kind', 'snapshot_sha256']
            && preg_match('/^[a-f0-9]{64}$/D', (string) ($identity['snapshot_sha256'] ?? '')) === 1) {
            return ['kind' => 'local', 'snapshot_sha256' => $identity['snapshot_sha256']];
        }
        throw new RuntimeException('SCAFFOLD_SOURCE_IDENTITY_INVALID');
    }

    public static function fields(array $identity, bool $generation = false): array
    {
        $identity = self::validate($identity);
        return ['source_identity' => $identity] + ($identity['kind'] === 'git'
            ? [($generation ? 'commit' : 'source_commit') => $identity['commit'],
                ($generation ? 'tree' : 'source_tree') => $identity['tree']] : []);
    }

    /** @return list<string> Source-relative regular files, with excluded directories pruned before descent. */
    public static function files(string $root): array
    {
        $resolved = realpath($root);
        if ($resolved === false || !is_dir($resolved)) { throw new RuntimeException('SCAFFOLD_SOURCE_SNAPSHOT_INVALID'); }
        $files = [];
        $configured = [];
        $configPath = $resolved . '/.local/toolchain/host.json';
        if (is_file($configPath)) {
            $config = json_decode((string) file_get_contents($configPath), true, 512, JSON_THROW_ON_ERROR);
            foreach (['release', 'backups'] as $key) {
                $value = $config['paths'][$key] ?? null;
                if (!is_string($value) || $value === '') { continue; }
                $candidate = realpath(str_starts_with($value, '/') ? $value : $resolved . '/' . $value);
                if ($candidate === $resolved) { throw new RuntimeException('SCAFFOLD_SOURCE_OUTPUT_ROOT_CONFLICT'); }
                if (is_string($candidate) && str_starts_with($candidate, $resolved . '/') && is_dir($candidate)) {
                    $configured[] = substr($candidate, strlen($resolved) + 1);
                }
            }
        }
        $visit = static function (string $directory, string $prefix = '') use (&$visit, &$files, $resolved, $configured): void {
            $entries = scandir($directory);
            if ($entries === false) { throw new RuntimeException('SCAFFOLD_SOURCE_SNAPSHOT_READ_FAILED'); }
            foreach ($entries as $name) {
                if ($name === '.' || $name === '..') { continue; }
                $relative = $prefix . $name;
                if (preg_match('/[\x00-\x1f\x7f\\\\]/', $relative) === 1) { throw new RuntimeException('SCAFFOLD_SOURCE_SNAPSHOT_PATH_INVALID'); }
                $absolute = $resolved . '/' . $relative;
                if (self::excluded($relative, is_dir($absolute) && !is_link($absolute), $configured)) { continue; }
                if (is_link($absolute)) { throw new RuntimeException('SCAFFOLD_SOURCE_SNAPSHOT_SYMLINK: ' . $relative); }
                if (is_dir($absolute)) { $visit($absolute, $relative . '/'); continue; }
                if (!is_file($absolute)) { throw new RuntimeException('SCAFFOLD_SOURCE_SNAPSHOT_TYPE_INVALID: ' . $relative); }
                $files[] = $relative;
            }
        };
        $visit($resolved);
        sort($files, SORT_STRING);
        return $files;
    }

    /** SHA-256(sorted(path NUL octal-mode NUL file-sha256 LF)); bytes match the CLI. */
    public static function local(string $root): array
    {
        $resolved = realpath($root);
        $hash = hash_init('sha256');
        foreach (self::files($root) as $relative) {
            $absolute = $resolved . '/' . $relative;
            $mode = (fileperms($absolute) & 0111) !== 0 ? 0755 : 0644;
            $digest = hash_file('sha256', $absolute);
            if (!is_string($digest)) { throw new RuntimeException('SCAFFOLD_SOURCE_SNAPSHOT_READ_FAILED'); }
            hash_update($hash, $relative . "\0" . decoct($mode) . "\0" . $digest . "\n");
        }
        return ['kind' => 'local', 'snapshot_sha256' => hash_final($hash)];
    }

    /** Portable proof of the canonical source index and the two selection inputs. */
    public static function proof(string $root): array
    {
        $root = (string) realpath($root);
        $rows = [];
        foreach (self::files($root) as $path) {
            $rows[] = ['path' => $path, 'mode' => (fileperms($root . '/' . $path) & 0111) !== 0 ? 0755 : 0644,
                'sha256' => hash_file('sha256', $root . '/' . $path)];
        }
        $proof = ['protocol' => 'peanut.local-source-snapshot.v1', 'source_identity' => self::local($root),
            'files' => $rows, 'inventory_base64' => base64_encode((string) file_get_contents($root . '/scaffold/application-template-inventory.json')),
            'edition_profile_base64' => base64_encode((string) file_get_contents($root . '/scaffold/edition-profiles.json'))];
        self::validateProof($proof, $proof['source_identity']);
        return $proof;
    }

    /** @return array{inventory:array,edition_profiles:array} */
    public static function validateProof(array $proof, array $identity): array
    {
        if (($proof['protocol'] ?? null) !== 'peanut.local-source-snapshot.v1'
            || ($proof['source_identity'] ?? null) !== self::validate($identity) || $identity['kind'] !== 'local'
            || !is_array($proof['files'] ?? null)) { throw new RuntimeException('SCAFFOLD_LOCAL_SOURCE_PROOF_INVALID'); }
        $rows = []; $previous = null; $hash = hash_init('sha256');
        foreach ($proof['files'] as $file) {
            $path = $file['path'] ?? null;
            if (!is_string($path) || $path === '' || strlen($path) > 4096 || count($rows) >= 100000
                || preg_match('/[\x00-\x1f\x7f\\\\]/', $path) === 1
                || str_starts_with($path, '/') || array_intersect(explode('/', $path), ['', '.', '..']) !== []
                || !in_array($file['mode'] ?? null, [0644, 0755], true)
                || preg_match('/^[a-f0-9]{64}$/D', (string) ($file['sha256'] ?? '')) !== 1
                || ($previous !== null && strcmp($previous, $path) >= 0)) { throw new RuntimeException('SCAFFOLD_LOCAL_SOURCE_PROOF_INVALID'); }
            $rows[$path] = $file; $previous = $path;
            hash_update($hash, $path . "\0" . decoct($file['mode']) . "\0" . $file['sha256'] . "\n");
        }
        if (hash_final($hash) !== $identity['snapshot_sha256']) { throw new RuntimeException('SCAFFOLD_LOCAL_SOURCE_PROOF_CHANGED'); }
        $decoded = [];
        foreach (['inventory' => 'scaffold/application-template-inventory.json', 'edition_profile' => 'scaffold/edition-profiles.json'] as $key => $path) {
            $encoded = $proof[$key . '_base64'] ?? null;
            $bytes = is_string($encoded) && strlen($encoded) <= 16777216 ? base64_decode($encoded, true) : false;
            if (!is_string($bytes) || base64_encode($bytes) !== $encoded || !isset($rows[$path])
                || hash('sha256', $bytes) !== $rows[$path]['sha256']) { throw new RuntimeException('SCAFFOLD_LOCAL_SOURCE_INPUT_CHANGED'); }
            $document = json_decode($bytes, true, 512, JSON_THROW_ON_ERROR);
            if (!is_array($document)) { throw new RuntimeException('SCAFFOLD_LOCAL_SOURCE_PROOF_INVALID'); }
            $decoded[$key === 'inventory' ? 'inventory' : 'edition_profiles'] = $document;
        }
        $inventory = $decoded['inventory'];
        if (($inventory['protocol'] ?? null) !== 'peanut.create-app-inventory.v2' || !is_array($inventory['files'] ?? null)) {
            throw new RuntimeException('SCAFFOLD_LOCAL_SOURCE_INVENTORY_INVALID');
        }
        $seen = [];
        foreach ($inventory['files'] as $entry) {
            $path = $entry['path'] ?? null;
            if (!is_string($path) || isset($seen[$path])) { throw new RuntimeException('SCAFFOLD_LOCAL_SOURCE_INVENTORY_INVALID'); }
            $seen[$path] = true;
            if (($entry['classification'] ?? null) === 'excluded') { continue; }
            $semantic = !str_starts_with($path, 'server/resources/scaffold-application/')
                && in_array($entry['transform'] ?? null, ['changelog', 'release-metadata', 'resources', 'readme', 'docs-page', 'version-contract'], true);
            $expected = $semantic ? hash('sha256', "peanut.create-app-semantic-source.v1\0{$path}\0{$entry['transform']}") : ($rows[$path]['sha256'] ?? null);
            if (!isset($rows[$path]) || ($entry['source_sha256'] ?? null) !== $expected
                || ($entry['mode'] ?? null) !== $rows[$path]['mode']) { throw new RuntimeException('SCAFFOLD_LOCAL_SOURCE_INVENTORY_CHANGED'); }
        }
        return $decoded;
    }

    private static function excluded(string $path, bool $directory, array $configured): bool
    {
        $name = basename($path);
        if (in_array($name, self::EXCLUDED_NAMES, true)) { return true; }
        foreach ([...self::EXCLUDED_PATHS, ...$configured] as $prefix) {
            if ($path === $prefix || str_starts_with($path, $prefix . '/')) { return true; }
        }
        if ($directory) { return $name === 'dist'; }
        $envTemplate = $name === '.env.example' || preg_match('/^\.env\.[A-Za-z0-9_-]+\.example$/D', $name) === 1;
        return (!$envTemplate && ($name === '.env' || str_starts_with($name, '.env.')))
            || preg_match('/(?:^|\.)secret(?:\.|$)/i', $name) === 1
            || in_array($name, ['.DS_Store', 'Thumbs.db'], true) || preg_match('/\.(?:sqlite3?|db|log)$/i', $name) === 1
            || $path === 'server/docker/collaboration.env';
    }
}
