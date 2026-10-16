<?php

declare(strict_types=1);

namespace app\adminapi\services\dept;

use DateTimeImmutable;
use DateTimeZone;
use app\common\dto\authorization\AdminPrincipal;
use app\common\exception\BusinessException;
use app\common\execution\CurrentExecutionContext;
use app\common\services\authorization\AdminAuthorizationService;
use PeanutAdmin\Modules\Identity\Organization\Application\DepartmentAdminService;
use PeanutAdmin\Kernel\Auth\TenantContext;
use PeanutAdmin\Kernel\Authorization\Application\PageRequest;
use think\facade\Db;

/** Bounded department directory backed by native Identity queries. */
final class DeptApplicationService
{
    public function __construct(
        private readonly DepartmentAdminService $departments,
        private readonly CurrentExecutionContext $executionContext,
        private readonly AdminAuthorizationService $authorization,
    ) {}

    public function validationRules(string $scene): array
    {
        $rules = ['id' => 'require|integer|gt:0', 'name' => 'require|length:1,120',
            'pid' => 'require|integer|egt:0', 'leader' => 'max:50', 'mobile' => 'max:20',
            'sort' => 'integer|egt:0', 'status' => 'require|in:0,1'];
        if ($scene === 'add') {
            unset($rules['id']);
        }
        return $rules;
    }

    public function lists(TenantContext $context, array $params = []): array
    {
        return $this->directory($context, $params, false, false);
    }

    public function all(TenantContext $context, array $params = []): array
    {
        return $this->directory($context, $params, true, false);
    }

    public function leaderDept(TenantContext $context, array $params = []): array
    {
        return $this->directory($context, $params, true, true);
    }

