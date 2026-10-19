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

    public function __construct(private readonly App $app) {}

    public function plan(string $operation, string $pluginKey, array $actor, callable $readPlan, string $toolId = '', string $output = '', ?array $execution = null): array
    {
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
        $connection = Db::connect();
        foreach (['hostname' => 'host', 'hostport' => 'port', 'database' => 'database', 'username' => 'user', 'password' => 'password'] as $native => $guarded) {
            if ((string) $connection->getConfig($native) !== (string) $config[$guarded]) { throw new RuntimeException('PLUGIN_SOURCE_MASTER_MISMATCH'); }
        }
        if ($connection->getConfig('break_reconnect') !== false || (int) $connection->getConfig('deploy') !== 0
            || $connection->getConfig('prefix') !== 'pa_') { throw new RuntimeException('PLUGIN_SOURCE_MASTER_MISMATCH'); }
        $connection->query('SELECT CONNECTION_ID()', [], true);
        $pdo = $connection->getPdo();
        if (!$pdo instanceof PDO || $pdo->inTransaction()) { throw new RuntimeException('PLUGIN_SOURCE_MASTER_MISMATCH'); }
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
                        if (basename($file, '.sql') === '20261016-module-migration-provenance'
                            && in_array('20261016-module-migration-provenance', $selection['pending'], true)) {
                            $prerequisite = ['migration_id' => '20261016-module-migration-provenance',
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
                            if ($applying) {
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
                                $expected = SourceApplicationBackup::protectedPluginJson($execution['plan'], $this->app->getRootPath());
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
                                $scope = SourceApplicationBackup::pluginScope($pdo, $effects);
                                // Fixed closure cannot attest arbitrary trigger bodies or cascading outside writers.
                                if ($scope['triggers'] !== []) { throw new RuntimeException('PLUGIN_SOURCE_TRIGGER_EFFECT_REVIEW_REQUIRED'); }
                                foreach ($scope['foreign_keys'] as $foreign) {
                                    if ((int) $foreign['IS_LOCAL_PARENT'] === 1 && in_array($foreign['REFERENCED_TABLE_NAME'], $effects['tables'], true)
                                        && ($foreign['TABLE_SCHEMA'] !== $identity['database'] || !in_array($foreign['TABLE_NAME'], $effects['tables'], true))
                                        && (in_array($foreign['UPDATE_RULE'], ['CASCADE', 'SET NULL'], true) || in_array($foreign['DELETE_RULE'], ['CASCADE', 'SET NULL'], true))) {
                                        throw new RuntimeException('PLUGIN_SOURCE_CASCADE_EFFECT_REVIEW_REQUIRED');
                                    }
                                }
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
                                        || SourceApplicationBackup::pluginScope($pdo, $effects) !== $scope || $readLedger() !== $oldLedger) {
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
                                    return ['protocol' => 'peanut.source-plugin-result.v1', 'status' => 'completed', 'operation' => $operation,
                                        'plugin_key' => $pluginKey, 'binding' => $binding, 'native_plan_sha256' => self::digest($native),
                                        'evidence' => $proof, 'result' => $result, 'ledger' => $ledger,
                                        'verification_level' => 'native_same_process_lifecycle_completion',
                                        'cross_process_preservation' => 'not_verified', 'product_database' => 'not_verified', 'qualification' => 'not_run'];
                                } finally { $this->mutation = null; }
                            }
                            if ($toolId !== '') {
                                $this->assertBackupReads($effects['tables'], $config);
                                $scope = SourceApplicationBackup::pluginScope($pdo, $effects);
                                $registry = \projectResourceRegistry($registrySha);
                                $tools = array_values(array_filter($registry['resources']['tooling'] ?? [], static fn(array $row): bool => ($row['stable_resource_id'] ?? null) === $toolId));
                                $resources = \activeLeaseResources(\requiredEnvironment('PEANUT_RESOURCE_LEASE_PROOF'));
                                if (count($tools) !== 1 || $registrySha !== $binding['registry_sha256']
                                    || !in_array($toolId, $resources['tooling-resource-id'] ?? [], true) || !in_array($output, $resources['output-dir'] ?? [], true)) {
                                    throw new RuntimeException('PLUGIN_SOURCE_BACKUP_NATIVE_TOOL_OUTPUT_REQUIRED');
                                }
                                $baseline = ['pending' => $effects['pending'], 'selected' => $effects['selected'],
                                    'operation' => $operation, 'plugin_key' => $pluginKey, 'native_plan_sha256' => self::digest($native), 'lifecycle_effects' => $effects];
                                $checkBackup = function () use ($pdo, $recheck, $readPlan, $native, $effects, $scope): void {
                                    $recheck();
                                    // The original native lock recognizes its own owner;
                                    // this nested read does not release the outer package lock.
                                    $current = $readPlan(static fn(array $plan): array => $plan);
                                    if ($pdo->inTransaction() || self::digest($current) !== self::digest($native)
                                        || SourceApplicationBackup::pluginScope($pdo, $effects) !== $scope) {
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
