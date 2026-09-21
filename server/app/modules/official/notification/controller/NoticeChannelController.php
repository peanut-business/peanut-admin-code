<?php
declare(strict_types=1);

namespace app\modules\official\notification\controller;

use app\adminapi\controller\BaseAdminController;
use app\common\execution\CurrentExecutionContext;
use app\modules\official\notification\services\NotificationAdminApplicationService;
use think\App;

/**
 * 通知渠道配置控制器
 */
class NoticeChannelController extends BaseAdminController
{
    public function __construct(
        App $app,
        CurrentExecutionContext $executionContext,
        private readonly NotificationAdminApplicationService $notifications,
    ) {
        parent::__construct($app, $executionContext);
    }

    /**
     * 获取渠道配置（脱敏：密钥只返回是否已设置）
     */
    public function detail(): \think\Response
    {
        return $this->data($this->notifications->channel(
            $this->tenantAdminContext(),
            $this->tenantAdminActor(),
        ));
    }

    /**
     * 保存渠道配置
     * 前端分块提交：{ section: 'sms_aliyun'|'sms_tencent'|'sms_default', ...fields }
     */
    public function save(): \think\Response
    {
        $post    = $this->request->post();
        $section = (string) ($post['section'] ?? '');

        unset($post['section']);
        $this->notifications->saveChannel(
            $this->tenantAdminContext(),
            $this->tenantAdminActor(),
            $section,
            $post,
        );
        return $this->success('保存成功');
    }
}
