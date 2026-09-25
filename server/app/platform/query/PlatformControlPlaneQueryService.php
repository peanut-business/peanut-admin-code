<?php

declare(strict_types=1);

namespace app\platform\query;

use app\common\contract\module\ModuleQualification;
use app\common\contract\module\ModuleQualificationQuery;
use app\platform\context\PlatformOperatorContext;
use app\platform\services\PlatformOperatorSessionService;
use PeanutAdmin\Kernel\Authorization\Application\AdminAccessException;
use PeanutAdmin\Kernel\Authorization\Application\PageRequest;
use think\db\Query;
use think\facade\Db;

final readonly class PlatformControlPlaneQueryService
{
    public function __construct(
        private PlatformOperatorSessionService $sessions,
        private ModuleQualificationQuery $qualification,
    ) {}

    /** @return array{items:list<array<string,mixed>>,total:int} */
    public function operators(PlatformOperatorContext $context, PageRequest $page): array
    {
        $this->sessions->assertAllowed($context, 'platform.operator.read');
        $query = Db::name('platform_operator')->alias('operator')
            ->join('account account', 'account.id=operator.account_id')
            ->leftJoin('credential credential', "credential.account_id=account.id AND credential.identifier_type='email' AND credential.status='active'")
            ->leftJoin('platform_operator_role membership', 'membership.platform_operator_id=operator.id')
            ->leftJoin('platform_role role', 'role.id=membership.platform_role_id')
            ->field('operator.id,operator.account_id,operator.display_name,operator.status,operator.security_revision,operator.created_at,operator.updated_at')
            ->field('account.display_name AS account_display_name,account.status AS account_status,credential.identifier_normalized AS email')
            ->fieldRaw("COALESCE(GROUP_CONCAT(DISTINCT role.`key` ORDER BY role.`key` SEPARATOR ','),'') AS role_keys")
            ->group('operator.id,operator.account_id,operator.display_name,operator.status,operator.security_revision,account.display_name,account.status,credential.identifier_normalized,operator.created_at,operator.updated_at')
            ->order('operator.id', 'desc');
        return $this->paginate($query, $page, (int) Db::name('platform_operator')->count(), static function (array $row): array {
            $row['role_keys'] = $row['role_keys'] === '' ? [] : explode(',', (string) $row['role_keys']);
            return $row;
        });
    }

    /** @return array{items:list<array<string,mixed>>,total:int} */
    public function roles(PlatformOperatorContext $context, PageRequest $page): array
    {
        $this->sessions->assertAllowed($context, 'platform.role.read');
        $query = Db::name('platform_role')->alias('role')
            ->leftJoin('platform_role_permission binding', 'binding.platform_role_id=role.id')
            ->leftJoin('permission permission', "permission.id=binding.permission_id AND permission.status='active'")
            ->field('role.id,role.key,role.name,role.description,role.is_builtin,role.status,role.revision,role.created_at,role.updated_at')
            ->fieldRaw('COUNT(DISTINCT binding.permission_id) AS permission_count')
            ->fieldRaw("COALESCE(GROUP_CONCAT(DISTINCT permission.`key` ORDER BY permission.`key` SEPARATOR ','),'') AS permission_keys")
            ->group('role.id,role.key,role.name,role.description,role.is_builtin,role.status,role.revision,role.created_at,role.updated_at')
            ->order('role.id', 'desc');
        return $this->paginate($query, $page, (int) Db::name('platform_role')->count(), static function (array $row): array {
            $row['permission_keys'] = $row['permission_keys'] === '' ? [] : explode(',', (string) $row['permission_keys']);
            return $row;
        });
    }

    /** @return array{items:list<array<string,mixed>>,total:int} */
    public function permissions(PlatformOperatorContext $context, PageRequest $page): array
    {
        $this->sessions->assertAllowed($context, 'platform.permission.read');
        $query = Db::name('permission')->where('module_key', 'platform');
        return $this->paginate((clone $query)
            ->field('id,key,module_key,type,name,description,risk_level,status,manifest_version,created_at,updated_at,retired_at')
            ->order('id'), $page, (int) $query->count());
    }

    /** @return array{items:list<array<string,mixed>>,total:int} */
    public function audit(PlatformOperatorContext $context, PageRequest $page): array
    {
        $this->sessions->assertAllowed($context, 'platform.audit.read');
        $result = $this->paginate(Db::name('platform_audit_event')
            ->field('id,event_type,action,outcome,reason_code,operator_id,account_id,target_type,target_id,request_id,operation_id,ip_address,user_agent_hash,before_json,after_json,metadata_json,occurred_at')
            ->order('id', 'desc'), $page, (int) Db::name('platform_audit_event')->count());
        foreach ($result['items'] as &$item) {
            foreach (['before_json', 'after_json', 'metadata_json'] as $column) {
                $item[$column] = $this->decodeJson($item[$column] ?? null);
            }
        }
        unset($item);
        return $result;
    }

    /** @return array{items:list<array<string,mixed>>,total:int} */
    public function moduleStates(PlatformOperatorContext $context, int $tenantId, PageRequest $page): array
    {
        $this->sessions->assertAllowed($context, 'platform.tenant.read');
        $states = [];
        foreach ($this->qualification->tenantModuleStates($tenantId) as $state) {
            $states[$state->moduleKey] = $state->toArray();
        }
        $rows = array_map(static function (ModuleQualification $module) use ($states, $tenantId): array {
            $state = $states[$module->moduleKey] ?? null;
            return [
                'id' => $state['id'] ?? null, 'tenant_id' => $tenantId, 'module_key' => $module->moduleKey,
                'status' => $state['status'] ?? 'not_enabled', 'source' => $state['source'] ?? 'not_configured',
                'config_revision' => $state['config_revision'] ?? 0, 'effective_at' => $state['effective_at'] ?? null,
                'expires_at' => $state['expires_at'] ?? null, 'enabled_at' => $state['enabled_at'] ?? null,
                'disabled_at' => $state['disabled_at'] ?? null, 'disabled_reason' => $state['disabled_reason'] ?? null,
                'created_at' => $state['created_at'] ?? null, 'updated_at' => $state['updated_at'] ?? null,
                'installed_version' => $module->version, 'installation_status' => $module->status,
            ];
        }, $this->qualification->installedModules());
        return ['items' => array_slice($rows, $page->offset(), $page->pageSize), 'total' => count($rows)];
    }

    /** @return array<string,mixed> */
    public function owner(PlatformOperatorContext $context, int $tenantId): array
    {
        $this->sessions->assertAllowed($context, 'platform.tenant.read');
        $row = Db::name('tenant_member')->alias('member')
            ->join('account account', 'account.id=member.account_id')
            ->join('member_role membership', 'membership.tenant_id=member.tenant_id AND membership.tenant_member_id=member.id')
            ->join('role role', "role.tenant_id=membership.tenant_id AND role.id=membership.role_id AND role.`key`='core.tenant-owner'")
            ->leftJoin('credential credential', "credential.account_id=account.id AND credential.identifier_type='email' AND credential.status='active'")
            ->where('member.tenant_id', $tenantId)
            ->field('member.id AS member_id,member.tenant_id,member.account_id,member.display_name,member.status AS member_status,member.security_revision,member.authorization_revision,member.joined_at,member.created_at,member.updated_at')
            ->field('account.display_name AS account_display_name,account.status AS account_status,credential.identifier_normalized AS email,role.id AS role_id,role.key AS role_key')
            ->order('member.id')->find();
        if ($row === null) {
            throw AdminAccessException::notFound();
        }
        return $row;
    }

    /** @return array{items:list<array<string,mixed>>,total:int} */
    private function paginate(Query $query, PageRequest $page, int $total, ?callable $map = null): array
    {
        $items = $query->limit($page->offset(), $page->pageSize)->select()->toArray();
        if ($map !== null) {
            $items = array_map($map, $items);
        }
        return ['items' => $items, 'total' => $total];
    }

    /** @return array<string,mixed>|null */
    private function decodeJson(mixed $value): ?array
    {
        if (!is_string($value) || trim($value) === '') {
            return null;
        }
        $decoded = json_decode($value, true);
        return is_array($decoded) ? $decoded : null;
    }
}
