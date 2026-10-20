<?php

declare(strict_types=1);

namespace app\common\services\upgrade;

use app\common\execution\CurrentExecutionContext;
use app\common\execution\InstanceExecutionContext;
use app\common\infrastructure\scaffold\ScaffoldUpgradeRunner;
use app\common\services\installation\InstallationExecutionHost;
use PDO;
use RuntimeException;
use think\App;
use think\facade\Db;

/** Holds the native source Plugin locks; only a live, bound apply admits writes. */
final class PluginSourceDevelopmentHost
{
    private ?array $planning = null;
    private ?array $mutation = null;
    private string $sourceMetadataTool = '';
    private const CONTRACTS = [
        'official.file' => \PeanutAdmin\Modules\File\Contract\FileMigrationPreservation::class,
        'official.document' => \PeanutAdmin\Modules\Document\Contract\DocumentMigrationPreservation::class,
        'official.article' => \PeanutAdmin\Modules\Article\Contract\ArticleMigrationPreservation::class,
        'official.member' => \PeanutAdmin\Modules\Member\Contract\MemberSessionMigrationPreservation::class,
        'official.import-export' => \PeanutAdmin\Modules\ImportExport\Contract\ImportExportMigrationPreservation::class,
        'official.notification' => \PeanutAdmin\Modules\Notification\Contract\NotificationUpgradePreflight::class,
        'official.task' => \PeanutAdmin\Modules\Task\Job\Database\TaskSchemaMigrationProof::class,
        'peanut.workflow' => \PeanutAdmin\Modules\Workflow\Database\WorkflowSchemaMigrationProof::class,
    ];

    public function __construct(private readonly App $app) {}

