<?php
declare(strict_types=1);

namespace tests\Multitenancy;

use app\api\services\UserTokenService;
use PeanutAdmin\Modules\Member\Contract\MemberSessionStore;
use PeanutAdmin\Modules\Member\Contract\MemberSubjectLookup;
use PeanutAdmin\Modules\Member\Contract\Dto\MemberSessionRecord;
use PeanutAdmin\Modules\Member\Contract\Dto\MemberSessionSubject;
use PeanutAdmin\Modules\Member\Service\MemberSessionService;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use PHPUnit\Framework\TestCase;

/** 会员 Token 的租户声明不能覆盖会员模块的权威归属。 */
final class MemberSessionTenantIsolationTest extends TestCase
{
    private const SECRET = 'member-session-tenant-test-secret-000000000000000000';

    public function testValidSignatureWithForeignTenantClaimIsRejected(): void
    {
        $subjects = new TenantSubjectLookup([
            7 => new MemberSessionSubject(31, 7, 1),
            8 => new MemberSessionSubject(32, 8, 1),
        ]);
        $sessions = new MemberSessionService($subjects, new TenantSessionStore());
        $tokens = new UserTokenService(self::SECRET, 600, $sessions);
        $tenant31Token = $tokens->createToken(7);
        $tenant32Token = $tokens->createToken(8);

        self::assertSame(7, $tokens->parseToken($tenant31Token));
        self::assertSame(8, $tokens->parseToken($tenant32Token));

        $claims = (array)JWT::decode($tenant31Token, new Key(self::SECRET, 'HS256'));
        $claims['tenant_id'] = 32;
        $foreignTenantToken = JWT::encode($claims, self::SECRET, 'HS256');

        $this->expectException(\UnexpectedValueException::class);
        $tokens->parseToken($foreignTenantToken);
    }

    public function testAuthoritativeOwnershipChangeInvalidatesPreviousTenantSession(): void
    {
        $subjects = new TenantSubjectLookup([
            7 => new MemberSessionSubject(31, 7, 1),
        ]);
        $tokens = new UserTokenService(
            self::SECRET,
            600,
            new MemberSessionService($subjects, new TenantSessionStore()),
        );
        $token = $tokens->createToken(7);
        $subjects->subjects[7] = new MemberSessionSubject(32, 7, 2);

        $this->expectException(\UnexpectedValueException::class);
        $tokens->parseToken($token);
    }
}

final class TenantSubjectLookup implements MemberSubjectLookup
{
    /** @param array<int,MemberSessionSubject> $subjects */
    public function __construct(public array $subjects)
    {
    }

    public function tenantId(int $memberId): ?int
    {
        return $this->sessionSubject($memberId)?->tenantId;
    }

    public function sessionSubject(int $memberId): ?MemberSessionSubject
    {
        return $this->subjects[$memberId] ?? null;
    }
}

final class TenantSessionStore implements MemberSessionStore
{
    /** @var array<string,MemberSessionRecord> */
    private array $records = [];

    public function create(MemberSessionRecord $session): void
    {
        $this->records[$session->sessionHash] = $session;
    }

    public function findByHash(string $sessionHash): ?MemberSessionRecord
    {
        return $this->records[$sessionHash] ?? null;
    }

    public function revoke(string $sessionHash, int $tenantId, int $memberId, string $reason, int $revokedAt): bool
    {
        return false;
    }

    public function revokeAll(int $tenantId, int $memberId, string $reason, int $revokedAt): void
    {
    }
}
