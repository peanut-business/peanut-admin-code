#!/usr/bin/env php
<?php

declare(strict_types=1);

final class PeanutServerUpdatePlan
{
    private const IDENTITY = 'server/.peanut/release-identity.json';
    private const PLAN_PROTOCOL = 'peanut.server-update-plan.v1';
    private const JOURNAL_PROTOCOL = 'peanut.server-update-journal.v1';
    private const MAINTENANCE_PROTOCOL = 'peanut.server-update-maintenance.v1';
    private const RUNTIME_DIR = 'runtime/upgrade';

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
        if (count($argv) < 2 || !in_array($argv[1] ?? '', ['plan', 'apply', 'recover'], true)) {
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
        $command = $argv[1];
        $requiredOptions = $command === 'plan'
            ? ['instance-server', 'archive', 'expected-sha256', 'workspace']
            : ['instance-server', 'workspace'];
        foreach ($requiredOptions as $required) {
            if (!isset($options[$required])) {
                self::usage();
            }
        }
        if ($command !== 'plan' && (isset($options['archive']) || isset($options['expected-sha256']))) {
            self::usage();
        }

        try {
            $result = match ($command) {
                'plan' => self::build(
                    $options['instance-server'],
                    $options['archive'],
                    $options['expected-sha256'],
                    $options['workspace'],
                ),
                'apply' => self::apply($options['instance-server'], $options['workspace']),
                'recover' => self::recover($options['instance-server'], $options['workspace']),
            };
            echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), PHP_EOL;
            return 0;
        } catch (Throwable $exception) {
            fwrite(STDERR, 'server-update: ' . $exception->getMessage() . PHP_EOL);
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
            'protocol' => self::PLAN_PROTOCOL,
            'status' => 'planned',
            'update_id' => 'server_update_' . gmdate('YmdHis') . '_' . bin2hex(random_bytes(6)),
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
            'prepared_server' => 'prepared/server',
            'operation_count' => count($operations),
            'operations' => $operations,
            'operation_paths_sha256' => hash('sha256', implode("\n", $changedPaths) . "\n"),
        ];

        $planPath = $workspace . '/plan.json';
        if (file_exists($planPath) || is_link($planPath)) {
            throw new RuntimeException('update workspace already contains a plan');
        }
        self::extractPreparedServer($archive, $workspace, $targetMap);
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

