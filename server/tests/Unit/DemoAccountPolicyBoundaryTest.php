<?php

declare(strict_types=1);

namespace tests\Unit;

use PDO;
use PeanutAdmin\Modules\Identity\Policy\DemoAccountPolicy;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;
use think\App;

/** Original demo-only policy, isolated synthetic credentials; no install, deployment or real account writes. */
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class DemoAccountPolicyBoundaryTest extends TestCase
{
    private PDO $database;

    protected function setUp(): void
    {
        $root = dirname(__DIR__, 3);
        $temporary = $root . '/.local/tmp/demo-account-policy-tests';
        if (!is_dir($temporary) && !mkdir($temporary, 0700, true) && !is_dir($temporary)) {
            throw new \RuntimeException('DEMO_POLICY_TEST_DIRECTORY_UNAVAILABLE');
        }
        self::assertStringStartsWith($root . '/.local/tmp/', (string) realpath($temporary));
        require_once $root . '/server/vendor/topthink/framework/src/helper.php';
        new App($temporary . '/case-' . bin2hex(random_bytes(5)));
        $this->database = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $this->database->sqliteCreateFunction('UTC_TIMESTAMP', static fn(int $precision): string => '2031-01-01 00:00:00.000', 1);
        \ThinkPhpTestConnection::fromPdo($this->database);
        $this->database->exec(<<<'SQL'
            CREATE TABLE pa_credential (id INTEGER PRIMARY KEY, account_id INTEGER, kind TEXT, identifier_type TEXT, identifier_normalized TEXT, status TEXT, secret_hash TEXT, failed_attempts INTEGER, locked_until TEXT, secret_changed_at TEXT, revision INTEGER, updated_at TEXT);
            INSERT INTO pa_credential VALUES
                (1,101,'email_password','email','demo@example.test','active','original',3,'locked',NULL,4,NULL),
                (2,202,'email_password','email','normal@example.test','active','original',3,'locked',NULL,4,NULL),
                (3,303,'email_password','email','demo@example.test','inactive','original',3,'locked',NULL,4,NULL),
                (4,404,'other','email','demo@example.test','active','original',3,'locked',NULL,4,NULL),
                (5,505,'email_password','phone','demo@example.test','active','original',3,'locked',NULL,4,NULL);
            SQL);
    }

    public function testPolicyIsPublishedByIdentityWithNoCompatibilityClass(): void
    {
        $manifest = json_decode(file_get_contents(dirname(__DIR__, 2) . '/app/modules/official/identity/module.json'), true, 512, JSON_THROW_ON_ERROR);
        self::assertContains(DemoAccountPolicy::class, $manifest['contracts']['exports']);
        self::assertFileDoesNotExist(dirname(__DIR__, 2) . '/app/common/policy/DemoAccountPolicy.php');
        self::assertStringContainsString('/modules/official/identity/src/', (new \ReflectionClass(DemoAccountPolicy::class))->getFileName());
    }

    public function testDisabledDemoDoesNotReadCredentialStorageOrLockNormalDeployments(): void
    {
        $this->database->exec('DROP TABLE pa_credential');
        $policy = new DemoAccountPolicy(false, ['demo@example.test']);
        self::assertFalse($policy->enabled());
        self::assertFalse($policy->isDemoEmail('demo@example.test'));
        self::assertFalse($policy->platformMutationLocked(101));
        self::assertFalse($policy->mutationLocked(['username' => 'demo@example.test'], 'admin/edit'));
        $policy->assertPasswordChangeAllowed(101);
        foreach (['bootstrapPassword', 'credentialHash'] as $method) {
            try {
                $policy->$method();
                self::fail('Disabled demo credential generation was accepted.');
            } catch (\LogicException $exception) {
                self::assertSame('演示密码策略未启用', $exception->getMessage());
            }
        }
        $this->expectException(\LogicException::class);
        $policy->replaceCredentialHashes(['demo@example.test']);
    }

    public function testActiveDemoIdentityAndRestrictedPathsRemainExact(): void
    {
        $policy = new DemoAccountPolicy(true, [' DEMO@example.test ', '']);
        self::assertTrue($policy->isDemoEmail(' demo@EXAMPLE.test '));
        self::assertFalse($policy->isDemoEmail('normal@example.test'));
        self::assertTrue($policy->platformMutationLocked(101));
        foreach ([0, 202, 303, 404, 505, 999] as $accountId) {
            self::assertFalse($policy->platformMutationLocked($accountId));
            $policy->assertPasswordChangeAllowed($accountId);
        }
        self::assertTrue($policy->mutationLocked(['username' => 'demo@example.test'], '/ADMIN/EDIT/'));
        self::assertFalse($policy->mutationLocked(['username' => 'normal@example.test'], 'admin/edit'));
        self::assertFalse($policy->mutationLocked(['username' => 'demo@example.test'], 'article/read'));
        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('演示账号密码已锁定，不能在页面中修改');
        $policy->assertPasswordChangeAllowed(101);
    }

    public function testExplicitDemoBootstrapUpdatesOnlySelectedActiveEmailCredentials(): void
    {
        $policy = new DemoAccountPolicy(true, ['demo@example.test']);
        $policy->replaceCredentialHashes(['DEMO@example.test', 'demo@example.test', '  ']);
        $rows = $this->database->query('SELECT * FROM pa_credential ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
        self::assertTrue(password_verify('peanut1234', $rows[0]['secret_hash']));
        self::assertSame(PASSWORD_ARGON2ID, password_get_info($rows[0]['secret_hash'])['algo']);
        self::assertSame(5, $rows[0]['revision']);
        self::assertSame(0, $rows[0]['failed_attempts']);
        self::assertNull($rows[0]['locked_until']);
        self::assertSame('2031-01-01 00:00:00.000', $rows[0]['secret_changed_at']);
        foreach (array_slice($rows, 1) as $row) {
            self::assertSame('original', $row['secret_hash']);
            self::assertSame(4, $row['revision']);
        }
        self::assertSame(50, strlen($policy->bootstrapPassword()));
        self::assertNotSame($policy->bootstrapPassword(), $policy->bootstrapPassword());
    }

    public function testEnabledDemoStoreFailureCannotSilentlyUnlockAnAccount(): void
    {
        $this->database->exec('DROP TABLE pa_credential');
        $policy = new DemoAccountPolicy(true, ['demo@example.test']);
        $this->expectException(\Throwable::class);
        $policy->assertPasswordChangeAllowed(101);
    }
}
