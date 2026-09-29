#!/usr/bin/env php
<?php

declare(strict_types=1);

final class PeanutServerUpdatePlan
{
    private const IDENTITY = 'server/.peanut/release-identity.json';

    /** @var list<string> */
    private const PROTECTED_PREFIXES = [
        'server/runtime/',
        'server/public/storage/',
        'server/public/uploads/',
        'server/private/storage/',
        'server/private/installation/',
        'server/private/resources/',
        'server/docker/mysql/',
        'server/docker/secrets/',
        'server/vendor/',
        'server/.git/',
    ];

    /** @var list<string> */
    private const PROTECTED_FILES = [
        'server/.env',
        'server/docker/.env',
    ];

    /** @param list<string> $argv */
    public static function main(array $argv): int
    {
        if (count($argv) !== 6 || $argv[1] !== 'plan') {
            self::usage();
        }
        $options = [];
        foreach (array_slice($argv, 2) as $argument) {
            if (preg_match('/^--(instance-server|archive|expected-sha256|workspace)=(.+)$/D', $argument, $matches) !== 1
                || isset($options[$matches[1]])) {
                self::usage();
            }
            $options[$matches[1]] = $matches[2];
        }
        foreach (['instance-server', 'archive', 'expected-sha256', 'workspace'] as $required) {
            if (!isset($options[$required])) {
                self::usage();
            }
        }

        try {
            $plan = self::build(
                $options['instance-server'],
                $options['archive'],
                $options['expected-sha256'],
                $options['workspace'],
            );
            echo json_encode($plan, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), PHP_EOL;
            return 0;
        } catch (Throwable $exception) {
            fwrite(STDERR, 'server-update-plan: ' . $exception->getMessage() . PHP_EOL);
            return 1;
        }
    }

