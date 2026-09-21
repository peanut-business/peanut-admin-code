<?php

declare(strict_types=1);

namespace app\modules\official\identity\identity\query;

use app\modules\official\identity\contracts\PlatformOperatorIdentityQuery;
use think\facade\Db;

final readonly class ThinkPhpPlatformOperatorIdentityQuery implements PlatformOperatorIdentityQuery
{
    public function accountId(int $operatorId): int
    {
        if ($operatorId < 1) {
            throw new \InvalidArgumentException('PLATFORM_OPERATOR_ID_INVALID');
        }
        $accountId = Db::name('platform_operator')->where('id', $operatorId)->value('account_id');
        if (!is_numeric($accountId) || (int) $accountId < 1) {
            throw new \RuntimeException('PLATFORM_OPERATOR_IDENTITY_UNAVAILABLE');
        }

        return (int) $accountId;
    }
}