    public function plan(string $operation, string $pluginKey, array $actor, callable $readPlan, string $toolId = '', string $output = '', ?array $execution = null, string $metadataTool = ''): array
    {
        $this->sourceMetadataTool = $metadataTool;
        $applying = $execution !== null;
        if ($applying && ($toolId !== '' || $output !== '' || !is_callable($execution['mutate'] ?? null))) { throw new RuntimeException('PLUGIN_SOURCE_ARGUMENTS_INVALID'); }
        if (($toolId === '') !== ($output === '')) { throw new RuntimeException('PLUGIN_SOURCE_BACKUP_ARGUMENTS_REQUIRED'); }
        $serverRoot = rtrim($this->app->getRootPath(), DIRECTORY_SEPARATOR);
        $context = $this->app->make(CurrentExecutionContext::class)->current();
        if (!in_array($operation, ['install', 'upgrade'], true) || $pluginKey === '' || $this->planning !== null
            || !$context instanceof InstanceExecutionContext || $context->operation() !== 'console.plugin:' . $operation
            || $context->instance->actorKey !== 'console' || PHP_SAPI !== 'cli'
            || $this->app->config->get('peanut.environment') !== 'development' || !$this->app->isDebug()
            || !in_array($this->app->config->get('deployment.mode'), ['standalone', 'multi-tenant'], true)) {
            throw new RuntimeException('PLUGIN_SOURCE_CONTEXT_REQUIRED');
        }
        $admission = new SourceDevelopmentMaintenanceAdmission($serverRoot);
        $initial = $admission->capture($actor, $applying);
        $config = \guardedDatabaseConfig();
        [$connection, $pdo] = $this->connect($config);
        $identity = InstallationExecutionHost::migrationDatabaseIdentity($pdo);
        $files = \applicationMigrationFiles($serverRoot . '/database');
        $versions = \applicationReleaseVersions($serverRoot);
        $target = \applicationMigrationTargetVersion($serverRoot, $versions);
        $runner = new ApplicationMigrationRunner($config, $pdo);
        return ScaffoldUpgradeRunner::sourceProductLock(dirname($serverRoot), function () use ($runner, $files, $target, $versions,
            $pdo, $connection, $admission, $actor, $initial, $identity, $context, $operation, $pluginKey, $config, $readPlan, $toolId, $output, $execution, $applying): array {
            $result = null;
            $runner->run($files, $target, $versions['release_sequence_version'], true, null,
                function (PDO $actual, array $selection) use ($pdo, $connection, $admission, $actor, $initial, $identity,
                    $context, $operation, $pluginKey, $config, $files, $readPlan, $toolId, $output, $execution, $applying, &$result): void {
                    $recheck = function () use ($pdo, $connection, $admission, $actor, $initial, $identity, $context, $config, $applying): void {
                        $current = $this->app->make(CurrentExecutionContext::class)->current();
                        if ($current !== $context || Db::connect() !== $connection || $connection->getPdo() !== $pdo
                            || $admission->capture($actor, $applying) !== $initial || InstallationExecutionHost::migrationDatabaseIdentity($pdo) !== $identity) {
                            throw new RuntimeException('PLUGIN_SOURCE_PLAN_CHANGED');
                        }
                        ScaffoldUpgradeRunner::sourceProductLock(dirname(rtrim($this->app->getRootPath(), DIRECTORY_SEPARATOR)), static fn() => null);
                        $lock = $pdo->prepare('SELECT IS_USED_LOCK(?) = CONNECTION_ID()');
                        try {
                            $lock->execute(['peanut_migrate_' . substr(hash('sha256', $config['database']), 0, 48)]);
                            if ((int) $lock->fetchColumn() !== 1) { throw new RuntimeException('PLUGIN_SOURCE_SCHEMA_LOCK_REQUIRED'); }
                        } finally { $lock->closeCursor(); }
                    };
                    if ($actual !== $pdo || $pdo->inTransaction()) { throw new RuntimeException('PLUGIN_SOURCE_MASTER_MISMATCH'); }
                    $recheck();
                    $prerequisite = null;
                    foreach ($files as $file) {
                        if (basename($file, '.sql') === '20261019-module-migration-provenance-metadata'
                            && in_array('20261019-module-migration-provenance-metadata', $selection['pending'], true)) {
                            $prerequisite = ['migration_id' => '20261019-module-migration-provenance-metadata',
                                'reason' => 'module_migration_provenance_schema', 'sha256' => hash_file('sha256', $file)];
                        }
                    }
                    $this->planning = ['pdo' => $pdo, 'recheck' => $recheck, 'prerequisite' => $prerequisite];
                    try {
                        $result = $readPlan(function (array $native) use ($operation, $pluginKey, $initial, $identity, $selection,
                            $pdo, $config, $recheck, $readPlan, $toolId, $output, $execution, $applying, $context): array {
                            if ($pdo->inTransaction()) { throw new RuntimeException('PLUGIN_SOURCE_PLAN_TRANSACTION_INCOMPLETE'); }
                            $recheck();
                            if ($operation === 'install' && ($native['action'] ?? null) !== 'planned_install') {
                                throw new \app\platform\exception\plugin\PluginLifecycleException('PLUGIN_ALREADY_INSTALLED', 'Use the explicit upgrade plan for an installed Plugin.');
                            }
                            $binding = $initial + ['database' => $identity];
                            $effects = SourceApplicationBackup::pluginEffects($native);
                            $report = ['protocol' => 'peanut.source-development-plugin-plan.v1', 'maintenance_scope' => 'source-development',
                                'operation' => $operation, 'plugin_key' => $pluginKey, 'binding' => $binding,
                                'application' => $selection, 'plugin' => $native, 'lifecycle_effects' => $effects, 'execution_admitted' => false,
                                'recovery' => 'not_attested', 'product_database' => 'not_verified'];
                            if ($this->sourceMetadataTool !== '') { $report['metadata_tool'] = $this->sourceMetadataTool; }
                            if ($applying) {
                                if ($this->sourceMetadataTool === '') {
                                $grants = $pdo->query('SHOW GRANTS')->fetchAll(PDO::FETCH_COLUMN);
                                $select = false; $trigger = false;
                                foreach ($grants as $grant) {
                                    if (str_starts_with($grant, 'REVOKE ')) { throw new RuntimeException('PLUGIN_SOURCE_METADATA_VISIBILITY_REQUIRED'); }
                                    if (preg_match('/\AGRANT (.+) ON (.+) TO /D', $grant, $parts) !== 1) { continue; }
                                    $privileges = explode(', ', $parts[1]);
                                    $global = $parts[2] === '*.*';
                                    $database = $parts[2] === '`' . str_replace('`', '``', $identity['database']) . '`.*';
                                    $select = $select || ($global && (in_array('SELECT', $privileges, true) || in_array('ALL PRIVILEGES', $privileges, true)));
                                    $trigger = $trigger || (($global || $database) && (in_array('TRIGGER', $privileges, true) || in_array('ALL PRIVILEGES', $privileges, true)));
                                }
                                if (!$select || !$trigger) { throw new RuntimeException('PLUGIN_SOURCE_METADATA_VISIBILITY_REQUIRED'); }
                                }
                                $expected = SourceApplicationBackup::protectedPluginJson($execution['plan'], $this->app->getRootPath());
                                if (($expected['metadata_tool'] ?? '') !== $this->sourceMetadataTool) { throw new RuntimeException('PLUGIN_SOURCE_PLAN_STALE'); }
                                foreach (array_keys($report) as $field) {
                                    if (self::digest([$expected[$field] ?? null]) !== self::digest([$report[$field]])) { throw new RuntimeException('PLUGIN_SOURCE_PLAN_STALE'); }
                                }
                                if (($native['may_apply'] ?? null) !== true) {
                                    // The original business reports passed, but this read plan has no write window.
                                    // No caller-supplied may_apply is substituted for the later held re-plan.
                                    if (($native['planning_complete'] ?? null) !== true) { throw new RuntimeException('PLUGIN_SOURCE_PLAN_BLOCKED'); }
                                    foreach ($native['modules'] as $module) {
                                        if (($module['may_apply'] ?? null) === true) { continue; }
                                        if (($module['source_admission_deferred'] ?? null) !== true || ($module['planning_complete'] ?? null) !== true
                                            || ($module['application_prerequisites'] ?? null) !== []) { throw new RuntimeException('PLUGIN_SOURCE_PLAN_BLOCKED'); }
                                    }
                                }
                                if (($effects['closure_complete'] ?? null) !== true || $this->planning['prerequisite'] !== null) { throw new RuntimeException('PLUGIN_SOURCE_PLAN_BLOCKED'); }
                                $scope = SourceApplicationBackup::pluginScope($pdo, $effects, $binding, $this->sourceMetadataTool, $recheck);
                                $this->assertEffectBoundary($scope, $effects, $identity['database']);
                                $baseline = ['pending' => $effects['pending'], 'selected' => $effects['selected'],
                                    'operation' => $operation, 'plugin_key' => $pluginKey, 'native_plan_sha256' => self::digest($native), 'lifecycle_effects' => $effects];
                                $evidence = fn(): array => SourceApplicationBackup::pluginRecovery($binding, $baseline, $scope,
                                    $execution['maintenance'], $execution['recovery'], $this->app->getRootPath());
                                $proof = $evidence();
                                $recheck();
                                $readLedger = static function () use ($pdo, $native): array {
                                    $query = $pdo->prepare('SELECT * FROM pa_module_migration WHERE module_key IN ('
                                        . implode(',', array_fill(0, count($native['modules']), '?')) . ') ORDER BY module_key,migration_key');
                                    try { $query->execute(array_column($native['modules'], 'module_key')); return $query->fetchAll(PDO::FETCH_ASSOC); }
                                    finally { $query->closeCursor(); }
                                };
                                $oldLedger = $readLedger();
                                $this->mutation = ['pdo' => $pdo, 'plugin_key' => $pluginKey, 'operation' => $operation,
                                    'pending' => $effects['pending'], 'selected' => $effects['selected'], 'stages' => [],
                                    'effects' => $effects, 'binding' => $binding, 'recheck' => $recheck,
                                    'check' => function () use ($recheck, $evidence, $proof): void {
                                        $recheck();
                                        if ($evidence() !== $proof) { throw new RuntimeException('PLUGIN_SOURCE_WINDOW_CHANGED'); }
                                    },
                                    'admission' => ['schema_version' => 1, 'scope' => 'source-plugin', 'protocol' => 'peanut.source-plugin-admission.v1',
                                        'instance_root' => realpath($this->app->getRootPath()), 'database_identity' => $identity,
                                        'plugin_key' => $pluginKey, 'operation' => $context->operation(), 'request_id' => $context->requestId(),
                                        'plan_sha256' => self::digest($native), 'binding' => $binding, 'evidence' => $proof,
                                        'external_writer_exclusion' => 'ops_protocol_gate_required_not_attested']];
                                $this->mutation['admission']['admission_digest'] = self::digest($this->mutation['admission']);
                                try {
                                    $this->requireMutation($pdo, $pluginKey);
                                    $ready = $readPlan(static fn(array $plan): array => $plan);
                                    if (($ready['may_apply'] ?? null) !== true
                                        || self::digest(SourceApplicationBackup::pluginEffects($ready)) !== self::digest($effects)
                                        || SourceApplicationBackup::pluginScope($pdo, $effects, $binding, $this->sourceMetadataTool, $recheck) !== $scope || $readLedger() !== $oldLedger) {
                                        throw new RuntimeException('PLUGIN_SOURCE_EXECUTION_PLAN_CHANGED');
                                    }
                                    $this->mutation['admission']['execution_plan_sha256'] = self::digest($ready);
                                    unset($this->mutation['admission']['admission_digest']);
                                    $this->mutation['admission']['admission_digest'] = self::digest($this->mutation['admission']);
                                    $this->requireMutation($pdo, $pluginKey);
                                    // The outer read-plan callback still holds the native package lock.
                                    // Only this call enters the original lifecycle, never a copied SQL runner.
                                    $result = ($execution['mutate'])();
                                    $this->requireMutation($pdo, $pluginKey);
                                    if ($pdo->inTransaction()) { throw new RuntimeException('PLUGIN_SOURCE_TRANSACTION_INCOMPLETE'); }
                                    $ledger = $readLedger(); $byKey = array_column($ledger, null, 'migration_key');
                                    foreach ($oldLedger as $row) {
                                        if (($byKey[$row['migration_key']] ?? null) !== $row) { throw new RuntimeException('PLUGIN_SOURCE_OLD_LEDGER_CHANGED'); }
                                    }
                                    foreach ($effects['pending'] as $key) {
                                        $row = $byKey[$key] ?? [];
                                        if (($row['status'] ?? null) !== 'applied' || ($row['checksum'] ?? null) !== $effects['selected'][$key]
                                            || !in_array($row['resolution_kind'] ?? null, ['executed', 'adopted'], true)) { throw new RuntimeException('PLUGIN_SOURCE_LEDGER_INCOMPLETE'); }
                                    }
                                    $stages = $this->mutation['stages'];
                                    $seen = array_column($stages, 'migration_key'); $wanted = $effects['pending']; sort($seen, SORT_STRING); sort($wanted, SORT_STRING);
                                    if ($seen !== $wanted) { throw new RuntimeException('PLUGIN_SOURCE_STAGE_RECEIPTS_INCOMPLETE'); }
                                    foreach ($stages as &$stage) {
                                        $stage['applied_ledger'] = $byKey[$stage['migration_key']];
                                        if (($stage['accepted_while'] === 'applied' && $stage['applied_ledger']['resolution_kind'] !== 'adopted')
                                            || ($stage['accepted_while'] === 'applying' && $stage['applied_ledger']['resolution_kind'] !== 'executed')) {
                                            throw new RuntimeException('PLUGIN_SOURCE_STAGE_RECEIPTS_INCOMPLETE');
                                        }
                                    }
                                    unset($stage);
                                    $writerSha = hash_file('sha256', __FILE__);
                                    $executorSha = hash_file('sha256', rtrim($this->app->getRootPath(), DIRECTORY_SEPARATOR) . '/app/platform/services/plugin/PluginLifecycleService.php');
                                    $acceptedAt = time();
                                    if (SourceApplicationBackup::pluginRecovery($binding, $baseline, $scope, $execution['maintenance'], $execution['recovery'], $this->app->getRootPath(), $acceptedAt) !== $proof) {
                                        throw new RuntimeException('PLUGIN_SOURCE_WINDOW_CHANGED');
                                    }
                                    $this->requireMutation($pdo, $pluginKey);
                                    $record = ['protocol' => 'peanut.source-plugin-result.v1', 'status' => 'completed', 'operation' => $operation,
                                        'plugin_key' => $pluginKey, 'binding' => $binding, 'native_plan_sha256' => self::digest($native),
                                        'evidence' => $proof, 'result' => $result, 'ledger' => $ledger,
                                        'native_plan' => $native, 'execution_plan' => $ready, 'baseline' => $baseline, 'backup_scope' => $scope,
                                        'admission' => $this->mutation['admission'], 'ordered_stages' => $stages, 'observed_at' => $acceptedAt,
                                        'request_id' => $context->requestId(), 'writer_sha256' => $writerSha, 'executor_sha256' => $executorSha,
                                        'verification_level' => 'native_same_process_lifecycle_completion',
                                        'cross_process_preservation' => 'not_verified', 'product_database' => 'not_verified', 'qualification' => 'not_run'];
                                    $record['document_sha256'] = self::digest($record);
                                    $record['result_path'] = $this->saveReceipt($record);
                                    $this->requireMutation($pdo, $pluginKey);
                                    return $record;
                                } finally { $this->mutation = null; }
                            }
                            if ($toolId !== '') {
                                $this->assertBackupReads($effects['tables'], $config);
                                $scope = SourceApplicationBackup::pluginScope($pdo, $effects, $binding, $this->sourceMetadataTool, $recheck);
                                $registry = \projectResourceRegistry($registrySha);
                                $tools = array_values(array_filter($registry['resources']['tooling'] ?? [], static fn(array $row): bool => ($row['stable_resource_id'] ?? null) === $toolId));
                                $resources = \activeLeaseResources(\requiredEnvironment('PEANUT_RESOURCE_LEASE_PROOF'));
                                if (count($tools) !== 1 || $registrySha !== $binding['registry_sha256']
                                    || !in_array($toolId, $resources['tooling-resource-id'] ?? [], true) || !in_array($output, $resources['output-dir'] ?? [], true)) {
                                    throw new RuntimeException('PLUGIN_SOURCE_BACKUP_NATIVE_TOOL_OUTPUT_REQUIRED');
                                }
                                $baseline = ['pending' => $effects['pending'], 'selected' => $effects['selected'],
                                    'operation' => $operation, 'plugin_key' => $pluginKey, 'native_plan_sha256' => self::digest($native), 'lifecycle_effects' => $effects];
                                $checkBackup = function () use ($pdo, $recheck, $readPlan, $native, $effects, $scope, $binding): void {
                                    $recheck();
                                    // The original native lock recognizes its own owner;
                                    // this nested read does not release the outer package lock.
                                    $current = $readPlan(static fn(array $plan): array => $plan);
                                    if ($pdo->inTransaction() || self::digest($current) !== self::digest($native)
                                        || SourceApplicationBackup::pluginScope($pdo, $effects, $binding, $this->sourceMetadataTool, $recheck) !== $scope) {
                                        throw new RuntimeException('PLUGIN_SOURCE_BACKUP_SNAPSHOT_CHANGED');
                                    }
                                    $recheck();
                                };
                                $report['backup'] = SourceApplicationBackup::capture($binding, $baseline, self::digest($baseline), $scope,
                                    $tools[0], $output, $checkBackup, 'plugin-lifecycle');
                                $report['backup_scope'] = $scope;
                            }
                            $recheck();
                            return $report;
                        });
                    } finally { $this->planning = null; }
                });
            if (!is_array($result)) { throw new RuntimeException('PLUGIN_SOURCE_PLAN_INCOMPLETE'); }
            return $result;
        });
    }

