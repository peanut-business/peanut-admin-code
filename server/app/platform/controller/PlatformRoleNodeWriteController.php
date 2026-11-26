<?php

declare(strict_types=1);

namespace app\platform\controller;

use app\platform\query\PlatformRoleNodeSource;
use PeanutAdmin\Modules\Identity\Contract\PlatformRoleNodeWriteContract;
use think\App;

/**
 * Task-isolated native HTTP adapter. Identity owns validation, permissions,
 * trusted node re-resolution, transaction, revision CAS and audit.
 */
final class PlatformRoleNodeWriteController extends BasePlatformController
{
    public function __construct(
        App $app,
        private readonly PlatformRoleNodeWriteContract $writes,
        private readonly PlatformRoleNodeSource $source,
    ) {
        parent::__construct($app);
    }

    public function prepare()
    {
        if ($this->platformContext === null) {
            throw \app\common\http\ApiProblem::fromEnvelope('Platform authentication is required.', null, 40100);
        }
        return $this->data($this->writes->prepare(
            $this->platformContext->core,
            $this->request->post(),
            fn(array $candidate): array => $this->source->authoritativeCandidate($candidate),
        ));
    }

    public function save()
    {
        if ($this->platformContext === null) {
            throw \app\common\http\ApiProblem::fromEnvelope('Platform authentication is required.', null, 40100);
        }
        return $this->data($this->writes->save(
            $this->platformContext->core,
            $this->request->post(),
            fn(array $candidate): array => $this->source->authoritativeCandidate($candidate),
        ));
    }
}
