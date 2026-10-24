<?php

declare(strict_types=1);

namespace app\common\services\installation;

use app\common\composition\ModuleComposition;
use app\common\exception\installation\InstallationExecutionException;
use app\common\services\audit\AuditContractHost;
use app\common\value\installation\ApplicationSourceIdentity;
use app\common\value\installation\ServerReleaseIdentity;
use app\platform\infrastructure\module\ThinkPhpModuleGovernanceProvider;
use app\platform\services\module\ProductTenantModuleProfileService;
use app\platform\infrastructure\plugin\ModuleCatalogApplier;
use PeanutAdmin\Kernel\Module\CompiledModuleRegistry;
use PeanutAdmin\Kernel\Module\ModuleRuntimeRepository;
use PeanutAdmin\Modules\Identity\Contract\TenantModuleStateQueries;
use Closure;
use PDO;
use RuntimeException;
use think\db\PDOConnection;
use think\facade\Config;
use think\facade\Db;
use Throwable;

/** The only application-owned fresh installation execution runtime. */
final class InstallationExecutionHost
{
    private const MODES = ['guided', 'automatic'];
    /** @var resource|null Only execute() owns and releases this native lock. */
    private $executionLock = null;
    private ?array $moduleMigrationAdmission = null;
    private ?Closure $partialGuard = null;
    private ?string $currentInstallationModule = null;
    private ?array $fileContinuation = null;
    private ?Closure $partialCompletion = null;
    private ?Closure $partialCompletionIntent = null;
    private readonly string $serverRoot;

    /** @param Closure(CompiledModuleRegistry):ModuleRuntimeRepository $moduleRuntimeFactory */
    public function __construct(
        string $serverRoot,
        private readonly ModuleCatalogApplier $catalogs,
        private readonly \PeanutAdmin\Modules\Identity\Contract\AdminDirectoryQuery $tenantDirectory,
        private readonly Closure $moduleRuntimeFactory,
        private readonly AuditContractHost $audit,
        private readonly TenantModuleStateQueries $moduleStates,
        private readonly ModuleComposition $moduleComposition,
        private readonly CompiledModuleRegistry $definitions,
    ) {
        $this->serverRoot = rtrim($serverRoot, DIRECTORY_SEPARATOR);
        require_once $this->serverRoot . '/database/install.php';
    }

    /** The normal request gate checks only the physical completion lock. */
    public function isInstalled(): bool
    {
        return $this->completionLockPresent();
    }

    /** @return array{installed:bool,deployment_mode:string} */
    public function entryStatus(): array
    {
        return [
            'installed' => $this->completionLockPresent(),
            'deployment_mode' => $this->deploymentMode(),
        ];
    }

    /** Environment inspection precedes instance configuration and database use. */
    public function environment(): array
    {
        $report = (new InstallationPreflightHost($this->serverRoot))->inspect(false);
        return $report + ['stage' => 'environment', 'writes' => ['outcome' => 'none'],
            'safe_retry' => true, 'completion_lock_present' => $this->completionLockPresent()];
    }

    /** Read-only permission admission on the framework's actual master PDO. */
    public function databaseCheck(): array
    {
        $status = $this->status();
        if ($status['state'] !== 'uninstalled' || !$status['safe_retry']) {
            return $status + ['permissions_verified' => false];
        }
        try {
            $connection = Db::connect();
            $connection->query('SELECT 1', [], true);
            $pdo = $connection->getPdo();
            if (!$pdo instanceof PDO || $pdo->inTransaction()) {
                throw new RuntimeException('INSTALL_DATABASE_CONNECTION_UNAVAILABLE');
            }
            $database = $pdo->query('SELECT DATABASE()')->fetchColumn();
            if (!is_string($database) || $database === '') {
                throw new RuntimeException('INSTALL_DATABASE_IDENTITY_UNKNOWN');
            }
            $registered = \loadConfig($this->serverRoot);
            $actualConfig = $connection->getConfig();
            if ($database !== $registered['DB_NAME']
                || ($actualConfig['database'] ?? null) !== $registered['DB_NAME']
                || ($actualConfig['hostname'] ?? null) !== $registered['DB_HOST']
                || (string) ($actualConfig['hostport'] ?? '') !== (string) $registered['DB_PORT']) {
                throw new RuntimeException('INSTALL_DATABASE_CONNECTION_MISMATCH');
            }
            $required = ['SELECT', 'INSERT', 'UPDATE', 'DELETE', 'CREATE', 'ALTER', 'DROP', 'INDEX', 'REFERENCES', 'CREATE TEMPORARY TABLES'];
            $observed = [];
            $scope = '`' . str_replace('`', '``', $database) . '`.*';
            $escapedScope = '`' . strtr($database, ['\\' => '\\\\', '`' => '``', '_' => '\\_', '%' => '\\%']) . '`.*';
            foreach ($pdo->query('SHOW GRANTS FOR CURRENT_USER()')->fetchAll(PDO::FETCH_NUM) as $row) {
                // SHOW GRANTS can escape literal schema characters; accept only
                // the exact current schema spelling, never expand a pattern.
                // Roles, other wildcard schema patterns and unrecognised grants are
                // never guessed into effective permission. They remain unknown.
                if (!preg_match('/^GRANT (.+) ON (.+) TO /D', $row[0], $match)
                    || !in_array($match[2], ['*.*', $scope, $escapedScope], true)) {
                    continue;
                }
                if ($match[1] === 'ALL PRIVILEGES') {
                    $observed = $required;
                    break;
                }
                $observed = array_merge($observed, explode(', ', $match[1]));
            }
            $missing = array_values(array_diff($required, $observed));
            return $status + ['permissions_verified' => $missing === [], 'permissions_missing_or_unconfirmed' => $missing,
                'database_check' => $missing === [] ? 'ready' : 'blocked',
                'permission_remedy' => $missing === [] ? null : '请资源 owner 核对目标库的精确有效权限；本检查不授权或写入数据库。'];
        } catch (Throwable) {
            return [...$status, 'category' => 'unknown', 'stage' => 'database_check',
                'reason' => 'INSTALL_DATABASE_PERMISSIONS_UNKNOWN', 'safe_retry' => false,
                'permissions_verified' => false, 'database_check' => 'unknown',
                'remedy' => '核对实际连接、库身份及权限后重新检查；没有执行安装或授予权限。'];
        }
    }

    /** @return array<string,mixed> */
    public function status(): array
    {
        $status = $this->inspectStatus();
        $code = (string) ($status['code'] ?? 'INSTALL_STATE_UNKNOWN');
        $locked = $this->completionLockPresent();
        $localMarkers = file_exists($this->progressMarker()) || is_link($this->progressMarker())
            || file_exists($this->baselineMarker()) || is_link($this->baselineMarker());
        $partial = $localMarkers
            || in_array($code, ['INSTALL_PARTIAL_STATE_REQUIRES_REBUILD', 'INSTALL_BASELINE_WITHOUT_COMPLETION_LOCK'], true);
        $existingInstallation = $locked || $code === 'INSTALL_COMPLETION_LOCK_MISSING';
        $unknown = str_contains($code, 'UNAVAILABLE');
        $category = $status['state'] === 'installed' ? 'installed'
            : ($existingInstallation ? 'installed_fault' : ($unknown ? 'unknown' : ($partial ? 'partial' : 'prerequisite')));
        return $status + [
            'category' => $category,
            'stage' => $existingInstallation ? 'runtime' : ($partial ? 'execution'
                : ($code === 'INSTALL_PREFLIGHT_BLOCKED' ? 'environment' : 'database_check')),
            'reason' => $code,
            'remedy' => $existingInstallation
                ? '恢复现有实例配置或数据库；不要删除完成锁来修复运行故障。'
                : ($partial ? '保留现有数据库、baseline 和执行记录，核对部分完成事实后由资源 owner 处理；不自动清库或重建身份。'
                    : '按环境、配置和数据库检查的具体诊断修复后重新检查。'),
            'writes' => ['status_request' => 'none', 'database_existing_state' => $category === 'partial' ? 'nonempty_or_partial' : ($existingInstallation ? 'installed_or_faulted' : 'see_status'), 'completion_lock_present' => $locked,
                'residual_origin' => $localMarkers ? 'local_markers_present_identity_not_inferred' : 'unconfirmed_existing_database_origin',
                'progress_present' => is_file($this->progressMarker()), 'baseline_present' => is_file($this->baselineMarker())],
            'safe_retry' => !$locked && !$partial && $status['state'] === 'uninstalled',
            'completion_lock' => 'server/private/installation/installed.json',
        ];
    }

    /** No status request creates, replaces or removes installation anchors. */
    private function inspectStatus(): array
    {
        $tenantBootstrap = \installationTenantBootstrapContract($this->serverRoot);
        $preflight = (new InstallationPreflightHost($this->serverRoot))->inspect();
        $base = [
            'mode' => $this->mode(),
            'deployment_mode' => $this->deploymentMode(),
            'tenant_bootstrap' => $tenantBootstrap,
            'preflight' => $preflight,
            'official_modules' => $this->officialModules(),
        ];
        $complete = $this->completionLockPresent();
        if (!$complete && (file_exists($this->baselineMarker()) || is_link($this->baselineMarker()))) {
            return [...$base, 'state' => 'blocked', 'code' => 'INSTALL_BASELINE_WITHOUT_COMPLETION_LOCK',
                'retryable' => false, 'health' => null];
        }
        if (($preflight['status'] ?? null) !== 'ready') {
            return [
                ...$base,
                'state' => 'blocked',
                'code' => 'INSTALL_PREFLIGHT_BLOCKED',
                'retryable' => !$complete,
                'health' => null,
            ];
        }

        if (!$this->installationMigrationComplete()) {
            return [
                ...$base,
                'state' => 'blocked',
                'code' => 'INSTALL_STATE_MIGRATION_PENDING',
                'retryable' => false,
                'health' => null,
            ];
        }
        if ($this->hasLegacyInstallationState()) {
            return [
                ...$base,
                'state' => 'blocked',
                'code' => 'INSTALL_STATE_MIGRATION_REQUIRED',
                'retryable' => false,
                'health' => null,
            ];
        }

        if ($complete && !$this->completionReceiptValid()) {
            return [
                ...$base,
                'state' => 'blocked',
                'code' => 'INSTALL_COMPLETION_LOCK_INVALID',
                'retryable' => false,
                'health' => null,
            ];
        }

        try {
            $database = \installationDatabaseState($this->serverRoot);
        } catch (Throwable) {
            return [
                ...$base,
                'state' => 'blocked',
                'code' => $complete ? 'INSTALL_LOCKED_DATABASE_UNAVAILABLE' : 'INSTALL_DATABASE_UNAVAILABLE',
                'retryable' => !$complete,
                'health' => null,
            ];
        }

        $progress = is_file($this->progressMarker());
        if ($complete) {
            if ($database['state'] !== 'installed') {
                return [
                    ...$base,
                    'state' => 'blocked',
                    'code' => 'INSTALL_LOCKED_DATABASE_MISMATCH',
                    'retryable' => false,
                    'health' => null,
                ];
            }
            return [
                ...$base,
                'state' => 'installed',
                'code' => 'INSTALL_ALREADY_COMPLETED',
                'retryable' => false,
                'health' => $database['health'],
            ];
        }
        if ($progress && $database['state'] !== 'uninstalled') {
            return [
                ...$base,
                'state' => 'blocked',
                'code' => 'INSTALL_PARTIAL_STATE_REQUIRES_REBUILD',
                'retryable' => false,
                'health' => null,
            ];
        }
        if ($database['state'] === 'uninstalled') {
            if ($this->completionLockPresent()) {
                return [
                    ...$base,
                    'state' => 'blocked',
                    'code' => 'INSTALL_LOCKED_DATABASE_MISMATCH',
                    'retryable' => false,
                    'health' => null,
                ];
            }
            return [
                ...$base,
                'state' => 'uninstalled',
                'code' => $progress ? 'INSTALL_RETRY_READY' : 'INSTALL_READY',
                'retryable' => true,
                'health' => null,
            ];
        }
        if ($database['state'] === 'installed') {
            return [
                ...$base,
                'state' => 'blocked',
                'code' => 'INSTALL_COMPLETION_LOCK_MISSING',
                'retryable' => false,
                'health' => null,
            ];
        }

        return [
            ...$base,
            'state' => 'blocked',
            'code' => 'INSTALL_PARTIAL_STATE_REQUIRES_REBUILD',
            'retryable' => false,
            'health' => null,
        ];
    }

    /** @param array<string,mixed> $input @return array<string,mixed> */
    public function executeGuided(string $token, array $input): array
    {
        if ($this->mode() !== 'guided') {
            throw new InstallationExecutionException(
                'INSTALL_GUIDED_MODE_DISABLED',
                '当前部署未启用 guided 安装模式。',
                403,
            );
        }
        $expected = (string) Config::get('peanut.installation.setup_token', '');
        if (preg_match('/^[A-Za-z0-9_-]{32,128}$/D', $expected) !== 1
            || $token === ''
            || !hash_equals($expected, $token)) {
            throw new InstallationExecutionException(
                'INSTALL_SETUP_TOKEN_INVALID',
                'Setup token 无效。',
                403,
            );
        }
        return $this->execute($input);
    }

    /** @param array<string,mixed> $input @return array<string,mixed> */
    public function executeAutomatic(array $input): array
    {
        if ($this->mode() !== 'automatic') {
            throw new InstallationExecutionException(
                'INSTALL_AUTOMATIC_MODE_DISABLED',
                '当前部署未启用 automatic 安装模式。',
                409,
            );
        }
        return $this->execute($input);
    }