    /**
     * @param null|callable(string,string,array<string,mixed>):void $onEvent
     * @return array<string,mixed>
     */
    public static function apply(string $serverRoot, string $workspace, ?callable $onEvent = null): array
    {
        $serverRoot = self::ordinaryDirectory($serverRoot, 'instance server');
        $workspace = self::ordinaryDirectory($workspace, 'update workspace');
        $plan = self::plan($workspace);
        $updateId = self::updateId($plan);
        self::assertPreparedServer($workspace, $plan);

        if (($plan['requirements']['database_migrations_changed'] ?? null) === true) {
            throw new RuntimeException('database migration gate is not implemented for server update apply');
        }
        if (($plan['requirements']['composer_dependencies_changed'] ?? null) === true) {
            throw new RuntimeException('composer dependency preparation gate is not implemented for server update apply');
        }

        return self::withInstanceLock($serverRoot, static function () use ($serverRoot, $workspace, $plan, $updateId, $onEvent): array {
            $currentPointer = self::currentPointerPath($serverRoot);
            if (file_exists($currentPointer) || is_link($currentPointer)) {
                $current = self::jsonFile($currentPointer, 'current server update pointer');
                if (in_array($current['status'] ?? null, ['completed', 'recovered'], true)) {
                    @unlink($currentPointer);
                } else {
                    throw new RuntimeException('another server update is already active or requires recovery');
                }
            }

            $journalPath = self::journalPath($workspace);
            if (file_exists($journalPath) || is_link($journalPath)) {
                $existing = self::journal($workspace);
                if (($existing['status'] ?? null) === 'completed') {
                    return $existing;
                }
                throw new RuntimeException('update workspace already has an unfinished journal; run recover before applying again');
            }

            $journal = self::initialJournal($workspace, $plan);
            self::writeJson($journalPath, $journal);
            self::writeJson($currentPointer, [
                'schema_version' => 1,
                'protocol' => 'peanut.server-update-current.v1',
                'update_id' => $updateId,
                'workspace' => $workspace,
                'status' => 'applying',
                'updated_at' => self::now(),
            ]);
            self::writeMaintenance($serverRoot, $plan, 'applying');

            try {
                foreach ($journal['operations'] as $index => $operation) {
                    $operation = self::recordIntent($serverRoot, $workspace, $journal, $index);
                    $journal['operations'][$index] = $operation;
                    self::writeJson($journalPath, $journal);
                    if (($operation['status'] ?? null) === 'done') {
                        continue;
                    }
                    $onEvent !== null && $onEvent('after_intent', (string) $operation['path'], $journal);

                    self::applyOperation($serverRoot, $workspace, $operation);
                    $journal['operations'][$index]['status'] = 'done';
                    $journal['operations'][$index]['completed_at'] = self::now();
                    self::writeJson($journalPath, $journal);
                    $onEvent !== null && $onEvent('after_operation', (string) $operation['path'], $journal);
                }
                $journal['database_migration'] = [
                    'status' => 'skipped',
                    'reason' => 'no database migration changes in this server update plan',
                ];
                $journal['status'] = 'completed';
                $journal['completed_at'] = self::now();
                self::writeJson($journalPath, $journal);
                self::clearMaintenance($serverRoot, $updateId, 'completed');
                self::writeJson($currentPointer, [
                    'schema_version' => 1,
                    'protocol' => 'peanut.server-update-current.v1',
                    'update_id' => $updateId,
                    'workspace' => $workspace,
                    'status' => 'completed',
                    'updated_at' => self::now(),
                ]);
                @unlink($currentPointer);
                return $journal;
            } catch (Throwable $exception) {
                $journal['status'] = 'failed';
                $journal['failed_at'] = self::now();
                $journal['failure'] = ['message' => $exception->getMessage()];
                self::writeJson($journalPath, $journal);
                self::writeJson($currentPointer, [
                    'schema_version' => 1,
                    'protocol' => 'peanut.server-update-current.v1',
                    'update_id' => $updateId,
                    'workspace' => $workspace,
                    'status' => 'failed',
                    'updated_at' => self::now(),
                ]);
                throw $exception;
            }
        });
    }

    /** @return array<string,mixed> */
    public static function recover(string $serverRoot, string $workspace): array
    {
        $serverRoot = self::ordinaryDirectory($serverRoot, 'instance server');
        $workspace = self::ordinaryDirectory($workspace, 'update workspace');
        $plan = self::plan($workspace);
        $updateId = self::updateId($plan);
        $journalPath = self::journalPath($workspace);

        return self::withInstanceLock($serverRoot, static function () use ($serverRoot, $workspace, $updateId, $journalPath): array {
            $journal = self::journal($workspace);
            if (($journal['activation_started'] ?? null) === true) {
                throw new RuntimeException('activation already started; automatic old database recovery is not allowed');
            }
            if (($journal['status'] ?? null) === 'completed') {
                throw new RuntimeException('completed server update cannot be recovered automatically');
            }
            if (($journal['status'] ?? null) === 'recovered') {
                return $journal;
            }

            self::writeMaintenance($serverRoot, $journal, 'recovering');
            try {
                for ($index = count($journal['operations']) - 1; $index >= 0; --$index) {
                    $operation = $journal['operations'][$index];
                    if (($operation['status'] ?? null) === 'pending') {
                        continue;
                    }
                    self::recoverOperation($serverRoot, $operation);
                    $journal['operations'][$index]['recovered_at'] = self::now();
                    self::writeJson($journalPath, $journal);
                }
                $journal['status'] = 'recovered';
                $journal['recovered_at'] = self::now();
                self::writeJson($journalPath, $journal);
                self::clearMaintenance($serverRoot, $updateId, 'recovered');
                @unlink(self::currentPointerPath($serverRoot));
                return $journal;
            } catch (Throwable $exception) {
                $journal['status'] = 'recovery_failed';
                $journal['failed_at'] = self::now();
                $journal['failure'] = ['message' => $exception->getMessage()];
                self::writeJson($journalPath, $journal);
                throw $exception;
            }
        });
    }

