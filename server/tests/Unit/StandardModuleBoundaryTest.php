<?php

declare(strict_types=1);

namespace tests\Unit;

use app\common\execution\AdminExecutionContext;
use app\common\execution\CurrentExecutionContext;
use app\common\execution\ExecutionContextStore;
use app\common\model\TenantOwnedModel;
use app\common\tenancy\MultiTenantDataScopePolicy;
use app\common\tenancy\PlatformTenantDataGateway;
use app\common\infrastructure\crontab\CrontabTenantLock;
use app\common\services\audit\AuditContractHost;
use PDO;
use PeanutAdmin\Kernel\Auth\TenantContext;
use PeanutAdmin\Kernel\Auth\ValidatedTenantSession;
use PeanutAdmin\Kernel\Tenancy\ThinkPhpTenantLockStore;
use PeanutAdmin\Modules\Identity\Contract\AdminDirectoryQuery;
use PeanutAdmin\Modules\Identity\Tenancy\DefaultTenantContextResolver;
use PeanutAdmin\Modules\Integration\Contract\ExternalTenantAudit;
use PeanutAdmin\Modules\Integration\Contract\ExternalTenantResolutionException;
use PeanutAdmin\Modules\Integration\Contract\ExternalTenantResolutionService;
use PeanutAdmin\Modules\Integration\Infrastructure\ThinkPhpExternalTenantBindingRepository;
use PeanutAdmin\Modules\Integration\Service\ExternalTenantResolver;
use PeanutAdmin\Modules\OAuth\Infrastructure\Persistence\ThinkPhpOAuthCallbackLocator;
use PeanutAdmin\Modules\Payment\Infrastructure\ThinkPhpPaymentChannelGrantCommands;
use PeanutAdmin\Modules\Payment\Service\RechargeAdministrationService;
use PeanutAdmin\Modules\Payment\Service\RefundApplicationService;
use PeanutAdmin\Modules\Member\Service\MemberQueryService;
use PeanutAdmin\Modules\File\Contract\FileReferences;
use PeanutAdmin\Modules\File\Composition\Storage\StorageDriverFactory;
use PeanutAdmin\Modules\File\Service\Storage\StorageService;
use PeanutAdmin\Modules\Task\Service\CrontabSchedulerService;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;
use ReflectionProperty;
use RuntimeException;
use think\App;
use think\Model;
use think\facade\Db;

/** Real ORM and process-local SQLite; advisory lock UDFs below are explicit substitutes, not MySQL concurrency proof. */
final class StandardModuleBoundaryTest extends TestCase
{
    private PDO $database;
    private App $app;
    private ExecutionContextStore $contexts;
    private CurrentExecutionContext $current;
    private AdminDirectoryQuery $directory;
    private ExternalTenantResolver $resolver;
    private ThinkPhpExternalTenantBindingRepository $bindings;
    private MultiTenantDataScopePolicy $policy;
    private array $locks = [];

