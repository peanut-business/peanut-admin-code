<?php
declare(strict_types=1);

namespace tests\Multitenancy;

use app\api\services\UserTokenService;
use app\common\execution\AdminExecutionContext;
use app\common\execution\ConsumerExecutionContext;
use app\common\execution\CurrentExecutionContext;
use app\common\execution\ExecutionContextStore;
use app\common\execution\SystemExecutionContext;
use app\common\model\TenantOwnedModel;
use app\common\tenancy\MultiTenantDataScopePolicy;
use app\common\tenancy\PlatformTenantDataGateway;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use PeanutAdmin\Kernel\Auth\TenantContext;
use PeanutAdmin\Kernel\Auth\ValidatedTenantSession;
use PeanutAdmin\Kernel\Context\AuthenticatedMemberContext;
use PeanutAdmin\Kernel\Context\TenantSystemContext;
use PeanutAdmin\Kernel\Persistence\Schema\KernelSchema;
use PeanutAdmin\Modules\Member\Infrastructure\Persistence\ThinkPhpMemberSessionStore;
use PeanutAdmin\Modules\Member\Infrastructure\Persistence\ThinkPhpMemberSubjectLookup;
use PeanutAdmin\Modules\Member\Service\MemberIdentityContractService;
use PeanutAdmin\Modules\Member\Service\MemberProfileContractService;
use PeanutAdmin\Modules\Member\Service\MemberSessionService;
use PDO;
use PHPUnit\Framework\TestCase;
use think\Model;

require_once __DIR__ . '/../Support/ThinkPhpTestConnection.php';
require_once __DIR__ . '/../Support/RegisteredMysqlTestResource.php';

/** Real MySQL qualification for member-session migration, revocation, and Tenant ownership. */
final class MemberSessionTenantIsolationTest extends TestCase
{
    private const TENANT_A = 31;
    private const TENANT_B = 32;
    private const MEMBER_A = 7;
    private const MEMBER_B = 8;
    private const OLD_PASSWORD = 'Old-password-2026!';
    private const CHANGED_PASSWORD = 'Changed-password-2026!';

    private static PDO $pdo;
    private static bool $createdDatabase;
    private static ExecutionContextStore $contexts;
    private static MemberSessionService $sessions;
    private static MemberIdentityContractService $identities;
    private static MemberProfileContractService $profiles;
    private static UserTokenService $tokens;

    public static function setUpBeforeClass(): void
    {
        [self::$pdo, self::$createdDatabase] = \RegisteredMysqlTestResource::openEmptyDatabase(self::database());
        self::createSchema(self::$pdo);
        self::configureRuntime(self::$pdo);
    }

    public static function tearDownAfterClass(): void
    {
        if (isset(self::$pdo)) {
            \RegisteredMysqlTestResource::cleanup(self::$pdo, self::database(), self::$createdDatabase);
        }
    }

