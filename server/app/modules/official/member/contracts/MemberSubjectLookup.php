<?php
declare(strict_types=1);

namespace app\modules\official\member\contracts;

use app\modules\official\member\contracts\dto\MemberSessionSubject;

/** Resolves the owning Tenant for an active member authentication subject. */
interface MemberSubjectLookup
{
    public function tenantId(int $memberId): ?int;

    /** 返回启用会员的权威租户归属和当前会话安全修订。 */
    public function sessionSubject(int $memberId): ?MemberSessionSubject;
}
