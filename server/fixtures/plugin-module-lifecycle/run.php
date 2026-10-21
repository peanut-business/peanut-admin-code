<?php

declare(strict_types=1);

use PeanutAdmin\Fixtures\DeliveryRecord\Contract\DeliveryRecordCommands;
use app\common\execution\AdminExecutionContext;
use app\common\execution\CurrentExecutionContext;
use app\common\execution\ExecutionContextStore;
use app\common\infrastructure\module\ModuleExecutionBoundary;
use app\platform\exception\plugin\PluginLifecycleException;
use app\platform\services\plugin\PluginLifecycleService;
use app\platform\infrastructure\plugin\PluginLockResolver;
use app\platform\infrastructure\plugin\PluginArtifactWriter;
use app\platform\composition\plugin\PluginModuleRegistryFactory;
use app\platform\infrastructure\plugin\ModuleCatalogApplier;
use PeanutAdmin\Kernel\Auth\TenantContext;
use PeanutAdmin\Kernel\Module\ModuleException;
use think\App;

function pluginLifecycleExpect(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function pluginLifecycleTableExists(PDO $pdo, string $table): bool
{
    $statement = $pdo->prepare(
        'SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name=?',
    );
    $statement->execute([$table]);
    return (int) $statement->fetchColumn() === 1;
}

/** @return array{lock:string,manifest:string,migration:string} */
function pluginLifecycleFailureArtifact(string $projectRoot, string $directory): array
{
    $migration = $projectRoot
        . '/server/app/modules/fixture/delivery_record/database/migrations/20260814999999_failure_fixture.sql';
    file_put_contents($migration, "THIS IS AN INTENTIONAL FIXTURE FAILURE;\n");
    $manifest = (new PluginArtifactWriter($projectRoot . '/server'))->build('fixture.delivery-record', '1.0.0',
        ['fixture.delivery-record=server/app/modules/fixture/delivery_record']);
    $manifestPath = tempnam($directory, 'failure-manifest-');
    pluginLifecycleExpect(is_string($manifestPath), 'temporary Plugin manifest is unavailable');
    file_put_contents(
        $manifestPath,
        json_encode($manifest, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n",
    );
    $lock = json_decode(
        (string) file_get_contents($projectRoot . '/plugins.lock'),
        true,
        64,
        JSON_THROW_ON_ERROR,
    );
    foreach ($lock['plugins'] as &$plugin) {
        if (($plugin['key'] ?? null) !== 'fixture.delivery-record') { continue; }
        foreach (['version', 'source', 'trust', 'composer', 'npm', 'frontend', 'modules'] as $field) { $plugin[$field] = $manifest[$field]; }
        $plugin['manifest'] = $manifestPath;
        $plugin['manifest_sha256'] = hash_file('sha256', $manifestPath);
    }
    unset($plugin);
    $lockPath = tempnam($directory, 'failure-lock-');
    pluginLifecycleExpect(is_string($lockPath), 'temporary Plugin lock is unavailable');
    file_put_contents(
        $lockPath,
        json_encode($lock, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n",
    );
    return ['lock' => $lockPath, 'manifest' => $manifestPath, 'migration' => $migration];
}

/** @return array{lock:string,manifest:string,migration:string} */
function pluginLifecycleRepairArtifact(string $projectRoot, string $directory): array
{
    $migration = $projectRoot
        . '/server/app/modules/fixture/delivery_record/database/migrations/20260815000000_failure_repair.sql';
    file_put_contents(
        $migration,
        "-- peanut-admin-repairs: fixture.delivery-record:20260814999999_failure_fixture\nSELECT 1;\n",
    );
    $manifest = (new PluginArtifactWriter($projectRoot . '/server'))->build('fixture.delivery-record', '1.0.1',
        ['fixture.delivery-record=server/app/modules/fixture/delivery_record']);
    $manifestPath = tempnam($directory, 'repair-manifest-');
    pluginLifecycleExpect(is_string($manifestPath), 'temporary repair Plugin manifest is unavailable');
    file_put_contents(
        $manifestPath,
        json_encode($manifest, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n",
    );
    $lock = json_decode(
        (string) file_get_contents($projectRoot . '/plugins.lock'),
        true,
        64,
        JSON_THROW_ON_ERROR,
    );
    foreach ($lock['plugins'] as &$plugin) {
        if (($plugin['key'] ?? null) !== 'fixture.delivery-record') {
            continue;
        }
        foreach (['version', 'source', 'trust', 'composer', 'npm', 'frontend', 'modules'] as $field) { $plugin[$field] = $manifest[$field]; }
        $plugin['manifest'] = $manifestPath;
        $plugin['manifest_sha256'] = hash_file('sha256', $manifestPath);
    }
    unset($plugin);
    $lockPath = tempnam($directory, 'repair-lock-');
    pluginLifecycleExpect(is_string($lockPath), 'temporary repair Plugin lock is unavailable');
    file_put_contents(
        $lockPath,
        json_encode($lock, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n",
    );
    return ['lock' => $lockPath, 'manifest' => $manifestPath, 'migration' => $migration];
}

/** @return array{permissions:list<array<string,mixed>>,menus:list<array<string,mixed>>} */
function pluginLifecycleCatalogSnapshot(PDO $pdo): array
{
    return [
        'permissions' => $pdo->query("SELECT * FROM pa_permission WHERE module_key<>'fixture.delivery-record' ORDER BY id")->fetchAll(PDO::FETCH_ASSOC),
        'menus' => $pdo->query("SELECT * FROM pa_menu_definition WHERE module_key<>'fixture.delivery-record' ORDER BY id")->fetchAll(PDO::FETCH_ASSOC),
    ];
}

/** Installed native host supplies the authenticated principal inside paired protection. */
function pluginLifecycleInstalledAssertions(App $app, PDO $pdo, TenantContext $context, int $roleId, array $expectedDatabaseIdentity, string $artifactDirectory, callable $guard): void
{
    // Only these existing qualification DML operations use this same native transaction.
    $write = static function (PDOStatement|string $sql, array $parameters = []) use ($pdo, $guard): void {
        \think\facade\Db::transaction(static function () use ($pdo, $guard, $sql, $parameters): void {
            $guard(true);
            $statement = $sql instanceof PDOStatement ? $sql : $pdo->prepare($sql);
            $guard(true);
            $statement->execute($parameters);
            $guard(true);
        });
    };
    $serverRoot = dirname(__DIR__, 2);
    $projectRoot = dirname($serverRoot);
    pluginLifecycleExpect(\app\common\services\installation\InstallationExecutionHost::migrationDatabaseIdentity($pdo) === $expectedDatabaseIdentity, 'protected database identity changed');
    $principal = $app->make(\app\common\services\authorization\AdminAuthorizationService::class)->principal($context);
    pluginLifecycleExpect(!$principal->root, 'fixture permission denial requires a non-Root principal');
    $role = $pdo->prepare("SELECT COUNT(*) FROM pa_member_role mr JOIN pa_role r ON r.id=mr.role_id AND r.tenant_id=mr.tenant_id WHERE mr.tenant_id=? AND mr.tenant_member_id=? AND mr.role_id=? AND r.status='active'");
    $role->execute([$context->tenantId, $context->memberId, $roleId]);
    pluginLifecycleExpect((int) $role->fetchColumn() === 1, 'existing authenticated principal Role is required');
    $member = ['tenant_id' => $context->tenantId, 'member_id' => $context->memberId, 'account_id' => $context->accountId, 'role_id' => $roleId];
    foreach (['20260814999999_failure_fixture.sql', '20260815000000_failure_repair.sql'] as $name) {
        $path = $serverRoot . '/app/modules/fixture/delivery_record/database/migrations/' . $name;
        pluginLifecycleExpect(!file_exists($path) && !is_link($path), 'previous failure source is retained; reconcile before another run');
    }
    pluginLifecycleExpect(!pluginLifecycleTableExists($pdo, 'pa_fixture_delivery_record'), 'existing fixture business data must be retained');
    $guard();
    pluginLifecycleExpect(!file_exists($artifactDirectory) && !is_link($artifactDirectory) && mkdir($artifactDirectory, 0700), 'protected fixture artifact output must be new');

    $requiredTables = [
        'pa_tenant', 'pa_tenant_member', 'pa_role', 'pa_member_role', 'pa_role_permission',
        'pa_tenant_module', 'pa_module_installation', 'pa_plugin_installation', 'pa_plugin_module',
        'pa_module_migration', 'pa_permission', 'pa_menu_definition', 'pa_setting_definition',
    ];
    foreach ($requiredTables as $table) {
        pluginLifecycleExpect(pluginLifecycleTableExists($pdo, $table), "standard migration is missing: {$table}");
    }
    pluginLifecycleExpect(
        (int) $pdo->query("SELECT COUNT(*) FROM pa_plugin_installation WHERE plugin_key='fixture.delivery-record'")->fetchColumn() === 0,
        'fixture Plugin state already exists; refusing non-auditable cleanup',
    );
    // Native lifecycle passes explicit module keys to its scoped catalog applier.
    // Existing installed Modules stay active; unrelated catalog rows must remain equal.
    $catalogSnapshot = pluginLifecycleCatalogSnapshot($pdo);

    $config = [
        'plugin_lock' => '../plugins.lock',
        'kernel_version' => '1.0.0',
        'registered_client_keys' => ['admin-web', 'platform-web'],
    ];
    // The initialized native application owns the current catalog dependency graph.
    $catalogs = app(ModuleCatalogApplier::class);
    $artifact = null;
    $repairArtifact = null;
    try {
        $resolver = new PluginLockResolver($serverRoot, '../plugins.lock');
        $service = new PluginLifecycleService(
            $resolver,
            new PluginModuleRegistryFactory($serverRoot),
            $config,
            $catalogs,
            new \PeanutAdmin\Modules\Identity\Contract\TenantModuleStateQueries(),
        );
        $guard();
        $first = $service->install('fixture.delivery-record');
        pluginLifecycleExpect(($first['status'] ?? null) === 'active', 'Plugin did not activate');
        pluginLifecycleExpect(
            (int) $pdo->query("SELECT COUNT(*) FROM pa_tenant_module WHERE module_key='fixture.delivery-record'")->fetchColumn() === 0,
            'install enabled a TenantModule',
        );
        $guard();
        pluginLifecycleExpect(($service->install('fixture.delivery-record')['operation'] ?? null) === 'unchanged', 'repeat install was not idempotent');
        $migrationCountBeforeResume = (int) $pdo->query("SELECT COUNT(*) FROM pa_module_migration WHERE module_key='fixture.delivery-record'")->fetchColumn();
        $guard();
        $write("UPDATE pa_plugin_installation SET status='installing' WHERE plugin_key='fixture.delivery-record'");
        $guard();
        $write("UPDATE pa_module_installation SET status='installing' WHERE module_key='fixture.delivery-record'");
        $guard();
        $resumed = $service->install('fixture.delivery-record');
        pluginLifecycleExpect(($resumed['operation'] ?? null) === 'resumed', 'interrupted installing state did not resume');
        pluginLifecycleExpect(
            (int) $pdo->query("SELECT COUNT(*) FROM pa_module_migration WHERE module_key='fixture.delivery-record'")->fetchColumn() === $migrationCountBeforeResume,
            'install resume duplicated an applied migration ledger row',
        );
        pluginLifecycleExpect(
            (string) $pdo->query("SELECT status FROM pa_plugin_installation WHERE plugin_key='fixture.delivery-record'")->fetchColumn() === 'active',
            'install resume did not finalize Plugin activation',
        );
        $guard();
        pluginLifecycleExpect(($service->upgrade('fixture.delivery-record', true)['dry_run'] ?? null) === true, 'upgrade dry-run was not a plan');
        pluginLifecycleExpect(($service->rollbackPlan('fixture.delivery-record')['automatic'] ?? null) === false, 'rollback became automatic');

        $executionContexts = $app->make(ExecutionContextStore::class);
        $executionContext = $app->make(CurrentExecutionContext::class);
        $commands = $app->make(DeliveryRecordCommands::class);
        $modules = $app->make(ModuleExecutionBoundary::class);
        $record = static fn(string $reference): array => $executionContexts->run(
            new AdminExecutionContext($context, 'fixture.delivery-record.record'),
            static function () use ($modules, $commands, $reference, $guard): array {
                return \think\facade\Db::transaction(static function () use ($modules, $commands, $reference, $guard): array {
                    $guard(true);
                    $modules->assertHttp('fixture.delivery-record', 'fixture.delivery-record.record');
                    $guard(true);
                    $result = $commands->record($reference);
                    $guard(true);
                    return $result;
                });
            },
        );
        foreach (['not-enabled', 'not-authorized'] as $phase) {
            if ($phase === 'not-authorized') {
                $now = gmdate('Y-m-d H:i:s.v');
                $enable = $pdo->prepare(<<<'SQL'
    INSERT INTO pa_tenant_module (
     tenant_id,module_key,status,source,config_json,config_revision,authorization_revision,
     effective_at,enabled_at,created_at,updated_at
    ) VALUES (:tenant_id,'fixture.delivery-record','enabled','manual','{}',1,1,:now,:now,:now,:now)
    SQL);
                $guard();
                $write($enable, ['tenant_id' => (int) $member['tenant_id'], 'now' => $now]);
            }
            try {
                $record($phase);
                throw new RuntimeException("{$phase} write was accepted");
            } catch (ModuleException $exception) {
                $expectedCode = $phase === 'not-enabled'
                    ? 'MODULE_TENANT_DISABLED'
                    : 'AUTHORIZATION_PERMISSION_DENIED';
                pluginLifecycleExpect($exception->errorCode === $expectedCode, "{$phase} refusal changed");
            }
        }
        $permissionId = (int) $pdo->query("SELECT id FROM pa_permission WHERE `key`='fixture.delivery-record.create'")->fetchColumn();
        $grant = $pdo->prepare(<<<'SQL'
    INSERT INTO pa_role_permission (tenant_id,role_id,permission_id,granted_by_member_id,granted_at)
    VALUES (:tenant_id,:role_id,:permission_id,:member_id,:now)
    SQL);
        $guard();
        $write($grant, [
            'tenant_id' => (int) $member['tenant_id'],
            'role_id' => (int) $member['role_id'],
            'permission_id' => $permissionId,
            'member_id' => (int) $member['member_id'],
            'now' => gmdate('Y-m-d H:i:s.v'),
        ]);
        pluginLifecycleExpect($record('authorized')['status'] === 'recorded', 'authorized write failed');
        try {
            $guard();
            $service->uninstall('fixture.delivery-record');
            throw new RuntimeException('uninstall ignored an enabled TenantModule');
        } catch (PluginLifecycleException $exception) {
            pluginLifecycleExpect($exception->errorCode === 'PLUGIN_TENANT_MODULE_ACTIVE', 'uninstall refusal code changed');
        }
        $guard();
        $write("UPDATE pa_tenant_module SET status='disabled',disabled_at=UTC_TIMESTAMP(3),disabled_reason='fixture-test' WHERE module_key='fixture.delivery-record'");
        try {
            $record('disabled-after-grant');
            throw new RuntimeException('disabled Module accepted a command after permission grant');
        } catch (ModuleException $exception) {
            pluginLifecycleExpect(
                $exception->errorCode === 'MODULE_TENANT_DISABLED',
                'disabled Module command refusal changed',
            );
        }
        $guard();
        pluginLifecycleExpect(($service->uninstall('fixture.delivery-record')['preserve_data'] ?? null) === true, 'uninstall did not preserve data');
        pluginLifecycleExpect((int) $pdo->query('SELECT COUNT(*) FROM pa_fixture_delivery_record')->fetchColumn() === 1, 'uninstall removed business data');

        $guard();
        $artifact = pluginLifecycleFailureArtifact($projectRoot, $artifactDirectory);
        $failureResolver = new PluginLockResolver($serverRoot, $artifact['lock']);
        $failureService = new PluginLifecycleService(
            $failureResolver,
            new PluginModuleRegistryFactory($serverRoot),
            $config + ['plugin_lock' => $artifact['lock']],
            $catalogs,
            new \PeanutAdmin\Modules\Identity\Contract\TenantModuleStateQueries(),
        );
        try {
            $guard();
            $failureService->install('fixture.delivery-record');
            throw new RuntimeException('failing Module migration activated the Plugin');
        } catch (Throwable) {
            $state = $pdo->query("SELECT status FROM pa_plugin_installation WHERE plugin_key='fixture.delivery-record'")->fetchColumn();
            pluginLifecycleExpect($state === 'failed', 'failed migration left Plugin active');
            $moduleState = $pdo->query("SELECT status FROM pa_module_installation WHERE module_key='fixture.delivery-record'")->fetchColumn();
            pluginLifecycleExpect($moduleState === 'failed', 'failed migration left Module active');
        }
        try {
            $guard();
            $failureService->install('fixture.delivery-record');
            throw new RuntimeException('uncertain failed migration was replayed');
        } catch (PluginLifecycleException $exception) {
            pluginLifecycleExpect(
                $exception->errorCode === 'MODULE_MIGRATION_REPAIR_REQUIRED',
                'failed migration retry did not require an append-only repair',
            );
        }
        $guard();
        $repairArtifact = pluginLifecycleRepairArtifact($projectRoot, $artifactDirectory);
        $repairResolver = new PluginLockResolver($serverRoot, $repairArtifact['lock']);
        $repairService = new PluginLifecycleService(
            $repairResolver,
            new PluginModuleRegistryFactory($serverRoot),
            $config + ['plugin_lock' => $repairArtifact['lock']],
            $catalogs,
            new \PeanutAdmin\Modules\Identity\Contract\TenantModuleStateQueries(),
        );
        $guard();
        $recovered = $repairService->install('fixture.delivery-record');
        pluginLifecycleExpect(($recovered['operation'] ?? null) === 'recovered', 'higher repair package did not recover the install');
        pluginLifecycleExpect(
            (string) $pdo->query("SELECT status FROM pa_module_migration WHERE migration_key='fixture.delivery-record:20260814999999_failure_fixture'")->fetchColumn() === 'failed',
            'repair rewrote the immutable failed migration ledger row',
        );
        pluginLifecycleExpect(
            (string) $pdo->query("SELECT status FROM pa_module_migration WHERE migration_key='fixture.delivery-record:20260815000000_failure_repair'")->fetchColumn() === 'applied',
            'append-only repair migration was not applied',
        );
        pluginLifecycleExpect(
            (string) $pdo->query("SELECT status FROM pa_plugin_installation WHERE plugin_key='fixture.delivery-record'")->fetchColumn() === 'active',
            'repair package did not finalize Plugin activation',
        );
        pluginLifecycleExpect(pluginLifecycleCatalogSnapshot($pdo) === $catalogSnapshot, 'installed non-fixture catalogs changed');
        // Only successful qualification removes its two proven-new source inputs.
        // Their exact bytes remain beside the original lock/manifest in the protected backup.
        // FAIL/UNKNOWN never reaches this block and retains the source scene in place.
        foreach ([$artifact, $repairArtifact] as $generated) {
            $migration = $generated['migration'];
            $digest = hash_file('sha256', $migration);
            $retained = $artifactDirectory . '/' . basename($migration);
            pluginLifecycleExpect(!is_link($migration) && is_file($migration) && !file_exists($retained)
                && copy($migration, $retained) && chmod($retained, 0600)
                && hash_file('sha256', $retained) === $digest && hash_file('sha256', $migration) === $digest,
                'generated source could not be preserved; keep actual source');
            foreach ([$retained, $artifactDirectory] as $path) {
                $stream = fopen($path, 'r');
                pluginLifecycleExpect(is_resource($stream), 'retained source sync handle is unavailable');
                try { pluginLifecycleExpect(fsync($stream), 'retained source sync failed; keep actual source'); }
                finally { fclose($stream); }
            }
            $guard();
            pluginLifecycleExpect(unlink($migration), 'generated source removal failed; reconcile retained copy and actual source');
        }
        echo "PLUGIN-MODULE-LIFECYCLE-DB-001 passed\n";
    } finally {
        // Retain actual ledgers, rows and immutable failure/repair source and lock inputs.
        echo json_encode([
            'cleanup' => 'not_performed; retain actual scene',
            'module_key' => 'fixture.delivery-record',
            'failure_artifact' => $artifact,
            'repair_artifact' => $repairArtifact,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES) . "\n";
    }
}

require_once dirname(__DIR__, 2) . '/app/common/services/upgrade/SourceApplicationBackup.php';
exit(app\common\services\upgrade\SourceApplicationBackup::qualificationEntry('plugin-module-lifecycle', $argv));