    public function testRealMigrationLogoutPasswordChangeDisableAndConcurrentChange(): void
    {
        $first = self::$tokens->createToken(self::MEMBER_A);
        $second = self::$tokens->createToken(self::MEMBER_A);
        $foreign = self::$tokens->createToken(self::MEMBER_B);
        self::assertSame(self::MEMBER_A, self::$tokens->parseToken($first));
        self::assertSame(self::MEMBER_A, self::$tokens->parseToken($second));
        self::assertSame(self::MEMBER_B, self::$tokens->parseToken($foreign));
        self::assertSame(3, (int)self::$pdo->query('SELECT COUNT(*) FROM pa_member_session')->fetchColumn());
        self::assertSame(0, (int)self::$pdo->query("SELECT COUNT(*) FROM pa_member_session WHERE session_hash IN (" . self::$pdo->quote($first) . ',' . self::$pdo->quote($second) . ')')->fetchColumn());

        $claims = (array)JWT::decode($first, new Key(self::jwtSecret(), 'HS256'));
        $claims['tenant_id'] = self::TENANT_B;
        $forged = JWT::encode($claims, self::jwtSecret(), 'HS256');
        $this->assertInvalidToken($forged, 'a signed foreign Tenant claim was accepted');

        self::$tokens->revokeToken($first);
        $this->assertInvalidToken($first, 'logout did not revoke the current session');
        self::assertSame(self::MEMBER_A, self::$tokens->parseToken($second));
        self::assertSame('logout', self::$pdo->query(
            'SELECT revoke_reason FROM pa_member_session WHERE session_hash=' . self::$pdo->quote(self::sessionHash($first)),
        )->fetchColumn());

        $member = self::memberContext(self::TENANT_A, self::MEMBER_A, 'member-password-change');
        self::$contexts->run(ConsumerExecutionContext::member($member, 'member.password.change'), fn() =>
            self::$identities->changePassword($member, self::MEMBER_A, self::OLD_PASSWORD, self::CHANGED_PASSWORD),
        );
        self::assertTrue(self::$contexts->isEmpty());
        $this->assertInvalidToken($second, 'password change did not invalidate the previous session');
        self::assertSame(2, $this->memberRevision(self::MEMBER_A));
        self::assertSame(0, (int)self::$pdo->query(
            "SELECT COUNT(*) FROM pa_member_session WHERE tenant_id=31 AND member_id=7 AND revoked_at IS NULL",
        )->fetchColumn());
        self::assertGreaterThanOrEqual(1, (int)self::$pdo->query(
            "SELECT COUNT(*) FROM pa_member_session WHERE tenant_id=31 AND member_id=7 AND revoke_reason='password_change'",
        )->fetchColumn());

        $this->login(self::CHANGED_PASSWORD);
        $beforeConcurrent = self::$tokens->createToken(self::MEMBER_A);
        $results = $this->concurrentPasswordChange(
            self::CHANGED_PASSWORD,
            ['Concurrent-password-one-2026!', 'Concurrent-password-two-2026!'],
        );
        sort($results, SORT_STRING);
        self::assertSame(['PASSWORD_REJECTED', 'SUCCESS'], $results);
        self::assertSame(3, $this->memberRevision(self::MEMBER_A));
        $this->assertInvalidToken($beforeConcurrent, 'concurrent password change left the previous session active');

        $winningPassword = $this->winningPassword([
            'Concurrent-password-one-2026!',
            'Concurrent-password-two-2026!',
        ]);
        $this->login($winningPassword);
        $activeBeforeDisable = self::$tokens->createToken(self::MEMBER_A);
        $admin = self::adminContext(self::TENANT_A, 501, 301, 'member-disable');
        self::$contexts->run(new AdminExecutionContext($admin, 'member.disable'), fn() =>
            self::$profiles->updateStatus($admin, self::MEMBER_A, 0),
        );
        self::assertTrue(self::$contexts->isEmpty());
        self::assertSame(0, (int)self::$pdo->query('SELECT status FROM pa_member WHERE id=7')->fetchColumn());
        self::assertSame(4, $this->memberRevision(self::MEMBER_A));
        self::assertSame('member_disabled', self::$pdo->query(
            'SELECT revoke_reason FROM pa_member_session WHERE session_hash=' . self::$pdo->quote(self::sessionHash($activeBeforeDisable)),
        )->fetchColumn());
        $this->assertInvalidToken($activeBeforeDisable, 'disabled member session remained valid');
        self::assertSame(self::MEMBER_B, self::$tokens->parseToken($foreign), 'Tenant A lifecycle changed Tenant B session');
    }

