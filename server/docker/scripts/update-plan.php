#!/usr/bin/env php
<?php

declare(strict_types=1);

final class PeanutServerUpdatePlan
{
    private const IDENTITY = 'server/.peanut/release-identity.json';
    private const PLAN_PROTOCOL = 'peanut.server-release-inputs.v1';
    private const JOURNAL_PROTOCOL = 'peanut.server-file-intents.v1';
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
        if (count($argv) < 2 || !in_array($argv[1] ?? '', [
            'plan',
            'files-apply', 'files-verify', 'files-recover', 'files-verify-recovery',
            'hold', 'health', 'publish', 'open',
            'initialize-traffic',
        ], true)) {
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
            : ($command === 'initialize-traffic' ? ['instance-server'] : ['instance-server', 'workspace']);
        foreach ($requiredOptions as $required) {
            if (!isset($options[$required])) {
                self::usage();
            }
        }
        if ($command !== 'plan' && (isset($options['archive']) || isset($options['expected-sha256']))) {
            self::usage();
        }
        if ($command === 'initialize-traffic' && isset($options['workspace'])) {
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
                'files-apply', 'files-verify', 'files-recover', 'files-verify-recovery',
                'hold', 'health', 'publish', 'open' => self::productPhase($options['instance-server'], $options['workspace'], $command),
                'initialize-traffic' => self::initializeTraffic($options['instance-server']),
            };
            echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), PHP_EOL;
            return 0;
        } catch (Throwable $exception) {
            fwrite(STDERR, 'server-update: ' . $exception->getMessage() . PHP_EOL);
            return 1;
        }
    }

    /** Initial startup may grant traffic once; an interrupted update cannot be reopened by a restart. */
    /** Immutable inventory and per-file intents are subordinate to the installed product coordinator. */
    public static function productBinding(string $workspace): array
    {
        $binding = self::jsonFile($workspace . '/product-binding.json', 'product binding');
        $product = self::jsonFile((string) ($binding['plan_path'] ?? ''), 'product plan');
        $digest = $product['plan_sha256'] ?? null;
        unset($product['plan_sha256']);
        $normalize = static function (mixed $value) use (&$normalize): mixed {
            if (!is_array($value)) {
                return $value;
            }
            if (!array_is_list($value)) {
                ksort($value, SORT_STRING);
            }
            foreach ($value as $key => $child) {
                $value[$key] = $normalize($child);
            }
            return $value;
        };
        if (($product['protocol'] ?? null) !== 'peanut.product-upgrade-plan.v2'
            || ($product['workspace'] ?? null) !== $workspace
            || $digest !== ($binding['plan_sha256'] ?? null)
            || !is_string($digest) || !hash_equals($digest, 'sha256:' . hash('sha256', json_encode($normalize($product), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)))) {
            throw new RuntimeException('phase inputs are not bound to the product plan');
        }
        $state = self::jsonFile((string) ($product['state_path'] ?? ''), 'product state');
        $stateDigest = $state['state_sha256'] ?? null;
        unset($state['state_sha256']);
        if (($state['protocol'] ?? null) !== 'peanut.product-upgrade-state.v1'
            || ($state['candidate'] ?? null) !== ($product['candidate'] ?? null)
            || ($state['plan_sha256'] ?? null) !== $digest
            || !is_string($stateDigest) || !hash_equals($stateDigest, 'sha256:' . hash('sha256', json_encode($normalize($state), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)))) {
            throw new RuntimeException('product phase state binding is invalid');
        }
        return [$product + ['plan_sha256' => $digest], $state];
    }

    public static function productPhase(string $serverRoot, string $workspace, string $phase): array
    {
        $serverRoot = self::ordinaryDirectory($serverRoot, 'instance server');
        $workspace = self::ordinaryDirectory($workspace, 'phase workspace');
        [$product, $state] = self::productBinding($workspace);
        if ($serverRoot !== $product['instance_root'] . '/server') {
            throw new RuntimeException('phase server differs from product instance');
        }
        $allowed = match ($phase) {
            'files-apply' => ['managed_files'], 'files-recover' => [null, 'recover'],
            'files-verify', 'files-verify-recovery' => [null, 'health', 'activate', 'recovery-verify'],
            'hold' => ['backup', 'quiesce', 'recover', 'activate', 'resume-old'], 'health' => ['health', 'activate', 'resume-old', 'recovery-verify'],
            'publish' => ['activate'], 'open' => ['activate', 'resume-old'],
        };
        if (!in_array($state['phase_in_progress'] ?? null, $allowed, true)
            || (str_contains($phase, 'recover') && (($state['activation_started'] ?? null) !== false || !isset($state['recovery_context'])))
            || ($phase === 'publish' && ($state['activation_started'] ?? null) !== true)) {
            throw new RuntimeException('native phase is not authorized by product state');
        }
        return self::withInstanceLock($serverRoot, static function () use ($serverRoot, $workspace, $product, $state, $phase): array {
            $id = $product['candidate'];
            self::verificationKey($serverRoot, true);
            if ($phase === 'hold') {
                self::writeMaintenance($serverRoot, ['update_id' => $id], $phase);
                return ['write_gate' => 'closed', 'candidate' => $id];
            }
            if ($phase === 'health') {
                self::nativeRuntimeHealth($serverRoot);
                return ['installed_runtime_health' => 'current', 'candidate' => $id];
            }
            if ($phase === 'open') {
                if (($state['phase_in_progress'] ?? null) === 'activate' && ($state['activation_started'] ?? null) !== true) {
                    throw new RuntimeException('product activation boundary was not persisted');
                }
                self::revokeTraffic($serverRoot);
                self::nativeRuntimeHealth($serverRoot);
                self::grantTraffic($serverRoot);
                self::clearMaintenance($serverRoot, $id, isset($state['recovery_context']) ? 'recovered' : 'completed');
                return ['write_gate' => 'open', 'candidate' => $id];
            }
            if (($product['scope'] ?? null) !== 'server') {
                throw new RuntimeException('server inventory phase requires server scope');
            }
            $input = self::plan($workspace);
            if (hash_file('sha256', $workspace . '/plan.json') !== $product['inputs_sha256']) {
                throw new RuntimeException('server inventory changed after product planning');
            }
            if ($phase === 'files-apply') {
                self::assertMaintenance($serverRoot, $id);
                $backupPath = $workspace . '/backup.json';
                $backupBytes = self::regularBytes($backupPath, 'paired backup');
                $signature = trim(self::regularBytes($backupPath . '.hmac', 'paired backup authentication'));
                $backup = json_decode($backupBytes, true, 256, JSON_THROW_ON_ERROR);
                if (!isset($state['evidence']['backup'], $state['evidence']['quiesce'])
                    || ($backup['product_plan_sha256'] ?? null) !== $product['plan_sha256']
                    || ($backup['plan_sha256'] ?? null) !== $product['inputs_sha256']
                    || !hash_equals(hash_hmac('sha256', $backupBytes, self::verificationKey($serverRoot, false)), $signature)
                    || ($state['evidence']['backup']['payload']['payload']['backup_sha256'] ?? null) !== hash('sha256', $backupBytes)) {
                    throw new RuntimeException('file phase lacks its authenticated paired recovery point');
                }
            }
            if ($phase === 'files-recover' && !isset($state['evidence']['recover'])) {
                throw new RuntimeException('product data recovery must finish before source recovery');
            }
            if ($phase === 'publish') {
                self::publishDeploymentState($serverRoot, $workspace, $input, $id);
                return ['deployment_generation' => $input['source']['deployment_generation'] + 1];
            }
            $path = self::journalPath($workspace);
            if (!file_exists($path) && !is_link($path)) {
                if (in_array($phase, ['files-recover', 'files-verify-recovery'], true)) {
                    self::assertPlanOperations($serverRoot, $workspace, $input);
                    return ['candidate' => $id, 'program_changes' => false];
                }
                if ($phase !== 'files-apply') {
                    throw new RuntimeException('file intents are missing');
                }
                self::assertPreparedServer($workspace, $input);
                self::assertPlanOperations($serverRoot, $workspace, $input);
                $journal = self::initialJournal($workspace, $input);
                $journal['product_plan_sha256'] = $product['plan_sha256'];
                self::writeJson($path, $journal);
            }
            $journal = self::journal($workspace);
            self::assertJournalPlan($workspace, $input, $journal);
            if (($journal['product_plan_sha256'] ?? null) !== $product['plan_sha256']
                || ($journal['plan_sha256'] ?? null) !== $product['inputs_sha256']) {
                throw new RuntimeException('file intents belong to another product plan');
            }
            if ($phase === 'files-apply') {
                self::assertPreparedServer($workspace, $input);
                foreach ($journal['operations'] as $index => $operation) {
                    $operation = self::recordIntent($serverRoot, $workspace, $journal, $index);
                    $journal['operations'][$index] = $operation;
                    self::writeJson($path, $journal);
                    if ($operation['status'] !== 'done') {
                        self::applyOperation($serverRoot, $workspace, $operation);
                        $journal['operations'][$index]['status'] = 'done';
                        self::writeJson($path, $journal);
                    }
                }
            } elseif ($phase === 'files-recover') {
                for ($index = count($journal['operations']) - 1; $index >= 0; --$index) {
                    if ($journal['operations'][$index]['status'] === 'pending') {
                        continue;
                    }
                    self::recoverOperation($serverRoot, $workspace, $journal['operations'][$index]);
                    $journal['operations'][$index]['status'] = 'recovered';
                    self::writeJson($path, $journal);
                }
            } else {
                foreach ($journal['operations'] as $operation) {
                    self::assertOperationState($serverRoot, $operation, $phase === 'files-verify');
                }
            }
            return ['candidate' => $id, 'operation_count' => count($journal['operations']), 'intent_sha256' => hash_file('sha256', $path)];
        });
    }

    public static function initializeTraffic(string $serverRoot): array
    {
        $serverRoot = self::ordinaryDirectory($serverRoot, 'instance server');
        return self::withInstanceLock($serverRoot, static function () use ($serverRoot): array {
            $maintenance = self::maintenancePath($serverRoot);
            $pointer = self::currentPointerPath($serverRoot);
            if (file_exists($maintenance) || is_link($maintenance)
                || file_exists($pointer) || is_link($pointer)) {
                self::revokeTraffic($serverRoot);
                return ['status' => 'closed'];
            }
            try {
                [$identity, $identityDigest, $serverRelease] = self::startupIdentity($serverRoot);
                $installedPath = $serverRoot . '/private/installation/installed.json';
                $deploymentPath = self::deploymentPath($serverRoot);
                if (is_link($installedPath) || is_link($deploymentPath)
                    || (file_exists($installedPath) && !is_file($installedPath))
                    || (file_exists($deploymentPath) && !is_file($deploymentPath))) {
                    throw new RuntimeException('installation state is unsafe for initial traffic');
                }
                if ($serverRelease && file_exists($installedPath)) {
                    self::deploymentState($serverRoot, $identity, $identityDigest);
                } elseif (file_exists($deploymentPath)) {
                    throw new RuntimeException('source or uninstalled instance has server deployment state');
                }
            } catch (Throwable $exception) {
                // A startup failure must not leave an earlier static-page grant usable.
                self::revokeTraffic($serverRoot);
                throw $exception;
            }
            $initialized = self::trafficInitializedPath($serverRoot);
            $ready = self::trafficReadyPath($serverRoot);
            if (file_exists($initialized) || is_link($initialized)) {
                try {
                    self::assertTrafficInitialized($serverRoot);
                } catch (Throwable $exception) {
                    self::removeTrafficReady($serverRoot);
                    throw $exception;
                }
                if (!file_exists($ready) && !is_link($ready)) {
                    return ['status' => 'closed'];
                }
                try {
                    self::assertTrafficReady($serverRoot);
                } catch (Throwable $exception) {
                    self::removeTrafficReady($serverRoot);
                    throw $exception;
                }
                self::removeTrafficReady($serverRoot);
                self::prepareRuntimeAdmission($serverRoot, $serverRelease);
                self::grantTraffic($serverRoot);
                return ['status' => 'open'];
            }
            if (file_exists($ready) || is_link($ready)) {
                self::revokeTraffic($serverRoot);
                throw new RuntimeException('unbound traffic permission exists before initialization');
            }
            self::durableFile($initialized, "peanut.server-traffic-initialized.v1\n", 0600);
            self::prepareRuntimeAdmission($serverRoot, $serverRelease);
            self::grantTraffic($serverRoot);
            return ['status' => 'open'];
        });
    }

    /** @return array{0:array<string,mixed>,1:string,2:bool} */
    private static function startupIdentity(string $serverRoot): array
    {
        $identityPath = $serverRoot . '/.peanut/release-identity.json';
        if (file_exists($identityPath) || is_link($identityPath)) {
            $identityBytes = self::regularBytes($identityPath, 'server release identity');
            return [
                self::identity(json_decode($identityBytes, true, 512, JSON_THROW_ON_ERROR), false),
                hash('sha256', $identityBytes),
                true,
            ];
        }

        $autoload = $serverRoot . '/vendor/autoload.php';
        if (!is_file($autoload) || is_link($autoload)) {
            throw new RuntimeException('source application runtime is missing Composer autoload');
        }
        require_once $autoload;
        $source = \app\common\value\installation\ApplicationSourceIdentity::load($serverRoot);
        return [
            [
                'application' => $source->applicationIdentity(),
                'versions' => $source->versions(),
            ],
            hash('sha256', json_encode([
                'application' => $source->applicationIdentity(),
                'versions' => $source->versions(),
            ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)),
            false,
        ];
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

        self::assertOrdinaryParents($serverRoot, '.peanut/release-identity.json');
        $currentBytes = self::regularBytes($serverRoot . '/.peanut/release-identity.json', 'current server release identity');
        $current = self::identity(json_decode($currentBytes, true, 512, JSON_THROW_ON_ERROR), false);
        self::assertInstalledInstance($serverRoot, $current);
        $deployment = self::deploymentState($serverRoot, $current, hash('sha256', $currentBytes));
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
            $currentMode = null;
            if ($exists) {
                if (!is_file($actualPath) || is_link($actualPath)) {
                    throw new RuntimeException('program path changed to an unsafe file type: ' . $path);
                }
                $currentDigest = hash_file('sha256', $actualPath);
                $currentMode = fileperms($actualPath) & 0777;
                if (!is_string($currentDigest)) {
                    throw new RuntimeException('cannot hash current program file: ' . $path);
                }
                if (isset($currentMap[$path]) && ($currentDigest !== $currentMap[$path]['sha256'] || $currentMode !== $currentMap[$path]['mode'])) {
                    throw new RuntimeException('managed source changed outside its published application release: ' . $path);
                }
            }
            $operations[] = [
                'path' => $path,
                'operation' => isset($currentMap[$path]) ? 'replace' : 'add',
                'current_present' => $exists,
                'current_sha256' => $currentDigest,
                'current_mode' => $currentMode,
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
            $currentMode = null;
            if ($exists) {
                if (!is_file($actualPath) || is_link($actualPath)) {
                    throw new RuntimeException('removed program path changed to an unsafe file type: ' . $path);
                }
                $currentDigest = hash_file('sha256', $actualPath);
                $currentMode = fileperms($actualPath) & 0777;
                if (!is_string($currentDigest)) {
                    throw new RuntimeException('cannot hash removed program file: ' . $path);
                }
                if ($currentDigest !== $currentFile['sha256'] || $currentMode !== $currentFile['mode']) {
                    throw new RuntimeException('removed managed source was customized: ' . $path);
                }
            }
            $operations[] = [
                'path' => $path,
                'operation' => 'delete',
                'current_present' => $exists,
                'current_sha256' => $currentDigest,
                'current_mode' => $currentMode,
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
                || self::operationPrefix(
                    array_values(array_filter(
                        $operations,
                        static fn(array $operation): bool => $operation['path'] !== 'server/docker/conf/nginx.conf',
                    )),
                    'server/docker/conf/',
                ),
            'nginx_configuration_changed' => self::operationTouches($operations, 'server/docker/conf/nginx.conf'),
        ];

        $plan = [
            'schema_version' => 1,
            'protocol' => self::PLAN_PROTOCOL,
            'update_id' => 'server_update_' . gmdate('YmdHis') . '_' . bin2hex(random_bytes(6)),
            'source' => [
                'application' => $current['application'],
                'identity_sha256' => hash('sha256', $currentBytes),
                'installed_receipt_sha256' => $deployment['installed_receipt_sha256'],
                'baseline_sha256' => $deployment['baseline_sha256'],
                'deployment_generation' => $deployment['generation'],
                'deployment_state_sha256' => $deployment['state_sha256'],
            ],
            'target' => [
                'application' => $target['application'],
                'archive_sha256' => $archiveDigest,
                'identity_sha256' => hash('sha256', $targetBytes),
            ],
            'tool_sha256' => hash_file('sha256', __FILE__),
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
        self::writeJson($planPath, $plan);
        return $plan;
    }

    private static function nativeRuntimeHealth(string $serverRoot): void
    {
        $started = hrtime(true);
        $envPath = $serverRoot . '/.env';
        self::regularBytes($envPath, 'installed runtime environment');
        putenv('PEANUT_SERVER_ENV_FILE=' . $envPath);
        chdir($serverRoot);
        self::assertAdmissionBoundary($serverRoot);
        require_once $serverRoot . '/vendor/autoload.php';
        $identity = \app\common\value\installation\ServerReleaseIdentity::load($serverRoot);
        $verified = hrtime(true);
        require_once $serverRoot . '/database/install.php';
        $application = new \think\App($serverRoot);
        $application->instance(\app\common\value\installation\ServerReleaseIdentity::class, $identity);
        $application->initialize();
        $compiled = hrtime(true);
        $host = $application->make(\app\common\services\installation\InstallationExecutionHost::class);
        $status = $host->status();
        if (($status['state'] ?? null) !== 'installed'
            || ($status['code'] ?? null) !== 'INSTALL_ALREADY_COMPLETED'
            || !is_array($status['health'] ?? null) || $status['health'] === []) {
            throw new RuntimeException('native installed runtime health is not current');
        }
        $healthy = hrtime(true);
        \app\common\infrastructure\installation\VerifiedServerDeployment::publish($application, $identity);
        self::writeAdmissionReceipt($serverRoot, $application, $identity, $started, $verified, $compiled, $healthy);
    }

    private static function assertAdmissionBoundary(string $serverRoot): void
    {
        require_once $serverRoot . '/app/common/infrastructure/installation/VerifiedServerDeployment.php';
        \app\common\infrastructure\installation\VerifiedServerDeployment::assertImmutableProgram($serverRoot);
    }

    private static function prepareRuntimeAdmission(string $serverRoot, bool $serverRelease): void
    {
        if (!$serverRelease) {
            return;
        }
        $started = hrtime(true);
        self::assertAdmissionBoundary($serverRoot);
        require_once $serverRoot . '/vendor/autoload.php';
        $identity = \app\common\value\installation\ServerReleaseIdentity::load($serverRoot);
        $verified = hrtime(true);
        require_once $serverRoot . '/database/install.php';
        $application = new \think\App($serverRoot);
        $application->instance(\app\common\value\installation\ServerReleaseIdentity::class, $identity);
        $application->initialize();
        $compiled = hrtime(true);
        \app\common\infrastructure\installation\VerifiedServerDeployment::publish($application, $identity);
        self::writeAdmissionReceipt($serverRoot, $application, $identity, $started, $verified, $compiled);
    }

    private static function writeAdmissionReceipt(
        string $serverRoot,
        \think\App $application,
        \app\common\value\installation\ServerReleaseIdentity $identity,
        int $started,
        int $verified,
        int $compiled,
        ?int $healthy = null,
    ): void {
        $healthy ??= $compiled;
        self::writeJson($serverRoot . '/runtime/upgrade/verified-deployment/receipt.json', [
            'protocol' => 'peanut.server-runtime-admission-receipt.v1',
            'identity_sha256' => $identity->identitySha256(),
            'file_count' => count($identity->document()['files']),
            'module_count' => count($application->make(\PeanutAdmin\Kernel\Module\CompiledModuleRegistry::class)->modules),
            'verification_ms' => ($verified - $started) / 1000000,
            'module_initialization_ms' => ($compiled - $verified) / 1000000,
            'runtime_health_ms' => ($healthy - $compiled) / 1000000,
            'publication_ms' => (hrtime(true) - $healthy) / 1000000,
        ]);
    }

    /** @param array<string,mixed> $plan @param array<string,mixed> $journal @return array<string,mixed> */
    /** @param array<string,mixed> $plan @param array<string,mixed> $journal */
    private static function assertMaintenance(string $serverRoot, string $updateId): void
    {
        $marker = self::jsonFile(self::maintenancePath($serverRoot), 'server update maintenance marker');
        if (($marker['protocol'] ?? null) !== self::MAINTENANCE_PROTOCOL
            || ($marker['status'] ?? null) !== 'active'
            || ($marker['update_id'] ?? null) !== $updateId) {
            throw new RuntimeException('server update maintenance marker is not bound to plan');
        }
    }

    private static function verificationKey(string $serverRoot, bool $create): string
    {
        $path = $serverRoot . '/private/installation/update-verification.key';
        if (!file_exists($path) && !is_link($path) && $create) {
            self::durableFile($path, bin2hex(random_bytes(32)) . "\n", 0600);
        }
        if (!is_file($path) || is_link($path) || (fileperms($path) & 0777) !== 0600) {
            throw new RuntimeException('private update verification key has unsafe type or mode');
        }
        $key = trim(self::regularBytes($path, 'private update verification key'));
        if (preg_match('/^[a-f0-9]{64}$/D', $key) !== 1) {
            throw new RuntimeException('private update verification key is invalid');
        }
        return $key;
    }

    /** @param array<string,mixed> $plan */
    /** @param array<string,mixed> $plan */
    /** @param array<string,mixed> $plan */
    /** @param array<string,mixed> $plan @param array<string,mixed> $journal */
    /** @param array<string,array{path:string,sha256:string,mode:int}> $targetMap */
    private static function extractPreparedServer(string $archive, string $workspace, array $targetMap): void
    {
        $prepared = $workspace . '/prepared/server';
        if (file_exists($prepared) || is_link($prepared)) {
            throw new RuntimeException('update workspace already contains prepared server files');
        }
        self::ensureDirectory($prepared, 0700);
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
            $bytes = $file->getContent();
            if (hash('sha256', $bytes) !== $targetMap[$relative]['sha256']) {
                throw new RuntimeException('prepared file digest changed while extracting: ' . $relative);
            }
            self::durableFile($target, $bytes, $targetMap[$relative]['mode']);
        }
    }

    /** @return array<string,mixed> */
    private static function plan(string $workspace): array
    {
        $plan = self::jsonFile($workspace . '/plan.json', 'server update plan');
        if (($plan['schema_version'] ?? null) !== 1 || ($plan['protocol'] ?? null) !== self::PLAN_PROTOCOL
            || !is_array($plan['operations'] ?? null)
            || !is_array($plan['requirements'] ?? null)) {
            throw new RuntimeException('server update plan is invalid');
        }
        $toolDigest = hash_file('sha256', __FILE__);
        if (!is_string($toolDigest) || !hash_equals((string) ($plan['tool_sha256'] ?? ''), $toolDigest)) {
            throw new RuntimeException('server update tool differs from planned trusted copy');
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
        self::assertOrdinaryParents($workspace, 'prepared/' . self::IDENTITY);
        $targetIdentity = self::regularBytes(
            self::preparedPath($workspace, self::IDENTITY),
            'prepared server release identity',
        );
        if (!hash_equals((string) ($plan['target']['identity_sha256'] ?? ''), hash('sha256', $targetIdentity))) {
            throw new RuntimeException('prepared server release identity differs from plan');
        }
        foreach ($plan['operations'] as $operation) {
            if (!is_array($operation) || !is_string($operation['path'] ?? null)) {
                throw new RuntimeException('server update operation is invalid');
            }
            if (($operation['operation'] ?? null) === 'delete') {
                continue;
            }
            $prepared = self::preparedPath($workspace, $operation['path']);
            self::assertOrdinaryParents($workspace, 'prepared/' . $operation['path']);
            if (!is_file($prepared) || is_link($prepared)) {
                throw new RuntimeException('prepared server file is unavailable: ' . $operation['path']);
            }
            $digest = hash_file('sha256', $prepared);
            if (!is_string($digest) || !hash_equals((string) $operation['target_sha256'], $digest)) {
                throw new RuntimeException('prepared server file digest differs from plan: ' . $operation['path']);
            }
        }
    }

    /** @param array<string,mixed> $plan */
    /** @param array<string,mixed> $identity */
    private static function assertInstalledInstance(string $serverRoot, array $identity): void
    {
        self::assertOrdinaryParents($serverRoot, 'private/installation/installed.json');
        $receipt = self::jsonFile($serverRoot . '/private/installation/installed.json', 'installed instance receipt');
        if (($receipt['schema_version'] ?? null) !== 1
            || ($receipt['protocol'] ?? null) !== 'peanut.installation-receipt.v1'
            || ($receipt['state'] ?? null) !== 'installed'
            || ($receipt['deployment_mode'] ?? null) !== ($identity['application']['edition'] ?? null)
            || !is_array($receipt['baseline_manifest'] ?? null)
            || ($receipt['baseline_manifest']['path'] ?? null) !== 'server/private/installation/baseline.json') {
            throw new RuntimeException('installed instance identity is invalid');
        }
        $baselineBytes = self::regularBytes(
            $serverRoot . '/private/installation/baseline.json',
            'installed instance baseline',
        );
        if (!hash_equals((string) ($receipt['baseline_manifest']['sha256'] ?? ''), hash('sha256', $baselineBytes))) {
            throw new RuntimeException('installed instance baseline differs from receipt');
        }
        $baseline = json_decode($baselineBytes, true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($baseline) || ($baseline['protocol'] ?? null) !== 'peanut.installation-baseline.v1'
            || ($baseline['deployment_mode'] ?? null) !== $receipt['deployment_mode']
            || ($baseline['source']['kind'] ?? null) !== 'server-release'
            || preg_match('/^[a-f0-9]{64}$/D', (string) ($baseline['source']['server_release_identity_sha256'] ?? '')) !== 1) {
            throw new RuntimeException('installed instance baseline identity is invalid');
        }
    }

    /** @param array<string,mixed> $identity @return array{installed_receipt_sha256:string,baseline_sha256:string,generation:int,state_sha256:?string} */
    private static function deploymentState(string $serverRoot, array $identity, string $currentDigest): array
    {
        self::assertInstalledInstance($serverRoot, $identity);
        $receiptPath = $serverRoot . '/private/installation/installed.json';
        $baselinePath = $serverRoot . '/private/installation/baseline.json';
        $receiptDigest = hash_file('sha256', $receiptPath);
        $baselineDigest = hash_file('sha256', $baselinePath);
        if (!is_string($receiptDigest) || !is_string($baselineDigest)) {
            throw new RuntimeException('installed identity cannot be hashed');
        }
        $path = self::deploymentPath($serverRoot);
        if (!file_exists($path) && !is_link($path)) {
            $baseline = self::jsonFile($baselinePath, 'installed instance baseline');
            if (($baseline['source']['server_release_identity_sha256'] ?? null) !== $currentDigest) {
                throw new RuntimeException('current release has no matching installed deployment chain');
            }
            return [
                'installed_receipt_sha256' => $receiptDigest,
                'baseline_sha256' => $baselineDigest,
                'generation' => 0,
                'state_sha256' => null,
            ];
        }
        $stateBytes = self::regularBytes($path, 'current deployment identity');
        $state = json_decode($stateBytes, true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($state) || ($state['schema_version'] ?? null) !== 1
            || ($state['protocol'] ?? null) !== 'peanut.server-deployment.v1'
            || ($state['installed_receipt_sha256'] ?? null) !== $receiptDigest
            || ($state['baseline_sha256'] ?? null) !== $baselineDigest
            || ($state['current_identity_sha256'] ?? null) !== $currentDigest
            || ($state['application']['slug'] ?? null) !== $identity['application']['slug']
            || ($state['application']['edition'] ?? null) !== $identity['application']['edition']
            || ($state['application']['package_identity'] ?? null) !== $identity['application']['package_identity']
            || !is_int($state['generation'] ?? null) || $state['generation'] < 1) {
            throw new RuntimeException('current deployment identity is invalid');
        }
        return [
            'installed_receipt_sha256' => $receiptDigest,
            'baseline_sha256' => $baselineDigest,
            'generation' => $state['generation'],
            'state_sha256' => hash('sha256', $stateBytes),
        ];
    }

    private static function deploymentPath(string $serverRoot): string
    {
        return $serverRoot . '/private/installation/deployment.json';
    }

    /** @param array<string,mixed> $plan */
    private static function publishDeploymentState(string $serverRoot, string $workspace, array $plan, string $updateId): void
    {
        $path = self::deploymentPath($serverRoot);
        $targetDigest = (string) $plan['target']['identity_sha256'];
        $sourceDigest = (string) $plan['source']['identity_sha256'];
        $generation = $plan['source']['deployment_generation'] ?? null;
        if (!is_int($generation) || $generation < 0
            || hash_file('sha256', $serverRoot . '/private/installation/installed.json') !== $plan['source']['installed_receipt_sha256']
            || hash_file('sha256', $serverRoot . '/private/installation/baseline.json') !== $plan['source']['baseline_sha256']) {
            throw new RuntimeException('installed identity changed before deployment publication');
        }
        if (file_exists($path) || is_link($path)) {
            $existingBytes = self::regularBytes($path, 'current deployment identity');
            $existing = json_decode($existingBytes, true, 512, JSON_THROW_ON_ERROR);
            if (is_array($existing) && ($existing['update_id'] ?? null) === $updateId
                && ($existing['current_identity_sha256'] ?? null) === $targetDigest
                && ($existing['generation'] ?? null) === $generation + 1) {
                return;
            }
            if (hash('sha256', $existingBytes) !== ($plan['source']['deployment_state_sha256'] ?? null)
                || ($existing['current_identity_sha256'] ?? null) !== $sourceDigest) {
                throw new RuntimeException('deployment identity changed after planning');
            }
        } elseif (($plan['source']['deployment_state_sha256'] ?? null) !== null || $generation !== 0) {
            throw new RuntimeException('prior deployment identity is missing');
        }
        self::writeJson($path, [
            'schema_version' => 1,
            'protocol' => 'peanut.server-deployment.v1',
            'application' => [
                'slug' => $plan['target']['application']['slug'],
                'edition' => $plan['target']['application']['edition'],
                'package_identity' => $plan['target']['application']['package_identity'],
            ],
            'installed_receipt_sha256' => $plan['source']['installed_receipt_sha256'],
            'baseline_sha256' => $plan['source']['baseline_sha256'],
            'generation' => $generation + 1,
            'previous_identity_sha256' => $sourceDigest,
            'current_identity_sha256' => $targetDigest,
            'previous_deployment_state_sha256' => $plan['source']['deployment_state_sha256'],
            'update_id' => $updateId,
            'plan_sha256' => hash_file('sha256', $workspace . '/plan.json'),
        ]);
    }

    /** @param array<string,mixed> $plan */
    /** @param array<string,mixed> $plan @param array<string,mixed> $journal */
    private static function assertJournalPlan(string $workspace, array $plan, array $journal): void
    {
        $digest = hash_file('sha256', $workspace . '/plan.json');
        if (!is_string($digest) || !hash_equals((string) ($journal['plan_sha256'] ?? ''), $digest)
            || ($journal['update_id'] ?? null) !== self::updateId($plan)
            || ($journal['source'] ?? null) !== ($plan['source'] ?? null)
            || ($journal['target'] ?? null) !== ($plan['target'] ?? null)) {
            throw new RuntimeException('server update journal differs from plan');
        }
        if (count($journal['operations']) !== count($plan['operations'])) {
            throw new RuntimeException('server update journal operations differ from plan');
        }
        foreach ($plan['operations'] as $index => $operation) {
            $record = $journal['operations'][$index] ?? null;
            if (!is_array($record)) {
                throw new RuntimeException('server update journal operations differ from plan');
            }
            foreach (['path', 'operation', 'current_sha256', 'current_mode', 'target_sha256', 'target_mode'] as $field) {
                if (($record[$field] ?? null) !== ($operation[$field] ?? null)) {
                    throw new RuntimeException('server update journal operations differ from plan');
                }
            }
        }
    }

    /** @param array<string,mixed> $plan */
    private static function assertPlanOperations(string $serverRoot, string $workspace, array $plan): void
    {
        $sourceBytes = self::regularBytes($serverRoot . '/.peanut/release-identity.json', 'current server release identity');
        $targetBytes = self::regularBytes(self::preparedPath($workspace, self::IDENTITY), 'prepared server release identity');
        $source = self::identity(json_decode($sourceBytes, true, 512, JSON_THROW_ON_ERROR), false);
        $target = self::identity(json_decode($targetBytes, true, 512, JSON_THROW_ON_ERROR), true);
        foreach (['slug', 'edition', 'package_identity'] as $field) {
            if (($source['application'][$field] ?? null) !== ($target['application'][$field] ?? null)) {
                throw new RuntimeException('server update plan changes application identity');
            }
        }
        $sourceMap = self::fileMap($source['files']);
        $sourceMap[self::IDENTITY] = ['sha256' => hash('sha256', $sourceBytes), 'mode' => 0644];
        $targetMap = self::fileMap($target['files']);
        $targetMap[self::IDENTITY] = [
            'sha256' => hash('sha256', $targetBytes),
            'mode' => fileperms(self::preparedPath($workspace, self::IDENTITY)) & 0777,
        ];
        $ownedDirectories = self::ownedDirectories(array_keys($sourceMap));
        $expectedPaths = array_unique([...array_keys($sourceMap), ...array_keys($targetMap)]);
        sort($expectedPaths, SORT_STRING);
        $operations = $plan['operations'];
        if (count($operations) !== count($expectedPaths)) {
            throw new RuntimeException('server update plan operations differ from release identities');
        }
        foreach ($expectedPaths as $index => $path) {
            $operation = $operations[$index] ?? null;
            self::assertProgramPath($path);
            $sourceFile = $sourceMap[$path] ?? null;
            $targetFile = $targetMap[$path] ?? null;
            $actual = self::actualPath($serverRoot, $path);
            self::assertOrdinaryParents($serverRoot, substr($path, strlen('server/')));
            if ($targetFile !== null) {
                self::assertTargetParents($serverRoot, $path, $ownedDirectories, $sourceFile !== null);
            }
            $present = file_exists($actual) || is_link($actual);
            if ($present && (!is_file($actual) || is_link($actual))) {
                throw new RuntimeException('program path is unsafe before update: ' . $path);
            }
            $currentDigest = $present ? hash_file('sha256', $actual) : null;
            $currentMode = $present ? (fileperms($actual) & 0777) : null;
            if (!is_array($operation) || ($operation['path'] ?? null) !== $path
                || ($operation['operation'] ?? null) !== ($targetFile === null ? 'delete' : ($sourceFile === null ? 'add' : 'replace'))
                || ($operation['current_present'] ?? null) !== $present
                || ($operation['current_sha256'] ?? null) !== $currentDigest
                || ($operation['current_mode'] ?? null) !== $currentMode
                || ($operation['target_sha256'] ?? null) !== ($targetFile['sha256'] ?? null)
                || ($operation['target_mode'] ?? null) !== ($targetFile['mode'] ?? null)) {
                throw new RuntimeException('server update plan operations differ from release identities');
            }
        }
        $requirements = [
            'composer_dependencies_changed' => self::operationTouches($operations, 'server/composer.json')
                || self::operationTouches($operations, 'server/composer.lock'),
            'database_migrations_changed' => self::operationPrefix($operations, 'server/database/'),
            'runtime_environment_changed' => self::operationTouches($operations, 'server/docker/Dockerfile')
                || self::operationTouches($operations, 'server/docker/compose.yaml')
                || self::operationPrefix(
                    array_values(array_filter(
                        $operations,
                        static fn(array $operation): bool => $operation['path'] !== 'server/docker/conf/nginx.conf',
                    )),
                    'server/docker/conf/',
                ),
            'nginx_configuration_changed' => self::operationTouches($operations, 'server/docker/conf/nginx.conf'),
        ];
        if ($plan['requirements'] !== $requirements) {
            throw new RuntimeException('server update plan requirements differ from operations');
        }
    }

    private static function assertOrdinaryParents(string $root, string $relative): void
    {
        $parts = explode('/', $relative);
        array_pop($parts);
        $cursor = $root;
        foreach ($parts as $part) {
            $cursor .= '/' . $part;
            if (is_link($cursor) || (file_exists($cursor) && !is_dir($cursor))) {
                throw new RuntimeException('server update parent is unsafe: ' . $relative);
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
                'current_mode' => $operation['current_mode'] ?? null,
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
            'update_id' => self::updateId($plan),
            'maintenance_key' => 'maintenance_' . bin2hex(random_bytes(16)),
            'reason_key' => 'planned-upgrade',
            'plan_sha256' => $planDigest,
            'source' => $plan['source'],
            'target' => $plan['target'],
            'operations' => $operations,
            'created_at' => self::now(),
        ];
    }

    /** @param array<string,mixed> $journal @return array<string,mixed> */
    private static function recordIntent(string $serverRoot, string $workspace, array $journal, int $index): array
    {
        $operation = $journal['operations'][$index];
        if (($operation['status'] ?? null) === 'intent_recorded') {
            try {
                self::assertOperationState($serverRoot, $operation, true);
                $operation['status'] = 'done';
                $operation['completed_at'] = self::now();
                return $operation;
            } catch (RuntimeException) {
                // The operation may have been interrupted before publication.
            }
            self::assertOperationState($serverRoot, $operation);
            return $operation;
        }
        if (($operation['status'] ?? null) === 'done') {
            self::assertOperationState($serverRoot, $operation, true);
            return $operation;
        }
        if (($operation['status'] ?? null) !== 'pending') {
            throw new RuntimeException('server update operation status is invalid');
        }
        $path = (string) $operation['path'];
        self::assertProgramPath($path);
        self::assertOrdinaryParents($serverRoot, substr($path, strlen('server/')));
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
            $mode = fileperms($actual) & 0777;
            if (is_string($expectedCurrent) && (!hash_equals($expectedCurrent, $digest)
                || $mode !== ($operation['current_mode'] ?? null))) {
                throw new RuntimeException('program path changed after planning: ' . $path);
            }
            if (is_string($expectedTarget) && hash_equals($expectedTarget, $digest)
                && $mode === ($operation['target_mode'] ?? null)) {
                $operation['status'] = 'done';
                $operation['completed_at'] = self::now();
                return $operation;
            }
            $backupPath = self::backupPath($workspace, $path);
            self::assertOrdinaryParents($workspace, 'backups/' . $path);
            self::ensureDirectory(dirname($backupPath), 0700);
            if (file_exists($backupPath) || is_link($backupPath)) {
                $backupBytes = self::regularBytes($backupPath, 'existing program backup');
                if (!hash_equals($digest, hash('sha256', $backupBytes))
                    || (fileperms($backupPath) & 0777) !== $mode) {
                    throw new RuntimeException('existing program backup differs from current file: ' . $path);
                }
            } elseif (!copy($actual, $backupPath) || !chmod($backupPath, fileperms($actual) & 0777)) {
                throw new RuntimeException('cannot backup program file: ' . $path);
            }
            self::syncFile($backupPath);
            self::syncDirectory(dirname($backupPath));
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
        self::assertOrdinaryParents($serverRoot, substr($path, strlen('server/')));
        self::assertOperationState($serverRoot, $operation);
        $actual = self::actualPath($serverRoot, $path);
        if (($operation['operation'] ?? null) === 'delete') {
            if (file_exists($actual) || is_link($actual)) {
                if (!is_file($actual) || is_link($actual)) {
                    throw new RuntimeException('program delete target is unsafe: ' . $path);
                }
                if (!unlink($actual)) {
                    throw new RuntimeException('cannot delete program file: ' . $path);
                }
                self::syncDirectory(dirname($actual));
            }
            return;
        }
        $prepared = self::preparedPath($workspace, $path);
        $targetSha = (string) $operation['target_sha256'];
        $digest = hash_file('sha256', $prepared);
        if (!is_string($digest) || !hash_equals($targetSha, $digest)) {
            throw new RuntimeException('prepared file digest differs before update: ' . $path);
        }
        self::ensureDirectory(dirname($actual), 0755);
        $temporary = dirname($actual) . '/.server-update-' . bin2hex(random_bytes(8)) . '.tmp';
        if (!copy($prepared, $temporary) || !chmod($temporary, (int) $operation['target_mode'])) {
            @unlink($temporary);
            throw new RuntimeException('cannot stage program file: ' . $path);
        }
        self::syncFile($temporary);
        $temporaryDigest = hash_file('sha256', $temporary);
        if (!is_string($temporaryDigest) || !hash_equals($targetSha, $temporaryDigest) || !rename($temporary, $actual)) {
            @unlink($temporary);
            throw new RuntimeException('cannot publish program file atomically: ' . $path);
        }
        self::syncDirectory(dirname($actual));
    }

    /** @param array<string,mixed> $operation */
    private static function assertOperationState(string $serverRoot, array $operation, bool $done = false): void
    {
        $path = (string) $operation['path'];
        self::assertOrdinaryParents($serverRoot, substr($path, strlen('server/')));
        $actual = self::actualPath($serverRoot, $path);
        $digest = null;
        $mode = null;
        if (file_exists($actual) || is_link($actual)) {
            if (!is_file($actual) || is_link($actual)) {
                throw new RuntimeException('program path is unsafe during update: ' . $path);
            }
            $digest = hash_file('sha256', $actual);
            $mode = fileperms($actual) & 0777;
        }
        $expected = $done
            ? ($operation['operation'] === 'delete' ? null : $operation['target_sha256'])
            : $operation['current_sha256'];
        $expectedMode = $done
            ? ($operation['operation'] === 'delete' ? null : ($operation['target_mode'] ?? null))
            : ($operation['current_mode'] ?? null);
        if ($digest !== $expected || $mode !== $expectedMode) {
            throw new RuntimeException('program path changed during update: ' . $path);
        }
    }

    /** @param array<string,mixed> $operation */
    private static function recoverOperation(string $serverRoot, string $workspace, array $operation): void
    {
        $path = (string) $operation['path'];
        self::assertProgramPath($path);
        self::assertOrdinaryParents($serverRoot, substr($path, strlen('server/')));
        $actual = self::actualPath($serverRoot, $path);
        $currentSha = is_string($operation['current_sha256'] ?? null) ? $operation['current_sha256'] : null;
        $targetSha = is_string($operation['target_sha256'] ?? null) ? $operation['target_sha256'] : null;
        $actualSha = null;
        $actualMode = null;
        if (file_exists($actual) || is_link($actual)) {
            if (!is_file($actual) || is_link($actual)) {
                throw new RuntimeException('program path is unsafe during recovery: ' . $path);
            }
            $actualSha = hash_file('sha256', $actual);
            $actualMode = fileperms($actual) & 0777;
            if (!is_string($actualSha)) {
                throw new RuntimeException('cannot hash program path during recovery: ' . $path);
            }
        }

        if (($operation['operation'] ?? null) === 'add') {
            if ($actualSha === null) {
                return;
            }
            if ($targetSha !== null && hash_equals($targetSha, $actualSha)
                && $actualMode === ($operation['target_mode'] ?? null)) {
                if (!unlink($actual)) {
                    throw new RuntimeException('cannot remove added program file during recovery: ' . $path);
                }
                self::syncDirectory(dirname($actual));
                return;
            }
            throw new RuntimeException('added program file changed after failed update: ' . $path);
        }

        if ($currentSha !== null && $actualSha !== null && hash_equals($currentSha, $actualSha)
            && $actualMode === ($operation['current_mode'] ?? null)) {
            return;
        }
        if (($operation['operation'] ?? null) === 'delete' && $actualSha !== null) {
            throw new RuntimeException('deleted program file changed after failed update: ' . $path);
        }
        if ($targetSha !== null && $actualSha !== null
            && (!hash_equals($targetSha, $actualSha) || $actualMode !== ($operation['target_mode'] ?? null))) {
            throw new RuntimeException('program file changed after failed update: ' . $path);
        }
        $backup = $operation['backup_path'] ?? null;
        if (!is_string($backup) || $backup !== self::backupPath($workspace, $path)) {
            throw new RuntimeException('program backup path is invalid during recovery: ' . $path);
        }
        self::assertOrdinaryParents($workspace, 'backups/' . $path);
        if (!is_file($backup) || is_link($backup)) {
            throw new RuntimeException('program backup is unavailable during recovery: ' . $path);
        }
        if ((fileperms($backup) & 0777) !== ($operation['current_mode'] ?? null)) {
            throw new RuntimeException('program backup mode differs during recovery: ' . $path);
        }
        self::ensureDirectory(dirname($actual), 0755);
        $temporary = dirname($actual) . '/.server-recover-' . bin2hex(random_bytes(8)) . '.tmp';
        if (!copy($backup, $temporary) || !chmod($temporary, (int) $operation['current_mode'])) {
            @unlink($temporary);
            throw new RuntimeException('cannot stage recovered program file: ' . $path);
        }
        self::syncFile($temporary);
        $restoredSha = hash_file('sha256', $temporary);
        if (!is_string($restoredSha) || $currentSha === null || !hash_equals($currentSha, $restoredSha)
            || !rename($temporary, $actual)) {
            @unlink($temporary);
            throw new RuntimeException('cannot restore program file during recovery: ' . $path);
        }
        self::syncDirectory(dirname($actual));
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
        self::ensureDirectory($runtimeRoot, 01775);
        $runtime = $serverRoot . '/' . self::RUNTIME_DIR;
        if (is_link($runtime) || (file_exists($runtime) && !is_dir($runtime))) {
            throw new RuntimeException('server update runtime directory is unsafe');
        }
        self::ensureDirectory($runtime, 0755);
        // Keep application runtime writable without allowing replacement of the
        // owner-controlled update guard directory under the sticky parent.
        if (!chmod($runtimeRoot, 01775) || !chmod($runtime, 0755)) {
            throw new RuntimeException('cannot expose nonsecret update guard directory to nginx');
        }
        $sentinel = $runtime . '/.mount-ready';
        if (!file_exists($sentinel) && !is_link($sentinel)) {
            self::durableFile($sentinel, "peanut.server-update-guard.v1\n", 0644);
        } elseif (self::regularBytes($sentinel, 'server update guard sentinel') !== "peanut.server-update-guard.v1\n") {
            throw new RuntimeException('server update guard sentinel is invalid');
        }
        return $runtime;
    }

    private static function trafficReadyPath(string $serverRoot): string
    {
        return $serverRoot . '/' . self::RUNTIME_DIR . '/.traffic-ready';
    }

    private static function trafficInitializedPath(string $serverRoot): string
    {
        return $serverRoot . '/' . self::RUNTIME_DIR . '/.traffic-initialized';
    }

    private static function assertTrafficInitialized(string $serverRoot): void
    {
        if (self::regularBytes(self::trafficInitializedPath($serverRoot), 'traffic initialization state')
            !== "peanut.server-traffic-initialized.v1\n") {
            throw new RuntimeException('traffic initialization state is invalid');
        }
    }

    private static function assertTrafficReady(string $serverRoot): void
    {
        if (self::regularBytes(self::trafficReadyPath($serverRoot), 'traffic permission')
            !== "peanut.server-traffic-ready.v1\n") {
            throw new RuntimeException('traffic permission is invalid');
        }
    }

    private static function revokeTraffic(string $serverRoot): void
    {
        $initialized = self::trafficInitializedPath($serverRoot);
        if (!file_exists($initialized) && !is_link($initialized)) {
            self::durableFile($initialized, "peanut.server-traffic-initialized.v1\n", 0600);
        } else {
            try {
                self::assertTrafficInitialized($serverRoot);
            } catch (Throwable $exception) {
                self::removeTrafficReady($serverRoot);
                throw $exception;
            }
        }
        self::removeTrafficReady($serverRoot);
    }

    private static function removeTrafficReady(string $serverRoot): void
    {
        $ready = self::trafficReadyPath($serverRoot);
        if (file_exists($ready) || is_link($ready)) {
            if ((!is_file($ready) && !is_link($ready)) || !unlink($ready)) {
                throw new RuntimeException('cannot revoke public traffic permission');
            }
            self::syncDirectory(dirname($ready));
        }
    }

    private static function grantTraffic(string $serverRoot): void
    {
        self::assertTrafficInitialized($serverRoot);
        $ready = self::trafficReadyPath($serverRoot);
        if (file_exists($ready) || is_link($ready)) {
            self::assertTrafficReady($serverRoot);
            return;
        }
        self::durableFile($ready, "peanut.server-traffic-ready.v1\n", 0644);
    }

    /** @param array<string,mixed> $plan */
    private static function writeMaintenance(string $serverRoot, array $plan, string $phase): void
    {
        self::revokeTraffic($serverRoot);
        $updateId = (string) $plan['update_id'];
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
        self::syncDirectory(dirname($path));
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
        $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . "\n";
        self::durableFile($path, $json, 0600);
    }

    private static function ensureDirectory(string $directory, int $mode): void
    {
        if (is_dir($directory) && !is_link($directory)) {
            return;
        }
        if (file_exists($directory) || is_link($directory)) {
            throw new RuntimeException('directory path is unsafe: ' . $directory);
        }
        self::ensureDirectory(dirname($directory), $mode);
        if (!mkdir($directory, $mode)) {
            throw new RuntimeException('cannot create directory: ' . $directory);
        }
        self::syncDirectory(dirname($directory));
    }

    private static function durableFile(string $path, string $bytes, int $mode): void
    {
        if (is_link($path)) {
            throw new RuntimeException('refusing to overwrite a symbolic link: ' . $path);
        }
        self::ensureDirectory(dirname($path), 0700);
        $temporary = dirname($path) . '/.' . basename($path) . '-' . bin2hex(random_bytes(8)) . '.tmp';
        $previousUmask = umask(0077);
        try {
            $handle = fopen($temporary, 'x+b');
        } finally {
            umask($previousUmask);
        }
        if (!is_resource($handle)) {
            throw new RuntimeException('cannot stage durable file: ' . $path);
        }
        try {
            if (!chmod($temporary, $mode)) {
                throw new RuntimeException('cannot protect durable file before write: ' . $path);
            }
            $offset = 0;
            while ($offset < strlen($bytes)) {
                $written = fwrite($handle, substr($bytes, $offset));
                if (!is_int($written) || $written < 1) {
                    throw new RuntimeException('cannot write durable file: ' . $path);
                }
                $offset += $written;
            }
            if (!fflush($handle) || !fsync($handle)) {
                throw new RuntimeException('cannot sync durable file: ' . $path);
            }
        } catch (Throwable $exception) {
            @unlink($temporary);
            throw $exception;
        } finally {
            fclose($handle);
        }
        if (!rename($temporary, $path)) {
            @unlink($temporary);
            throw new RuntimeException('cannot publish durable file: ' . $path);
        }
        self::syncDirectory(dirname($path));
    }

    private static function syncFile(string $path): void
    {
        $handle = fopen($path, 'rb');
        if (!is_resource($handle) || !fsync($handle)) {
            is_resource($handle) && fclose($handle);
            throw new RuntimeException('cannot sync file: ' . $path);
        }
        fclose($handle);
    }

    private static function syncDirectory(string $directory): void
    {
        $handle = @fopen($directory, 'rb');
        if (!is_resource($handle) || !@fsync($handle)) {
            is_resource($handle) && fclose($handle);
            throw new RuntimeException('cannot sync directory: ' . $directory);
        }
        fclose($handle);
    }

    private static function removeDurableFile(string $path): void
    {
        if (file_exists($path) || is_link($path)) {
            if (is_link($path) || !is_file($path) || !unlink($path)) {
                throw new RuntimeException('cannot remove durable file: ' . $path);
            }
            self::syncDirectory(dirname($path));
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
        ksort($actualComparable, SORT_STRING);
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
                    || !hash_equals($operation['current_sha256'], (string) $operation['target_sha256'])
                    || ($operation['current_mode'] ?? null) !== ($operation['target_mode'] ?? null))) {
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
                    || !hash_equals($operation['current_sha256'], (string) $operation['target_sha256'])
                    || ($operation['current_mode'] ?? null) !== ($operation['target_mode'] ?? null))) {
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
        fwrite(STDERR, "       php update-plan.php files-apply|files-verify|files-recover|files-verify-recovery|hold|health|publish|open --instance-server=/absolute/server --workspace=/absolute/update-workspace\n");
        exit(64);
    }
}

if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    exit(PeanutServerUpdatePlan::main($_SERVER['argv'] ?? []));
}
