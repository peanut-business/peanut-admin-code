<?php

declare(strict_types=1);

use app\common\execution\ConsumerExecutionContext;
use app\common\execution\ExecutionContextStore;
use app\common\exception\BusinessException;
use PeanutAdmin\Modules\Member\Model\Member;
use PeanutAdmin\Modules\Member\Service\MemberProfileContractService;
use PeanutAdmin\Kernel\Context\AuthenticatedMemberContext;

require dirname(__DIR__, 2) . '/vendor/autoload.php';
require __DIR__ . '/../Support/IsolatedBackendEnvironment.php';

$memberProfileAssertions = 0;

function expectMemberProfile(bool $condition, string $message): void
{
    global $memberProfileAssertions;
    $memberProfileAssertions++;
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function memberProfileContext(int $tenantId, int $memberId, string $requestId): AuthenticatedMemberContext
{
    return new AuthenticatedMemberContext(
        $tenantId,
        $memberId,
        hash('sha256', 'member-profile-' . $tenantId . '-' . $memberId),
        $requestId,
    );
}

function memberProfileSchema(PDO $pdo): void
{
    $pdo->exec(<<<'SQL'
CREATE TABLE pa_tenant (
  id BIGINT UNSIGNED NOT NULL, status VARCHAR(32) NOT NULL,
  PRIMARY KEY (id)
) ENGINE=InnoDB;
CREATE TABLE pa_member (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT, sn VARCHAR(20) NOT NULL DEFAULT '',
  account VARCHAR(50) NOT NULL DEFAULT '', account_unique VARCHAR(50) GENERATED ALWAYS AS (NULLIF(account,'')) STORED,
  password VARCHAR(100) NOT NULL DEFAULT '', nickname VARCHAR(50) NOT NULL DEFAULT '', avatar VARCHAR(255) NOT NULL DEFAULT '',
  real_name VARCHAR(32) NOT NULL DEFAULT '', mobile VARCHAR(20) NOT NULL DEFAULT '',
  mobile_unique VARCHAR(20) GENERATED ALWAYS AS (NULLIF(mobile,'')) STORED, channel TINYINT UNSIGNED NOT NULL DEFAULT 0,
  email VARCHAR(100) NOT NULL DEFAULT '', sex TINYINT NOT NULL DEFAULT 0, birthday DATE NULL, status TINYINT NOT NULL DEFAULT 1,
  login_time INT UNSIGNED NOT NULL DEFAULT 0, login_ip VARCHAR(45) NOT NULL DEFAULT '', is_new_user TINYINT NOT NULL DEFAULT 0,
  user_money DECIMAL(10,2) UNSIGNED NOT NULL DEFAULT 0, total_recharge_amount DECIMAL(10,2) UNSIGNED NOT NULL DEFAULT 0,
  points INT UNSIGNED NOT NULL DEFAULT 0, create_time INT UNSIGNED NOT NULL DEFAULT 0, update_time INT UNSIGNED NOT NULL DEFAULT 0,
  delete_time INT UNSIGNED NULL, tenant_id BIGINT UNSIGNED NOT NULL,
  PRIMARY KEY (id), UNIQUE KEY uk_member_tenant_id (tenant_id, id),
  CONSTRAINT fk_member_tenant FOREIGN KEY (tenant_id) REFERENCES pa_tenant (id) ON DELETE RESTRICT
) ENGINE=InnoDB;
SQL);
}

function rejectMemberProfile(callable $operation, string $message): void
{
    try {
        $operation();
    } catch (BusinessException $exception) {
        expectMemberProfile($exception->errorCode === 'MEMBER_PROFILE_VALUE_INVALID', $message . ': error code changed');
        return;
    }

    throw new RuntimeException($message . ': invalid value was accepted');
}

$serverRoot = dirname(__DIR__, 2);
$host = IsolatedBackendEnvironment::required('DB_HOST');
$port = (int) IsolatedBackendEnvironment::required('DB_PORT');
$database = 'peanut_stabilization_d02';
$user = IsolatedBackendEnvironment::required('DB_USER');
$password = IsolatedBackendEnvironment::required('DB_PASS');
expectMemberProfile(IsolatedBackendEnvironment::required('APP_ENV') === 'development', 'D02 requires the development environment');
expectMemberProfile(
    IsolatedBackendEnvironment::required('PEANUT_DATABASE_RESOURCE_ID') === 'peanut-admin-backend-stabilization-mysql84',
    'D02 requires its registered MySQL resource',
);

$createdDatabase = false;
$adminPdo = null;

try {
    $adminPdo = new PDO(
        "mysql:host={$host};port={$port};charset=utf8mb4",
        $user,
        $password,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
    );
    $existingDatabase = $adminPdo->prepare('SELECT SCHEMA_NAME FROM information_schema.SCHEMATA WHERE SCHEMA_NAME = ?');
    $existingDatabase->execute([$database]);
    expectMemberProfile($existingDatabase->fetchColumn() === false, 'D02 test database must not pre-exist');
    $adminPdo->exec('CREATE DATABASE peanut_stabilization_d02 CHARACTER SET utf8mb4');
    $createdDatabase = true;
    $pdo = new PDO(
        "mysql:host={$host};port={$port};dbname={$database};charset=utf8mb4",
        $user,
        $password,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
    );
    memberProfileSchema($pdo);
    $pdo->exec("INSERT INTO pa_tenant (id, status) VALUES (101, 'active'), (202, 'active')");
    $pdo->exec(<<<'SQL'
INSERT INTO pa_member (id, tenant_id, sn, nickname, sex, birthday, email, status, create_time, update_time)
VALUES
  (501, 101, 'M-PROFILE-501', 'Alpha', 0, '2000-01-01', 'alpha@example.com', 1, 1, 1),
  (503, 101, 'M-PROFILE-503', 'Alpha peer', 0, '2002-01-01', 'peer@example.com', 1, 1, 1),
  (502, 202, 'M-PROFILE-502', 'Beta', 0, '2001-01-01', 'beta@example.com', 1, 1, 1)
SQL);

    IsolatedBackendEnvironment::activateDatabase($host, $port, $database, $user, $password, 'multi-tenant');
    $app = new think\App($serverRoot);
    $app->initialize();
    $profile = new MemberProfileContractService();
    $alpha = memberProfileContext(101, 501, 'd02-profile-alpha');
    $beta = memberProfileContext(202, 502, 'd02-profile-beta');
    $contexts = $app->make(ExecutionContextStore::class);

    $contexts->run(ConsumerExecutionContext::member($alpha, 'test.member.profile.valid'), static function () use ($profile, $alpha): void {
        $profile->updateSelfField($alpha, 501, 'nickname', str_repeat('名', 50));
        $profile->updateSelfField($alpha, 501, 'sex', '2');
        $profile->updateSelfField($alpha, 501, 'birthday', '2000-02-29');
        $profile->updateSelfField($alpha, 501, 'email', str_repeat('a', 64) . '@' . str_repeat('b', 31) . '.com');
        $profile->updateSelfField($alpha, 501, 'avatar', str_repeat('a', 255));
    });
    expectMemberProfile(
        (string) $contexts->run(ConsumerExecutionContext::member($alpha, 'test.member.profile.read'), static fn() => Member::where([])->where('id', 501)->value('nickname')) === str_repeat('名', 50),
        'valid nickname was not persisted',
    );

    $contexts->run(ConsumerExecutionContext::member($alpha, 'test.member.profile.invalid'), static function () use ($profile, $alpha): void {
        rejectMemberProfile(static fn() => $profile->updateSelfField($alpha, 501, 'sex', '3'), 'invalid sex');
        rejectMemberProfile(static fn() => $profile->updateSelfField($alpha, 501, 'nickname', ['array']), 'array nickname');
        rejectMemberProfile(static fn() => $profile->updateSelfField($alpha, 501, 'nickname', str_repeat('a', 51)), 'long nickname');
        rejectMemberProfile(static fn() => $profile->updateSelfField($alpha, 501, 'avatar', str_repeat('a', 256)), 'long avatar');
        rejectMemberProfile(static fn() => $profile->updateSelfField($alpha, 501, 'email', 'not-an-email'), 'invalid email');
        rejectMemberProfile(static fn() => $profile->updateSelfField($alpha, 501, 'email', str_repeat('a', 89) . '@example.com'), 'long email');
        rejectMemberProfile(static fn() => $profile->updateSelfField($alpha, 501, 'birthday', '2025-02-29'), 'invalid birthday');
        rejectMemberProfile(static fn() => $profile->updateSelfField($alpha, 501, 'birthday', '0001-01-01'), 'out-of-range birthday');
        rejectMemberProfile(static fn() => $profile->updateSelfField($alpha, 501, 'birthday', "2000-01-01\0x"), 'null-byte birthday');
    });

    $contexts->run(ConsumerExecutionContext::member($alpha, 'test.member.profile.clear'), static function () use ($profile, $alpha): void {
        $profile->updateSelfField($alpha, 501, 'birthday', '');
        $profile->updateSelfField($alpha, 501, 'email', '');
        $profile->updateSelfField($alpha, 501, 'avatar', '');
    });
    expectMemberProfile(
        $contexts->run(ConsumerExecutionContext::member($alpha, 'test.member.profile.clear-read'), static fn() => Member::where([])->where('id', 501)->value('birthday')) === null,
        'empty birthday did not clear to NULL',
    );
    $contexts->run(ConsumerExecutionContext::member($alpha, 'test.member.profile.clear-repeat'), static function () use ($profile, $alpha): void {
        $profile->updateSelfField($alpha, 501, 'birthday', '');
    });
    try {
        $missing = memberProfileContext(101, 999, 'd02-profile-missing');
        $contexts->run(ConsumerExecutionContext::member($missing, 'test.member.profile.missing'), static fn() => $profile->updateSelfField($missing, 999, 'nickname', 'missing'));
        throw new RuntimeException('missing member profile update was accepted');
    } catch (BusinessException $exception) {
        expectMemberProfile($exception->errorCode === 'MEMBER_NOT_FOUND', 'missing-member rejection changed');
    }

    try {
        $contexts->run(ConsumerExecutionContext::member($alpha, 'test.member.profile.cross-tenant'), static fn() => $profile->updateSelfField($alpha, 502, 'nickname', 'forbidden'));
        throw new RuntimeException('cross-tenant profile update was accepted');
    } catch (BusinessException $exception) {
        expectMemberProfile($exception->errorCode === 'MEMBER_PROFILE_SELF_FORBIDDEN', 'cross-tenant rejection changed');
    }
    expectMemberProfile(
        (string) $contexts->run(ConsumerExecutionContext::member($beta, 'test.member.profile.cross-tenant-read'), static fn() => Member::where([])->where('id', 502)->value('nickname')) === 'Beta',
        'cross-tenant profile update mutated the target',
    );
    try {
        $contexts->run(ConsumerExecutionContext::member($alpha, 'test.member.profile.other-member'), static fn() => $profile->updateSelfField($alpha, 503, 'nickname', 'forbidden'));
        throw new RuntimeException('same-tenant other-member profile update was accepted');
    } catch (BusinessException $exception) {
        expectMemberProfile($exception->errorCode === 'MEMBER_PROFILE_SELF_FORBIDDEN', 'same-tenant rejection changed');
    }
    expectMemberProfile(
        (string) $contexts->run(ConsumerExecutionContext::member($alpha, 'test.member.profile.other-member-read'), static fn() => Member::where([])->where('id', 503)->value('nickname')) === 'Alpha peer',
        'same-tenant other-member profile update mutated the target',
    );
} finally {
    IsolatedBackendEnvironment::cleanup();
    if ($createdDatabase && $adminPdo instanceof PDO) {
        $adminPdo->exec('DROP DATABASE peanut_stabilization_d02');
    }
}

echo "D02-MEMBER-PROFILE-FIELD-VALIDATION-001 passed ({$memberProfileAssertions} assertions)\n";