    /** @param list<string> $newPasswords @return list<string> */
    private function concurrentPasswordChange(string $oldPassword, array $newPasswords): array
    {
        if (!function_exists('pcntl_fork') || !function_exists('stream_socket_pair')) {
            self::fail('Member-session concurrency qualification requires pcntl and Unix socket pairs.');
        }
        $children = [];
        foreach ($newPasswords as $index => $newPassword) {
            $sockets = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, 0);
            if (!is_array($sockets)) {
                self::fail('Cannot create concurrency barrier.');
            }
            $pid = pcntl_fork();
            if ($pid === -1) {
                self::fail('Cannot fork member-session concurrency worker.');
            }
            if ($pid === 0) {
                fclose($sockets[0]);
                fwrite($sockets[1], 'R');
                fread($sockets[1], 1);
                try {
                    $pdo = self::newConnection();
                    \RegisteredMysqlTestResource::assertSelectedDatabase($pdo, self::database());
                    self::configureRuntime($pdo);
                    $context = self::memberContext(self::TENANT_A, self::MEMBER_A, 'member-concurrent-' . $index);
                    self::$contexts->run(ConsumerExecutionContext::member($context, 'member.password.change'), fn() =>
                        self::$identities->changePassword($context, self::MEMBER_A, $oldPassword, $newPassword),
                    );
                    fwrite($sockets[1], 'SUCCESS');
                } catch (\Throwable $exception) {
                    fwrite($sockets[1], $exception->getMessage() === '原密码错误' ? 'PASSWORD_REJECTED' : 'UNEXPECTED');
                }
                fclose($sockets[1]);
                exit(0);
            }
            fclose($sockets[1]);
            $children[] = [$pid, $sockets[0]];
        }
        foreach ($children as [, $socket]) {
            self::assertSame('R', fread($socket, 1));
        }
        foreach ($children as [, $socket]) {
            fwrite($socket, 'S');
        }
        $results = [];
        foreach ($children as [$pid, $socket]) {
            $results[] = stream_get_contents($socket);
            fclose($socket);
            pcntl_waitpid($pid, $status);
            self::assertTrue(pcntl_wifexited($status) && pcntl_wexitstatus($status) === 0);
        }
        // fork 会复制父进程 PDO；子进程析构可能关闭其共享连接。父进程必须新建连接，
        // 不能用已经断开的 PDO 判断事务结果，也不能修改生产连接器来迁就测试。
        self::$pdo = self::newConnection();
        \RegisteredMysqlTestResource::assertSelectedDatabase(self::$pdo, self::database());
        self::configureRuntime(self::$pdo);
        return $results;
    }

    /** @param list<string> $passwords */
    private function winningPassword(array $passwords): string
    {
        $hash = (string)self::$pdo->query('SELECT password FROM pa_member WHERE id=7')->fetchColumn();
        $winners = array_values(array_filter($passwords, static fn(string $password): bool => password_verify($password, $hash)));
        self::assertCount(1, $winners);
        return $winners[0];
    }

    private function login(string $password): void
    {
        $system = new TenantSystemContext(self::TENANT_A, 'member-auth', 'member.login', 'member-login-' . hash('sha256', $password));
        $snapshot = self::$contexts->run(new SystemExecutionContext($system), fn() =>
            self::$identities->login($system, 'member-a@example.test', $password, '127.0.0.1'),
        );
        // 公开 MemberIdentitySnapshot 使用 id；不能读取不存在的 memberId 后误判登录结果。
        self::assertSame(self::MEMBER_A, $snapshot->id);
        self::assertTrue(self::$contexts->isEmpty());
    }

    private function assertInvalidToken(string $token, string $message): void
    {
        try {
            self::$tokens->parseToken($token);
        } catch (\UnexpectedValueException) {
            self::assertTrue(true);
            return;
        }
        self::fail($message);
    }

    private function memberRevision(int $memberId): int
    {
        return (int)self::$pdo->query('SELECT session_revision FROM pa_member WHERE id=' . $memberId)->fetchColumn();
    }

    private static function configureRuntime(PDO $pdo): void
    {
        \ThinkPhpTestConnection::fromPdo($pdo);
        self::$contexts = new ExecutionContextStore();
        $current = new CurrentExecutionContext(self::$contexts);
        $policy = new MultiTenantDataScopePolicy($current);
        Model::maker(static function (Model $model) use ($policy): void {
            if ($model instanceof TenantOwnedModel) {
                $model->setDataScopePolicy($policy);
            }
        });
        $gateway = new PlatformTenantDataGateway($current);
        self::$sessions = new MemberSessionService(
            new ThinkPhpMemberSubjectLookup($gateway),
            new ThinkPhpMemberSessionStore($gateway),
        );
        self::$identities = new MemberIdentityContractService(self::$sessions);
        self::$profiles = new MemberProfileContractService(self::$sessions);
        self::$tokens = new UserTokenService(self::jwtSecret(), 3600, self::$sessions);
    }

    private static function createSchema(PDO $pdo): void
    {
        $pdo->exec(KernelSchema::createSql('pa_tenant'));
        $source = (string)file_get_contents(dirname(__DIR__, 2) . '/database/init.sql');
        if (preg_match('/CREATE TABLE `pa_member` \(.*?\n\) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT=\'会员\';/s', $source, $match) !== 1) {
            throw new \RuntimeException('MEMBER_SESSION_CANONICAL_MEMBER_SCHEMA_MISSING');
        }
        $pdo->exec($match[0]);
        $migration = (string)file_get_contents(
            dirname(__DIR__, 2) . '/app/modules/official/member/database/migrations/20260921-create-member-session.sql',
        );
        if ($migration === '') {
            throw new \RuntimeException('MEMBER_SESSION_MIGRATION_MISSING');
        }
        $pdo->exec($migration);
        $pdo->exec("INSERT INTO pa_tenant(id,code,name,display_name,status,activated_at,created_at,updated_at) VALUES "
            . "(31,'member-a','Member A','Member A','active',UTC_TIMESTAMP(3),UTC_TIMESTAMP(3),UTC_TIMESTAMP(3)),"
            . "(32,'member-b','Member B','Member B','active',UTC_TIMESTAMP(3),UTC_TIMESTAMP(3),UTC_TIMESTAMP(3))");
        $insert = $pdo->prepare(
            'INSERT INTO pa_member(id,sn,account,password,nickname,status,tenant_id,create_time,update_time) VALUES (?,?,?,?,?,?,?,UNIX_TIMESTAMP(),UNIX_TIMESTAMP())',
        );
        $insert->execute([self::MEMBER_A, 'M-A', 'member-a@example.test', password_hash(self::OLD_PASSWORD, PASSWORD_ARGON2ID), 'Member A', 1, self::TENANT_A]);
        $insert->execute([self::MEMBER_B, 'M-B', 'member-b@example.test', password_hash('Member-b-password-2026!', PASSWORD_ARGON2ID), 'Member B', 1, self::TENANT_B]);
        self::assertSame(1, (int)$pdo->query(
            "SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA='" . self::database() . "' AND TABLE_NAME='pa_member' AND COLUMN_NAME='session_revision'",
        )->fetchColumn());
        self::assertSame(1, (int)$pdo->query(
            "SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA='" . self::database() . "' AND TABLE_NAME='pa_member_session'",
        )->fetchColumn());
    }

    private static function newConnection(): PDO
    {
        return new PDO(
            sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4', getenv('DB_HOST'), getenv('DB_PORT'), self::database()),
            (string)getenv('DB_USER'),
            (string)getenv('DB_PASS'),
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES => false],
        );
    }

    private static function memberContext(int $tenantId, int $memberId, string $requestId): AuthenticatedMemberContext
    {
        return new AuthenticatedMemberContext($tenantId, $memberId, hash('sha256', $requestId), $requestId);
    }

    private static function database(): string
    {
        return \RegisteredMysqlTestResource::configuredDatabaseName();
    }

    private static function adminContext(int $tenantId, int $memberId, int $accountId, string $requestId): TenantContext
    {
        return TenantContext::fromValidatedSession(new ValidatedTenantSession(
            $memberId,
            '01J00000000000000000000000',
            $tenantId,
            $accountId,
            $memberId,
            'admin-web',
            new \DateTimeImmutable('2031-01-01T00:00:00Z'),
            1,
        ), $requestId);
    }

    private static function sessionHash(string $token): string
    {
        $claims = (array)JWT::decode($token, new Key(self::jwtSecret(), 'HS256'));
        return hash('sha256', (string)$claims['sid']);
    }

    private static function jwtSecret(): string
    {
        $secret = getenv('JWT_SECRET');
        if (!is_string($secret) || strlen($secret) < 32) {
            throw new \RuntimeException('MEMBER_SESSION_JWT_SECRET_INVALID');
        }
        return $secret;
    }
}
