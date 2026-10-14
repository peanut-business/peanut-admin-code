<?php

declare(strict_types=1);

namespace PeanutAdmin\Modules\Collaboration;

use app\common\execution\ExecutionContextStore;
use PeanutAdmin\Modules\Collaboration\Service\ServiceAuthentication;
use PeanutAdmin\Modules\Collaboration\Service\CollaborationService;
use PeanutAdmin\Modules\Collaboration\Service\NativeCollaborationProvider;
use think\App;

final class ModuleProvider implements \PeanutAdmin\Kernel\Module\ModuleProvider, \PeanutAdmin\Modules\Document\Contract\DocumentAcceptedWriteContributor
{
    public function moduleKey(): string
    {
        return 'official.collaboration';
    }

    public function bindings(): array
    {
        return [\PeanutAdmin\Modules\Collaboration\Contract\CollaborationTickets::class => CollaborationService::class,
            \PeanutAdmin\Modules\Collaboration\Service\RuntimeLifecycle::class => static fn(App $app) => new \PeanutAdmin\Modules\Collaboration\Service\RuntimeLifecycle(
                $app->make(ExecutionContextStore::class),
                $app->make(\PeanutAdmin\Kernel\Module\ModuleRuntimeRepository::class),
                $app->make(\PeanutAdmin\Kernel\Module\CompiledModuleRegistry::class),
                (array) ($app->config->has('collaboration') ? $app->config->get('collaboration') : require dirname(__DIR__) . '/config/collaboration.php'),
                (string) $app->config->get('deployment.mode'),
                $app->make(\PeanutAdmin\Modules\Document\Contract\DocumentCollaborativeWrites::class),
                $app->make(\PeanutAdmin\Modules\Collaboration\Service\BusinessControlClient::class),
                $app->make(\PeanutAdmin\Modules\Document\Service\DocumentAcceptedCommitQuery::class),
            ), ServiceAuthentication::class => static fn(App $app): ServiceAuthentication => new ServiceAuthentication(
                $app->make(ExecutionContextStore::class),
                array_replace(
                    (array) ($app->config->has('collaboration') ? $app->config->get('collaboration') : require dirname(__DIR__) . '/config/collaboration.php'),
                    ['edition' => (string) $app->config->get('deployment.mode')],
                ),
            ), \PeanutAdmin\Modules\Collaboration\Service\BusinessControlClient::class => static fn(App $app) => new \PeanutAdmin\Modules\Collaboration\Service\BusinessControlClient(
                $app->make(\app\common\contract\http\OutboundHttpTransport::class),
                (array) ($app->config->has('collaboration') ? $app->config->get('collaboration') : require dirname(__DIR__) . '/config/collaboration.php'),
            ), CollaborationService::class => static fn(App $app): CollaborationService => new CollaborationService(
                $app->make(\PeanutAdmin\Modules\Document\Contract\DocumentCollaborativeWrites::class),
                $app->make(NativeCollaborationProvider::class),
                $app->make(\PeanutAdmin\Modules\File\Contract\FileReferenceCommands::class),
                $app->make(\PeanutAdmin\Modules\Identity\Contract\TenantSessionAccess::class),
                $app->make(\PeanutAdmin\Modules\Identity\Contract\TenantMemberDirectory::class),
                $app->make(\app\common\contract\authorization\AdminAuthorizationQuery::class),
                $app->make(ExecutionContextStore::class),
                $app->make(ServiceAuthentication::class),
                $app->make(\PeanutAdmin\Modules\Collaboration\Service\RuntimeLifecycle::class),
                $app->make(\app\common\services\audit\AuditContractHost::class),
                (array) ($app->config->has('collaboration') ? $app->config->get('collaboration') : require dirname(__DIR__) . '/config/collaboration.php'),
                (string) $app->config->get('deployment.mode'),
            )];
    }

    public function acceptedWriteProvider(): string
    {
        return NativeCollaborationProvider::class;
    }
}
