<?php

declare(strict_types=1);

namespace app\api\controller;

use think\App;
use app\api\services\PcApplicationService;
use PeanutAdmin\Kernel\Tenancy\TenantEntryBindingResolver;

/**
 * PC 端聚合接口（部分端点返回更丰富的字段或不同格式）
 */
class PcController extends BaseApiController
{
    public function __construct(
        App $app,
        private readonly PcApplicationService $pcApplication,
        private readonly TenantEntryBindingResolver $entryBindings,
    ) {
        parent::__construct($app);
    }


    /** PC 配置 */
    public function config()
    {
        // Core 当前的可信 Host 绑定契约只接收 Request-like 对象，因此解析留在 HTTP 边界；
        // 解析后的租户编号和规范请求字段再交给 PC 应用用例完成聚合查询。
        $entryTenantId = $this->entryBindings->boundTenantId(
            $this->request,
            TenantEntryBindingResolver::ADMIN_CLIENT,
        );
        $result = $this->pcApplication->config(
            $this->publicTenantContext('decoration.config'),
            (string) $this->request->domain(),
            (string) $this->request->host(),
            $entryTenantId,
        );
        return $this->data($result);
    }

    /** PC 首页 */
    public function index()
    {
        $result = $this->pcApplication->getIndexData($this->publicTenantContext('article.pc-index'));
        return $this->data($result);
    }

    /** PC 资讯中心（同 article/lists） */
    public function infoCenter()
    {
        return $this->data($this->pcApplication->infoCenter(
            $this->publicTenantContext('article.info-center'),
        ));
    }

    /** PC 文章详情 */
    public function articleDetail()
    {
        $id     = $this->request->get('id/d', 0);
        $source = $this->request->get('source/s', 'default');
        return $this->data($this->pcApplication->articleDetail(
            $this->publicTenantContext('article.pc-detail'),
            $this->memberId,
            $id,
            $source,
        ));
    }
}
