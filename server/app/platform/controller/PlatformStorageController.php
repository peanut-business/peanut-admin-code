<?php
declare(strict_types=1);

namespace app\platform\controller;

use app\common\execution\CurrentExecutionContext;
use app\modules\official\file\contracts\StorageConfiguration;
use app\platform\context\PlatformOperatorContext;
use think\App;

final class PlatformStorageController extends BasePlatformController
{
    public function __construct(
        App $app,
        CurrentExecutionContext $execution,
        private readonly StorageConfiguration $storage,
    ) {
        parent::__construct($app, $execution);
    }

    public function snapshot()
    {
        return $this->data($this->storage->snapshot());
    }

    public function createAccount()
    {
        return $this->data([
            'id' => $this->storage->createAccount($this->context(), $this->request->post()),
        ]);
    }

    public function updateAccount()
    {
        $this->storage->updateAccount($this->context(), $this->request->post());
        return $this->success('存储账号已更新');
    }

    public function createSpace()
    {
        return $this->data([
            'id' => $this->storage->createSpace($this->context(), $this->request->post()),
        ]);
    }

    public function updateSpace()
    {
        $this->storage->updateSpace($this->context(), $this->request->post());
        return $this->success('Space 已更新');
    }

    public function setRoute()
    {
        $this->storage->setRoute($this->context(), $this->request->post());
        return $this->success('存储路由已更新');
    }

    private function context(): PlatformOperatorContext
    {
        if ($this->platformContext === null) {
            throw \app\common\http\ApiProblem::fromEnvelope(
                'Platform authentication is required.',
                null,
                40100,
            );
        }
        return $this->platformContext;
    }
}
