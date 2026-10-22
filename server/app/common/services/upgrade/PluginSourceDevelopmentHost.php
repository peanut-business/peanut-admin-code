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
use app\platform\services\plugin\PluginLifecycleService;

/** Holds the native source Plugin locks; only a live, bound apply admits writes. */
final class PluginSourceDevelopmentHost
{
    private ?array $planning = null;
    private ?array $mutation = null;
    private ?array $identityRecovery = null;
    private ?array $failureForensic = null;
    private array $identityRecoveryFiles = [];
    private string $sourceMetadataTool = '';
    private string $notificationPredecessor = '';
    private ?array $notificationLineage = null;
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
        'official.collaboration' => \PeanutAdmin\Modules\Collaboration\Contract\CollaborationMigrationPreservation::class,
        'official.article' => \PeanutAdmin\Modules\Article\Contract\ArticleMigrationPreservation::class,
        'official.member' => \PeanutAdmin\Modules\Member\Contract\MemberSessionMigrationPreservation::class,
        'official.import-export' => \PeanutAdmin\Modules\ImportExport\Contract\ImportExportMigrationPreservation::class,
        'official.notification' => \PeanutAdmin\Modules\Notification\Contract\NotificationUpgradePreflight::class,
        'official.task' => \PeanutAdmin\Modules\Task\Job\Database\TaskSchemaMigrationProof::class,
        'peanut.workflow' => \PeanutAdmin\Modules\Workflow\Database\WorkflowSchemaMigrationProof::class,
        'peanut.artifact-revision' => \PeanutAdmin\Modules\ArtifactRevision\Database\ArtifactRevisionMigrationPreservation::class,
        'fixture.workflow-business' => \PeanutAdmin\Fixtures\WorkflowBusiness\Database\WorkflowBusinessMigrationPreservation::class,
    ];

    public function __construct(private readonly App $app) {}

    public function plan(string $operation, string $pluginKey, array $actor, callable $readPlan, string $toolId = '', string $output = '', ?array $execution = null, string $metadataTool = '', string $notificationPredecessor = ''): array
    {
        $run = fn(): array => $this->planHeld($operation, $pluginKey, $actor, $readPlan, $toolId, $output, $execution, $metadataTool, $notificationPredecessor);
        if (($actor['scope'] ?? 'source-development') !== 'source-application') { return $run(); }
        $server = rtrim($this->app->getRootPath(), DIRECTORY_SEPARATOR);
        $facts = (new SourceDevelopmentMaintenanceAdmission($server))->capture($actor, false);
        return \PeanutServerUpdatePlan::withSourceApplicationMaintenance($server, $facts['source'], $run);
    }

    private function planHeld(string $operation, string $pluginKey, array $actor, callable $readPlan, string $toolId = '', string $output = '', ?array $execution = null, string $metadataTool = '', string $notificationPredecessor = ''): array
    {
        $this->sourceMetadataTool = $metadataTool;
        if ($notificationPredecessor !== '' && ($operation !== 'upgrade' || $pluginKey !== 'official.notification')) {
            throw new RuntimeException('PLUGIN_SOURCE_NOTIFICATION_PREDECESSOR_INVALID');
        }
        $this->notificationPredecessor = $notificationPredecessor;
        $this->notificationLineage = null;
        $applying = $execution !== null;
        if ($applying && ($toolId !== '' || $output !== '' || !is_callable($execution['mutate'] ?? null))) { throw new RuntimeException('PLUGIN_SOURCE_ARGUMENTS_INVALID'); }
        if (($toolId === '') !== ($output === '')) { throw new RuntimeException('PLUGIN_SOURCE_BACKUP_ARGUMENTS_REQUIRED'); }
        $serverRoot = rtrim($this->app->getRootPath(), DIRECTORY_SEPARATOR);
        $context = $this->app->make(CurrentExecutionContext::class)->current();
        if (!in_array($operation, ['install', 'upgrade'], true) || $pluginKey === '' || $this->planning !== null || $this->identityRecovery !== null || $this->failureForensic !== null
            || !$context instanceof InstanceExecutionContext || $context->operation() !== 'console.plugin:' . $operation
            || $context->instance->actorKey !== 'console' || PHP_SAPI !== 'cli'
            || $this->app->config->get('peanut.environment') !== 'development' || !$this->app->isDebug()
            || !in_array($this->app->config->get('deployment.mode'), ['standalone', 'multi-tenant'], true)) {
            throw new RuntimeException('PLUGIN_SOURCE_CONTEXT_REQUIRED');
        }
        $admission = new SourceDevelopmentMaintenanceAdmission($serverRoot);
        // Observe the real locked plan before choosing DDL or catalog-only authority.
        $initial = $admission->capture($actor, false);
        $mutationEffects = null;
        $config = \guardedDatabaseConfig();
        [$connection, $pdo] = $this->connect($config);
        $identity = InstallationExecutionHost::migrationDatabaseIdentity($pdo);
        $files = \applicationMigrationFiles($serverRoot . '/database');
        $versions = \applicationReleaseVersions($serverRoot);
        $target = \applicationMigrationTargetVersion($serverRoot, $versions);
        $runner = new ApplicationMigrationRunner($config, $pdo);
        return ScaffoldUpgradeRunner::sourceProductLock(dirname($serverRoot), function () use ($runner, $files, $target, $versions,
            $pdo, $connection, $admission, $actor, $initial, $identity, $context, $operation, $pluginKey, $config, $readPlan, $toolId, $output, $execution, $applying, &$mutationEffects): array {
            $result = null;
            $runner->run($files, $target, $versions['release_sequence_version'], true, null,
                function (PDO $actual, array $selection) use ($pdo, $connection, $admission, $actor, $initial, $identity,
                    $context, $operation, $pluginKey, $config, $files, $readPlan, $toolId, $output, $execution, $applying, &$mutationEffects, &$result): void {
                    $recheck = function () use ($pdo, $connection, $admission, $actor, $initial, $identity, $context, $config, &$mutationEffects): void {
                        $current = $this->app->make(CurrentExecutionContext::class)->current();
                        if ($current !== $context || Db::connect() !== $connection || $connection->getPdo() !== $pdo
                            || $admission->capture($actor, $mutationEffects !== null && $mutationEffects['pending'] !== [],
                                $mutationEffects['tables'] ?? []) !== $initial || InstallationExecutionHost::migrationDatabaseIdentity($pdo) !== $identity) {
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
                            $pdo, $config, $recheck, $readPlan, $toolId, $output, $execution, $applying, $context, &$mutationEffects): array {
                            if ($pdo->inTransaction()) { throw new RuntimeException('PLUGIN_SOURCE_PLAN_TRANSACTION_INCOMPLETE'); }
                            $recheck();
                            if ($operation === 'install' && ($native['action'] ?? null) !== 'planned_install') {
                                throw new \app\platform\exception\plugin\PluginLifecycleException('PLUGIN_ALREADY_INSTALLED', 'Use the explicit upgrade plan for an installed Plugin.');
                            }
                            $binding = $initial + ['database' => $identity];
                            $effects = SourceApplicationBackup::pluginEffects($native);
                            $report = ['protocol' => 'peanut.source-development-plugin-plan.v1', 'maintenance_scope' => isset($initial['source']['application_source']) ? 'source-application' : 'source-development',
                                'operation' => $operation, 'plugin_key' => $pluginKey, 'binding' => $binding,
                                'application' => $selection, 'plugin' => $native, 'lifecycle_effects' => $effects, 'execution_admitted' => false,
                                'recovery' => 'not_attested', 'product_database' => 'not_verified'];
                            if ($this->sourceMetadataTool !== '') { $report['metadata_tool'] = $this->sourceMetadataTool; }
                            if ($this->notificationPredecessor !== '') {
                                $report['notification_predecessor'] = $this->notificationRepairEvidence($pdo)['lineage'];
                            }
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
                                // This is the native plan's complete write set, including shared catalogs.
                                // SQL/adoption stages keep the original DDL guard; no-SQL identity adoption
                                // needs whole-table catalog writes, never a read lease or row-limited claim.
                                $mutationEffects = $effects;
                                $recheck();
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
                                    'notification_receipt' => ['current' => $effects['notification_forward_receipt'], 'phase' => 'unchanged', 'transition' => null],
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
                                    SourceApplicationBackup::sourceApplicationBeforeApply($binding, $execution['maintenance'], $recheck);
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
                                    $this->assertAppendOnlyRepairs($effects, $ledger);
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
                                        'notification_receipt_write' => $this->mutation['notification_receipt'],
                                        'request_id' => $context->requestId(), 'writer_sha256' => $writerSha, 'executor_sha256' => $executorSha,
                                        'verification_level' => 'native_same_process_lifecycle_completion',
                                        'cross_process_preservation' => 'not_verified', 'product_database' => 'not_verified', 'qualification' => 'not_run'];
                                    $this->assertNotificationReceiptWrite($record, $effects);
                                    $record['document_sha256'] = self::digest($record);
                                    $record['result_path'] = $this->saveReceipt($record);
                                    $this->requireMutation($pdo, $pluginKey);
                                    SourceApplicationBackup::sourceApplicationStage($binding, $execution['maintenance'], 'completed', $record['result_path']);
                                    return $record;
                                } catch (\Throwable $failure) {
                                    if ($enteredLifecycle) {                                        SourceApplicationBackup::sourceApplicationStage($binding, $execution['maintenance'], 'failed');

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
                                            'notification_receipt_write' => $this->mutation['notification_receipt'],
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
                                $scope = SourceApplicationBackup::pluginScope($pdo, $effects, $binding, $this->sourceMetadataTool, $recheck);
                                // Only the complete native observer can prove an affected object absent.
                                // It checks present-table row coverage before any backup reads occur.
                                if (($scope['absent_original_tables'] ?? []) !== []
                                    && $scope['metadata_visibility'] !== 'complete native source metadata; ordinary PDO unchanged') {
                                    throw new RuntimeException('PLUGIN_SOURCE_BACKUP_ABSENT_METADATA_REQUIRED');
                                }
                                $this->assertBackupReads($scope['tables'], $config);
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
                                if (($binding['source']['kind'] ?? null) === 'generated-application') {
                                    $report['application_recovery'] = SourceApplicationBackup::sourceApplicationProtection($binding, $report, $tools[0], $output, $checkBackup);
                                }
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


    /** Explicit opening; no service start and no old recovery point survives it. */
    public function finishSourceApplication(array $actor): array
    {
        $server = rtrim($this->app->getRootPath(), DIRECTORY_SEPARATOR);
        $context = $this->app->make(CurrentExecutionContext::class)->current();
        if (($actor['scope'] ?? null) !== 'source-application' || !$context instanceof InstanceExecutionContext
            || $context->operation() !== 'console.schema:application' || $context->instance->actorKey !== 'console'
            || PHP_SAPI !== 'cli' || $this->app->config->get('peanut.environment') !== 'development' || !$this->app->isDebug()) {
            throw new RuntimeException('SOURCE_APPLICATION_OPEN_CONTEXT_REQUIRED');
        }
        $admission = new SourceDevelopmentMaintenanceAdmission($server);
        $initial = $admission->capture($actor, true);
        return ScaffoldUpgradeRunner::sourceProductLock(dirname($server),
            fn(): array => \PeanutServerUpdatePlan::withSourceApplicationMaintenance($server, $initial['source'],
                function () use ($server, $actor, $admission, $initial, $context): array {
                    $records = \PeanutServerUpdatePlan::sourceApplicationCompletionRecords($server, $initial['source']);
                    $contexts = $this->app->make(\app\common\execution\ExecutionContextStore::class);
                    foreach ($records as $reference) {
                        $record = SourceApplicationBackup::protectedPluginJson($reference['path'], $server);
                        if (($record['protocol'] ?? null) === 'peanut.source-development-schema-result.v1') {
                            $contexts->run(new InstanceExecutionContext('console.schema:application', $context->requestId()),
                                fn(): array => (new ApplicationSchemaHost($server))->sourceDevelopment('verify-receipt', $actor,
                                    '', $reference['path'], '', '', '', ''));
                        } elseif (($record['protocol'] ?? null) === 'peanut.source-plugin-result.v1'
                            && in_array($record['operation'] ?? null, ['install', 'upgrade'], true)) {
                            $contexts->run(new InstanceExecutionContext('console.plugin:' . $record['operation'], $context->requestId()),
                                fn(): array => $this->verifyReceipt($record['operation'], $record['plugin_key'], $actor, $reference['path']));
                        } else { throw new RuntimeException('SOURCE_APPLICATION_COMPLETION_RECEIPT_REQUIRED'); }
                    }
                    $config = \guardedDatabaseConfig(); [$connection, $pdo] = $this->connect($config);
                    $files = \applicationMigrationFiles($server . '/database'); $versions = \applicationReleaseVersions($server);
                    $runner = new ApplicationMigrationRunner($config, $pdo);
                    $result = null;
                    $runner->run($files, \applicationMigrationTargetVersion($server, $versions), $versions['release_sequence_version'], true, null,
                        function (PDO $actual, array $selection) use ($admission, $actor, $initial, $connection, $pdo, $server, &$result): void {
                            if ($actual !== $pdo || $connection->getPdo() !== $pdo || $selection['pending'] !== [] || $pdo->inTransaction()
                                || $admission->capture($actor, true) !== $initial) { throw new RuntimeException('SOURCE_APPLICATION_OPEN_SCHEMA_NOT_READY'); }
                            $lifecycle = $this->app->make(\app\platform\infrastructure\module\ThinkPhpModuleGovernanceProvider::class)->pluginLifecycle();
                            $installed = Db::name('plugin_installation')->where('status', '<>', 'uninstalled')->field('plugin_key,status')->order('plugin_key')->select()->toArray();
                            foreach ($installed as $row) {
                                if ($row['status'] !== 'active') { throw new RuntimeException('SOURCE_APPLICATION_OPEN_PLUGIN_NOT_READY'); }
                                $plan = $lifecycle->upgrade($row['plugin_key'], true);
                                if (($plan['may_apply'] ?? null) !== true || ($plan['identity_changed'] ?? null) !== false
                                    || ($plan['pending_migrations'] ?? null) !== []) { throw new RuntimeException('SOURCE_APPLICATION_OPEN_PLUGIN_NOT_READY'); }
                            }
                            if ($admission->capture($actor, true) !== $initial) { throw new RuntimeException('SOURCE_APPLICATION_OPEN_BINDING_CHANGED'); }
                            \PeanutServerUpdatePlan::finishSourceApplicationMaintenance($server, $initial['source']);
                            $result = ['protocol' => 'peanut.source-application-open.v1', 'status' => 'write-gate-cleared',
                                'source' => $initial['source'], 'installed_plugins' => count($installed),
                                'services' => 'not_started', 'business_qualification' => 'not_attested'];
                        });
                    return $result ?? throw new RuntimeException('SOURCE_APPLICATION_OPEN_INCOMPLETE');
                }));
    }

    /** Preserve only fixed native failure profiles; grants no lifecycle or restore admission. */
    public function snapshotFailure(string $pluginKey, array $actor, string $input, string $toolId, string $output, string $metadataTool, callable $packageLock): array
    {
        $profiles = [
            'official.file' => ['operation' => 'upgrade', 'module_root' => 'official/file',
                'owner' => 'Contract/FileMigrationPreservation.php',
                'selected' => [
                    'official.file:20260823-unify-storage-service' => 'f79c35b2fe8414dc172a81a45183d2a52eadb05833a704149780b36e400ca2d4',
                    'official.file:20260826-namespace-permission-keys' => 'fd7801e514efdde57945b9756e4303ea8685d02f07dfd86e7131b92ca28003f7',
                    'official.file:20260921020000_add_file_image_assets' => '826fa9d8fd841b6834bc5f23cabced30b7ca111de4bb1f629ccbdb8501200cd4',
                    'official.file:20261012030000_add_controlled_file_references' => '005edad54fc0cf06a4eeb6afa7c0e5f374a0bc352eaa088282b2f12932251591',
                    'official.file:20261012051000_add_immutable_document_snapshots' => '7b73c0c24f14f4c7f4fc1f75321e009b1a645d7df3f7d653dbb3d018e3b5bdbe',
                    'official.file:20261021060100_file_visibility_batches' => '3827e993c38855ce94ceaa2b7c0ae8c7082a9d231476e6c42358cd29f977edb7',
                    'official.file:20261021060200_file_visibility_object_state' => '93ab8b34a84124f7d6ed86e7f36a7ea76e6ac122cc64f58810387506bef65b6b',
                ], 'first' => 'official.file:20261021060100_file_visibility_batches',
                'failed' => 'official.file:20261021060200_file_visibility_object_state',
                'created' => ['pa_file_visibility_batch', 'pa_file_visibility_item'],
                'purpose' => 'file-failure-forensic'],
            'official.collaboration' => ['operation' => 'install', 'module_root' => 'official/collaboration',
                'owner' => 'Contract/CollaborationMigrationPreservation.php',
                'selected' => [
                    'official.collaboration:20261012120000_create_collaboration_runtime' => '0befec8d041ce121f624417fef9df343907b2686e57c8c0a8dde509d556813ac',
                    'official.collaboration:20261013120100_add_collaboration_business_barriers' => '6cd0d6dab2b08f26371ba97d6887f0e3ae7f7e0b1fcdb3fa3f8a59c54e2f850b',
                    'official.collaboration:20261013130000_add_instance_lifecycle' => 'e876c39e990491ba422ecb9067c8050ed98573b3ee457108de7d8955a084d6bd',
                    'official.collaboration:20261014120000_add_native_collaboration_grants' => '15a44a7aaa282b9addcc8c7b72cae027197e5f68058244e881ffc48695ac2f18',
                ], 'first' => 'official.collaboration:20261012120000_create_collaboration_runtime',
                'failed' => 'official.collaboration:20261012120000_create_collaboration_runtime',
                'created' => ['pa_collaboration_service_nonce', 'pa_collaboration_ticket', 'pa_collaboration_room',
                    'pa_collaboration_connection', 'pa_collaboration_admission', 'pa_collaboration_barrier'],
                'purpose' => 'collaboration-failure-forensic'],
            'official.notification' => ['operation' => 'upgrade', 'module_root' => 'official/notification',
                'owner' => 'Contract/NotificationUpgradePreflight.php',
                'selected' => [
                    'official.notification:20260826-namespace-permission-keys' => '8be087e7adacb8d34f2fa61111e683a7b15af4b62d93910d3a9655f1a605f606',
                    'official.notification:20260921-adopt-notification-delivery-schema' => 'eaef582cd762c803f3f7d213687f61d43fd3634e9f0b984471f11648e10979fb',
                    'official.notification:20261012-notification-producer-identity' => '7c01bc1bf1f309e879ff3014f89418157abbce45374a58064d1a6dc94fca07ce',
                ], 'first' => 'official.notification:20260921-adopt-notification-delivery-schema',
                'failed' => 'official.notification:20261012-notification-producer-identity',
                'created' => ['pa_notification_template', 'pa_notification_message', 'pa_notification_attachment', 'pa_notification_outbox',
                    'pa_sms_rate_bucket', 'pa_notification_event', 'pa_notification_task_actor'],
                'purpose' => 'notification-failure-forensic'],
            'official.task' => ['operation' => 'upgrade', 'module_root' => 'official/task',
                'owner' => 'Job/Database/TaskSchemaMigrationProof.php',
                'selected' => [
                    'official.task:20260826-namespace-permission-keys' => '81bff457523333ad56c1d30fb84094fbf3941902c37a56f27fe291c09145d131',
                    'official.task:20260921-adopt-task-job-schema' => 'f04762aeab007bea0b0c4ce277d8f7781a694b17891f1193f303d0481366f21a',
                    'official.task:20261015020000_add_negative_fact_job_profile' => '2217e8a0cfc44e2c4c6c1a76a0413ed2448c5695e32f2a31a6a2a3072f44dbd4',
                ], 'first' => 'official.task:20260921-adopt-task-job-schema',
                'failed' => 'official.task:20261015020000_add_negative_fact_job_profile', 'created' => [],
                'purpose' => 'task-failure-forensic'],
            'peanut.workflow' => ['operation' => 'install', 'module_root' => 'peanut/workflow',
                'owner' => 'Database/WorkflowSchemaMigrationProof.php',
                'selected' => [
                    'peanut.workflow:20260921-register-module-schema' => 'f0a21d63c83afcd2438fc327bf6ad97a59d82daa571bc4de51d74c32eb487ff7',
                    'peanut.workflow:20261012-workflow-managed-template' => 'fd02893a4b7a0ea60aa6c5434ff8b46f11e35bec94f10a76fc0b0f64609282a5',
                    'peanut.workflow:20261012-workflow-notification-facts' => 'ff26361f27773b9500fe6484729a26c0655d18f893cac8739bae9edfec8f6378',
                    'peanut.workflow:20261012-workflow-v2-execution' => 'f46f8b9a7a567584988e3520669daa1a8f893b69d837a1dedf223deb4f2a1b09',
                ], 'first' => 'peanut.workflow:20260921-register-module-schema',
                'failed' => 'peanut.workflow:20261012-workflow-managed-template',
                'created' => ['pa_workflow_definition', 'pa_workflow_definition_version', 'pa_workflow_event', 'pa_workflow_instance', 'pa_workflow_work_item'],
                'purpose' => 'workflow-failure-forensic'],
        ];
        $profile = $profiles[$pluginKey] ?? null;
        if ($profile === null) { throw new RuntimeException('PLUGIN_SOURCE_FAILURE_FORENSIC_PROFILE_REQUIRED'); }
        $root = rtrim($this->app->getRootPath(), DIRECTORY_SEPARATOR);
        $context = $this->app->make(CurrentExecutionContext::class)->current();
        if (!$context instanceof InstanceExecutionContext || $context->operation() !== 'console.plugin:' . $profile['operation']
            || $context->instance->actorKey !== 'console' || PHP_SAPI !== 'cli'
            || $this->app->config->get('peanut.environment') !== 'development' || !$this->app->isDebug()
            || !in_array($this->app->config->get('deployment.mode'), ['standalone', 'multi-tenant'], true)
            || $this->planning !== null || $this->mutation !== null || $this->identityRecovery !== null || $this->failureForensic !== null
            || $toolId === '' || $output === '' || $metadataTool === '') { throw new RuntimeException('PLUGIN_SOURCE_TASK_FORENSIC_CONTEXT_REQUIRED'); }
        $admission = new SourceDevelopmentMaintenanceAdmission($root);
        $initial = $admission->capture($actor, true);
        $originalBytes = SourceApplicationBackup::protectedPluginBytes($input, $root);
        $original = json_decode($originalBytes, true, 64, JSON_THROW_ON_ERROR); $originalSha = hash('sha256', $originalBytes);
        if (!is_array($original) || array_is_list($original)) { throw new RuntimeException('PLUGIN_SOURCE_TASK_FORENSIC_ORIGIN_REQUIRED'); }
        // A second, fixed Workflow failure shape: the managed repair and facts were
        // accepted, then V2 failed. Preserve this actual afterimage before changing
        // the package; neither failed original is converted into an applied row.
        $workflowV2 = $pluginKey === 'peanut.workflow'
            && isset($original['baseline']['selected']['peanut.workflow:20261012-workflow-managed-template-repair-20261021']);
        if ($workflowV2) {
            $profile['selected'] = [
                'peanut.workflow:20260921-register-module-schema' => 'f0a21d63c83afcd2438fc327bf6ad97a59d82daa571bc4de51d74c32eb487ff7',
                'peanut.workflow:20261012-workflow-managed-template' => 'fd02893a4b7a0ea60aa6c5434ff8b46f11e35bec94f10a76fc0b0f64609282a5',
                'peanut.workflow:20261012-workflow-managed-template-repair-20261021' => 'f3416b6c726a4a97ef70df967f14227c328c0ced156ac98656200f44712e5993',
                'peanut.workflow:20261012-workflow-notification-facts' => 'ff26361f27773b9500fe6484729a26c0655d18f893cac8739bae9edfec8f6378',
                'peanut.workflow:20261012-workflow-v2-execution' => 'f46f8b9a7a567584988e3520669daa1a8f893b69d837a1dedf223deb4f2a1b09',
            ];
            $profile['first'] = 'peanut.workflow:20261012-workflow-managed-template-repair-20261021';
            $profile['failed'] = 'peanut.workflow:20261012-workflow-v2-execution';
            $profile['created'] = ['pa_workflow_node_execution', 'pa_workflow_task_identity_link'];
            $profile['empty'] = ['pa_workflow_definition', 'pa_workflow_definition_version', 'pa_workflow_event',
                'pa_workflow_instance', 'pa_workflow_work_item', ...$profile['created']];
        }
        $digest = $original['document_sha256'] ?? null; $document = $original; unset($document['document_sha256']);
        $pending = array_keys($profile['selected']);
        $firstCreate = $pluginKey === 'official.collaboration';
        if (in_array($pluginKey, ['official.task', 'official.notification'], true)) { array_shift($pending); }
        if ($pluginKey === 'official.file') { $pending = array_slice($pending, -2); }
        if ($workflowV2) { $pending = array_slice($pending, 2); }
        if (($original['protocol'] ?? null) !== 'peanut.source-plugin-failure.v1' || ($original['status'] ?? null) !== 'failed'
            || ($original['operation'] ?? null) !== $profile['operation'] || ($original['plugin_key'] ?? null) !== $pluginKey
            || ($original['failure_phase'] ?? null) !== 'native_lifecycle' || !is_string($digest) || !hash_equals($digest, self::digest($document))
            || ($original['baseline']['pending'] ?? null) !== $pending
            || ($original['baseline']['selected'] ?? null) !== $profile['selected']
            || !is_array($original['ordered_stages'] ?? null)
            || count($original['ordered_stages']) !== ($firstCreate ? 0 : ($workflowV2 ? 2 : 1))
            || (!$firstCreate && (($original['ordered_stages'][0]['migration_key'] ?? null) !== $profile['first']
                || ($original['ordered_stages'][0]['accepted_while'] ?? null) !== 'applying'))) { throw new RuntimeException('PLUGIN_SOURCE_TASK_FORENSIC_ORIGIN_REQUIRED'); }
        if ($firstCreate && (($original['old_ledger'] ?? null) !== [] || count($original['ledger'] ?? []) !== 1)) {
            throw new RuntimeException('PLUGIN_SOURCE_COLLABORATION_FORENSIC_FRESH_ORIGIN_REQUIRED');
        }
        if ($workflowV2 && (array_column($original['ordered_stages'], 'migration_key') !== array_slice($pending, 0, 2)
            || array_column($original['ordered_stages'], 'accepted_while') !== ['applying', 'applying']
            || array_column(array_column($original['ordered_stages'], 'safe_after'), 'preservation_verified') !== [true, true]
            || array_column(array_column($original['ordered_stages'], 'safe_after'), 'created_empty_tables') !== [0, 0])) {
            throw new RuntimeException('PLUGIN_SOURCE_FAILURE_FORENSIC_ORIGIN_REQUIRED');
        }
        if ($pluginKey === 'peanut.workflow' && (count($original['ledger'] ?? []) !== ($workflowV2 ? 5 : 2)
            || ($original['ordered_stages'][0]['safe_after']['preservation_verified'] ?? null) !== true
            || (!$workflowV2 && ($original['ordered_stages'][0]['safe_after']['created_empty_tables'] ?? null) !== count($profile['created'])))) {
            throw new RuntimeException('PLUGIN_SOURCE_FAILURE_FORENSIC_ORIGIN_REQUIRED');
        }
        $native = $original['native_plan'] ?? [];
        if (($native['plugin_key'] ?? $native['key'] ?? null) !== $pluginKey || array_column($native['modules'] ?? [], 'module_key') !== [$pluginKey]
            || ($original['native_plan_sha256'] ?? null) !== self::digest($native)) { throw new RuntimeException('PLUGIN_SOURCE_TASK_FORENSIC_PLAN_CHANGED'); }
        $effects = SourceApplicationBackup::pluginEffects($native);
        if ($pluginKey === 'official.notification') {
            $this->assertNotificationReceiptWrite($original, $effects);
            if (count($original['ledger'] ?? []) !== 3
                || ($original['ordered_stages'][0]['safe_after']['preservation_verified'] ?? null) !== true
                || ($original['ordered_stages'][0]['safe_after']['created_empty_tables'] ?? null) !== 6
                || ($original['notification_receipt_write']['phase'] ?? null) !== 'transition_accepted') {
                throw new RuntimeException('PLUGIN_SOURCE_FAILURE_FORENSIC_ORIGIN_REQUIRED');
            }
        }
        $expectedBaseline = ['pending' => $effects['pending'], 'selected' => $effects['selected'], 'operation' => $profile['operation'],
            'plugin_key' => $pluginKey, 'native_plan_sha256' => self::digest($native), 'lifecycle_effects' => $effects];
        if (($effects['closure_complete'] ?? null) !== true || self::digest($expectedBaseline) !== self::digest($original['baseline'])) {
            throw new RuntimeException('PLUGIN_SOURCE_TASK_FORENSIC_EFFECTS_CHANGED');
        }
        $source = $original['binding']['source'] ?? [];
        if (($source['root'] ?? null) !== dirname($root)) { throw new RuntimeException('PLUGIN_SOURCE_TASK_FORENSIC_SOURCE_CHANGED'); }
        if ($firstCreate || $pluginKey === 'official.file') {
            $this->identityHistoricalProgram($source['head'] ?? '', 'server/app/common/services/upgrade/PluginSourceDevelopmentHost.php', $original['writer_sha256'] ?? '');
            $this->identityHistoricalProgram($source['head'] ?? '', 'server/app/platform/services/plugin/PluginLifecycleService.php', $original['executor_sha256'] ?? '');
        }
        $ownerFile = $root . '/app/modules/' . $profile['module_root'] . '/src/' . $profile['owner'];
        // A failed first observer has no accepted-after record. Verify the still-unchanged
        // owner against the original committed source, never invent an accepted stage.
        $this->assertReceiptSource($source['head'] ?? '', $ownerFile,
            $firstCreate ? hash_file('sha256', $ownerFile) : ($original['ordered_stages'][0]['contract_sha256'] ?? ''));
        // Do not move to a different package before preserving its actual failed state.
        $this->assertReceiptSource($source['head'], dirname($root) . '/plugins.lock', hash_file('sha256', dirname($root) . '/plugins.lock'));
        foreach ($original['baseline']['selected'] ?? [] as $key => $sha) {
            $this->assertReceiptSource($source['head'], $root . '/app/modules/' . $profile['module_root'] . '/database/migrations/' . substr($key, strlen($pluginKey . ':')) . '.sql', $sha);
        }
        $reference = $original['evidence']['backup_manifest'] ?? [];
        $backupBytes = SourceApplicationBackup::protectedPluginBytes($reference['path'] ?? '', $root);
        $backup = json_decode($backupBytes, true, 64, JSON_THROW_ON_ERROR);
        if (!is_array($backup) || array_is_list($backup) || hash('sha256', $backupBytes) !== ($reference['sha256'] ?? null)
            || ($backup['protocol'] ?? null) !== 'peanut.source-plugin-backup.v1'
            || self::digest($backup['binding'] ?? []) !== self::digest($original['binding'])
            || ($backup['baseline_sha256'] ?? null) !== self::digest($original['baseline'])
            || self::digest($backup['scope'] ?? []) !== self::digest($original['backup_scope'] ?? [])
            || ($backup['backup_payload'] ?? null) !== ($original['evidence']['backup_payload'] ?? null)) { throw new RuntimeException('PLUGIN_SOURCE_TASK_FORENSIC_BACKUP_CHANGED'); }
        if ($pluginKey === 'official.file') {
            // A retained failure may never turn a missing/corrupt prestate into an
            // accepted forensic chain. Reuse the complete native attachment reader.
            $this->identityRecoveryFiles = [];
            if (self::digest($this->identityNativeBackup($reference['path'], 'peanut.source-plugin-backup.v1')) !== self::digest($backup)) {
                throw new RuntimeException('PLUGIN_SOURCE_FILE_FORENSIC_BACKUP_CHANGED');
            }
        }
        $config = \guardedDatabaseConfig(); [$connection, $pdo] = $this->connect($config);
        $identity = InstallationExecutionHost::migrationDatabaseIdentity($pdo); $binding = $initial + ['database' => $identity];
        if (($original['binding']['database'] ?? null) !== $identity || ($original['binding']['resource_id'] ?? null) !== $binding['resource_id']
            || ($original['binding']['endpoint_id'] ?? null) !== $binding['endpoint_id']) { throw new RuntimeException('PLUGIN_SOURCE_TASK_FORENSIC_DATABASE_CHANGED'); }
        $registry = \projectResourceRegistry($registrySha); $resources = \activeLeaseResources(\requiredEnvironment('PEANUT_RESOURCE_LEASE_PROOF'));
        $tools = array_values(array_filter($registry['resources']['tooling'] ?? [], static fn(array $row): bool => ($row['stable_resource_id'] ?? null) === $toolId));
        if (count($tools) !== 1 || $registrySha !== $binding['registry_sha256'] || !in_array($toolId, $resources['tooling-resource-id'] ?? [], true)
            || !in_array($output, $resources['output-dir'] ?? [], true)) { throw new RuntimeException('PLUGIN_SOURCE_BACKUP_NATIVE_TOOL_OUTPUT_REQUIRED'); }
        $files = \applicationMigrationFiles($root . '/database'); $versions = \applicationReleaseVersions($root);
        $result = null;
        ScaffoldUpgradeRunner::sourceProductLock(dirname($root), function () use ($root, $pdo, $connection, $actor, $admission, $initial, $identity,
            $binding, $context, $config, $files, $versions, $original, $input, $originalSha, $backup, $toolId, $tools, $output, $metadataTool, $packageLock, $profile, $pluginKey, &$result): void {
            (new ApplicationMigrationRunner($config, $pdo))->run($files, \applicationMigrationTargetVersion($root, $versions), $versions['release_sequence_version'], true, null,
                function (PDO $actual, array $selection) use ($root, $pdo, $connection, $actor, $admission, $initial, $identity, $binding, $context, $config,
                    $original, $input, $originalSha, $backup, $toolId, $tools, $output, $metadataTool, $packageLock, $profile, $pluginKey, &$result): void {
                    if ($actual !== $pdo || $selection['pending'] !== []) { throw new RuntimeException('PLUGIN_SOURCE_TASK_FORENSIC_APPLICATION_PENDING'); }
                    $guard = function () use ($root, $pdo, $connection, $actor, $admission, $initial, $identity, $context, $config, $input, $originalSha, $original, $pluginKey): void {
                        if ($pluginKey === 'official.file') { $this->identityRecoveryFilesGuard(); }
                        if ($this->app->make(CurrentExecutionContext::class)->current() !== $context || Db::connect() !== $connection || $connection->getPdo() !== $pdo
                            || $pdo->inTransaction() || $admission->capture($actor, true) !== $initial
                            || InstallationExecutionHost::migrationDatabaseIdentity($pdo) !== $identity
                            || hash('sha256', SourceApplicationBackup::protectedPluginBytes($input, $root)) !== $originalSha
                            || hash('sha256', SourceApplicationBackup::protectedPluginBytes($original['evidence']['backup_manifest']['path'], $root)) !== $original['evidence']['backup_manifest']['sha256']) {
                            throw new RuntimeException('PLUGIN_SOURCE_TASK_FORENSIC_BINDING_CHANGED');
                        }
                        ScaffoldUpgradeRunner::sourceProductLock(dirname($root), static fn() => null);
                        $q = $pdo->prepare('SELECT IS_USED_LOCK(?) = CONNECTION_ID()');
                        try { $q->execute(['peanut_migrate_' . substr(hash('sha256', $config['database']), 0, 48)]);
                            if ((int) $q->fetchColumn() !== 1) { throw new RuntimeException('PLUGIN_SOURCE_SCHEMA_LOCK_REQUIRED'); }
                        } finally { $q->closeCursor(); }
                    };
                    $guard(); $this->failureForensic = ['pdo' => $pdo, 'guard' => $guard, 'plugin_key' => $pluginKey];
                    try {
                        $result = $packageLock($pdo, function () use ($pdo, $guard, $original, $input, $originalSha, $backup, $binding, $toolId, $tools, $output, $metadataTool, $profile, $pluginKey): array {
                            $check = function () use ($pdo, $guard, $original, $profile, $pluginKey): void {
                                $guard(); $this->requireFailureForensic($pdo, $pluginKey);
                                $q = $pdo->prepare('SELECT * FROM pa_module_migration WHERE module_key=? ORDER BY migration_key');
                                try { $q->execute([$pluginKey]); $rows = $q->fetchAll(PDO::FETCH_ASSOC); } finally { $q->closeCursor(); }
                                $expected = $original['ledger']; usort($expected, static fn(array $a, array $b): int => strcmp($a['migration_key'], $b['migration_key']));
                                if (self::digest($rows) !== self::digest($expected)) { throw new RuntimeException('PLUGIN_SOURCE_TASK_FORENSIC_LEDGER_CHANGED'); }
                                if ($pluginKey === 'official.collaboration' && (count($rows) !== 1
                                    || $rows[0]['migration_key'] !== $profile['failed'] || $rows[0]['module_key'] !== $pluginKey
                                    || $rows[0]['module_version'] !== '1.0.1' || $rows[0]['checksum'] !== $profile['selected'][$profile['failed']]
                                    || $rows[0]['status'] !== 'failed' || $rows[0]['resolution_kind'] !== null || $rows[0]['provenance_json'] !== null
                                    || $rows[0]['error_code'] !== 'MODULE_MIGRATION_FAILED' || !is_string($rows[0]['finished_at']))) {
                                    throw new RuntimeException('PLUGIN_SOURCE_COLLABORATION_FORENSIC_LEDGER_CHANGED');
                                }
                                $v2 = $pluginKey === 'peanut.workflow' && count($profile['selected']) === 5;
                                if ($pluginKey === 'official.file') {
                                    if (array_column($rows, 'migration_key') !== array_keys($profile['selected'])
                                        || array_column($rows, 'checksum') !== array_values($profile['selected'])
                                        || count($original['old_ledger'] ?? []) !== 5
                                        || self::digest(array_slice($rows, 0, 5)) !== self::digest($original['old_ledger'])
                                        || $rows[5]['status'] !== 'applied' || $rows[5]['resolution_kind'] !== 'executed'
                                        || $rows[5]['provenance_json'] !== null || $rows[5]['error_code'] !== null
                                        || $rows[6]['module_version'] !== '1.0.17' || $rows[6]['status'] !== 'failed'
                                        || $rows[6]['error_code'] !== 'MODULE_MIGRATION_ADOPTION_DRIFT'
                                        || !is_string($rows[5]['finished_at']) || !is_string($rows[6]['finished_at'])
                                        || ($original['ordered_stages'][0]['safe_after']['preservation_verified'] ?? null) !== true
                                        || ($original['ordered_stages'][0]['safe_after']['created_empty_tables'] ?? null) !== 2) {
                                        throw new RuntimeException('PLUGIN_SOURCE_FILE_FORENSIC_LEDGER_CHANGED');
                                    }
                                    $q = $pdo->prepare('SELECT installed_version,status FROM pa_module_installation WHERE module_key=?');
                                    try { $q->execute([$pluginKey]); $module = $q->fetchAll(PDO::FETCH_ASSOC); }
                                    finally { $q->closeCursor(); }
                                    if ($module !== [['installed_version' => '1.0.17', 'status' => 'failed']]) {
                                        throw new RuntimeException('PLUGIN_SOURCE_FILE_FORENSIC_MODULE_CHANGED');
                                    }
                                    $schema = MySqlMetadataObservation::tableSchema($pdo, 'pa_file_object');
                                    $column = $schema['columns']['visibility_batch_key'] ?? null;
                                    if (!is_array($column) || $column['COLUMN_TYPE'] !== 'varchar(64)'
                                        || $column['IS_NULLABLE'] !== 'YES' || $column['COLUMN_DEFAULT'] !== null
                                        || $column['EXTRA'] !== '' || $column['COLLATION_NAME'] !== 'ascii_bin'
                                        || ($schema['columns']['status']['COLUMN_TYPE'] ?? null) !== 'varchar(32)'
                                        || (string) $pdo->query('SELECT COUNT(*) FROM pa_file_object WHERE visibility_batch_key IS NOT NULL')->fetchColumn() !== '0') {
                                        throw new RuntimeException('PLUGIN_SOURCE_FILE_FORENSIC_SHAPE_CHANGED');
                                    }
                                }
                                if ($pluginKey === 'official.notification') {
                                    if (array_column($rows, 'migration_key') !== array_keys($profile['selected'])
                                        || array_column($rows, 'checksum') !== array_values($profile['selected'])
                                        || array_column($rows, 'status') !== ['applied', 'applied', 'failed']
                                        || array_column($rows, 'resolution_kind') !== [null, 'executed', null]
                                        || $rows[2]['error_code'] !== 'MODULE_MIGRATION_FAILED' || !is_string($rows[2]['finished_at'])) {
                                        throw new RuntimeException('PLUGIN_SOURCE_FAILURE_FORENSIC_LEDGER_CHANGED');
                                    }
                                    $this->assertNotificationReceiptWrite($original, $original['baseline']['lifecycle_effects']);
                                    $observed = PluginLifecycleService::notificationReceiptSnapshot($original['notification_receipt_write']['current']['path']);
                                    unset($observed['contents']);
                                    if (self::digest($observed) !== self::digest($original['notification_receipt_write']['current'])) {
                                        throw new RuntimeException('PLUGIN_SOURCE_NOTIFICATION_RECEIPT_CHANGED');
                                    }
                                }
                                if ($v2) {
                                    if (array_column($rows, 'migration_key') !== array_keys($profile['selected'])
                                        || array_column($rows, 'status') !== ['applied', 'failed', 'applied', 'applied', 'failed']
                                        || array_column($rows, 'resolution_kind') !== ['executed', null, 'executed', 'executed', null]
                                        || array_column($rows, 'checksum') !== array_values($profile['selected'])) {
                                        throw new RuntimeException('PLUGIN_SOURCE_FAILURE_FORENSIC_LEDGER_CHANGED');
                                    }
                                } elseif ($pluginKey === 'peanut.workflow' && (array_column($rows, 'migration_key') !== [$profile['first'], $profile['failed']]
                                    || $rows[0]['status'] !== 'applied' || $rows[0]['resolution_kind'] !== 'executed'
                                    || $rows[0]['checksum'] !== $profile['selected'][$profile['first']]
                                    || $rows[1]['checksum'] !== $profile['selected'][$profile['failed']])) {
                                    throw new RuntimeException('PLUGIN_SOURCE_FAILURE_FORENSIC_LEDGER_CHANGED');
                                }
                                $failed = array_values(array_filter($rows, static fn(array $row): bool => $row['status'] !== 'applied'));
                                if (array_column($failed, 'migration_key') !== ($v2
                                    ? ['peanut.workflow:20261012-workflow-managed-template', $profile['failed']] : [$profile['failed']])) {
                                    throw new RuntimeException('PLUGIN_SOURCE_TASK_FORENSIC_LEDGER_CHANGED');
                                }
                                foreach ($failed as $row) {
                                    if ($row['status'] !== 'failed' || $row['resolution_kind'] !== null || $row['provenance_json'] !== null
                                        || ($v2 && ($row['error_code'] !== 'MODULE_MIGRATION_FAILED' || !is_string($row['finished_at'])))) {
                                        throw new RuntimeException('PLUGIN_SOURCE_TASK_FORENSIC_LEDGER_CHANGED');
                                    }
                                }
                                foreach ($profile['empty'] ?? $profile['created'] as $table) {
                                    $q = $pdo->query('SELECT EXISTS(SELECT 1 FROM `' . $table . '`)');
                                    try { if ((string) $q->fetchColumn() !== '0') { throw new RuntimeException('PLUGIN_SOURCE_FAILURE_FORENSIC_NEW_TABLE_NOT_EMPTY'); } }
                                    finally { $q->closeCursor(); }
                                }
                            };
                            $check(); $effects = $original['baseline']['lifecycle_effects'];
                            // Capture the accepted failure afterimage, while the baseline still
                            // retains the immutable pre-operation receipt and its recovery chain.
                            if ($pluginKey === 'official.notification') {
                                $effects['notification_forward_receipt'] = $original['notification_receipt_write']['current'];
                            }
                            $scope = SourceApplicationBackup::pluginScope($pdo, $effects, $binding, $metadataTool, $check);
                            $present = [...$backup['scope']['tables'], ...$profile['created']]; sort($present, SORT_STRING);
                            $absent = array_values(array_diff($backup['scope']['absent_original_tables'], $profile['created']));
                            if (array_diff($profile['created'], $backup['scope']['absent_original_tables']) !== []
                                || $scope['tables'] !== $present || $scope['absent_original_tables'] !== $absent) {
                                throw new RuntimeException('PLUGIN_SOURCE_TASK_FORENSIC_OBJECTS_CHANGED');
                            }
                            $baseline = $original['baseline'] + ['forensic_only' => true, 'original_failure' => ['path' => $input, 'sha256' => $originalSha], 'recovery_admission' => false];
                            $captured = SourceApplicationBackup::capture($binding, $baseline, self::digest($baseline), $scope, $tools[0], $output, $check, $profile['purpose']);
                            if (SourceApplicationBackup::pluginScope($pdo, $effects, $binding, $metadataTool, $check) !== $scope) { throw new RuntimeException('PLUGIN_SOURCE_TASK_FORENSIC_SCOPE_CHANGED'); }
                            $check(); return ['protocol' => 'peanut.source-' . match ($pluginKey) { 'official.task' => 'task', 'official.notification' => 'notification', 'official.collaboration' => 'collaboration', 'official.file' => 'file', default => 'workflow' } . '-failure-forensic-result.v1', 'status' => 'captured', 'original_failure' => $baseline['original_failure'],
                                'backup' => $captured, 'source_schema_package_locks' => 'held', 'restore' => 'not_executed', 'recovery_admission' => false, 'qualification' => 'not_run'];
                        });
                    } finally { $this->failureForensic = null; }
                });
        });
        return $result;
    }

    /** Fixed predecessor evidence, available only inside this source request's native locks. */
    public function notificationRepairEvidence(PDO $pdo): array
    {
        if ($this->notificationPredecessor === '' || (($this->planning['pdo'] ?? null) !== $pdo && ($this->mutation['pdo'] ?? null) !== $pdo)) {
            throw new RuntimeException('PLUGIN_SOURCE_NOTIFICATION_PREDECESSOR_REQUIRED');
        }
        $evidence = $this->readNotificationPredecessor($this->notificationPredecessor);
        $live = PluginLifecycleService::notificationReceiptSnapshot($evidence['lineage']['live_binding']['path']);
        unset($live['contents']);
        if (self::digest($live) !== self::digest($evidence['lineage']['live_binding'])) {
            throw new RuntimeException('PLUGIN_SOURCE_NOTIFICATION_PREDECESSOR_BINDING_CHANGED');
        }
        if (InstallationExecutionHost::migrationDatabaseIdentity($pdo) !== $evidence['database']) {
            throw new RuntimeException('PLUGIN_SOURCE_NOTIFICATION_PREDECESSOR_DATABASE_CHANGED');
        }
        $q = $pdo->prepare('SELECT * FROM pa_module_migration WHERE module_key=? AND migration_key<>? ORDER BY migration_key');
        try { $q->execute(['official.notification', \PeanutAdmin\Modules\Notification\Contract\NotificationUpgradePreflight::REPAIR_KEY]);
            $rows = $q->fetchAll(PDO::FETCH_ASSOC); } finally { $q->closeCursor(); }
        if (self::digest($rows) !== $evidence['lineage']['original_ledger_sha256']) {
            throw new RuntimeException('PLUGIN_SOURCE_NOTIFICATION_PREDECESSOR_LEDGER_CHANGED');
        }
        if ($this->notificationLineage !== null && self::digest($this->notificationLineage) !== self::digest($evidence['lineage'])) {
            throw new RuntimeException('PLUGIN_SOURCE_NOTIFICATION_PREDECESSOR_CHANGED');
        }
        $this->notificationLineage ??= $evidence['lineage'];
        return $evidence;
    }

    private function readNotificationPredecessor(string $path): array
    {
        $root = rtrim($this->app->getRootPath(), DIRECTORY_SEPARATOR);
        $bytes = SourceApplicationBackup::protectedPluginBytes($path, $root);
        $backup = json_decode($bytes, true, 64, JSON_THROW_ON_ERROR);
        $baseline = $backup['notification_failure'] ?? [];
        $ref = $baseline['original_failure'] ?? [];
        if (dirname($ref['path'] ?? '') !== $this->receiptDirectory(false)
            || preg_match('/\Aresult-[a-f0-9]{32}\.json\z/D', basename($ref['path'] ?? '')) !== 1) {
            throw new RuntimeException('PLUGIN_SOURCE_NOTIFICATION_PREDECESSOR_INVALID');
        }
        $originalBytes = SourceApplicationBackup::protectedPluginBytes($ref['path'] ?? '', $root);
        $original = json_decode($originalBytes, true, 64, JSON_THROW_ON_ERROR);
        $document = $original; unset($document['document_sha256']);
        $expected = [
            'official.notification:20260826-namespace-permission-keys' => '8be087e7adacb8d34f2fa61111e683a7b15af4b62d93910d3a9655f1a605f606',
            'official.notification:20260921-adopt-notification-delivery-schema' => 'eaef582cd762c803f3f7d213687f61d43fd3634e9f0b984471f11648e10979fb',
            'official.notification:20261012-notification-producer-identity' => '7c01bc1bf1f309e879ff3014f89418157abbce45374a58064d1a6dc94fca07ce',
        ];
        $ledger = $original['ledger'] ?? [];
        if (($backup['protocol'] ?? null) !== 'peanut.source-notification-failure-forensic.v1' || ($backup['status'] ?? null) !== 'captured'
            || ($backup['recovery_admission'] ?? null) !== false || ($backup['execution_admitted'] ?? null) !== false
            || ($baseline['forensic_only'] ?? null) !== true || ($baseline['recovery_admission'] ?? null) !== false
            || ($backup['baseline_sha256'] ?? null) !== self::digest($baseline)
            || ($ref['sha256'] ?? null) !== hash('sha256', $originalBytes)
            || ($original['document_sha256'] ?? null) !== self::digest($document)
            || ($original['protocol'] ?? null) !== 'peanut.source-plugin-failure.v1' || ($original['status'] ?? null) !== 'failed'
            || ($original['operation'] ?? null) !== 'upgrade' || ($original['plugin_key'] ?? null) !== 'official.notification'
            || ($original['binding']['source']['root'] ?? null) !== dirname($root)
            || ($backup['binding']['source']['root'] ?? null) !== dirname($root)
            || ($original['failure_phase'] ?? null) !== 'native_lifecycle'
            || ($original['baseline']['selected'] ?? null) !== $expected || ($baseline['selected'] ?? null) !== $expected
            || self::digest($backup['binding']['database'] ?? []) !== self::digest($original['binding']['database'] ?? [])
            || count($ledger) !== 3 || array_column($ledger, 'migration_key') !== array_keys($expected)
            || array_column($ledger, 'checksum') !== array_values($expected)
            || array_column($ledger, 'status') !== ['applied', 'applied', 'failed']
            || array_column($ledger, 'resolution_kind') !== [null, 'executed', null]
            || ($ledger[2]['error_code'] ?? null) !== 'MODULE_MIGRATION_FAILED' || !is_string($ledger[2]['finished_at'] ?? null)
            || ($ledger[2]['provenance_json'] ?? null) !== null
            || count($original['ordered_stages'] ?? []) !== 1
            || ($original['ordered_stages'][0]['migration_key'] ?? null) !== array_keys($expected)[1]
            || ($original['ordered_stages'][0]['accepted_while'] ?? null) !== 'applying'
            || ($original['ordered_stages'][0]['safe_after']['preservation_verified'] ?? null) !== true
            || ($original['ordered_stages'][0]['safe_after']['created_empty_tables'] ?? null) !== 6
            || ($original['notification_receipt_write']['phase'] ?? null) !== 'transition_accepted') {
            throw new RuntimeException('PLUGIN_SOURCE_NOTIFICATION_PREDECESSOR_INVALID');
        }
        foreach (['pa_notification_template', 'pa_notification_message', 'pa_notification_attachment', 'pa_notification_outbox',
            'pa_sms_rate_bucket', 'pa_notification_event', 'pa_notification_task_actor'] as $table) {
            if (!in_array($table, $backup['scope']['tables'] ?? [], true) || !isset($backup['scope']['objects'][$table])) {
                throw new RuntimeException('PLUGIN_SOURCE_NOTIFICATION_PREDECESSOR_SCOPE_CHANGED');
            }
        }
        if (array_column($backup['receipts'] ?? [], 'phase') !== ['probe-before', 'dump', 'probe-after']) {
            throw new RuntimeException('PLUGIN_SOURCE_NOTIFICATION_PREDECESSOR_PAYLOAD_CHANGED');
        }
        foreach ([$backup['backup_payload'] ?? [], $backup['restore_instructions'] ?? [], ...($backup['receipts'] ?? [])] as $file) {
            $data = SourceApplicationBackup::protectedPluginBytes($file['path'] ?? '', $root);
            if (($file['sha256'] ?? null) !== hash('sha256', $data)) { throw new RuntimeException('PLUGIN_SOURCE_NOTIFICATION_PREDECESSOR_PAYLOAD_CHANGED'); }
            if (isset($file['phase'])) {
                $process = json_decode($data, true, 64, JSON_THROW_ON_ERROR);
                if (($process['phase'] ?? null) !== $file['phase'] || ($process['rc'] ?? null) !== 0
                    || ($process['local_process_terminal'] ?? null) !== true || ($process['remote_success_terminal_confirmed'] ?? null) !== true
                    || ($process['local_deadline_exceeded'] ?? null) !== false || ($process['local_output_limit_exceeded'] ?? null) !== false) {
                    throw new RuntimeException('PLUGIN_SOURCE_NOTIFICATION_PREDECESSOR_PAYLOAD_CHANGED');
                }
            }
        }
        $state = $original['notification_receipt_write'];
        $payload = $state['transition']['payload'] ?? [];
        $sealedBytes = SourceApplicationBackup::protectedPluginBytes($payload['path'] ?? '', $root);
        $sealed = json_decode($sealedBytes, true, 64, JSON_THROW_ON_ERROR);
        $binding = $sealed['binding'] ?? [];
        // The forensic dump binds the receipt snapshot; its already sealed native
        // afterimage is the protected byte source, retained with the original failure.
        $receipt = $backup['scope']['notification_forward_receipt'] ?? [];
        if (hash('sha256', $sealedBytes) !== ($state['current']['sha256'] ?? null)
            || hash('sha256', $sealedBytes) !== ($payload['sha256'] ?? null)
            || strlen($sealedBytes) !== ($payload['bytes'] ?? null)
            || ($sealed['sha256'] ?? null) !== self::digest($binding)
            || ($state['transition']['binding_sha256'] ?? null) !== self::digest($binding)
            || ($state['transition']['target_sha256'] ?? null) !== self::digest($binding['target'] ?? [])
            || self::digest($receipt) !== self::digest($state['current'])
            || ($binding['instance'] ?? null) === null || count($binding['attempts'] ?? []) !== 2
            || ($binding['attempts'][1]['request_id'] ?? null) !== ($original['request_id'] ?? null)) {
            throw new RuntimeException('PLUGIN_SOURCE_NOTIFICATION_PREDECESSOR_BINDING_CHANGED');
        }
        $head = $original['binding']['source']['head'] ?? '';
        $project = dirname($root);
        $committed = static function (string $file) use ($head, $project): string {
            if (preg_match('/\A[a-f0-9]{40}\z/D', $head) !== 1) { throw new RuntimeException('PLUGIN_SOURCE_NOTIFICATION_PREDECESSOR_SOURCE_CHANGED'); }
            $data = \leaseGit(['-C', $project, 'show', $head . ':' . $file]) . "\n";
            if (hash('sha1', 'blob ' . strlen($data) . "\0" . $data) !== \leaseGit(['-C', $project, 'rev-parse', $head . ':' . $file])) {
                throw new RuntimeException('PLUGIN_SOURCE_NOTIFICATION_PREDECESSOR_SOURCE_CHANGED');
            }
            return $data;
        };
        $lockBytes = $committed('plugins.lock'); $lock = json_decode($lockBytes, true, 64, JSON_THROW_ON_ERROR);
        $entries = array_values(array_filter($lock['plugins'] ?? [], static fn(array $entry): bool => ($entry['key'] ?? null) === 'official.notification'));
        $package = $binding['target']['package'] ?? [];
        if (count($entries) !== 1 || ($package['lock_digest'] ?? null) !== hash('sha256', $lockBytes)
            || ($package['artifact_sha256'] ?? null) !== ($entries[0]['source']['sha256'] ?? null)
            || ($package['version'] ?? null) !== ($entries[0]['version'] ?? null)
            || ($package['key'] ?? null) !== 'official.notification'
            || ($package['source'] ?? null) !== (($entries[0]['source']['type'] ?? '') . ':' . ($entries[0]['source']['reference'] ?? ''))
            || hash('sha256', $committed('plugins/official.notification/plugin.json')) !== ($binding['target']['manifest_sha256'] ?? null)
            || hash('sha256', $committed('server/app/modules/official/notification/module.json')) !== ($binding['target']['module_manifest_sha256'] ?? null)
            || hash('sha256', $committed('server/app/modules/official/notification/src/Contract/NotificationUpgradePreflight.php')) !== ($original['ordered_stages'][0]['contract_sha256'] ?? null)) {
            throw new RuntimeException('PLUGIN_SOURCE_NOTIFICATION_PREDECESSOR_SOURCE_CHANGED');
        }
        $entry = $entries[0];
        $descriptor = new \app\platform\value\plugin\PluginDescriptor($entry['key'], $entry['version'], $entry['source'], hash('sha256', $lockBytes),
            $entry['manifest_sha256'], $entry['composer'], $entry['npm'], $entry['frontend'], [], $entry['trust']);
        if (self::digest($descriptor->publicIdentity()) !== self::digest($package)) {
            throw new RuntimeException('PLUGIN_SOURCE_NOTIFICATION_PREDECESSOR_SOURCE_CHANGED');
        }
        foreach ($expected as $key => $sha) {
            if (hash('sha256', $committed('server/app/modules/official/notification/database/migrations/' . substr($key, strlen('official.notification:')) . '.sql')) !== $sha) {
                throw new RuntimeException('PLUGIN_SOURCE_NOTIFICATION_PREDECESSOR_SOURCE_CHANGED');
            }
        }
        return ['database' => $original['binding']['database'], 'binding' => $binding, 'ledger' => $ledger,
            'lineage' => ['protocol' => 'peanut.notification-producer-repair-predecessor.v1',
                'forensic' => ['path' => $path, 'sha256' => hash('sha256', $bytes)], 'failure' => $ref,
                'sealed_binding' => $payload, 'live_binding' => $state['current'],
                'original_target' => $binding['target'], 'original_ledger_sha256' => self::digest($ledger)]];
    }

    public function requireFailureForensic(PDO $pdo, string $pluginKey, bool $packageLocked = true): void
    {
        if (!in_array($pluginKey, ['official.task', 'peanut.workflow', 'official.notification', 'official.collaboration', 'official.file'], true) || $this->failureForensic === null
            || $this->failureForensic['pdo'] !== $pdo || $this->failureForensic['plugin_key'] !== $pluginKey || $this->mutation !== null || $this->planning !== null
            || $this->identityRecovery !== null || $pdo->inTransaction()) { throw new RuntimeException('PLUGIN_SOURCE_TASK_FORENSIC_WINDOW_REQUIRED'); }
        ($this->failureForensic['guard'])();
        if ($packageLocked) {
            $name = \app\common\value\runtime\RuntimeNamespace::fromConfiguration()->advisoryLockName('pa:module-runtime:' . substr(hash('sha256', $pluginKey), 0, 40));
            $q = $pdo->prepare('SELECT IS_USED_LOCK(?) = CONNECTION_ID()');
            try { $q->execute([$name]); if ((int) $q->fetchColumn() !== 1) { throw new RuntimeException('PLUGIN_SOURCE_PACKAGE_LOCK_REQUIRED'); } }
            finally { $q->closeCursor(); }
        }
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
            || $this->planning !== null || $this->mutation !== null || $this->identityRecovery !== null || $this->failureForensic !== null
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
                // Native dump statement blocks include the preceding table's separator whitespace.
                && ltrim($block['bytes'], " \t\r\n") !== ltrim($now['tables'][$name]['bytes'], " \t\r\n")) {
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
            'empty' => true, 'original_business_catalog_blocks_unchanged' => true,
            'block_comparison' => 'exact SQL bytes excluding leading native statement separator whitespace only',
            'old_safe_after' => 'not_created', 'qualification' => 'not_run'];
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
        SourceApplicationBackup::assertInstalledQualificationWrite($pdo);
        if ($this->mutation === null || $this->sourceMetadataTool === '') { return; }
        $this->requireMutation($pdo, $this->mutation['plugin_key']);
        $observedEffects = $this->mutation['effects'];
        $observedEffects['notification_forward_receipt'] = $this->mutation['notification_receipt']['current'];
        $scope = SourceApplicationBackup::pluginScope($pdo, $observedEffects, $this->mutation['binding'],
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
        $allowedPath = is_string($file) && match ($module) {
            'peanut.workflow' => $file === $modulesRoot . 'peanut/workflow/src/Database/WorkflowSchemaMigrationProof.php',
            'peanut.artifact-revision' => $file === $modulesRoot . 'peanut/artifact_revision/src/Database/ArtifactRevisionMigrationPreservation.php',
            'fixture.workflow-business' => $file === $modulesRoot . 'fixture/workflow_business/src/Database/WorkflowBusinessMigrationPreservation.php',
            default => str_starts_with($file, $modulesRoot . 'official/'),
        };
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

    private function assertAppendOnlyRepairs(array $effects, array $ledger): void
    {
        $rows = array_column($ledger, null, 'migration_key');
        foreach ($effects['repair_omissions'] ?? [] as $repairKey => $relation) {
            $original = $rows[$relation['original_key']] ?? [];
            $collaboration = $repairKey === 'official.collaboration:20261012120000_create_collaboration_runtime-repair-20261021';
            if (isset($rows[$repairKey]) || ($original['module_key'] ?? null) !== ($collaboration ? 'official.collaboration' : 'peanut.workflow')
                || ($original['status'] ?? null) !== 'applied' || ($original['checksum'] ?? null) !== $relation['original_sha256']
                || ($original['resolution_kind'] ?? null) !== 'executed' || ($original['provenance_json'] ?? null) !== null
                || ($original['error_code'] ?? null) !== null || !is_string($original['finished_at'] ?? null)
                || self::digest($original) !== $relation['original_ledger_sha256']) {
                throw new RuntimeException('PLUGIN_SOURCE_REPAIR_OMISSION_CHANGED');
            }
            if ($collaboration) {
                $expectedKeys = ['official.collaboration:20261012120000_create_collaboration_runtime',
                    'official.collaboration:20261013120100_add_collaboration_business_barriers',
                    'official.collaboration:20261013130000_add_instance_lifecycle',
                    'official.collaboration:20261014120000_add_native_collaboration_grants'];
                if (array_keys($relation['completed_originals'] ?? []) !== $expectedKeys) { throw new RuntimeException('PLUGIN_SOURCE_REPAIR_OMISSION_CHANGED'); }
                foreach ($relation['completed_originals'] as $key => $witness) {
                    $row = $rows[$key] ?? [];
                    if (($row['module_key'] ?? null) !== 'official.collaboration' || ($row['status'] ?? null) !== 'applied'
                        || ($row['checksum'] ?? null) !== $witness['sha256'] || ($row['resolution_kind'] ?? null) !== 'executed'
                        || ($row['provenance_json'] ?? null) !== null || ($row['error_code'] ?? null) !== null
                        || !is_string($row['finished_at'] ?? null) || self::digest($row) !== $witness['ledger_sha256']) {
                        throw new RuntimeException('PLUGIN_SOURCE_REPAIR_OMISSION_CHANGED');
                    }
                }
            }
        }
        foreach ($effects['append_only_repairs'] ?? [] as $key => $relation) {
            $original = $rows[$key] ?? []; $repair = $rows[$relation['repair_key']] ?? [];
            if (($original['status'] ?? null) !== 'failed' || ($original['checksum'] ?? null) !== $relation['original_sha256']
                || self::digest($original) !== $relation['original_ledger_sha256'] || ($original['resolution_kind'] ?? null) !== null
                || ($original['provenance_json'] ?? null) !== null || ($repair['status'] ?? null) !== 'applied'
                || ($repair['checksum'] ?? null) !== $relation['repair_sha256'] || ($repair['resolution_kind'] ?? null) !== 'executed'
                || ($repair['provenance_json'] ?? null) !== null || ($repair['module_key'] ?? null) !== ($original['module_key'] ?? null)
                || !is_string($repair['finished_at'] ?? null) || ($repair['error_code'] ?? null) !== null) {
                throw new RuntimeException('PLUGIN_SOURCE_APPEND_ONLY_REPAIR_INCOMPLETE');
            }
        }
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
            $this->assertNotificationReceiptWrite($record, $effects);
            $stages = $record['ordered_stages'] ?? null; $ledger = $record['ledger'] ?? null;
            if (!is_array($stages) || !array_is_list($stages) || !is_array($ledger) || !array_is_list($ledger)) { throw new RuntimeException('PLUGIN_SOURCE_ORIGINAL_COMPLETION_CHANGED'); }
            $seen = array_column($stages, 'migration_key'); $wanted = $effects['pending']; sort($seen, SORT_STRING); sort($wanted, SORT_STRING);
            if ($seen !== $wanted) { throw new RuntimeException('PLUGIN_SOURCE_ORIGINAL_COMPLETION_CHANGED'); }
            $byKey = array_column($ledger, null, 'migration_key');
            $this->assertAppendOnlyRepairs($effects, $ledger);
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
        $qualification = SourceApplicationBackup::installedQualificationAdmission($pdo, $pluginKey);
        if ($qualification !== null) {
            $name = \app\common\value\runtime\RuntimeNamespace::fromConfiguration()->advisoryLockName(
                'pa:module-runtime:' . substr(hash('sha256', $pluginKey), 0, 40));
            $lock = $pdo->prepare('SELECT IS_USED_LOCK(?) = CONNECTION_ID()');
            try {
                $lock->execute([$name]);
                if ((int) $lock->fetchColumn() !== 1) { throw new RuntimeException('PLUGIN_SOURCE_PACKAGE_LOCK_REQUIRED'); }
            } finally { $lock->closeCursor(); }
            return ['operation' => 'console.qualification:installed', 'binding' => $qualification];
        }
        $this->assertMutationLocks($pdo, $pluginKey);
        if ($this->notificationPredecessor !== '') { $this->notificationRepairEvidence($pdo); }
        $expectedReceipt = $this->mutation['notification_receipt']['current'];
        if ($expectedReceipt !== null) {
            $actualReceipt = PluginLifecycleService::notificationReceiptSnapshot($expectedReceipt['path']);
            unset($actualReceipt['contents']);
            if ($actualReceipt !== $expectedReceipt) { throw new RuntimeException('PLUGIN_SOURCE_NOTIFICATION_RECEIPT_CHANGED'); }
        }
        return $this->mutation['admission'];
    }

    private function assertMutationLocks(PDO $pdo, string $pluginKey): void
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
    }

    /** Original-window verification consumes the sealed afterimage, never a later live attempt. */
    private function assertNotificationReceiptWrite(array $record, array $effects): void
    {
        $original = $effects['notification_forward_receipt'];
        if ($original === null) { return; }
        $state = $record['notification_receipt_write'] ?? null;
        if (!is_array($state)) { throw new RuntimeException('PLUGIN_SOURCE_NOTIFICATION_TRANSITION_REQUIRED'); }
        if (($state['phase'] ?? null) === 'unchanged' && ($state['current'] ?? null) === $original
            && ($state['transition'] ?? null) === null) { return; }
        $transition = $state['transition'] ?? [];
        $after = $transition['after'] ?? [];
        $payload = $transition['payload'] ?? [];
        $request = $record['request_id'];
        $expectedPath = $this->receiptDirectory(false) . '/notification-' . hash('sha256', $request) . '.json';
        if (($state['phase'] ?? null) !== 'transition_accepted' || ($transition['before'] ?? null) !== $original
            || ($state['current'] ?? null) !== $after || ($state['observed_after'] ?? null) !== $after
            || ($state['afterimage'] ?? null) !== $payload || ($after['path'] ?? null) !== $original['path']
            || ($after['exists'] ?? null) !== true || ($after['directory_exists'] ?? null) !== true || ($after['mode'] ?? null) !== 0600
            || ($transition['request_id'] ?? null) !== $request
            || ($transition['admission_sha256'] ?? null) !== self::digest($record['admission'])
            || ($payload['path'] ?? null) !== $expectedPath || ($payload['sha256'] ?? null) !== ($after['sha256'] ?? null)
            || ($payload['bytes'] ?? null) !== ($after['bytes'] ?? null)) {
            throw new RuntimeException('PLUGIN_SOURCE_NOTIFICATION_TRANSITION_REQUIRED');
        }
        $bytes = SourceApplicationBackup::protectedPluginBytes($expectedPath, $this->app->getRootPath());
        $document = json_decode($bytes, true, 64, JSON_THROW_ON_ERROR);
        $binding = $document['binding'] ?? null;
        if (!is_array($binding) || hash('sha256', $bytes) !== $after['sha256'] || strlen($bytes) !== $after['bytes']
            || ($document['sha256'] ?? null) !== self::digest($binding)
            || ($transition['binding_sha256'] ?? null) !== self::digest($binding)
            || ($transition['target_sha256'] ?? null) !== self::digest($binding['target'] ?? [])) {
            throw new RuntimeException('PLUGIN_SOURCE_NOTIFICATION_AFTERIMAGE_REQUIRED');
        }
        $prior = null;
        if ($original['exists']) {
            $recoveryBytes = SourceApplicationBackup::protectedPluginBytes($record['evidence']['recovery']['path'], $this->app->getRootPath());
            $recovery = json_decode($recoveryBytes, true, 64, JSON_THROW_ON_ERROR);
            if (hash('sha256', $recoveryBytes) !== $record['evidence']['recovery']['sha256']
                || self::digest($recovery['backup_manifest'] ?? []) !== self::digest($record['evidence']['backup_manifest'])) {
                throw new RuntimeException('PLUGIN_SOURCE_NOTIFICATION_ORIGINAL_CHANGED');
            }
            $backupBytes = SourceApplicationBackup::protectedPluginBytes($record['evidence']['backup_manifest']['path'], $this->app->getRootPath());
            $backup = json_decode($backupBytes, true, 64, JSON_THROW_ON_ERROR);
            $receiptBackup = $backup['notification_forward_receipt'] ?? [];
            if (hash('sha256', $backupBytes) !== $record['evidence']['backup_manifest']['sha256']
                || self::digest($receiptBackup['original'] ?? []) !== self::digest($original)
                || ($receiptBackup['payload']['sha256'] ?? null) !== $original['sha256']
                || ($receiptBackup['payload']['bytes'] ?? null) !== $original['bytes']) {
                throw new RuntimeException('PLUGIN_SOURCE_NOTIFICATION_ORIGINAL_CHANGED');
            }
            $priorBytes = SourceApplicationBackup::protectedPluginBytes($receiptBackup['payload']['path'], $this->app->getRootPath());
            if (hash('sha256', $priorBytes) !== $original['sha256'] || strlen($priorBytes) !== $original['bytes']) {
                throw new RuntimeException('PLUGIN_SOURCE_NOTIFICATION_ORIGINAL_CHANGED');
            }
            $prior = json_decode($priorBytes, true, 64, JSON_THROW_ON_ERROR)['binding'];
        }
        if (($transition['kind'] ?? null) !== self::notificationTransitionKind($prior, $binding, $record['admission'])) {
            throw new RuntimeException('PLUGIN_SOURCE_NOTIFICATION_TRANSITION_REQUIRED');
        }
        if (isset($binding['lineage'])) {
            $predecessor = $this->readNotificationPredecessor($binding['lineage']['forensic']['path'] ?? '');
            if (self::digest($predecessor['lineage']) !== self::digest($binding['lineage'])
                || self::digest($binding['lineage']) !== self::digest($record['native_plan']['modules'][0]['notification_forward_predecessor'] ?? null)
                || self::digest($binding['lineage']) !== self::digest($record['execution_plan']['modules'][0]['notification_forward_predecessor'] ?? null)
                || !version_compare((string) ($binding['target']['package']['version'] ?? ''), (string) ($predecessor['lineage']['original_target']['package']['version'] ?? ''), '>')) {
                throw new RuntimeException('PLUGIN_SOURCE_NOTIFICATION_PREDECESSOR_SOURCE_CHANGED');
            }
        }
        $package = $binding['target']['package'] ?? [];
        if (($record['protocol'] ?? null) === 'peanut.source-plugin-failure.v1' && ($record['status'] ?? null) === 'failed') {
            // A failure has no successful result. Resolve the unchanged original
            // locked package instead; this branch grants forensic capture only.
            $root = dirname(rtrim($this->app->getRootPath(), DIRECTORY_SEPARATOR));
            $this->assertReceiptSource($record['binding']['source']['head'], $root . '/plugins.lock', hash_file('sha256', $root . '/plugins.lock'));
            $plugins = (new \app\platform\infrastructure\plugin\PluginLockResolver($root . '/server', $root . '/plugins.lock'))->all();
            $identity = isset($plugins['official.notification']) ? $plugins['official.notification']->publicIdentity() : [];
            if (($record['native_plan_sha256'] ?? null) !== self::digest($record['native_plan'] ?? [])
                || ($record['native_plan']['plugin_key'] ?? null) !== 'official.notification'
                || ($record['native_plan']['to_version'] ?? null) !== ($identity['version'] ?? null)) {
                throw new RuntimeException('PLUGIN_SOURCE_NOTIFICATION_TARGET_CHANGED');
            }
        } else { $identity = $record['result'] ?? []; }
        if (self::digest($package) !== self::digest(array_intersect_key($identity, $package))) {
            throw new RuntimeException('PLUGIN_SOURCE_NOTIFICATION_TARGET_CHANGED');
        }
        $head = $record['binding']['source']['head']; $root = dirname(rtrim($this->app->getRootPath(), DIRECTORY_SEPARATOR));
        $this->assertReceiptSource($head, $root . '/plugins/official.notification/plugin.json', $binding['target']['manifest_sha256']);
        $this->assertReceiptSource($head, $root . '/server/app/modules/official/notification/module.json', $binding['target']['module_manifest_sha256']);
    }

    private static function notificationTransitionKind(?array $prior, array $binding, array $admission): string
    {
        $attempts = $binding['attempts'] ?? null;
        if (($binding['protocol'] ?? null) !== 'peanut.notification-forward-binding.v1' || !is_array($attempts)
            || !array_is_list($attempts) || $attempts === []
            || ($attempts[array_key_last($attempts)]['request_id'] ?? null) !== $admission['request_id']
            || ($attempts[array_key_last($attempts)]['module_status'] ?? null) !== ($admission['operation'] === 'console.plugin:upgrade' ? 'upgrading' : 'installing')) {
            throw new RuntimeException('PLUGIN_SOURCE_NOTIFICATION_TRANSITION_REQUIRED');
        }
        if ($prior === null) {
            if (count($attempts) !== 1 || self::digest($binding['admission'] ?? []) !== self::digest($admission)
                || ($binding['request_id'] ?? null) !== $admission['request_id']) {
                throw new RuntimeException('PLUGIN_SOURCE_NOTIFICATION_TRANSITION_REQUIRED');
            }
            return isset($binding['lineage']) ? 'create_repair' : 'create';
        }
        $expected = $prior; $expected['attempts'][] = $attempts[array_key_last($attempts)];
        if (self::digest($binding) !== self::digest($expected)) { throw new RuntimeException('PLUGIN_SOURCE_NOTIFICATION_TRANSITION_REQUIRED'); }
        return 'append_attempt';
    }

    /** One fixed Notification writer, inside its existing native transaction and package lock. */
    public function writeNotificationForwardReceipt(PDO $pdo, string $path, array $binding, ?array $previous, \Closure $writeAndValidate): void
    {
        $admission = $this->requireMutation($pdo, 'official.notification');
        $state = $this->mutation['notification_receipt'];
        $before = PluginLifecycleService::notificationReceiptSnapshot($path);
        $originalBytes = $before['contents'] ?? null; unset($before['contents']);
        if ($state['phase'] !== 'unchanged' || $state['transition'] !== null || $before !== $state['current']) {
            throw new RuntimeException('PLUGIN_SOURCE_NOTIFICATION_TRANSITION_REQUIRED');
        }
        if ($before['exists']) {
            $priorBinding = json_decode($originalBytes, true, 64, JSON_THROW_ON_ERROR)['binding'];
            if ($previous !== $priorBinding) {
                throw new RuntimeException('PLUGIN_SOURCE_NOTIFICATION_TRANSITION_REQUIRED');
            }
        } elseif ($previous !== null) {
            throw new RuntimeException('PLUGIN_SOURCE_NOTIFICATION_TRANSITION_REQUIRED');
        }
        $kind = self::notificationTransitionKind($previous, $binding, $admission);
        $directory = $this->receiptDirectory(true);
        $resources = \activeLeaseResources(\requiredEnvironment('PEANUT_RESOURCE_LEASE_PROOF'));
        if (!in_array($directory, $resources['output-dir'] ?? [], true)) {
            throw new RuntimeException('PLUGIN_SOURCE_NOTIFICATION_OUTPUT_REQUIRED');
        }
        $payload = $directory . '/notification-' . hash('sha256', $admission['request_id']) . '.json';
        if (file_exists($payload) || is_link($payload)) { throw new RuntimeException('PLUGIN_SOURCE_NOTIFICATION_OUTPUT_REQUIRED'); }
        $this->mutation['notification_receipt']['phase'] = 'write_entered';
        $writeAndValidate();
        $after = PluginLifecycleService::notificationReceiptSnapshot($path);
        $bytes = $after['contents'] ?? null; unset($after['contents']);
        if (!is_string($bytes) || self::digest(json_decode($bytes, true, 64, JSON_THROW_ON_ERROR)['binding']) !== self::digest($binding)
            || $after['path'] !== $before['path'] || !$after['exists']) {
            throw new RuntimeException('PLUGIN_SOURCE_NOTIFICATION_WRITE_CHANGED');
        }
        $this->mutation['notification_receipt']['phase'] = 'durable_readback';
        $this->mutation['notification_receipt']['observed_after'] = $after;
        // The callback has ended. Check the unchanged window/PDO/source before accepting the new file value.
        $this->assertMutationLocks($pdo, 'official.notification');
        $this->mutation['notification_receipt']['afterimage'] = ['path' => $payload, 'sha256' => $after['sha256'], 'bytes' => strlen($bytes)];
        $stream = fopen($payload, 'x');
        if ($stream === false) { throw new RuntimeException('PLUGIN_SOURCE_NOTIFICATION_AFTERIMAGE_REQUIRED'); }
        try {
            if (!chmod($payload, 0600) || fwrite($stream, $bytes) !== strlen($bytes) || !fflush($stream) || !fsync($stream)) {
                throw new RuntimeException('PLUGIN_SOURCE_NOTIFICATION_AFTERIMAGE_REQUIRED');
            }
        } finally { fclose($stream); }
        $handle = fopen($directory, 'r');
        if ($handle === false) { throw new RuntimeException('PLUGIN_SOURCE_NOTIFICATION_AFTERIMAGE_REQUIRED'); }
        try { if (!fsync($handle)) { throw new RuntimeException('PLUGIN_SOURCE_NOTIFICATION_AFTERIMAGE_REQUIRED'); } }
        finally { fclose($handle); }
        $sealedBytes = SourceApplicationBackup::protectedPluginBytes($payload, $this->app->getRootPath());
        if ($sealedBytes !== $bytes) { throw new RuntimeException('PLUGIN_SOURCE_NOTIFICATION_AFTERIMAGE_REQUIRED'); }
        $this->mutation['notification_receipt']['phase'] = 'afterimage_saved';
        $this->assertMutationLocks($pdo, 'official.notification');
        $current = PluginLifecycleService::notificationReceiptSnapshot($path); unset($current['contents']);
        if ($current !== $after) { throw new RuntimeException('PLUGIN_SOURCE_NOTIFICATION_WRITE_CHANGED'); }
        $this->mutation['notification_receipt']['current'] = $after;
        $this->mutation['notification_receipt']['transition'] = [
            'before' => $before, 'after' => $after, 'request_id' => $admission['request_id'],
            'admission_sha256' => self::digest($admission), 'target_sha256' => self::digest($binding['target']),
            'binding_sha256' => self::digest($binding), 'kind' => $kind,
            'payload' => ['path' => $payload, 'sha256' => $after['sha256'], 'bytes' => strlen($bytes)],
        ];
        $this->mutation['notification_receipt']['phase'] = 'transition_accepted';
        $this->requireMutation($pdo, 'official.notification');
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