    private function connect(array $config): array
    {
        $connection = Db::connect();
        foreach (['hostname' => 'host', 'hostport' => 'port', 'database' => 'database', 'username' => 'user', 'password' => 'password'] as $native => $guarded) {
            if ((string) $connection->getConfig($native) !== (string) $config[$guarded]) { throw new RuntimeException('PLUGIN_SOURCE_MASTER_MISMATCH'); }
        }
        if ($connection->getConfig('break_reconnect') !== false || (int) $connection->getConfig('deploy') !== 0 || $connection->getConfig('prefix') !== 'pa_') {
            throw new RuntimeException('PLUGIN_SOURCE_MASTER_MISMATCH');
        }
        $connection->query('SELECT CONNECTION_ID()', [], true);
        $pdo = $connection->getPdo();
        if (!$pdo instanceof PDO || $pdo->inTransaction()) { throw new RuntimeException('PLUGIN_SOURCE_MASTER_MISMATCH'); }
        return [$connection, $pdo];
    }

    /** Fresh metadata at real SQL boundaries; DML transaction guards keep their existing contract. */
    public function observeEffects(PDO $pdo): void
    {
        if ($this->mutation === null || $this->sourceMetadataTool === '') { return; }
        $this->requireMutation($pdo, $this->mutation['plugin_key']);
        $scope = SourceApplicationBackup::pluginScope($pdo, $this->mutation['effects'], $this->mutation['binding'],
            $this->sourceMetadataTool, $this->mutation['recheck']);
        $this->assertEffectBoundary($scope, $this->mutation['effects'], $this->mutation['binding']['database']['database']);
    }

