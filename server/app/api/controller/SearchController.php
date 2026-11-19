<?php

declare(strict_types=1);

namespace app\api\controller;

use think\App;

use app\api\services\SearchApplicationService;

class SearchController extends BaseApiController
{
    protected readonly SearchApplicationService $search;

    public function __construct(App $app, ?SearchApplicationService $search = null)
    {
        parent::__construct($app);
        $this->search = $search ?? $app->make(SearchApplicationService::class);
    }
    /** 热门搜索 */
    public function hotLists()
    {
        $result = $this->search->hotLists(
            $this->publicTenantContext('hot-search.lists'),
        );
        return $this->data($result);
    }
}
