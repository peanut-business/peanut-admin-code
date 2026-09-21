<?php
declare(strict_types=1);

namespace PeanutAdmin\Modules\Article\Contract;

use app\common\http\PageResult;
use PeanutAdmin\Kernel\Auth\TenantContext;

/** 资讯后台公开用例；调用者必须提供当前受信租户，服务仍会复核人员权限。 */
interface ArticleAdministration
{
    public function lists(TenantContext $context, array $params): PageResult;

    /** @return array<string,mixed> */
    public function detail(TenantContext $context, int $id): array;

    public function add(TenantContext $context, array $params): bool;

    public function edit(TenantContext $context, array $params): bool;

    public function delete(TenantContext $context, int $id): bool;

    public function updateStatus(TenantContext $context, int $id, int $isShow): bool;

    public function recycleLists(TenantContext $context, array $params): PageResult;

    /** @return array<string,mixed> */
    public function recycleDetail(TenantContext $context, int $id): array;

    /** @param list<int> $ids @return array<string,mixed> */
    public function restore(TenantContext $context, array $ids): array;

    /** @param list<int> $ids @return array<string,mixed> */
    public function forceDelete(TenantContext $context, array $ids): array;
}
