<?php

declare(strict_types=1);

namespace PeanutAdmin\Fixtures\WorkflowBusiness;

use PeanutAdmin\DataPermission\Runtime\DataPermissionModuleProvider;
use PeanutAdmin\DataPermission\Runtime\DataPermissionRuntimeRegistry;
use PeanutAdmin\Fixtures\WorkflowBusiness\Integration\WorkflowBusinessPolicyProvider;
use PeanutAdmin\Kernel\Module\ModuleProvider as ModuleProviderContract;

/** Optional fixture registration creates no data and enables no tenant. */
final readonly class ModuleProvider implements ModuleProviderContract, DataPermissionModuleProvider, \PeanutAdmin\Modules\Workflow\Integration\WorkflowScenarioContributor
{
    public function __construct(private \think\App $app) {}

    public function moduleKey(): string
    {
        return Package::MODULE_KEY;
    }

    public function bindings(): array
    {
        return [];
    }

    public function registerDataPermission(DataPermissionRuntimeRegistry $registry): void
    {
        $registry->registerResourceProvider(WorkflowBusinessPolicyProvider::class, $this->app->make(WorkflowBusinessPolicyProvider::class));
    }

    public function registerWorkflowScenarios(\PeanutAdmin\Modules\Workflow\Integration\WorkflowScenarioRegistry $registry, \think\App $app): void
    {
        $app->make(\PeanutAdmin\Fixtures\WorkflowBusiness\Integration\WorkflowBusinessComposition::class)->register($registry);
    }

    public function workflowTaskSubmissions(\think\App $app): array
    {
        $authorization = $app->make(\PeanutAdmin\Fixtures\WorkflowBusiness\Integration\WorkflowBusinessTaskAuthorization::class);
        $submissions = array_map(static fn(string $scene) => new \PeanutAdmin\Fixtures\WorkflowBusiness\Integration\WorkflowBusinessTaskSubmissionProvider($scene, $authorization), Package::SCENES);
        if ($app->bound(\PeanutAdmin\Modules\Notification\Contract\NotificationProducerRegistry::class)) {
            foreach ($this->notificationProducers($app) as $producer) {
                $submissions[] = new \PeanutAdmin\Modules\Notification\Delivery\Task\ProducerOutboxTaskSubmissionProvider($producer, $app->make(\PeanutAdmin\Modules\Notification\Infrastructure\Authorization\NotificationAsyncAuthorization::class));
            }
        }
        return $submissions;
    }

    public function notificationProducers(\think\App $app): array
    {
        return array_map(fn(string $scene) => new \PeanutAdmin\Fixtures\WorkflowBusiness\Integration\WorkflowBusinessNotificationProducer($scene,
            $app->make(\PeanutAdmin\Fixtures\WorkflowBusiness\Service\WorkflowBusinessAccess::class), $app->make(\PeanutAdmin\Modules\Workflow\Application\WorkflowQueries::class)), Package::SCENES);
    }

    public function taskWorkerDefinitions(): array
    {
        $definitions = [\PeanutAdmin\Fixtures\WorkflowBusiness\Integration\ApplicationWorkerDefinition::class,
            \PeanutAdmin\Fixtures\WorkflowBusiness\Integration\PurchaseWorkerDefinition::class,
            \PeanutAdmin\Fixtures\WorkflowBusiness\Integration\ReceiptWorkerDefinition::class];
        if ($this->app->bound(\PeanutAdmin\Modules\Notification\Contract\NotificationProducerRegistry::class)) {
            array_push($definitions, \PeanutAdmin\Fixtures\WorkflowBusiness\Integration\ApplicationNotificationWorkerDefinition::class,
                \PeanutAdmin\Fixtures\WorkflowBusiness\Integration\PurchaseNotificationWorkerDefinition::class,
                \PeanutAdmin\Fixtures\WorkflowBusiness\Integration\ReceiptNotificationWorkerDefinition::class);
        }
        return $definitions;
    }
}