    /** @param array<string,array{path:string,sha256:string,mode:int}> $targetMap */
    private static function extractPreparedServer(string $archive, string $workspace, array $targetMap): void
    {
        $prepared = $workspace . '/prepared/server';
        if (file_exists($prepared) || is_link($prepared)) {
            throw new RuntimeException('update workspace already contains prepared server files');
        }
        if (!mkdir($prepared, 0700, true) && !is_dir($prepared)) {
            throw new RuntimeException('cannot create prepared server directory');
        }
        try {
            $phar = new PharData($archive);
        } catch (Throwable $exception) {
            throw new RuntimeException('target archive cannot be reopened for preparation', 0, $exception);
        }
        $prefix = 'phar://' . $archive . '/';
        $iterator = new RecursiveIteratorIterator($phar, RecursiveIteratorIterator::LEAVES_ONLY);
        foreach ($iterator as $file) {
            if (!$file instanceof PharFileInfo || !$file->isFile()) {
                continue;
            }
            $pathname = str_replace('\\', '/', $file->getPathname());
            if (!str_starts_with($pathname, $prefix)) {
                throw new RuntimeException('target archive path is invalid during preparation');
            }
            $parts = explode('/', substr($pathname, strlen($prefix)));
            if (count($parts) < 3 || $parts[1] !== 'server') {
                continue;
            }
            $relative = implode('/', array_slice($parts, 1));
            if (!isset($targetMap[$relative])) {
                continue;
            }
            $target = $workspace . '/prepared/' . $relative;
            if (!is_dir(dirname($target)) && !mkdir(dirname($target), 0700, true) && !is_dir(dirname($target))) {
                throw new RuntimeException('cannot create prepared parent directory: ' . $relative);
            }
            $bytes = $file->getContent();
            if (hash('sha256', $bytes) !== $targetMap[$relative]['sha256']) {
                throw new RuntimeException('prepared file digest changed while extracting: ' . $relative);
            }
            if (file_put_contents($target, $bytes, LOCK_EX) !== strlen($bytes)
                || !chmod($target, $targetMap[$relative]['mode'])) {
                throw new RuntimeException('cannot write prepared file: ' . $relative);
            }
        }
    }

    /** @return array<string,mixed> */
    private static function plan(string $workspace): array
    {
        $plan = self::jsonFile($workspace . '/plan.json', 'server update plan');
        if (($plan['schema_version'] ?? null) !== 1 || ($plan['protocol'] ?? null) !== self::PLAN_PROTOCOL
            || ($plan['status'] ?? null) !== 'planned' || !is_array($plan['operations'] ?? null)
            || !is_array($plan['requirements'] ?? null)) {
            throw new RuntimeException('server update plan is invalid');
        }
        self::updateId($plan);
        return $plan;
    }

    /** @return array<string,mixed> */
    private static function journal(string $workspace): array
    {
        $journal = self::jsonFile(self::journalPath($workspace), 'server update journal');
        if (($journal['schema_version'] ?? null) !== 1 || ($journal['protocol'] ?? null) !== self::JOURNAL_PROTOCOL
            || !is_array($journal['operations'] ?? null) || !is_string($journal['update_id'] ?? null)) {
            throw new RuntimeException('server update journal is invalid');
        }
        return $journal;
    }