    protected function setUp(): void
    {
        $root = dirname(__DIR__, 3);
        require_once $root . '/server/vendor/topthink/framework/src/helper.php';
        $this->app = new App($root . '/.local/tmp/standard-module-boundary/case-' . bin2hex(random_bytes(6)));
        $this->database = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        \ThinkPhpTestConnection::fromPdo($this->database);
        $this->contexts = new ExecutionContextStore();
        $this->current = new CurrentExecutionContext($this->contexts);
        $this->policy = new MultiTenantDataScopePolicy($this->current);
        Model::maker(function (Model $model): void {
            if ($model instanceof TenantOwnedModel) {
                $model->setDataScopePolicy($this->policy);
            }
        });
        $this->directory = new AdminDirectoryQuery($this->current);
        $this->bindings = new ThinkPhpExternalTenantBindingRepository($this->directory);
        $this->resolver = new ExternalTenantResolver($this->bindings, new class implements ExternalTenantAudit {
            public function record(string $outcome, array $attributes): void {}
        });
        $this->database->exec(<<<'SQL'
            CREATE TABLE pa_tenant (id INTEGER PRIMARY KEY, code TEXT, status TEXT);
            INSERT INTO pa_tenant VALUES (1,'alpha','active'),(2,'beta','active'),(3,'paused','suspended');
            CREATE TABLE pa_tenant_member (id INTEGER, tenant_id INTEGER, display_name TEXT, status TEXT);
            INSERT INTO pa_tenant_member VALUES (10,1,'Alpha operator','active'),(10,2,'Beta operator','active'),(11,1,'Former operator','disabled');
            CREATE TABLE pa_external_channel_binding (id INTEGER PRIMARY KEY, tenant_id INTEGER, provider TEXT, callback_key TEXT, identity_hash TEXT, identity_hint TEXT, config_json TEXT, status INTEGER, create_time INTEGER, update_time INTEGER);
            CREATE TABLE pa_oauth_attempt (id INTEGER PRIMARY KEY, tenant_id INTEGER, scene TEXT, state_hash TEXT, used_at INTEGER, expires_at INTEGER);
            CREATE TABLE pa_oauth_completion_ticket (id INTEGER PRIMARY KEY, tenant_id INTEGER, binding_id INTEGER, token_hash TEXT, used_at INTEGER, expires_at INTEGER);
            CREATE TABLE pa_crontab (id INTEGER PRIMARY KEY, tenant_id INTEGER, status INTEGER, last_time INTEGER, expression TEXT, update_time INTEGER);
            CREATE TABLE pa_refund_record (id INTEGER PRIMARY KEY, tenant_id INTEGER, order_type INTEGER, order_id INTEGER, refund_amount TEXT, delete_time INTEGER);
            CREATE TABLE pa_member (id INTEGER PRIMARY KEY, tenant_id INTEGER, user_money TEXT, total_recharge_amount TEXT, delete_time INTEGER);
            INSERT INTO pa_member VALUES (15,1,'80.00','120.00',NULL),(16,2,'999.00','999.00',NULL);
            CREATE TABLE pa_refund_log (id INTEGER PRIMARY KEY, tenant_id INTEGER, record_id INTEGER, user_id INTEGER, handle_id INTEGER, refund_status INTEGER, create_time INTEGER, refund_msg TEXT, delete_time INTEGER);
            INSERT INTO pa_refund_log VALUES (1,1,9,15,10,1,100,'secret-channel-reply',NULL),(2,1,9,15,0,1,101,'secret',NULL),(3,1,9,15,11,1,102,'secret',NULL),(4,2,9,16,10,1,103,'other tenant',NULL);
            CREATE TABLE pa_storage_account (id INTEGER PRIMARY KEY, account_key TEXT, driver TEXT, name TEXT, credential_ciphertext TEXT, credential_key_version INTEGER, status TEXT);
            INSERT INTO pa_storage_account VALUES (1,'local','local','Local',NULL,1,'active');
            CREATE TABLE pa_storage_space (id INTEGER PRIMARY KEY, account_id INTEGER, space_key TEXT, name TEXT, access_type TEXT, bucket TEXT, region TEXT, endpoint TEXT, access_domain TEXT, local_path TEXT, status TEXT);
            INSERT INTO pa_storage_space VALUES (1,1,'public','Public','public',NULL,NULL,NULL,NULL,'public/storage','active');
            CREATE TABLE pa_file_object (id INTEGER PRIMARY KEY, file_key TEXT, tenant_id INTEGER, access_type TEXT, storage_space_id INTEGER, object_key TEXT, status TEXT);
            INSERT INTO pa_file_object VALUES (1,'file_aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa',1,'public',1,'tenants/v1/1/material/image/file_aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa.png','ready');
            SQL);
        $this->database->sqliteCreateFunction('GET_LOCK', function (string $name, int $timeout): int { $this->locks[] = ['acquire', $name]; return 1; }, 2);
        $this->database->sqliteCreateFunction('RELEASE_LOCK', function (string $name): int { $this->locks[] = ['release', $name]; return 1; }, 1);
    }

    private function context(int $tenant = 1): TenantContext
    {
        return TenantContext::fromValidatedSession(new ValidatedTenantSession(10, 'synthetic-session-' . $tenant, $tenant, 100, 10, 'admin-web', new \DateTimeImmutable('2035-01-01T00:00:00Z'), 1), 'standard-boundary');
    }

    private function binding(int $id = 1, int $tenant = 1, string $provider = 'oauth.wechat.oa', int $enabled = 1): void
    {
        $statement = $this->database->prepare('INSERT INTO pa_external_channel_binding VALUES (?,?,?,?,?,?,?,?,?,?)');
        $statement->execute([$id, $tenant, $provider, 'callback-' . $id, hash('sha256', 'synthetic-' . $id), 'hint', '{"app_id":"synthetic"}', $enabled, 1, 2]);
    }