    private function directory(TenantContext $context, array $params, bool $activeOnly, bool $flat): array
    {
        $invalid = static fn() => BusinessException::invalid('DEPARTMENT_DIRECTORY_INVALID', '部门目录需要明确的模式和有界分页参数');
        if (array_diff(array_keys($params), ['mode', 'parent_id', 'name', 'status', 'ids', 'pageNo', 'pageSize']) !== []) { throw $invalid(); }
        $integer = static function (mixed $value, int $min, int $max) use ($invalid): int {
            if ((!is_int($value) && !is_string($value)) || !preg_match('/^(0|[1-9][0-9]*)$/D', (string) $value)
                || filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => $min, 'max_range' => $max]]) === false) { throw $invalid(); }
            return (int) $value;
        };
        $pageNo = $integer($params['pageNo'] ?? null, 1, PHP_INT_MAX);
        $pageSize = $integer($params['pageSize'] ?? null, 1, 100);
        if (($pageNo - 1) > intdiv(PHP_INT_MAX, $pageSize)) { throw $invalid(); }
        $mode = $params['mode'] ?? null;
        if (!in_array($mode, ['children', 'search', 'resolve'], true) || ($flat && $mode === 'children')) { throw $invalid(); }
        $name = $params['name'] ?? '';
        if (!is_string($name) || mb_strlen($name) > 120) { throw $invalid(); }
        $name = trim($name);
        $status = null;
        if (array_key_exists('status', $params)) {
            if (!in_array($params['status'], [0, 1, '0', '1'], true)) { throw $invalid(); }
            $status = (int) $params['status'] === 1 ? 'active' : 'disabled';
        }
        $parent = 0;
        $ids = [];
        if ($mode === 'children') {
            $parent = $integer($params['parent_id'] ?? null, 0, PHP_INT_MAX);
            if ($name !== '' || $status !== null || isset($params['ids'])) { throw $invalid(); }
        } elseif (isset($params['parent_id'])) { throw $invalid(); }
        if ($mode === 'resolve') {
            if (!is_string($params['ids'] ?? null) || $name !== '' || $status !== null || $pageNo !== 1) { throw $invalid(); }
            $parts = explode(',', $params['ids']);
            if (count($parts) > $pageSize) { throw $invalid(); }
            $ids = array_values(array_unique(array_map(fn($id): int => $integer($id, 1, PHP_INT_MAX), $parts)));
        } elseif (isset($params['ids'])) { throw $invalid(); }
        if ($activeOnly && $status === 'disabled') { throw $invalid(); }
        $result = $this->departments->directory($context->tenantId, new PageRequest($pageNo, $pageSize), $mode, $parent, $name, $status, $ids, $activeOnly, $flat);
        $project = static function (array $row): array {
            $item = ['id' => $row['id'], 'pid' => $row['parent_id'] ?? 0,
            'name' => $row['name'], 'sort' => $row['sort_order'], 'realStatus' => $row['status'],
            'status' => $row['status'] === 'active' ? 1 : ($row['status'] === 'disabled' ? 0 : -1),
            'status_desc' => match ($row['status']) { 'active' => '正常', 'disabled' => '停用', default => '已归档' },
            'structuralOnly' => $row['structuralOnly'], 'selectable' => $row['selectable'], 'hasChildren' => $row['hasChildren']]
            + array_intersect_key($row, array_flip(['code', 'leader', 'mobile', 'revision']));
            foreach (['created_at' => 'create_time', 'updated_at' => 'update_time'] as $source => $target) {
                if (isset($row[$source])) {
                    $item[$target] = (new DateTimeImmutable((string) $row[$source], new DateTimeZone('UTC')))->format('Y-m-d\TH:i:s.v\Z');
                }
            }
            return $item;
        };
        return ['lists' => array_map($project, $result['items']), 'ancestors' => array_map($project, $result['ancestors']),
            'count' => $result['total'], 'pageNo' => $pageNo, 'pageSize' => $pageSize,
            'hasMore' => ($pageNo - 1) * $pageSize < $result['total']
                && count($result['items']) < $result['total'] - ($pageNo - 1) * $pageSize];
    }

    public function detail(TenantContext $context, int $id): array
    {
        return self::compat($this->service()->get($context->tenantId, $id));
    }

    public function add(TenantContext $context, array $params): bool
    {
        // 元数据与状态属于同一个用户动作；失败时连同所属模块的审计/修订一起回滚。
        return Db::transaction(function () use ($context, $params): bool {
            $department = $this->departments->create(
                $context,
                self::code($params),
                (string) $params['name'],
                (int) $params['pid'] > 0 ? (int) $params['pid'] : null,
                (int) ($params['sort'] ?? 0),
                (string) ($params['leader'] ?? ''),
                (string) ($params['mobile'] ?? ''),
            );
            if ((int) $params['status'] === 0) {
                $this->departments->setStatus($context, (int) $department['id'], (int) $department['revision'], false);
            }
            return true;
        });
    }

    public function edit(TenantContext $context, array $params): bool
    {
        return Db::transaction(function () use ($context, $params): bool {
            $service = $this->departments;
            $current = $service->get($context->tenantId, (int) $params['id']);
            $parent = (int) $params['pid'] > 0 ? (int) $params['pid'] : null;
            $currentParent = $current['parent_id'] === null ? null : (int) $current['parent_id'];
            if ($parent !== $currentParent) {
                $this->assertMovePermission($context);
            }
            $updated = $service->update(
                $context,
                (int) $params['id'],
                (string) $current['code'],
                (string) $params['name'],
                (int) ($params['sort'] ?? 0),
                (int) $current['revision'],
                array_key_exists('leader', $params) ? (string) $params['leader'] : (string) $current['leader'],
                array_key_exists('mobile', $params) ? (string) $params['mobile'] : (string) $current['mobile'],
            );
            if ($parent !== $currentParent) {
                $updated = $service->move($context, (int) $params['id'], $parent, (int) $updated['revision']);
            }
            $service->setStatus($context, (int) $params['id'], (int) $updated['revision'], (int) $params['status'] === 1);
            return true;
        });
    }

    public function delete(TenantContext $context, int $id): bool
    {
        $service = $this->service();
        $row = $service->get($context->tenantId, $id);
        $service->archive($context, $id, (int) $row['revision']);
        return true;
    }

    public function updateStatus(TenantContext $context, int $id, int $status): bool
    {
        $department = $this->departments->get($context->tenantId, $id);
        $this->departments->setStatus($context, $id, (int) $department['revision'], $status === 1);
        return true;
    }

    private function assertMovePermission(TenantContext $context): void
    {
        try {
            $current = $this->executionContext->tenantAdmin();
            $actor = AdminPrincipal::fromArray($this->executionContext->tenantAdminPrincipal());
        } catch (\Throwable) {
            throw BusinessException::forbidden('DEPARTMENT_MOVE_PERMISSION_DENIED', '无权移动部门');
        }
        if ($current->tenantId !== $context->tenantId
            || $current->accountId !== $context->accountId
            || $current->memberId !== $context->memberId
            || $current->authorizationRevision !== $context->authorizationRevision
            || !$this->authorization->decide($context, $actor, 'core.department.move')->allowed
        ) {
            throw BusinessException::forbidden('DEPARTMENT_MOVE_PERMISSION_DENIED', '无权移动部门');
        }
    }

    private static function compat(array $row): array
    {
        $utc = new DateTimeZone('UTC');
        $createdAt = (new DateTimeImmutable((string) $row['created_at'], $utc))
            ->setTimezone($utc)->format('Y-m-d\TH:i:s.v\Z');
        $updatedAt = (new DateTimeImmutable((string) $row['updated_at'], $utc))
            ->setTimezone($utc)->format('Y-m-d\TH:i:s.v\Z');
        return ['id' => (int) $row['id'], 'pid' => $row['parent_id'] === null ? 0 : (int) $row['parent_id'],
            'code' => $row['code'], 'name' => $row['name'], 'leader' => $row['leader'], 'mobile' => $row['mobile'],
            'sort' => (int) $row['sort_order'], 'status' => self::statusInt($row['status']),
            'is_disable' => self::statusInt($row['status']) === 1 ? 0 : 1,
            'status_desc' => self::statusInt($row['status']) === 1 ? '正常' : '停用', 'revision' => (int) $row['revision'],
            'create_time' => $createdAt, 'update_time' => $updatedAt];
    }

    private static function statusInt(string $status): int
    {
        return $status === 'active' ? 1 : 0;
    }
    private static function code(array $params): string
    {
        return 'application.department.' . bin2hex(random_bytes(8));
    }
    private function service(): DepartmentAdminService
    {
        return $this->departments;
    }
}
