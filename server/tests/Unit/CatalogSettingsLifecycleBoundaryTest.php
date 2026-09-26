<?php

declare(strict_types=1);

use app\platform\infrastructure\plugin\ModuleCatalogMutationRepository;
use PeanutAdmin\Modules\Settings\Definition\SettingDefinitionSynchronizer;
use PeanutAdmin\Modules\Settings\Service\SettingCatalogService;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;
use think\App;
use think\facade\Db;

/** In-memory lifecycle fixtures only: no real installation, tenant values or deployment resources. */
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class CatalogSettingsLifecycleBoundaryTest extends TestCase
{
    private PDO $database;
    private SettingCatalogService $settings;
    private \app\platform\infrastructure\plugin\ModuleCatalogApplier $host;

    protected function setUp(): void
    {
        $root = dirname(__DIR__, 3);
        require_once $root . '/server/vendor/topthink/framework/src/helper.php';
        new App($root . '/.local/tmp/catalog-settings-lifecycle/' . bin2hex(random_bytes(6)));
        $this->database = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        ThinkPhpTestConnection::fromPdo($this->database);
        foreach ([
            'permission' => '"key" TEXT, module_key TEXT, status TEXT, retired_at TEXT, updated_at TEXT',
            'protected_resource' => '"key" TEXT, module_key TEXT, status TEXT, retired_at TEXT, updated_at TEXT',
            'target_type' => '"key" TEXT, module_key TEXT, status TEXT, updated_at TEXT',
            'data_condition_definition' => '"key" TEXT, module_key TEXT, status TEXT, updated_at TEXT',
            'resource_operation' => 'protected_resource_id INTEGER, operation TEXT, status TEXT, updated_at TEXT',
            'resource_operation_permission' => 'resource_operation_id INTEGER, permission_id INTEGER',
            'resource_operation_target_type' => 'resource_operation_id INTEGER, target_type_id INTEGER, status TEXT',
            'resource_operation_condition' => 'resource_operation_id INTEGER, condition_definition_id INTEGER, status TEXT',
            'menu_definition' => '"key" TEXT, module_key TEXT, required_permission_id INTEGER, status TEXT, updated_at TEXT',
            'role_permission' => 'tenant_id INTEGER, role_id INTEGER, permission_id INTEGER',
            'platform_role_permission' => 'platform_role_id INTEGER, permission_id INTEGER',
            'setting_definition' => 'module_key TEXT, setting_key TEXT, status TEXT, revision INTEGER, updated_at TEXT',
            'setting_deployment_value' => 'definition_id INTEGER, value_json TEXT',
            'setting_tenant_value' => 'tenant_id INTEGER, definition_id INTEGER, value_json TEXT',
            'setting_target_value' => 'tenant_id INTEGER, definition_id INTEGER, value_json TEXT',
        ] as $name => $columns) {
            $this->database->exec('CREATE TABLE pa_' . $name . ' (id INTEGER PRIMARY KEY,' . $columns . ')');
        }
        $this->database->exec(<<<'SQL'
            INSERT INTO pa_permission VALUES (7,'fixture.alpha.read','fixture.alpha','active',NULL,'old'),(8,'fixture.beta.read','fixture.beta','active',NULL,'old');
            INSERT INTO pa_role_permission VALUES (70,101,301,7),(80,202,302,8);
            INSERT INTO pa_setting_definition VALUES (11,'fixture.alpha','theme','active',3,'old'),(12,'fixture.beta','theme','active',5,'old');
            INSERT INTO pa_setting_deployment_value VALUES (21,11,'"alpha deployment"'),(22,12,'"beta deployment"');
            INSERT INTO pa_setting_tenant_value VALUES (31,101,11,'"alpha tenant"'),(32,202,12,'"beta tenant"');
            INSERT INTO pa_setting_target_value VALUES (41,101,11,'"alpha target"'),(42,202,12,'"beta target"');
            SQL);
        $this->settings = new SettingCatalogService(new SettingDefinitionSynchronizer());
        $this->host = ThinkPhpTestConnection::moduleCatalogs($this->database);
    }

    public function testMutationCoordinatorConsumesSettingsOwnerInsteadOfItsTables(): void
    {
        $constructor = (new ReflectionClass(ModuleCatalogMutationRepository::class))->getConstructor();
        self::assertNotNull($constructor);
        self::assertSame(SettingCatalogService::class, $constructor->getParameters()[0]->getType()->getName());
        $source = file_get_contents((new ReflectionClass(ModuleCatalogMutationRepository::class))->getFileName());
        foreach (["\$this->ids('pa_setting_definition'", "\$this->activeIds('pa_setting_definition'", "\$this->foreignIds('pa_setting_", "\$this->deleteByIds('pa_setting_", "\$this->updateByIds('pa_setting_"] as $access) {
            self::assertStringNotContainsString($access, $source);
        }
    }

    public function testPreviewPreservesExactSettingActionsAndDoesNotWrite(): void
    {
        $before = $this->snapshot();
        $soft = $this->host->plan(['fixture.alpha'], false);
        self::assertSame([$this->entry('pa_setting_definition', 'soft_retire', ['11'])], $this->settingEntries($soft['removed']));
        self::assertSame([
            $this->entry('pa_setting_deployment_value', 'preserve', ['21']),
            $this->entry('pa_setting_target_value', 'preserve', ['41']),
            $this->entry('pa_setting_tenant_value', 'preserve', ['31']),
        ], $this->settingEntries($soft['preserved']));
        $purge = $this->host->plan(['fixture.alpha'], true);
        self::assertSame([
            $this->entry('pa_setting_definition', 'delete', ['11']),
            $this->entry('pa_setting_deployment_value', 'delete', ['21']),
            $this->entry('pa_setting_target_value', 'delete', ['41']),
            $this->entry('pa_setting_tenant_value', 'delete', ['31']),
        ], $this->settingEntries($purge['removed']));
        self::assertSame([], $purge['preserved']);
        self::assertSame($before, $this->snapshot());
    }

    public function testRetirementPreservesAllValuesAndUnselectedDefinitions(): void
    {
        $before = $this->snapshot();
        $this->host->retire(['fixture.alpha']);
        $after = $this->snapshot();
        self::assertSame('retired', $after['setting_definition'][0]['status']);
        self::assertSame(4, $after['setting_definition'][0]['revision']);
        self::assertSame($before['setting_definition'][1], $after['setting_definition'][1]);
        foreach (['setting_deployment_value','setting_tenant_value','setting_target_value','role_permission'] as $name) {
            self::assertSame($before[$name], $after[$name]);
        }
        self::assertSame('retired', $after['permission'][0]['status']);
        self::assertSame($before['permission'][1], $after['permission'][1]);
    }

    public function testPurgeDeletesOnlySelectedDefinitionValuesAndBindings(): void
    {
        $before = $this->snapshot();
        $this->host->purge(['fixture.alpha']);
        foreach ($this->snapshot() as $name => $rows) {
            self::assertSame([$before[$name][1]], $rows, $name);
        }
    }

    public function testEmptySelectionAndOwnerMetadataDoNotExposeSettingValues(): void
    {
        $before = $this->snapshot();
        $this->host->retire([]);
        $this->host->purge([]);
        self::assertSame($before, $this->snapshot());
        self::assertSame(['definitions' => ['11'], 'active_definitions' => ['11'], 'deployment_values' => ['21'], 'tenant_values' => ['31'], 'target_values' => ['41']], $this->settings->lifecycleReferences(['fixture.alpha']));
        self::assertSame(['definitions' => [], 'active_definitions' => [], 'deployment_values' => [], 'tenant_values' => [], 'target_values' => []], $this->settings->lifecycleReferences([]));
    }

    public function testSettingsOnlyActiveModuleIsStillDiscovered(): void
    {
        $this->database->exec("UPDATE pa_permission SET status='retired'");
        $coordinator = new ModuleCatalogMutationRepository($this->settings);
        self::assertSame(['fixture.alpha','fixture.beta'], $coordinator->activeModuleKeys());
        $this->database->exec("INSERT INTO pa_setting_definition VALUES (13,'core','reserved','active',1,'old'),(14,'platform','reserved','active',1,'old')");
        self::assertSame(['fixture.alpha','fixture.beta'], $coordinator->activeModuleKeys());
    }

    public function testSettingsFailureRollsBackEarlierIdentityRetirement(): void
    {
        $before = $this->snapshot();
        $this->database->exec("CREATE TRIGGER fail_settings BEFORE UPDATE ON pa_setting_definition BEGIN SELECT RAISE(ABORT, 'FIXTURE_SETTINGS_FAILURE'); END");
        $failure = null;
        try {
            $this->host->retire(['fixture.alpha']);
        } catch (\think\db\exception\PDOException $exception) {
            $failure = $exception;
        }
        self::assertNotNull($failure);
        self::assertSame($before, $this->snapshot());
    }

    public function testOuterFailureRollsBackCompletePurge(): void
    {
        $before = $this->snapshot();
        try {
            Db::transaction(function (): void {
                $this->host->purge(['fixture.alpha']);
                self::assertCount(1, $this->snapshot()['setting_definition']);
                throw new DomainException('FIXTURE_OUTER_FAILURE');
            });
            self::fail('Outer failure was swallowed.');
        } catch (DomainException $exception) {
            self::assertSame('FIXTURE_OUTER_FAILURE', $exception->getMessage());
        }
        self::assertSame($before, $this->snapshot());
    }

    private function snapshot(): array
    {
        $result = [];
        foreach (['permission','role_permission','setting_definition','setting_deployment_value','setting_tenant_value','setting_target_value'] as $name) {
            $result[$name] = $this->database->query('SELECT * FROM pa_' . $name . ' ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
        }
        return $result;
    }

    private function settingEntries(array $entries): array
    {
        return array_values(array_filter($entries, static fn(array $entry): bool => str_starts_with($entry['table'], 'pa_setting_')));
    }

    private function entry(string $table, string $action, array $ids): array
    {
        return ['scope' => 'catalog', 'table' => $table, 'action' => $action, 'count' => count($ids), 'identifiers' => $ids];
    }
}