    public function testPaymentDependsOnThePublicResolutionUseCase(): void
    {
        $parameter = (new ReflectionClass(ThinkPhpPaymentChannelGrantCommands::class))->getConstructor()->getParameters()[0];
        self::assertSame(ExternalTenantResolutionService::class, $parameter->getType()?->getName());
        $manifest = json_decode((string) file_get_contents(dirname(__DIR__, 2) . '/app/modules/official/integration/module.json'), true, 512, JSON_THROW_ON_ERROR);
        self::assertContains(ExternalTenantResolutionService::class, $manifest['contracts']['exports']);
        self::assertNotContains(\PeanutAdmin\Modules\Integration\Contract\ExternalTenantBindingRepository::class, $manifest['contracts']['exports']);
    }

    public function testGrantResolutionPreservesDisabledCandidatesAndExactOwnerAndMissingResult(): void
    {
        $this->binding(1, 1, 'payment.wechat', 0);
        self::assertNull($this->resolver->bindingForGrant(2, 'payment.wechat'));
        self::assertNull($this->resolver->bindingForGrant(1, 'payment.wechat', 2));
        $binding = Db::transaction(fn() => $this->resolver->bindingForGrant(1, 'payment.wechat', 1, true));
        self::assertSame(1, $binding?->id);
        self::assertFalse($binding?->bindingActive);
        self::assertTrue($binding?->tenantActive);
        self::assertSame('synthetic', $binding?->config['app_id']);
    }

    public function testDuplicateGrantBindingIsNotSilentlySelected(): void
    {
        $this->binding(1, 1, 'payment.wechat');
        $this->binding(2, 1, 'payment.wechat');
        $this->expectException(ExternalTenantResolutionException::class);
        $this->resolver->bindingForGrant(1, 'payment.wechat');
    }

    public function testOAuthStateAndTicketLookUpOnlyTheirOwningReferences(): void
    {
        $this->binding();
        $this->binding(2, 2);
        $future = time() + 300;
        $this->database->exec("INSERT INTO pa_oauth_attempt VALUES (1,1,'oa','state-a',NULL,$future),(2,2,'oa','state-b',NULL,$future),(3,1,'oa','used',1,$future),(4,1,'oa','expired',NULL,1)");
        $this->database->exec("INSERT INTO pa_oauth_completion_ticket VALUES (1,1,1,'ticket-a',NULL,$future),(2,2,2,'ticket-b',NULL,$future)");
        $locator = new ThinkPhpOAuthCallbackLocator($this->resolver);
        self::assertSame(1, $locator->locateState('oauth.wechat.oa', 'state-a')[0]->tenantId);
        self::assertSame(2, $locator->locateTicket('ticket-b')[0]->tenantId);
        self::assertSame([], $locator->locateState('oauth.wechat.oa', 'used'));
        self::assertSame([], $locator->locateState('oauth.wechat.oa', 'expired'));
        self::assertSame([], $locator->locateState('payment.wechat', 'state-a'));
        self::assertSame([], $locator->locateTicket('unknown'));
    }

    public function testOAuthOrphanCannotDisappearAndHideAmbiguity(): void
    {
        $this->binding();
        $future = time() + 300;
        $this->database->exec("INSERT INTO pa_oauth_attempt VALUES (1,1,'oa','same-state',NULL,$future),(2,2,'oa','same-state',NULL,$future)");
        $this->expectException(ExternalTenantResolutionException::class);
        (new ThinkPhpOAuthCallbackLocator($this->resolver))->locateState('oauth.wechat.oa', 'same-state');
    }

    public function testOAuthCrossTenantTicketReferenceFails(): void
    {
        $this->binding(1, 1);
        $this->expectException(ExternalTenantResolutionException::class);
        $this->resolver->bindingForCallbackReference(2, null, 1);
    }

    public function testInactiveCallbackCandidateIsStillRejectedByTheExistingVerifier(): void
    {
        $this->binding(1, 3);
        $candidate = $this->resolver->bindingForCallbackReference(3, 'oauth.wechat.oa');
        self::assertFalse($candidate->tenantActive);
        $this->expectException(ExternalTenantResolutionException::class);
        $this->resolver->verifiedCandidates([$candidate], 'oauth.wechat.oa', 'synthetic', 'oauth.callback', 'case');
    }

    public function testLifecycleAndDisplayProjectionExcludeOtherOwnersButRetainFormerNames(): void
    {
        self::assertEqualsCanonicalizing([1, 2], $this->directory->activeTenantIds([1, 2, 3, 999, 1]));
        self::assertSame([], $this->directory->activeTenantIds([]));
        self::assertSame([10 => 'Alpha operator', 11 => 'Former operator'], $this->directory->memberDisplayNames($this->context(), [10, 11, 99]));
        self::assertSame([10 => 'Beta operator'], $this->directory->memberDisplayNames($this->context(2), [10, 11]));
        $this->expectException(\InvalidArgumentException::class);
        $this->directory->activeTenantIds([0]);
    }

