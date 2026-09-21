<?php
declare(strict_types=1);

namespace tests\Unit;

use app\api\services\UserTokenService;
use PeanutAdmin\Modules\Member\Contract\MemberSessionStore;
use PeanutAdmin\Modules\Member\Contract\MemberSubjectLookup;
use PeanutAdmin\Modules\Member\Contract\Dto\MemberSessionRecord;
use PeanutAdmin\Modules\Member\Contract\Dto\MemberSessionSubject;
use PeanutAdmin\Modules\Member\Service\MemberSessionService;
use Firebase\JWT\JWT;
use PHPUnit\Framework\TestCase;

/** 真实 JWT 签发/解析配合公开会话持久化接口的行为测试。 */
final class MemberSessionTokenTest extends TestCase
{
    private const SECRET = 'member-session-unit-test-secret-00000000000000000000';

    public function testIssuedTokenIsVerifiedAndCurrentLogoutRevokesOnlyThatSession(): void
    {
        [$tokens, , $store] = $this->fixture();
        $first = $tokens->createToken(7);
        $second = $tokens->createToken(7);

        self::assertSame(7, $tokens->parseToken($first));
        self::assertSame(7, $tokens->parseToken($second));

        $tokens->revokeToken($first);
        $this->assertRejected($tokens, $first);
        self::assertSame(7, $tokens->parseToken($second));
        self::assertSame(1, $store->revokedCount('logout'));
    }

    public function testPasswordRevisionAndRevokeAllInvalidateEveryExistingDevice(): void
    {
        [$tokens, $subjects, $store, $sessions] = $this->fixture();
        $web = $tokens->createToken(7);
        $app = $tokens->createToken(7);

        // 生产改密/重置在同一数据库事务中先递增修订，再撤销该会员全部会话。
        $subjects->revision++;
        $sessions->revokeAll(31, 7, 'password_change', time());

        $this->assertRejected($tokens, $web);
        $this->assertRejected($tokens, $app);
        self::assertSame(2, $store->revokedCount('password_change'));
        $afterChange = $tokens->createToken(7);
        self::assertSame(7, $tokens->parseToken($afterChange));

        $subjects->revision++;
        $sessions->revokeAll(31, 7, 'password_reset', time());
        $this->assertRejected($tokens, $afterChange);
        self::assertSame(1, $store->revokedCount('password_reset'));
    }

    public function testDisabledMemberAndStorageFailureBothFailClosed(): void
    {
        [$tokens, $subjects, $store] = $this->fixture();
        $token = $tokens->createToken(7);

        $subjects->active = false;
        $this->assertRejected($tokens, $token);

        $subjects->active = true;
        $store->failReads = true;
        $this->assertRejected($tokens, $token);

        $store->failReads = false;
        $store->failWrites = true;
        $this->expectException(\RuntimeException::class);
        $tokens->createToken(7);
    }

    public function testLegacySignedJwtWithoutServerSessionClaimsIsRejected(): void
    {
        [$tokens] = $this->fixture();
        $now = time();
        $legacy = JWT::encode([
            'iss' => 'peanut-admin',
            'aud' => 'peanut-admin-member-api',
            'sub' => 'member:7',
            'iat' => $now,
            'nbf' => $now,
            'exp' => $now + 600,
            'member_id' => 7,
        ], self::SECRET, 'HS256');

        $this->assertRejected($tokens, $legacy);
    }

    /** @return array{UserTokenService,MutableMemberSubjectLookup,InMemoryMemberSessionStore,MemberSessionService} */
    private function fixture(): array
    {
        $subjects = new MutableMemberSubjectLookup(31, 7, 1);
        $store = new InMemoryMemberSessionStore();
        $sessions = new MemberSessionService($subjects, $store);
        return [new UserTokenService(self::SECRET, 600, $sessions), $subjects, $store, $sessions];
    }

    private function assertRejected(UserTokenService $tokens, string $token): void
    {
        try {
            $tokens->parseToken($token);
            self::fail('Expected the member token to be rejected.');
        } catch (\UnexpectedValueException $exception) {
            self::assertNotSame('', $exception->getMessage());
        }
    }
}

final class MutableMemberSubjectLookup implements MemberSubjectLookup
{
    public bool $active = true;

    public function __construct(
        public int $tenantIdValue,
        public int $memberId,
        public int $revision,
    ) {
    }

    public function tenantId(int $memberId): ?int
    {
        return $this->sessionSubject($memberId)?->tenantId;
    }

    public function sessionSubject(int $memberId): ?MemberSessionSubject
    {
        return $this->active && $memberId === $this->memberId
            ? new MemberSessionSubject($this->tenantIdValue, $this->memberId, $this->revision)
            : null;
    }
}

final class InMemoryMemberSessionStore implements MemberSessionStore
{
    /** @var array<string,array{record:MemberSessionRecord,reason:?string}> */
    private array $records = [];
    public bool $failReads = false;
    public bool $failWrites = false;

    public function create(MemberSessionRecord $session): void
    {
        if ($this->failWrites) {
            throw new \RuntimeException('synthetic storage failure');
        }
        if (isset($this->records[$session->sessionHash])) {
            throw new \RuntimeException('duplicate session');
        }
        $this->records[$session->sessionHash] = ['record' => $session, 'reason' => null];
    }

    public function findByHash(string $sessionHash): ?MemberSessionRecord
    {
        if ($this->failReads) {
            throw new \RuntimeException('synthetic storage failure');
        }
        return $this->records[$sessionHash]['record'] ?? null;
    }

    public function revoke(string $sessionHash, int $tenantId, int $memberId, string $reason, int $revokedAt): bool
    {
        $entry = $this->records[$sessionHash] ?? null;
        if ($entry === null
            || $entry['record']->tenantId !== $tenantId
            || $entry['record']->memberId !== $memberId
            || $entry['record']->revokedAt !== null) {
            return false;
        }
        $this->records[$sessionHash] = [
            'record' => self::revoked($entry['record'], $revokedAt),
            'reason' => $reason,
        ];
        return true;
    }

    public function revokeAll(int $tenantId, int $memberId, string $reason, int $revokedAt): void
    {
        foreach ($this->records as $hash => $entry) {
            if ($entry['record']->tenantId === $tenantId
                && $entry['record']->memberId === $memberId
                && $entry['record']->revokedAt === null) {
                $this->records[$hash] = [
                    'record' => self::revoked($entry['record'], $revokedAt),
                    'reason' => $reason,
                ];
            }
        }
    }

    public function revokedCount(string $reason): int
    {
        return count(array_filter(
            $this->records,
            static fn(array $entry): bool => $entry['reason'] === $reason,
        ));
    }

    private static function revoked(MemberSessionRecord $record, int $revokedAt): MemberSessionRecord
    {
        return new MemberSessionRecord(
            $record->sessionHash,
            $record->tenantId,
            $record->memberId,
            $record->sessionRevision,
            $record->issuedAt,
            $record->expiresAt,
            $revokedAt,
        );
    }
}
