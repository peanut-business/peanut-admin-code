<?php
declare(strict_types=1);

namespace app\modules\peanut\artifact_revision;

use PeanutAdmin\Kernel\Module\ModuleProvider as ModuleProviderContract;

/** 官方维护的可选业务模块；注册定义不启用租户、不执行迁移或读取当前身份。 */
final class ModuleProvider implements ModuleProviderContract
{
    public function moduleKey(): string
    {
        return 'peanut.artifact-revision';
    }

    public function bindings(): array
    {
        return [
            \PeanutAdmin\ArtifactRevision\Workflow\ArtifactSubjectRevisionReader::class
                => \PeanutAdmin\ArtifactRevision\Persistence\ThinkPhpArtifactRevisionRepository::class,
        ];
    }
}
