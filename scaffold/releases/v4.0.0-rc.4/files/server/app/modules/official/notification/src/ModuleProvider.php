<?php

declare(strict_types=1);

namespace PeanutAdmin\Modules\Notification;

use app\common\execution\CurrentExecutionContext;
use app\common\contract\http\OutboundHttpTransport;
use app\common\infrastructure\notice\ApplicationNoticeSmsSender;
use app\common\services\notice\NoticeChannelService;
use PeanutAdmin\Modules\Integration\Contract\ExternalChannelBindings;
use PeanutAdmin\Modules\Integration\Contract\ExternalTenantResolutionService;
use PeanutAdmin\Modules\Notification\Service\VerificationCodeService;
use PeanutAdmin\Modules\Notification\Service\NotificationApplicationService;
use PeanutAdmin\Modules\Notification\Service\NotificationBootstrapService;
use PeanutAdmin\Modules\Notification\Contract\NotificationBootstrapCommands;
use PeanutAdmin\Modules\Notification\Contract\NotificationCommands;
use PeanutAdmin\Modules\Notification\Contract\NotificationQueries;
use PeanutAdmin\Modules\Notification\Contract\VerificationCodeCommands;
use PeanutAdmin\Kernel\Module\ModuleProvider as ModuleProviderContract;
use PeanutAdmin\Modules\Notification\Contract\NoticeSmsSender;
use PeanutAdmin\Modules\Notification\Delivery\Application\RecipientResolver;
use PeanutAdmin\Modules\Notification\Delivery\Identity\TenantMemberRecipientResolver;
use PeanutAdmin\Modules\Notification\Delivery\Persistence\NotificationRepository;
use PeanutAdmin\Modules\Notification\Delivery\Persistence\NotificationStore;
use PeanutAdmin\Modules\Notification\Delivery\Sms\DisabledSmsProvider;
use PeanutAdmin\Modules\Notification\Delivery\Sms\LocalDevSmsProvider;
use PeanutAdmin\Modules\Notification\Delivery\Sms\SmsProvider;
use PeanutAdmin\Modules\Notification\Delivery\Sms\SmsRecipientResolver;
use PeanutAdmin\Modules\Notification\Delivery\Sms\UnavailableSmsRecipientResolver;
use PeanutAdmin\Modules\Notification\Delivery\Task\NotificationOutboxDispatcher;
use PeanutAdmin\Modules\Notification\Delivery\Task\NotificationTaskWorkerDefinition;
use PeanutAdmin\Modules\Notification\Delivery\Task\OutboxTaskSubmissionProvider;
use PeanutAdmin\Modules\Task\Contract\TaskJobRuntime;
use PeanutAdmin\Modules\Task\Contract\TaskWorkerContributor;
use think\App;
use think\facade\Config;

final class ModuleProvider implements ModuleProviderContract, TaskWorkerContributor
{
    public function moduleKey(): string
    {
        return 'official.notification';
    }

    public function bindings(): array
    {
        return [
            NoticeSmsSender::class => fn(App $app): NoticeSmsSender => new ApplicationNoticeSmsSender(
                $app->make(CurrentExecutionContext::class),
                $app->make(NoticeChannelService::class),
                (string) Config::get('peanut.environment', '') === 'development',
            ),
            VerificationCodeService::class => fn(App $app): VerificationCodeService => new VerificationCodeService(
                $app->make(NoticeSmsSender::class),
                $app->make(CurrentExecutionContext::class),
                (string) Config::get('peanut.environment', '') === 'development',
                (int) Config::get('notification.verification.max_failed_attempts', VerificationCodeService::DEFAULT_MAX_FAILED_ATTEMPTS),
            ),
            NotificationCommands::class => NotificationApplicationService::class,
            NotificationBootstrapCommands::class => NotificationBootstrapService::class,
            NotificationQueries::class => NotificationApplicationService::class,
            VerificationCodeCommands::class => NotificationApplicationService::class,
            NotificationRepository::class => NotificationStore::class,
            RecipientResolver::class => TenantMemberRecipientResolver::class,
            SmsRecipientResolver::class => UnavailableSmsRecipientResolver::class,
            SmsProvider::class => fn(): SmsProvider => (string) Config::get('peanut.environment', '') === 'development'
                ? new LocalDevSmsProvider()
                : new DisabledSmsProvider(),
            NotificationOutboxDispatcher::class => fn(App $app): NotificationOutboxDispatcher => new NotificationOutboxDispatcher(
                $app->make(NotificationRepository::class),
                $app->make(TaskJobRuntime::class)->publisher(
                    new OutboxTaskSubmissionProvider('inbox'),
                    new OutboxTaskSubmissionProvider('sms'),
                ),
            ),
        ];
    }

    public function taskWorkerDefinitions(): array
    {
        return [NotificationTaskWorkerDefinition::class];
    }
}