    public function testStorageReadsLifecycleWithoutAJoinAndStillRejectsRevokedOrWrongOwner(): void
    {
        $storage = new StorageService((new ReflectionClass(StorageDriverFactory::class))->newInstanceWithoutConstructor(), $this->policy, new DefaultTenantContextResolver(), str_repeat('s', 32), 'https://synthetic.example.test', $this->directory);
        $key = 'file_aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';
        self::assertSame($key, $storage->normalizePublicReference(1, $key));
        try { $storage->normalizePublicReference(2, $key); self::fail('Another tenant obtained the reference'); } catch (RuntimeException) { self::assertTrue(true); }
        $this->database->exec("UPDATE pa_tenant SET status='suspended' WHERE id=1");
        $this->expectException(RuntimeException::class);
        $storage->normalizePublicReference(1, $key);
    }

    public function testRefundLogPreservesOrderingSystemAndFormerOperatorNamesWithoutSecrets(): void
    {
        $service = new RefundApplicationService($this->createMock(FileReferences::class), $this->directory);
        $context = $this->context();
        $rows = $this->contexts->run(new AdminExecutionContext($context, 'refund.log'), fn() => $service->refundLog($context, 9));
        self::assertSame([3, 2, 1], array_column($rows, 'id'));
        self::assertSame(['Former operator', '系统', 'Alpha operator'], array_column($rows, 'handler'));
        self::assertStringNotContainsString('secret', json_encode($rows, JSON_THROW_ON_ERROR));
        self::assertNull($this->current->current());
    }

    public function testRefundAmountUsesExactMemberBalanceAndRetainsRemainingAndInsufficientChecks(): void
    {
        $service = (new ReflectionClass(RechargeAdministrationService::class))->newInstanceWithoutConstructor();
        (new ReflectionProperty($service, 'members'))->setValue($service, new MemberQueryService($this->current));
        $method = new ReflectionMethod($service, 'requestedRefundAmountCents');
        $context = $this->context();
        $order = (object) ['id' => 5, 'user_id' => 15, 'order_amount' => '100.00'];
        $this->database->exec("INSERT INTO pa_refund_record VALUES (1,1,1,5,'25.00',NULL)");
        $this->contexts->run(new AdminExecutionContext($context, 'refund.preflight'), function () use ($service, $method, $context, $order): void {
            self::assertSame(5000, $method->invoke($service, $context, $order, 5000));
            $this->database->exec("UPDATE pa_member SET user_money='40.00' WHERE id=15");
            $this->expectException(\app\common\exception\BusinessException::class);
            $method->invoke($service, $context, $order, 5000);
        });
    }

    public function testSchedulerDiscoveryPreservesActiveOwnerAndReleasesItsSyntheticLock(): void
    {
        $now = time();
        $last = $now - 300;
        $this->database->exec("INSERT INTO pa_crontab VALUES (1,1,1,$last,'* * * * *',0),(2,3,1,$last,'* * * * *',0),(3,999,1,$last,'* * * * *',0),(4,2,2,$last,'* * * * *',0)");
        $scheduler = new CrontabSchedulerService($this->contexts, $this->current, new CrontabTenantLock(new ThinkPhpTenantLockStore()), new AuditContractHost($this->current), new PlatformTenantDataGateway($this->current), $this->directory);
        $seen = [];
        $ids = $scheduler->runDue($now, function ($scope, array $item) use (&$seen): void { $seen[] = [$scope->tenantId(), (int) $item['id']]; });
        self::assertSame([1], $ids);
        self::assertSame([[1, 1]], $seen);
        self::assertSame(['acquire', 'release'], array_column($this->locks, 0));
        self::assertSame($this->locks[0][1], $this->locks[1][1]);
        self::assertNull($this->current->current());
        self::assertSame($last, (int) $this->database->query('SELECT last_time FROM pa_crontab WHERE id=2')->fetchColumn());
    }

    public function testMigratedSourcesDoNotReadForeignBindingOrLifecycleTables(): void
    {
        foreach ([ThinkPhpOAuthCallbackLocator::class, StorageService::class, CrontabSchedulerService::class] as $type) {
            $source = (string) file_get_contents((new ReflectionClass($type))->getFileName());
            self::assertStringNotContainsString("->join('tenant ", $source);
            self::assertStringNotContainsString("->join('external_channel_binding ", $source);
        }
        $source = (string) file_get_contents((new ReflectionClass(ThinkPhpPaymentChannelGrantCommands::class))->getFileName());
        self::assertStringNotContainsString('ExternalTenantBindingRepository', $source);
    }
}
