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
    private ?array $identityRecovery = null;
    private array $identityRecoveryFiles = [];
    private string $sourceMetadataTool = '';
    private const IDENTITY_EXTERNAL_REFERENCES = [
        'pa_platform_role_permission' => ['permission_id' => ['pa_permission', 'id']],
        'pa_role_permission' => ['permission_id' => ['pa_permission', 'id']],
        'pa_tenant_member' => ['tenant_id' => ['pa_department', 'tenant_id'], 'primary_department_id' => ['pa_department', 'id']],
        'pa_setting_deployment_value' => ['definition_id' => ['pa_setting_definition', 'id']],
        'pa_setting_tenant_value' => ['definition_id' => ['pa_setting_definition', 'id']],
        'pa_setting_target_value' => ['definition_id' => ['pa_setting_definition', 'id']],
    ];
    private const CONTRACTS = [
        'official.identity' => \PeanutAdmin\Modules\Identity\Database\IdentitySchemaMigrationProof::class,
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
        if (!in_array($operation, ['install', 'upgrade'], true) || $pluginKey === '' || $this->planning !== null || $this->identityRecovery !== null
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
                                $recoveryPreflight = null;
                                if ($operation === 'install' && $pluginKey === 'official.identity'
                                    && $effects['pending'] === array_keys(\PeanutAdmin\Modules\Identity\Database\IdentitySchemaMigrationProof::recoveryStages())) {
                                    $recoveryPreflight = $this->identityRecoveryPreflight($pdo, $scope, $effects, $identity['database']);
                                    if (($expected['identity_recovery_preflight'] ?? null) !== $recoveryPreflight) {
                                        throw new RuntimeException('PLUGIN_SOURCE_IDENTITY_RECOVERY_PREFLIGHT_CHANGED');
                                    }
                                    $this->identityRecoveryBaseline($proof['backup_payload'], $scope);
                                }
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
                                $enteredLifecycle = false;
                                $lifecycleCompleted = false;
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
                                    $enteredLifecycle = true;
                                    $result = ($execution['mutate'])();
                                    $lifecycleCompleted = true;
                                    $this->requireMutation($pdo, $pluginKey);
                                    if ($pdo->inTransaction()) { throw new RuntimeException('PLUGIN_SOURCE_TRANSACTION_INCOMPLETE'); }
                                    if ($recoveryPreflight !== null && $this->identityExternalBindings($pdo, $scope, $effects, $identity['database']) !== $recoveryPreflight['external_bindings']) {
                                        throw new RuntimeException('PLUGIN_SOURCE_IDENTITY_EXTERNAL_BINDINGS_CHANGED');
                                    }
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
                                        'evidence' => $proof, 'result' => $result, 'ledger' => $ledger, 'identity_recovery_preflight' => $recoveryPreflight,
                                        'native_plan' => $native, 'execution_plan' => $ready, 'baseline' => $baseline, 'backup_scope' => $scope,
                                        'admission' => $this->mutation['admission'], 'ordered_stages' => $stages, 'observed_at' => $acceptedAt,
                                        'request_id' => $context->requestId(), 'writer_sha256' => $writerSha, 'executor_sha256' => $executorSha,
                                        'verification_level' => 'native_same_process_lifecycle_completion',
                                        'cross_process_preservation' => 'not_verified', 'product_database' => 'not_verified', 'qualification' => 'not_run'];
                                    $record['document_sha256'] = self::digest($record);
                                    $record['result_path'] = $this->saveReceipt($record);
                                    $this->requireMutation($pdo, $pluginKey);
                                    return $record;
                                } catch (\Throwable $failure) {
                                    if ($enteredLifecycle) {
                                        // Preserve what this real invocation actually observed. A catalog
                                        // failure need not have a failed migration; never manufacture one.
                                        $ledger = null;
                                        if (!$pdo->inTransaction()) {
                                            try { $recheck(); $ledger = $readLedger(); }
                                            catch (\Throwable) { /* Unknown connection/authority stays unknown. */ }
                                        }
                                        $record = ['protocol' => 'peanut.source-plugin-failure.v1', 'status' => 'failed',
                                            'operation' => $operation, 'plugin_key' => $pluginKey, 'binding' => $binding,
                                            'native_plan' => $native, 'native_plan_sha256' => self::digest($native),
                                            'baseline' => $baseline, 'backup_scope' => $scope, 'evidence' => $proof,
                                            'identity_recovery_preflight' => $recoveryPreflight, 'external_bindings_after_failure' => 'not_verified',
                                            'admission' => $this->mutation['admission'], 'old_ledger' => $oldLedger,
                                            'ledger' => $ledger, 'ledger_observation' => $ledger === null ? 'unknown' : 'observed_after_failure',
                                            'ordered_stages' => $this->mutation['stages'], 'request_id' => $context->requestId(),
                                            'failure_phase' => $lifecycleCompleted ? 'completion_validation_or_receipt' : 'native_lifecycle',
                                            'native_result' => $lifecycleCompleted ? $result : null,
                                            'failure_type' => $failure::class,
                                            'observed_at' => time(), 'writer_sha256' => hash_file('sha256', __FILE__),
                                            'executor_sha256' => hash_file('sha256', rtrim($this->app->getRootPath(), DIRECTORY_SEPARATOR)
                                                . '/app/platform/services/plugin/PluginLifecycleService.php'),
                                            'database_afterimage' => 'not_captured', 'restoration' => 'not_executed',
                                            'product_database' => 'not_verified', 'qualification' => 'not_run'];
                                        $record['document_sha256'] = self::digest($record);
                                        // Failure to durably preserve the record is itself a stop, not a
                                        // reason to retry the lifecycle or silently discard the error.
                                        try { $this->saveReceipt($record); }
                                        catch (\Throwable $receiptFailure) {
                                            throw new RuntimeException('PLUGIN_SOURCE_FAILURE_RECEIPT_WRITE_FAILED', 0, $receiptFailure);
                                        }
                                    }
                                    throw $failure;
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
                                if ($operation === 'install' && $pluginKey === 'official.identity'
                                    && $effects['pending'] === array_keys(\PeanutAdmin\Modules\Identity\Database\IdentitySchemaMigrationProof::recoveryStages())) {
                                    $report['identity_recovery_preflight'] = $this->identityRecoveryPreflight($pdo, $scope, $effects, $identity['database']);
                                }
                                $report['backup'] = SourceApplicationBackup::capture($binding, $baseline, self::digest($baseline), $scope,
                                    $tools[0], $output, $checkBackup, 'plugin-lifecycle');
                                $report['backup_scope'] = $scope;
                                if (isset($report['identity_recovery_preflight'])) {
                                    $captured = SourceApplicationBackup::protectedPluginJson($report['backup']['path'], $this->app->getRootPath());
                                    $this->identityRecoveryBaseline($captured['backup_payload'], $scope);
                                    $report['identity_baseline_supported'] = true;
                                }
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

    /** Explicit source-only snapshot/reversal of one real Identity installation. */
    public function recoverIdentity(array $actor, string $input, bool $restoring, string $toolId, string $output,
        string $metadataTool, string $maintenance, callable $packageLock, bool $preview = false): array
    {
        $root = rtrim($this->app->getRootPath(), DIRECTORY_SEPARATOR);
        $context = $this->app->make(CurrentExecutionContext::class)->current();
        if (!$context instanceof InstanceExecutionContext || $context->operation() !== 'console.plugin:install'
            || $context->instance->actorKey !== 'console' || PHP_SAPI !== 'cli'
            || $this->app->config->get('peanut.environment') !== 'development' || !$this->app->isDebug()
            || !in_array($this->app->config->get('deployment.mode'), ['standalone', 'multi-tenant'], true)
            || $this->planning !== null || $this->mutation !== null || $this->identityRecovery !== null
            || $toolId === '' || $metadataTool === '' || $output === '' || (!$restoring && $preview) || ($restoring ? $maintenance === '' : $maintenance !== '')) {
            throw new RuntimeException('PLUGIN_SOURCE_IDENTITY_RECOVERY_CONTEXT_REQUIRED');
        }
        $admission = new SourceDevelopmentMaintenanceAdmission($root);
        $initial = $admission->capture($actor, true); // Before lifecycle/provider/PDO initialization.
        $config = \guardedDatabaseConfig(); [$connection, $pdo] = $this->connect($config);
        $identity = InstallationExecutionHost::migrationDatabaseIdentity($pdo); $binding = $initial + ['database' => $identity];
        $registry = \projectResourceRegistry($registrySha); $resources = \activeLeaseResources(\requiredEnvironment('PEANUT_RESOURCE_LEASE_PROOF'));
        $tools = array_values(array_filter($registry['resources']['tooling'] ?? [], static fn(array $tool): bool => ($tool['stable_resource_id'] ?? null) === $toolId));
        if (count($tools) !== 1 || $registrySha !== $binding['registry_sha256'] || !in_array($toolId, $resources['tooling-resource-id'] ?? [], true)
            || !in_array($output, $resources['output-dir'] ?? [], true)) { throw new RuntimeException('PLUGIN_SOURCE_BACKUP_NATIVE_TOOL_OUTPUT_REQUIRED'); }
        $files = \applicationMigrationFiles($root . '/database'); $versions = \applicationReleaseVersions($root);
        $runner = new ApplicationMigrationRunner($config, $pdo); $target = \applicationMigrationTargetVersion($root, $versions);
        return ScaffoldUpgradeRunner::sourceProductLock(dirname($root), function () use ($runner, $files, $target, $versions, $pdo, $connection,
            $admission, $actor, $initial, $identity, $binding, $context, $config, $tools, $input, $restoring, $metadataTool, $output, $maintenance, $packageLock, $root, $preview): array {
            $result = null;
            $runner->run($files, $target, $versions['release_sequence_version'], true, null,
                function (PDO $actual, array $selection) use ($pdo, $connection, $admission, $actor, $initial, $identity, $binding, $context,
                    $config, $tools, $input, $restoring, $metadataTool, $output, $maintenance, $packageLock, $root, $preview, &$result): void {
                    if ($actual !== $pdo || $selection['pending'] !== [] || $pdo->inTransaction()) { throw new RuntimeException('PLUGIN_SOURCE_IDENTITY_APPLICATION_PREREQUISITES_CHANGED'); }
                    $guard = function () use ($pdo, $connection, $admission, $actor, $initial, $identity, $context, $config, $root): void {
                        if ($this->app->make(CurrentExecutionContext::class)->current() !== $context || Db::connect() !== $connection
                            || $connection->getPdo() !== $pdo || $admission->capture($actor, true) !== $initial
                            || InstallationExecutionHost::migrationDatabaseIdentity($pdo) !== $identity || $pdo->inTransaction()) {
                            throw new RuntimeException('PLUGIN_SOURCE_IDENTITY_RECOVERY_BINDING_CHANGED');
                        }
                        ScaffoldUpgradeRunner::sourceProductLock(dirname($root), static fn() => null);
                        $query = $pdo->prepare('SELECT IS_USED_LOCK(?) = CONNECTION_ID()');
                        try {
                            $query->execute(['peanut_migrate_' . substr(hash('sha256', $config['database']), 0, 48)]);
                            if ((int) $query->fetchColumn() !== 1) { throw new RuntimeException('PLUGIN_SOURCE_SCHEMA_LOCK_REQUIRED'); }
                        } finally { $query->closeCursor(); }
                    };
                    $guard(); $this->identityRecoveryFiles = []; $this->identityRecovery = ['pdo' => $pdo, 'guard' => $guard, 'write' => $restoring && !$preview];
                    try {
                        $result = $packageLock($pdo, function () use ($pdo, $binding, $tools, $input, $restoring, $metadataTool, $output, $maintenance, $guard, $root, $preview): array {
                            $this->requireIdentityRecovery($pdo, 'official.identity');
                            $capsule = $restoring ? $this->identityNativeBackup($input, 'peanut.source-identity-operation-snapshot.v1') : null;
                            $receiptPath = $restoring ? ($capsule['identity_operation']['operation_receipt']['path'] ?? '') : $input;
                            $origin = $this->identityOperationOrigin($receiptPath, $binding); $effects = $origin['effects']; $original = $origin['original'];
                            if ($restoring && $origin['record']['ledger'] === null) {
                                throw new RuntimeException('PLUGIN_SOURCE_IDENTITY_ORIGINAL_LEDGER_UNKNOWN');
                            }
                            $historicalEnd = $origin['maintenance']['expires_at'];
                            $eligible = $origin['record']['observed_at'] < $historicalEnd && time() < $historicalEnd;
                            if ($restoring && (($capsule['identity_operation']['recovery_eligible'] ?? null) !== true
                                || ($capsule['identity_operation']['historical_window_end'] ?? null) !== $historicalEnd
                                || ($capsule['capture_started_at'] ?? 0) < $origin['record']['observed_at']
                                || ($capsule['observed_at'] ?? PHP_INT_MAX) >= $historicalEnd)) {
                                throw new RuntimeException('PLUGIN_SOURCE_IDENTITY_HISTORICAL_WINDOW_GAP');
                            }
                            if ($restoring && (($capsule['identity_operation']['operation_receipt'] ?? null) !== $origin['reference']
                                || ($capsule['identity_operation']['original_backup'] ?? null) !== $origin['original_reference']
                                || ($capsule['binding']['database'] ?? null) !== $binding['database'])) {
                                throw new RuntimeException('PLUGIN_SOURCE_IDENTITY_CAPSULE_CHANGED');
                            }
                            $scope = SourceApplicationBackup::pluginScope($pdo, $effects, $binding, $metadataTool, $guard);
                            $external = $this->identityExternalBindings($pdo, $scope, $effects, $binding['database']['database']);
                            $originalExternal = $origin['record']['identity_recovery_preflight']['external_bindings'] ?? null;
                            $preserved = is_array($originalExternal) && $external === $originalExternal;
                            $eligible = $eligible && $preserved;
                            if ($restoring && !$preserved) { throw new RuntimeException('PLUGIN_SOURCE_IDENTITY_EXTERNAL_BINDINGS_CHANGED'); }
                            $state = $this->identityOperationState($pdo); $stateSha = self::digest($state);
                            $recordedLedger = $origin['record']['ledger'];
                            if (is_array($recordedLedger)) { usort($recordedLedger, static fn(array $a, array $b): int => strcmp($a['migration_key'], $b['migration_key'])); }
                            if ($recordedLedger !== null && $state['identity_ledger'] !== $recordedLedger) {
                                throw new RuntimeException('PLUGIN_SOURCE_IDENTITY_OPERATION_STATE_CHANGED');
                            }
                            $image = ['recovery_profile' => 'identity-native-install-v1', 'operation_status' => $origin['record']['status'],
                                'recovery_eligible' => $eligible, 'historical_window_end' => $historicalEnd,
                                'operation_receipt' => $origin['reference'], 'original_backup' => $origin['original_reference'],
                                'pending' => $effects['pending'], 'selected' => $effects['selected'], 'state_sha256' => $stateSha,
                                'external_bindings' => $external, 'original_external_bindings_preserved' => $preserved];
                            $check = function () use ($pdo, $effects, $binding, $metadataTool, $guard, $scope, $stateSha, $eligible, $historicalEnd, $restoring, $external): void {
                                $this->requireIdentityRecovery($pdo, 'official.identity'); $guard();
                                $this->identityRecoveryFilesGuard();
                                if (!$restoring && $eligible && time() >= $historicalEnd) { throw new RuntimeException('PLUGIN_SOURCE_IDENTITY_HISTORICAL_WINDOW_GAP'); }
                                if (SourceApplicationBackup::pluginScope($pdo, $effects, $binding, $metadataTool, $guard) !== $scope
                                    || self::digest($this->identityOperationState($pdo)) !== $stateSha
                                    || $this->identityExternalBindings($pdo, $scope, $effects, $binding['database']['database']) !== $external) {
                                    throw new RuntimeException('PLUGIN_SOURCE_IDENTITY_SNAPSHOT_CHANGED');
                                }
                            };
                            if (!$restoring) {
                                $capture = SourceApplicationBackup::capture($binding, $image, self::digest($image), $scope, $tools[0], $output, $check, 'identity-recovery-snapshot');
                                return ['protocol' => 'peanut.source-identity-operation-capture.v1', 'status' => 'captured', 'backup' => $capture,
                                    'original_operation_status' => $origin['record']['status'], 'restoration' => 'not_executed',
                                    'qualification' => 'not_run', 'product_database' => 'not_verified'];
                            }
                            if (self::digest($capsule['scope']) !== self::digest($scope)
                                || ($capsule['identity_operation']['state_sha256'] ?? null) !== $stateSha
                                || ($capsule['identity_operation']['external_bindings'] ?? null) !== $external) {
                                throw new RuntimeException('PLUGIN_SOURCE_IDENTITY_CAPSULE_CURRENT_STATE_CHANGED');
                            }
                            $outside = static fn(array $value): array => array_values(array_filter($value['foreign_keys'],
                                static fn(array $fk): bool => in_array($fk['REFERENCED_TABLE_NAME'], $effects['tables'], true)
                                    && !in_array($fk['TABLE_NAME'], $effects['tables'], true)));
                            if (self::digest($outside($scope)) !== self::digest($outside($original['scope']))) {
                                throw new RuntimeException('PLUGIN_SOURCE_IDENTITY_EXTERNAL_DEFINITIONS_CHANGED');
                            }
                            $capsuleRef = ['path' => $input, 'sha256' => $this->identityRecoveryFileSha($input)];
                            $window = $this->identityRecoveryWindow($maintenance, $binding, $capsuleRef, $scope);
                            $windowGuard = function () use ($pdo, $guard, $maintenance, $binding, $capsuleRef, $scope, $window, $input): void {
                                $this->requireIdentityRecovery($pdo, 'official.identity'); $guard();
                                $this->identityRecoveryFilesGuard();
                                if ($this->identityRecoveryFileSha($input) !== $capsuleRef['sha256']
                                    || $this->identityRecoveryWindow($maintenance, $binding, $capsuleRef, $scope) !== $window) {
                                    throw new RuntimeException('PLUGIN_SOURCE_IDENTITY_RECOVERY_WINDOW_CHANGED');
                                }
                            };
                            $partialCreate = null;
                            foreach (\PeanutAdmin\Modules\Identity\Database\IdentitySchemaMigrationProof::recoveryStages() as $key => [, $table, $kind]) {
                                if ($kind !== 'create' || !in_array($table, $scope['tables'], true)) { continue; }
                                if (in_array($table, $original['scope']['tables'], true)) {
                                    throw new RuntimeException('PLUGIN_SOURCE_IDENTITY_CREATED_TABLE_OWNERSHIP_UNPROVEN');
                                }
                                if (!isset($origin['stages'][$key])) {
                                    if ($partialCreate !== null || $table !== 'pa_data_permission_policy') {
                                        throw new RuntimeException('PLUGIN_SOURCE_IDENTITY_CREATED_TABLE_OWNERSHIP_UNPROVEN');
                                    }
                                    $partialCreate = $this->identityFailedFirstCreate($pdo, $origin, $capsule, $scope, $maintenance, $tools[0]);
                                    continue;
                                }
                                \PeanutAdmin\Modules\Identity\Database\IdentitySchemaMigrationProof::recoveryCreatedTable($pdo, $key,
                                    $origin['stages'][$key]['safe_after']['metadata_sha256']);
                            }
                            if ($preview) {
                                $windowGuard(); $check();
                                return ['protocol' => 'peanut.source-identity-recovery-plan.v1', 'status' => 'planned', 'binding' => $binding,
                                    'original_operation' => $origin['reference'], 'capsule' => $capsuleRef, 'maintenance' => $window,
                                    'created_tables_to_remove' => array_values(array_diff($scope['tables'], $original['scope']['tables'])),
                                    'failed_first_create_proof' => $partialCreate, 'external_bindings' => $external,
                                    'execution_admitted' => false, 'fresh_before' => 'required_on_actual_restore',
                                    'restoration' => 'not_executed', 'qualification' => 'not_run', 'product_database' => 'not_verified'];
                            }
                            $parent = @lstat(dirname($output));
                            if (realpath(dirname($output)) !== dirname($output) || !is_array($parent)
                                || ($parent['mode'] & 0022) !== 0 || !in_array($parent['uid'], [0, posix_geteuid()], true)
                                || file_exists($output) || is_link($output) || !mkdir($output, 0700)) {
                                throw new RuntimeException('APPLICATION_SCHEMA_BACKUP_OUTPUT_UNSAFE');
                            }
                            $phase = 'fresh_before_capture'; $inverse = null;
                            try {
                                $beforeCheck = function () use ($check, $windowGuard): void { $windowGuard(); $check(); };
                                $fresh = SourceApplicationBackup::capture($binding, $image, self::digest($image), $scope, $tools[0], $output . '/before', $beforeCheck, 'identity-recovery-snapshot');
                                $before = $this->identityNativeBackup($fresh['path'], 'peanut.source-identity-operation-snapshot.v1');
                                if ($before['backup_payload']['sha256'] !== $capsule['backup_payload']['sha256']
                                    || $before['backup_payload']['bytes'] !== $capsule['backup_payload']['bytes']) {
                                    throw new RuntimeException('PLUGIN_SOURCE_IDENTITY_FRESH_CAPSULE_CHANGED');
                                }
                                $windowGuard(); $phase = 'native_inverse';
                                $inverse = SourceApplicationBackup::restoreIdentityOperation($pdo, $binding, $tools[0], $origin['original_reference']['path'], $input, $output . '/inverse', $window['expires_at'], $windowGuard);
                                $phase = 'postimage_capture'; $windowGuard();
                                $postScope = SourceApplicationBackup::pluginScope($pdo, $effects, $binding, $metadataTool, $windowGuard);
                                $postExternal = $this->identityExternalBindings($pdo, $postScope, $effects, $binding['database']['database']);
                                if ($postScope['tables'] !== $original['scope']['tables'] || $postScope['absent_original_tables'] !== $original['scope']['absent_original_tables']
                                    || $postScope['objects'] !== $original['scope']['objects'] || $postScope['foreign_keys'] !== $original['scope']['foreign_keys']
                                    || $postExternal !== $external) { throw new RuntimeException('PLUGIN_SOURCE_IDENTITY_RESTORATION_POSTSTATE_CHANGED'); }
                                $postImage = $image + ['operation_capsule' => $capsuleRef, 'restored_state_sha256' => self::digest($this->identityOperationState($pdo))];
                                $postCheck = function () use ($pdo, $effects, $binding, $metadataTool, $windowGuard, $postScope, $postExternal): void {
                                    $windowGuard();
                                    if (SourceApplicationBackup::pluginScope($pdo, $effects, $binding, $metadataTool, $windowGuard) !== $postScope
                                        || $this->identityExternalBindings($pdo, $postScope, $effects, $binding['database']['database']) !== $postExternal) {
                                        throw new RuntimeException('PLUGIN_SOURCE_IDENTITY_POSTIMAGE_CHANGED');
                                    }
                                };
                                $post = SourceApplicationBackup::capture($binding, $postImage, self::digest($postImage), $postScope, $tools[0], $output . '/after', $postCheck, 'identity-recovery-postimage');
                                $after = $this->identityNativeBackup($post['path'], 'peanut.source-identity-operation-postimage.v1');
                                if ($after['backup_payload']['sha256'] !== $original['backup_payload']['sha256']
                                    || $after['backup_payload']['bytes'] !== $original['backup_payload']['bytes']) {
                                    throw new RuntimeException('PLUGIN_SOURCE_IDENTITY_RESTORATION_BYTES_CHANGED');
                                }
                                $windowGuard();
                                $record = ['protocol' => 'peanut.source-identity-operation-restoration.v1', 'status' => 'completed', 'binding' => $binding,
                                    'original_operation' => $origin['reference'], 'original_operation_status' => $origin['record']['status'],
                                    'original_backup' => $origin['original_reference'], 'capsule' => $capsuleRef, 'fresh_before' => $fresh, 'inverse' => $inverse,
                                    'postimage' => $post, 'external_bindings' => $postExternal, 'maintenance' => $window,
                                    'created_tables_removed' => array_values(array_diff($scope['tables'], $postScope['tables'])),
                                    'original_absent_tables' => $original['scope']['absent_original_tables'],
                                    'original_present_bytes_verified' => true, 'original_absence_verified' => true, 'failed_first_create_proof' => $partialCreate, 'observed_at' => time(),
                                    'writer_sha256' => hash_file('sha256', __FILE__), 'product_database' => 'not_verified', 'qualification' => 'not_run'];
                                $record['document_sha256'] = self::digest($record); $record['result_path'] = $this->saveReceipt($record);
                                return $record;
                            } catch (\Throwable $failure) {
                                $record = ['protocol' => 'peanut.source-identity-restoration-failure.v1', 'status' => 'failed', 'binding' => $binding,
                                    'capsule' => $capsuleRef, 'original_backup' => $origin['original_reference'], 'phase' => $phase, 'inverse' => $inverse,
                                    'failed_first_create_proof' => $partialCreate,
                                    'failure_type' => $failure::class, 'observed_at' => time(), 'replay' => 'forbidden_until_partial_state_reconciled',
                                    'product_database' => 'not_verified', 'qualification' => 'not_run'];
                                $record['document_sha256'] = self::digest($record); $this->saveReceipt($record); throw $failure;
                            }
                        });
                    } finally { $this->identityRecovery = null; $this->identityRecoveryFiles = []; }
                });
            if (!is_array($result)) { throw new RuntimeException('PLUGIN_SOURCE_IDENTITY_RECOVERY_INCOMPLETE'); }
            return $result;
        });
    }

    /** A separate recovery proof for a real first CREATE whose post-observer failed, never an old safe_after. */
    private function identityFailedFirstCreate(PDO $pdo, array $origin, array $capsule, array $scope, string $maintenance, array $tool): array
    {
        $this->requireIdentityRecovery($pdo, 'official.identity');
        $root = rtrim($this->app->getRootPath(), DIRECTORY_SEPARATOR); $record = $origin['record'];
        $table = 'pa_data_permission_policy';
        $stages = \PeanutAdmin\Modules\Identity\Database\IdentitySchemaMigrationProof::recoveryStages();
        $key = array_key_first($stages); $ledger = $record['ledger'];
        $present = $origin['original']['scope']['tables']; $present[] = $table; sort($present, SORT_STRING);
        if ($record['status'] !== 'failed' || $record['old_ledger'] !== [] || $record['ordered_stages'] !== []
            || !is_array($ledger) || count($ledger) !== 1 || ($ledger[0]['migration_key'] ?? null) !== $key
            || ($ledger[0]['module_key'] ?? null) !== 'official.identity' || ($ledger[0]['checksum'] ?? null) !== $stages[$key][0]
            || ($ledger[0]['status'] ?? null) !== 'failed' || ($ledger[0]['resolution_kind'] ?? null) !== null
            || $scope['tables'] !== $present || $capsule['scope']['tables'] !== $present
            || ($origin['original']['scope']['objects'][$table] ?? null) !== ['exists' => false]
            || !in_array($scope['objects'][$table]['auto_increment'], [null, '1'], true)) {
            throw new RuntimeException('PLUGIN_SOURCE_IDENTITY_FIRST_CREATE_RECOVERY_REQUIRED');
        }
        $window = $this->identityRecoveryJson($maintenance, $root); $reference = $window['failed_first_create_metadata'] ?? [];
        $path = $reference['path'] ?? '';
        if (!is_string($path) || preg_match('~\A' . preg_quote($root, '~')
            . '/private/installation/source-development/effect-metadata/([a-f0-9]{32})/request\.json\z~D', $path, $match) !== 1) {
            throw new RuntimeException('PLUGIN_SOURCE_IDENTITY_FIRST_CREATE_METADATA_REQUIRED');
        }
        $request = $this->identityRecoveryJson($path, $root); $directory = dirname($path);
        $selected = $origin['effects']['tables']; sort($selected, SORT_STRING);
        if ($this->identityRecoveryFileSha($path) !== ($reference['sha256'] ?? null)
            || ($request['protocol'] ?? null) !== 'peanut.source-development-effect-request.v1'
            || ($request['nonce'] ?? null) !== $match[1] || ($request['tables'] ?? null) !== $selected
            || ($request['present'] ?? null) !== $present || !is_int($request['observed_at'] ?? null)
            || self::digest($request['binding'] ?? []) !== self::digest($record['binding'])
            || ($request['tool_id'] ?? null) !== $tool['stable_resource_id'] || ($request['tool_sha256'] ?? null) !== self::digest($tool)
            || ($request['adapter_sha256'] ?? null) !== $origin['original']['adapter_sha256']) {
            throw new RuntimeException('PLUGIN_SOURCE_IDENTITY_FIRST_CREATE_METADATA_CHANGED');
        }
        $producer = $this->identityHistoricalProgram($record['binding']['source']['head'],
            'server/app/common/services/upgrade/SourceApplicationBackup.php', $request['adapter_sha256']);
        $ordering = str_contains($producer, 'DDL_BEFORE_STATISTICS_') ? 'ddl-before-statistics' : 'statistics-before-ddl';
        $output = $directory . '/metadata.txt'; $terminalPath = $output . '.process.json';
        $terminal = $this->identityRecoveryJson($terminalPath, $root);
        $this->identityRecoveryBytes($output, 8388608); $stderr = $this->identityRecoveryBytes($directory . '/metadata.stderr', 16384);
        $parsed = SourceApplicationBackup::historicalEffectMetadata($output, $request, $tool, $root, $ordering);
        if (($terminal['phase'] ?? null) !== 'effect-metadata' || ($terminal['rc'] ?? null) !== 0
            || ($terminal['local_process_terminal'] ?? null) !== true || ($terminal['local_deadline_exceeded'] ?? null) !== false
            || ($terminal['local_output_limit_exceeded'] ?? null) !== false || ($terminal['remote_success_terminal_confirmed'] ?? null) !== true
            || ($terminal['stdout'] ?? null) !== $parsed['stdout'] || ($terminal['stderr_sha256'] ?? null) !== hash('sha256', $stderr)
            || $stderr !== '' || $parsed['client']['mysql_sha256'] !== $origin['original']['native_client']['mysql_sha256']) {
            throw new RuntimeException('PLUGIN_SOURCE_IDENTITY_FIRST_CREATE_PROCESS_CHANGED');
        }
        $started = $this->identityLedgerTime($ledger[0]['started_at']); $finished = $this->identityLedgerTime($ledger[0]['finished_at']);
        $completed = lstat($terminalPath)['mtime'];
        if ($origin['original']['observed_at'] > $started || $started > $request['observed_at'] || $request['observed_at'] > $completed
            || $completed > $finished || $finished > $record['observed_at']
            || $started < $origin['maintenance']['observed_at'] || $record['observed_at'] >= $origin['maintenance']['expires_at']) {
            throw new RuntimeException('PLUGIN_SOURCE_IDENTITY_FIRST_CREATE_TIME_CHANGED');
        }
        $observed = $parsed['metadata']['objects'][$table] ?? null;
        if (!is_array($observed) || !in_array($observed['auto_increment'], [null, '1'], true)
            || $observed['create_sql_sha256'] !== $scope['objects'][$table]['create_sql_sha256']
            || $observed['create_sql_sha256'] !== $capsule['scope']['objects'][$table]['create_sql_sha256']
            || self::digest($parsed['metadata']['foreign_keys']) !== self::digest($scope['foreign_keys'])
            || $parsed['metadata']['triggers'] !== [] || $scope['triggers'] !== []) {
            throw new RuntimeException('PLUGIN_SOURCE_IDENTITY_FIRST_CREATE_SHAPE_CHANGED');
        }
        $query = $pdo->query('SELECT EXISTS(SELECT 1 FROM pa_data_permission_policy)');
        try { if ((int) $query->fetchColumn() !== 0) { throw new RuntimeException('PLUGIN_SOURCE_IDENTITY_FIRST_CREATE_NOT_EMPTY'); } }
        finally { $query->closeCursor(); }
        $read = function (array $backup) use ($root): array {
            $payload = $backup['backup_payload']; $bytes = $this->identityRecoveryBytes($payload['path'], null);
            if (hash('sha256', $bytes) !== $payload['sha256'] || strlen($bytes) !== $payload['bytes']) {
                throw new RuntimeException('PLUGIN_SOURCE_IDENTITY_FIRST_CREATE_IMAGE_CHANGED');
            }
            return SourceApplicationDump::read($bytes, $backup['scope']['tables']);
        };
        $old = $read($origin['original']); $now = $read($capsule);
        foreach ($old['tables'] as $name => $block) {
            if (!in_array($name, ['pa_module_installation', 'pa_module_migration', 'pa_plugin_installation', 'pa_plugin_module'], true)
                && $block['bytes'] !== $now['tables'][$name]['bytes']) {
                throw new RuntimeException('PLUGIN_SOURCE_IDENTITY_FIRST_CREATE_ORIGINAL_ROWS_CHANGED');
            }
        }
        if ($now['tables'][$table]['insert_rows'] !== 0) { throw new RuntimeException('PLUGIN_SOURCE_IDENTITY_FIRST_CREATE_NOT_EMPTY'); }
        SourceApplicationDump::restoreIdentity($old, $now); // Existing strict original-row/other-package and new-state boundaries.
        $this->identityRecoveryFilesGuard();
        return ['protocol' => 'peanut.source-identity-first-create-recovery-proof.v1', 'migration_key' => $key,
            'original_operation' => $origin['reference'], 'metadata_request' => $reference, 'metadata_stdout' => $parsed['stdout'],
            'metadata_terminal' => ['path' => $terminalPath, 'sha256' => $this->identityRecoveryFileSha($terminalPath)],
            'original_ordering' => $ordering, 'historical_evidence_qualification' => $parsed['qualification'],
            'process_completion_evidence' => 'protected original terminal filesystem mtime bounded by request/failed ledger/receipt',
            'started_at' => $started, 'request_at' => $request['observed_at'], 'terminal_mtime' => $completed, 'finished_at' => $finished,
            'table' => $table, 'create_sql_sha256' => $observed['create_sql_sha256'],
            'original_observer_allocator' => $observed['auto_increment'], 'current_allocator' => $scope['objects'][$table]['auto_increment'],
            'empty' => true, 'original_business_catalog_blocks_unchanged' => true, 'old_safe_after' => 'not_created', 'qualification' => 'not_run'];
    }

    private function identityLedgerTime(mixed $value): int
    {
        if (!is_string($value)) { throw new RuntimeException('PLUGIN_SOURCE_IDENTITY_FIRST_CREATE_TIME_CHANGED'); }
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d H:i:s.v', $value, new \DateTimeZone('UTC'));
        if (!$date instanceof \DateTimeImmutable || $date->format('Y-m-d H:i:s.v') !== $value) {
            throw new RuntimeException('PLUGIN_SOURCE_IDENTITY_FIRST_CREATE_TIME_CHANGED');
        }
        return $date->getTimestamp();
    }

    private function identityRecoveryPreflight(PDO $pdo, array $scope, array $effects, string $database): array
    {
        $proof = (new \ReflectionClass(\PeanutAdmin\Modules\Identity\Database\IdentitySchemaMigrationProof::class))->getFileName();
        return ['profile' => 'identity-native-install-v1', 'external_bindings' => $this->identityExternalBindings($pdo, $scope, $effects, $database),
            'inverse_producer_sha256' => hash_file('sha256', __DIR__ . '/SourceApplicationDump.php'),
            'native_executor_sha256' => hash_file('sha256', __DIR__ . '/SourceApplicationBackup.php'), 'owner_proof_sha256' => hash_file('sha256', $proof),
            'restoration' => 'not_run', 'qualification' => 'not_run'];
    }

    private function identityRecoveryBaseline(array $payload, array $scope): void
    {
        $bytes = SourceApplicationBackup::protectedPluginBytes($payload['path'] ?? '', $this->app->getRootPath(), null);
        if (!is_string($bytes) || strlen($bytes) !== ($payload['bytes'] ?? null) || hash('sha256', $bytes) !== ($payload['sha256'] ?? null)) {
            throw new RuntimeException('PLUGIN_SOURCE_IDENTITY_ORIGINAL_BACKUP_CHANGED');
        }
        $original = SourceApplicationDump::read($bytes, $scope['tables']);
        SourceApplicationDump::restoreIdentity($original, $original); // Parse original rows only; no SQL is executed.
    }

    private function identityOperationState(PDO $pdo): array
    {
        $all = $pdo->query('SELECT * FROM pa_module_migration ORDER BY id');
        try { $ledger = $all->fetchAll(PDO::FETCH_ASSOC); } finally { $all->closeCursor(); }
        $state = ['ledger' => $ledger, 'identity_ledger' => array_values(array_filter($ledger, static fn(array $row): bool => $row['module_key'] === 'official.identity'))];
        usort($state['identity_ledger'], static fn(array $a, array $b): int => strcmp($a['migration_key'], $b['migration_key']));
        foreach (['pa_plugin_installation' => 'plugin_key', 'pa_module_installation' => 'module_key', 'pa_plugin_module' => 'module_key'] as $table => $column) {
            $query = $pdo->prepare('SELECT * FROM `' . $table . '` WHERE `' . $column . '`=? ORDER BY id');
            try { $query->execute(['official.identity']); $state[$table] = $query->fetchAll(PDO::FETCH_ASSOC); } finally { $query->closeCursor(); }
        }
        return $state;
    }

    /** Only PK/FK bindings of affected parents are read outside the write set; no business values. */
    private function identityExternalBindings(PDO $pdo, array $scope, array $effects, string $database): array
    {
        $this->assertEffectBoundary($scope, $effects, $database); $children = [];
        foreach ($scope['derived_update_columns'] as $table => $columns) {
            if ($table !== 'pa_resource_operation_condition' || $columns !== ['selector_resource_key_norm']) {
                throw new RuntimeException('PLUGIN_SOURCE_IDENTITY_DERIVED_WRITES_UNPROVEN');
            }
        }
        foreach ($scope['foreign_keys'] as $fk) {
            if ($fk['TABLE_SCHEMA'] !== $database || $fk['REFERENCED_TABLE_SCHEMA'] !== $database
                || in_array($fk['UPDATE_RULE'], ['CASCADE', 'SET NULL'], true) || in_array($fk['DELETE_RULE'], ['CASCADE', 'SET NULL'], true)) {
                throw new RuntimeException('PLUGIN_SOURCE_IDENTITY_FOREIGN_EFFECT_UNPROVEN');
            }
            if (in_array($fk['REFERENCED_TABLE_NAME'], $effects['tables'], true) && !in_array($fk['TABLE_NAME'], $effects['tables'], true)) {
                if ((self::IDENTITY_EXTERNAL_REFERENCES[$fk['TABLE_NAME']][$fk['COLUMN_NAME']] ?? null)
                    !== [$fk['REFERENCED_TABLE_NAME'], $fk['REFERENCED_COLUMN_NAME']]) {
                    throw new RuntimeException('PLUGIN_SOURCE_IDENTITY_UNKNOWN_EXTERNAL_DEPENDENCY');
                }
                $children[$fk['TABLE_NAME']][] = $fk['COLUMN_NAME'];
            }
        }
        ksort($children, SORT_STRING); $result = [];
        $this->assertBackupReads(array_keys($children), \guardedDatabaseConfig());
        $pdo->exec('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ'); $pdo->exec('START TRANSACTION READ ONLY');
        try {
            foreach ($children as $table => $foreign) {
                if (preg_match('/\Apa_[a-z0-9_]+\z/D', $table) !== 1) { throw new RuntimeException('PLUGIN_SOURCE_IDENTITY_EXTERNAL_SCOPE_INVALID'); }
                $query = $pdo->prepare("SELECT COLUMN_NAME FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND CONSTRAINT_NAME='PRIMARY' ORDER BY ORDINAL_POSITION");
                try { $query->execute([$table]); $primary = $query->fetchAll(PDO::FETCH_COLUMN); } finally { $query->closeCursor(); }
                if ($primary === []) { throw new RuntimeException('PLUGIN_SOURCE_IDENTITY_EXTERNAL_PRIMARY_KEY_REQUIRED'); }
                sort($foreign, SORT_STRING); $columns = array_values(array_unique([...$primary, ...$foreign]));
                foreach ($columns as $name) { if (preg_match('/\A[a-z][a-z0-9_]*\z/D', $name) !== 1) { throw new RuntimeException('PLUGIN_SOURCE_IDENTITY_EXTERNAL_SCOPE_INVALID'); } }
                $query = $pdo->query('SELECT `' . implode('`,`', $columns) . '` FROM `' . $table . '` ORDER BY `' . implode('`,`', $primary) . '`');
                $hash = hash_init('sha256'); $count = 0;
                try {
                    while (($row = $query->fetch(PDO::FETCH_NUM)) !== false) {
                        $bytes = serialize(array_map(static fn($value) => $value === null ? null : (string) $value, $row));
                        hash_update($hash, strlen($bytes) . ':' . $bytes); ++$count;
                    }
                } finally { $query->closeCursor(); }
                $result[$table] = ['columns' => $columns, 'rows' => $count, 'sha256' => hash_final($hash)];
            }
        } finally { if ($pdo->inTransaction()) { $pdo->rollBack(); } }
        return $result;
    }

    private function identityRecoveryBytes(string $path, ?int $maximum = 4194304): string
    {
        $bytes = SourceApplicationBackup::protectedPluginBytes($path, rtrim($this->app->getRootPath(), DIRECTORY_SEPARATOR), $maximum);
        $sha = hash('sha256', $bytes);
        if (isset($this->identityRecoveryFiles[$path]) && $this->identityRecoveryFiles[$path] !== $sha) {
            throw new RuntimeException('PLUGIN_SOURCE_IDENTITY_ORIGIN_CHANGED');
        }
        $this->identityRecoveryFiles[$path] = $sha;
        return $bytes;
    }

    private function identityRecoveryJson(string $path, string $root): array
    {
        $value = json_decode($this->identityRecoveryBytes($path), true, 64, JSON_THROW_ON_ERROR);
        if (!is_array($value) || array_is_list($value)) { throw new RuntimeException('PLUGIN_SOURCE_DOCUMENT_INVALID'); }
        return $value;
    }

    private function identityRecoveryFileSha(string $path): string
    {
        $this->identityRecoveryBytes($path, null);
        return $this->identityRecoveryFiles[$path];
    }

    private function identityRecoveryFilesGuard(): void
    {
        foreach ($this->identityRecoveryFiles as $path => $sha) {
            if (hash('sha256', SourceApplicationBackup::protectedPluginBytes($path, rtrim($this->app->getRootPath(), DIRECTORY_SEPARATOR), null)) !== $sha) {
                throw new RuntimeException('PLUGIN_SOURCE_IDENTITY_ORIGIN_CHANGED');
            }
        }
    }

    private function identityRecoveryWindow(string $path, array $binding, array $capsule, array $scope): array
    {
        $root = rtrim($this->app->getRootPath(), DIRECTORY_SEPARATOR); $window = $this->identityRecoveryJson($path, $root); $now = time();
        foreach (['source', 'database', 'authority', 'registry_sha256'] as $field) {
            if (self::digest([$window[$field] ?? null]) !== self::digest([$binding[$field]])) { throw new RuntimeException('PLUGIN_SOURCE_IDENTITY_RECOVERY_WINDOW_REQUIRED'); }
        }
        if (($window['schema_version'] ?? null) !== 1 || ($window['protocol'] ?? null) !== 'peanut.source-identity-maintenance.v1'
            || ($window['state'] ?? null) !== 'active' || ($window['operation'] ?? null) !== 'restore_identity_operation'
            || ($window['operation_capsule'] ?? null) !== $capsule || ($window['scope'] ?? null) !== $scope['affected_tables']
            || ($window['excluded_writers'] ?? null) !== ['http', 'workers', 'other-writers']
            || !is_int($window['observed_at'] ?? null) || !is_int($window['expires_at'] ?? null)
            || $window['observed_at'] < $binding['authority']['created_at'] || $window['observed_at'] > $now
            || $window['expires_at'] <= $now || $window['expires_at'] > $binding['authority']['expires_at']
            || !is_array($window['receipts'] ?? null) || $window['receipts'] === []) { throw new RuntimeException('PLUGIN_SOURCE_IDENTITY_RECOVERY_WINDOW_REQUIRED'); }
        foreach ($window['receipts'] as $receipt) {
            $this->identityRecoveryBytes($receipt['path'] ?? '');
            if ($this->identityRecoveryFileSha($receipt['path']) !== ($receipt['sha256'] ?? null)) { throw new RuntimeException('PLUGIN_SOURCE_IDENTITY_RECOVERY_WINDOW_CHANGED'); }
        }
        return ['path' => $path, 'sha256' => $this->identityRecoveryFileSha($path), 'expires_at' => $window['expires_at'], 'external_writer_exclusion' => 'ops_protocol_gate_required_not_attested'];
    }

    /** Historical producer bytes remain verifiable without running an old checkout. */
    private function identityHistoricalProgram(string $head, string $relative, string $sha): string
    {
        $root = dirname(rtrim($this->app->getRootPath(), DIRECTORY_SEPARATOR));
        if (preg_match('/\A[a-f0-9]{40}\z/D', $head) !== 1 || preg_match('/\A[a-f0-9]{64}\z/D', $sha) !== 1
            || !in_array($relative, ['server/app/common/services/upgrade/PluginSourceDevelopmentHost.php',
                'server/app/common/services/upgrade/SourceApplicationBackup.php', 'server/app/platform/services/plugin/PluginLifecycleService.php'], true)
            || \leaseGit(['-C', $root, 'merge-base', $head, 'HEAD']) !== $head) {
            throw new RuntimeException('PLUGIN_SOURCE_IDENTITY_HISTORICAL_SOURCE_REQUIRED');
        }
        $process = proc_open(['/usr/bin/git', '-C', $root, 'show', $head . ':' . $relative], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        if (!is_resource($process)) { throw new RuntimeException('PLUGIN_SOURCE_IDENTITY_HISTORICAL_SOURCE_REQUIRED'); }
        $bytes = stream_get_contents($pipes[1], 2097153); $error = stream_get_contents($pipes[2], 8192);
        fclose($pipes[1]); fclose($pipes[2]); $rc = proc_close($process);
        if ($rc !== 0 || !is_string($bytes) || strlen($bytes) > 2097152 || hash('sha256', $bytes) !== $sha) {
            throw new RuntimeException('PLUGIN_SOURCE_IDENTITY_HISTORICAL_SOURCE_CHANGED');
        }
        return $bytes;
    }

    /** Read a genuine fixed native capture, including its complete terminal/output chain. */
    private function identityNativeBackup(string $path, string $protocol): array
    {
        $root = rtrim($this->app->getRootPath(), DIRECTORY_SEPARATOR);
        $record = $this->identityRecoveryJson($path, $root); $directory = dirname($path);
        $payload = $record['backup_payload'] ?? []; $restore = $record['restore_instructions'] ?? [];
        if (basename($path) !== 'backup.json' || ($record['protocol'] ?? null) !== $protocol || ($record['status'] ?? null) !== 'captured'
            || ($payload['path'] ?? null) !== $directory . '/database.sql' || ($restore['path'] ?? null) !== $directory . '/RESTORE.json'
            || !is_int($payload['bytes'] ?? null) || $payload['bytes'] < 1
            || array_column($record['receipts'] ?? [], 'phase') !== ['probe-before', 'dump', 'probe-after']) {
            throw new RuntimeException('PLUGIN_SOURCE_IDENTITY_NATIVE_CAPTURE_REQUIRED');
        }
        $this->identityRecoveryBytes($payload['path'], null);
        $instructions = $this->identityRecoveryJson($restore['path'], $root);
        if ($this->identityRecoveryFileSha($payload['path']) !== ($payload['sha256'] ?? null) || strlen($this->identityRecoveryBytes($payload['path'], null)) !== $payload['bytes']
            || $this->identityRecoveryFileSha($restore['path']) !== ($restore['sha256'] ?? null)
            || ($instructions['payload'] ?? null) !== $payload || ($instructions['executed'] ?? null) !== false
            || ($instructions['database'] ?? null) !== ($record['binding']['database'] ?? null)) {
            throw new RuntimeException('PLUGIN_SOURCE_IDENTITY_NATIVE_CAPTURE_CHANGED');
        }
        foreach ($record['receipts'] as $receipt) {
            $out = $directory . '/' . match ($receipt['phase']) { 'probe-before' => 'probe-before.txt', 'dump' => 'database.sql', 'probe-after' => 'probe-after.txt' };
            if ($receipt['path'] !== $out . '.process.json') { throw new RuntimeException('PLUGIN_SOURCE_IDENTITY_NATIVE_CAPTURE_REQUIRED'); }
            $terminal = $this->identityRecoveryJson($receipt['path'], $root);
            $this->identityRecoveryBytes($out, $receipt['phase'] === 'dump' ? null : 16384);
            if ($this->identityRecoveryFileSha($receipt['path']) !== $receipt['sha256'] || ($terminal['rc'] ?? null) !== 0
                || ($terminal['local_process_terminal'] ?? null) !== true || ($terminal['local_deadline_exceeded'] ?? null) !== false
                || ($terminal['local_output_limit_exceeded'] ?? null) !== false || ($terminal['remote_success_terminal_confirmed'] ?? null) !== true
                || ($terminal['stdout'] ?? null) !== ['path' => $out, 'sha256' => $this->identityRecoveryFileSha($out), 'bytes' => strlen($this->identityRecoveryBytes($out, null))]) {
                throw new RuntimeException('PLUGIN_SOURCE_IDENTITY_NATIVE_CAPTURE_CHANGED');
            }
        }
        $this->identityHistoricalProgram($record['binding']['source']['head'] ?? '', 'server/app/common/services/upgrade/SourceApplicationBackup.php', $record['adapter_sha256'] ?? '');
        return $record;
    }

    private function identityOperationOrigin(string $path, array $binding): array
    {
        $root = rtrim($this->app->getRootPath(), DIRECTORY_SEPARATOR); $directory = $this->receiptDirectory(false);
        if (preg_match('~\A' . preg_quote($directory, '~') . '/result-[a-f0-9]{32}\.json\z~D', $path) !== 1) {
            throw new RuntimeException('PLUGIN_SOURCE_IDENTITY_OPERATION_RECEIPT_REQUIRED');
        }
        $record = $this->identityRecoveryJson($path, $root); $document = $record; unset($document['document_sha256']);
        $status = $record['status'] ?? null;
        if (!in_array($status, ['completed', 'failed'], true)
            || ($record['protocol'] ?? null) !== ($status === 'completed' ? 'peanut.source-plugin-result.v1' : 'peanut.source-plugin-failure.v1')
            || ($record['document_sha256'] ?? null) !== self::digest($document)
            || ($record['operation'] ?? null) !== 'install' || ($record['plugin_key'] ?? null) !== 'official.identity'
            || ($record['binding']['source']['root'] ?? null) !== dirname($root)
            || ($record['binding']['database'] ?? null) !== $binding['database']
            || ($record['binding']['resource_id'] ?? null) !== $binding['resource_id'] || ($record['binding']['endpoint_id'] ?? null) !== $binding['endpoint_id']
            || ($record['binding']['installation_originals'] ?? null) !== $binding['installation_originals']
            || !is_int($record['observed_at'] ?? null) || $record['observed_at'] > time()
            || !is_string($record['request_id'] ?? null) || $record['request_id'] === '') {
            throw new RuntimeException('PLUGIN_SOURCE_IDENTITY_OPERATION_RECEIPT_CHANGED');
        }
        $head = $record['binding']['source']['head'] ?? '';
        $this->identityHistoricalProgram($head, 'server/app/common/services/upgrade/PluginSourceDevelopmentHost.php', $record['writer_sha256'] ?? '');
        $this->identityHistoricalProgram($head, 'server/app/platform/services/plugin/PluginLifecycleService.php', $record['executor_sha256'] ?? '');
        foreach (['plugins.lock', 'plugins/official.identity/plugin.json'] as $file) {
            if (\leaseGit(['-C', dirname($root), 'rev-parse', $head . ':' . $file]) !== \leaseGit(['-C', dirname($root), 'hash-object', '--no-filters', '--', dirname($root) . '/' . $file])) {
                throw new RuntimeException('PLUGIN_SOURCE_IDENTITY_PACKAGE_CHANGED');
            }
        }
        $native = $record['native_plan'] ?? []; $effects = SourceApplicationBackup::pluginEffects($native);
        $stages = \PeanutAdmin\Modules\Identity\Database\IdentitySchemaMigrationProof::recoveryStages();
        if (($record['native_plan_sha256'] ?? null) !== self::digest($native) || ($native['action'] ?? null) !== 'planned_install'
            || array_column($native['modules'] ?? [], 'module_key') !== ['official.identity']
            || ($effects['closure_complete'] ?? null) !== true || $effects['pending'] !== array_keys($stages)
            || $effects['selected'] !== array_map(static fn(array $stage): string => $stage[0], $stages)
            || self::digest($record['baseline']['lifecycle_effects'] ?? []) !== self::digest($effects)) {
            throw new RuntimeException('PLUGIN_SOURCE_IDENTITY_FIXED_OPERATION_REQUIRED');
        }
        $admission = $record['admission'] ?? []; $admissionSha = $admission['admission_digest'] ?? null; unset($admission['admission_digest']);
        if (($admission['schema_version'] ?? null) !== 1 || ($admission['protocol'] ?? null) !== 'peanut.source-plugin-admission.v1'
            || ($admission['scope'] ?? null) !== 'source-plugin' || ($admission['instance_root'] ?? null) !== $root
            || ($admission['plugin_key'] ?? null) !== 'official.identity' || ($admission['operation'] ?? null) !== 'console.plugin:install'
            || ($admission['request_id'] ?? null) !== $record['request_id'] || ($admission['database_identity'] ?? null) !== $binding['database']
            || self::digest($admission['binding'] ?? []) !== self::digest($record['binding'])
            || self::digest($admission['evidence'] ?? []) !== self::digest($record['evidence'])
            || ($admission['plan_sha256'] ?? null) !== $record['native_plan_sha256'] || $admissionSha !== self::digest($admission)
            || preg_match('/\A[a-f0-9]{64}\z/D', (string) ($admission['execution_plan_sha256'] ?? '')) !== 1) {
            throw new RuntimeException('PLUGIN_SOURCE_IDENTITY_ORIGINAL_ADMISSION_CHANGED');
        }
        foreach ($stages as $key => [$sha]) {
            $this->assertReceiptSource($head, $root . '/app/modules/official/identity/database/migrations/' . substr($key, strlen('official.identity:')) . '.sql', $sha);
        }
        $ordered = $record['ordered_stages'] ?? null;
        if (!is_array($ordered) || !array_is_list($ordered) || count($ordered) > 9 || !array_key_exists('ledger', $record)
            || ($record['ledger'] !== null && !is_array($record['ledger'])) || ($status === 'completed' && $record['ledger'] === null)) {
            throw new RuntimeException('PLUGIN_SOURCE_IDENTITY_STAGE_RECEIPT_CHANGED');
        }
        $seen = []; $proofFile = (new \ReflectionClass(\PeanutAdmin\Modules\Identity\Database\IdentitySchemaMigrationProof::class))->getFileName();
        foreach ($ordered as $index => $stage) {
            $key = $stage['migration_key'] ?? '';
            if (($stage['ordinal'] ?? null) !== $index + 1 || $key !== array_keys($stages)[$index]
                || ($stage['module_key'] ?? null) !== 'official.identity' || ($stage['sql_sha256'] ?? null) !== $stages[$key][0]
                || ($stage['contract'] ?? null) !== \PeanutAdmin\Modules\Identity\Database\IdentitySchemaMigrationProof::class
                || ($stage['accepted_while'] ?? null) !== 'applying') { throw new RuntimeException('PLUGIN_SOURCE_IDENTITY_STAGE_RECEIPT_CHANGED'); }
            $this->assertReceiptSource($head, $proofFile, $stage['contract_sha256'] ?? '');
            $this->assertSafeAfter($key, $stages[$key][0], $stage['contract'], $stage['safe_after'] ?? []);
            $seen[$key] = $stage;
        }
        if ($status === 'completed' && count($seen) !== 9) { throw new RuntimeException('PLUGIN_SOURCE_IDENTITY_STAGE_RECEIPT_CHANGED'); }
        $originalRef = $record['evidence']['backup_manifest'] ?? []; $originalPath = $originalRef['path'] ?? '';
        $original = $this->identityNativeBackup($originalPath, 'peanut.source-plugin-backup.v1');
        if ($this->identityRecoveryFileSha($originalPath) !== ($originalRef['sha256'] ?? null)
            || self::digest($original['binding']) !== self::digest($record['binding'])
            || self::digest($original['scope']) !== self::digest($record['backup_scope'] ?? [])
            || ($original['baseline_sha256'] ?? null) !== self::digest($record['baseline'])
            || ($original['backup_payload'] ?? null) !== ($record['evidence']['backup_payload'] ?? null)
            || ($original['restore_instructions']['sha256'] ?? null) !== ($record['evidence']['restore_contract_sha256'] ?? null)) {
            throw new RuntimeException('PLUGIN_SOURCE_IDENTITY_ORIGINAL_BACKUP_CHANGED');
        }
        $documents = [];
        foreach (['maintenance', 'recovery'] as $kind) {
            $ref = $record['evidence'][$kind] ?? []; $value = $this->identityRecoveryJson($ref['path'] ?? '', $root);
            if ($this->identityRecoveryFileSha($ref['path']) !== ($ref['sha256'] ?? null)
                || ($value['protocol'] ?? null) !== 'peanut.source-plugin-' . $kind . '.v1'
                || self::digest($value['source'] ?? []) !== self::digest($record['binding']['source'])
                || self::digest($value['authority'] ?? []) !== self::digest($record['binding']['authority'])
                || ($value['database'] ?? null) !== $binding['database'] || ($value['registry_sha256'] ?? null) !== $record['binding']['registry_sha256']
                || ($value['baseline_sha256'] ?? null) !== $original['baseline_sha256'] || ($value['pending'] ?? null) !== $effects['pending']
                || self::digest($value['selected'] ?? []) !== self::digest($effects['selected']) || ($value['scope'] ?? null) !== $original['scope']['tables']
                || !is_int($value['observed_at'] ?? null) || !is_int($value['expires_at'] ?? null)
                || $value['observed_at'] < $record['binding']['authority']['created_at'] || $value['observed_at'] > $value['expires_at']
                || $value['expires_at'] > $record['binding']['authority']['expires_at']
                || !is_array($value['receipts'] ?? null) || $value['receipts'] === []) {
                throw new RuntimeException('PLUGIN_SOURCE_IDENTITY_ORIGINAL_WINDOW_CHANGED');
            }
            foreach ($value['receipts'] ?? [] as $receipt) {
                $this->identityRecoveryBytes($receipt['path'] ?? '');
                if ($this->identityRecoveryFileSha($receipt['path']) !== ($receipt['sha256'] ?? null)) { throw new RuntimeException('PLUGIN_SOURCE_IDENTITY_ORIGINAL_WINDOW_CHANGED'); }
            }
            $documents[$kind] = $value;
        }
        if (($documents['maintenance']['state'] ?? null) !== 'active'
            || ($documents['maintenance']['excluded_writers'] ?? null) !== ['http', 'workers', 'other-writers']
            || !is_int($original['capture_started_at'] ?? null) || !is_int($original['observed_at'] ?? null)
            || $original['capture_started_at'] < $documents['maintenance']['observed_at'] || $original['capture_started_at'] > $original['observed_at']
            || $original['observed_at'] > $documents['maintenance']['expires_at'] || $record['observed_at'] < $original['observed_at']
            || ($status === 'completed' && $record['observed_at'] >= $documents['maintenance']['expires_at'])
            || ($documents['recovery']['backup_manifest'] ?? null) !== $originalRef
            || ($documents['recovery']['backup_payload'] ?? null) !== $original['backup_payload']) {
            throw new RuntimeException('PLUGIN_SOURCE_IDENTITY_ORIGINAL_WINDOW_CHANGED');
        }
        return ['record' => $record, 'reference' => ['path' => $path, 'sha256' => $this->identityRecoveryFileSha($path)],
            'maintenance' => $documents['maintenance'], 'original' => $original, 'original_reference' => $originalRef, 'effects' => $effects, 'stages' => $seen];
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
        if (!in_array($operation, ['install', 'upgrade'], true) || $pluginKey === '' || $this->planning !== null || $this->mutation !== null || $this->identityRecovery !== null
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

    /** This capability exists only in the real source/schema callback, never caller JSON. */
    public function requireIdentityRecovery(PDO $pdo, string $key, bool $packageLocked = true, bool $write = false): void
    {
        if ($key !== 'official.identity' || $this->identityRecovery === null || $this->identityRecovery['pdo'] !== $pdo
            || $this->mutation !== null || $this->planning !== null || $pdo->inTransaction()
            || ($write && ($this->identityRecovery['write'] ?? false) !== true)) {
            throw new RuntimeException('PLUGIN_SOURCE_IDENTITY_RECOVERY_WINDOW_REQUIRED');
        }
        ($this->identityRecovery['guard'])();
        if ($packageLocked) {
            $name = \app\common\value\runtime\RuntimeNamespace::fromConfiguration()->advisoryLockName(
                'pa:module-runtime:' . substr(hash('sha256', $key), 0, 40));
            $query = $pdo->prepare('SELECT IS_USED_LOCK(?) = CONNECTION_ID()');
            try {
                $query->execute([$name]);
                if ((int) $query->fetchColumn() !== 1) { throw new RuntimeException('PLUGIN_SOURCE_PACKAGE_LOCK_REQUIRED'); }
            } finally { $query->closeCursor(); }
        }
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