    /** @return array<string,mixed> */
    public static function build(string $serverRoot, string $archive, string $expectedSha256, string $workspace): array
    {
        $serverRoot = self::ordinaryDirectory($serverRoot, 'instance server');
        $workspace = self::ordinaryDirectory($workspace, 'update workspace');
        if (!is_file($archive) || is_link($archive) || preg_match('/^[a-f0-9]{64}$/D', $expectedSha256) !== 1) {
            throw new RuntimeException('target archive or trusted SHA-256 is invalid');
        }
        $archiveDigest = hash_file('sha256', $archive);
        if (!is_string($archiveDigest) || !hash_equals($expectedSha256, $archiveDigest)) {
            throw new RuntimeException('target archive differs from trusted SHA-256');
        }

        $currentBytes = self::regularBytes($serverRoot . '/.peanut/release-identity.json', 'current server release identity');
        $current = self::identity(json_decode($currentBytes, true, 512, JSON_THROW_ON_ERROR), false);
        [$targetBytes, $target, $targetFiles] = self::archive($archive);

        foreach (['slug', 'edition', 'package_identity'] as $field) {
            if (!hash_equals((string) $current['application'][$field], (string) $target['application'][$field])) {
                throw new RuntimeException('target release belongs to another application or edition');
            }
        }
        if ($current['application']['version'] === $target['application']['version']
            && !hash_equals(hash('sha256', $currentBytes), hash('sha256', $targetBytes))) {
            throw new RuntimeException('target release reuses the current application version with different bytes');
        }

        $currentMap = self::fileMap($current['files']);
        $currentMap[self::IDENTITY] = [
            'path' => self::IDENTITY,
            'sha256' => hash('sha256', $currentBytes),
            'mode' => 0644,
        ];
        $targetMap = self::fileMap($target['files']);
        $targetMap[self::IDENTITY] = [
            'path' => self::IDENTITY,
            'sha256' => hash('sha256', $targetBytes),
            'mode' => $targetFiles[self::IDENTITY]['mode'],
        ];

        $ownedDirectories = self::ownedDirectories(array_keys($currentMap));
        $operations = [];
        foreach ($targetMap as $path => $targetFile) {
            self::assertProgramPath($path);
            $relative = substr($path, strlen('server/'));
            $actualPath = $serverRoot . '/' . $relative;
            self::assertTargetParents($serverRoot, $path, $ownedDirectories, isset($currentMap[$path]));
            $exists = file_exists($actualPath) || is_link($actualPath);
            if (!isset($currentMap[$path]) && $exists) {
                throw new RuntimeException('target path collides with an unknown existing path: ' . $path);
            }
            $currentDigest = null;
            if ($exists) {
                if (!is_file($actualPath) || is_link($actualPath)) {
                    throw new RuntimeException('program path changed to an unsafe file type: ' . $path);
                }
                $currentDigest = hash_file('sha256', $actualPath);
                if (!is_string($currentDigest)) {
                    throw new RuntimeException('cannot hash current program file: ' . $path);
                }
            }
            $operations[] = [
                'path' => $path,
                'operation' => isset($currentMap[$path]) ? 'replace' : 'add',
                'current_present' => $exists,
                'current_sha256' => $currentDigest,
                'target_sha256' => $targetFile['sha256'],
                'target_mode' => $targetFile['mode'],
            ];
        }

        foreach ($currentMap as $path => $currentFile) {
            if (isset($targetMap[$path])) {
                continue;
            }
            self::assertProgramPath($path);
            $relative = substr($path, strlen('server/'));
            $actualPath = $serverRoot . '/' . $relative;
            $exists = file_exists($actualPath) || is_link($actualPath);
            $currentDigest = null;
            if ($exists) {
                if (!is_file($actualPath) || is_link($actualPath)) {
                    throw new RuntimeException('removed program path changed to an unsafe file type: ' . $path);
                }
                $currentDigest = hash_file('sha256', $actualPath);
                if (!is_string($currentDigest)) {
                    throw new RuntimeException('cannot hash removed program file: ' . $path);
                }
            }
            $operations[] = [
                'path' => $path,
                'operation' => 'delete',
                'current_present' => $exists,
                'current_sha256' => $currentDigest,
                'target_sha256' => null,
                'target_mode' => null,
            ];
        }
        usort($operations, static fn(array $left, array $right): int => strcmp($left['path'], $right['path']));

        $changedPaths = array_column($operations, 'path');
        $requirements = [
            'composer_dependencies_changed' => self::operationTouches($operations, 'server/composer.json')
                || self::operationTouches($operations, 'server/composer.lock'),
            'database_migrations_changed' => self::operationPrefix($operations, 'server/database/'),
            'runtime_environment_changed' => self::operationTouches($operations, 'server/docker/Dockerfile')
                || self::operationTouches($operations, 'server/docker/compose.yaml')
                || self::operationPrefix($operations, 'server/docker/conf/'),
        ];

        $plan = [
            'schema_version' => 1,
            'protocol' => 'peanut.server-update-plan.v1',
            'status' => 'planned',
            'source' => [
                'application' => $current['application'],
                'identity_sha256' => hash('sha256', $currentBytes),
            ],
            'target' => [
                'application' => $target['application'],
                'archive_sha256' => $archiveDigest,
                'identity_sha256' => hash('sha256', $targetBytes),
            ],
            'requirements' => $requirements,
            'protected_paths' => [...self::PROTECTED_FILES, ...self::PROTECTED_PREFIXES],
            'operation_count' => count($operations),
            'operations' => $operations,
            'operation_paths_sha256' => hash('sha256', implode("\n", $changedPaths) . "\n"),
        ];

        $planPath = $workspace . '/plan.json';
        if (file_exists($planPath) || is_link($planPath)) {
            throw new RuntimeException('update workspace already contains a plan');
        }
        $temporary = $workspace . '/.plan-' . bin2hex(random_bytes(8));
        $json = json_encode($plan, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . "\n";
        if (file_put_contents($temporary, $json, LOCK_EX) !== strlen($json)
            || !chmod($temporary, 0600)
            || !rename($temporary, $planPath)) {
            @unlink($temporary);
            throw new RuntimeException('cannot publish durable update plan');
        }
        return $plan;
    }

    /** @return array{0:string,1:array<string,mixed>,2:array<string,array{sha256:string,mode:int}>} */
    private static function archive(string $archive): array
    {
        try {
            $phar = new PharData($archive);
        } catch (Throwable $exception) {
            throw new RuntimeException('target archive cannot be opened', 0, $exception);
        }
        $prefix = 'phar://' . $archive . '/';
        $root = null;
        $files = [];
        $identityBytes = null;
        $seen = [];
        $iterator = new RecursiveIteratorIterator($phar, RecursiveIteratorIterator::SELF_FIRST);
        foreach ($iterator as $file) {
            if (!$file instanceof PharFileInfo) {
                throw new RuntimeException('target archive contains an unknown entry');
            }
            $pathname = str_replace('\\', '/', $file->getPathname());
            if (!str_starts_with($pathname, $prefix)) {
                throw new RuntimeException('target archive path is invalid');
            }
            $name = rtrim(substr($pathname, strlen($prefix)), '/');
            if ($name === '') {
                continue;
            }
            $parts = explode('/', $name);
            if (preg_match('/^[a-z0-9][a-z0-9.-]*$/D', $parts[0]) !== 1
                || in_array('', $parts, true)
                || in_array('.', $parts, true) || in_array('..', $parts, true)
                || preg_match('/[\x00-\x1f\\\\]/', $name) === 1) {
                throw new RuntimeException('target archive contains an unsafe path');
            }
            $root ??= $parts[0];
            if ($root !== $parts[0]) {
                throw new RuntimeException('target archive contains multiple roots');
            }
            if (count($parts) === 1) {
                if (!$file->isDir()) {
                    throw new RuntimeException('target archive root must be a directory');
                }
                continue;
            }
            if ($parts[1] !== 'server') {
                throw new RuntimeException('target archive contains a non-server entry');
            }
            $folded = strtolower($name);
            if (isset($seen[$folded])) {
                throw new RuntimeException('target archive contains a duplicate or case-colliding path');
            }
            $seen[$folded] = true;
            if ($file->isLink() || (!$file->isDir() && !$file->isFile())) {
                throw new RuntimeException('target archive contains a link or special file');
            }
            if (!$file->isFile()) {
                continue;
            }
            $relative = implode('/', array_slice($parts, 1));
            $bytes = $file->getContent();
            $mode = $file->getPerms() & 0777;
            $files[$relative] = ['sha256' => hash('sha256', $bytes), 'mode' => $mode];
            if ($relative === self::IDENTITY) {
                $identityBytes = $bytes;
            }
        }
        if (!is_string($identityBytes)) {
            throw new RuntimeException('target archive lacks server release identity');
        }
        $identity = self::identity(json_decode($identityBytes, true, 512, JSON_THROW_ON_ERROR), true);
        $declared = self::fileMap($identity['files']);
        $actual = $files;
        unset($actual[self::IDENTITY]);
        $actualComparable = [];
        foreach ($actual as $path => $row) {
            $actualComparable[$path] = ['path' => $path, 'sha256' => $row['sha256'], 'mode' => $row['mode']];
        }
        if ($declared !== $actualComparable) {
            throw new RuntimeException('target archive files differ from release identity');
        }
        return [$identityBytes, $identity, $files];
    }

    /** @param mixed $value @return array<string,mixed> */
    private static function identity(mixed $value, bool $target): array
    {
        if (!is_array($value) || ($value['schema_version'] ?? null) !== 1
            || ($value['protocol'] ?? null) !== 'peanut.server-release.v1'
            || !is_array($value['application'] ?? null) || !is_array($value['files'] ?? null)) {
            throw new RuntimeException(($target ? 'target' : 'current') . ' server release identity is invalid');
        }
        $application = $value['application'];
        foreach (['slug', 'edition', 'version', 'package_identity'] as $field) {
            if (!is_string($application[$field] ?? null) || $application[$field] === '') {
                throw new RuntimeException(($target ? 'target' : 'current') . ' application identity is incomplete');
            }
        }
        if (!in_array($application['edition'], ['standalone', 'multi-tenant'], true)) {
            throw new RuntimeException(($target ? 'target' : 'current') . ' edition is invalid');
        }
        $files = self::fileMap($value['files']);
        $canonical = json_encode(array_values($files), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        if ($files === [] || !is_string($value['files_sha256'] ?? null)
            || !hash_equals($value['files_sha256'], hash('sha256', $canonical))) {
            throw new RuntimeException(($target ? 'target' : 'current') . ' server file list digest is invalid');
        }
        return $value;
    }

    /** @param mixed $rows @return array<string,array{path:string,sha256:string,mode:int}> */
    private static function fileMap(mixed $rows): array
    {
        if (!is_array($rows) || !array_is_list($rows)) {
            throw new RuntimeException('server file list must be an array');
        }
        $result = [];
        $previous = '';
        foreach ($rows as $row) {
            if (!is_array($row) || array_keys($row) !== ['path', 'sha256', 'mode']
                || !is_string($row['path']) || !str_starts_with($row['path'], 'server/')
                || $row['path'] <= $previous || isset($result[$row['path']])
                || preg_match('/^[a-f0-9]{64}$/D', (string) ($row['sha256'] ?? '')) !== 1
                || !in_array($row['mode'] ?? null, [0644, 0755], true)) {
                throw new RuntimeException('server file list contains an invalid row');
            }
            self::safePath($row['path']);
            self::assertProgramPath($row['path']);
            $previous = $row['path'];
            $result[$row['path']] = $row;
        }
        return $result;
    }

    private static function assertProgramPath(string $path): void
    {
        self::safePath($path);
        if (in_array($path, self::PROTECTED_FILES, true)) {
            throw new RuntimeException('release identity targets a protected instance file: ' . $path);
        }
        foreach (self::PROTECTED_PREFIXES as $prefix) {
            if (str_starts_with($path, $prefix)) {
                throw new RuntimeException('release identity targets protected instance data: ' . $path);
            }
        }
    }

    private static function safePath(string $path): void
    {
        if (!str_starts_with($path, 'server/') || str_contains($path, '\\')
            || preg_match('/[\x00-\x1f]/', $path) === 1
            || in_array('', explode('/', $path), true)
            || in_array('.', explode('/', $path), true)
            || in_array('..', explode('/', $path), true)) {
            throw new RuntimeException('unsafe server path: ' . $path);
        }
    }

    /** @param list<string> $paths @return array<string,bool> */
    private static function ownedDirectories(array $paths): array
    {
        $owned = ['server' => true];
        foreach ($paths as $path) {
            $parts = explode('/', $path);
            array_pop($parts);
            while (count($parts) > 1) {
                $owned[implode('/', $parts)] = true;
                array_pop($parts);
            }
        }
        return $owned;
    }

    /** @param array<string,bool> $ownedDirectories */
    private static function assertTargetParents(string $serverRoot, string $path, array $ownedDirectories, bool $existingProgram): void
    {
        $parts = explode('/', substr($path, strlen('server/')));
        array_pop($parts);
        $cursor = $serverRoot;
        $relative = 'server';
        foreach ($parts as $part) {
            $cursor .= '/' . $part;
            $relative .= '/' . $part;
            if (is_link($cursor)) {
                throw new RuntimeException('target parent contains a symbolic link: ' . $path);
            }
            if (file_exists($cursor) && !is_dir($cursor)) {
                throw new RuntimeException('target parent conflicts with a file: ' . $path);
            }
            if (is_dir($cursor) && !$existingProgram && !isset($ownedDirectories[$relative])) {
                throw new RuntimeException('target parent is an unknown existing directory: ' . $relative);
            }
        }
    }

    /** @param list<array<string,mixed>> $operations */
    private static function operationTouches(array $operations, string $path): bool
    {
        foreach ($operations as $operation) {
            if ($operation['path'] === $path
                && ($operation['operation'] !== 'replace'
                    || !is_string($operation['current_sha256'])
                    || !hash_equals($operation['current_sha256'], (string) $operation['target_sha256']))) {
                return true;
            }
        }
        return false;
    }

    /** @param list<array<string,mixed>> $operations */
    private static function operationPrefix(array $operations, string $prefix): bool
    {
        foreach ($operations as $operation) {
            if (str_starts_with($operation['path'], $prefix)
                && ($operation['operation'] !== 'replace'
                    || !is_string($operation['current_sha256'])
                    || !hash_equals($operation['current_sha256'], (string) $operation['target_sha256']))) {
                return true;
            }
        }
        return false;
    }

    private static function ordinaryDirectory(string $path, string $label): string
    {
        if ($path === '' || $path[0] !== '/' || is_link($path)) {
            throw new RuntimeException($label . ' must be an absolute ordinary directory');
        }
        $real = realpath($path);
        if (!is_string($real) || $real !== $path || !is_dir($real)) {
            throw new RuntimeException($label . ' must be a canonical ordinary directory');
        }
        return $real;
    }

    private static function regularBytes(string $path, string $label): string
    {
        if (!is_file($path) || is_link($path)) {
            throw new RuntimeException($label . ' is unavailable');
        }
        $stat = lstat($path);
        if (!is_array($stat) || ($stat['nlink'] ?? 0) !== 1) {
            throw new RuntimeException($label . ' must have one hard link');
        }
        $bytes = file_get_contents($path);
        if (!is_string($bytes)) {
            throw new RuntimeException($label . ' cannot be read');
        }
        return $bytes;
    }

    private static function usage(): never
    {
        fwrite(STDERR, "Usage: php update-plan.php plan --instance-server=/absolute/server --archive=/absolute/server.tar.gz --expected-sha256=<trusted-64-hex> --workspace=/absolute/update-workspace\n");
        exit(64);
    }
}

if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    exit(PeanutServerUpdatePlan::main($_SERVER['argv'] ?? []));
}
