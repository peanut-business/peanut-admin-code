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

/** Holds one native read-only source Plugin plan; never grants migration writes. */
final class PluginSourceDevelopmentHost
{
    private ?array $planning = null;

    public function __construct(private readonly App $app) {}

    public function plan(string $operation, string $pluginKey, array $actor, callable $readPlan, string $toolId = '', string $output = ''): array
    {
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
        $initial = $admission->capture($actor, false);
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
            $pdo, $connection, $admission, $actor, $initial, $identity, $context, $operation, $pluginKey, $config, $readPlan, $toolId, $output): array {
            $result = null;
            $runner->run($files, $target, $versions['release_sequence_version'], true, null,
                function (PDO $actual, array $selection) use ($pdo, $connection, $admission, $actor, $initial, $identity,
                    $context, $operation, $pluginKey, $config, $files, $readPlan, $toolId, $output, &$result): void {
                    $recheck = function () use ($pdo, $connection, $admission, $actor, $initial, $identity, $context, $config): void {
                        $current = $this->app->make(CurrentExecutionContext::class)->current();
                        if ($current !== $context || Db::connect() !== $connection || $connection->getPdo() !== $pdo
                            || $admission->capture($actor, false) !== $initial || InstallationExecutionHost::migrationDatabaseIdentity($pdo) !== $identity) {
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
                            $pdo, $config, $recheck, $readPlan, $toolId, $output): array {
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