    /** @param array<string,mixed> $plan */
    private static function updateId(array $plan): string
    {
        $updateId = $plan['update_id'] ?? null;
        if (!is_string($updateId) || preg_match('/^server_update_[0-9]{14}_[a-f0-9]{12}$/D', $updateId) !== 1) {
            throw new RuntimeException('server update id is invalid');
        }
        return $updateId;
    }

    /** @param array<string,mixed> $plan */
    private static function assertPreparedServer(string $workspace, array $plan): void
    {
        if (($plan['prepared_server'] ?? null) !== 'prepared/server') {
            throw new RuntimeException('server update prepared root is invalid');
        }
        foreach ($plan['operations'] as $operation) {
            if (!is_array($operation) || !is_string($operation['path'] ?? null)) {
                throw new RuntimeException('server update operation is invalid');
            }
            if (($operation['operation'] ?? null) === 'delete') {
                continue;
            }
            $prepared = self::preparedPath($workspace, $operation['path']);
            if (!is_file($prepared) || is_link($prepared)) {
                throw new RuntimeException('prepared server file is unavailable: ' . $operation['path']);
            }
            $digest = hash_file('sha256', $prepared);
            if (!is_string($digest) || !hash_equals((string) $operation['target_sha256'], $digest)) {
                throw new RuntimeException('prepared server file digest differs from plan: ' . $operation['path']);
            }
        }
    }

    /** @param array<string,mixed> $plan @return array<string,mixed> */
    private static function initialJournal(string $workspace, array $plan): array
    {
        $operations = [];
        foreach ($plan['operations'] as $operation) {
            if (!is_array($operation) || !is_string($operation['path'] ?? null)
                || !in_array($operation['operation'] ?? null, ['add', 'replace', 'delete'], true)) {
                throw new RuntimeException('server update operation is invalid');
            }
            $operations[] = [
                'path' => $operation['path'],
                'operation' => $operation['operation'],
                'current_sha256' => $operation['current_sha256'] ?? null,
                'target_sha256' => $operation['target_sha256'] ?? null,
                'target_mode' => $operation['target_mode'] ?? null,
                'backup_path' => null,
                'status' => 'pending',
            ];
        }
        $planDigest = hash_file('sha256', $workspace . '/plan.json');
        if (!is_string($planDigest)) {
            throw new RuntimeException('cannot hash server update plan for journal');
        }

        return [
            'schema_version' => 1,
            'protocol' => self::JOURNAL_PROTOCOL,
            'status' => 'applying',
            'update_id' => self::updateId($plan),
            'maintenance_key' => 'maintenance_' . bin2hex(random_bytes(16)),
            'reason_key' => 'planned-upgrade',
            'plan_sha256' => $planDigest,
            'source' => $plan['source'],
            'target' => $plan['target'],
            'activation_started' => false,
            'operations' => $operations,
            'created_at' => self::now(),
        ];
    }

