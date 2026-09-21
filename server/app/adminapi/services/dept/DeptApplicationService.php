<?php
declare(strict_types=1);

namespace app\adminapi\services\dept;

use PeanutAdmin\Modules\Identity\Organization\Application\DepartmentAdminService;
use PeanutAdmin\Kernel\Auth\TenantContext;
use PeanutAdmin\Kernel\Authorization\Application\PageRequest;

/** Compatibility department tree backed by native pa_department. */
final class DeptApplicationService
{
    public function __construct(private readonly DepartmentAdminService $departments)
    {
    }

    public function validationRules(string $scene): array
    {
        $rules = ['id' => 'require|integer|gt:0', 'name' => 'require|length:1,120',
            'pid' => 'require|integer|egt:0', 'leader' => 'max:50', 'mobile' => 'max:20',
            'sort' => 'integer|egt:0', 'status' => 'require|in:0,1'];
        if ($scene === 'add') unset($rules['id']);
        return $rules;
    }

    public function lists(TenantContext $context, array $params = []): array
    {
        $items = $this->service()->list($context->tenantId, new PageRequest(1, 100))['items'];
        $items = array_values(array_filter($items, static fn(array $row): bool =>
            (empty($params['name']) || str_contains((string)$row['name'], trim((string)$params['name'])))
            && (!isset($params['status']) || $params['status'] === '' || self::statusInt($row['status']) === (int)$params['status'])));
        return self::buildTree(array_map([self::class, 'compat'], $items));
    }

    public function all(TenantContext $context): array
    {
        return self::lists($context, ['status' => 1]);
    }

    public function leaderDept(TenantContext $context): array
    {
        $flat = [];
        foreach ($this->service()->list($context->tenantId, new PageRequest(1, 100))['items'] as $row) {
            if ($row['status'] === 'active') $flat[] = ['id' => (int)$row['id'], 'name' => $row['name']];
        }
        return $flat;
    }

    public function detail(TenantContext $context, int $id): array
    {
        return self::compat($this->service()->get($context->tenantId, $id));
    }

    public function add(TenantContext $context, array $params): bool
    {
        // 写命令传递认证上下文，禁止由请求参数拼接租户或操作者身份。
        $department = $this->service()->create($context, self::code($params), (string)$params['name'],
                (int)$params['pid'] > 0 ? (int)$params['pid'] : null, (int)($params['sort'] ?? 0));
            if ((int)$params['status'] === 0) $this->runtime->setStatus($department, 0);
        return true;
    }

    public function edit(TenantContext $context, array $params): bool
    {
        $service = $this->service();
            $current = $service->get($context->tenantId, (int)$params['id']);
            $updated = $service->update($context, (int)$params['id'], (string)$current['code'],
                (string)$params['name'], (int)($params['sort'] ?? 0), (int)$current['revision']);
            $parent = (int)$params['pid'] > 0 ? (int)$params['pid'] : null;
            $currentParent = $updated['parent_id'] === null ? null : (int)$updated['parent_id'];
            if ($parent !== $currentParent) {
                $updated = $service->move($context, (int)$params['id'], $parent, (int)$updated['revision']);
            }
            $this->runtime->setStatus($updated, (int)$params['status']);
        return true;
    }

    public function delete(TenantContext $context, int $id): bool
    {
        $service = $this->service(); $row = $service->get($context->tenantId, $id);
            $service->archive($context, $id, (int)$row['revision']);
        return true;
    }

    public function updateStatus(TenantContext $context, int $id, int $status): bool
    {
        $department = $this->departments->get($context->tenantId, $id);
        $this->departments->setStatus($context, $id, (int)$department['revision'], $status === 1);
        return true;
    }

    private static function compat(array $row): array
    {
        return ['id' => (int)$row['id'], 'pid' => $row['parent_id'] === null ? 0 : (int)$row['parent_id'],
            'code' => $row['code'], 'name' => $row['name'], 'leader' => '', 'mobile' => '',
            'sort' => (int)$row['sort_order'], 'status' => self::statusInt($row['status']),
            'is_disable' => self::statusInt($row['status']) === 1 ? 0 : 1,
            'status_desc' => self::statusInt($row['status']) === 1 ? '正常' : '停用', 'revision' => (int)$row['revision']];
    }

    private static function buildTree(array $data, int $pid = 0, int $level = 0): array
    {
        $tree = [];
        foreach ($data as $item) if ($item['pid'] === $pid) { $item['level'] = $level; $item['children'] = self::buildTree($data, $item['id'], $level + 1); $tree[] = $item; }
        return $tree;
    }

    private static function statusInt(string $status): int { return $status === 'active' ? 1 : 0; }
    private static function code(array $params): string { return 'application.department.' . bin2hex(random_bytes(8)); }
    private function service(): DepartmentAdminService { return $this->departments; }
}