    /** Deliberate native owner operation only. Ordinary execute/HTTP never enters partial recovery. */
    public function partialInstallation(string $operation, string $inputPath): array
    {
        $context = app()->make(\app\common\execution\CurrentExecutionContext::class)->current();
        $registryPath = $this->serverRoot . '/private/resources/project-resources.json';
        $appOwner = is_file($registryPath) && ($this->partialJson($registryPath)['authority']['role'] ?? null) === 'application';
        $releaseOwner = $appOwner && !ApplicationSourceIdentity::hasRuntime($this->serverRoot)
            && is_file($this->serverRoot . '/.peanut/release-identity.json');
        if (PHP_SAPI !== 'cli' || !$context instanceof \app\common\execution\InstallationExecutionContext
            || $context->operation() !== 'installation.console.partial' || !in_array($operation, ['plan', 'backup', 'apply'], true)
            || (!$releaseOwner && (!ApplicationSourceIdentity::hasRuntime($this->serverRoot) || Config::get('peanut.environment') !== 'development'))
            || !function_exists('posix_geteuid') || (posix_geteuid() !== fileowner($this->serverRoot)
                && !($appOwner && posix_geteuid() === 0))) { throw new RuntimeException('INSTALL_PARTIAL_NATIVE_OWNER_REQUIRED'); }
        $input = $this->partialJson($inputPath);
        $allowed = ['setup_token', 'actor', 'original_runtime', 'original_progress', 'original_configuration', 'original_failure', 'original_ledger', 'maintenance', 'plan', 'backup', 'backup_tool', 'backup_output'];
        if (array_diff(array_keys($input), $allowed) !== []) { throw new RuntimeException('INSTALL_PARTIAL_ARGUMENTS_INVALID'); }
        if ($this->mode() === 'guided') {
            $token = (string) Config::get('peanut.installation.setup_token', '');
            if (preg_match('/^[A-Za-z0-9_-]{32,128}$/D', $token) !== 1 || !is_string($input['setup_token'] ?? null)
                || !hash_equals($token, $input['setup_token'])) { throw new RuntimeException('INSTALL_SETUP_TOKEN_INVALID'); }
        }
        if ($appOwner) { return $this->partialApplicationInstallation($operation, $input, $context); }
        $lock = $this->acquireExecutionLock(); $this->executionLock = $lock;
        try {
            if ($this->completionLockPresent() || file_exists($this->baselineMarker()) || is_link($this->baselineMarker())) { throw new RuntimeException('INSTALL_PARTIAL_COMPLETION_CONFLICT'); }
            if (file_exists($this->serverRoot . '/private/partial-installation/attempt.json') || is_link($this->serverRoot . '/private/partial-installation/attempt.json')) { throw new RuntimeException('INSTALL_PARTIAL_ATTEMPT_REQUIRES_RECONCILIATION'); }
            $access = $this->partialNativeAccess($input, $operation === 'apply');
            // The complete native read declaration is checked before creating the master PDO.
            $connection = Db::connect();
            foreach (['hostname' => 'host', 'hostport' => 'port', 'database' => 'database', 'username' => 'user', 'password' => 'password'] as $native => $guarded) {
                if ((string) $connection->getConfig($native) !== (string) $access['config'][$guarded]) { throw new RuntimeException('INSTALL_PARTIAL_MASTER_MISMATCH'); }
            }
            if ($connection->getConfig('break_reconnect') !== false || (int) $connection->getConfig('deploy') !== 0 || $connection->getConfig('prefix') !== 'pa_') { throw new RuntimeException('INSTALL_PARTIAL_MASTER_MISMATCH'); }
            $connection->query('SELECT 1', [], true); $pdo = $this->pdo();
            if ($pdo->inTransaction()) { throw new RuntimeException('INSTALL_PARTIAL_TRANSACTION_CONFLICT'); }
            $facts = $this->partialFacts($input, $access, $pdo);
            if ($operation === 'plan') {
                if (isset($input['plan']) || isset($input['backup']) || isset($input['backup_tool']) || isset($input['backup_output'])) { throw new RuntimeException('INSTALL_PARTIAL_ARGUMENTS_INVALID'); }
                $directory = $this->partialDirectory('partial-plans', $access['resources']);
                $path = $directory . '/plan-' . bin2hex(random_bytes(16)) . '.json';
                $plan = ['protocol' => 'peanut.installation-partial-plan.v1', 'resume_from' => 'migrate_database', 'facts' => $facts,
                    'created_at' => time(), 'automatic_retry' => false, 'execution_admitted' => false, 'qualification' => 'not_run'];
                $plan['document_sha256'] = self::partialDigest($plan);
                \app\common\services\upgrade\SourceApplicationBackup::writePartialInstallationRecord($path, $plan);
                return ['status' => 'planned', 'path' => $path, 'sha256' => hash_file('sha256', $path), 'resume_from' => 'migrate_database',
                    'supersessions' => $facts['selection']['supersessions'], 'safe_retry' => false, 'execution_admitted' => false];
            }
            $planPath = $input['plan'] ?? '';
            if (!is_string($planPath) || realpath(dirname($planPath)) !== $this->partialDirectory('partial-plans', $access['resources'])
                || preg_match('/\Aplan-[a-f0-9]{32}\.json\z/D', basename($planPath)) !== 1) { throw new RuntimeException('INSTALL_PARTIAL_PLAN_REQUIRED'); }
            $plan = $this->partialJson($planPath); $unsigned = $plan; unset($unsigned['document_sha256']);
            if (($plan['protocol'] ?? null) !== 'peanut.installation-partial-plan.v1' || ($plan['resume_from'] ?? null) !== 'migrate_database'
                || ($plan['document_sha256'] ?? null) !== self::partialDigest($unsigned) || ($plan['facts'] ?? null) !== $facts) { throw new RuntimeException('INSTALL_PARTIAL_PLAN_CHANGED'); }
            $recheck = function () use ($input, $access, $facts, $pdo, $planPath, $plan): void {
                if ($this->partialNativeAccess($input, false) !== $access || Db::connect()->getPdo() !== $pdo || $pdo->inTransaction()
                    || $this->partialFacts($input, $access, $pdo) !== $facts || $this->partialJson($planPath) !== $plan) { throw new RuntimeException('INSTALL_PARTIAL_FACTS_CHANGED'); }
            };
            $recheck();
            if ($operation === 'backup') {
                if (isset($input['backup']) || !is_string($input['backup_tool'] ?? null) || !is_string($input['backup_output'] ?? null)) { throw new RuntimeException('INSTALL_PARTIAL_ARGUMENTS_INVALID'); }
                $output = $input['backup_output'];
                $backupDirectory = $this->partialDirectory('partial-backups', $access['resources']);
                if (realpath(dirname($output)) !== $backupDirectory || preg_match('/\A[a-zA-Z0-9][a-zA-Z0-9_-]{0,63}\z/D', basename($output)) !== 1) { throw new RuntimeException('INSTALL_PARTIAL_BACKUP_OUTPUT_REQUIRED'); }
                $this->partialOutputGrant($output, $access['resources']);
                if (!in_array($input['backup_tool'], $access['resources']['tooling-resource-id'] ?? [], true)) { throw new RuntimeException('INSTALL_PARTIAL_BACKUP_TOOL_REQUIRED'); }
                $tools = array_values(array_filter($access['registry']['resources']['tooling'] ?? [], static fn(array $row): bool => ($row['stable_resource_id'] ?? null) === $input['backup_tool']));
                if (count($tools) !== 1) { throw new RuntimeException('INSTALL_PARTIAL_BACKUP_TOOL_REQUIRED'); }
                $tool = $tools[0];
                $this->partialReadGrant($access['resources'], $access['schema'], $facts['scope']['tables'], false, $access['legacy_exclusive']);
                return \app\common\services\upgrade\SourceApplicationBackup::capture($facts['binding'], $facts['baseline'], self::partialDigest($facts['baseline']),
                    $facts['scope'], $tool, $output, $recheck, 'installation-partial');
            }
            if (isset($input['backup_tool']) || isset($input['backup_output']) || !is_string($input['backup'] ?? null)) { throw new RuntimeException('INSTALL_PARTIAL_ARGUMENTS_INVALID'); }
            $backup = \app\common\services\upgrade\SourceApplicationBackup::partialInstallationBackup($input['backup'], $this->serverRoot, $facts['binding'], $facts['baseline']);
            $recheck(); $stage = 'migrate_database';
            $this->partialGuard = function () use ($input, $access, $facts, $pdo, $context, $backup): void {
                if (app()->make(\app\common\execution\CurrentExecutionContext::class)->current() !== $context
                    || $this->partialNativeAccess($input, true) !== $access || Db::connect()->getPdo() !== $pdo
                    || self::migrationDatabaseIdentity($pdo) !== $facts['binding']['database']
                    || ApplicationSourceIdentity::load($this->serverRoot)->identitySha256() !== $facts['binding']['source']['runtime_sha256']
                    || $this->partialCoreFingerprint() !== $facts['binding']['source']['loaded_core_sha256']
                    || hash_file('sha256', PHP_BINARY) !== $facts['binding']['source']['php_binary_sha256']) { throw new RuntimeException('INSTALL_PARTIAL_FACTS_CHANGED'); }
                if ($this->partialLockBinding() !== $facts['baseline']['execution_lock']) { throw new RuntimeException('INSTALL_PARTIAL_EXECUTION_LOCK_CHANGED'); }
                $tables = $pdo->query('SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() ORDER BY TABLE_NAME')->fetchAll(PDO::FETCH_COLUMN);
                $effects = \app\common\services\upgrade\SourceApplicationBackup::partialInstallationScope($pdo, $tables);
                if ($effects['effect_boundary'] !== $facts['scope']['effect_boundary']) { throw new RuntimeException('INSTALL_PARTIAL_EFFECT_BOUNDARY_CHANGED'); }
                $this->partialFileReference(['path' => $this->progressMarker(), 'sha256' => $input['original_progress']['sha256']]);
                foreach ($facts['baseline']['original_files'] as $original) { $this->partialFileReference($original); }
                if ($this->fileDigest($this->progressMarker()) !== $input['original_progress']['sha256']
                    || $this->fileDigest($this->serverRoot . '/private/resources/configuration.json') !== $input['original_configuration']['sha256']) { throw new RuntimeException('INSTALL_PARTIAL_FACTS_CHANGED'); }
                $maintenance = $this->partialReference($input['maintenance']);
                if ($maintenance['expires_at'] <= time()) { throw new RuntimeException('INSTALL_PARTIAL_MAINTENANCE_REQUIRED'); }
                foreach ($maintenance['receipts'] as $receipt) { $this->partialFileReference($receipt); }
                $current = $pdo->query('SELECT * FROM pa_schema_migration ORDER BY migration_id')->fetchAll(PDO::FETCH_ASSOC);
                $old = array_values(array_filter($current, static fn(array $row): bool => $row['migration_id'] !== \app\common\services\upgrade\ApplicationMigrationRunner::PROVENANCE_CORRECTION));
                if ($old !== $facts['baseline']['original_ledger'] || \app\common\services\upgrade\SourceApplicationBackup::partialInstallationBackup($input['backup'], $this->serverRoot, $facts['binding'], $facts['baseline']) !== $backup) { throw new RuntimeException('INSTALL_PARTIAL_ORIGINAL_HISTORY_CHANGED'); }
            };
            ($this->partialGuard)();
            $attempt = $this->serverRoot . '/private/partial-installation/attempt.json'; $this->partialOutputGrant($attempt, $access['resources']);
            \app\common\services\upgrade\SourceApplicationBackup::writePartialInstallationRecord($attempt, ['protocol' => 'peanut.installation-partial-attempt.v1',
                'plan' => ['path' => $planPath, 'sha256' => hash_file('sha256', $planPath)], 'backup' => $backup, 'request_id' => $context->requestId(),
                'started_at' => time(), 'stage' => $stage, 'safe_retry' => false, 'progress_sha256' => $input['original_progress']['sha256']]);
            try {
                $runner = new \app\common\services\upgrade\ApplicationMigrationRunner($access['config'], $pdo);
                $versions = \applicationReleaseVersions($this->serverRoot);
                $migration = $runner->run(\applicationMigrationFiles($this->serverRoot . '/database'), $this->migrationTargetVersion(), $versions['release_sequence_version'], false,
                    function (PDO $actual, array $migration) use ($pdo): bool {
                        if ($actual !== $pdo || $migration['id'] !== \app\common\services\upgrade\ApplicationMigrationRunner::PROVENANCE_CORRECTION
                            || $migration['checksum'] !== \app\common\services\upgrade\ApplicationMigrationRunner::PROVENANCE_CORRECTION_SHA) { throw new RuntimeException('INSTALL_PARTIAL_EXACT_FAILED_STAGE_REQUIRED'); }
                        ($this->partialGuard)(); return false;
                    }, null, function (PDO $actual, array $migration, ?array $bounds) use ($pdo): void {
                        if ($actual !== $pdo || $bounds !== null || $migration['id'] !== \app\common\services\upgrade\ApplicationMigrationRunner::PROVENANCE_CORRECTION) { throw new RuntimeException('INSTALL_PARTIAL_EXACT_FAILED_STAGE_REQUIRED'); }
                        ($this->partialGuard)();
                    });
                if (($migration['applied'] ?? null) !== [\app\common\services\upgrade\ApplicationMigrationRunner::PROVENANCE_CORRECTION]
                    || ($migration['supersessions'][0]['state'] ?? null) !== 'resolved_by_correction') { throw new RuntimeException('INSTALL_PARTIAL_MIGRATION_INCOMPLETE'); }
                $query = $pdo->query('SELECT * FROM pa_schema_migration ORDER BY migration_id'); $rows = $query->fetchAll(PDO::FETCH_ASSOC); $query->closeCursor();
                $original = array_values(array_filter($rows, static fn(array $row): bool => $row['migration_id'] !== \app\common\services\upgrade\ApplicationMigrationRunner::PROVENANCE_CORRECTION));
                if ($original !== $facts['baseline']['original_ledger']) { throw new RuntimeException('INSTALL_PARTIAL_ORIGINAL_HISTORY_CHANGED'); }
                if ($this->partialNativeAccess($input, true) !== $access || $this->fileDigest($this->progressMarker()) !== $facts['baseline']['original_files']['progress']['sha256']) { throw new RuntimeException('INSTALL_PARTIAL_FACTS_CHANGED'); }
                $completion = $this->completeInitializedInstallation($facts['modules'], $lock, $stage);
                return ['state' => 'installed', 'code' => 'INSTALL_COMPLETED', 'resumed_from' => 'migrate_database',
                    'original_history_preserved' => true, 'migration' => $migration, 'afterimage_backup' => $backup, ...$completion];
            } catch (Throwable $exception) {
                \logInstallationFailure($this->serverRoot, $stage, 'INSTALL_PARTIAL_CONTINUATION_FAILED', $exception, $this->currentInstallationModule);
                throw new InstallationExecutionException('INSTALL_PARTIAL_CONTINUATION_FAILED', '续作未完成；保留原数据、进度和本次失败，不得自动重放。', 409, $exception,
                    ['category' => 'partial', 'stage' => $stage, 'safe_retry' => false, 'writes' => ['database' => 'may_have_partial_writes'],
                        ...\installationFailureDiagnostic($this->serverRoot, $stage, $exception, $this->currentInstallationModule)]);
            }
        } finally {
            $this->currentInstallationModule = null;
            $this->partialGuard = null; $this->moduleMigrationAdmission = null; $this->executionLock = null; flock($lock, LOCK_UN); fclose($lock);
        }
    }

