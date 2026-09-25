<?php

declare(strict_types=1);

namespace PeanutAdmin\Modules\Identity\Contract;

use app\common\execution\CurrentExecutionContext;
use PeanutAdmin\Modules\Identity\Persistence\Model\TenantMember;

/** Non-ORM read boundary for the native Account/TenantMember directory. */
final readonly class AdminDirectoryQuery
{
    public function __construct(
        private CurrentExecutionContext $execution,
    ) {}

    /** @return list<array<string,mixed>> */
    public function rows(array $filters): array
    {
        $context = $this->execution->tenantAdmin();
        $query = TenantMember::alias('member')
            ->join('account account', 'account.id = member.account_id')
            ->join('credential credential', "credential.account_id = account.id AND credential.identifier_type = 'email'")
            ->leftJoin('department department', 'department.tenant_id = member.tenant_id AND department.id = member.primary_department_id')
            ->leftJoin('member_role membership', 'membership.tenant_id = member.tenant_id AND membership.tenant_member_id = member.id')
            ->leftJoin('role role', 'role.tenant_id = membership.tenant_id AND role.id = membership.role_id')
            ->where('member.tenant_id', $context->tenantId)
            ->field([
                'member.id', 'member.account_id', 'member.display_name', 'member.primary_department_id', 'member.status',
                'member.created_at', 'member.updated_at', 'account.avatar_uri', 'account.last_login_at',
                // ThinkORM 数组字段以表达式为键、响应别名为值，保持原查询输出合同。
                'credential.identifier_normalized' => 'username', 'department.name' => 'department_name',
            ])
            ->fieldRaw("GROUP_CONCAT(DISTINCT role.id ORDER BY role.id SEPARATOR ',') AS role_ids")
            ->fieldRaw("GROUP_CONCAT(DISTINCT role.name ORDER BY role.`key` SEPARATOR '/') AS role_name")
            ->fieldRaw("MAX(CASE WHEN role.`key` = 'core.tenant-owner' AND role.is_builtin = 1 AND role.status = 'active' THEN 1 ELSE 0 END) AS root");
        if (!empty($filters['account'])) {
            $query->whereLike('credential.identifier_normalized', '%' . trim((string) $filters['account']) . '%');
        }
        if (!empty($filters['name'])) {
            $query->whereLike('member.display_name', '%' . trim((string) $filters['name']) . '%');
        }
        if (!empty($filters['id'])) {
            $query->where('member.id', (int) $filters['id']);
        }
        if (!empty($filters['role_id'])) {
            $query->join('member_role filter_membership', 'filter_membership.tenant_id = member.tenant_id AND filter_membership.tenant_member_id = member.id')
                ->where('filter_membership.role_id', (int) $filters['role_id']);
        }
        return $query->group('member.id,member.account_id,member.display_name,member.primary_department_id,member.status,member.created_at,member.updated_at,account.avatar_uri,account.last_login_at,credential.identifier_normalized,department.name')
            ->order('member.id', 'desc')->select()->toArray();
    }

    /** @return null|array{id:int,account_id:int,authorization_revision:int} */
    public function activeTenantOwner(int $tenantId, ?int $memberId, ?int $accountId): ?array
    {
        $query = TenantMember::alias('member')
            ->join('account account', "account.id = member.account_id AND account.status = 'active'")
            ->join('member_role membership', 'membership.tenant_id = member.tenant_id AND membership.tenant_member_id = member.id')
            ->join('role role', "role.tenant_id = membership.tenant_id AND role.id = membership.role_id AND role.`key` = 'core.tenant-owner' AND role.is_builtin = 1 AND role.status = 'active'")
            ->where('member.tenant_id', $tenantId)->where('member.status', 'active')
            ->field('member.id,member.account_id,member.authorization_revision');
        if ($memberId !== null && $accountId !== null) {
            $query->where('member.id', $memberId)->where('member.account_id', $accountId);
        }
        $owner = $query->order('member.id')->find();

        return is_array($owner) ? [
            'id' => (int) $owner['id'],
            'account_id' => (int) $owner['account_id'],
            'authorization_revision' => (int) $owner['authorization_revision'],
        ] : null;
    }
}