    /** @param array<string,mixed> $journal @return array<string,mixed> */
    private static function recordIntent(string $serverRoot, string $workspace, array $journal, int $index): array
    {
        $operation = $journal['operations'][$index];
        if (($operation['status'] ?? null) !== 'pending') {
            return $operation;
        }
        $path = (string) $operation['path'];
        self::assertProgramPath($path);
        $actual = self::actualPath($serverRoot, $path);
        $backupPath = null;
        if (file_exists($actual) || is_link($actual)) {
            if (!is_file($actual) || is_link($actual)) {
                throw new RuntimeException('program path is not a regular file before update: ' . $path);
            }
            $digest = hash_file('sha256', $actual);
            if (!is_string($digest)) {
                throw new RuntimeException('cannot hash program path before update: ' . $path);
            }
            $expectedCurrent = $operation['current_sha256'];
            $expectedTarget = $operation['target_sha256'];
            if (is_string($expectedTarget) && hash_equals($expectedTarget, $digest)) {
                $operation['status'] = 'done';
                $operation['completed_at'] = self::now();
                return $operation;
            }
            if (is_string($expectedCurrent) && !hash_equals($expectedCurrent, $digest)) {
                throw new RuntimeException('program path changed after planning: ' . $path);
            }
            $backupPath = self::backupPath($workspace, $path);
            if (!is_dir(dirname($backupPath)) && !mkdir(dirname($backupPath), 0700, true) && !is_dir(dirname($backupPath))) {
                throw new RuntimeException('cannot create backup parent directory: ' . $path);
            }
            if (!copy($actual, $backupPath) || !chmod($backupPath, fileperms($actual) & 0777)) {
                throw new RuntimeException('cannot backup program file: ' . $path);
            }
        } elseif (($operation['operation'] ?? null) !== 'add') {
            if (($operation['operation'] ?? null) === 'delete') {
                $operation['status'] = 'done';
                $operation['completed_at'] = self::now();
                return $operation;
            }
            throw new RuntimeException('program path disappeared after planning: ' . $path);
        }
        $operation['backup_path'] = $backupPath;
        $operation['status'] = 'intent_recorded';
        $operation['intent_recorded_at'] = self::now();
        return $operation;
    }

    /** @param array<string,mixed> $operation */
    private static function applyOperation(string $serverRoot, string $workspace, array $operation): void
    {
        $path = (string) $operation['path'];
        $actual = self::actualPath($serverRoot, $path);
        if (($operation['operation'] ?? null) === 'delete') {
            if (file_exists($actual) || is_link($actual)) {
                if (!is_file($actual) || is_link($actual)) {
                    throw new RuntimeException('program delete target is unsafe: ' . $path);
                }
                if (!unlink($actual)) {
                    throw new RuntimeException('cannot delete program file: ' . $path);
                }
            }
            return;
        }
        $prepared = self::preparedPath($workspace, $path);
        $targetSha = (string) $operation['target_sha256'];
        $digest = hash_file('sha256', $prepared);
        if (!is_string($digest) || !hash_equals($targetSha, $digest)) {
            throw new RuntimeException('prepared file digest differs before update: ' . $path);
        }
        if (!is_dir(dirname($actual)) && !mkdir(dirname($actual), 0700, true) && !is_dir(dirname($actual))) {
            throw new RuntimeException('cannot create target parent directory: ' . $path);
        }
        $temporary = dirname($actual) . '/.server-update-' . bin2hex(random_bytes(8)) . '.tmp';
        if (!copy($prepared, $temporary) || !chmod($temporary, (int) $operation['target_mode'])) {
            @unlink($temporary);
            throw new RuntimeException('cannot stage program file: ' . $path);
        }
        $temporaryDigest = hash_file('sha256', $temporary);
        if (!is_string($temporaryDigest) || !hash_equals($targetSha, $temporaryDigest) || !rename($temporary, $actual)) {
            @unlink($temporary);
            throw new RuntimeException('cannot publish program file atomically: ' . $path);
        }
    }

