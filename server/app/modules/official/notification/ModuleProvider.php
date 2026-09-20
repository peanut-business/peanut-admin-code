<?php
declare(strict_types=1);

namespace app\modules\official\notification;

use app\common\execution\CurrentExecutionContext;
use app\common\contract\http\OutboundHttpTransport;
use app\common\infrastructure\notice\ApplicationNoticeSmsSender;
use app\common\services\notice\NoticeChannelService;
use app\common\services\external\ExternalChannelBindingService;
use PeanutAdmin\IntegrationSecurity\External\ExternalTenantResolver;
use app\modules\official\notification\services\VerificationCodeService;
use app\modules\official\notification\services\NotificationApplicationService;
use app\modules\official\notification\services\NotificationBootstrapService;
use app\modules\official\notification\contracts\NotificationBootstrapCommands;
use app\modules\official\notification\contracts\NotificationCommands;
use app\modules\official\notification\contracts\NotificationQueries;
use app\modules\official\notification\contracts\VerificationCodeCommands;
use PeanutAdmin\Kernel\Module\ModuleProvider as ModuleProviderContract;
use PeanutAdmin\NotificationSms\Sms\NoticeSmsSender;
use think\App;
use think\facade\Config;

final class ModuleProvider implements ModuleProviderContract
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
                (string)Config::get('peanut.environment', '') === 'development',
            ),
            VerificationCodeService::class => fn(App $app): VerificationCodeService => new VerificationCodeService(
                $app->make(NoticeSmsSender::class),
                $app->make(CurrentExecutionContext::class),
                (string)Config::get('peanut.environment', '') === 'development',
                (int)Config::get('notification.verification.max_failed_attempts', VerificationCodeService::DEFAULT_MAX_FAILED_ATTEMPTS),
            ),
            NotificationCommands::class => NotificationApplicationService::class,
            NotificationBootstrapCommands::class => NotificationBootstrapService::class,
            NotificationQueries::class => NotificationApplicationService::class,
            VerificationCodeCommands::class => NotificationApplicationService::class,
        ];
    }
}
