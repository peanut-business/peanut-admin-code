<?php

declare(strict_types=1);

use app\common\execution\CurrentExecutionContext;
use app\common\execution\ExecutionContextStore;
use app\platform\contract\provider\ProviderQualificationContributor;
use app\platform\infrastructure\provider\NotificationQualificationContributor;
use app\platform\infrastructure\provider\OauthQualificationContributor;
use app\platform\infrastructure\provider\PaymentQualificationContributor;
use app\platform\services\provider\PlatformProviderQualificationService;
use PeanutAdmin\Kernel\Context\PlatformContext;
use PeanutAdmin\Modules\Identity\Contract\AdminDirectoryQuery;
use PeanutAdmin\Modules\Integration\Contract\ExternalBindingQualification;
use PeanutAdmin\Modules\Integration\Contract\ExternalBindingQualificationQueries;
use PeanutAdmin\Modules\Ops\Domain\Application\OpsConsoleException;
use PeanutAdmin\Modules\Ops\Domain\Application\PlatformPermissionChecker;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;
use think\App;

/** Real owner queries and host contributors; synthetic SQLite only, no provider request or qualification probe. */
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class BindingQualificationBoundaryTest extends TestCase
{
    private PDO $database;
    private ExternalBindingQualificationQueries $queries;
    private const KEY = 'synthetic-qualification-hmac-key-32-bytes';

    protected function setUp(): void
    {
        require_once dirname(__DIR__, 2) . '/vendor/topthink/framework/src/helper.php';
        new App(dirname(__DIR__, 3) . '/.local/tmp/binding-qualification-' . bin2hex(random_bytes(6)));
        $this->database = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        ThinkPhpTestConnection::fromPdo($this->database);
        $this->database->exec("CREATE TABLE pa_tenant (id INTEGER PRIMARY KEY, status TEXT); INSERT INTO pa_tenant VALUES(1,'active'),(2,'inactive'),(3,'active'); CREATE TABLE pa_external_channel_binding (id INTEGER PRIMARY KEY AUTOINCREMENT, tenant_id INTEGER, provider TEXT, identity_hash TEXT, config_json TEXT, status INTEGER, update_time INTEGER, UNIQUE(tenant_id,provider))");
        $this->queries = new ExternalBindingQualificationQueries();
    }

    private function binding(int $tenant, string $provider, string $json, int $status = 1): int
    {
        $statement = $this->database->prepare('INSERT INTO pa_external_channel_binding(tenant_id,provider,identity_hash,config_json,status,update_time) VALUES(?,?,?,?,?,?)');
        $statement->execute([$tenant, $provider, 'fixture-identity', $json, $status, 123]);
        return (int) $this->database->lastInsertId();
    }

    public function testPublicResultAndQueryAreDeclaredWithoutExposingStorage(): void
    {
        $manifest = json_decode(file_get_contents(dirname(__DIR__, 2) . '/app/modules/official/integration/module.json'), true, 512, JSON_THROW_ON_ERROR);
        foreach ([ExternalBindingQualification::class, ExternalBindingQualificationQueries::class] as $type) {
            self::assertContains($type, $manifest['contracts']['exports']);
            self::assertFalse(is_subclass_of($type, think\Model::class));
        }
    }

    public function testRawDigestAndFixedProjectionRemainExact(): void
    {
        $json = '{ "app_id":"fixture-app", "app_secret":"fixture-secret" }';
        $id = $this->binding(1, 'oauth.wechat.oa', $json);
        $result = $this->queries->forTenants([1], ['oauth.wechat.oa'], self::KEY)[0];
        self::assertSame([1, 'oauth.wechat.oa', true], [$result->tenantId, $result->providerKey, $result->configured]);
        self::assertSame(hash_hmac('sha256', implode("\0", ['oauth.wechat.oa', (string) $id, 'fixture-identity', '1', '123', $json]), self::KEY), $result->configDigest);
        self::assertSame(['tenantId', 'providerKey', 'configured', 'configDigest'], array_keys(get_object_vars($result)));
        self::assertStringNotContainsString('fixture-secret', json_encode($result, JSON_THROW_ON_ERROR));
        self::assertTrue((new ReflectionClass($result))->isReadOnly());
    }

    public function testMissingMalformedDisabledAndOfficialAccountTokenRemainDistinct(): void
    {
        $this->binding(1, 'oauth.wechat.oa', '{broken');
        $this->binding(1, 'oauth.wechat.mini-program', '{"app_id":"a","app_secret":"s"}', 0);
        $this->binding(1, 'wechat.official-account', '{"app_id":"a","app_secret":"s"}');
        foreach ($this->queries->forTenants([1], ['oauth.wechat.oa', 'oauth.wechat.mini-program', 'wechat.official-account', 'payment.wechat'], self::KEY) as $result) {
            self::assertFalse($result->configured);
        }
        $missing = $this->queries->forTenants([3], ['oauth.wechat.oa'], self::KEY)[0];
        self::assertSame(hash_hmac('sha256', "oauth.wechat.oa\0003\0missing", self::KEY), $missing->configDigest);
    }

    public function testPaymentAndNestedSmsConfigurationRulesArePreserved(): void
    {
        $wechat = array_fill_keys(['wx_pay_appid', 'wx_pay_mch_id', 'wx_pay_secret', 'wx_pay_cert_path', 'wx_pay_cert_key_path', 'wx_pay_platform_cert_path'], 'fixture');
        $wechat['wx_pay_status'] = '1';
        $alipay = array_fill_keys(['ali_pay_app_id', 'ali_pay_private_key', 'ali_pay_public_key', 'ali_pay_seller_id'], 'fixture');
        $alipay['ali_pay_status'] = 1;
        $sms = ['sms_aliyun' => json_encode(['status' => '1', 'access_key_id' => 'a', 'access_key_secret' => 's', 'sign_name' => 'sign']), 'sms_tencent' => ['status' => 1, 'secret_id' => 'i', 'secret_key' => 's', 'sdk_app_id' => 'a', 'sign_name' => 'sign', 'region' => 'r']];
        foreach (['payment.wechat' => $wechat, 'payment.alipay' => $alipay, 'notice.sms' => $sms] as $provider => $config) {
            $this->binding(1, $provider, json_encode($config, JSON_THROW_ON_ERROR));
        }
        $results = $this->queries->forTenants([1], ['payment.wechat', 'payment.alipay', 'notification.sms.aliyun', 'notification.sms.tencent'], self::KEY);
        self::assertCount(4, $results);
        self::assertSame([true, true, true, true], array_column($results, 'configured'));
        self::assertNotSame($results[2]->configDigest, $results[3]->configDigest);
        $this->database->exec("UPDATE pa_external_channel_binding SET status=0 WHERE provider='notice.sms'");
        self::assertFalse($this->queries->forTenants([1], ['notification.sms.aliyun'], self::KEY)[0]->configured);
    }

    public function testActualContributorsKeepActiveMissingAndUnimplementedSubjects(): void
    {
        $this->binding(2, 'oauth.wechat.oa', '{"app_id":"other","app_secret":"private"}');
        $directory = new AdminDirectoryQuery(new CurrentExecutionContext(new ExecutionContextStore()));
        foreach ([PaymentQualificationContributor::class => 4, OauthQualificationContributor::class => 8, NotificationQualificationContributor::class => 6] as $type => $count) {
            $results = (new $type(self::KEY, $directory, $this->queries))->subjects();
            self::assertCount($count, $results);
            foreach ($results as $subject) {
                self::assertContains($subject->tenantId, [1, 3]);
                self::assertFalse($subject->configured);
                if ($subject->providerKey === 'notification.email') {
                    self::assertFalse($subject->implemented);
                    self::assertFalse($subject->callbackRequired);
                }
            }
        }
    }

    public function testInvalidSelectionsFailBeforeAnyDatabaseRead(): void
    {
        $this->database->exec('DROP TABLE pa_external_channel_binding');
        foreach ([[[0], ['payment.wechat'], self::KEY], [[1], ['unknown.provider'], self::KEY], [[1], ['payment.wechat'], 'short']] as [$tenants, $providers, $key]) {
            try {
                $this->queries->forTenants($tenants, $providers, $key);
                self::fail('Invalid selection was accepted.');
            } catch (InvalidArgumentException $exception) {
                self::assertStringStartsWith('PROVIDER_QUALIFICATION_', $exception->getMessage());
            }
        }
        self::assertSame([], $this->queries->forTenants([], ['payment.wechat'], self::KEY));
    }

    public function testBatchBoundaryDoesNotTruncateOrIncludeUnselectedTenants(): void
    {
        $this->binding(2, 'oauth.wechat.oa', '{"app_id":"foreign","app_secret":"secret"}');
        $results = $this->queries->forTenants(range(3, 503), ['oauth.wechat.oa'], self::KEY);
        self::assertCount(501, $results);
        self::assertSame(503, $results[500]->tenantId);
        self::assertFalse($results[0]->configured);
    }

    public function testUnavailableStorageIsNotAnUnconfiguredSuccess(): void
    {
        $this->database->exec('DROP TABLE pa_external_channel_binding');
        $this->expectException(think\db\exception\PDOException::class);
        $this->queries->forTenants([1], ['payment.wechat'], self::KEY);
    }

    public function testPlatformPermissionStillPrecedesAllContributorReads(): void
    {
        $permissions = $this->createStub(PlatformPermissionChecker::class);
        $permissions->method('allows')->willReturn(false);
        $contributor = $this->createMock(ProviderQualificationContributor::class);
        $contributor->expects(self::never())->method('subjects');
        $service = new PlatformProviderQualificationService($permissions, [$contributor], self::KEY);
        $context = (new ReflectionClass(PlatformContext::class))->newInstanceWithoutConstructor();
        $this->expectException(OpsConsoleException::class);
        $service->snapshot($context);
    }
}
