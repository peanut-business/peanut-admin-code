<?php
declare(strict_types=1);

namespace app\modules\official\notification\controller;

use app\adminapi\controller\BaseAdminController;
use app\common\execution\CurrentExecutionContext;
use app\modules\official\notification\services\NotificationAdminApplicationService;
use app\modules\official\notification\validate\NoticeSceneValidate;
use think\App;

class NoticeSceneController extends BaseAdminController
{
    public function __construct(
        App $app,
        CurrentExecutionContext $executionContext,
        private readonly NotificationAdminApplicationService $notifications,
    ) {
        parent::__construct($app, $executionContext);
    }

    public function lists()
    {
        return $this->data($this->notifications->scenes(
            $this->tenantAdminContext(),
            $this->tenantAdminActor(),
        ));
    }

    public function detail()
    {
        $params = $this->request->get();
        $this->validate($params, NoticeSceneValidate::class . '.detail');
        return $this->data($this->notifications->scene(
            $this->tenantAdminContext(),
            $this->tenantAdminActor(),
            (int)$params['id'],
        ));
    }

    public function save()
    {
        $params = $this->request->post();
        $this->validate($params, NoticeSceneValidate::class . '.save');
        $this->notifications->saveScene(
            $this->tenantAdminContext(),
            $this->tenantAdminActor(),
            $params,
        );
        return $this->success('保存成功');
    }

}