    /** @param array<string,mixed> $operation */
    private static function recoverOperation(string $serverRoot, array $operation): void
    {
        $path = (string) $operation['path'];
        self::assertProgramPath($path);
        $actual = self::actualPath($serverRoot, $path);
        $currentSha = is_string($operation['current_sha256'] ?? null) ? $operation['current_sha256'] : null;
        $targetSha = is_string($operation['target_sha256'] ?? null) ? $operation['target_sha256'] : null;
        $actualSha = null;
        if (file_exists($actual) || is_link($actual)) {
            if (!is_file($actual) || is_link($actual)) {
                throw new RuntimeException('program path is unsafe during recovery: ' . $path);
            }
            $actualSha = hash_file('sha256', $actual);
            if (!is_string($actualSha)) {
                throw new RuntimeException('cannot hash program path during recovery: ' . $path);
            }
        }

        if (($operation['operation'] ?? null) === 'add') {
            if ($actualSha === null) {
                return;
            }
            if ($targetSha !== null && hash_equals($targetSha, $actualSha)) {
                if (!unlink($actual)) {
                    throw new RuntimeException('cannot remove added program file during recovery: ' . $path);
                }
                return;
            }
            throw new RuntimeException('added program file changed after failed update: ' . $path);
        }

        if ($currentSha !== null && $actualSha !== null && hash_equals($currentSha, $actualSha)) {
            return;
        }
        if (($operation['operation'] ?? null) === 'delete' && $actualSha !== null) {
            throw new RuntimeException('deleted program file changed after failed update: ' . $path);
        }
        if ($targetSha !== null && $actualSha !== null && !hash_equals($targetSha, $actualSha)) {
            throw new RuntimeException('program file changed after failed update: ' . $path);
        }
        $backup = $operation['backup_path'] ?? null;
        if (!is_string($backup) || $backup === '' || $backup[0] !== '/') {
            throw new RuntimeException('program backup path is invalid during recovery: ' . $path);
        }
        if (!is_file($backup) || is_link($backup)) {
            throw new RuntimeException('program backup is unavailable during recovery: ' . $path);
        }
        if (!is_dir(dirname($actual)) && !mkdir(dirname($actual), 0700, true) && !is_dir(dirname($actual))) {
            throw new RuntimeException('cannot recreate target parent during recovery: ' . $path);
        }
        $temporary = dirname($actual) . '/.server-recover-' . bin2hex(random_bytes(8)) . '.tmp';
        if (!copy($backup, $temporary) || !chmod($temporary, fileperms($backup) & 0777)) {
            @unlink($temporary);
            throw new RuntimeException('cannot stage recovered program file: ' . $path);
        }
        $restoredSha = hash_file('sha256', $temporary);
        if (!is_string($restoredSha) || $currentSha === null || !hash_equals($currentSha, $restoredSha)
            || !rename($temporary, $actual)) {
            @unlink($temporary);
            throw new RuntimeException('cannot restore program file during recovery: ' . $path);
        }
    }

