<?php

declare(strict_types=1);

namespace tests\Unit;

use app\common\execution\CurrentExecutionContext;
use app\common\execution\ExecutionContextStore;
use DateTimeImmutable;
use PDO;
use PeanutAdmin\Kernel\Auth\TenantContext;
use PeanutAdmin\Kernel\Auth\ValidatedTenantSession;
use PeanutAdmin\Modules\Identity\Contract\AdminDirectoryQuery;
use PeanutAdmin\Modules\ImportExport\Infrastructure\configuration\ExternalBindingConfigurationAdapter;
use PeanutAdmin\Modules\Integration\Contract\ExternalBindingTransfer;
use PeanutAdmin\Modules\Integration\Contract\ExternalTenantResolutionException;
use PeanutAdmin\Modules\Integration\ModuleProvider;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use RuntimeException;
use think\App;
use think\facade\Db;

/** Native container and ORM with synthetic in-memory state, not MySQL concurrency or HTTP authorization. */
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class ExternalBindingTransferBoundaryTest extends TestCase
{
    private PDO $database;
    private App $app;
    private ExternalBindingConfigurationAdapter $adapter;

    protected function setUp(): void
    {
        $root = dirname(__DIR__, 3);
        $temporary = $root . '/.local/tmp/external-binding-transfer-tests';
        if (!is_dir($temporary) && !mkdir($temporary, 0700, true) && !is_dir($temporary)) {
            throw new RuntimeException('BINDING_TRANSFER_TEST_DIRECTORY_UNAVAILABLE');
        }
        self::assertStringStartsWith($root . '/.local/tmp/', (string) realpath($temporary));
        require_once $root . '/server/vendor/topthink/framework/src/helper.php';
        $this->app = new App($temporary . '/case-' . bin2hex(random_bytes(6)));
        $this->database = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        \ThinkPhpTestConnection::fromPdo($this->database);
        $this->database->exec(<<<'SQL'
            CREATE TABLE pa_tenant (id INTEGER PRIMARY KEY, code TEXT, status TEXT);
            CREATE TABLE pa_external_channel_binding (id INTEGER PRIMARY KEY AUTOINCREMENT, tenant_id INTEGER, provider TEXT, callback_key TEXT, identity_hash TEXT, identity_hint TEXT, config_json TEXT, status INTEGER, create_time INTEGER, update_time INTEGER, UNIQUE(tenant_id, provider));
            INSERT INTO pa_tenant VALUES (1, 'alpha', 'active'), (2, 'beta', 'active');
            SQL);
        $this->app->instance(AdminDirectoryQuery::class, new AdminDirectoryQuery(new CurrentExecutionContext(new ExecutionContextStore())));
        $this->app->bind((new ModuleProvider())->bindings());
        $this->adapter = $this->app->make(ExternalBindingConfigurationAdapter::class);
    }

    private function actor(int $tenantId = 1): TenantContext
    {
        return TenantContext::fromValidatedSession(new ValidatedTenantSession(
            $tenantId, 'synthetic-session', $tenantId, 101, 201, 'admin-web', new DateTimeImmutable('2099-01-01T00:00:00Z'), 3,
        ), 'synthetic-binding-transfer');
    }

    private function value(string $appId = 'synthetic-app'): array
    {
        return ['identity_hash' => hash('sha256', $appId), 'identity_hint' => 'synthetic', 'config' => ['app_id' => $appId, 'api_secret' => 'synthetic-test-secret'], 'status' => true];
    }

    private function apply(array $value, ?int $revision = null, int $tenantId = 1): void
    {
        Db::transaction(fn() => $this->adapter->apply($this->actor($tenantId), 'payment.wechat', $value, [], $revision));
    }

    private function rows(): array
    {
        return $this->database->query('SELECT * FROM pa_external_channel_binding ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
    }

    public function testAdapterUsesTheExportedIntegrationCapabilityAndNoPersistence(): void
    {
        $constructor = (new ReflectionClass($this->adapter))->getConstructor();
        self::assertNotNull($constructor);
        self::assertSame(ExternalBindingTransfer::class, $constructor->getParameters()[0]->getType()?->getName());
        $source = (string) file_get_contents((new ReflectionClass($this->adapter))->getFileName());
        self::assertStringNotContainsString('Db::', $source);
        self::assertStringNotContainsString('external_channel_binding', $source);
        $root = dirname(__DIR__, 3);
        $manifest = json_decode((string) file_get_contents($root . '/server/app/modules/official/integration/module.json'), true, 512, JSON_THROW_ON_ERROR);
        self::assertContains(ExternalBindingTransfer::class, $manifest['contracts']['exports']);
        self::assertInstanceOf(ExternalBindingTransfer::class, $this->app->make(ExternalBindingTransfer::class));
    }

    public function testExportKeepsTenantScopeAndOnlySecretReferences(): void
    {
        $this->apply($this->value('alpha'));
        $this->apply($this->value('beta'), tenantId: 2);
        $entries = $this->adapter->export($this->actor());
        self::assertCount(1, $entries);
        self::assertSame('alpha', $entries[0]['value']['config']['app_id']);
        self::assertArrayHasKey('$secret', $entries[0]['value']['config']['api_secret']);
        $encoded = json_encode($entries, JSON_THROW_ON_ERROR);
        self::assertStringNotContainsString('synthetic-test-secret', $encoded);
        self::assertStringNotContainsString('callback_key', $encoded);
        self::assertStringNotContainsString($this->rows()[0]['callback_key'], $encoded);
    }

    public function testUpdateKeepsCallbackCreationTimeAndOtherTenantUnchanged(): void
    {
        $this->apply($this->value('alpha'));
        $this->apply($this->value('beta'), tenantId: 2);
        $before = $this->rows();
        $revision = $this->adapter->current($this->actor(), 'payment.wechat')['revision'];
        $this->apply($this->value('changed'), $revision);
        $after = $this->rows();
        self::assertSame($before[0]['callback_key'], $after[0]['callback_key']);
        self::assertSame($before[0]['create_time'], $after[0]['create_time']);
        self::assertSame($before[1], $after[1]);
        self::assertSame('changed', json_decode($after[0]['config_json'], true)['app_id']);
    }

    public function testSameSecondMutationInvalidatesTheOriginalRevision(): void
    {
        $this->apply($this->value());
        $revision = $this->adapter->current($this->actor(), 'payment.wechat')['revision'];
        $this->database->exec("UPDATE pa_external_channel_binding SET identity_hint = 'other' WHERE tenant_id = 1");
        self::assertNotSame($revision, $this->adapter->current($this->actor(), 'payment.wechat')['revision']);
        $this->expectExceptionMessage('TRANSFER_CONFLICT');
        $this->apply($this->value('replacement'), $revision);
    }

    public function testExpectedExistingStateCannotRecreateAMissingTarget(): void
    {
        $this->apply($this->value());
        $revision = $this->adapter->current($this->actor(), 'payment.wechat')['revision'];
        $this->database->exec('DELETE FROM pa_external_channel_binding WHERE tenant_id = 1');
        $this->expectExceptionMessage('TRANSFER_CONFLICT');
        $this->apply($this->value(), $revision);
    }

    public function testCreateCannotOverwriteAnExistingBinding(): void
    {
        $this->apply($this->value());
        $this->expectExceptionMessage('TRANSFER_CONFLICT');
        $this->apply($this->value('other'));
    }

    public function testSuspendedTenantCannotImportAndOuterRollbackIsPreserved(): void
    {
        $this->apply($this->value());
        $before = $this->rows();
        $revision = $this->adapter->current($this->actor(), 'payment.wechat')['revision'];
        try {
            Db::transaction(function () use ($revision): void {
                $this->adapter->apply($this->actor(), 'payment.wechat', $this->value('other'), [], $revision);
                throw new RuntimeException('synthetic outer failure');
            });
        } catch (RuntimeException $exception) {
            self::assertSame('synthetic outer failure', $exception->getMessage());
        }
        self::assertSame($before, $this->rows());
        $this->database->exec("UPDATE pa_tenant SET status = 'suspended' WHERE id = 1");
        $this->expectException(ExternalTenantResolutionException::class);
        $this->apply($this->value(), $revision);
    }

    public static function invalidValues(): array
    {
        return [
            'unknown field' => ['extra', true],
            'wrong enabled type' => ['status', 1],
            'missing enabled identity' => ['identity_hash', null],
            'malformed identity' => ['identity_hash', 'invalid'],
            'oversized hint' => ['identity_hint', '123456789012345678901234567890123'],
            'wrong config type' => ['config', 'invalid'],
        ];
    }

    #[DataProvider('invalidValues')]
    public function testInvalidValuesNeverReachPersistence(string $key, mixed $value): void
    {
        $input = $this->value();
        $input[$key] = $value;
        try {
            $this->apply($input);
            self::fail('Invalid transfer accepted');
        } catch (RuntimeException $exception) {
            self::assertSame('TRANSFER_EXTERNAL_BINDING_INVALID', $exception->getMessage());
            self::assertSame([], $this->rows());
        }
    }

    public function testCorruptStoredJsonFailsInsteadOfExportingAnEmptyConfiguration(): void
    {
        $this->apply($this->value());
        $this->database->exec("UPDATE pa_external_channel_binding SET config_json = '{broken' WHERE tenant_id = 1");
        $this->expectExceptionMessage('TRANSFER_EXTERNAL_BINDING_INVALID');
        $this->adapter->export($this->actor());
    }
}