    private function assertEffectBoundary(array $scope, array $effects, string $database): void
    {
        if ($scope['triggers'] !== []) { throw new RuntimeException('PLUGIN_SOURCE_TRIGGER_EFFECT_REVIEW_REQUIRED'); }
        foreach ($scope['foreign_keys'] as $foreign) {
            if ((int) $foreign['IS_LOCAL_PARENT'] === 1 && in_array($foreign['REFERENCED_TABLE_NAME'], $effects['tables'], true)
                && ($foreign['TABLE_SCHEMA'] !== $database || !in_array($foreign['TABLE_NAME'], $effects['tables'], true))
                && (in_array($foreign['UPDATE_RULE'], ['CASCADE', 'SET NULL'], true) || in_array($foreign['DELETE_RULE'], ['CASCADE', 'SET NULL'], true))) {
                throw new RuntimeException('PLUGIN_SOURCE_CASCADE_EFFECT_REVIEW_REQUIRED');
            }
        }
    }

    /** Called only after the original owning after assertion, before the real native applied write. */
    public function recordStage(PDO $pdo, string $key, string $sha, string $contract, array $after): void
    {
        if ($this->mutation === null) { return; }
        $this->requireMutation($pdo, $this->mutation['plugin_key']);
        if (!in_array($key, $this->mutation['pending'], true) || ($this->mutation['selected'][$key] ?? null) !== $sha
            || in_array($key, array_column($this->mutation['stages'], 'migration_key'), true)) { throw new RuntimeException('PLUGIN_SOURCE_STAGE_RECEIPT_INVALID'); }
        $this->assertSafeAfter($key, $sha, $contract, $after);
        $module = explode(':', $key, 2)[0];
        $query = $pdo->prepare('SELECT status,checksum,resolution_kind FROM pa_module_migration WHERE module_key=? AND migration_key=?');
        try { $query->execute([$module, $key]); $rows = $query->fetchAll(PDO::FETCH_ASSOC); } finally { $query->closeCursor(); }
        if (count($rows) !== 1 || $rows[0]['checksum'] !== $sha || !in_array($rows[0]['status'], ['applying', 'applied'], true)
            || ($rows[0]['status'] === 'applied' && $rows[0]['resolution_kind'] !== 'adopted')) { throw new RuntimeException('PLUGIN_SOURCE_STAGE_RECEIPT_INVALID'); }
        $file = (new \ReflectionClass($contract))->getFileName();
        $modulesRoot = rtrim($this->app->getRootPath(), DIRECTORY_SEPARATOR) . '/app/modules/';
        $allowedPath = is_string($file) && ($module === 'peanut.workflow'
            ? $file === $modulesRoot . 'peanut/workflow/src/Database/WorkflowSchemaMigrationProof.php'
            : str_starts_with($file, $modulesRoot . 'official/'));
        if (!$allowedPath || realpath($file) !== $file) {
            throw new RuntimeException('PLUGIN_SOURCE_STAGE_RECEIPT_INVALID');
        }
        $this->mutation['stages'][] = ['ordinal' => count($this->mutation['stages']) + 1, 'module_key' => $module,
            'migration_key' => $key, 'sql_sha256' => $sha, 'contract' => $contract, 'contract_sha256' => hash_file('sha256', $file),
            'accepted_while' => $rows[0]['status'], 'safe_after' => $after];
        $this->requireMutation($pdo, $this->mutation['plugin_key']);
    }

