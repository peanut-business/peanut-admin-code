<?php

declare(strict_types=1);

namespace tests\Unit;

use DateTimeImmutable;
use PDO;
use PeanutAdmin\Kernel\Auth\TenantContext;
use PeanutAdmin\Kernel\Auth\ValidatedTenantSession;
use PeanutAdmin\Modules\ImportExport\Infrastructure\configuration\TenantSettingsConfigurationAdapter;
use PeanutAdmin\Modules\Settings\Contract\TenantSettingsTransfer;
use PeanutAdmin\Modules\Settings\ModuleProvider;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;
use think\App;
use think\facade\Db;

/** Real ThinkORM on isolated in-memory tables; not MySQL locking or HTTP authorization proof. */
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class TenantSettingsTransferBoundaryTest extends TestCase
{
    private PDO $database;
    private App $app;
    private TenantSettingsConfigurationAdapter $adapter;

    protected function setUp(): void
    {
        $root = dirname(__DIR__, 3);
        $temporaryRoot = $root . '/.local/tmp/tenant-settings-transfer-tests';
        if (!is_dir($temporaryRoot) && !mkdir($temporaryRoot, 0700, true) && !is_dir($temporaryRoot)) {
            throw new \RuntimeException('TRANSFER_TEST_DIRECTORY_UNAVAILABLE');
        }
        $temporary = realpath($temporaryRoot);
        self::assertIsString($temporary);
        self::assertStringStartsWith($root . '/.local/tmp/', $temporary);
        require_once $root . '/server/vendor/topthink/framework/src/helper.php';
        $this->app = new App($temporary . '/case-' . bin2hex(random_bytes(6)));
        $this->database = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        \ThinkPhpTestConnection::fromPdo($this->database);
        $this->database->exec(<<<'SQL'
            CREATE TABLE pa_tenant_setting (
                id INTEGER PRIMARY KEY AUTOINCREMENT, tenant_id INTEGER NOT NULL,
                namespace TEXT NOT NULL, config_json TEXT NOT NULL, revision INTEGER NOT NULL,
                create_time INTEGER NOT NULL, update_time INTEGER NOT NULL,
                UNIQUE(tenant_id, namespace)
            );
            INSERT INTO pa_tenant_setting VALUES
                (1, 1, 'website', '{"title":"first","password":"synthetic-secret-a"}', 3, 100, 100),
                (2, 2, 'website', '{"title":"second","password":"synthetic-secret-b"}', 9, 200, 200);
            SQL);
        $this->app->bind((new ModuleProvider())->bindings());
        $this->adapter = $this->app->make(TenantSettingsConfigurationAdapter::class);
        self::assertStringStartsWith($root . '/server/app/', (new \ReflectionClass($this->adapter))->getFileName());
    }

    private function actor(int $tenantId = 1): TenantContext
    {
        return TenantContext::fromValidatedSession(new ValidatedTenantSession(
            $tenantId, 'synthetic-session-' . $tenantId, $tenantId, 100 + $tenantId,
            200 + $tenantId, 'admin-web', new DateTimeImmutable('2099-01-01T00:00:00Z'), 3,
        ), 'settings-transfer-test');
    }

    public function testAdapterCannotAccessSettingsPersistenceDirectly(): void
    {
        $source = file_get_contents((new \ReflectionClass($this->adapter))->getFileName());
        self::assertIsString($source);
        self::assertStringNotContainsString('think\\facade\\Db', $source);
        self::assertStringNotContainsString('Db::', $source);
        self::assertStringContainsString('Settings\\Contract\\TenantSettingsTransfer', $source);
        $constructor = (new \ReflectionClass($this->adapter))->getConstructor();
        self::assertNotNull($constructor);
        self::assertSame(TenantSettingsTransfer::class, (string) $constructor->getParameters()[0]->getType());
    }

    public function testOwnerPublishesAndBindsOnlyTheBusinessTransferContract(): void
    {
        $bindings = (new ModuleProvider())->bindings();
        self::assertArrayHasKey(TenantSettingsTransfer::class, $bindings);
        $owner = $this->app->make(TenantSettingsTransfer::class);
        self::assertInstanceOf(TenantSettingsTransfer::class, $owner);
        self::assertStringContainsString('/modules/official/settings/src/', (new \ReflectionClass($owner))->getFileName());
        $manifest = json_decode(file_get_contents(dirname(__DIR__, 3) . '/server/app/modules/official/settings/module.json'), true, 512, JSON_THROW_ON_ERROR);
        self::assertContains(TenantSettingsTransfer::class, $manifest['contracts']['exports']);
        self::assertNotContains(get_class($owner), $manifest['contracts']['exports']);
    }

    public function testCurrentPreservesExistenceRevisionAndSecretRedaction(): void
    {
        $current = $this->adapter->current($this->actor(), 'website');
        self::assertTrue($current['exists']);
        self::assertSame(3, $current['revision']);
        self::assertSame('first', $current['value']['title']);
        self::assertSame('configured', $current['value']['password']['$secret']['state']);
        self::assertStringNotContainsString('synthetic-secret-a', json_encode($current, JSON_THROW_ON_ERROR));
        self::assertSame(['exists' => false, 'value' => null, 'revision' => null], $this->adapter->current($this->actor(), 'missing'));
    }

    public function testExportIsOrderedRedactedAndNeverIncludesAnotherTenant(): void
    {
        $this->database->exec("INSERT INTO pa_tenant_setting VALUES (3, 1, 'agreement', '{\"text\":\"a\"}', 1, 100, 100)");
        $entries = $this->adapter->export($this->actor());
        self::assertSame(['agreement', 'website'], array_column($entries, 'key'));
        $encoded = json_encode($entries, JSON_THROW_ON_ERROR);
        self::assertStringNotContainsString('synthetic-secret-', $encoded);
        self::assertStringNotContainsString('second', $encoded);
        self::assertStringNotContainsString('tenant_id', $encoded);
        self::assertCount(1, $entries[1]['secrets']);
    }

    public function testCreateAndConditionalReplacePreserveTenantAndCreationTime(): void
    {
        $this->adapter->apply($this->actor(), 'new-setting', ['enabled' => true], [], null);
        self::assertSame(1, $this->adapter->current($this->actor(), 'new-setting')['revision']);
        $this->adapter->apply($this->actor(), 'website', ['title' => 'changed'], [], 3);
        self::assertSame(4, $this->adapter->current($this->actor(), 'website')['revision']);
        self::assertSame('changed', $this->adapter->current($this->actor(), 'website')['value']['title']);
        self::assertSame(100, (int) $this->database->query('SELECT create_time FROM pa_tenant_setting WHERE id = 1')->fetchColumn());
        self::assertSame(9, $this->adapter->current($this->actor(2), 'website')['revision']);
        self::assertSame('second', $this->adapter->current($this->actor(2), 'website')['value']['title']);
    }

    public static function conflicts(): array
    {
        return [
            'stale revision' => ['website', 2],
            'create collided with existing row' => ['website', null],
            'target disappeared after planning' => ['missing', 3],
        ];
    }

    #[DataProvider('conflicts')]
    public function testConflictsNeverOverwriteOrCreate(string $namespace, ?int $revision): void
    {
        try {
            $this->adapter->apply($this->actor(), $namespace, ['title' => 'wrong'], [], $revision);
            self::fail('Expected revision conflict');
        } catch (\RuntimeException $exception) {
            self::assertSame('TRANSFER_CONFLICT', $exception->getMessage());
        }
        self::assertSame(3, $this->adapter->current($this->actor(), 'website')['revision']);
        self::assertSame('first', $this->adapter->current($this->actor(), 'website')['value']['title']);
        self::assertSame(2, (int) $this->database->query('SELECT COUNT(*) FROM pa_tenant_setting')->fetchColumn());
    }

    public function testOuterTransactionRollbackIncludesSuccessfulAdapterWrite(): void
    {
        try {
            Db::transaction(function (): void {
                $this->adapter->apply($this->actor(), 'website', ['title' => 'temporary'], [], 3);
                $this->adapter->apply($this->actor(), 'new-setting', ['enabled' => true], [], null);
                throw new \RuntimeException('SYNTHETIC_LATER_ADAPTER_FAILURE');
            });
            self::fail('Expected outer failure');
        } catch (\RuntimeException $exception) {
            self::assertSame('SYNTHETIC_LATER_ADAPTER_FAILURE', $exception->getMessage());
        }
        self::assertSame(3, $this->adapter->current($this->actor(), 'website')['revision']);
        self::assertFalse($this->adapter->current($this->actor(), 'new-setting')['exists']);
    }

    public static function invalidInputs(): array
    {
        return [
            'invalid namespace' => ['../other', ['title' => 'bad']],
            'wrong value type' => ['website', 'bad'],
            'unencodable number' => ['website', ['value' => INF]],
        ];
    }

    #[DataProvider('invalidInputs')]
    public function testInvalidInputsAreRejectedBeforeWriting(string $namespace, mixed $document): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('TRANSFER_TENANT_SETTING_INVALID');
        try {
            $this->adapter->apply($this->actor(), $namespace, $document, [], 3);
        } finally {
            self::assertSame(3, (int) $this->database->query('SELECT revision FROM pa_tenant_setting WHERE id = 1')->fetchColumn());
        }
    }

    public static function corruptDocuments(): array
    {
        return [['not-json'], ['null'], ['42']];
    }

    #[DataProvider('corruptDocuments')]
    public function testInvalidStoredDocumentsAreNotSilentlyConvertedToEmptySettings(string $document): void
    {
        $statement = $this->database->prepare('UPDATE pa_tenant_setting SET config_json = ? WHERE id = 1');
        $statement->execute([$document]);
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('TRANSFER_TENANT_SETTING_INVALID');
        $this->adapter->current($this->actor(), 'website');
    }
}
