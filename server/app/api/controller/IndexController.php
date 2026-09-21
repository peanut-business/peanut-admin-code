<?php
declare(strict_types=1);

namespace app\api\controller;

use think\App;

use app\api\services\IndexApplicationService;
use PeanutAdmin\Kernel\Tenancy\TenantEntryBindingResolver;

class IndexController extends BaseApiController
{
    public function __construct(
        App $app,
        private readonly IndexApplicationService $index,
        private readonly TenantEntryBindingResolver $entryBindings,
    ) {
        parent::__construct($app);
    }


    /** 首页数据 */
    public function index()
    {
        $result = $this->index->getIndexData($this->publicTenantContext('article.index'));
        return $this->data($result);
    }

    /** 全局配置 */
    public function config()
    {
        $entryTenantId = $this->entryBindings->boundTenantId(
            $this->request,
            TenantEntryBindingResolver::ADMIN_CLIENT,
        );
        $result = $this->index->getConfigData(
            $this->publicTenantContext('decoration.config'),
            (string)$this->request->domain(),
            (string)$this->request->host(),
            $entryTenantId,
        );
        return $this->data($result);
    }

    /** 政策协议 */
    public function policy()
    {
        $type   = $this->request->get('type/s', 'service');
        $result = $this->index->getPolicyByType(
            $this->publicTenantContext('decoration.config'),
            $type,
        );
        return $this->data($result);
    }
}
