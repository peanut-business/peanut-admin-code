<?php
declare(strict_types=1);

namespace PeanutAdmin\Modules\Article\Contract;

use app\common\http\PageResult;

/** Public administrative use cases for the Article Module. */
interface ArticleAdministration
{
    public function lists(array $params): PageResult;

    /** @return array<string,mixed> */
    public function detail(int $id): array;

    public function add(array $params): void;

    public function edit(array $params): void;

    public function delete(int $id): void;

    public function updateStatus(int $id, int $isShow): void;

    public function categoryLists(array $params): PageResult;

    /** @return list<array<string,mixed>> */
    public function allCategories(): array;

    /** @return array<string,mixed> */
    public function categoryDetail(int $id): array;

    public function addCategory(array $params): void;

    public function editCategory(array $params): void;

    public function deleteCategory(int $id): void;

    public function updateCategoryStatus(int $id, int $isShow): void;
}
