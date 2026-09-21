<?php
declare(strict_types=1);

namespace app\api\controller;

use app\api\services\SearchApplicationService;

class SearchController extends BaseApiController
{
    protected function search(): SearchApplicationService
    {
        return $this->app->make(SearchApplicationService::class);
    }


    /** 热门搜索 */
    public function hotLists()
    {
        $result = $this->search()->hotLists(
            $this->publicTenantContext('hot-search.lists')
        );
        return $this->data($result);
    }
}