    private function assertSafeAfter(string $key, string $sha, string $contract, array $after): void
    {
        $module = explode(':', $key, 2)[0];
        $allowed = ['module_key', 'migration_key', 'sql_sha256', 'checksum', 'readonly', 'preservation_verified', 'before_sha256',
            'after_sha256', 'table_counts', 'delta_kind', 'delta_sha256', 'proof_kind', 'table_count', 'created_empty_tables',
            'metadata_sha256', 'ledger_sha256', 'data_proof'];
        if ((self::CONTRACTS[$module] ?? null) !== $contract || ($after['module_key'] ?? null) !== $module
            || ($after['migration_key'] ?? null) !== $key || ($after['sql_sha256'] ?? $after['checksum'] ?? null) !== $sha
            || ($after['preservation_verified'] ?? null) !== true || array_diff(array_keys($after), $allowed) !== []
            || ($module !== 'official.file' && ($after['readonly'] ?? null) !== true)) { throw new RuntimeException('PLUGIN_SOURCE_SAFE_AFTER_REQUIRED'); }
        foreach ($after as $field => $value) {
            if ($field === 'table_counts') {
                if ($module !== 'official.file' || !is_array($value)) { throw new RuntimeException('PLUGIN_SOURCE_SAFE_AFTER_REQUIRED'); }
                foreach ($value as $table => $count) {
                    if (!is_string($table) || preg_match('/\A[a-z0-9_]+\z/D', $table) !== 1 || ($count !== null && (!is_int($count) || $count < 0))) {
                        throw new RuntimeException('PLUGIN_SOURCE_SAFE_AFTER_REQUIRED');
                    }
                }
                continue;
            }
            if (!is_string($value) && !is_int($value) && !is_bool($value) && $value !== null) { throw new RuntimeException('PLUGIN_SOURCE_SAFE_AFTER_REQUIRED'); }
            if (str_ends_with($field, 'sha256') && (!is_string($value) || preg_match('/\A[a-f0-9]{64}\z/D', $value) !== 1)) {
                throw new RuntimeException('PLUGIN_SOURCE_SAFE_AFTER_REQUIRED');
            }
        }
        $digests = in_array($module, ['official.task', 'official.notification'], true) ? ['metadata_sha256', 'ledger_sha256'] : ['before_sha256', 'after_sha256'];
        foreach ($digests as $field) {
            if (preg_match('/\A[a-f0-9]{64}\z/D', (string) ($after[$field] ?? '')) !== 1) { throw new RuntimeException('PLUGIN_SOURCE_SAFE_AFTER_REQUIRED'); }
        }
    }

    private function receiptDirectory(bool $create): string
    {
        $parent = rtrim($this->app->getRootPath(), DIRECTORY_SEPARATOR); $owner = fileowner($parent);
        foreach (['private', 'installation', 'plugin-source-development'] as $part) {
            $parent .= '/' . $part;
            if (($create && !is_dir($parent) && !mkdir($parent, 0700)) || realpath($parent) !== $parent || !is_dir($parent) || is_link($parent)
                || !in_array(fileowner($parent), [0, $owner], true) || (fileperms($parent) & 0022) !== 0) { throw new RuntimeException('PLUGIN_SOURCE_RECEIPT_ORIGIN_INVALID'); }
        }
        if ((fileperms($parent) & 0777) !== 0700) { throw new RuntimeException('PLUGIN_SOURCE_RECEIPT_ORIGIN_INVALID'); }
        return $parent;
    }