    /** One fixed APP frontier. No Git checkout, maintainer registry or native Project lease is consumed here. */
    private function partialApplicationInstallation(string $operation, array $input, \app\common\execution\InstallationExecutionContext $context): array
    {
        require_once $this->serverRoot . '/docker/scripts/update-plan.php';
        $release = !ApplicationSourceIdentity::hasRuntime($this->serverRoot);
        return \PeanutServerUpdatePlan::withPartialInstallationOwner($this->serverRoot, function () use ($operation, $input, $context, $release): array {
            $lock = $this->acquireExecutionLock(); $this->executionLock = $lock;
            $directory = $this->partialDirectory('partial-plans', ['output-dir' => [$this->serverRoot . '/private/partial-installation']]);
            $completionPath = dirname($directory) . '/completion.json';
            $attemptPath = dirname($directory) . '/attempt.json';
            try {
                if (array_diff(array_keys($input), ['setup_token', 'plan', 'backup', 'backup_tool', 'backup_output']) !== []) {
                    throw new RuntimeException('INSTALL_PARTIAL_ARGUMENTS_INVALID');
                }
                if (file_exists($completionPath) || is_link($completionPath)) {
                    if ($operation !== 'apply' || !is_string($input['plan'] ?? null)) { throw new RuntimeException('INSTALL_PARTIAL_FINALIZE_ONLY'); }
                    $completion = \PeanutServerUpdatePlan::readPartialOwnerRecord($this->serverRoot, $completionPath);
                    if (($completion['plan']['path'] ?? null) !== $input['plan']
                        || ($completion['plan']['sha256'] ?? null) !== $this->fileDigest($input['plan'])
                        || (isset($input['backup']) && $input['backup'] !== ($completion['backup']['path'] ?? null))) { throw new RuntimeException('INSTALL_PARTIAL_FINALIZE_PLAN_CHANGED'); }
                    $completedPlan = \PeanutServerUpdatePlan::readPartialOwnerRecord($this->serverRoot, $input['plan']);
                    if ($this->partialCoreFingerprint() !== $completedPlan['facts']['binding']['source']['loaded_core_sha256']
                        || hash_file('sha256', PHP_BINARY) !== $completedPlan['facts']['binding']['source']['php_binary_sha256']
                        || ($release ? ServerReleaseIdentity::load($this->serverRoot)->identitySha256() !== ($completion['source']['release']['sha256'] ?? null)
                            : ApplicationSourceIdentity::load($this->serverRoot)->identitySha256() !== $completion['source']['runtime_sha256'])) {
                        throw new RuntimeException('INSTALL_PARTIAL_FINALIZE_SOURCE_CHANGED');
                    }
                    $this->partialApplicationWindow();
                    $result = $release ? $this->notificationPartialFinalize($completionPath) : \PeanutServerUpdatePlan::finalizePartialInstallation($this->serverRoot, $completionPath);
                    if (is_file($this->progressMarker()) && !unlink($this->progressMarker())) { throw new RuntimeException('INSTALL_PARTIAL_PROGRESS_FINALIZE_REQUIRED'); }
                    return ['state' => 'installed', 'code' => 'INSTALL_COMPLETED', 'resumed_from' => 'finalize_only', 'safe_retry' => false, ...$result];
                }
                if ($this->completionLockPresent() || file_exists($this->baselineMarker()) || is_link($this->baselineMarker())
                    || file_exists($attemptPath) || is_link($attemptPath)) { throw new RuntimeException('INSTALL_PARTIAL_ATTEMPT_REQUIRES_RECONCILIATION'); }
                if (isset($input['actor']) || isset($input['maintenance'])) { throw new RuntimeException('INSTALL_PARTIAL_APP_NATIVE_WINDOW_REQUIRED'); }
                $source = $release ? $this->notificationPartialSource() : \PeanutServerUpdatePlan::partialInstallationSource($this->serverRoot);
                $config = \guardedDatabaseConfig();
                $registry = $this->partialJson($this->serverRoot . '/private/resources/project-resources.json');
                $resource = \registeredDatabase($registry, $config['resource_id']);
                $consumer = $release ? $config['consumer'] : (is_file('/.dockerenv') ? 'container' : 'host');
                $endpoint = \registeredDatabaseEndpoint($resource, $consumer);
                if (($registry['authority']['role'] ?? null) !== 'application'
                    || !in_array(\requiredEnvironment('PEANUT_DATABASE_SOURCE'), $release ? ['registered', 'bundled'] : ['registered'], true)
                    || (!$release && ($config['environment'] !== 'development' || $config['deployment_target'] !== 'local-multi-tenant-demo'))
                    || ($release && (\deploymentTargetContract($config['deployment_target'])['app_environment'] !== Config::get('peanut.environment')
                        || !in_array(\deploymentTargetContract($config['deployment_target'])['resource_environment'], $resource['environments'] ?? [$resource['environment'] ?? null], true)))
                    || $config['database'] !== $resource['database'] || $config['host'] !== $endpoint['host'] || (int) $config['port'] !== $endpoint['port']) {
                    throw new RuntimeException('INSTALL_PARTIAL_APP_DATABASE_BINDING_REQUIRED');
                }
                $connection = Db::connect();
                foreach (['hostname' => 'host', 'hostport' => 'port', 'database' => 'database', 'username' => 'user', 'password' => 'password'] as $native => $key) {
                    if ((string) $connection->getConfig($native) !== (string) $config[$key]) { throw new RuntimeException('INSTALL_PARTIAL_MASTER_MISMATCH'); }
                }
                if ($connection->getConfig('prefix') !== 'pa_' || $connection->getConfig('break_reconnect') !== false
                    || (int) $connection->getConfig('deploy') !== 0) { throw new RuntimeException('INSTALL_PARTIAL_MASTER_MISMATCH'); }
                $connection->query('SELECT 1', [], true); $pdo = $this->pdo();
                if ($pdo->inTransaction()) { throw new RuntimeException('INSTALL_PARTIAL_TRANSACTION_CONFLICT'); }
                $window = $this->partialApplicationWindow();
                $planPath = $input['plan'] ?? null;
                if ($operation === 'plan') {
                    if ($planPath !== null || isset($input['backup']) || isset($input['backup_output']) || !is_string($input['backup_tool'] ?? null)) { throw new RuntimeException('INSTALL_PARTIAL_ARGUMENTS_INVALID'); }
                    $toolPath = $input['backup_tool']; $tool = $this->partialJson($toolPath);
                    if (!str_starts_with($toolPath, dirname($directory) . '/')) { throw new RuntimeException('INSTALL_PARTIAL_APP_BACKUP_TOOL_REQUIRED'); }
                    $authority = ['kind' => 'app-native-owner', 'owner_uid' => posix_geteuid(), 'server_root' => $this->serverRoot,
                        'plan_id' => bin2hex(random_bytes(16)), 'created_at' => time(), 'expires_at' => time() + 1800];
                    $facts = $this->partialApplicationFacts($pdo, $source, $config, $endpoint, $window, $authority, $toolPath);
                    $ledgerPath = $directory . '/ledger-' . $authority['plan_id'] . '.json';
                    \app\common\services\upgrade\SourceApplicationBackup::writePartialInstallationRecord($ledgerPath,
                        ['application' => $facts['baseline']['original_ledger'], 'identity' => $facts['baseline']['identity_ledger']]);
                    $facts['baseline']['original_files']['ledger'] = ['path' => $ledgerPath, 'sha256' => $this->fileDigest($ledgerPath)];
                    $planPath = $directory . '/plan-' . $authority['plan_id'] . '.json';
                    $plan = ['protocol' => 'peanut.app-installation-partial-plan.v1', 'resume_from' => 'install_modules',
                        'facts' => $facts, 'source' => $source, 'tool' => ['path' => $toolPath, 'sha256' => $this->fileDigest($toolPath)],
                        'automatic_retry' => false, 'execution_admitted' => false];
                    \PeanutServerUpdatePlan::writePartialOwnerRecord($this->serverRoot, $planPath, $plan);
                    return ['status' => 'planned', 'path' => $planPath, 'sha256' => $this->fileDigest($planPath),
                        'resume_from' => 'install_modules', 'execution_admitted' => false, 'safe_retry' => false];
                }
                if (!is_string($planPath) || realpath(dirname($planPath)) !== $directory
                    || preg_match('/\Aplan-[a-f0-9]{32}\.json\z/D', basename($planPath)) !== 1) { throw new RuntimeException('INSTALL_PARTIAL_PLAN_REQUIRED'); }
                $plan = \PeanutServerUpdatePlan::readPartialOwnerRecord($this->serverRoot, $planPath);
                if (($plan['protocol'] ?? null) !== 'peanut.app-installation-partial-plan.v1' || $plan['source'] !== $source
                    || $plan['facts']['binding']['authority']['expires_at'] <= time()
                    || $plan['facts']['binding']['authority']['owner_uid'] !== posix_geteuid()) { throw new RuntimeException('INSTALL_PARTIAL_PLAN_CHANGED'); }
                $facts = $plan['facts'];
                $recheck = function () use ($pdo, $connection, $context, $planPath, $plan, $source, $config, $endpoint, $facts): void {
                    if ($pdo->inTransaction() || Db::connect() !== $connection || $connection->getPdo() !== $pdo
                        || app()->make(\app\common\execution\CurrentExecutionContext::class)->current() !== $context
                        || \PeanutServerUpdatePlan::readPartialOwnerRecord($this->serverRoot, $planPath) !== $plan
                        || (($source['kind'] ?? null) === 'notification-server-release' ? $this->notificationPartialSource() : \PeanutServerUpdatePlan::partialInstallationSource($this->serverRoot)) !== $source) { throw new RuntimeException('INSTALL_PARTIAL_FACTS_CHANGED'); }
                    $fresh = $this->partialApplicationFacts($pdo, $source, $config, $endpoint, $this->partialApplicationWindow(),
                        $facts['binding']['authority'], $plan['tool']['path'], $facts['baseline']['effect_metadata']);
                    $fresh['baseline']['original_files']['ledger'] = $facts['baseline']['original_files']['ledger'];
                    if ($fresh !== $facts || $this->fileDigest($plan['tool']['path']) !== $plan['tool']['sha256']) { throw new RuntimeException('INSTALL_PARTIAL_FACTS_CHANGED'); }
                    $this->partialFileReference($facts['baseline']['original_files']['ledger']);
                };
                $recheck();
                if ($operation === 'backup') {
                    if (isset($input['backup']) || !is_string($input['backup_output'] ?? null)
                        || (isset($input['backup_tool']) && $input['backup_tool'] !== $plan['tool']['path'])) { throw new RuntimeException('INSTALL_PARTIAL_ARGUMENTS_INVALID'); }
                    $backupDirectory = $this->partialDirectory('partial-backups', ['output-dir' => [dirname($directory)]]);
                    $output = $input['backup_output'];
                    if (realpath(dirname($output)) !== $backupDirectory || preg_match('/\A[a-zA-Z0-9][a-zA-Z0-9_-]{0,63}\z/D', basename($output)) !== 1) { throw new RuntimeException('INSTALL_PARTIAL_BACKUP_OUTPUT_REQUIRED'); }
                    return \app\common\services\upgrade\SourceApplicationBackup::capture($facts['binding'], $facts['baseline'], self::partialDigest($facts['baseline']),
                        $facts['scope'], $this->partialJson($plan['tool']['path']), $output, $recheck, 'installation-partial');
                }
                if (!is_string($input['backup'] ?? null) || isset($input['backup_output']) || isset($input['backup_tool'])) { throw new RuntimeException('INSTALL_PARTIAL_ARGUMENTS_INVALID'); }
                $backup = \app\common\services\upgrade\SourceApplicationBackup::partialInstallationBackup($input['backup'], $this->serverRoot, $facts['binding'], $facts['baseline'], $facts['scope']);
                $recheck();
                $this->partialGuard = function () use ($pdo, $connection, $context, $facts, $source, $plan, $backup, $input): void {
                    if (($source['kind'] ?? null) === 'notification-server-release') {
                        $this->notificationPartialGuard($pdo, $connection, $context, $facts, $source, $plan, $backup, $input);
                        return;
                    }
                    if (Db::connect() !== $connection || $connection->getPdo() !== $pdo
                        || app()->make(\app\common\execution\CurrentExecutionContext::class)->current() !== $context
                        || time() >= $facts['binding']['authority']['expires_at'] || $this->partialLockBinding() !== $facts['baseline']['execution_lock']
                        || self::migrationDatabaseIdentity($pdo) !== $facts['binding']['database']
                        || ApplicationSourceIdentity::load($this->serverRoot)->identitySha256() !== $source['runtime_sha256']
                        || $this->partialCoreFingerprint() !== $facts['binding']['source']['loaded_core_sha256']
                        || hash_file('sha256', PHP_BINARY) !== $facts['binding']['source']['php_binary_sha256']
                        || $this->fileDigest($source['checkpoint']['path']) !== $source['checkpoint']['sha256']
                        || $this->fileDigest($this->serverRoot . '/private/installation/deployment.json') !== $source['deployment']['head_sha256']
                        || $this->partialApplicationWindow() !== $facts['window']) { throw new RuntimeException('INSTALL_PARTIAL_HELD_AUTHORITY_CHANGED'); }
                    foreach ($facts['baseline']['original_files'] as $name => $ref) {
                        if ($name === 'runtime') { $this->partialApplicationRuntimeReference($ref); }
                        else { $this->partialFileReference($ref); }
                    }
                    if ($this->fileDigest($plan['tool']['path']) !== $plan['tool']['sha256']
                        || \PeanutServerUpdatePlan::readPartialOwnerRecord($this->serverRoot, $input['plan']) !== $plan
                        || \app\common\services\upgrade\SourceApplicationBackup::partialInstallationBackup($input['backup'], $this->serverRoot, $facts['binding'], $facts['baseline'], $facts['scope']) !== $backup
                        || $pdo->query('SELECT * FROM pa_schema_migration ORDER BY migration_id')->fetchAll(PDO::FETCH_ASSOC) !== $facts['baseline']['original_ledger']
                        || $pdo->query("SELECT * FROM pa_module_migration WHERE module_key='official.identity' ORDER BY migration_key")->fetchAll(PDO::FETCH_ASSOC) !== $facts['baseline']['identity_ledger']) {
                        throw new RuntimeException('INSTALL_PARTIAL_ORIGINAL_HISTORY_CHANGED');
                    }
                    $metadata = $this->partialApplicationMetadata($pdo, $facts['binding'], $source, $facts['window'], $plan['tool']['path']);
                    $tables = $pdo->query('SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() ORDER BY TABLE_NAME')->fetchAll(PDO::FETCH_COLUMN);
                    if (\app\common\services\upgrade\SourceApplicationBackup::appPartialScope($pdo, $tables,
                        $facts['binding'], $source, $facts['window'], $plan['tool']['path'], $metadata)['effect_boundary'] !== $facts['scope']['effect_boundary']) {
                        throw new RuntimeException('INSTALL_PARTIAL_EFFECT_BOUNDARY_CHANGED');
                    }
                };
                ($this->partialGuard)();
                $this->fileContinuation = $release ? null : ['pdo' => $pdo, 'context' => $context, 'token' => new \stdClass(),
                    'authority_sha256' => self::partialDigest([$facts['binding'], $backup, $plan['tool']]),
                    'identity_sha256' => $facts['baseline']['identity_rows_sha256'], 'config_sha256' => $facts['baseline']['config_rows_sha256']];
                $this->partialCompletionIntent = function (array $baseline, array $receipt, array $health) use ($completionPath, $planPath, $source, $backup, $facts): void {
                    \PeanutServerUpdatePlan::writePartialOwnerRecord($this->serverRoot, $completionPath,
                        ['protocol' => 'peanut.installation-partial-completion.v1', 'phase' => 'native-health-complete-intent',
                            'native_health_sha256' => self::partialDigest($health), 'health_completed_at' => time(),
                            'source' => $source, 'modules' => $baseline['official_modules'],
                            'plan' => ['path' => $planPath, 'sha256' => $this->fileDigest($planPath)], 'backup' => $backup,
                            'database' => $facts['binding']['database'],
                            'installed_receipt_sha256' => hash('sha256', json_encode($receipt, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES) . "\n"),
                            'baseline_sha256' => $receipt['baseline_manifest']['sha256']]);
                };
                $this->partialCompletion = function (array $receipt, array $modules) use ($completionPath, $release): void {
                    if ($release) { $this->notificationPartialFinalize($completionPath); }
                    else { \PeanutServerUpdatePlan::finalizePartialInstallation($this->serverRoot, $completionPath); }
                };
                \PeanutServerUpdatePlan::writePartialOwnerRecord($this->serverRoot, $attemptPath,
                    ['protocol' => 'peanut.app-installation-partial-attempt.v1', 'plan' => ['path' => $planPath, 'sha256' => $this->fileDigest($planPath)],
                        'backup' => $backup, 'request_id' => $context->requestId(), 'started_at' => time(), 'safe_retry' => false]);
                $stage = 'install_modules';
                try {
                    $completion = $this->completeInitializedInstallation($facts['modules'], $lock, $stage);
                    return ['state' => 'installed', 'code' => 'INSTALL_COMPLETED', 'resumed_from' => 'install_modules',
                        'original_history_preserved' => true, 'afterimage_backup' => $backup, ...$completion];
                } catch (Throwable $error) {
                    \logInstallationFailure($this->serverRoot, $stage, 'INSTALL_PARTIAL_CONTINUATION_FAILED', $error, $this->currentInstallationModule);
                    throw new InstallationExecutionException('INSTALL_PARTIAL_CONTINUATION_FAILED', '续作未完成；保留当前数据、原记录和失败现场，不得自动重放。', 409, $error,
                        ['stage' => $stage, 'safe_retry' => false, 'writes' => ['database' => 'may_have_partial_writes'],
                            ...\installationFailureDiagnostic($this->serverRoot, $stage, $error, $this->currentInstallationModule)]);
                }
            } finally {
                $this->partialCompletion = null; $this->partialCompletionIntent = null; $this->fileContinuation = null; $this->partialGuard = null;
                $this->currentInstallationModule = null; $this->moduleMigrationAdmission = null; $this->executionLock = null;
                flock($lock, LOCK_UN); fclose($lock);
            }
        });
    }

    /** Server-only C has a real release identity and no APP source checkpoint. */
    private function notificationPartialSource(): array
    {
        if (ApplicationSourceIdentity::hasRuntime($this->serverRoot)) { throw new RuntimeException('INSTALL_PARTIAL_RELEASE_REQUIRED'); }
        foreach (['private/installation/deployment.json', 'runtime/upgrade/source-transition.json'] as $relative) {
            if (file_exists($this->serverRoot . '/' . $relative) || is_link($this->serverRoot . '/' . $relative)) { throw new RuntimeException('INSTALL_PARTIAL_RELEASE_SOURCE_CONFLICT'); }
        }
        $identity = ServerReleaseIdentity::load($this->serverRoot);
        $progress = $this->partialJson($this->progressMarker());
        $modules = ['official.identity', 'official.content-tools', 'official.file', 'official.rich-text', 'official.document', 'official.article',
            'official.collaboration', 'official.task', 'official.settings', 'official.integration', 'official.import-export', 'official.member',
            'official.notification', 'official.oauth', 'official.ops', 'official.payment', 'official.reference-codes'];
        if (($progress['schema_version'] ?? null) !== 1 || ($progress['state'] ?? null) !== 'executing'
            || ($progress['official_modules'] ?? null) !== $modules || ($progress['deployment_mode'] ?? null) !== $this->deploymentMode()
            || $identity->applicationIdentity()['edition'] !== $this->deploymentMode()) { throw new RuntimeException('INSTALL_PARTIAL_NOTIFICATION_FRONTIER_REQUIRED'); }
        $failure = \PeanutServerUpdatePlan::partialApplicationFailure($this->serverRoot, $progress);
        if (($failure['frontier'] ?? null) !== 'notification-fresh-sentinel') { throw new RuntimeException('INSTALL_PARTIAL_NOTIFICATION_FAILURE_REQUIRED'); }
        $files = [];
        foreach (['private/installation/executing.json', 'private/resources/configuration.json', 'private/resources/project-resources.json', '.env', $failure['path']] as $relative) {
            $ref = ['path' => $this->serverRoot . '/' . $relative, 'sha256' => $this->fileDigest($this->serverRoot . '/' . $relative)];
            $this->partialFileReference($ref); $files[$relative] = $ref['sha256'];
        }
        return ['kind' => 'notification-server-release', 'release' => \app\common\services\upgrade\SourceApplicationBackup::partialRuntimeOriginalReference($this->serverRoot, true),
            'files' => $files, 'failure' => $failure, 'modules' => $modules, 'execution_lock' => $this->partialLockBinding()];
    }

    private function notificationPartialFacts(PDO $pdo, array $source, array $config, array $endpoint, array $window, array $authority, string $toolPath, ?array $origin): array
    {
        if ($pdo->inTransaction()) { throw new RuntimeException('INSTALL_PARTIAL_TRANSACTION_CONFLICT'); }
        $binding = $this->partialApplicationBinding($pdo, $source, $config, $endpoint, $authority);
        $metadata = $this->partialApplicationMetadata($pdo, $binding, $source, $window, $toolPath);
        $pdo->exec('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ'); $pdo->exec('SET TRANSACTION READ ONLY'); $pdo->beginTransaction();
        try {
            $history = \PeanutServerUpdatePlan::notificationPartialHistory($this->serverRoot, $pdo);
            $application = $pdo->query('SELECT * FROM pa_schema_migration ORDER BY migration_id')->fetchAll(PDO::FETCH_ASSOC);
            if (count($application) !== 1 || $application[0]['migration_id'] !== '20261019-clean-install-5.0.2-r2'
                || $application[0]['status'] !== 'applied' || $application[0]['release_version'] !== '5.0.2'
                || $application[0]['error_code'] !== null || !is_string($application[0]['finished_at'])
                || $application[0]['checksum'] !== $this->fileDigest($this->serverRoot . '/database/baselines/5.0.2-r2/manifest.json')) {
                throw new RuntimeException('INSTALL_PARTIAL_ORIGINAL_HISTORY_CHANGED');
            }
            $priorAdmission = $this->moduleMigrationAdmission;
            try {
                $this->initializeModuleMigrationAdmission($this->executionLock);
                $governance = new ThinkPhpModuleGovernanceProvider($this->serverRoot, $this->moduleConfig(), $this->catalogs, $this->definitionRegistry());
                $filePlan = $governance->pluginLifecycle()->reconcilePlan('official.file');
                if (($filePlan['action'] ?? null) !== 'planned_unchanged' || ($filePlan['may_apply'] ?? null) !== true
                    || ($filePlan['planning_complete'] ?? null) !== true) { throw new RuntimeException('INSTALL_PARTIAL_FILE_PROVENANCE_UNRESOLVED'); }
            } finally { $this->moduleMigrationAdmission = $priorAdmission; }
            $tables = $pdo->query('SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() ORDER BY TABLE_NAME')->fetchAll(PDO::FETCH_COLUMN);
            if (count($tables) !== 138) { throw new RuntimeException('INSTALL_PARTIAL_NOTIFICATION_OBJECT_FRONTIER_CHANGED'); }
            $scope = \app\common\services\upgrade\SourceApplicationBackup::appPartialScope($pdo, $tables, $binding, $source, $window, $toolPath, $metadata);
            if ($origin !== null && ($origin['semantic_sha256'] ?? null) !== $scope['metadata_semantics_sha256']) { throw new RuntimeException('INSTALL_PARTIAL_METADATA_ORIGIN_CHANGED'); }
            $originals = [];
            foreach (['progress' => 'private/installation/executing.json', 'configuration' => 'private/resources/configuration.json',
                'failure' => $source['failure']['path'], 'environment' => '.env'] as $name => $relative) {
                $originals[$name] = ['path' => $this->serverRoot . '/' . $relative, 'sha256' => $source['files'][$relative]];
            }
            $originals['runtime'] = $source['release'];
            $identity = array_values(array_filter($history['ledger'], static fn(array $row): bool => $row['module_key'] === 'official.identity'));
            $catalogs = ['pa_data_condition_definition', 'pa_menu_definition', 'pa_module_installation', 'pa_permission', 'pa_protected_resource',
                'pa_resource_operation', 'pa_resource_operation_condition', 'pa_resource_operation_permission', 'pa_resource_operation_target_type',
                'pa_system_menu', 'pa_target_type', 'pa_tenant_module', 'pa_platform_audit_event', 'pa_tenant_audit_event'];
            $data = [];
            foreach (array_diff($history['owned_tables'], $catalogs) as $table) { $data[$table] = $this->partialTableRows($pdo, $table); }
            return ['binding' => $binding, 'modules' => $source['modules'], 'scope' => $scope, 'window' => $window,
                'baseline' => ['pending' => ['official.notification'], 'selected' => ['official.notification' => $this->fileDigest($this->serverRoot . '/app/modules/official/notification/module.json')],
                    'original_ledger' => $application, 'identity_ledger' => $identity, 'predecessors' => $history, 'predecessor_rows_sha256' => $data,
                    'effect_metadata' => $origin ?? ['reference' => $metadata, 'semantic_sha256' => $scope['metadata_semantics_sha256']],
                    'original_files' => $originals, 'execution_lock' => $this->partialLockBinding(),
                    'identity_rows_sha256' => $this->partialIdentityRows($pdo), 'config_rows_sha256' => $this->partialTableRows($pdo, 'pa_config')]];
        } finally { if ($pdo->inTransaction() && !$pdo->rollBack()) { throw new RuntimeException('INSTALL_PARTIAL_SNAPSHOT_CHANGED'); } }
    }

