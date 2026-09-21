<?php
declare(strict_types=1);

namespace app\modules\official\member\model;

use app\common\model\TenantOwnedModel;

/** 会员端可撤销会话；只持久化随机会话密钥的摘要。 */
final class MemberSession extends TenantOwnedModel
{
    protected $name = 'member_session';
    protected $autoWriteTimestamp = false;
}