    private function saveReceipt(array $record): string
    {
        $directory = $this->receiptDirectory(true);
        $path = $directory . '/result-' . bin2hex(random_bytes(16)) . '.json'; $tmp = $path . '.tmp';
        $stream = fopen($tmp, 'x'); $bytes = json_encode($record, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";
        if ($stream === false || !chmod($tmp, 0600) || fwrite($stream, $bytes) !== strlen($bytes) || !fflush($stream) || !fsync($stream)) {
            throw new RuntimeException('PLUGIN_SOURCE_RECEIPT_WRITE_FAILED');
        }
        fclose($stream);
        if (!rename($tmp, $path)) { throw new RuntimeException('PLUGIN_SOURCE_RECEIPT_WRITE_FAILED'); }
        $stream = fopen($directory, 'r');
        if ($stream === false || !fsync($stream)) { throw new RuntimeException('PLUGIN_SOURCE_RECEIPT_WRITE_FAILED'); }
        fclose($stream);
        SourceApplicationBackup::protectedPluginJson($path, $this->app->getRootPath());
        return $path;
    }

    private function assertReceiptSource(string $head, string $file, string $sha): void
    {
        $root = dirname(rtrim($this->app->getRootPath(), DIRECTORY_SEPARATOR));
        if (!str_starts_with($file, $root . '/') || !is_file($file) || is_link($file) || hash_file('sha256', $file) !== $sha
            || \leaseGit(['-C', $root, 'rev-parse', $head . ':' . substr($file, strlen($root) + 1)])
                !== \leaseGit(['-C', $root, 'hash-object', '--no-filters', '--', $file])) { throw new RuntimeException('PLUGIN_SOURCE_ORIGINAL_CONTRACT_UNAVAILABLE'); }
    }

    /** A new native read grant observes only the original scope; it never enters the lifecycle. */
    public function verifyReceipt(string $operation, string $pluginKey, array $actor, string $path): array
    {
        $root = rtrim($this->app->getRootPath(), DIRECTORY_SEPARATOR);
        $context = $this->app->make(CurrentExecutionContext::class)->current();
        if (!in_array($operation, ['install', 'upgrade'], true) || $pluginKey === '' || $this->planning !== null || $this->mutation !== null
            || !$context instanceof InstanceExecutionContext || $context->operation() !== 'console.plugin:' . $operation
            || $context->instance->actorKey !== 'console' || PHP_SAPI !== 'cli' || $this->app->config->get('peanut.environment') !== 'development'
            || !$this->app->isDebug() || !in_array($this->app->config->get('deployment.mode'), ['standalone', 'multi-tenant'], true)) {
            throw new RuntimeException('PLUGIN_SOURCE_CONTEXT_REQUIRED');
        }
        $admission = new SourceDevelopmentMaintenanceAdmission($root);
        $initial = $admission->capture($actor, false);
        $config = \guardedDatabaseConfig();
        $this->assertBackupReads(['pa_module_migration'], $config);
        [$connection, $pdo] = $this->connect($config);
        $identity = InstallationExecutionHost::migrationDatabaseIdentity($pdo);
        $recheck = function () use ($admission, $actor, $initial, $connection, $pdo, $context, $identity): void {
            if ($this->app->make(CurrentExecutionContext::class)->current() !== $context || Db::connect() !== $connection
                || $connection->getPdo() !== $pdo || $pdo->inTransaction() || $admission->capture($actor, false) !== $initial
                || InstallationExecutionHost::migrationDatabaseIdentity($pdo) !== $identity) { throw new RuntimeException('PLUGIN_SOURCE_RECEIPT_OBSERVATION_CHANGED'); }
        };
        return ScaffoldUpgradeRunner::sourceProductLock(dirname($root), function () use ($path, $pluginKey, $root, $initial, $pdo, $identity, $recheck): array {
            $recheck();
            if (realpath(dirname($path)) !== $this->receiptDirectory(false) || preg_match('/\Aresult-[a-f0-9]{32}\.json\z/D', basename($path)) !== 1) {
                throw new RuntimeException('PLUGIN_SOURCE_RECEIPT_ORIGIN_INVALID');
            }
            $record = SourceApplicationBackup::protectedPluginJson($path, $root); $receiptSha = hash_file('sha256', $path);
            $digest = $record['document_sha256'] ?? null; $unsigned = $record; unset($unsigned['document_sha256']);
            $binding = $record['binding'] ?? null;
            if (($record['protocol'] ?? null) !== 'peanut.source-plugin-result.v1' || ($record['status'] ?? null) !== 'completed'
                || ($record['plugin_key'] ?? null) !== $pluginKey || !in_array($record['operation'] ?? null, ['install', 'upgrade'], true)
                || !is_string($digest) || !hash_equals($digest, self::digest($unsigned)) || !is_array($binding)
                || ($binding['source']['root'] ?? null) !== dirname($root) || ($binding['resource_id'] ?? null) !== $initial['resource_id']
                || ($binding['endpoint_id'] ?? null) !== $initial['endpoint_id'] || self::digest([$binding['database'] ?? null]) !== self::digest([$identity])
                || !is_int($record['observed_at'] ?? null) || !is_string($record['request_id'] ?? null) || $record['request_id'] === ''
                || ($record['verification_level'] ?? null) !== 'native_same_process_lifecycle_completion'
                || ($record['cross_process_preservation'] ?? null) !== 'not_verified' || ($record['qualification'] ?? null) !== 'not_run'
                || ($record['product_database'] ?? null) !== 'not_verified') { throw new RuntimeException('PLUGIN_SOURCE_ORIGINAL_RECEIPT_REQUIRED'); }
            $head = $binding['source']['head'] ?? '';
            if (!is_string($head) || preg_match('/\A[a-f0-9]{40}\z/D', $head) !== 1
                || \leaseGit(['-C', dirname($root), 'rev-parse', $head . '^{tree}']) !== ($binding['source']['tree'] ?? null)) {
                throw new RuntimeException('PLUGIN_SOURCE_ORIGINAL_SOURCE_UNKNOWN');
            }
            $this->assertReceiptSource($head, __FILE__, (string) ($record['writer_sha256'] ?? ''));
            $this->assertReceiptSource($head, $root . '/app/platform/services/plugin/PluginLifecycleService.php', (string) ($record['executor_sha256'] ?? ''));
            $native = $record['native_plan'] ?? null; $ready = $record['execution_plan'] ?? null; $baseline = $record['baseline'] ?? null; $scope = $record['backup_scope'] ?? null;
            if (!is_array($native) || !is_array($ready) || !is_array($baseline) || !is_array($scope)
                || ($native['key'] ?? $native['plugin_key'] ?? null) !== $pluginKey || ($ready['key'] ?? $ready['plugin_key'] ?? null) !== $pluginKey
                || ($ready['may_apply'] ?? null) !== true || self::digest($native) !== ($record['native_plan_sha256'] ?? null)) {
                throw new RuntimeException('PLUGIN_SOURCE_ORIGINAL_PLAN_CHANGED');
            }
            $effects = SourceApplicationBackup::pluginEffects($native);
            $expected = ['pending' => $effects['pending'], 'selected' => $effects['selected'], 'operation' => $record['operation'],
                'plugin_key' => $pluginKey, 'native_plan_sha256' => self::digest($native), 'lifecycle_effects' => $effects];
            if (($effects['closure_complete'] ?? null) !== true || self::digest(SourceApplicationBackup::pluginEffects($ready)) !== self::digest($effects)
                || self::digest($baseline) !== self::digest($expected) || ($scope['affected_tables'] ?? null) !== $effects['tables']) {
                throw new RuntimeException('PLUGIN_SOURCE_ORIGINAL_PLAN_CHANGED');
            }
            $proof = SourceApplicationBackup::pluginRecovery($binding, $baseline, $scope,
                $record['evidence']['maintenance']['path'] ?? '', $record['evidence']['recovery']['path'] ?? '', $root, $record['observed_at']);
            $nativeAdmission = $record['admission'] ?? null;
            if (!is_array($nativeAdmission)) { throw new RuntimeException('PLUGIN_SOURCE_ORIGINAL_ADMISSION_CHANGED'); }
            $nativeDigest = $nativeAdmission['admission_digest'] ?? null; unset($nativeAdmission['admission_digest']);
            if (!is_string($nativeDigest) || !hash_equals($nativeDigest, self::digest($nativeAdmission))
                || ($nativeAdmission['scope'] ?? null) !== 'source-plugin' || ($nativeAdmission['protocol'] ?? null) !== 'peanut.source-plugin-admission.v1'
                || ($nativeAdmission['request_id'] ?? null) !== $record['request_id'] || ($nativeAdmission['operation'] ?? null) !== 'console.plugin:' . $record['operation']
                || ($nativeAdmission['plugin_key'] ?? null) !== $pluginKey || ($nativeAdmission['plan_sha256'] ?? null) !== self::digest($native)
                || ($nativeAdmission['execution_plan_sha256'] ?? null) !== self::digest($ready)
                || self::digest([$nativeAdmission['binding'] ?? null]) !== self::digest([$binding])
                || self::digest([$nativeAdmission['evidence'] ?? null]) !== self::digest([$proof])
                || self::digest([$record['evidence'] ?? null]) !== self::digest([$proof])) { throw new RuntimeException('PLUGIN_SOURCE_ORIGINAL_ADMISSION_CHANGED'); }
            $stages = $record['ordered_stages'] ?? null; $ledger = $record['ledger'] ?? null;
            if (!is_array($stages) || !array_is_list($stages) || !is_array($ledger) || !array_is_list($ledger)) { throw new RuntimeException('PLUGIN_SOURCE_ORIGINAL_COMPLETION_CHANGED'); }
            $seen = array_column($stages, 'migration_key'); $wanted = $effects['pending']; sort($seen, SORT_STRING); sort($wanted, SORT_STRING);
            if ($seen !== $wanted) { throw new RuntimeException('PLUGIN_SOURCE_ORIGINAL_COMPLETION_CHANGED'); }
            $byKey = array_column($ledger, null, 'migration_key');
            if (count($byKey) !== count($ledger)) { throw new RuntimeException('PLUGIN_SOURCE_ORIGINAL_COMPLETION_CHANGED'); }
            foreach ($stages as $index => $stage) {
                $key = $stage['migration_key'] ?? ''; $sha = $effects['selected'][$key] ?? '';
                if (($stage['ordinal'] ?? null) !== $index + 1 || ($stage['module_key'] ?? null) !== explode(':', $key, 2)[0]
                    || ($stage['sql_sha256'] ?? null) !== $sha || !is_array($stage['safe_after'] ?? null)) { throw new RuntimeException('PLUGIN_SOURCE_ORIGINAL_COMPLETION_CHANGED'); }
                $this->assertSafeAfter($key, $sha, (string) ($stage['contract'] ?? ''), $stage['safe_after']);
                $this->assertReceiptSource($head, (string) (new \ReflectionClass($stage['contract']))->getFileName(), (string) ($stage['contract_sha256'] ?? ''));
                $row = $byKey[$key] ?? [];
                if (($row['status'] ?? null) !== 'applied' || ($row['checksum'] ?? null) !== $sha || ($row['module_key'] ?? null) !== $stage['module_key']
                    || !in_array($stage['accepted_while'] ?? null, ['applying', 'applied'], true)
                    || ($row['resolution_kind'] ?? null) !== ($stage['accepted_while'] === 'applied' ? 'adopted' : 'executed')
                    || self::digest([$stage['applied_ledger'] ?? null]) !== self::digest([$row])
                    || !is_string($row['finished_at'] ?? null) || ($row['error_code'] ?? null) !== null) { throw new RuntimeException('PLUGIN_SOURCE_ORIGINAL_COMPLETION_CHANGED'); }
            }
            $modules = array_column($native['modules'], 'module_key');
            $recheck();
            $pdo->exec('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ'); $pdo->exec('SET TRANSACTION READ ONLY'); $pdo->beginTransaction();
            try {
                foreach ($ledger as $row) {
                    if (!is_string($row['module_key'] ?? null) || !in_array($row['module_key'], $modules, true) || !is_string($row['migration_key'] ?? null)) {
                        throw new RuntimeException('PLUGIN_SOURCE_ORIGINAL_LEDGER_CHANGED');
                    }
                    $query = $pdo->prepare('SELECT * FROM pa_module_migration WHERE module_key=? AND migration_key=?');
                    try { $query->execute([$row['module_key'], $row['migration_key']]); $rows = $query->fetchAll(PDO::FETCH_ASSOC); } finally { $query->closeCursor(); }
                    if (count($rows) !== 1 || self::digest(array_intersect_key($rows[0], $row)) !== self::digest($row)) {
                        throw new RuntimeException('PLUGIN_SOURCE_ORIGINAL_LEDGER_CHANGED');
                    }
                }
                $pdo->commit();
            } finally { if ($pdo->inTransaction()) { $pdo->rollBack(); } }
            $recheck();
            if (hash_file('sha256', $path) !== $receiptSha || SourceApplicationBackup::pluginRecovery($binding, $baseline, $scope,
                $record['evidence']['maintenance']['path'], $record['evidence']['recovery']['path'], $root, $record['observed_at']) !== $proof) {
                throw new RuntimeException('PLUGIN_SOURCE_ORIGINAL_EVIDENCE_CHANGED');
            }
            return ['protocol' => 'peanut.source-plugin-original-receipt-verification.v1', 'status' => 'verified', 'readonly' => true,
                'plugin_key' => $pluginKey, 'verification_level' => 'accepted_original_window_receipt', 'original_window_receipt_verified' => true,
                'original_private_algorithm_recomputed' => false, 'current_target_verified' => false,
                'receipt' => ['path' => $path, 'sha256' => $receiptSha], 'original_source' => $binding['source'],
                'original_request_id' => $record['request_id'], 'original_observed_at' => $record['observed_at'], 'stage_count' => count($stages),
                'later_ledger_rows' => 'outside_original_scope_not_compared', 'restore' => 'not_executed', 'qualification' => 'not_run', 'product_database' => 'not_verified'];
        });
    }

    /** No caller JSON can create this capability; it exists only inside the held native callback. */
    public function requireMutation(PDO $pdo, string $pluginKey): array
    {
        if ($this->mutation === null || $this->mutation['pdo'] !== $pdo || $this->mutation['plugin_key'] !== $pluginKey) {
            throw new RuntimeException('PLUGIN_SOURCE_MUTATION_WINDOW_REQUIRED');
        }
        ($this->mutation['check'])();
        $name = \app\common\value\runtime\RuntimeNamespace::fromConfiguration()->advisoryLockName(
            'pa:module-runtime:' . substr(hash('sha256', $pluginKey), 0, 40));
        $lock = $pdo->prepare('SELECT IS_USED_LOCK(?) = CONNECTION_ID()');
        try {
            $lock->execute([$name]);
            if ((int) $lock->fetchColumn() !== 1) { throw new RuntimeException('PLUGIN_SOURCE_PACKAGE_LOCK_REQUIRED'); }
        } finally { $lock->closeCursor(); }
        return $this->mutation['admission'];
    }

    public function migrationAdmission(PDO $pdo): ?array
    {
        return $this->mutation === null ? null : $this->requireMutation($pdo, $this->mutation['plugin_key']);
    }

    /** Read-plan visibility only; this value is never returned by migrationAdmission. */
    public function planningAdmission(PDO $pdo): ?array
    {
        if ($this->planning === null || $this->mutation !== null) { return null; }
        if ($this->planning['pdo'] !== $pdo) { throw new RuntimeException('PLUGIN_SOURCE_MASTER_MISMATCH'); }
        ($this->planning['recheck'])();
        return ['scope' => 'source-plugin-plan', 'may_apply' => false, 'execution_admitted' => false,
            'status' => 'planned', 'code' => 'MODULE_MIGRATION_ADMISSION_DEFERRED'];
    }

    /** The normal native declaration must cover every whole shared table before observation/dump. */
    private function assertBackupReads(array $tables, array $config): void
    {
        $registry = \projectResourceRegistry($unused);
        $database = \registeredDatabase($registry, $config['resource_id']);
        $schema = \mysqlSchemaLeaseResource($database, \registeredDatabaseEndpoint($database, $config['consumer']), $config);
        $resources = \activeLeaseResources(\requiredEnvironment('PEANUT_RESOURCE_LEASE_PROOF'));
        if (!isset($database['lease_policy']['shared_access']) && !isset($resources['mysql-access'])) { return; }
        foreach ($resources['mysql-access'] ?? [] as $raw) {
            $access = json_decode($raw, true, 64, JSON_THROW_ON_ERROR);
            if (($access['schema'] ?? null) !== $schema || ($access['complete'] ?? null) !== true) { continue; }
            if (in_array($access['operation'] ?? null, ['ddl', 'exclusive'], true) && ($access['tables'] ?? null) === []) { return; }
            $covered = [];
            foreach ($access['tables'] ?? [] as $entry) {
                if (($entry['rows'] ?? null) === '*' && in_array($entry['access'] ?? null, ['read', 'write'], true)) { $covered[] = $entry['table']; }
            }
            if (array_diff($tables, $covered) === []) { return; }
        }
        throw new RuntimeException('PLUGIN_SOURCE_BACKUP_COMPLETE_READ_SCOPE_REQUIRED');
    }

    private static function digest(array $value): string
    {
        $sort = static function (mixed $value) use (&$sort): mixed {
            if (!is_array($value)) { return $value; }
            if (!array_is_list($value)) { ksort($value, SORT_STRING); }
            foreach ($value as &$item) { $item = $sort($item); }
            return $value;
        };
        return hash('sha256', json_encode($sort($value), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    /**
     * A real pending stage inside the held native read-plan callback. The existing
     * Notification proof may call here inside its own READ ONLY snapshot; these
     * source/lease/PDO/lock observations do not open, commit or end that snapshot.
     * The enclosing plan still requires no transaction on entry and return.
     */
    public function pendingModuleProvenanceMigration(PDO $pdo): ?array
    {
        if ($this->planning === null) { return null; }
        if ($this->planning['pdo'] !== $pdo) { throw new RuntimeException('PLUGIN_SOURCE_MASTER_MISMATCH'); }
        ($this->planning['recheck'])();
        if ($this->planning['prerequisite'] !== null) {
            $row = $pdo->prepare('SELECT status FROM pa_schema_migration WHERE migration_id=?');
            try {
                $row->execute([$this->planning['prerequisite']['migration_id']]);
                if ($row->fetchColumn() !== false) { throw new RuntimeException('PLUGIN_SOURCE_APPLICATION_LEDGER_CHANGED'); }
            } finally { $row->closeCursor(); }
        }
        return $this->planning['prerequisite'];
    }
}