    /** Recheck immutable preimages throughout SQL; newly installed modules may add their own rows. */
    private function notificationPartialGuard(PDO $pdo, PDOConnection $connection, \app\common\execution\InstallationExecutionContext $context,
        array $facts, array $source, array $plan, array $backup, array $input): void
    {
        if (Db::connect() !== $connection || $connection->getPdo() !== $pdo
            || app()->make(\app\common\execution\CurrentExecutionContext::class)->current() !== $context
            || time() >= $facts['binding']['authority']['expires_at'] || $this->notificationPartialSource() !== $source
            || self::migrationDatabaseIdentity($pdo) !== $facts['binding']['database']
            || $this->partialCoreFingerprint() !== $facts['binding']['source']['loaded_core_sha256']
            || hash_file('sha256', PHP_BINARY) !== $facts['binding']['source']['php_binary_sha256']
            || $this->partialApplicationWindow() !== $facts['window']
            || $this->fileDigest($plan['tool']['path']) !== $plan['tool']['sha256']
            || \PeanutServerUpdatePlan::readPartialOwnerRecord($this->serverRoot, $input['plan']) !== $plan
            || \app\common\services\upgrade\SourceApplicationBackup::partialInstallationBackup($input['backup'], $this->serverRoot, $facts['binding'], $facts['baseline'], $facts['scope']) !== $backup
            || $pdo->query('SELECT * FROM pa_schema_migration ORDER BY migration_id')->fetchAll(PDO::FETCH_ASSOC) !== $facts['baseline']['original_ledger']) {
            throw new RuntimeException('INSTALL_PARTIAL_HELD_AUTHORITY_CHANGED');
        }
        $old = $facts['baseline']['predecessors'];
        foreach (['plugins' => ['pa_plugin_installation', 'plugin_key'], 'modules' => ['pa_module_installation', 'module_key'], 'ledger' => ['pa_module_migration', 'migration_key']] as $name => [$table, $key]) {
            $keys = array_column($old[$name], $key);
            $query = $pdo->query('SELECT * FROM ' . $table . ' ORDER BY ' . ($name === 'ledger' ? 'module_key,migration_key' : $key));
            $rows = array_values(array_filter($query->fetchAll(PDO::FETCH_ASSOC), static fn(array $row): bool => in_array($row[$key], $keys, true)));
            if ($rows !== $old[$name]) { throw new RuntimeException('INSTALL_PARTIAL_PREDECESSOR_HISTORY_CHANGED'); }
        }
        foreach ($old['source_files'] as $path => $sha) { if ($this->fileDigest($path) !== $sha) { throw new RuntimeException('INSTALL_PARTIAL_PREDECESSOR_SOURCE_CHANGED'); } }
        foreach ($facts['baseline']['predecessor_rows_sha256'] as $table => $sha) {
            if ($this->partialTableRows($pdo, $table) !== $sha) { throw new RuntimeException('INSTALL_PARTIAL_PREDECESSOR_DATA_CHANGED'); }
        }
        if ($this->partialIdentityRows($pdo) !== $facts['baseline']['identity_rows_sha256'] || $this->partialTableRows($pdo, 'pa_config') !== $facts['baseline']['config_rows_sha256']) {
            throw new RuntimeException('INSTALL_PARTIAL_IDENTITY_OR_CONFIGURATION_CHANGED');
        }
        foreach ($facts['baseline']['original_files'] as $name => $ref) {
            if ($name === 'runtime') {
                if (\app\common\services\upgrade\SourceApplicationBackup::partialRuntimeOriginalReference($this->serverRoot, true) !== $ref) { throw new RuntimeException('INSTALL_PARTIAL_RELEASE_CHANGED'); }
            } else { $this->partialFileReference($ref); }
        }
        $metadata = $this->partialApplicationMetadata($pdo, $facts['binding'], $source, $facts['window'], $plan['tool']['path']);
        $tables = $pdo->query('SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() ORDER BY TABLE_NAME')->fetchAll(PDO::FETCH_COLUMN);
        if (\app\common\services\upgrade\SourceApplicationBackup::appPartialScope($pdo, $tables, $facts['binding'], $source,
            $facts['window'], $plan['tool']['path'], $metadata)['effect_boundary'] !== $facts['scope']['effect_boundary']) {
            throw new RuntimeException('INSTALL_PARTIAL_EFFECT_BOUNDARY_CHANGED');
        }
        if (file_exists($this->baselineMarker()) || is_link($this->baselineMarker())) {
            $intent = \PeanutServerUpdatePlan::readPartialOwnerRecord($this->serverRoot, $this->serverRoot . '/private/partial-installation/completion.json');
            if (($intent['baseline_sha256'] ?? null) !== $this->fileDigest($this->baselineMarker())) { throw new RuntimeException('INSTALL_PARTIAL_COMPLETION_BASELINE_CHANGED'); }
        }
    }

    /** Only an authentic health intent plus the exact real pair can finalize; no SQL or traffic action. */
    private function notificationPartialFinalize(string $path): array
    {
        $record = \PeanutServerUpdatePlan::readPartialOwnerRecord($this->serverRoot, $path);
        $plan = \PeanutServerUpdatePlan::readPartialOwnerRecord($this->serverRoot, $record['plan']['path']);
        $facts = self::verifiedInstallationFacts($this->serverRoot); $source = $record['source'];
        $config = \guardedDatabaseConfig();
        $connection = Db::connect();
        foreach (['hostname' => 'host', 'hostport' => 'port', 'database' => 'database', 'username' => 'user', 'password' => 'password'] as $native => $key) {
            if ((string) $connection->getConfig($native) !== (string) $config[$key]) { throw new RuntimeException('INSTALL_PARTIAL_MASTER_MISMATCH'); }
        }
        if (($record['protocol'] ?? null) !== 'peanut.installation-partial-completion.v1' || ($record['phase'] ?? null) !== 'native-health-complete-intent'
            || preg_match('/\A[a-f0-9]{64}\z/D', (string) ($record['native_health_sha256'] ?? '')) !== 1
            || ($plan['protocol'] ?? null) !== 'peanut.app-installation-partial-plan.v1' || $plan['source'] !== $source
            || ($source['kind'] ?? null) !== 'notification-server-release'
            || $this->partialApplicationWindow() !== $plan['facts']['window']
            || $config['resource_id'] !== $plan['facts']['binding']['resource_id'] || $config['endpoint_id'] !== $plan['facts']['binding']['endpoint_id']
            || $this->partialCoreFingerprint() !== $plan['facts']['binding']['source']['loaded_core_sha256']
            || hash_file('sha256', PHP_BINARY) !== $plan['facts']['binding']['source']['php_binary_sha256']
            || $this->fileDigest($record['plan']['path']) !== $record['plan']['sha256']
            || !is_int($record['health_completed_at'] ?? null) || $record['health_completed_at'] < $plan['facts']['binding']['authority']['created_at']
            || $record['health_completed_at'] > $plan['facts']['binding']['authority']['expires_at'] || $record['health_completed_at'] > time()
            || ServerReleaseIdentity::load($this->serverRoot)->identitySha256() !== $source['release']['sha256']
            || \app\common\services\upgrade\SourceApplicationBackup::partialRuntimeOriginalReference($this->serverRoot, true) !== $source['release']
            || $this->partialLockBinding() !== $source['execution_lock']
            || $facts['receipt_sha256'] !== $record['installed_receipt_sha256'] || $facts['baseline_sha256'] !== $record['baseline_sha256']
            || ($facts['baseline']['source']['server_release_identity_sha256'] ?? null) !== $source['release']['sha256']
            || $facts['receipt']['official_modules'] !== $record['modules']
            || self::migrationDatabaseIdentity($this->pdo()) !== $record['database'] || $record['database'] !== $plan['facts']['binding']['database']
            || \app\common\services\upgrade\SourceApplicationBackup::partialInstallationBackup($record['backup']['path'], $this->serverRoot,
                $plan['facts']['binding'], $plan['facts']['baseline'], $plan['facts']['scope']) !== $record['backup']) {
            throw new RuntimeException('INSTALL_PARTIAL_FINALIZE_PAIR_REQUIRED');
        }
        foreach ($source['files'] as $relative => $sha) {
            $file = $this->serverRoot . '/' . $relative;
            if ($file === $this->progressMarker() && !file_exists($file) && !is_link($file)) { continue; }
            $this->partialFileReference(['path' => $file, 'sha256' => $sha]);
        }
        foreach (['private/installation/deployment.json', 'runtime/upgrade/source-transition.json'] as $relative) {
            if (file_exists($this->serverRoot . '/' . $relative) || is_link($this->serverRoot . '/' . $relative)) { throw new RuntimeException('INSTALL_PARTIAL_RELEASE_SOURCE_CONFLICT'); }
        }
        return ['status' => 'finalized', 'completion' => ['path' => $path, 'sha256' => $this->fileDigest($path)], 'safe_retry' => false];
    }

    /** The original runtime is public program identity; it is not an owner-only backup payload. */
    private function partialApplicationRuntimeReference(array $ref): void
    {
        if (\app\common\services\upgrade\SourceApplicationBackup::partialRuntimeOriginalReference($this->serverRoot) !== $ref) {
            throw new RuntimeException('INSTALL_PARTIAL_RUNTIME_ORIGINAL_CHANGED');
        }
    }

