<?php

declare(strict_types=1);

use PeanutAdmin\Kernel\Module\ManifestDocument;
use PeanutAdmin\Kernel\Module\ModuleException;
use PeanutAdmin\Modules\Settings\Application\SettingException;
use PeanutAdmin\Modules\Settings\Definition\SettingDefinitionSynchronizer;
use PeanutAdmin\Modules\Settings\Service\SettingCatalogService;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;
use think\App;
use think\facade\Db;

/** Actual loader/validator/synchronizer with test-owned SQLite; no deployment or secret operations. */
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class SettingCatalogBoundaryTest extends TestCase
{
    private PDO $database;
    private SettingCatalogService $catalog;
    private string $directory;

    protected function setUp(): void
    {
        $root = dirname(__DIR__, 3);
        require_once $root . '/server/vendor/topthink/framework/src/helper.php';
        $this->directory = $root . '/.local/tmp/setting-catalog/' . bin2hex(random_bytes(6));
        mkdir($this->directory, 0700, true);
        new App($this->directory);
        $this->database = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        ThinkPhpTestConnection::fromPdo($this->database);
        $this->database->exec(<<<'SQL'
            CREATE TABLE pa_setting_definition (
                id INTEGER PRIMARY KEY AUTOINCREMENT, module_key TEXT NOT NULL, setting_key TEXT NOT NULL,
                name TEXT NOT NULL, description TEXT NOT NULL, schema_json TEXT NOT NULL,
                required_flag INTEGER NOT NULL, secret_flag INTEGER NOT NULL, deployment_scope_flag INTEGER NOT NULL,
                tenant_scope_flag INTEGER NOT NULL, target_scope_flag INTEGER NOT NULL, target_resource_key TEXT,
                target_operation TEXT, default_json TEXT, definition_digest TEXT NOT NULL,
                status TEXT NOT NULL, revision INTEGER NOT NULL, created_at TEXT NOT NULL, updated_at TEXT NOT NULL,
                UNIQUE(module_key,setting_key)
            );
            CREATE TABLE pa_setting_tenant_value (id INTEGER PRIMARY KEY, tenant_id INTEGER, definition_id INTEGER, value_json TEXT);
            INSERT INTO pa_setting_tenant_value VALUES (1, 101, 1, '"tenant choice"');
            SQL);
        $this->catalog = new SettingCatalogService(new SettingDefinitionSynchronizer());
    }

    public function testHostUsesPublishedCatalogWithoutReadingSettingsTables(): void
    {
        $root = dirname(__DIR__, 3);
        $manifest = json_decode(file_get_contents($root . '/server/app/modules/official/settings/module.json'), true, 512, JSON_THROW_ON_ERROR);
        self::assertContains(SettingCatalogService::class, $manifest['contracts']['exports']);
        $source = file_get_contents($root . '/server/app/platform/infrastructure/plugin/ModuleCatalogApplier.php');
        foreach (['SettingDefinitionSynchronizer', 'SettingDefinitionRegistry', 'SettingDefinitionLoader', "Db::name('setting_definition')", "'settings' => 'pa_setting_definition'"] as $internal) {
            self::assertStringNotContainsString($internal, $source);
        }
        self::assertStringContainsString('$this->settings->synchronize($selected, $now)', $source);
        $host = ThinkPhpTestConnection::moduleCatalogs($this->database);
        self::assertInstanceOf(SettingCatalogService::class, (new ReflectionProperty($host, 'settings'))->getValue($host));
    }

    public function testSelectedDefinitionsRetireAndReactivateWithoutChangingTenantValues(): void
    {
        $alpha = $this->manifest('fixture.alpha', [$this->definition('theme')]);
        $beta = $this->manifest('fixture.beta', [$this->definition('theme')]);
        $values = $this->tenantValues();
        $this->catalog->synchronize(['fixture.alpha' => $alpha, 'fixture.beta' => $beta], $this->now());
        self::assertSame(2, $this->catalog->activeCount(['fixture.alpha', 'fixture.beta']));
        $before = $this->catalog->revisionRows();
        self::assertSame(['id', 'module_key', 'setting_key', 'status', 'revision', 'definition_digest'], array_keys($before[0]));
        $this->catalog->synchronize(['fixture.alpha' => ManifestDocument::fromArray($alpha->root, ['key' => 'fixture.alpha'])], $this->now());
        $retired = $this->catalog->revisionRows();
        self::assertSame('retired', $retired[0]['status']);
        self::assertSame(2, (int) $retired[0]['revision']);
        self::assertSame($before[1], $retired[1]);
        self::assertSame(0, $this->catalog->activeCount(['fixture.alpha']));
        $this->catalog->synchronize(['fixture.alpha' => $alpha], $this->now());
        self::assertSame(3, (int) $this->catalog->revisionRows()[0]['revision']);
        self::assertSame($values, $this->tenantValues());
    }

    public function testUnchangedContributionIsIdempotentAndChangedDefinitionAdvancesRevision(): void
    {
        $definition = $this->definition('theme');
        $manifest = $this->manifest('fixture.alpha', [$definition]);
        $this->catalog->synchronize(['fixture.alpha' => $manifest], $this->now());
        $before = $this->database->query('SELECT * FROM pa_setting_definition')->fetchAll(PDO::FETCH_ASSOC);
        $this->catalog->synchronize(['fixture.alpha' => $manifest], $this->now()->modify('+1 hour'));
        self::assertSame($before, $this->database->query('SELECT * FROM pa_setting_definition')->fetchAll(PDO::FETCH_ASSOC));
        $definition['name'] = 'Changed theme';
        file_put_contents($manifest->root . '/settings.json', json_encode([$definition], JSON_THROW_ON_ERROR));
        $this->catalog->synchronize(['fixture.alpha' => $manifest], $this->now()->modify('+2 hours'));
        $after = $this->database->query('SELECT * FROM pa_setting_definition')->fetch(PDO::FETCH_ASSOC);
        self::assertSame(2, (int) $after['revision']);
        self::assertSame('Changed theme', $after['name']);
        self::assertSame($before[0]['created_at'], $after['created_at']);
        self::assertNotSame($before[0]['definition_digest'], $after['definition_digest']);
    }

    public function testInvalidDefaultAndSecretDefaultRemainRejectedBeforeAnyWrite(): void
    {
        foreach (['default' => ['default' => ['not a string']], 'secret' => ['secret' => true]] as $label => $changes) {
            $definition = array_replace($this->definition('theme'), $changes);
            $manifest = $this->manifest('fixture.' . $label, [$definition]);
            try {
                $this->catalog->synchronize(['fixture.' . $label => $manifest], $this->now());
                self::fail('Invalid definition reached storage.');
            } catch (SettingException $exception) {
                self::assertContains($exception->errorCode, ['SETTING_DEFAULT_INVALID', 'SETTING_SECRET_DEFINITION_INVALID']);
            }
        }
        self::assertSame([], $this->catalog->revisionRows());
        self::assertCount(1, $this->tenantValues());
    }

    public function testInvalidOwnerAndResourceEscapeDoNotWriteCatalogs(): void
    {
        foreach ([
            ManifestDocument::fromArray($this->directory, ['key' => 'fixture.beta']),
            ManifestDocument::fromArray($this->directory, ['key' => 'fixture.alpha', 'backend' => ['setting_definitions' => '../outside.json']]),
        ] as $manifest) {
            try {
                $this->catalog->synchronize(['fixture.alpha' => $manifest], $this->now());
                self::fail('Invalid Module contribution was accepted.');
            } catch (ModuleException $exception) {
                self::assertContains($exception->errorCode, ['MODULE_SETTING_OWNER_MISMATCH', 'MODULE_SETTING_RESOURCE_INVALID']);
            }
        }
        self::assertSame([], $this->catalog->revisionRows());
    }

    public function testEnclosingTransactionRollsBackDefinitionWrites(): void
    {
        $manifest = $this->manifest('fixture.alpha', [$this->definition('theme')]);
        $values = $this->tenantValues();
        try {
            Db::transaction(function () use ($manifest): void {
                $this->catalog->synchronize(['fixture.alpha' => $manifest], $this->now());
                self::assertSame(1, $this->catalog->activeCount(['fixture.alpha']));
                throw new DomainException('FIXTURE_OUTER_FAILURE');
            });
            self::fail('The enclosing transaction did not fail.');
        } catch (DomainException $exception) {
            self::assertSame('FIXTURE_OUTER_FAILURE', $exception->getMessage());
        }
        self::assertSame([], $this->catalog->revisionRows());
        self::assertSame($values, $this->tenantValues());
    }

    public function testRealHostFingerprintRetainsItsFixedSettingMetadata(): void
    {
        $this->database->sqliteCreateFunction('DATE_FORMAT', static fn($value, $format) => $value, 2);
        $this->database->exec(<<<'SQL'
            CREATE TABLE pa_permission (id INTEGER PRIMARY KEY, "key" TEXT, module_key TEXT, status TEXT, retired_at TEXT);
            CREATE TABLE pa_menu_definition (id INTEGER PRIMARY KEY, "key" TEXT, module_key TEXT, status TEXT, manifest_digest TEXT);
            SQL);
        $host = ThinkPhpTestConnection::moduleCatalogs($this->database);
        $before = $host->catalogRevision();
        $manifest = $this->manifest('fixture.alpha', [$this->definition('theme')]);
        $this->catalog->synchronize(['fixture.alpha' => $manifest], $this->now());
        $rows = ['pa_permission' => [], 'pa_menu_definition' => [], 'pa_setting_definition' => $this->catalog->revisionRows(), 'pa_reference_code_set' => []];
        self::assertSame(hash('sha256', json_encode($rows, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)), $host->catalogRevision());
        self::assertNotSame($before, $host->catalogRevision());
        self::assertSame(['menus' => 0, 'permissions' => 0, 'settings' => 1, 'reference_codes' => 0], (new ReflectionMethod($host, 'activeCounts'))->invoke($host, ['fixture.alpha']));
    }

    private function definition(string $key): array
    {
        return ['key' => $key, 'name' => 'Theme', 'description' => 'Fixture setting',
            'schema' => ['$schema' => 'https://json-schema.org/draft/2020-12/schema', 'type' => 'string'],
            'required' => false, 'secret' => false, 'allowed_scopes' => ['tenant'],
            'target_resource_key' => null, 'target_operation' => null, 'default' => 'light'];
    }

    private function manifest(string $key, array $definitions): ManifestDocument
    {
        $directory = $this->directory . '/' . $key;
        mkdir($directory, 0700, true);
        file_put_contents($directory . '/settings.json', json_encode($definitions, JSON_THROW_ON_ERROR));
        return ManifestDocument::fromArray($directory, ['key' => $key, 'backend' => ['setting_definitions' => 'settings.json']]);
    }

    private function tenantValues(): array
    {
        return $this->database->query('SELECT * FROM pa_setting_tenant_value ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
    }

    private function now(): DateTimeImmutable
    {
        return new DateTimeImmutable('2031-01-01T00:00:00.000Z');
    }
}