    /** @return array<string,mixed> */
    private static function withInstanceLock(string $serverRoot, callable $callback): array
    {
        $runtime = self::ensureRuntimeDirectory($serverRoot);
        $lock = fopen($runtime . '/update.lock', 'c');
        if (!is_resource($lock)) {
            throw new RuntimeException('cannot open server update lock');
        }
        if (!flock($lock, LOCK_EX | LOCK_NB)) {
            fclose($lock);
            throw new RuntimeException('another server update process holds the instance lock');
        }
        try {
            return $callback();
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    private static function ensureRuntimeDirectory(string $serverRoot): string
    {
        $runtimeRoot = $serverRoot . '/runtime';
        if (is_link($runtimeRoot) || (file_exists($runtimeRoot) && !is_dir($runtimeRoot))) {
            throw new RuntimeException('server runtime directory is unsafe');
        }
        if (!is_dir($runtimeRoot) && !mkdir($runtimeRoot, 0700) && !is_dir($runtimeRoot)) {
            throw new RuntimeException('cannot create server runtime directory');
        }
        $runtime = $serverRoot . '/' . self::RUNTIME_DIR;
        if (is_link($runtime) || (file_exists($runtime) && !is_dir($runtime))) {
            throw new RuntimeException('server update runtime directory is unsafe');
        }
        if (!is_dir($runtime) && !mkdir($runtime, 0700) && !is_dir($runtime)) {
            throw new RuntimeException('cannot create server update runtime directory');
        }
        return $runtime;
    }

    /** @param array<string,mixed> $plan */
    private static function writeMaintenance(string $serverRoot, array $plan, string $phase): void
    {
        $updateId = self::updateId($plan);
        $maintenanceKey = is_string($plan['maintenance_key'] ?? null)
            ? $plan['maintenance_key']
            : 'maintenance_' . substr(hash('sha256', $updateId), 0, 32);
        $path = self::maintenancePath($serverRoot);
        $existing = null;
        if (file_exists($path) || is_link($path)) {
            $existing = self::jsonFile($path, 'server update maintenance marker');
            if (($existing['protocol'] ?? null) !== self::MAINTENANCE_PROTOCOL
                || ($existing['update_id'] ?? null) !== $updateId) {
                throw new RuntimeException('another maintenance marker is active');
            }
        }
        self::writeJson($path, [
            'schema_version' => 1,
            'protocol' => self::MAINTENANCE_PROTOCOL,
            'status' => 'active',
            'phase' => $phase,
            'update_id' => $updateId,
            'maintenance_key' => $existing['maintenance_key'] ?? $maintenanceKey,
            'reason_key' => 'planned-upgrade',
            'updated_at' => self::now(),
        ]);
    }

    private static function clearMaintenance(string $serverRoot, string $updateId, string $finalStatus): void
    {
        $path = self::maintenancePath($serverRoot);
        if (!file_exists($path) && !is_link($path)) {
            return;
        }
        $marker = self::jsonFile($path, 'server update maintenance marker');
        if (($marker['protocol'] ?? null) !== self::MAINTENANCE_PROTOCOL
            || ($marker['update_id'] ?? null) !== $updateId
            || ($marker['status'] ?? null) !== 'active') {
            throw new RuntimeException('maintenance marker does not belong to this server update');
        }
        if (!in_array($finalStatus, ['completed', 'recovered'], true)) {
            throw new RuntimeException('maintenance marker clear status is invalid');
        }
        if (!unlink($path)) {
            throw new RuntimeException('cannot clear server update maintenance marker');
        }
    }

    /** @return array<string,mixed> */
    private static function jsonFile(string $path, string $label): array
    {
        if (!is_file($path) || is_link($path)) {
            throw new RuntimeException($label . ' is unavailable');
        }
        $bytes = file_get_contents($path);
        if (!is_string($bytes)) {
            throw new RuntimeException($label . ' cannot be read');
        }
        $value = json_decode($bytes, true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($value)) {
            throw new RuntimeException($label . ' must be a JSON object');
        }
        return $value;
    }

    /** @param array<string,mixed> $data */
    private static function writeJson(string $path, array $data): void
    {
        if (is_link($path)) {
            throw new RuntimeException('refusing to overwrite a symbolic link: ' . $path);
        }
        if (!is_dir(dirname($path)) && !mkdir(dirname($path), 0700, true) && !is_dir(dirname($path))) {
            throw new RuntimeException('cannot create parent directory: ' . dirname($path));
        }
        $temporary = dirname($path) . '/.' . basename($path) . '-' . bin2hex(random_bytes(8)) . '.tmp';
        $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . "\n";
        if (file_put_contents($temporary, $json, LOCK_EX) !== strlen($json)
            || !chmod($temporary, 0600)
            || !rename($temporary, $path)) {
            @unlink($temporary);
            throw new RuntimeException('cannot write JSON file: ' . $path);
        }
    }

    private static function actualPath(string $serverRoot, string $path): string
    {
        self::assertProgramPath($path);
        return $serverRoot . '/' . substr($path, strlen('server/'));
    }

    private static function preparedPath(string $workspace, string $path): string
    {
        self::assertProgramPath($path);
        return $workspace . '/prepared/' . $path;
    }

    private static function backupPath(string $workspace, string $path): string
    {
        self::assertProgramPath($path);
        return $workspace . '/backups/' . $path;
    }

    private static function journalPath(string $workspace): string
    {
        return $workspace . '/journal.json';
    }

    private static function currentPointerPath(string $serverRoot): string
    {
        return $serverRoot . '/' . self::RUNTIME_DIR . '/current-update.json';
    }

    private static function maintenancePath(string $serverRoot): string
    {
        return $serverRoot . '/' . self::RUNTIME_DIR . '/maintenance.json';
    }

    private static function now(): string
    {
        return gmdate('Y-m-d\TH:i:s\Z');
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