    /** Read-only invocation of the existing exact Compose guard. No shell, service action or replacement network. */
    private function partialApplicationWindow(): array
    {
        $script = $this->serverRoot . '/docker/scripts/update-recovery.py';
        $program = 'import importlib.util,json,sys;from pathlib import Path;s=importlib.util.spec_from_file_location("partial_native_guard",sys.argv[1]);m=importlib.util.module_from_spec(s);s.loader.exec_module(m);print(json.dumps(m.assert_stopped(Path(sys.argv[2]),allow_registered=True),sort_keys=True))';
        if (!ApplicationSourceIdentity::hasRuntime($this->serverRoot)) {
            $releaseSha = ServerReleaseIdentity::load($this->serverRoot)->identitySha256();
            $program = str_replace('allow_registered=True)', 'allow_registered=True,partial_release_identity=' . var_export($releaseSha, true) . ')', $program);
        }
        $process = proc_open(['python3', '-c', $program, $script, $this->serverRoot], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        if (!is_resource($process)) { throw new RuntimeException('INSTALL_PARTIAL_NATIVE_WINDOW_UNAVAILABLE'); }
        fclose($pipes[0]); stream_set_blocking($pipes[1], false); stream_set_blocking($pipes[2], false);
        $out = ''; $size = 0; $deadline = microtime(true) + 30; $terminal = null;
        try {
            do {
                $chunk = stream_get_contents($pipes[1]); $error = stream_get_contents($pipes[2]);
                $out .= $chunk; $size += strlen($chunk) + strlen($error);
                $status = proc_get_status($process);
                if (!$status['running']) { $terminal = $status['exitcode']; break; }
                if ($size > 65536 || microtime(true) >= $deadline) { proc_terminate($process); throw new RuntimeException('INSTALL_PARTIAL_NATIVE_WINDOW_UNAVAILABLE'); }
                usleep(10000);
            } while (true);
            $out .= stream_get_contents($pipes[1]);
        } finally { fclose($pipes[1]); fclose($pipes[2]); $closed = proc_close($process); }
        $window = json_decode($out, true, 32, JSON_THROW_ON_ERROR);
        $release = !ApplicationSourceIdentity::hasRuntime($this->serverRoot);
        $databaseSource = \requiredEnvironment('PEANUT_DATABASE_SOURCE');
        $containerKeys = $release && $databaseSource === 'bundled' ? ['mysql', 'nginx', 'php'] : ['nginx', 'php'];
        $actualKeys = array_keys($window['containers'] ?? []); sort($actualKeys, SORT_STRING);
        if (($terminal ?? $closed) !== 0 || !is_array($window)
            || ($window['database_binding']['source'] ?? null) !== ($release ? $databaseSource : 'registered')
            || !in_array($databaseSource, $release ? ['registered', 'bundled'] : ['registered'], true)
            || !is_array($window['admission_network'] ?? null) || $actualKeys !== $containerKeys) {
            throw new RuntimeException('INSTALL_PARTIAL_NATIVE_WINDOW_REQUIRED');
        }
        return $window;
    }

    private function partialApplicationBinding(PDO $pdo, array $source, array $config, array $endpoint, array $authority): array
    {
        if (($source['kind'] ?? null) === 'notification-server-release') {
            return ['source' => ['kind' => $source['kind'], 'server_root' => $this->serverRoot,
                    'release_identity_sha256' => $source['release']['sha256'], 'loaded_core_sha256' => $this->partialCoreFingerprint(),
                    'php_binary_sha256' => hash_file('sha256', PHP_BINARY)],
                'database' => self::migrationDatabaseIdentity($pdo), 'resource_id' => $config['resource_id'], 'endpoint_id' => $endpoint['endpoint_id'],
                'database_source' => \requiredEnvironment('PEANUT_DATABASE_SOURCE'), 'operator_consumer' => $config['consumer'], 'authority' => $authority,
                'registry_sha256' => $this->fileDigest($this->serverRoot . '/private/resources/project-resources.json')];
        }
        return ['source' => ['server_root' => $this->serverRoot, 'runtime_sha256' => $source['runtime_sha256'],
                'loaded_core_sha256' => $this->partialCoreFingerprint(), 'php_binary_sha256' => hash_file('sha256', PHP_BINARY)],
            'database' => self::migrationDatabaseIdentity($pdo), 'resource_id' => $config['resource_id'], 'endpoint_id' => $endpoint['endpoint_id'],
            'operator_consumer' => is_file('/.dockerenv') ? 'container' : 'host', 'authority' => $authority,
            'registry_sha256' => $this->fileDigest($this->serverRoot . '/private/resources/project-resources.json')];
    }

    /** Never calls facts or itself. A metadata subprocess must preserve the caller's transaction state. */
    private function partialApplicationMetadata(PDO $pdo, array $binding, array $source, array $window, string $toolPath): array
    {
        $physical = $binding['database']; unset($physical['transport']);
        $release = ($source['kind'] ?? null) === 'notification-server-release';
        if ((!$release && ($physical !== $source['deployment']['partial_installation']['database']
            || $binding['resource_id'] !== $source['deployment']['partial_installation']['resource_id']))
            || $window['database_binding']['resource_id'] !== $binding['resource_id']
            || $binding['authority']['server_root'] !== $this->serverRoot) { throw new RuntimeException('INSTALL_PARTIAL_DATABASE_IDENTITY_CHANGED'); }
        $connection = Db::connect(); $context = app()->make(\app\common\execution\CurrentExecutionContext::class)->current();
        $transaction = $pdo->inTransaction(); $executionLock = $this->partialLockBinding();
        $files = [];
        foreach (['private/resources/project-resources.json', 'private/resources/configuration.json',
            'private/installation/executing.json', '.env'] as $relative) {
            $files[$this->serverRoot . '/' . $relative] = $this->fileDigest($this->serverRoot . '/' . $relative);
        }
        $toolHash = $this->fileDigest($toolPath);
        $guard = function () use ($pdo, $connection, $context, $transaction, $executionLock, $files, $toolPath, $toolHash, $binding, $source, $window, $release): void {
            if (Db::connect() !== $connection || $connection->getPdo() !== $pdo || $pdo->inTransaction() !== $transaction
                || app()->make(\app\common\execution\CurrentExecutionContext::class)->current() !== $context
                || time() >= $binding['authority']['expires_at'] || $binding['authority']['owner_uid'] !== posix_geteuid()
                || $this->partialLockBinding() !== $executionLock || self::migrationDatabaseIdentity($pdo) !== $binding['database']
                || ($release ? $this->notificationPartialSource() !== $source : ApplicationSourceIdentity::load($this->serverRoot)->identitySha256() !== $source['runtime_sha256'])
                || $this->partialCoreFingerprint() !== $binding['source']['loaded_core_sha256']
                || hash_file('sha256', PHP_BINARY) !== $binding['source']['php_binary_sha256']
                || (!$release && ($this->fileDigest($source['checkpoint']['path']) !== $source['checkpoint']['sha256']
                    || $this->fileDigest($this->serverRoot . '/private/installation/deployment.json') !== $source['deployment']['head_sha256']))
                || $this->partialApplicationWindow() !== $window || $this->fileDigest($toolPath) !== $toolHash) {
                throw new RuntimeException('INSTALL_PARTIAL_METADATA_AUTHORITY_CHANGED');
            }
            foreach ($files as $path => $sha) {
                if ($this->fileDigest($path) !== $sha) { throw new RuntimeException('INSTALL_PARTIAL_METADATA_AUTHORITY_CHANGED'); }
            }
        };
        return \app\common\services\upgrade\SourceApplicationBackup::appPartialMetadata($pdo, $binding, $source, $window, $toolPath, $guard);
    }

    private function partialApplicationFacts(PDO $pdo, array $source, array $config, array $endpoint, array $window, array $authority,
        string $toolPath, ?array $origin = null): array
    {
        if (($source['kind'] ?? null) === 'notification-server-release') {
            return $this->notificationPartialFacts($pdo, $source, $config, $endpoint, $window, $authority, $toolPath, $origin);
        }
        if ($pdo->inTransaction()) { throw new RuntimeException('INSTALL_PARTIAL_TRANSACTION_CONFLICT'); }
        $binding = $this->partialApplicationBinding($pdo, $source, $config, $endpoint, $authority);
        // Privileged metadata is collected before opening the ordinary APP read-only snapshot.
        $metadata = $this->partialApplicationMetadata($pdo, $binding, $source, $window, $toolPath);
        $pdo->exec('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ'); $pdo->exec('SET TRANSACTION READ ONLY'); $pdo->beginTransaction();
        try {
        $modules = ['official.identity', 'official.content-tools', 'official.file', 'official.rich-text', 'official.document', 'official.article',
            'official.collaboration', 'official.task', 'official.settings', 'official.integration', 'official.import-export', 'official.member',
            'official.notification', 'official.oauth', 'official.ops', 'official.payment', 'official.reference-codes'];
        $progress = $this->partialJson($this->progressMarker());
        if (($progress['official_modules'] ?? null) !== $modules || $this->deploymentMode() !== 'multi-tenant'
            || $this->partialLockBinding() !== $source['deployment']['partial_installation']['execution_lock']) { throw new RuntimeException('INSTALL_PARTIAL_EXACT_FILE_FRONTIER_REQUIRED'); }
        $database = self::migrationDatabaseIdentity($pdo); $physical = $database; unset($physical['transport']);
        if ($physical !== $source['deployment']['partial_installation']['database']
            || $config['resource_id'] !== $source['deployment']['partial_installation']['resource_id']
            || $window['database_binding']['resource_id'] !== $config['resource_id']) { throw new RuntimeException('INSTALL_PARTIAL_DATABASE_IDENTITY_CHANGED'); }
        $originals = [];
        foreach (['progress' => 'private/installation/executing.json', 'configuration' => 'private/resources/configuration.json',
            'runtime' => '.peanut/development-runtime.json', 'environment' => '.env'] as $name => $relative) {
            $ref = ['path' => $this->serverRoot . '/' . $relative, 'sha256' => $this->fileDigest($this->serverRoot . '/' . $relative)];
            if ($name === 'runtime') { $ref = \app\common\services\upgrade\SourceApplicationBackup::partialRuntimeOriginalReference($this->serverRoot); }
            else { $this->partialFileReference($ref); }
            $originals[$name] = $ref;
        }
        $failureFiles = array_filter($source['deployment']['partial_installation']['files'], static fn(string $key): bool => str_starts_with($key, 'runtime/log/'), ARRAY_FILTER_USE_KEY);
        if (count($failureFiles) !== 1) { throw new RuntimeException('INSTALL_PARTIAL_FAILURE_RECORD_REQUIRED'); }
        $failurePath = array_key_first($failureFiles); $originals['failure'] = ['path' => $this->serverRoot . '/' . $failurePath, 'sha256' => $failureFiles[$failurePath]];
        $this->partialFileReference($originals['failure']);
        $tables = $pdo->query('SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() ORDER BY TABLE_NAME')->fetchAll(PDO::FETCH_COLUMN);
        $scope = \app\common\services\upgrade\SourceApplicationBackup::appPartialScope($pdo, $tables, $binding, $source, $window, $toolPath, $metadata);
        $effectMetadata = ['reference' => $metadata, 'semantic_sha256' => $scope['metadata_semantics_sha256']];
        if ($origin !== null) {
            if (($origin['semantic_sha256'] ?? null) !== $scope['metadata_semantics_sha256']) { throw new RuntimeException('INSTALL_PARTIAL_METADATA_ORIGIN_CHANGED'); }
            // Preserve the original HMAC plan's reference; fresh receipts were independently verified above.
            $effectMetadata = $origin;
        }
        $application = $pdo->query('SELECT * FROM pa_schema_migration ORDER BY migration_id')->fetchAll(PDO::FETCH_ASSOC);
        $identity = $pdo->query("SELECT * FROM pa_module_migration WHERE module_key='official.identity' ORDER BY migration_key")->fetchAll(PDO::FETCH_ASSOC);
        if (count($application) !== 1 || $application[0]['migration_id'] !== '20261019-clean-install-5.0.2-r1'
            || $application[0]['status'] !== 'applied' || $application[0]['checksum'] !== $this->fileDigest($this->serverRoot . '/database/baselines/5.0.2-r1/manifest.json')
            || count($identity) !== 9 || (int) $pdo->query("SELECT COUNT(*) FROM pa_module_migration WHERE module_key<>'official.identity'")->fetchColumn() !== 0) {
            throw new RuntimeException('INSTALL_PARTIAL_ORIGINAL_HISTORY_CHANGED');
        }
        $key = 'official.file:20260823-unify-storage-service';
        $sha = 'f79c35b2fe8414dc172a81a45183d2a52eadb05833a704149780b36e400ca2d4';
        if ($this->fileDigest($this->serverRoot . '/app/modules/official/file/database/migrations/20260823-unify-storage-service.sql') !== $sha) { throw new RuntimeException('INSTALL_PARTIAL_FILE_SOURCE_CHANGED'); }
        if ($this->partialApplicationBinding($pdo, $source, $config, $endpoint, $authority) !== $binding) { throw new RuntimeException('INSTALL_PARTIAL_METADATA_BINDING_CHANGED'); }
        return ['binding' => $binding, 'modules' => $modules, 'scope' => $scope, 'window' => $window,
            'baseline' => ['pending' => [$key], 'selected' => [$key => $sha], 'original_ledger' => $application, 'effect_metadata' => $effectMetadata,
                'identity_ledger' => $identity, 'original_files' => $originals, 'execution_lock' => $this->partialLockBinding(),
                'identity_rows_sha256' => $this->partialIdentityRows($pdo), 'config_rows_sha256' => $this->partialTableRows($pdo, 'pa_config')]];
        } finally { if ($pdo->inTransaction() && !$pdo->rollBack()) { throw new RuntimeException('INSTALL_PARTIAL_SNAPSHOT_CHANGED'); } }
    }

    /** Raw typed rows, ordered by their actual primary key; no row data leaves the Host. */
    private function partialTableRows(PDO $pdo, string $table): string
    {
        if (preg_match('/\Apa_[a-z0-9_]+\z/D', $table) !== 1) { throw new RuntimeException('INSTALL_PARTIAL_TABLE_INVALID'); }
        $statement = $pdo->prepare("SELECT COLUMN_NAME FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND INDEX_NAME='PRIMARY' ORDER BY SEQ_IN_INDEX");
        $statement->execute([$table]); $primary = $statement->fetchAll(PDO::FETCH_COLUMN); $statement->closeCursor();
        if ($primary === [] || array_filter($primary, static fn(string $column): bool => preg_match('/\A[a-zA-Z0-9_]+\z/D', $column) !== 1) !== []) { throw new RuntimeException('INSTALL_PARTIAL_PRIMARY_KEY_REQUIRED'); }
        $query = $pdo->query('SELECT * FROM `' . $table . '` ORDER BY `' . implode('`,`', $primary) . '`'); $hash = hash_init('sha256');
        while (($row = $query->fetch(PDO::FETCH_ASSOC)) !== false) { hash_update($hash, serialize($row)); }
        $query->closeCursor(); return hash_final($hash);
    }

    private function partialIdentityRows(PDO $pdo): string
    {
        $manifest = $this->definitionRegistry()->requireManifest('official.identity')->data;
        $catalogs = ['pa_data_condition_definition', 'pa_menu_definition', 'pa_module_installation', 'pa_permission', 'pa_protected_resource',
            'pa_resource_operation', 'pa_resource_operation_condition', 'pa_resource_operation_permission', 'pa_resource_operation_target_type',
            'pa_system_menu', 'pa_target_type', 'pa_tenant_module', 'pa_platform_audit_event', 'pa_tenant_audit_event'];
        $tables = array_values(array_diff($manifest['database']['owned_tables'], $catalogs)); sort($tables, SORT_STRING); $hashes = [];
        foreach ($tables as $table) { $hashes[$table] = $this->partialTableRows($pdo, $table); }
        return self::partialDigest($hashes);
    }

    /** Capability exists only during this Host's one native apply and current File reconcile. */
    public function fileUnifyContinuationAdmission(PDO $pdo, string $key): array
    {
        $held = $this->fileContinuation;
        if ($held === null || $held['pdo'] !== $pdo || $key !== 'official.file:20260823-unify-storage-service'
            || $this->currentInstallationModule !== 'official.file' || $this->partialGuard === null
            || app()->make(\app\common\execution\CurrentExecutionContext::class)->current() !== $held['context']) {
            throw new RuntimeException('INSTALL_PARTIAL_FILE_CONTINUATION_REQUIRED');
        }
        $this->migrationAdoptionAdmission($pdo);
        if ($this->partialIdentityRows($pdo) !== $held['identity_sha256'] || $this->partialTableRows($pdo, 'pa_config') !== $held['config_sha256']) {
            throw new RuntimeException('INSTALL_PARTIAL_IDENTITY_OR_CONFIGURATION_CHANGED');
        }
        return ['token' => $held['token'], 'authority_sha256' => $held['authority_sha256'], 'config_sha256' => $held['config_sha256']];
    }

    private static function partialDigest(array $value): string
    {
        $sort = static function (mixed $v) use (&$sort): mixed { if (!is_array($v)) { return $v; } if (!array_is_list($v)) { ksort($v, SORT_STRING); } foreach ($v as &$item) { $item = $sort($item); } return $v; };
        return hash('sha256', json_encode($sort($value), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    private function partialJson(string $path): array
    {
        return \app\common\services\upgrade\SourceApplicationBackup::protectedPluginJson($path, $this->serverRoot);
    }

    private function partialReference(mixed $ref): array
    {
        $this->partialFileReference($ref);
        return $this->partialJson($ref['path']);
    }

    private function partialFileReference(mixed $ref): void
    {
        if (!is_array($ref) || !is_string($ref['path'] ?? null) || !is_string($ref['sha256'] ?? null)) { throw new RuntimeException('INSTALL_PARTIAL_ORIGINAL_REQUIRED'); }
        $stat = @lstat($ref['path']);
        if (!is_array($stat) || realpath($ref['path']) !== $ref['path'] || !is_file($ref['path']) || is_link($ref['path']) || $stat['nlink'] !== 1
            || ($stat['mode'] & 0777) !== 0600 || !in_array($stat['uid'], [0, fileowner($this->serverRoot)], true) || $stat['size'] > 4194304
            || hash_file('sha256', $ref['path']) !== $ref['sha256']) { throw new RuntimeException('INSTALL_PARTIAL_ORIGINAL_CHANGED'); }
    }

    private function partialDirectory(string $name, array $resources): string
    {
        $base = $this->serverRoot . '/private/partial-installation';
        $path = $base . '/' . $name;
        $this->partialOutputGrant($base, $resources);
        $this->partialOutputGrant($path, $resources);
        foreach ([$this->serverRoot . '/private'] as $parent) {
            if (realpath($parent) !== $parent || (fileperms($parent) & 0022) !== 0 || !in_array(fileowner($parent), [0, fileowner($this->serverRoot)], true)) { throw new RuntimeException('INSTALL_PARTIAL_OUTPUT_UNSAFE'); }
        }
        if (!is_dir($base) && !mkdir($base, 0700)) { throw new RuntimeException('INSTALL_PARTIAL_OUTPUT_UNAVAILABLE'); }
        if (realpath($base) !== $base || (fileperms($base) & 0777) !== 0700 || !in_array(fileowner($base), [0, fileowner($this->serverRoot)], true)) { throw new RuntimeException('INSTALL_PARTIAL_OUTPUT_UNSAFE'); }
        if (!is_dir($path) && !mkdir($path, 0700)) { throw new RuntimeException('INSTALL_PARTIAL_OUTPUT_UNAVAILABLE'); }
        if (realpath($path) !== $path || is_link($path) || (fileperms($path) & 0777) !== 0700
            || !in_array(fileowner($path), [0, fileowner($this->serverRoot)], true)) { throw new RuntimeException('INSTALL_PARTIAL_OUTPUT_UNSAFE'); }
        return $path;
    }

    private function partialLockBinding(): array
    {
        $path = dirname($this->progressMarker()) . '/execution.lock';
        $held = is_resource($this->executionLock) ? fstat($this->executionLock) : false; $physical = @lstat($path);
        if (!is_array($held) || !is_array($physical) || !is_file($path) || is_link($path) || $physical['nlink'] !== 1
            || ($physical['mode'] & 0777) !== 0600 || !in_array($physical['uid'], [0, fileowner($this->serverRoot)], true)
            || $held['dev'] !== $physical['dev'] || $held['ino'] !== $physical['ino']) { throw new RuntimeException('INSTALL_PARTIAL_EXECUTION_LOCK_CHANGED'); }
        return ['device' => $held['dev'], 'inode' => $held['ino']];
    }

    private function partialOutputGrant(string $path, array $resources): void
    {
        foreach ($resources['output-dir'] ?? [] as $root) {
            if (realpath($root) === $root && ($path === $root || str_starts_with($path, rtrim($root, '/') . '/'))) { return; }
            if ($path === $root && !file_exists($root) && !is_link($root) && realpath(dirname($root)) === dirname($root)) { return; }
        }
        throw new RuntimeException('INSTALL_PARTIAL_OUTPUT_GRANT_REQUIRED');
    }

    private function partialReadGrant(array $resources, string $schema, array $tables, bool $ddl, bool $legacyExclusive = false): void
    {
        if ($legacyExclusive) { return; } // The registered non-shared schema lease is already exclusive.
        foreach ($resources['mysql-access'] ?? [] as $raw) {
            $access = json_decode($raw, true, 64, JSON_THROW_ON_ERROR);
            if (($access['schema'] ?? null) !== $schema || ($access['complete'] ?? null) !== true) { continue; }
            if (in_array($access['operation'] ?? null, ['ddl', 'exclusive'], true) && ($access['tables'] ?? null) === []) { return; }
            if ($ddl) { continue; }
            $read = [];
            foreach ($access['tables'] ?? [] as $entry) { if (($entry['rows'] ?? null) === '*' && in_array($entry['access'] ?? null, ['read', 'write'], true)) { $read[] = $entry['table']; } }
            if (array_diff($tables, $read) === []) { return; }
        }
        throw new RuntimeException('INSTALL_PARTIAL_NATIVE_SCOPE_REQUIRED');
    }

    /** Native actor, actual clean candidate and exact database grant, before PDO initialization. */
    private function partialNativeAccess(array $input, bool $ddl): array
    {
        $config = \guardedDatabaseConfig(); $registry = \projectResourceRegistry($registrySha);
        if ($config['environment'] !== 'development' || str_contains($config['deployment_target'], 'production')) { throw new RuntimeException('INSTALL_PARTIAL_NATIVE_OWNER_REQUIRED'); }
        $database = \registeredDatabase($registry, $config['resource_id']); $endpoint = \registeredDatabaseEndpoint($database, $config['consumer']);
        $schema = \mysqlSchemaLeaseResource($database, $endpoint, $config); $actor = $input['actor'] ?? [];
        foreach (['owner', 'thread', 'gate'] as $key) { if (!is_string($actor[$key] ?? null) || trim($actor[$key]) === '') { throw new RuntimeException('INSTALL_PARTIAL_NATIVE_ACTOR_REQUIRED'); } }
        $proof = \requiredEnvironment('PEANUT_RESOURCE_LEASE_PROOF'); $meta = \activeLeaseMetadata($proof, time(), $actor['gate']); $resources = \activeLeaseResources($proof);
        if ($meta['owner'] !== $actor['owner'] || $meta['thread'] !== $actor['thread'] || $meta['gate'] !== ($database['lease_gate'] ?? null) || !is_string($schema)) { throw new RuntimeException('INSTALL_PARTIAL_NATIVE_ACTOR_MISMATCH'); }
        $candidate = $meta['candidate_repository'];
        \assertNativeMysqlSchemaLease($candidate, $proof, $meta, $resources, $config['resource_id'], $schema, $config['endpoint_id'], $registrySha);
        if (\leaseGit(['-C', $candidate, 'status', '--porcelain', '--untracked-files=normal']) !== '') { throw new RuntimeException('INSTALL_PARTIAL_SOURCE_DIRTY'); }
        foreach (['database/install.php', 'database/environment-guard.php', 'app/common/value/installation/ApplicationSourceIdentity.php',
            'app/common/services/installation/InstallationExecutionHost.php', 'app/common/services/upgrade/ApplicationMigrationRunner.php',
            'app/common/services/upgrade/SourceApplicationBackup.php', 'database/migrations/20261019-module-migration-provenance-metadata.sql',
            'app/common/infrastructure/migration/PeanutMigrationAdapter.php', 'app/common/infrastructure/migration/PeanutMigrationRun.php',
            'app/common/infrastructure/migration/ImmutableSqlMigration.php', 'app/common/infrastructure/migration/CleanInstallBaseline.php',
            'app/common/infrastructure/migration/CleanInstallMigration.php', 'database/baselines/5.0.2-r1/manifest.json',
            'database/baselines/5.0.2-r1/baseline.lock', 'database/baselines/5.0.2-r1/schema.sql', 'database/baselines/5.0.2-r1/data.sql'] as $relative) {
            $runtimeFile = $this->serverRoot . '/' . $relative; $sourceFile = $candidate . '/server/' . $relative;
            if (!is_file($runtimeFile) || is_link($runtimeFile) || !is_file($sourceFile) || is_link($sourceFile)
                || hash_file('sha256', $runtimeFile) !== hash_file('sha256', $sourceFile)) { throw new RuntimeException('INSTALL_PARTIAL_PROGRAM_CANDIDATE_MISMATCH'); }
        }
        $required = ['pa_schema_migration', 'pa_module_migration', 'pa_system_menu', 'pa_config', 'pa_tenant', 'pa_tenant_member', 'pa_account',
            'pa_credential', 'pa_member_role', 'pa_role', 'pa_platform_operator', 'pa_plugin_installation', 'pa_module_installation'];
        $legacyExclusive = !isset($database['lease_policy']['shared_access']) && !isset($resources['mysql-access']);
        $this->partialReadGrant($resources, $schema, $required, $ddl, $legacyExclusive);
        return ['config' => $config, 'registry' => $registry, 'registry_sha256' => $registrySha, 'schema' => $schema, 'resources' => $resources,
            'legacy_exclusive' => $legacyExclusive,
            'authority' => ['lease' => $meta['lease'], 'owner' => $meta['owner'], 'thread' => $meta['thread'], 'gate' => $meta['gate'],
                'candidate' => $meta['candidate'], 'created_at' => (int) $meta['created_at'], 'expires_at' => (int) $meta['expires_at'], 'resources_sha256' => hash_file('sha256', $proof . '/resources.tsv')]];
    }

    /** Pin the actually loaded Core, including local development source links. */
    private function partialCoreFingerprint(): string
    {
        $root = dirname((string) (new \ReflectionClass(\PeanutAdmin\Kernel\Module\ModuleProvider::class))->getFileName(), 4);
        $files = [];
        $iterator = new \RecursiveIteratorIterator(new \RecursiveCallbackFilterIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS),
            static fn($file): bool => !in_array($file->getFilename(), ['.git', '.local', 'vendor', 'tests', 'node_modules'], true)));
        foreach ($iterator as $file) {
            if ($file->isLink()) { throw new RuntimeException('INSTALL_PARTIAL_CORE_CHANGED'); }
            if ($file->isFile() && ($file->getExtension() === 'php' || $file->getFilename() === 'composer.json')) { $files[substr($file->getPathname(), strlen($root) + 1)] = hash_file('sha256', $file->getPathname()); }
        }
        ksort($files, SORT_STRING);
        return self::partialDigest($files);
    }

    /** Original protected failure evidence and current initialized facts must both match. */
    private function partialFacts(array $input, array $access, PDO $pdo): array
    {
        $runner = \app\common\services\upgrade\ApplicationMigrationRunner::class;
        $identity = ApplicationSourceIdentity::load($this->serverRoot); $oldRuntime = $this->partialReference($input['original_runtime'] ?? null);
        $progress = $this->partialReference($input['original_progress'] ?? null); $this->partialReference($input['original_configuration'] ?? null);
        $oldLedger = $this->partialReference($input['original_ledger'] ?? null);
        $this->partialFileReference(['path' => $this->progressMarker(), 'sha256' => $input['original_progress']['sha256']]);
        if (($oldRuntime['protocol'] ?? null) !== 'peanut.development-server-runtime.v1' || ($oldRuntime['application'] ?? null) !== $identity->applicationIdentity()
            || !is_array($oldRuntime['files'] ?? null) || ($oldRuntime['files_sha256'] ?? null) !== hash('sha256', json_encode($oldRuntime['files'], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE))
            || ($progress['state'] ?? null) !== 'executing' || ($progress['deployment_mode'] ?? null) !== $this->deploymentMode()
            || !is_array($progress['official_modules'] ?? null) || !array_is_list($progress['official_modules']) || $progress['official_modules'] === []
            || count(array_filter($progress['official_modules'], static fn(mixed $key): bool => is_string($key) && preg_match('/\A[a-z][a-z0-9_]*\.[a-z][a-z0-9_-]*\z/D', $key) === 1)) !== count($progress['official_modules'])
            || count(array_unique($progress['official_modules'])) !== count($progress['official_modules']) || !is_string($progress['started_at'] ?? null)
            || $this->fileDigest($this->progressMarker()) !== $input['original_progress']['sha256']
            || $this->fileDigest($this->serverRoot . '/private/resources/configuration.json') !== $input['original_configuration']['sha256']) { throw new RuntimeException('INSTALL_PARTIAL_ORIGINAL_BINDING_REQUIRED'); }
        $oldSource = array_column($oldRuntime['files'], 'sha256', 'path');
        if (($oldSource['server/database/init.sql'] ?? null) !== $this->fileDigest($this->serverRoot . '/database/init.sql')
            || ($oldSource['server/database/migrations/' . $runner::PROVENANCE_ORIGINAL . '.sql'] ?? null) !== $runner::PROVENANCE_ORIGINAL_SHA) { throw new RuntimeException('INSTALL_PARTIAL_ORIGINAL_SOURCE_CHANGED'); }
        $database = self::migrationDatabaseIdentity($pdo);
        if (($oldLedger['identity']['db'] ?? null) !== $database['database'] || strtolower((string) ($oldLedger['identity']['uuid'] ?? '')) !== $database['server_uuid']) { throw new RuntimeException('INSTALL_PARTIAL_ORIGINAL_DATABASE_CHANGED'); }
        $scope = '`' . str_replace('`', '``', $database['database']) . '`.*';
        $escapedScope = '`' . strtr($database['database'], ['\\' => '\\\\', '`' => '``', '_' => '\\_', '%' => '\\%']) . '`.*';
        $grants = [];
        foreach ($pdo->query('SHOW GRANTS FOR CURRENT_USER()')->fetchAll(PDO::FETCH_NUM) as $row) {
            if (count($row) !== 1 || !is_string($row[0]) || str_contains($row[0], ' WITH GRANT OPTION')
                || preg_match('/\AGRANT (.+) ON (.+) TO /D', $row[0], $grant) !== 1
                || (!in_array($grant[2], [$scope, $escapedScope], true) && !($grant[1] === 'USAGE' && $grant[2] === '*.*'))) { throw new RuntimeException('INSTALL_PARTIAL_DATABASE_WRITE_BOUNDARY_UNKNOWN'); }
            $grants[] = [$grant[1], $grant[2]]; // Never retain the principal or authentication material.
        }
        if ($grants === []) { throw new RuntimeException('INSTALL_PARTIAL_DATABASE_WRITE_BOUNDARY_UNKNOWN'); }
        sort($grants);
        $failure = $input['original_failure'] ?? null;
        if (!is_array($failure) || !is_string($failure['path'] ?? null) || realpath($failure['path']) !== $failure['path']
            || !is_file($failure['path']) || is_link($failure['path']) || filesize($failure['path']) > 4194304
            || (fileperms($failure['path']) & 0777) !== 0600 || !in_array(fileowner($failure['path']), [0, fileowner($this->serverRoot)], true)
            || $this->fileDigest($failure['path']) !== ($failure['sha256'] ?? null)) { throw new RuntimeException('INSTALL_PARTIAL_FAILURE_RECORD_REQUIRED'); }
        $found = [];
        foreach (file($failure['path'], FILE_IGNORE_NEW_LINES) as $line) {
            if (!str_contains($line, '"event":"installation_failure"')) { continue; }
            $offset = strpos($line, '{'); if ($offset === false) { continue; }
            $record = json_decode(substr($line, $offset), true, 32, JSON_THROW_ON_ERROR);
            $at = preg_match('/\A\[([^\]]+)\]/', $line, $match) === 1 ? strtotime($match[1]) : false;
            if ($at === false || $at < strtotime($progress['started_at'])) { continue; }
            $found[] = $record;
        }
        if (count($found) !== 1 || ($found[0]['stage'] ?? null) !== 'migrate_database' || ($found[0]['error_code'] ?? null) !== 'INSTALL_EXECUTION_FAILED'
            || !in_array(['class' => 'PDOException', 'path' => 'server/app/common/infrastructure/migration/ImmutableSqlMigration.php', 'line' => 54, 'sqlstate' => 'HY000', 'driver_code' => 3822], $found[0]['exception_chain'] ?? [], true)) { throw new RuntimeException('INSTALL_PARTIAL_FAILURE_STAGE_UNPROVEN'); }
        $files = \applicationMigrationFiles($this->serverRoot . '/database'); $versions = \applicationReleaseVersions($this->serverRoot);
        $native = new $runner($access['config'], $pdo); $selection = $native->run($files, $this->migrationTargetVersion(), $versions['release_sequence_version'], true);
        $relation = $selection['supersessions'][0] ?? null;
        if ($selection['pending'] !== [$runner::PROVENANCE_CORRECTION] || !is_array($relation) || $relation['state'] !== 'awaiting_correction'
            || ($relation['original_ledger']['status'] ?? null) !== 'failed' || !is_array($oldLedger['failed_or_applying'] ?? null)
            || count($oldLedger['failed_or_applying']) !== 1 || ($oldLedger['failed_or_applying'][0]['checksum'] ?? null) !== $runner::PROVENANCE_ORIGINAL_SHA
            || array_intersect_key($relation['original_ledger'], $oldLedger['failed_or_applying'][0]) !== $oldLedger['failed_or_applying'][0]) { throw new RuntimeException('INSTALL_PARTIAL_EXACT_FAILED_STAGE_REQUIRED'); }
        $rows = $pdo->query('SELECT * FROM pa_schema_migration ORDER BY migration_id')->fetchAll(PDO::FETCH_ASSOC); $expected = [];
        foreach ($oldSource as $path => $sha) { if (preg_match('#\Aserver/database/migrations/([0-9]{8}-[a-z0-9_-]+)\.sql\z#D', $path, $m) === 1) { $expected[$m[1]] = $sha; } }
        if (count($rows) !== count($expected) || count($rows) !== 18) { throw new RuntimeException('INSTALL_PARTIAL_ORIGINAL_PREFIX_UNPROVEN'); }
        $expectedKeys = array_keys($expected); sort($expectedKeys, SORT_STRING);
        if (array_column($rows, 'migration_id') !== $expectedKeys) { throw new RuntimeException('INSTALL_PARTIAL_ORIGINAL_PREFIX_UNPROVEN'); }
        foreach ($rows as $row) {
            if (($expected[$row['migration_id']] ?? null) !== $row['checksum'] || $row['finished_at'] === null
                || ($row['migration_id'] !== $runner::PROVENANCE_ORIGINAL && ($row['status'] !== 'applied' || $row['error_code'] !== null))) { throw new RuntimeException('INSTALL_PARTIAL_ORIGINAL_PREFIX_UNPROVEN'); }
        }
        foreach (['pa_module_migration', 'pa_plugin_installation', 'pa_module_installation'] as $table) {
            if ((int) $pdo->query('SELECT COUNT(*) FROM ' . $table)->fetchColumn() !== 0) { throw new RuntimeException('INSTALL_PARTIAL_MODULE_STAGE_UNKNOWN'); }
        }
        $tenant = \installationTenantBootstrapContract($this->serverRoot);
        $counts = \coreIdentityCounts($pdo, $tenant['code']);
        if ($counts !== ['tenant_count' => 1, 'owner_count' => 1, 'operator_count' => 1]
            || (int) $pdo->query('SELECT COUNT(*) FROM pa_system_menu')->fetchColumn() === 0 || (int) $pdo->query('SELECT COUNT(*) FROM pa_config')->fetchColumn() === 0) { throw new RuntimeException('INSTALL_PARTIAL_INITIALIZATION_UNPROVEN'); }
        $tableFacts = $pdo->query('SELECT TABLE_NAME,TABLE_TYPE,ENGINE,TABLE_COLLATION FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() ORDER BY TABLE_NAME')->fetchAll(PDO::FETCH_ASSOC);
        foreach ($tableFacts as $table) { if ($table['TABLE_TYPE'] !== 'BASE TABLE' || $table['ENGINE'] !== 'InnoDB') { throw new RuntimeException('INSTALL_PARTIAL_TABLE_KIND_UNKNOWN'); } }
        $tables = array_column($tableFacts, 'TABLE_NAME');
        $required = \expectedTables(\sqlFiles($this->serverRoot . '/database'));
        if (array_diff($required, $tables) !== []) { throw new RuntimeException('INSTALL_PARTIAL_INITIALIZATION_UNPROVEN'); }
        $effects = \app\common\services\upgrade\SourceApplicationBackup::partialInstallationScope($pdo, $tables);
        $originals = ['progress' => $input['original_progress'], 'configuration' => $input['original_configuration'], 'runtime' => $input['original_runtime'],
            'failure' => $failure, 'environment' => ['path' => $this->serverRoot . '/.env', 'sha256' => $this->fileDigest($this->serverRoot . '/.env')], 'ledger' => $input['original_ledger']];
        $baseline = ['pending' => [$runner::PROVENANCE_CORRECTION], 'selected' => [$runner::PROVENANCE_CORRECTION => $runner::PROVENANCE_CORRECTION_SHA],
            'original_ledger' => $rows, 'original_files' => $originals, 'modules' => $progress['official_modules'], 'initialization_counts' => $counts,
            'tables' => $tableFacts, 'write_boundary' => 'ordinary_current_principal_grants_only_exact_schema', 'grant_scope_sha256' => self::partialDigest($grants)];
        $baseline['effect_scope'] = $effects;
        $baseline['execution_lock'] = $this->partialLockBinding();
        $maintenance = $this->partialReference($input['maintenance'] ?? null);
        if (($maintenance['protocol'] ?? null) !== 'peanut.installation-partial-maintenance.v1' || ($maintenance['state'] ?? null) !== 'active'
            || ($maintenance['resource_id'] ?? null) !== $access['config']['resource_id'] || ($maintenance['server_uuid'] ?? null) !== $database['server_uuid']
            || ($maintenance['database'] ?? null) !== $database['database'] || ($maintenance['progress_sha256'] ?? null) !== $input['original_progress']['sha256']
            || ($maintenance['excluded_writers'] ?? null) !== ['http', 'workers', 'other-writers'] || !is_int($maintenance['observed_at'] ?? null)
            || !is_int($maintenance['expires_at'] ?? null) || $maintenance['observed_at'] > time() || $maintenance['expires_at'] <= time()
            || $maintenance['expires_at'] > $access['authority']['expires_at'] || !is_array($maintenance['receipts'] ?? null) || $maintenance['receipts'] === []) { throw new RuntimeException('INSTALL_PARTIAL_MAINTENANCE_REQUIRED'); }
        foreach ($maintenance['receipts'] as $receipt) { $this->partialFileReference($receipt); }
        $binding = ['source' => ['server_root' => $this->serverRoot, 'application' => $identity->applicationIdentity(), 'runtime_sha256' => $identity->identitySha256(),
                'original_runtime_sha256' => $input['original_runtime']['sha256'], 'maintenance' => $input['maintenance'],
                'loaded_core_sha256' => $this->partialCoreFingerprint(), 'php_binary_sha256' => hash_file('sha256', PHP_BINARY), 'php_version' => PHP_VERSION],
            'database' => $database, 'resource_id' => $access['config']['resource_id'], 'endpoint_id' => $access['config']['endpoint_id'],
            'registry_sha256' => $access['registry_sha256'], 'authority' => $access['authority']];
        return ['binding' => $binding, 'baseline' => $baseline, 'modules' => $progress['official_modules'], 'selection' => $selection,
            'scope' => $effects, 'fresh_initialization' => 'original_failure_and_current_facts_verified_not_reexecuted'];
    }

    /** @param array<string,mixed> $input @return array<string,mixed> */
    private function execute(array $input): array
    {
        $lock = $this->acquireExecutionLock();
        $this->executionLock = $lock;
        try {
            if ($this->completionLockPresent()) {
                throw new InstallationExecutionException(
                    'INSTALL_ALREADY_COMPLETED',
                    '安装完成锁已经存在，重复执行已被拒绝。',
                    409,
                );
            }
            $status = $this->status();
            if ($status['state'] === 'installed') {
                throw new InstallationExecutionException(
                    'INSTALL_ALREADY_COMPLETED',
                    '系统已经完成安装，重复执行已被拒绝。',
                    409,
                );
            }
            if ($status['state'] !== 'uninstalled') {
                throw new InstallationExecutionException(
                    (string) $status['code'],
                    $status['retryable']
                        ? '安装前置条件暂未满足。'
                        : '目标包含安装身份或未完成残留，保留原数据并由资源 owner 核对，不能自动重建。',
                    409,
                    null,
                    array_intersect_key($status, array_flip(['category', 'stage', 'reason', 'remedy', 'writes', 'safe_retry'])),
                );
            }

            $databaseCheck = $this->databaseCheck();
            if (($databaseCheck['permissions_verified'] ?? false) !== true) {
                throw new InstallationExecutionException('INSTALL_DATABASE_PERMISSIONS_UNKNOWN',
                    '数据库有效安装权限未确认，尚未执行安装。', 409, null,
                    ['stage' => 'database_check', 'reason' => 'INSTALL_DATABASE_PERMISSIONS_UNKNOWN',
                        'remedy' => '由资源 owner 核对精确库权限后重新检查。',
                        'writes' => ['database' => 'none'], 'safe_retry' => true]);
            }
            [$credentials, $moduleKeys] = $this->normalizeInput($input);
            $this->writeProgressMarker($moduleKeys);
            $stage = 'fresh_database';
            try {
                $baseline = \installFreshDatabase($this->serverRoot, $credentials);
                $stage = 'migrate_database';
                $migration = \migrateDatabase(
                    $this->serverRoot,
                    $this->migrationTargetVersion(),
                );
                $completion = $this->completeInitializedInstallation($moduleKeys, $lock, $stage);

                return [
                    'state' => 'installed',
                    'code' => 'INSTALL_COMPLETED',
                    'deployment_mode' => $this->deploymentMode(),
                    'baseline' => $baseline,
                    'migration' => $migration,
                    ...$completion,
                ];
            } catch (Throwable $exception) {
                $failure = new InstallationExecutionException(
                    'INSTALL_EXECUTION_FAILED',
                    '安装执行失败；保留已写入的数据和安装记录，核对失败阶段后处理。',
                    409,
                    $exception,
                    ['category' => 'partial', 'stage' => $stage, 'reason' => 'INSTALL_EXECUTION_FAILED',
                        'remedy' => '核对该阶段日志、数据库和执行记录；不得直接重放、清库或覆盖身份。',
                        'writes' => ['progress_marker' => true, 'database' => 'may_have_partial_writes',
                            'completion_lock_present' => $this->completionLockPresent()],
                        'safe_retry' => false,
                        ...\installationFailureDiagnostic($this->serverRoot, $stage, $exception, $this->currentInstallationModule)],
                );
                \logInstallationFailure($this->serverRoot, $stage, $failure->errorCode, $failure, $this->currentInstallationModule);
                try {
                    $database = \installationDatabaseState($this->serverRoot);
                    if ($database['state'] === 'uninstalled') {
                        @unlink($this->progressMarker());
                    }
                } catch (Throwable) {
                }
                throw $failure;
            }
        } finally {
            $this->currentInstallationModule = null;
            $this->moduleMigrationAdmission = null;
            $this->executionLock = null;
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    /** Both fresh execution and the bounded partial continuation use this original lifecycle. */
    private function completeInitializedInstallation(array $moduleKeys, $lock, string &$stage): array
    {
        $this->currentInstallationModule = null;
        if ($this->partialGuard !== null) { ($this->partialGuard)(); }
        $stage = 'install_modules';
        $this->initializeModuleMigrationAdmission($lock);
        try { $modules = $this->installModules($moduleKeys, \installationTenantBootstrapContract($this->serverRoot)); }
        finally { $this->moduleMigrationAdmission = null; }
        if ($this->partialGuard !== null) { ($this->partialGuard)(); }
        $stage = 'health'; $health = $this->health($moduleKeys);
        if ($this->partialGuard !== null) { ($this->partialGuard)(); }
        $stage = 'completion_marker'; $receipt = $this->writeCompletionMarker($moduleKeys, $health);
        if ($this->partialCompletion !== null) { ($this->partialCompletion)($receipt, $moduleKeys); }
        @unlink($this->progressMarker());
        return ['modules' => $modules, 'health' => $health, 'installation_receipt' => $receipt];
    }

    /** Same held native lock and ordinary master for read-plan proof and module execution. */
    private function initializeModuleMigrationAdmission($lock): void
    {
        $context = app()->make(\app\common\execution\CurrentExecutionContext::class)->current();
        if (!$context instanceof \app\common\execution\InstallationExecutionContext) { throw new RuntimeException('INSTALL_EXECUTION_CONTEXT_REQUIRED'); }
        $stat = fstat($lock);
        if (!is_array($stat)) { throw new RuntimeException('INSTALL_EXECUTION_LOCK_UNAVAILABLE'); }
        Db::connect()->query('SELECT 1', [], true);
        $this->moduleMigrationAdmission = [
            'protocol' => 'peanut.installation-module-admission.v1', 'instance_root' => realpath($this->serverRoot),
            'request_id' => $context->requestId(), 'operation' => $context->operation(),
            'lock_device' => $stat['dev'], 'lock_inode' => $stat['ino'], 'progress_sha256' => $this->fileDigest($this->progressMarker()),
            'fresh_schema_sha256' => $this->fileDigest($this->serverRoot . '/database/init.sql'),
            'database_identity' => self::migrationDatabaseIdentity($this->pdo()),
            'product_writer_boundary' => 'native_installation_execution_lock_before_completion',
            'external_writer_exclusion' => 'ops_protocol_gate_required_not_attested',
        ];
    }

    /** Read-only capability of this actual executor, available only inside its held installModules call. */
    public function migrationAdoptionAdmission(PDO $pdo): array
    {
        $stage = 'partial_guard'; $logged = false;
        $facts = array_fill_keys(['capability_absent', 'context_invalid', 'request_changed', 'operation_changed',
            'handle_invalid', 'physical_lock_invalid', 'physical_lock_linked', 'handle_device_changed', 'handle_inode_changed',
            'physical_device_changed', 'physical_inode_changed', 'completion_present', 'root_changed', 'progress_changed',
            'source_changed', 'database_changed'], 'skipped');
        $observe = static function (string $reason) use (&$facts, &$stage, &$logged): void {
            $logged = true;
            try {
                \think\facade\Log::write(json_encode(['event' => 'installation_adoption_admission_rejected',
                    'source' => 'InstallationExecutionHost::migrationAdoptionAdmission', 'stage' => $stage,
                    'reason' => $reason, 'condition' => $stage === 'guard'
                        ? ($reason === 'THROWN' ? array_search('skipped', $facts, true) : $reason) : 'not_reached',
                    'predicates' => $facts], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES), 'error');
            } catch (\Throwable) { /* Observability never replaces the original admission failure. */ }
        };
        try {
        if ($this->partialGuard !== null) { ($this->partialGuard)(); }
        $stage = 'context';
        $admission = $this->moduleMigrationAdmission;
        $context = app()->make(\app\common\execution\CurrentExecutionContext::class)->current();
        $stage = 'lock_observation';
        $path = dirname($this->progressMarker()) . '/execution.lock';
        $stat = is_resource($this->executionLock) ? fstat($this->executionLock) : false;
        $physical = is_file($path) && !is_link($path) ? lstat($path) : false;
        $stage = 'guard';
        if (($facts['capability_absent'] = $admission === null) || ($facts['context_invalid'] = !$context instanceof \app\common\execution\InstallationExecutionContext)
            || ($facts['request_changed'] = $context->requestId() !== $admission['request_id']) || ($facts['operation_changed'] = $context->operation() !== $admission['operation'])
            || ($facts['handle_invalid'] = !is_array($stat)) || ($facts['physical_lock_invalid'] = !is_array($physical)) || ($facts['physical_lock_linked'] = $physical['nlink'] !== 1)
            || ($facts['handle_device_changed'] = $stat['dev'] !== $admission['lock_device']) || ($facts['handle_inode_changed'] = $stat['ino'] !== $admission['lock_inode'])
            || ($facts['physical_device_changed'] = $stat['dev'] !== $physical['dev']) || ($facts['physical_inode_changed'] = $stat['ino'] !== $physical['ino'])
            || ($facts['completion_present'] = $this->completionLockPresent()) || ($facts['root_changed'] = realpath($this->serverRoot) !== $admission['instance_root'])
            || ($facts['progress_changed'] = $this->fileDigest($this->progressMarker()) !== $admission['progress_sha256'])
            || ($facts['source_changed'] = $this->fileDigest($this->serverRoot . '/database/init.sql') !== $admission['fresh_schema_sha256'])
            || ($facts['database_changed'] = self::migrationDatabaseIdentity($pdo) !== $admission['database_identity'])) {
            $observe((string) array_search(true, $facts, true));
            throw new RuntimeException('INSTALL_MODULE_ADOPTION_ADMISSION_REQUIRED');
        }
        // execute() retains the private handle until installModules returns, including each DB commit.
        return $admission;
        } catch (\Throwable $error) {
            if (!$logged) { $observe('THROWN'); }
            throw $error;
        }
    }

    /** Identity comes from the actual native master PDO, never from a supplied window/endpoint declaration. */
    public static function migrationDatabaseIdentity(PDO $pdo): array
    {
        if (Db::connect()->getPdo() !== $pdo) { throw new RuntimeException('INSTALL_DATABASE_CONNECTION_MISMATCH'); }
        $statement = $pdo->query('SELECT @@server_uuid AS server_uuid,@@hostname AS server_hostname,@@port AS server_port,DATABASE() AS database_name');
        $row = $statement->fetch(PDO::FETCH_ASSOC); $statement->closeCursor();
        $transport = $pdo->getAttribute(PDO::ATTR_CONNECTION_STATUS);
        if (!is_array($row) || preg_match('/^[a-f0-9]{8}(?:-[a-f0-9]{4}){3}-[a-f0-9]{12}$/Di', (string) ($row['server_uuid'] ?? '')) !== 1
            || !is_string($row['server_hostname'] ?? null) || $row['server_hostname'] === ''
            || (int) ($row['server_port'] ?? 0) < 1 || (int) $row['server_port'] > 65535
            || !is_string($row['database_name'] ?? null) || $row['database_name'] === ''
            || !is_string($transport) || $transport === '') {
            throw new RuntimeException('INSTALL_DATABASE_IDENTITY_UNAVAILABLE');
        }
        return ['server_uuid' => strtolower($row['server_uuid']), 'server_hostname' => $row['server_hostname'],
            'server_port' => (int) $row['server_port'], 'database' => $row['database_name'], 'transport' => $transport];
    }

    /** @return resource */
    private function acquireExecutionLock()
    {
        $directory = dirname($this->progressMarker());
        if (!is_dir($directory) && !mkdir($directory, 0770, true) && !is_dir($directory)) {
            throw new InstallationExecutionException(
                'INSTALL_STATE_UNAVAILABLE',
                '安装状态目录不可用。',
                503,
            );
        }
        $handle = fopen($directory . '/execution.lock', 'c+');
        if ($handle === false || !flock($handle, LOCK_EX | LOCK_NB)) {
            if (is_resource($handle)) {
                fclose($handle);
            }
            throw new InstallationExecutionException(
                'INSTALL_EXECUTION_IN_PROGRESS',
                '已有安装执行正在进行。',
                409,
            );
        }
        return $handle;
    }

    /**
     * @param array<string,mixed> $input
     * @return array{0:array<string,mixed>,1:list<string>}
     */
    private function normalizeInput(array $input): array
    {
        $allowed = [
            'admin_email',
            'admin_password',
            'platform_email',
            'platform_password',
            'official_modules',
        ];
        if (array_diff(array_keys($input), $allowed) !== []) {
            throw new InstallationExecutionException(
                'INSTALL_INPUT_INVALID',
                '安装请求包含不支持的字段。',
                422,
            );
        }
        $modules = $input['official_modules'] ?? array_column($this->officialModules(), 'key');
        if (!is_array($modules)
            || !array_is_list($modules)
            || array_filter($modules, static fn(mixed $key): bool => !is_string($key)) !== []) {
            throw new InstallationExecutionException('INSTALL_MODULE_SELECTION_INVALID', 'Module 选择无效。', 422);
        }
        $modules = array_values(array_unique($modules));
        $options = $this->officialModules();
        $available = array_column($options, 'key');
        if (array_diff($modules, $available) !== []) {
            throw new InstallationExecutionException('INSTALL_MODULE_SELECTION_INVALID', 'Module 选择无效。', 422);
        }
        foreach ($options as $option) {
            if ($option['required']) {
                $modules[] = $option['key'];
            }
        }
        $modules = array_values(array_unique($modules));
        $selected = array_fill_keys($modules, true);
        $definitions = [];
        foreach ($this->definitionRegistry()->modules as $manifest) {
            $key = (string) $manifest->data['key'];
            if (isset($selected[$key])) {
                $definitions[$key] = [
                    'version' => (string) $manifest->data['version'],
                    'dependencies' => $manifest->data['dependencies'] ?? [],
                ];
            }
        }
        // 与独立模块包使用同一依赖/版本规则，且在创建数据库之前完成校验。
        $serverIdentity = $this->serverRoot . '/.peanut/release-identity.json';
        $projectRoot = dirname($this->serverRoot);
        $applicationManifest = $projectRoot . '/.peanut/application-manifest.json';
        if (ApplicationSourceIdentity::hasRuntime($this->serverRoot)) {
            ApplicationSourceIdentity::load($this->serverRoot);
            $packageRoot = $this->serverRoot;
        } elseif (file_exists($serverIdentity) || is_link($serverIdentity)) {
            $packageRoot = $this->serverRoot;
        } elseif (\installationSourceDevelopmentMode($this->serverRoot)) {
            $packageRoot = $projectRoot;
        } elseif (file_exists($applicationManifest) || is_link($applicationManifest)) {
            if (!is_file($applicationManifest) || is_link($applicationManifest)) {
                throw new RuntimeException('INSTALL_RELEASE_IDENTITY_UNAVAILABLE');
            }
            $packageRoot = $projectRoot;
        } else {
            throw new RuntimeException('INSTALL_RELEASE_IDENTITY_UNAVAILABLE');
        }
        $modules = (new \app\platform\validation\plugin\ModulePackagePreflight($packageRoot))
            ->dependencyOrder($definitions, []);
        $credentials = array_intersect_key($input, array_flip([
            'admin_email', 'admin_password', 'platform_email', 'platform_password',
        ]));
        try {
            \normalizeInstallationCredentials($credentials);
        } catch (Throwable) {
            throw new InstallationExecutionException('INSTALL_IDENTITY_INVALID', '初始身份不符合安装要求。', 422);
        }
        return [$credentials, $modules];
    }

    /** @return list<array{key:string,label:string,description:string,required:bool,default:bool}> */
    private function officialModules(): array
    {
        $modules = [];
        $registry = $this->definitionRegistry();
        foreach ($registry->modules as $manifest) {
            $key = $manifest->data['key'] ?? null;
            if (is_string($key) && str_starts_with($key, 'official.')) {
                $required = $registry->isRequiredTenantFoundation($key);
                $modules[] = [
                    'key' => $key,
                    'label' => (string) ($manifest->data['name'] ?? substr($key, strlen('official.'))),
                    'description' => (string) ($manifest->data['description'] ?? ''),
                    'required' => $required,
                    'default' => true,
                ];
            }
        }
        usort($modules, static fn(array $left, array $right): int => $left['key'] <=> $right['key']);
        return $modules;
    }

    /**
     * @param list<string> $moduleKeys
     * @param array{kind:string,code:string,tenant_identity:string,rbac:string,execution_context:string,module_lifecycle:string} $tenantBootstrap
     * @return array<string,mixed>
     */
    private function installModules(array $moduleKeys, array $tenantBootstrap): array
    {
        $this->currentInstallationModule = null;
        $config = $this->moduleConfig();
        $registry = $this->definitionRegistry();
        $governance = new ThinkPhpModuleGovernanceProvider(
            $this->serverRoot,
            $config,
            $this->catalogs,
            $registry,
        );
        $lifecycle = $governance->pluginLifecycle();
        $operations = [];
        foreach ($moduleKeys as $moduleKey) {
            $this->currentInstallationModule = $moduleKey;
            $result = $lifecycle->reconcile($moduleKey);
            $this->currentInstallationModule = null;
            $operations[] = [
                'key' => $moduleKey,
                'operation' => (string) ($result['operation'] ?? ''),
            ];
        }
        $profile = (new ProductTenantModuleProfileService(
            $this->runtimeForProfile($registry),
            $governance,
            $this->audit,
            $this->tenantDirectory,
            $this->moduleComposition,
        ))->applyInstallationSelection($moduleKeys, $tenantBootstrap['code']);
        return ['operations' => $operations, 'profile' => $profile];
    }

    /** Construct persistence after reconciliation over the same immutable deployment declaration. */
    private function runtimeForProfile(CompiledModuleRegistry $registry): ModuleRuntimeRepository
    {
        return ($this->moduleRuntimeFactory)($registry);
    }

    /** @param list<string> $moduleKeys @return array<string,int> */
    private function health(array $moduleKeys): array
    {
        $pdo = $this->pdo();
        $tenantBootstrap = \installationTenantBootstrapContract($this->serverRoot);
        $health = \assertCurrentDatabase($pdo);
        if ($moduleKeys !== []) {
            if ($this->moduleStates->activeInstallationCount($moduleKeys) !== count($moduleKeys)) {
                throw new RuntimeException('Official Module installation is incomplete.');
            }
            $definitions = $this->definitionRegistry();
            $tenantManaged = array_values(array_filter(
                $moduleKeys,
                fn(string $moduleKey): bool => !$definitions->isRequiredTenantFoundation($moduleKey),
            ));
            if ($tenantManaged !== []) {
                if ($this->moduleStates->enabledTenantSelectionCount($tenantBootstrap['code'], $tenantManaged) !== count($tenantManaged)) {
                    throw new RuntimeException('Default Tenant Module selection is incomplete.');
                }
            }
            $ownedTables = [];
            foreach ($moduleKeys as $moduleKey) {
                foreach ($definitions->requireManifest($moduleKey)->data['database']['owned_tables'] ?? [] as $table) {
                    $ownedTables[$table] = true;
                }
            }
            if ($ownedTables !== []) {
                $names = array_keys($ownedTables);
                $placeholders = implode(',', array_fill(0, count($names), '?'));
                $query = $pdo->prepare(
                    "SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN ({$placeholders})",
                );
                $query->execute($names);
                $present = $query->fetchAll(PDO::FETCH_COLUMN);
                $missing = array_diff($names, $present);
                if ($missing !== []) {
                    throw new RuntimeException('Installed Module tables are missing: ' . implode(', ', $missing));
                }
            }
        }
        $health['selected_module_count'] = count($moduleKeys);
        return $health;
    }

    private function pdo(): PDO
    {
        $connection = Db::connect();
        if (!$connection instanceof PDOConnection) {
            throw new RuntimeException('INSTALL_DATABASE_DRIVER_UNAVAILABLE');
        }
        $pdo = $connection->getPdo();
        if (!$pdo instanceof PDO) {
            throw new RuntimeException('INSTALL_DATABASE_CONNECTION_UNAVAILABLE');
        }

        return $pdo;
    }

    /** @return array<string,mixed> */
    private function moduleConfig(): array
    {
        $config = Config::get('modules', []);
        if (!is_array($config)) {
            throw new RuntimeException('MODULE_REGISTRY_UNAVAILABLE');
        }

        return $config;
    }

    private function definitionRegistry(): CompiledModuleRegistry
    {
        return $this->definitions;
    }

    /** Select Peanut-owned SQL by the adopted scaffold and optional verified overlay identity. */
    private function migrationTargetVersion(): string
    {
        $versions = \applicationReleaseVersions($this->serverRoot);
        return \applicationMigrationTargetVersion($this->serverRoot, $versions);
    }

    private function mode(): string
    {
        $mode = trim((string) Config::get('peanut.installation.mode', 'automatic'));
        if (!in_array($mode, self::MODES, true)) {
            throw new RuntimeException('PEANUT_INSTALLATION_MODE must be guided or automatic.');
        }
        return $mode;
    }

    private function deploymentMode(): string
    {
        $mode = trim((string) Config::get('deployment.mode', ''));
        if ($mode !== 'standalone' && $mode !== 'multi-tenant') {
            throw new RuntimeException('DEPLOYMENT_MODE must be standalone or multi-tenant.');
        }
        return $mode;
    }

    /** @param list<string> $moduleKeys */
    private function writeProgressMarker(array $moduleKeys): void
    {
        $this->writeMarker($this->progressMarker(), [
            'schema_version' => 1,
            'state' => 'executing',
            'deployment_mode' => $this->deploymentMode(),
            'official_modules' => $moduleKeys,
            'started_at' => gmdate(DATE_ATOM),
        ]);
    }

    /** @param list<string> $moduleKeys @return array<string,mixed> */
    private function writeCompletionMarker(array $moduleKeys, array $health): array
    {
        $versions = \applicationReleaseVersions($this->serverRoot);
        $baseline = $this->installationBaseline($moduleKeys, $versions);
        $baselineSha256 = hash('sha256', json_encode($baseline, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES) . "\n");
        if (!is_string($baselineSha256)) {
            throw new RuntimeException('INSTALL_RELEASE_IDENTITY_UNAVAILABLE');
        }
        $payload = [
            'schema_version' => 1,
            'protocol' => 'peanut.installation-receipt.v1',
            'state' => 'installed',
            'deployment_mode' => $this->deploymentMode(),
            'official_modules' => $moduleKeys,
            'release' => $versions,
            'baseline_manifest' => [
                'path' => 'server/private/installation/baseline.json',
                'sha256' => $baselineSha256,
            ],
            'completed_at' => gmdate(DATE_ATOM),
        ];
        if (isset($baseline['source']['application_manifest_sha256'])) {
            $payload['application_manifest_sha256'] = $baseline['source']['application_manifest_sha256'];
        }
        // Persist the authenticated real-health frontier before either completion file.
        // A later owner can finish only an already real, exact pair; missing/one-sided pairs stay closed.
        if ($this->partialCompletionIntent !== null) { ($this->partialCompletionIntent)($baseline, $payload, $health); }
        $this->writeMarker($this->baselineMarker(), $baseline);
        if ($this->fileDigest($this->baselineMarker()) !== $baselineSha256) { throw new RuntimeException('INSTALL_COMPLETION_BASELINE_CHANGED'); }
        if ($this->partialGuard !== null) { ($this->partialGuard)(); }
        $this->writeMarker($this->completionMarker(), $payload);
        $receiptSha256 = hash_file('sha256', $this->completionMarker());
        if (!is_string($receiptSha256)) {
            throw new RuntimeException('INSTALL_RELEASE_IDENTITY_UNAVAILABLE');
        }
        return [
            'path' => 'server/private/installation/installed.json',
            'sha256' => $receiptSha256,
            'baseline_manifest' => $payload['baseline_manifest'],
        ];
    }

    /** @param list<string> $moduleKeys @param array<string,string> $versions @return array<string,mixed> */
    private function installationBaseline(array $moduleKeys, array $versions): array
    {
        $projectRoot = dirname($this->serverRoot);
        $serverIdentity = $this->serverRoot . '/.peanut/release-identity.json';
        $applicationManifest = $projectRoot . '/.peanut/application-manifest.json';
        if (ApplicationSourceIdentity::hasRuntime($this->serverRoot)) {
            $identity = ApplicationSourceIdentity::load($this->serverRoot);
            $source = [
                'kind' => 'development-runtime',
                'development_runtime_identity_sha256' => $identity->identitySha256(),
                'application_manifest_sha256' => $identity->manifestSha256(),
            ];
        } elseif (file_exists($serverIdentity) || is_link($serverIdentity)) {
            $identity = ServerReleaseIdentity::resolve($this->serverRoot);
            $source = [
                'kind' => 'server-release',
                'server_release_identity_sha256' => $identity->identitySha256(),
                'application_manifest_sha256' => $identity->manifestSha256(),
            ];
        } elseif (\installationSourceDevelopmentMode($this->serverRoot)) {
            $source = [
                'kind' => 'product-source',
                'inventory_sha256' => $this->fileDigest($projectRoot . '/scaffold/application-template-inventory.json'),
                'edition_profiles_sha256' => $this->fileDigest($projectRoot . '/scaffold/edition-profiles.json'),
                'release_versions_sha256' => $this->fileDigest($projectRoot . '/release-versions.json'),
            ];
        } elseif (file_exists($applicationManifest) || is_link($applicationManifest)) {
            if (!is_file($applicationManifest) || is_link($applicationManifest)) {
                throw new RuntimeException('INSTALL_RELEASE_IDENTITY_UNAVAILABLE');
            }
            $source = [
                'kind' => 'generated-application',
                'application_manifest_sha256' => $this->fileDigest($applicationManifest),
            ];
        } else {
            throw new RuntimeException('INSTALL_RELEASE_IDENTITY_UNAVAILABLE');
        }

        $migrations = [];
        foreach (glob($this->serverRoot . '/database/migrations/*.sql') ?: [] as $path) {
            $migrations[] = [
                'id' => basename($path, '.sql'),
                'sha256' => $this->fileDigest($path),
            ];
        }
        $selection = \app\common\infrastructure\migration\CleanInstallBaseline::selection(
            $this->pdo(), \applicationMigrationFiles($this->serverRoot . '/database'),
            $versions['source_product_version'], $this->serverRoot . '/database',
        );
        if ($selection['baseline'] !== null) {
            $migrations[] = ['id' => $selection['baseline']['key'], 'sha256' => $selection['baseline']['sha256']];
        }
        usort($migrations, static fn(array $left, array $right): int => strcmp($left['id'], $right['id']));

        return [
            'schema_version' => 1,
            'protocol' => 'peanut.installation-baseline.v1',
            'deployment_mode' => $this->deploymentMode(),
            'release' => $versions,
            'source' => $source,
            'database' => [
                'fresh_schema_sha256' => $this->fileDigest($this->serverRoot . '/database/init.sql'),
                'migration_chain' => $migrations,
            ],
            'official_modules' => $moduleKeys,
        ];
    }

    private function fileDigest(string $path): string
    {
        if (!is_file($path) || is_link($path)) {
            throw new RuntimeException('INSTALL_RELEASE_IDENTITY_UNAVAILABLE');
        }
        $digest = hash_file('sha256', $path);
        if (!is_string($digest)) {
            throw new RuntimeException('INSTALL_RELEASE_IDENTITY_UNAVAILABLE');
        }
        return $digest;
    }

    /** @param array<string,mixed> $payload */
    private function writeMarker(string $path, array $payload): void
    {
        $directory = dirname($path);
        if (!is_dir($directory) && !mkdir($directory, 0770, true) && !is_dir($directory)) {
            throw new RuntimeException('Installation state directory is unavailable.');
        }
        $temporary = $path . '.tmp-' . bin2hex(random_bytes(6));
        $json = json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        $contents = $json . "\n";
        if (file_put_contents($temporary, $contents, LOCK_EX) !== strlen($contents)
            || !chmod($temporary, 0600)
            || !rename($temporary, $path)) {
            @unlink($temporary);
            throw new RuntimeException('Installation state marker cannot be written.');
        }
    }

    private function progressMarker(): string
    {
        return $this->serverRoot . '/private/installation/executing.json';
    }

    private function completionMarker(): string
    {
        return $this->serverRoot . '/private/installation/installed.json';
    }

    private function completionLockPresent(): bool
    {
        $path = $this->completionMarker();
        return file_exists($path) || is_link($path);
    }

    /** Historical installation anchors only; no database, catalog or target-source comparison. */
    public static function verifiedInstallationFacts(string $serverRoot): array
    {
        if (realpath($serverRoot) !== $serverRoot) {
            throw new RuntimeException('APPLICATION_SCHEMA_INSTALLATION_ROOT_INVALID');
        }
        $read = static function (string $path): array {
            $stat = is_file($path) && !is_link($path) ? lstat($path) : false;
            if (!is_array($stat) || $stat['nlink'] !== 1 || ($stat['mode'] & 0777) !== 0600
                || $stat['size'] > 4194304) {
                throw new RuntimeException('APPLICATION_SCHEMA_INSTALLATION_FILE_UNSAFE');
            }
            $bytes = file_get_contents($path);
            if (!is_string($bytes)) {
                throw new RuntimeException('APPLICATION_SCHEMA_INSTALLATION_FILE_UNAVAILABLE');
            }
            $value = json_decode($bytes, true, 512, JSON_THROW_ON_ERROR);
            if (!is_array($value) || !hash_equals(hash('sha256', $bytes), (string) hash_file('sha256', $path))) {
                throw new RuntimeException('APPLICATION_SCHEMA_INSTALLATION_FILE_CHANGED');
            }
            return ['value' => $value, 'sha256' => hash('sha256', $bytes)];
        };
        $receiptFile = $read($serverRoot . '/private/installation/installed.json');
        $receipt = $receiptFile['value'];
        if (($receipt['schema_version'] ?? null) !== 1
            || ($receipt['protocol'] ?? null) !== 'peanut.installation-receipt.v1'
            || ($receipt['state'] ?? null) !== 'installed'
            || !in_array($receipt['deployment_mode'] ?? null, ['standalone', 'multi-tenant'], true)
            || !is_array($receipt['official_modules'] ?? null) || !array_is_list($receipt['official_modules'])
            || !is_array($receipt['release'] ?? null)
            || !is_array($receipt['baseline_manifest'] ?? null)
            || !is_string($receipt['completed_at'] ?? null)
            || preg_match('/\A[0-9]{4}-[0-9]{2}-[0-9]{2}T[0-9]{2}:[0-9]{2}:[0-9]{2}(?:\.[0-9]+)?(?:Z|[+-][0-9]{2}:[0-9]{2})\z/D', $receipt['completed_at']) !== 1) {
            throw new RuntimeException('APPLICATION_SCHEMA_INSTALLATION_RECEIPT_INVALID');
        }
        foreach ($receipt['official_modules'] as $module) {
            if (!is_string($module) || preg_match('/\A[a-z][a-z0-9_-]*\.[a-z][a-z0-9_.-]*\z/D', $module) !== 1) {
                throw new RuntimeException('APPLICATION_SCHEMA_INSTALLATION_MODULES_INVALID');
            }
        }
        if (count(array_unique($receipt['official_modules'])) !== count($receipt['official_modules'])) {
            throw new RuntimeException('APPLICATION_SCHEMA_INSTALLATION_MODULES_INVALID');
        }
        foreach (['source_product_version', 'release_sequence_version', 'scaffold_template'] as $name) {
            if (!is_string($receipt['release'][$name] ?? null)
                || preg_match('/\A(?:0|[1-9][0-9]*)\.(?:0|[1-9][0-9]*)\.(?:0|[1-9][0-9]*)(?:-[0-9A-Za-z.-]+)?(?:\+[0-9A-Za-z.-]+)?\z/D', $receipt['release'][$name]) !== 1) {
                throw new RuntimeException('APPLICATION_SCHEMA_INSTALLATION_RELEASE_INVALID');
            }
        }
        $reference = $receipt['baseline_manifest'];
        $baselinePath = $reference['path'] ?? null;
        $baselineSha256 = $reference['sha256'] ?? null;
        if (!in_array($baselinePath, [
            'server/runtime/installation/baseline.json',
            'server/private/installation/baseline.json',
        ], true) || !is_string($baselineSha256)
            || preg_match('/^[a-f0-9]{64}$/D', $baselineSha256) !== 1) {
            throw new RuntimeException('APPLICATION_SCHEMA_INSTALLATION_BASELINE_INVALID');
        }
        // The canonical private baseline remains authoritative after the existing
        // installation-state migration; a historical path is not a read fallback.
        $baselineFile = $read($serverRoot . '/private/installation/baseline.json');
        $baseline = $baselineFile['value'];
        if (!hash_equals($baselineSha256, $baselineFile['sha256'])
            || ($baseline['schema_version'] ?? null) !== 1
            || ($baseline['protocol'] ?? null) !== 'peanut.installation-baseline.v1'
            || ($baseline['deployment_mode'] ?? null) !== $receipt['deployment_mode']
            || ($baseline['release'] ?? null) !== $receipt['release']
            || ($baseline['official_modules'] ?? null) !== $receipt['official_modules']) {
            throw new RuntimeException('APPLICATION_SCHEMA_INSTALLATION_BASELINE_MISMATCH');
        }
        $source = $baseline['source'] ?? null;
        $required = match (is_array($source) ? ($source['kind'] ?? null) : null) {
            'development-runtime' => ['development_runtime_identity_sha256', 'application_manifest_sha256'],
            'server-release' => ['server_release_identity_sha256', 'application_manifest_sha256'],
            'generated-application' => ['application_manifest_sha256'],
            'product-source' => ['inventory_sha256', 'edition_profiles_sha256', 'release_versions_sha256'],
            default => throw new RuntimeException('APPLICATION_SCHEMA_INSTALLATION_SOURCE_INVALID'),
        };
        foreach ($required as $field) {
            if (preg_match('/\A[a-f0-9]{64}\z/D', (string) ($source[$field] ?? '')) !== 1) {
                throw new RuntimeException('APPLICATION_SCHEMA_INSTALLATION_SOURCE_INVALID');
            }
        }
        $chain = $baseline['database']['migration_chain'] ?? null;
        if (!is_array($chain) || !array_is_list($chain)
            || preg_match('/\A[a-f0-9]{64}\z/D', (string) ($baseline['database']['fresh_schema_sha256'] ?? '')) !== 1) {
            throw new RuntimeException('APPLICATION_SCHEMA_INSTALLATION_CHAIN_INVALID');
        }
        $previous = '';
        foreach ($chain as $migration) {
            $id = is_array($migration) ? ($migration['id'] ?? null) : null;
            if (!is_string($id) || preg_match('/\A[0-9]{8}-[a-z0-9][a-z0-9_-]*\z/D', $id) !== 1
                || strcmp($previous, $id) >= 0
                || preg_match('/\A[a-f0-9]{64}\z/D', (string) ($migration['sha256'] ?? '')) !== 1) {
                throw new RuntimeException('APPLICATION_SCHEMA_INSTALLATION_CHAIN_INVALID');
            }
            $previous = $id;
        }
        return [
            'receipt_sha256' => $receiptFile['sha256'], 'baseline_sha256' => $baselineFile['sha256'],
            'receipt' => $receipt, 'baseline' => $baseline,
        ];
    }

    private function completionReceiptValid(): bool
    {
        try {
            self::verifiedInstallationFacts($this->serverRoot);
            return true;
        } catch (Throwable) {
            return false;
        }
    }

    private function baselineMarker(): string
    {
        return $this->serverRoot . '/private/installation/baseline.json';
    }

    private function hasLegacyInstallationState(): bool
    {
        $legacy = $this->serverRoot . '/runtime/installation';
        foreach (['executing.json', 'installed.json', 'baseline.json'] as $name) {
            if (file_exists($legacy . '/' . $name) || is_link($legacy . '/' . $name)) {
                return true;
            }
        }
        return false;
    }

    private function installationMigrationComplete(): bool
    {
        $path = $this->serverRoot . '/private/installation/migration.json';
        if (!file_exists($path) && !is_link($path)) {
            return true;
        }
        if (!is_file($path) || is_link($path)) {
            return false;
        }
        try {
            $record = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            return false;
        }
        if (!is_array($record)
            || ($record['protocol'] ?? null) !== 'peanut.installation-state-migration.v1'
            || ($record['phase'] ?? null) !== 'complete'
            || !is_array($record['files'] ?? null)
            || $record['files'] === []) {
            return false;
        }
        foreach ($record['files'] as $name => $identity) {
            if (!in_array($name, ['executing.json', 'installed.json', 'baseline.json'], true)
                || !is_array($identity)
                || !is_string($identity['sha256'] ?? null)
                || !is_int($identity['bytes'] ?? null)) {
                return false;
            }
            $file = dirname($path) . '/' . $name;
            if (!is_file($file) || is_link($file)
                || filesize($file) !== $identity['bytes']
                || hash_file('sha256', $file) !== $identity['sha256']) {
                return false;
            }
        }
        return true;
    }
}
